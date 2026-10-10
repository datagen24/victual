<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ConsumptionException;
use Victual\Services\ConsumptionRecipeService;
use Victual\Services\ConsumptionRefillService;
use Victual\Services\DatabaseService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0042 as ConsumptionRefillService enforces it, against a real PostgreSQL schema: what a fill,
 * a rule, an explicit date, an order and a notice do, who may do it, and what none of them may touch.
 * The arithmetic itself is RefillEstimatorTest. Every call names the acting user, because
 * VICTUAL_USER_ID is one constant per process.
 *
 * Fixtures: an owner; a member holding `read` only; an editor holding `edit`; a member with an `edit`
 * share and no STOCK_VIEW; an unrelated member; an account manager (USERS_EDIT); an administrator
 * (ADMIN). The last two hold the global permissions a stock user holds and no share, so every answer
 * they get is the answer an unrelated member gets.
 *
 * Dates are pinned. "Today" is always a parameter, never the process clock, except in the one case
 * that asks what the server does when the client sends none.
 */
class ConsumptionRefillServiceTest extends PgsqlSchemaTestCase
{
	private const OWNER = 9401;
	private const READER = 9402;
	private const EDITOR = 9403;
	private const OTHER = 9404;
	private const MANAGER = 9405;
	private const ADMIN = 9406;
	private const NO_STOCK_VIEW = 9407;

	private static PDO $db;
	private static ConsumptionRefillService $service;
	private static ConsumptionRecipeService $recipes;
	private static int $tablet;
	private static int $organizerA;
	private static int $organizerB;
	private static int $product;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$service = ConsumptionRefillService::GetInstance();
		self::$recipes = ConsumptionRecipeService::GetInstance();

		$users = [self::OWNER => 'owner', self::READER => 'reader', self::EDITOR => 'editor', self::OTHER => 'other', self::MANAGER => 'manager', self::ADMIN => 'admin', self::NO_STOCK_VIEW => 'nostock'];
		foreach ($users as $id => $name)
		{
			self::$db->exec("INSERT INTO users (id, username, password) VALUES ($id, 'rf-$name', 'fixture')");
		}
		foreach ([self::OWNER, self::READER, self::EDITOR, self::OTHER, self::MANAGER] as $id)
		{
			self::grant($id, ['STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT', 'STOCK_TRANSFER']);
		}
		self::grant(self::MANAGER, ['USERS_EDIT']);
		self::grant(self::ADMIN, ['ADMIN']);

