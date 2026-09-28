<?php

namespace Victual\Services;

use Victual\Controllers\Users\PermissionMissingException;
use Victual\Controllers\Users\User;

/**
 * Business logic for chore tracking: execution journal, next-execution user assignment
 * and merging of chores.
 */
class ChoresService extends BaseService
{
	/**
	 * Values of chores.assignment_type: how the user for the next execution is picked.
	 */
	const CHORE_ASSIGNMENT_TYPE_IN_ALPHABETICAL_ORDER = 'in-alphabetical-order';
	const CHORE_ASSIGNMENT_TYPE_NO_ASSIGNMENT = 'no-assignment';
	const CHORE_ASSIGNMENT_TYPE_RANDOM = 'random';
	const CHORE_ASSIGNMENT_TYPE_WHO_LEAST_DID_FIRST = 'who-least-did-first';

	/**
	 * Values of chores.period_type: the scheduling scheme, interpreted together with
	 * period_interval and period_config ("manually" means no schedule at all,
	 * "adaptive" derives the interval from past executions).
	 */
	const CHORE_PERIOD_TYPE_HOURLY = 'hourly';
	const CHORE_PERIOD_TYPE_DAILY = 'daily';
	const CHORE_PERIOD_TYPE_MANUALLY = 'manually';
	const CHORE_PERIOD_TYPE_MONTHLY = 'monthly';
	const CHORE_PERIOD_TYPE_WEEKLY = 'weekly';
	const CHORE_PERIOD_TYPE_YEARLY = 'yearly';
	const CHORE_PERIOD_TYPE_ADAPTIVE = 'adaptive';

	/**
	 * Recalculates and stores which user the next execution of the given chore is
	 * assigned to, honoring the chore's assignment type (a manual reschedule
	 * assignment takes precedence over any strategy).
	 *
	 * A chore whose assignment group resolves to nobody - no assignment_config, or one
	 * naming only users that no longer exist - is assigned null, whatever its
	 * assignment_type says. Every strategy needs somebody to choose between and none of
	 * them can invent one, so "nobody" is the answer rather than a failure; it is what
	 * CHORE_ASSIGNMENT_TYPE_NO_ASSIGNMENT stores too.
	 *
	 * @param int $choreId
	 * @throws \Exception When the chore does not exist
	 */
	public function CalculateNextExecutionAssignment($choreId)
	{
		if (!$this->ChoreExists($choreId))
		{
			throw new \Exception('Chore does not exist');
		}

		$chore = $this->DB->chores($choreId);

		if (!empty($chore->rescheduled_next_execution_assigned_to_user_id))
		{
			$nextExecutionUserId = $chore->rescheduled_next_execution_assigned_to_user_id;
		}
		else
		{
			$choreLastTrackedTime = $this->DB->chores_log()->where('chore_id = :1 AND undone = 0', $choreId)->max('tracked_time');
			$lastChoreLogRow = $this->DB->chores_log()->where('chore_id = :1 AND tracked_time = :2 AND undone = 0', $choreId, $choreLastTrackedTime)->orderBy('row_created_timestamp', 'DESC')->fetch();

			// A chore that has never been executed - every chore, once - has no last row,
			// and reading ->done_by_user_id off it was "Attempt to read property on null".
			// A warning rather than a 500, so it never surfaced as a failure; it merely
			// fired on every newly created chore and evaluated to the null it is written
			// out as here. Only the alphabetical strategy reads the value, and "nobody has
			// done it yet" matches no user there, which is what starts the rotation at the
			// first name.
			$lastDoneByUserId = $lastChoreLogRow === null ? null : $lastChoreLogRow->done_by_user_id;

			$users = UsersService::GetInstance()->GetUsersAsDto();
			$assignedUsers = [];
			foreach ($users as $user)
			{
				if (!empty($chore->assignment_config) && in_array($user->id, explode(',', $chore->assignment_config)))
				{
					$assignedUsers[] = $user;
				}
			}

			$nextExecutionUserId = null;
			if ($chore->assignment_type == self::CHORE_ASSIGNMENT_TYPE_RANDOM)
			{
				// Random assignment and only 1 user in the group? Well, ok - will be hard to guess the next one...
				if (count($assignedUsers) == 1)
				{
					$nextExecutionUserId = array_shift($assignedUsers)->id;
				}
				elseif (count($assignedUsers) > 1)
				{
					$nextExecutionUserId = $assignedUsers[array_rand($assignedUsers)]->id;
				}

				// No third branch on purpose: nobody assigned means there is nobody to pick,
				// and the answer is the null this variable already holds - the same value the
				// no-assignment path gives. It was `else` until the empty case was found,
				// which made array_rand() the branch an empty group fell into; array_rand([])
				// is a ValueError, \Error is deliberately not caught (plan 11), so
				// POST /api/chores/{id}/execute answered 500 for a chore whose assignment_type
				// is "random" and whose assignment_config is empty. An empty group is not only
				// a chore nobody was ever assigned to: assignment_config naming a user that has
				// since been deleted resolves to the same nothing, so this cannot be closed by
				// validating the write.
			}
			elseif ($chore->assignment_type == self::CHORE_ASSIGNMENT_TYPE_IN_ALPHABETICAL_ORDER)
			{
				usort($assignedUsers, function ($a, $b)
				{
					return strcmp($a->display_name, $b->display_name);
				});

				$nextRoundMatches = false;
				foreach ($assignedUsers as $user)
				{
					if ($nextRoundMatches)
					{
						$nextExecutionUserId = $user->id;
						break;
					}

					if ($user->id == $lastDoneByUserId)
					{
						$nextRoundMatches = true;
					}
				}

				// If nothing has matched, probably it was the last user in the sorted list -> the first one is the next one
				// (or there is no list at all, which array_shift() answers with null rather
				// than a user - the same empty group the random branch above documents. Reading
				// ->id off that null is a warning rather than an \Error, so this was never the
				// 500 the random branch was; it evaluated to the null the guard now writes
				// deliberately, and said so in the log on every request.)
				if ($nextExecutionUserId == null && count($assignedUsers) > 0)
				{
					$nextExecutionUserId = array_shift($assignedUsers)->id;
				}
			}
			elseif ($chore->assignment_type == self::CHORE_ASSIGNMENT_TYPE_WHO_LEAST_DID_FIRST)
			{
				$row = $this->DB->chores_execution_users_statistics()->where('chore_id = :1', $choreId)->orderBy('execution_count')->limit(1)->fetch();
				if ($row != null)
				{
					$nextExecutionUserId = $row->user_id;
				}
			}
		}

		$chore->update([
			'next_execution_assigned_to_user_id' => $nextExecutionUserId
		]);
	}

