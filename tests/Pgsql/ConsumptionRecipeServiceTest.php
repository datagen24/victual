<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ConsumptionException;
use Victual\Services\ConsumptionRecipeService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0040 and ADR-0041 as ConsumptionRecipeService enforces them: who may read, consume, edit,
 * undo and share a private consumption recipe, what a consumption books, and what a refusal leaves
 * behind. Every call names the acting user, because VICTUAL_USER_ID is one constant per process.
 *
 * Fixtures: an owner, a member the recipe is shared with, an unrelated member, an account manager
 * (USERS_EDIT) and an administrator (ADMIN). The last two hold the global permissions a stock user
 * holds and no share, so every answer they get is the answer an unrelated member gets.
 */
class ConsumptionRecipeServiceTest extends PgsqlSchemaTestCase
{
	private const OWNER = 9101;
	private const MEMBER = 9102;
	private const OTHER = 9103;
	private const MANAGER = 9104;
	private const ADMIN = 9105;
	private const VIEWER = 9106;

	private static PDO $db;
	private static ConsumptionRecipeService $service;
	private static int $tablet;
	private static int $box;
	private static int $organizerA;
	private static int $organizerB;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$service = ConsumptionRecipeService::GetInstance();

		$users = [self::OWNER => 'owner', self::MEMBER => 'member', self::OTHER => 'other', self::MANAGER => 'manager', self::ADMIN => 'admin', self::VIEWER => 'viewer'];
		foreach ($users as $id => $name)
		{
			self::$db->exec("INSERT INTO users (id, username, password) VALUES ($id, 'cr-$name', 'fixture')");
		}

		foreach ([self::OWNER, self::MEMBER, self::OTHER, self::MANAGER] as $id)
		{
			self::grant($id, ['STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT', 'STOCK_TRANSFER']);
		}
		self::grant(self::MANAGER, ['USERS_EDIT']);
		self::grant(self::ADMIN, ['ADMIN']);
		self::grant(self::VIEWER, ['STOCK_VIEW']);