		self::$tablet = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('RF tablet') RETURNING id")->fetchColumn();
		self::$organizerA = (int)self::$db->query("INSERT INTO locations (name) VALUES ('RF organizer A') RETURNING id")->fetchColumn();
		self::$organizerB = (int)self::$db->query("INSERT INTO locations (name) VALUES ('RF organizer B') RETURNING id")->fetchColumn();

		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute(['RF product', self::$organizerA, self::$tablet, self::$tablet, self::$tablet, self::$tablet]);
		self::$product = (int)$statement->fetchColumn();
		StockService::GetInstance()->AddProduct(self::$product, 90, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$organizerA);
	}

	private static function grant(int $userId, array $names): void
	{
		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?');
		foreach ($names as $name)
		{
			$statement->execute([$userId, $name]);
		}
	}

	/** A recipe the owner holds, shared with the reader (read), the editor (edit) and the no-STOCK_VIEW member (edit). */
	private static function recipe(string $name = 'Refill recipe'): int
	{
		$id = self::$recipes->CreateRecipe($name . ' ' . bin2hex(random_bytes(3)), null, [['product_id' => self::$product, 'amount' => 1, 'qu_id' => self::$tablet]], self::OWNER);
		self::$recipes->SetShare($id, self::READER, [], self::OWNER);
		self::$recipes->SetShare($id, self::EDITOR, ['edit' => true], self::OWNER);
		self::$recipes->SetShare($id, self::NO_STOCK_VIEW, ['edit' => true], self::OWNER);

		return $id;
	}

	private function expectRefusal(callable $work, int $status, string $code): void
	{
		try
		{
			$work();
		}
		catch (ConsumptionException $exception)
		{
			self::assertSame([$status, $code], [$exception->status, $exception->errorCode], $exception->getMessage());
			return;
		}

		self::fail("expected a $status $code refusal");
	}

	private static function state(int $recipe, string $asOf = '2026-03-01', int $user = self::OWNER): array
	{
		return self::$service->GetRefill($recipe, $asOf, $user);
	}

	private static function fill(int $recipe, string $filledOn, ?int $days = 90, int $user = self::OWNER, ?string $note = null, string $asOf = '2026-03-01'): array
	{
		return self::$service->RecordFill($recipe, ['filled_on' => $filledOn, 'supplied_days' => $days, 'note' => $note], $asOf, $user);
	}

	/** The columns of a state that a refill must not move when something else happens. */
	private static function stable(array $state): array
	{
		return [$state['status'], $state['days_overdue'], $state['current_fill'], $state['estimate'], $state['open_order'], $state['settings'], $state['explicit_date'], $state['fills'], $state['orders']];
	}

	private static function ledger(): array
	{
		return [
			(int)self::$db->query('SELECT count(*) FROM stock_log')->fetchColumn(),
			(float)self::$db->query('SELECT COALESCE(sum(amount), 0) FROM stock')->fetchColumn(),
			(int)self::$db->query('SELECT count(*) FROM stock')->fetchColumn(),
			(int)self::$db->query('SELECT count(*) FROM consumption_events')->fetchColumn(),
		];
	}

	// --- The estimate, from recorded fills ----------------------------------------------------

	public function testThirtyAndNinetyDayFillsGiveTheFallbackDatesWithTheirProvenance(): void
	{
		$thirty = self::recipe();
		$ninety = self::recipe();
		self::fill($thirty, '2026-01-01', 30);
		self::fill($ninety, '2026-01-01', 90);

		$a = self::state($thirty);
		$b = self::state($ninety);

		self::assertSame(['2026-01-17', '2026-01-10', 'fallback', 7, null], [$a['estimate']['reorder_date'], $a['estimate']['warning_date'], $a['estimate']['source'], $a['estimate']['lead_days'], $a['estimate']['reason']]);
		self::assertSame(['2026-03-18', '2026-03-11', 'fallback'], [$b['estimate']['reorder_date'], $b['estimate']['warning_date'], $b['estimate']['source']]);
		self::assertSame(['id' => $b['current_fill']['id'], 'filled_on' => '2026-01-01', 'supplied_days' => 90], $b['current_fill']);
		self::assertSame(['due', 43], [$a['status'], $a['days_overdue']], '30-day fill on 2026-03-01, 43 days after its reorder date');
		self::assertSame(['ok', null], [$b['status'], $b['days_overdue']]);
	}

	public function testAStateCarriesExactlyTheDocumentedKeysAndTheEstimateAlwaysHasFiveKeys(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		$state = self::state($id);

		self::assertSame(['recipe_id', 'recipe_name', 'as_of', 'as_of_source', 'status', 'days_overdue', 'current_fill', 'estimate', 'open_order', 'settings', 'explicit_date', 'fills', 'orders'], array_keys($state));
		self::assertSame(['reorder_date', 'warning_date', 'source', 'lead_days', 'reason'], array_keys($state['estimate']));
		self::assertSame(['id', 'filled_on', 'supplied_days'], array_keys($state['current_fill']));

		$empty = self::state(self::recipe());
		self::assertSame(['unknown', null, null], [$empty['status'], $empty['current_fill'], $empty['open_order']]);
		self::assertSame(['reorder_date' => null, 'warning_date' => null, 'source' => null, 'lead_days' => 7, 'reason' => 'no_fill'], $empty['estimate']);
	}

	public function testTheCurrentFillIsTheGreatestFilledOnThenTheGreatestId(): void
	{
		$id = self::recipe();
		$first = self::fill($id, '2026-02-01', 30)['current_fill']['id'];
		self::assertSame($first, self::state($id)['current_fill']['id']);

		$backdated = self::fill($id, '2026-01-10', 30);
		self::assertSame($first, $backdated['current_fill']['id'], 'a fill dated earlier does not become current');
		self::assertCount(2, $backdated['fills']);

		$sameDay = self::fill($id, '2026-02-01', 90)['current_fill'];
		self::assertGreaterThan($first, $sameDay['id']);
		self::assertSame(90, $sameDay['supplied_days'], 'the greater id wins on the same date');
		self::assertSame([true, false, false], array_column(self::state($id)['fills'], 'is_current'));
	}

	public function testVoidingTheCurrentFillMakesThePreviousOneCurrentAndKeepsBothInTheHistory(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		$newer = self::fill($id, '2026-03-01', 30)['current_fill']['id'];

		$state = self::$service->VoidFill($id, $newer, 'entered the wrong day', '2026-03-01', self::OWNER);

		self::assertSame(['2026-01-01', '2026-03-18'], [$state['current_fill']['filled_on'], $state['estimate']['reorder_date']]);
		self::assertCount(2, $state['fills']);
		$voided = array_values(array_filter($state['fills'], fn($fill) => $fill['id'] === $newer))[0];
		self::assertSame(['entered the wrong day', false], [$voided['void_reason'], $voided['is_current']]);
		self::assertNotNull($voided['voided_at']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $voided['voided_at'], 'an instant travels as RFC 3339 UTC');
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $voided['created_at']);
		self::assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}$/', $voided['filled_on']), 'a fill date is a calendar date with no time or offset');
	}

	public function testVoidingEveryFillLeavesNoFillAndKeepsTheHistory(): void
	{
		$id = self::recipe();
		$fill = self::fill($id, '2026-01-01', 90)['current_fill']['id'];
		$state = self::$service->VoidFill($id, $fill, 'wrong prescription', '2026-03-01', self::OWNER);

		self::assertSame(['unknown', null, 'no_fill', 1], [$state['status'], $state['current_fill'], $state['estimate']['reason'], count($state['fills'])]);
		$this->expectRefusal(fn() => self::$service->VoidFill($id, $fill, 'again', '2026-03-01', self::OWNER), 409, 'already_voided');
		$this->expectRefusal(fn() => self::$service->VoidFill($id, 987654, 'no such fill', '2026-03-01', self::OWNER), 404, 'fill_not_found');
		$this->expectRefusal(fn() => self::$service->VoidFill($id, $fill, '  ', '2026-03-01', self::OWNER), 422, 'reason_required');
	}

	public function testAFillOfAnotherRecipeCannotBeVoidedThroughThisOne(): void
	{
		$mine = self::recipe();
		$theirs = self::recipe();
		$fill = self::fill($theirs, '2026-01-01', 90)['current_fill']['id'];

		$this->expectRefusal(fn() => self::$service->VoidFill($mine, $fill, 'wrong recipe', '2026-03-01', self::OWNER), 404, 'fill_not_found');
		self::assertNull(self::state($theirs)['fills'][0]['voided_at']);
	}

	// --- Rules, explicit dates and their precedence ---------------------------------------------

	public function testAnExplicitDateBeatsARuleAndTheFallbackAndRemovingEachRevealsTheNext(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		self::$service->SetSettings($id, ['rule' => ['kind' => 'fixed_interval', 'parameter' => 60]], '2026-03-01', self::OWNER);
		$state = self::$service->SetSettings($id, ['explicit_reorder_date' => '2026-02-10'], '2026-03-01', self::OWNER);
		self::assertSame(['2026-02-10', 'explicit'], [$state['estimate']['reorder_date'], $state['estimate']['source']]);
		self::assertSame('2026-02-10', $state['explicit_date']['reorder_on']);

		$state = self::$service->SetSettings($id, ['explicit_reorder_date' => null], '2026-03-01', self::OWNER);
		self::assertSame(['2026-03-02', 'rule:fixed_interval', null], [$state['estimate']['reorder_date'], $state['estimate']['source'], $state['explicit_date']]);

		$state = self::$service->SetSettings($id, ['rule' => null], '2026-03-01', self::OWNER);
		self::assertSame(['2026-03-18', 'fallback', null], [$state['estimate']['reorder_date'], $state['estimate']['source'], $state['settings']['rule']]);
	}

	public function testAnExplicitDateWithinTheSameFillCanBeReplacedAndEachReplacementIsKept(): void
	{
		$id = self::recipe();
		$fill = self::fill($id, '2026-01-01', 90)['current_fill']['id'];
		self::$service->SetSettings($id, ['explicit_reorder_date' => '2026-02-10'], '2026-03-01', self::OWNER);
		$state = self::$service->SetSettings($id, ['explicit_reorder_date' => '2026-02-12'], '2026-03-01', self::OWNER);

		self::assertSame('2026-02-12', $state['estimate']['reorder_date']);
		self::assertSame($fill, $state['explicit_date']['fill_id']);
		$rows = self::$db->query("SELECT reorder_on, ended_reason FROM consumption_refill_dates WHERE recipe_id = $id ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
		self::assertSame([['reorder_on' => '2026-02-10', 'ended_reason' => 'replaced'], ['reorder_on' => '2026-02-12', 'ended_reason' => null]], $rows);
	}

	public function testANewerFillEndsTheExplicitDateAndVoidingTheNewerFillDoesNotBringItBack(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		self::$service->SetSettings($id, ['explicit_reorder_date' => '2026-02-10'], '2026-03-01', self::OWNER);
		$newer = self::fill($id, '2026-02-12', 30)['current_fill']['id'];

		$state = self::state($id);
		self::assertSame([null, 'fallback', '2026-02-28'], [$state['explicit_date'], $state['estimate']['source'], $state['estimate']['reorder_date']], 'the newer fill ends the date, and the fallback runs on the newer fill');

		$state = self::$service->VoidFill($id, $newer, 'wrong fill', '2026-03-01', self::OWNER);
		self::assertSame([null, 'fallback', '2026-03-18'], [$state['explicit_date'], $state['estimate']['source'], $state['estimate']['reorder_date']], 'the explicit date does not apply to the older fill again');
		self::assertSame('superseded', self::$db->query("SELECT ended_reason FROM consumption_refill_dates WHERE recipe_id = $id")->fetchColumn());
	}

	public function testVoidingTheFillAnExplicitDateBelongsToEndsTheDate(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		$second = self::fill($id, '2026-02-01', 90)['current_fill']['id'];
		self::$service->SetSettings($id, ['explicit_reorder_date' => '2026-03-10'], '2026-03-01', self::OWNER);

		$state = self::$service->VoidFill($id, $second, 'wrong fill', '2026-03-01', self::OWNER);

		self::assertSame([null, 'fallback', '2026-01-01'], [$state['explicit_date'], $state['estimate']['source'], $state['current_fill']['filled_on']]);
		self::assertSame('fill_voided', self::$db->query("SELECT ended_reason FROM consumption_refill_dates WHERE recipe_id = $id")->fetchColumn());
	}

	public function testAnExplicitDateNeedsACurrentFillAndMayNotPrecedeIt(): void
	{
		$id = self::recipe();
		$this->expectRefusal(fn() => self::$service->SetSettings($id, ['explicit_reorder_date' => '2026-02-10'], '2026-03-01', self::OWNER), 422, 'no_current_fill');

		self::fill($id, '2026-02-01', 30);
		$this->expectRefusal(fn() => self::$service->SetSettings($id, ['explicit_reorder_date' => '2026-01-31'], '2026-03-01', self::OWNER), 422, 'date_before_fill');
		self::assertNull(self::state($id)['explicit_date'], 'the refused date left nothing behind');

		$state = self::$service->SetSettings($id, ['explicit_reorder_date' => '2026-02-01'], '2026-03-01', self::OWNER);
		self::assertSame('2026-02-01', $state['estimate']['reorder_date'], 'the fill date itself is allowed, as it is for the explicit date');
	}

	public function testEveryRuleKindAndItsBoundsThroughTheService(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);

		foreach ([['days_before_end', 21, '2026-03-11'], ['days_before_end', 0, '2026-04-01'], ['fixed_interval', 28, '2026-01-29'], ['fraction_elapsed', 75, '2026-03-09'], ['fraction_elapsed', 1, '2026-01-01']] as [$kind, $parameter, $expected])
		{
			if ($expected === '2026-01-01')
			{
				$state = self::$service->SetSettings($id, ['rule' => ['kind' => $kind, 'parameter' => $parameter]], '2026-03-01', self::OWNER);
				self::assertSame(['unknown', 'result_not_after_fill'], [$state['status'], $state['estimate']['reason']], '1 percent of 90 days is 0 whole days: not after the fill');
				continue;
			}

			$state = self::$service->SetSettings($id, ['rule' => ['kind' => $kind, 'parameter' => $parameter]], '2026-03-01', self::OWNER);
			self::assertSame([$expected, 'rule:' . $kind], [$state['estimate']['reorder_date'], $state['estimate']['source']], "$kind $parameter");
			self::assertSame(['kind' => $kind, 'parameter' => $parameter], $state['settings']['rule']);
		}
	}

	public function testInvalidRulesLeadsAndFieldsAreRefusedWith422AndChangeNothing(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		$before = self::stable(self::state($id));

		foreach ([
			['rule' => ['kind' => 'percent', 'parameter' => 50]],
			['rule' => ['kind' => 'fixed_interval']],
			['rule' => ['kind' => 'fixed_interval', 'parameter' => '28']],
			['rule' => ['kind' => 'fixed_interval', 'parameter' => 0]],
			['rule' => ['kind' => 'days_before_end', 'parameter' => 731]],
			['rule' => ['kind' => 'days_before_end', 'parameter' => -1]],
			['rule' => ['kind' => 'fraction_elapsed', 'parameter' => 0]],
			['rule' => ['kind' => 'fraction_elapsed', 'parameter' => 100]],
			['rule' => ['kind' => 'fraction_elapsed', 'parameter' => 0.5]],
			['rule' => 'fixed_interval'],
		] as $changes)
		{
			$this->expectRefusal(fn() => self::$service->SetSettings($id, $changes, '2026-03-01', self::OWNER), 422, 'invalid_rule');
		}

		foreach ([-1, 61, '7', 7.5, true] as $lead)
		{
			$this->expectRefusal(fn() => self::$service->SetSettings($id, ['warning_lead_days' => $lead], '2026-03-01', self::OWNER), 422, 'invalid_lead');
		}

		foreach (['2026-02-30', '2026-13-01', '03/01/2026', '', '2026-1-1', 20260301, 'tomorrow'] as $date)
		{
			$this->expectRefusal(fn() => self::$service->SetSettings($id, ['explicit_reorder_date' => $date], '2026-03-01', self::OWNER), 422, 'invalid_date');
		}

		$this->expectRefusal(fn() => self::$service->SetSettings($id, ['rule_kind' => 'fixed_interval'], '2026-03-01', self::OWNER), 422, 'unknown_field');
		self::assertSame($before, self::stable(self::state($id)), 'no refused request changed anything');
	}

	// --- Fill input -----------------------------------------------------------------------------

	public function testAFillNeedsADateAndNeverTakesOneFromTheServer(): void
	{
		$id = self::recipe();

		foreach ([[], ['filled_on' => null], ['filled_on' => ''], ['supplied_days' => 30]] as $input)
		{
			$this->expectRefusal(fn() => self::$service->RecordFill($id, $input, '2026-03-01', self::OWNER), 422, 'date_required');
		}
		foreach (['2026-02-30', '2026-03-01T00:00:00Z', 'today', 20260301, '1899-12-31', '2201-01-01'] as $date)
		{
			$this->expectRefusal(fn() => self::$service->RecordFill($id, ['filled_on' => $date, 'supplied_days' => 30], '2026-03-01', self::OWNER), 422, 'invalid_date');
		}
		self::assertSame([], self::state($id)['fills']);
	}

	public function testSuppliedDaysAreAnIntegerFromOneToSevenHundredThirtyOrAbsent(): void
	{
		$id = self::recipe();

		foreach ([0, 731, -5, '30', 30.5, true, [30]] as $days)
		{
			$this->expectRefusal(fn() => self::$service->RecordFill($id, ['filled_on' => '2026-01-01', 'supplied_days' => $days], '2026-03-01', self::OWNER), 422, 'invalid_supplied_days');
		}
		self::assertSame([], self::state($id)['fills']);

		$long = self::fill($id, '2026-01-01', 730);
		self::assertSame('2027-12-18', $long['estimate']['reorder_date']);
		$none = self::$service->RecordFill($id, ['filled_on' => '2026-02-01'], '2026-03-01', self::OWNER);
		self::assertSame([null, 'invalid_supply', 'unknown'], [$none['current_fill']['supplied_days'], $none['estimate']['reason'], $none['status']], 'a fill with no supply is allowed and the fallback then has no estimate');
	}

	public function testAMissingSupplyIsUnknownExceptForAFixedIntervalOrAnExplicitDate(): void
	{
		$id = self::recipe();
		self::$service->RecordFill($id, ['filled_on' => '2026-01-01'], '2026-03-01', self::OWNER);
		self::assertSame('invalid_supply', self::state($id)['estimate']['reason']);

		self::$service->SetSettings($id, ['rule' => ['kind' => 'days_before_end', 'parameter' => 7]], '2026-03-01', self::OWNER);
		self::assertSame('invalid_supply', self::state($id)['estimate']['reason']);

		$state = self::$service->SetSettings($id, ['rule' => ['kind' => 'fixed_interval', 'parameter' => 28]], '2026-03-01', self::OWNER);
		self::assertSame(['2026-01-29', 'rule:fixed_interval'], [$state['estimate']['reorder_date'], $state['estimate']['source']]);

		self::$service->SetSettings($id, ['rule' => ['kind' => 'fraction_elapsed', 'parameter' => 80]], '2026-03-01', self::OWNER);
		$state = self::$service->SetSettings($id, ['explicit_reorder_date' => '2026-02-20'], '2026-03-01', self::OWNER);
		self::assertSame(['2026-02-20', 'explicit'], [$state['estimate']['reorder_date'], $state['estimate']['source']]);
	}

	public function testShortSuppliesAreUnknownAndNeverClamped(): void
	{
		$id = self::recipe();
		$state = self::fill($id, '2026-01-01', 14);
		self::assertSame(['unknown', null, 'result_not_after_fill'], [$state['status'], $state['estimate']['reorder_date'], $state['estimate']['reason']]);

		self::fill($id, '2026-01-08', 7);
		self::assertSame('result_not_after_fill', self::state($id)['estimate']['reason']);

		$state = self::$service->SetSettings($id, ['rule' => ['kind' => 'fixed_interval', 'parameter' => 5]], '2026-03-01', self::OWNER);
		self::assertSame('2026-01-13', $state['estimate']['reorder_date'], 'a person fixes a short supply with a rule');
	}

	public function testANoteIsStoredAsTypedAndLongOrNonTextNotesAreRefused(): void
	{
		$id = self::recipe();
		$hostile = '<img src=x onerror=alert(1)> "quoted" \' ; DROP TABLE consumption_refill_fills; --';
		$state = self::fill($id, '2026-01-01', 30, self::OWNER, $hostile);

		self::assertSame($hostile, $state['fills'][0]['note'], 'stored and returned verbatim; it is shown as text');
		self::assertSame($hostile, self::$db->query("SELECT note FROM consumption_refill_fills WHERE recipe_id = $id")->fetchColumn());

		$this->expectRefusal(fn() => self::fill($id, '2026-01-02', 30, self::OWNER, str_repeat('n', 2001)), 422, 'invalid_note');
		$this->expectRefusal(fn() => self::$service->RecordFill($id, ['filled_on' => '2026-01-02', 'note' => ['x']], '2026-03-01', self::OWNER), 422, 'invalid_note');
		self::assertNull(self::fill($id, '2026-01-03', 30, self::OWNER, '   ')['fills'][0]['note'], 'a blank note is no note');
		self::assertSame(2, count(self::state($id)['fills']));
	}

	// --- Today: as_of and the server default ----------------------------------------------------

	public function testStatusFollowsTheSuppliedDateAndStoredValuesDoNotMove(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90, self::OWNER, null, '2026-03-17');

		$monday = self::state($id, '2026-03-17');
		$tuesday = self::state($id, '2026-03-18');

		self::assertSame(['approaching', 'client', '2026-03-17'], [$monday['status'], $monday['as_of_source'], $monday['as_of']]);
		self::assertSame(['due', 'client'], [$tuesday['status'], $tuesday['as_of_source']]);
		self::assertSame($monday['estimate']['reorder_date'], $tuesday['estimate']['reorder_date'], 'only the status changed');
		self::assertSame($monday['fills'], $tuesday['fills']);
	}

	public function testWithoutAsOfTheUtcDateIsUsedAndTheResponseSaysSo(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);

		$before = gmdate('Y-m-d');
		$state = self::$service->GetRefill($id, null, self::OWNER);
		$after = gmdate('Y-m-d');

		self::assertSame('server_utc', $state['as_of_source']);
		self::assertContains($state['as_of'], [$before, $after]);
		self::assertSame('server_utc', self::$service->ListRefills(null, self::OWNER)['as_of_source']);
		self::assertSame('server_utc', self::$service->Notices(null, self::OWNER)['as_of_source']);
		self::assertSame('client', self::$service->GetRefill($id, '2026-03-01', self::OWNER)['as_of_source']);
	}

	public function testAnInvalidAsOfIsRefusedAndNeverRolledOver(): void
	{
		$id = self::recipe();
		foreach (['2026-02-30', 'tomorrow', '2026-3-1', '20260301', '2026-03-01T00:00:00Z'] as $asOf)
		{
			$this->expectRefusal(fn() => self::$service->GetRefill($id, $asOf, self::OWNER), 422, 'invalid_as_of');
			$this->expectRefusal(fn() => self::$service->ListRefills($asOf, self::OWNER), 422, 'invalid_as_of');
			$this->expectRefusal(fn() => self::$service->Notices($asOf, self::OWNER), 422, 'invalid_as_of');
		}
	}

	public function testNeitherTheDatabaseSessionZoneNorThePhpZoneMovesADate(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		self::$service->RecordOrder($id, ['ordered_on' => '2026-02-01'], '2026-03-01', self::OWNER);
		$expected = self::state($id, '2026-03-17');

		$pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();
		$previous = date_default_timezone_get();
		try
		{
			foreach (['Pacific/Kiritimati', 'Pacific/Pago_Pago', 'America/St_Johns', 'UTC'] as $zone)
			{
				$pdo->exec("SET TIME ZONE '$zone'");
				date_default_timezone_set($zone);
				self::assertSame($expected, self::state($id, '2026-03-17'), "in $zone");
				self::assertSame($expected['fills'][0]['filled_on'], self::$db->query("SELECT filled_on::text FROM consumption_refill_fills WHERE recipe_id = $id")->fetchColumn(), "the stored fill date in $zone");
			}
		}
		finally
		{
			$pdo->exec('RESET TIME ZONE');
			date_default_timezone_set($previous);
		}
	}

	// --- Warning lead ---------------------------------------------------------------------------

	private static function userLead(int $user, mixed $value): void
	{
		self::$db->prepare("DELETE FROM user_settings WHERE user_id = ? AND key = 'refill_warning_lead_days'")->execute([$user]);
		if ($value !== null)
		{
			self::$db->prepare("INSERT INTO user_settings (user_id, key, value) VALUES (?, 'refill_warning_lead_days', ?)")->execute([$user, (string)$value]);
		}
	}

	public function testTheLeadIsTheRecipeOverrideThenTheUsersSettingThenSeven(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		self::userLead(self::OWNER, null);

		self::assertSame([7, '2026-03-11'], [self::state($id)['estimate']['lead_days'], self::state($id)['estimate']['warning_date']]);

		self::userLead(self::OWNER, 14);
		self::assertSame([14, '2026-03-04'], [self::state($id)['estimate']['lead_days'], self::state($id)['estimate']['warning_date']], "the user's setting");

		$state = self::$service->SetSettings($id, ['warning_lead_days' => 3], '2026-03-01', self::OWNER);
		self::assertSame([3, '2026-03-15', 3], [$state['estimate']['lead_days'], $state['estimate']['warning_date'], $state['settings']['warning_lead_days']], "the recipe's override beats the user's setting");

		$state = self::$service->SetSettings($id, ['warning_lead_days' => null], '2026-03-01', self::OWNER);
		self::assertSame([14, null], [$state['estimate']['lead_days'], $state['settings']['warning_lead_days']], 'clearing the override returns to the setting');
		self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM consumption_refill_settings WHERE recipe_id = $id")->fetchColumn(), 'and a settings row with nothing in it is not kept');
		self::userLead(self::OWNER, null);
	}

	public function testAUserSettingOutsideZeroToSixtyIsNotALead(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);

		foreach (['-1', '61', 'seven', '7.5', ''] as $stored)
		{
			self::userLead(self::OWNER, $stored);
			self::assertSame(7, self::state($id)['estimate']['lead_days'], "stored '$stored'");
		}
		self::userLead(self::OWNER, 0);
		self::assertSame(0, self::state($id)['estimate']['lead_days']);
		self::userLead(self::OWNER, 60);
		self::assertSame(['2026-01-17', 60], [self::state($id)['estimate']['warning_date'], self::state($id)['estimate']['lead_days']]);
		self::userLead(self::OWNER, null);
	}

	public function testEachUserSeesTheirOwnLeadOnASharedRecipe(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		self::userLead(self::OWNER, 2);
		self::userLead(self::READER, 20);

		self::assertSame(2, self::state($id, '2026-03-01', self::OWNER)['estimate']['lead_days']);
		self::assertSame(20, self::state($id, '2026-03-01', self::READER)['estimate']['lead_days']);
		self::userLead(self::OWNER, null);
		self::userLead(self::READER, null);
	}

	public function testTheLeadValidatorTheUserSettingsRouteUsesAcceptsOnlyZeroToSixty(): void
	{
		foreach ([0, 7, 60, '0', '60'] as $value)
		{
			self::assertSame((int)$value, ConsumptionRefillService::ValidatedLeadSetting($value));
		}
		foreach ([-1, 61, '', 'x', null, 7.5, true, '7.5'] as $value)
		{
			$this->expectRefusal(fn() => ConsumptionRefillService::ValidatedLeadSetting($value), 422, 'invalid_lead');
		}
	}

	// --- Orders ---------------------------------------------------------------------------------

	public function testRecordingAnOrderChangesNothingButTheStatus(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		$before = self::state($id, '2026-03-20');
		$ledger = self::ledger();

		$state = self::$service->RecordOrder($id, ['ordered_on' => '2026-03-19'], '2026-03-20', self::OWNER);

		self::assertSame(['due', null], [$before['status'], $before['open_order']]);
		self::assertSame(['ordered', null, 1], [$state['status'], $state['days_overdue'], $state['open_order']['age_days']]);
		self::assertSame($before['estimate']['reorder_date'], $state['estimate']['reorder_date'], 'the estimate is the same');
		self::assertSame($before['fills'], $state['fills'], 'and so is the fill history');
		self::assertSame($ledger, self::ledger(), 'an order adds no stock and writes no ledger row');
		self::assertSame(['open', null, null], [$state['orders'][0]['state'], $state['orders'][0]['received_fill_id'], $state['orders'][0]['closed_at']]);
	}

	public function testASecondOpenOrderIsAConflictAndAnOrderNeedsItsDate(): void
	{
		$id = self::recipe();
		self::$service->RecordOrder($id, ['ordered_on' => '2026-03-01'], '2026-03-01', self::OWNER);

		$this->expectRefusal(fn() => self::$service->RecordOrder($id, ['ordered_on' => '2026-03-02'], '2026-03-01', self::OWNER), 409, 'order_open');
		$this->expectRefusal(fn() => self::$service->RecordOrder(self::recipe(), [], '2026-03-01', self::OWNER), 422, 'date_required');
		$this->expectRefusal(fn() => self::$service->RecordOrder(self::recipe(), ['ordered_on' => '2026-02-30'], '2026-03-01', self::OWNER), 422, 'invalid_date');
		self::assertCount(1, self::state($id)['orders']);
	}

	public function testReceivingAnOrderRecordsTheFillAndClosesTheOrderInOneStepWithNoStock(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 30);
		$order = self::$service->RecordOrder($id, ['ordered_on' => '2026-01-14'], '2026-01-14', self::OWNER)['open_order']['id'];
		$ledger = self::ledger();

		$state = self::$service->ReceiveOrder($id, $order, ['filled_on' => '2026-01-20', 'supplied_days' => 90], '2026-01-20', self::OWNER);

		self::assertSame(['ok', '2026-01-20', '2026-04-06', null], [$state['status'], $state['current_fill']['filled_on'], $state['estimate']['reorder_date'], $state['open_order']]);
		$closed = $state['orders'][0];
		self::assertSame(['received', $state['current_fill']['id']], [$closed['state'], $closed['received_fill_id']]);
		self::assertNotNull($closed['closed_at']);
		self::assertCount(2, $state['fills']);
		self::assertSame($ledger, self::ledger(), 'receiving an order is not a purchase');
		$this->expectRefusal(fn() => self::$service->ReceiveOrder($id, $order, ['filled_on' => '2026-01-21'], '2026-01-21', self::OWNER), 409, 'order_closed');
		self::assertCount(2, self::state($id)['fills'], 'a refused receive recorded no fill');
	}

	public function testAReceiveWithBadInputLeavesTheOrderOpenAndRecordsNoFill(): void
	{
		$id = self::recipe();
		$order = self::$service->RecordOrder($id, ['ordered_on' => '2026-01-14'], '2026-01-14', self::OWNER)['open_order']['id'];

		$this->expectRefusal(fn() => self::$service->ReceiveOrder($id, $order, ['supplied_days' => 30], '2026-01-20', self::OWNER), 422, 'date_required');
		$this->expectRefusal(fn() => self::$service->ReceiveOrder($id, $order, ['filled_on' => '2026-01-20', 'supplied_days' => 0], '2026-01-20', self::OWNER), 422, 'invalid_supplied_days');

		$state = self::state($id);
		self::assertSame(['ordered', 0, 'open'], [$state['status'], count($state['fills']), $state['orders'][0]['state']]);
	}

	public function testCancellingAnOrderRestoresTheStatusTheFillsAndRulesSay(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		$order = self::$service->RecordOrder($id, ['ordered_on' => '2026-03-10'], '2026-03-12', self::OWNER)['open_order']['id'];
		self::assertSame('ordered', self::state($id, '2026-03-12')['status']);

		$state = self::$service->CancelOrder($id, $order, '2026-03-12', self::OWNER);

		self::assertSame(['approaching', null, 'cancelled'], [$state['status'], $state['open_order'], $state['orders'][0]['state']]);
		$this->expectRefusal(fn() => self::$service->CancelOrder($id, $order, '2026-03-12', self::OWNER), 409, 'order_closed');
		$this->expectRefusal(fn() => self::$service->ReceiveOrder($id, $order, ['filled_on' => '2026-03-12'], '2026-03-12', self::OWNER), 409, 'order_closed');
		self::assertSame('ordered', self::$service->RecordOrder($id, ['ordered_on' => '2026-03-13'], '2026-03-13', self::OWNER)['status'], 'a new order is allowed after a cancellation');
	}

	public function testAnOrderOfAnotherRecipeIsNotFound(): void
	{
		$mine = self::recipe();
		$theirs = self::recipe();
		$order = self::$service->RecordOrder($theirs, ['ordered_on' => '2026-03-01'], '2026-03-01', self::OWNER)['open_order']['id'];

		$this->expectRefusal(fn() => self::$service->CancelOrder($mine, $order, '2026-03-01', self::OWNER), 404, 'order_not_found');
		$this->expectRefusal(fn() => self::$service->ReceiveOrder($mine, $order, ['filled_on' => '2026-03-01'], '2026-03-01', self::OWNER), 404, 'order_not_found');
		self::assertSame('open', self::state($theirs)['orders'][0]['state']);
	}

	public function testAnOrderWithNoFillHistoryIsStillOrdered(): void
	{
		$id = self::recipe();
		$state = self::$service->RecordOrder($id, ['ordered_on' => '2026-03-01'], '2026-03-01', self::OWNER);

		self::assertSame(['ordered', 'no_fill', null], [$state['status'], $state['estimate']['reason'], $state['estimate']['reorder_date']]);
	}

	// --- What a refill never touches ------------------------------------------------------------

	public function testNoRefillOperationWritesTheStockLedgerOrAnEvent(): void
	{
		$id = self::recipe();
		$ledger = self::ledger();

		self::fill($id, '2026-01-01', 90);
		self::$service->SetSettings($id, ['rule' => ['kind' => 'fixed_interval', 'parameter' => 30], 'warning_lead_days' => 5, 'explicit_reorder_date' => '2026-01-20'], '2026-03-01', self::OWNER);
		$order = self::$service->RecordOrder($id, ['ordered_on' => '2026-01-15'], '2026-01-15', self::OWNER)['open_order']['id'];
		self::$service->CancelOrder($id, $order, '2026-01-16', self::OWNER);
		$order = self::$service->RecordOrder($id, ['ordered_on' => '2026-01-17'], '2026-01-17', self::OWNER)['open_order']['id'];
		self::$service->ReceiveOrder($id, $order, ['filled_on' => '2026-01-19', 'supplied_days' => 30], '2026-01-19', self::OWNER);
		self::$service->VoidFill($id, self::state($id)['current_fill']['id'], 'wrong', '2026-01-19', self::OWNER);
		self::$service->Notices('2026-03-01', self::OWNER);
		self::$service->Acknowledge($id . ':due:2026-01-31', self::OWNER);

		self::assertSame($ledger, self::ledger());
	}

	public function testTransfersConsumptionAndUndoDoNotChangeAFillAnOrOrderOrAnEstimate(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		self::$service->RecordOrder($id, ['ordered_on' => '2026-03-10'], '2026-03-12', self::OWNER);
		$before = self::stable(self::state($id, '2026-03-12'));

		$stock = StockService::GetInstance();
		$stock->TransferProduct(self::$product, 7, self::$organizerA, self::$organizerB);
		$stock->ConsumeProduct(self::$product, 2, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, self::$organizerB, $transactionId);
		self::assertNotNull($transactionId, 'a consumption was booked');
		self::assertSame($before, self::stable(self::state($id, '2026-03-12')), 'after a transfer and a consumption');

		$stock->UndoTransaction($transactionId);
		self::assertSame($before, self::stable(self::state($id, '2026-03-12')), 'and after the undo');
		self::assertSame(['ordered', '2026-03-18'], [self::state($id, '2026-03-12')['status'], self::state($id, '2026-03-12')['estimate']['reorder_date']]);
	}

	// --- Access ----------------------------------------------------------------------------------

	public function testOwnerReaderAndEditorReadAndOnlyOwnerAndEditorWrite(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);

		foreach ([self::OWNER, self::READER, self::EDITOR] as $user)
		{
			self::assertSame('ok', self::state($id, '2026-03-01', $user)['status'], "user $user reads");
		}

		foreach ([
			fn() => self::fill($id, '2026-02-01', 30, self::READER),
			fn() => self::$service->SetSettings($id, ['warning_lead_days' => 3], '2026-03-01', self::READER),
			fn() => self::$service->RecordOrder($id, ['ordered_on' => '2026-03-01'], '2026-03-01', self::READER),
			fn() => self::$service->VoidFill($id, self::state($id)['current_fill']['id'], 'reader', '2026-03-01', self::READER),
		] as $write)
		{
			$this->expectRefusal($write, 403, 'right_missing');
		}
		self::assertCount(1, self::state($id)['fills']);

		self::assertSame(2, count(self::fill($id, '2026-02-01', 30, self::EDITOR)['fills']), 'the editor writes');
		$order = self::$service->RecordOrder($id, ['ordered_on' => '2026-03-01'], '2026-03-01', self::EDITOR)['open_order']['id'];
		self::assertSame('ordered', self::state($id, '2026-03-01', self::READER)['status'], 'the reader sees what the editor recorded');
		self::assertSame('cancelled', self::$service->CancelOrder($id, $order, '2026-03-01', self::EDITOR)['orders'][0]['state']);
	}

	public function testAnUnsharedMemberAnAccountManagerAndAnAdministratorAreToldTheRecipeDoesNotExist(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		$order = self::$service->RecordOrder($id, ['ordered_on' => '2026-03-01'], '2026-03-01', self::OWNER)['open_order']['id'];
		$fillId = self::state($id)['current_fill']['id'];

		foreach ([self::OTHER, self::MANAGER, self::ADMIN] as $stranger)
		{
			foreach ([
				'read' => fn() => self::$service->GetRefill($id, '2026-03-01', $stranger),
				'settings' => fn() => self::$service->SetSettings($id, ['warning_lead_days' => 1], '2026-03-01', $stranger),
				'fill' => fn() => self::$service->RecordFill($id, ['filled_on' => '2026-03-01', 'supplied_days' => 30], '2026-03-01', $stranger),
				'void' => fn() => self::$service->VoidFill($id, $fillId, 'x', '2026-03-01', $stranger),
				'order' => fn() => self::$service->RecordOrder($id, ['ordered_on' => '2026-03-02'], '2026-03-02', $stranger),
				'receive' => fn() => self::$service->ReceiveOrder($id, $order, ['filled_on' => '2026-03-02'], '2026-03-02', $stranger),
				'cancel' => fn() => self::$service->CancelOrder($id, $order, '2026-03-02', $stranger),
				'ack' => fn() => self::$service->Acknowledge($id . ':due:2026-03-18', $stranger),
			] as $what => $work)
			{
				$this->expectRefusal($work, 404, 'not_found');
			}
		}

		$missing = null;
		try
		{
			self::$service->GetRefill(987654, '2026-03-01', self::OTHER);
		}
		catch (ConsumptionException $exception)
		{
			$missing = [$exception->status, $exception->errorCode, $exception->getMessage()];
		}
		try
		{
			self::$service->GetRefill($id, '2026-03-01', self::OTHER);
		}
		catch (ConsumptionException $exception)
		{
			self::assertSame($missing, [$exception->status, $exception->errorCode, $exception->getMessage()], 'a hidden recipe and a missing one answer the same');
		}
		self::assertSame('open', self::state($id)['orders'][0]['state'], 'no refused request changed anything');
	}

	public function testAShareHolderWithoutStockViewIsRefusedAndStillNotToldMore(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);

		$this->expectRefusal(fn() => self::state($id, '2026-03-01', self::NO_STOCK_VIEW), 403, 'permission_missing');
		$this->expectRefusal(fn() => self::fill($id, '2026-02-01', 30, self::NO_STOCK_VIEW), 403, 'permission_missing');
		$this->expectRefusal(fn() => self::$service->ListRefills('2026-03-01', self::NO_STOCK_VIEW), 403, 'permission_missing');
		$this->expectRefusal(fn() => self::$service->Notices('2026-03-01', self::NO_STOCK_VIEW), 403, 'permission_missing');
	}

	public function testListsAndNoticesHoldOnlyTheRecipesTheCallerCanRead(): void
	{
		$shared = self::recipe('Shared');
		$private = self::$recipes->CreateRecipe('Private ' . bin2hex(random_bytes(3)), null, [['product_id' => self::$product, 'amount' => 1, 'qu_id' => self::$tablet]], self::OWNER);
		self::fill($shared, '2026-01-01', 30);
		self::fill($private, '2026-01-01', 30);

		$owner = array_column(self::$service->ListRefills('2026-03-01', self::OWNER)['refills'], 'recipe_id');
		$reader = array_column(self::$service->ListRefills('2026-03-01', self::READER)['refills'], 'recipe_id');
		$other = array_column(self::$service->ListRefills('2026-03-01', self::OTHER)['refills'], 'recipe_id');

		self::assertContains($private, $owner);
		self::assertContains($shared, $reader);
		self::assertNotContains($private, $reader);
		self::assertNotContains($shared, $other);
		self::assertNotContains($private, $other);
		self::assertNotContains($private, array_column(self::$service->Notices('2026-03-01', self::READER)['notices'], 'recipe_id'));
		self::assertContains($private, array_column(self::$service->Notices('2026-03-01', self::OWNER)['notices'], 'recipe_id'));
		self::assertSame([], self::$service->Notices('2026-03-01', self::ADMIN)['notices'], 'an administrator holds no share');
	}

	public function testARevokedShareLosesTheRefillDataAtOnce(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 30);
		self::assertSame('due', self::state($id, '2026-03-01', self::EDITOR)['status']);

		self::$recipes->RemoveShare($id, self::EDITOR, self::OWNER);

		$this->expectRefusal(fn() => self::state($id, '2026-03-01', self::EDITOR), 404, 'not_found');
		$this->expectRefusal(fn() => self::fill($id, '2026-02-01', 30, self::EDITOR), 404, 'not_found');
		self::assertNotContains($id, array_column(self::$service->ListRefills('2026-03-01', self::EDITOR)['refills'], 'recipe_id'));
		self::assertNotContains($id, array_column(self::$service->Notices('2026-03-01', self::EDITOR)['notices'], 'recipe_id'));
		self::assertCount(1, self::state($id)['fills'], 'the owner still sees the history the editor left');
	}

	public function testAShareDowngradedToReadStopsWritingAtOnce(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 30, self::EDITOR);
		self::$recipes->SetShare($id, self::EDITOR, [], self::OWNER);

		$this->expectRefusal(fn() => self::fill($id, '2026-02-01', 30, self::EDITOR), 403, 'right_missing');
		self::assertSame('due', self::state($id, '2026-03-01', self::EDITOR)['status']);
	}

	public function testDeletingARecipeDeletesItsRefillDataAndDeletingTheOwnerDeletesTheirRecipes(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		self::$service->SetSettings($id, ['rule' => ['kind' => 'fixed_interval', 'parameter' => 30], 'explicit_reorder_date' => '2026-02-01'], '2026-03-01', self::OWNER);
		$order = self::$service->RecordOrder($id, ['ordered_on' => '2026-03-01'], '2026-03-01', self::OWNER)['open_order']['id'];
		self::$service->ReceiveOrder($id, $order, ['filled_on' => '2026-03-02', 'supplied_days' => 30], '2026-03-02', self::OWNER);
		self::$service->Acknowledge($id . ':due:2026-04-01', self::OWNER);

		self::$recipes->DeleteRecipe($id, self::OWNER);

		foreach (['consumption_refill_settings', 'consumption_refill_fills', 'consumption_refill_dates', 'consumption_refill_orders', 'consumption_refill_acks'] as $table)
		{
			self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM $table WHERE recipe_id = $id")->fetchColumn(), "$table");
		}
		$this->expectRefusal(fn() => self::state($id, '2026-03-01', self::OWNER), 404, 'not_found');
	}

	// --- Notices ---------------------------------------------------------------------------------

	private static function noticesFor(int $recipe, string $asOf, int $user = self::OWNER): array
	{
		return array_values(array_filter(self::$service->Notices($asOf, $user)['notices'], fn($notice) => $notice['recipe_id'] === $recipe));
	}

	public function testApproachingIsRaisedFromTheWarningDateAndDueFromTheReorderDateAndNeverBoth(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);

		self::assertSame([], self::noticesFor($id, '2026-03-10'), 'before the warning date');

		$approaching = self::noticesFor($id, '2026-03-11');
		self::assertCount(1, $approaching);
		self::assertSame([$id . ':approaching:2026-03-18', 'approaching', '2026-03-18', '2026-03-11', null, 'fallback'],
			[$approaching[0]['key'], $approaching[0]['kind'], $approaching[0]['reorder_date'], $approaching[0]['warning_date'], $approaching[0]['days_overdue'], $approaching[0]['source']]);
		self::assertSame('approaching', self::noticesFor($id, '2026-03-17')[0]['kind']);

		$due = self::noticesFor($id, '2026-03-18');
		self::assertCount(1, $due, 'on the reorder date only the due notice is raised');
		self::assertSame([$id . ':due:2026-03-18', 'due', 0], [$due[0]['key'], $due[0]['kind'], $due[0]['days_overdue']]);
		self::assertSame(3, self::noticesFor($id, '2026-03-21')[0]['days_overdue']);
	}

	public function testANoticeStatesAFactAndAnEstimateAndNamesNoMedicineAndGivesNoAdvice(): void
	{
		$id = self::recipe('Lisinopril 10 mg');
		self::fill($id, '2026-01-01', 90);
		$notice = self::noticesFor($id, '2026-03-18')[0];

		self::assertSame('Reorder date reached: estimated 2026-03-18 (from your last fill)', $notice['text']);
		self::assertStringNotContainsString('Lisinopril', $notice['text'], 'the sentence is safe where a title would disclose the medicine');
		self::assertStringContainsString('Lisinopril 10 mg', $notice['recipe_name'], 'the name is a separate field the client may choose to show');
		self::assertSame('Estimated reorder date 2026-03-11 (from your last fill)', ConsumptionRefillService::NoticeText('approaching', '2026-03-11', 'fallback'));
		self::assertSame('Reorder date reached: estimated 2026-03-18 (from the date you entered)', ConsumptionRefillService::NoticeText('due', '2026-03-18', 'explicit'));
		self::assertSame('Reorder date reached: estimated 2026-03-18 (from the rule you set for this prescription)', ConsumptionRefillService::NoticeText('due', '2026-03-18', 'rule:fixed_interval'));

		foreach (['take', 'stop', 'should', 'must', 'eligible', 'insurance', 'covered', 'dose', 'safe', 'urgent', 'overdue'] as $word)
		{
			foreach (['approaching', 'due'] as $kind)
			{
				foreach (['fallback', 'explicit', 'rule:fixed_interval'] as $source)
				{
					self::assertStringNotContainsStringIgnoringCase($word, ConsumptionRefillService::NoticeText($kind, '2026-03-18', $source), "the $kind $source sentence never says '$word'");
				}
			}
		}
	}

	public function testAnOpenOrderRaisesNoNoticeAndKeepsEarlierAcknowledgements(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		self::$service->Acknowledge($id . ':approaching:2026-03-18', self::OWNER);
		$order = self::$service->RecordOrder($id, ['ordered_on' => '2026-03-12'], '2026-03-12', self::OWNER)['open_order']['id'];

		self::assertSame([], self::noticesFor($id, '2026-03-18'), 'no due notice while an order is open');
		self::assertSame(1, (int)self::$db->query("SELECT count(*) FROM consumption_refill_acks WHERE recipe_id = $id")->fetchColumn(), 'the acknowledgement is kept');

		self::$service->CancelOrder($id, $order, '2026-03-18', self::OWNER);
		self::assertSame('due', self::noticesFor($id, '2026-03-18')[0]['kind'], 'cancelling brings the notice back');
	}

	public function testAcknowledgementIsIdempotentAndPerUser(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		$key = $id . ':due:2026-03-18';

		$first = self::$service->Acknowledge($key, self::OWNER);
		$second = self::$service->Acknowledge($key, self::OWNER);

		self::assertSame($first, $second, 'the second call returns the same answer');
		self::assertSame($key, $first['notice_key']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $first['acknowledged_at']);
		self::assertSame(1, (int)self::$db->query("SELECT count(*) FROM consumption_refill_acks WHERE recipe_id = $id AND user_id = " . self::OWNER)->fetchColumn());

		self::assertSame([], self::noticesFor($id, '2026-03-18', self::OWNER), 'acknowledged for the owner');
		self::assertCount(1, self::noticesFor($id, '2026-03-18', self::READER), "the reader's notice is still there");
		self::assertCount(1, self::noticesFor($id, '2026-03-18', self::EDITOR));
		self::$service->Acknowledge($key, self::READER);
		self::assertSame([], self::noticesFor($id, '2026-03-18', self::READER));
		self::assertCount(1, self::noticesFor($id, '2026-03-18', self::EDITOR), "and the editor's too");
		self::assertSame(2, (int)self::$db->query("SELECT count(*) FROM consumption_refill_acks WHERE recipe_id = $id")->fetchColumn(), 'two users acknowledged: two rows');
	}

	public function testAcknowledgingApproachingDoesNotAcknowledgeDue(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		self::$service->Acknowledge($id . ':approaching:2026-03-18', self::OWNER);

		self::assertSame([], self::noticesFor($id, '2026-03-14'));
		self::assertSame('due', self::noticesFor($id, '2026-03-18')[0]['kind']);
	}

	public function testAnIdenticalCorrectionKeepsTheKeyAndAChangedDateRaisesANewNotice(): void
	{
		$id = self::recipe();
		$fill = self::fill($id, '2026-01-01', 90)['current_fill']['id'];
		self::$service->Acknowledge($id . ':due:2026-03-18', self::OWNER);

		self::$service->VoidFill($id, $fill, 'typo', '2026-03-18', self::OWNER);
		self::assertSame([], self::noticesFor($id, '2026-03-18'), 'no fill, no notice');
		self::fill($id, '2026-01-01', 90, self::OWNER, null, '2026-03-18');
		self::assertSame([], self::noticesFor($id, '2026-03-18'), 'void and record again with identical data: the same key, already acknowledged');

		$current = self::state($id, '2026-03-18')['current_fill']['id'];
		self::$service->VoidFill($id, $current, 'wrong supply', '2026-03-18', self::OWNER);
		self::fill($id, '2026-01-01', 60, self::OWNER, null, '2026-03-18');
		$changed = self::noticesFor($id, '2026-03-18');
		self::assertCount(1, $changed);
		self::assertSame($id . ':due:2026-02-16', $changed[0]['key'], 'a changed date is a new notice');
	}

	public function testAcknowledgementKeysAreValidatedAndMustNameAReadableRecipe(): void
	{
		$id = self::recipe();
		$private = self::$recipes->CreateRecipe('Private ' . bin2hex(random_bytes(3)), null, [['product_id' => self::$product, 'amount' => 1, 'qu_id' => self::$tablet]], self::OWNER);
		$rows = (int)self::$db->query('SELECT count(*) FROM consumption_refill_acks')->fetchColumn();

		foreach (['not-a-real-key', '', $id . ':due', $id . ':overdue:2026-03-18', $id . ':due:2026-02-30', $id . ':due:2026-3-18', 'x:due:2026-03-18', ':due:2026-03-18',
			$id . ':due:2026-03-18:extra', ' ' . $id . ':due:2026-03-18', $id . ':DUE:2026-03-18', '-1:due:2026-03-18', $id . ':due:0000-01-01', $id . ':due:9999-12-31', $id . ":due:2026-03-18\n"] as $key)
		{
			$this->expectRefusal(fn() => self::$service->Acknowledge($key, self::OWNER), 400, 'invalid_notice_key');
		}
		foreach ([null, 7, ['a'], true] as $key)
		{
			$this->expectRefusal(fn() => self::$service->Acknowledge($key, self::OWNER), 400, 'invalid_notice_key');
		}

		$this->expectRefusal(fn() => self::$service->Acknowledge('987654:due:2026-03-18', self::OWNER), 404, 'not_found');
		$this->expectRefusal(fn() => self::$service->Acknowledge('99999999999:due:2026-03-18', self::OWNER), 404, 'not_found');
		$this->expectRefusal(fn() => self::$service->Acknowledge($private . ':due:2026-03-18', self::READER), 404, 'not_found');
		$this->expectRefusal(fn() => self::$service->Acknowledge($private . ':due:2026-03-18', self::OTHER), 404, 'not_found');
		self::assertSame($rows, (int)self::$db->query('SELECT count(*) FROM consumption_refill_acks')->fetchColumn(), 'no refused key stored anything');
	}

	public function testNoticesAreOrderedByReorderDateThenRecipe(): void
	{
		$late = self::recipe();
		$early = self::recipe();
		self::fill($late, '2026-02-10', 30);
		self::fill($early, '2026-02-01', 30);

		$ids = array_column(self::$service->Notices('2026-03-20', self::OWNER)['notices'], 'recipe_id');
		self::assertLessThan(array_search($late, $ids, true), array_search($early, $ids, true));
	}

	// --- Calendar boundaries through the service ---------------------------------------------------

	public function testYearEndAndLeapDayFillsAndWarningDatesThroughTheService(): void
	{
		$yearEnd = self::recipe();
		$leap = self::recipe();
		self::fill($yearEnd, '2027-12-20', 90, self::OWNER, null, '2028-03-01');
		self::fill($leap, '2028-02-20', 30, self::OWNER, null, '2028-03-01');

		$a = self::state($yearEnd, '2028-02-27');
		$b = self::state($leap, '2028-03-06');

		self::assertSame(['2028-03-05', '2028-02-27', 'approaching'], [$a['estimate']['reorder_date'], $a['estimate']['warning_date'], $a['status']]);
		self::assertSame(['2028-03-07', '2028-02-29', 'approaching'], [$b['estimate']['reorder_date'], $b['estimate']['warning_date'], $b['status']]);
		self::assertSame('due', self::state($leap, '2028-03-07')['status']);
		self::assertSame('ok', self::state($leap, '2028-02-28')['status'], 'the day before the warning date, which is the leap day');
		self::assertSame('approaching', self::state($leap, '2028-02-29')['status'], 'the leap day is the warning date');
	}

	public function testWarningLeadZeroAndSixtyThroughTheService(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);
		self::$service->SetSettings($id, ['warning_lead_days' => 0], '2026-03-01', self::OWNER);
		self::assertSame(['ok', 'due'], [self::state($id, '2026-03-17')['status'], self::state($id, '2026-03-18')['status']]);

		self::$service->SetSettings($id, ['warning_lead_days' => 60], '2026-03-01', self::OWNER);
		self::assertSame(['ok', 'approaching', '2026-01-17'], [self::state($id, '2026-01-16')['status'], self::state($id, '2026-01-17')['status'], self::state($id, '2026-01-16')['estimate']['warning_date']]);
	}
}
