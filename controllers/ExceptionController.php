<?php

namespace Victual\Controllers;

use DI\Container;
use Victual\Controllers\Api\BaseApiController;
use Victual\Services\ApplicationService;
use Victual\Services\DatabaseService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * Slim custom error handler for the whole application: renders uncaught
 * exceptions either as a JSON API error payload (for /api/* routes) or as an
 * HTML error page (404/403/500) for regular view routes.
 */
class ExceptionController extends BaseApiController
{
	/**
	 * @param ResponseFactoryInterface $responseFactory Factory used to create the fresh error response
	 * @param LoggerInterface|null $logger Where uncaught exceptions are recorded. Slim's error
	 *                                     middleware only hands its own logger to its own handler,
	 *                                     so this one arrives here rather than through __invoke()
	 */
	public function __construct(Container $container, ResponseFactoryInterface $responseFactory, ?LoggerInterface $logger = null)
	{
		// false: this controller is constructed at bootstrap, in front of the error
		// middleware it is handed to, so a connection opened here has nothing above it to
		// catch its failure. See BaseController::__construct() and RenderErrorPage().
		parent::__construct($container, false);
		$this->ResponseFactory = $responseFactory;
		$this->Logger = $logger;
	}

	private $ResponseFactory;
	private ?LoggerInterface $Logger;

	/** @var LoggerInterface|null The logger of the invocation being handled - see LogException() */
	private ?LoggerInterface $ActiveLogger = null;

	/**
	 * Handles the given exception (Slim error handler signature).
	 *
	 * For API routes a JSON body with error_message (plus stack trace details when
	 * $displayErrorDetails is true) is returned; the HTTP status comes from the
	 * exception for HttpExceptions, otherwise 500. For view routes the matching
	 * error page (404, 403 or 500) is rendered.
	 *
	 * @return \Psr\Http\Message\ResponseInterface
	 */
	public function __invoke(ServerRequestInterface $request, Throwable $exception, bool $displayErrorDetails, bool $logErrors, bool $logErrorDetails, ?LoggerInterface $logger = null)
	{
		if (!defined('VICTUAL_LOCALE'))
		{
			define('VICTUAL_LOCALE', VICTUAL_DEFAULT_LOCALE);
		}

		$response = $this->ResponseFactory->createResponse();
		$isApiRoute = IsApiRoutePath($request->getUri()->getPath());

		// Kept for the whole invocation rather than passed down: RenderErrorPage() has a
		// failure of its own to record, and it happens below the point Slim hands the
		// logger in.
		$this->ActiveLogger = $logger ?? $this->Logger;

		$this->LogException($request, $exception, $logErrors, $logErrorDetails, $logger);

		if (!defined('VICTUAL_AUTHENTICATED'))
		{
			define('VICTUAL_AUTHENTICATED', false);
		}

		if ($isApiRoute)
		{
			$status = self::HttpStatusOf($exception);

			// The same rule GenericErrorResponse() applies to a caught exception, applied
			// to one that escaped: an uncaught PDOException is a server fault and stays a
			// 500, but the answer does not quote the statement that failed. See issue #48.
			$data = [
				'error_message' => self::WithoutDriverText($exception->getMessage())
			];

			if ($displayErrorDetails)
			{
				$data['error_details'] = [
					'stack_trace' => $exception->getTraceAsString(),
					'file' => $exception->getFile(),
					'line' => $exception->getLine()
				];
			}

			return $this->ApiResponse($response->withStatus($status)->withHeader('Content-Type', 'application/json'), $data);
		}

		if ($exception instanceof HttpNotFoundException)
		{
			return $this->RenderErrorPage($response, 404, 'errors/404', function () use ($exception)
			{
				return ['exception' => $exception];
			});
		}

		if ($exception instanceof HttpForbiddenException)
		{
			return $this->RenderErrorPage($response, 403, 'errors/403', function () use ($exception)
			{
				return ['exception' => $exception];
			});
		}

		$status = self::HttpStatusOf($exception);

		if ($status < 500)
		{
			// A 4xx that is neither of the two above - a 405 on a route whose verb changed,
			// a 400 raised before a controller was reached. It used to render the "a server
			// error occured" page with a 500, which is wrong in both halves: it is the
			// caller's request that could not be handled, and telling them the server broke
			// invites a retry that will fail the same way. Plan 15-C4.
			return $this->RenderErrorPage($response, $status, 'errors/4xx', function () use ($exception, $status)
			{
				return ['exception' => $exception, 'status' => $status];
			});
		}

		// The template variables are built inside RenderErrorPage() rather than here: the
		// system info reads the database (ApplicationService extends BaseService), so
		// assembling them is part of what can fail and has to be inside its try.
		return $this->RenderErrorPage($response, 500, 'errors/500', function () use ($exception)
		{
			return [
				'exception' => $exception,
				'systemInfo' => ApplicationService::GetInstance()->GetSystemInfo()
			];
		});
	}

	/**
	 * Renders an error page, falling back to a fixed one when rendering is itself
	 * impossible.
	 *
	 * Rendering an error page is not a cheap operation and never was: RenderPage() reads
	 * the sidebar userentities, Render() asks ApplicationService for the version and
	 * LocalizationService for the translations, and the 500 page asks for the system
	 * information on top - and every one of those is a service that opens the database.
	 * So the page that reports a failure needs the database to be working, which is
	 * exactly the assumption a database failure breaks. The exception thrown here escapes
	 * into Slim's error *middleware*, which has no handler above it, and PHP ends the
	 * request with a fatal: no status, no body, a connection the client sees only as its
	 * proxy's read timeout, and a worker gone.
	 *
	 * The connection is therefore obtained here rather than in the constructor, and
	 * everything that can reach it is inside one try. Throwable and not Exception: a null
	 * or a type mismatch on a half-built connection is an \Error, and an \Error escaping
	 * this method ends the request the same way a PDOException does.
	 *
	 * @param callable():array $data The template variables, built lazily for the same reason
	 */
	private function RenderErrorPage($response, int $status, string $viewName, callable $data)
	{
		try
		{
			if ($this->DB === null)
			{
				$this->DB = DatabaseService::GetInstance()->GetDbConnection();
			}

			return $this->RenderPage($response->withStatus($status), $viewName, $data());
		}
		catch (Throwable $renderFailure)
		{
			return $this->StaticErrorPage($status, $renderFailure);
		}
	}

	/**
	 * The error page for when the error page could not be rendered: fixed markup, written
	 * straight into the response, depending on nothing.
	 *
	 * No Blade, no localization, no database - each of those is a thing that has already
	 * failed by the time this is reached, and a fallback that can fail is not one. It is
	 * plain English rather than a translated string for the same reason: the translations
	 * come out of LocalizationService, which extends BaseService and opens a connection.
	 *
	 * The status the handler decided is kept rather than replaced with a 500. Routing
	 * found no route, or authorization refused, before anything touched the database, so
	 * that answer is still true and still the one a client should act on; only the page
	 * saying it is missing. What went wrong here goes to the log, not into the body -
	 * a connection failure names the host, the port and the role, and this response is
	 * emitted without authentication. Same rule as
	 * SchemaVersionMiddleware::DatabaseUnavailable().
	 */
	private function StaticErrorPage(int $status, Throwable $renderFailure)
	{
		if ($this->ActiveLogger !== null)
		{
			$this->ActiveLogger->error('The error page could not be rendered: ' . self::WithoutDriverText($renderFailure->getMessage()), [
				'status' => $status,
				'exception' => get_class($renderFailure),
				'file' => $renderFailure->getFile(),
				'line' => $renderFailure->getLine(),
				'stack_trace' => $renderFailure->getTraceAsString()
			]);
		}
		else
		{
			// Nothing was handed a logger - a construction path the application does not
			// use, but silence here would lose the only record of a fatal-shaped failure
			error_log('Victual: the error page could not be rendered: ' . $renderFailure->getMessage());
		}

		$headline = $status >= 500
			? 'A server error occured while processing your request'
			: 'This request could not be handled';

		$body = '<!DOCTYPE html>' . PHP_EOL
			. '<html lang="en">' . PHP_EOL
			. '<head><meta charset="utf-8"><title>Victual - error ' . $status . '</title></head>' . PHP_EOL
			. '<body>' . PHP_EOL
			. '<h1>' . $headline . '</h1>' . PHP_EOL
			. '<p>The error page itself could not be rendered, which usually means the database'
			. ' is unreachable. The server log holds what failed.</p>' . PHP_EOL
			. '</body>' . PHP_EOL
			. '</html>' . PHP_EOL;

		// A fresh response rather than the one the render was attempted on: PSR-7 clones
		// share their body stream, so whatever that had already written to it would
		// otherwise still be there, in front of this.
		$response = $this->ResponseFactory->createResponse($status)
			->withHeader('Content-Type', 'text/html; charset=utf-8');
		$response->getBody()->write($body);

		return $response;
	}

	/**
	 * The HTTP status an exception asks for, clamped to a range that is one.
	 *
	 * `HttpException::getCode()` was trusted as a status with nothing checking it, so an
	 * exception constructed with a code that is not one - which any `\Exception` subclass
	 * may carry, since getCode() is free-form - reached the response as a status the PSR-7
	 * implementation then rejected, turning a handled error into an unhandled one. Plan
	 * 15-C4.
	 */
	private static function HttpStatusOf(Throwable $exception): int
	{
		if (!($exception instanceof HttpException))
		{
			return 500;
		}

		$status = (int)$exception->getCode();

		return ($status >= 400 && $status <= 599) ? $status : 500;
	}

	/**
	 * Records the exception for the operator.
	 *
	 * What it deliberately does not carry is the request body. Bodies on this API contain
	 * product notes, user names and, on the user endpoints, passwords, and a log is a
	 * place they would sit in plain text for as long as the platform keeps records.
	 *
	 * A client error is logged at warning and a server fault at error, so that the volume
	 * a malformed filter can generate does not drown the faults worth reading. File, line
	 * and stack trace are attached only when the error middleware was asked for details -
	 * the same flag that used to be the only reason anything was recorded at all.
	 */
	private function LogException(ServerRequestInterface $request, Throwable $exception, bool $logErrors, bool $logErrorDetails, ?LoggerInterface $logger): void
	{
		$logger = $logger ?? $this->Logger;

		if (!$logErrors || $logger === null)
		{
			return;
		}

		$status = self::HttpStatusOf($exception);

		$context = [
			'method' => $request->getMethod(),
			'path' => $request->getUri()->getPath(),
			'status' => $status,
			'exception' => get_class($exception)
		];

		if ($logErrorDetails)
		{
			$context['file'] = $exception->getFile();
			$context['line'] = $exception->getLine();
			$context['stack_trace'] = $exception->getTraceAsString();
		}

		$message = self::WithoutDriverText($exception->getMessage());

		if ($status >= 500)
		{
			$logger->error($message, $context);
		}
		else
		{
			$logger->warning($message, $context);
		}
	}
}
