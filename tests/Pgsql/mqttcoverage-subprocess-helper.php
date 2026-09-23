<?php

// The MQTT publication surface driven in a process of its own, and the stand-in broker it is
// driven against.
//
//   php mqttcoverage-subprocess-helper.php broker <port> <log file> <record|drop>
//   php mqttcoverage-subprocess-helper.php scenario <step,step,...> <result file>
//
// Two jobs in one file because MqttCoverageTest owns exactly one helper.
//
// Why a subprocess at all: every question this asks is a question about a different value of
// a VICTUAL_MQTT_* constant - publication off, a broker that refuses, a broker that wants
// credentials - and PHP cannot redefine a constant. The settings arrive as VICTUAL_MQTT_*
// environment variables, which Setting() reads ahead of its own defaults
// (helpers/extensions.php:389-405), so the caller picks them per scenario without writing a
// config file.
//
// Why a stand-in broker rather than a real one: the same reason .devtools/mqtt/'s probes use
// one. A check that only runs where somebody installed mosquitto is a check CI skips, and
// everything this exercises fails silently, so a skipped check is worse than no check. This
// speaks the small part of MQTT 3.1.1 that MqttPublisher uses - CONNECT, PUBLISH at QoS 0
// with retain, DISCONNECT - and records what arrived. It is close kin to
// .devtools/mqtt/broker-standin.php and differs in the two things these tests need that the
// probes do not: it records the CONNECT packet's client id, username and password *length*
// (never the password itself, which is a configured credential), and it can be told to hang
// up after CONNACK so that a batch fails halfway rather than at connect time.
//
// Broker log format, one line per event:
//
//   === connect\t<client id>\t<username>\t<password length>
//   <topic>\t<payload length>
//   === end
//
// "=== end" is written when the connection closes and is load-bearing: a reader that looks at
// the log as soon as PublishBatch() returns is racing this process, and that race produces a
// *short* topic list, which reads as "the publisher did not send those" - exactly the wrong
// conclusion. Readers wait for the marker.
//
// Scenario steps, applied in order, each recorded in the result JSON:
//
//   reset                  empty both mqtt tables and reactivate every product
//   flag:<product id>      opt that product in to a per-product entity
//   deactivate:<id>        set products.active = 0
//   rename:<id>            change the product's name, so its published payload changes
//   enabled                MqttStatePublicationService::IsEnabled()
//   requestend             PublishForRequestEnd()
//   suppress               SuppressRequestEndPublish()
//   state                  PublishState()
//   full                   PublishDiscoveryAndState()
//   retract                Retract()
//   emptybatch             MqttPublisher::PublishBatch([]) with no topics
//   breakassembly          rename the view the snapshot is assembled from out of the way
//   restoreassembly        put it back
//   breakledger            rename the publication ledger's table out of the way
//   restoreledger          put it back
//
// Result file: {"steps": {"<index>:<step>": <result>}, "ledger": {object id: hash},
// "flags": [product id], "error": "<message>"}. Written to a file rather than stdout so that
// a warning or a log line from the code under test cannot be mistaken for the result.

use Victual\Services\DatabaseService;
use Victual\Services\Mqtt\MqttPublisher;
use Victual\Services\Mqtt\MqttStatePublicationService;

if (PHP_SAPI !== 'cli')
{
	exit('This is a command line script');
}

$mode = (string)($argv[1] ?? '');

if ($mode === 'broker')
{
	RunBroker((int)($argv[2] ?? 0), (string)($argv[3] ?? ''), (string)($argv[4] ?? 'record'));

	exit(0);
}

if ($mode !== 'scenario')
{
	fwrite(STDERR, "usage: mqttcoverage-subprocess-helper.php broker <port> <log> <record|drop>\n");
	fwrite(STDERR, "       mqttcoverage-subprocess-helper.php scenario <steps> <result file>\n");
	exit(1);
}

RunScenario(explode(',', (string)($argv[2] ?? '')), (string)($argv[3] ?? ''));

