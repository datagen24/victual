<?php

namespace Victual\Tests\Support;

use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * A child process whose stdout and stderr are read together, under a wall-clock bound.
 *
 * Issue #904: tests read a child's stdout to EOF and only then its stderr. A child that
 * writes more than a pipe buffer (64 KB on Linux) to stderr before it closes stdout blocks
 * in write(2) while the parent blocks on stdout, so the run hangs for good, and in a test
 * that holds a transaction open the hang holds its locks too. finish() drains both pipes
 * in one stream_select() loop and kills the child when the bound runs out.
 *
 * start() reads nothing, so a test can start several children (a lock holder and a
 * waiter, say), do other work, and finish them afterwards. Output is never capped: the
 * full buffers come back. Stdin is not wired unless start() is asked for it (a child that
 * waits for a "go" line); otherwise the child inherits the parent's.
 *
 * A child that prints a "ready" line and then waits for the parent is read with
 * readLine(), which keeps draining stderr while it waits for the stdout line, so a child
 * that fills stderr first cannot deadlock. A line readLine() returns is consumed:
 * finish() returns the stdout after it, as stream_get_contents() would after fgets().
 * Stderr is always returned whole.
 *
 * The bound counts from start(), not from finish(): a child started, left running, and
 * finished late has less time than finish()'s argument suggests (a child that has already
 * exited is never timed out for that). If the child exits but a descendant keeps its pipes
 * open, finish() waits one second for output, closes the pipes, and reports pipesHeldOpen
 * rather than signalling a pid it has already reaped.
 *
 * With a string command PHP runs it through sh -c, so on timeout only the shell is
 * signalled; pass an array to signal the program itself.
 */
final class Subprocess
{
	private const CHUNK = 65536;
	private const TICK_MICROSECONDS = 50000;
	private const TERM_GRACE_SECONDS = 2.0;
	private const REAP_SECONDS = 2.0;
	private const PIPE_GRACE_SECONDS = 1.0;
	private const TICK_READ_CAP = 1048576;
	private const TAIL_BYTES = 4096;
	private const SIGTERM = 15;
	private const SIGKILL = 9;

	/** @var resource|null */
	private $process;

	/** @var array<int, resource> */
	private array $pipes;

	/** @var resource|null The child's stdin, while it is open */
	private $stdin = null;

	/** @var array<int, string> */
	private array $buffers = [1 => '', 2 => ''];

	/** @var array<int, resource> Output pipes not yet at EOF */
	private array $open = [];

	/** Bytes of the stdout buffer already handed out by readLine(). */
	private int $consumed = 0;

	private int $pid;
	private float $startedAt;
	private bool $finished = false;

	/** @param resource $process @param array<int, resource> $pipes */
	private function __construct($process, array $pipes, private readonly string $commandLine)
	{
		$this->process = $process;
		$this->pipes = $pipes;
		$this->stdin = $pipes[0] ?? null;
		$this->open = [1 => $pipes[1], 2 => $pipes[2]];
		$this->startedAt = microtime(true);
		$this->pid = (int)(proc_get_status($process)['pid'] ?? 0);

		foreach ($this->open as $pipe)
		{
			stream_set_blocking($pipe, false);
		}
	}

	/**
	 * @param string[]|string $command An argv array (no shell) or a shell command line
	 * @param array<string, scalar>|null $env null inherits the parent's environment
	 * @param bool $stdin true gives the child a stdin pipe for write() and closeStdin()
	 */
	public static function start(array|string $command, ?array $env = null, ?string $cwd = null, bool $stdin = false): self
	{
		$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

		if ($stdin)
		{
			$descriptors = [0 => ['pipe', 'r']] + $descriptors;
		}

		$process = proc_open($command, $descriptors, $pipes, $cwd, $env);

		if (!is_resource($process))
		{
			throw new RuntimeException('proc_open failed for: ' . self::CommandLine($command));
		}

		return new self($process, $pipes, self::CommandLine($command));
	}

	/**
	 * @param string[]|string $command
	 * @param array<string, scalar>|null $env
	 * @return array{exit: int, stdout: string, stderr: string, timedOut: bool, pipesHeldOpen: bool, elapsed: float, command: string}
	 */
	public static function run(array|string $command, ?array $env = null, ?string $cwd = null, float $boundSeconds = 120.0): array
	{
		return self::start($command, $env, $cwd)->finish($boundSeconds);
	}

	public function Pid(): int
	{
		return $this->pid;
	}

	/** Writes to the child's stdin (start() with $stdin = true). Blocks until the bytes are in the pipe. */
	public function write(string $bytes): void
	{
		if ($this->stdin === null)
		{
			throw new RuntimeException('stdin is not open for: ' . $this->commandLine);
		}

		while ($bytes !== '')
		{
			$written = fwrite($this->stdin, $bytes);

			if ($written === false || $written === 0)
			{
				throw new RuntimeException('could not write to the stdin of: ' . $this->commandLine);
			}

			$bytes = substr($bytes, $written);
		}
	}