	/**
	 * Returns detail information for one chore.
	 *
	 * @return array {chore: \LessQL\Row, last_tracked: string|null, tracked_count: int, last_done_by: object|null, next_estimated_execution_time: string|null, next_execution_assigned_user: object|null, average_execution_frequency_hours: float|null}
	 * @throws \Exception When the chore does not exist
	 */
	public function GetChoreDetails(int $choreId)
	{
		if (!$this->ChoreExists($choreId))
		{
			throw new \Exception('Chore does not exist');
		}

		$users = UsersService::GetInstance()->GetUsersAsDto();

		$chore = $this->DB->chores($choreId);
		$choreTrackedCount = $this->DB->chores_log()->where('chore_id = :1 AND undone = 0 AND skipped = 0', $choreId)->count();
		$choreLastTrackedTime = $this->DB->chores_log()->where('chore_id = :1 AND undone = 0 AND skipped = 0', $choreId)->max('tracked_time');
		$nextExecutionTime = $this->DB->chores_current()->where('chore_id', $choreId)->min('next_estimated_execution_time');
		$averageExecutionFrequency = $this->DB->chores_execution_average_frequency()->where('chore_id', $choreId)->min('average_frequency_hours');

		$lastChoreLogRow = $this->DB->chores_log()->where('chore_id = :1 AND tracked_time = :2 AND undone = 0', $choreId, $choreLastTrackedTime)->fetch();
		$lastDoneByUser = null;
		if ($lastChoreLogRow !== null && !empty($lastChoreLogRow))
		{
			$lastDoneByUser = FindObjectInArrayByPropertyValue($users, 'id', $lastChoreLogRow->done_by_user_id);
		}

		$nextExecutionAssignedUser = null;
		if (!empty($chore->next_execution_assigned_to_user_id))
		{
			$nextExecutionAssignedUser = FindObjectInArrayByPropertyValue($users, 'id', $chore->next_execution_assigned_to_user_id);
		}

		return [
			'chore' => $chore,
			'last_tracked' => $choreLastTrackedTime,
			'tracked_count' => $choreTrackedCount,
			'last_done_by' => $lastDoneByUser,
			'next_estimated_execution_time' => $nextExecutionTime,
			'next_execution_assigned_user' => $nextExecutionAssignedUser,
			'average_execution_frequency_hours' => $averageExecutionFrequency
		];
	}

