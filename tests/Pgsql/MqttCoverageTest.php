<?php

namespace Victual\Tests\Pgsql;

use Victual\Services\Mqtt\MqttPublisher;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * The parts of MQTT state publication the .devtools/mqtt/ probes do not reach: what happens
 * when publication is off, when the broker refuses the connection or hangs up halfway, when
 * the snapshot cannot be assembled at all, and what --retract actually clears.
 *
 * The probes in .devtools/mqtt/ own the happy paths and eight named silent defects - a stale
 * retained topic, a lost event, a redelivered point, a client id that lost its randomness, a
 * payload written out as zeros, a rewound changed time, an event delivered to a login page,
 * and a boot publish that skipped the per-product topics. Nothing here repeats one of those.
 * What is here is the failure half, and it is worth having for the same reason the probes
 * are: every one of these paths is designed to fail quietly, because a broker is never
 * allowed to turn a committed write into an error response. A publisher that swallowed the
 * failure *and* recorded the batch as sent would be indistinguishable from one that worked,
 * until a wall tablet started showing last month's stock.
 *
 * Driven against a stand-in broker (tests/Pgsql/mqttcoverage-subprocess-helper.php), which is
 * what keeps this phase dependency-free - no mosquitto, no outbound network, and the broker
 * address is a configured constant that nothing derived from a request reaches.
 *
 * One subprocess per scenario because each one needs a different value of a VICTUAL_MQTT_*
 * constant and PHP cannot redefine a constant.
 */
class MqttCoverageTest extends PgsqlSchemaTestCase
{
	/**
	 * Two products, because "retracted the one that went" has to be distinguishable from
	 * "retracted everything". Fixed ids so a scenario can name one without a lookup.
	 */
	private const PRODUCT_GONE = 9101;
	private const PRODUCT_STAYS = 9102;

	/**
	 * The eight ambient state topics, from docs/plans/18-mqtt-state-publication.md's topic
	 * layout. Written out rather than derived from DiscoveryPayloadBuilder, because a test
	 * that derives its expectation from the code under test asserts nothing.
	 */
	private const AMBIENT_STATE_TOPICS = [
		'victual/state/stock',
		'victual/state/shopping_list',
		'victual/state/next_chore',
		'victual/state/next_battery',
		'victual/state/next_task',
		'victual/state/products_due_soon',
		'victual/state/products_expired',
		'victual/state/last_published'
	];

	/**
	 * Every discovery config topic this version owns - the device-mode one and all eight
	 * entity-mode ones. Retraction clears both modes, since switching MQTT_DISCOVERY_MODE
	 * would otherwise strand the other mode's retained config forever.
	 */
	private const ALL_DISCOVERY_TOPICS = [
		'homeassistant/device/victual/config',
		'homeassistant/sensor/victual/stock/config',
		'homeassistant/sensor/victual/shopping_list/config',
		'homeassistant/sensor/victual/next_chore/config',
		'homeassistant/sensor/victual/next_battery/config',
		'homeassistant/sensor/victual/next_task/config',
		'homeassistant/sensor/victual/products_due_soon/config',
		'homeassistant/sensor/victual/products_expired/config',
		'homeassistant/sensor/victual/last_published/config'
	];

	private static string $scratch;
	private static string $brokerLog;
	private static string $droppingBrokerLog;
	private static int $brokerPort;
	private static int $droppingBrokerPort;
	private static int $refusedPort;

	/** @var resource|null */
	private static $brokerProcess = null;

	/** @var resource|null */
	private static $droppingBrokerProcess = null;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$pdo = self::Pdo();
		$pdo->exec("INSERT INTO users(id, username, password) VALUES (9000, 'mqttcoverage-caller', 'fixture')");

		// Location 2 (Fridge) and quantity unit 2 (Piece) come from the initial data seed
		$pdo->exec('INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock) VALUES '
			. '(' . self::PRODUCT_GONE . ", 'Mqtt coverage: goes away', 2, 2, 2), "
			. '(' . self::PRODUCT_STAYS . ", 'Mqtt coverage: stays', 2, 2, 2)");

		self::$scratch = VICTUAL_DATAPATH . '/mqttcoverage';

		if (!is_dir(self::$scratch))
		{
			mkdir(self::$scratch, 0755, true);
		}

		self::$brokerLog = self::$scratch . '/broker.log';
		self::$droppingBrokerLog = self::$scratch . '/dropping-broker.log';

		self::$brokerPort = self::ReservePort();
		self::$droppingBrokerPort = self::ReservePort();

		// Reserved and then deliberately left unbound: a port nothing listens on is refused
		// immediately, which is what "the broker is not there" looks like without spending
		// the configured connect timeout on every scenario that needs it.
		self::$refusedPort = self::ReservePort();

