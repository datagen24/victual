<?php

namespace Victual\Tests\Pgsql;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Victual\Services\RefillEstimator;

/**
 * ADR-0042 sections 2 to 4: the reorder estimate, its provenance, the unknown reasons and the status
 * boundaries. RefillEstimator reads no table, clock or zone, so this class needs no database; the
 * service, API and race tests that put these results behind routes are ConsumptionRefill*Test.php.
 *
 * Every row of the ADR's "Verification cases" table that is arithmetic is a case here, with the ADR's
 * own numbers where it gives them. A case is named by what it pins, not by the value it expects.
 */
class RefillEstimatorTest extends TestCase
{
	private static function fill(string $filledOn, ?int $suppliedDays): array
	{
		return ['filled_on' => $filledOn, 'supplied_days' => $suppliedDays];
	}

	private static function rule(string $kind, int $parameter): array
	{
		return ['kind' => $kind, 'parameter' => $parameter];
	}

	// --- The fallback: filled_on + supplied_days - 14 -----------------------------------------

	public static function fallbackCases(): array
	{
		// The ADR's table, including the year end plus a leap day and the leap day alone.
		return [
			'30 days' => ['2026-01-01', 30, '2026-01-17', 16],
			'90 days' => ['2026-01-01', 90, '2026-03-18', 76],
			'90 days across a year end and a leap day' => ['2027-12-20', 90, '2028-03-05', 76],
			'30 days across a leap day' => ['2028-02-20', 30, '2028-03-07', 16],
			'a date in the next month' => ['2026-05-01', 45, '2026-06-01', 31],
			'a fill on the last day of a month' => ['2026-01-31', 30, '2026-02-16', 16],
			'a fill on 29 February' => ['2028-02-29', 90, '2028-05-15', 76],
			'15 days is the shortest supply that gives a date' => ['2026-06-10', 15, '2026-06-11', 1],
			'730 days is the longest supply' => ['2026-01-01', 730, '2027-12-18', 716],
		];
	}

	#[DataProvider('fallbackCases')]
	public function testTheFallbackIsTheFillDatePlusTheSuppliedDaysLessFourteen(string $filledOn, int $days, string $expected, int $daysAfterFill): void
	{
		$estimate = RefillEstimator::Estimate(self::fill($filledOn, $days), null, null);

		self::assertSame(['reorder_date' => $expected, 'source' => 'fallback', 'reason' => null], $estimate);
		self::assertSame($daysAfterFill, RefillEstimator::DaysBetween($filledOn, $expected));
	}

	// --- Precedence ----------------------------------------------------------------------------

	public function testAnExplicitDateBeatsARuleAndTheFallbackAndRemovingEachRevealsTheNext(): void
	{
		$fill = self::fill('2026-01-01', 90);
		$rule = self::rule('fixed_interval', 60);

		self::assertSame(['reorder_date' => '2026-02-10', 'source' => 'explicit', 'reason' => null], RefillEstimator::Estimate($fill, $rule, '2026-02-10'));
		self::assertSame(['reorder_date' => '2026-03-02', 'source' => 'rule:fixed_interval', 'reason' => null], RefillEstimator::Estimate($fill, $rule, null));
		self::assertSame(['reorder_date' => '2026-03-18', 'source' => 'fallback', 'reason' => null], RefillEstimator::Estimate($fill, null, null));
	}

	public function testAnInvalidRuleIsNeverReplacedByTheFallback(): void
	{
		$estimate = RefillEstimator::Estimate(self::fill('2026-01-01', 90), self::rule('fraction_elapsed', 100), null);

		self::assertSame(['reorder_date' => null, 'source' => null, 'reason' => 'invalid_rule'], $estimate);
	}

	public function testAnExplicitDateStillWinsWhenTheRuleWouldBeInvalidOrTheSupplyIsMissing(): void
	{
		$estimate = RefillEstimator::Estimate(self::fill('2026-01-01', null), self::rule('fraction_elapsed', 100), '2026-01-20');

		self::assertSame('explicit', $estimate['source']);
		self::assertSame('2026-01-20', $estimate['reorder_date']);
	}

	public function testAnExplicitDateOnTheFillDateItselfIsNotRefusedAsNotAfterTheFill(): void
	{
		self::assertSame('2026-01-01', RefillEstimator::Estimate(self::fill('2026-01-01', 30), null, '2026-01-01')['reorder_date']);
	}

	// --- The three rules -----------------------------------------------------------------------

