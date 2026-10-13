<?php

namespace Victual\Tests\Support;

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
 * full buffers come back. Stdin is not wired; the child inherits the parent's.
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
	private const TAIL_BYTES = 4096;
	private const SIGTERM = 15;
	private const SIGKILL = 9;

	/** @var resource|null */
	private $process;

	/** @var array<int, resource> */
	private array $pipes;

	private int $pid;
	private float $startedAt;
	private bool $finished = false;

	/** @param resource $process @param array<int, resource> $pipes */
	private function __construct($process, array $pipes, private readonly string $commandLine)
	{
		$this->process = $process;
		$this->pipes = $pipes;
		$this->startedAt = microtime(true);
		$this->pid = (int)(proc_get_status($process)['pid'] ?? 0);
	}

	/**
	 * @param string[]|string $command An argv array (no shell) or a shell command line
	 * @param array<string, scalar>|null $env null inherits the parent's environment
	 */
	public static function start(array|string $command, ?array $env = null, ?string $cwd = null): self
	{
		$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);

		if (!is_resource($process))
		{
			throw new RuntimeException('proc_open failed for: ' . self::CommandLine($command));
		}

		return new self($process, $pipes, self::CommandLine($command));
	}

	/**
	 * @param string[]|string $command
	 * @param array<string, scalar>|null $env
	 * @return array{exit: int, stdout: string, stderr: string, timedOut: bool, elapsed: float, command: string}
	 */
	public static function run(array|string $command, ?array $env = null, ?string $cwd = null, float $boundSeconds = 120.0): array
	{
		return self::start($command, $env, $cwd)->finish($boundSeconds);
	}

	public function Pid(): int
	{
		return $this->pid;
	}

	/**
	 * Reads both pipes until both reach EOF and the child has exited, or until the bound
	 * runs out. On expiry: SIGTERM, up to two seconds of grace, SIGKILL, a last
	 * non-blocking read, timedOut = true. "exit" is the code from the first status that
	 * reported the child gone, or 128 + the signal number when a signal ended it.
	 * "command" is the command line, for Describe().
	 *
	 * @return array{exit: int, stdout: string, stderr: string, timedOut: bool, elapsed: float, command: string}
	 */
	public function finish(float $boundSeconds = 120.0): array
	{
		if ($this->finished)
		{
			throw new RuntimeException('finish() was already called for: ' . $this->commandLine);
		}

		$this->finished = true;
		$process = $this->process;
		$buffers = [1 => '', 2 => ''];
		$open = [1 => $this->pipes[1], 2 => $this->pipes[2]];

		foreach ($open as $pipe)
		{
			stream_set_blocking($pipe, false);
		}

		$deadline = $this->startedAt + $boundSeconds;
		$exit = null;
		$timedOut = false;
		$termSentAt = null;
		$killSentAt = null;

		while (true)
		{
			$now = microtime(true);

			if (!$timedOut && $now >= $deadline)
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
				// SIGKILL does not return control of a pipe held open by a grandchild.
				break;
			}

			if ($open !== [])
			{
				$read = array_values($open);
				$write = null;
				$except = null;

				if (stream_select($read, $write, $except, 0, self::TICK_MICROSECONDS) !== false)
				{
					foreach ($open as $number => $pipe)
					{
						if (in_array($pipe, $read, true))
						{
							$this->drain($number, $pipe, $buffers, $open);
						}
					}
				}
			}

			if ($exit === null)
			{
				$exit = self::ExitOf(proc_get_status($process));
			}

			if ($open === [] && $exit !== null)
			{
				break;
			}

			if ($open === [])
			{
				usleep(10000);
			}
		}

		// Whatever is already in the pipes when the loop ends (the child may have exited
		// between the last select and the status check).
		foreach ($open as $number => $pipe)
		{
			$this->drain($number, $pipe, $buffers, $open);
		}

		foreach ($this->pipes as $pipe)
		{
			fclose($pipe);
		}

		$closeCode = proc_close($process);
		$this->process = null;

		return [
			'exit' => $exit ?? $closeCode,
			'stdout' => $buffers[1],
			'stderr' => $buffers[2],
			'timedOut' => $timedOut,
			'elapsed' => microtime(true) - $this->startedAt,
			'command' => $this->commandLine
		];
	}

	/**
	 * Assertion-message text for a finish() result: command, elapsed, exit, byte counts
	 * and the last 4 KB of each stream.
	 *
	 * @param array{exit: int, stdout: string, stderr: string, timedOut: bool, elapsed: float, command?: string} $result
	 */
	public static function Describe(array $result): string
	{
		return sprintf(
			"subprocess %s: %s after %.1f s, exit %d; stdout %d bytes, stderr %d bytes\n--- stdout (last %d bytes) ---\n%s\n--- stderr (last %d bytes) ---\n%s",
			$result['command'] ?? '(unknown command)',
			$result['timedOut'] ? 'timed out and was killed' : 'finished',
			$result['elapsed'],
			$result['exit'],
			strlen($result['stdout']),
			strlen($result['stderr']),
			self::TAIL_BYTES,
			substr($result['stdout'], -self::TAIL_BYTES),
			self::TAIL_BYTES,
			substr($result['stderr'], -self::TAIL_BYTES)
		);
	}

	/**
	 * @param array<int, string> $buffers
	 * @param array<int, resource> $open
	 * @param resource $pipe
	 */
	private function drain(int $number, $pipe, array &$buffers, array &$open): void
	{
		// A non-blocking fread returns at most what is buffered; loop so one tick empties it.
		while (true)
		{
			$chunk = fread($pipe, self::CHUNK);

			if ($chunk !== false && $chunk !== '')
			{
				$buffers[$number] .= $chunk;

				continue;
			}

			break;
		}

		if (feof($pipe))
		{
			unset($open[$number]);
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
