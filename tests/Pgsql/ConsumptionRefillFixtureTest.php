<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Victual\Services\ApiKeyService;
use Victual\Services\ConsumptionRecipeService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Replays the JSON fixtures in tests/fixtures/consumption-refill/ through the whole middleware stack, one process per
 * request (tests/Pgsql/request-subprocess-helper.php), against a real PostgreSQL schema.
 *
 * These are server fixtures (ADR-0042, issue 701): they show what Victual answers to a sequence of requests, so the
 * victual-kit team can read and replay them. They do not show that a native client schedules a notification, which is
 * device evidence and belongs to issue 702. The format is described in tests/fixtures/consumption-refill/README.md.
 *
 * Every date a fixture states is a literal calendar date and every read sends its own as_of, so no fixture depends on
 * the day it runs. Each fixture gets a world of its own (users with API keys and one recipe), so two fixtures never meet.
 */
class ConsumptionRefillFixtureTest extends PgsqlSchemaTestCase
{
	private const DIRECTORY = __DIR__ . '/../fixtures/consumption-refill';

	private static PDO $db;
	private static int $unit;
	private static int $product;
	private static int $nextUser = 9701;
	private static int $nextName = 0;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$unit = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('refill fixture tablet') RETURNING id")->fetchColumn();
		$location = (int)self::$db->query("INSERT INTO locations (name) VALUES ('refill fixture organizer') RETURNING id")->fetchColumn();
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute(['refill fixture product', $location, self::$unit, self::$unit, self::$unit, self::$unit]);
		self::$product = (int)$statement->fetchColumn();
		StockService::GetInstance()->AddProduct(self::$product, 10, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, $location);
	}

	/** @return array<string, array{0: string}> */
	public static function fixtures(): array
	{
		$files = glob(self::DIRECTORY . '/*.json');
		sort($files);
		$cases = [];

		foreach ($files as $file)
		{
			$cases[basename($file)] = [$file];
		}

		return $cases;
	}

	public function testEveryFixtureIsListedInTheReadme(): void
	{
		$files = array_keys(self::fixtures());
		$readme = (string)file_get_contents(self::DIRECTORY . '/README.md');

		self::assertNotSame([], $files, 'no fixture files were found');
		foreach ($files as $file)
		{
			self::assertStringContainsString($file, $readme, "$file is not listed in README.md");
		}
	}

	#[DataProvider('fixtures')]
	public function testFixtureReplaysAsDocumented(string $path): void
	{
		$name = basename($path);
		$fixture = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
		foreach (['title', 'description', 'setup', 'steps'] as $required)
		{
			self::assertArrayHasKey($required, $fixture, "$name: missing $required");
		}

		$world = $this->createWorld($fixture['setup']);

		foreach ($fixture['steps'] as $index => $step)
		{
			$label = "$name step " . ($index + 1) . (isset($step['note']) ? ' (' . $step['note'] . ')' : '');
			$actor = $step['as'] ?? 'alice';
			self::assertArrayHasKey($actor, $world['users'], "$label: unknown actor $actor");

			$method = $step['request']['method'];
			$requestPath = $this->resolve($step['request']['path'], $world);
			$body = array_key_exists('body', $step['request']) ? $this->resolve($step['request']['body'], $world) : null;
			$response = self::send($method, $requestPath, $world['users'][$actor]['key'], $body);
			$where = "$label: $actor $method $requestPath" . ($body === null ? '' : ' ' . json_encode($body)) . ' -> ' . $response['status'] . ' ' . json_encode($response['body']);

			$expect = $step['expect'];
			self::assertSame($expect['status'], $response['status'], "$where\nexpected status " . $expect['status']);

			$errors = [];
			if (array_key_exists('body', $expect))
			{
				$this->matchSubset($this->resolve($expect['body'], $world, true), $response['body'], 'body', $errors);
			}
			self::assertSame([], $errors, "$where\n" . implode("\n", $errors));

			foreach ($step['save'] ?? [] as $saveAs => $dotted)
			{
				$value = self::dig($response['body'], $dotted, $present);
				self::assertTrue($present, "$where\ncannot save '$saveAs': no '$dotted' in the response");
				$world['saved'][$saveAs] = $value;
			}
		}
	}

	// --- The world a fixture runs in -----------------------------------------------------------

	/** @return array{users: array<string,array{id: int, username: string, key: string}>, recipes: array<string,int>, saved: array<string,mixed>} */
	private function createWorld(array $setup): array
	{
		$suffix = ++self::$nextName . '-' . bin2hex(random_bytes(2));
		$world = ['users' => [], 'recipes' => [], 'saved' => []];

		foreach ($setup['users'] ?? ['alice'] as $user)
		{
			$id = self::$nextUser++;
			$username = "rfx-$user-$suffix";
			self::$db->exec("INSERT INTO users (id, username, password) VALUES ($id, " . self::$db->quote($username) . ", 'fixture')");
			foreach ($user === 'alice' ? ['STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT'] : ['STOCK_VIEW'] as $permission)
			{
				self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?')->execute([$id, $permission]);
			}
			$plaintext = bin2hex(random_bytes(25));
			self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, 0)")
				->execute([ApiKeyService::HashKey($plaintext), substr($plaintext, -4), $id, ApiKeyService::API_KEY_TYPE_DEFAULT]);
			$world['users'][$user] = ['id' => $id, 'username' => $username, 'key' => $plaintext];
		}

		$recipes = ConsumptionRecipeService::GetInstance();
		foreach ($setup['recipes'] ?? [] as $recipe => $definition)
		{
			$owner = $world['users'][$definition['owner']]['id'];
			$id = $recipes->CreateRecipe("Refill fixture $recipe $suffix", null, [['product_id' => self::$product, 'amount' => 1, 'qu_id' => self::$unit]], $owner);
			foreach ($definition['shares'] ?? [] as $user => $rights)
			{
				$recipes->SetShare($id, $world['users'][$user]['id'], $rights, $owner);
			}
			$world['recipes'][$recipe] = $id;
		}

		return $world;
	}

	// --- Placeholders --------------------------------------------------------------------------

	/**
	 * Replaces placeholders in a decoded fixture value. A string that is exactly one placeholder becomes the
	 * placeholder's typed value (an integer id); inside a longer string it is written as text. `{{any}}` is left
	 * alone: matchSubset() reads it.
	 */
	private function resolve(mixed $value, array $world, bool $expectation = false): mixed
	{
		if (is_array($value))
		{
			return array_map(fn($item) => $this->resolve($item, $world, $expectation), $value);
		}

		if (!is_string($value) || !str_contains($value, '{{'))
		{
			return $value;
		}

		if ($value === '{{any}}')
		{
			self::assertTrue($expectation, '{{any}} is only meaningful in an expect body');

			return $value;
		}

		if (preg_match('/^\{\{([^{}]+)\}\}$/', $value, $whole) === 1)
		{
			return $this->placeholder($whole[1], $world);
		}

		return preg_replace_callback('/\{\{([^{}]+)\}\}/', fn(array $found) => (string)$this->placeholder($found[1], $world), $value);
	}

	private function placeholder(string $expression, array $world): mixed
	{
		if (preg_match('/^(recipe|user|saved)\.(.+)$/', $expression, $m) === 1)
		{
			[, $kind, $name] = $m;
			$source = match ($kind)
			{
				'recipe' => $world['recipes'],
				'saved' => $world['saved'],
				default => array_map(fn(array $user) => $user['id'], $world['users']),
			};
			self::assertArrayHasKey($name, $source, "placeholder {{{$expression}}} names nothing");

			return $source[$name];
		}

		self::fail("unknown placeholder {{{$expression}}}");
	}

	// --- Matching ------------------------------------------------------------------------------

	/**
	 * Subset match. Objects match on the keys the fixture lists; a list matches element by element for the elements
	 * the fixture lists, except that an empty list means "an empty list"; `{{any}}` matches any value that is there.
	 *
	 * @param string[] $errors
	 */
	private function matchSubset(mixed $expected, mixed $actual, string $path, array &$errors): void
	{
		if ($expected === '{{any}}')
		{
			return;
		}

		if (is_array($expected))
		{
			if (!is_array($actual))
			{
				$errors[] = "$path: expected " . json_encode($expected) . ', got ' . json_encode($actual);

				return;
			}

			if ($expected === [])
			{
				if ($actual !== [])
				{
					$errors[] = "$path: expected an empty list, got " . json_encode($actual);
				}

				return;
			}

			foreach ($expected as $key => $item)
			{
				if (!array_key_exists($key, $actual))
				{
					$errors[] = "$path.$key: missing from " . json_encode($actual);
					continue;
				}

				$this->matchSubset($item, $actual[$key], "$path.$key", $errors);
			}

			return;
		}

		$same = (is_int($expected) || is_float($expected)) && (is_int($actual) || is_float($actual)) ? abs($expected - $actual) < 0.000001 : $expected === $actual;
		if (!$same)
		{
			$errors[] = "$path: expected " . json_encode($expected) . ', got ' . json_encode($actual);
		}
	}

	/** The value at a dotted path ("lines.0.product_id"); "" is the whole response. */
	private static function dig(mixed $value, string $dotted, ?bool &$found = null): mixed
	{
		$found = true;
		foreach ($dotted === '' ? [] : explode('.', $dotted) as $segment)
		{
			if (!is_array($value) || !array_key_exists($segment, $value))
			{
				$found = false;

				return null;
			}

			$value = $value[$segment];
		}

		return $value;
	}

	// --- Requests ------------------------------------------------------------------------------

	/** @return array{status: int, body: mixed, headers: array} */
	private static function send(string $method, string $path, string $key, ?array $body = null): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => $key]];
		if ($body !== null)
		{
			$spec['body'] = $body;
		}

		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(), 'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'), 'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'), 'PGPORT' => getenv('PGPORT'), 'PGUSER' => getenv('PGUSER'), 'PGPASSWORD' => getenv('PGPASSWORD'), 'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);
		$process = proc_open([PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$start = strrpos($output, '{"status"');
		$response = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($response, "the request helper printed no JSON. stdout: $output stderr: $errors");
		$decoded = json_decode((string)$response['body'], true);
		$response['body'] = is_array($decoded) ? $decoded : $response['body'];

		return $response;
	}
}
