<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * `GET /` in production mode, through the whole middleware stack.
 *
 * The root route is public - BaseAuthMiddleware lets it through without authenticating -
 * yet Root() chooses the entry page by what the caller may view, and that question is put
 * to the per-user permission views by VICTUAL_USER_ID. For anybody not logged in the
 * constant did not exist, so the first page a logged-out browser opens was a fatal
 * "Undefined constant" and an HTTP 500; and for a logged-in one it did not exist either
 * (root never authenticated anyone), so LoginController's redirect to `/` after a
 * successful login landed on the same 500. Only the modes that fix a user up front (demo,
 * dev, DISABLE_AUTH) had it defined, which is why nothing before the first production
 * deployment noticed.
 *
 * What the answers should be:
 *
 *   - anonymous or stale-cookie caller: a redirect to the entry page chosen by feature flag
 *     alone (upstream grocy 4.6.0 does this for everybody; it has no per-user entry page),
 *     where the page's own authentication sends them on to /login;
 *   - logged-in caller: the entry page they may view, or /about when they may view none.
 *
 * Every case is its own process (tests/Pgsql/root-subprocess-helper.php) because the
 * authentication middleware defines the user's constants and PHP cannot redefine them.
 */
class RootEntryTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9300, 'root-admin', 'fixture'), (9301, 'root-tasks-only', 'fixture')");
		self::$db->exec('INSERT INTO user_roles(user_id, role_id) VALUES (9300, ' . self::roleId('ADMIN') . ')');
		// A leaf that is not one the default entry page needs
		$stmt = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT 9301, id FROM permission_hierarchy WHERE name = ?');
		$stmt->execute(['TASKS_VIEW']);

		self::$db->exec("INSERT INTO sessions(session_key, user_id, expires) VALUES ('root-test-admin', 9300, now() + interval '1 day'), ('root-test-tasks', 9301, now() + interval '1 day')");
	}

	private static function roleId(string $code): int
	{
		$stmt = self::$db->prepare('SELECT id FROM roles WHERE code = ?');
		$stmt->execute([$code]);
		return (int)$stmt->fetchColumn();
	}

	/** @return array{status: int, location: string} */
	private static function getRoot(?string $sessionKey, ?string $entryPage = null): array
	{
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		], $entryPage === null ? [] : ['VICTUAL_ENTRY_PAGE' => $entryPage]);
		$process = proc_open(
			array_merge([PHP_BINARY, __DIR__ . '/root-subprocess-helper.php'], $sessionKey === null ? [] : [$sessionKey]),
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit = proc_close($process);

		$decoded = json_decode($output, true);
		self::assertIsArray($decoded, "The helper printed no JSON (exit $exit). stdout: $output stderr: $errors");

		return $decoded;
	}

	public function testAnonymousRootRedirectsToTheEntryPageRatherThanFailing(): void
	{
		$answer = self::getRoot(null);

		self::assertSame(302, $answer['status'], 'GET / with no session is a redirect, not a 500');
		self::assertStringEndsWith('/stockoverview', $answer['location'], 'chosen by feature flag alone, as upstream does; /stockoverview then sends the caller to /login');
	}

	public function testUnknownSessionCookieIsTreatedAsAnonymous(): void
	{
		$answer = self::getRoot('a-cookie-nobody-issued');

		self::assertSame(302, $answer['status']);
		self::assertStringEndsWith('/stockoverview', $answer['location']);
	}

	public function testLoggedInCallerWhoMayViewStockLandsOnStock(): void
	{
		$answer = self::getRoot('root-test-admin');

		self::assertSame(302, $answer['status'], 'GET / for an authenticated caller is a redirect, not a 500');
		self::assertStringEndsWith('/stockoverview', $answer['location']);
	}

	public function testLoggedInCallerWhoMayNotViewStockFallsBackToAbout(): void
	{
		$answer = self::getRoot('root-test-tasks');

		self::assertSame(302, $answer['status']);
		self::assertStringEndsWith('/about', $answer['location'], 'the per-user check is live: a caller without STOCK_VIEW is not sent to a page that would refuse them');
	}

	/**
	 * The four entry pages that carry no *_VIEW leaf of their own: what each page's own
	 * gate is (MealPlan() refuses without MEALPLAN_VIEW; the other three are the legacy
	 * BATTERIES / EQUIPMENT / CALENDAR grants the sidebar disables an item for). Their
	 * branches in GetEntryPageRelative() used to ask nobody, so a logged-in caller was sent
	 * to a page that refused or greyed them out.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function ungatedEntryPages(): array
	{
		return [
			'batteries' => ['batteries', '/batteriesoverview'],
			'equipment' => ['equipment', '/equipment'],
			'calendar' => ['calendar', '/calendar'],
			'mealplan' => ['mealplan', '/mealplan'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('ungatedEntryPages')]
	public function testEntryPageWithItsOwnGrantIsHonouredAndWithoutItFallsBackToAbout(string $entryPage, string $target): void
	{
		$anonymous = self::getRoot(null, $entryPage);
		self::assertSame(302, $anonymous['status']);
		self::assertStringEndsWith($target, $anonymous['location'], "$entryPage: an unidentified caller is redirected by flag alone");

		$granted = self::getRoot('root-test-admin', $entryPage);
		self::assertSame(302, $granted['status']);
		self::assertStringEndsWith($target, $granted['location'], "$entryPage: ADMIN holds the grant");

		$refused = self::getRoot('root-test-tasks', $entryPage);
		self::assertSame(302, $refused['status']);
		self::assertStringEndsWith('/about', $refused['location'], "$entryPage: a caller without the grant is not sent to a page that refuses them");
	}
}
