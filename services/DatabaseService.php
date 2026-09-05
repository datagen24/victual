<?php

namespace Victual\Services;

use Victual\Services\Database\DatabaseDialect;
use Victual\Services\Influx\BookingEventPublisher;
use Victual\Services\Influx\InfluxEventWriter;
use Victual\Services\Mqtt\MqttStatePublicationService;
use LessQL\Database;

/**
 * Central access point to the database: owns the single PDO connection, the LessQL
 * wrapper around it, and the DatabaseDialect for the configured engine.
 *
 * The dialect (SQLite or PostgreSQL, chosen via DB_DRIVER) encapsulates everything
 * engine specific - connection setup, identifier quoting and the "db changed time"
 * bookkeeping. This service routes both LessQL writes and raw SQL through the dialect
 * so that change tracking stays accurate on engines that need it (PostgreSQL); on
 * SQLite the file modification time covers it for free.
 */
class DatabaseService
{
	private static $DbConnection = null;
	private static $DbConnectionRaw = null;
	private static $Dialect = null;
	private static $instance = null;
	private static $ShutdownHandlerRegistered = false;
	private static $DataChanged = false;

	/**
	 * How many RunAsBookkeeping() calls are currently on the stack. Above zero, writes
	 * reaching the database are not counted as data changes at all.
	 *
	 * A depth rather than a bool because the calls nest: a publish runs the ledger's
	 * bookkeeping inside its own, and an inner call finishing must not re-arm change
	 * tracking for the outer one that is still running.
	 *
	 * @var int
	 */
	private static $BookkeepingDepth = 0;

	/**
	 * Work to run inside the outermost transaction just before it commits, keyed so that a
	 * caller reached many times in one transaction registers once. See
	 * RegisterBeforeOutermostCommit().
	 *
	 * @var array<string, callable>
	 */
	private static $BeforeOutermostCommitListeners = [];

	/**
	 * Executes a SQL query and returns its result set.
	 *
	 * The statement is executed twice by design (once via ExecuteDbStatement for
	 * logging/change tracking, once to obtain the result), so only use this for
	 * side-effect free SELECTs.
	 *
	 * Pass $params for anything that would otherwise be interpolated into $sql -
	 * notably date cut-offs, which have to be computed in PHP because the engines
	 * disagree about date arithmetic (SQLite's DATE(x, '-6 months') has no
	 * PostgreSQL equivalent).
	 *
	 * @param array|null $params Positional placeholder values, or null for a plain query
	 * @return \PDOStatement|false The result statement, or false when execution failed
	 */
	public function ExecuteDbQuery(string $sql, ?array $params = null)
	{
		$pdo = $this->GetDbConnectionRaw();

		if ($this->ExecuteDbStatement($sql, $params) !== true)
		{
			return false;
		}

		if (empty($params))
		{
			return $pdo->query($sql);
		}

		$cmd = $pdo->prepare($sql);
		if ($cmd->execute($params) === false)
		{
			return false;
		}

		return $cmd;
	}

	/**
	 * Executes a SQL statement without returning a result set, throwing on failure.
	 *
	 * Without $params the statement is run via PDO::exec(), which (engine permitting)
	 * may contain several semicolon separated statements; with $params it is prepared
	 * and executed with the given positional values.
	 *
	 * @param array|null $params Positional placeholder values, or null for a plain exec
	 * @return bool Always true (an exception is thrown on failure)
	 */
	public function ExecuteDbStatement(string $sql, ?array $params = null)
	{
		$pdo = $this->GetDbConnectionRaw();

		$this->LogQuery($sql, $params ?? []);

		if ($params == null)
		{

			if ($pdo->exec($sql) === false)
			{
				throw new \Exception($pdo->errorInfo());
			}
		}
		else
		{
			$cmd = $pdo->prepare($sql);
			if ($cmd->execute($params) === false)
			{
				throw new \Exception($pdo->errorInfo());
			}
		}

		// Raw SQL bypasses LessQL, so the changed time has to be maintained here too
		$dialect = $this->GetDialect();
		if ($dialect->IsWriteStatement($sql) && !$this->IsBookkeeping())
		{
			if ($dialect->RequiresChangeTracking())
			{
				$dialect->MarkDbChanged($pdo);
			}

			$this->MarkDataChanged();
		}

		return true;
	}

	/**
	 * When the data last changed, as "Y-m-d H:i:s" (see DatabaseDialect::GetDbChangedTime()
	 * for the semantics external API clients rely on).
	 *
	 * @return string
	 */
	public function GetDbChangedTime()
	{
		return $this->GetDialect()->GetDbChangedTime($this->GetDbConnectionRaw());
	}

