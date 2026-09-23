<?php

namespace Victual\Helpers;

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
	 * @param array $locations All existing location rows
	 * @param array $quantityUnits All existing quantity unit rows
	 * @param array $userSettings All settings (name => value) of the current user
	 */
	final public function __construct($locations, $quantityUnits, $userSettings)
	{
		$this->Locations = $locations;
		$this->QuantityUnits = $quantityUnits;
		$this->UserSettings = $userSettings;
	}

	protected $Locations;
	protected $QuantityUnits;
	protected $UserSettings;

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

		// A divisor in every conversion the caller makes with it
		// (services/StockService.php:1057), so zero, blank, non-numeric and negative are
		// all refused - not just the first two, which is what empty() alone catches.
		$quFactor = $pluginOutput['__qu_factor_purchase_to_stock'];
		if (!is_numeric($quFactor) || (float)$quFactor <= 0)
		{
			throw new \Exception('Provided __qu_factor_purchase_to_stock must be a number greater than zero');
		}

		return $pluginOutput;
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