	/** Closes the child's stdin, so a child reading it sees EOF. finish() closes it too if it is still open. */
	public function closeStdin(): void
	{
		if ($this->stdin !== null)
		{
			fclose($this->stdin);
			$this->stdin = null;
		}
	}

	/**
	 * One line of the child's stdout, including its newline (like fgets()), or null when
	 * stdout reaches EOF with nothing left or the bound runs out. Stderr is drained into the
	 * buffer while this waits. A last line without a newline is returned at EOF. The bound
	 * counts from this call. Nothing is killed on null: finish() does that.
	 */
	public function readLine(float $boundSeconds = 120.0): ?string
	{
		if ($this->finished)
		{
			throw new RuntimeException('readLine() after finish() for: ' . $this->commandLine);
		}

		$deadline = microtime(true) + $boundSeconds;

		while (true)
		{
			$newline = strpos($this->buffers[1], "\n", $this->consumed);

			if ($newline !== false)
			{
				$line = substr($this->buffers[1], $this->consumed, $newline + 1 - $this->consumed);
				$this->consumed = $newline + 1;

				return $line;
			}

			if (!isset($this->open[1]))
			{
				if ($this->consumed < strlen($this->buffers[1]))
				{
					$line = substr($this->buffers[1], $this->consumed);
					$this->consumed = strlen($this->buffers[1]);

					return $line;
				}

				return null;
			}

			if (microtime(true) >= $deadline)
			{
				return null;
			}

			$this->Pump();
		}
	}

	/**
	 * Gives up on the child: SIGTERM, up to two seconds of grace, SIGKILL, then closes the
	 * pipes. For a test's finally block when the test failed before finish(). Does nothing
	 * after finish().
	 */
	public function abort(): void
	{
		if ($this->finished)
		{
			return;
		}

		$this->finished = true;
		$this->closeStdin();
		$process = $this->process;
		$exited = self::ExitOf(proc_get_status($process)) !== null;
		$deadline = microtime(true) + self::TERM_GRACE_SECONDS;

		if (!$exited)
		{
			proc_terminate($process, self::SIGTERM);
		}

		while (!$exited && microtime(true) < $deadline)
		{
			usleep(10000);
			$exited = self::ExitOf(proc_get_status($process)) !== null;
		}

		if (!$exited)
		{
			proc_terminate($process, self::SIGKILL);
			$deadline = microtime(true) + self::REAP_SECONDS;

			while (!$exited && microtime(true) < $deadline)
			{
				usleep(10000);
				$exited = self::ExitOf(proc_get_status($process)) !== null;
			}
		}

		foreach ([1, 2] as $number)
		{
			fclose($this->pipes[$number]);
		}

		$this->open = [];

		if ($exited)
		{
			proc_close($process);
		}

		$this->process = null;
	}

	/**
	 * Fails the running test when finish() reported a timeout, or a descendant still holding
	 * the pipes (so the output may be incomplete). Returns the result for chaining.
	 *
	 * @param array{exit: int, stdout: string, stderr: string, timedOut: bool, pipesHeldOpen: bool, elapsed: float, command: string} $result
	 * @return array{exit: int, stdout: string, stderr: string, timedOut: bool, pipesHeldOpen: bool, elapsed: float, command: string}
	 */
	public static function Completed(array $result): array
	{
		if ($result['timedOut'] || $result['pipesHeldOpen'])
		{
			Assert::fail(self::Describe($result));
		}

		return $result;
	}

