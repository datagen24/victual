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

	/**
	 * The context endpoint keeps epoch out of existing per-kind responses.
	 *
	 * `name` is a display convenience for the caller composing a print request, not an
	 * authorized capture - a stock entry has no name column of its own, so it reads the
	 * product it holds, the same value `FieldCatalogue::For('stock_entry')` names
	 * `stock_entry.product_name`.
	 */
	public function Context(string $kind, int $id): ?array
	{
		$table = FieldCatalogue::TableFor($kind);
		$nameExpression = $kind === 'stock_entry'
			? '(SELECT p.name FROM products p WHERE p.id = stock.product_id)'
			: 'name';
		$query = $this->db->prepare("SELECT id, $nameExpression AS name, import_epoch FROM $table WHERE id = ?");
		$query->execute([$id]);
		return $query->fetch(\PDO::FETCH_ASSOC) ?: null;
	}

	/** The location route predates the other five kinds and keeps its own name. */
	public function LocationContext(int $id): ?array
	{
		return $this->Context('location', $id);
	}

	/** Requires the caller's transaction; a rollback must also undo the mapping. */
	public function Issue(string $kind, int $id, int $expectedEpoch): string
	{
		$table = FieldCatalogue::TableFor($kind);
		self::LockImport($this->db);
		$currentEpoch = (int)$this->db->query('SELECT epoch FROM label_import_state WHERE id = 1')->fetchColumn();
		if ($expectedEpoch !== $currentEpoch)
		{
			throw new \RuntimeException("The $kind set was replaced (epoch $expectedEpoch -> $currentEpoch)");
		}
		$query = $this->db->prepare("SELECT id FROM $table WHERE id = ? AND import_epoch = ? FOR UPDATE");
		$query->execute([$id, $expectedEpoch]);
		if ($query->fetchColumn() === false)
		{
			throw new \RuntimeException(ucfirst(str_replace('_', ' ', $kind)) . ' not found in the requested import epoch');
		}
		$query = $this->db->prepare('SELECT uid FROM labels WHERE kind = ? AND target_id = ? AND retired_at IS NULL');
		$query->execute([$kind, $id]);
		$existing = $query->fetchColumn();
		if ($existing !== false)
		{
			return $existing;
		}
		// ON CONFLICT keeps PostgreSQL's enclosing transaction usable after a collision.
		$insert = $this->db->prepare('INSERT INTO labels (uid, kind, target_id) VALUES (?, ?, ?) ON CONFLICT (uid) DO NOTHING RETURNING uid');
		for ($attempt = 0; $attempt < 32; $attempt++)
		{
			$uid = $this->NewUid();
			$insert->execute([$uid, $kind, $id]);
			if ($insert->fetchColumn() !== false)
			{
				return $uid;
			}
		}
		throw new \RuntimeException('Unable to allocate a unique label uid');
	}

	/** The location route predates the other five kinds and keeps its own name. */
	public function IssueLocation(int $id, int $expectedEpoch): string
	{
		return $this->Issue('location', $id, $expectedEpoch);
	}

	protected function NewUid(): string
	{
		return self::GenerateUid();
	}

	/** Every kind a label can carry, for Resolve()'s up-front denial check. */
	private const KINDS = ['location', 'product', 'stock_entry', 'recipe', 'chore', 'battery'];

	/**
	 * Denied callers see the same 'unknown' answer as a code that does not exist, and - for a
	 * caller denied every kind - do no label lookup at all, the property the class carried
	 * before plan 32 widened this to six kinds each needing a different grant.
	 *
	 * @param callable(string):bool $mayRead Answers whether the caller may read *this kind*
	 *        of label. A scanner holding RECIPES_VIEW but not STOCK_VIEW may resolve a recipe
	 *        label and not a product one, so which kind gates the read is not known until the
	 *        row names it - unless the caller is denied every kind, which is checked first and
	 *        skips the lookup entirely, exactly as a single false flag used to.
	 */
	public function Resolve(string $code, callable $mayRead): array
	{
		$unknown = ['status' => 'unknown'];
		if (($uid = self::Canonicalize($code)) === null || !self::AnyAllowed($mayRead))
		{
			return $unknown;
		}
		$query = $this->db->prepare('SELECT uid, kind, retired_at, retirement_snapshot, target_id FROM labels WHERE uid = ?');
		$query->execute([$uid]);
		$row = $query->fetch(\PDO::FETCH_ASSOC);
		if (!$row || !$mayRead($row['kind']))
		{
			return $unknown;
		}
		if ($row['retired_at'] !== null)
		{
			return ['status' => 'retired', 'uid' => $uid, 'kind' => $row['kind'],
				'retired_at' => $row['retired_at'], 'snapshot' => json_decode($row['retirement_snapshot'], true, 512, JSON_THROW_ON_ERROR)];
		}
		$target = $this->ResolveTarget($row['kind'], (int)$row['target_id']);
		if ($target === null)
		{
			throw new \RuntimeException('Live label has no target');
		}
		return ['status' => 'resolved', 'uid' => $uid, 'kind' => $row['kind'], 'target' => $target];
	}

	private static function AnyAllowed(callable $mayRead): bool
	{
		foreach (self::KINDS as $kind)
		{
			if ($mayRead($kind))
			{
				return true;
			}
		}
		return false;
	}

	/**
	 * The live target a resolved label names, or null if it has vanished without the
	 * retirement trigger catching it (a logic error `Resolve()` refuses on rather than hides).
	 *
	 * The path is read live from locations_resolved rather than pinned anywhere, so a scan
	 * always shows where the location is *now* - unlike a label's captured_fields, nothing
	 * here was fixed at print time. A location deeper than hierarchy_depth_limit() has no self
	 * row (the app itself refuses to create one that deep, so this is only reachable from data
	 * older than migration 0273); the scan page shows the bare name rather than failing a
	 * lookup over it, which is why this is a plain LEFT JOIN and not a refusal the way
	 * FieldCatalogue's 'location.path' is for a print. The other five kinds have no tree, so
	 * `path` mirrors `name` for them - a scan page reading `target.path` sees the same value
	 * either way.
	 */
	private function ResolveTarget(string $kind, int $id): ?array
	{
		$query = match ($kind)
		{
			'location' => $this->db->prepare('SELECT l.id, l.name, r.path
				FROM locations l
				LEFT JOIN locations_resolved r ON r.ancestor_location_id = l.id AND r.descendant_location_id = l.id
				WHERE l.id = ?'),
			'product' => $this->db->prepare('SELECT id, name FROM products WHERE id = ?'),
			'stock_entry' => $this->db->prepare('SELECT s.id, p.name FROM stock s JOIN products p ON p.id = s.product_id WHERE s.id = ?'),
			'recipe' => $this->db->prepare('SELECT id, name FROM recipes WHERE id = ?'),
			'chore' => $this->db->prepare('SELECT id, name FROM chores WHERE id = ?'),
			'battery' => $this->db->prepare('SELECT id, name FROM batteries WHERE id = ?'),
			default => throw new LabelValidationException('kind', 'unsupported_entity_kind', 'No target resolution exists for kind "' . $kind . '"'),
		};
		$query->execute([$id]);
		$row = $query->fetch(\PDO::FETCH_ASSOC);
		if (!$row)
		{
			return null;
		}
		return ['id' => (int)$row['id'], 'name' => $row['name'], 'path' => $row['path'] ?? $row['name']];
	}
}
