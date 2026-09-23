<?php

namespace Victual\Services\Mqtt;

use Victual\Services\DatabaseService;

/**
 * Ties the three pieces of MQTT state publication together and owns the two triggers.
 *
 * **Trigger one: the end of a request that changed data.** DatabaseService marks the
 * request dirty when a write statement goes through it, does not mark it for a write made
 * under RunAsBookkeeping(), and its shutdown handler calls
 * PublishForRequestEnd() once, after everything else. That seam was chosen over explicit
 * calls at StockService's seven entrypoints for two reasons: it is the same "did anything
 * really change" question GET /api/system/db-changed-time already answers, so chores,
 * batteries, tasks, the shopping list and every generic CRUD write are covered without
 * naming any of them; and it fires once per request rather than once per commit, which is
 * what the plan's question 3 asks for - a shopping trip is many commits and one snapshot.
 *
 * **Trigger two: bin/victual-publish-state.** PHP has no boot event, so "publish on boot"
 * is a command the deployment runs, from a postStart hook or a Job alongside the
 * initContainer that runs bin/victual-migrate. It publishes the discovery payloads as well
 * as the state, and every per-product topic whatever the ledger says was last sent, which is
 * what makes an out-of-band change - a migration, an import, someone in psql - and a broker
 * that lost its retained messages self-heal rather than silently diverge.
 *
 * Nothing here throws. A broker is not allowed to affect a write that has already
 * committed, so every failure is logged inside MqttPublisher and reported as a bool.
 */
class MqttStatePublicationService
{
	/**
	 * Set by an explicit publish so the request-end trigger does not fire a second time in
	 * the same process.
	 *
	 * This is not state between requests - it is a single process deciding not to do the
	 * same work twice within one run, and it is gone when the process is. bin/victual-migrate
	 * and bin/victual-db-import deliberately do not set it: they change data, so the
	 * request-end publish is exactly right for them.
	 */
	private static $RequestEndPublishSuppressed = false;

	/**
	 * Whether publication is configured on at all.
	 *
	 * Read as a constant so that a fork with MQTT off pays for this feature with one
	 * constant lookup and nothing else - no class loaded, no query run, no connection
	 * attempted.
	 */
	public static function IsEnabled(): bool
	{
		return defined('VICTUAL_MQTT_ENABLED') && VICTUAL_MQTT_ENABLED === true;
	}

	/**
	 * The request-end trigger, called from DatabaseService's shutdown handler when the
	 * request changed data and every transaction is closed.
	 *
	 * That last condition is checked by the caller rather than here, because the caller owns
	 * the PDO connection - but it is the point of the whole seam: a state published inside a
	 * transaction that then rolls back is a lie that persists in a retained topic.
	 *
	 * @return bool True when a snapshot was published
	 */
	public static function PublishForRequestEnd(): bool
	{
		if (self::$RequestEndPublishSuppressed || !self::IsEnabled())
		{
			return false;
		}

		return self::PublishState();
	}

	/**
	 * Publishes the state topics only. Used by the request-end trigger: discovery is a
	 * literal that does not change between requests, and republishing it on every write
	 * would make Home Assistant reprocess the device on every purchase.
	 */
	public static function PublishState(): bool
	{
		return self::Publish(false);
	}

	/**
	 * The full refresh: every discovery payload and every state topic this version owns,
	 * including each per-product entity, whatever the ledger says was last sent.
	 *
	 * What bin/victual-publish-state does, and what a fresh deployment needs so that Home
	 * Assistant learns the entities exist before it is told their values. It is also the
	 * repair path, which is the part the ledger must not be allowed to shorten - see
	 * PublishLocked().
	 */
	public static function PublishDiscoveryAndState(): bool
	{
		return self::Publish(true);
	}

