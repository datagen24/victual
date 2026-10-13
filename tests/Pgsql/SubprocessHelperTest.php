<?php

namespace Victual\Tests\Pgsql;

use PHPUnit\Framework\TestCase;
use Victual\Tests\Support\Subprocess;

/**
 * tests/Support/Subprocess.php: pure PHP, no database. Issue #904: test code that read a
 * child's stdout to EOF and only then its stderr hung for good once the child wrote more
 * than a pipe buffer (64 KB) to stderr, because the child blocked in write(2) on stderr
 * while the parent blocked reading stdout.
 */
class SubprocessHelperTest extends TestCase
{
	private const BYTES = 262144;

	private int $grandchildPid = 0;

	public function testStderrLargerThanThePipeBufferDoesNotBlockTheChild(): void
	{
		$code = '$line = str_repeat("e", 4095) . "\n";'
			. 'for ($i = 0; $i < 64; $i++) { fwrite(STDERR, $line); }'
			. 'echo json_encode(["status" => 200]), "\n";'
			. 'exit(3);';

		$result = Subprocess::run([PHP_BINARY, '-r', $code], null, null, 30.0);

		self::assertFalse($result['timedOut'], Subprocess::Describe($result));
		self::assertSame(3, $result['exit']);
		self::assertSame(['status' => 200], json_decode($result['stdout'], true));
		self::assertSame(self::BYTES, strlen($result['stderr']));
		self::assertLessThan(30.0, $result['elapsed']);
	}

	public function testInterleavedStdoutAndStderrArriveIntact(): void
	{
		$code = 'for ($i = 0; $i < 64; $i++) {'
			. 'fwrite(STDOUT, str_repeat(chr(65 + $i % 26), 4096));'
			. 'fwrite(STDERR, str_repeat(chr(97 + $i % 26), 4096));'
			. '}';

		$result = Subprocess::run([PHP_BINARY, '-r', $code], null, null, 30.0);

		$expectedOut = '';
		$expectedErr = '';

		for ($i = 0; $i < 64; $i++)
		{
			$expectedOut .= str_repeat(chr(65 + $i % 26), 4096);
			$expectedErr .= str_repeat(chr(97 + $i % 26), 4096);
		}

		self::assertFalse($result['timedOut'], Subprocess::Describe($result));
		self::assertSame(0, $result['exit']);
		self::assertSame(self::BYTES, strlen($result['stdout']));
		self::assertSame(self::BYTES, strlen($result['stderr']));
		self::assertTrue($expectedOut === $result['stdout'], 'stdout was altered');
		self::assertTrue($expectedErr === $result['stderr'], 'stderr was altered');
	}

	public function testAChildThatOutlivesTheBoundIsKilledAndReportedAsTimedOut(): void
	{
		$command = [PHP_BINARY, '-r', 'fwrite(STDOUT, "started"); sleep(60);'];
		$process = Subprocess::start($command);
		$pid = $process->Pid();

		$result = $process->finish(2.0);

		self::assertTrue($result['timedOut']);
		self::assertLessThan(10.0, $result['elapsed']);
		self::assertSame('started', $result['stdout'], 'what the child wrote before the kill is kept');
		self::assertFalse(posix_kill($pid, 0), 'the child must be gone after finish()');
		self::assertStringContainsString('sleep(60)', Subprocess::Describe($result), 'the diagnostics name the command');
		self::assertStringContainsString('timed out', Subprocess::Describe($result));
	}

	public function testANonZeroExitWithNoOutputIsCapturedAsThatExitCode(): void
	{
		$result = Subprocess::run([PHP_BINARY, '-r', 'exit(7);'], null, null, 30.0);

		self::assertFalse($result['timedOut']);
		self::assertSame(7, $result['exit']);
		self::assertSame('', $result['stdout']);
		self::assertSame('', $result['stderr']);
	}

	public function testStartReadsNothingSoSeveralChildrenCanRunBeforeAnyIsFinished(): void
	{
		$first = Subprocess::start([PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("x", 200000)); echo "one";']);
		$second = Subprocess::start([PHP_BINARY, '-r', 'echo "two";']);

		$secondResult = $second->finish(30.0);
		$firstResult = $first->finish(30.0);

		self::assertSame('two', $secondResult['stdout']);
		self::assertSame('one', $firstResult['stdout']);
		self::assertSame(200000, strlen($firstResult['stderr']));
	}

	public function testFinishCalledLateAfterCleanExit(): void
	{
		$process = Subprocess::start([PHP_BINARY, '-r', 'fwrite(STDERR, "warn"); echo "late";']);

		sleep(3);

		$result = $process->finish(2.0);

		self::assertFalse($result['timedOut'], 'a child that exited before the bound is not timed out: ' . Subprocess::Describe($result));
		self::assertSame(0, $result['exit']);
		self::assertSame('late', $result['stdout']);
		self::assertSame('warn', $result['stderr']);
	}

	public function testGrandchildHoldsPipes(): void
	{
		$result = Subprocess::run(['sh', '-c', 'sleep 30 & echo $!; exit 0'], null, null, 3.0);

		$this->grandchildPid = (int)trim($result['stdout']);

		self::assertGreaterThan(0, $this->grandchildPid, Subprocess::Describe($result));
		self::assertLessThan(3.0, $result['elapsed']);
		self::assertSame(0, $result['exit']);
		self::assertFalse($result['timedOut'], Subprocess::Describe($result));
		self::assertTrue($result['pipesHeldOpen'] ?? false, 'the result says a descendant kept the pipes open');
		self::assertStringContainsString('pipes', Subprocess::Describe($result));
		self::assertTrue(posix_kill($this->grandchildPid, 0), 'the helper does not signal a process it did not time out');
	}

	protected function tearDown(): void
	{
		if ($this->grandchildPid > 0)
		{
			posix_kill($this->grandchildPid, 9);
			$this->grandchildPid = 0;
		}
	}

	public function testEnvironmentAndWorkingDirectoryReachTheChild(): void
	{
		$result = Subprocess::run(
			[PHP_BINARY, '-r', 'echo getenv("SUBPROCESS_HELPER_PROBE"), "|", getcwd();'],
			['SUBPROCESS_HELPER_PROBE' => 'probe-value', 'PATH' => (string)getenv('PATH')],
			sys_get_temp_dir(),
			30.0
		);

		self::assertSame('probe-value|' . realpath(sys_get_temp_dir()), $result['stdout']);
	}
}
