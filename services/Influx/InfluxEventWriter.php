<?php

namespace Victual\Services\Influx;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * Writes events to InfluxDB over its v2 HTTP line-protocol endpoint.
 *
 * The transport half of question 7 of docs/plans/18-mqtt-state-publication.md, answered
 * 2026-08-31: the fork does write to InfluxDB, scoped to *events* rather than sampled state.
 * The distinction is the whole answer. Sampling stock state from a pod that is mostly asleep
 * would produce a series full of holes that mean "nobody was shopping" rather than "nothing
 * was true"; a point written when a purchase commits produces a series whose gaps mean "no
 * purchases", which is true. So this is only ever called with points that describe something
 * that happened, at the moment it happened.
 *
 * Everything the MQTT publisher's contract says applies here too, for the same reasons:
 *
 * - **The endpoint is a configured constant.** Nothing derived from a request can influence
 *   where a write goes, so the 2026-08-29 security sweep's finding that this tree has no
 *   user-configurable outbound URL survives this plan's second outbound connection as well
 *   as its first.
 * - **The token is a setting** and must never join SystemApiController's EXPOSED_SETTINGS.
 * - **Nothing throws.** This runs after the transaction has committed, so a database that is
 *   down, slow or misconfigured cannot turn a successful write into an error response.
 * - **Short timeouts**, so an unreachable endpoint bounds the delay rather than hanging the
 *   request.
 * - **A write counts only when the write endpoint acknowledged it.** Redirects are refused
 *   rather than followed and the status is checked explicitly, because this class's return
 *   value is what the outbox turns into `delivered_at` - see Write().
 *
 * Unlike the broker there is no wall-tablet test to pass: InfluxDB is queried with
 * credentials rather than subscribed to, which is exactly why question 8's "no prices on
 * MQTT" and question 7's "prices to InfluxDB" are consistent rather than contradictory.
 */
class InfluxEventWriter
{
	/**
	 * Whether event writing is configured on at all. Read as a constant so that a fork with
	 * it off pays one constant lookup.
	 */
	/**
	 * Why the last Write() failed, or null when it succeeded. Read by the outbox drain so
	 * that a stuck queue records the reason on the rows it could not deliver.
	 *
	 * @var string|null
	 */
	private $LastError = null;

	public static function IsEnabled(): bool
	{
		return defined('VICTUAL_INFLUXDB_ENABLED') && VICTUAL_INFLUXDB_ENABLED === true;
	}

	/**
	 * Writes a batch of line-protocol lines, with nanosecond timestamps.
	 *
	 * **Success means the write endpoint acknowledged the batch, and nothing weaker.** This
	 * bool is what the outbox drain turns into `delivered_at`, so anything it reports as true
	 * without an acknowledgement is a committed event discarded silently - the one failure
	 * the whole outbox exists to prevent, arriving at the last step.
	 *
	 * Guzzle's defaults are not that check, in two ways that both looked like success:
	 *
	 * - **`http_errors` only throws for 4xx and 5xx.** A 3xx that is not followed - a bare
	 *   302, say - comes back as an ordinary response, and the discarded return value meant
	 *   nothing ever looked at its status. Through the real drain that set `delivered_at`
	 *   with zero attempts and no error, so the event was never retried.
	 * - **`allow_redirects` is on by default.** A POST redirected to a login page finishes as
	 *   an HTTP 200 from somewhere that is not the write endpoint, which is indistinguishable
	 *   from an acknowledgement unless the redirect is refused.
	 *
	 * So redirects are off, every status comes back as a response rather than an exception,
	 * and the acknowledgement is asserted here: a 2xx, from the address the request was sent
	 * to, with no body. The empty body is part of the contract rather than fussiness -
	 * InfluxDB's v2 write API answers `204 No Content`, so a 2xx carrying a page is a proxy
	 * or a portal answering on the endpoint's behalf. If a real deployment ever puts
	 * something in front of InfluxDB that returns a body on success, this is the check to
	 * loosen, deliberately and with the endpoint named.
	 *
	 * @param string[] $lines
	 * @return bool True when the write endpoint acknowledged the batch
	 */
	public function Write(array $lines): bool
	{
		if (count($lines) === 0)
		{
			return true;
		}

		$timeout = max(1, (int)VICTUAL_INFLUXDB_TIMEOUT_SECONDS);
		$url = rtrim((string)VICTUAL_INFLUXDB_URL, '/') . '/api/v2/write';

		try
		{
			$client = new Client([
				RequestOptions::TIMEOUT => $timeout,
				RequestOptions::CONNECT_TIMEOUT => $timeout,
				// Never follow one. A redirect is not an acknowledgement, and following it
				// means the 200 that comes back was written by whatever the redirect pointed
				// at - a login page, an SSO portal, an error page on a proxy - rather than by
				// the endpoint this batch was addressed to.
				RequestOptions::ALLOW_REDIRECTS => false,
				// Every status arrives as a response so that one place decides what counts as
				// an acknowledgement. Left on, 4xx and 5xx would throw while 3xx would not,
				// which is the split that hid this.
				RequestOptions::HTTP_ERRORS => false
			]);

			$response = $client->request('POST', $url, [
				RequestOptions::QUERY => [
					'org' => VICTUAL_INFLUXDB_ORG,
					'bucket' => VICTUAL_INFLUXDB_BUCKET,
					'precision' => 'ns'
				],
				RequestOptions::HEADERS => [
					'Authorization' => 'Token ' . VICTUAL_INFLUXDB_TOKEN,
					'Content-Type' => 'text/plain; charset=utf-8'
				],
				RequestOptions::BODY => implode("\n", $lines)
			]);

			$status = $response->getStatusCode();

			if ($status < 200 || $status > 299)
			{
				$where = $response->getHeaderLine('Location');

				return $this->Reject('the write endpoint answered HTTP ' . $status
					. ($where === '' ? '' : ', redirecting to ' . $where)
					. ($status >= 300 && $status <= 399 ? ' (not followed: a redirect is not an acknowledgement)' : '')
					. self::DescribeBody($response->getBody()));
			}

			$body = trim((string)$response->getBody());

			if ($body !== '')
			{
				return $this->Reject('the write endpoint answered HTTP ' . $status
					. ' with a body, where InfluxDB\'s write API answers 204 with none - so this'
					. ' was answered by something in front of it rather than by InfluxDB'
					. self::DescribeBody($body));
			}

			$this->LastError = null;

			return true;
		}
		// GuzzleException, the interface every Guzzle exception implements, rather than
		// RequestException: ConnectException extends TransferException, not RequestException,
		// so a DNS failure or a connect timeout would otherwise escape - and those are the
		// likely failures here
		catch (GuzzleException $ex)
		{
			return $this->Reject($ex->getMessage());
		}
		catch (\Throwable $ex)
		{
			return $this->Reject($ex->getMessage());
		}
	}

