<?php

// Merges the per-process coverage files the suite left behind and reports on them.
//
//   php report.php <coverage-dir> [--clover=path] [--min=NN] [--expect=label,label]
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
//
// --expect exists because a step that quietly stops being measured is indistinguishable,
// in the number alone, from a step that honestly reached nothing new — and the first is a
// setup failure while the second is a fact. Each separately measured step in the CI job
// sets VICTUAL_COVERAGE_LABEL, prepend.php makes that the filename's prefix, and this asks
// that every named label actually left a file behind. A stale file from another step
// cannot satisfy a label that is missing, because the match is on that step's own prefix.

require_once dirname(__DIR__, 2) . '/packages/autoload.php';

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover;
use SebastianBergmann\CodeCoverage\Report\Text;
use SebastianBergmann\CodeCoverage\Report\Thresholds;

$args = array_slice($argv, 1);
$directory = null;
$clover = null;
$minimum = null;
$expected = [];

foreach ($args as $arg)
{
	if (str_starts_with($arg, '--clover='))
	{
		$clover = substr($arg, strlen('--clover='));
	}
	elseif (str_starts_with($arg, '--min='))
	{
		$value = substr($arg, strlen('--min='));

		// Checked rather than cast. (float) turns anything unparseable into 0.0, and 0.0 is
		// a threshold every run passes - so a --min that is a typo, an unexpanded template
		// variable or an empty shell expansion disables the gate while the step still
		// reports green. That happened here: a placeholder committed in place of the figure
		// left CI comparing against zero for the length of a branch, with the workflow's own
		// fourteen-line comment above it arguing that a rounded number is too loose.
		if (!is_numeric($value))
		{
			fwrite(STDERR, '--min needs a number, got "' . $value . "\"\n");
			fwrite(STDERR, "a threshold that cannot be read is not a threshold of zero.\n");
			exit(2);
		}

		$minimum = (float)$value;
	}
	elseif (str_starts_with($arg, '--expect='))
	{
		foreach (explode(',', substr($arg, strlen('--expect='))) as $label)
		{
			$label = trim($label);

			if ($label !== '')
			{
				$expected[] = $label;
			}
		}
	}
	elseif ($directory === null)
	{
		$directory = $arg;
	}
	else
	{
		fwrite(STDERR, "usage: report.php <coverage-dir> [--clover=path] [--min=NN] [--expect=label,label]\n");
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

// Checked before the merge, so a job that lost a step is told which step rather than being
// left to read a percentage and guess. Exit 2, the same code the "no .cov files" case above
// uses, because both are setup failures rather than a coverage shortfall (which is exit 1).
$missing = [];

foreach ($expected as $label)
{
	$prefix = preg_replace('/[^A-Za-z0-9_-]/', '-', $label) . '.';

	$found = false;

	foreach ($files as $file)
	{
		if (str_starts_with(basename($file), $prefix))
		{
			$found = true;

			break;
		}
	}

	if (!$found)
	{
		$missing[] = $label;
	}
}

if ($missing !== [])
{
	fwrite(STDERR, "no coverage data from: " . implode(', ', $missing) . "\n");
	fwrite(STDERR, "each of those steps should have set VICTUAL_COVERAGE_LABEL and run under\n");
	fwrite(STDERR, "prepend.php (PHP_INI_SCAN_DIR), with a coverage driver loaded. A step that\n");
	fwrite(STDERR, "measured nothing is not the same as a step that was not measured.\n");
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
