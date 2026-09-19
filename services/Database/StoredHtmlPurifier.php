<?php

namespace Victual\Services\Database;

use Victual\Controllers\Api\BaseApiController;

/**
 * Runs the API's HTMLPurifier over rich text that is already in a database.
 *
 * Five columns are rendered as HTML rather than escaped - the descriptions a summernote
 * editor writes, listed in BaseApiController::HTML_RENDERED_COLUMNS - so the boundary for
 * them is the purifier every write goes through, not escaping at the sink. That covers
 * every row the API wrote. It covers nothing that arrived any other way, and two paths do:
 *
 * - **A database upgraded in place.** Rows written by upstream grocy, or by this fork
 *   before security sweep finding S1 added the purifier, are still exactly as they were
 *   typed. No migration has ever rewritten them.
 * - **A database imported.** DatabaseImporter copies rows verbatim, which is what an
 *   importer should do; the payload arrives in the target unchanged.
 *
 * In both cases the stored value reaches `{!! $recipe->description !!}` in
 * views/recipes.blade.php, the `.html()` renders in shoppinglist.js, equipment.js,
 * productcard.js and chorecard.js, and summernote's own editable div - none of which can
 * defend themselves, by design. So the rows are purified where they sit. Review finding P1
 * on pull request #41; see docs/plans/landed/21-frontend-sink-discipline.md.
 *
 * The column list is read from BaseApiController rather than restated here on purpose. A
 * second copy of it is how one of these two paths silently stops being covered when a
 * sixth column is added.
 */
class StoredHtmlPurifier
{
	/**
	 * Purifies every HTML-rendered column of every row that needs it, in place.
	 *
	 * Only rows the purifier actually changes are written back, so a database that has
	 * nothing to fix takes one SELECT per column and issues no UPDATE - which is what
	 * makes this cheap enough to run from a migration and from the importer both.
	 *
	 * @param \PDO $db The database to clean, already migrated
	 * @param DatabaseDialect $dialect Dialect matching $db, for identifier quoting
	 * @param callable|null $progress Receives one human-readable line per changed column
	 * @return array Number of rows rewritten, keyed by "table.column"; only non-zero entries
	 */
	public static function Purify(\PDO $db, DatabaseDialect $dialect, ?callable $progress = null): array
	{
		// app.php makes this directory on the first request, and bin/victual-warm-cache
		// fills it - but a migration run and an import both happen before either, and
		// HTMLPurifier warns to stderr once per definition when its serializer path does
		// not exist. Harmless, and alarming in the middle of an upgrade, which is the
		// worst moment to print something that looks like a fault. Same guarded mkdir
		// app.php uses.
		if (!file_exists(VICTUAL_VIEWCACHE_PATH))
		{
			@mkdir(VICTUAL_VIEWCACHE_PATH, 0755, true);
		}

		$purifier = BaseApiController::CreateHtmlPurifier();
		$report = [];

		foreach (BaseApiController::HTML_RENDERED_COLUMNS as $table => $columns)
		{
			foreach ($columns as $column)
			{
				$changed = self::PurifyColumn($db, $dialect, $purifier, $table, $column);

				if ($changed > 0)
				{
					$report[$table . '.' . $column] = $changed;

					if ($progress !== null)
					{
						$progress(sprintf('  %-46s %7d rows rewritten', $table . '.' . $column, $changed));
					}
				}
			}
		}

		return $report;
	}

	/**
	 * One column of one table. Returns the number of rows whose value the purifier changed.
	 */
	private static function PurifyColumn(\PDO $db, DatabaseDialect $dialect, \HTMLPurifier $purifier, string $table, string $column): int
	{
		$quotedTable = $dialect->QuoteIdentifier($table);
		$quotedColumn = $dialect->QuoteIdentifier($column);

		// Streamed rather than fetchAll'd: these are household-sized tables today, but a
		// recipe description is unbounded text and loading every one of them into memory
		// to change none of them would be a poor trade.
		$select = $db->query('SELECT ' . $dialect->QuoteIdentifier('id') . ', ' . $quotedColumn
			. ' FROM ' . $quotedTable
			. ' WHERE ' . $quotedColumn . ' IS NOT NULL AND ' . $quotedColumn . " <> ''");

		if ($select === false)
		{
			return 0;
		}

		$update = $db->prepare('UPDATE ' . $quotedTable . ' SET ' . $quotedColumn . ' = ? WHERE '
			. $dialect->QuoteIdentifier('id') . ' = ?');

		$changed = 0;
		$pending = [];

		while ($row = $select->fetch(\PDO::FETCH_NUM))
		{
			$purified = $purifier->purify((string)$row[1]);

			if ($purified !== $row[1])
			{
				// Collected rather than written inside the cursor's loop: SQLite will not
				// reliably see an UPDATE issued against a table a SELECT is still walking.
				$pending[] = [$purified, $row[0]];
			}
		}

		foreach ($pending as $write)
		{
			$update->execute($write);
			$changed++;
		}

		return $changed;
	}
}
