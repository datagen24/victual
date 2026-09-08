<?php

// RFC 8785 canonicalization: vectors, then a differential run against an ECMAScript oracle.
//
//   php .devtools/labels/canonical-json-tests.php
//
// Two halves, because one of them cannot be written in PHP. The vectors below pin the places
// ADR-0021 prerequisite 5 found PHP's own encoder diverging - the number layout at the 1e+21
// and 1e-7 boundaries, the solidus, non-ASCII, and UTF-16 member ordering - and the refusals,
// which an oracle that never sees them cannot check. The differential half then runs a corpus
// through `.devtools/labels/canonical-json-oracle.js`, which is correct by construction rather
// than by testing; two thousand of its documents are doubles built from random bit patterns,
// because that is where a hand-written layout goes wrong and no vector list is going to guess
// which double it goes wrong on.
//
// CANONICAL_JSON_EMIT_ONLY=1 writes the corpus and stops, for the split environment this
// repository actually has on a Mac: PHP runs in the dev container and node runs on the host.
// CI runs the whole thing in one step, because the suite job has both.

define('VICTUAL_ROOT_PATH', dirname(__DIR__, 2));

require_once VICTUAL_ROOT_PATH . '/helpers/ECanonicalizationFailed.php';
require_once VICTUAL_ROOT_PATH . '/helpers/CanonicalJson.php';

use Victual\Helpers\CanonicalJson;
use Victual\Helpers\ECanonicalizationFailed;

$checks = 0;

function Check(bool $ok, string $message): void
{
	global $checks;

	if (!$ok)
	{
		throw new RuntimeException($message);
	}

	$checks++;
}

function Encodes($document, string $expected, string $message): void
{
	$actual = CanonicalJson::Encode($document);
	Check($actual === $expected, $message . ': expected ' . $expected . ', got ' . $actual);
}

function Refuses(callable $work, string $message): void
{
	try
	{
		$work();
	}
	catch (ECanonicalizationFailed $exception)
	{
		Check(true, $message);

		return;
	}

	throw new RuntimeException('Expected a refusal: ' . $message);
}

// --- Structure ----------------------------------------------------------------------------

Encodes([], '[]', 'The empty array is a JSON array');
Encodes(new stdClass(), '{}', 'The empty object is a JSON object');
Encodes(['b' => 1, 'a' => 2], '{"a":2,"b":1}', 'Members are ordered, not preserved');
Encodes(['a' => ['c' => true, 'b' => null]], '{"a":{"b":null,"c":true}}', 'Ordering is recursive');
Encodes([1, 2, 3], '[1,2,3]', 'Array order is the document, not a set');
Encodes([1 => 'x', 0 => 'y'], '{"0":"y","1":"x"}', 'A non-sequential integer-keyed array is an object');

// The rule a byte-order sort gets backwards. U+1F600 is a surrogate pair whose leading unit
// is U+D83D, below U+FB00, so it sorts first by code unit and last by UTF-8 byte.
Encodes(["\u{1F600}" => 1, "\u{FB00}" => 2], '{"' . "\u{1F600}" . '":1,"' . "\u{FB00}" . '":2}', 'Members sort by UTF-16 code unit');
Check(strcmp("\u{1F600}", "\u{FB00}") > 0, 'The UTF-8 byte order of those two keys is the opposite one');

// --- Strings ------------------------------------------------------------------------------

Encodes('a/b', '"a/b"', 'The solidus is not escaped');
Encodes('é', '"é"', 'Non-ASCII is emitted as UTF-8, not as \\u');
Encodes("\u{1F600}", '"' . "\u{1F600}" . '"', 'An astral character is emitted as UTF-8');
Encodes("a\"b\\c", '"a\\"b\\\\c"', 'The quote and the reverse solidus are escaped');
Encodes("\x08\x09\x0A\x0C\x0D", '"\\b\\t\\n\\f\\r"', 'C0 controls with shortcuts use them');
Encodes("\x00\x1F", '"\\u0000\\u001f"', 'Other C0 controls are lowercase \\u escapes');

// --- Numbers ------------------------------------------------------------------------------

Encodes(0.0, '0', 'Zero is zero');
Encodes(-0.0, '0', 'Negative zero serializes as zero');
Encodes(1.0, '1', 'A whole double drops its fraction');
Encodes(-1.5, '-1.5', 'A negative fraction keeps its sign');
Encodes(1.0e20, '100000000000000000000', 'Below the exponential boundary the digits are written out');
Encodes(1.0e21, '1e+21', 'At 1e+21 the layout becomes exponential');
Encodes(1.0e30, '1e+30', 'PHP writes 1.0e+30 here and RFC 8785 does not');
Encodes(1.0e-6, '0.000001', 'Above the small boundary the leading zeros are written out');
Encodes(1.0e-7, '1e-7', 'At 1e-7 the layout becomes exponential');
Encodes(5.0e-324, '5e-324', 'The smallest subnormal round-trips');
Encodes(1.7976931348623157e308, '1.7976931348623157e+308', 'The largest double round-trips');
Encodes(9007199254740992, '9007199254740992', '2^53 is exactly representable and is accepted');
Encodes(0.1, '0.1', 'Shortest round-trip digits, not seventeen of them');