	/**
	 * Returns the rows of the chores_current view (next estimated execution time per
	 * chore), each enriched with the assigned user DTO as ->next_execution_assigned_user.
	 *
	 * @return \LessQL\Result
	 */
	public function GetCurrent()
	{
		$users = UsersService::GetInstance()->GetUsersAsDto();

		$chores = $this->DB->chores_current();
		foreach ($chores as $chore)
		{
			if (!empty($chore->next_execution_assigned_to_user_id))
			{
				$chore->next_execution_assigned_user = FindObjectInArrayByPropertyValue($users, 'id', $chore->next_execution_assigned_to_user_id);
			}
			else
			{
				$chore->next_execution_assigned_user = null;
			}
		}

		return $chores;
	}

	/**
	 * Logs an execution (or skip) of the given chore and handles the follow-up work:
	 * consuming the linked product where configured, clearing any manual reschedule
	 * and recalculating the next execution assignment.
	 *
	 * Authorization (issue #604, the same composite-permission class as #532 and #591):
	 * CHORE_TRACK_EXECUTION alone authorizes tracking the chore, not consuming stock - when
	 * the chore has consume_product_on_execution set (so this call is actually about to
	 * consume a product, via StockService::ConsumeProduct() below), it also requires
	 * STOCK_CONSUME, the same permission POST /api/stock/products/{id}/consume already
	 * requires for that same booking (StockApiController::ConsumeProduct()). Checked first,
	 * inside the transaction and before any write - including the chores_log insert itself -
	 * so a refusal leaves no chores_log row, exactly as a chore that never reaches this method
	 * at all. A chore that consumes nothing (consume_product_on_execution is 0, or no
	 * product_id) needs only CHORE_TRACK_EXECUTION, unchanged.
	 *
	 * $request shapes the refusal the same way RecipesService::ConsumeRecipe()'s STOCK_PURCHASE
	 * check and UndoChoreExecution()'s STOCK_EDIT check both do: User::HasPermissions() only
	 * ever reads the ambient VICTUAL_USER_ID, never $request, so the permission is checked
	 * unconditionally rather than only when a request happens to be given - a caller with no
	 * request (this method's own tests, DemoDataGeneratorService) must already hold
	 * STOCK_CONSUME for a stock-consuming chore, exactly like one that does. With a request,
	 * the refusal is the same PermissionMissingException User::CheckPermission() throws
	 * everywhere else (HandleApiCall() answers 403); without one, a plain \Exception. Either
	 * way nothing is booked and no chores_log row is written.
	 *
	 * @param string $trackedTime "Y-m-d H:i:s"; truncated to the day for chores which track the date only
	 * @param int $doneBy User id of the executing user, defaults to the current user
	 * @param bool $skipped True to record a skip instead of an execution (not possible for unscheduled chores)
	 * @param \Psr\Http\Message\ServerRequestInterface|null $request Only shapes the exception a
	 *              missing STOCK_CONSUME throws for a stock-consuming chore; the permission
	 *              check itself is unconditional (see above)
	 * @return int The id of the created log row
	 * @throws \Exception When the chore or user does not exist, the chore is inactive, a chore
	 *              that consumes a product on execution has no product_amount configured, a
	 *              manually scheduled chore is skipped, or the acting user lacks STOCK_CONSUME
	 *              for a chore that consumes a product and no request was given to shape a
	 *              PermissionMissingException instead
	 * @throws \Victual\Controllers\Users\PermissionMissingException When a request was given and
	 *              the acting user lacks STOCK_CONSUME for a chore that consumes a product
	 */
	public function TrackChore(int $choreId, string $trackedTime, $doneBy = VICTUAL_USER_ID, $skipped = false, $request = null)
	{
		if (!$this->ChoreExists($choreId))
		{
			throw new \Exception('Chore does not exist');
		}

		$userRow = $this->DB->users()->where('id = :1', $doneBy)->fetch();
		if ($userRow === null)
		{
			throw new \Exception('User does not exist');
		}

		$chore = $this->DB->chores($choreId);

		// Issue #506 (audit M6, validation half): neither of these was checked at all, so an
		// inactive chore could still be tracked (the "active" column exists purely as a list
		// filter - ChoresController's "chores" queries already restrict to "active = 1" - and
		// tracking bypassed it entirely), and a chore configured to consume a product on
		// execution but left with no product_amount reached StockService::ConsumeProduct()'s
		// non-nullable `float $amount` parameter with null - a PHP TypeError, not a silent
		// success: the request 500ed (uncaught by HandleApiCall()'s generic \Exception catch,
		// which does not catch \TypeError) and the transaction rolled back, so nothing was
		// ever booked. That is still the wrong refusal - an opaque 500 instead of the 400 a
		// misconfigured chore should get - which is what these checks fix. Checked here,
		// before any write (including the chores_log insert below) and before this method's
		// own STOCK_CONSUME permission check further down (issue #604/#606): a caller who
		// lacks STOCK_CONSUME but tracks an invalid chore now gets 400, not 403 - either way
		// nothing is written.
		if ((int)$chore->active !== 1)
		{
			throw new \Exception('Chore is inactive');
		}

		if ($chore->consume_product_on_execution == 1 && !empty($chore->product_id) && $chore->product_amount === null)
		{
			throw new \Exception('Chore consumes a product on execution but has no product_amount configured');
		}

		if ($chore->track_date_only == 1)
		{
			$trackedTime = substr($trackedTime, 0, 10) . ' 00:00:00';
		}

		if ($skipped)
		{
			if ($chore->period_type == self::CHORE_PERIOD_TYPE_MANUALLY)
			{
				throw new \Exception('Chores without a schedule can\'t be skipped');
			}
		}

		$scheduledExecutionTime = $this->DB->chores_current()->where('chore_id = :1', $chore->id)->fetch()->next_estimated_execution_time;

		// The execution log entry, the linked stock consumption and the reschedule/assignment
		// bookkeeping are one business operation (issue #494/H5): consuming the linked
		// product can refuse (not enough stock), and a refusal must not leave the log entry
		// it was meant to accompany committed on its own. StockService::ConsumeProduct() opens
		// its own InTransaction() internally, which joins this one (DatabaseService::InTransaction()
		// lets a nested call join rather than nest) rather than being able to commit or roll
		// back independently of it.
		return DatabaseService::GetInstance()->InTransaction(function () use ($choreId, $chore, $trackedTime, $doneBy, $skipped, $scheduledExecutionTime, $request)
		{
			// Checked before any write (issue #604) - including the chores_log insert just
			// below, so a refusal leaves no row at all, not merely an execution with no
			// linked consumption.
			if ($chore->consume_product_on_execution == 1 && !empty($chore->product_id) && !User::HasPermissions(User::PERMISSION_STOCK_CONSUME))
			{
				if ($request !== null)
				{
					throw new PermissionMissingException($request, User::PERMISSION_STOCK_CONSUME);
				}

				throw new \Exception('Permission missing: ' . User::PERMISSION_STOCK_CONSUME);
			}

			$logRow = $this->DB->chores_log()->createRow([
				'chore_id' => $choreId,
				'tracked_time' => $trackedTime,
				'done_by_user_id' => $doneBy,
				'skipped' => BoolToInt($skipped),
				'scheduled_execution_time' => $scheduledExecutionTime
			]);
			$logRow->save();
			$lastInsertId = $this->DB->lastInsertId();

			if ($chore->consume_product_on_execution == 1 && !empty($chore->product_id))
			{
				$transactionId = uniqid();
				StockService::GetInstance()->ConsumeProduct($chore->product_id, $chore->product_amount, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $transactionId, true);

				// Issue #506 (maintainer decision D5, round 4): the explicit link
				// UndoChoreExecution() undoes this consumption through - recorded here,
				// inside this same transaction, rather than derived after the fact (an
				// earlier round tried deriving it from PostgreSQL's `xmin`, which a
				// validator proved identifies a whole transaction rather than one
				// execution - see migrations/0291.pgsql.sql).
				$logRow->update([
					'stock_transaction_id' => $transactionId
				]);
			}

			if (!empty($chore->rescheduled_date))
			{
				$chore->update([
					'rescheduled_date' => null
				]);
			}

			if (!empty($chore->rescheduled_next_execution_assigned_to_user_id))
			{
				$chore->update([
					'rescheduled_next_execution_assigned_to_user_id' => null
				]);
			}

			$this->CalculateNextExecutionAssignment($choreId);

			return $lastInsertId;
		});
	}

