<?php

namespace Victual\Helpers;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Victual\Services\ApplicationService;

/**
 * Base class for external barcode lookup plugins (selected via the
 * STOCK_BARCODE_LOOKUP_PLUGIN setting, see plugins/DemoBarcodeLookupPlugin.php
 * for a documented example). Subclasses implement ExecuteLookup(); Lookup()
 * wraps it and validates the returned product data.
 */
abstract class BaseBarcodeLookupPlugin
{
	// That's a "self-referencing constant" and forces the child class to define it
	public const PLUGIN_NAME = self::PLUGIN_NAME;

	/**
	 * Outbound request timeout, in seconds, for every fetch that goes through Fetch() -
	 * a source's own request and the barcode picture download alike. Neither had one
	 * before issue #460; a source that hangs must not hang the request indefinitely.
	 */
	private const FETCH_TIMEOUT_SECONDS = 10.0;

	/**
	 * The User-Agent Fetch() sends when a caller does not supply its own - the barcode
	 * picture download's, unchanged from issue #459. A source that wants to identify
	 * itself distinctly (Open Food Facts does) passes its own via $options.
	 */
	private static function DefaultUserAgent(): string
	{
		return 'Victual/' . ApplicationService::GetInstance()->GetInstalledVersion()->Version . ' (https://github.com/datagen24/victual)';
	}

	/**
	 * @param array $locations All existing location rows
	 * @param array $quantityUnits All existing quantity unit rows
	 * @param array $userSettings All settings (name => value) of the current user
	 * @param callable(string): array<int, string>|null $hostResolver Passed straight through
	 *        to Fetch()'s OutboundHostPolicy. Production code and every documented plugin
	 *        (plugins/DemoBarcodeLookupPlugin.php) leave this null, which is a real DNS
	 *        lookup (OutboundHostPolicy::DefaultResolve()); a test supplies canned answers
	 *        here to exercise Fetch()'s host policy without touching the network. Adding
	 *        this as a fourth, defaulted parameter keeps every existing three-argument
	 *        construction - LoadExternalBarcodeLookupPlugin()'s and every custom plugin's -
	 *        unchanged.
	 */
	final public function __construct($locations, $quantityUnits, $userSettings, ?callable $hostResolver = null)
	{
		$this->Locations = $locations;
		$this->QuantityUnits = $quantityUnits;
		$this->UserSettings = $userSettings;
		$this->HostResolver = $hostResolver;
	}

	protected $Locations;
	protected $QuantityUnits;
	protected $UserSettings;

	/** @var callable(string): array<int, string>|null */
	private $HostResolver;