		self::$tablet = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('CR tablet') RETURNING id")->fetchColumn();
		self::$box = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('CR box') RETURNING id")->fetchColumn();
		self::$organizerA = (int)self::$db->query("INSERT INTO locations (name) VALUES ('CR organizer A') RETURNING id")->fetchColumn();
		self::$organizerB = (int)self::$db->query("INSERT INTO locations (name) VALUES ('CR organizer B') RETURNING id")->fetchColumn();
	}

	private static function grant(int $userId, array $names): void
	{
		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?');
		foreach ($names as $name)
		{
			$statement->execute([$userId, $name]);
		}
	}

	/** A product stocked in tablets, with `$onA` tablets in organizer A and `$onB` in organizer B. */
	private static function product(string $name, float $onA = 0, float $onB = 0, bool $boxConversion = true): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute([$name, self::$organizerA, self::$tablet, self::$tablet, self::$tablet, self::$tablet]);
		$id = (int)$statement->fetchColumn();

		if ($boxConversion)
		{
			self::$db->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (?, ?, 10, ?)')->execute([self::$box, self::$tablet, $id]);
			self::$db->exec('SELECT 1');
			self::refreshConversions();
		}

		$stock = StockService::GetInstance();
		if ($onA > 0)
		{
			$stock->AddProduct($id, $onA, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$organizerA);
		}
		if ($onB > 0)
		{
			$stock->AddProduct($id, $onB, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$organizerB);
		}

		return $id;
	}

	private static function refreshConversions(): void
	{
		// The resolved conversions are a cache the application refreshes on a conversion write.
		self::$db->exec('SELECT count(*) FROM cache__quantity_unit_conversions_resolved');
	}

	private static function onHand(int $productId, ?int $location = null): float
	{
		$sql = 'SELECT COALESCE(sum(amount), 0) FROM stock WHERE product_id = ' . $productId . ($location === null ? '' : ' AND location_id = ' . $location);
		return (float)self::$db->query($sql)->fetchColumn();
	}

	private static function line(int $product, float $amount, ?int $qu = null): array
	{
		return ['product_id' => $product, 'amount' => $amount, 'qu_id' => $qu ?? self::$tablet];
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

	private static function recipe(array $lines, string $name = 'Recipe', int $owner = self::OWNER): int
	{
		return self::$service->CreateRecipe($name . ' ' . bin2hex(random_bytes(3)), null, $lines, $owner);
	}

	// --- Reading: absent means absent -------------------------------------------------------

	public function testOnlyTheOwnerAndNamedMembersCanSeeARecipe(): void
	{
		$product = self::product('CR visibility', 10);
		$id = self::recipe([self::line($product, 1)]);
		self::$service->SetShare($id, self::MEMBER, ['consume' => true], self::OWNER);

		self::assertSame($id, self::$service->GetRecipe($id, self::OWNER)['id']);
		self::assertTrue(self::$service->GetRecipe($id, self::OWNER)['is_owner']);
		self::assertSame(['read' => true, 'consume' => true, 'edit' => false, 'undo' => false, 'share' => false], self::$service->GetRecipe($id, self::MEMBER)['rights']);

		foreach ([self::OTHER, self::MANAGER, self::ADMIN] as $stranger)
		{
			$this->expectRefusal(fn() => self::$service->GetRecipe($id, $stranger), 404, 'not_found');
			self::assertNotContains($id, array_column(self::$service->ListRecipes($stranger), 'id'), "user $stranger lists no recipe they hold no share on");
		}
		self::assertContains($id, array_column(self::$service->ListRecipes(self::MEMBER), 'id'));

		$this->expectRefusal(fn() => self::$service->GetRecipe(987654, self::OWNER), 404, 'not_found');
	}

	public function testTheGlobalPermissionIsCheckedAsWellAsTheShare(): void
	{
		$product = self::product('CR global', 10);
		$id = self::recipe([self::line($product, 1)]);

		$this->expectRefusal(fn() => self::$service->CreateRecipe('No consume right', null, [self::line($product, 1)], self::VIEWER), 403, 'permission_missing');
		self::$service->SetShare($id, self::VIEWER, ['consume' => true], self::OWNER);
		self::assertSame($id, self::$service->GetRecipe($id, self::VIEWER)['id'], 'a viewer reads a recipe shared with them');
		$this->expectRefusal(fn() => self::$service->Consume($id, 'viewer-1', null, null, self::VIEWER), 403, 'permission_missing');
		self::assertSame(10.0, self::onHand($product), 'an inert share booked nothing');
	}

	// --- Consuming ---------------------------------------------------------------------------

	public function testAMemberWithTheConsumeRightBooksEveryLineAndNoRecipeIdReachesTheLedger(): void
	{
		$a = self::product('CR line A', 10);
		$b = self::product('CR line B', 10);
		$id = self::recipe([self::line($a, 2), self::line($b, 1)]);
		self::$service->SetShare($id, self::MEMBER, ['consume' => true], self::OWNER);

		$event = self::$service->Consume($id, 'member-req-1', null, null, self::MEMBER);

		self::assertSame('booked', $event['state']);
		self::assertFalse($event['replayed']);
		self::assertSame([8.0, 9.0], [self::onHand($a), self::onHand($b)]);
		self::assertSame(2, (int)self::$db->query('SELECT count(*) FROM stock_log WHERE transaction_id = ' . self::$db->quote($event['transaction_id']))->fetchColumn());
		self::assertSame(0, (int)self::$db->query('SELECT count(*) FROM stock_log WHERE recipe_id IS NOT NULL AND transaction_id = ' . self::$db->quote($event['transaction_id']))->fetchColumn(),
			'stock_log.recipe_id is never set for a consumption recipe');
		// StockService books the ambient VICTUAL_USER_ID. The route always passes the signed-in user, so the
		// two agree in production; ConsumptionRecipeApiTest pins the attribution through real requests.
		self::assertSame((int)VICTUAL_USER_ID, (int)self::$db->query('SELECT user_id FROM stock_log WHERE transaction_id = ' . self::$db->quote($event['transaction_id']) . ' LIMIT 1')->fetchColumn());
		self::assertCount(2, $event['lines']);
	}

	public function testAMemberWithoutTheConsumeRightIsRefusedAndAStrangerSeesNothing(): void
	{
		$product = self::product('CR no consume', 10);
		$id = self::recipe([self::line($product, 1)]);
		self::$service->SetShare($id, self::MEMBER, [], self::OWNER);

		$this->expectRefusal(fn() => self::$service->Consume($id, 'r1', null, null, self::MEMBER), 403, 'right_missing');
		$this->expectRefusal(fn() => self::$service->Consume($id, 'r2', null, null, self::OTHER), 404, 'not_found');
		self::assertSame(10.0, self::onHand($product));
		self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM consumption_events WHERE source_event_id IN ('r1', 'r2')")->fetchColumn(), 'a refused consumption leaves no event');
	}

	public function testALineInBoxesBooksTenTabletsEach(): void
	{
		$product = self::product('CR boxes', 40);
		$id = self::recipe([self::line($product, 1.5, self::$box)]);

		self::$service->Consume($id, null, null, null, self::OWNER);

		self::assertSame(25.0, self::onHand($product), '1.5 boxes at 10 tablets per box');
	}

	public function testAUnitWithNoEnteredConversionIsRefusedWhenTheRecipeIsWritten(): void
	{
		$product = self::product('CR no conversion', 10, 0, false);
		$this->expectRefusal(fn() => self::recipe([self::line($product, 1, self::$box)]), 422, 'no_conversion');
	}

	public function testAConversionRemovedAfterwardsRefusesTheConsumptionAndBooksNothing(): void
	{
		$product = self::product('CR conversion removed', 40);
		$id = self::recipe([self::line($product, 1, self::$box)]);
		self::$db->exec("DELETE FROM quantity_unit_conversions WHERE product_id = $product");
		self::$db->exec("DELETE FROM cache__quantity_unit_conversions_resolved WHERE product_id = $product");

		$this->expectRefusal(fn() => self::$service->Consume($id, 'noconv', null, null, self::OWNER), 422, 'no_conversion');
		self::assertSame(40.0, self::onHand($product));
	}

	public function testALaterLineWithTooLittleStockRollsBackTheEarlierOnes(): void
	{
		$first = self::product('CR rollback first', 10);
		$second = self::product('CR rollback second', 1);
		$id = self::recipe([self::line($first, 3), self::line($second, 2)]);

		$this->expectRefusal(fn() => self::$service->Consume($id, 'rollback-1', null, null, self::OWNER), 409, 'stock_refused');

		self::assertSame([10.0, 1.0], [self::onHand($first), self::onHand($second)], 'no partial deduction');
		self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM consumption_events WHERE source_event_id = 'rollback-1'")->fetchColumn(), 'no event row');
		self::assertSame(0, (int)self::$db->query('SELECT count(*) FROM stock_log WHERE product_id IN (' . $first . ',' . $second . ") AND transaction_type = 'consume'")->fetchColumn());
	}

	public function testARepeatedRequestIdBooksOnceAndAnotherUsersIdIsIndependent(): void
	{
		$product = self::product('CR replay', 10);
		$id = self::recipe([self::line($product, 1)]);
		self::$service->SetShare($id, self::MEMBER, ['consume' => true], self::OWNER);

		$first = self::$service->Consume($id, 'same-id', null, null, self::OWNER);
		$second = self::$service->Consume($id, 'same-id', null, null, self::OWNER);
		$member = self::$service->Consume($id, 'same-id', null, null, self::MEMBER);

		self::assertFalse($first['replayed']);
		self::assertTrue($second['replayed']);
		self::assertSame($first['transaction_id'], $second['transaction_id']);
		self::assertFalse($member['replayed'], 'the same id from another user is a different event');
		self::assertNotSame($first['transaction_id'], $member['transaction_id']);
		self::assertSame(8.0, self::onHand($product), 'two bookings, not three');
	}

	public function testALocationIsNeverSilentlySwappedForAnother(): void
	{
		$product = self::product('CR organizers', 5, 20);
		$id = self::recipe([self::line($product, 6)]);

		$this->expectRefusal(fn() => self::$service->Consume($id, 'org-a', self::$organizerA, null, self::OWNER), 409, 'stock_refused');
		self::assertSame([5.0, 20.0], [self::onHand($product, self::$organizerA), self::onHand($product, self::$organizerB)], 'organizer B was not charged for organizer A');

		self::$service->Consume($id, 'org-b', self::$organizerB, null, self::OWNER);
		self::assertSame([5.0, 14.0], [self::onHand($product, self::$organizerA), self::onHand($product, self::$organizerB)]);
	}

	public function testALateEventBooksTheDateWrittenInItsOwnOffset(): void
	{
		$product = self::product('CR late', 10);
		$id = self::recipe([self::line($product, 1)]);
		$stamp = (new \DateTimeImmutable('-2 days', new \DateTimeZone('-05:00')))->setTime(21, 30)->format('Y-m-d\TH:i:sP');

		$event = self::$service->Consume($id, 'late', null, $stamp, self::OWNER);

		self::assertSame(substr($stamp, 0, 10), self::$db->query('SELECT used_date::text FROM stock_log WHERE transaction_id = ' . self::$db->quote($event['transaction_id']))->fetchColumn(),
			'the local date of the offset the client wrote, not the UTC date');
		$this->expectRefusal(fn() => self::$service->Consume($id, 'future', null, gmdate('Y-m-d\TH:i:s\Z', time() + 3600), self::OWNER), 422, 'future_occurred_at');
		$this->expectRefusal(fn() => self::$service->Consume($id, 'naive', null, '2026-10-09T10:00:00', self::OWNER), 422, 'invalid_occurred_at');
	}

	public function testEventLinesFollowTheBookingsNotTheRecipeLines(): void
	{
		$product = self::product('CR two rows', 0);
		$stock = StockService::GetInstance();
		$stock->AddProduct($product, 2, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$organizerA);
		$stock->AddProduct($product, 2, '2999-12-30', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-02', null, self::$organizerA);
		$id = self::recipe([self::line($product, 3)]);

		$event = self::$service->Consume($id, 'two-rows', null, null, self::OWNER);

		self::assertCount(2, $event['lines'], 'one recipe line taken from two stock rows is two event lines');
		self::assertEqualsWithDelta(3.0, array_sum(array_column($event['lines'], 'amount')), 1e-9);
		self::assertSame(self::$organizerA, $event['lines'][0]['location_id']);
		self::assertNotNull($event['lines'][0]['stock_log_id']);
	}

	// --- Editing and history -----------------------------------------------------------------

	public function testAnEditLeavesWhatPastEventsBookedUntouchedAndUndoUsesTheRecordedBookings(): void
	{
		$product = self::product('CR history', 20);
		$id = self::recipe([self::line($product, 4)]);
		$event = self::$service->Consume($id, 'history', null, null, self::OWNER);
		self::assertSame(16.0, self::onHand($product));

		self::$service->UpdateRecipe($id, ['name' => 'Renamed', 'lines' => [self::line($product, 9)]], self::OWNER);
		self::assertSame(9.0, self::$service->GetRecipe($id, self::OWNER)['lines'][0]['amount']);
		self::assertEquals(4.0, array_sum(array_column(self::$service->ListEvents($id, self::OWNER)[0]['lines'], 'amount')), 'the event still says 4');

		$undone = self::$service->UndoConsumption($id, $event['id'], self::OWNER);
		self::assertSame('undone', $undone['state']);
		self::assertSame(20.0, self::onHand($product), 'undo restored what was booked, not what the recipe says now');
		$this->expectRefusal(fn() => self::$service->UndoConsumption($id, $event['id'], self::OWNER), 409, 'invalid_state');
	}

	public function testEditNeedsTheEditRightAndAStrangerGets404(): void
	{
		$product = self::product('CR edit rights', 10);
		$id = self::recipe([self::line($product, 1)]);
		self::$service->SetShare($id, self::MEMBER, ['consume' => true], self::OWNER);

		$this->expectRefusal(fn() => self::$service->UpdateRecipe($id, ['name' => 'Nope'], self::MEMBER), 403, 'right_missing');
		$this->expectRefusal(fn() => self::$service->UpdateRecipe($id, ['name' => 'Nope'], self::OTHER), 404, 'not_found');
		self::$service->SetShare($id, self::MEMBER, ['consume' => true, 'edit' => true], self::OWNER);
		self::$service->UpdateRecipe($id, ['note' => 'by member'], self::MEMBER);
		self::assertSame('by member', self::$service->GetRecipe($id, self::OWNER)['note']);
	}

	public function testUndoThroughTheRecipeNeedsTheUndoRightAndOnlyCoversTheCallersOwnEvents(): void
	{
		$product = self::product('CR undo rights', 10);
		$id = self::recipe([self::line($product, 2)]);
		self::$service->SetShare($id, self::MEMBER, ['consume' => true, 'undo' => true], self::OWNER);
		$event = self::$service->Consume($id, 'undo-me', null, null, self::MEMBER);

		$this->expectRefusal(fn() => self::$service->UndoConsumption($id, $event['id'], self::OWNER), 404, 'not_found');
		self::assertSame(8.0, self::onHand($product), "the owner cannot reach the member's event");
		self::assertSame([], self::$service->ListEvents($id, self::OWNER), "the owner's list holds none of the member's events");
		self::$service->UndoConsumption($id, $event['id'], self::MEMBER);
		self::assertSame(10.0, self::onHand($product));
	}

	public function testRecipeUndoAfterADirectStockUndoOnlyMarksTheEvent(): void
	{
		$product = self::product('CR direct undo', 10);
		$id = self::recipe([self::line($product, 2)]);
		$event = self::$service->Consume($id, 'direct', null, null, self::OWNER);
		StockService::GetInstance()->UndoTransaction($event['transaction_id']);

		$result = self::$service->UndoConsumption($id, $event['id'], self::OWNER);

		self::assertSame('undone', $result['state']);
		self::assertSame(10.0, self::onHand($product), 'stock restored once');
	}

	// --- Sharing -----------------------------------------------------------------------------

	public function testRevocationTakesEffectAtOnce(): void
	{
		$product = self::product('CR revoke', 10);
		$id = self::recipe([self::line($product, 1)]);
		self::$service->SetShare($id, self::MEMBER, ['consume' => true], self::OWNER);
		self::$service->Consume($id, 'before', null, null, self::MEMBER);

		self::$service->RemoveShare($id, self::MEMBER, self::OWNER);

		$this->expectRefusal(fn() => self::$service->Consume($id, 'after', null, null, self::MEMBER), 404, 'not_found');
		$this->expectRefusal(fn() => self::$service->GetRecipe($id, self::MEMBER), 404, 'not_found');
		self::assertSame(9.0, self::onHand($product));
	}

	public function testAShareHolderGrantsOnlyWhatTheyHoldAndNeverTheShareRight(): void
	{
		$product = self::product('CR delegation', 10);
		$id = self::recipe([self::line($product, 1)]);
		self::$service->SetShare($id, self::MEMBER, ['consume' => true, 'share' => true], self::OWNER);

		self::$service->SetShare($id, self::OTHER, ['consume' => true], self::MEMBER);
		self::assertTrue(self::$service->GetRecipe($id, self::OTHER)['rights']['consume']);

		$this->expectRefusal(fn() => self::$service->SetShare($id, self::OTHER, ['consume' => true, 'edit' => true], self::MEMBER), 403, 'right_missing');
		$this->expectRefusal(fn() => self::$service->SetShare($id, self::OTHER, ['share' => true], self::MEMBER), 403, 'right_missing');
		$this->expectRefusal(fn() => self::$service->SetShare($id, self::MEMBER, ['consume' => true], self::MEMBER), 403, 'right_missing');
		$this->expectRefusal(fn() => self::$service->SetShare($id, self::OWNER, ['consume' => true], self::MEMBER), 422, 'invalid_user');

		self::$service->SetShare($id, self::OTHER, ['consume' => true, 'edit' => true], self::OWNER);
		$this->expectRefusal(fn() => self::$service->RemoveShare($id, self::OTHER, self::MEMBER), 403, 'right_missing');
		self::assertCount(1, array_filter(self::$service->ListShares($id, self::OWNER), fn($s) => $s['user_id'] === self::OTHER && $s['rights']['edit']));
		self::assertSame([], array_filter(self::$service->ListShares($id, self::MEMBER), fn($s) => $s['user_id'] === self::OTHER), 'a holder does not see a share they could not change');

		$this->expectRefusal(fn() => self::$service->ListShares($id, self::OTHER), 403, 'right_missing');
		$this->expectRefusal(fn() => self::$service->ListShares($id, self::MANAGER), 404, 'not_found');
	}

	public function testAShareToAUserByNameNeedsAnExistingNonOwnerName(): void
	{
		$product = self::product('CR by name', 10);
		$id = self::recipe([self::line($product, 1)]);

		self::$service->SetShare($id, 'cr-member', ['consume' => true], self::OWNER);
		self::assertSame($id, self::$service->GetRecipe($id, self::MEMBER)['id']);
		$this->expectRefusal(fn() => self::$service->SetShare($id, 'nobody-by-that-name', [], self::OWNER), 422, 'invalid_user');
		$this->expectRefusal(fn() => self::$service->SetShare($id, 'cr-owner', [], self::OWNER), 422, 'invalid_user');
		$this->expectRefusal(fn() => self::$service->SetShare($id, 'cr-member', [], self::OTHER), 404, 'not_found');
	}

	public function testAnInertShareIsAcceptedAndNamesNoPermission(): void
	{
		$product = self::product('CR inert', 10);
		$id = self::recipe([self::line($product, 1)]);
		self::$service->SetShare($id, self::VIEWER, ['consume' => true, 'edit' => true], self::OWNER);

		$before = \Victual\Controllers\Users\User::ResolvedPermissionNames(self::VIEWER);
		self::$service->SetShare($id, self::VIEWER, ['consume' => true, 'edit' => true, 'undo' => true], self::OWNER);
		self::assertSame($before, \Victual\Controllers\Users\User::ResolvedPermissionNames(self::VIEWER), 'a share writes no permission');
	}

	public function testTransferNeedsAShareAndLeavesTheOldOwnerWithEveryRight(): void
	{
		$product = self::product('CR transfer', 10);
		$id = self::recipe([self::line($product, 1)]);

		$this->expectRefusal(fn() => self::$service->TransferOwnership($id, self::MEMBER, self::OWNER), 422, 'must_hold_share');
		self::$service->SetShare($id, self::MEMBER, ['consume' => true], self::OWNER);
		$this->expectRefusal(fn() => self::$service->TransferOwnership($id, self::MEMBER, self::MEMBER), 403, 'right_missing');

		self::$service->TransferOwnership($id, self::MEMBER, self::OWNER);

		self::assertTrue(self::$service->GetRecipe($id, self::MEMBER)['is_owner']);
		self::assertSame(['read' => true, 'consume' => true, 'edit' => true, 'undo' => true, 'share' => true], self::$service->GetRecipe($id, self::OWNER)['rights']);
		self::assertFalse(self::$service->GetRecipe($id, self::OWNER)['is_owner']);
		self::$service->RemoveShare($id, self::OWNER, self::MEMBER);
		$this->expectRefusal(fn() => self::$service->GetRecipe($id, self::OWNER), 404, 'not_found');
	}

	public function testOnlyTheOwnerDeletesAndEventsOutliveTheRecipe(): void
	{
		$product = self::product('CR delete', 10);
		$id = self::recipe([self::line($product, 1)]);
		self::$service->SetShare($id, self::MEMBER, ['consume' => true, 'edit' => true, 'undo' => true, 'share' => true], self::OWNER);
		$event = self::$service->Consume($id, 'survives', null, null, self::OWNER);

		$this->expectRefusal(fn() => self::$service->DeleteRecipe($id, self::MEMBER), 403, 'right_missing');
		$this->expectRefusal(fn() => self::$service->DeleteRecipe($id, self::OTHER), 404, 'not_found');
		self::$service->DeleteRecipe($id, self::OWNER);

		$this->expectRefusal(fn() => self::$service->GetRecipe($id, self::MEMBER), 404, 'not_found');
		$row = self::$db->query('SELECT recipe_id, state, transaction_id FROM consumption_events WHERE id = ' . $event['id'])->fetch(PDO::FETCH_ASSOC);
		self::assertNull($row['recipe_id']);
		self::assertSame('booked', $row['state']);
		self::assertSame(9.0, self::onHand($product), 'stock history is unchanged');
	}

	public function testAShareReadIsNeverAPermissionRead(): void
	{
		$product = self::product('CR admin', 10);
		$id = self::recipe([self::line($product, 1)]);

		foreach ([self::ADMIN, self::MANAGER] as $privileged)
		{
			$this->expectRefusal(fn() => self::$service->Consume($id, 'priv-' . $privileged, null, null, $privileged), 404, 'not_found');
			$this->expectRefusal(fn() => self::$service->SetShare($id, self::OTHER, [], $privileged), 404, 'not_found');
			$this->expectRefusal(fn() => self::$service->DeleteRecipe($id, $privileged), 404, 'not_found');
		}
		self::assertSame(10.0, self::onHand($product));
	}

	public function testValidationRefusesBadNamesLinesAndRequestIds(): void
	{
		$product = self::product('CR validation', 10);
		$this->expectRefusal(fn() => self::$service->CreateRecipe('   ', null, [self::line($product, 1)], self::OWNER), 422, 'invalid_name');
		$this->expectRefusal(fn() => self::$service->CreateRecipe('Empty', null, [], self::OWNER), 422, 'invalid_lines');
		$this->expectRefusal(fn() => self::$service->CreateRecipe('Zero', null, [self::line($product, 0)], self::OWNER), 422, 'invalid_line');
		$this->expectRefusal(fn() => self::$service->CreateRecipe('No product', null, [self::line(987654, 1)], self::OWNER), 422, 'invalid_product');
		$this->expectRefusal(fn() => self::$service->CreateRecipe('No unit', null, [['product_id' => $product, 'amount' => 1, 'qu_id' => 987654]], self::OWNER), 422, 'invalid_unit');
		$id = self::recipe([self::line($product, 1)]);
		$this->expectRefusal(fn() => self::$service->Consume($id, 'has space', null, null, self::OWNER), 422, 'invalid_request_id');
		$this->expectRefusal(fn() => self::$service->Consume($id, 'loc', 987654, null, self::OWNER), 422, 'invalid_location');
	}
}
