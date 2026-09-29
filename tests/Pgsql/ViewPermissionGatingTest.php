<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\BatteriesApiController;
use Victual\Controllers\Api\CalendarApiController;
use Victual\Controllers\BatteriesController;
use Victual\Controllers\CalendarController;
use Victual\Controllers\EquipmentController;
use Victual\Controllers\GenericEntityController;
use Victual\Controllers\Users\User;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #521 (M21, part of #487): six controllers served GET routes with no permission
 * check at all - BatteriesController's seven page routes, BatteriesApiController's
 * Current/BatteryDetails, CalendarController::Overview, CalendarApiController's
 * Ical/IcalSharingLink, GenericEntityController's userentities/userfields/userobjects
 * pages, and EquipmentController's Overview/EditForm. Migration 0299 adds three new
 * permission leaves (BATTERIES_VIEW, CALENDAR_VIEW, EQUIPMENT_VIEW), nested under their
 * parent exactly as STOCK_PRICES_VIEW nests under STOCK_PURCHASE (migrations/0281),
 * and the controllers now check them (or, for the two GenericEntityController entities
 * that already had a write policy, the write policy's own permission(s) - see
 * GenericEntityController::CheckViewPermission()'s docblock).
 *
 * IMPORTANT - conflicts with an Accepted ADR: docs/adr/0018-role-grants-and-domain-reads.md
 * (Accepted 2026-09-14) and docs/plans/19-rbac.md's Executed section record, in so many
 * words, that "Batteries, equipment and custom entities retain their previous read
 * policy; this wave does not add view leaves for them" - a deliberate decision, not an
 * oversight, and tests/Pgsql/HouseholdPagesTest.php pins it with three tests named
 * "...FollowsTheRecordedReadPolicyOfNoViewLeaf" plus an assertion inside
 * testCalendarEventListShrinksWithTheCallersViewLeaves() ("batteries carry no view leaf,
 * so they stay - the recorded policy in plan 19"). This migration and these gates
 * directly reverse that recorded decision for BatteriesController, EquipmentController,
 * GenericEntityController and CalendarController, on this task's maintainer instruction
 * (issue #521). Per FIXER_RULES ("if an existing test appears to encode the defective
 * behaviour, do not edit it: report it"), tests/Pgsql/HouseholdPagesTest.php is left
 * unedited here - its four pinned assertions will fail once this branch is merged, and
 * that conflict is reported to the master/validator rather than resolved unilaterally by
 * either weakening this test or silently amending an Accepted ADR. See this PR's body.
 */
class ViewPermissionGatingTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static \DI\Container $container;
	private static BatteriesController $batteries;
	private static BatteriesApiController $batteriesApi;
	private static CalendarController $calendar;
	private static CalendarApiController $calendarApi;
	private static EquipmentController $equipment;
	private static GenericEntityController $generic;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		$_SERVER['HTTP_HOST'] ??= 'localhost';

		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));

		self::$batteries = new BatteriesController(self::$container);
		self::$batteriesApi = new BatteriesApiController(self::$container);
		self::$calendar = new CalendarController(self::$container);
		self::$calendarApi = new CalendarApiController(self::$container);
		self::$equipment = new EquipmentController(self::$container);
		self::$generic = new GenericEntityController(self::$container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'view-gating-caller', 'fixture')");

		self::$db->exec("INSERT INTO batteries (id, name, active) VALUES (501, 'Gating battery', 1)");
		self::$db->exec("INSERT INTO equipment (id, name) VALUES (501, 'Gating equipment')");
		self::$db->exec("INSERT INTO userentities (id, name, caption) VALUES (501, 'gatingentity', 'Gating entity')");
		self::$db->exec("INSERT INTO userfields (id, entity, name, caption, type) VALUES (501, 'batteries', 'gatingfield', 'Gating field', 'text-single-line')");
	}

	private static function request(string $method = 'GET', array $query = [])
	{
		$request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/');
		return $query === [] ? $request : $request->withQueryParams($query);
	}

	private function expectStatus(callable $work, int $expected, string $message)
	{
		try
		{
			$response = $work();
			$actual = $response->getStatusCode();
		}
		catch (HttpException $e)
		{
			$actual = $e->getCode();
			$response = null;
		}
		self::assertSame($expected, $actual, "$message: expected $expected, got $actual " . ($response === null ? '' : (string)$response->getBody()));
		return $response;
	}

	private static function grant(array $names): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');
		$stmt = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = ?');
		foreach ($names as $name)
		{
			$stmt->execute([$name]);
		}
	}

	public function testBatteriesPageRoutesRefuseWithoutGrantAndSucceedWithBatteriesView(): void
	{
		self::grant([]);

		$this->expectStatus(fn () => self::$batteries->BatteriesList(self::request(), new Response(), []), 403, 'BatteriesList refuses no grants');
		$this->expectStatus(fn () => self::$batteries->BatteriesSettings(self::request(), new Response(), []), 403, 'BatteriesSettings refuses no grants');
		$this->expectStatus(fn () => self::$batteries->BatteryEditForm(self::request(), new Response(), ['batteryId' => 'new']), 403, 'BatteryEditForm refuses no grants');
		$this->expectStatus(fn () => self::$batteries->Journal(self::request(), new Response(), []), 403, 'Journal refuses no grants');
		$this->expectStatus(fn () => self::$batteries->Overview(self::request(), new Response(), []), 403, 'Overview refuses no grants');
		$this->expectStatus(fn () => self::$batteries->TrackChargeCycle(self::request(), new Response(), []), 403, 'TrackChargeCycle refuses no grants');
		$this->expectStatus(fn () => self::$batteries->BatteryGrocycodeImage(self::request(), new Response(), ['batteryId' => 501]), 403, 'BatteryGrocycodeImage refuses no grants');

		self::grant(['BATTERIES_VIEW']);

		$this->expectStatus(fn () => self::$batteries->BatteriesList(self::request(), new Response(), []), 200, 'BatteriesList allowed with BATTERIES_VIEW');
		$this->expectStatus(fn () => self::$batteries->BatteriesSettings(self::request(), new Response(), []), 200, 'BatteriesSettings allowed with BATTERIES_VIEW');
		$this->expectStatus(fn () => self::$batteries->BatteryEditForm(self::request(), new Response(), ['batteryId' => 'new']), 200, 'BatteryEditForm allowed with BATTERIES_VIEW');
		$this->expectStatus(fn () => self::$batteries->Journal(self::request(), new Response(), []), 200, 'Journal allowed with BATTERIES_VIEW');
		$this->expectStatus(fn () => self::$batteries->Overview(self::request(), new Response(), []), 200, 'Overview allowed with BATTERIES_VIEW');
		$this->expectStatus(fn () => self::$batteries->TrackChargeCycle(self::request(), new Response(), []), 200, 'TrackChargeCycle allowed with BATTERIES_VIEW');
		$this->expectStatus(fn () => self::$batteries->BatteryGrocycodeImage(self::request(), new Response(), ['batteryId' => 501]), 200, 'BatteryGrocycodeImage allowed with BATTERIES_VIEW');
	}

	public function testBatteriesApiRefusesWithoutGrantAndSucceedsWithBatteriesView(): void
	{
		self::grant([]);

		$this->expectStatus(fn () => self::$batteriesApi->Current(self::request(), new Response(), []), 403, 'Current refuses no grants');
		$this->expectStatus(fn () => self::$batteriesApi->BatteryDetails(self::request(), new Response(), ['batteryId' => 501]), 403, 'BatteryDetails refuses no grants');

		self::grant(['BATTERIES_VIEW']);

		$this->expectStatus(fn () => self::$batteriesApi->Current(self::request(), new Response(), []), 200, 'Current allowed with BATTERIES_VIEW');
		$this->expectStatus(fn () => self::$batteriesApi->BatteryDetails(self::request(), new Response(), ['batteryId' => 501]), 200, 'BatteryDetails allowed with BATTERIES_VIEW');
	}

	public function testEquipmentPageRoutesRefuseWithoutGrantAndSucceedWithEquipmentView(): void
	{
		self::grant([]);

		$this->expectStatus(fn () => self::$equipment->Overview(self::request(), new Response(), []), 403, 'Overview refuses no grants');
		$this->expectStatus(fn () => self::$equipment->EditForm(self::request(), new Response(), ['equipmentId' => 'new']), 403, 'EditForm refuses no grants');

		self::grant(['EQUIPMENT_VIEW']);

		$this->expectStatus(fn () => self::$equipment->Overview(self::request(), new Response(), []), 200, 'Overview allowed with EQUIPMENT_VIEW');
		$this->expectStatus(fn () => self::$equipment->EditForm(self::request(), new Response(), ['equipmentId' => 'new']), 200, 'EditForm allowed with EQUIPMENT_VIEW');
	}

	public function testCalendarPageRefusesWithoutGrantAndSucceedsWithCalendarView(): void
	{
		self::grant([]);
		$this->expectStatus(fn () => self::$calendar->Overview(self::request(), new Response(), []), 403, 'Overview refuses no grants');

		self::grant(['CALENDAR_VIEW']);
		$this->expectStatus(fn () => self::$calendar->Overview(self::request(), new Response(), []), 200, 'Overview allowed with CALENDAR_VIEW');
	}

	public function testIcalSharingLinkRequiresCalendarViewToCreateOrView(): void
	{
		self::grant([]);
		$this->expectStatus(fn () => self::$calendarApi->IcalSharingLink(self::request(), new Response(), []), 403, 'IcalSharingLink refuses no grants');

		self::grant(['CALENDAR_VIEW']);
		$this->expectStatus(fn () => self::$calendarApi->IcalSharingLink(self::request(), new Response(), []), 200, 'IcalSharingLink allowed with CALENDAR_VIEW');
	}

	/**
	 * The token IS the authorization for the iCal export (docs/manual, and
	 * ApiKeyAuthenticator's CalendarSharingSecret() - this is the one route the "secret"
	 * query parameter is ever read from). A session-authenticated caller with no
	 * CALENDAR_VIEW is refused; the same route with a valid special-purpose calendar
	 * key's secret is not, even for a caller who holds no permissions at all.
	 */
	public function testIcalRequiresCalendarViewForSessionAccessButAValidSecretTokenBypassesIt(): void
	{
		self::grant([]);
		$this->expectStatus(fn () => self::$calendarApi->Ical(self::request(), new Response(), []), 403, 'Ical refuses session access with no grants');

		$secret = ApiKeyService::GetInstance()->GetOrCreateApiKey(ApiKeyService::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL);

		self::grant([]);
		$this->expectStatus(fn () => self::$calendarApi->Ical(self::request('GET', ['secret' => $secret]), new Response(), []), 200, 'Ical allowed via a valid calendar sharing secret with no permissions granted at all');

		$this->expectStatus(fn () => self::$calendarApi->Ical(self::request('GET', ['secret' => 'not-a-real-secret']), new Response(), []), 403, 'An invalid secret does not bypass the permission check');

		self::grant(['CALENDAR_VIEW']);
		$this->expectStatus(fn () => self::$calendarApi->Ical(self::request(), new Response(), []), 200, 'Ical allowed via session access with CALENDAR_VIEW');
	}

	/**
	 * GenericEntityController's userentities/userfields/userobjects pages, gated on
	 * exactly the permission(s) GenericEntityApiController::AddObject/EditObject/DeleteObject
	 * already require for a write to the same entity (MASTER_DATA_EDIT for all three,
	 * plus ADMIN for userentities/userfields - victual.openapi.json's
	 * ExposedEntityEditRequiresAdmin enum), rather than a new *_VIEW leaf.
	 */
	public function testGenericEntityPagesRequireTheSamePermissionAsTheMatchingWritePath(): void
	{
		self::grant([]);
		$this->expectStatus(fn () => self::$generic->UserentitiesList(self::request(), new Response(), []), 403, 'UserentitiesList refuses no grants');
		$this->expectStatus(fn () => self::$generic->UserentityEditForm(self::request(), new Response(), ['userentityId' => 'new']), 403, 'UserentityEditForm refuses no grants');
		$this->expectStatus(fn () => self::$generic->UserfieldsList(self::request(), new Response(), []), 403, 'UserfieldsList refuses no grants');
		$this->expectStatus(fn () => self::$generic->UserfieldEditForm(self::request(), new Response(), ['userfieldId' => 'new']), 403, 'UserfieldEditForm refuses no grants');
		$this->expectStatus(fn () => self::$generic->UserobjectsList(self::request(), new Response(), ['userentityName' => 'gatingentity']), 403, 'UserobjectsList refuses no grants');
		$this->expectStatus(fn () => self::$generic->UserobjectEditForm(self::request(), new Response(), ['userentityName' => 'gatingentity', 'userobjectId' => 'new']), 403, 'UserobjectEditForm refuses no grants');

		// MASTER_DATA_EDIT alone is not enough for userentities/userfields: ADMIN is also required.
		self::grant(['MASTER_DATA_EDIT']);
		$this->expectStatus(fn () => self::$generic->UserentitiesList(self::request(), new Response(), []), 403, 'UserentitiesList refuses MASTER_DATA_EDIT alone');
		$this->expectStatus(fn () => self::$generic->UserfieldsList(self::request(), new Response(), []), 403, 'UserfieldsList refuses MASTER_DATA_EDIT alone');
		$this->expectStatus(fn () => self::$generic->UserobjectsList(self::request(), new Response(), ['userentityName' => 'gatingentity']), 200, 'UserobjectsList allowed with MASTER_DATA_EDIT alone');

		self::grant(['MASTER_DATA_EDIT', 'ADMIN']);
		$this->expectStatus(fn () => self::$generic->UserentitiesList(self::request(), new Response(), []), 200, 'UserentitiesList allowed with MASTER_DATA_EDIT and ADMIN');
		$this->expectStatus(fn () => self::$generic->UserentityEditForm(self::request(), new Response(), ['userentityId' => 'new']), 200, 'UserentityEditForm allowed with MASTER_DATA_EDIT and ADMIN');
		$this->expectStatus(fn () => self::$generic->UserfieldsList(self::request(), new Response(), []), 200, 'UserfieldsList allowed with MASTER_DATA_EDIT and ADMIN');
		$this->expectStatus(fn () => self::$generic->UserfieldEditForm(self::request(), new Response(), ['userfieldId' => 'new']), 200, 'UserfieldEditForm allowed with MASTER_DATA_EDIT and ADMIN');
		$this->expectStatus(fn () => self::$generic->UserobjectEditForm(self::request(), new Response(), ['userentityName' => 'gatingentity', 'userobjectId' => 'new']), 200, 'UserobjectEditForm allowed with MASTER_DATA_EDIT and ADMIN');

		// ADMIN alone resolves to MASTER_DATA_EDIT through permission_tree, so it is
		// sufficient on its own too.
		self::grant(['ADMIN']);
		$this->expectStatus(fn () => self::$generic->UserentitiesList(self::request(), new Response(), []), 200, 'UserentitiesList allowed with ADMIN alone');
	}
}
