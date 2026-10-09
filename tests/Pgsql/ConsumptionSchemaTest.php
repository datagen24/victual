<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\GenericEntityApiController;
use Victual\Controllers\Users\EntityReadPolicy;
use Victual\Services\Database\DatabaseImporter;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Migration 0305 (ADR-0040 and ADR-0041, issue #698): the five private consumption tables.
 *
 * What this pins that the pgTAP file (031) cannot: that the tables stay out of every generic
 * surface (ADR-0040 rule 1), that the importer clears them, and that the migration reruns.
 * Rights, locking and consumption are covered by ConsumptionRecipe*Test.php.
 */
class ConsumptionSchemaTest extends PgsqlSchemaTestCase
{
	private const TABLES = ['consumption_recipes', 'consumption_recipe_lines', 'consumption_recipe_shares', 'consumption_events', 'consumption_event_lines'];

	private static PDO $db;
	private static GenericEntityApiController $generic;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		$container = new \DI\Container();
		$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$generic = new GenericEntityApiController($container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'consumption-schema-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");
	}

	public function testTheFiveTablesExist(): void
	{
		foreach (self::TABLES as $table)
		{
			self::assertSame($table, self::$db->query("SELECT to_regclass('$table')::text")->fetchColumn(), "$table exists");
		}
	}

	public function testNoTableIsAnExposedEntityOrAnyPathOfTheSpecification(): void
	{
		$spec = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true, 512, JSON_THROW_ON_ERROR);

		foreach (self::TABLES as $table)
		{
			self::assertNotContains($table, $spec['components']['schemas']['ExposedEntity']['enum'], "$table is not an ExposedEntity");
			self::assertFalse(EntityReadPolicy::Covers($table), "$table has no generic read policy");
		}
	}

	public function testEveryGenericReadRefusesEachTableForAnAdministrator(): void
	{
		foreach (self::TABLES as $table)
		{
			$request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api');
			$list = self::$generic->GetObjects($request, new Response(), ['entity' => $table]);
			self::assertSame(400, $list->getStatusCode(), "GET /api/objects/$table is refused");
			$one = self::$generic->GetObject($request, new Response(), ['entity' => $table, 'objectId' => '1']);
			self::assertSame(400, $one->getStatusCode(), "GET /api/objects/$table/1 is refused");
		}
	}

	public function testTheUserfieldRoutesRefuseEachTable(): void
	{
		foreach (self::TABLES as $table)
		{
			$get = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api');
			$read = self::$generic->GetUserfields($get, new Response(), ['entity' => $table, 'objectId' => '1']);
			self::assertSame(400, $read->getStatusCode(), "GET /api/userfields/$table/1 is refused");

			$put = (new ServerRequestFactory())->createServerRequest('PUT', 'http://localhost/api')
				->withParsedBody(['anything' => 'x'])->withHeader('Content-Type', 'application/json');
			$write = self::$generic->SetUserfields($put, new Response(), ['entity' => $table, 'objectId' => '1']);
			self::assertSame(400, $write->getStatusCode(), "PUT /api/userfields/$table/1 is refused");
		}
	}

	public function testTheImporterClearsAllFiveTables(): void
	{
		foreach (self::TABLES as $table)
		{
			self::assertContains($table, DatabaseImporter::DERIVED_STATE_TABLES, "$table is cleared by an import");
			self::assertNotContains($table, DatabaseImporter::NOT_COPIED_TABLES);
			self::assertNotContains($table, DatabaseImporter::TARGET_ONLY_TABLES);
		}
	}

	public function testTheMigrationRerunsWithoutChangingAnything(): void
	{
		$before = self::$db->query("SELECT count(*) FROM information_schema.columns WHERE table_schema = current_schema() AND table_name LIKE 'consumption\\_%'")->fetchColumn();
		self::$db->exec(file_get_contents(VICTUAL_ROOT_PATH . '/migrations/0305.pgsql.sql'));
		$after = self::$db->query("SELECT count(*) FROM information_schema.columns WHERE table_schema = current_schema() AND table_name LIKE 'consumption\\_%'")->fetchColumn();
		self::assertSame($before, $after);
		self::assertSame(2, (int)self::$db->query("SELECT count(*) FROM pg_trigger WHERE tgname IN ('consumption_share_not_owner', 'consumption_owner_not_sharee')")->fetchColumn(), 'both triggers exist exactly once');
	}

	public function testNoPermissionRowWasAdded(): void
	{
		self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM permission_hierarchy WHERE name ILIKE '%CONSUMPTION%'")->fetchColumn(),
			'ADR-0040 adds no permission constant');
	}
}
