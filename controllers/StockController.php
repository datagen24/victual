<?php

namespace Victual\Controllers;

use DI\Container;
use Victual\Helpers\Grocycode;
use Victual\Services\LocalizationService;
use Victual\Services\RecipesService;
use Victual\Services\StockService;
use Victual\Services\UserfieldsService;
use Victual\Services\UsersService;
use Victual\Controllers\Users\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Slim route controller for all stock related views: stock overview and
 * entries, purchase/consume/transfer/inventory pages, the stock journal,
 * the shopping list and the master data (products, quantity units, locations,
 * stores, product groups, barcodes). Stock business logic is delegated to StockService.
 */
class StockController extends BaseController
{
	use GrocycodeTrait;

	/**
	 * Additionally exposes the configured external barcode lookup plugin name
	 * to all views rendered by this controller (empty string when unavailable).
	 */
	public function __construct(Container $container)
	{
		parent::__construct($container);

		try
		{
			$externalBarcodeLookupPluginName = StockService::GetInstance()->GetExternalBarcodeLookupPluginName();
		}
		catch (\Exception)
		{
			$externalBarcodeLookupPluginName = '';
		}
		finally
		{
			$this->View->set('ExternalBarcodeLookupPluginName', $externalBarcodeLookupPluginName);
		}
	}

	/**
	 * Serves the consume view (route GET /consume); only products currently in stock are offered.
	 */
	public function Consume(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		return $this->RenderPage($response, 'consume', [
			'products' => $this->DB->products()->where('active = 1')->where('id IN (SELECT product_id from stock_current WHERE amount_aggregated > 0)')->orderBy('name'),
			'barcodes' => $this->DB->product_barcodes_comma_separated(),
			'recipes' => $this->DB->recipes()->where('type', RecipesService::RECIPE_TYPE_NORMAL)->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->DB->locations()->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved()
		]);
	}

	/**
	 * Serves the inventory (stocktaking) view (route GET /inventory).
	 */
	public function Inventory(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		return $this->RenderPage($response, 'inventory', [
			'products' => $this->DB->products()->where('active = 1 AND no_own_stock = 0')->orderBy('name', 'COLLATE NOCASE'),
			'barcodes' => $this->DB->product_barcodes_comma_separated(),
			'shoppinglocations' => $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
			'userfields' => UserfieldsService::GetInstance()->GetFields('stock')
		]);
	}

	/**
	 * Serves the stock journal view (route GET /stockjournal).
	 *
	 * Optional query parameters: months (int, how far back to list; default 6)
	 * and product (int, filter by product id).
	 */
	public function Journal(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		// Default 6 months
		$months = 6;
		if (isset($request->getQueryParams()['months']) && filter_var($request->getQueryParams()['months'], FILTER_VALIDATE_INT) !== false)
		{
			$months = intval($request->getQueryParams()['months']);
		}

		// The cut-off date is computed here and bound as a parameter instead of being
		// expressed in SQL: SQLite's DATE(x, '-N months') has no PostgreSQL equivalent,
		// so date arithmetic must not leak into the query (see DatabaseDialect)
		$stockLog = $this->DB->uihelper_stock_journal()->where('row_created_timestamp > :1', date('Y-m-d', strtotime('-' . $months . ' months')));

		if (isset($request->getQueryParams()['product']) && filter_var($request->getQueryParams()['product'], FILTER_VALIDATE_INT) !== false)
		{
			$stockLog = $stockLog->where('product_id', intval($request->getQueryParams()['product']));
		}

		$usersService = UsersService::GetInstance();

		return $this->RenderPage($response, 'stockjournal', [
			'stockLog' => $stockLog->orderBy('row_created_timestamp', 'DESC'),
			'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->DB->locations()->orderBy('name', 'COLLATE NOCASE'),
			'users' => $usersService->GetUsersAsDto(),
			'transactionTypes' => GetClassConstants('\Victual\Services\StockService', 'TRANSACTION_TYPE_'),
			'userfieldsStock' => UserfieldsService::GetInstance()->GetFields('stock'),
			'userfieldValuesStock' => UserfieldsService::GetInstance()->GetAllValues('stock')
		]);
	}

