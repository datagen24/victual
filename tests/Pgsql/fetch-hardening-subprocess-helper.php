<?php

// Calls BaseBarcodeLookupPlugin::Fetch() (issue #460) directly, with a caller-supplied
// $options that tries to defeat the policy it enforces, for
// tests/Pgsql/BarcodeLookupTest.php::testFetchIgnoresACallerSuppliedAllowRedirectsProxyAndCurlOverride.
//
//   php fetch-hardening-subprocess-helper.php <base64 of a JSON spec>
//
// Fetch() is public, and OpenFoodFactsBarcodeLookupPlugin is a ready-made concrete
// subclass, so this calls $plugin->Fetch($url, $options) directly rather than going
// through Lookup()/ExecuteLookup() - what is under test is Fetch() itself, not anything
// OpenFoodFactsBarcodeLookupPlugin does with it.
//
// GuzzleHttp\Client is declared before packages/autoload.php runs (the same substitution
// barcodelookup-subprocess-helper.php uses), so the request never leaves the process; a
// canned resolver answer (host_resolver_addresses) stands in for a real DNS lookup, so
// this makes no real DNS lookup either.
//
// Spec: {url, options, host_resolver_addresses, on_headers_content_lengths}. Prints
// {request_options: {allow_redirects, proxy, timeout, connect_timeout, curl},
// on_headers_results} -
// the options a caller must not be able to override, plus, for each entry in
// on_headers_content_lengths, whether invoking the recorded on_headers callback with a
// response carrying that Content-Length throws ("outcome": "threw"|"passed").

namespace GuzzleHttp
{
	class Client
	{
		public static array $LastOptions = [];

		public function __construct(array $config = [])
		{
		}

		public function request(string $method, $uri = '', array $options = [])
		{
			self::$LastOptions = $options;

			return new \GuzzleHttp\Psr7\Response(200, [], '');
		}
	}
}

namespace
{
	define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
	define('VICTUAL_LOCALE', 'en');

	require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
	require_once VICTUAL_ROOT_PATH . '/plugins/OpenFoodFactsBarcodeLookupPlugin.php';

	$spec = json_decode(base64_decode($argv[1]), true);

	$plugin = new OpenFoodFactsBarcodeLookupPlugin(
		[(object)['id' => 1, 'name' => 'Fixture location']],
		[(object)['id' => 1, 'name' => 'Fixture unit']],
		['product_presets_location_id' => -1, 'product_presets_qu_id' => -1],
		fn (string $host) => $spec['host_resolver_addresses']
	);

	$plugin->Fetch($spec['url'], $spec['options']);

	$onHeaders = \GuzzleHttp\Client::$LastOptions['on_headers'] ?? null;
	$onHeadersResults = [];

	foreach ($spec['on_headers_content_lengths'] ?? [] as $contentLength)
	{
		$response = new \GuzzleHttp\Psr7\Response(200, ['Content-Length' => (string)$contentLength], '');

		try
		{
			$onHeaders($response);
			$onHeadersResults[(string)$contentLength] = 'passed';
		}
		catch (\Throwable $ex)
		{
			$onHeadersResults[(string)$contentLength] = 'threw';
		}
	}

	echo json_encode([
		'request_options' => [
			'allow_redirects' => \GuzzleHttp\Client::$LastOptions['allow_redirects'] ?? null,
			'proxy' => \GuzzleHttp\Client::$LastOptions['proxy'] ?? null,
			'timeout' => \GuzzleHttp\Client::$LastOptions['timeout'] ?? null,
			'connect_timeout' => \GuzzleHttp\Client::$LastOptions['connect_timeout'] ?? null,
			'on_headers_is_callable' => is_callable($onHeaders),
			'curl' => \GuzzleHttp\Client::$LastOptions['curl'] ?? null,
			// Present only if Fetch() itself ever started setting one; a caller-supplied
			// 'verify' must never reach here at all now that $options is an allow-list of
			// 'headers' alone.
			'verify' => \GuzzleHttp\Client::$LastOptions['verify'] ?? null,
		],
		'on_headers_results' => $onHeadersResults,
	]);
}