exit(0);

// -------------------------------------------------------------------------------------
// The stand-in broker
// -------------------------------------------------------------------------------------

/**
 * Reads exactly $length bytes, or null when the peer went away first.
 *
 * A stream read can come back short, and treating a short read as a whole packet is how a
 * parser like this silently starts recording the wrong topic.
 *
 * @param resource $connection
 */
function ReadExactly($connection, int $length): ?string
{
	$buffer = '';

	while (strlen($buffer) < $length)
	{
		$chunk = fread($connection, $length - strlen($buffer));

		if ($chunk === false || $chunk === '')
		{
			return null;
		}

		$buffer .= $chunk;
	}

	return $buffer;
}

/**
 * MQTT's variable length integer: seven bits per byte, the top bit meaning "one more".
 *
 * @param resource $connection
 */
function ReadRemainingLength($connection): ?int
{
	$value = 0;
	$multiplier = 1;

	for ($i = 0; $i < 4; $i++)
	{
		$byte = ReadExactly($connection, 1);

		if ($byte === null)
		{
			return null;
		}

		$digit = ord($byte);
		$value += ($digit & 127) * $multiplier;

		if (($digit & 128) === 0)
		{
			return $value;
		}

		$multiplier *= 128;
	}

	return null;
}

/**
 * A CONNECT packet's client id, username and password length.
 *
 * The variable header is the protocol name, level, connect flags and keep-alive; the payload
 * is a sequence of length-prefixed strings whose presence the flags announce. Only the fields
 * MqttPublisher can set are decoded - there is no will here, ever, by design (the publisher's
 * docblock says why), but the will flags are honoured so that a publisher which grew one
 * would be read correctly rather than silently mis-parsed.
 *
 * @return array{client_id: string, username: string, password_length: int}
 */
function ParseConnect(string $rest): array
{
	$offset = 0;

	$read = function () use ($rest, &$offset): string
	{
		if ($offset + 2 > strlen($rest))
		{
			return '';
		}

		$length = (ord($rest[$offset]) << 8) | ord($rest[$offset + 1]);
		$value = substr($rest, $offset + 2, $length);
		$offset += 2 + $length;

		return $value;
	};

	$read();                                    // protocol name
	$offset += 1;                               // protocol level
	$flags = $offset < strlen($rest) ? ord($rest[$offset]) : 0;
	$offset += 1;
	$offset += 2;                               // keep-alive

	$clientId = $read();

	if (($flags & 0x04) !== 0)
	{
		$read();                                // will topic
		$read();                                // will message
	}

	$username = ($flags & 0x80) !== 0 ? $read() : '';
	$password = ($flags & 0x40) !== 0 ? $read() : '';

	return ['client_id' => $clientId, 'username' => $username, 'password_length' => strlen($password)];
}

/**
 * Serves connections until killed.
 *
 * "drop" is the broker that dies mid-batch: it answers CONNACK, so the publisher believes it
 * is connected, and then goes away. A publisher which reported that batch as delivered would
 * be recording a retained topic the broker never received.
 */