	public function testDaysBeforeEndSubtractsItsParameterFromTheEndOfTheSupply(): void
	{
		self::assertSame('2026-03-11', RefillEstimator::Estimate(self::fill('2026-01-01', 90), self::rule('days_before_end', 21), null)['reorder_date']);
		self::assertSame('2026-04-01', RefillEstimator::Estimate(self::fill('2026-01-01', 90), self::rule('days_before_end', 0), null)['reorder_date'], 'N of 0 is the day the supply ends');
		self::assertSame('rule:days_before_end', RefillEstimator::Estimate(self::fill('2026-01-01', 90), self::rule('days_before_end', 21), null)['source']);
	}

	public function testFixedIntervalIgnoresTheSuppliedDays(): void
	{
		foreach ([30, 90, null] as $days)
		{
			$estimate = RefillEstimator::Estimate(self::fill('2026-01-01', $days), self::rule('fixed_interval', 28), null);
			self::assertSame(['reorder_date' => '2026-01-29', 'source' => 'rule:fixed_interval', 'reason' => null], $estimate, 'supplied_days ' . var_export($days, true));
		}
	}

	public static function fractionCases(): array
	{
		return [
			'75 percent of 90 is 67 days' => [90, 75, 67],
			// 100 * 58 / 100 in floating point is 57.99999999999999; the stored percent is an integer.
			'58 percent of 100 is 58 days, not 57' => [100, 58, 58],
			'a fraction rounds down' => [30, 50, 15],
			'a fraction that is not a whole day rounds down' => [31, 50, 15],
			'99 percent of 100' => [100, 99, 99],
			'1 percent of 100 is the first day after the fill' => [100, 1, 1],
		];
	}

	#[DataProvider('fractionCases')]
	public function testFractionElapsedIsIntegerPercentArithmetic(int $supplied, int $percent, int $offset): void
	{
		$estimate = RefillEstimator::Estimate(self::fill('2026-01-01', $supplied), self::rule('fraction_elapsed', $percent), null);

		self::assertSame(RefillEstimator::AddDays('2026-01-01', $offset), $estimate['reorder_date']);
		self::assertSame('rule:fraction_elapsed', $estimate['source']);
	}

	public function testTheFloatingPointFractionOfTheEvidenceWouldHaveBeenOffByOne(): void
	{
		// The control for the case above: the retired float arithmetic gives 57 here.
		self::assertSame(57, (int)floor(100 * 0.58), 'the control: 100 days at 0.58 floors to 57');
		self::assertSame(58, intdiv(100 * 58, 100));
	}

	// --- Unknown: the first failing check names the reason -------------------------------------

	public function testNoFillIsUnknownWithNoFillWhateverElseIsSet(): void
	{
		self::assertSame(['reorder_date' => null, 'source' => null, 'reason' => 'no_fill'], RefillEstimator::Estimate(null, self::rule('fixed_interval', 30), '2026-03-01'));
	}

	public function testAnInvalidRuleAndAnInvalidSupplyReportTheRule(): void
	{
		self::assertSame('invalid_rule', RefillEstimator::Estimate(self::fill('2026-01-01', 0), self::rule('days_before_end', 731), null)['reason']);
	}

	public static function invalidRules(): array
	{
		return [
			'an unknown kind' => [['kind' => 'percent', 'parameter' => 50]],
			'a missing parameter' => [['kind' => 'fixed_interval', 'parameter' => null]],
			'a missing kind' => [['kind' => null, 'parameter' => 5]],
			'a string parameter' => [['kind' => 'fixed_interval', 'parameter' => '28']],
			'a float parameter' => [['kind' => 'fixed_interval', 'parameter' => 28.0]],
			'days_before_end of -1' => [self::rule('days_before_end', -1)],
			'days_before_end of 731' => [self::rule('days_before_end', 731)],
			'fixed_interval of 0' => [self::rule('fixed_interval', 0)],
			'fixed_interval of 731' => [self::rule('fixed_interval', 731)],
			'fraction_elapsed of 0' => [self::rule('fraction_elapsed', 0)],
			'fraction_elapsed of 100' => [self::rule('fraction_elapsed', 100)],
		];
	}

	#[DataProvider('invalidRules')]
	public function testARuleParameterThatIsMissingOrOutOfRangeIsAnInvalidRule(array $rule): void
	{
		self::assertSame(['reorder_date' => null, 'source' => null, 'reason' => 'invalid_rule'], RefillEstimator::Estimate(self::fill('2026-01-01', 90), $rule, null));
	}

	public static function invalidSupplies(): array
	{
		return ['0' => [0], '731' => [731], 'missing' => [null], 'negative' => [-30]];
	}

	#[DataProvider('invalidSupplies')]
	public function testTheFallbackNeedsASupplyOfOneToSevenHundredThirtyDays(?int $supplied): void
	{
		self::assertSame(['reorder_date' => null, 'source' => null, 'reason' => 'invalid_supply'], RefillEstimator::Estimate(self::fill('2026-01-01', $supplied), null, null));
	}

