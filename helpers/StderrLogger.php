<?php

namespace Victual\Helpers;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * A PSR-3 logger that writes one line per record to php://stderr.
 *
 * stderr is the whole of the retention story on purpose (plan 11, its question 7):
 * `kubectl logs` and `docker logs` are the log file on both deployment targets this fork
 * has, and a rotating file would reintroduce the writable path
 * plan 10 removed. Before this existed a 500 in production left no trace anywhere -
 * displayErrorDetails was gated on dev mode, which stopped serving stack traces to
 * clients, and nothing took over the job of recording them for the operator.
 *
 * The record is written as `[timestamp] LEVEL: message {context}`. Context is encoded as
 * JSON on the same line so that a log collector keeps one event as one record; a value it
 * cannot encode is substituted (null, or 0 for NaN and the infinities) rather than
 * dropping the whole line.
 */
class StderrLogger extends AbstractLogger
{
	/**
	 * The minimum severity, as a PSR-3 level name. Records below it are discarded.
	 */
	public function __construct(string $minimumLevel = LogLevel::DEBUG)
	{
		$this->MinimumLevel = $minimumLevel;
	}

	private string $MinimumLevel;

	/**
	 * PSR-3 severities, least severe first, so that a level can be compared by position.
	 */
	private const LEVEL_ORDER = [
		LogLevel::DEBUG,
		LogLevel::INFO,
		LogLevel::NOTICE,
		LogLevel::WARNING,
		LogLevel::ERROR,
		LogLevel::CRITICAL,
		LogLevel::ALERT,
		LogLevel::EMERGENCY
	];

	/**
	 * @param mixed $level
	 * @param string|\Stringable $message
	 * @param array $context
	 * @return void
	 */
	public function log($level, $message, array $context = []): void
	{
		if (!$this->IsAtLeastMinimumLevel((string)$level))
		{
			return;
		}

		$line = '[' . date('c') . '] ' . strtoupper((string)$level) . ': ' . (string)$message;

		if (!empty($context))
		{
			$line .= ' ' . self::EncodeContext($context);
		}

		// Opened per record rather than held open: this process serves one request and a
		// handle kept on a class would outlive the request in a worker SAPI without ever
		// being needed twice.
		$stream = @fopen('php://stderr', 'a');

		if ($stream === false)
		{
			return;
		}

		@fwrite($stream, $line . PHP_EOL);
		@fclose($stream);
	}

	/**
	 * Whether a level is at or above the configured minimum; an unknown level is kept.
	 */
	private function IsAtLeastMinimumLevel(string $level): bool
	{
		$position = array_search($level, self::LEVEL_ORDER, true);
		$minimum = array_search($this->MinimumLevel, self::LEVEL_ORDER, true);

		if ($position === false || $minimum === false)
		{
			// An unknown level is logged rather than swallowed - the alternative is losing
			// records because somebody spelled a constant wrong
			return true;
		}

		return $position >= $minimum;
	}

	/**
	 * Encodes a log context as single-line JSON, substituting any value JSON cannot hold.
	 */
	private static function EncodeContext(array $context): string
	{
		// json_encode() cannot return false with JSON_PARTIAL_OUTPUT_ON_ERROR: it returns
		// false only when an error was recorded and that flag is off (php-src PHP-8.4 and
		// PHP-8.5 ext/json/json.c, PHP_FUNCTION(json_encode)), and otherwise ends in
		// RETURN_STR(). What the flag does instead is substitute the offending value - null
		// for a resource, an invalid UTF-8 string, a recursion or a depth overrun, 0 for NaN
		// and the infinities - and keep the rest of the document. Pinned on both interpreters
		// by HelperUnitsTest::testAnUnencodableContextValueIsSubstitutedNotDropped() (#1026, D1).
		/** @var string $encoded */
		$encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

		return $encoded;
	}
}
