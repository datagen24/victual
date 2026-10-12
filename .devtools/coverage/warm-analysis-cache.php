<?php

// Fills php-code-coverage's static-analysis cache for every file prepend.php measures, once,
// before the suite's processes start reading it. See the cache's comment in prepend.php for
// why the processes only read and never write it.
//
// Usage: php warm-analysis-cache.php <cache directory>
//
// Run without auto_prepend_file (run-tests.sh calls it before it exports PHP_INI_SCAN_DIR),
// so this process is not itself measured.
//
// The analysis goes through CodeCoverage rather than constructing the analysers directly:
// the cache key includes options CodeCoverage passes to them, and asking CodeCoverage for
// its uncovered files is the one path that analyses every file in the filter with exactly
// the options a measured process will use.

$root = getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2);
$cacheDirectory = $argv[1] ?? '';

if ($cacheDirectory === '')
{
	fwrite(STDERR, "usage: php warm-analysis-cache.php <cache directory>\n");
	exit(2);
}

require_once $root . '/packages/autoload.php';

$filter = (require __DIR__ . '/filter.php')($root);

$coverage = new \SebastianBergmann\CodeCoverage\CodeCoverage(
	(new \SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter),
	$filter
);
$coverage->cacheStaticAnalysis($cacheDirectory);
$coverage->includeUncoveredFiles();
$coverage->getData();

printf("analysed %d file(s) into %s\n", count($filter->files()), $cacheDirectory);