	/**
	 * Marks a chore execution log entry as undone (the row is kept, not deleted),
	 * undoes the stock consumption it booked (when there is one), and recalculates the
	 * next execution assignment.
	 *
	 * Issue #506 (maintainer decision D1, #487 remediation): undoing an execution that
	 * consumed stock must undo that consumption too, atomically with the chore undo - if
	 * the stock undo is refused (e.g. a later booking now depends on it), nothing here
	 * commits either.
	 *
	 * The link is chores_log.stock_transaction_id (migrations/0291.pgsql.sql, maintainer
	 * decision D5, round 4): TrackChore() records the transaction_id its own consumption
	 * booked, in the same database transaction, directly on the row. Two earlier rounds
	 * tried to derive the link after the fact instead - first from (product_id,
	 * row_created_timestamp), then from PostgreSQL's `xmin` system column - and a
	 * validator broke each: a timestamp match reversed an unrelated same-second
	 * consumption, and `xmin` identifies a whole database transaction rather than one
	 * execution, so every chores_log row DatabaseImporter::Import() writes in its single
	 * transaction shared an `xmin` with every stock_log row it also wrote. An explicit
	 * column has neither failure mode: it names one specific transaction_id, or it is
	 * NULL, decided once, when the execution's own consumption (if any) was booked.
	 *
	 * A NULL stock_transaction_id - an execution that never consumed anything
	 * (consume_product_on_execution was 0, or the chore named no product), one recorded
	 * before this column existed, or one an import brought in from another database -
	 * undoes the chore alone, exactly as before decision D1 (its "legacy executions"
	 * case), rather than refusing.
	 *
	 * A stock_transaction_id that is set but names no still-live (undone = 0) stock_log
	 * row also undoes the chore alone (issue #506 round 5): the stock side of this
	 * execution was already undone by some other path - directly through the stock
	 * journal, for instance - and StockService::UndoTransaction() refuses outright when
	 * every booking of a transaction id is already undone ("This transaction was not
	 * found or already undone"). Calling it anyway would make this chore's own undo fail
	 * every time, forever, over stock that is already back the way it was - the chore
	 * would never be undoable again. A transaction with *some* bookings still live and
	 * some already undone is different and is not this method's problem to solve:
	 * UndoTransaction() itself only ever reverses the still-live remainder of a
	 * transaction (never the whole original set), which is exactly right here too, so
	 * that ordinary partial case reaches it unchanged, still atomic, still refusing
	 * whole when any one of that remainder cannot be undone.
	 *
	 * Authorization (CWE-863, CodeRabbit finding on this PR): CHORE_UNDO_EXECUTION alone
	 * authorizes undoing the chore itself, not reversing stock - the same composite-
	 * operation rule issue #532 applied to recipe consumption (RecipesService::
	 * ConsumeRecipe(), which checks STOCK_PURCHASE before a self-production booking).
	 * So when - and only when - a live linked booking is actually about to be reversed,
	 * this also requires STOCK_EDIT, the same permission POST /api/stock/transactions/
	 * {id}/undo and POST /api/stock/bookings/{id}/undo already require for reversing
	 * stock this way (StockApiController::UndoTransaction()/UndoBooking()). A NULL link,
	 * or one whose booking is already gone, is chore-only regardless, and stays
	 * available on CHORE_UNDO_EXECUTION alone, exactly as before this check existed.
	 *
	 * $request shapes the refusal the same way RecipesService::ConsumeRecipe()'s own
	 * STOCK_PURCHASE check does: User::HasPermissions() only ever reads the ambient
	 * VICTUAL_USER_ID, never $request, so the permission is checked unconditionally
	 * rather than only when a request happens to be given (that gate is exactly the
	 * fail-open shape issue #532 closed) - a caller with no request must already hold
	 * STOCK_EDIT, exactly like one that does. With a request, the refusal is the same
	 * PermissionMissingException User::CheckPermission() throws everywhere else
	 * (HandleApiCall() answers 403); without one, a plain \Exception. Either way nothing
	 * is booked, and this whole InTransaction() unwinds before the chores_log update
	 * below runs.
	 *
	 * @param int $executionId
	 * @param \Psr\Http\Message\ServerRequestInterface|null $request Only shapes the
	 *              exception a missing STOCK_EDIT throws; the permission check itself is
	 *              unconditional (see above)
	 * @throws \Exception When the entry does not exist or was already undone, when the
	 *                     linked stock transaction has a live booking that can no longer
	 *                     be undone, or when a live linked booking would be reversed but
	 *                     the acting user lacks STOCK_EDIT
	 * @throws \Victual\Controllers\Users\PermissionMissingException When a request was
	 *              given and the acting user lacks STOCK_EDIT for an execution that will
	 *              reverse a live linked booking
	 */
	public function UndoChoreExecution($executionId, $request = null)
	{
		return DatabaseService::GetInstance()->InTransaction(function () use ($executionId, $request)
		{
			$logRow = $this->DB->chores_log()->where('id = :1 AND undone = 0', $executionId)->fetch();
			if ($logRow == null)
			{
				throw new \Exception('Execution does not exist or was already undone');
			}

			if (!empty($logRow->stock_transaction_id) && $this->HasLiveStockBooking($logRow->stock_transaction_id))
			{
				if (!User::HasPermissions(User::PERMISSION_STOCK_EDIT))
				{
					if ($request !== null)
					{
						throw new PermissionMissingException($request, User::PERMISSION_STOCK_EDIT);
					}

					throw new \Exception('Permission missing: ' . User::PERMISSION_STOCK_EDIT);
				}

				// Runs first: if this refuses (StockService::UndoTransaction(), e.g. a
				// later booking now depends on this one), the exception unwinds this
				// whole InTransaction() and the chores_log update below never happens.
				StockService::GetInstance()->UndoTransaction($logRow->stock_transaction_id);
			}

			// Update log entry
			$logRow->update([
				'undone' => 1,
				'undone_timestamp' => date('Y-m-d H:i:s')
			]);

			$this->CalculateNextExecutionAssignment($logRow->chore_id);
		});
	}