	/**
	 * Looks up the given barcode via the plugin implementation and validates the result.
	 *
	 * @param string $barcode The barcode to look up
	 * @return array|null The validated product data (associative array, see ExecuteLookup)
	 *                    or null when nothing was found for the barcode
	 * @throws \Exception When the plugin output is not an associative array, misses a
	 *                    required property or references invalid location/quantity unit ids
	 */
	final public function Lookup($barcode)
	{
		$pluginOutput = $this->ExecuteLookup($barcode);

		if ($pluginOutput === null)
		{
			return $pluginOutput;
		}

		// Plugin must return an associative array
		if (!is_array($pluginOutput))
		{
			throw new \Exception('Plugin output must be an associative array');
		}

		if (!IsAssociativeArray($pluginOutput))
		{
			// $pluginOutput is at least an indexed array here
			throw new \Exception('Plugin output must be an associative array');
		}

		// Check for minimum needed properties
		$minimunNeededProperties = [
			'name',
			'location_id',
			'qu_id_purchase',
			'qu_id_stock',
			'__qu_factor_purchase_to_stock',
			'__barcode'
		];

		foreach ($minimunNeededProperties as $prop)
		{
			if (!array_key_exists($prop, $pluginOutput))
			{
				throw new \Exception("Plugin output does not provide needed property $prop");
			}
		}

		// $pluginOutput contains all needed properties here

		// Of the required properties, the four ids/factor below are all value-checked;
		// name is not, so a source that found a product with no name would otherwise pass
		// straight through to the row services/StockService.php writes.
		if (empty($pluginOutput['name']))
		{
			throw new \Exception('Provided name is empty');
		}

		// Check if referenced entity ids are valid
		$locationId = $pluginOutput['location_id'];
		if (FindObjectInArrayByPropertyValue($this->Locations, 'id', $locationId) === null)
		{
			throw new \Exception("Provided location_id ($locationId) is not a valid location id");
		}

		$quIdPurchase = $pluginOutput['qu_id_purchase'];
		if (FindObjectInArrayByPropertyValue($this->QuantityUnits, 'id', $quIdPurchase) === null)
		{
			throw new \Exception("Provided qu_id_purchase ($quIdPurchase) is not a valid quantity unit id");
		}

		$quIdStock = $pluginOutput['qu_id_stock'];
		if (FindObjectInArrayByPropertyValue($this->QuantityUnits, 'id', $quIdStock) === null)
		{
			throw new \Exception("Provided qu_id_stock ($quIdStock) is not a valid quantity unit id");
		}

		// A divisor in every conversion StockService::ExternalBarcodeLookup() makes with
		// it, so zero, blank, non-numeric and negative are all refused - not just the
		// first two, which is what empty() alone catches.
		$quFactor = $pluginOutput['__qu_factor_purchase_to_stock'];
		if (!is_numeric($quFactor) || (float)$quFactor <= 0)
		{
			throw new \Exception('Provided __qu_factor_purchase_to_stock must be a number greater than zero');
		}

		// __barcode is not just an identifier: services/StockService.php builds the stored
		// picture's file name from it directly, so every source has to agree on what a safe
		// file name component looks like, checked once here rather than by each source.
		// Issue #243 first refused a directory separator, a null byte and a leading dot;
		// issue #459 widens that to sweep finding S14's requested allow-list
		// (docs/security-sweep.md, S14 row), [0-9A-Za-z_-], which every real GTIN/EAN/UPC
		// satisfies and which also subsumes the earlier, narrower checks. The empty string
		// stays accepted - DemoBarcodeLookupPlugin::ExecuteLookup() documents an empty scan
		// as a hit stored under an empty barcode, and that behaviour predates and is outside
		// this issue's scope.
		$barcode = $pluginOutput['__barcode'];
		// \A and \z, not ^ and $: $ also matches before a final newline, so "123\n" would pass.
		if (!is_string($barcode) || !preg_match('/\A[0-9A-Za-z_-]*\z/', $barcode))
		{
			throw new \Exception('Provided __barcode is not a valid file name component');
		}

		return $pluginOutput;
	}

