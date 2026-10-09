<?php

namespace Victual\Tests\Pgsql;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Victual\Services\ApiKeyService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Replays the JSON fixtures in tests/fixtures/consumption-events/ through the whole middleware stack, one process
 * per request (tests/Pgsql/request-subprocess-helper.php), against a real PostgreSQL schema.
 *
 * These are server fixtures (ADR-0041, "Native acceptance test contract"): they show what Victual answers to a
 * sequence of requests. They do not show that Apple Health produces those requests; that is device evidence and
 * belongs to issue 702. The format and the placeholders are described in tests/fixtures/consumption-events/README.md.
 *
 * Each fixture gets a world of its own (locations, products, users with API keys), so a fixture may reuse the same
 * source_event_id as another one without the two meeting. The acting user is chosen per step by its API key.
 */
class ConsumptionEventFixtureTest extends PgsqlSchemaTestCase
{
	private const DIRECTORY = __DIR__ . '/../fixtures/consumption-events';

	private static PDO $db;
	private static int $unit;
	private static int $nextUser = 9501;
	private static int $nextName = 0;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users (id, username, password) VALUES (9000, 'phpunit-caller', 'fixture') ON CONFLICT DO NOTHING");
		self::$unit = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('fixture tablet') RETURNING id")->fetchColumn();
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
		foreach (['title', 'adr_sequence', 'description', 'setup', 'steps'] as $required)
		{
			self::assertArrayHasKey($required, $fixture, "$name: missing $required");
		}

		$world = $this->createWorld($name, $fixture['setup']);

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
			foreach ($expect['count'] ?? [] as $dotted => $count)
			{
				$found = self::dig($response['body'], $dotted);
				if (!is_array($found) || count($found) !== $count)
				{
					$errors[] = "count of '$dotted' is " . (is_array($found) ? count($found) : 'not a list') . ", expected $count";
				}
			}
			self::assertSame([], $errors, "$where\n" . implode("\n", $errors));

			foreach ($step['save'] ?? [] as $saveAs => $dotted)
			{
				$value = self::dig($response['body'], $dotted, $present);
				self::assertTrue($present, "$where\ncannot save '$saveAs': no '$dotted' in the response");
				$world['saved'][$saveAs] = $value;
			}

			foreach ($step['stock'] ?? [] as $key => $expected)
			{
				[$productName, $locationName] = array_pad(explode('@', $key, 2), 2, null);
				self::assertArrayHasKey($productName, $world['products'], "$label: unknown product $productName");
				$actual = self::onHand($world['products'][$productName], $locationName === null ? null : $world['locations'][$locationName]);
				self::assertEqualsWithDelta((float)$expected, $actual, 0.000001, "$where\non-hand stock of $key");
			}
		}
	}

	// --- The world a fixture runs in -----------------------------------------------------------

	/** @return array{now: DateTimeImmutable, locations: array<string,int>, products: array<string,int>, users: array<string,array{id: int, username: string, key: string}>, saved: array<string,mixed>} */
	private function createWorld(string $fixtureName, array $setup): array
	{
		$suffix = ++self::$nextName . '-' . bin2hex(random_bytes(2));
		$world = ['now' => new DateTimeImmutable('now', new DateTimeZone('UTC')), 'locations' => [], 'products' => [], 'users' => [], 'saved' => []];

		foreach ($setup['locations'] ?? [] as $location)
		{
			$world['locations'][$location] = (int)self::$db->query('INSERT INTO locations (name) VALUES (' . self::$db->quote("fx $location $suffix") . ') RETURNING id')->fetchColumn();
		}

		foreach ($setup['users'] ?? ['alice'] as $user)
		{
			$id = self::$nextUser++;
			$username = "fx-$user-$suffix";
			self::$db->exec("INSERT INTO users (id, username, password) VALUES ($id, " . self::$db->quote($username) . ", 'fixture')");
			foreach (['STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT', 'STOCK_PURCHASE'] as $permission)
			{
				self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?')->execute([$id, $permission]);
			}
			$plaintext = bin2hex(random_bytes(25));
			self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, 0)")
				->execute([ApiKeyService::HashKey($plaintext), substr($plaintext, -4), $id, ApiKeyService::API_KEY_TYPE_DEFAULT]);
			$world['users'][$user] = ['id' => $id, 'username' => $username, 'key' => $plaintext];
		}

		foreach ($setup['products'] ?? [] as $product => $definition)
		{
			$stock = $definition['stock'] ?? [];
			$home = $world['locations'][array_key_first($stock) ?? array_key_first($world['locations'])];
			$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
			$statement->execute(["fx $product $suffix", $home, self::$unit, self::$unit, self::$unit, self::$unit]);
			$id = (int)$statement->fetchColumn();
			$world['products'][$product] = $id;

			// A number is one purchase; a list is one purchase per element, in order (separate lots, ADR-0036).
			foreach ($stock as $location => $amounts)
			{
				foreach (is_array($amounts) ? $amounts : [$amounts] as $amount)
				{
					StockService::GetInstance()->AddProduct($id, (float)$amount, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, $world['locations'][$location]);
				}
			}
		}

		return $world;
	}

	private static function onHand(int $product, ?int $location = null): float
	{
		return (float)self::$db->query("SELECT COALESCE(sum(amount), 0) FROM stock WHERE product_id = $product" . ($location === null ? '' : " AND location_id = $location"))->fetchColumn();
	}

	// --- Placeholders --------------------------------------------------------------------------

	/**
	 * Replaces placeholders in a decoded fixture value. A string that is exactly one placeholder becomes the
	 * placeholder's typed value (an integer id, a list); inside a longer string it is written as text. `{{any}}`
	 * is left alone: matchSubset() reads it.
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
		if (preg_match('/^(product|location|user|username|saved)\.(.+)$/', $expression, $m) === 1)
		{
			[, $kind, $name] = $m;
			$source = match ($kind)
			{
				'product' => $world['products'],
				'location' => $world['locations'],
				'saved' => $world['saved'],
				default => array_map(fn(array $user) => $kind === 'user' ? $user['id'] : $user['username'], $world['users']),
			};
			self::assertArrayHasKey($name, $source, "placeholder {{{$expression}}} names nothing");

			return $source[$name];
		}

		if ($expression === 'unit')
		{
			return self::$unit;
		}

		if (preg_match('/^(time|date):([+-]\d+)([mhd])(?:@([+-]\d{2}:\d{2}))?$/', $expression, $m) === 1)
		{
			$seconds = (int)$m[2] * ['m' => 60, 'h' => 3600, 'd' => 86400][$m[3]];
			$instant = $world['now']->setTimestamp($world['now']->getTimestamp() + $seconds);
			if (isset($m[4]) && $m[4] !== '')
			{
				$instant = $instant->setTimezone(new DateTimeZone($m[4]));
			}

			if ($m[1] === 'date')
			{
				return $instant->format('Y-m-d');
			}

			return isset($m[4]) && $m[4] !== '' ? $instant->format('Y-m-d\TH:i:sP') : $instant->format('Y-m-d\TH:i:s\Z');
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