	/**
	 * The dialect for the configured database engine (created on first use from
	 * the DB_DRIVER setting, defaulting to SQLite).
	 */
	public function GetDialect(): DatabaseDialect
	{
		if (self::$Dialect == null)
		{
			self::$Dialect = DatabaseDialect::Create();
		}

		return self::$Dialect;
	}

	/**
	 * The shared LessQL wrapper around the PDO connection, configured for the current
	 * dialect (identifier delimiter, and a query callback for change tracking and
	 * query logging where needed).
	 *
	 * @return Database
	 */
	public function GetDbConnection()
	{
		if (self::$DbConnection == null)
		{
			$pdo = $this->GetDbConnectionRaw();
			self::$DbConnection = new Database($pdo);
			self::$DbConnection->setPrimary('user_roles', ['user_id', 'role_id']);
			self::$DbConnection->setPrimary('role_permissions', ['role_id', 'permission_id']);

			$dialect = $this->GetDialect();
			self::$DbConnection->setIdentifierDelimiter($dialect->GetIdentifierDelimiter());

			$trackChanges = $dialect->RequiresChangeTracking();

			// The after-commit publishes need the same "did this request write anything"
			// answer the changed time gives, and they need it on SQLite too - where the file
			// modification time makes per-statement tracking unnecessary and the callback
			// would otherwise not be installed at all. Both are asked, not just MQTT: they
			// are independently configurable, and gating the flag on one of them would make
			// the other silently do nothing on SQLite.
			$notifyOnChange = MqttStatePublicationService::IsEnabled() || InfluxEventWriter::IsEnabled();

			if ($trackChanges || $notifyOnChange || $this->IsQueryLoggingEnabled())
			{
				self::$DbConnection->setQueryCallback(function ($query, $params) use ($pdo, $dialect, $trackChanges, $notifyOnChange)
				{
					$this->LogQuery($query, $params);

					if (($trackChanges || $notifyOnChange) && $dialect->IsWriteStatement($query)
						&& !$this->IsBookkeeping())
					{
						if ($trackChanges)
						{
							$dialect->MarkDbChanged($pdo);
						}

						$this->MarkDataChanged();
					}
				});
			}
		}

		return self::$DbConnection;
	}

	/**
	 * The shared raw PDO connection, created and initialized by the dialect on first use.
	 *
	 * @return \PDO
	 */
	public function GetDbConnectionRaw()
	{
		if (self::$DbConnectionRaw == null)
		{
			$dialect = $this->GetDialect();

			$pdo = $dialect->CreateConnection();
			$dialect->OnConnected($pdo);

			self::$DbConnectionRaw = $pdo;
			$this->RegisterShutdownHandler();
		}

		return self::$DbConnectionRaw;
	}

	/**
	 * Runs $work inside a database transaction, committing on return and rolling back if
	 * it throws.
	 *
	 * Nesting is the reason this exists. PDO has no nested transactions, and the call
	 * graph already nests: RecipesService::ConsumeRecipe wraps and then calls
	 * ConsumeProduct and AddProduct; StockService::InventoryProduct delegates to one of
	 * those two; UndoTransaction loops over UndoBooking, which recurses into itself for
	 * correlated bookings. A naive beginTransaction() in each of those would throw the
	 * moment two of them met.
	 *
	 * So an inner call is a no-op: whoever opened the transaction owns committing it, and
	 * the innermost work simply joins it. That is the right semantics as well as the
	 * simple one — nothing here wants partial rollback, and an undo that half-succeeds is
	 * exactly the state these transactions exist to prevent. Savepoints would allow it and
	 * are supported by both engines, but no caller wants it; if one ever does, this
	 * signature does not have to change.
	 *
	 * "Is a transaction already open?" is asked of PDO rather than tracked in a counter of
	 * our own. A counter would only know about transactions opened through this method,
	 * and DatabaseMigrationService opens its own directly — so a migration that called a
	 * service would nest wrongly and the mistake would surface as a runtime error far from
	 * its cause.
	 *
	 * The engine-specific counterpart is on the dialect: see DatabaseDialect for the
	 * per-engine locking used around migrations. Engine-neutral composition belongs here;
	 * anything an engine does differently belongs there.
	 *
	 * @param callable $work Receives no arguments; its return value is passed through
	 * @return mixed Whatever $work returns
	 * @throws \Throwable Whatever $work throws, after the transaction is rolled back
	 */
	public function InTransaction(callable $work)
	{
		$pdo = $this->GetDbConnectionRaw();

		if ($pdo->inTransaction())
		{
			return $work();
		}

		$pdo->beginTransaction();

		try
		{
			$result = $work();

			// Still inside the transaction, and only for the outermost caller: this is the
			// one moment where the whole unit of work is done and none of it is committed
			// yet. Anything that has to be written exactly once per transaction, describing
			// its final state, belongs here - see RegisterBeforeOutermostCommit().
			$this->RunBeforeOutermostCommit();
		}
		catch (\Throwable $ex)
		{
			// Guarded because a statement can abort the transaction on its own (SQLite
			// does this on some errors), and rolling back a transaction that is no longer
			// open would replace the real exception with a misleading one.
			if ($pdo->inTransaction())
			{
				$pdo->rollBack();
			}

			// The listeners describe a transaction that is not happening, so nothing may
			// carry over into whatever this request does next
			self::$BeforeOutermostCommitListeners = [];

			throw $ex;
		}

		$pdo->commit();

		return $result;
	}

