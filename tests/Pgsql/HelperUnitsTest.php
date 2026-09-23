<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;
use Victual\Helpers\CachePaths;
use Victual\Helpers\CanonicalJson;
use Victual\Helpers\ECanonicalizationFailed;
use Victual\Helpers\Grocycode;
use Victual\Helpers\WebhookRunner;
use Victual\Services\ApiKeyService;
use Victual\Services\ApplicationService;
use Victual\Services\CalendarService;
use Victual\Services\DatabaseService;
use Victual\Services\LocalizationService;
use Victual\Services\UserfieldsService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * The helpers and services nothing else in the suite reaches: the global functions in
 * helpers/extensions.php, the two startup validators, the Grocycode parser, the label
 * subsystem's canonicalizer, the stderr logger, the webhook runner, the route cache
 * naming, and the calendar, userfield, localization and application services.
 *
 * Plan 33 (docs/plans/33-coverage-floor.md) is why they are together: each is small, and
 * several need no database at all. The class extends PgsqlSchemaTestCase anyway, because
 * one phase is one class (harness brief section 2) and the services in it do need the
 * migrated schema; the database-free cases simply never touch self::Pdo().
 *
 * ParseApiDateTime() and API_DATE_TIME_PATTERN are deliberately absent. They are the
 * subject of tests/Pgsql/WireContractTest.php:940-1288 under ADR-0028, and a second set of
 * assertions over the same contract would be two documents of one rule.
 */
class HelperUnitsTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	/** Scratch directory for the cases that need real files (EmptyFolder, Setting overrides). */
	private static string $scratch;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'helperunits-caller', 'fixture')");

		self::$scratch = sys_get_temp_dir() . '/victual-helperunits-' . getmypid();
		mkdir(self::$scratch, 0700, true);

		self::seedUserfieldFixtures();
		self::seedCalendarFixtures();
	}

	public static function tearDownAfterClass(): void
	{
		self::RemoveRecursively(self::$scratch);

		parent::tearDownAfterClass();
	}

	private static function RemoveRecursively(string $path): void
	{
		if (!is_dir($path))
		{
			return;
		}

		foreach (glob($path . '/*') as $entry)
		{
			is_dir($entry) ? self::RemoveRecursively($entry) : @unlink($entry);
		}

		@rmdir($path);
	}

	/**
	 * Replaces the caller's direct permissions with exactly the named ones. Copied from
	 * tests/Pgsql/RbacTest.php:73-78 - the permission tables are the only place the acting
	 * identity's rights live, so rewriting them is how a test changes who it is.
	 */
	private static function grant(array $names): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');

		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = ?');

		foreach ($names as $name)
		{
			$statement->execute([$name]);
		}
	}

	// ----------------------------------------------------------- the subprocess half

	/**
	 * Runs one task of tests/Pgsql/helperunits-subprocess-helper.php and returns the JSON
	 * it printed.
	 *
	 * @param array $spec The task spec
	 * @param array $settings VICTUAL_* environment overrides, without the prefix
	 * @param array $arguments Extra interpreter arguments, e.g. -n to start without php.ini
	 * @param array $raw Environment entries passed through under their own names
	 * @return array{ok: bool, class?: string, message?: string, result?: array}
	 */
	private static function runHelper(array $spec, array $settings = [], array $arguments = [], array $raw = []): array
	{
		// -n drops the ini scan directory, so the two extensions the measurement itself needs
		// have to be asked for by name: pcov, or the prepend below finds no driver and
		// everything these subprocesses reach is silently unmeasured, and tokenizer, which
		// php-code-coverage's static analysis uses when it writes the file out at shutdown.
		// Neither is a requirement of the subject, and tokenizer is not the extension any of
		// these cases is about missing.
		if (in_array('-n', $arguments, true))
		{
			$arguments = array_merge($arguments, ['-d', 'extension=pcov', '-d', 'extension=tokenizer']);
		}

		$command = array_merge(
			[PHP_BINARY],
			$arguments,
			// -n drops the ini scan directory, and with it the coverage prepend the suite
			// hooks at the interpreter - so the two lines it needs are passed back by hand,
			// and the prepend itself does nothing when VICTUAL_COVERAGE_DIR is unset.
			[
				'-d', 'auto_prepend_file=' . VICTUAL_ROOT_PATH . '/.devtools/coverage/prepend.php',
				'-d', 'pcov.directory=' . VICTUAL_ROOT_PATH,
				'-d', 'pcov.enabled=1',
				__DIR__ . '/helperunits-subprocess-helper.php',
				base64_encode(json_encode($spec))
			]
		);

		$environment = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$environment['VICTUAL_ROOT'] = VICTUAL_ROOT_PATH;
		$environment['VICTUAL_DATAPATH'] = self::$scratch;

		// run-tests.sh exports DIFFTEST_SQLITE_RUNTIME for the whole suite, which would make
		// every one of these boots the differential harness. The one case that wants it asks
		// for it by name instead, so that this phase answers the same question whether it is
		// started by the suite or on its own.
		unset($environment[\Victual\Services\Database\DatabaseDialect::SQLITE_TOOLING_ENV]);

		foreach ($settings as $name => $value)
		{
			$environment['VICTUAL_' . $name] = $value;
		}

		foreach ($raw as $name => $value)
		{
			$environment[$name] = $value;
		}

		$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$decoded = json_decode((string)$output, true);
		self::assertIsArray($decoded, "the helper printed no JSON for task {$spec['task']}. stdout: $output\nstderr: $errors");

		$decoded['stderr'] = $errors;

		return $decoded;
	}

	/**
	 * The configuration validator's verdict on one set of VICTUAL_* values.
	 *
	 * Every case is its own process because the values under test are constants, and PHP
	 * cannot redefine one (harness brief gotcha 5).
	 */
	private static function validateConfiguration(array $settings, array $userSettings = [], array $defines = []): array
	{
		$spec = ['task' => 'configuration'];

		if ($userSettings !== [])
		{
			$spec['user_settings'] = $userSettings;
		}

		if ($defines !== [])
		{
			$spec['defines'] = $defines;
		}

		return self::runHelper($spec, $settings);
	}

	private static function assertConfigurationAccepted(array $settings, string $why): void
	{
		$verdict = self::validateConfiguration($settings);

		self::assertTrue($verdict['ok'], "$why, but the validator refused it: " . ($verdict['message'] ?? ''));
	}

	private static function assertConfigurationRefused(array $settings, string $expectedInMessage, string $why, array $userSettings = [], array $defines = []): void
	{
		$verdict = self::validateConfiguration($settings, $userSettings, $defines);

		self::assertFalse($verdict['ok'], "$why, but the validator accepted it");
		self::assertSame('Victual\\Helpers\\EInvalidConfig', $verdict['class'], 'the refusal is the configuration exception');
		self::assertStringContainsString($expectedInMessage, $verdict['message'], 'the refusal names what is wrong');
	}

	// ================================================================ helpers/extensions.php

	/**
	 * The array search helpers back every "look this up in a result set I already have"
	 * call in the services, so "no match" has to be a value and not a crash.
	 */
	public function testFindObjectInArrayByPropertyValueReturnsTheFirstMatchOrNull()
	{
		$rows = [
			(object)['id' => 1, 'name' => 'first'],
			(object)['id' => 2, 'name' => 'second'],
			(object)['id' => 2, 'name' => 'shadowed']
		];

		self::assertSame('first', FindObjectInArrayByPropertyValue($rows, 'id', 1)->name);
		self::assertSame('second', FindObjectInArrayByPropertyValue($rows, 'id', 2)->name, 'the first of two matches wins');
		self::assertNull(FindObjectInArrayByPropertyValue($rows, 'id', 99), 'no match is null, not an error');
		self::assertNull(FindObjectInArrayByPropertyValue([], 'id', 1), 'an empty collection matches nothing');
	}

	public function testFindAllObjectsInArrayByPropertyValueAppliesTheNamedComparison()
	{
		$rows = [
			(object)['amount' => 1],
			(object)['amount' => 5],
			(object)['amount' => 9]
		];

		self::assertSame([5], array_column(FindAllObjectsInArrayByPropertyValue($rows, 'amount', 5), 'amount'));
		self::assertSame([9], array_column(FindAllObjectsInArrayByPropertyValue($rows, 'amount', 5, '>'), 'amount'));
		self::assertSame([1], array_column(FindAllObjectsInArrayByPropertyValue($rows, 'amount', 5, '<'), 'amount'));

		// Documented: an operator the switch does not name matches nothing, rather than
		// falling back to equality
		self::assertSame([], FindAllObjectsInArrayByPropertyValue($rows, 'amount', 5, '>='));
		self::assertSame([], FindAllObjectsInArrayByPropertyValue([], 'amount', 5));
	}

	public function testFindAllItemsInArrayByValueAppliesTheNamedComparison()
	{
		$items = [1, 5, 9];

		self::assertSame([5], FindAllItemsInArrayByValue($items, 5));
		self::assertSame([9], FindAllItemsInArrayByValue($items, 5, '>'));
		self::assertSame([1], FindAllItemsInArrayByValue($items, 5, '<'));
		self::assertSame([], FindAllItemsInArrayByValue($items, 5, '<>'), 'an unknown operator matches nothing');
		self::assertSame([], FindAllItemsInArrayByValue([], 5));
	}

	public function testSumArrayValueAddsThePropertyAsAFloat()
	{
		$rows = [
			(object)['price' => '1.50'],
			(object)['price' => 2],
			(object)['price' => null]
		];

		self::assertSame(3.5, SumArrayValue($rows, 'price'), 'a string amount and a null both convert');
		self::assertSame(0, SumArrayValue([], 'price'), 'an empty collection sums to zero');
	}

	public function testGetClassConstantsReturnsEverythingOrOnlyThePrefixedOnes()
	{
		$all = GetClassConstants(UserfieldsService::class);
		$prefixed = GetClassConstants(UserfieldsService::class, 'USERFIELD_TYPE_');

		self::assertArrayHasKey('USERFIELD_TYPE_CHECKBOX', $all);
		self::assertSame('checkbox', $all['USERFIELD_TYPE_CHECKBOX']);
		self::assertSame($all, $prefixed, 'this class has nothing but USERFIELD_TYPE_ constants');

		// A negative control, so that "the prefix filtered something" is not vacuous
		self::assertArrayHasKey('MAGIC', GetClassConstants(Grocycode::class));
		self::assertSame([], GetClassConstants(Grocycode::class, 'USERFIELD_TYPE_'));
	}

	public function testRandomStringHasTheRequestedLengthAndUsesOnlyTheAllowedCharacters()
	{
		self::assertSame('', RandomString(0), 'zero characters is the empty string');
		self::assertSame(40, strlen(RandomString(40)));
		self::assertSame('aaaaa', RandomString(5, 'a'), 'a one character alphabet has one answer');
		self::assertSame(1, preg_match('/^[0-9a-zA-Z]{64}$/', RandomString(64)), 'the default alphabet is alphanumeric');

		// Session keys and API keys are drawn from this, so two draws must not agree
		self::assertNotSame(RandomString(32), RandomString(32));
	}

	public function testIsAssociativeArrayDistinguishesAMapFromAList()
	{
		self::assertFalse(IsAssociativeArray([]), 'the empty array is a list');
		self::assertFalse(IsAssociativeArray(['a', 'b']));
		self::assertTrue(IsAssociativeArray(['key' => 'value']));
		self::assertTrue(IsAssociativeArray([1 => 'a', 0 => 'b']), 'out of order integer keys are a map');
		self::assertTrue(IsAssociativeArray([5 => 'a']), 'a list that does not start at zero is a map');
	}

	/**
	 * best_before_date and purchased_date are written through this, so a date the calendar
	 * does not have has to be refused rather than rolled over into the next month.
	 */
	public function testIsIsoDateAcceptsOnlyRealDatesInYmdForm()
	{
		self::assertTrue(IsIsoDate('2026-09-21'));
		self::assertTrue(IsIsoDate('2024-02-29'), 'a leap day of a leap year is a date');
		self::assertTrue(IsIsoDate('2000-02-29'), 'a century divisible by 400 is a leap year');

		self::assertFalse(IsIsoDate('2026-02-29'), 'the 29th of February 2026 does not exist');
		self::assertFalse(IsIsoDate('1900-02-29'), 'a century not divisible by 400 is not a leap year');
		self::assertFalse(IsIsoDate('2026-04-31'), 'April has thirty days');
		self::assertFalse(IsIsoDate('2026-13-01'), 'there is no thirteenth month');
		self::assertFalse(IsIsoDate(''), 'the empty string is not a date');
		self::assertFalse(IsIsoDate('2026-9-1'), 'the form is zero padded');
		self::assertFalse(IsIsoDate('2026-09-21 14:30:00'), 'a date and time is not a date');
		self::assertFalse(IsIsoDate('tomorrow'), 'a relative expression is not a date');
	}

	public function testBoolToStringAndBoolToIntRenderBothValues()
	{
		self::assertSame('true', BoolToString(true));
		self::assertSame('false', BoolToString(false));
		self::assertSame(1, BoolToInt(true));
		self::assertSame(0, BoolToInt(false));
	}

	public function testExternalSettingValueTrimsLineBreaksAndReadsTheBooleanWords()
	{
		self::assertTrue(ExternalSettingValue('true'));
		self::assertTrue(ExternalSettingValue("TRUE\n"), 'case and a trailing newline are both tolerated');
		self::assertFalse(ExternalSettingValue('False'));
		self::assertSame('pgsql', ExternalSettingValue("pgsql\r\n"));
		self::assertSame('', ExternalSettingValue(''), 'an empty override stays an empty string');
		self::assertSame(' true ', ExternalSettingValue(' true '), 'only line breaks are trimmed, so a padded word stays a string');
	}

	/**
	 * Setting()'s precedence is the whole of how an installation is configured without a
	 * writable config.php (helpers/extensions.php:389-410), so all four of its outcomes
	 * are asserted: an already-defined constant, an override file, an environment
	 * variable, and the default.
	 *
	 * The names are unique to this test because a constant cannot be undefined again.
	 */
	public function testSettingPrefersAnAlreadyDefinedValueThenAFileThenTheEnvironmentThenTheDefault()
	{
		$overrides = VICTUAL_DATAPATH . '/settingoverrides';
		@mkdir($overrides, 0700, true);

		// Both variables and the override file are process wide: a failure at any assertion
		// below would otherwise leave them set for every later test in the run, so the undo
		// runs in a finally rather than as trailing statements.
		$originalFromFile = getenv('VICTUAL_HELPERUNITS_FROM_FILE');
		$originalFromEnv = getenv('VICTUAL_HELPERUNITS_FROM_ENV');

		try
		{
			define('VICTUAL_HELPERUNITS_PREDEFINED', 'already set');
			Setting('HELPERUNITS_PREDEFINED', 'default that must not win');
			self::assertSame('already set', VICTUAL_HELPERUNITS_PREDEFINED, 'an existing constant is never overwritten');

			file_put_contents($overrides . '/HELPERUNITS_FROM_FILE.txt', "from the file\n");
			putenv('VICTUAL_HELPERUNITS_FROM_FILE=from the environment');
			Setting('HELPERUNITS_FROM_FILE', 'from the default');
			self::assertSame('from the file', VICTUAL_HELPERUNITS_FROM_FILE, 'the override file outranks the environment');

			putenv('VICTUAL_HELPERUNITS_FROM_ENV=false');
			Setting('HELPERUNITS_FROM_ENV', 'from the default');
			self::assertFalse(VICTUAL_HELPERUNITS_FROM_ENV, 'the environment outranks the default and "false" arrives as a boolean');

			Setting('HELPERUNITS_FROM_DEFAULT', 42);
			self::assertSame(42, VICTUAL_HELPERUNITS_FROM_DEFAULT, 'with neither, the default is taken unchanged');
		}
		finally
		{
			if ($originalFromFile === false)
			{
				putenv('VICTUAL_HELPERUNITS_FROM_FILE');
			}
			else
			{
				putenv('VICTUAL_HELPERUNITS_FROM_FILE=' . $originalFromFile);
			}

			if ($originalFromEnv === false)
			{
				putenv('VICTUAL_HELPERUNITS_FROM_ENV');
			}
			else
			{
				putenv('VICTUAL_HELPERUNITS_FROM_ENV=' . $originalFromEnv);
			}

			@unlink($overrides . '/HELPERUNITS_FROM_FILE.txt');
		}
	}

	public function testDefaultUserSettingKeepsTheFirstRegistrationOfAName()
	{
		global $VICTUAL_DEFAULT_USER_SETTINGS;

		DefaultUserSetting('helperunits_probe', 'first');
		DefaultUserSetting('helperunits_probe', 'second');

		self::assertSame('first', $VICTUAL_DEFAULT_USER_SETTINGS['helperunits_probe']);

		// A negative control: the array is the registry the validator and UsersService read,
		// so a name nobody registered must be absent rather than null
		self::assertArrayNotHasKey('helperunits_never_registered', $VICTUAL_DEFAULT_USER_SETTINGS);

		unset($VICTUAL_DEFAULT_USER_SETTINGS['helperunits_probe']);
	}

	public function testGetUserDisplayNameFallsBackThroughTheNameFieldsToTheUsername()
	{
		self::assertSame('Ada Lovelace', GetUserDisplayName((object)['first_name' => 'Ada', 'last_name' => 'Lovelace', 'username' => 'ada']));
		self::assertSame('Ada', GetUserDisplayName((object)['first_name' => 'Ada', 'last_name' => '', 'username' => 'ada']));
		self::assertSame('Lovelace', GetUserDisplayName((object)['first_name' => null, 'last_name' => 'Lovelace', 'username' => 'ada']));
		self::assertSame('ada', GetUserDisplayName((object)['first_name' => null, 'last_name' => null, 'username' => 'ada']));
	}

	public function testIsValidFileNameRefusesPathSeparatorsAndNamesWithoutAnExtension()
	{
		self::assertTrue(IsValidFileName('picture.png'));
		self::assertTrue(IsValidFileName('two.dots.png'));

		self::assertFalse(IsValidFileName('folder/picture.png'), 'a path separator is not part of a file name');
		self::assertFalse(IsValidFileName('..\\windows\\system32.dll'), 'a backslash is refused too');
		self::assertFalse(IsValidFileName('noextension'));
		self::assertFalse(IsValidFileName('semi;colon.png'));
		self::assertFalse(IsValidFileName("null\0byte.png"), 'a null byte is refused');
		self::assertFalse(IsValidFileName(''));
	}

	public function testIsJsonStringAnswersForTheWholeDocument()
	{
		self::assertTrue(IsJsonString('{"a":1}'));
		self::assertTrue(IsJsonString('[]'));
		self::assertTrue(IsJsonString('null'), 'a bare literal is a JSON document');
		self::assertFalse(IsJsonString('{a:1}'));
		self::assertFalse(IsJsonString(''));
	}

	/**
	 * The CIDR test decides whether a reverse proxy is trusted
	 * (REVERSE_PROXY_AUTH_TRUSTED_PROXIES), so "not understood" has to read as "not
	 * trusted" and a v4 address must never fall inside a v6 range.
	 */
	public function testIsIpInCidrComparesPackedAddressesAndRefusesWhatItCannotRead()
	{
		self::assertTrue(IsIpInCidr('10.1.2.3', '10.0.0.0/8'));
		self::assertFalse(IsIpInCidr('11.1.2.3', '10.0.0.0/8'), 'a differing whole byte is outside');

		self::assertTrue(IsIpInCidr('10.128.0.1', '10.128.0.0/9'), 'a prefix that ends inside a byte still matches');
		self::assertFalse(IsIpInCidr('10.0.0.1', '10.128.0.0/9'), 'and still separates');

		self::assertTrue(IsIpInCidr('10.0.0.5', '10.0.0.5'), 'a bare address is an exact comparison');
		self::assertFalse(IsIpInCidr('10.0.0.6', '10.0.0.5'));

		self::assertTrue(IsIpInCidr('203.0.113.9', '0.0.0.0/0'), 'a zero length prefix matches every v4 address');
		self::assertTrue(IsIpInCidr('10.0.0.5', '10.0.0.5/32'), 'a full length prefix is an exact comparison');
		self::assertFalse(IsIpInCidr('10.0.0.6', '10.0.0.5/32'));

		self::assertTrue(IsIpInCidr('fd00::1', 'fd00::/8'), 'IPv6 is compared the same way');
		self::assertFalse(IsIpInCidr('10.0.0.5', 'fd00::/8'), 'a v4 address is never inside a v6 range');
		self::assertFalse(IsIpInCidr('fd00::1', '10.0.0.0/8'), 'nor the reverse');

		self::assertFalse(IsIpInCidr('not an address', '10.0.0.0/8'), 'an unreadable address is not trusted');
		self::assertFalse(IsIpInCidr('10.0.0.5', 'not a range'), 'nor an unreadable bare range');
		self::assertFalse(IsIpInCidr('10.0.0.5', 'not a range/8'), 'nor an unreadable subnet');
		self::assertFalse(IsIpInCidr('10.0.0.5', '10.0.0.0/eight'), 'nor a prefix length that is not a number');
		self::assertFalse(IsIpInCidr('10.0.0.5', '10.0.0.0/33'), 'a prefix longer than the address is refused');
		self::assertFalse(IsIpInCidr('10.0.0.5', '10.0.0.0/-1'), 'and so is a negative one');
	}

	public function testIsIpInCidrListMatchesAnyEntryAndAnEmptyListMatchesNothing()
	{
		$list = '10.0.0.0/8, 192.168.1.5 , fd00::/8';

		self::assertTrue(IsIpInCidrList('10.1.2.3', $list));
		self::assertTrue(IsIpInCidrList('192.168.1.5', $list), 'a bare address entry counts');
		self::assertTrue(IsIpInCidrList('fd00::99', $list));
		self::assertFalse(IsIpInCidrList('172.16.0.1', $list), 'an address in no entry is not trusted');

		self::assertFalse(IsIpInCidrList('10.1.2.3', ''), 'an empty list trusts nothing');
		self::assertFalse(IsIpInCidrList('10.1.2.3', ' , , '), 'and neither does a list of nothing but separators');
	}

	/**
	 * Sweep finding S28: escaping a stored URL protects the attribute, not the navigation,
	 * so the scheme is what decides.
	 */
	public function testIsSafeExternalUrlAllowsOnlyTheThreeNavigableSchemesAndRelativeUrls()
	{
		self::assertTrue(IsSafeExternalUrl('https://example.test/page'));
		self::assertTrue(IsSafeExternalUrl('HTTP://example.test'), 'the scheme comparison is case insensitive');
		self::assertTrue(IsSafeExternalUrl('mailto:someone@example.test'));
		self::assertTrue(IsSafeExternalUrl('/products/1'), 'a relative URL cannot leave this origin');
		self::assertTrue(IsSafeExternalUrl(null), 'a missing URL is not a dangerous one');
		self::assertTrue(IsSafeExternalUrl(''));

		self::assertFalse(IsSafeExternalUrl('javascript:alert(1)'));
		self::assertFalse(IsSafeExternalUrl("java\nscript:alert(1)"), 'a control character inside the scheme is ignored by browsers and so here');
		self::assertFalse(IsSafeExternalUrl('  javascript:alert(1)'), 'leading whitespace does not make it relative');
		self::assertFalse(IsSafeExternalUrl('data:text/html;base64,PHNjcmlwdD4='));
		self::assertFalse(IsSafeExternalUrl('ftp://example.test/file'));
	}

	public function testSafeExternalUrlKeepsASafeUrlAndReplacesAnUnsafeOneWithAHash()
	{
		self::assertSame('https://example.test/page', SafeExternalUrl('https://example.test/page'));
		self::assertSame('/products/1', SafeExternalUrl('/products/1'));
		self::assertSame('#', SafeExternalUrl('javascript:alert(1)'));
		self::assertSame('', SafeExternalUrl(null), 'a null URL renders as an empty href, not as a hash');
	}

	public function testIsApiRoutePathAnswersForTheDefaultInstallationRoot()
	{
		self::assertSame('', VICTUAL_BASE_PATH, 'this process is booted at the installation root');

		self::assertTrue(IsApiRoutePath('/api/stock'));
		self::assertFalse(IsApiRoutePath('/stockoverview'), 'a rendered page is not the API');
		self::assertFalse(IsApiRoutePath('/api'), 'the prefix is /api/, so the bare word is not a route');
	}

	/**
	 * The same question on an installation mounted in a subdirectory, which is the case the
	 * function exists for: Slim's setBasePath() only affects routing, so the request path
	 * still carries the prefix and a bare comparison would answer "not the API" for every
	 * API request - an HTML error page instead of a JSON one.
	 *
	 * A subprocess because VICTUAL_BASE_PATH is a constant.
	 */
	public function testIsApiRoutePathStripsTheBasePathBeforeComparing()
	{
		$paths = ['/victual/api/stock', '/victual/stockoverview', '/api/stock'];
		$verdict = self::runHelper(['task' => 'api-route-path', 'paths' => $paths], ['BASE_PATH' => '/victual']);

		self::assertTrue($verdict['ok'], 'the helper answered: ' . ($verdict['message'] ?? ''));
		self::assertSame('/victual', $verdict['result']['base_path']);

		$answers = $verdict['result']['answers'];
		self::assertTrue($answers['/victual/api/stock'], 'an API request on a mounted installation is an API request');
		self::assertFalse($answers['/victual/stockoverview'], 'and a page on the same installation is not');
	}

	public function testApiKeyIsReadableOnlyForKeysTheApplicationHasToBeAbleToHandBack()
	{
		$calendarKey = (object)['key_type' => ApiKeyService::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL, 'api_key' => 'plain-calendar-key', 'key_hint' => 'ykey'];
		$regularKey = (object)['key_type' => ApiKeyService::API_KEY_TYPE_DEFAULT, 'api_key' => str_repeat('a', 64), 'key_hint' => 'wxyz'];
		$mcpKey = (object)['key_type' => ApiKeyService::API_KEY_TYPE_MCP, 'api_key' => str_repeat('b', 64), 'key_hint' => ''];

		self::assertTrue(ApiKeyIsReadable($calendarKey), 'the calendar link cannot be rebuilt from a hash');
		self::assertFalse(ApiKeyIsReadable($regularKey), 'a user issued key is stored hashed');
		self::assertFalse(ApiKeyIsReadable($mcpKey), 'issue #208: an MCP key is user issued too');

		self::assertSame('plain-calendar-key', ApiKeyDisplayValue($calendarKey));
		self::assertSame('••••wxyz', ApiKeyDisplayValue($regularKey), 'a hashed key shows its hint, never the stored value');
		self::assertStringNotContainsString('aaaa', ApiKeyDisplayValue($regularKey));
		self::assertSame('••••', ApiKeyDisplayValue($mcpKey), 'a key created before hints existed shows nothing');
	}

	public function testStringStartsWithAndStringEndsWith()
	{
		self::assertTrue(string_starts_with('/api/stock', '/api/'));
		self::assertFalse(string_starts_with('/api/stock', 'stock'));
		self::assertTrue(string_starts_with('anything', ''), 'the empty prefix always matches');

		self::assertTrue(string_ends_with('picture.png', '.png'));
		self::assertFalse(string_ends_with('picture.png', '.jpg'));
		self::assertTrue(string_ends_with('anything', ''), 'the empty suffix always matches');
		self::assertFalse(string_ends_with('png', 'picture.png'), 'a needle longer than the haystack does not match');
	}

	public function testRequireFrontendPackagesCollectsEachPackageOnce()
	{
		global $VICTUAL_REQUIRED_FRONTEND_PACKAGES;

		$before = $VICTUAL_REQUIRED_FRONTEND_PACKAGES;

		require_frontend_packages(['helperunits-a', 'helperunits-b']);
		require_frontend_packages(['helperunits-b', 'helperunits-c']);

		$collected = array_values(array_diff($VICTUAL_REQUIRED_FRONTEND_PACKAGES, $before));
		sort($collected);

		self::assertSame(['helperunits-a', 'helperunits-b', 'helperunits-c'], $collected, 'a package asked for twice is included once');

		$VICTUAL_REQUIRED_FRONTEND_PACKAGES = $before;
	}

	public function testEmptyFolderRemovesEverythingBelowItAndKeepsTheFolderItself()
	{
		$root = self::$scratch . '/emptyfolder';
		mkdir($root . '/nested/deeper', 0700, true);
		file_put_contents($root . '/top.txt', 'x');
		file_put_contents($root . '/nested/middle.txt', 'x');
		file_put_contents($root . '/nested/deeper/bottom.txt', 'x');

		EmptyFolder($root);

		self::assertDirectoryExists($root, 'the folder itself survives');
		self::assertSame([], glob($root . '/*'), 'and nothing inside it does');
		self::assertFileDoesNotExist($root . '/nested/deeper/bottom.txt');

		// A second call on the now empty folder must be a no-op rather than an error
		EmptyFolder($root);
		self::assertDirectoryExists($root);

		rmdir($root);
	}

	// =============================================================== helpers/CachePaths.php

	/**
	 * The route cache file is named after the two things the compiled route table depends
	 * on, so that changing either produces a different file rather than dispatching the
	 * wrong URLs from a stale one.
	 */
	public function testTheRouteCacheFileIsNamedAfterItsInputsAndTheGlobMatchesIt()
	{
		$file = CachePaths::RouteCacheFile();
		$glob = CachePaths::RouteCacheGlob();

		self::assertStringStartsWith(VICTUAL_VIEWCACHE_PATH . '/route_cache-', $file, 'the file lives in the cache directory');
		self::assertStringEndsWith('.php', $file);
		self::assertSame(VICTUAL_VIEWCACHE_PATH . '/route_cache*.php', $glob);
		self::assertTrue(fnmatch($glob, $file), 'the warmer has to be able to find the file it wrote');

		self::assertSame($file, CachePaths::RouteCacheFile(), 'the same inputs name the same file');
	}

	/**
	 * The other half of that contract: a different VICTUAL_BASE_PATH is a different routing
	 * table, so it has to be a different file name. A subprocess, because the base path is
	 * a constant.
	 */
	public function testADifferentBasePathNamesADifferentRouteCacheFile()
	{
		$mounted = self::runHelper(['task' => 'route-cache-file'], ['BASE_PATH' => '/victual', 'VIEWCACHE_PATH' => self::$scratch]);
		$root = self::runHelper(['task' => 'route-cache-file'], ['BASE_PATH' => '', 'VIEWCACHE_PATH' => self::$scratch]);

		self::assertTrue($mounted['ok'] && $root['ok'], 'both boots answered');
		self::assertNotSame($mounted['result']['file'], $root['result']['file'], 'a cache built for /victual must not be reused at the root');
		self::assertSame($mounted['result']['glob'], $root['result']['glob'], 'the cleanup glob covers both');
		self::assertTrue(fnmatch($mounted['result']['glob'], $mounted['result']['file']));
	}

	// ================================================================ helpers/Grocycode.php

	/**
	 * docs/grocycode.md: the four entity identifiers are the whole set, and a code is the
	 * magic, a type, an id and any number of extra fields.
	 */
	public function testGrocycodeParsesEveryTypeItAccepts()
	{
		foreach ([Grocycode::PRODUCT => 'p', Grocycode::BATTERY => 'b', Grocycode::CHORE => 'c', Grocycode::RECIPE => 'r'] as $constant => $letter)
		{
			self::assertSame($letter, $constant, 'the constant is the letter that appears on the label');

			$parsed = new Grocycode('grcy:' . $letter . ':13');

			self::assertSame($letter, $parsed->GetType());
			self::assertSame('13', $parsed->GetId());
			self::assertSame([], $parsed->GetExtraData(), 'a three part code carries no extra data');
		}

		self::assertSame(['p', 'b', 'c', 'r'], Grocycode::$Items, 'ADR-0011: there is no fifth type');
	}

	public function testGrocycodeParsesTheExtraDataFieldsInOrder()
	{
		$stockEntry = new Grocycode('grcy:p:13:60bf8b5244b04');

		self::assertSame('p', $stockEntry->GetType());
		self::assertSame('13', $stockEntry->GetId());
		self::assertSame(['60bf8b5244b04'], $stockEntry->GetExtraData(), 'the documented product/stock entry example');

		$several = new Grocycode('grcy:p:13:first:second:third');
		self::assertSame(['first', 'second', 'third'], $several->GetExtraData(), 'extra fields keep their order');
	}

	public function testGrocycodeRefusesWhatIsNotAGrocycode()
	{
		$refusals = [
			'vctl:0123456789ABC' => 'Not a Grocycode',
			'' => 'Not a Grocycode',
			'13' => 'Not a Grocycode',
			'GRCY:p:13' => 'Not a Grocycode',
			'grcy:l:13' => 'Unknown Grocycode type',
			'grcy:P:13' => 'Unknown Grocycode type',
			'grcy:product:13' => 'Unknown Grocycode type',
			'grcy' => 'Unknown Grocycode type'
		];

		foreach ($refusals as $code => $expected)
		{
			self::assertFalse(Grocycode::Validate($code), "\"$code\" is not a grocycode");

			try
			{
				new Grocycode($code);
				self::fail("\"$code\" was accepted");
			}
			catch (\Exception $exception)
			{
				self::assertSame($expected, $exception->getMessage(), "the refusal of \"$code\" says which rule it broke");
			}
		}

		self::assertTrue(Grocycode::Validate('grcy:p:13'), 'the negative control: a real code still validates');
	}

	/**
	 * Grocycode refuses codes with no id part or an empty id part, as docs/grocycode.md
	 * specifies three mandatory parts: grcy:type:id. A scanned "grcy:p" with no id or
	 * "grcy:p:" with an empty id is rejected rather than reaching a product query with
	 * a null id.
	 */
	public function testGrocycodeRefusesACodeWithNoId()
	{
		self::assertFalse(Grocycode::Validate('grcy:p'), 'a code with no id part is refused');
		self::assertFalse(Grocycode::Validate('grcy:p:'), 'a code with an empty id part is refused');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Not a Grocycode');

		new Grocycode('grcy:p');
	}

	public function testGrocycodeRefusesArgumentListsItHasNoOverloadFor()
	{
		$cases = [
			[[], 'No suitable overload found.'],
			[['p', 13, [], 'extra'], 'No suitable overload found.'],
			[['p', 13, 'not an array'], 'Extra data must be array of string'],
			[['l', 13], 'Unknown Grocycode type']
		];

		foreach ($cases as [$arguments, $expected])
		{
			try
			{
				new Grocycode(...$arguments);
				self::fail('accepted ' . json_encode($arguments));
			}
			catch (\Exception $exception)
			{
				self::assertSame($expected, $exception->getMessage());
			}
		}
	}

	/**
	 * FINDING, not a defect in this class: Grocycode still serializes, and five routes
	 * still call it. ADR-0011 (accepted 2026-09-04) says the fork "parses grcy:* indefinitely
	 * and emits it never", and docs/grocycode.md repeats it, but
	 * controllers/StockController.php:447, :866, controllers/ChoresController.php:195,
	 * controllers/RecipesController.php:335 and controllers/BatteriesController.php:173 each
	 * build a Grocycode from data and render __toString() into a barcode image.
	 *
	 * The behaviour is asserted as it stands, because that is what those routes serve today.
	 */
	public function testGrocycodeStillSerializesTheWayTheBarcodeRoutesEmitIt()
	{
		self::assertSame('grcy:p:13', (string)(new Grocycode(Grocycode::PRODUCT, 13)));
		self::assertSame('grcy:b:7', (string)(new Grocycode(Grocycode::BATTERY, 7)));
		self::assertSame('grcy:c:9', (string)(new Grocycode(Grocycode::CHORE, 9)));
		self::assertSame('grcy:r:4', (string)(new Grocycode(Grocycode::RECIPE, 4)));
		self::assertSame('grcy:p:13:60bf8b5244b04', (string)(new Grocycode(Grocycode::PRODUCT, 13, ['60bf8b5244b04'])));

		// What is emitted parses back to what it was built from
		$round = new Grocycode((string)(new Grocycode(Grocycode::PRODUCT, 13, ['abc'])));
		self::assertSame('p', $round->GetType());
		self::assertSame('13', $round->GetId());
		self::assertSame(['abc'], $round->GetExtraData());
	}

	// =============================================================== helpers/CanonicalJson.php

	/**
	 * The two refusals .devtools/labels/canonical-json-tests.php does not reach. That script
	 * runs a 2068 document differential against an ECMAScript oracle, so every rule about
	 * what canonical output looks like is already covered there; what a differential cannot
	 * produce is a document the canonicalizer must not encode at all.
	 */
	public function testCanonicalJsonRefusesADocumentDeeperThanItWillRecurseInto()
	{
		$shallow = 'leaf';

		for ($level = 0; $level < 60; $level++)
		{
			$shallow = [$shallow];
		}

		self::assertStringEndsWith('"leaf"' . str_repeat(']', 60), CanonicalJson::Encode($shallow), 'sixty levels is inside the bound');

		$deep = 'leaf';

		for ($level = 0; $level < 70; $level++)
		{
			$deep = [$deep];
		}

		$this->expectException(ECanonicalizationFailed::class);
		$this->expectExceptionMessage('Document nests deeper than 64 levels');

		CanonicalJson::Encode($deep);
	}

	public function testCanonicalJsonRefusesAValueWithNoCanonicalForm()
	{
		$handle = fopen('php://memory', 'r');

		try
		{
			CanonicalJson::Encode(['stream' => $handle]);
			self::fail('a resource was encoded');
		}
		catch (ECanonicalizationFailed $exception)
		{
			self::assertStringContainsString('has no canonical form', $exception->getMessage());
			self::assertStringContainsString('resource', $exception->getMessage(), 'the refusal names the type it could not encode');
		}
		finally
		{
			fclose($handle);
		}

		// The negative control: the same document with an encodable value goes through, so
		// the refusal above is about the resource and not about the shape
		self::assertSame('{"stream":"a name"}', CanonicalJson::Encode(['stream' => 'a name']));
	}

	// ========================================================= helpers/PrerequisiteChecker.php

	/**
	 * The interpreter the suite itself runs on meets every requirement, which is the
	 * control the unmet cases below are read against.
	 */
	public function testEveryStartupPrerequisiteIsMetOnASupportedInterpreter()
	{
		$verdict = self::runHelper(['task' => 'requirements']);

		self::assertTrue($verdict['ok'], 'the checker refused a supported interpreter: ' . ($verdict['message'] ?? ''));
		self::assertTrue(version_compare($verdict['result']['php_version'], '8.4.1', '>='), 'composer.json puts the floor at 8.4');
	}

	/**
	 * The unmet branch of the extension check. Started with -n, so the interpreter loads no
	 * ini file and therefore none of the shared extensions - which is exactly the situation
	 * the check exists for (the comment in helpers/PrerequisiteChecker.php:14-16 names
	 * FreeBSD builds missing core extensions).
	 */
	public function testAMissingPhpExtensionIsRefusedByNameAtStartup()
	{
		$verdict = self::runHelper(['task' => 'requirements'], [], ['-n']);

		self::assertFalse($verdict['ok'], 'an interpreter without the required extensions was accepted');
		self::assertSame('Victual\\Helpers\\ERequirementNotMet', $verdict['class']);
		self::assertMatchesRegularExpression(
			"/PHP module '[a-z]+' not installed, but required\\./",
			$verdict['message'],
			'the message names the missing module, so an operator can install it'
		);
	}

	/**
	 * The engine dependent half, which runs later than the other because it needs the
	 * configuration: the configured driver's PDO extension, and nothing else's.
	 */
	public function testTheConfiguredDatabaseDriversExtensionIsWhatIsChecked()
	{
		$pgsql = self::runHelper(['task' => 'database-requirements', 'driver' => 'pgsql']);
		self::assertTrue($pgsql['ok'], 'pdo_pgsql is present in this environment: ' . ($pgsql['message'] ?? ''));

		$uppercase = self::runHelper(['task' => 'database-requirements', 'driver' => 'PGSQL']);
		self::assertTrue($uppercase['ok'], 'the driver name is compared case insensitively');

		// A driver the table does not name is not the application's business to check
		$unknown = self::runHelper(['task' => 'database-requirements', 'driver' => 'informix']);
		self::assertTrue($unknown['ok'], 'a driver with no entry asks for no extension');

		$missing = self::runHelper(['task' => 'database-requirements', 'driver' => 'pgsql'], [], ['-n']);
		self::assertFalse($missing['ok'], 'an interpreter without pdo_pgsql was accepted for a pgsql installation');
		self::assertSame(
			"PHP module 'pdo_pgsql' not installed, but required for the 'pgsql' database driver.",
			$missing['message'],
			'the message names the extension and the driver that wants it'
		);
	}

	/**
	 * "sqlite" still has an entry, and deliberately so (helpers/PrerequisiteChecker.php:20-32):
	 * the differential harness builds a SQLite side, and a run on a PHP without pdo_sqlite
	 * should fail here rather than inside a migration. It also carries the library version
	 * floor, which the PostgreSQL path never reaches.
	 */
	public function testTheSqliteEntrySurvivesForTheDifferentialHarness()
	{
		$present = self::runHelper(['task' => 'database-requirements', 'driver' => 'sqlite']);
		self::assertTrue($present['ok'], 'pdo_sqlite and a recent enough library are present: ' . ($present['message'] ?? ''));

		$missing = self::runHelper(['task' => 'database-requirements', 'driver' => 'sqlite'], [], ['-n']);
		self::assertFalse($missing['ok']);
		self::assertSame(
			"PHP module 'pdo_sqlite' not installed, but required for the 'sqlite' database driver.",
			$missing['message']
		);
	}

	// ======================================================= helpers/ConfigurationValidator.php

	/**
	 * The control every refusal below is read against: config-dist.php's own defaults are a
	 * valid configuration.
	 */
	public function testTheShippedDefaultConfigurationIsValid()
	{
		self::assertConfigurationAccepted([], 'config-dist.php ships a usable configuration');
	}

	public function testModeHasToBeOneOfTheFourTheApplicationKnows()
	{
		foreach (['production', 'dev', 'demo', 'prerelease'] as $mode)
		{
			self::assertConfigurationAccepted(['MODE' => $mode], "\"$mode\" is a documented mode");
		}

		self::assertConfigurationRefused(['MODE' => 'staging'], 'Invalid mode "staging"', 'a mode nothing branches on is refused');
	}

	/**
	 * Sweep finding S18 (plan 15-B1): app.php does new $authMiddlewareClass(...) on this
	 * value, so a name that is not an authentication middleware used to be a fatal on the
	 * first request.
	 */
	public function testAuthClassHasToNameAnAuthenticationMiddleware()
	{
		self::assertConfigurationAccepted(['AUTH_CLASS' => 'Victual\\Middleware\\Auth\\ReverseProxyAuthMiddleware'], 'the reverse proxy backend is a middleware');

		self::assertConfigurationRefused(
			['AUTH_CLASS' => 'Victual\\Middleware\\Auth\\LdapAuthMiddleware'],
			'does not exist',
			'a backend this fork removed is named rather than fatal'
		);

		self::assertConfigurationRefused(
			['AUTH_CLASS' => 'Victual\\Helpers\\CachePaths'],
			'is not an authentication middleware',
			'a class that exists but is not a middleware is refused too'
		);
	}

	/**
	 * ADR-0008: DB_DRIVER accepts pgsql alone, and "sqlite" is answered by name because it
	 * was this fork's default until the retirement, so an upgrading operator meets this
	 * check with a config.php that was correct yesterday.
	 */
	public function testDatabaseDriverAcceptsPostgresqlAndAnswersSqliteWithTheWayOut()
	{
		self::assertConfigurationAccepted(['DB_DRIVER' => 'pgsql'], 'PostgreSQL is the runtime engine');
		self::assertConfigurationAccepted(['DB_DRIVER' => 'PgSQL'], 'the driver name is compared case insensitively');

		$verdict = self::validateConfiguration(['DB_DRIVER' => 'sqlite']);
		self::assertFalse($verdict['ok'], 'SQLite is not a runtime engine');
		self::assertStringContainsString('ADR-0008', $verdict['message']);
		self::assertStringContainsString('bin/victual-db-import', $verdict['message'], 'the message says what to do, not only that the value is wrong');

		self::assertConfigurationRefused(['DB_DRIVER' => 'mysql'], 'Invalid database driver "mysql"', 'any other driver gets the generic refusal');
	}

	/**
	 * The driver named has to be one this interpreter can actually open. Started with -n so
	 * that PDO is loaded and no driver is: an installation whose PHP was built or packaged
	 * without pdo_pgsql is told at startup instead of at the first query.
	 */
	public function testAConfiguredDriverThePhpBuildDoesNotHaveIsRefused()
	{
		$verdict = self::runHelper(['task' => 'configuration'], ['DB_DRIVER' => 'pgsql'], ['-n', '-d', 'extension=pdo']);

		self::assertFalse($verdict['ok'], 'an interpreter with no PDO drivers at all was accepted');
		self::assertSame('Victual\\Helpers\\EInvalidConfig', $verdict['class']);
		self::assertSame('The PDO driver for "pgsql" is not installed in this PHP environment', $verdict['message']);
	}

	/**
	 * The one process that may still ask for the SQLite dialect is the differential suite,
	 * through an environment variable rather than a setting - precisely so that it is not a
	 * way to run this fork (AGENTS.md:16-32).
	 */
	public function testTheDifferentialHarnessMayStillSelectSqlite()
	{
		$environment = ['DB_DRIVER' => 'sqlite'];
		$spec = ['task' => 'configuration'];

		$verdict = self::runHelperWithRawEnvironment($spec, $environment, ['DIFFTEST_SQLITE_RUNTIME' => '1']);

		self::assertTrue($verdict['ok'], 'the harness dialect was refused: ' . ($verdict['message'] ?? ''));
	}

	public function testPostgresqlNeedsItsConnectionSettingsAndAKnownSslMode()
	{
		self::assertConfigurationRefused([], 'DB_HOST, DB_NAME and DB_USER need to be set', 'an empty database name is refused', [], ['DB_NAME' => '']);
		self::assertConfigurationRefused([], 'DB_HOST, DB_NAME and DB_USER need to be set', 'an empty host is refused', [], ['DB_HOST' => '']);
		self::assertConfigurationRefused([], 'DB_HOST, DB_NAME and DB_USER need to be set', 'an empty user is refused', [], ['DB_USER' => '']);

		self::assertConfigurationAccepted([], 'an empty sslmode means the libpq default');
		self::assertConfigurationAccepted(['DB_SSLMODE' => 'verify-full'], 'the strictest mode is allowed');
		self::assertConfigurationRefused(['DB_SSLMODE' => 'verify'], 'Invalid DB_SSLMODE "verify"', 'a truncated mode name would silently weaken the connection');
	}

	/**
	 * Plan 01: both combinations are refused at startup rather than at the first upload, so
	 * a household that flips the setting finds out while it can still change its mind.
	 */
	public function testFileStorageRefusesTheTwoCombinationsItCannotServe()
	{
		self::assertConfigurationAccepted(['FILE_STORAGE' => 'filesystem'], 'the default backend');
		self::assertConfigurationAccepted(['FILE_STORAGE' => 'database', 'MODE' => 'production'], 'BYTEA storage on PostgreSQL in production');

		self::assertConfigurationRefused(['FILE_STORAGE' => 's3'], 'Invalid file storage "s3"', 'there are two backends');

		// The backend is BYTEA bound as a LOB through raw PDO, and there is deliberately no
		// SQLite counterpart (plan 01). Reaching this needs a driver that is not pgsql, which
		// only the differential suite's environment variable can still select.
		$onSqlite = self::runHelperWithRawEnvironment(
			['task' => 'configuration'],
			['DB_DRIVER' => 'sqlite', 'FILE_STORAGE' => 'database'],
			['DIFFTEST_SQLITE_RUNTIME' => '1']
		);
		self::assertFalse($onSqlite['ok'], 'database file storage on a non-PostgreSQL driver was accepted');
		self::assertStringContainsString('requires DB_DRIVER "pgsql"', $onSqlite['message']);

		self::assertConfigurationRefused(
			['FILE_STORAGE' => 'database', 'MODE' => 'demo'],
			'not supported in "demo" mode',
			'demo instances share a storage location by file name suffix'
		);
		self::assertConfigurationRefused(
			['FILE_STORAGE' => 'database', 'MODE' => 'prerelease'],
			'not supported in "prerelease" mode',
			'and so do prerelease instances'
		);
	}

	public function testTheUploadSizeLimitHasToBeAPositiveNumberOfMegabytes()
	{
		self::assertConfigurationAccepted(['FILE_STORAGE_MAX_SIZE_MB' => '1'], 'one megabyte is a positive limit');
		self::assertConfigurationAccepted(['FILE_STORAGE_MAX_SIZE_MB' => '0.5'], 'a fractional limit is a number of megabytes');

		self::assertConfigurationRefused(['FILE_STORAGE_MAX_SIZE_MB' => '0'], 'must be a positive number', 'zero would refuse every upload');
		self::assertConfigurationRefused(['FILE_STORAGE_MAX_SIZE_MB' => '-8'], 'must be a positive number', 'and a negative limit is meaningless');
		self::assertConfigurationRefused(['FILE_STORAGE_MAX_SIZE_MB' => 'lots'], 'must be a positive number', 'and so is a word');
	}

	/**
	 * Plan 27 question 7: label artifacts and assets carry captured household data and
	 * every printed label, so the subsystem stores them in the database or it does not run.
	 */
	public function testTheLabelSubsystemRequiresDatabaseBackedStorage()
	{
		self::assertConfigurationAccepted(['FEATURE_FLAG_LABELS' => 'false'], 'a deployment that does not print labels is not asked to change its storage');
		self::assertConfigurationAccepted(['FEATURE_FLAG_LABELS' => 'true', 'FILE_STORAGE' => 'database', 'MODE' => 'production'], 'labels on database storage in production');

		self::assertConfigurationRefused(
			['FEATURE_FLAG_LABELS' => 'true', 'FILE_STORAGE' => 'filesystem'],
			'requires FILE_STORAGE "database"',
			'putting label artifacts on disk would reintroduce the persistent volume'
		);
		// FINDING: checkLabelSubsystem()'s demo/prerelease refusal
		// (helpers/ConfigurationValidator.php:228-231) cannot be reached. It runs after
		// checkFileStorage(), which already refuses FILE_STORAGE "database" in those modes,
		// and the only way past this method's own first check is FILE_STORAGE "database".
		// So a demo instance enabling labels is answered by whichever of the two applies,
		// and never by the message written for it.
		self::assertConfigurationRefused(
			['FEATURE_FLAG_LABELS' => 'true', 'FILE_STORAGE' => 'database', 'MODE' => 'demo'],
			'not supported in "demo" mode',
			'a demo instance enabling labels is refused - by the storage check, which runs first'
		);
		self::assertConfigurationRefused(
			['FEATURE_FLAG_LABELS' => 'true', 'FILE_STORAGE' => 'filesystem', 'MODE' => 'demo'],
			'requires FILE_STORAGE "database"',
			'and with filesystem storage by the label check, still not by its mode branch'
		);
	}

	public function testTheDefaultLocaleHasToExistInTheLocalizationFolder()
	{
		self::assertConfigurationAccepted(['DEFAULT_LOCALE' => 'de'], 'de ships with the application');
		self::assertConfigurationRefused(['DEFAULT_LOCALE' => 'xx_XX'], 'Invalid locale "xx_XX"', 'a locale with no folder would leave the UI untranslated');
	}

	public function testCurrencyHasToBeAThreeLetterCode()
	{
		self::assertConfigurationAccepted(['CURRENCY' => 'EUR'], 'ISO 4217');
		self::assertConfigurationRefused(['CURRENCY' => 'EURO'], 'ISO 4217', 'four letters is not a code');
		self::assertConfigurationRefused(['CURRENCY' => 'US'], 'ISO 4217', 'and neither is two');
		self::assertConfigurationRefused(['CURRENCY' => '840'], 'ISO 4217', 'the numeric code is not the letter code');
	}

	public function testTheTwoFirstDayOfWeekSettingsHaveDifferentRanges()
	{
		self::assertConfigurationAccepted(['CALENDAR_FIRST_DAY_OF_WEEK' => ''], 'empty means the locale decides');
		self::assertConfigurationAccepted(['CALENDAR_FIRST_DAY_OF_WEEK' => '0'], 'Sunday');
		self::assertConfigurationAccepted(['CALENDAR_FIRST_DAY_OF_WEEK' => '6'], 'Saturday is the last allowed value');
		self::assertConfigurationRefused(['CALENDAR_FIRST_DAY_OF_WEEK' => '7'], 'CALENDAR_FIRST_DAY_OF_WEEK', 'a week has seven days, numbered to six');
		self::assertConfigurationRefused(['CALENDAR_FIRST_DAY_OF_WEEK' => '-1'], 'CALENDAR_FIRST_DAY_OF_WEEK', 'the calendar has no "follow the calendar" value');

		self::assertConfigurationAccepted(['MEAL_PLAN_FIRST_DAY_OF_WEEK' => '-1'], 'the meal plan does take -1, which means follow the calendar setting');
		self::assertConfigurationAccepted(['MEAL_PLAN_FIRST_DAY_OF_WEEK' => '6'], 'Saturday');
		self::assertConfigurationRefused(['MEAL_PLAN_FIRST_DAY_OF_WEEK' => '-2'], 'MEAL_PLAN_FIRST_DAY_OF_WEEK', 'and nothing below it');
		self::assertConfigurationRefused(['MEAL_PLAN_FIRST_DAY_OF_WEEK' => '7'], 'MEAL_PLAN_FIRST_DAY_OF_WEEK', 'nor above six');
	}

	public function testTheEntryPageHasToBeAPageTheApplicationServes()
	{
		self::assertConfigurationAccepted(['ENTRY_PAGE' => 'mealplan'], 'the meal plan is an entry page');
		self::assertConfigurationRefused(['ENTRY_PAGE' => 'about'], 'Invalid entry page "about"', 'a page not on the list would redirect nowhere');
	}

	/**
	 * A prefix or substring match on an origin is how a rule meant for
	 * "https://home.example.com" comes to admit "https://home.example.com.evil.test", so
	 * CorsMiddleware compares exactly - which makes an entry with a path silently dead.
	 */
	public function testEveryCorsAllowedOriginsEntryHasToBeABareOrigin()
	{
		self::assertConfigurationAccepted([], 'the default is no cross origin access at all, and an empty list has no entry to check');
		self::assertConfigurationAccepted(['CORS_ALLOWED_ORIGINS' => 'https://home.example.test'], 'a bare origin');
		self::assertConfigurationAccepted(['CORS_ALLOWED_ORIGINS' => 'http://localhost:8080, https://home.example.test'], 'a port and a list are both fine');

		self::assertConfigurationRefused(
			['CORS_ALLOWED_ORIGINS' => 'https://home.example.test/'],
			'no path and no trailing slash',
			'a trailing slash never matches an Origin header, so the setting would look configured and do nothing'
		);
		self::assertConfigurationRefused(
			['CORS_ALLOWED_ORIGINS' => 'https://home.example.test/app'],
			'no path and no trailing slash',
			'and neither does a path'
		);
		self::assertConfigurationRefused(
			['CORS_ALLOWED_ORIGINS' => 'home.example.test'],
			'no path and no trailing slash',
			'an origin carries its scheme'
		);
	}

	/**
	 * The publish path swallows every failure by design, so a misconfigured broker would
	 * otherwise show up as nothing being published and no error anywhere.
	 */
	public function testMqttIsOnlyValidatedWhenItIsTurnedOn()
	{
		self::assertConfigurationAccepted(['MQTT_ENABLED' => 'false'], 'an unused broker setting is not checked');

		self::assertConfigurationRefused(['MQTT_ENABLED' => 'true'], 'MQTT_HOST needs to be set', 'an enabled broker needs a host');
		self::assertConfigurationRefused(['MQTT_ENABLED' => 'true', 'MQTT_HOST' => '   '], 'MQTT_HOST needs to be set', 'and whitespace is not a host');

		self::assertConfigurationAccepted(['MQTT_ENABLED' => 'true', 'MQTT_HOST' => 'broker.example.test'], 'a host is all it needs');

		self::assertConfigurationAccepted(['MQTT_ENABLED' => 'true', 'MQTT_HOST' => 'broker.example.test', 'MQTT_DISCOVERY_MODE' => 'entity'], 'one config topic per sensor');
		self::assertConfigurationRefused(
			['MQTT_ENABLED' => 'true', 'MQTT_HOST' => 'broker.example.test', 'MQTT_DISCOVERY_MODE' => 'both'],
			'Invalid MQTT_DISCOVERY_MODE "both"',
			'there are two discovery modes'
		);

		self::assertConfigurationAccepted(['MQTT_ENABLED' => 'true', 'MQTT_HOST' => 'broker.example.test', 'MQTT_CONNECT_TIMEOUT_SECONDS' => '1'], 'one second is the library floor');
		self::assertConfigurationRefused(
			['MQTT_ENABLED' => 'true', 'MQTT_HOST' => 'broker.example.test', 'MQTT_CONNECT_TIMEOUT_SECONDS' => '0'],
			'at least 1',
			'the timeout bounds how long an unreachable broker delays a committed write'
		);
		self::assertConfigurationRefused(
			['MQTT_ENABLED' => 'true', 'MQTT_HOST' => 'broker.example.test', 'MQTT_CONNECT_TIMEOUT_SECONDS' => 'soon'],
			'whole number of seconds',
			'and it has to be a number'
		);
	}

	public function testInfluxDbIsOnlyValidatedWhenItIsTurnedOn()
	{
		self::assertConfigurationAccepted(['INFLUXDB_ENABLED' => 'false'], 'an unused metrics setting is not checked');

		self::assertConfigurationRefused(['INFLUXDB_ENABLED' => 'true'], 'INFLUXDB_URL needs to be set', 'an enabled writer needs a URL');
		self::assertConfigurationRefused(
			['INFLUXDB_ENABLED' => 'true', 'INFLUXDB_URL' => 'http://influxdb.example.test:8086'],
			'INFLUXDB_ORG and INFLUXDB_BUCKET need to be set',
			'a point has to be written somewhere',
			[],
			['INFLUXDB_ORG' => '']
		);
		self::assertConfigurationRefused(
			['INFLUXDB_ENABLED' => 'true', 'INFLUXDB_URL' => 'http://influxdb.example.test:8086'],
			'INFLUXDB_ORG and INFLUXDB_BUCKET need to be set',
			'and the bucket is half of where',
			[],
			['INFLUXDB_BUCKET' => '']
		);
		self::assertConfigurationRefused(
			['INFLUXDB_ENABLED' => 'true', 'INFLUXDB_URL' => 'http://influxdb.example.test:8086', 'INFLUXDB_TIMEOUT_SECONDS' => '0'],
			'at least 1',
			'the timeout bounds how long an unreachable metrics server delays a booking'
		);

		self::assertConfigurationAccepted(
			['INFLUXDB_ENABLED' => 'true', 'INFLUXDB_URL' => 'http://influxdb.example.test:8086'],
			'a URL with the shipped org and bucket defaults is enough'
		);
	}

	/**
	 * The night mode range is not a VICTUAL_ constant but a default user setting, so the
	 * helper plants the value in $VICTUAL_DEFAULT_USER_SETTINGS before config-dist.php runs
	 * (DefaultUserSetting() keeps the first registration of a name).
	 */
	public function testTheAutoNightModeRangeHasToBeTwoWallClockTimes()
	{
		self::assertConfigurationRefused(
			[],
			'auto_night_mode_time_range_from is not in HH:mm format',
			'24:00 is not a clock time',
			['auto_night_mode_time_range_from' => '24:00']
		);
		self::assertConfigurationRefused(
			[],
			'auto_night_mode_time_range_from is not in HH:mm format',
			'nor is a time with a seconds field',
			['auto_night_mode_time_range_from' => '22:00:00']
		);
		self::assertConfigurationRefused(
			[],
			'auto_night_mode_time_range_to is not in HH:mm format',
			'the end of the range is checked too',
			['auto_night_mode_time_range_to' => '6:00']
		);

		$verdict = self::validateConfiguration([], ['auto_night_mode_time_range_from' => '00:00', 'auto_night_mode_time_range_to' => '23:59']);
		self::assertTrue($verdict['ok'], 'midnight to one minute to midnight is a valid range: ' . ($verdict['message'] ?? ''));
	}

	/**
	 * Like runHelper(), but the extra environment entries are passed through under their
	 * own names rather than prefixed with VICTUAL_ - for DatabaseDialect::SQLITE_TOOLING_ENV,
	 * which is an environment variable exactly so that it is not a setting.
	 */
	private static function runHelperWithRawEnvironment(array $spec, array $settings, array $raw): array
	{
		return self::runHelper($spec, $settings, [], $raw);
	}

	// ============================================================== helpers/StderrLogger.php

	/**
	 * Runs the logger in a subprocess and returns the lines it wrote to stderr. stderr is
	 * the whole of this logger's retention story (plan 11 question 7), so reading it is the
	 * only way to assert on what it recorded - and it has to belong to a process whose
	 * stderr the test owns.
	 *
	 * @return string[]
	 */
	private static function logLines(array $records, ?string $minimumLevel = null): array
	{
		$spec = ['task' => 'logger', 'records' => $records];

		if ($minimumLevel !== null)
		{
			$spec['minimum_level'] = $minimumLevel;
		}

		$verdict = self::runHelper($spec);

		self::assertTrue($verdict['ok'], 'the logger threw: ' . ($verdict['message'] ?? ''));

		return array_values(array_filter(explode("\n", trim($verdict['stderr'])), fn ($line) => $line !== ''));
	}

	public function testAStderrRecordCarriesItsTimestampLevelAndMessage()
	{
		$lines = self::logLines([['level' => 'error', 'message' => 'the thing broke']]);

		self::assertCount(1, $lines, 'one record is one line, so a collector keeps one event as one record');
		self::assertMatchesRegularExpression(
			'/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}\] ERROR: the thing broke$/',
			$lines[0],
			'the record is [timestamp] LEVEL: message'
		);
	}

	public function testContextIsAppendedAsJsonOnTheSameLine()
	{
		$lines = self::logLines([
			['level' => 'warning', 'message' => 'with context', 'context' => ['route' => '/api/stock', 'status' => 500, 'name' => 'Käse']],
			['level' => 'warning', 'message' => 'without context'],
			['level' => 'warning', 'message' => 'with empty context', 'context' => []]
		]);

		self::assertCount(3, $lines);
		self::assertStringContainsString('{"route":"/api/stock","status":500,"name":"Käse"}', $lines[0], 'slashes and non-ASCII are left alone so the line stays readable');
		self::assertStringEndsWith('WARNING: without context', $lines[1], 'no context means nothing is appended');
		self::assertStringEndsWith('WARNING: with empty context', $lines[2], 'an empty context is the same as none');
	}

	public function testRecordsBelowTheMinimumLevelAreDiscardedAndTheRestAreKept()
	{
		$records = [
			['level' => 'debug', 'message' => 'debug record'],
			['level' => 'info', 'message' => 'info record'],
			['level' => 'warning', 'message' => 'warning record'],
			['level' => 'emergency', 'message' => 'emergency record']
		];

		$all = self::logLines($records);
		self::assertCount(4, $all, 'the default minimum is debug, which discards nothing');

		$fromWarning = self::logLines($records, 'warning');
		self::assertCount(2, $fromWarning, 'debug and info are below warning');
		self::assertStringContainsString('warning record', $fromWarning[0]);
		self::assertStringContainsString('emergency record', $fromWarning[1]);

		$fromEmergency = self::logLines($records, 'emergency');
		self::assertCount(1, $fromEmergency, 'the most severe level is the last thing still logged');
		self::assertStringContainsString('emergency record', $fromEmergency[0]);
	}

	/**
	 * The alternative to logging an unrecognised level is losing records because somebody
	 * spelled a constant wrong, which is the trade the class documents.
	 */
	public function testALevelNobodyRecognisesIsLoggedRatherThanSwallowed()
	{
		$unknownRecord = self::logLines([['level' => 'shout', 'message' => 'misspelled level']], 'emergency');
		self::assertCount(1, $unknownRecord, 'a record whose level is not a PSR-3 name survives even the strictest minimum');
		self::assertStringContainsString('SHOUT: misspelled level', $unknownRecord[0]);

		$unknownMinimum = self::logLines([['level' => 'debug', 'message' => 'kept anyway']], 'loud');
		self::assertCount(1, $unknownMinimum, 'and a minimum that is not a PSR-3 name filters nothing');
		self::assertStringContainsString('DEBUG: kept anyway', $unknownMinimum[0]);
	}

	/**
	 * A context value it cannot encode is rendered as null rather than dropping the whole
	 * line - losing the record would lose the event the context was attached to.
	 */
	public function testAContextValueThatCannotBeEncodedStillLeavesARecord()
	{
		$resource = self::logLines([['level' => 'error', 'message' => 'unencodable value', 'context_kind' => 'resource']]);
		self::assertCount(1, $resource);
		self::assertStringContainsString('ERROR: unencodable value', $resource[0]);
		self::assertStringContainsString('{"handle":null}', $resource[0], 'the stream becomes null and the record survives');

		$deep = self::logLines([['level' => 'error', 'message' => 'very deep context', 'context_kind' => 'too-deep']]);
		self::assertCount(1, $deep, 'a context nested past json_encode\'s depth limit still leaves one line');
		self::assertStringContainsString('ERROR: very deep context', $deep[0]);
	}

	/**
	 * A logger that cannot open its stream drops the record rather than failing the request
	 * it was logging. The subject is a 500 page's last line of defence, so it must not be
	 * able to become the thing that breaks.
	 */
	public function testARecordIsDroppedRatherThanThrownWhenStderrCannotBeOpened()
	{
		$verdict = self::runHelper([
			'task' => 'logger',
			'close_stderr' => true,
			'records' => [['level' => 'error', 'message' => 'nowhere to write this', 'context' => ['route' => '/api/stock']]]
		]);

		self::assertTrue($verdict['ok'], 'writing to a closed stderr threw: ' . ($verdict['message'] ?? ''));
		self::assertSame(1, $verdict['result']['records'], 'the call returned normally');
		self::assertSame('', $verdict['stderr'], 'and nothing reached stderr, since there was no stderr');
	}

	// ============================================================= helpers/WebhookRunner.php

	/** @var resource|null The php -S process standing in for a webhook receiver */
	private static $webhookServer = null;

	private static string $webhookBase = '';

	private static string $webhookLog = '';

	/**
	 * Starts the loopback listener WebhookRunner posts to.
	 *
	 * A local stand-in on 127.0.0.1 and nothing else: AGENTS.md:40 keeps this tree free of
	 * user-configurable outbound URLs, so a test of the outgoing POST must not be able to
	 * become a test of a reachable external host. The same shape the MQTT phases use for a
	 * broker.
	 */
	private static function startWebhookServer(): void
	{
		self::$webhookLog = self::$scratch . '/webhook-requests.jsonl';
		@unlink(self::$webhookLog);

		$port = self::freePort();
		self::$webhookBase = 'http://127.0.0.1:' . $port;

		$environment = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$environment['HELPERUNITS_WEBHOOK_LOG'] = self::$webhookLog;
		// The listener is not the subject and its own coverage is noise, so it is started
		// without the interpreter-level prepend the suite hooks for measurement.
		unset($environment['VICTUAL_COVERAGE_DIR']);

		self::$webhookServer = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/helperunits-subprocess-helper.php'],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$environment
		);

		self::assertIsResource(self::$webhookServer, 'could not start the loopback webhook listener');

		foreach ($pipes as $pipe)
		{
			stream_set_blocking($pipe, false);
		}

		self::$webhookPipes = $pipes;

		for ($attempt = 0; $attempt < 100; $attempt++)
		{
			$probe = @fsockopen('127.0.0.1', $port, $errorNumber, $errorString, 0.2);

			if ($probe !== false)
			{
				fclose($probe);

				return;
			}

			usleep(50000);
		}

		self::fail('the loopback webhook listener never accepted a connection on port ' . $port);
	}

	/** @var array */
	private static array $webhookPipes = [];

	private static function stopWebhookServer(): void
	{
		if (self::$webhookServer === null)
		{
			return;
		}

		foreach (self::$webhookPipes as $pipe)
		{
			@fclose($pipe);
		}

		proc_terminate(self::$webhookServer);
		proc_close(self::$webhookServer);

		self::$webhookServer = null;
		self::$webhookPipes = [];
	}

	private static function freePort(): int
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorString);
		self::assertIsResource($socket, 'could not reserve a loopback port');

		$name = stream_socket_get_name($socket, false);
		fclose($socket);

		return (int)substr($name, strrpos($name, ':') + 1);
	}

	/** @return array[] One entry per request the listener recorded, in arrival order. */
	private static function webhookRequests(): array
	{
		if (!file_exists(self::$webhookLog))
		{
			return [];
		}

		$requests = [];

		foreach (explode("\n", trim((string)file_get_contents(self::$webhookLog))) as $line)
		{
			if ($line !== '')
			{
				$requests[] = json_decode($line, true);
			}
		}

		return $requests;
	}

	/**
	 * FINDING: WebhookRunner has no caller left in the application.
	 *
	 * Plan 32 moved the five label kinds onto the label subsystem and deleted the webhook
	 * path (docs/plans/landed/32-label-kinds.md:376-378), and plan 18's InfluxEventWriter -
	 * the caller ADR-0019's step 3 kept it for - uses GuzzleHttp\Client directly. The class
	 * is still autoloaded and still works, which is what these cases assert; whether it
	 * should still exist is the maintainer's call, not this test's.
	 */
	public function testAWebhookPostsItsArgumentsAsFormParametersByDefault()
	{
		self::startWebhookServer();

		try
		{
			(new WebhookRunner())->run(self::$webhookBase . '/print', ['product' => 'Cheese', 'amount' => '2']);

			$requests = self::webhookRequests();

			self::assertCount(1, $requests, 'exactly one request reached the listener');
			self::assertSame('POST', $requests[0]['method'], 'a webhook is a POST');
			self::assertSame('/print', $requests[0]['path'], 'the path of the configured URL is used unchanged');
			self::assertStringContainsString('application/x-www-form-urlencoded', $requests[0]['content_type']);

			parse_str($requests[0]['body'], $received);
			self::assertSame(['product' => 'Cheese', 'amount' => '2'], $received, 'the arguments arrive as form fields');
		}
		finally
		{
			self::stopWebhookServer();
		}
	}

	public function testAWebhookPostsJsonWhenAskedTo()
	{
		self::startWebhookServer();

		try
		{
			(new WebhookRunner())->run(self::$webhookBase . '/print', ['product' => 'Cheese', 'amount' => 2], true);

			$requests = self::webhookRequests();

			self::assertCount(1, $requests);
			self::assertStringContainsString('application/json', $requests[0]['content_type']);
			self::assertSame(['product' => 'Cheese', 'amount' => 2], json_decode($requests[0]['body'], true), 'the JSON body keeps the argument types');
		}
		finally
		{
			self::stopWebhookServer();
		}
	}

	public function testRunAllPostsOnceToEachUrlAndNotAtAllToNone()
	{
		self::startWebhookServer();

		try
		{
			$runner = new WebhookRunner();

			$runner->runAll([], ['product' => 'Cheese']);
			self::assertSame([], self::webhookRequests(), 'an empty list of webhooks sends nothing');

			$runner->runAll([self::$webhookBase . '/first', self::$webhookBase . '/second'], ['product' => 'Cheese']);

			$requests = self::webhookRequests();
			self::assertCount(2, $requests);
			self::assertSame(['/first', '/second'], array_column($requests, 'path'), 'each URL is posted to once, in order');
		}
		finally
		{
			self::stopWebhookServer();
		}
	}

	/**
	 * Architecture review defect 6 (docs/architecture-review.md:52): a printer that does not
	 * answer must not turn a user's action into a 500. The failure is logged and swallowed.
	 *
	 * The unreachable address is a port on the loopback interface that nothing is listening
	 * on, so the refusal is immediate and local.
	 */
	public function testAnUnreachableWebhookIsLoggedAndNotThrown()
	{
		$deadPort = self::freePort();

		$before = self::webhookRequests();

		(new WebhookRunner())->run('http://127.0.0.1:' . $deadPort . '/print', ['product' => 'Cheese']);

		self::assertSame($before, self::webhookRequests(), 'nothing was delivered, and nothing was thrown either');
	}

	// ========================================================= services/ApplicationService.php

	private static function request(string $method = 'GET', array $headers = [])
	{
		$request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api');

		foreach ($headers as $name => $value)
		{
			$request = $request->withHeader($name, $value);
		}

		return $request;
	}

	public function testTheChangelogIsParsedFromItsFileNamesNewestReleaseFirst()
	{
		$changelog = ApplicationService::GetInstance()->GetChangelog();

		self::assertNotEmpty($changelog['changelog_items']);

		$releaseNumbers = array_column($changelog['changelog_items'], 'release_number');
		$descending = $releaseNumbers;
		rsort($descending);

		self::assertSame($descending, $releaseNumbers, 'the newest release is first');
		self::assertSame($releaseNumbers[0], $changelog['newest_release_number'], 'the reported newest number is the first item');
		self::assertSame(max($releaseNumbers), $changelog['newest_release_number'], 'and it is the largest one');

		$first = $changelog['changelog_items'][0];
		self::assertIsInt($first['release_number']);
		self::assertNotSame('', $first['version'], 'the version comes out of the middle of the file name');
		self::assertContains('1.4.0', array_column($changelog['changelog_items'], 'version'), 'a released version is carried as it is written in the file name');
		self::assertStringNotContainsString('.md', $first['release_date'], 'and the release date out of the end, without the extension');
		self::assertContains('2017-06-04', array_column($changelog['changelog_items'], 'release_date'), 'a dated release keeps its date');
		self::assertNotSame('', trim($first['body']), 'the body is the file contents');

		// The template is not a release; a negative control for a filter that could have
		// been vacuous
		self::assertNotContains('TEMPLATE.md', array_column($changelog['changelog_items'], 'version'));
		self::assertCount(count(glob(VICTUAL_ROOT_PATH . '/changelog/*.md')) - 1, $changelog['changelog_items'], 'every changelog file but the template is an item');
	}

	public function testTheInstalledVersionIsReadFromVersionJsonAndCachedPerInstance()
	{
		$service = ApplicationService::GetInstance();
		$version = $service->GetInstalledVersion();

		$onDisk = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/version.json'));

		self::assertSame($onDisk->Version, $version->Version);
		self::assertSame($onDisk->ReleaseDate, $version->ReleaseDate);
		self::assertSame($version, $service->GetInstalledVersion(), 'the file is read once per instance');
	}

	public function testSystemInfoReportsTheEngineActuallyServingTheInstallation()
	{
		$info = ApplicationService::GetInstance()->GetSystemInfo(self::request('GET', ['User-Agent' => 'HelperUnits/1.0']));

		self::assertSame(phpversion(), $info['php_version']);
		self::assertSame('HelperUnits/1.0', $info['client'], 'plan 15-C8: the client is read through PSR-7');
		self::assertMatchesRegularExpression('/^PostgreSQL \d+(\.\d+)*$/', $info['database_engine'], 'the engine name and the bare version, without libpq\'s packaging suffix');
		self::assertSame($info['victual_version']->Version, ApplicationService::GetInstance()->GetInstalledVersion()->Version);

		$maximumMigration = (int)self::$db->query('SELECT max(migration) FROM migrations')->fetchColumn();
		self::assertSame($maximumMigration, (int)$info['db_version'], 'the schema version is the highest migration this schema has run');

		self::assertArrayHasKey('sqlite_version', $info, 'ADR-0005: the key stays in the response whatever the driver situation is');
		self::assertArrayHasKey('os', $info);
		self::assertStringContainsString(php_uname('s'), $info['os']);
	}

	public function testSystemInfoAnswersUnknownForACallerThatNamedNoClient()
	{
		$withoutRequest = ApplicationService::GetInstance()->GetSystemInfo();
		self::assertSame('unknown', $withoutRequest['client'], 'a service method does not require a request to run');

		$withoutHeader = ApplicationService::GetInstance()->GetSystemInfo(self::request());
		self::assertSame('unknown', $withoutHeader['client'], 'and a request carrying no User-Agent is answered the same way');
	}

	/**
	 * The About page and the 500 page both read this, and the 500 page is reached precisely
	 * when things are broken - including, sometimes, the database. A diagnostic that throws
	 * while reporting a diagnostic is how one 500 becomes a fatal error.
	 */
	public function testTheEngineVersionIsUnavailableRatherThanFatalWhenTheConnectionIsNot()
	{
		$property = new \ReflectionProperty(DatabaseService::class, 'DbConnectionRaw');
		$real = $property->getValue();

		try
		{
			// Something that is not a connection, which is what a broken one behaves like
			// from here: the attribute read throws rather than answering.
			$property->setValue(null, new \stdClass());

			$info = ApplicationService::GetInstance()->GetSystemInfo();

			self::assertSame('unavailable', $info['database_engine'], 'the diagnostic degrades instead of throwing');
		}
		finally
		{
			$property->setValue(null, $real);
		}

		self::assertStringStartsWith('PostgreSQL', ApplicationService::GetInstance()->GetSystemInfo()['database_engine'], 'and recovers once the connection is back');
	}

	/**
	 * Found by plan 20's verification: the Nix app and web images drop pdo_sqlite, and both
	 * of these used to open new PDO('sqlite::memory:') unconditionally - so /about,
	 * GET /api/system/info and GET /api/system/time answered a fatal "could not find driver",
	 * including from inside ExceptionController's 500 page.
	 *
	 * The keys stay in the response either way (ADR-0005); what changes is that a deployment
	 * without the driver reports "" rather than dying. A subprocess started with -n and only
	 * the PostgreSQL driver is that deployment.
	 */
	public function testSystemInfoAndSystemTimeSurviveAnInterpreterWithoutPdoSqlite()
	{
		$verdict = self::runHelper(
			['task' => 'application-info', 'schema' => self::Schema(), 'offset' => 0],
			[
				'DB_DRIVER' => 'pgsql',
				'DB_HOST' => (string)getenv('PGHOST'),
				'DB_PORT' => (string)getenv('PGPORT'),
				'DB_NAME' => (string)getenv('PHPUNIT_DB_NAME'),
				'DB_USER' => (string)getenv('PGUSER'),
				'DB_PASSWORD' => (string)getenv('PGPASSWORD')
			],
			['-n', '-d', 'extension=pdo', '-d', 'extension=pdo_pgsql', '-d', 'extension=pgsql']
		);

		self::assertTrue($verdict['ok'], 'the pdo_sqlite-less boot failed: ' . ($verdict['message'] ?? ''));
		self::assertSame(['pgsql'], $verdict['result']['drivers'], 'the driver really is absent in that process');

		self::assertSame('', $verdict['result']['sqlite_version'], 'the vestigial field is empty rather than fatal');
		self::assertSame('', $verdict['result']['time_local_sqlite3'], 'and so is the vestigial time');
		self::assertStringStartsWith('PostgreSQL', $verdict['result']['database_engine'], 'the engine that is actually serving still answers');
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $verdict['result']['time_local'], 'and so does the real local time');

		// The negative control: in this process, which does have the driver, both fields
		// carry a value
		self::assertNotSame('', ApplicationService::GetInstance()->GetSystemInfo()['sqlite_version']);
		self::assertNotSame('', ApplicationService::GetInstance()->GetSystemTime()['time_local_sqlite3']);
	}

	public function testSystemTimeReportsTheSameInstantFromEveryPerspective()
	{
		$now = ApplicationService::GetInstance()->GetSystemTime();

		self::assertSame(0, $now['offset'], 'no offset is the default');
		self::assertSame(date_default_timezone_get(), $now['timezone']);
		self::assertEqualsWithDelta(time(), $now['timestamp'], 2, 'the timestamp is now');
		self::assertSame(date('Y-m-d H:i:s', $now['timestamp']), $now['time_local']);
		self::assertSame(gmdate('Y-m-d H:i:s', $now['timestamp']), $now['time_utc'], 'the UTC rendering is the same instant');
		self::assertArrayHasKey('time_local_sqlite3', $now, 'ADR-0005: the key stays in the response');
	}

	public function testSystemTimeShiftsByTheOffsetInBothDirections()
	{
		$base = ApplicationService::GetInstance()->GetSystemTime();
		$ahead = ApplicationService::GetInstance()->GetSystemTime(3600);
		$behind = ApplicationService::GetInstance()->GetSystemTime(-3600);

		self::assertSame(3600, $ahead['offset']);
		self::assertSame(-3600, $behind['offset']);

		self::assertEqualsWithDelta(3600, $ahead['timestamp'] - $base['timestamp'], 2, 'a positive offset moves the clock forward');
		self::assertEqualsWithDelta(-3600, $behind['timestamp'] - $base['timestamp'], 2, 'and a negative one back');
		self::assertSame(date('Y-m-d H:i:s', $ahead['timestamp']), $ahead['time_local']);
		self::assertSame(gmdate('Y-m-d H:i:s', $behind['timestamp']), $behind['time_utc']);
	}

	// ========================================================== services/UserfieldsService.php

	private static function userfields(): UserfieldsService
	{
		return UserfieldsService::GetInstance();
	}

	/**
	 * The userfield definitions and the objects they hang off. Seeded for the class rather
	 * than by whichever case runs first: four of the cases below read them, and under
	 * --order-by=random a reader can precede the writer. Each case owns its own product so
	 * that no case can be made to pass or fail by another one's write.
	 */
	private static function seedUserfieldFixtures(): void
	{
		self::$db->exec('INSERT INTO userfields (id, entity, name, caption, type, sort_number) VALUES '
			. "(9601, 'products', 'helperunits_zeta', 'Zeta', 'text-single-line', 2), "
			. "(9602, 'products', 'helperunits_alpha', 'Alpha', 'number-integral', 1), "
			. "(9603, 'chores', 'helperunits_chore_field', 'Chore field', 'checkbox', 1)");

		self::$db->exec('INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock) VALUES '
			. "(9600, 'HelperUnitsProduct', 2, 2, 2), "
			. "(9601, 'HelperUnitsOtherProduct', 2, 2, 2), "
			. "(9602, 'HelperUnitsListedProduct', 2, 2, 2), "
			. "(9603, 'HelperUnitsHalfWrittenProduct', 2, 2, 2)");
	}

	public function testTheEntityListIsTheExposedEntitiesPlusUserEntitiesPlusUsers()
	{
		$before = self::userfields()->GetEntities();

		self::assertContains('products', $before, 'an exposed API entity');
		self::assertContains('users', $before, 'the one special entity');
		self::assertNotContains('userentity-helperunits_gadgets', $before, 'the negative control: nothing has defined this one yet');

		self::$db->exec("INSERT INTO userentities (id, name, caption, description) VALUES (9600, 'helperunits_gadgets', 'Gadgets', 'for the userfield entity list')");

		$after = UserfieldsService::GetInstance()->GetEntities();

		self::assertContains('userentity-helperunits_gadgets', $after, 'a user defined entity joins the list under its userentity- name');

		$sorted = $after;
		sort($sorted);
		self::assertSame($sorted, $after, 'the list is sorted');
	}

	public function testFieldsAreReadPerEntityAndAnUnknownEntityIsRefused()
	{
		self::assertSame([], self::userfields()->GetFields('batteries'), 'an entity the fixture defines no userfield for has none');

		$productFields = self::userfields()->GetFields('products');

		self::assertSame(['helperunits_alpha', 'helperunits_zeta'], array_map(fn ($field) => $field->name, $productFields), 'ordered by sort number');
		self::assertCount(1, self::userfields()->GetFields('chores'), 'a field of another entity is not listed here');

		$all = self::userfields()->GetAllFields();
		self::assertCount(3, $all, 'across all entities, every definition');

		$single = self::userfields()->GetField(9602);
		self::assertSame('helperunits_alpha', $single->name);
		self::assertSame('number-integral', $single->type);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Entity does not exist or is not exposed');
		self::userfields()->GetFields('userentity-there_is_no_such_entity');
	}

	public function testTheFieldTypeListIsTheSetTheUserInterfaceOffers()
	{
		$types = self::userfields()->GetFieldTypes();

		self::assertSame('checkbox', $types['USERFIELD_TYPE_CHECKBOX']);
		self::assertSame('text-multi-line', $types['USERFIELD_TYPE_SINGLE_MULTILINE_TEXT']);
		self::assertContains('preset-list', $types);
		self::assertCount(14, $types, 'the fourteen USERFIELD_TYPE_ constants and nothing else');
	}

	public function testValuesAreNullUntilSomethingStoresThemAndThenReadBack()
	{
		$empty = self::userfields()->GetValues('products', 9600);

		self::assertSame(['helperunits_alpha' => null, 'helperunits_zeta' => null], $empty, 'every field of the entity is a key, with null where nothing is stored');

		self::userfields()->SetValues('products', 9600, ['helperunits_alpha' => '7']);

		$stored = (int)self::$db->query("SELECT count(*) FROM userfield_values WHERE field_id = 9602 AND object_id = '9600'")->fetchColumn();
		self::assertSame(1, $stored, 'the durable state: one row for the field and object');
		self::assertSame('7', self::$db->query("SELECT value FROM userfield_values WHERE field_id = 9602 AND object_id = '9600'")->fetchColumn());

		self::assertSame(['helperunits_alpha' => '7', 'helperunits_zeta' => null], self::userfields()->GetValues('products', 9600));
		self::assertSame(['helperunits_alpha' => null, 'helperunits_zeta' => null], self::userfields()->GetValues('products', 9601), 'the negative control: another object is untouched');

		// The same call again is an update, not a second row
		self::userfields()->SetValues('products', 9600, ['helperunits_alpha' => '9']);

		self::assertSame(1, (int)self::$db->query("SELECT count(*) FROM userfield_values WHERE field_id = 9602 AND object_id = '9600'")->fetchColumn(), 'a second write updates rather than inserting');
		self::assertSame('9', self::userfields()->GetValues('products', 9600)['helperunits_alpha']);
	}

	public function testReadingTheValuesOfAnUnknownEntityIsRefused()
	{
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Entity does not exist or is not exposed');

		self::userfields()->GetValues('userentity-there_is_no_such_entity', 1);
	}

	public function testAllValuesOfAnEntityAreListedAndAnUnknownEntityIsRefused()
	{
		self::userfields()->SetValues('products', 9602, ['helperunits_alpha' => '11']);

		$values = self::userfields()->GetAllValues('products');

		self::assertNotEmpty($values, 'the value this case stored is listed');
		self::assertContains('helperunits_alpha', array_map(fn ($row) => $row->name, $values));
		self::assertSame([], self::userfields()->GetAllValues('chores'), 'an entity whose fields nobody has filled in has no values');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Entity does not exist or is not exposed');
		self::userfields()->GetAllValues('not-an-entity');
	}

	public function testSettingValuesForAnUnknownEntityWritesNothing()
	{
		$before = (int)self::$db->query('SELECT count(*) FROM userfield_values')->fetchColumn();

		try
		{
			self::userfields()->SetValues('not-an-entity', 9600, ['helperunits_alpha' => 'x']);
			self::fail('an unknown entity was accepted');
		}
		catch (\Exception $exception)
		{
			self::assertSame('Entity does not exist or is not exposed', $exception->getMessage());
		}

		self::assertSame($before, (int)self::$db->query('SELECT count(*) FROM userfield_values')->fetchColumn(), 'the refusal left the state exactly as it was');
	}

	/**
	 * DEFECT: a refusal half-writes.
	 *
	 * SetValues() (services/UserfieldsService.php:157-184) loops over the submitted keys and
	 * throws on the first that is not a userfield of the entity, after having already
	 * written the keys before it. Nothing above it opens a transaction -
	 * GenericEntityApiController::SetUserfields (controllers/Api/GenericEntityApiController.php:446-462)
	 * calls it straight inside HandleApiCall(), which only turns the exception into a 400 -
	 * so PUT /api/userfields/{entity}/{objectId} answers "Field ... is not a valid
	 * userfield" having already changed the object.
	 *
	 * Correct behaviour: either validate every key before writing any, or run the loop in a
	 * transaction. Pinned rather than skipped, because the current answer is what callers
	 * see today.
	 */
	public function testAnInvalidFieldNameRefusesAndWritesNothing()
	{
		self::$db->exec("DELETE FROM userfield_values WHERE object_id = '9603'");

		try
		{
			self::userfields()->SetValues('products', 9603, ['helperunits_alpha' => '3', 'helperunits_not_a_field' => 'x']);
			self::fail('an unknown field name was accepted');
		}
		catch (\Exception $exception)
		{
			self::assertSame('Field helperunits_not_a_field is not a valid userfield of the given entity', $exception->getMessage());
		}

		self::assertSame(
			false,
			self::$db->query("SELECT value FROM userfield_values WHERE field_id = 9602 AND object_id = '9603'")->fetchColumn(),
			'the invalid key refused the whole call, leaving the valid key before it unwritten too'
		);

		self::$db->exec("DELETE FROM userfield_values WHERE object_id = '9603'");
	}

	// ======================================================== services/LocalizationService.php

	public function testTheLocaleInstanceIsSharedPerLocaleAndDefaultsToTheConfiguredOne()
	{
		$configured = LocalizationService::GetInstance();
		$explicit = LocalizationService::GetInstance(VICTUAL_LOCALE);
		$german = LocalizationService::GetInstance('de');

		self::assertSame($configured, $explicit, 'an empty locale means VICTUAL_LOCALE');
		self::assertSame($german, LocalizationService::GetInstance('de'), 'one instance per locale');
		self::assertNotSame($configured, $german, 'and a different locale is a different instance');
	}

	public function testTranslationFallsBackToTheSourceStringAndFillsPlaceholders()
	{
		$english = LocalizationService::GetInstance('en');

		self::assertSame('Stock overview', $english->__t('Stock overview'), 'an untranslated locale answers the source string');
		self::assertSame('assigned to Ada', $english->__t('assigned to %s', 'Ada'), 'a single placeholder value is filled in');
		self::assertSame(
			'Stock overview',
			$english->__t('Stock overview', []),
			'an array argument is spread over the placeholders, and a string with none is unchanged'
		);

		$german = LocalizationService::GetInstance('de');
		self::assertSame('Bestandsübersicht', $german->__t('Stock overview'), 'a translated locale answers the translation');
		self::assertNotSame($german->__t('Stock overview'), $english->__t('Stock overview'), 'the negative control: the two locales do not agree');
	}

	public function testPluralTranslationChoosesTheFormForTheNumber()
	{
		$english = LocalizationService::GetInstance('en');

		self::assertSame('1 day', $english->__n(1, '%s day', '%s days'));
		self::assertSame('3 days', $english->__n(3, '%s day', '%s days'));
		self::assertSame('0 days', $english->__n(0, '%s day', '%s days'), 'zero takes the plural form in English');
		self::assertSame('-3 days', $english->__n(-3, '%s day', '%s days'), 'the absolute value decides the form, and the number is printed as given');

		self::assertSame('2 day', $english->__n(2, '%s day', ''), 'an empty plural form falls back to the singular');
	}

	public function testThePluralHeaderIsReadFromTheLocaleAndDefaultsWhenItIsAbsent()
	{
		$english = LocalizationService::GetInstance('en');

		self::assertSame(2, $english->GetPluralCount(), 'en carries no Plural-Forms header, so the default applies');
		self::assertSame('(n != 1)', $english->GetPluralDefinition());

		$german = LocalizationService::GetInstance('de');
		self::assertSame(2, $german->GetPluralCount(), 'de declares two forms');
		self::assertSame('(n != 1)', $german->GetPluralDefinition());

		$japanese = LocalizationService::GetInstance('ja');
		self::assertSame(1, $japanese->GetPluralCount(), 'ja declares one, which is what makes the header read rather than assumed');
		self::assertSame('0', $japanese->GetPluralDefinition());
	}

	public function testQuantityUnitNamesAreTranslatedFromTheDatabaseRatherThanFromPoFiles()
	{
		// The translated forms live in the row, one per line, which is what makes this a
		// differential rather than two spellings of the same fallback
		self::$db->exec("INSERT INTO quantity_units (id, name, name_plural, plural_forms) VALUES (9700, 'HelperUnitsJar', 'HelperUnitsJars', E'Glas\\nGlaeser')");

		// A locale nothing has instantiated yet, because the quantity unit translator is
		// built in the constructor and the instances above predate this row
		$service = LocalizationService::GetInstance('en_GB');

		self::assertSame('HelperUnitsJar', $service->__n(1, 'HelperUnitsJar', 'HelperUnitsJars', true), 'the singular is the unit name as stored');
		self::assertSame('Glas', $service->__n(4, 'HelperUnitsJar', 'HelperUnitsJars', true), 'and the plural comes from the row\'s plural_forms, not from a .po file');

		// The negative control: the UI translator knows nothing about quantity units, so
		// the same call without the flag answers the source strings
		self::assertSame('HelperUnitsJars', $service->__n(4, 'HelperUnitsJar', 'HelperUnitsJars', false));
	}

	public function testTheTranslationsAreAlsoServedAsJsonForTheClientSide()
	{
		$service = LocalizationService::GetInstance('de');

		$ui = json_decode($service->GetPoAsJsonString(), true);
		$quantityUnits = json_decode($service->GetPoAsJsonStringQu(), true);

		self::assertIsArray($ui, 'the UI translations are valid JSON');
		self::assertIsArray($quantityUnits, 'and so are the quantity unit ones');
		self::assertNotSame($ui, $quantityUnits, 'they are two separate translation sets');
	}

	/**
	 * Dev mode collects new source strings into localization/strings.pot. The collection is
	 * conditional on the string being unknown, which is what this asserts - a string the
	 * .pot files already carry leaves the file byte for byte as it was.
	 *
	 * A subprocess because VICTUAL_MODE is a constant, and because loading the .pot files at
	 * all only happens in that mode.
	 */
	public function testDevModeDoesNotRewriteThePotFileForAStringItAlreadyKnows()
	{
		$verdict = self::runHelper(
			['task' => 'localization-dev', 'locale' => 'de', 'texts' => ['Stock overview', 'Candy cupboard']],
			[
				'MODE' => 'dev',
				'DB_DRIVER' => 'pgsql',
				'DB_HOST' => (string)getenv('PGHOST'),
				'DB_PORT' => (string)getenv('PGPORT'),
				'DB_NAME' => (string)getenv('PHPUNIT_DB_NAME'),
				'DB_USER' => (string)getenv('PGUSER'),
				'DB_PASSWORD' => (string)getenv('PGPASSWORD')
			]
		);

		self::assertTrue($verdict['ok'], 'the dev boot failed: ' . ($verdict['message'] ?? ''));
		self::assertSame('dev', $verdict['result']['mode']);
		self::assertSame('Bestandsübersicht', $verdict['result']['translated']['Stock overview'], 'the ordinary strings are loaded');
		self::assertTrue($verdict['result']['pot_unchanged'], 'a string the .pot files already carry is not appended again');

		// The demo strings live in their own .po and are merged only outside production,
		// which is what makes this a differential rather than one more translated string
		self::assertSame('Süßigkeitenschrank', $verdict['result']['translated']['Candy cupboard'], 'outside production the locale\'s demo_data.po is merged in');

		$production = LocalizationService::GetInstance('de');
		self::assertSame('Bestandsübersicht', $production->__t('Stock overview'), 'this process is in production mode and still translates the ordinary strings');
		self::assertSame('Candy cupboard', $production->__t('Candy cupboard'), 'and does not carry the demo ones');
	}

	// =========================================================== services/CalendarService.php

	/**
	 * Everything the calendar can show, dated in 2030 so that the fixtures do not move with
	 * the calendar day (the unpinned best_before_date that flipped a golden file's JSON type
	 * is why the rule exists).
	 *
	 * Seeded for the class, like the userfield fixtures: every calendar case below reads it,
	 * so seeding it from the first of them would make the rest depend on running after it.
	 */
	private static function seedCalendarFixtures(): void
	{
		self::$db->exec("INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock) VALUES (9800, 'CalendarCheese', 2, 2, 2)");
		self::$db->exec("INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock) VALUES (9801, 'CalendarNeverStocked', 2, 2, 2)");
		self::$db->exec("INSERT INTO stock (id, product_id, amount, best_before_date, stock_id) VALUES (9800, 9800, 3, DATE '2030-04-05', 'calendar-stock-1')");

		self::$db->exec("INSERT INTO tasks (id, name, due_date) VALUES (9800, 'CalendarTask', DATE '2030-05-06')");
		self::$db->exec("INSERT INTO tasks (id, name, due_date, done) VALUES (9801, 'CalendarDoneTask', DATE '2030-05-07', 1)");

		self::$db->exec('INSERT INTO chores (id, name, period_type, period_days, start_date, track_date_only) '
			. "VALUES (9800, 'CalendarChore', 'daily', 1, TIMESTAMP '2030-06-07 09:00:00', 1)");
		self::$db->exec('INSERT INTO chores (id, name, period_type, period_days, start_date, track_date_only) '
			. "VALUES (9801, 'CalendarTimedChore', 'daily', 1, TIMESTAMP '2030-06-08 09:00:00', 0)");
		self::$db->exec('INSERT INTO chores (id, name, period_type, period_days, start_date, active) '
			. "VALUES (9802, 'CalendarInactiveChore', 'daily', 1, TIMESTAMP '2030-06-09 09:00:00', 0)");

		self::$db->exec("INSERT INTO batteries (id, name, charge_interval_days) VALUES (9800, 'CalendarBattery', 30)");

		self::$db->exec("INSERT INTO recipes (id, name, base_servings, desired_servings, type) VALUES (9800, 'CalendarRecipe', 1, 1, 'normal')");
		self::$db->exec("INSERT INTO meal_plan_sections (id, name, sort_number, time_info) VALUES (9800, 'CalendarBreakfast', 1, '07:30')");

		self::$db->exec("INSERT INTO meal_plan (id, day, type, recipe_id, section_id) VALUES (9800, DATE '2030-07-08', 'recipe', 9800, 9800)");
		self::$db->exec("INSERT INTO meal_plan (id, day, type, recipe_id, section_id) VALUES (9801, DATE '2030-07-09', 'recipe', 9800, -1)");
		self::$db->exec("INSERT INTO meal_plan (id, day, type, note, section_id) VALUES (9802, DATE '2030-07-10', 'note', 'CalendarNote', 9800)");
		self::$db->exec("INSERT INTO meal_plan (id, day, type, product_id, product_amount, section_id) VALUES (9803, DATE '2030-07-11', 'product', 9800, 1, -1)");
		self::$db->exec("INSERT INTO meal_plan (id, day, type, product_id, product_amount, section_id) VALUES (9804, DATE '2030-07-12', 'product', 9800, 1, 9800)");
	}

	/** The one event starting at the given moment, asserted to be unique. */
	private static function eventStarting(array $events, string $start): array
	{
		$matches = array_values(array_filter($events, fn ($event) => $event['start'] === $start));

		self::assertCount(1, $matches, "expected exactly one calendar event starting at \"$start\", found " . count($matches));

		return $matches[0];
	}

	/** The one event whose title contains the given text, asserted to be unique. */
	private static function eventTitled(array $events, string $needle): array
	{
		$matches = array_values(array_filter($events, fn ($event) => str_contains($event['title'], $needle)));

		self::assertCount(1, $matches, "expected exactly one calendar event mentioning \"$needle\", found " . count($matches));

		return $matches[0];
	}

	/**
	 * A caller holding nothing sees nothing. The feature flags are all on in this process,
	 * so the permission is the only thing deciding - which is the point: the calendar is
	 * assembled from other people's data.
	 */
	public function testTheCalendarShowsOnlyTheUngatedAreasToACallerHoldingNoPermissions()
	{
		self::grant([]);

		$events = CalendarService::GetInstance()->GetEvents();

		foreach ($events as $event)
		{
			self::assertStringContainsString('Battery charge cycle due', $event['title'], 'the only events without a permission gate are the battery ones');
		}

		$titles = array_column($events, 'title');
		self::assertNotContains('Product due: CalendarCheese', $titles);
		self::assertNotContains('Task due: CalendarTask', $titles);
	}

	public function testStockDueDatesBecomeAllDayEventsLinkedToTheStockOverview()
	{
		self::grant(['STOCK_VIEW']);

		$events = CalendarService::GetInstance()->GetEvents();
		$cheese = self::eventTitled($events, 'CalendarCheese');

		self::assertSame('Product due: CalendarCheese', $cheese['title']);
		self::assertSame('2030-04-05', $cheese['start'], 'the pinned best before date');
		self::assertSame('date', $cheese['date_format'], 'a due date is an all day event');
		self::assertStringEndsWith('/stockoverview', $cheese['link']);
		self::assertNotSame('', $cheese['color'], 'the event carries the user\'s configured colour');

		$titles = array_column($events, 'title');
		self::assertNotContains('Product due: CalendarNeverStocked', $titles, 'a product with no stock has no due date to show');
		self::assertNotContains('Task due: CalendarTask', $titles, 'and STOCK_VIEW alone does not reveal tasks');
	}

	public function testTaskDueDatesAppearForACallerWhoMaySeeTasks()
	{
		self::grant(['TASKS_VIEW']);

		$events = CalendarService::GetInstance()->GetEvents();
		$task = self::eventTitled($events, 'CalendarTask');

		self::assertSame('Task due: CalendarTask', $task['title']);
		self::assertSame('2030-05-06', $task['start']);
		self::assertSame('date', $task['date_format']);
		self::assertStringEndsWith('/tasks', $task['link']);

		self::assertNotContains('Task due: CalendarDoneTask', array_column($events, 'title'), 'a completed task is not still due');
	}

	public function testChoreEventsCarryTheirTrackingGranularityAndAssignment()
	{
		self::grant(['CHORES_VIEW']);

		$events = CalendarService::GetInstance()->GetEvents();

		$dateOnly = self::eventTitled($events, 'CalendarChore');
		self::assertSame('Chore due: CalendarChore', $dateOnly['title']);
		self::assertSame('datetime', $dateOnly['date_format']);
		self::assertTrue($dateOnly['allDay'], 'a chore tracked by date only is an all day event');
		self::assertStringEndsWith('/choresoverview', $dateOnly['link']);

		$timed = self::eventTitled($events, 'CalendarTimedChore');
		self::assertFalse($timed['allDay'], 'a chore that keeps a time of day is not');

		self::assertNotContains('Chore due: CalendarInactiveChore', array_column($events, 'title'), 'an inactive chore is not due');

		// The assignment suffix, which only appears once somebody is assigned
		self::assertStringNotContainsString('assigned to', $dateOnly['title']);

		self::$db->exec('UPDATE chores SET next_execution_assigned_to_user_id = 9000 WHERE id = 9800');
		self::$db->exec("UPDATE users SET first_name = 'Calendar', last_name = 'Caller' WHERE id = 9000");

		$assigned = self::eventTitled(CalendarService::GetInstance()->GetEvents(), 'CalendarChore');
		self::assertStringContainsString('(assigned to Calendar Caller)', $assigned['title'], 'the assignee is named in the title');
	}

	public function testBatteryChargeCyclesAreTimedEventsLinkedToTheBatteryOverview()
	{
		self::grant([]);

		$battery = self::eventTitled(CalendarService::GetInstance()->GetEvents(), 'CalendarBattery');

		self::assertSame('Battery charge cycle due: CalendarBattery', $battery['title']);
		self::assertSame('datetime', $battery['date_format']);
		self::assertStringEndsWith('/batteriesoverview', $battery['link']);
		self::assertNotSame('', $battery['start'], 'a battery that has never been charged is still due');
	}

	/**
	 * The meal plan's three row types, and the rule the class documents: an entry in a
	 * section with no time is an all day event, and one in a section with a time is a timed
	 * event at that time.
	 */
	public function testMealPlanEntriesBecomeAllDayOrTimedEventsAccordingToTheirSection()
	{
		self::grant(['MEALPLAN_VIEW']);

		$events = CalendarService::GetInstance()->GetEvents();

		$sectioned = self::eventStarting($events, '2030-07-08 07:30:00');
		self::assertSame('Meal plan recipe: CalendarBreakfast: CalendarRecipe', $sectioned['title'], 'the section name prefixes the entry');
		self::assertSame('2030-07-08 07:30:00', $sectioned['start'], 'the section time makes it a timed event');
		self::assertSame('datetime', $sectioned['date_format']);
		self::assertStringContainsString('recipe=9800', $sectioned['link'], 'a recipe entry links to the recipe');
		self::assertStringContainsString('/mealplan', $sectioned['description'], 'and describes the week it belongs to');

		$unsectioned = self::eventStarting($events, '2030-07-09');
		self::assertSame('Meal plan recipe: CalendarRecipe', $unsectioned['title'], 'the internal section contributes no prefix');
		self::assertSame('date', $unsectioned['date_format'], 'and no time, so the entry is all day');

		$note = self::eventTitled($events, 'CalendarNote');
		self::assertSame('Meal plan note: CalendarBreakfast: CalendarNote', $note['title']);
		self::assertSame('2030-07-10 07:30:00', $note['start']);
		self::assertStringContainsString('/mealplan', $note['link']);

		$product = self::eventStarting($events, '2030-07-11');
		self::assertSame('Meal plan product: CalendarCheese', $product['title']);
		self::assertSame('date', $product['date_format'], 'a product entry outside a named section is all day too');

		$sectionedProduct = self::eventStarting($events, '2030-07-12 07:30:00');
		self::assertSame('Meal plan product: CalendarBreakfast: CalendarCheese', $sectionedProduct['title'], 'and carries the section name when it has one');
		self::assertSame('datetime', $sectionedProduct['date_format'], 'and the section time makes it timed');
		self::assertStringContainsString('/mealplan', $sectionedProduct['link']);
	}

	/**
	 * The whole list, assembled for a caller holding everything - the shape the calendar
	 * page and the iCal export consume.
	 */
	public function testTheEventListIsEveryAreaTheCallerMaySeeAtOnce()
	{
		self::grant(['STOCK_VIEW', 'TASKS_VIEW', 'CHORES_VIEW', 'MEALPLAN_VIEW']);

		$events = CalendarService::GetInstance()->GetEvents();
		$titles = array_column($events, 'title');

		foreach (['Product due: CalendarCheese', 'Task due: CalendarTask', 'Battery charge cycle due: CalendarBattery', 'Meal plan note: CalendarBreakfast: CalendarNote'] as $expected)
		{
			self::assertContains($expected, $titles, "the combined list carries \"$expected\"");
		}

		foreach ($events as $event)
		{
			self::assertArrayHasKey('start', $event);
			self::assertArrayHasKey('link', $event);
			self::assertArrayHasKey('color', $event);
			self::assertContains($event['date_format'], ['date', 'datetime'], 'every event declares which of the two renderings it is');
		}
	}
}
