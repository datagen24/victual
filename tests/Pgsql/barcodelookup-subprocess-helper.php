<?php

// One Open Food Facts lookup, run against a canned payload instead of the network, for
// tests/Pgsql/BarcodeLookupTest.php.
//
//   php barcodelookup-subprocess-helper.php <base64 of a JSON spec>
//
// Why a separate process. OpenFoodFactsBarcodeLookupPlugin::ExecuteLookup() calls
// $this->Fetch() (issue #460's shared seam, helpers/BaseBarcodeLookupPlugin.php), which
// builds its own GuzzleHttp\Client against a URL compiled into the plugin, so there is no
// argument and no return value a caller in the same process could substitute - the seam is
// reached, but the transport it uses still is not. This file declares GuzzleHttp\Client
// before packages/autoload.php is required, so `new Client(...)` inside Fetch() binds to
// the stand-in and the request never leaves the process. Doing that in the PHPUnit process
// would replace Guzzle for every other test in the run; here it dies with the process. The
// network is the one genuine external boundary in this plugin - the parsing, the mapping
// and the null-on-miss decisions all run for real.
//
// Fetch() also resolves the request's host through OutboundHostPolicy (issue #459), which
// defaults to a real DNS lookup. host_resolver_addresses (below) is passed as the fourth,
// injectable BaseBarcodeLookupPlugin constructor argument precisely so that stays offline
// too, except in the one test that asks for real DNS on purpose (see
// BarcodeLookupTest::testOpenFoodFactsCompiledHostResolvesToPublicAddressesForReal).
//
// Spec keys: barcode, locale, status, body, locations, quantity_units, user_settings,
// host_resolver_addresses (array of IPs the compiled-in host "resolves" to; omit or set
// null for a real DNS lookup instead).
// Prints one JSON object: outcome ("hit" | "miss" | "exception"), product, message,
// request_uri, request_headers, request_options (allow_redirects, proxy, timeout, and the
// curl CURLOPT_RESOLVE entry - the same seam options
// tests/Pgsql/barcodelookup-picture-subprocess-helper.php captures for the picture
// download, which is how both callers going through Fetch() is proven), client_config,
// diagnostics (every PHP notice/warning the plugin raised, which is how the test pins the
// payload shapes the plugin does not guard).

namespace GuzzleHttp
{
	class Client
	{
		public static int $Status = 200;
		public static string $Body = '';
		public static ?string $LastUri = null;
		public static array $LastOptions = [];
		public static array $LastConfig = [];

		public function __construct(array $config = [])
		{
			self::$LastConfig = $config;
		}

		public function request(string $method, $uri = '', array $options = [])
		{
			self::$LastUri = $method . ' ' . (string)$uri;
			self::$LastOptions = $options;

			return new \GuzzleHttp\Psr7\Response(self::$Status, ['Content-Type' => 'application/json'], self::$Body);
		}
	}
}

namespace
{
	$spec = json_decode(base64_decode($argv[1]), true);

	\GuzzleHttp\Client::$Status = (int)$spec['status'];
	\GuzzleHttp\Client::$Body = (string)$spec['body'];

	define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
	define('VICTUAL_LOCALE', $spec['locale']);

	require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
	require_once VICTUAL_ROOT_PATH . '/plugins/OpenFoodFactsBarcodeLookupPlugin.php';

	// Collected rather than printed: a notice on stdout would corrupt the JSON, and the
	// notices are themselves an assertion subject - see the payload-shape cases in
	// BarcodeLookupTest.
	$diagnostics = [];
	set_error_handler(function ($severity, $message) use (&$diagnostics)
	{
		$diagnostics[] = $message;

		return true;
	});

	$toObjects = fn (array $rows) => array_map(fn ($row) => (object)$row, $rows);

	$hostResolver = array_key_exists('host_resolver_addresses', $spec) && $spec['host_resolver_addresses'] !== null
		? fn (string $host) => $spec['host_resolver_addresses']
		: null;

	$plugin = new OpenFoodFactsBarcodeLookupPlugin(
		$toObjects($spec['locations']),
		$toObjects($spec['quantity_units']),
		$spec['user_settings'],
		$hostResolver
	);

	$result = ['outcome' => 'miss', 'product' => null, 'message' => null];

	try
	{
		$product = $plugin->Lookup($spec['barcode']);
		$result = $product === null
			? ['outcome' => 'miss', 'product' => null, 'message' => null]
			: ['outcome' => 'hit', 'product' => $product, 'message' => null];
	}
	catch (\Throwable $ex)
	{
		$result = ['outcome' => 'exception', 'product' => null, 'message' => $ex->getMessage()];
	}

	restore_error_handler();

	echo json_encode($result + [
		'request_uri' => \GuzzleHttp\Client::$LastUri,
		'request_headers' => \GuzzleHttp\Client::$LastOptions['headers'] ?? [],
		'request_options' => [
			'allow_redirects' => \GuzzleHttp\Client::$LastOptions['allow_redirects'] ?? null,
			'proxy' => \GuzzleHttp\Client::$LastOptions['proxy'] ?? null,
			'timeout' => \GuzzleHttp\Client::$LastOptions['timeout'] ?? null,
			'http_errors' => \GuzzleHttp\Client::$LastOptions['http_errors'] ?? null,
			'curl' => \GuzzleHttp\Client::$LastOptions['curl'] ?? null,
		],
		'client_config' => \GuzzleHttp\Client::$LastConfig,
		'diagnostics' => $diagnostics
	]);
}