	/**
	 * Records why the batch was not acknowledged and reports the failure.
	 *
	 * One place, so that every way of not being acknowledged - a refused connection, a
	 * timeout, a redirect, a 500, a 200 from a login page - leaves the same evidence: a
	 * LastError the drain writes onto the rows it could not deliver, and one log line.
	 */
	private function Reject(string $reason): bool
	{
		$this->LastError = $reason;

		error_log('Victual: writing events to InfluxDB at ' . VICTUAL_INFLUXDB_URL
			. ' was not acknowledged, the events stay in the outbox for the next drain: ' . $reason);

		return false;
	}

	/**
	 * A bounded, single-line rendering of a response body for the error column.
	 *
	 * Bounded because the body may be an entire HTML login page and `last_error` is for a
	 * person glancing at why a queue stopped moving.
	 *
	 * @param mixed $body Anything castable to string, including a PSR-7 stream
	 */
	private static function DescribeBody($body): string
	{
		$text = trim(preg_replace('/\s+/', ' ', (string)$body));

		if ($text === '')
		{
			return '';
		}

		return ': ' . (strlen($text) > 200 ? substr($text, 0, 200) . '…' : $text);
	}

	/**
	 * The message from the last failed Write(), or null when the last one succeeded.
	 */
	public function GetLastError(): ?string
	{
		return $this->LastError;
	}

	/**
	 * One line-protocol line.
	 *
	 * Tags and field keys are escaped per InfluxDB's line protocol rules (commas, spaces and
	 * equals signs); string field values are not produced here at all, because every field
	 * this plan writes is a number and quoting rules that are never exercised are rules that
	 * are wrong when they finally are.
	 *
	 * @param array<string, string|int> $tags
	 * @param array<string, float|int> $fields
	 * @param int $timestampNs
	 */
	public static function BuildLine(string $measurement, array $tags, array $fields, int $timestampNs): string
	{
		$line = self::Escape($measurement);

		foreach ($tags as $key => $value)
		{
			$line .= ',' . self::Escape((string)$key) . '=' . self::Escape((string)$value);
		}

		$fieldParts = [];
		foreach ($fields as $key => $value)
		{
			$fieldParts[] = self::Escape((string)$key) . '=' . self::FormatFloat((float)$value);
		}

		return $line . ' ' . implode(',', $fieldParts) . ' ' . $timestampNs;
	}

	/**
	 * A local "Y-m-d H:i:s" timestamp as nanoseconds since the epoch, which is the precision
	 * the write above declares.
	 */
	public static function ToNanoseconds(string $localTimestamp): int
	{
		return (new \DateTimeImmutable($localTimestamp))->getTimestamp() * 1000000000;
	}

	/**
	 * Line protocol reserves comma, space and equals in measurement, tag and field keys.
	 */
	private static function Escape(string $value): string
	{
		return str_replace([',', ' ', '='], ['\\,', '\\ ', '\\='], $value);
	}

	/**
	 * A float field value with a decimal point, so InfluxDB types the field as a float on
	 * the first point and does not then reject a later one for being a different type - an
	 * integer-looking "2" and a float "2.5" in the same field is a rejected write.
	 */
	private static function FormatFloat(float $value): string
	{
		$formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

		return str_contains($formatted, '.') ? $formatted : $formatted . '.0';
	}
}