	/**
	 * Serves the printable location content sheet view (route GET /locationcontentsheet).
	 *
	 * Query parameter include_out_of_stock (presence only) also lists products without stock.
	 */
	public function LocationContentSheet(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		return $this->RenderPage($response, 'locationcontentsheet', [
			'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->DB->locations()->orderBy('name', 'COLLATE NOCASE'),
			'currentStockLocationContent' => StockService::GetInstance()->GetCurrentStockLocationContent(isset($request->getQueryParams()['include_out_of_stock']))
		]);
	}

	/**
	 * Serves the location create/edit form (route GET /location/{locationId}).
	 *
	 * @param array $args Route arguments; locationId is either a location id or the literal 'new' for create mode
	 */
	public function LocationEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		if ($args['locationId'] == 'new')
		{
			return $this->RenderPage($response, 'locationform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('locations')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'locationform', [
				'location' => $this->DB->locations($args['locationId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('locations')
			]);
		}
	}

	/**
	 * Serves the location master data list view (route GET /locations).
	 *
	 * Query parameter include_disabled (presence only) also lists inactive locations.
	 */
	public function LocationsList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$locations = $this->DB->locations()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$locations = $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->RenderPage($response, 'locations', [
			'locations' => $locations,
			'userfields' => UserfieldsService::GetInstance()->GetFields('locations'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('locations')
		]);
	}

	/**
	 * Serves the stock overview view (route GET /stockoverview); lists products in
	 * stock or below their min stock amount (or all products, depending on the
	 * user's stock_overview_show_all_out_of_stock_products setting).
	 */
	public function Overview(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$usersService = UsersService::GetInstance();
		$userSettings = $usersService->GetUserSettings(VICTUAL_USER_ID);
		$nextXDays = $userSettings['stock_due_soon_days'];

		$where = 'is_in_stock_or_below_min_stock = 1';
		if (boolval($userSettings['stock_overview_show_all_out_of_stock_products']))
		{
			$where = '1=1';
		}

		return $this->RenderPage($response, 'stockoverview', [
			'currentStock' => $this->DB->uihelper_stock_current_overview()->where($where),
			'locations' => $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'currentStockLocations' => StockService::GetInstance()->GetCurrentStockLocations(),
			'nextXDays' => $nextXDays,
			'productGroups' => $this->DB->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('products'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('products')
		]);
	}

	/**
	 * Serves the product barcode create/edit form (route GET /productbarcodes/{productBarcodeId}).
	 *
	 * Query parameter product (id) preselects the associated product.
	 *
	 * @param array $args Route arguments; productBarcodeId is either a barcode id or the literal 'new' for create mode
	 */
	public function ProductBarcodesEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$product = null;
		if (isset($request->getQueryParams()['product']))
		{
			$product = $this->DB->products($request->getQueryParams()['product']);
		}

		if ($args['productBarcodeId'] == 'new')
		{
			return $this->RenderPage($response, 'productbarcodeform', [
				'mode' => 'create',
				'barcodes' => $this->DB->product_barcodes()->orderBy('barcode'),
				'product' => $product,
				'shoppinglocations' => $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
				'userfields' => UserfieldsService::GetInstance()->GetFields('product_barcodes')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'productbarcodeform', [
				'mode' => 'edit',
				'barcode' => $this->DB->product_barcodes($args['productBarcodeId']),
				'product' => $product,
				'shoppinglocations' => $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
				'userfields' => UserfieldsService::GetInstance()->GetFields('product_barcodes')
			]);
		}
	}

	/**
	 * Serves the product create/edit form (route GET /product/{productId}).
	 *
	 * In edit mode the selectable quantity units are restricted to units
	 * reachable through conversions once the product has stock log entries.
	 *
	 * @param array $args Route arguments; productId is either a product id or the literal 'new' for create mode
	 */
	public function ProductEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		if ($args['productId'] == 'new')
		{
			$quantityunits = $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');

			return $this->RenderPage($response, 'productform', [
				'locations' => $this->DB->locations()->where('active = 1')->orderBy('name'),
				'barcodes' => $this->DB->product_barcodes()->orderBy('barcode'),
				'quantityunitsAll' => $quantityunits,
				'quantityunitsReferenced' => $quantityunits,
				'shoppinglocations' => $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'productgroups' => $this->DB->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'userfields' => UserfieldsService::GetInstance()->GetFields('products'),
				'products' => $this->DB->products()->where('parent_product_id IS NULL and active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'isSubProductOfOthers' => false,
				'mode' => 'create'
			]);
		}
		else
		{
			$product = $this->DB->products($args['productId']);

			return $this->RenderPage($response, 'productform', [
				'product' => $product,
				'locations' => $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'barcodes' => $this->DB->product_barcodes()->orderBy('barcode'),
				'quantityunitsAll' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'quantityunitsReferenced' => $this->DB->quantity_units()->where('id IN (SELECT to_qu_id FROM cache__quantity_unit_conversions_resolved WHERE product_id = :1) OR NOT EXISTS(SELECT 1 FROM stock_log WHERE product_id = :1)', $product->id)->orderBy('name', 'COLLATE NOCASE'),
				'shoppinglocations' => $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'productgroups' => $this->DB->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'userfields' => UserfieldsService::GetInstance()->GetFields('products'),
				'products' => $this->DB->products()->where('id != :1 AND parent_product_id IS NULL and active = 1', $product->id)->orderBy('name', 'COLLATE NOCASE'),
				'isSubProductOfOthers' => $this->DB->products()->where('parent_product_id = :1', $product->id)->count() !== 0,
				'mode' => 'edit',
				'quConversions' => $this->DB->quantity_unit_conversions()->where('product_id', $product->id),
				'productBarcodeUserfields' => UserfieldsService::GetInstance()->GetFields('product_barcodes'),
				'productBarcodeUserfieldValues' => UserfieldsService::GetInstance()->GetAllValues('product_barcodes')
			]);
		}
	}

	/**
	 * Serves the Grocycode barcode PNG for a product (route GET /product/{productId}/grocycode).
	 */
	public function ProductGrocycodeImage(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$gc = new Grocycode(Grocycode::PRODUCT, $args['productId']);
		return $this->ServeGrocycodeImage($request, $response, $gc);
	}

	/**
	 * Serves the product group create/edit form (route GET /productgroup/{productGroupId}).
	 *
	 * @param array $args Route arguments; productGroupId is either a product group id or the literal 'new' for create mode
	 */
	public function ProductGroupEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		if ($args['productGroupId'] == 'new')
		{
			return $this->RenderPage($response, 'productgroupform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('product_groups')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'productgroupform', [
				'group' => $this->DB->product_groups($args['productGroupId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('product_groups')
			]);
		}
	}

	/**
	 * Serves the product group master data list view (route GET /productgroups).
	 *
	 * Query parameter include_disabled (presence only) also lists inactive product groups.
	 */
	public function ProductGroupsList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$productGroups = $this->DB->product_groups()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$productGroups = $this->DB->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->RenderPage($response, 'productgroups', [
			'productGroups' => $productGroups,
			'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('product_groups'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('product_groups')
		]);
	}