	/**
	 * Reads both pipes until both reach EOF and the child has exited, or until the bound
	 * runs out. On expiry: SIGTERM, up to two seconds of grace, SIGKILL, a last
	 * non-blocking read, timedOut = true. "exit" is the code from the first status that
	 * reported the child gone, or 128 + the signal number when a signal ended it.
	 * "command" is the command line, for Describe().
	 *
	 * @return array{exit: int, stdout: string, stderr: string, timedOut: bool, pipesHeldOpen: bool, elapsed: float, command: string}
	 */
	public function finish(float $boundSeconds = 120.0): array
	{
		if ($this->finished)
		{
			throw new RuntimeException('finish() was already called for: ' . $this->commandLine);
		}

		$this->finished = true;
		$this->closeStdin();
		$process = $this->process;
		$deadline = $this->startedAt + $boundSeconds;
		$exit = null;
		$exitedAt = null;
		$timedOut = false;
		$pipesHeldOpen = false;
		$termSentAt = null;
		$killSentAt = null;

		while (true)
		{
			$this->Pump();

			// Status before the deadline: a child that already exited cleanly is not timed out
			// because finish() was called late.
			if ($exit === null)
			{
				$exit = self::ExitOf(proc_get_status($process));

				if ($exit !== null)
				{
					$exitedAt = microtime(true);
				}
			}

			if ($this->open === [] && $exit !== null)
			{
				break;
			}

			$now = microtime(true);

			if ($exit !== null)
			{
				// The child is gone and reaped, so its pid may be reused: never signal it. A
				// descendant that kept the pipes open gets a short grace to finish writing.
				if ($now >= $exitedAt + self::PIPE_GRACE_SECONDS)
				{
					$pipesHeldOpen = true;

					break;
				}
			}
			elseif (!$timedOut && $now >= $deadline)
			{
				$timedOut = true;
				$termSentAt = $now;
				proc_terminate($process, self::SIGTERM);
			}
			elseif ($timedOut && $killSentAt === null && $now >= $termSentAt + self::TERM_GRACE_SECONDS)
			{
				$killSentAt = $now;
				proc_terminate($process, self::SIGKILL);
			}
			elseif ($killSentAt !== null && $now >= $killSentAt + self::REAP_SECONDS)
			{
				// Still not reaped two seconds after SIGKILL (uninterruptible sleep, say).
				break;
			}
		}

		// Whatever is already in the pipes when the loop ends.
		foreach ($this->open as $number => $pipe)
		{
			$this->drain($number, $pipe);
		}

		foreach ([1, 2] as $number)
		{
			fclose($this->pipes[$number]);
		}

		if ($exit !== null)
		{
			proc_close($process);
		}
		// else: the child survived SIGKILL, and proc_close() would block in waitpid() until
		// it died. The handle is dropped unclosed instead, which leaks it until this PHP
		// process ends.

		$this->process = null;

		return [
			'exit' => $exit ?? -1,
			'stdout' => substr($this->buffers[1], $this->consumed),
			'stderr' => $this->buffers[2],
			'timedOut' => $timedOut,
			'pipesHeldOpen' => $pipesHeldOpen,
			'elapsed' => microtime(true) - $this->startedAt,
			'command' => $this->commandLine
		];
	}

	/**
	 * Assertion-message text for a finish() result: command, elapsed, exit, byte counts
	 * and the last 4 KB of each stream.
	 *
	 * @param array{exit: int, stdout: string, stderr: string, timedOut: bool, pipesHeldOpen?: bool, elapsed: float, command?: string} $result
	 */
	public static function Describe(array $result): string
	{
		return sprintf(
			"subprocess %s: %s after %.1f s, exit %d%s; stdout %d bytes, stderr %d bytes\n--- stdout (last %d bytes) ---\n%s\n--- stderr (last %d bytes) ---\n%s",
			$result['command'] ?? '(unknown command)',
			$result['timedOut'] ? 'timed out and was killed' : 'finished',
			$result['elapsed'],
			$result['exit'],
			($result['pipesHeldOpen'] ?? false) ? ' (a descendant still held the pipes open, so output may be incomplete)' : '',
			strlen($result['stdout']),
			strlen($result['stderr']),
			self::TAIL_BYTES,
			substr($result['stdout'], -self::TAIL_BYTES),
			self::TAIL_BYTES,
			substr($result['stderr'], -self::TAIL_BYTES)
		);
	}

	/** One select tick over the pipes still open: reads whatever is ready into the buffers. */
	private function Pump(): void
	{
		if ($this->open === [])
		{
			usleep(10000);

			return;
		}

		$read = array_values($this->open);
		$write = null;
		$except = null;

		// false is an interrupted system call; the warning is noise, so try again.
		if (@stream_select($read, $write, $except, 0, self::TICK_MICROSECONDS) === false)
		{
			usleep(10000);

			return;
		}

		foreach ($this->open as $number => $pipe)
		{
			if (in_array($pipe, $read, true))
			{
				$this->drain($number, $pipe);
			}
		}
	}

	/** @param resource $pipe */
	private function drain(int $number, $pipe): void
	{
		// A non-blocking fread returns at most what is buffered; loop so one tick empties it,
		// but stop at a cap so a writer that keeps the pipe full cannot starve the deadline.
		$taken = 0;

		while ($taken < self::TICK_READ_CAP)
		{
			$chunk = fread($pipe, self::CHUNK);

			if ($chunk !== false && $chunk !== '')
			{
				$this->buffers[$number] .= $chunk;
				$taken += strlen($chunk);

				continue;
			}

			break;
		}

		if (feof($pipe))
		{
			unset($this->open[$number]);
		}
	}

	/** @param array<string, mixed>|false $status */
	private static function ExitOf(array|false $status): ?int
	{
		if ($status === false || $status['running'])
		{
			return null;
		}

		if ($status['signaled'])
		{
			return 128 + $status['termsig'];
		}

		return $status['exitcode'];
	}

	/** @param string[]|string $command */
	private static function CommandLine(array|string $command): string
	{
		return is_array($command) ? implode(' ', array_map('escapeshellarg', $command)) : $command;
	}
}