	/**
	 * Registers work to run once, inside the outermost transaction, just before it commits.
	 *
	 * The problem this solves is that "once per transaction" has no other honest seam. The
	 * call graph nests - RecipesService::ConsumeRecipe wraps ConsumeProduct and AddProduct,
	 * OpenProduct delegates to TransferProduct, UndoTransaction loops over UndoBooking - and
	 * InTransaction() deliberately lets an inner call join the outer one. So a side effect
	 * written at the end of an inner method runs several times per transaction, each time
	 * describing a state that is real only in the middle of the work: intermediate stock
	 * amounts, bookings not yet undone. That is fine for something idempotent and wrong for
	 * anything that records what happened, which is what plan 18's outbox does.
	 *
	 * Listeners run in registration order, before the commit rather than after, so whatever
	 * they write is part of the same transaction and a rollback takes it with them. They are
	 * cleared once run, and cleared again on rollback: a listener describes a transaction
	 * that either committed or did not happen, and must never leak into the next one.
	 *
	 * A listener throwing aborts the transaction, deliberately. It is running as part of the
	 * unit of work, not beside it.
	 *
	 * Keyed, so that a caller invoked many times inside one transaction registers once. The
	 * key is the caller's to choose and is how "one event per transaction id" is expressed.
	 *
	 * **Called with no transaction open, the work runs immediately** - see the body for why
	 * that is the honest reading of "before the outermost commit" rather than a fallback.
	 *
	 * This is process state for the length of one transaction, not between requests - the
	 * same category as the label-webhook payloads StockService collects inside a transaction
	 * and fires after it, and not the cold-start problem ADR-0007 forbids.
	 *
	 * @param string $key Identifies the work; a second registration under the same key is ignored
	 * @param callable $work Receives no arguments; its return value is discarded
	 */
	public function RegisterBeforeOutermostCommit(string $key, callable $work): void
	{
		// No transaction open means there is no later moment to wait for: this call site is
		// already the end of a unit of work that happens to consist of autocommitted
		// statements, so its "outermost commit" is now. Running it immediately is the
		// degenerate case rather than a special one.
		//
		// The alternative - storing it anyway - fails twice over and silently. Nothing would
		// ever run it, so the work is lost; and it would sit in the static array until the
		// next unrelated InTransaction() in the same process picked it up and ran it there,
		// describing a unit of work that committed long before. Throwing instead would be
		// worse than either: a caller that books outside a transaction still has to get its
		// event, and refusing would turn a working write path into a failing one.
		if (!$this->GetDbConnectionRaw()->inTransaction())
		{
			$work();

			return;
		}

		if (array_key_exists($key, self::$BeforeOutermostCommitListeners))
		{
			return;
		}

		self::$BeforeOutermostCommitListeners[$key] = $work;
	}

	/**
	 * Runs and clears the registered listeners.
	 *
	 * Taken and cleared before running rather than after, so that a listener which itself
	 * registers something cannot loop - and so that a listener throwing leaves nothing
	 * behind for the next transaction to pick up.
	 */
	private function RunBeforeOutermostCommit(): void
	{
		$listeners = self::$BeforeOutermostCommitListeners;
		self::$BeforeOutermostCommitListeners = [];

		foreach ($listeners as $work)
		{
			$work();
		}
	}

