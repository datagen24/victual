<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\FilesApiController;
use Victual\Controllers\Api\GenericEntityApiController;
use Victual\Services\Storage\DatabaseStorage;
use Victual\Services\Storage\FileStorage;
use Victual\Services\Storage\FilesystemStorage;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #905: a userfile is readable only by a caller every record that references it permits.
 *
 * Nothing links a userfile to its owner except the text of a userfield value, so the rule
 * is decided from the values that name the file: each (entity, object id) that references it
 * must admit the caller, a reference whose record is gone is ADMIN only, a file nothing
 * references is ADMIN only, and a reference on a record that is deliberately not readable
 * (api_keys) admits nobody. Authorization runs before the bytes are read and before a
 * picture derivative is created.
 *
 * Both storage backends, because the bytes and the derivative rows live in different media.
 * The helpers are copies of StorageFilesTest's; that class keeps them private.
 */
class UserfileOwnerReadTest extends PgsqlSchemaTestCase
{
	/** The caller every test acts as; PgsqlSchemaTestCase fixes VICTUAL_USER_ID at this. */
	private const CALLER_ID = 9000;
	private const OTHER_USER_ID = 9001;

	private static PDO $db;
	private static \DI\Container $container;
	private static FilesApiController $files;
	private static string $png;

	/** What every ownership refusal says: no owner type, no hint whether the file exists. */
	private const REFUSAL_BODY = '{"error_message":"Permission missing: USERFILE_OWNER_READ"}';

	private static int $productId;
	private static int $batteryId;
	private static int $equipmentId;
	private static int $recipeId;
	private static int $choreId;
	private static int $taskId;
	private static int $objectId;
	private static string $stockId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$files = new FilesApiController(self::$container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::CALLER_ID . ", 'ufo-caller', 'fixture'), (" . self::OTHER_USER_ID . ", 'ufo-other', 'fixture')");

		self::$productId = (int)self::$db->query("INSERT INTO products(name, location_id, qu_id_purchase, qu_id_stock) SELECT 'ufo-product', (SELECT MIN(id) FROM locations), MIN(id), MIN(id) FROM quantity_units RETURNING id")->fetchColumn();
		self::$batteryId = (int)self::$db->query("INSERT INTO batteries (name) VALUES ('ufo-battery') RETURNING id")->fetchColumn();
		self::$equipmentId = (int)self::$db->query("INSERT INTO equipment (name) VALUES ('ufo-equipment') RETURNING id")->fetchColumn();
		self::$recipeId = (int)self::$db->query("INSERT INTO recipes (name) VALUES ('ufo-recipe') RETURNING id")->fetchColumn();
		self::$choreId = (int)self::$db->query("INSERT INTO chores (name, period_type, period_interval, start_date, active, track_date_only) VALUES ('ufo-chore', 'daily', 1, '2028-03-01 09:00:00', 1, 0) RETURNING id")->fetchColumn();
		self::$taskId = (int)self::$db->query("INSERT INTO tasks (name, due_date, done) VALUES ('ufo-task', '2028-03-01', 0) RETURNING id")->fetchColumn();
		$entityId = (int)self::$db->query("INSERT INTO userentities(name, caption) VALUES ('ufo-garage', 'Garage') RETURNING id")->fetchColumn();
		self::$objectId = (int)self::$db->query("INSERT INTO userobjects(userentity_id) VALUES ($entityId) RETURNING id")->fetchColumn();
		self::$stockId = 'ufo-stock-' . uniqid();
		self::$db->prepare('INSERT INTO stock (product_id, location_id, best_before_date, amount, stock_id, open, purchased_date) VALUES (?, (SELECT MIN(id) FROM locations), ?, 1, ?, 0, CURRENT_DATE)')
			->execute([self::$productId, '2999-12-31', self::$stockId]);

