<?php

namespace Victual\Services\Mqtt;

use Victual\Services\BaseService;
use Victual\Services\DatabaseService;

/**
 * What has been published to which retained topic, kept in the database.
 *
 * This exists because retraction needs memory. Question 2's Response requires that deleting
 * a product, deactivating it or clearing its opt-in flag sends an empty retained payload to
 * that product's discovery and state topics - and by the time that has happened, the row
 * that would have told us the entity ever existed is gone. Something has to have written it
 * down first.
 *
 * It is a table rather than process memory for the reason the constitution gives: anything
 * that keeps state between requests is a cold-start problem, and ADR-0007 allows process
 * memory only for pure caches whose loss costs a recomputation. Losing this would cost a
 * retraction that never happens and a stale entity on a wall forever, which is not a
 * recomputation.
 *
 * The payload hash is what makes publishing twice a no-op the second time: an entity whose
 * discovery and state payloads are byte-identical to what the ledger says was last published
 * is skipped entirely.
 *
 * Every write here is bookkeeping rather than a data change, so it runs with change tracking
 * suppressed. Without that, publishing would mark the database changed, which would mark the
 * request dirty, which is the condition that triggered the publish.
 */
class PublicationLedger extends BaseService
{
	/**
	 * Everything the ledger holds: object id => payload hash.
	 *
	 * @return array<string, string>
	 */
	public function GetPublished(): array
	{
		$published = [];

		foreach ($this->DB->mqtt_published_entities() as $row)
		{
			$published[(string)$row['object_id']] = (string)$row['payload_hash'];
		}

		return $published;
	}

	/**
	 * Records that an object id is now published with the given payload hash, inserting or
	 * updating as needed.
	 */
	public function Record(string $objectId, string $payloadHash): void
	{
		$this->AsBookkeeping(function () use ($objectId, $payloadHash)
		{
			$existing = $this->DB->mqtt_published_entities()->where('object_id = :1', $objectId)->fetch();

			if ($existing === null)
			{
				$this->DB->mqtt_published_entities()->createRow([
					'object_id' => $objectId,
					'payload_hash' => $payloadHash
				])->save();
			}
			else
			{
				$existing->update(['payload_hash' => $payloadHash]);
			}
		});
	}

	/**
	 * Drops an object id from the ledger, after its topics have been retracted.
	 */
	public function Forget(string $objectId): void
	{
		$this->AsBookkeeping(function () use ($objectId)
		{
			$existing = $this->DB->mqtt_published_entities()->where('object_id = :1', $objectId)->fetch();

			if ($existing !== null)
			{
				$existing->delete();
			}
		});
	}

	/**
	 * Removes opt-in flag rows whose product has been deleted or deactivated.
	 *
	 * A data change rather than bookkeeping - the household's flag really is gone - but it is
	 * only reached after the corresponding entity has been retracted, so it cannot leave a
	 * published topic without a flag behind it.
	 *
	 * @param int[] $productIds
	 */
	public function DropFlags(array $productIds): void
	{
		foreach ($productIds as $productId)
		{
			$row = $this->DB->mqtt_product_entities()->where('product_id = :1', (int)$productId)->fetch();

			if ($row !== null)
			{
				$row->delete();
			}
		}
	}

	/**
	 * Runs a write without letting it count as a data change.
	 *
	 * Suppression, not the read-write-restore idiom SessionService and ApiKeyService use for
	 * their last-used stamps. Restoring writes a shared value back from a snapshot, and the
	 * publication lock does not make that safe: it serializes publishers, not the
	 * application writes happening beside them, so another request can commit and flush a
	 * newer changed time inside the window and have it overwritten with the stale one. See
	 * DatabaseService::RunAsBookkeeping() for the full reasoning and for what SQLite cannot
	 * suppress.
	 */
	private function AsBookkeeping(callable $work): void
	{
		DatabaseService::GetInstance()->RunAsBookkeeping($work);
	}
}
