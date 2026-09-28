<?php

namespace Victual\Tests\Support;

use PDO;

/**
 * Test-only PDO subclass that deterministically forces the wider window in issue #584
 * the Opus validator found in PR #598's first round: StockService::UndoBooking()'s own
 * "does row X still exist" check (services/StockService.php) and the eventual
 * explicit-id INSERT that reuses X are two separate statements, and an entirely
 * different connection can insert and commit a real row under that exact id in between -
 * which PostgresDialect::AdvanceIdentitySequence()'s own read-to-draw fix (the narrower
 * window this same issue already closed) cannot see, since a real INSERT never has to
 * draw from the sequence at all if its own caller already resolved its id some other
 * way.
 *
 * Installs tests/Support/StockRowStealingStatement.php through PDO::ATTR_STATEMENT_CLASS
 * so every statement this connection prepares is checked for the exact SQL shape and
 * bound parameter that check uses; the one that matches has a second, genuinely separate
 * connection insert and commit a real row under the target id immediately after it
 * executes (finding nothing) but before UndoBooking() ever acts on that answer - no
 * timing involved at all. Installed in place of DatabaseService's own cached raw
 * connection through the exact same ReflectionProperty swap
 * tests/Support/PgsqlSchemaTestCase.php's own setUpBeforeClass() performs for its own
 * connection (the same pattern tests/Support/SequenceReadRacingPdo.php already uses for
 * issue #584's narrower window). No production code is touched or branches on a
 * test-only condition.
 */
class StockRowStealingPdo extends PDO
{
	public function __construct(string $dsn, ?string $username, ?string $password, ?array $options, PDO $racingConnection, int $targetId, array $victimRow)
	{
		parent::__construct($dsn, $username, $password, $options);

		$this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [StockRowStealingStatement::class, [$racingConnection, $targetId, $victimRow]]);
	}
}