	/**
	 * Assembles and publishes: the ambient state topics always, the ambient discovery
	 * payloads on a full refresh, and the per-product entities - all of them on a full
	 * refresh, and only the ones that have appeared, changed or gone since the last publish
	 * otherwise.
	 *
	 * The ambient half is unconditional because a whole snapshot every time is the design -
	 * a publish lost to a broker restart is repaired by the next write with no
	 * reconciliation logic. The per-product half is a diff on the incremental path because
	 * it cannot be unconditional there: retracting a removed entity means knowing it was
	 * there, and republishing hundreds of unchanged discovery payloads on every purchase
	 * would be a real cost rather than a theoretical one. On a full refresh it is not a diff
	 * at all, for the reason PublishLocked() gives.
	 *
	 * The ledger is only updated after the broker has accepted the batch, so a failed publish
	 * is retried by the next one rather than being recorded as done.
	 *
	 * @param bool $fullRefresh True for the boot/CLI publish, which resends everything
	 */
	private static function Publish(bool $fullRefresh): bool
	{
		if (!self::IsEnabled())
		{
			return false;
		}

		try
		{
			// Everything from the read to the ledger update is one critical section. Two
			// requests would otherwise interleave a read of the state with a write of it and
			// leave the older snapshot retained - silently, since nothing failed and retained
			// topics carry no ordering. The assembly is inside the lock, not just the publish:
			// a lock around the publish alone lets both requests read before either writes,
			// which is the same lost update with a smaller window.
			return DatabaseService::GetInstance()->GetDialect()->WithPublicationLock(function () use ($fullRefresh)
			{
				return self::PublishLocked($fullRefresh);
			});
		}
		catch (\Throwable $ex)
		{
			// WithPublicationLock() itself can throw: acquiring the advisory lock is a database
			// call made before PublishLocked()'s own try, and releasing it is a database call
			// made in a finally that runs even when PublishLocked() returned normally - so a
			// connection that drops on either side of the body throws here regardless of
			// whether anything was actually published. This class's whole contract is that a
			// publish never throws, and the caller is a shutdown handler that must not either.
			//
			// A session-level advisory lock lives on the connection that took it, so a
			// connection drop releases it with nobody having to clean up (WithPublicationLock()'s
			// docblock). What is not safe is a live connection whose unlock statement fails for
			// some other reason: the lock then stays held on the server until that connection
			// closes, and every later publish blocks behind it. That risk exists in
			// WithPublicationLock() itself and is unchanged by catching here - this catch only
			// keeps the failure from escaping the no-throw contract.
			error_log('Victual: the MQTT publication lock could not be acquired or released, nothing was published: ' . $ex->getMessage());

			return false;
		}
	}

