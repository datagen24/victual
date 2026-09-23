<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Exception\HttpException;
use Slim\Http\Factory\DecoratedResponseFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Victual\Controllers\BatteriesController;
use Victual\Controllers\CalendarController;
use Victual\Controllers\ChoresController;
use Victual\Controllers\EquipmentController;
use Victual\Controllers\GenericEntityController;
use Victual\Controllers\LabelPrintJobsController;
use Victual\Controllers\LabelTemplatesController;
use Victual\Controllers\LoginController;
use Victual\Controllers\SystemController;
use Victual\Controllers\TasksController;
use Victual\Controllers\UsersController;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * The household Blade page controllers - chores, batteries, tasks, equipment, the
 * calendar, the user-defined entity system, user management, the label designer's two
 * pages, the login form and the application-level pages - rendered by calling each
 * controller method directly, the way tests/Pgsql/MealPlanRedactionTest.php and
 * RbacTest::testRoleUiRendersAndEscapesNames() do.
 *
 * What these pages promise is on their routes in routes.php and in
 * docs/manual/using-victual: a list page lists the rows of its entity, an overview page
 * says which of them are due, a journal lists what was tracked, a form carries the row it
 * edits. So every assertion here is "the row the page is about reaches the page, and a row
 * that must not reach it does not" - the rendered id of the table row, never its wording.
 *
 * Which pages check a permission and which do not is not an accident this test should
 * paper over: docs/plans/19-rbac.md's Executed section says in so many words that
 * "Batteries, equipment and custom entities retain their previous read policy; this wave
 * does not add view leaves for them", and that calendar aggregation filters by the
 * caller's view leaves rather than gating the page. So the batteries, equipment,
 * userentity and calendar pages are asserted reachable by a caller holding nothing - that
 * is the recorded decision - and the calendar's event list is asserted to shrink with the
 * caller's leaves instead.
 *
 * Every fixture date is pinned. The three that cannot be (a chore due *today*, a battery
 * due *today*, a task due *today*) are built from date('Y-m-d') at fixture time, which is
 * the only way to express "today" at all.
 */
class HouseholdPagesTest extends PgsqlSchemaTestCase
{
	/** Far enough in the past that no "due soon" window and no journal cut-off can reach it. */
	private const LONG_AGO = '2020-01-01 09:00:00';

	private static PDO $db;
	private static \DI\Container $container;
	private static ChoresController $chores;
	private static BatteriesController $batteries;
	private static TasksController $tasks;
	private static SystemController $system;
	private static GenericEntityController $generic;
	private static UsersController $users;
	private static LabelTemplatesController $labelTemplates;
	private static LabelPrintJobsController $labelPrintJobs;
	private static EquipmentController $equipment;
	private static LoginController $login;
	private static CalendarController $calendar;

	/** @var array<string, int> Fixture row ids by the name they were inserted under. */
	private static array $ids = [];

	private static string $today;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$today = date('Y-m-d');