Refuses(fn () => CanonicalJson::Encode(9007199254740993), '2^53+1 does not survive a double and is refused');
Refuses(fn () => CanonicalJson::Encode(NAN), 'NaN is refused rather than encoded');
Refuses(fn () => CanonicalJson::Encode(INF), 'Infinity is refused rather than encoded');
Refuses(fn () => CanonicalJson::Encode("\xC3\x28"), 'Invalid UTF-8 is refused');

// --- The digest is over the canonical bytes -----------------------------------------------

Check(
	CanonicalJson::Digest(['b' => 1, 'a' => [2, 3]]) === CanonicalJson::Digest(['a' => [2, 3], 'b' => 1]),
	'The digest does not depend on member order'
);
Check(
	CanonicalJson::Digest(['a' => 1]) === hash('sha256', '{"a":1}'),
	'The digest is SHA-256 over the canonical encoding and nothing else'
);
Check(
	CanonicalJson::Digest(['a' => [1, 2]]) !== CanonicalJson::Digest(['a' => [2, 1]]),
	'Array order is part of the document'
);

echo "  vectors: $checks assertions passed" . PHP_EOL;

// --- The differential corpus --------------------------------------------------------------

$corpus = [];

function Document($value): void
{
	global $corpus;

	try
	{
		$php = CanonicalJson::Encode($value);
	}
	catch (ECanonicalizationFailed $exception)
	{
		$php = null;
	}

	// The document travels to the oracle as JSON text and is parsed there, so a double
	// reaches node as the identical double: the shortest representation round-trips exactly,
	// which is the property that makes it the shortest representation.
	$encoded = json_encode(['doc' => $value, 'php' => $php], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	if ($encoded === false)
	{
		return;
	}

	$corpus[] = $encoded;
}

// Two thousand doubles from random bit patterns. Rejecting the non-finite ones here rather
// than encoding them is deliberate: their refusal is a vector above, and an oracle that
// answers "null" for them would agree with nothing.
mt_srand(20260908);
$doubles = 0;

while ($doubles < 2000)
{
	$bits = pack('N2', mt_rand(0, 0xFFFFFFFF), mt_rand(0, 0xFFFFFFFF));
	$value = unpack('E', $bits)[1];

	if (is_nan($value) || is_infinite($value))
	{
		continue;
	}

	Document($value);
	$doubles++;
}

// Then the shapes that are not about number layout at all.
$strings = ['', 'a/b', 'é', "\u{1F600}", "line\nbreak", "\x00", 'tab	here', 'ﬀ', 'Ünicode', '"quoted"'];

foreach ($strings as $string)
{
	Document($string);
	Document([$string => 1]);
	Document([$string]);
}

foreach ([0.0, -0.0, 1.0, -1.5, 1e20, 1e21, 1e-6, 1e-7, 5e-324, 1.7976931348623157e308, 0.1, 1e30] as $number)
{
	Document($number);
	Document(['n' => $number, 'a' => [$number]]);
}

foreach ([0, 1, -1, 9007199254740992, -9007199254740992, PHP_INT_MAX] as $integer)
{
	Document($integer);
}

Document(null);
Document(true);
Document(false);
Document([]);
Document(new stdClass());
Document(['z' => 1, 'a' => 2, 'M' => 3, 'm' => 4, '0' => 5, "\u{1F600}" => 6, 'ﬀ' => 7]);
Document([['a' => [1, ['b' => null]]], 'x']);
Document(['nested' => ['deep' => ['deeper' => ['deepest' => [1, 2.5, 'three', true, null]]]]]);

$corpusPath = ($_SERVER['CANONICAL_JSON_CORPUS'] ?? '') !== ''
	? $_SERVER['CANONICAL_JSON_CORPUS']
	: sys_get_temp_dir() . '/victual-canonical-json-corpus.jsonl';

file_put_contents($corpusPath, implode("\n", $corpus) . "\n");

echo '  corpus: ' . count($corpus) . " documents written to $corpusPath" . PHP_EOL;

if (($_SERVER['CANONICAL_JSON_EMIT_ONLY'] ?? '') === '1')
{
	echo '  oracle: not run (CANONICAL_JSON_EMIT_ONLY=1); run' . PHP_EOL;
	echo "          node .devtools/labels/canonical-json-oracle.js $corpusPath" . PHP_EOL;
	exit(0);
}

$oracle = VICTUAL_ROOT_PATH . '/.devtools/labels/canonical-json-oracle.js';
$command = 'node ' . escapeshellarg($oracle) . ' ' . escapeshellarg($corpusPath);
$output = [];
$status = 0;
exec($command . ' 2>&1', $output, $status);

echo implode(PHP_EOL, $output) . PHP_EOL;

if ($status !== 0)
{
	// Not skipped when node is missing. A differential test that quietly becomes a vector
	// test is the failure mode this half exists to avoid, so an absent oracle is a failure
	// with the two-step instructions rather than a pass.
	echo 'The oracle did not agree, or node is not on PATH. In the split environment run:' . PHP_EOL;
	echo "  CANONICAL_JSON_EMIT_ONLY=1 php .devtools/labels/canonical-json-tests.php" . PHP_EOL;
	echo "  node .devtools/labels/canonical-json-oracle.js $corpusPath" . PHP_EOL;
	exit(1);
}

echo 'CANONICAL JSON OK' . PHP_EOL;
