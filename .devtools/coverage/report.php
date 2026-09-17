<?php

// Merges the per-process coverage files the suite left behind and reports on them.
//
//   php report.php <coverage-dir> [--clover=path] [--min=NN]
//
// prepend.php writes one .cov per PHP process, because no process can know it is the last
// one. This is the other half: it loads all of them into a single CodeCoverage object,
// prints a per-file summary, and optionally writes Clover for anything that reads that.
//
// The number this prints is line coverage of the application by the differential suite —
// not by a unit test suite, which this fork does not have. It is a map of which code the
// suite actually reaches, which is the question worth asking of it: the suite drives SQL
// at both engines and stock operations through StockService, so wide areas of controllers
// and helpers are untouched by design. Treat a fall in the number as "the suite stopped
// exercising something", not as a code quality score.
//
// --min exists for CI. It is deliberately not set by default: a threshold nobody chose is
// a threshold that gets raised until it fails, then deleted.

require_once dirname(__DIR__, 2) . '/packages/autoload.php';

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover;
use SebastianBergmann\CodeCoverage\Report\Text;
use SebastianBergmann\CodeCoverage\Report\Thresholds;

$args = array_slice($argv, 1);
$directory = null;
$clover = null;
$minimum = null;

foreach ($args as $arg)
{
	if (str_starts_with($arg, '--clover='))
	{
		$clover = substr($arg, strlen('--clover='));
	}
	elseif (str_starts_with($arg, '--min='))
	{
		$minimum = (float)substr($arg, strlen('--min='));
	}
	elseif ($directory === null)
	{
		$directory = $arg;
	}
	else
	{
		fwrite(STDERR, "usage: report.php <coverage-dir> [--clover=path] [--min=NN]\n");
		exit(2);
	}
}

if ($directory === null)
{
	$directory = getenv('VICTUAL_COVERAGE_DIR') ?: null;
}

if ($directory === null || !is_dir($directory))
{
	fwrite(STDERR, "no coverage directory: pass one, or set VICTUAL_COVERAGE_DIR\n");
	exit(2);
}

$files = glob(rtrim($directory, '/') . '/*.cov');

if (empty($files))
{
	// Not an error to distinguish from a low number: it means the suite ran without the
	// driver loaded, which is a setup problem and worth saying so plainly.
	fwrite(STDERR, "no .cov files in " . $directory . " — did the suite run with SUITE_COVERAGE=1?\n");
	exit(2);
}

$merged = null;

foreach ($files as $file)
{
	$coverage = require $file;

	if (!$coverage instanceof CodeCoverage)
	{
		fwrite(STDERR, 'not a coverage file: ' . $file . "\n");
		exit(2);
	}

	if ($merged === null)
	{
		$merged = $coverage;

		continue;
	}

	$merged->merge($coverage);
}

// showUncoveredFiles: true. CodeCoverage::getData() already adds every filtered file
// CodeCoverage never saw a line from (includeUncoveredFiles() is the library default),
// so the aggregate percentage below always counted them — but Text::process() drops a
// class with zero covered statements from the per-class listing unless told not to,
// which hid every never-loaded file from this report entirely rather than showing it at
// 0%. Issue 192 asked which one was true; this was the gap.
echo (new Text(Thresholds::default(), true, false))->process($merged, false);

if ($clover !== null)
{
	(new Clover())->process($merged, $clover, 'victual differential suite');
	echo 'Clover written to ' . $clover . "\n";
}

$report = $merged->getReport();
$executable = $report->numberOfExecutableLines();
$executed = $report->numberOfExecutedLines();
$percent = $executable === 0 ? 0.0 : ($executed / $executable) * 100;

printf("%d of %d executable lines covered (%.2f%%) from %d process(es)\n",
	$executed, $executable, $percent, count($files));

if ($minimum !== null && $percent < $minimum)
{
	printf("below the requested minimum of %.2f%%\n", $minimum);
	exit(1);
}
