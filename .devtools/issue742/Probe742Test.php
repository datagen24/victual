<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue 742 probe. Throwaway: prints a status table, asserts nothing about the permissions.
 * Users: child/adult hold the seeded CHILD/ADULT roles (migration 0266), editor holds
 * MASTER_DATA_EDIT directly and nothing else, editorconsumer adds STOCK_VIEW+STOCK_CONSUME,
 * admin holds ADMIN.
 */
class Probe742Test extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static array $keys = [];
	private static int $loc;
	private static int $tablet;
	private static int $bottle;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::$db = self::Pdo();

		$users = [
			'child' => ['roles' => ['CHILD']],
			'adult' => ['roles' => ['ADULT']],
			'editor' => ['perms' => ['MASTER_DATA_EDIT']],
			'editorconsumer' => ['perms' => ['MASTER_DATA_EDIT', 'STOCK_VIEW', 'STOCK_CONSUME']],
			'admin' => ['perms' => ['ADMIN']],
		];
		$next = 9701;
		foreach ($users as $name => $grant)
		{
			$id = $next++;
			self::$db->exec("INSERT INTO users (id, username, password) VALUES ($id, 'probe-$name', 'fixture')");
			foreach ($grant['perms'] ?? [] as $p)
			{
				self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?')->execute([$id, $p]);
			}
			foreach ($grant['roles'] ?? [] as $r)
			{
				self::$db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE code = ?')->execute([$id, $r]);
			}
			$plain = bin2hex(random_bytes(25));
			self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, 0)")
				->execute([ApiKeyService::HashKey($plain), substr($plain, -4), $id, ApiKeyService::API_KEY_TYPE_DEFAULT]);
			self::$keys[$name] = $plain;
		}
		self::$loc = (int)self::$db->query("INSERT INTO locations (name) VALUES ('probe cabinet') RETURNING id")->fetchColumn();
		self::$tablet = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('probe tablet') RETURNING id")->fetchColumn();
		self::$bottle = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('probe bottle') RETURNING id")->fetchColumn();
	}

	private static function send(string $method, string $path, string $as, ?array $body = null): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$keys[$as]]];
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
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);
		$start = strrpos($out, '{"status"');
		$r = $start === false ? null : json_decode(substr($out, $start), true);
		if (!is_array($r))
		{
			return ['status' => -1, 'body' => "NOJSON $out $err"];
		}
		return ['status' => $r['status'], 'body' => (string)$r['body']];
	}

	private function row(string $label, array $r): void
	{
		fwrite(STDERR, sprintf("PROBE %-58s %d %s\n", $label, $r['status'], substr(preg_replace('/\s+/', ' ', $r['body']), 0, 110)));
	}

	public function testProbe(): void
	{
		$who = ['child', 'adult', 'editor', 'editorconsumer', 'admin'];
		fwrite(STDERR, "PROBE-EFFECTIVE\n");
		foreach ($who as $u)
		{
			$names = self::$db->query("SELECT permission_name FROM user_permissions_resolved WHERE user_id = (SELECT id FROM users WHERE username = 'probe-$u') ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
			fwrite(STDERR, sprintf("PROBE-EFFECTIVE %-15s MDE=%s ADMIN=%s count=%d\n", $u, in_array('MASTER_DATA_EDIT', $names) ? 'y' : 'n', in_array('ADMIN', $names) ? 'y' : 'n', count($names)));
		}

		$n = 0;
		foreach ($who as $u)
		{
			$n++;
			$product = ['name' => "probe vitamin $u $n", 'location_id' => self::$loc, 'qu_id_stock' => self::$tablet, 'qu_id_purchase' => self::$tablet, 'qu_id_consume' => self::$tablet, 'qu_id_price' => self::$tablet];
			$this->row("$u POST objects/products", $r = self::send('POST', '/api/objects/products', $u, $product));
			$this->row("$u POST objects/locations", self::send('POST', '/api/objects/locations', $u, ['name' => "probe loc $u"]));
			$this->row("$u POST objects/quantity_units", self::send('POST', '/api/objects/quantity_units', $u, ['name' => "probe qu $u"]));
			$pid = (int)(json_decode($r['body'], true)['created_object_id'] ?? 0);
			if ($pid === 0)
			{
				$pid = (int)self::$db->query("SELECT id FROM products ORDER BY id LIMIT 1")->fetchColumn();
			}
			$this->row("$u POST objects/quantity_unit_conversions", self::send('POST', '/api/objects/quantity_unit_conversions', $u, ['product_id' => $pid, 'from_qu_id' => self::$bottle, 'to_qu_id' => self::$tablet, 'factor' => 30]));
			$this->row("$u POST objects/product_barcodes", self::send('POST', '/api/objects/product_barcodes', $u, ['product_id' => $pid, 'barcode' => "probe-bc-$u-$n"]));
			$this->row("$u PUT objects/products/{id} (edit)", self::send('PUT', "/api/objects/products/$pid", $u, ['name' => "probe edit $u $n"]));
			$this->row("$u DELETE objects/products/{id}", self::send('DELETE', "/api/objects/products/" . (int)self::$db->query("SELECT id FROM products WHERE name LIKE 'probe edit%' ORDER BY id DESC LIMIT 1")->fetchColumn(), $u));
			$this->row("$u GET external-lookup?add=true", self::send('GET', '/api/stock/barcodes/external-lookup/4006381333931?add=true', $u));
			$this->row("$u POST objects/tasks", self::send('POST', '/api/objects/tasks', $u, ['name' => "probe task $u"]));
			$this->row("$u POST objects/chores", self::send('POST', '/api/objects/chores', $u, ['name' => "probe chore $u", 'period_type' => 'manually']));
			$this->row("$u POST objects/batteries", self::send('POST', '/api/objects/batteries', $u, ['name' => "probe battery $u"]));
			$this->row("$u POST objects/shopping_locations", self::send('POST', '/api/objects/shopping_locations', $u, ['name' => "probe store $u"]));
			$this->row("$u POST objects/product_groups", self::send('POST', '/api/objects/product_groups', $u, ['name' => "probe group $u"]));
			$this->row("$u PUT userfields/products/{id}", self::send('PUT', "/api/userfields/products/$pid", $u, []));
		}

		// Mapping, step two. A product whose stock unit is tablets, and one that also has a bottle->tablet conversion.
		$plain = (int)self::$db->query("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES ('probe plain', " . self::$loc . ', ' . self::$tablet . ', ' . self::$tablet . ', ' . self::$tablet . ', ' . self::$tablet . ') RETURNING id')->fetchColumn();
		$withConv = (int)self::$db->query("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES ('probe conv', " . self::$loc . ', ' . self::$bottle . ', ' . self::$tablet . ', ' . self::$tablet . ', ' . self::$tablet . ') RETURNING id')->fetchColumn();
		fwrite(STDERR, 'PROBE-CONV rows for withConv before: ' . json_encode(self::$db->query("SELECT from_qu_id, to_qu_id, factor FROM quantity_unit_conversions WHERE product_id = $withConv")->fetchAll(PDO::FETCH_ASSOC)) . "\n");
		$map = fn(int $p, array $o = []) => $o + ['product_id' => $p, 'unit_labels' => ['tablet'], 'location' => ['mode' => 'fixed', 'location_id' => self::$loc], 'effective_from' => '2026-01-01T00:00:00Z'];
		foreach (['child', 'adult', 'editorconsumer', 'editor'] as $u)
		{
			$this->row("$u PUT mapping, product stock unit, no qu_id", self::send('PUT', "/api/consumption/mappings/healthkit/p-$u-1", $u, $map($plain)));
			$this->row("$u PUT mapping, qu_id = stock unit", self::send('PUT', "/api/consumption/mappings/healthkit/p-$u-2", $u, $map($plain, ['qu_id' => self::$tablet])));
			$this->row("$u PUT mapping, qu_id bottle, NO conversion", self::send('PUT', "/api/consumption/mappings/healthkit/p-$u-3", $u, $map($plain, ['qu_id' => self::$bottle])));
			$this->row("$u PUT mapping, qu_id bottle, purchase-unit row", self::send('PUT', "/api/consumption/mappings/healthkit/p-$u-4", $u, $map($withConv, ['qu_id' => self::$bottle])));
		}
	}
}