	#[DataProvider('invalidSupplies')]
	public function testDaysBeforeEndAndFractionElapsedNeedTheSupplyToo(?int $supplied): void
	{
		self::assertSame('invalid_supply', RefillEstimator::Estimate(self::fill('2026-01-01', $supplied), self::rule('days_before_end', 7), null)['reason']);
		self::assertSame('invalid_supply', RefillEstimator::Estimate(self::fill('2026-01-01', $supplied), self::rule('fraction_elapsed', 80), null)['reason']);
	}

	public function testFixedIntervalIsComputedWithAMissingSupply(): void
	{
		self::assertSame('2026-01-29', RefillEstimator::Estimate(self::fill('2026-01-01', null), self::rule('fixed_interval', 28), null)['reorder_date']);
	}

	public static function notAfterTheFill(): array
	{
		return [
			'a 14-day supply with the fallback' => [self::fill('2026-01-01', 14), null],
			'a 7-day supply with the fallback' => [self::fill('2026-01-01', 7), null],
			'fraction_elapsed 50 on a 1-day supply' => [self::fill('2026-01-01', 1), self::rule('fraction_elapsed', 50)],
			'days_before_end larger than the supply' => [self::fill('2026-01-01', 30), self::rule('days_before_end', 30)],
		];
	}

	#[DataProvider('notAfterTheFill')]
	public function testAComputedDateOnOrBeforeTheFillIsUnknownAndNeverClamped(array $fill, ?array $rule): void
	{
		self::assertSame(['reorder_date' => null, 'source' => null, 'reason' => 'result_not_after_fill'], RefillEstimator::Estimate($fill, $rule, null));
	}

	// --- Calendar arithmetic -------------------------------------------------------------------

	public function testDayArithmeticIsCalendarArithmeticAcrossDaylightSavingChanges(): void
	{
		// The United States moved its clocks on 2026-03-08 and 2026-11-01, Europe on 2026-03-29 and 2026-10-25.
		self::assertSame('2026-03-09', RefillEstimator::AddDays('2026-03-08', 1));
		self::assertSame('2026-11-02', RefillEstimator::AddDays('2026-11-01', 1));
		self::assertSame('2026-03-30', RefillEstimator::AddDays('2026-03-29', 1));
		self::assertSame(365, RefillEstimator::DaysBetween('2026-01-01', '2027-01-01'));
		self::assertSame(366, RefillEstimator::DaysBetween('2028-01-01', '2029-01-01'));
		self::assertSame(-1, RefillEstimator::DaysBetween('2026-03-09', '2026-03-08'));
	}

	public function testTheResultDoesNotDependOnThePhpDefaultTimezone(): void
	{
		$previous = date_default_timezone_get();

		try
		{
			$results = [];
			foreach (['UTC', 'America/New_York', 'Pacific/Auckland', 'Asia/Kolkata'] as $zone)
			{
				date_default_timezone_set($zone);
				$results[$zone] = [RefillEstimator::Estimate(self::fill('2026-03-01', 30), null, null)['reorder_date'], RefillEstimator::AddDays('2026-03-07', 2), RefillEstimator::DaysBetween('2026-03-07', '2026-03-09')];
			}
		}
		finally
		{
			date_default_timezone_set($previous);
		}

		self::assertCount(1, array_unique(array_map('json_encode', $results)), 'the same answer in every zone');
		self::assertSame(['2026-03-17', '2026-03-09', 2], array_values($results)[0]);
	}

	public function testADateThatDoesNotExistIsNotRolledOver(): void
	{
		foreach (['2026-02-30', '2026-13-01', '2026-00-10', '2026-1-1', '2026-01-01T00:00:00Z', '', 'today'] as $value)
		{
			self::assertNull(RefillEstimator::ParseDate($value), var_export($value, true));
		}

		self::assertNull(RefillEstimator::ParseDate(20260101));
		self::assertNull(RefillEstimator::ParseDate(null));
		self::assertSame('2028-02-29', RefillEstimator::ParseDate('2028-02-29')->format('Y-m-d'));
		self::assertNull(RefillEstimator::ParseDate('2027-02-29'), '2027 is not a leap year');
	}

	// --- Status ---------------------------------------------------------------------------------

	private static function statusOn(string $asOf, int $lead, string $reorder = '2026-03-18', bool $ordered = false): array
	{
		return RefillEstimator::Status(['reorder_date' => $reorder], $lead, $asOf, $ordered);
	}

