<?php

namespace Victual\Services;

use Victual\Services\Time\Instant;

/**
 * Business logic for battery tracking: charge cycle journal and per battery details.
 */
class BatteriesService extends BaseService
{
	/**
	 * Returns detail information for one battery.
	 *
	 * @return array {battery: \LessQL\Row, last_charged: string|null, charge_cycles_count: int, next_estimated_charge_time: string|null}
	 * @throws \Exception When the battery does not exist
	 */
	public function GetBatteryDetails(int $batteryId)
	{
		if (!$this->BatteryExists($batteryId))
		{
			throw new \Exception('Battery does not exist');
		}

		$battery = $this->DB->batteries($batteryId);
		$batteryChargeCyclesCount = $this->DB->battery_charge_cycles()->where('battery_id = :1 AND undone = 0', $batteryId)->count();
		$batteryLastChargedTime = $this->DB->battery_charge_cycles()->where('battery_id = :1 AND undone = 0', $batteryId)->max('tracked_time');
		$nextChargeTime = $this->DB->batteries_current()->where('battery_id', $batteryId)->min('next_estimated_charge_time');

		return [
			'battery' => $battery,
			'last_charged' => $batteryLastChargedTime,
			'charge_cycles_count' => $batteryChargeCyclesCount,
			'next_estimated_charge_time' => $nextChargeTime
		];
	}

	/**
	 * Returns the rows of the batteries_current view (next estimated charge time per
	 * battery), each enriched with the full battery row as ->battery.
	 *
	 * @return \LessQL\Result
	 */
	public function GetCurrent()
	{
		$batteries = $this->DB->batteries()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		$currentBatteries = $this->DB->batteries_current();
		foreach ($currentBatteries as $currentBattery)
		{
			$currentBattery->battery = FindObjectInArrayByPropertyValue($batteries, 'id', $currentBattery->battery_id);
		}

		return $currentBatteries;
	}

	/**
	 * Logs a charge cycle for the given battery.
	 *
	 * @param string $trackedTime "Y-m-d H:i:s"
	 * @return int The id of the created log row
	 * @throws \Exception When the battery does not exist
	 */
	public function TrackChargeCycle(int $batteryId, string $trackedTime)
	{
		if (!$this->BatteryExists($batteryId))
		{
			throw new \Exception('Battery does not exist');
		}

		$logRow = $this->DB->battery_charge_cycles()->createRow([
			'battery_id' => $batteryId,
			'tracked_time' => self::Instant($trackedTime)
		]);
		$logRow->save();

		return $this->DB->lastInsertId();
	}

	/**
	 * Marks a charge cycle log entry as undone (the row is kept, not deleted).
	 *
	 * @param int $chargeCycleId
	 * @throws \Exception When the entry does not exist or was already undone
	 */
	public function UndoChargeCycle($chargeCycleId)
	{
		$logRow = $this->DB->battery_charge_cycles()->where('id = :1 AND undone = 0', $chargeCycleId)->fetch();

		if ($logRow == null)
		{
			throw new \Exception('Charge cycle does not exist or was already undone');
		}

		// Update log entry
		$logRow->update([
			'undone' => 1,
			'undone_timestamp' => Instant::Now()
		]);
	}

	/**
	 * @param int $batteryId
	 * @return bool
	 */
	private function BatteryExists($batteryId)
	{
		$batteryRow = $this->DB->batteries()->where('id = :1', $batteryId)->fetch();
		return $batteryRow !== null;
	}

	/**
	 * A tracked time from the API (already an instant) or from an internal caller (a legacy
	 * wall clock in the configured zone), as the instant that is stored.
	 */
	private static function Instant(string $value): string
	{
		$instant = Instant::ParseStored($value);
		if ($instant === null)
		{
			throw new \Exception('Invalid tracked time');
		}

		return Instant::ToWire($instant);
	}
}
