<?php

use Victual\Helpers\BaseBarcodeLookupPlugin;

/*
	To use this plugin, configure it in data/config.php like this:
	Setting('STOCK_BARCODE_LOOKUP_PLUGIN', 'OpenFoodFactsBarcodeLookupPlugin');
*/

/**
 * External barcode lookup plugin (see BaseBarcodeLookupPlugin) which queries the
 * public Open Food Facts product database (https://world.openfoodfacts.org) for
 * the given barcode/EAN.
 */
class OpenFoodFactsBarcodeLookupPlugin extends BaseBarcodeLookupPlugin
{
	public const PLUGIN_NAME = 'Open Food Facts';

	/**
	 * Queries the Open Food Facts v2 product API for the barcode (digits only),
	 * requesting the product name (localized to VICTUAL_LOCALE when available) and
	 * image URL.
	 *
	 * @param string $barcode The barcode to look up
	 * @return array|null Associative array with name, location_id, qu_id_purchase,
	 *                     qu_id_stock, __qu_factor_purchase_to_stock (always 1),
	 *                     __barcode and __image_url (validated/completed by
	 *                     BaseBarcodeLookupPlugin::Lookup()), or null when the API
	 *                     answers anything but 2xx, the body is not a decision to map (not JSON, not a
	 *                     hit, or a hit with no product object), or nothing was scanned
	 */
	protected function ExecuteLookup($barcode)
	{
		if ($barcode === '')
		{
			// Nothing to ask about - every other case below is a real API round trip, and
			// an empty scan is not one of them.
			return null;
		}

		$productNameFieldLocalized = 'product_name_' . substr(VICTUAL_LOCALE, 0, 2);

		// Fetch() (issue #460) applies the same host policy, DNS-rebinding pin, redirect
		// and proxy refusal, and timeout that services/StockService.php's picture download
		// does (issue #459) - this compiled-in host resolves to Open Food Facts' own public
		// addresses, so the policy passes as it always would for a legitimate destination.
		$response = $this->Fetch(
			'https://world.openfoodfacts.org/api/v2/product/' . preg_replace('/[^0-9]/', '', $barcode) . '?fields=product_name,image_url,' . $productNameFieldLocalized,
			['headers' => ['User-Agent' => 'VictualOpenFoodFactsBarcodeLookupPlugin/1.0 (https://github.com/datagen24/victual)']]
		);
		$statusCode = $response->getStatusCode();

		// Fetch() sets http_errors: false, so a connection-level failure (DNS, TCP, TLS) is
		// still the only thing that throws here - an HTTP error status comes back as an
		// ordinary response, handled by the status check below.

		if ($statusCode < 200 || $statusCode >= 300)
		{
			// A 404 is the API's "nothing found for the given barcode". Any other status
			// outside 2xx is an error page whose body is no answer either, whatever it says
			return null;
		}

		$data = json_decode(mb_convert_encoding($response->getBody(), 'UTF-8', 'UTF-8'));

		// A body that is not a JSON object (a CDN error page, say), a status other than 1,
		// or a status of 1 with no product object to map are all misses - checked here,
		// once, rather than let each read below reach one of them through a PHP notice on
		// its way to the same null.
		if (!is_object($data) || !isset($data->status) || $data->status != 1 || !isset($data->product) || !is_object($data->product))
		{
			return null;
		}
		else
		{
			$imageUrl = '';
			if (isset($data->product->image_url) && !empty($data->product->image_url))
			{
				$imageUrl = $data->product->image_url;
			}

			// Take the preset user setting or otherwise simply the first existing location
			$locationId = $this->Locations[0]->id;
			if ($this->UserSettings['product_presets_location_id'] != -1)
			{
				$locationId = $this->UserSettings['product_presets_location_id'];
			}

			// Take the preset user setting or otherwise simply the first existing quantity unit
			$quId = $this->QuantityUnits[0]->id;
			if ($this->UserSettings['product_presets_qu_id'] != -1)
			{
				$quId = $this->UserSettings['product_presets_qu_id'];
			}

			// Use the localized product name, if provided
			$name = $data->product->product_name;
			if (isset($data->product->$productNameFieldLocalized) && !empty($data->product->$productNameFieldLocalized))
			{
				$name = $data->product->$productNameFieldLocalized;
			}

			return [
				'name' => $name,
				'location_id' => $locationId,
				'qu_id_purchase' => $quId,
				'qu_id_stock' => $quId,
				'__qu_factor_purchase_to_stock' => 1,
				'__barcode' => $barcode,
				'__image_url' => $imageUrl
			];
		}
	}
}
