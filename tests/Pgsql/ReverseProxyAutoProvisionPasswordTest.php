<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #556: ReverseProxyAuthenticator::Authenticate() auto-provisions an account the
 * first time it sees a username, by calling UsersService::CreateUser($username, '', '', '')
 * - an empty string password. UsersService::CreateUser() hashes whatever it is given
 * (services/UsersService.php:111), so the stored hash is password_hash('', ...), and
 * password_verify('', $thatHash) is therefore true. Nothing about that account is
 * unusable; it is safe today only because PasswordLogin::Process() and
 * UsersService::CheckCurrentPassword() both refuse an empty submitted password before
 * ever calling password_verify() against it.
 *
 * PR #529 already settled the convention for this exact situation on the
 * POST /users create path (UsersApiController::CreatedUserPassword()): store
 * cryptographically random bytes instead of an empty string, so the stored hash does not
 * verify against '' or any other fixed value regardless of what guards a future caller
 * does or does not have. This test asserts ReverseProxyAuthenticator's auto-provisioned
 * account gets the same treatment.
 *
 * Runs the real middleware stack in a subprocess, the way AuthStackTest does and for the
 * same reasons (REMOTE_USER travels through $_SERVER, which a PSR-7 request does not
 * carry) - see tests/Pgsql/authstack-subprocess-helper.php's own docblock.
 */
class ReverseProxyAutoProvisionPasswordTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
	}

	/** One request through the whole middleware stack, in a process of its own. */
	private static function send(string $method, string $path, array $options = []): array
	{
		$spec = ['method' => $method, 'path' => $path];

		foreach (['headers', 'cookie', 'body', 'server', 'authority'] as $key)
		{
			if (isset($options[$key]))
			{
				$spec[$key] = $options[$key];
			}
		}

		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH
		]);

		foreach ($options['settings'] ?? [] as $name => $value)
		{
			$env['VICTUAL_' . $name] = $value;
		}

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/authstack-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);

		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");

		return $result;
	}

	/** The settings that put the reverse-proxy backend in charge, USE_ENV mode. */
	private static function proxySettings(array $extra = []): array
	{
		return array_merge([
			'AUTH_CLASS' => 'Victual\\Middleware\\Auth\\ReverseProxyAuthMiddleware',
			'REVERSE_PROXY_AUTH_USE_ENV' => 'true'
		], $extra);
	}

	/**
	 * The defect itself: the stored hash of an account the reverse proxy auto-provisions
	 * must not verify against the empty string it used to be hashed from, nor against any
	 * other fixed guess - it has to be genuinely random, the way PR #529's
	 * CreatedUserPassword() already is for POST /users under the same three
	 * no-local-password conditions.
	 */
	public function testAnAutoProvisionedAccountsPasswordHashVerifiesNothing(): void
	{
		$username = 'reverseproxy-newcomer-' . bin2hex(random_bytes(4));

		try
		{
			$response = self::send('GET', '/api/user', [
				'server' => ['REMOTE_USER' => $username],
				'settings' => self::proxySettings()
			]);

			self::assertSame(200, $response['status'], $response['body']);

			$statement = self::$db->prepare('SELECT password FROM users WHERE username = ?');
			$statement->execute([$username]);
			$hash = $statement->fetchColumn();

			self::assertNotFalse($hash, 'the account was created');
			self::assertIsString($hash);
			self::assertNotSame('', $hash, 'a real hash must have been stored, not an empty value');

			self::assertFalse(
				password_verify('', $hash),
				'the stored hash must not verify against the empty string it used to be hashed from'
			);
			self::assertFalse(
				password_verify('admin', $hash),
				'the stored hash must not verify against another guessable fixed value either'
			);
			self::assertFalse(
				password_verify('x', $hash),
				'the stored hash must not verify against the placeholder value issue #554 removed'
			);

			$info = password_get_info($hash);
			self::assertSame(PASSWORD_ARGON2ID, $info['algo'], 'hashed the same way every other account is (services/UsersService.php:111)');
		}
		finally
		{
			$statement = self::$db->prepare('DELETE FROM users WHERE username = ?');
			$statement->execute([$username]);
		}
	}
}
