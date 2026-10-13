<?php

// A stand-in for InfluxDB, for the suite and for local probing.
//
//   php -S 127.0.0.1:<port> .devtools/mqtt/influx-standin.php
//
// It accepts POST /api/v2/write, appends the request to the file named by
// VICTUAL_STANDIN_LOG, and answers 204 the way the real server does.
//
// Three ways to make it fail, all so that a probe can exercise the failure path without
// needing an unroutable address and its multi-second timeout:
//
//   VICTUAL_STANDIN_REJECT=1        answer 500 to everything
//   VICTUAL_STANDIN_FAIL_AFTER=N    answer 204 to the first N writes and 500 after that,
//                                   which is how the drain loop's "stop at the first
//                                   failure" behaviour gets tested mid-backlog
//   VICTUAL_STANDIN_CONTROL=<path>  read the mode from that file on every request, so a
//                                   test can flip a running server between rejecting and
//                                   accepting without restarting it. The file holds
//                                   "reject", "accept", "fail-after:N", "redirect",
//                                   "ok-with-body", "status:CODE" or "lines:MARKER=CODE,...";
//                                   it wins over the variables above, and a missing file
//                                   means accept.
//
// The last two modes exist for one defect. A write is only delivered when the *write
// endpoint* acknowledged it, and two shapes used to pass for an acknowledgement:
//
//   redirect       302 to /login, which is what an expired session or an SSO portal in front
//                  of InfluxDB answers with. Guzzle follows redirects by default and does not
//                  raise for a 3xx it cannot follow, so both a bare 302 and a 302 followed to
//                  a 200 login page came back as success.
//   ok-with-body   200 with an HTML page, which is what a proxy that answers everything with
//                  its own page looks like. InfluxDB's write API answers 204 with no body.
//
// The last two modes exist for permanent rejections (issue #854). InfluxDB answers 400 to a
// body it cannot parse and 413 to one that is too large, and 401 or 404 to a token or bucket
// that is wrong; the drain has to tell those apart:
//
//   status:CODE           answer CODE to every write, whatever it carries (401, 404, 500, ...)
//   lines:MARKER=CODE,..  answer CODE to a write whose body contains MARKER, 204 otherwise.
//                         The first listed marker found in the body wins, so a test can make
//                         one row a permanent 400 and another a transient 500, and a batch
//                         holding both answers 400. A rejection carries InfluxDB's own JSON
//                         error shape, which is the text a dead-lettered row keeps.
//
// GET /login always answers a 200 login page, whatever the mode - so a probe can assert not
// only that the write failed but that /login was never requested at all, which is what proves
// the redirect was refused rather than followed.
//
// The counts come from the log file rather than from memory: the built-in server runs the
// router script afresh per request, so there is nowhere else to keep them.
//
// PHP's own built-in server rather than node, so the suite phase that uses this has no
// dependency beyond the PHP the rest of the suite already needs - a probe that only runs
// where somebody installed node is a probe CI skips.

$log = getenv('VICTUAL_STANDIN_LOG') ?: (sys_get_temp_dir() . '/victual-influx-standin.log');
$body = file_get_contents('php://input');

file_put_contents($log, '=== ' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . ($_SERVER['REQUEST_URI'] ?? '?') . "\n"
	. 'authorization: ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? '') . "\n"
	. $body . "\n", FILE_APPEND);

// Whatever the mode, this path is the login page a redirect would point at. It is served
// before any mode is read, so a probe asserting "/login was never requested" is asserting
// something this script would happily have answered.
if (str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/login'))
{
	http_response_code(200);
	echo '<html><body><h1>Sign in</h1><form method="post"></form></body></html>';

	return true;
}

$reject = getenv('VICTUAL_STANDIN_REJECT') === '1';
$failAfter = getenv('VICTUAL_STANDIN_FAIL_AFTER');

// The control file, when there is one, is the authority - that is the point of it: a test
// that has to change the server's behaviour part way through cannot restart it without
// losing the log the same test is counting.
$control = getenv('VICTUAL_STANDIN_CONTROL');
if ($control !== false && $control !== '' && file_exists($control))
{
	$mode = trim((string)file_get_contents($control));

	if ($mode === 'redirect')
	{
		// A bare 302 with a Location. Followed, it ends at the login page above; not
		// followed, it is a 302 that never wrote anything. Neither is an acknowledgement.
		http_response_code(302);
		header('Location: /login');

		return true;
	}

	if ($mode === 'ok-with-body')
	{
		// The shape that is hardest to tell from success: a 2xx, from the right address,
		// carrying a page instead of the empty body InfluxDB's write API answers with.
		http_response_code(200);
		echo '<html><body><h1>Sign in</h1></body></html>';

		return true;
	}

	if (str_starts_with($mode, 'status:'))
	{
		http_response_code((int)substr($mode, strlen('status:')));
		echo 'answered by the stand-in';

		return true;
	}

	if (str_starts_with($mode, 'lines:'))
	{
		foreach (explode(',', substr($mode, strlen('lines:'))) as $rule)
		{
			[$marker, $code] = array_pad(explode('=', $rule, 2), 2, '400');

			if ($marker !== '' && str_contains($body, $marker))
			{
				http_response_code((int)$code);
				header('Content-Type: application/json');
				echo '{"code":"invalid","message":"unable to parse line containing ' . $marker . '"}';

				return true;
			}
		}

		http_response_code(204);

		return true;
	}

	if ($mode === 'reject')
	{
		$reject = true;
		$failAfter = false;
	}
	elseif ($mode === 'accept')
	{
		$reject = false;
		$failAfter = false;
	}
	elseif (str_starts_with($mode, 'fail-after:'))
	{
		$reject = false;
		$failAfter = substr($mode, strlen('fail-after:'));
	}
}

if (!$reject && $failAfter !== false && $failAfter !== '')
{
	// This request is already in the log, so the count includes it
	$reject = substr_count(file_get_contents($log), '=== POST') > (int)$failAfter;
}

if ($reject)
{
	http_response_code(500);
	echo 'rejected by the stand-in';

	return true;
}

http_response_code(204);

return true;
