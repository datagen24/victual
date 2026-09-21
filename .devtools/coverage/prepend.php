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

	$filter = new \SebastianBergmann\CodeCoverage\Filter();

	// The application, and only the application. Vendored code says nothing about this
	// fork, and the .devtools scripts are the thing doing the measuring — including them
	// would report the test tooling as well covered, which is true and worthless.
	//
	// Listed file by file rather than by directory: php-code-coverage 11 dropped
	// Filter::includeDirectory(), and the file iterator it depends on is the same one it
	// used internally before.
	$directories = [];

	foreach (['services', 'controllers', 'helpers', 'middleware', 'plugins'] as $directory)
	{
		if (is_dir($root . '/' . $directory))
		{
			$directories[] = $root . '/' . $directory;
		}
	}

	if ($directories !== [])
	{
		$filter->includeFiles((new \SebastianBergmann\FileIterator\Facade())->getFilesAsArray($directories, '.php'));
	}

	foreach (['app.php', 'routes.php', 'config-dist.php'] as $file)
	{
		if (is_file($root . '/' . $file))
		{
			$filter->includeFile($root . '/' . $file);
		}
	}

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
		$label = (string)(getenv('VICTUAL_COVERAGE_LABEL') ?: '');
		$prefix = $label === '' ? '' : preg_replace('/[^A-Za-z0-9_-]/', '-', $label) . '.';

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