	public static function leadSevenCases(): array
	{
		// R is 2026-03-18, L is 7: R-8 is 03-10, R-7 is 03-11, R-1 is 03-17, R is 03-18, R+3 is 03-21.
		return [
			'R - 8' => ['2026-03-10', 'ok', null],
			'R - 7 is the warning date, the first approaching day' => ['2026-03-11', 'approaching', null],
			'R - 1' => ['2026-03-17', 'approaching', null],
			'R is due' => ['2026-03-18', 'due', 0],
			'R + 3' => ['2026-03-21', 'due', 3],
		];
	}

	#[DataProvider('leadSevenCases')]
	public function testTheStatusBoundariesWithALeadOfSeven(string $asOf, string $status, ?int $overdue): void
	{
		self::assertSame(['status' => $status, 'days_overdue' => $overdue, 'warning_date' => '2026-03-11'], self::statusOn($asOf, 7));
	}

	public function testALeadOfZeroHasNoApproachingPeriod(): void
	{
		self::assertSame('ok', self::statusOn('2026-03-17', 0)['status']);
		self::assertSame('due', self::statusOn('2026-03-18', 0)['status']);
		self::assertSame('2026-03-18', self::statusOn('2026-03-17', 0)['warning_date'], 'the warning date is the reorder date');
	}

	public function testALeadOfSixtyStartsApproachingSixtyDaysBefore(): void
	{
		self::assertSame('ok', self::statusOn('2026-01-16', 60)['status']);
		self::assertSame('approaching', self::statusOn('2026-01-17', 60)['status'], 'the warning date, 60 days before the reorder date');
		self::assertSame('2026-01-17', self::statusOn('2026-01-01', 60)['warning_date']);
	}

	public function testTheWarningDateCrossesYearAndLeapDayBoundaries(): void
	{
		self::assertSame('2027-12-28', self::statusOn('2027-01-01', 7, '2028-01-04')['warning_date']);
		self::assertSame('2028-02-26', self::statusOn('2028-01-01', 7, '2028-03-04')['warning_date'], 'seven days before 4 March in a leap year');
		self::assertSame('2027-02-25', self::statusOn('2027-01-01', 7, '2027-03-04')['warning_date'], 'and in a common year');
	}

	public function testAnOpenOrderIsOrderedWhateverTheDateOrTheEstimateIs(): void
	{
		foreach (['2026-01-01', '2026-03-11', '2026-03-18', '2027-01-01'] as $asOf)
		{
			self::assertSame('ordered', self::statusOn($asOf, 7, '2026-03-18', true)['status'], $asOf);
			self::assertNull(self::statusOn($asOf, 7, '2026-03-18', true)['days_overdue'], 'an ordered recipe reports no days overdue');
		}

		self::assertSame('ordered', RefillEstimator::Status(['reorder_date' => null], 7, '2026-03-18', true)['status'], 'an order with no fill is still an order');
	}

	public function testNoEstimateIsUnknownWithNoWarningDate(): void
	{
		self::assertSame(['status' => 'unknown', 'days_overdue' => null, 'warning_date' => null], RefillEstimator::Status(['reorder_date' => null], 7, '2026-03-18', false));
	}

	public function testTheStatusFollowsTheSuppliedDateAcrossMidnightUtc(): void
	{
		// One stored reorder date, two clients: the one that is still on the previous local day sees approaching,
		// the one that has reached the date sees due. The estimator has no clock to disagree with either.
		self::assertSame('approaching', self::statusOn('2026-03-17', 7)['status']);
		self::assertSame('due', self::statusOn('2026-03-18', 7)['status']);
	}

	public function testLeadAndSupplyAndRuleRangesAreTheAdrs(): void
	{
		self::assertTrue(RefillEstimator::ValidLeadDays(0));
		self::assertTrue(RefillEstimator::ValidLeadDays(60));
		self::assertFalse(RefillEstimator::ValidLeadDays(-1));
		self::assertFalse(RefillEstimator::ValidLeadDays(61));
		self::assertFalse(RefillEstimator::ValidLeadDays('7'));
		self::assertTrue(RefillEstimator::ValidSuppliedDays(1));
		self::assertTrue(RefillEstimator::ValidSuppliedDays(730));
		self::assertFalse(RefillEstimator::ValidSuppliedDays(0));
		self::assertFalse(RefillEstimator::ValidSuppliedDays(731));
		self::assertFalse(RefillEstimator::ValidSuppliedDays(null));
		self::assertTrue(RefillEstimator::ValidRule('days_before_end', 0));
		self::assertTrue(RefillEstimator::ValidRule('days_before_end', 730));
		self::assertTrue(RefillEstimator::ValidRule('fixed_interval', 1));
		self::assertTrue(RefillEstimator::ValidRule('fraction_elapsed', 99));
		self::assertFalse(RefillEstimator::ValidRule('fraction_elapsed', 0.5));
	}
}
