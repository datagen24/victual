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
// Six groups, and the negatives are the point: a check that only demonstrates its
// happy path does not establish that anything would have failed. Each negative runs against
// a directory that already holds the positive control's file, because a stale file from some
// other step concealing a missing one is the specific way this mechanism could be useless.
//
// No database, no application boot: this is about the instrument, not about what it measures.

$root = dirname(__DIR__, 2);
$prepend = $root . '/.devtools/coverage/prepend.php';
$report = $root . '/.devtools/coverage/report.php';
$inventory = $root . '/.devtools/coverage/inventory.php';

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

// 5. --min has the same failure shape as --expect and had no control until it bit. (float)
//    reads anything unparseable as 0.0, which is a threshold every run clears, so a typo or
//    an unexpanded placeholder disables the gate and leaves the step green. The three cases
//    below are the three ways that happens.

foreach (['RATCHET_PLACEHOLDER', '', 'ninety'] as $bad)
{
	[$code, , $stderr] = run([$report, $directory, '--min=' . $bad], []);
	check($code === 2, 'report.php refuses --min=' . ($bad === '' ? '<empty>' : $bad) . ' rather than reading it as zero');
	check(str_contains($stderr, 'needs a number'), 'and says so');
}

// And the control: a real figure still gates, in both directions.

[$code] = run([$report, $directory, '--min=0'], []);
check($code === 0, 'a run at or above its minimum passes');

[$code, $stdout] = run([$report, $directory, '--min=100'], []);
check($code === 1, 'a run below it fails with exit 1, which is not the setup failure exit 2');
check(str_contains($stdout, 'below the requested minimum'), 'and says by how much');

// 6. The two refusals CodeRabbit found on PR #256, which are the same shape as the --min
//    one above: a value cast instead of checked, and a rewrite that is not injective.

[$code, , $stderr] = run([$report, $directory, '--expect=not/a/label'], []);
check($code === 2, 'report.php refuses a label outside its own character rule');
check(str_contains($stderr, 'not a usable label'), 'and says what a label is');

// The collision itself: a process labelled "control/present" must not leave a file that
// satisfies an expectation for "control-present", which a sanitising rewrite would have
// made indistinguishable.
[$code, , $stderr] = run(
	['-d', 'auto_prepend_file=' . $prepend, '-d', 'pcov.enabled=1', $child],
	['VICTUAL_COVERAGE_DIR' => $directory, 'VICTUAL_COVERAGE_LABEL' => 'control/present', 'VICTUAL_ROOT' => $root]
);

check($code === 0, 'a process carrying an unusable label still runs');
check(str_contains($stderr, 'ignoring VICTUAL_COVERAGE_LABEL'), 'and prepend.php says it ignored the label');
check(count(labelled($directory, 'control-present')) === 1,
	'and left no second file under the label it would have been rewritten to');

// inventory.php takes a floor the same way report.php takes a minimum, and had the same
// gap: a cast turns an unreadable value into 0, and a floor of zero passes every file.
$clover = $directory . '/inventory.xml';
file_put_contents($clover, '<?xml version="1.0" encoding="UTF-8"?><coverage><project>'
	. '<file name="a.php"><metrics statements="10" coveredstatements="1"/></file>'
	. '</project></coverage>');

foreach (['RATCHET_PLACEHOLDER', '', '-1', '101'] as $bad)
{
	[$code, , $stderr] = run([$inventory, $clover, '--floor=' . $bad], []);
	check($code === 2, 'inventory.php refuses --floor=' . ($bad === '' ? '<empty>' : $bad));
	check(str_contains($stderr, 'needs a number from 0 through 100'), 'and says what it needs');
}

[$code, $stdout] = run([$inventory, $clover, '--floor=75'], []);
check($code === 0, 'and accepts a real floor');
check(str_contains($stdout, 'Files below the 75% floor: 1 of 1'),
	'reporting the file that is under it, which a floor of zero would not have');

echo "\n";

if ($failures > 0)
{
	echo $failures . ' of ' . $checks . " checks failed\n";
	exit(1);
}

echo 'COVERAGE EXPECTATIONS HOLD (' . $checks . " checks)\n";