	/**
	 * Whether $transactionId still has at least one not-yet-undone stock_log booking.
	 *
	 * @param string $transactionId
	 * @return bool
	 */
	private function HasLiveStockBooking(string $transactionId): bool
	{
		return $this->DB->stock_log()->where('undone = 0 AND transaction_id = :1', $transactionId)->count() > 0;
	}

	/**
	 * Merges two chores in a transaction: reassigns the whole execution log of
	 * $choreIdToRemove to $choreIdToKeep, then deletes the removed chore.
	 *
	 * @throws \Exception When either chore does not exist or both ids are equal
	 */
	public function MergeChores(int $choreIdToKeep, int $choreIdToRemove)
	{
		if (!$this->ChoreExists($choreIdToKeep))
		{
			throw new \Exception('$choreIdToKeep does not exist or is inactive');
		}

		if (!$this->ChoreExists($choreIdToRemove))
		{
			throw new \Exception('$choreIdToRemove does not exist or is inactive');
		}

		if ($choreIdToKeep == $choreIdToRemove)
		{
			throw new \Exception('$choreIdToKeep cannot equal $choreIdToRemove');
		}

		DatabaseService::GetInstance()->InTransaction(function () use ($choreIdToKeep, $choreIdToRemove)
		{
			$choreToKeep = $this->DB->chores($choreIdToKeep);
			$choreToRemove = $this->DB->chores($choreIdToRemove);

			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE chores_log SET chore_id = ' . $choreIdToKeep . ' WHERE chore_id = ' . $choreIdToRemove);
			DatabaseService::GetInstance()->ExecuteDbStatement('DELETE FROM chores WHERE id = ' . $choreIdToRemove);
		});
	}

	/**
	 * @param int $choreId
	 * @return bool
	 */
	private function ChoreExists($choreId)
	{
		$choreRow = $this->DB->chores()->where('id = :1', $choreId)->fetch();
		return $choreRow !== null;
	}
}