	/**
	 * Serves the product master data list view (route GET /products).
	 *
	 * Optional query parameters: include_disabled (presence only, also lists
	 * inactive products) and filter ('only_in_stock' or 'only_out_of_stock').
	 */
	public function ProductsList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$products = $this->DB->products();
		if (!isset($request->getQueryParams()['include_disabled']))
		{
			$products = $products->where('active = 1');
		}

		if (isset($request->getQueryParams()['filter']))
		{
			if ($request->getQueryParams()['filter'] == 'only_in_stock')
			{
				$products = $products->where('id IN (SELECT product_id from stock_current WHERE amount_aggregated > 0)');
			}
			elseif ($request->getQueryParams()['filter'] == 'only_out_of_stock')
			{
				$products = $products->where('id NOT IN (SELECT product_id from stock_current WHERE amount_aggregated > 0)');
			}
		}

		$products = $products->orderBy('name', 'COLLATE NOCASE');

		return $this->RenderPage($response, 'products', [
			'products' => $products,
			'locations' => $this->DB->locations()->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'productGroups' => $this->DB->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'shoppingLocations' => $this->DB->shopping_locations()->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('products'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('products')
		]);
	}

	/**
	 * Serves the purchase view (route GET /purchase).
	 */
	public function Purchase(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		return $this->RenderPage($response, 'purchase', [
			'products' => $this->DB->products()->where('active = 1 AND no_own_stock = 0')->orderBy('name', 'COLLATE NOCASE'),
			'barcodes' => $this->DB->product_barcodes_comma_separated(),
			'shoppinglocations' => $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
			'userfields' => UserfieldsService::GetInstance()->GetFields('stock')
		]);
	}