		// UrlManager builds every link from the request host, which a CLI process has not
		// got; without this the pages render "http:/..." and Root()'s redirect cannot be
		// compared against anything.
		$_SERVER['HTTP_HOST'] ??= 'localhost';

		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		// VICTUAL_BASE_URL, not '' - app.php:… builds the container's UrlManager from the
		// setting, and the redirect targets these routes return are what it constructs.
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(VICTUAL_BASE_URL));

		self::$chores = new ChoresController(self::$container);
		self::$batteries = new BatteriesController(self::$container);
		self::$tasks = new TasksController(self::$container);
		self::$system = new SystemController(self::$container);
		self::$generic = new GenericEntityController(self::$container);
		self::$users = new UsersController(self::$container);
		self::$labelTemplates = new LabelTemplatesController(self::$container);
		self::$labelPrintJobs = new LabelPrintJobsController(self::$container);
		self::$equipment = new EquipmentController(self::$container);
		self::$login = new LoginController(self::$container);
		self::$calendar = new CalendarController(self::$container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'household-caller', 'fixture')");
		// A second user, so the user list and the permission page have somebody other than
		// the caller to render - and something to be a negative control against.
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9001, 'household-other', 'fixture')");

		self::seedChores();
		self::seedBatteries();
		self::seedTasks();
		self::seedEquipmentAndUserEntities();
		self::seedLabelTemplates();
		self::seedFullStackCallers();
	}

	/**
	 * The identities and the printer the subprocess scenarios need. A request through the
	 * real stack is authenticated by a session row, and the acting user is whoever that
	 * row names - not the harness process's own 9000.
	 */
	private static function seedFullStackCallers(): void
	{
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9010, 'household-entry-admin', 'fixture'), (9011, 'household-entry-narrow', 'fixture')");
		self::$db->exec('INSERT INTO user_roles(user_id, role_id) VALUES (9010, ' . (int)self::$db->query("SELECT id FROM roles WHERE code = 'ADMIN'")->fetchColumn() . ')');
		// USERS_EDIT_SELF alone: a real identity that holds none of the leaves any entry
		// page is gated on, so every one of them falls back to /about for this caller.
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9011, id FROM permission_hierarchy WHERE name = 'USERS_EDIT_SELF'");
		self::$db->exec("INSERT INTO sessions(session_key, user_id, expires) VALUES ('household-entry-admin', 9010, now() + interval '1 day'), ('household-entry-narrow', 9011, now() + interval '1 day')");

		// One configured, active, default printer, which is what makes the label print
		// widget render at all.
		self::$db->exec("INSERT INTO label_workers (id, name, configuration_mode, active) VALUES (9100, 'household-worker', 'declared', 1)");
		self::$db->exec("INSERT INTO label_drivers (driver_id, schema_version, contract_version, connection_types, discriminator_properties, combination_binding, settings_schemas, capability_document, registered_by_worker_id)
			VALUES ('household-driver', '1', 1, '[]'::jsonb, '[]'::jsonb, '{}'::jsonb, '{}'::jsonb, '{}'::jsonb, 9100)");
		self::$db->exec("INSERT INTO label_printers (name, active, is_default, worker_id, driver_id, driver_schema_version, connection, connection_type, model, settings, settings_validated_at)
			VALUES ('Household label printer', 1, 1, 9100, 'household-driver', '1', 'usb://household', 'usb', 'HH-1', '{}'::jsonb, now())");
	}

	/**
	 * Chores whose next execution is pinned through `rescheduled_date`, which
	 * chores_current honours ahead of every period calculation, plus one chore that has
	 * never been executed and one that has.
	 */
	private static function seedChores(): void
	{
		$insert = self::$db->prepare('INSERT INTO chores (name, period_type, period_interval, start_date, rescheduled_date, active, track_date_only)
			VALUES (?, ?, 1, ?, ?, ?, 0) RETURNING id');

		$rows = [
			// name, period_type, start_date, rescheduled_date, active
			['Chore overdue', 'daily', self::LONG_AGO, self::LONG_AGO, 1],
			['Chore due today', 'daily', self::LONG_AGO, self::$today . ' 23:59:59', 1],
			['Chore due soon', 'daily', self::LONG_AGO, date('Y-m-d', strtotime('+3 days')) . ' 12:00:00', 1],
			// No rescheduled_date and no log row: chores_current falls back to start_date.
			['Chore never executed', 'daily', self::LONG_AGO, null, 1],
			['Chore executed once', 'daily', self::LONG_AGO, null, 1],
			// The negative control for every page that lists "current" chores.
			['Chore retired', 'daily', self::LONG_AGO, self::LONG_AGO, 0],
		];

		foreach ($rows as $row)
		{
			$insert->execute([$row[0], $row[1], $row[2], $row[3], $row[4]]);
			self::$ids[$row[0]] = (int)$insert->fetchColumn();
		}

		// One execution long ago (outside the journal's 12 month default) and one today
		// (inside it), so the journal's months filter has both sides to distinguish.
		$log = self::$db->prepare('INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id, undone) VALUES (?, ?, 9000, 0) RETURNING id');
		$log->execute([self::$ids['Chore executed once'], self::LONG_AGO]);
		self::$ids['log long ago'] = (int)$log->fetchColumn();
		$log->execute([self::$ids['Chore executed once'], self::$today . ' 12:00:00']);
		self::$ids['log today'] = (int)$log->fetchColumn();

		self::$db->exec("INSERT INTO userfields (entity, name, caption, type, show_as_column_in_tables) VALUES ('chores', 'chorenote', 'Chore note', 'text-single-line', 1)");
	}

	/**
	 * Batteries, whose next charge is MAX(tracked_time) + charge_interval_days, so the
	 * cycle rows below are what pin each one's due state.
	 */
	private static function seedBatteries(): void
	{
		$insert = self::$db->prepare('INSERT INTO batteries (name, charge_interval_days, active) VALUES (?, ?, ?) RETURNING id');

		foreach ([
			['Battery overdue', 30, 1],
			['Battery due today', 1, 1],
			['Battery due soon', 10, 1],
			['Battery never charged', 7, 1],
			// charge_interval_days 0 is the documented "do not track an interval" value.
			['Battery without interval', 0, 1],
			['Battery retired', 30, 0],
		] as $row)
		{
			$insert->execute($row);
			self::$ids[$row[0]] = (int)$insert->fetchColumn();
		}

		$cycle = self::$db->prepare('INSERT INTO battery_charge_cycles (battery_id, tracked_time, undone) VALUES (?, ?, 0) RETURNING id');
		$cycle->execute([self::$ids['Battery overdue'], self::LONG_AGO]);
		self::$ids['cycle long ago'] = (int)$cycle->fetchColumn();
		// Yesterday at the last second of the day plus the one day interval is today at the
		// last second of the day: due today, and not yet overdue at any hour the suite runs.
		$cycle->execute([self::$ids['Battery due today'], date('Y-m-d', strtotime('-1 days')) . ' 23:59:59']);
		self::$ids['cycle yesterday'] = (int)$cycle->fetchColumn();
		// Seven days ago on a ten day interval: three days from now, inside the five day
		// default "due soon" window.
		$cycle->execute([self::$ids['Battery due soon'], date('Y-m-d', strtotime('-7 days')) . ' 12:00:00']);
		self::$ids['cycle a week ago'] = (int)$cycle->fetchColumn();
		$cycle->execute([self::$ids['Battery without interval'], self::$today . ' 08:00:00']);
		self::$ids['cycle today'] = (int)$cycle->fetchColumn();

		self::$db->exec("INSERT INTO userfields (entity, name, caption, type, show_as_column_in_tables) VALUES ('batteries', 'batterynote', 'Battery note', 'text-single-line', 1)");
	}

	private static function seedTasks(): void
	{
		self::$db->exec("INSERT INTO task_categories (name, active) VALUES ('Task category active', 1), ('Task category retired', 0)");
		self::$ids['Task category active'] = (int)self::$db->query("SELECT id FROM task_categories WHERE name = 'Task category active'")->fetchColumn();
		self::$ids['Task category retired'] = (int)self::$db->query("SELECT id FROM task_categories WHERE name = 'Task category retired'")->fetchColumn();

		$insert = self::$db->prepare('INSERT INTO tasks (name, due_date, done, category_id) VALUES (?, ?, ?, ?) RETURNING id');
		foreach ([
			['Task overdue', '2020-01-01', 0],
			['Task due today', self::$today, 0],
			['Task due soon', date('Y-m-d', strtotime('+3 days')), 0],
			// A task with no due date at all: the missing optional field.
			['Task without due date', null, 0],
			// The negative control for the default task list.
			['Task already done', '2020-01-01', 1],
		] as $row)
		{
			$insert->execute([$row[0], $row[1], $row[2], self::$ids['Task category active']]);
			self::$ids[$row[0]] = (int)$insert->fetchColumn();
		}

		self::$db->exec("INSERT INTO userfields (entity, name, caption, type, show_as_column_in_tables) VALUES ('tasks', 'tasknote', 'Task note', 'text-single-line', 1)");
	}

	private static function seedEquipmentAndUserEntities(): void
	{
		$insert = self::$db->prepare('INSERT INTO equipment (name, description) VALUES (?, ?) RETURNING id');
		$insert->execute(['Equipment listed', 'The dishwasher']);
		self::$ids['Equipment listed'] = (int)$insert->fetchColumn();
		$insert->execute(['Equipment second', 'The oven']);
		self::$ids['Equipment second'] = (int)$insert->fetchColumn();

		self::$db->exec("INSERT INTO userentities (name, caption, description, show_in_sidebar_menu) VALUES ('householdbook', 'Household book', 'A user defined entity', 1)");
		self::$ids['householdbook'] = (int)self::$db->query("SELECT id FROM userentities WHERE name = 'householdbook'")->fetchColumn();
		self::$db->exec("INSERT INTO userentities (name, caption, description, show_in_sidebar_menu) VALUES ('hiddenbook', 'Hidden book', 'Not in the sidebar', 0)");
		self::$ids['hiddenbook'] = (int)self::$db->query("SELECT id FROM userentities WHERE name = 'hiddenbook'")->fetchColumn();

		$object = self::$db->prepare('INSERT INTO userobjects (userentity_id) VALUES (?) RETURNING id');
		$object->execute([self::$ids['householdbook']]);
		self::$ids['userobject listed'] = (int)$object->fetchColumn();
		// Belongs to the other userentity, so it must not appear under householdbook.
		$object->execute([self::$ids['hiddenbook']]);
		self::$ids['userobject elsewhere'] = (int)$object->fetchColumn();

		self::$db->exec("INSERT INTO userfields (entity, name, caption, type, show_as_column_in_tables) VALUES ('userentity-householdbook', 'bookpage', 'Book page', 'text-single-line', 1)");
		self::$ids['userfield bookpage'] = (int)self::$db->query("SELECT id FROM userfields WHERE name = 'bookpage'")->fetchColumn();
		self::$ids['userfield chorenote'] = (int)self::$db->query("SELECT id FROM userfields WHERE name = 'chorenote'")->fetchColumn();
	}

	private static function seedLabelTemplates(): void
	{
		$insert = self::$db->prepare("INSERT INTO label_templates (name, description, entity_kind) VALUES (?, ?, 'product') RETURNING id");
		$insert->execute(['Template listed', 'A fixture template']);
		self::$ids['Template listed'] = (int)$insert->fetchColumn();
		$insert->execute(['Template second', 'Another fixture template']);
		self::$ids['Template second'] = (int)$insert->fetchColumn();
	}

	// ---------------------------------------------------------------- harness helpers

	protected function setUp(): void
	{
		parent::setUp();

		// Every test states the grant it needs; starting from ADMIN means no test depends
		// on the order the previous one left the caller in.
		self::grantRole('ADMIN');
	}

	private static function request(string $method = 'GET', array $query = [], $body = null)
	{
		$request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/page');

		if ($query !== [])
		{
			$request = $request->withQueryParams($query);
		}

		return $body === null ? $request : $request->withParsedBody($body);
	}

	/**
	 * A response of the kind the application actually hands a controller. AppFactory wraps
	 * the PSR-17 factory in slim/http's DecoratedResponseFactory, and withRedirect() -
	 * which the root and login routes return - lives on that decorator alone, so a bare
	 * Slim\Psr7\Response would make those routes a fatal error the running application
	 * never sees.
	 */
	private static function response(): \Psr\Http\Message\ResponseInterface
	{
		return (new DecoratedResponseFactory(new ResponseFactory(), new StreamFactory()))->createResponse();
	}

	/** RbacTest.php:65-71 - a permission refusal thrown above the route arrives as HttpException. */
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

	private static function grantRole(string $code): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');
		$stmt = self::$db->prepare('SELECT id FROM roles WHERE code = ?');
		$stmt->execute([$code]);
		self::$db->exec('INSERT INTO user_roles(user_id, role_id) VALUES (9000, ' . (int)$stmt->fetchColumn() . ')');
	}

	/** Renders through the given controller call and returns the HTML of a 200 answer. */
	private static function render(callable $work, string $what): string
	{
		$response = $work();
		self::assertSame(200, $response->getStatusCode(), "$what answers 200");
		$html = (string)$response->getBody();
		self::assertNotSame('', $html, "$what renders a body");

		return $html;
	}

	/**
	 * Renders a page, handing back any PHP diagnostics it raised for assertion instead of
	 * letting PHPUnit's handler turn them into a failed run (phpunit.xml sets
	 * failOnWarning="true", correctly - a warning is a defect).
	 *
	 * The default mask is E_ALL rather than the weaker E_WARNING: masking this helper to a
	 * severity does not forward out-of-mask diagnostics to the handler that was installed
	 * before it, so a mask narrower than E_ALL can leave a diagnostic unassert-able and
	 * unnoticed. Asserting the full list under E_ALL is what guarantees nothing is
	 * swallowed. Callers that need to isolate a single severity (e.g. E_DEPRECATED) may
	 * still narrow it explicitly.
	 *
	 * @param int $mask The diagnostics to capture; anything else keeps reaching PHPUnit.
	 * @return array{0: string, 1: string[]}
	 */
	private static function renderCapturingWarnings(callable $work, string $what, int $mask = E_ALL): array
	{
		$warnings = [];
		set_error_handler(function (int $number, string $message) use (&$warnings): bool
		{
			$warnings[] = $message;
			return true;
		}, $mask);

		try
		{
			$html = self::render($work, $what);
		}
		finally
		{
			restore_error_handler();
		}

		return [$html, $warnings];
	}

	private static function assertListsRow(string $html, string $rowId, string $message): void
	{
		self::assertStringContainsString('id="' . $rowId . '"', $html, $message);
	}

	private static function assertOmitsRow(string $html, string $rowId, string $message): void
	{
		self::assertStringNotContainsString('id="' . $rowId . '"', $html, $message);
	}

	/**
	 * The class attribute of one rendered table row. The overview pages express
	 * "overdue"/"due today"/"due soon" by colouring the row, so that attribute is the
	 * user-visible form of the due_type the controller computes.
	 */
	private static function rowClass(string $html, string $rowId): string
	{
		self::assertSame(1, preg_match('/id="' . preg_quote($rowId, '/') . '"\s*class="([^"]*)"/', $html, $matches), "the page renders a row $rowId carrying a class");

		return $matches[1];
	}

	/**
	 * The state of one permission checkbox on the per-user permission page: whether the
	 * page says this user holds it directly and whether it says a role brings it.
	 *
	 * @return array{direct: string, inherited: string}
	 */
	private static function permissionCheckbox(string $html, string $name): array
	{
		self::assertSame(1, preg_match('/<input type="checkbox"[^>]*data-permission-name="' . $name . '"[^>]*>/s', $html, $matches), "the page renders a checkbox for $name");
		self::assertSame(1, preg_match('/data-direct="(\\d)"/', $matches[0], $direct));
		self::assertSame(1, preg_match('/data-inherited="(\\d)"/', $matches[0], $inherited));

		return ['direct' => $direct[1], 'inherited' => $inherited[1]];
	}

	/**
	 * Row counts of everything these pages could conceivably write to. A page is a read;
	 * a refusal is a read that did not happen. Neither may leave a row behind.
	 *
	 * @return array<string, int>
	 */
	private static function stateSnapshot(): array
	{
		$counts = [];
		foreach (['chores', 'chores_log', 'batteries', 'battery_charge_cycles', 'tasks', 'task_categories',
			'equipment', 'users', 'user_permissions', 'user_roles', 'userentities', 'userobjects', 'userfields',
			'userfield_values', 'sessions', 'label_templates'] as $table)
		{
			$counts[$table] = (int)self::$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
		}

		return $counts;
	}

	// ---------------------------------------------------------------------- chores

	public function testChoresOverviewFlagsEachCurrentChoreByWhenItIsNextDue(): void
	{
		$html = self::render(fn () => self::$chores->Overview(self::request(), self::response(), []), 'GET /choresoverview');

		self::assertStringContainsString('table-danger', self::rowClass($html, 'chore-' . self::$ids['Chore overdue'] . '-row'), 'a chore whose next execution is in 2020 is overdue');
		self::assertStringContainsString('table-info', self::rowClass($html, 'chore-' . self::$ids['Chore due today'] . '-row'), 'a chore due at the end of today is due today');
		self::assertStringContainsString('table-warning', self::rowClass($html, 'chore-' . self::$ids['Chore due soon'] . '-row'), 'a chore due in three days is within the five day default window');

		// Executed and never executed are both current; what differs is the tracked time.
		self::assertListsRow($html, 'chore-' . self::$ids['Chore never executed'] . '-row', 'a chore nobody has done yet is still on the overview');
		self::assertListsRow($html, 'chore-' . self::$ids['Chore executed once'] . '-row', 'a chore that has been done is on the overview too');
		self::assertStringContainsString(self::$today . ' 12:00:00', $html, 'the overview reports the pinned last execution of the chore that has one');

		self::assertOmitsRow($html, 'chore-' . self::$ids['Chore retired'] . '-row', 'an inactive chore is not something the household still owes');
	}

	public function testChoresOverviewRefusesACallerWithoutChoresViewAndWritesNothing(): void
	{
		self::grant(['TASKS_VIEW']);
		$before = self::stateSnapshot();

		$this->expectStatus(fn () => self::$chores->Overview(self::request(), self::response(), []), 403, 'GET /choresoverview without CHORES_VIEW');

		self::assertSame($before, self::stateSnapshot(), 'a refused page read leaves every table as it was');
	}

	public function testChoresListShowsActiveChoresAndOnlyIncludesRetiredOnesWhenAsked(): void
	{
		$default = self::render(fn () => self::$chores->ChoresList(self::request(), self::response(), []), 'GET /chores');
		self::assertStringContainsString('Chore overdue', $default);
		self::assertStringNotContainsString('Chore retired', $default, 'the master data list hides inactive chores by default');

		$withDisabled = self::render(fn () => self::$chores->ChoresList(self::request('GET', ['include_disabled' => '']), self::response(), []), 'GET /chores?include_disabled');
		self::assertStringContainsString('Chore retired', $withDisabled, 'include_disabled is presence-only, so an empty value still turns it on');
		self::assertStringContainsString('Chore overdue', $withDisabled, 'and it adds to the list rather than replacing it');
	}

	public function testChoresListRefusesACallerWithoutChoresView(): void
	{
		self::grant([]);
		$this->expectStatus(fn () => self::$chores->ChoresList(self::request(), self::response(), []), 403, 'GET /chores without CHORES_VIEW');
	}

	public function testChoreEditFormCarriesTheChoreItEditsAndTheCreateFormCarriesNone(): void
	{
		// views/choreform.blade.php explode(',', $chore->assignment_config) on the edit
		// form used to raise a deprecation once per user in the household for a chore
		// whose assignment type is the default "no assignment" (assignment_config is
		// nullable and null in that case), and would be a TypeError once PHP removes the
		// null coercion. The chore's assignment_config is coalesced to '' before the
		// explode(), so a chore with no assignment renders clean under E_ALL.
		[$edit, $editDiagnostics] = self::renderCapturingWarnings(
			fn () => self::$chores->ChoreEditForm(self::request(), self::response(), ['choreId' => self::$ids['Chore overdue']]),
			'GET /chore/{id}',
			E_ALL
		);
		self::assertStringContainsString('Chore overdue', $edit, 'the edit form is populated from the row it edits');
		self::assertStringNotContainsString('Chore due today', $edit, 'and carries no other chore');
		self::assertSame([], $editDiagnostics, 'the edit form for a chore with no assignment renders with no PHP diagnostics');

		// views/choreform.blade.php read $chore->id for the grocycode "Download" link,
		// outside the @if($mode == 'edit') guard that wraps the barcode image immediately
		// above it, so GET /chore/new raised two warnings and rendered a download link to
		// /chore//grocycode. The guard now covers both, as it already does on the battery
		// form, so the whole Grocycode block - image and download link - is absent in
		// create mode instead of rendering broken.
		[$create, $createDiagnostics] = self::renderCapturingWarnings(fn () => self::$chores->ChoreEditForm(self::request(), self::response(), ['choreId' => 'new']), 'GET /chore/new', E_ALL);
		self::assertStringNotContainsString('Chore overdue', $create, 'the create form starts empty');
		self::assertSame([], $createDiagnostics, 'the chore create form renders with no PHP diagnostics');
		self::assertStringNotContainsString('/chore//grocycode', $create, 'and renders no Grocycode block at all in create mode');
	}

	public function testChoreEditFormRefusesACallerWithoutChoresView(): void
	{
		self::grant([]);
		$this->expectStatus(fn () => self::$chores->ChoreEditForm(self::request(), self::response(), ['choreId' => 'new']), 403, 'GET /chore/new without CHORES_VIEW');
		$this->expectStatus(fn () => self::$chores->ChoreEditForm(self::request(), self::response(), ['choreId' => self::$ids['Chore overdue']]), 403, 'GET /chore/{id} without CHORES_VIEW');
	}

	public function testChoresJournalListsRecentExecutionsAndTheMonthsWindowMovesTheCutOff(): void
	{
		$default = self::render(fn () => self::$chores->Journal(self::request(), self::response(), []), 'GET /choresjournal');
		self::assertListsRow($default, 'chore-execution-' . self::$ids['log today'] . '-row', 'the default window is twelve months, which reaches today');
		self::assertOmitsRow($default, 'chore-execution-' . self::$ids['log long ago'] . '-row', 'and does not reach 2020');

		$wide = self::render(fn () => self::$chores->Journal(self::request('GET', ['months' => '120']), self::response(), []), 'GET /choresjournal?months=120');
		self::assertListsRow($wide, 'chore-execution-' . self::$ids['log long ago'] . '-row', 'ten years reaches the 2020 execution');

		// Zero is the boundary: the cut-off becomes today's date, so only what was tracked
		// after midnight survives it.
		$zero = self::render(fn () => self::$chores->Journal(self::request('GET', ['months' => '0']), self::response(), []), 'GET /choresjournal?months=0');
		self::assertListsRow($zero, 'chore-execution-' . self::$ids['log today'] . '-row', 'months=0 still reaches what was tracked today');
		self::assertOmitsRow($zero, 'chore-execution-' . self::$ids['log long ago'] . '-row', 'months=0 does not reach 2020');

		// A months value that is not an integer is not an error page; the route falls back
		// to its documented default rather than refusing a bookmarked URL.
		$invalid = self::render(fn () => self::$chores->Journal(self::request('GET', ['months' => 'twelve']), self::response(), []), 'GET /choresjournal?months=twelve');
		self::assertListsRow($invalid, 'chore-execution-' . self::$ids['log today'] . '-row', 'an unparseable months falls back to the twelve month default');
		self::assertOmitsRow($invalid, 'chore-execution-' . self::$ids['log long ago'] . '-row', 'and the default still does not reach 2020');
	}

	public function testChoresJournalFiltersByChore(): void
	{
		$other = self::$ids['Chore overdue'];
		$filtered = self::render(fn () => self::$chores->Journal(self::request('GET', ['months' => '120', 'chore' => (string)$other]), self::response(), []), 'GET /choresjournal?chore=');
		self::assertOmitsRow($filtered, 'chore-execution-' . self::$ids['log long ago'] . '-row', 'filtering by a chore with no executions lists none of another chore\'s');

		$mine = self::render(fn () => self::$chores->Journal(self::request('GET', ['months' => '120', 'chore' => (string)self::$ids['Chore executed once']]), self::response(), []), 'GET /choresjournal?chore=');
		self::assertListsRow($mine, 'chore-execution-' . self::$ids['log long ago'] . '-row', 'filtering by the chore that was executed lists its executions');
	}

	public function testChoresJournalRefusesACallerWithoutChoresView(): void
	{
		self::grant([]);
		$this->expectStatus(fn () => self::$chores->Journal(self::request(), self::response(), []), 403, 'GET /choresjournal without CHORES_VIEW');
	}

	public function testChoreTrackingOffersActiveChoresOnly(): void
	{
		$html = self::render(fn () => self::$chores->TrackChoreExecution(self::request(), self::response(), []), 'GET /choretracking');
		self::assertStringContainsString('Chore overdue', $html, 'the tracking page offers the chores that can be tracked');
		self::assertStringNotContainsString('Chore retired', $html, 'a retired chore cannot be tracked, so it is not offered');
		self::assertStringContainsString('household-caller', $html, 'and it offers the users an execution can be attributed to');
	}

	public function testChoreTrackingRefusesACallerWithoutChoresView(): void
	{
		self::grant([]);
		$this->expectStatus(fn () => self::$chores->TrackChoreExecution(self::request(), self::response(), []), 403, 'GET /choretracking without CHORES_VIEW');
	}

	public function testChoresSettingsRendersAndIsGated(): void
	{
		self::render(fn () => self::$chores->ChoresSettings(self::request(), self::response(), []), 'GET /choressettings');

		self::grant([]);
		$this->expectStatus(fn () => self::$chores->ChoresSettings(self::request(), self::response(), []), 403, 'GET /choressettings without CHORES_VIEW');
	}

	// ------------------------------------------------------------------ grocycode

	/**
	 * docs/grocycode.md: Grocycode is a serialization that references an entity by type
	 * and id, rendered as a barcode picture. The picture is the only observable, so what
	 * is asserted is what a picture can prove: it is a PNG, it is the same picture for the
	 * same reference, and a different reference is a different picture - which is what
	 * "the type letter and the id are encoded in it" means when the bytes cannot be
	 * decoded here.
	 */
	public function testChoreGrocycodeImageServesAPngThatDependsOnTheReference(): void
	{
		$response = self::$chores->ChoreGrocycodeImage(self::request(), self::response(), ['choreId' => self::$ids['Chore overdue']]);
		self::assertSame(200, $response->getStatusCode());
		self::assertSame('image/png', $response->getHeaderLine('Content-Type'), 'an inline grocycode is served as an image');
		$png = (string)$response->getBody();
		self::assertStringStartsWith("\x89PNG", $png, 'the body is a PNG');
		self::assertSame((string)strlen($png), $response->getHeaderLine('Content-Length'), 'the declared length is the real one');
		self::assertSame('no-cache', $response->getHeaderLine('Cache-Control'));

		$again = (string)self::$chores->ChoreGrocycodeImage(self::request(), self::response(), ['choreId' => self::$ids['Chore overdue']])->getBody();
		self::assertSame($png, $again, 'the same reference always renders the same code');

		$otherChore = (string)self::$chores->ChoreGrocycodeImage(self::request(), self::response(), ['choreId' => self::$ids['Chore due today']])->getBody();
		self::assertNotSame($png, $otherChore, 'a different chore id is a different code');

		// Same id, different entity type: "grcy:b:<id>" is not "grcy:c:<id>", which is the
		// whole point of the type identifier in docs/grocycode.md.
		$sameIdOtherType = (string)self::$batteries->BatteryGrocycodeImage(self::request(), self::response(), ['batteryId' => self::$ids['Chore overdue']])->getBody();
		self::assertNotSame($png, $sameIdOtherType, 'the entity type is part of the encoded reference');
	}

	public function testGrocycodeImageHonoursTheSizeAndDownloadParameters(): void
	{
		$default = (string)self::$chores->ChoreGrocycodeImage(self::request(), self::response(), ['choreId' => self::$ids['Chore overdue']])->getBody();
		$sized = (string)self::$chores->ChoreGrocycodeImage(self::request('GET', ['size' => '200']), self::response(), ['choreId' => self::$ids['Chore overdue']])->getBody();
		self::assertNotSame(strlen($default), strlen($sized), 'size changes the rendered picture');

		$download = self::$chores->ChoreGrocycodeImage(self::request('GET', ['download' => '1']), self::response(), ['choreId' => self::$ids['Chore overdue']]);
		self::assertSame('application/octet-stream', $download->getHeaderLine('Content-Type'), 'a download is not served inline');
		self::assertSame('attachment; filename=Grocycode.png', $download->getHeaderLine('Content-Disposition'));
		self::assertStringStartsWith("\x89PNG", (string)$download->getBody(), 'and it is still the same PNG');
	}

	/**
	 * Grocycode is an input symbology (ADR-0011): a code is minted from a reference, and
	 * nothing resolves it on the way out. So a code for a row that does not exist is still
	 * a well formed code - the "does this exist" question belongs to whatever reads it
	 * back, not to the picture.
	 */
	public function testGrocycodeImageIsMintedWithoutResolvingTheReference(): void
	{
		$response = self::$chores->ChoreGrocycodeImage(self::request(), self::response(), ['choreId' => '999999']);
		self::assertSame(200, $response->getStatusCode(), 'minting a code does not look the row up');
		self::assertStringStartsWith("\x89PNG", (string)$response->getBody());
	}

	public function testChoreGrocycodeImageRefusesACallerWithoutChoresView(): void
	{
		self::grant([]);
		$this->expectStatus(fn () => self::$chores->ChoreGrocycodeImage(self::request(), self::response(), ['choreId' => self::$ids['Chore overdue']]), 403, 'GET /chore/{id}/grocycode without CHORES_VIEW');
	}

	// -------------------------------------------------------------------- batteries

	public function testBatteriesOverviewFlagsEachBatteryByWhenItIsNextDue(): void
	{
		$html = self::render(fn () => self::$batteries->Overview(self::request(), self::response(), []), 'GET /batteriesoverview');

		self::assertStringContainsString('table-danger', self::rowClass($html, 'battery-' . self::$ids['Battery overdue'] . '-row'), 'charged in 2020 on a 30 day interval is overdue');
		self::assertStringContainsString('table-info', self::rowClass($html, 'battery-' . self::$ids['Battery due today'] . '-row'), 'charged yesterday on a one day interval is due today');
		self::assertStringContainsString('table-warning', self::rowClass($html, 'battery-' . self::$ids['Battery due soon'] . '-row'), 'charged seven days ago on a ten day interval is within the five day window');
		self::assertStringContainsString('table-danger', self::rowClass($html, 'battery-' . self::$ids['Battery never charged'] . '-row'), 'a battery with an interval that has never been charged is due now');

		// charge_interval_days 0 means "no interval tracked", so the row carries no due
		// colouring at all - the zero boundary.
		self::assertSame('', trim(self::rowClass($html, 'battery-' . self::$ids['Battery without interval'] . '-row')), 'a battery without a charge interval is never reported due');

		self::assertOmitsRow($html, 'battery-' . self::$ids['Battery retired'] . '-row', 'an inactive battery is not tracked');
		self::assertStringContainsString(self::LONG_AGO, $html, 'the overview reports the pinned last charge of the battery that has one');
	}

	/**
	 * docs/plans/19-rbac.md's Executed section: "Batteries, equipment and custom entities
	 * retain their previous read policy; this wave does not add view leaves for them." So
	 * a caller holding no leaf at all still reaches these pages, and that is the recorded
	 * decision rather than an oversight. This pins it, so that changing it has to be a
	 * decision too.
	 */
	public function testBatteryPagesFollowTheRecordedReadPolicyOfNoViewLeaf(): void
	{
		self::grant([]);
		$before = self::stateSnapshot();

		self::render(fn () => self::$batteries->Overview(self::request(), self::response(), []), 'GET /batteriesoverview with no grants');
		self::render(fn () => self::$batteries->BatteriesList(self::request(), self::response(), []), 'GET /batteries with no grants');
		self::render(fn () => self::$batteries->Journal(self::request(), self::response(), []), 'GET /batteriesjournal with no grants');
		self::render(fn () => self::$batteries->TrackChargeCycle(self::request(), self::response(), []), 'GET /batterytracking with no grants');
		self::render(fn () => self::$batteries->BatteriesSettings(self::request(), self::response(), []), 'GET /batteriessettings with no grants');
		self::render(fn () => self::$batteries->BatteryEditForm(self::request(), self::response(), ['batteryId' => 'new']), 'GET /battery/new with no grants');

		self::assertSame($before, self::stateSnapshot(), 'and none of them writes anything');
	}

	public function testBatteriesListShowsActiveBatteriesAndOnlyIncludesRetiredOnesWhenAsked(): void
	{
		$default = self::render(fn () => self::$batteries->BatteriesList(self::request(), self::response(), []), 'GET /batteries');
		self::assertStringContainsString('Battery overdue', $default);
		self::assertStringNotContainsString('Battery retired', $default, 'the master data list hides inactive batteries by default');

		$withDisabled = self::render(fn () => self::$batteries->BatteriesList(self::request('GET', ['include_disabled' => '']), self::response(), []), 'GET /batteries?include_disabled');
		self::assertStringContainsString('Battery retired', $withDisabled);
		self::assertStringContainsString('Battery overdue', $withDisabled);
	}

	public function testBatteryEditFormCarriesTheBatteryItEditsAndTheCreateFormCarriesNone(): void
	{
		$edit = self::render(fn () => self::$batteries->BatteryEditForm(self::request(), self::response(), ['batteryId' => self::$ids['Battery overdue']]), 'GET /battery/{id}');
		self::assertStringContainsString('Battery overdue', $edit);
		self::assertStringNotContainsString('Battery due today', $edit, 'the form carries only the battery it edits');

		$create = self::render(fn () => self::$batteries->BatteryEditForm(self::request(), self::response(), ['batteryId' => 'new']), 'GET /battery/new');
		self::assertStringNotContainsString('Battery overdue', $create);
	}

	public function testBatteriesJournalListsChargeCyclesAndTheMonthsWindowMovesTheCutOff(): void
	{
		// The default is twenty-four months, which does not reach 2020 either.
		$default = self::render(fn () => self::$batteries->Journal(self::request(), self::response(), []), 'GET /batteriesjournal');
		self::assertListsRow($default, 'charge-cycle-' . self::$ids['cycle today'] . '-row', 'the default window reaches today');
		self::assertOmitsRow($default, 'charge-cycle-' . self::$ids['cycle long ago'] . '-row', 'and does not reach 2020');

		$wide = self::render(fn () => self::$batteries->Journal(self::request('GET', ['months' => '120']), self::response(), []), 'GET /batteriesjournal?months=120');
		self::assertListsRow($wide, 'charge-cycle-' . self::$ids['cycle long ago'] . '-row', 'ten years reaches the 2020 cycle');

		$filtered = self::render(fn () => self::$batteries->Journal(self::request('GET', ['months' => '120', 'battery' => (string)self::$ids['Battery due today']]), self::response(), []), 'GET /batteriesjournal?battery=');
		self::assertListsRow($filtered, 'charge-cycle-' . self::$ids['cycle yesterday'] . '-row', 'the filter keeps the chosen battery\'s cycles');
		self::assertOmitsRow($filtered, 'charge-cycle-' . self::$ids['cycle long ago'] . '-row', 'and drops every other battery\'s');

		$invalid = self::render(fn () => self::$batteries->Journal(self::request('GET', ['months' => 'lots', 'battery' => 'all']), self::response(), []), 'GET /batteriesjournal with unparseable filters');
		self::assertListsRow($invalid, 'charge-cycle-' . self::$ids['cycle today'] . '-row', 'unparseable filters fall back to the defaults rather than refusing');
		self::assertOmitsRow($invalid, 'charge-cycle-' . self::$ids['cycle long ago'] . '-row', 'and the defaults still do not reach 2020');
	}

	public function testBatteryTrackingOffersActiveBatteriesOnly(): void
	{
		$html = self::render(fn () => self::$batteries->TrackChargeCycle(self::request(), self::response(), []), 'GET /batterytracking');
		self::assertStringContainsString('Battery overdue', $html, 'the tracking page offers the batteries that can be charged');
		self::assertStringNotContainsString('Battery retired', $html, 'a retired battery cannot be charged, so it is not offered');
	}

	public function testBatteriesSettingsAndGrocycodeImageRender(): void
	{
		self::render(fn () => self::$batteries->BatteriesSettings(self::request(), self::response(), []), 'GET /batteriessettings');

		$response = self::$batteries->BatteryGrocycodeImage(self::request(), self::response(), ['batteryId' => self::$ids['Battery overdue']]);
		self::assertSame(200, $response->getStatusCode());
		self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
		self::assertStringStartsWith("\x89PNG", (string)$response->getBody());
	}

	// ------------------------------------------------------------------------ tasks

	public function testTaskListFlagsEachTaskByItsDueDateAndHidesDoneOnes(): void
	{
		$html = self::render(fn () => self::$tasks->Overview(self::request(), self::response(), []), 'GET /tasks');

		self::assertStringContainsString('table-danger', self::rowClass($html, 'task-' . self::$ids['Task overdue'] . '-row'), 'a task due in 2020 is overdue');
		self::assertStringContainsString('table-info', self::rowClass($html, 'task-' . self::$ids['Task due today'] . '-row'), 'a task due today is due today');
		self::assertStringContainsString('table-warning', self::rowClass($html, 'task-' . self::$ids['Task due soon'] . '-row'), 'a task due in three days is within the five day window');
		self::assertSame('', trim(self::rowClass($html, 'task-' . self::$ids['Task without due date'] . '-row')), 'a task with no due date is never due');

		self::assertOmitsRow($html, 'task-' . self::$ids['Task already done'] . '-row', 'a completed task is off the list');
	}

	public function testTaskListIncludesDoneTasksWhenAsked(): void
	{
		$html = self::render(fn () => self::$tasks->Overview(self::request('GET', ['include_done' => '']), self::response(), []), 'GET /tasks?include_done');
		self::assertListsRow($html, 'task-' . self::$ids['Task already done'] . '-row', 'include_done adds the completed tasks');
		self::assertListsRow($html, 'task-' . self::$ids['Task overdue'] . '-row', 'and keeps the open ones');
	}

	public function testTaskPagesRefuseACallerWithoutTasksViewAndWriteNothing(): void
	{
		self::grant(['CHORES_VIEW']);
		$before = self::stateSnapshot();

		$this->expectStatus(fn () => self::$tasks->Overview(self::request(), self::response(), []), 403, 'GET /tasks without TASKS_VIEW');
		$this->expectStatus(fn () => self::$tasks->TaskEditForm(self::request(), self::response(), ['taskId' => 'new']), 403, 'GET /task/new without TASKS_VIEW');
		$this->expectStatus(fn () => self::$tasks->TaskCategoriesList(self::request(), self::response(), []), 403, 'GET /taskcategories without TASKS_VIEW');
		$this->expectStatus(fn () => self::$tasks->TaskCategoryEditForm(self::request(), self::response(), ['categoryId' => 'new']), 403, 'GET /taskcategory/new without TASKS_VIEW');
		$this->expectStatus(fn () => self::$tasks->TasksSettings(self::request(), self::response(), []), 403, 'GET /taskssettings without TASKS_VIEW');

		self::assertSame($before, self::stateSnapshot(), 'five refusals leave every table as it was');
	}

	public function testTaskEditFormCarriesTheTaskItEditsAndTheCreateFormCarriesNone(): void
	{
		$edit = self::render(fn () => self::$tasks->TaskEditForm(self::request(), self::response(), ['taskId' => self::$ids['Task overdue']]), 'GET /task/{id}');
		self::assertStringContainsString('Task overdue', $edit);
		self::assertStringNotContainsString('Task due soon', $edit, 'the form carries only the task it edits');
		self::assertStringContainsString('Task category active', $edit, 'and offers the categories a task can be filed under');
		self::assertStringNotContainsString('Task category retired', $edit, 'but not the retired ones');

		$create = self::render(fn () => self::$tasks->TaskEditForm(self::request(), self::response(), ['taskId' => 'new']), 'GET /task/new');
		self::assertStringNotContainsString('Task overdue', $create);
		self::assertStringContainsString('Task category active', $create, 'the create form offers the same categories');
	}

	public function testTaskCategoriesListShowsActiveCategoriesAndOnlyIncludesRetiredOnesWhenAsked(): void
	{
		$default = self::render(fn () => self::$tasks->TaskCategoriesList(self::request(), self::response(), []), 'GET /taskcategories');
		self::assertStringContainsString('Task category active', $default);
		self::assertStringNotContainsString('Task category retired', $default);

		$withDisabled = self::render(fn () => self::$tasks->TaskCategoriesList(self::request('GET', ['include_disabled' => '']), self::response(), []), 'GET /taskcategories?include_disabled');
		self::assertStringContainsString('Task category retired', $withDisabled);
	}

	public function testTaskCategoryEditFormCarriesTheCategoryItEditsAndTasksSettingsRenders(): void
	{
		$edit = self::render(fn () => self::$tasks->TaskCategoryEditForm(self::request(), self::response(), ['categoryId' => self::$ids['Task category active']]), 'GET /taskcategory/{id}');
		self::assertStringContainsString('Task category active', $edit);

		$create = self::render(fn () => self::$tasks->TaskCategoryEditForm(self::request(), self::response(), ['categoryId' => 'new']), 'GET /taskcategory/new');
		self::assertStringNotContainsString('Task category active', $create);

		self::render(fn () => self::$tasks->TasksSettings(self::request(), self::response(), []), 'GET /taskssettings');
	}

	// -------------------------------------------------------------------- equipment

	public function testEquipmentPagesListTheEquipmentAndCarryTheItemTheyEdit(): void
	{
		$overview = self::render(fn () => self::$equipment->Overview(self::request(), self::response(), []), 'GET /equipment');
		self::assertStringContainsString('Equipment listed', $overview);
		self::assertStringContainsString('Equipment second', $overview, 'the overview lists every item');

		$edit = self::render(fn () => self::$equipment->EditForm(self::request(), self::response(), ['equipmentId' => self::$ids['Equipment listed']]), 'GET /equipment/{id}');
		self::assertStringContainsString('The dishwasher', $edit, 'the form carries the item it edits');
		self::assertStringNotContainsString('The oven', $edit, 'and no other item');

		// views/equipmentform.blade.php used to print
		// $equipment->instruction_manual_file_name unguarded, while the three sites around
		// it test it with empty()/!empty() - which tolerate a missing variable. The bare
		// read is now null-coalesced the same way, rather than removed: the instruction
		// manual label is toggled visible by public/viewjs/equipmentform.js's file input
		// "change" handler independent of mode, so it has to stay in the create-mode DOM.
		[$create, $diagnostics] = self::renderCapturingWarnings(fn () => self::$equipment->EditForm(self::request(), self::response(), ['equipmentId' => 'new']), 'GET /equipment/new', E_ALL);
		self::assertStringNotContainsString('The dishwasher', $create);
		self::assertSame([], $diagnostics, 'the equipment create form renders with no PHP diagnostics');
	}

	/** The same recorded read policy as the battery pages - see that test's comment. */
	public function testEquipmentPagesFollowTheRecordedReadPolicyOfNoViewLeaf(): void
	{
		self::grant([]);
		$before = self::stateSnapshot();

		self::render(fn () => self::$equipment->Overview(self::request(), self::response(), []), 'GET /equipment with no grants');
		self::renderCapturingWarnings(fn () => self::$equipment->EditForm(self::request(), self::response(), ['equipmentId' => 'new']), 'GET /equipment/new with no grants');

		self::assertSame($before, self::stateSnapshot());
	}

	// --------------------------------------------------------------------- calendar

	/**
	 * docs/plans/19-rbac.md: "Calendar aggregation filters those domains by the caller's
	 * view leaves." The page is not gated; its contents are.
	 */
	public function testCalendarEventListShrinksWithTheCallersViewLeaves(): void
	{
		$asAdmin = self::calendarEventTitles();
		self::assertNotEmpty($asAdmin, 'an administrator sees the household\'s due dates');
		self::assertTrue(self::titlesContain($asAdmin, 'Chore overdue'), 'a due chore is a calendar event');
		self::assertTrue(self::titlesContain($asAdmin, 'Task overdue'), 'a due task is a calendar event');

		// GUEST holds STOCK_VIEW, RECIPES_VIEW and MEALPLAN_VIEW - neither CHORES_VIEW nor
		// TASKS_VIEW - so the chore and task events must be gone while the page still renders.
		self::grantRole('GUEST');
		$asGuest = self::calendarEventTitles();
		self::assertFalse(self::titlesContain($asGuest, 'Chore overdue'), 'a caller without CHORES_VIEW is not shown the chores');
		self::assertFalse(self::titlesContain($asGuest, 'Task overdue'), 'a caller without TASKS_VIEW is not shown the tasks');
		self::assertTrue(self::titlesContain($asGuest, 'Battery overdue'), 'batteries carry no view leaf, so they stay - the recorded policy in plan 19');
	}

	/** @return string[] The event titles the calendar page hands fullcalendar. */
	private static function calendarEventTitles(): array
	{
		$html = self::render(fn () => self::$calendar->Overview(self::request(), self::response(), []), 'GET /calendar');
		self::assertSame(1, preg_match('/Victual\.FullcalendarEventSources = (.*)\R/', $html, $matches), 'the page embeds the event sources');
		$sources = json_decode(rtrim($matches[1]), true, 512, JSON_THROW_ON_ERROR);

		$titles = [];
		foreach ($sources[0] ?? [] as $event)
		{
			$titles[] = $event['title'];
		}

		return $titles;
	}

	/** @param string[] $titles */
	private static function titlesContain(array $titles, string $needle): bool
	{
		foreach ($titles as $title)
		{
			if (str_contains($title, $needle))
			{
				return true;
			}
		}

		return false;
	}

	// ------------------------------------------------------- user defined entities

	public function testUserentityPagesListTheEntitiesAndCarryTheOneTheyEdit(): void
	{
		$list = self::render(fn () => self::$generic->UserentitiesList(self::request(), self::response(), []), 'GET /userentities');
		self::assertStringContainsString('Household book', $list);
		self::assertStringContainsString('Hidden book', $list, 'the list is the master data list, so it shows entities the sidebar hides');

		// Asserted on the form field rather than on the page text: every userentity whose
		// show_in_sidebar_menu is set is in the sidebar of every page, so "the name appears
		// somewhere" would be true of the create form too.
		$edit = self::render(fn () => self::$generic->UserentityEditForm(self::request(), self::response(), ['userentityId' => self::$ids['householdbook']]), 'GET /userentity/{id}');
		self::assertStringContainsString('value="householdbook"', $edit, 'the edit form is populated from the entity it edits');
		self::assertStringNotContainsString('value="hiddenbook"', $edit, 'and carries no other entity');

		$create = self::render(fn () => self::$generic->UserentityEditForm(self::request(), self::response(), ['userentityId' => 'new']), 'GET /userentity/new');
		self::assertStringNotContainsString('value="householdbook"', $create, 'the create form starts empty');
	}

	public function testUserfieldPagesListTheFieldsAndCarryTheOneTheyEdit(): void
	{
		$list = self::render(fn () => self::$generic->UserfieldsList(self::request(), self::response(), []), 'GET /userfields');
		self::assertStringContainsString('Chore note', $list, 'the list covers every entity\'s fields');
		self::assertStringContainsString('Book page', $list);

		$edit = self::render(fn () => self::$generic->UserfieldEditForm(self::request(), self::response(), ['userfieldId' => self::$ids['userfield bookpage']]), 'GET /userfield/{id}');
		self::assertStringContainsString('Book page', $edit);
		self::assertStringNotContainsString('Chore note', $edit, 'the form carries only the field it edits');

		$create = self::render(fn () => self::$generic->UserfieldEditForm(self::request(), self::response(), ['userfieldId' => 'new']), 'GET /userfield/new');
		self::assertStringNotContainsString('Book page', $create);
	}

	public function testUserobjectPagesListOnlyTheChosenEntitysObjects(): void
	{
		$list = self::render(fn () => self::$generic->UserobjectsList(self::request(), self::response(), ['userentityName' => 'householdbook']), 'GET /userobjects/{name}');
		self::assertStringContainsString('Household book', $list, 'the list is titled by the entity it lists');
		self::assertStringContainsString('data-userobject-id="' . self::$ids['userobject listed'] . '"', $list, 'and lists that entity\'s object');
		self::assertStringNotContainsString('data-userobject-id="' . self::$ids['userobject elsewhere'] . '"', $list, 'and not another entity\'s');

		$edit = self::render(fn () => self::$generic->UserobjectEditForm(self::request(), self::response(), ['userentityName' => 'householdbook', 'userobjectId' => self::$ids['userobject listed']]), 'GET /userobject/{name}/{id}');
		self::assertStringContainsString('Book page', $edit, 'the form offers the entity\'s userfields');

		$create = self::render(fn () => self::$generic->UserobjectEditForm(self::request(), self::response(), ['userentityName' => 'householdbook', 'userobjectId' => 'new']), 'GET /userobject/{name}/new');
		self::assertStringContainsString('Book page', $create);
	}

	/** The same recorded read policy as the battery pages - see that test's comment. */
	public function testUserentityPagesFollowTheRecordedReadPolicyOfNoViewLeaf(): void
	{
		self::grant([]);
		$before = self::stateSnapshot();

		self::render(fn () => self::$generic->UserentitiesList(self::request(), self::response(), []), 'GET /userentities with no grants');
		self::render(fn () => self::$generic->UserfieldsList(self::request(), self::response(), []), 'GET /userfields with no grants');
		self::render(fn () => self::$generic->UserobjectsList(self::request(), self::response(), ['userentityName' => 'householdbook']), 'GET /userobjects with no grants');

		self::assertSame($before, self::stateSnapshot());
	}

	// ------------------------------------------------------------------------ users

	public function testUsersListShowsEveryUserAndIsGatedOnUsersRead(): void
	{
		$html = self::render(fn () => self::$users->UsersList(self::request(), self::response(), []), 'GET /users');
		self::assertStringContainsString('household-caller', $html);
		self::assertStringContainsString('household-other', $html, 'the management list is every user, not just the caller');

		self::grant(['CHORES_VIEW']);
		$before = self::stateSnapshot();
		$this->expectStatus(fn () => self::$users->UsersList(self::request(), self::response(), []), 403, 'GET /users without USERS_READ');
		self::assertSame($before, self::stateSnapshot(), 'the refusal wrote nothing');
	}

	public function testUserEditFormAsksForTheRightLeafForCreateSelfAndOthers(): void
	{
		$self = self::render(fn () => self::$users->UserEditForm(self::request(), self::response(), ['userId' => 9000]), 'GET /user/9000');
		self::assertStringContainsString('household-caller', $self, 'the form carries the user it edits');
		self::assertStringNotContainsString('household-other', $self, 'and nobody else');

		$other = self::render(fn () => self::$users->UserEditForm(self::request(), self::response(), ['userId' => 9001]), 'GET /user/9001');
		self::assertStringContainsString('household-other', $other);

		// views/userform.blade.php used to print $user->picture_file_name unguarded, where
		// the lines around it use empty()/!empty(). The bare read is now null-coalesced
		// the same way, rather than removed: public/viewjs/userform.js's file input
		// "change" handler toggles the picture label visible independent of mode, so it
		// has to stay in the create-mode DOM.
		[, $diagnostics] = self::renderCapturingWarnings(fn () => self::$users->UserEditForm(self::request(), self::response(), ['userId' => 'new']), 'GET /user/new', E_ALL);
		self::assertSame([], $diagnostics, 'the user create form renders with no PHP diagnostics');

		// USERS_EDIT_SELF alone: own form yes, somebody else's no, creating no.
		self::grant(['USERS_EDIT_SELF']);
		self::render(fn () => self::$users->UserEditForm(self::request(), self::response(), ['userId' => 9000]), 'GET /user/9000 with USERS_EDIT_SELF');
		$this->expectStatus(fn () => self::$users->UserEditForm(self::request(), self::response(), ['userId' => 9001]), 403, 'GET /user/9001 with USERS_EDIT_SELF only');
		$this->expectStatus(fn () => self::$users->UserEditForm(self::request(), self::response(), ['userId' => 'new']), 403, 'GET /user/new with USERS_EDIT_SELF only');
	}

	public function testUserEditFormRefusalsWriteNothing(): void
	{
		self::grant([]);
		$before = self::stateSnapshot();

		$this->expectStatus(fn () => self::$users->UserEditForm(self::request(), self::response(), ['userId' => 'new']), 403, 'GET /user/new with no grants');
		$this->expectStatus(fn () => self::$users->UserEditForm(self::request(), self::response(), ['userId' => 9000]), 403, 'GET /user/9000 with no grants');
		$this->expectStatus(fn () => self::$users->UserEditForm(self::request(), self::response(), ['userId' => 9001]), 403, 'GET /user/9001 with no grants');

		self::assertSame($before, self::stateSnapshot());
	}

	public function testPermissionListRendersTheTargetsGrantsAndSaysWhetherTheCallerMayEdit(): void
	{
		// Give the target a grant of its own, so the page has something true to report.
		// EQUIPMENT is not in the CHILD role and TASKS_VIEW is, so granting both directly
		// gives the page one leaf held only directly and one held both ways.
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9001, id FROM permission_hierarchy WHERE name IN ('EQUIPMENT', 'TASKS_VIEW')");
		// And a role, so the page has both kinds of grant to report - the direct one and
		// the one held through a role, which are separate lists on that page.
		$childRole = (int)self::$db->query("SELECT id FROM roles WHERE code = 'CHILD'")->fetchColumn();
		self::$db->exec('INSERT INTO user_roles(user_id, role_id) VALUES (9001, ' . $childRole . ')');

		try
		{
			$html = self::render(fn () => self::$users->PermissionList(self::request(), self::response(), ['userId' => 9001]), 'GET /user/9001/permissions');
			self::assertStringContainsString('household-other', $html, 'the page names the user whose permissions it shows');

			// The page's whole job: saying, per permission, whether this user holds it
			// directly or through a role. Both are true of 9001 and of different leaves.
			self::assertSame(['direct' => '1', 'inherited' => '0'], self::permissionCheckbox($html, 'EQUIPMENT'), 'EQUIPMENT was granted to 9001 directly and by no role');
			self::assertSame(['direct' => '0', 'inherited' => '1'], self::permissionCheckbox($html, 'CHORES_VIEW'), 'CHORES_VIEW reaches 9001 through the CHILD role only');
			self::assertSame(['direct' => '1', 'inherited' => '1'], self::permissionCheckbox($html, 'TASKS_VIEW'), 'TASKS_VIEW reaches 9001 both ways, and the page says so - that overlap is what survives removing the role');
			self::assertSame(['direct' => '0', 'inherited' => '0'], self::permissionCheckbox($html, 'ADMIN'), 'and 9001 holds ADMIN neither way');

			// USERS_READ alone opens the page; it does not make the boxes editable, which is
			// the ADMIN-versus-USERS_READ mismatch docs/plans/19-rbac.md question 9 closed.
			self::grant(['USERS_READ']);
			$readOnly = self::render(fn () => self::$users->PermissionList(self::request(), self::response(), ['userId' => 9001]), 'GET /user/9001/permissions with USERS_READ');
			self::assertStringContainsString('household-other', $readOnly);
			self::assertStringContainsString('disabled', explode('<h3', $readOnly)[0], 'a caller who may read but not edit gets the controls disabled');

			self::grant(['CHORES_VIEW']);
			$this->expectStatus(fn () => self::$users->PermissionList(self::request(), self::response(), ['userId' => 9001]), 403, 'GET /user/9001/permissions without USERS_READ');
		}
		finally
		{
			self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9001; DELETE FROM user_roles WHERE user_id = 9001');
		}
	}

	public function testRolePagesListTheSeededRolesAndCarryTheOneTheyEdit(): void
	{
		// Asserted on the per-role link the list builds, not on the role code as text:
		// every page embeds the caller's own resolved permissions, so "ADMIN appears
		// somewhere" is true of all of them.
		$list = self::render(fn () => self::$users->RolesList(self::request(), self::response(), []), 'GET /roles');
		foreach (['ADMIN', 'ADULT', 'CHILD', 'GUEST'] as $code)
		{
			$id = (int)self::$db->query("SELECT id FROM roles WHERE code = '$code'")->fetchColumn();
			self::assertStringContainsString('/role/' . $id . '"', $list, "the list links to the $code role, which a fresh household has");
		}

		$roleId = (int)self::$db->query("SELECT id FROM roles WHERE code = 'CHILD'")->fetchColumn();
		$edit = self::render(fn () => self::$users->RoleEditForm(self::request(), self::response(), ['roleId' => (string)$roleId]), 'GET /role/{id}');
		self::assertStringContainsString('value="CHILD"', $edit, 'the role form is populated from the role it edits');

		$create = self::render(fn () => self::$users->RoleEditForm(self::request(), self::response(), ['roleId' => 'new']), 'GET /role/new');
		self::assertStringNotContainsString('value="CHILD"', $create, 'and the create form starts empty');

		self::grant(['CHORES_VIEW']);
		$this->expectStatus(fn () => self::$users->RolesList(self::request(), self::response(), []), 403, 'GET /roles without USERS_READ');
		$this->expectStatus(fn () => self::$users->RoleEditForm(self::request(), self::response(), ['roleId' => 'new']), 403, 'GET /role/new without USERS_READ');
	}

	/**
	 * /usersettings is every user's own page: plan 19 gates the management views, not this
	 * one, so a caller holding nothing still reaches their own settings.
	 */
	public function testUserSettingsIsReachableByAnyCallerAndOffersTheInstalledLanguages(): void
	{
		self::grant([]);
		$html = self::render(fn () => self::$users->UserSettings(self::request(), self::response(), []), 'GET /usersettings with no grants');
		// The list is scandir() over localization/, so what it offers is what is installed.
		$installed = array_values(array_filter(scandir(VICTUAL_ROOT_PATH . '/localization'), fn ($item) => $item !== '.' && $item !== '..' && is_dir(VICTUAL_ROOT_PATH . '/localization/' . $item)));
		self::assertContains('en', $installed, 'the repository ships en, or this asserts nothing');
		foreach ($installed as $language)
		{
			self::assertStringContainsString('<option value="' . $language . '"', $html, "the settings page offers the installed language $language");
		}
		self::assertStringNotContainsString('<option value="kl_GL"', $html, 'and offers nothing that is not installed');
	}

	// ------------------------------------------------------------- label designer

	public function testLabelTemplatePagesAreAdministrationOnly(): void
	{
		$list = self::render(fn () => self::$labelTemplates->TemplatesList(self::request(), self::response(), []), 'GET /labeltemplates');
		self::assertStringContainsString('Template listed', $list);
		self::assertStringContainsString('Template second', $list, 'the list is every template');

		$editor = self::render(fn () => self::$labelTemplates->TemplateEditor(self::request(), self::response(), ['templateId' => (string)self::$ids['Template listed']]), 'GET /labeltemplate/{id}');
		self::assertStringContainsString('Template listed', $editor, 'the editor carries the template it opens');
		self::assertStringNotContainsString('Template second', $editor, 'and no other');

		self::grant(['USERS_READ']);
		$before = self::stateSnapshot();
		$this->expectStatus(fn () => self::$labelTemplates->TemplatesList(self::request(), self::response(), []), 403, 'GET /labeltemplates without ADMIN');
		$this->expectStatus(fn () => self::$labelTemplates->TemplateEditor(self::request(), self::response(), ['templateId' => (string)self::$ids['Template listed']]), 403, 'GET /labeltemplate/{id} without ADMIN');
		self::assertSame($before, self::stateSnapshot(), 'the refusals wrote nothing');
	}

	/**
	 * Opening a template id that does not exist answers 404, as
	 * LabelTemplatesController::TemplateEditor() throws a Slim HttpNotFoundException for
	 * it - the idiom the other page controllers extending BaseController use - rather than
	 * calling GenericErrorResponse(), which is defined on BaseApiController and not on
	 * BaseController.
	 */
	public function testTemplateEditorForAnUnknownTemplateAnswers404(): void
	{
		$this->expectStatus(
			fn () => self::$labelTemplates->TemplateEditor(self::request(), self::response(), ['templateId' => '999999']),
			404,
			'GET /labeltemplate/{id} for an id that does not exist'
		);
	}

	public function testLabelPrintJobPagesAreAdministrationOnly(): void
	{
		self::render(fn () => self::$labelPrintJobs->Index(self::request(), self::response(), []), 'GET /labelprintjobs');
		self::render(fn () => self::$labelPrintJobs->Printers(self::request(), self::response(), []), 'GET /labelprinters');

		self::grant(['USERS_READ']);
		$before = self::stateSnapshot();
		$this->expectStatus(fn () => self::$labelPrintJobs->Index(self::request(), self::response(), []), 403, 'GET /labelprintjobs without ADMIN');
		$this->expectStatus(fn () => self::$labelPrintJobs->Printers(self::request(), self::response(), []), 403, 'GET /labelprinters without ADMIN');
		self::assertSame($before, self::stateSnapshot());
	}

	// ------------------------------------------------------------------------ login

	public function testLoginPageRendersForAnAuthenticatedCaller(): void
	{
		$html = self::render(fn () => self::$login->LoginPage(self::request(), self::response(), []), 'GET /login');
		self::assertStringContainsString('name="username"', $html, 'the login page is a credential form whatever the caller is');
	}

	/**
	 * The login form for a caller who has not authenticated. It has to be a process of its
	 * own: VICTUAL_AUTHENTICATED is a constant the harness fixes at true, and whether the
	 * page renders for somebody who is not authenticated is exactly the question.
	 */
	public function testLoginPageRendersForAnUnauthenticatedCaller(): void
	{
		$answer = self::sendThroughTheStack('GET', '/login');

		self::assertSame(200, $answer['status'], 'the login page is reachable without a session, or nobody could ever log in');
		self::assertStringContainsString('name="username"', $answer['body']);
	}

	public function testProcessLoginWithValidCredentialsCreatesASessionAndRedirectsHome(): void
	{
		$password = 'household-login-password';
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9002, 'household-login', '" . password_hash($password, PASSWORD_ARGON2ID) . "')");

		try
		{
			$before = (int)self::$db->query('SELECT COUNT(*) FROM sessions WHERE user_id = 9002')->fetchColumn();

			$response = self::$login->ProcessLogin(self::request('POST', [], ['username' => 'household-login', 'password' => $password]), self::response(), []);

			self::assertSame(302, $response->getStatusCode(), 'a valid login is a redirect');
			self::assertSame('http://localhost/', $response->getHeaderLine('Location'), 'and it lands on the root page');
			self::assertSame($before + 1, (int)self::$db->query('SELECT COUNT(*) FROM sessions WHERE user_id = 9002')->fetchColumn(), 'a successful login leaves a session row behind');
		}
		finally
		{
			self::$db->exec('DELETE FROM sessions WHERE user_id = 9002');
			self::$db->exec('DELETE FROM users WHERE id = 9002');
		}
	}

	public function testProcessLoginAcceptsABase64EncodedPassword(): void
	{
		$password = 'household-login-password';
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9003, 'household-login-b64', '" . password_hash($password, PASSWORD_ARGON2ID) . "')");

		try
		{
			$response = self::$login->ProcessLogin(self::request('POST', [], ['username' => 'household-login-b64', 'password_base64' => base64_encode($password)]), self::response(), []);

			self::assertSame('http://localhost/', $response->getHeaderLine('Location'), 'password_base64 is decoded and accepted like a plain password');
			self::assertSame(1, (int)self::$db->query('SELECT COUNT(*) FROM sessions WHERE user_id = 9003')->fetchColumn());
		}
		finally
		{
			self::$db->exec('DELETE FROM sessions WHERE user_id = 9003');
			self::$db->exec('DELETE FROM users WHERE id = 9003');
		}
	}

	public function testProcessLoginWithABadCredentialSendsTheCallerBackAndCreatesNoSession(): void
	{
		$password = 'household-login-password';
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9004, 'household-login-bad', '" . password_hash($password, PASSWORD_ARGON2ID) . "')");

		try
		{
			foreach ([
				'wrong password' => ['username' => 'household-login-bad', 'password' => 'not the password'],
				'unknown username' => ['username' => 'nobody-by-that-name', 'password' => $password],
				'empty password' => ['username' => 'household-login-bad', 'password' => ''],
				'nothing at all' => [],
			] as $what => $body)
			{
				$response = self::$login->ProcessLogin(self::request('POST', [], $body), self::response(), []);

				self::assertSame(302, $response->getStatusCode(), "$what is answered with a redirect");
				self::assertSame('http://localhost/login?invalid=true', $response->getHeaderLine('Location'), "$what sends the caller back to the form, flagged invalid");
				self::assertSame(0, (int)self::$db->query('SELECT COUNT(*) FROM sessions WHERE user_id = 9004')->fetchColumn(), "$what creates no session");
			}
		}
		finally
		{
			self::$db->exec('DELETE FROM users WHERE id = 9004');
		}
	}

	public function testLogoutDeletesTheSessionRowAndIsHarmlessWhenThereIsNoCookie(): void
	{
		self::$db->exec("INSERT INTO sessions(session_key, user_id, expires) VALUES ('household-logout-key', 9000, now() + interval '1 day')");

		$response = self::$login->Logout(
			self::request('POST')->withCookieParams([\Victual\Services\SessionService::SESSION_COOKIE_NAME => 'household-logout-key']),
			self::response(),
			[]
		);

		self::assertSame(302, $response->getStatusCode());
		self::assertSame('http://localhost/', $response->getHeaderLine('Location'), 'logging out lands on the root page');
		self::assertSame(0, (int)self::$db->query("SELECT COUNT(*) FROM sessions WHERE session_key = 'household-logout-key'")->fetchColumn(), 'the session row is gone');

		// Logging out twice is a thing people do; the second one carries no cookie.
		$again = self::$login->Logout(self::request('POST'), self::response(), []);
		self::assertSame(302, $again->getStatusCode(), 'a logout without a cookie is not an error');
	}

	// ----------------------------------------------------------------------- system

	/**
	 * The about page is where an operator reads what they are running, so the values it
	 * reports have to be the live ones: the version from version.json and the highest
	 * migration this schema has actually applied.
	 */
	public function testAboutPageReportsTheRealVersionAndTheRealMigrationLevel(): void
	{
		$html = self::render(fn () => self::$system->About(self::request(), self::response(), []), 'GET /about');

		$version = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/version.json'));
		self::assertStringContainsString('<code>' . $version->Version . '</code>', $html, 'the reported version is the installed one');
		self::assertStringContainsString('<code>' . $version->ReleaseDate . '</code>', $html);

		$migration = (int)self::$db->query('SELECT MAX(migration) FROM migrations')->fetchColumn();
		self::assertGreaterThan(0, $migration, 'the schema this class migrated has migrations in it');
		self::assertStringContainsString('<code>' . $migration . '</code>', $html, 'the reported database version is the schema\'s own highest migration');

		self::assertStringContainsString('<code>PostgreSQL ', $html, 'and the engine it reports is the one actually serving it (ADR-0008)');
		self::assertStringContainsString('<code>' . phpversion() . '</code>', $html);
	}

	public function testAboutPageCarriesTheChangelogNewestFirst(): void
	{
		$html = self::render(fn () => self::$system->About(self::request(), self::response(), []), 'GET /about');

		$files = array_values(array_filter(glob(VICTUAL_ROOT_PATH . '/changelog/*.md'), fn ($f) => basename($f) !== '__TEMPLATE.md'));
		self::assertNotEmpty($files, 'the repository ships a changelog, or this asserts nothing');

		$numbers = array_map(fn ($f) => intval(explode('_', basename($f))[0]), $files);
		$newest = explode('_', basename($files[array_search(max($numbers), $numbers, true)]));

		self::assertStringContainsString($newest[1], $html, 'the newest release version is on the page');
		self::assertSame(count($files), substr_count($html, 'Released on <span'), 'every changelog entry is rendered');
	}

	public function testBarcodeScannerTestingPageRenders(): void
	{
		self::render(fn () => self::$system->BarcodeScannerTesting(self::request(), self::response(), []), 'GET /barcodescannertesting');
	}

	/**
	 * The manifest is the PWA descriptor a browser installs from; its start_url and name
	 * come from the base64 "data" parameter the page that links to it built.
	 */
	public function testManifestIsJsonBuiltFromTheEncodedParameter(): void
	{
		$response = self::$system->Manifest(self::request('GET', ['data' => base64_encode('Household#/choresoverview')]), self::response(), []);

		self::assertSame(200, $response->getStatusCode());
		self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

		$manifest = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('Victual Household', $manifest['name'], 'the suffix comes from the first half of the encoded pair');
		self::assertSame('/choresoverview', $manifest['start_url'], 'and the start URL from the second');
		self::assertSame('standalone', $manifest['display'], 'a PWA descriptor, not a browser tab');
		self::assertNotEmpty($manifest['icons'][0]['src']);
	}

	/**
	 * GET / chooses where a caller lands. VICTUAL_ENTRY_PAGE is 'stock' here, so an
	 * administrator goes to the stock overview and somebody who may not see stock is sent
	 * to /about rather than to a page that would refuse them.
	 */
	public function testRootRedirectsToTheEntryPageTheCallerMayActuallyView(): void
	{
		$response = self::$system->Root(self::request(), self::response(), []);
		self::assertSame(302, $response->getStatusCode(), 'the root route is a redirect');
		self::assertSame('http://localhost/stockoverview', $response->getHeaderLine('Location'));

		self::grant(['CHORES_VIEW']);
		$fallback = self::$system->Root(self::request(), self::response(), []);
		self::assertSame('http://localhost/about', $fallback->getHeaderLine('Location'), 'a caller without STOCK_VIEW is not sent to the stock overview');
	}

	public function testRootWritesNothingInProductionMode(): void
	{
		// VICTUAL_MODE is 'production' and VICTUAL_MIGRATE_ON_ROOT_REQUEST is false here,
		// which is the combination a real installation runs: opening the root page must not
		// generate demo data and must not migrate.
		$before = self::stateSnapshot();
		$migrationBefore = (int)self::$db->query('SELECT MAX(migration) FROM migrations')->fetchColumn();

		self::$system->Root(self::request(), self::response(), []);

		self::assertSame($before, self::stateSnapshot(), 'the root page is a redirect, not a seeder');
		self::assertSame($migrationBefore, (int)self::$db->query('SELECT MAX(migration) FROM migrations')->fetchColumn(), 'and it does not migrate');
	}

	// ------------------------------------------------------------------ subprocess

	/**
	 * One request through the real middleware stack, for the cases that need a caller
	 * identity the harness process cannot have. Shape from WireContractTest.php:190-233.
	 *
	 * @return array{status: int, body: string}
	 */
	private static function sendThroughTheStack(string $method, string $path, array $extraEnv = [], ?string $cookie = null): array
	{
		$spec = ['method' => $method, 'path' => $path];

		if ($cookie !== null)
		{
			$spec['cookie'] = $cookie;
		}

		// $_SERVER carries non-scalar entries (argv among them) that proc_open's env
		// conversion cannot stringify.
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
		], $extraEnv);

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))],
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

	/**
	 * GET / through the whole stack, with the entry page and any other setting the
	 * scenario needs supplied as environment variables - which is how Setting() takes an
	 * override (helpers/extensions.php:400). tests/Pgsql/root-subprocess-helper.php is the
	 * existing helper for exactly this and prints the Location header this asserts on.
	 *
	 * @return array{status: int, location: string}
	 */
	private static function getRootThroughTheStack(?string $sessionKey, array $extraEnv = []): array
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
		], $extraEnv);

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
		proc_close($process);

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, "the root helper printed no JSON. stdout: $output\nstderr: $errors");

		return $result;
	}

	/**
	 * Every entry page a household can configure. Each branch of GetEntryPageRelative()
	 * is gated on its own leaf as well as on its feature flag, so a household that
	 * configures one gets it, and a caller who may not see it is sent to /about rather
	 * than to a page that would refuse them.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function gatedEntryPages(): array
	{
		return [
			'stock' => ['stock', '/stockoverview', 'STOCK_VIEW'],
			'shoppinglist' => ['shoppinglist', '/shoppinglist', 'SHOPPINGLIST_VIEW'],
			'recipes' => ['recipes', '/recipes', 'RECIPES_VIEW'],
			'chores' => ['chores', '/choresoverview', 'CHORES_VIEW'],
			'tasks' => ['tasks', '/tasks', 'TASKS_VIEW'],
			'batteries' => ['batteries', '/batteriesoverview', 'BATTERIES'],
			'equipment' => ['equipment', '/equipment', 'EQUIPMENT'],
			'calendar' => ['calendar', '/calendar', 'CALENDAR'],
			'mealplan' => ['mealplan', '/mealplan', 'MEALPLAN_VIEW'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('gatedEntryPages')]
	public function testConfiguredEntryPageIsHonouredForACallerWhoMayViewItAndNotForOneWhoMayNot(string $entryPage, string $target, string $leaf): void
	{
		$granted = self::getRootThroughTheStack('household-entry-admin', ['VICTUAL_ENTRY_PAGE' => $entryPage]);
		self::assertSame(302, $granted['status']);
		self::assertSame('http://localhost' . $target, $granted['location'], "$entryPage: a caller holding $leaf lands on it");

		$refused = self::getRootThroughTheStack('household-entry-narrow', ['VICTUAL_ENTRY_PAGE' => $entryPage]);
		self::assertSame(302, $refused['status']);
		self::assertSame('http://localhost/about', $refused['location'], "$entryPage: a caller without $leaf is sent to /about instead");
	}

	/**
	 * MIGRATE_ON_ROOT_REQUEST is the documented escape hatch for an installation with no
	 * init step to run bin/victual-migrate from (SystemController::Root()'s own comment).
	 * On an already migrated schema it must be a no-op that still serves the redirect -
	 * that is what makes the setting safe to leave on.
	 */
	public function testRootMigratesOnRequestWhenConfiguredToAndStillRedirects(): void
	{
		$before = (int)self::$db->query('SELECT MAX(migration) FROM migrations')->fetchColumn();

		$answer = self::getRootThroughTheStack('household-entry-admin', ['VICTUAL_MIGRATE_ON_ROOT_REQUEST' => '1']);

		self::assertSame(302, $answer['status'], 'migrating on the way through does not change what the route answers');
		self::assertSame('http://localhost/stockoverview', $answer['location']);
		self::assertSame($before, (int)self::$db->query('SELECT MAX(migration) FROM migrations')->fetchColumn(), 'and an already migrated schema is left alone');
	}

	/**
	 * Plan 32 moved chores and batteries onto the label subsystem, so with
	 * FEATURE_FLAG_LABELS on and a printer configured their pages offer a print action,
	 * and with it off they do not. The flag is a constant, so the "on" half is a process
	 * of its own.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function labelPrintingPages(): array
	{
		return [
			'chores overview' => ['/choresoverview', 'chore-label-print'],
			'batteries overview' => ['/batteriesoverview', 'battery-label-print'],
			'chore form' => ['/chore/%chore%', 'chore-form-printer'],
			'battery form' => ['/battery/%battery%', 'battery-form-printer'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('labelPrintingPages')]
	public function testChoreAndBatteryPagesOfferPrintingOnlyWhenLabelsAreEnabled(string $path, string $marker): void
	{
		$path = str_replace(['%chore%', '%battery%'], [(string)self::$ids['Chore overdue'], (string)self::$ids['Battery overdue']], $path);

		$off = self::sendThroughTheStack('GET', $path, [], 'household-entry-admin');
		self::assertSame(200, $off['status'], "GET $path renders with labels off");
		self::assertStringNotContainsString($marker, $off['body'], "$path offers no print action while FEATURE_FLAG_LABELS is off");

		$on = self::sendThroughTheStack('GET', $path, ['VICTUAL_FEATURE_FLAG_LABELS' => '1'], 'household-entry-admin');
		self::assertSame(200, $on['status'], "GET $path renders with labels on");
		self::assertStringContainsString($marker, $on['body'], "$path offers the configured printer once FEATURE_FLAG_LABELS is on");
		self::assertStringContainsString('Household label printer', $on['body'], 'and names the printer it would print to');
	}

	/**
	 * docs/grocycode.md: DataMatrix is the default and Code128 is the documented
	 * alternative, chosen by GROCYCODE_TYPE. Both must produce a servable PNG for the same
	 * reference, and they must not produce the same one - a household that switches
	 * symbology has switched something.
	 */
	public function testGrocycodeSymbologyFollowsTheConfiguredType(): void
	{
		$default = (string)self::$chores->ChoreGrocycodeImage(self::request(), self::response(), ['choreId' => self::$ids['Chore overdue']])->getBody();

		$twoD = self::renderGrocycodeInProcessWith('2D', self::$ids['Chore overdue']);
		self::assertSame(hash('sha256', $default), $twoD['sha256'], 'GROCYCODE_TYPE is 2D by default, so the configured render matches the one this process made');

		$oneD = self::renderGrocycodeInProcessWith('1D', self::$ids['Chore overdue']);
		self::assertSame(200, $oneD['status'], 'Code128 is a supported symbology, not an error');
		self::assertSame('image/png', $oneD['content_type'], 'and it is served as a PNG like the other');
		self::assertSame(base64_encode("\x89PNG\r\n\x1a\n"), $oneD['magic'], 'a real PNG, by its signature');
		self::assertNotSame($twoD['sha256'], $oneD['sha256'], 'Code128 and DataMatrix are different pictures of the same reference');
	}

	/**
	 * One Grocycode render under a chosen GROCYCODE_TYPE. Its own process because that
	 * setting is a constant; see tests/Pgsql/householdpages-subprocess-helper.php.
	 *
	 * @return array{status: int, content_type: string, length: int, sha256: string, magic: string}
	 */
	private static function renderGrocycodeInProcessWith(string $type, int $choreId): array
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
			'VICTUAL_GROCYCODE_TYPE' => $type,
		]);

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/householdpages-subprocess-helper.php', (string)$choreId],
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
		self::assertIsArray($result, "the grocycode helper printed no JSON for $type. stdout: $output\nstderr: $errors");

		return $result;
	}


	/**
	 * In demo, dev or prerelease mode the root route seeds the demo household on the way
	 * through - and ?nodemodata is the documented way to say "not on this instance", which
	 * marks the database so nothing seeds it later either (migration -1). The refusal has
	 * to leave the household empty, which is the whole point of the parameter.
	 */
	public function testRootInDemoModeHonoursNodemodataAndSeedsNothing(): void
	{
		$products = (int)self::$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
		$locations = (int)self::$db->query('SELECT COUNT(*) FROM locations')->fetchColumn();

		$answer = self::sendThroughTheStack('GET', '/?nodemodata', ['VICTUAL_MODE' => 'demo']);

		self::assertSame(302, $answer['status'], 'the root route still redirects in demo mode');
		self::assertSame($products, (int)self::$db->query('SELECT COUNT(*) FROM products')->fetchColumn(), 'nodemodata means no demo products');
		self::assertSame($locations, (int)self::$db->query('SELECT COUNT(*) FROM locations')->fetchColumn(), 'and no demo locations');
		self::assertSame(1, (int)self::$db->query('SELECT COUNT(*) FROM migrations WHERE migration = -1')->fetchColumn(), 'the decision is recorded as migration -1, so a later request does not seed either');
	}
}