		// PHPUnit does not call tearDownAfterClass when setUpBeforeClass raises, so a broker
		// that started before the failure would outlive the run holding its port. Whatever
		// got as far as a process is stopped here instead.
		try
		{
			self::StartBroker(self::$brokerPort, self::$brokerLog, 'record', self::$brokerProcess);
			self::StartBroker(self::$droppingBrokerPort, self::$droppingBrokerLog, 'drop',
				self::$droppingBrokerProcess);
		}
		catch (\Throwable $failure)
		{
			self::StopBrokers();

			throw $failure;
		}
	}

	public static function tearDownAfterClass(): void
	{
		self::StopBrokers();

		parent::tearDownAfterClass();
	}

	/** Stops whichever stand-in brokers are running. Safe to call twice, and with neither. */
	private static function StopBrokers(): void
	{
		foreach ([self::$brokerProcess, self::$droppingBrokerProcess] as $process)
		{
			if (is_resource($process))
			{
				proc_terminate($process);
				proc_close($process);
			}
		}

		self::$brokerProcess = null;
		self::$droppingBrokerProcess = null;
	}

	// ---------------------------------------------------------------------------------
	// Publication switched off
	// ---------------------------------------------------------------------------------

	/**
	 * MQTT_ENABLED false is the default, and it has to cost nothing and touch nothing: the
	 * constant is read so that a fork with MQTT off pays one constant lookup and no query,
	 * no connection and no row. Every entry point reports false rather than throwing, which
	 * is what lets bin/victual-publish-state exit 0 on an installation that never configured
	 * a broker.
	 */
	public function testEveryEntryPointIsASilentNoOpWhenPublicationIsOff(): void
	{
		$result = self::RunScenario(
			['reset', 'flag:' . self::PRODUCT_STAYS, 'enabled', 'requestend', 'state', 'full', 'retract'],
			['VICTUAL_MQTT_ENABLED' => 'false', 'VICTUAL_MQTT_HOST' => '127.0.0.1', 'VICTUAL_MQTT_PORT' => (string)self::$brokerPort]
		);

		self::assertNull($result['error'], 'nothing may throw out of the publication surface');
		self::assertFalse($result['steps']['2:enabled'], 'MQTT_ENABLED false means publication is off');
		self::assertFalse($result['steps']['3:requestend'], 'the request-end trigger publishes nothing');
		self::assertFalse($result['steps']['4:state'], 'an explicit state publish publishes nothing');
		self::assertFalse($result['steps']['5:full'], 'a full refresh publishes nothing');
		self::assertFalse($result['steps']['6:retract'], 'a retraction retracts nothing');

		self::assertSame('', trim((string)file_get_contents(self::$brokerLog)),
			'a disabled installation must not open a broker connection at all');
		self::assertSame([], $result['ledger'], 'and must not write the publication ledger');
	}

	// ---------------------------------------------------------------------------------
	// The request-end trigger
	// ---------------------------------------------------------------------------------

	/**
	 * The trigger DatabaseService's shutdown handler fires after a request that changed data.
	 * It publishes the state snapshot and deliberately not the discovery payloads: discovery
	 * is a literal that does not change between requests, and republishing it on every write
	 * would make Home Assistant reprocess the device on every purchase.
	 *
	 * The discovery topic's absence is the negative control - "it published something" would
	 * pass just as well if it had published everything.
	 */
	public function testTheRequestEndTriggerPublishesStateButNotDiscovery(): void
	{
		$result = self::RunScenario(['reset', 'requestend'], self::BrokerSettings(self::$brokerPort), self::$brokerLog);

		self::assertNull($result['error']);
		self::assertTrue($result['steps']['1:requestend'], 'a request that changed data publishes a snapshot');

		$batch = self::ReadBatch(self::$brokerLog);

		foreach (self::AMBIENT_STATE_TOPICS as $topic)
		{
			self::assertArrayHasKey($topic, $batch['topics'], 'the snapshot carries every ambient state topic');
			self::assertGreaterThan(0, $batch['topics'][$topic], "$topic must carry a payload, not a retraction");
		}

		foreach (self::ALL_DISCOVERY_TOPICS as $topic)
		{
			self::assertArrayNotHasKey($topic, $batch['topics'],
				'the request-end publish must not republish discovery on every write');
		}
	}

	/**
	 * bin/victual-publish-state suppresses the trigger before it publishes, so that one CLI
	 * run cannot publish twice and have the two races for the same retained topics. After
	 * that suppression the trigger must do nothing at all - not publish a smaller batch, not
	 * open a connection.
	 */
	public function testSuppressionStopsTheRequestEndTriggerCompletely(): void
	{
		$result = self::RunScenario(['reset', 'suppress', 'requestend'], self::BrokerSettings(self::$brokerPort));

		self::assertNull($result['error']);
		self::assertFalse($result['steps']['2:requestend'], 'a suppressed trigger reports that it published nothing');
		self::assertSame('', trim((string)file_get_contents(self::$brokerLog)),
			'and opens no broker connection, so an explicit publish cannot be raced by the trigger behind it');
	}

	// ---------------------------------------------------------------------------------
	// The broker is not there
	// ---------------------------------------------------------------------------------

	/**
	 * The property the whole design rests on: a failed publish is not recorded as done.
	 *
	 * The ledger is what makes the next incremental publish skip a product, so recording a
	 * batch the broker never received would leave that product's entity missing from Home
	 * Assistant until its payload happened to change - which for a product nobody buys is
	 * never. It fails silently in every other respect, on purpose, which is exactly why this
	 * one consequence has to be asserted rather than assumed.
	 *
	 * The second half is the negative control: the same publish against a broker that is
	 * there does record it, so the empty ledger above is a refusal rather than a publish that
	 * never ran.
	 */
	public function testAFailedPublishIsNotRecordedInTheLedger(): void
	{
		$failed = self::RunScenario(
			['reset', 'flag:' . self::PRODUCT_STAYS, 'full'],
			self::BrokerSettings(self::$refusedPort)
		);

		self::assertNull($failed['error'], 'an unreachable broker must not throw into the caller');
		self::assertFalse($failed['steps']['2:full'], 'a publish that did not reach the broker reports failure');
		self::assertSame([], $failed['ledger'], 'and records nothing as published');
		self::assertSame([self::PRODUCT_STAYS], $failed['flags'],
			'and leaves the opt-in flag alone - the entity was never retracted, so its flag is not an orphan');

		$succeeded = self::RunScenario(['full'], self::BrokerSettings(self::$brokerPort), self::$brokerLog);

		self::assertTrue($succeeded['steps']['0:full'], 'the same publish against a reachable broker succeeds');
		self::assertArrayHasKey('product_' . self::PRODUCT_STAYS, $succeeded['ledger'],
			'and only then is the product recorded as published');
	}

	/**
	 * A broker that accepts the connection and then hangs up is the nastier half of the same
	 * property: the publisher believes it is connected, so a failure part way through the
	 * batch is the one most likely to be reported as success.
	 */
	public function testABrokerThatHangsUpMidBatchIsNotRecordedAsDelivered(): void
	{
		$result = self::RunScenario(
			['reset', 'flag:' . self::PRODUCT_STAYS, 'full'],
			self::BrokerSettings(self::$droppingBrokerPort)
		);

		self::assertNull($result['error'], 'a broker that disappears mid-batch must not throw into the caller');

		// Without this the test would pass just as well against a broker that was never
		// there, which is a different failure and one the test above already owns
		self::assertCount(1, self::ReadBatch(self::$droppingBrokerLog)['connects'],
			'the connection was accepted first, so this is a batch that failed part way through');

		self::assertFalse($result['steps']['2:full'], 'a batch that was not delivered whole reports failure');
		self::assertSame([], $result['ledger'], 'and records nothing as published');
	}

	/**
	 * Publishing a product whose payload changed updates its ledger row rather than adding a
	 * second one.
	 *
	 * object_id is unique in mqtt_published_entities, so a publisher that inserted instead
	 * would throw on the second publish of any product that ever changes - which is every
	 * product a household actually uses. The hash is what the next incremental publish
	 * compares against, so it has to be the new one.
	 */
	public function testRepublishingAChangedProductUpdatesItsLedgerRowInPlace(): void
	{
		$first = self::RunScenario(
			['reset', 'flag:' . self::PRODUCT_STAYS, 'full'],
			self::BrokerSettings(self::$brokerPort),
			self::$brokerLog
		);

		$objectId = 'product_' . self::PRODUCT_STAYS;
		self::assertArrayHasKey($objectId, $first['ledger']);

		$second = self::RunScenario(
			['rename:' . self::PRODUCT_STAYS, 'state'],
			self::BrokerSettings(self::$brokerPort),
			self::$brokerLog
		);

		self::assertNull($second['error']);
		self::assertTrue($second['steps']['1:state'], 'the changed product is published again');
		self::assertSame([$objectId], array_keys($second['ledger']), 'one ledger row, not two');
		self::assertNotSame($first['ledger'][$objectId], $second['ledger'][$objectId],
			'and it carries the new payload hash, so the next publish diffs against what was really sent');
	}

	/**
	 * A retraction that did not reach the broker must leave the ledger intact, for the mirror
	 * of the reason above: the ledger is the only record that the per-product topics exist at
	 * all, so forgetting them after a failed retraction would strand every one of them
	 * retained on the broker with nothing left that knows to clear them.
	 */
	public function testAFailedRetractionLeavesTheLedgerIntact(): void
	{
		$published = self::RunScenario(
			['reset', 'flag:' . self::PRODUCT_STAYS, 'full'],
			self::BrokerSettings(self::$brokerPort),
			self::$brokerLog
		);

		self::assertSame(['product_' . self::PRODUCT_STAYS], array_keys($published['ledger']));

		$retraction = self::RunScenario(['retract'], self::BrokerSettings(self::$refusedPort));

		self::assertNull($retraction['error']);
		self::assertFalse($retraction['steps']['0:retract'], 'a retraction that did not reach the broker reports failure');
		self::assertSame($published['ledger'], $retraction['ledger'],
			'and the ledger still remembers the topics that are still out there');
	}

	// ---------------------------------------------------------------------------------
	// Retraction
	// ---------------------------------------------------------------------------------

	/**
	 * What bin/victual-publish-state --retract promises: every retained topic this version
	 * owns is cleared, in both discovery modes plus every per-product topic the ledger
	 * remembers, by publishing an empty retained payload to each. An empty payload on a
	 * discovery config topic is how Home Assistant is told to remove the entity.
	 *
	 * Both modes rather than only the configured one, because switching MQTT_DISCOVERY_MODE
	 * strands the other mode's retained config, and a config topic nobody rewrites is this
	 * design's one operational wart.
	 *
	 * The payload length is the assertion that matters: a topic published with content would
	 * look identical in a list of topic names and would resurrect the entity rather than
	 * remove it.
	 */
	public function testRetractionClearsEveryTopicThisVersionOwns(): void
	{
		self::RunScenario(
			['reset', 'flag:' . self::PRODUCT_STAYS, 'full'],
			self::BrokerSettings(self::$brokerPort),
			self::$brokerLog
		);

		$result = self::RunScenario(['retract'], self::BrokerSettings(self::$brokerPort), self::$brokerLog);

		self::assertNull($result['error']);
		self::assertTrue($result['steps']['0:retract'], 'the retraction reports success');

		$batch = self::ReadBatch(self::$brokerLog);

		$expected = array_merge(
			self::ALL_DISCOVERY_TOPICS,
			self::AMBIENT_STATE_TOPICS,
			[
				'homeassistant/sensor/victual/product_' . self::PRODUCT_STAYS . '/config',
				'victual/state/product/' . self::PRODUCT_STAYS
			]
		);

		foreach ($expected as $topic)
		{
			self::assertArrayHasKey($topic, $batch['topics'], "$topic must be cleared by a retraction");
			self::assertSame(0, $batch['topics'][$topic],
				"$topic must be cleared with an empty payload - anything else republishes the entity");
		}

		self::assertSame([], $result['ledger'], 'a full retraction empties the ledger');
		self::assertSame([self::PRODUCT_STAYS], $result['flags'],
			'but not the opt-in flags: retracting the topics is not the household opting the product out');
	}

	/**
	 * A product that goes away - deleted, deactivated, or its opt-in flag cleared - has its
	 * two topics retracted on the next publish, and only then is its flag row dropped. This
	 * is the cascade migration 0257 deliberately does not express as a foreign key.
	 *
	 * The product that stayed is the negative control twice over: its topics must be absent
	 * (the incremental path still diffs, so an unchanged product is not resent) and its
	 * ledger row and flag must survive.
	 */
	public function testADeactivatedProductIsRetractedAndItsFlagDropped(): void
	{
		$published = self::RunScenario(
			['reset', 'flag:' . self::PRODUCT_GONE, 'flag:' . self::PRODUCT_STAYS, 'full'],
			self::BrokerSettings(self::$brokerPort),
			self::$brokerLog
		);

		self::assertSame(
			['product_' . self::PRODUCT_GONE, 'product_' . self::PRODUCT_STAYS],
			array_keys($published['ledger']),
			'both opted-in products are published to begin with'
		);

		$result = self::RunScenario(
			['deactivate:' . self::PRODUCT_GONE, 'state'],
			self::BrokerSettings(self::$brokerPort),
			self::$brokerLog
		);

		self::assertNull($result['error']);
		self::assertTrue($result['steps']['1:state']);

		$batch = self::ReadBatch(self::$brokerLog);

		foreach (['homeassistant/sensor/victual/product_' . self::PRODUCT_GONE . '/config',
			'victual/state/product/' . self::PRODUCT_GONE] as $topic)
		{
			self::assertArrayHasKey($topic, $batch['topics'], "$topic must be retracted once the product is gone");
			self::assertSame(0, $batch['topics'][$topic], "$topic must be retracted with an empty payload");
		}

		foreach (['homeassistant/sensor/victual/product_' . self::PRODUCT_STAYS . '/config',
			'victual/state/product/' . self::PRODUCT_STAYS] as $topic)
		{
			self::assertArrayNotHasKey($topic, $batch['topics'],
				"$topic did not change, so an incremental publish must not resend it");
		}

		self::assertSame(['product_' . self::PRODUCT_STAYS], array_keys($result['ledger']),
			'the gone product is forgotten and the other is not');
		self::assertSame([self::PRODUCT_STAYS], $result['flags'],
			'and its orphaned opt-in flag is dropped, after its topics were retracted');
	}

	/**
	 * The latent gap issue #463 part 2 describes: RetractLocked() only knows how to build a
	 * retraction for the "product_" prefix, but before this fix it forgot every ledger row
	 * regardless of kind. Record() is only ever called from the per-product loop today, so no
	 * other kind exists yet - this pins the behaviour for the day one does, by seeding a row
	 * directly the way PublishLocked() never does.
	 *
	 * Forgetting a row that was never retracted would drop the ledger's only record that its
	 * retained topic exists, so nothing could ever clear it again - precisely the stale-retained-
	 * topic failure the ledger exists to prevent. The fix keeps the row instead of the whole-ledger
	 * ForgetAll() this replaced.
	 */
	public function testRetractionForgetsOnlyTheRowsItRetracted(): void
	{
		self::RunScenario(
			['reset', 'flag:' . self::PRODUCT_STAYS, 'full'],
			self::BrokerSettings(self::$brokerPort),
			self::$brokerLog
		);

		$seeded = self::RunScenario(
			['seedledgerrow:mystery_object:deadbeef'],
			self::BrokerSettings(self::$refusedPort)
		);

		self::assertNull($seeded['error']);
		self::assertSame(
			['mystery_object', 'product_' . self::PRODUCT_STAYS],
			array_keys($seeded['ledger']),
			'both the seeded row and the earlier publish are in the ledger to begin with'
		);

		$result = self::RunScenario(['retract'], self::BrokerSettings(self::$brokerPort), self::$brokerLog);

		self::assertNull($result['error'], 'an unretractable ledger row must not throw out of the service');
		self::assertTrue($result['steps']['0:retract'], 'the retraction still succeeds for the kind it knows');

		$batch = self::ReadBatch(self::$brokerLog);

		self::assertArrayHasKey('homeassistant/sensor/victual/product_' . self::PRODUCT_STAYS . '/config',
			$batch['topics'], 'the product entity is retracted as normal');

		self::assertSame(['mystery_object'], array_keys($result['ledger']),
			'the product row is forgotten because it was retracted, and the unknown-kind row is'
				. ' kept because it was not - forgetting it would orphan its retained topic forever');
	}

	// ---------------------------------------------------------------------------------
	// The snapshot cannot be assembled
	// ---------------------------------------------------------------------------------

	/**
	 * When the snapshot cannot be assembled, nothing is published - not even the part that
	 * assembled fine.
	 *
	 * Refusing is the right direction to fail in and the reason is the retain flag: a missing
	 * snapshot is repaired by the next write, and a half snapshot sits on the broker being
	 * wrong until somebody notices. Provoked here by taking away the view the stock entity is
	 * read from, which stands in for every way the read can fail; the assertion is about what
	 * the publisher does with the failure, not about that particular view.
	 */
	public function testASnapshotThatCannotBeAssembledPublishesNothing(): void
	{
		$result = self::RunScenario(
			['reset', 'flag:' . self::PRODUCT_STAYS, 'breakassembly', 'state', 'restoreassembly'],
			self::BrokerSettings(self::$brokerPort)
		);

		self::assertNull($result['error'], 'an unassemblable snapshot must not throw into the caller');
		self::assertFalse($result['steps']['3:state'], 'it reports that nothing was published');
		self::assertSame('', trim((string)file_get_contents(self::$brokerLog)),
			'and publishes nothing at all, rather than the entities that did assemble');
		self::assertSame([], $result['ledger'], 'and records nothing');
	}

	/**
	 * DEFECT: a publish whose *ledger* read fails throws out of the service, where a publish
	 * whose *snapshot* read fails does not.
	 *
	 * The class says "Nothing here throws" (services/Mqtt/MqttStatePublicationService.php:27)
	 * and it is the property the whole after-commit seam rests on: the trigger runs from
	 * DatabaseService's shutdown handler (services/DatabaseService.php:655), which does not
	 * wrap the call the way it wraps the changed-time flush eight lines above it. Two
	 * database reads happen one after the other in PublishLocked() - the snapshot at :165,
	 * inside a try that logs and returns false, and the ledger at :185, outside it - and only
	 * the first is caught. Both fail the same way when the database goes away between the
	 * commit and the end of the request, which is the one moment this code runs in.
	 *
	 * The expected behaviour is the one the neighbouring path already has: log, return false,
	 * publish nothing. Asserted as it currently behaves rather than skipped, so that fixing it
	 * fails here and says so; the fix is application code and out of scope for this work.
	 */
	public function testALedgerReadFailureLogsAndReturnsFalseWithoutThrowing(): void
	{
		$result = self::RunScenario(
			['reset', 'breakledger', 'state', 'restoreledger'],
			self::BrokerSettings(self::$brokerPort)
		);

		self::assertNull($result['error'],
			'a ledger read failure must not throw out of the service');
		self::assertFalse($result['steps']['2:state'],
			'it reports that the publish failed');
		self::assertSame('', trim((string)file_get_contents(self::$brokerLog)),
			'and publishes nothing at all');
		self::assertSame([], $result['ledger'], 'and records nothing');
	}

	/**
	 * A ledger write failure happens after the broker has already accepted the batch, so the
	 * messages are published but unrecorded. This is still a failure to return false and not
	 * throw, and the next publish will retry the recording.
	 */
	public function testALedgerWriteFailureLogsAndReturnsFalseWithoutThrowing(): void
	{
		$result = self::RunScenario(
			['reset', 'flag:' . self::PRODUCT_STAYS, 'breakledgerwrite', 'full', 'restoreledgerwrite'],
			self::BrokerSettings(self::$brokerPort),
			self::$brokerLog
		);

		self::assertNull($result['error'],
			'a ledger write failure must not throw out of the service');
		self::assertFalse($result['steps']['3:full'],
			'it reports that the publish failed even though the broker accepted it');
		self::assertNotSame('', trim((string)file_get_contents(self::$brokerLog)),
			'the messages were published to the broker');
		self::assertSame([], $result['ledger'],
			'but the ledger was not updated, so the next publish will retry it');
	}

	// ---------------------------------------------------------------------------------
	// The publication lock itself (issue #463 part 1)
	// ---------------------------------------------------------------------------------

	/**
	 * The gap #449 left: everything *inside* WithPublicationLock() honours the no-throw
	 * contract, but WithPublicationLock() itself - the advisory lock around the whole
	 * assemble-publish-record cycle - did not. A connection that cannot even take the lock
	 * (services/Database/PostgresDialect.php:297-311, called before its own try) threw a
	 * PDOException straight out of Publish(), which the request-end trigger called unwrapped
	 * (services/DatabaseService.php:655), which skipped BookingEventPublisher::WriteForRequestEnd()
	 * on the line after it.
	 *
	 * This is the half of that gap Publish() itself can be tested for in isolation: a lock that
	 * cannot be acquired must publish nothing and must not throw.
	 */
	public function testALockAcquisitionFailureLogsAndReturnsFalseWithoutThrowing(): void
	{
		$result = self::RunScenario(
			['reset', 'flag:' . self::PRODUCT_STAYS, 'breaklockacquire', 'full', 'restorelock'],
			self::BrokerSettings(self::$brokerPort)
		);

		self::assertNull($result['error'],
			'a lock acquisition failure must not throw out of the service');
		self::assertFalse($result['steps']['3:full'],
			'it reports that the publish failed');
		self::assertSame('', trim((string)file_get_contents(self::$brokerLog)),
			'and never contacts the broker at all - the assembly and publish never ran');
		self::assertSame([], $result['ledger'], 'and records nothing');
	}

	/**
	 * The other half of the same gap: WithPublicationLock() releases the lock in a finally that
	 * runs even when the body succeeded, and that release is a database call too. A connection
	 * that drops between the body finishing and the unlock statement throws from the finally,
	 * which discards the body's own return value and propagates instead - so by the time
	 * Publish() sees it, the broker has already accepted the batch and the ledger has already
	 * been updated, and the failure is only in the lock's own bookkeeping.
	 *
	 * The class's contract - never throw - has to hold even here: the caller sees a failed
	 * publish it will not retry into a worse state, even though the work underneath it in fact
	 * completed.
	 */
	public function testALockReleaseFailureLogsAndReturnsFalseEvenThoughThePublishSucceeded(): void
	{
		$result = self::RunScenario(
			['reset', 'flag:' . self::PRODUCT_STAYS, 'breaklockrelease', 'full', 'restorelock'],
			self::BrokerSettings(self::$brokerPort),
			self::$brokerLog
		);

		self::assertNull($result['error'],
			'a lock release failure must not throw out of the service');
		self::assertFalse($result['steps']['3:full'],
			'it reports failure - the caller cannot tell this apart from a publish that never ran');
		self::assertNotSame('', trim((string)file_get_contents(self::$brokerLog)),
			'even though the messages really were published to the broker');
		self::assertArrayHasKey('product_' . self::PRODUCT_STAYS, $result['ledger'],
			'and the ledger really was updated - only the lock release itself failed');
	}

	/**
	 * The reason both halves above matter beyond MqttStatePublicationService itself:
	 * DatabaseService's shutdown handler calls the MQTT request-end publish and then
	 * BookingEventPublisher::WriteForRequestEnd() on the next line
	 * (services/DatabaseService.php, formerly :655-656), and on master neither call is
	 * wrapped - unlike FlushDbChangedTime() just above them. An uncaught throwable from the
	 * MQTT step (which the lock could produce before this fix, and which any future bug in it
	 * could still produce) would skip the InfluxDB drain entirely, leaving that request's
	 * outbox rows undelivered until some later request happened to trigger a drain of its own.
	 *
	 * Driven through RunRequestEndPublishes() directly with the MQTT step forced to throw
	 * (ThrowingMqttDatabaseService in the subprocess helper), because register_shutdown_function()
	 * only ever fires at the real end of a PHP process - nothing a test can trigger on demand -
	 * and because forcing an actual advisory-lock failure to survive both this isolation and
	 * MqttStatePublicationService::Publish()'s own catch at once would not tell them apart.
	 */
	public function testTheInfluxDrainStillRunsWhenTheRequestEndMqttStepThrows(): void
	{
		$result = self::RunScenario(['shutdownisolation'], self::BrokerSettings(self::$refusedPort));

		self::assertNull($result['error'],
			'the isolation must absorb the throw - it must not escape RunRequestEndPublishes()');
		self::assertTrue($result['steps']['0:shutdownisolation'],
			'the InfluxDB drain ran even though the MQTT step immediately above it threw');
	}

	// ---------------------------------------------------------------------------------
	// The transport
	// ---------------------------------------------------------------------------------

	/**
	 * A batch with no topics in it needs no broker. The ambient half of a snapshot is never
	 * empty, so this is reached through PublishBatch() directly - which is also how a caller
	 * with nothing to say should be able to use it.
	 *
	 * In process rather than in a subprocess deliberately: the point is that the empty batch
	 * is answered before any connection setting is read at all, so an installation with no
	 * broker configured cannot be made to dial one.
	 */
	public function testAnEmptyBatchIsSuccessfulWithoutContactingTheBroker(): void
	{
		file_put_contents(self::$brokerLog, '');

		self::assertTrue((new MqttPublisher())->PublishBatch([]), 'an empty batch has nothing to fail at');
		self::assertSame('', trim((string)file_get_contents(self::$brokerLog)),
			'and opens no connection');
	}

	/**
	 * Configured broker credentials reach the CONNECT packet.
	 *
	 * A broker that requires authentication and a publisher that quietly sent none would be
	 * indistinguishable, from the application's side, from a broker that is down - both are
	 * one swallowed failure and a log line. So the username is asserted on the wire rather
	 * than through the settings object.
	 *
	 * The empty password is the boundary: MQTT_PASSWORD defaults to empty, and an anonymous
	 * broker must see no password field rather than a zero-length one.
	 */
	public function testConfiguredCredentialsReachTheConnectPacket(): void
	{
		$withPassword = self::RunScenario(
			['reset', 'state'],
			self::BrokerSettings(self::$brokerPort, [
				'VICTUAL_MQTT_USERNAME' => 'victual-mqtt-fixture',
				'VICTUAL_MQTT_PASSWORD' => 'fixture-broker-password'
			]),
			self::$brokerLog
		);

		self::assertTrue($withPassword['steps']['1:state']);

		$batch = self::ReadBatch(self::$brokerLog);

		self::assertCount(1, $batch['connects'], 'one connection per batch');
		self::assertSame('victual-mqtt-fixture', $batch['connects'][0]['username'],
			'the configured username is what the broker is offered');
		self::assertSame(strlen('fixture-broker-password'), $batch['connects'][0]['password_length'],
			'and the configured password goes with it');

		$withoutPassword = self::RunScenario(
			['state'],
			self::BrokerSettings(self::$brokerPort, ['VICTUAL_MQTT_USERNAME' => 'victual-mqtt-fixture']),
			self::$brokerLog
		);

		self::assertTrue($withoutPassword['steps']['0:state']);

		$batch = self::ReadBatch(self::$brokerLog);

		self::assertSame('victual-mqtt-fixture', $batch['connects'][0]['username']);
		self::assertSame(0, $batch['connects'][0]['password_length'],
			'an empty MQTT_PASSWORD is no password at all, not an empty one');
	}

	// ---------------------------------------------------------------------------------
	// Harness
	// ---------------------------------------------------------------------------------

	/**
	 * A port the operating system says is free, left unbound.
	 *
	 * Asking for port 0 and reading back what was assigned is the only way to get one without
	 * guessing; the window between closing this socket and the stand-in binding it is the
	 * same window every fixed-port test tool lives with.
	 */
	private static function ReservePort(): int
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

		if ($socket === false)
		{
			self::fail('could not reserve a port: ' . $errorMessage);
		}

		$name = (string)stream_socket_get_name($socket, false);
		fclose($socket);

		return (int)substr($name, strrpos($name, ':') + 1);
	}

	/**
	 * Starts a stand-in broker and waits for it to bind.
	 *
	 * Waiting matters: a scenario that raced the bind would report a connection failure, and
	 * a connection failure is a result this class asserts on elsewhere, so the race would
	 * read as a passing test of the wrong thing.
	 *
	 * $handle is written before the wait rather than returned after it: a process that
	 * started and never bound is still a process, and the caller can only stop what it
	 * holds. It is the reason this does not return the resource - a return value arrives
	 * too late to be cleaned up.
	 *
	 * @param resource|null $handle receives the process, whether or not it goes on to bind
	 */
	private static function StartBroker(int $port, string $logFile, string $behaviour, &$handle): void
	{
		file_put_contents($logFile, '');

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/mqttcoverage-subprocess-helper.php', 'broker', (string)$port, $logFile, $behaviour],
			[1 => ['file', $logFile . '.stdout', 'a'], 2 => ['file', $logFile . '.stderr', 'a']],
			$pipes,
			null,
			self::ChildEnvironment()
		);

		if (!is_resource($process))
		{
			self::fail('could not start the stand-in broker on 127.0.0.1:' . $port);
		}

		$handle = $process;

		$waited = 0;

		while ($waited < 100)
		{
			$probe = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.2);

			if ($probe !== false)
			{
				fclose($probe);

				// The probe itself is a connection the stand-in recorded; the log belongs to
				// the scenarios, so it starts empty for them
				usleep(50000);
				file_put_contents($logFile, '');

				return;
			}

			usleep(50000);
			$waited++;
		}

		self::fail('the stand-in broker never bound 127.0.0.1:' . $port . ' - see ' . $logFile . '.stderr');
	}

	/**
	 * The publication settings for one scenario. Everything else stays at its configured
	 * default, so a scenario says only what it changes.
	 *
	 * @param array<string, string> $extra
	 * @return array<string, string>
	 */
	private static function BrokerSettings(int $port, array $extra = []): array
	{
		return array_merge([
			'VICTUAL_MQTT_ENABLED' => 'true',
			'VICTUAL_MQTT_HOST' => '127.0.0.1',
			'VICTUAL_MQTT_PORT' => (string)$port
		], $extra);
	}

	/**
	 * Environment every child gets: this class's schema, the database, and nothing of the
	 * caller's MQTT configuration.
	 *
	 * The is_scalar filter is required - $_SERVER['argv'] is an array and proc_open rejects
	 * one. The VICTUAL_MQTT_ prefix is stripped so that a leaked setting from one scenario
	 * cannot silently configure the next.
	 *
	 * @return array<string, string>
	 */
	private static function ChildEnvironment(): array
	{
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');

		foreach (array_keys($inherited) as $name)
		{
			if (str_starts_with((string)$name, 'VICTUAL_MQTT_'))
			{
				unset($inherited[$name]);
			}
		}

		return array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH
		]);
	}

	/**
	 * Runs one scenario in a process of its own and returns its result.
	 *
	 * @param string[] $steps
	 * @param array<string, string> $settings
	 * @param string|null $awaitLog The broker log to wait for a completed batch in, when the
	 *                              scenario is expected to reach a broker
	 * @return array{steps: array<string, bool>, ledger: array<string, string>, flags: int[], error: string|null}
	 */
	private static function RunScenario(array $steps, array $settings, ?string $awaitLog = null): array
	{
		// Truncated here rather than by the caller so that what a test reads afterwards
		// belongs to its own scenario and to no earlier one
		file_put_contents(self::$brokerLog, '');
		file_put_contents(self::$droppingBrokerLog, '');

		$resultFile = self::$scratch . '/result-' . uniqid() . '.json';

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/mqttcoverage-subprocess-helper.php', 'scenario', implode(',', $steps), $resultFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			array_merge(self::ChildEnvironment(), $settings)
		);

		self::assertIsResource($process, 'could not start the scenario helper');

		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		self::assertFileExists($resultFile,
			"the scenario helper wrote no result for " . implode(',', $steps) . "\nstdout: $output\nstderr: $errors");

		$result = json_decode((string)file_get_contents($resultFile), true);
		unlink($resultFile);

		self::assertIsArray($result, "the scenario helper wrote no JSON.\nstdout: $output\nstderr: $errors");

		if ($awaitLog !== null)
		{
			self::AwaitBatch($awaitLog);
		}

		return $result;
	}

	/**
	 * Waits for the stand-in to finish recording a batch.
	 *
	 * PublishBatch() returning means the packets were written to the socket, not that the
	 * stand-in has read them. Reading the log straight away is a race whose failure mode is a
	 * *short* topic list - which is precisely what several of these tests are looking for, so
	 * it would read as a defect rather than as a timing problem. The stand-in writes
	 * "=== end" when the connection closes; that is the handshake.
	 */
	private static function AwaitBatch(string $logFile): void
	{
		$waited = 0;

		while ($waited < 100 && !str_contains((string)@file_get_contents($logFile), '=== end'))
		{
			usleep(50000);
			$waited++;
		}

		self::assertStringContainsString('=== end', (string)@file_get_contents($logFile),
			'the stand-in broker never finished the connection');
	}

	/**
	 * One recorded batch: the CONNECT packets and the published topics with their payload
	 * lengths.
	 *
	 * @return array{connects: array<int, array{client_id: string, username: string, password_length: int}>, topics: array<string, int>}
	 */
	private static function ReadBatch(string $logFile): array
	{
		$connects = [];
		$topics = [];

		foreach (file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line)
		{
			$fields = explode("\t", $line);

			if ($fields[0] === '=== connect')
			{
				$connects[] = [
					'client_id' => $fields[1] ?? '',
					'username' => $fields[2] ?? '',
					'password_length' => (int)($fields[3] ?? 0)
				];

				continue;
			}

			if (str_starts_with($line, '==='))
			{
				continue;
			}

			$topics[$fields[0]] = (int)($fields[1] ?? 0);
		}

		return ['connects' => $connects, 'topics' => $topics];
	}
}