	/**
	 * Tells the connection which user it acts for. Engines which resolve user settings in
	 * SQL (PostgreSQL) need this; on SQLite it is a no-op.
	 *
	 * @param int|null $userId
	 */
	public function SetCurrentUserId($userId)
	{
		$this->GetDialect()->SetCurrentUserId($this->GetDbConnectionRaw(), $userId);
	}

	/**
	 * Overrides the "db changed time", used to restore it after bookkeeping writes
	 * (e.g. API key last-used stamps) that must not count as data changes.
	 *
	 * @param string $dateTime "Y-m-d H:i:s"
	 */
	public function SetDbChangedTime($dateTime)
	{
		$this->GetDialect()->SetDbChangedTime($this->GetDbConnectionRaw(), $dateTime);

		// Restoring the changed time is how a bookkeeping write says "this was not a data
		// change". The dirty flag means the same thing, so it is cleared here rather than
		// left to a second, separate call that could be forgotten - and it is cleared after
		// the dialect call, because on engines that keep the changed time in a table the
		// restore is itself an UPDATE which has just set the flag again.
		self::$DataChanged = false;
	}

	/**
	 * Runs $work with change tracking suppressed, so the rows it writes never count as a
	 * data change in the first place.
	 *
	 * **This is not the snapshot-and-restore idiom, and the difference is the point.**
	 * SessionService and ApiKeyService hide their last-used stamps by reading the changed
	 * time, writing, and putting the old value back. That is safe only while nothing else
	 * can write between the read and the restore, and for plan 18's publish and drain
	 * nothing guarantees that: another request can commit real data and flush a newer
	 * `system_db_changed_time` inside the window, and the restore then overwrites it with a
	 * stale snapshot. A client polling `GET /api/system/db-changed-time` would never see
	 * that committed change - it does not poll a version, it polls a timestamp, and the
	 * timestamp went backwards. The publication lock does not close this: it serializes
	 * publishers, not the application writes happening beside them.
	 *
	 * So nothing shared is touched at all. While the depth is above zero the query callback
	 * and ExecuteDbStatement skip both halves of change tracking - the dialect's
	 * MarkDbChanged() and this service's dirty flag - so the changed time is never advanced
	 * and therefore never has to be put back. A real data change recorded *before* the
	 * bookkeeping work also survives it, which the restore idiom did not manage:
	 * SetDbChangedTime() clears the dirty flag outright.
	 *
	 * **The residual is SQLite's, and it is the one engine where it costs nothing.** There
	 * the changed time *is* the database file's modification time, which the operating
	 * system advances when the write lands, with no PHP involved to suppress. Hiding a write
	 * from it means rewinding it, which is the hazard above; so it is not hidden, and a
	 * ledger or outbox row can advance the changed time on SQLite. That is at worst one
	 * redundant client refetch, and almost never even that: these writes only happen on the
	 * back of a request that already changed data, or during a backlog drain on an engine
	 * [ADR-0008](../docs/adr/0008-postgresql-only-runtime-engine.md) retires from runtime
	 * use and which has no concurrent writer to be wrong about. The two last-used stamps
	 * deliberately keep the old idiom: they fire on *every* authenticated read, so letting
	 * them advance the file modification time would make SQLite's changed time useless
	 * rather than slightly noisy, and their window is a single statement inside one request.
	 *
	 * Reentrant: an inner call finishing leaves an outer one still suppressed.
	 *
	 * @param callable $work Receives no arguments; its return value is passed through
	 * @return mixed Whatever $work returns
	 * @throws \Throwable Whatever $work throws, after suppression is lifted
	 */
	public function RunAsBookkeeping(callable $work)
	{
		self::$BookkeepingDepth++;

		try
		{
			return $work();
		}
		finally
		{
			self::$BookkeepingDepth--;
		}
	}

	/**
	 * Whether a RunAsBookkeeping() call is currently on the stack, in which case writes
	 * reaching the database are not data changes.
	 */
	public function IsBookkeeping(): bool
	{
		return self::$BookkeepingDepth > 0;
	}

	/**
	 * Records that this request wrote data, as opposed to having only read or having written
	 * a bookkeeping row (see RunAsBookkeeping(), under which this is not reached at all, and
	 * SetDbChangedTime(), which clears it again).
	 *
	 * Deliberately the same question DatabaseDialect::MarkDbChanged() answers for
	 * GET /api/system/db-changed-time, and deliberately maintained here rather than on the
	 * dialect: SQLite's dialect has nothing to do for the changed time (the file
	 * modification time is the changed time) and would never be asked.
	 */
	public function MarkDataChanged()
	{
		self::$DataChanged = true;
	}

