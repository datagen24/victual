<?php

// Starts line coverage for whichever PHP process included this, and writes what it
// collected on shutdown.
//
// Loaded through auto_prepend_file rather than by editing each script, because the suite
// is a dozen short-lived processes — difftest.php once per seed, trigdifftest.php,
// rollback-tests.php once per engine, bin/victual-migrate several times — and a per-call
// change would mean remembering to add one every time a phase is added. See
// .devtools/coverage/README.md.
//
// Does nothing at all unless VICTUAL_COVERAGE_DIR is set, so the suite's normal runs are
// untouched: no driver, no autoloader, no shutdown handler.
//
// Each process writes its own file. They are merged afterwards by report.php, because
// nothing here can know which process is the last one.

if (getenv('VICTUAL_COVERAGE_DIR') === false)
{
	return;
}

(function ()
{
	$root = getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2);
	$outputDir = getenv('VICTUAL_COVERAGE_DIR');

	if (!is_file($root . '/packages/autoload.php'))
	{
		return;
	}

	require_once $root . '/packages/autoload.php';

	if (!class_exists(\SebastianBergmann\CodeCoverage\CodeCoverage::class))
	{
		fwrite(STDERR, "coverage requested but phpunit/php-code-coverage is not installed\n");

		return;
	}

	$filter = (require __DIR__ . '/filter.php')($root);

	try
	{
		$driver = (new \SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter);
	}
	catch (\Throwable $ex)
	{
		fwrite(STDERR, 'coverage requested but no driver is available: ' . $ex->getMessage() . "\n");

		return;
	}

	$coverage = new \SebastianBergmann\CodeCoverage\CodeCoverage($driver, $filter);

	// stop() parses every application file the process executed, to learn which of its
	// lines are executable. Uncached, that is php-parser over the same sources in each of
	// the suite's ~3,300 processes - the leading suspect for the suite taking 40 minutes
	// with coverage and 16 without it (CI run 38255359626). run-tests.sh analyses every file in
	// the filter once, before any phase, into this directory, so a process here only ever
	// reads the cache.
	//
	// Only a directory that exists, so only a warmed one: php-code-coverage writes cache
	// entries with a plain file_put_contents, and a cold cache shared by concurrent
	// processes would let one read another's half-written entry. The notice unserialize()
	// prints then lands on a subprocess helper's stdout in front of its JSON - the shape
	// of the 2026-09-30 failures. A step run without the runner measures as it always did.
	$analysisCache = $outputDir . '-analysis';

	if (is_dir($analysisCache))
	{
		$coverage->cacheStaticAnalysis($analysisCache);
	}

	$coverage->start('suite');

	register_shutdown_function(function () use ($coverage, $outputDir)
	{
		$coverage->stop();

		if (!is_dir($outputDir))
		{
			@mkdir($outputDir, 0755, true);
		}

		// Named for the process, so concurrent or repeated invocations cannot overwrite
		// each other. report.php merges whatever it finds.
		//
		// VICTUAL_COVERAGE_LABEL, when a caller sets one, becomes the filename's prefix so
		// report.php --expect can tell "this step contributed nothing" from "this step never
		// ran under the driver at all" — issue 192 mechanics item 2 asks for exactly that
		// distinction, because a step that silently stops being measured reads as an honest
		// zero and nothing fails. Unset, the name is what it always was.
		//
		// The label is used as given, or not at all. Rewriting the characters it does not
		// like would map two different labels onto one prefix - "phase/a" and "phase-a"
		// both becoming "phase-a." - and then one step's file satisfies the other step's
		// expectation, which is the exact failure --expect exists to catch.
		$label = (string)(getenv('VICTUAL_COVERAGE_LABEL') ?: '');
		$prefix = '';

		if ($label !== '')
		{
			if (preg_match('/^[A-Za-z0-9_-]+$/', $label) === 1)
			{
				$prefix = $label . '.';
			}
			else
			{
				// No prefix rather than a mangled one, so it cannot be mistaken for a
				// valid label's file. report.php refuses the same label outright.
				fwrite(STDERR, 'ignoring VICTUAL_COVERAGE_LABEL "' . $label
					. '": a label is one or more of A-Z a-z 0-9 _ -' . "\n");
			}
		}

		$path = $outputDir . '/' . $prefix . getmypid() . '-' . uniqid() . '.cov';

		try
		{
			(new \SebastianBergmann\CodeCoverage\Report\PHP())->process($coverage, $path);
		}
		catch (\Throwable $ex)
		{
			fwrite(STDERR, 'could not write coverage data: ' . $ex->getMessage() . "\n");
		}
	});
})();