function RunBroker(int $port, string $logFile, string $behaviour): void
{
	if ($port === 0 || $logFile === '')
	{
		fwrite(STDERR, "broker: need a port and a log file\n");
		exit(1);
	}

	$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage);

	if ($server === false)
	{
		fwrite(STDERR, 'broker: could not listen on 127.0.0.1:' . $port . ': ' . $errorMessage . "\n");
		exit(1);
	}

	while (true)
	{
		$connection = @stream_socket_accept($server, -1);

		if ($connection === false)
		{
			continue;
		}

		while (true)
		{
			$header = ReadExactly($connection, 1);

			if ($header === null)
			{
				break;
			}

			$type = ord($header) >> 4;
			$flags = ord($header) & 15;
			$remaining = ReadRemainingLength($connection);

			if ($remaining === null)
			{
				break;
			}

			$rest = $remaining === 0 ? '' : ReadExactly($connection, $remaining);

			if ($rest === null)
			{
				break;
			}

			if ($type === 1)
			{
				$connect = ParseConnect((string)$rest);

				file_put_contents($logFile, '=== connect' . "\t" . $connect['client_id'] . "\t"
					. $connect['username'] . "\t" . $connect['password_length'] . "\n", FILE_APPEND);

				// Session present 0, return code 0 (accepted)
				fwrite($connection, "\x20\x02\x00\x00");

				if ($behaviour === 'drop')
				{
					break;
				}

				continue;
			}

			if ($type === 3)
			{
				// Anything above QoS 0 would need an acknowledgement back, and MqttPublisher
				// publishes at QoS 0 deliberately - recording it is more useful than hanging
				$qos = ($flags >> 1) & 3;

				if ($qos !== 0)
				{
					file_put_contents($logFile, '=== unsupported qos ' . $qos . "\n", FILE_APPEND);

					break;
				}

				$topicLength = (ord($rest[0]) << 8) | ord($rest[1]);

				file_put_contents($logFile, substr($rest, 2, $topicLength) . "\t"
					. strlen(substr($rest, 2 + $topicLength)) . "\n", FILE_APPEND);

				continue;
			}

			if ($type === 14)
			{
				break;
			}

			file_put_contents($logFile, '=== unhandled packet type ' . $type . "\n", FILE_APPEND);
		}

		// Only once every packet of this connection has been recorded: this is what tells a
		// reader the batch is complete rather than merely current
		file_put_contents($logFile, "=== end\n", FILE_APPEND);

		fclose($connection);
	}
}

// -------------------------------------------------------------------------------------
// The scenario
// -------------------------------------------------------------------------------------

/**
 * Boots the application against the calling test class's schema and runs the steps.
 */