	/**
	 * Serves the quantity unit conversion create/edit form (route GET /quantityunitconversion/{quConversionId}).
	 *
	 * Optional query parameters: product (id, associates the conversion with a
	 * product) and qu-unit (id, preselects the "from" quantity unit).
	 *
	 * @param array $args Route arguments; quConversionId is either a conversion id or the literal 'new' for create mode
	 */
	public function QuantityUnitConversionEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$product = null;
		if (isset($request->getQueryParams()['product']))
		{
			$product = $this->DB->products($request->getQueryParams()['product']);
		}

		$defaultQuUnit = null;

		if (isset($request->getQueryParams()['qu-unit']))
		{
			$defaultQuUnit = $this->DB->quantity_units($request->getQueryParams()['qu-unit']);
		}

		if ($args['quConversionId'] == 'new')
		{
			return $this->RenderPage($response, 'quantityunitconversionform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('quantity_unit_conversions'),
				'quantityunits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'product' => $product,
				'defaultQuUnit' => $defaultQuUnit
			]);
		}
		else
		{
			return $this->RenderPage($response, 'quantityunitconversionform', [
				'quConversion' => $this->DB->quantity_unit_conversions($args['quConversionId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('quantity_unit_conversions'),
				'quantityunits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'product' => $product,
				'defaultQuUnit' => $defaultQuUnit
			]);
		}
	}

	/**
	 * Serves the quantity unit create/edit form (route GET /quantityunit/{quantityunitId}).
	 *
	 * @param array $args Route arguments; quantityunitId is either a quantity unit id or the literal 'new' for create mode
	 */
	public function QuantityUnitEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		if ($args['quantityunitId'] == 'new')
		{
			return $this->RenderPage($response, 'quantityunitform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('quantity_units'),
				'pluralCount' => LocalizationService::GetInstance()->GetPluralCount(),
				'pluralRule' => LocalizationService::GetInstance()->GetPluralDefinition()
			]);
		}
		else
		{
			$quantityUnit = $this->DB->quantity_units($args['quantityunitId']);

			return $this->RenderPage($response, 'quantityunitform', [
				'quantityUnit' => $quantityUnit,
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('quantity_units'),
				'pluralCount' => LocalizationService::GetInstance()->GetPluralCount(),
				'pluralRule' => LocalizationService::GetInstance()->GetPluralDefinition(),
				'defaultQuConversions' => $this->DB->quantity_unit_conversions()->where('from_qu_id = :1 AND product_id IS NULL', $quantityUnit->id),
				'quantityUnits' => $this->DB->quantity_units()
			]);
		}
	}

	/**
	 * Serves the quantity unit plural form testing view (route GET /quantityunitpluraltesting).
	 */
	public function QuantityUnitPluralFormTesting(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		return $this->RenderPage($response, 'quantityunitpluraltesting', [
			'quantityUnits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE')
		]);
	}

	/**
	 * Serves the quantity unit master data list view (route GET /quantityunits).
	 *
	 * Query parameter include_disabled (presence only) also lists inactive quantity units.
	 */
	public function QuantityUnitsList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$quantityUnits = $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$quantityUnits = $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->RenderPage($response, 'quantityunits', [
			'quantityunits' => $quantityUnits,
			'userfields' => UserfieldsService::GetInstance()->GetFields('quantity_units'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('quantity_units')
		]);
	}

	/**
	 * Serves the shopping list view (route GET /shoppinglist).
	 *
	 * Query parameter list (shopping list id) selects the displayed list; defaults to list 1.
	 */
	public function ShoppingList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_VIEW);
		$listId = 1;
		if (isset($request->getQueryParams()['list']))
		{
			$listId = $request->getQueryParams()['list'];
		}

		// LessQL carries a Result's ORDER BY into aggregate queries. PostgreSQL rejects
		// SELECT COUNT(*) ... ORDER BY product_name, while SQLite silently accepts it, so
		// count the unordered result before adding the display order (issue #45).
		$listItems = $this->DB->uihelper_shopping_list()->where('shopping_list_id = :1', $listId);

		return $this->RenderPage($response, 'shoppinglist', [
			'listItems' => $listItems->orderBy('product_name', 'COLLATE NOCASE'),
			'listItemsCount' => $listItems->count(),
			'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'missingProducts' => StockService::GetInstance()->GetMissingProducts(),
			'shoppingLists' => $this->DB->shopping_lists_view()->orderBy('name', 'COLLATE NOCASE'),
			'selectedShoppingListId' => $listId,
			'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
			'productUserfields' => UserfieldsService::GetInstance()->GetFields('products'),
			'productUserfieldValues' => UserfieldsService::GetInstance()->GetAllValues('products'),
			'productGroupUserfields' => UserfieldsService::GetInstance()->GetFields('product_groups'),
			'productGroupUserfieldValues' => UserfieldsService::GetInstance()->GetAllValues('product_groups'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('shopping_list'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('shopping_list')
		]);
	}

	/**
	 * Serves the shopping list create/edit form (route GET /shoppinglist/{listId}).
	 *
	 * @param array $args Route arguments; listId is either a shopping list id or the literal 'new' for create mode
	 */
	public function ShoppingListEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_VIEW);
		if ($args['listId'] == 'new')
		{
			return $this->RenderPage($response, 'shoppinglistform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('shopping_lists')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'shoppinglistform', [
				'shoppingList' => $this->DB->shopping_lists($args['listId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('shopping_lists')
			]);
		}
	}

	/**
	 * Serves the shopping list item create/edit form (route GET /shoppinglistitem/{itemId}).
	 *
	 * @param array $args Route arguments; itemId is either a list item id or the literal 'new' for create mode
	 */
	public function ShoppingListItemEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_VIEW);
		if ($args['itemId'] == 'new')
		{
			return $this->RenderPage($response, 'shoppinglistitemform', [
				'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'barcodes' => $this->DB->product_barcodes_comma_separated(),
				'shoppingLists' => $this->DB->shopping_lists()->orderBy('name', 'COLLATE NOCASE'),
				'mode' => 'create',
				'quantityUnits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
				'userfields' => UserfieldsService::GetInstance()->GetFields('shopping_list')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'shoppinglistitemform', [
				'listItem' => $this->DB->shopping_list($args['itemId']),
				'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'barcodes' => $this->DB->product_barcodes_comma_separated(),
				'shoppingLists' => $this->DB->shopping_lists()->orderBy('name', 'COLLATE NOCASE'),
				'mode' => 'edit',
				'quantityUnits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
				'userfields' => UserfieldsService::GetInstance()->GetFields('shopping_list')
			]);
		}
	}

	/**
	 * Serves the shopping list settings view (route GET /shoppinglistsettings).
	 */
	public function ShoppingListSettings(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_VIEW);
		return $this->RenderPage($response, 'shoppinglistsettings', [
			'shoppingLists' => $this->DB->shopping_lists()->orderBy('name', 'COLLATE NOCASE')
		]);
	}

	/**
	 * Serves the store (shopping location) create/edit form (route GET /shoppinglocation/{shoppingLocationId}).
	 *
	 * @param array $args Route arguments; shoppingLocationId is either a store id or the literal 'new' for create mode
	 */
	public function ShoppingLocationEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		if ($args['shoppingLocationId'] == 'new')
		{
			return $this->RenderPage($response, 'shoppinglocationform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('shopping_locations')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'shoppinglocationform', [
				'shoppingLocation' => $this->DB->shopping_locations($args['shoppingLocationId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('shopping_locations')
			]);
		}
	}

	/**
	 * Serves the store (shopping location) master data list view (route GET /shoppinglocations).
	 *
	 * Query parameter include_disabled (presence only) also lists inactive stores.
	 */
	public function ShoppingLocationsList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$shoppingLocations = $this->DB->shopping_locations()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$shoppingLocations = $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->RenderPage($response, 'shoppinglocations', [
			'shoppinglocations' => $shoppingLocations,
			'userfields' => UserfieldsService::GetInstance()->GetFields('shopping_locations'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('shopping_locations')
		]);
	}

	/**
	 * Serves the stock entry edit form (route GET /stockentry/{entryId}).
	 */
	public function StockEntryEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		return $this->RenderPage($response, 'stockentryform', [
			'stockEntry' => $this->DB->stock()->where('id', $args['entryId'])->fetch(),
			'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'shoppinglocations' => $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('stock')
		]);
	}

	/**
	 * Serves the Grocycode barcode PNG for a single stock entry
	 * (route GET /stockentry/{entryId}/grocycode).
	 */
	public function StockEntryGrocycodeImage(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$stockEntry = $this->DB->stock()->where('id', $args['entryId'])->fetch();
		$gc = new Grocycode(Grocycode::PRODUCT, $stockEntry->product_id, [$stockEntry->stock_id]);
		return $this->ServeGrocycodeImage($request, $response, $gc);
	}

	/**
	 * Serves the printable Grocycode label page for a single stock entry
	 * (route GET /stockentry/{entryId}/label).
	 */
	public function StockEntryGrocycodeLabel(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$stockEntry = $this->DB->stock()->where('id', $args['entryId'])->fetch();
		return $this->RenderPage($response, 'stockentrylabel', [
			'stockEntry' => $stockEntry,
			'product' => $this->DB->products($stockEntry->product_id),
		]);
	}

	/**
	 * Serves the stock settings view (route GET /stocksettings).
	 */
	public function StockSettings(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		return $this->RenderPage($response, 'stocksettings', [
			'locations' => $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'productGroups' => $this->DB->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE')
		]);
	}

	/**
	 * Serves the stock entries view listing every single stock entry (route GET /stockentries).
	 */
	public function Stockentries(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$usersService = UsersService::GetInstance();
		$nextXDays = $usersService->GetUserSettings(VICTUAL_USER_ID)['stock_due_soon_days'];

		return $this->RenderPage($response, 'stockentries', [
			'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'shoppinglocations' => $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'stockEntries' => $this->DB->uihelper_stock_entries()->orderBy('product_id'),
			'currentStockLocations' => StockService::GetInstance()->GetCurrentStockLocations(),
			'nextXDays' => $nextXDays,
			'userfieldsProducts' => UserfieldsService::GetInstance()->GetFields('products'),
			'userfieldValuesProducts' => UserfieldsService::GetInstance()->GetAllValues('products'),
			'userfieldsStock' => UserfieldsService::GetInstance()->GetFields('stock'),
			'userfieldValuesStock' => UserfieldsService::GetInstance()->GetAllValues('stock')
		]);
	}

	/**
	 * Serves the transfer view (route GET /transfer); only products currently
	 * in stock (with own stock) are offered.
	 */
	public function Transfer(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		return $this->RenderPage($response, 'transfer', [
			'products' => $this->DB->products()->where('active = 1')->where('no_own_stock = 0 AND id IN (SELECT product_id from stock_current WHERE amount_aggregated > 0)')->orderBy('name', 'COLLATE NOCASE'),
			'barcodes' => $this->DB->product_barcodes_comma_separated(),
			'locations' => $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved()
		]);
	}

	/**
	 * Serves the stock journal summary view (route GET /stockjournal/summary).
	 *
	 * Optional query parameters product_id, user_id and transaction_type filter the summary.
	 */
	public function JournalSummary(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$entries = $this->DB->uihelper_stock_journal_summary();
		if (isset($request->getQueryParams()['product_id']))
		{
			$entries = $entries->where('product_id', $request->getQueryParams()['product_id']);
		}
		if (isset($request->getQueryParams()['user_id']))
		{
			$entries = $entries->where('user_id', $request->getQueryParams()['user_id']);
		}
		if (isset($request->getQueryParams()['transaction_type']))
		{
			$entries = $entries->where('transaction_type', $request->getQueryParams()['transaction_type']);
		}

		$usersService = UsersService::GetInstance();
		return $this->RenderPage($response, 'stockjournalsummary', [
			'entries' => $entries,
			'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'users' => $usersService->GetUsersAsDto(),
			'transactionTypes' => GetClassConstants('\Victual\Services\StockService', 'TRANSACTION_TYPE_')
		]);
	}

	/**
	 * Serves the resolved quantity unit conversions view (route GET /quantityunitconversionsresolved).
	 *
	 * Query parameter product (id) shows the conversions for that product;
	 * otherwise only product independent conversions are shown.
	 */
	public function QuantityUnitConversionsResolved(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$product = null;
		if (isset($request->getQueryParams()['product']))
		{
			$product = $this->DB->products($request->getQueryParams()['product']);
			$quantityUnitConversionsResolved = $this->DB->cache__quantity_unit_conversions_resolved()->where('product_id', $product->id);
		}
		else
		{
			$quantityUnitConversionsResolved = $this->DB->cache__quantity_unit_conversions_resolved()->where('product_id IS NULL');
		}

		return $this->RenderPage($response, 'quantityunitconversionsresolved', [
			'product' => $product,
			'quantityUnits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $quantityUnitConversionsResolved
		]);
	}
}