		self::$png = self::MakePng(120, 80);
	}

	/** Whether the next fixtureFile() call starts from an empty userfiles group. */
	private static bool $startFresh = true;

	protected function setUp(): void
	{
		self::$startFresh = true;
		self::$db->exec('DELETE FROM userfield_values');
	}

	public static function backends(): array
	{
		return [
			'filesystem backend' => ['filesystem'],
			'database backend' => ['database']
		];
	}

	// -------------------------------------------------------------------------------
	// One owner
	// -------------------------------------------------------------------------------

	#[DataProvider('backends')]
	public function testAProductFileFollowsStockView(string $backend): void
	{
		$token = $this->fixtureFile($backend, 'product-manual.txt', 'product manual bytes');
		self::reference('products', (string)self::$productId, 'product-manual.txt');

		self::grant([]);
		$this->assertRefused(self::serve($token), 'a caller holding nothing');

		self::grant(['STOCK_VIEW']);
		$served = self::serve($token);
		self::assertSame(200, $served['status']);
		self::assertSame('product manual bytes', $served['body'], 'and is handed the real bytes');
	}

	#[DataProvider('backends')]
	public function testEachOwnerTypeGrantsItsOwnReadLeafAndNoOther(string $backend): void
	{
		$cases = [
			'equipment' => ['equipment', (string)self::$equipmentId, 'EQUIPMENT_VIEW'],
			'batteries' => ['batteries', (string)self::$batteryId, 'BATTERIES_VIEW'],
			'recipes' => ['recipes', (string)self::$recipeId, 'RECIPES_VIEW'],
			'chores' => ['chores', (string)self::$choreId, 'CHORES_VIEW'],
			'tasks' => ['tasks', (string)self::$taskId, 'TASKS_VIEW'],
			'custom entity' => ['userentity-ufo-garage', (string)self::$objectId, 'MASTER_DATA_EDIT'],
			'stock (keyed by stock_id)' => ['stock', self::$stockId, 'STOCK_VIEW']
		];

		foreach ($cases as $label => [$entity, $objectId, $leaf])
		{
			self::$db->exec('DELETE FROM userfield_values');
			$name = 'owner-' . preg_replace('/[^a-z]/', '', $label) . '.txt';
			$token = $this->fixtureFile($backend, $name, "bytes of $label");
			self::reference($entity, $objectId, $name);

			$otherLeaf = $leaf === 'STOCK_VIEW' ? 'RECIPES_VIEW' : 'STOCK_VIEW';

			self::grant([]);
			$this->assertRefused(self::serve($token), "$label: holding nothing");
			self::grant([$otherLeaf]);
			$this->assertRefused(self::serve($token), "$label: holding $otherLeaf instead of $leaf");
			self::grant([$leaf]);
			$served = self::serve($token);
			self::assertSame(200, $served['status'], "$label: holding $leaf");
			self::assertSame("bytes of $label", $served['body'], "$label: the real bytes");
		}
	}

	#[DataProvider('backends')]
	public function testAConsumedStockRowLeavesItsFileToAdmin(string $backend): void
	{
		$stockId = 'ufo-consumed-' . uniqid();
		self::$db->prepare('INSERT INTO stock (product_id, location_id, best_before_date, amount, stock_id, open, purchased_date) VALUES (?, (SELECT MIN(id) FROM locations), ?, 1, ?, 0, CURRENT_DATE)')
			->execute([self::$productId, '2999-12-31', $stockId]);
		$token = $this->fixtureFile($backend, 'consumed.txt', 'consumed');
		self::reference('stock', $stockId, 'consumed.txt');

		self::grant(['STOCK_VIEW']);
		self::assertSame(200, self::serve($token)['status'], 'while the stock row exists');

		self::$db->prepare('DELETE FROM stock WHERE stock_id = ?')->execute([$stockId]);

		$this->assertRefused(self::serve($token), 'once the row is consumed the reference is unresolved');
		self::grant(['ADMIN']);
		self::assertSame(200, self::serve($token)['status'], 'and ADMIN still reads it');
	}

	#[DataProvider('backends')]
	public function testAUserFileIsReadableByThatUserAndByUsersRead(string $backend): void
	{
		$own = $this->fixtureFile($backend, 'own.txt', 'own');
		$theirs = $this->fixtureFile($backend, 'theirs.txt', 'theirs');
		self::reference('users', (string)self::CALLER_ID, 'own.txt');
		self::reference('users', (string)self::OTHER_USER_ID, 'theirs.txt');

		self::grant([]);
		self::assertSame(200, self::serve($own)['status'], 'the user reads a file on their own user row with no permission');
		$this->assertRefused(self::serve($theirs), 'another user\'s file, without USERS_READ');

		self::grant(['USERS_READ']);
		self::assertSame(200, self::serve($theirs)['status'], 'USERS_READ reads it');
		self::assertSame(200, self::serve($own)['status']);
	}

	#[DataProvider('backends')]
	public function testSharingAFileWithAnotherUserStillNeedsEveryOwner(string $backend): void
	{
		$token = $this->fixtureFile($backend, 'shared-user.txt', 'shared');
		self::reference('users', (string)self::CALLER_ID, 'shared-user.txt');
		self::reference('batteries', (string)self::$batteryId, 'shared-user.txt');

		self::grant([]);
		$this->assertRefused(self::serve($token), 'the self exception does not override a second owner');
		self::grant(['BATTERIES_VIEW']);
		self::assertSame(200, self::serve($token)['status']);
	}

	// -------------------------------------------------------------------------------
	// Several owners, orphans, owners nobody may read
	// -------------------------------------------------------------------------------

	#[DataProvider('backends')]
	public function testEveryReferencingOwnerMustAdmitTheCaller(string $backend): void
	{
		$token = $this->fixtureFile($backend, 'two-owners.txt', 'two');
		self::reference('products', (string)self::$productId, 'two-owners.txt');
		// A field retyped after its values were saved still counts: the second reference is
		// on a text field, and counting it can only narrow who may read.
		self::reference('batteries', (string)self::$batteryId, 'two-owners.txt', 'text-single-line');

		self::grant(['STOCK_VIEW']);
		$this->assertRefused(self::serve($token), 'STOCK_VIEW alone');
		self::grant(['BATTERIES_VIEW']);
		$this->assertRefused(self::serve($token), 'BATTERIES_VIEW alone');
		self::grant(['STOCK_VIEW', 'BATTERIES_VIEW']);
		self::assertSame(200, self::serve($token)['status'], 'both leaves');
	}

	#[DataProvider('backends')]
	public function testTheSameOwnerReferencingTwiceCountsOnce(string $backend): void
	{
		$token = $this->fixtureFile($backend, 'dup.txt', 'dup');
		self::reference('products', (string)self::$productId, 'dup.txt', 'file', 'first_field');
		self::reference('products', (string)self::$productId, 'dup.txt', 'image', 'second_field');

		self::grant(['STOCK_VIEW']);
		self::assertSame(200, self::serve($token)['status']);
	}

	#[DataProvider('backends')]
	public function testAReferenceWhoseOwnerIsGoneIsAdminOnly(string $backend): void
	{
		$cases = [
			'an id that is not a row' => ['products', '999999'],
			'a non-canonical id' => ['products', '0' . self::$productId],
			'a non-numeric id' => ['products', 'abc'],
			'a custom entity row that is not there' => ['userentity-ufo-garage', '999999'],
			'a user that is not there' => ['users', '999999']
		];

		foreach ($cases as $label => [$entity, $objectId])
		{
			self::$db->exec('DELETE FROM userfield_values');
			$token = $this->fixtureFile($backend, 'gone.txt', 'gone');
			self::reference($entity, $objectId, 'gone.txt');

			self::grant(['STOCK_VIEW', 'MASTER_DATA_EDIT', 'USERS_READ']);
			$this->assertRefused(self::serve($token), $label);
			self::grant(['ADMIN']);
			self::assertSame(200, self::serve($token)['status'], "$label: ADMIN");
		}
	}

	#[DataProvider('backends')]
	public function testAnUnreferencedFileIsAdminOnlyEvenForACallerHoldingEveryOtherLeaf(string $backend): void
	{
		$token = $this->fixtureFile($backend, 'orphan.txt', 'orphan');

		self::grant(self::everyLeafButAdmin());
		$this->assertRefused(self::serve($token), 'every leaf but ADMIN');
		self::grant(['ADMIN']);
		self::assertSame(200, self::serve($token)['status']);

		$missing = base64_encode('never-stored.txt');
		self::grant(self::everyLeafButAdmin());
		$this->assertRefused(self::serve($missing), 'a name nothing stores and nothing references');
		self::grant(['ADMIN']);
		self::assertSame(404, self::serve($missing)['status'], 'is a 404 only for ADMIN');
	}

	#[DataProvider('backends')]
	public function testAPermittedCallerGetsA404ForAMissingFile(string $backend): void
	{
		self::storage($backend);
		self::reference('products', (string)self::$productId, 'referenced-but-missing.txt');

		self::grant(['STOCK_VIEW']);
		self::assertSame(404, self::serve(base64_encode('referenced-but-missing.txt'))['status']);
	}

	#[DataProvider('backends')]
	public function testDefinitionRowsAreAdminOnlyAndApiKeysAreReadByNobody(string $backend): void
	{
		self::reference('products', (string)self::$productId, 'seed.txt');
		$fieldId = (int)self::$db->query("SELECT MIN(id) FROM userfields")->fetchColumn();
		$entityId = (int)self::$db->query("SELECT MIN(id) FROM userentities")->fetchColumn();
		$keyId = (int)self::$db->query("INSERT INTO api_keys (api_key, user_id, key_type) VALUES ('ufo-key-" . uniqid() . "', " . self::CALLER_ID . ", 'default') RETURNING id")->fetchColumn();

		$cases = [
			['userfields', (string)$fieldId, 'admin'],
			['userentities', (string)$entityId, 'admin'],
			['api_keys', (string)$keyId, 'nobody'],
			['api_keys', '999999', 'nobody'],
			// A view EntityReadPolicy covers but this class cannot look a record up in: unresolved
			['stock_current_locations', '1', 'admin'],
			['no_such_entity', '1', 'nobody']
		];

		foreach ($cases as [$entity, $objectId, $who])
		{
			self::$db->exec('DELETE FROM userfield_values');
			$token = $this->fixtureFile($backend, 'definition.txt', 'definition');
			self::reference($entity, $objectId, 'definition.txt');

			self::grant(self::everyLeafButAdmin());
			$this->assertRefused(self::serve($token), "$entity: a caller holding every leaf but ADMIN");
			self::grant(['ADMIN']);
			self::assertSame($who === 'admin' ? 200 : 403, self::serve($token)['status'], "$entity: ADMIN");
		}
	}

	// -------------------------------------------------------------------------------
	// Derivatives
	// -------------------------------------------------------------------------------

	#[DataProvider('backends')]
	public function testARefusedCallerCreatesNoDerivative(string $backend): void
	{
		$token = $this->fixtureFile($backend, 'photo.png', self::$png);
		self::reference('products', (string)self::$productId, 'photo.png');
		$query = ['force_serve_as' => 'picture', 'best_fit_width' => '32'];

		self::grant([]);
		$this->assertRefused(self::serve($token, $query), 'a refused picture request');
		self::assertSame(['photo.png'], self::durableNames($backend), 'nothing but the original is stored');
		self::assertSame(0, self::derivativeRows(), 'and no derivative row exists');

		self::grant(['STOCK_VIEW']);
		self::assertSame(200, self::serve($token, $query)['status'], 'the permitted caller is served');
		self::assertCount(2, self::durableNames($backend), 'and that is what creates the derivative');
	}

	#[DataProvider('backends')]
	public function testADerivativeNameAskedForDirectlyIsAdminOnly(string $backend): void
	{
		$token = $this->fixtureFile($backend, 'photo.png', self::$png);
		self::reference('products', (string)self::$productId, 'photo.png');
		self::grant(['STOCK_VIEW']);
		self::serve($token, ['force_serve_as' => 'picture', 'best_fit_width' => '32']);

		$derivatives = array_values(array_filter(self::durableNames($backend), fn ($name) => str_contains($name, '__downscaledto')));
		self::assertCount(1, $derivatives);
		$derivativeToken = base64_encode($derivatives[0]);

		$this->assertRefused(self::serve($derivativeToken), 'the derivative by its own name, for a caller who may read the original');
		self::grant(['ADMIN']);
		self::assertSame(200, self::serve($derivativeToken)['status']);
	}

	// -------------------------------------------------------------------------------
	// Reference forms
	// -------------------------------------------------------------------------------

	#[DataProvider('backends')]
	public function testEveryEncodingTheRequestSideAcceptsIsRecognisedAsAReference(string $backend): void
	{
		// 'a.txt' is five bytes, so its encoding is padded and its last character carries
		// two bits that decoding drops.
		$name = 'a.txt';
		$canonical = base64_encode($name);
		self::assertSame('YS50eHQ=', $canonical);

		$forms = [
			'canonical' => $canonical,
			'with the display name, as the browser writes it' => $canonical . '_' . base64_encode('My File.txt'),
			'without padding' => rtrim($canonical, '='),
			'without padding, with a display name' => rtrim($canonical, '=') . '_' . base64_encode('x.txt'),
			'a last character differing only in dropped bits' => 'YS50eHR=',
			'with a character outside the alphabet' => 'YS50!eHQ=',
		];

		foreach ($forms as $label => $value)
		{
			self::$db->exec('DELETE FROM userfield_values');
			$this->fixtureFile($backend, $name, 'form');
			self::reference('products', (string)self::$productId, null, 'file', 'form_field', $value);
			self::reference('batteries', (string)self::$batteryId, null, 'file', 'form_field', $canonical);

			self::grant(['STOCK_VIEW']);
			// The battery reference is in the canonical form; the product's is the variant. If the
			// variant were not recognised the file would have one owner and STOCK_VIEW alone
			// would be refused for the wrong reason, so the assertions are on the battery
			// leaf: the variant must narrow the read down to both.
			$this->assertRefused(self::serve($canonical), "$label: STOCK_VIEW without BATTERIES_VIEW");
			self::grant(['BATTERIES_VIEW']);
			$this->assertRefused(self::serve($canonical), "$label: BATTERIES_VIEW without STOCK_VIEW");
			self::grant(['STOCK_VIEW', 'BATTERIES_VIEW']);
			self::assertSame(200, self::serve($canonical)['status'], "$label: both");
		}

		// The request side accepts the same variants.
		self::$db->exec('DELETE FROM userfield_values');
		self::reference('products', (string)self::$productId, null, 'file', 'form_field', $canonical);
		self::grant(['STOCK_VIEW']);
		foreach (['without padding' => rtrim($canonical, '='), 'with a display name' => $canonical . '_' . base64_encode('shown.txt'), 'dropped bits' => 'YS50eHR='] as $label => $requested)
		{
			self::assertSame(200, self::serve($requested)['status'], "requested $label");
		}
	}

	// -------------------------------------------------------------------------------
	// Refusals are indistinguishable
	// -------------------------------------------------------------------------------

	#[DataProvider('backends')]
	public function testEveryOwnershipRefusalLooksTheSame(string $backend): void
	{
		$this->fixtureFile($backend, 'by-product.txt', 'p');
		$this->fixtureFile($backend, 'by-battery.txt', 'b');
		$this->fixtureFile($backend, 'orphan.txt', 'o');
		self::reference('products', (string)self::$productId, 'by-product.txt');
		self::reference('batteries', (string)self::$batteryId, 'by-battery.txt');
		self::reference('api_keys', '1', 'by-api-key.txt');

		self::grant(['RECIPES_VIEW']);
		$seen = [];
		foreach (['by-product.txt', 'by-battery.txt', 'orphan.txt', 'by-api-key.txt', 'never-stored.txt'] as $name)
		{
			$seen[$name] = self::serve(base64_encode($name));
		}

		foreach ($seen as $name => $result)
		{
			self::assertSame(403, $result['status'], $name);
			self::assertSame(self::REFUSAL_BODY, $result['body'], "$name: the same body, which names no owner and no file");
		}
	}

	// -------------------------------------------------------------------------------
	// F5: what the unchanged write paths can do to the read rule
	// -------------------------------------------------------------------------------

	#[DataProvider('backends')]
	public function testAttachingABatteryFileToAProductDoesNotWidenTheRead(string $backend): void
	{
		$token = $this->fixtureFile($backend, 'battery-doc.txt', 'battery doc');
		self::reference('batteries', (string)self::$batteryId, 'battery-doc.txt');
		self::$db->exec("INSERT INTO userfields(entity, name, caption, type) VALUES ('products', 'attach_field', 'Attach', 'file') ON CONFLICT DO NOTHING");

		self::grant(['MASTER_DATA_EDIT', 'STOCK_VIEW']);
		$request = (new ServerRequestFactory())->createServerRequest('PUT', 'http://localhost/api')
			->withParsedBody(['attach_field' => $token])->withHeader('Content-Type', 'application/json');
		$api = new GenericEntityApiController(self::$container);
		self::assertSame(204, $api->SetUserfields($request, new Response(), ['entity' => 'products', 'objectId' => (string)self::$productId])->getStatusCode(), 'the write path accepts the attachment');

		$this->assertRefused(self::serve($token), 'the attacher still cannot read it: the battery reference remains');
	}

	/**
	 * RESIDUAL FINDING (F5), asserting today's behaviour rather than the behaviour we want:
	 * SetUserfields checks only MASTER_DATA_EDIT, not the read permission of the record it
	 * edits, so a caller who can read the file through a second owner can remove the
	 * protected owner's reference and have the file widen to the second owner. Deleting
	 * the owning record has the same effect (values cascade for products, batteries, chores).
	 * Fixing it is owner-based write authorization, filed separately.
	 */
	#[DataProvider('backends')]
	public function testResidualF5DetachingTheProtectedOwnerWidensTheRead(string $backend): void
	{
		$token = $this->fixtureFile($backend, 'detach.txt', 'detach');
		self::reference('batteries', (string)self::$batteryId, 'detach.txt', 'file', 'battery_doc');
		self::reference('products', (string)self::$productId, 'detach.txt');

		self::grant(['MASTER_DATA_EDIT', 'STOCK_VIEW']);
		$this->assertRefused(self::serve($token), 'before detaching');

		$request = (new ServerRequestFactory())->createServerRequest('PUT', 'http://localhost/api')
			->withParsedBody(['battery_doc' => ''])->withHeader('Content-Type', 'application/json');
		$api = new GenericEntityApiController(self::$container);
		self::assertSame(204, $api->SetUserfields($request, new Response(), ['entity' => 'batteries', 'objectId' => (string)self::$batteryId])->getStatusCode());

		self::assertSame(200, self::serve($token)['status'], 'CURRENT BEHAVIOUR: after the battery reference is emptied the product alone decides');
	}

	// -------------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------------

	/** Stores $bytes under $name in userfiles and returns the request token for it. */
	private function fixtureFile(string $backend, string $name, string $bytes): string
	{
		if (self::$startFresh)
		{
			self::storage($backend);
			self::$startFresh = false;
		}
		FileStorage::GetInstance()->Write('userfiles', $name, $bytes);

		return base64_encode($name);
	}

	private static function storage(string $backend): FileStorage
	{
		$instance = $backend === 'database' ? new DatabaseStorage() : new FilesystemStorage();
		(new ReflectionProperty(FileStorage::class, 'Instance'))->setValue(null, $instance);

		foreach (self::durableNames($backend) as $name)
		{
			$instance->Delete('userfiles', $name);
		}

		return $instance;
	}

	private static function durableNames(string $backend): array
	{
		if ($backend === 'database')
		{
			$statement = self::$db->prepare("SELECT name FROM files WHERE file_group = 'userfiles' ORDER BY name");
			$statement->execute();

			return $statement->fetchAll(PDO::FETCH_COLUMN);
		}

		$folder = VICTUAL_DATAPATH . '/storage/userfiles';
		if (!is_dir($folder))
		{
			return [];
		}

		$names = array_values(array_diff(scandir($folder), ['.', '..']));
		sort($names);

		return $names;
	}

	private static function derivativeRows(): int
	{
		return (int)self::$db->query("SELECT COUNT(*) FROM files WHERE file_group = 'userfiles' AND name LIKE '%\\_\\_downscaledto%'")->fetchColumn();
	}

	/**
	 * Records that a record references a file. $value overrides the default
	 * "base64(name)_base64(display)" form the browser writes.
	 */
	private static function reference(string $entity, string $objectId, ?string $name, string $type = 'file', ?string $fieldName = null, ?string $value = null): void
	{
		$fieldName ??= 'ufo_file';
		$statement = self::$db->prepare('INSERT INTO userfields(entity, name, caption, type) VALUES (?, ?, ?, ?) ON CONFLICT (entity, name) DO UPDATE SET type = EXCLUDED.type RETURNING id');
		$statement->execute([$entity, $fieldName, $fieldName, $type]);
		$fieldId = (int)$statement->fetchColumn();

		$value ??= base64_encode($name) . '_' . base64_encode('Display ' . $name);
		if ($entity === 'stock')
		{
			// A stock value is written under the booking's transaction id and a trigger re-keys
			// it to the stock id; written under the stock id directly it would be deleted again.
			$transactionId = 'ufo-tx-' . uniqid();
			self::$db->prepare("INSERT INTO stock_log (product_id, amount, stock_id, transaction_type, user_id, transaction_id) VALUES (?, 1, ?, 'purchase', ?, ?)")
				->execute([self::$productId, $objectId, self::CALLER_ID, $transactionId]);
			$objectId = $transactionId;
		}
		self::$db->prepare('INSERT INTO userfield_values(field_id, object_id, value) VALUES (?, ?, ?)')->execute([$fieldId, $objectId, $value]);
	}

	/** @return array{status: int, body: string} */
	private static function serve(string $token, array $query = []): array
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api')->withQueryParams($query);

		try
		{
			$response = self::$files->ServeFile($request, new Response(), ['group' => 'userfiles', 'fileName' => $token]);

			return ['status' => $response->getStatusCode(), 'body' => (string)$response->getBody()];
		}
		catch (HttpException $ex)
		{
			return ['status' => $ex->getCode(), 'body' => $ex->getMessage()];
		}
	}

	private function assertRefused(array $result, string $message): void
	{
		self::assertSame(403, $result['status'], "$message: expected a refusal");
		self::assertSame(self::REFUSAL_BODY, $result['body'], "$message: and the one fixed body, no bytes");
	}

	/** @return string[] */
	private static function everyLeafButAdmin(): array
	{
		return self::$db->query("SELECT name FROM permission_hierarchy WHERE name <> 'ADMIN'")->fetchAll(PDO::FETCH_COLUMN);
	}

	private static function grant(array $names): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = ' . self::CALLER_ID . '; DELETE FROM user_roles WHERE user_id = ' . self::CALLER_ID);
		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::CALLER_ID . ', id FROM permission_hierarchy WHERE name = ?');
		foreach ($names as $name)
		{
			$statement->execute([$name]);
		}
	}

	private static function MakePng(int $width, int $height): string
	{
		$image = imagecreatetruecolor($width, $height);
		imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 10, 120, 200));
		imagefilledrectangle($image, 0, 0, max(1, intdiv($width, 2)), max(1, intdiv($height, 2)), imagecolorallocate($image, 230, 40, 40));

		ob_start();
		imagepng($image);

		return (string)ob_get_clean();
	}
}
