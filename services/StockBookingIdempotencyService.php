<?php

namespace Victual\Services;

use Victual\Helpers\CanonicalJson;

/** Durable, caller-transaction-owned identities for the five HTTP stock bookings. */
class StockBookingIdempotencyService
{
	public const RETENTION_SECONDS = 604800;
	public const OPERATIONS = ['purchase', 'consume', 'transfer', 'open', 'inventory'];

	/** @param \PDO $db The raw connection whose transaction the booking runs in */
	public function __construct(private \PDO $db)
	{
	}

	/** The stock_booking_idempotency object GET /api/system/info publishes (ADR-0046 decision 11), from the same constants that set each key row. */
	public static function Capability(): array
	{
		return ['header' => 'Idempotency-Key', 'retention_seconds' => self::RETENTION_SECONDS, 'operations' => self::OPERATIONS];
	}

	/** Prepares and runs one statement on the booking's connection. */
	private function Query(string $sql, array $parameters): \PDOStatement
	{
		$statement = $this->db->prepare($sql);
		$statement->execute($parameters);
		return $statement;
	}

	/** Reserve before taking the booking's product lock. A duplicate insert waits for commit. */
	public function Begin(int $userId, string $operation, string $key, array $request): array
	{
		if (!$this->db->inTransaction())
		{
			throw new \LogicException('A caller-owned transaction is required');
		}
		if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key))
		{
			throw new StockBookingIdempotencyException('invalid_value', 'An idempotency key is 8-128 characters of letters, digits, dot, underscore, colon and hyphen', 400);
		}

		$scope = [$userId, $operation, $key];
		$fingerprint = CanonicalJson::Digest($request);
		// Clean only this scope: unrelated bookings never wait for global expired-row cleanup.
		$this->Query('DELETE FROM stock_booking_idempotency_keys WHERE user_id=? AND operation=? AND idempotency_key=? AND expires_at<=CURRENT_TIMESTAMP', $scope);
		$select = 'SELECT request_fingerprint, transaction_id FROM stock_booking_idempotency_keys WHERE user_id=? AND operation=? AND idempotency_key=? FOR UPDATE';
		$row = $this->Query($select, $scope)->fetch(\PDO::FETCH_ASSOC);
		if (!$row)
		{
			$this->db->exec('SAVEPOINT stock_booking_key');
			try
			{
				$this->Query('INSERT INTO stock_booking_idempotency_keys(user_id,operation,idempotency_key,request_fingerprint,expires_at) VALUES (?,?,?,?,CURRENT_TIMESTAMP+make_interval(secs=>?))', [...$scope, $fingerprint, self::RETENTION_SECONDS]);
				$this->db->exec('RELEASE SAVEPOINT stock_booking_key');
				return ['replay' => false, 'transaction_id' => null];
			}
			catch (\PDOException $error)
			{
				if ($error->getCode() !== '23505')
				{
					throw $error;
				}
				$this->db->exec('ROLLBACK TO SAVEPOINT stock_booking_key');
				$this->db->exec('RELEASE SAVEPOINT stock_booking_key');
				$row = $this->Query($select, $scope)->fetch(\PDO::FETCH_ASSOC);
				if (!$row)
				{
					throw $error;
				}
			}
		}
		if (!hash_equals($row['request_fingerprint'], $fingerprint))
		{
			throw new StockBookingIdempotencyException('idempotency_conflict', 'That key was used for a different request; a second intentional action needs a new key');
		}
		if ($row['transaction_id'] === null)
		{
			throw new StockBookingIdempotencyException('idempotency_in_progress', 'A request with that key is still in progress');
		}
		return ['replay' => true, 'transaction_id' => $row['transaction_id']];
	}

	/** How many expired keys one keyed request deletes at most, by PurgeExpired(). */
	public const PURGE_BATCH = 100;

	/**
	 * Deletes up to $limit keys of any user whose retention has passed. Begin() only deletes
	 * the key it is asked about, so a key that is never retried would otherwise stay forever.
	 * Run before the booking's transaction, as its own short statement: SKIP LOCKED means it
	 * never waits behind another request's key, which is the wait ADR-0046 decision 9 warns of.
	 *
	 * @return int The number of keys deleted
	 */
	public function PurgeExpired(int $limit = self::PURGE_BATCH): int
	{
		return $this->Query('DELETE FROM stock_booking_idempotency_keys WHERE ctid IN (SELECT ctid FROM stock_booking_idempotency_keys WHERE expires_at <= CURRENT_TIMESTAMP LIMIT ? FOR UPDATE SKIP LOCKED)', [$limit])->rowCount();
	}

	/** Writes the booking's transaction id into the key row reserved by Begin(), in the same transaction. */
	public function Record(int $userId, string $operation, string $key, string $transactionId): void
	{
		$this->Query('UPDATE stock_booking_idempotency_keys SET transaction_id=? WHERE user_id=? AND operation=? AND idempotency_key=?', [$transactionId, $userId, $operation, $key]);
	}
}
