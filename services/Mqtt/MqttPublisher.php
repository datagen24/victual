<?php

namespace Victual\Services\Mqtt;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

/**
 * The transport half of MQTT state publication: connect, publish a batch of retained
 * topics, disconnect. One batch per call, and never an exception to the caller.
 *
 * Every choice here follows from docs/plans/18-mqtt-state-publication.md's question 5 and
 * from what this is used for - a call at the end of a web request that has already
 * committed.
 *
 * - **QoS 0, retained, MQTT 3.1.1.** The snapshot is idempotent and every publish
 *   supersedes the last, so a lost message costs nothing the next write or the next boot
 *   does not repair. QoS 1 would need php-mqtt/client's loop() running to collect the
 *   acknowledgement, which means running an MQTT event loop at the end of a web request -
 *   precisely the shape this design exists to avoid - and the library has no cross-session
 *   persistence for it either, so the guarantee would be weaker than it looks. Retain is
 *   the part that actually matters: once the broker has the message it is durable there,
 *   which is what lets a consumer survive the server being away for days.
 * - **Connect, publish, disconnect, every time.** There is no connection to keep: a PHP
 *   request ends and takes its process state with it, and keeping a broker session across
 *   requests would be exactly the cold-start problem ADR-0007 forbids.
 * - **No last will and no availability topic.** An entity with no availability topic is
 *   always available in Home Assistant, and that is the point: this server is
 *   intentionally absent most of the time and its absence says nothing about whether the
 *   published facts are still true. A last will would make every scale-to-zero look like a
 *   failure. Freshness is published as a fact instead - the last_published sensor.
 * - **Nothing throws.** The transaction is closed by the time this runs, so a broker
 *   problem must not turn a committed write into an error response. Failures are logged
 *   and swallowed.
 *
 * The broker address is a configured constant, the same way a label printer's connection is.
 * Nothing derived from a request reaches it, so the security sweep's finding that this tree
 * has no user-configurable outbound URL still holds.
 */
class MqttPublisher
{
	/**
	 * How long an identical "broker unreachable" complaint is suppressed, in seconds, when
	 * APCu is available to remember it.
	 */
	const FAILURE_LOG_SUPPRESSION_SECONDS = 300;

	/**
	 * The longest client id every MQTT 3.1.1 broker is required to accept. Brokers may allow
	 * more; nothing here needs more.
	 */
	const CLIENT_ID_MAX_LENGTH = 23;

	/**
	 * Random bytes in the per-connection suffix, hex encoded - so twelve of the twenty-three
	 * characters are random and the configured prefix gets the other ten.
	 */
	const CLIENT_ID_SUFFIX_BYTES = 6;

	/**
	 * Publishes a batch of retained topics in a single broker connection.
	 *
	 * @param array<string, string> $topics Topic => payload. An empty string payload is a
	 *                                      retraction: it clears the retained message.
	 * @return bool True when the whole batch reached the broker
	 */
	public function PublishBatch(array $topics): bool
	{
		if (count($topics) === 0)
		{
			return true;
		}

		$client = null;

		try
		{
			$client = new MqttClient(
				VICTUAL_MQTT_HOST,
				(int)VICTUAL_MQTT_PORT,
				$this->BuildClientId(),
				MqttClient::MQTT_3_1_1
			);

			$client->connect($this->BuildConnectionSettings(), true);

			foreach ($topics as $topic => $payload)
			{
				$client->publish($topic, $payload, MqttClient::QOS_AT_MOST_ONCE, true);
			}

			$client->disconnect();

			$this->ForgetFailure();

			return true;
		}
		catch (\Throwable $ex)
		{
			// One line for the whole batch rather than one per topic: a broker that is down
			// is down for all of them, and a log that repeats itself sixty times is a log
			// nobody reads.
			$this->LogFailureOnce($ex);

			// Best effort: a half-open socket left behind by a failure mid-batch would
			// otherwise be closed by the request ending anyway, but not before the broker
			// has waited out its keep-alive.
			if ($client !== null)
			{
				try
				{
					$client->disconnect();
				}
				catch (\Throwable $ignored)
				{
				}
			}

			return false;
		}
	}

