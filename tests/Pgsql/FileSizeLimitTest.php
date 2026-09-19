<?php

namespace Victual\Tests\Pgsql;

use PHPUnit\Framework\TestCase;

/**
 * The upload limit's clamp announcement is written once, at the CLI boot, and never by a
 * web process.
 *
 * Issue #217: FileSizeLimit memoized the effective limit in a static property and logged
 * the clamp when it first filled it, calling that "once per process". A static property
 * does not outlive a php-fpm request (ADR-0007), and ConfigurationValidator resolves the
 * limit at the top of every request, so the line was written once per request - 5,332
 * times in one year replay. Now the validator announces it only under the CLI SAPI,
 * which every deployment shape runs exactly once before serving (bin/victual-migrate).
 *
 * Each case is its own process (tests/Pgsql/file-size-limit-helper.php) because
 * upload_max_filesize is PHP_INI_PERDIR and cannot be changed from inside a running
 * script, and because the SAPI is the thing under test: the same helper runs under php
 * and under php-cgi, and what differs is only what reached error_log. No database.
 */
class FileSizeLimitTest extends TestCase
{
	private const CLAMP_LINE = '/Victual: FILE_STORAGE_MAX_SIZE_MB is 64 MB, but PHP\'s upload_max_filesize \(1M\) is smaller, so uploads are limited to 1 MB\./';

	private static string $datapath;

	public static function setUpBeforeClass(): void
	{
		self::$datapath = sys_get_temp_dir() . '/victual-filesizelimit-' . getmypid();
		mkdir(self::$datapath, 0700, true);
		file_put_contents(self::$datapath . '/config.php', "<?php\n");
	}

	public static function tearDownAfterClass(): void
	{
		@unlink(self::$datapath . '/config.php');
		@rmdir(self::$datapath);
	}

	/**
	 * @return array{output: array, log: string, exit: int, stderr: string}
	 */
	private static function boot(string $binary, string $uploadMax): array
	{
		$log = tempnam(sys_get_temp_dir(), 'victual-clamp-log-');
		$command = [
			$binary,
			'-d', 'upload_max_filesize=' . $uploadMax,
			'-d', 'post_max_size=64M',
			'-d', 'error_log=' . $log,
			'-d', 'log_errors=1',
			'-d', 'display_errors=0',
		];
		if (basename($binary) === 'php-cgi')
		{
			// no HTTP header block ahead of the JSON
			$command[] = '-q';
		}
		$command[] = __DIR__ . '/file-size-limit-helper.php';

		$env = array_merge(getenv(), [
			'VICTUAL_DATAPATH' => self::$datapath,
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);
		$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
		$output = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit = proc_close($process);

		$logged = file_get_contents($log);
		unlink($log);

		$decoded = json_decode($output, true);
		self::assertIsArray($decoded, "The helper printed no JSON under $binary (exit $exit). stdout: $output stderr: $stderr log: $logged");

		return ['output' => $decoded, 'log' => $logged, 'exit' => $exit, 'stderr' => $stderr];
	}

	private static function cgiBinary(): string
	{
		$candidate = dirname(PHP_BINARY) . '/php-cgi';
		if (!is_executable($candidate))
		{
			self::markTestSkipped('php-cgi is not installed next to ' . PHP_BINARY . '; the non-CLI half of issue #217 needs it');
		}

		return $candidate;
	}

	public function testTheCliBootAnnouncesTheClampOnce(): void
	{
		$run = self::boot(PHP_BINARY, '1M');

		self::assertSame('cli', $run['output']['sapi']);
		self::assertSame(1, $run['output']['effective_mb'], 'the effective limit is the smaller php.ini value');
		self::assertSame(1, preg_match_all(self::CLAMP_LINE, $run['log']), 'exactly one clamp line at the CLI boot; log was: ' . $run['log']);
	}

	public function testTheCliBootIsSilentWhenTheSettingIsHonoured(): void
	{
		$run = self::boot(PHP_BINARY, '64M');

		self::assertSame(64, $run['output']['effective_mb']);
		self::assertStringNotContainsString('FILE_STORAGE_MAX_SIZE_MB', $run['log'], 'nothing to announce when no directive binds; log was: ' . $run['log']);
	}

	public function testAWebProcessClampsTheSameButLogsNothing(): void
	{
		$run = self::boot(self::cgiBinary(), '1M');

		self::assertNotSame('cli', $run['output']['sapi'], 'php-cgi must not report the cli SAPI, or this case tests nothing');
		self::assertSame(1, $run['output']['effective_mb'], 'the clamp itself still applies to a web process');
		self::assertStringNotContainsString('FILE_STORAGE_MAX_SIZE_MB', $run['log'], 'a web process never writes the clamp line; log was: ' . $run['log']);
	}
}