	/**
	 * The body of Publish(), called with the publication lock held.
	 *
	 * **A full refresh ignores the ledger for the per-product topics.** The ledger records
	 * what this application last *sent*, which is not the same question as what the broker
	 * still *retains* - and the second question is the one a full refresh exists to answer.
	 * Everything here is published at QoS 0, so a message can simply be lost; a broker can
	 * also be restarted without persistence, replaced, or have its retained messages cleared
	 * by hand. In every one of those cases the ledger still says "sent", so an incremental
	 * publish skips the product and the entity stays missing from Home Assistant until that
	 * particular product's payload happens to change - which for a product nobody buys is
	 * never. The ambient topics never had this problem because they are resent every time;
	 * the per-product ones did, and a boot publish that skipped them was the recovery path
	 * quietly not recovering.
	 *
	 * The incremental path keeps the diff, because there the ledger is answering the question
	 * it is good for: nothing has changed since we last sent this, and resending hundreds of
	 * identical discovery payloads on every purchase is the cost the diff exists to avoid.
	 *
	 * @param bool $fullRefresh True for the boot/CLI publish
	 */
	private static function PublishLocked(bool $fullRefresh): bool
	{
		$builder = new DiscoveryPayloadBuilder();
		$ledger = new PublicationLedger();

		try
		{
			$topics = self::BuildStateTopics();

			if ($fullRefresh)
			{
				$topics = array_merge($builder->BuildDiscoveryPayloads(), $topics);
			}

			$assembler = new StateSnapshotAssembler();
			$entities = $assembler->AssemblePerProductEntities();
			$orphanedFlags = $assembler->GetOrphanedFlagProductIds();
			$publishedBefore = $ledger->GetPublished();
		}
		catch (\Throwable $ex)
		{
			// Assembling can throw on purpose - AssertNoForbiddenKeys() does - and refusing
			// to publish is the right outcome when it does. Reading the ledger can also fail -
			// a database hiccup at request end must never turn an otherwise successful request
			// into an error, just as the assembly path logs and returns false.
			error_log('Victual: could not assemble the MQTT state snapshot or read its ledger, nothing was published: ' . $ex->getMessage());

			return false;
		}

		$record = [];
		foreach ($entities as $objectId => $entity)
		{
			$discovery = $builder->BuildProductDiscoveryPayload($entity['product_id'], $entity['attributes']);
			$state = StateSnapshotAssembler::EncodePayload($entity);
			$hash = hash('sha256', $discovery . "\n" . $state);

			if (!$fullRefresh && ($publishedBefore[$objectId] ?? null) === $hash)
			{
				// Byte-identical to what the ledger says was last sent, on the path where
				// that is the right question: nothing has changed since, so publishing it
				// again would change nothing a subscriber can see. A full refresh does not
				// get this shortcut - see the docblock.
				continue;
			}

			$topics[$builder->GetProductDiscoveryTopic($entity['product_id'])] = $discovery;
			$topics[$builder->GetProductStateTopic($entity['product_id'])] = $state;
			$record[$objectId] = $hash;
		}

		$forget = [];
		foreach (array_keys($publishedBefore) as $objectId)
		{
			if (isset($entities[$objectId]) || !str_starts_with($objectId, StateSnapshotAssembler::PER_PRODUCT_OBJECT_ID_PREFIX))
			{
				continue;
			}

			// Gone: the product was deleted, deactivated, or its opt-in flag cleared. An empty
			// retained payload on the config topic is how Home Assistant is told to remove it
			$productId = (int)substr($objectId, strlen(StateSnapshotAssembler::PER_PRODUCT_OBJECT_ID_PREFIX));

			$topics[$builder->GetProductDiscoveryTopic($productId)] = '';
			$topics[$builder->GetProductStateTopic($productId)] = '';
			$forget[] = $objectId;
		}

		if (!(new MqttPublisher())->PublishBatch($topics))
		{
			return false;
		}

		try
		{
			foreach ($record as $objectId => $hash)
			{
				$ledger->Record($objectId, $hash);
			}

			foreach ($forget as $objectId)
			{
				$ledger->Forget($objectId);
			}

			// Only now that their entities are retracted: a flag row for a product that no longer
			// exists has nothing left to describe
			$ledger->DropFlags($orphanedFlags);
		}
		catch (\Throwable $ex)
		{
			// The ledger writes happen after the broker has accepted the batch, so at this point
			// the messages are already published. A failure to record that is an inconsistency
			// rather than a complete loss, and it must not turn an otherwise successful request
			// into an error - the next publish will try again, matching the design that a failed
			// publish is retried rather than recorded as done.
			error_log('Victual: could not update the MQTT ledger after publishing: ' . $ex->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * Clears every retained topic this version owns, by publishing an empty retained payload
	 * to each.
	 *
	 * An empty retained payload to a discovery config topic is how Home Assistant is told to
	 * remove an entity, and in device mode the removal propagates to that device's other
	 * components. Both discovery modes' topics are cleared, not just the configured one -
	 * see DiscoveryPayloadBuilder::GetAllDiscoveryTopics().
	 *
	 * The discovery topics go first: telling Home Assistant the entities are gone before
	 * clearing their state avoids a moment where an entity exists with no value.
	 */
	public static function Retract(): bool
	{
		if (!self::IsEnabled())
		{
			return false;
		}

		// Under the same lock as Publish(): a retraction racing a publish would otherwise
		// let the publish land after the empty payloads and resurrect every topic this was
		// asked to clear.
		return DatabaseService::GetInstance()->GetDialect()->WithPublicationLock(function ()
		{
			return self::RetractLocked();
		});
	}

	/**
	 * The body of Retract(), called with the publication lock held.
	 */
	private static function RetractLocked(): bool
	{
		$builder = new DiscoveryPayloadBuilder();

		$topics = [];

		foreach ($builder->GetAllDiscoveryTopics() as $topic)
		{
			$topics[$topic] = '';
		}

		foreach ($builder->GetAllStateTopics() as $topic)
		{
			$topics[$topic] = '';
		}

		// Per-product entities are only known from the ledger, which is exactly what it is for
		$ledger = new PublicationLedger();

		// Only the object ids this method knows how to build a retraction for are collected
		// here. Everything else is left in the ledger below rather than forgotten alongside
		// them: forgetting a row this method did not retract would drop the ledger's only
		// record that the topic exists, and nothing would ever clear it again.
		$retracted = [];

		foreach (array_keys($ledger->GetPublished()) as $objectId)
		{
			if (!str_starts_with($objectId, StateSnapshotAssembler::PER_PRODUCT_OBJECT_ID_PREFIX))
			{
				// Not a kind this method retracts. Today Record() is only ever called from the
				// per-product loop in PublishLocked(), so this does not happen - but if a
				// second kind is ever recorded, forgetting it here without having retracted it
				// would silently orphan its retained topic, which is exactly the failure the
				// ledger exists to prevent.
				error_log('Victual: Retract() does not know how to retract ledger object id "' . $objectId
					. '", leaving it in the ledger');

				continue;
			}

			$productId = (int)substr($objectId, strlen(StateSnapshotAssembler::PER_PRODUCT_OBJECT_ID_PREFIX));

			$topics[$builder->GetProductDiscoveryTopic($productId)] = '';
			$topics[$builder->GetProductStateTopic($productId)] = '';
			$retracted[] = $objectId;
		}

		if (!(new MqttPublisher())->PublishBatch($topics))
		{
			return false;
		}

		foreach ($retracted as $objectId)
		{
			$ledger->Forget($objectId);
		}

		return true;
	}

	/**
	 * Stops the request-end trigger firing for the rest of this process, so an explicit CLI
	 * publish is not followed by a second one on the way out.
	 */
	public static function SuppressRequestEndPublish(): void
	{
		self::$RequestEndPublishSuppressed = true;
	}

	/**
	 * The state topics and their JSON payloads.
	 *
	 * @return array<string, string>
	 * @throws \Exception When the snapshot carries a forbidden key
	 */
	private static function BuildStateTopics(): array
	{
		$snapshot = (new StateSnapshotAssembler())->Assemble();
		$builder = new DiscoveryPayloadBuilder();

		$topics = [];

		foreach ($snapshot as $entity => $payload)
		{
			$topics[$builder->GetStateTopic($entity)] = StateSnapshotAssembler::EncodePayload($payload);
		}

		return $topics;
	}
}