	/**
	 * A client id the broker will accept and which two concurrent publishes cannot share.
	 *
	 * MQTT brokers disconnect an existing session when a second connection presents the same
	 * client id, so a fixed id would have two overlapping requests knocking each other off
	 * the broker. The configured value is a prefix; the suffix makes each connection its own
	 * session.
	 *
	 * The whole id is capped at 23 characters, the length MQTT 3.1.1 requires every broker
	 * to accept, and the **prefix** is what gets trimmed to fit - never the suffix. Trimming
	 * the joined string instead would silently eat the randomness as the configured prefix
	 * grew: a prefix of 23 characters or more would leave no suffix at all, which is exactly
	 * the collision this method exists to avoid, arriving only for installations that
	 * configured a long name.
	 */
	private function BuildClientId(): string
	{
		$prefix = preg_replace('/[^A-Za-z0-9_-]/', '', (string)VICTUAL_MQTT_CLIENT_ID);
		if ($prefix === '')
		{
			$prefix = 'victual';
		}

		$suffix = bin2hex(random_bytes(self::CLIENT_ID_SUFFIX_BYTES));

		return substr($prefix, 0, self::CLIENT_ID_MAX_LENGTH - 1 - strlen($suffix)) . '-' . $suffix;
	}

	/**
	 * Connection settings: credentials, TLS, and the short timeouts that bound how long an
	 * unreachable broker can delay a request that has already committed.
	 *
	 * Automatic reconnection is off deliberately. The library would otherwise spend
	 * maxReconnectAttempts x connectTimeout seconds trying, which turns a configured two
	 * second ceiling into an unconfigured six.
	 */
	private function BuildConnectionSettings(): ConnectionSettings
	{
		$timeout = max(1, (int)VICTUAL_MQTT_CONNECT_TIMEOUT_SECONDS);

		$settings = (new ConnectionSettings())
			->setConnectTimeout($timeout)
			->setSocketTimeout($timeout)
			->setReconnectAutomatically(false)
			->setUseTls((bool)VICTUAL_MQTT_TLS);

		if (VICTUAL_MQTT_USERNAME !== '')
		{
			$settings = $settings
				->setUsername(VICTUAL_MQTT_USERNAME)
				->setPassword(VICTUAL_MQTT_PASSWORD === '' ? null : VICTUAL_MQTT_PASSWORD);
		}

		return $settings;
	}

	/**
	 * Logs a publish failure, at most once per suppression window.
	 *
	 * The plan's question 6 asks for once per process rather than once per publish. There is
	 * no process state to hold that in - ADR-0007 allows process memory only for pure caches
	 * - so APCu is used when it is there and the suppression is simply skipped when it is
	 * not. Losing the cache costs one extra log line and nothing else, which is exactly the
	 * bar ADR-0007 sets; without APCu the behaviour degrades to one line per publish
	 * attempt, which for a web request is one line per request anyway.
	 */
	private function LogFailureOnce(\Throwable $ex): void
	{
		$key = $this->FailureCacheKey();

		if ($key !== null && apcu_exists($key))
		{
			return;
		}

		if ($key !== null)
		{
			apcu_store($key, 1, self::FAILURE_LOG_SUPPRESSION_SECONDS);
		}

		// The message is the only thing logged: the exception's own context can contain the
		// connection string, and the credentials are settings.
		error_log('Victual: publishing state to the MQTT broker at ' . VICTUAL_MQTT_HOST . ':' . VICTUAL_MQTT_PORT
			. ' failed, the write itself was unaffected: ' . $ex->getMessage());
	}

	/**
	 * Clears the suppression after a successful publish, so the next outage is reported
	 * immediately rather than waiting out a window opened by the previous one.
	 */
	private function ForgetFailure(): void
	{
		$key = $this->FailureCacheKey();

		if ($key !== null)
		{
			apcu_delete($key);
		}
	}

	/**
	 * The APCu key for the failure suppression, or null when APCu is unavailable or
	 * disabled (the CLI leaves it off unless apc.enable_cli is set, which is fine - a CLI
	 * publish is a single attempt).
	 */
	private function FailureCacheKey(): ?string
	{
		if (!function_exists('apcu_enabled') || !apcu_enabled())
		{
			return null;
		}

		return 'victual_mqtt_publish_failure_' . VICTUAL_MQTT_HOST . '_' . VICTUAL_MQTT_PORT;
	}
}
