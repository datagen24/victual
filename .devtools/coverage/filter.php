<?php

// What coverage measures: the application, and only the application. Shared by prepend.php,
// which measures with it, and warm-analysis-cache.php, which analyses the same files ahead
// of the suite - the two must agree, or the cache is warmed for files nobody reads.
//
// Vendored code says nothing about this fork, and the .devtools scripts are the thing doing
// the measuring - including them would report the test tooling as well covered, which is
// true and worthless.
//
// Listed file by file rather than by directory: php-code-coverage 11 dropped
// Filter::includeDirectory(), and the file iterator it depends on is the same one it used
// internally before.

return function (string $root): \SebastianBergmann\CodeCoverage\Filter
{
	$filter = new \SebastianBergmann\CodeCoverage\Filter();

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

	return $filter;
};
