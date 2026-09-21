<?php

// Does the coverage wiring notice when a step stops being measured?
//
//   php .devtools/coverage/expectation-tests.php
//
// Issue 192 mechanics item 2 asks for the two things to be told apart: a step that ran and
// honestly reached nothing new, and a step that never ran under the driver at all. Only the
// first is a fact about the tests; the second is a broken setup that reads as a flat number
// and fails nothing. The distinction is VICTUAL_COVERAGE_LABEL — prepend.php makes it the
// filename's prefix — and report.php --expect, which asks that each named label left a file.
//
// Three cases, and the two negative ones are the point: a check that only demonstrates its
// happy path does not establish that anything would have failed. Each negative runs against
// a directory that already holds the positive control's file, because a stale file from some
// other step concealing a missing one is the specific way this mechanism could be useless.
//
// No database, no application boot: this is about the instrument, not about what it measures.

$root = dirname(__DIR__, 2);
$prepend = $root . '/.devtools/coverage/prepend.php';
$report = $root . '/.devtools/coverage/report.php';

$checks = 0;
$failures = 0;

function check(bool $ok, string $message): void
{
	global $checks, $failures;

	$checks++;

	if ($ok)
	{
		echo "  ok    " . $message . "\n";

		return;
	}

	$failures++;
	echo "  FAIL  " . $message . "\n";
}

/**
 * Runs a throwaway PHP process and returns [exit code, stdout, stderr]. The child does
 * nothing at all: what is under test is what auto_prepend_file leaves behind, not the body.
 *
 * The child is always a file rather than `php -r`, because auto_prepend_file is not applied
 * to code given on the command line — a -r child would silently exercise the unprepended
 * path in every case, including the one meant to be the positive control.
 */
function run(array $arguments, array $environment): array
{
	$command = 'php';
	foreach ($arguments as $argument)
	{
		$command .= ' ' . escapeshellarg($argument);
	}

	$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
	$process = proc_open($command, $descriptors, $pipes, null, $environment + ['PATH' => getenv('PATH')]);

	if (!is_resource($process))
	{
		throw new RuntimeException('could not start ' . $command);
	}

	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	return [proc_close($process), $stdout, $stderr];
}

function labelled(string $directory, string $label): array
{
	return glob(rtrim($directory, '/') . '/' . $label . '.*.cov') ?: [];
}

$directory = sys_get_temp_dir() . '/victual-coverage-expectation-' . bin2hex(random_bytes(6));
mkdir($directory, 0755, true);

// auto_prepend_file is not applied to `php -r`, so every child below is this file.
$child = $directory . '/child.php';
file_put_contents($child, "<?php\n\nexit(0);\n");

register_shutdown_function(static function () use ($directory)
{
	foreach (glob($directory . '/*') ?: [] as $file)
	{
		@unlink($file);
	}

	@rmdir($directory);
});

echo "\nCoverage expectations\n\n";

// 1. The positive control. Without this the two negatives below prove nothing: a mechanism
//    that refuses everything is not a mechanism that detects anything.

[$code, , $stderr] = run(
	['-d', 'auto_prepend_file=' . $prepend, '-d', 'pcov.enabled=1', $child],
	['VICTUAL_COVERAGE_DIR' => $directory, 'VICTUAL_COVERAGE_LABEL' => 'control-present', 'VICTUAL_ROOT' => $root]
);

check($code === 0, 'a wired process exits cleanly' . ($code === 0 ? '' : ': ' . trim($stderr)));
check(count(labelled($directory, 'control-present')) === 1, 'and writes exactly one file named for its label');

[$code, $stdout] = run([$report, $directory, '--expect=control-present'], []);
check($code === 0, 'report.php accepts a label whose step contributed' . ($code === 0 ? '' : ': ' . trim($stdout)));

// 2. The prepend path disabled. This is the realistic regression: a workflow step loses its
//    PHP_INI_SCAN_DIR, the process runs and passes, and the only symptom is a number that
//    stopped rising. The positive control's file is still sitting in the directory.

[$code] = run(
	['-d', 'pcov.enabled=1', $child],
	['VICTUAL_COVERAGE_DIR' => $directory, 'VICTUAL_COVERAGE_LABEL' => 'control-unprepended', 'VICTUAL_ROOT' => $root]
);

check($code === 0, 'a process with no auto_prepend_file still exits cleanly, as it would in CI');
check(labelled($directory, 'control-unprepended') === [], 'and leaves no coverage file');

[$code, , $stderr] = run([$report, $directory, '--expect=control-unprepended'], []);
check($code === 2, 'report.php refuses the run, rather than reporting an unchanged number');
check(str_contains($stderr, 'control-unprepended'), 'and names the step that went missing');
check(count(labelled($directory, 'control-present')) === 1, 'the file another step left behind did not satisfy it');

// 3. No coverage driver. prepend.php is loaded and says so on stderr, but there is nothing
//    for it to start, so it returns without registering the shutdown handler. -n drops every
//    ini file, which is how the extension is made absent without editing one.

[$code, , $stderr] = run(
	['-n', '-d', 'auto_prepend_file=' . $prepend, $child],
	['VICTUAL_COVERAGE_DIR' => $directory, 'VICTUAL_COVERAGE_LABEL' => 'control-driverless', 'VICTUAL_ROOT' => $root]
);

check($code === 0, 'a process with no driver still exits cleanly');
check(str_contains($stderr, 'no driver is available'), 'and prepend.php says why on stderr');
check(labelled($directory, 'control-driverless') === [], 'and leaves no coverage file');

[$code, , $stderr] = run([$report, $directory, '--expect=control-driverless'], []);
check($code === 2, 'report.php refuses that run too');
check(str_contains($stderr, 'control-driverless'), 'and names it');

// 4. An unknown label is refused whatever else the directory holds, so --expect cannot be
//    satisfied by a prefix that merely resembles one. Guards against a lazy substring match.

[$code] = run([$report, $directory, '--expect=control-pres'], []);
check($code === 2, 'a label that is only a prefix of a real one does not match it');

echo "\n";

if ($failures > 0)
{
	echo $failures . ' of ' . $checks . " checks failed\n";
	exit(1);
}

echo 'COVERAGE EXPECTATIONS HOLD (' . $checks . " checks)\n";
