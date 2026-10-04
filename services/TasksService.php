<?php

namespace Victual\Services;

use Victual\Services\Time\Instant;

use LessQL\Result;

/**
 * Task list operations: listing open tasks and toggling their done state.
 */
class TasksService extends BaseService
{
	/**
	 * All not-done tasks (tasks_current view), with each row enriched with an
	 * assigned_to_user object (users_dto row) and a category object - either may be
	 * null when unassigned/uncategorised.
	 */
	public function GetCurrent(): Result
	{
		$users = UsersService::GetInstance()->GetUsersAsDto();
		$categories = $this->DB->task_categories()->where('active = 1');

		$tasks = $this->DB->tasks_current();
		foreach ($tasks as $task)
		{
			if (!empty($task->assigned_to_user_id))
			{
				$task->assigned_to_user = FindObjectInArrayByPropertyValue($users, 'id', $task->assigned_to_user_id);
			}
			else
			{
				$task->assigned_to_user = null;
			}

			if (!empty($task->category_id))
			{
				$task->category = FindObjectInArrayByPropertyValue($categories, 'id', $task->category_id);
			}
			else
			{
				$task->category = null;
			}
		}

		return $tasks;
	}

	/**
	 * Marks a task done at the given time.
	 *
	 * @param int $taskId
	 * @param string $doneTime Timestamp "Y-m-d H:i:s"
	 * @return bool Always true; a missing task throws instead
	 * @throws \Exception When the task does not exist
	 */
	public function MarkTaskAsCompleted($taskId, $doneTime)
	{
		if (!$this->TaskExists($taskId))
		{
			throw new \Exception('Task does not exist');
		}

		$taskRow = $this->DB->tasks()->where('id = :1', $taskId)->fetch();
		$taskRow->update([
			'done' => 1,
			'done_timestamp' => Instant::ToWire(Instant::ParseStored($doneTime) ?? throw new \Exception('Invalid done time'))
		]);

		return true;
	}

	/**
	 * Reverts a task to not done, clearing its done timestamp.
	 *
	 * @param int $taskId
	 * @return bool Always true; a missing task throws instead
	 * @throws \Exception When the task does not exist
	 */
	public function UndoTask($taskId)
	{
		if (!$this->TaskExists($taskId))
		{
			throw new \Exception('Task does not exist');
		}

		$taskRow = $this->DB->tasks()->where('id = :1', $taskId)->fetch();
		$taskRow->update([
			'done' => 0,
			'done_timestamp' => null
		]);

		return true;
	}

	private function TaskExists($taskId)
	{
		$taskRow = $this->DB->tasks()->where('id = :1', $taskId)->fetch();
		return $taskRow !== null;
	}
}
