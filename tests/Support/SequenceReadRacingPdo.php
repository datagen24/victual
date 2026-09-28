<?php

namespace Victual\Tests\Support;

use PDO;
use PDOStatement;

/**
 * Test-only PDO subclass that deterministically forces issue #584's exact race: the gap
 * between PostgresDialect::AdvanceIdentitySequence()'s own plain read of a sequence's
 * position and its own nextval() draw immediately after. That window is two back-to-back
 * statements on one connection, open for a handful of microseconds -
 * tests/Pgsql/sequence-race-subprocess-helper.php's subprocess (a genuinely separate OS
 * process hammering nextval() in a tight loop, tests/Pgsql/DialectPolicyTest.php's own
 * race harness) cannot reliably land inside it: either it has not connected yet (losing
 * the whole race) or, once running, it draws roughly 14,000 values/second - over two
 * orders of magnitude faster than any round trip this test environment can make - so the
 * sequence is invariably already far past the target by the time the read executes,
 * landing in AdvanceIdentitySequence()'s own already-past fast path rather than the
 * window this fix actually closes (see tests/Pgsql/UndoSequenceRaceRebuildTest.php's own
 * docblock for that empirical finding).
 *
 * AdvanceIdentitySequence() already receives its connection as a plain \PDO parameter
 * (services/Database/PostgresDialect.php), and its only caller
 * (services/StockService.php's UndoBooking()) obtains that parameter fresh, every call,
 * from DatabaseService::GetDbConnectionRaw() - a single cached, static property. This
 * class is installed in its place through the exact same ReflectionProperty swap
 * tests/Support/PgsqlSchemaTestCase.php's own setUpBeforeClass() already performs for its
 * own connection, so every statement any service issues (through DatabaseService,
 * through LessQL, or directly) runs on this one connection consistently - no production
 * code is touched, and nothing here branches on a test-only condition in application
 * code.
 *
 * query() recognises AdvanceIdentitySequence()'s own read statement by the exact SQL
 * fragment it alone contains (confirmed unique in this codebase - the only other
 * occurrence is inside a PL/pgSQL DO block executed through exec(), never query(), by
 * ResyncGeneratedIdCounters()). The instant after that read executes - synchronously,
 * before control ever returns to AdvanceIdentitySequence() - a second, genuinely separate
 * PDO connection draws one value from the very same sequence, stealing whatever
 * AdvanceIdentitySequence() itself is about to want. Sequences are global, non-
 * transactional Postgres objects: this steal is visible to every connection immediately,
 * with no timing dependency at all, deterministically reproducing "a concurrent caller
 * already drew the target id" on every single run.
 */
class SequenceReadRacingPdo extends PDO
{
	/**
	 * The exact fragment of AdvanceIdentitySequence()'s own read statement
	 * (services/Database/PostgresDialect.php) - unique enough in this codebase (see the
	 * class docblock) that matching it here cannot fire on any other statement this
	 * test's own request issues.
	 */
	private const READ_STATEMENT_MARKER = 'is_called THEN 1 ELSE 0 END FROM';

	private PDO $racingConnection;
	private string $sequenceName;
	private bool $hasRaced = false;
	private int $raceCount = 0;

	/**
	 * @param PDO $racingConnection A second, already-open connection to the same
	 *        database/schema - idle for the whole duration of the call this races,
	 *        since the test's own single thread is blocked inside that call.
	 * @param string $sequenceName The fully schema-qualified sequence name (as
	 *        pg_get_serial_sequence() returns it) to steal a value from.
	 */
	public function __construct(string $dsn, ?string $username, ?string $password, ?array $options, PDO $racingConnection, string $sequenceName)
	{
		parent::__construct($dsn, $username, $password, $options);

		$this->racingConnection = $racingConnection;
		$this->sequenceName = $sequenceName;
	}

	public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
	{
		$result = parent::query($query, $fetchMode, ...$fetchModeArgs);

		if (str_contains($query, self::READ_STATEMENT_MARKER))
		{
			$this->hasRaced = true;
			$this->raceCount++;
			$this->racingConnection->query('SELECT nextval(\'' . $this->sequenceName . '\')');
		}

		return $result;
	}

	/** Whether the race statement has fired at least once - a setup sanity check for tests using this class. */
	public function HasRaced(): bool
	{
		return $this->hasRaced;
	}

	/** How many times the race statement fired - expected to be exactly 1 for a single AdvanceIdentitySequence() call. */
	public function RaceCount(): int
	{
		return $this->raceCount;
	}
}
