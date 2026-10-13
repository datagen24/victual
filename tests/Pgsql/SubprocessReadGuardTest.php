<?php

namespace Victual\Tests\Pgsql;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Issue #904: no test reads a child's pipes by hand. A read of one pipe to EOF while the
 * other is unread is the deadlock the helper exists to prevent, and nothing marks the next
 * copy of that shape as wrong unless something fails on it. This scans every PHP file under
 * tests/ for stream_get_contents(), fgets(), fread(), fgetc() and stream_get_line() called
 * on a variable named like a pipe ($pipes[1], $pipe, $consumePipes[2]); tests/Support/
 * Subprocess.php, which does the reading, is the one file exempt.
 *
 * Reads of other resources (a socket, a stored file, STDIN in a child) do not match.
 */
class SubprocessReadGuardTest extends TestCase
{
	private const READ = '/\b(?:stream_get_contents|fgets|fread|fgetc|stream_get_line)\s*\(\s*\$\w*[Pp]ipes?\b/';

	/** @var string[] */
	private array $planted = [];

	/** @var string[] */
	private array $plantedDirectories = [];

	protected function tearDown(): void
	{
		foreach ($this->planted as $path)
		{
			@unlink($path);
		}

		foreach (array_reverse($this->plantedDirectories) as $directory)
		{
			@rmdir($directory);
		}
	}

	/**
	 * @return array<int, string> "relative/path.php:line: the offending line" for each hand-rolled pipe read under $directory
	 */
	public static function Violations(string $directory): array
	{
		$exempt = realpath($directory . '/Support/Subprocess.php');
		$found = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

		foreach ($files as $file)
		{
			if ($file->getExtension() !== 'php' || $file->getRealPath() === $exempt)
			{
				continue;
			}

			foreach (file($file->getPathname()) ?: [] as $index => $line)
			{
				if (preg_match(self::READ, $line) === 1)
				{
					$found[] = substr($file->getPathname(), strlen($directory) + 1) . ':' . ($index + 1) . ': ' . trim($line);
				}
			}
		}

		sort($found);

		return $found;
	}

	public function testNoTestReadsAChildsPipesByHand(): void
	{
		self::assertSame(
			[],
			self::Violations(VICTUAL_ROOT_PATH . '/tests'),
			'Read a child through tests/Support/Subprocess.php (start()/finish(), readLine() for a ready line), not with stream_get_contents()/fgets() on its pipes.'
		);
	}

	public function testThePatternFiresOnEachShapeOfHandReadAndOnNothingElse(): void
	{
		$directory = sys_get_temp_dir() . '/subprocess-guard-' . getmypid() . '-' . bin2hex(random_bytes(3));
		mkdir($directory . '/Support', 0700, true);
		$this->plantedDirectories = [$directory, $directory . '/Support'];

		// Built from pieces so that this file does not contain what it plants.
		$read = 'stream_get' . '_contents';
		$line = 'fg' . 'ets';
		$violating = "<?php\n"
			. '$output = ' . $read . '($pipes[1]);' . "\n"
			. '$first = ' . $line . '($pipes[1]);' . "\n"
			. '$errors = ' . $read . '( $consumePipes[2] );' . "\n"
			. 'self::assertSame("ready\n", ' . $line . '($pipe), "x");' . "\n";
		$clean = "<?php\n"
			. '$a = ' . $read . '($socket);' . "\n"
			. '$b = ' . $read . '($stored[\'content\']);' . "\n"
			. $line . '(STDIN);' . "\n"
			. '$c = ' . $line . '($handle);' . "\n"
			. '// a comment about the pipes: ' . "\n"
			. '$d = $pipes[1];' . "\n";
		$exempt = "<?php\n" . '$output = ' . $read . '($pipes[1]);' . "\n";

		$this->plant($directory . '/Violating.php', $violating);
		$this->plant($directory . '/Clean.php', $clean);
		$this->plant($directory . '/Support/Subprocess.php', $exempt);
		$this->plant($directory . '/notes.txt', $violating);

		$found = self::Violations($directory);

		self::assertSame(
			['Violating.php:2', 'Violating.php:3', 'Violating.php:4', 'Violating.php:5'],
			array_map(static fn (string $entry): string => explode(': ', $entry, 2)[0], $found),
			"the scan reports exactly the four hand reads in the planted file:\n" . implode("\n", $found)
		);
	}

	private function plant(string $path, string $contents): void
	{
		file_put_contents($path, $contents);
		$this->planted[] = $path;
	}
}
