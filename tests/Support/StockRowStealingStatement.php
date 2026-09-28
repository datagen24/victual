<?php

namespace Victual\Tests\Support;

use PDO;
use PDOStatement;

/**
 * Companion to tests/Support/StockRowStealingPdo.php: a \PDOStatement subclass,
 * installed through PDO::ATTR_STATEMENT_CLASS, that recognises
 * StockService::UndoBooking()'s own "does row X still exist" check
 * (`$this->DB->stock()->where('id = :1', $stockRowId)->fetch()`) by its exact SQL shape
 * and bound parameter, and - after that check has already executed and found nothing,
 * but before the caller ever gets a chance to act on that answer - has a second,
 * genuinely separate connection insert and commit a real row under that exact id.
 *
 * This is issue #584's wider window (Opus validator, PR #598 round 2): the "row X does
 * not exist" check and the eventual explicit-id INSERT are two separate statements, and
 * a real, committed row can appear under X in between - which no sequence check (#584's
 * own, narrower, already-fixed read-to-draw window) can ever see, since a real INSERT
 * does not have to draw from the sequence at all if its own caller already resolved its
 * id some other way (as this steal itself does, by inserting under an explicit id with
 * no query() call at all).
 */
class StockRowStealingStatement extends PDOStatement
{
	private PDO $racingConnection;
	private int $targetId;
	private array $victimRow;
	private bool $hasStolen = false;

	protected function __construct(PDO $racingConnection, int $targetId, array $victimRow)
	{
		$this->racingConnection = $racingConnection;
		$this->targetId = $targetId;
		$this->victimRow = $victimRow;
	}

	public function execute(?array $params = null): bool
	{
		$result = parent::execute($params);

		if (!$this->hasStolen
			&& $params !== null
			&& count($params) === 1
			&& (int)$params[0] === $this->targetId
			&& str_contains($this->queryString, '"stock"'))
		{
			$this->hasStolen = true;

			$columns = array_keys($this->victimRow);
			$placeholders = implode(', ', array_fill(0, count($columns), '?'));

			$insert = $this->racingConnection->prepare(
				'INSERT INTO stock (id, ' . implode(', ', $columns) . ') VALUES (?, ' . $placeholders . ')'
			);
			$insert->execute(array_merge([$this->targetId], array_values($this->victimRow)));
		}

		return $result;
	}

	/** Whether the steal fired - a setup sanity check for tests using this class. */
	public function HasStolen(): bool
	{
		return $this->hasStolen;
	}
}
