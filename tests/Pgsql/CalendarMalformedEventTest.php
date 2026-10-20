<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\CalendarApiController;
use Victual\Services\BaseService;
use Victual\Services\CalendarService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #989: the iCal feed's 500 for an event without event_type/entity_id (#511) must not
 * carry the event's title, which is user-supplied text. The title goes to the server log,
 * JSON-encoded so a newline in it cannot forge a second log line.
 *
 * No real event source produces such an event; that is why the branch is a 500. The test
 * replaces CalendarService's singleton with one that does, and calls the controller in
 * process, as StockUndoIntegrityTest does.
 */
class CalendarMalformedEventTest extends PgsqlSchemaTestCase
{
	private const TITLE = "Private title\nVictual: forged line";

	private const MESSAGE = 'Calendar event is missing event_type/entity_id required for a stable UID (#511)';

	private static PDO $db;

	private static \DI\Container $container;

	/** Creates the admin user that direct controller calls act as (VICTUAL_USER_ID 9000). */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'calendar-malformed-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");
	}

	/** The 500 body is the fixed message only, and the log line carries the title, encoded. */
	public function testMalformedEventAnswersFixedMessageAndLogsTheTitle(): void
	{
		$malformed = new class extends CalendarService
		{
			/** Returns one all-day event with a title but no event_type or entity_id. */
			public function GetEvents()
			{
				return [['title' => CalendarMalformedEventTest::TitleForStub(), 'start' => '2028-01-01', 'date_format' => 'date']];
			}
		};

		$instances = new ReflectionProperty(BaseService::class, 'Instances');
		$original = $instances->getValue();
		$log = tempnam(sys_get_temp_dir(), 'victual-calendar-log-');
		$previousLog = ini_set('error_log', $log);
		$instances->setValue(null, [CalendarService::class => $malformed] + $original);

		try
		{
			$request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api/calendar/ical');
			$response = (new CalendarApiController(self::$container))->Ical($request, new Response(), []);
		}
		finally
		{
			$instances->setValue(null, $original);
			ini_set('error_log', $previousLog === false ? '' : $previousLog);
		}

		$body = (string)$response->getBody();
		$logged = (string)file_get_contents($log);
		unlink($log);

		self::assertSame(500, $response->getStatusCode(), $body);
		self::assertSame(self::MESSAGE, json_decode($body, true)['error_message'] ?? null, $body);
		self::assertStringNotContainsString('Private title', $body, 'The response must not carry the title');

		self::assertStringContainsString('"title":"Private title\\nVictual: forged line"', $logged, 'The log must carry the title, JSON-encoded');
		self::assertDoesNotMatchRegularExpression('/^Victual: forged line/m', $logged, 'A newline in the title must not start a log line');
	}

	/** Gives the anonymous stub the title constant, which it cannot read through self::. */
	public static function TitleForStub(): string
	{
		return self::TITLE;
	}
}