function RunScenario(array $steps, string $resultFile): void
{
	if ($resultFile === '')
	{
		fwrite(STDERR, "scenario: need a result file\n");
		exit(1);
	}

	define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
	define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));

	require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
	require_once VICTUAL_DATAPATH . '/config.php';
	require_once VICTUAL_ROOT_PATH . '/config-dist.php';

	if (!defined('VICTUAL_USER_ID'))
	{
		define('VICTUAL_USER_ID', 1);
	}

	$pdo = new PDO(
		'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
		getenv('PGUSER'),
		getenv('PGPASSWORD'),
		[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
	);
	$pdo->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');
	DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);
	(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
	(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

	$result = ['steps' => [], 'ledger' => [], 'flags' => [], 'error' => null];
	$renamed = false;
	$ledgerRenamed = false;
	$ledgerWriteBroken = false;

	try
	{
		foreach ($steps as $index => $step)
		{
			if ($step === '')
			{
				continue;
			}

			$argument = str_contains($step, ':') ? substr($step, strpos($step, ':') + 1) : '';

			switch (explode(':', $step)[0])
			{
				case 'reset':
					$pdo->exec('DELETE FROM mqtt_published_entities');
					$pdo->exec('DELETE FROM mqtt_product_entities');
					$pdo->exec('UPDATE products SET active = 1');
					$value = true;
					break;

				case 'flag':
					$statement = $pdo->prepare('INSERT INTO mqtt_product_entities (product_id) VALUES (?)');
					$statement->execute([(int)$argument]);
					$value = true;
					break;

				case 'deactivate':
					$statement = $pdo->prepare('UPDATE products SET active = 0 WHERE id = ?');
					$statement->execute([(int)$argument]);
					$value = true;
					break;

				case 'breakledger':
					$pdo->exec('ALTER TABLE mqtt_published_entities RENAME TO mqtt_published_entities_hidden');
					$ledgerRenamed = true;
					$value = true;
					break;

				case 'restoreledger':
					$pdo->exec('ALTER TABLE mqtt_published_entities_hidden RENAME TO mqtt_published_entities');
					$ledgerRenamed = false;
					$value = true;
					break;

				case 'breakledgerwrite':
					$pdo->exec('CREATE FUNCTION mqtt_ledger_write_blocked() RETURNS TRIGGER LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'ledger write blocked\'; END $$');
					$ledgerWriteBroken = true;
					$pdo->exec('CREATE TRIGGER mqtt_published_entities_write_blocked BEFORE INSERT OR UPDATE ON mqtt_published_entities FOR EACH ROW EXECUTE FUNCTION mqtt_ledger_write_blocked()');
					$value = true;
					break;

				case 'restoreledgerwrite':
					$pdo->exec('DROP TRIGGER mqtt_published_entities_write_blocked ON mqtt_published_entities');
					$pdo->exec('DROP FUNCTION mqtt_ledger_write_blocked()');
					$ledgerWriteBroken = false;
					$value = true;
					break;

				case 'rename':
					$statement = $pdo->prepare("UPDATE products SET name = name || ' (renamed)' WHERE id = ?");
					$statement->execute([(int)$argument]);
					$value = true;
					break;

				case 'enabled':
					$value = MqttStatePublicationService::IsEnabled();
					break;

				case 'requestend':
					$value = MqttStatePublicationService::PublishForRequestEnd();
					break;

				case 'suppress':
					MqttStatePublicationService::SuppressRequestEndPublish();
					$value = true;
					break;

				case 'state':
					$value = MqttStatePublicationService::PublishState();
					break;

				case 'full':
					$value = MqttStatePublicationService::PublishDiscoveryAndState();
					break;

				case 'retract':
					$value = MqttStatePublicationService::Retract();
					break;

				case 'emptybatch':
					$value = (new MqttPublisher())->PublishBatch([]);
					break;

				case 'breakassembly':
					// Not a transaction: a failed statement inside one poisons the
					// connection, and the publication lock is released through the same
					// connection on the way out, so the rollback would have to happen
					// before the code under test finished with it.
					$pdo->exec('ALTER VIEW uihelper_stock_current_overview RENAME TO uihelper_stock_current_overview_hidden');
					$renamed = true;
					$value = true;
					break;

				case 'restoreassembly':
					$pdo->exec('ALTER VIEW uihelper_stock_current_overview_hidden RENAME TO uihelper_stock_current_overview');
					$renamed = false;
					$value = true;
					break;

				default:
					throw new \Exception('unknown step "' . $step . '"');
			}

			$result['steps'][$index . ':' . $step] = $value;
		}
	}
	catch (\Throwable $ex)
	{
		$result['error'] = get_class($ex) . ': ' . $ex->getMessage();
	}
	finally
	{
		// Restored however the steps ended, because the schema outlives this process: a
		// scenario that broke something and then threw would otherwise take every test after
		// it down with it, and the failure would read as a defect in whatever ran next.
		if ($renamed)
		{
			$pdo->exec('ALTER VIEW uihelper_stock_current_overview_hidden RENAME TO uihelper_stock_current_overview');
		}

		if ($ledgerRenamed)
		{
			$pdo->exec('ALTER TABLE mqtt_published_entities_hidden RENAME TO mqtt_published_entities');
		}

		if ($ledgerWriteBroken)
		{
			$pdo->exec('DROP TRIGGER IF EXISTS mqtt_published_entities_write_blocked ON mqtt_published_entities');
			$pdo->exec('DROP FUNCTION IF EXISTS mqtt_ledger_write_blocked()');
		}
	}

	foreach ($pdo->query('SELECT object_id, payload_hash FROM mqtt_published_entities ORDER BY object_id') as $row)
	{
		$result['ledger'][(string)$row['object_id']] = (string)$row['payload_hash'];
	}

	foreach ($pdo->query('SELECT product_id FROM mqtt_product_entities ORDER BY product_id') as $row)
	{
		$result['flags'][] = (int)$row['product_id'];
	}

	file_put_contents($resultFile, json_encode($result));
}