	/**
	 * The one fetch seam every outbound GET this tree makes on a source's or a source's
	 * product's say-so goes through: a barcode source's own request
	 * (OpenFoodFactsBarcodeLookupPlugin::ExecuteLookup(), and any future source) and the
	 * barcode picture download (services/StockService.php::ExternalBarcodeLookup(), which
	 * holds a plugin instance already and calls this method on it rather than building its
	 * own client). Issue #460 introduces this method to stop that duplication; issue #459
	 * is where the security behaviour it wraps was written and proven, unchanged here:
	 *
	 *  - $url's scheme and its resolved host are checked by OutboundHostPolicy before
	 *    anything is requested, refusing loopback/private/link-local/CGNAT/reserved
	 *    addresses and their IPv6 equivalents (helpers/OutboundHostPolicy.php).
	 *  - The request is pinned to the address OutboundHostPolicy validated
	 *    (CURLOPT_RESOLVE), so a second, different DNS answer at request time cannot be
	 *    substituted for the one just checked (DNS rebinding).
	 *  - Redirects are not followed (allow_redirects: false) and the environment's
	 *    HTTP_PROXY/HTTPS_PROXY/NO_PROXY are not honoured (proxy: '') - either one would
	 *    let something other than the validated, pinned address decide where the request
	 *    actually goes.
	 *  - HTTP errors never throw (http_errors: false); the caller reads the status itself.
	 *    Open Food Facts' 404-is-a-miss handling and the picture download's 2xx check both
	 *    depend on getting a response object back rather than a caught exception.
	 *
	 * @param string $url An http(s) URL. A scheme this seam does not allow, or a host it
	 *                    refuses, surfaces as OutboundHostRefusedException - callers that
	 *                    already treat any \Exception from a fetch as a miss (both current
	 *                    callers do) need no change to keep doing that; OpenFoodFacts and
	 *                    other future sources.
	 * @param array $options Guzzle request options merged on top of the ones this method
	 *                       sets - most usefully 'headers' => ['User-Agent' => '...'] for a
	 *                       source that wants to identify itself distinctly, as Open Food
	 *                       Facts does. Values here are layered under this method's own
	 *                       array_merge, so a caller cannot use this to defeat
	 *                       allow_redirects, proxy or curl (a caller-supplied 'curl' array
	 *                       is merged with, not replacing, the CURLOPT_RESOLVE pin).
	 * @return ResponseInterface
	 * @throws OutboundHostRefusedException When $url's scheme or resolved host is refused
	 * @throws \GuzzleHttp\Exception\GuzzleException On a connection-level failure
	 */
	final public function Fetch(string $url, array $options = []): ResponseInterface
	{
		$hostPolicy = new OutboundHostPolicy($this->HostResolver);
		$validatedAddresses = $hostPolicy->AssertAllowed($url);

		$urlParts = parse_url($url);
		$scheme = strtolower($urlParts['scheme']);
		$host = trim($urlParts['host'], '[]');
		$port = $urlParts['port'] ?? ($scheme === 'https' ? 443 : 80);
		$pinnedAddress = $validatedAddresses[0];
		$resolveEntry = $host . ':' . $port . ':' . (str_contains($pinnedAddress, ':') ? "[$pinnedAddress]" : $pinnedAddress);

		// DefaultUserAgent() is only computed when nothing in $options already supplies one -
		// it resolves the installed version through ApplicationService, which needs a
		// database connection a caller with no other reason to have one (a lightweight test
		// process, most notably) may not have made. A caller that names its own User-Agent,
		// as OpenFoodFactsBarcodeLookupPlugin does, never touches that path.
		$headers = $options['headers'] ?? [];
		if (!array_key_exists('User-Agent', $headers))
		{
			$headers['User-Agent'] = self::DefaultUserAgent();
		}
		unset($options['headers']);

		$requestOptions = array_replace_recursive(
			[
				'http_errors' => false,
				'allow_redirects' => false,
				'proxy' => '',
				'timeout' => self::FETCH_TIMEOUT_SECONDS,
				'curl' => [CURLOPT_RESOLVE => [$resolveEntry]],
			],
			$options,
			['headers' => $headers]
		);

		// array_replace_recursive would append a second CURLOPT_RESOLVE array entry from
		// $options rather than replacing this method's own pin, since both are numerically
		// keyed; overwritten explicitly so the pin this call just validated is always the
		// one actually used, whatever $options[curl] contained.
		$requestOptions['curl'][CURLOPT_RESOLVE] = [$resolveEntry];

		return (new Client())->request('GET', $url, $requestOptions);
	}

	/**
	 * Performs the actual lookup; to be implemented by the plugin.
	 *
	 * @param string $barcode The barcode to look up
	 * @return array|null Associative array of the product model with at least the keys
	 *                    name, location_id, qu_id_purchase, qu_id_stock,
	 *                    __qu_factor_purchase_to_stock and __barcode (optionally
	 *                    __image_url), or null when nothing was found
	 */
	abstract protected function ExecuteLookup($barcode);
}
