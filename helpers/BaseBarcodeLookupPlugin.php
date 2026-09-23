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

		$quFactor = $pluginOutput['__qu_factor_purchase_to_stock'];
		if (empty($quFactor) || !is_numeric($quFactor))
		{
			throw new \Exception('Provided __qu_factor_purchase_to_stock is empty or not a number');
		}

		// __barcode is not just an identifier: services/StockService.php builds the stored
		// picture's file name from it directly, so every source has to agree on what a safe
		// file name component looks like, checked once here rather than by each source.
		$barcode = $pluginOutput['__barcode'];
		if (!is_string($barcode) || str_contains($barcode, '/') || str_contains($barcode, '\\') || str_contains($barcode, "\0") || str_starts_with($barcode, '.'))
		{
			throw new \Exception('Provided __barcode is not a valid file name component');
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
