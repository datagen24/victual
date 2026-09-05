<?php

// The error path renders without a database.
//
// Run through run-tests.sh errors, which runs it twice: once against a database that
// cannot be reached, once against the migrated one.
//
// What this guards is an escalation, not a connect failure. A transient PostgreSQL
// connect failure used to end the request as an uncaught PDOException rather than as a
// rendered 500, because the reporting path needed the thing that had failed:
// ExceptionController extends BaseController, whose constructor opened the connection, so
// app.php constructing the handler at bootstrap - in front of the error middleware it was
// being handed to - raised the failure where nothing could catch it. The client saw no
// status and no body at all, only its proxy's read timeout, and the worker was gone.
//
// So the assertions are about what the handler answers, in both directions: nothing may
// escape it when the database is unreachable, and the real pages must still be the ones
// rendered when it is. The second half is what stops the fallback quietly becoming the
// normal answer - a fallback that is always used is not a fallback, and nothing about the
// first half alone would notice.
//
// Exit codes: 0 when every assertion holds, 1 otherwise.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

use Victual\Controllers\ExceptionController;
use Psr\Log\AbstractLogger;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

$mode = $argv[1] ?? '';

if ($mode !== 'unreachable' && $mode !== 'reachable')
{
	fwrite(STDERR, 'usage: error-path-tests.php unreachable|reachable' . PHP_EOL);
	exit(1);
}

// Records rather than writes, so that "the fallback said so in the log" is an assertion
// instead of something a reader of the run has to notice.
class RecordingLogger extends AbstractLogger
{
	public array $Records = [];

	public function log($level, $message, array $context = []): void
	{
		$this->Records[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => $context];
	}
}

$checks = 0;

function check(bool $ok, string $message): void
{
	global $checks;

	if (!$ok)
	{
		throw new RuntimeException($message);
	}

	$checks++;
}

function request(string $path)
{
	return (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $path);
}

// The failure this is all about, as the driver actually raises it. Constructed rather
// than provoked, because provoking it means waiting for a connect timeout and the
// handler cannot tell the difference.
function connectFailure(): \PDOException
{
	return new \PDOException('SQLSTATE[08006] [7] connection to server at "postgres" port 5432 failed: timeout expired');
}

$container = new DI\Container();
$container->set('view', new Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_VIEWCACHE_PATH));
$container->set('UrlManager', new Victual\Helpers\UrlManager(''));

$logger = new RecordingLogger();

// Frame #5 of the original trace: app.php constructs the handler while wiring the
// application up, before a single request is served. On an installation whose database is
// down this threw, and there was nothing above it - the error middleware being built is
// what would have caught it.
$controller = new ExceptionController($container, new ResponseFactory(), $logger);
check(true, 'Constructing the handler does not need a database');

// A view route, the case from the report: an uncaught PDOException on a rendered page.
$response = $controller(request('/stockoverview'), connectFailure(), false, true, true);
$body = (string)$response->getBody();

check($response->getStatusCode() === 500, 'A view route answers 500, got ' . $response->getStatusCode());
check(strlen($body) > 0, 'The 500 carries a body');

// Whichever page answered, the driver's own words are not in it: a connect failure names
// the host, the port and the role, and this response is emitted without authentication.
check(!str_contains($body, 'SQLSTATE'), 'The 500 body does not quote the driver');
check(!str_contains($body, 'port 5432'), 'The 500 body does not name the deployment');

// An API route on the same failure. It needs no database of its own, but it is the other
// half of the handler and a regression that broke construction would break it too.
$response = $controller(request('/api/stock'), connectFailure(), false, true, true);
$payload = json_decode((string)$response->getBody(), true);

check($response->getStatusCode() === 500, 'An API route answers 500, got ' . $response->getStatusCode());
check(is_array($payload) && isset($payload['error_message']), 'The API 500 carries error_message');
check(!str_contains($payload['error_message'], 'SQLSTATE'), 'The API 500 does not quote the driver');

// A 404 goes through the same rendering path, so it is the case where the page that
// cannot be rendered is not an error page about the server at all.
$notFound = new HttpNotFoundException(request('/nope'));
$response = $controller(request('/nope'), $notFound, false, true, true);
$body = (string)$response->getBody();

check($response->getStatusCode() === 404, 'A missing route answers 404, got ' . $response->getStatusCode());
check(strlen($body) > 0, 'The 404 carries a body');

if ($mode === 'unreachable')
{
	// Every render above fell back, and each fallback says so exactly once. Silence here
	// is what the original defect had: a fatal error leaves the operator a stack trace
	// from php-fpm and nothing that names the request.
	$fallbacks = array_values(array_filter($logger->Records, function ($record)
	{
		return str_contains($record['message'], 'The error page could not be rendered');
	}));

	check(count($fallbacks) === 2, 'Both rendered answers record the fallback, got ' . count($fallbacks));

	// The fallback types itself, which the Blade path leaves to the emitter. A browser
	// handed markup with no Content-Type is the one thing worse than the plain page.
	check(str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/html'), 'The fallback is typed HTML, got "' . $response->getHeaderLine('Content-Type') . '"');

	check($fallbacks[0]['level'] === 'error', 'The fallback is recorded at error, got ' . $fallbacks[0]['level']);
	check(!str_contains($fallbacks[0]['message'], 'SQLSTATE'), 'The recorded fallback message is sanitised like every other');

	// The original exception is still recorded on its own, at the severity its status
	// asks for - the fallback is an addition to that record, not a replacement for it.
	// The fallback records name PDOException too (it is what rendering failed on), so
	// they are taken back out rather than counted twice.
	$reported = array_values(array_filter($logger->Records, function ($record)
	{
		return ($record['context']['exception'] ?? '') === 'PDOException'
			&& !str_contains($record['message'], 'The error page could not be rendered');
	}));

	check(count($reported) === 2, 'Both PDOExceptions are recorded on their own, got ' . count($reported));
	check($reported[0]['level'] === 'error', 'A 500 is recorded at error, got ' . $reported[0]['level']);
}
else
{
	// The database is there, so the real pages are what came back. Asserted on content
	// only these templates carry: errors/500.blade.php's report link, and the sidebar
	// RenderPage() reads out of userentities, which is the query the fallback path skips.
	$response = $controller(request('/stockoverview'), connectFailure(), false, true, true);
	$body = (string)$response->getBody();

	check($response->getStatusCode() === 500, 'A reachable database still answers 500, got ' . $response->getStatusCode());
	check(str_contains($body, 'github.com/datagen24/victual/issues'), 'The rendered 500 page is served, not the fallback');
	check(str_contains($body, '<nav'), 'The rendered 500 page carries the layout');

	$response = $controller(request('/nope'), new HttpNotFoundException(request('/nope')), false, true, true);
	$body = (string)$response->getBody();

	check($response->getStatusCode() === 404, 'A reachable database still answers 404, got ' . $response->getStatusCode());
	check(str_contains($body, '<nav'), 'The rendered 404 page is served, not the fallback');

	$fallbacks = array_filter($logger->Records, function ($record)
	{
		return str_contains($record['message'], 'The error page could not be rendered');
	});

	check(count($fallbacks) === 0, 'Nothing fell back while the database was reachable, got ' . count($fallbacks));
}

echo "ERROR PATH ($mode) PASSED ($checks assertions)\n";