	/**
	 * Whether this request has written data. Used by the request-end MQTT publish to skip
	 * reads entirely; a request that wrote nothing has nothing new to say.
	 */
	public function HasDataChanged(): bool
	{
		return self::$DataChanged;
	}

	/**
	 * Returns the singleton instance of this service.
	 *
	 * @return self
	 */
	public static function GetInstance()
	{
		if (self::$instance == null)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Query logging is opt-in: dev mode plus an existing <data path>/sql.log file.
	 */
	private function IsQueryLoggingEnabled(): bool
	{
		return VICTUAL_MODE === 'dev' && file_exists(VICTUAL_DATAPATH . '/sql.log');
	}

	private function LogQuery(string $sql, array $params)
	{
		if (!$this->IsQueryLoggingEnabled())
		{
			return;
		}

		$logFilePath = VICTUAL_DATAPATH . '/sql.log';

		$line = $sql;
		if (!empty($params))
		{
			$line .= ' #### ' . implode(';', $params);
		}

		file_put_contents($logFilePath, $line . PHP_EOL, FILE_APPEND);
	}

	/**
	 * Registers a once-per-request shutdown handler which hands the response off where the
	 * runtime allows it, flushes a pending "db changed" mark to the database (a no-op for
	 * dialects that write immediately), and then runs the after-commit publishes - the MQTT
	 * state snapshot and the InfluxDB booking events - when the request has work for them.
	 */
	private function RegisterShutdownHandler()
	{
		if (self::$ShutdownHandlerRegistered)
		{
			return;
		}

		self::$ShutdownHandlerRegistered = true;

		// Dialects which track the changed time in a table batch it into a single write
		register_shutdown_function(function ()
		{
			// Everything below this line happens after the response has been produced, and
			// the two outbound publishes at the end of it are bounded by their own connect
			// timeouts rather than by anything fast. Under php-fpm this hands the response
			// to the web server first, so an unreachable broker costs the pod a moment and
			// costs the caller nothing. The function only exists under FPM; under mod_php or
			// the built-in server there is nothing to hand off to and the timeouts are on
			// the response, which is the honest limit of what can be done here.
			if (function_exists('fastcgi_finish_request'))
			{
				fastcgi_finish_request();
			}

			try
			{
				if (self::$DbConnectionRaw !== null)
				{
					$this->GetDialect()->FlushDbChangedTime(self::$DbConnectionRaw);
				}
			}
			catch (\Exception $ex)
			{
				// A failure here must never turn an otherwise successful request into an error
			}

			// The after-commit seam for plan 18: the end of the request is the first moment
			// every transaction is provably closed, and the dirty flag is the same "really
			// changed" test the changed time uses, so reads and bookkeeping writes cost
			// nothing here. Named directly rather than through a listener registry because
			// there is no boot event to register one at, and holding one in process memory
			// between requests is what ADR-0007 forbids. Everything past this point catches
			// its own failures.
			//
			// Two independent pieces of evidence that there is work to do, not one. The
			// dirty flag covers everything that writes through this service; the outbox
			// covers the InfluxDB events specifically, which are recorded at StockService's
			// entrypoints and would otherwise be lost whenever nothing installed the query
			// callback - a SQLite database with MQTT off, for instance.
			//
			// Asking the outbox costs one indexed query per request, and only when InfluxDB
			// is configured on (HasBookings() returns false on a constant read otherwise).
			// That is deliberate rather than merely tolerable: it is what lets a request
			// that booked nothing deliver what an earlier failed attempt left behind, so a
			// queue drains itself once the endpoint comes back rather than waiting for
			// somebody to notice.
			if (!self::$DataChanged && !BookingEventPublisher::HasBookings())
			{
				return;
			}

			// A snapshot published from inside a transaction that then rolls back is a lie
			// that persists in a retained topic, and a time series point written for a
			// booking that rolled back is a number nothing will ever correct. An open
			// transaction here means something escaped InTransaction()'s rollback, so the
			// honest thing is to skip both and say so.
			if (self::$DbConnectionRaw !== null && self::$DbConnectionRaw->inTransaction())
			{
				error_log('Victual: skipped the after-commit MQTT publish and InfluxDB write because a database transaction was still open at the end of the request');

				return;
			}

			MqttStatePublicationService::PublishForRequestEnd();
			BookingEventPublisher::WriteForRequestEnd();
		});
	}
}
