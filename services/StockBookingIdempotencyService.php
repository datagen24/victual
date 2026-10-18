<?php

namespace Victual\Services;

use Victual\Helpers\CanonicalJson;

/** Durable, caller-transaction-owned identities for the five HTTP stock bookings. */
class StockBookingIdempotencyService
{
	public const RETENTION_SECONDS = 604800;
	public const OPERATIONS = ['purchase', 'consume', 'transfer', 'open', 'inventory'];

	public function __construct(private \PDO $db)
	{
	}

	public static function Capability(): array
	{
		return ['header' => 'Idempotency-Key', 'retention_seconds' => self::RETENTION_SECONDS, 'operations' => self::OPERATIONS];
	}

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

	public function Record(int $userId, string $operation, string $key, string $transactionId): void
	{
		$this->Query('UPDATE stock_booking_idempotency_keys SET transaction_id=? WHERE user_id=? AND operation=? AND idempotency_key=?', [$transactionId, $userId, $operation, $key]);
	}
}
