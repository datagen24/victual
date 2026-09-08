<?php

namespace Victual\Services\Labels;

/** Identity only: callers compose issuance with the future print job transaction. */
class LabelIdentityService
{
	// Shared by import and issuance. Never take this outside a transaction.
	public const IMPORT_LOCK = 109038;
	private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	public function __construct(private \PDO $db)
	{
	}

	public static function LockImport(\PDO $db): void
	{
		if (!$db->inTransaction())
		{
			throw new \LogicException('Label import lock requires a transaction');
		}
		$db->query('SELECT pg_advisory_xact_lock(' . self::IMPORT_LOCK . ')')->fetchColumn();
	}

	public static function GenerateUid(): string
	{
		// Prefix the 64 random bits with one zero; avoid signed 64-bit arithmetic.
		$bits = '0';
		foreach (unpack('C*', random_bytes(8)) as $byte)
		{
			$bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
		}
		$uid = '';
		for ($i = 0; $i < 65; $i += 5)
		{
			$uid .= self::ALPHABET[bindec(substr($bits, $i, 5))];
		}
		return $uid;
	}

	public static function Canonicalize(string $code): ?string
	{
		$code = strtoupper($code);
		if (str_starts_with($code, 'VCTL:'))
		{
			$code = substr($code, 5);
		}
		$code = strtr($code, ['I' => '1', 'L' => '1', 'O' => '0']);
		return preg_match('/^[0-9A-F][0-9A-HJKMNP-TV-Z]{12}$/D', $code) ? $code : null;
	}

	/** The new context endpoint keeps epoch out of existing location responses. */
	public function LocationContext(int $id): ?array
	{
		$query = $this->db->prepare('SELECT id, name, import_epoch FROM locations WHERE id = ?');
		$query->execute([$id]);
		return $query->fetch(\PDO::FETCH_ASSOC) ?: null;
	}

	/** Requires the caller's transaction; a rollback must also undo the mapping. */
	public function IssueLocation(int $id, int $expectedEpoch): string
	{
		self::LockImport($this->db);
		$currentEpoch = (int)$this->db->query('SELECT epoch FROM label_import_state WHERE id = 1')->fetchColumn();
		if ($expectedEpoch !== $currentEpoch)
		{
			throw new \RuntimeException("The location set was replaced (epoch $expectedEpoch -> $currentEpoch)");
		}
		$query = $this->db->prepare('SELECT id FROM locations WHERE id = ? AND import_epoch = ? FOR UPDATE');
		$query->execute([$id, $expectedEpoch]);
		if ($query->fetchColumn() === false)
		{
			throw new \RuntimeException('Location not found in the requested import epoch');
		}
		$query = $this->db->prepare("SELECT uid FROM labels WHERE kind = 'location' AND target_id = ? AND retired_at IS NULL");
		$query->execute([$id]);
		$existing = $query->fetchColumn();
		if ($existing !== false)
		{
			return $existing;
		}
		// ON CONFLICT keeps PostgreSQL's enclosing transaction usable after a collision.
		$insert = $this->db->prepare("INSERT INTO labels (uid, kind, target_id) VALUES (?, 'location', ?) ON CONFLICT (uid) DO NOTHING RETURNING uid");
		for ($attempt = 0; $attempt < 32; $attempt++)
		{
			$uid = $this->NewUid();
			$insert->execute([$uid, $id]);
			if ($insert->fetchColumn() !== false)
			{
				return $uid;
			}
		}
		throw new \RuntimeException('Unable to allocate a unique label uid');
	}

	protected function NewUid(): string
	{
		return self::GenerateUid();
	}

	/** Denied callers do no label lookup, for both existing and unknown codes. */
	public function Resolve(string $code, bool $mayReadLocations): array
	{
		$unknown = ['status' => 'unknown'];
		if (!$mayReadLocations || ($uid = self::Canonicalize($code)) === null)
		{
			return $unknown;
		}
		$query = $this->db->prepare("SELECT l.uid, l.retired_at, l.retirement_snapshot, t.id, t.name
			FROM labels l LEFT JOIN locations t ON l.target_id = t.id AND l.retired_at IS NULL
			WHERE l.uid = ? AND l.kind = 'location'");
		$query->execute([$uid]);
		$row = $query->fetch(\PDO::FETCH_ASSOC);
		if (!$row)
		{
			return $unknown;
		}
		if ($row['retired_at'] !== null)
		{
			return ['status' => 'retired', 'uid' => $uid, 'kind' => 'location',
				'retired_at' => $row['retired_at'], 'snapshot' => json_decode($row['retirement_snapshot'], true, 512, JSON_THROW_ON_ERROR)];
		}
		if ($row['id'] === null)
		{
			throw new \RuntimeException('Live label has no location');
		}
		return ['status' => 'resolved', 'uid' => $uid, 'kind' => 'location',
			'target' => ['id' => (int)$row['id'], 'name' => $row['name']]];
	}
}
