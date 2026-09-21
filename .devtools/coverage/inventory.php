<?php

// The per-file inventory plan 33's M1 asks for, read out of a Clover report.
//
//   php .devtools/coverage/inventory.php clover.xml [--floor=75] [--format=markdown|csv]
//
// report.php prints a per-class summary, which is not the same list: a file can hold more
// than one class, and a file holding none at all (a bare script, an interface-only file)
// is not a class row anywhere. The floor is stated per *file* — "a change never drops a
// file or the total below the floor", docs/constitution.md — so the list the floor is
// checked against has to be files.
//
// The shortfall column is the number of lines that actually have to be covered to reach the
// floor, max(0, ceil(floor * executable) - covered), not the file's whole uncovered count.
// Issue 192's backlog table quotes the second, which overstates the work by roughly a
// quarter of every file's line count and makes the wrong files look urgent.
//
// Files with no executable lines are listed separately rather than given a percentage:
// 0/0 is not 0% and is not 100%, and putting them in the main table at either figure
// invents a number nobody measured.

$args = array_slice($argv, 1);
$path = null;
$floor = 0.75;
$format = 'markdown';

foreach ($args as $arg)
{
	if (str_starts_with($arg, '--floor='))
	{
		$floor = ((float)substr($arg, strlen('--floor='))) / 100;
	}
	elseif (str_starts_with($arg, '--format='))
	{
		$format = substr($arg, strlen('--format='));
	}
	elseif ($path === null)
	{
		$path = $arg;
	}
	else
	{
		fwrite(STDERR, "usage: inventory.php <clover.xml> [--floor=75] [--format=markdown|csv]\n");
		exit(2);
	}
}

if ($path === null || !is_file($path))
{
	fwrite(STDERR, "no Clover report: pass the path report.php --clover wrote\n");
	exit(2);
}

if (!in_array($format, ['markdown', 'csv'], true))
{
	fwrite(STDERR, "unknown format: " . $format . "\n");
	exit(2);
}

$xml = simplexml_load_file($path);

if ($xml === false)
{
	fwrite(STDERR, "could not parse " . $path . "\n");
	exit(2);
}

$root = dirname(__DIR__, 2) . '/';
$rows = [];
$empty = [];
$totalCovered = 0;
$totalExecutable = 0;

foreach ($xml->xpath('//file') as $file)
{
	$name = (string)$file['name'];
	$name = str_starts_with($name, $root) ? substr($name, strlen($root)) : $name;

	$metrics = $file->metrics;
	$executable = (int)$metrics['statements'];
	$covered = (int)$metrics['coveredstatements'];

	$totalExecutable += $executable;
	$totalCovered += $covered;

	if ($executable === 0)
	{
		$empty[] = $name;

		continue;
	}

	$rows[] = [
		'file' => $name,
		'covered' => $covered,
		'executable' => $executable,
		'percent' => $covered / $executable * 100,
		'shortfall' => max(0, (int)ceil($floor * $executable) - $covered),
	];
}

// Worst first by shortfall, so the top of the list is the shortest route to the floor
// rather than the biggest file.
usort($rows, static function (array $a, array $b): int
{
	return [$b['shortfall'], $a['percent']] <=> [$a['shortfall'], $b['percent']];
});

$below = array_values(array_filter($rows, static fn (array $row): bool => $row['shortfall'] > 0));

if ($format === 'csv')
{
	$handle = fopen('php://stdout', 'w');
	fputcsv($handle, ['file', 'covered', 'executable', 'percent', 'shortfall_to_floor'], ',', '"', '');

	foreach ($rows as $row)
	{
		fputcsv($handle, [
			$row['file'],
			$row['covered'],
			$row['executable'],
			number_format($row['percent'], 2, '.', ''),
			$row['shortfall'],
		], ',', '"', '');
	}

	foreach ($empty as $name)
	{
		fputcsv($handle, [$name, 0, 0, '', ''], ',', '"', '');
	}

	fclose($handle);
	exit(0);
}

printf("Files below the %d%% floor: %d of %d measured (%d with no executable lines)\n\n",
	(int)round($floor * 100), count($below), count($rows), count($empty));

echo "| File | Covered | Executable | % | Lines to the floor |\n";
echo "|---|---:|---:|---:|---:|\n";

foreach ($below as $row)
{
	printf("| `%s` | %d | %d | %.1f%% | %d |\n",
		$row['file'], $row['covered'], $row['executable'], $row['percent'], $row['shortfall']);
}

echo "\n";

printf("At or above the floor: %d files.\n", count($rows) - count($below));

if ($empty !== [])
{
	echo "\nNo executable lines (not a percentage, and not a gap):\n\n";

	foreach ($empty as $name)
	{
		echo '- `' . $name . "`\n";
	}
}

$percent = $totalExecutable === 0 ? 0.0 : $totalCovered / $totalExecutable * 100;

printf("\nTotal: %d of %d executable lines (%.20f%%)\n", $totalCovered, $totalExecutable, $percent);
printf("Lines still to cover for a %d%% total: %d\n",
	(int)round($floor * 100), max(0, (int)ceil($floor * $totalExecutable) - $totalCovered));
