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
 * exactly once, immediately after insert()/update()/delete() returns *without throwing*.
 * A statement that throws propagates straight out of the overridden method, but the slot
 * is cleared unconditionally either way (each override's finally block does this): a
 * refused write's registration must not survive to be fired by some later, unrelated call
 * that never registers one of its own (a RunAsBookkeeping() write, or an update()/insert()
 * that returns early with nothing to write) - that stale-fire is exactly what a bare
 * "clear only on success" would let happen, and it is not a hypothetical: the earlier
 * version of this class did exactly that and a refused write's discarded mark could
 * resurface on the very next bookkeeping write in the same request.
 *
 * The callback is one slot, not a stack: insert()/update()/delete() are never reentrant
 * with each other for the same call - PDOStatement::execute() throws or returns before
 * anything downstream can call back into this Database instance - and insertPrepared()'s
 * per-row loop only ever re-registers the same, functionally identical closure on every
 * iteration, so firing the last one registered is exactly firing the one true mark for
 * the whole call.
 *
 * One further seam this leaves open, not closed by this class: in autocommit, a multi-row
 * insert() whose later row fails leaves the earlier rows' writes committed (LessQL's own
 * insertPrepared()/insertBatch() execute each row's statement immediately, with no
 * transaction of their own) but never signalled, since the callback registered for the
 * call is cleared, not fired, the moment any row throws. The one caller that passes more
 * than one row today, UsersService::CreateUser() via `$this->DB->user_permissions()->
 * insert($permList)` (services/UsersService.php), always runs inside
 * DatabaseService::InTransaction(), so a mid-batch failure there rolls the whole
 * transaction back - the earlier rows do not stay committed - and this seam cannot be
 * reached. A future autocommit multi-row insert() caller would reopen it.
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
		try
		{
			$result = parent::insert($table, $rows, $method);
			$this->FireOnWriteSucceeded();

			return $result;
		}
		finally
		{
			// Unconditional, not only on the success path above: a throw must clear
			// whatever this call registered just as surely as a return does, or it stays
			// in the slot to be fired by a later, unrelated call - see this class's own
			// docblock.
			$this->onWriteSucceeded = null;
		}
	}

	public function update($table, $data, $where = array(), $params = array())
	{
		try
		{
			$result = parent::update($table, $data, $where, $params);
			$this->FireOnWriteSucceeded();

			return $result;
		}
		finally
		{
			$this->onWriteSucceeded = null;
		}
	}

	public function delete($table, $where = array(), $params = array())
	{
		try
		{
			$result = parent::delete($table, $where, $params);
			$this->FireOnWriteSucceeded();

			return $result;
		}
		finally
		{
			$this->onWriteSucceeded = null;
		}
	}

	/**
	 * Fires whatever was registered for the write that just succeeded. Clearing the slot
	 * is the caller's job (each override's own finally block) precisely so that it happens
	 * whether this method runs or not - a throw from the parent call never reaches here at
	 * all, and the slot still has to be cleared.
	 */
	private function FireOnWriteSucceeded(): void
	{
		$callback = $this->onWriteSucceeded;

		if ($callback !== null)
		{
			$callback();
		}
	}
}
