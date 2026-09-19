<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Victual\Services\Database\PostgresDialect;

/**
 * The oldest PostgreSQL the application will run on is enforced, and is the oldest the
 * suite runs against.
 *
 * Migration 0273 declares UNIQUE NULLS NOT DISTINCT, which PostgreSQL added in 15, so a
 * 14 server used to get as far as that migration and fail with a syntax error. The check
 * now runs when the connection opens and names the version instead.
 *
 * The last case connects to whatever server the suite is running against, so a suite run
 * on a server below the minimum fails here first, with the same message an operator would
 * see - which is what keeps the constant and the tested floor the same number.
 */
class ServerVersionTest extends TestCase
{
	public static function SupportedVersions(): array
	{
		return [
			'the minimum itself' => ['15.0'],
			'a patch release of it' => ['15.19 (Debian 15.19-1.pgdg13+2)'],
			'the newest tested' => ['16.13'],
			'a later major' => ['18.1'],
			'a development build' => ['17devel'],
			'a version this cannot read' => ['CockroachDB CCL v24.1'],
			'an empty version' => [''],
		];
	}

	public static function UnsupportedVersions(): array
	{
		return [
			'one major below' => ['14.19'],
			'with a distribution suffix' => ['14.5 (Ubuntu 14.5-0ubuntu0.22.04.1)'],
			'a much older major' => ['9.6.24'],
			'a release candidate below' => ['13rc1'],
		];
	}

	#[DataProvider('SupportedVersions')]
	public function testSupportedVersionIsAccepted(string $version): void
	{
		PostgresDialect::AssertSupportedServerVersion($version);
		$this->addToAssertionCount(1);
	}

	#[DataProvider('UnsupportedVersions')]
	public function testOlderVersionIsRefusedAndNamed(string $version): void
	{
		$this->expectException(RuntimeException::class);
		// PHPUnit keeps one expected message, so both parts go in one pattern: the minimum,
		// then the version that was found.
		$this->expectExceptionMessageMatches(
			'/' . preg_quote('PostgreSQL ' . PostgresDialect::MINIMUM_MAJOR_VERSION . ' or newer', '/')
			. '.*' . preg_quote($version, '/') . '/s'
		);

		PostgresDialect::AssertSupportedServerVersion($version);
	}

	public function testTheServerTheSuiteRunsAgainstMeetsTheMinimum(): void
	{
		$dsn = sprintf('pgsql:host=%s;port=%d;dbname=postgres', getenv('PGHOST') ?: 'localhost', (int)(getenv('PGPORT') ?: 5432));
		$pdo = new PDO($dsn, getenv('PGUSER') ?: 'victual', getenv('PGPASSWORD') ?: '');

		PostgresDialect::AssertSupportedServerVersion((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
		$this->addToAssertionCount(1);
	}
}
