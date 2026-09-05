<?php

namespace Victual\Services;

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
	 * @param string $trackedTime "Y-m-d H:i:s"; truncated to the day for chores which track the date only
	 * @param int $doneBy User id of the executing user, defaults to the current user
	 * @param bool $skipped True to record a skip instead of an execution (not possible for unscheduled chores)
	 * @return int The id of the created log row
	 * @throws \Exception When the chore or user does not exist, or a manually scheduled chore is skipped
	 */
	public function TrackChore(int $choreId, string $trackedTime, $doneBy = VICTUAL_USER_ID, $skipped = false)
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
	}

	/**
	 * Marks a chore execution log entry as undone (the row is kept, not deleted) and
	 * recalculates the next execution assignment.
	 *
	 * @param int $executionId
	 * @throws \Exception When the entry does not exist or was already undone
	 */
	public function UndoChoreExecution($executionId)
	{
		$logRow = $this->DB->chores_log()->where('id = :1 AND undone = 0', $executionId)->fetch();
		if ($logRow == null)
		{
			throw new \Exception('Execution does not exist or was already undone');
		}

		// Update log entry
		$logRow->update([
			'undone' => 1,
			'undone_timestamp' => date('Y-m-d H:i:s')
		]);

		$this->CalculateNextExecutionAssignment($logRow->chore_id);
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
