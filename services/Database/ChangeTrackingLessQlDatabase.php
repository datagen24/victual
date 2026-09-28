<?php

namespace Victual\Services\Database;

use LessQL\Database;

/**
 * A LessQL Database whose insert()/update()/delete() only signal a data change once the
 * statement they build actually succeeds (issue #534 follow-up).
 *
 * LessQL's own onQuery() - the hook DatabaseService::GetDbConnection() installs a query
 * callback through via setQueryCallback() - fires *before* the query it describes is
 * prepared and executed (see insertPrepared()/insertBatch()/insertDefault()/update()/
 * delete() in packages/morris/lessql/src/LessQL/Database.php: every one of them calls
 * onQuery($query, $params) and only then $statement->execute($params)). A statement that
 * throws - most concretely, DELETE /api/objects/products/{id} for a product a still
 * existing product_location_min_stock row references, refused with SQLSTATE 23503 - had
 * therefore already been recorded as a data change by the time
 * GenericEntityApiController::DeleteObject() catches the PDOException and answers 400.
 * That happens in autocommit: DeleteObject() runs $row->delete() with no
 * DatabaseService::InTransaction() around it (see that method's own docblock), so the
 * rollback-restores-the-flags fix above it cannot reach this case - there is no
 * transaction to roll back.
 *
 * The query callback therefore no longer marks anything itself; it only registers, via
 * SetOnWriteSucceeded(), the mark it would have made. This class fires that registration
 * exactly once, immediately after insert()/update()/delete() returns *without throwing* -
 * a statement that throws propagates straight out of the overridden method, so the
 * registration set for it is simply discarded (not fired) instead of being cleared. A
 * later, different write callable can then set a callback of its own.
 *
 * The callback is one slot, not a stack: insert()/update()/delete() are never reentrant
 * with each other for the same call - PDOStatement::execute() throws or returns before
 * anything downstream can call back into this Database instance - and insertPrepared()'s
 * per-row loop only ever re-registers the same, functionally identical closure on every
 * iteration, so firing the last one registered is exactly firing the one true mark for
 * the whole call.
 */
class ChangeTrackingLessQlDatabase extends Database
{
	/** @var (callable(): void)|null */
	private $onWriteSucceeded = null;

	/**
	 * Registers work to run once, immediately after the insert()/update()/delete() call
	 * currently in progress returns without throwing. Overwrites whatever was registered
	 * before it - see this class's own docblock for why that is always the right callback
	 * to keep.
	 *
	 * @param callable $callback
	 */
	public function SetOnWriteSucceeded(callable $callback): void
	{
		$this->onWriteSucceeded = $callback;
	}

	public function insert($table, $rows, $method = null)
	{
		$result = parent::insert($table, $rows, $method);
		$this->FireOnWriteSucceeded();

		return $result;
	}

	public function update($table, $data, $where = array(), $params = array())
	{
		$result = parent::update($table, $data, $where, $params);
		$this->FireOnWriteSucceeded();

		return $result;
	}

	public function delete($table, $where = array(), $params = array())
	{
		$result = parent::delete($table, $where, $params);
		$this->FireOnWriteSucceeded();

		return $result;
	}

	/**
	 * Fires whatever was registered for the write that just succeeded, then clears it -
	 * a write that never re-registers (no write statement reached the database, e.g.
	 * insert() with no columns) must not fire a stale callback left over from an earlier,
	 * unrelated call.
	 */
	private function FireOnWriteSucceeded(): void
	{
		$callback = $this->onWriteSucceeded;
		$this->onWriteSucceeded = null;

		if ($callback !== null)
		{
			$callback();
		}
	}
}
