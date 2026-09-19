<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Helpers\Grocycode;
use Victual\Services\DatabaseService;
use Victual\Services\FieldPolicy;
use Victual\Services\Labels\LabelIdentityService;
use Victual\Services\LocalizationService;
use Victual\Services\StockService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the /api/stock endpoints: current/volatile stock, stock entries,
 * bookings/transactions with undo, product details and price history, the
 * purchase/consume/transfer/inventory/open flows (also by barcode via
 * /api/stock/products/by-barcode/...), shopping list operations
 * (/api/stock/shoppinglist/...) and label printing.
 */
class StockApiController extends BaseApiController
{
	/**
	 * POST /api/stock/shoppinglist/add-missing-products - adds all products below their
	 * minimum stock amount to the shopping list given by the numeric body field list_id
	 * (default 1). Requires the SHOPPINGLIST_ITEMS_ADD permission (403 otherwise).
	 * Returns 204 on success or a 400 error response.
	 */
	public function AddMissingProductsToShoppingList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_ADD);

		return $this->HandleApiCall($response, function () use ($request, $response)
		{
			$requestBody = $this->GetParsedAndFilteredRequestBody($request);

			$listId = 1;

			if (array_key_exists('list_id', $requestBody) && !empty($requestBody['list_id']) && is_numeric($requestBody['list_id']))
			{
				$listId = $requestBody['list_id'];
			}

			StockService::GetInstance()->AddMissingProductsToShoppingList($listId);
			return $this->EmptyApiResponse($response);
		});
	}

	/**
	 * POST /api/stock/shoppinglist/add-overdue-products - adds all overdue products to
	 * the shopping list given by the numeric body field list_id (default 1).
	 * Requires the SHOPPINGLIST_ITEMS_ADD permission (403 otherwise).
	 * Returns 204 on success or a 400 error response.
	 */
	public function AddOverdueProductsToShoppingList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_ADD);

		return $this->HandleApiCall($response, function () use ($request, $response)
		{
			$requestBody = $this->GetParsedAndFilteredRequestBody($request);

			$listId = 1;
			if (array_key_exists('list_id', $requestBody) && !empty($requestBody['list_id']) && is_numeric($requestBody['list_id']))
			{
				$listId = $requestBody['list_id'];
			}

			StockService::GetInstance()->AddOverdueProductsToShoppingList($listId);
			return $this->EmptyApiResponse($response);
		});
	}

	/**
	 * POST /api/stock/shoppinglist/add-expired-products - adds all expired products to
	 * the shopping list given by the numeric body field list_id (default 1).
	 * Requires the SHOPPINGLIST_ITEMS_ADD permission (403 otherwise).
	 * Returns 204 on success or a 400 error response.
	 */
	public function AddExpiredProductsToShoppingList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_ADD);

		return $this->HandleApiCall($response, function () use ($request, $response)
		{
			$requestBody = $this->GetParsedAndFilteredRequestBody($request);

			$listId = 1;
			if (array_key_exists('list_id', $requestBody) && !empty($requestBody['list_id']) && is_numeric($requestBody['list_id']))
			{
				$listId = $requestBody['list_id'];
			}

			StockService::GetInstance()->AddExpiredProductsToShoppingList($listId);
			return $this->EmptyApiResponse($response);
		});
	}

	/**
	 * POST /api/stock/products/{productId}/add - adds the given amount of a product to
	 * stock (a purchase). Requires the STOCK_PURCHASE permission (403 otherwise).
	 * Body fields: amount (required), best_before_date (ISO date), purchased_date
	 * (ISO date, default today), price, location_id, shopping_location_id,
	 * transaction_type (default "purchase"), stock_label_type (default 0) and note.
	 * Returns the stock_log rows of the resulting transaction (200) or a 400 error response.
	 */
	public function AddProduct(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		return $this->HandleApiCall($response, function () use ($args, $request, $requestBody, $response)
		{
			if ($requestBody === null)
			{
				throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
			}

			if (!array_key_exists('amount', $requestBody))
			{
				throw new \Exception('An amount is required');
			}

			$bestBeforeDate = null;
			if (array_key_exists('best_before_date', $requestBody) && IsIsoDate($requestBody['best_before_date']))
			{
				$bestBeforeDate = $requestBody['best_before_date'];
			}

			$purchasedDate = date('Y-m-d');
			if (array_key_exists('purchased_date', $requestBody) && IsIsoDate($requestBody['purchased_date']))
			{
				$purchasedDate = $requestBody['purchased_date'];
			}

			$price = null;
			if (array_key_exists('price', $requestBody) && is_numeric($requestBody['price']))
			{
				$price = $requestBody['price'];
			}

			$locationId = null;
			if (array_key_exists('location_id', $requestBody) && is_numeric($requestBody['location_id']))
			{
				$locationId = $requestBody['location_id'];
			}

			$shoppingLocationId = null;
			if (array_key_exists('shopping_location_id', $requestBody) && is_numeric($requestBody['shopping_location_id']))
			{
				$shoppingLocationId = $requestBody['shopping_location_id'];
			}

			$transactionType = StockService::TRANSACTION_TYPE_PURCHASE;
			if (array_key_exists('transaction_type', $requestBody) && !empty($requestBody['transaction_type']))
			{
				$transactionType = $requestBody['transaction_type'];
			}

			$stockLabelType = 0;
			if (array_key_exists('stock_label_type', $requestBody) && is_numeric($requestBody['stock_label_type']))
			{
				$stockLabelType = $requestBody['stock_label_type'];
			}

			$note = null;
			if (array_key_exists('note', $requestBody))
			{
				$note = $requestBody['note'];
			}

			$transactionId = StockService::GetInstance()->AddProduct($args['productId'], $requestBody['amount'], $bestBeforeDate, $transactionType, $purchasedDate, $price, $locationId, $shoppingLocationId, $unusedTransactionId, $stockLabelType, $note);

			$args['transactionId'] = $transactionId;
			return $this->StockTransactions($request, $response, $args);
		});
	}

	/**
	 * POST /api/stock/products/by-barcode/{barcode}/add - resolves the barcode to a
	 * product id and delegates to AddProduct (same body fields/responses);
	 * 400 error response when the barcode is unknown.
	 */
	public function AddProductByBarcode(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($args, $request, $response)
		{
			$args['productId'] = StockService::GetInstance()->GetProductIdFromBarcode($args['barcode']);
			return $this->AddProduct($request, $response, $args);
		});
	}

	/**
	 * POST /api/stock/shoppinglist/add-product - adds a product to a shopping list.
	 * Body fields: product_id (required, numeric), product_amount (default 1),
	 * qu_id (quantity unit id, default -1), note and list_id (default 1).
	 * Requires the SHOPPINGLIST_ITEMS_ADD permission (403 otherwise).
	 * Returns 204 on success or a 400 error response (e.g. missing product id).
	 */
	public function AddProductToShoppingList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_ADD);

		return $this->HandleApiCall($response, function () use ($request, $response)
		{
			$requestBody = $this->GetParsedAndFilteredRequestBody($request);

			$listId = 1;
			$amount = 1;
			$quId = -1;
			$productId = null;
			$note = null;

			if (array_key_exists('list_id', $requestBody) && !empty($requestBody['list_id']) && is_numeric($requestBody['list_id']))
			{
				$listId = $requestBody['list_id'];
			}

			if (array_key_exists('product_amount', $requestBody) && !empty($requestBody['product_amount']) && is_numeric($requestBody['product_amount']))
			{
				$amount = $requestBody['product_amount'];
			}

			if (array_key_exists('product_id', $requestBody) && !empty($requestBody['product_id']) && is_numeric($requestBody['product_id']))
			{
				$productId = $requestBody['product_id'];
			}

			if (array_key_exists('note', $requestBody) && !empty($requestBody['note']))
			{
				$note = $requestBody['note'];
			}

			if (array_key_exists('qu_id', $requestBody) && !empty($requestBody['qu_id']))
			{
				$quId = $requestBody['qu_id'];
			}

			if ($productId == null)
			{
				throw new \Exception('No product id was supplied');
			}

			StockService::GetInstance()->AddProductToShoppingList($productId, $amount, $quId, $note, $listId);
			return $this->EmptyApiResponse($response);
		});
	}

	/**
	 * POST /api/stock/shoppinglist/clear - removes all items (or only the done ones,
	 * when the boolean body field done_only is true) from the shopping list given by
	 * the numeric body field list_id (default 1).
	 * Requires the SHOPPINGLIST_ITEMS_DELETE permission (403 otherwise).
	 * Returns 204 on success or a 400 error response.
	 */
	public function ClearShoppingList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_DELETE);

		return $this->HandleApiCall($response, function () use ($request, $response)
		{
			$requestBody = $this->GetParsedAndFilteredRequestBody($request);

			$listId = 1;
			if (array_key_exists('list_id', $requestBody) && !empty($requestBody['list_id']) && is_numeric($requestBody['list_id']))
			{
				$listId = $requestBody['list_id'];
			}

			$doneOnly = false;
			if (array_key_exists('done_only', $requestBody) && filter_var($requestBody['done_only'], FILTER_VALIDATE_BOOLEAN) !== false)
			{
				$doneOnly = boolval($requestBody['done_only']);
			}

			StockService::GetInstance()->ClearShoppingList($listId, $doneOnly);
			return $this->EmptyApiResponse($response);
		});
	}

	/**
	 * POST /api/stock/products/{productId}/consume - consumes the given amount of a
	 * product from stock. Requires the STOCK_CONSUME permission (403 otherwise).
	 * Body fields: amount (required), spoiled, stock_entry_id (consume a specific
	 * entry), location_id, recipe_id, exact_amount and allow_subproduct_substitution;
	 * transaction_type defaults to "consume".
	 * Returns the stock_log rows of the resulting transaction (200) or a 400 error response.
	 */
	public function ConsumeProduct(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_CONSUME);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		return $this->HandleApiCall($response, function () use ($args, $request, $requestBody, $response)
		{
			if ($requestBody === null)
			{
				throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
			}

			if (!array_key_exists('amount', $requestBody))
			{
				throw new \Exception('An amount is required');
			}

			$spoiled = false;
			if (array_key_exists('spoiled', $requestBody))
			{
				$spoiled = $requestBody['spoiled'];
			}

			$transactionType = StockService::TRANSACTION_TYPE_CONSUME;
			if (array_key_exists('transaction_type', $requestBody) && !empty($requestBody['transaction_type']))
			{
				$transactionType = $requestBody['transaction_type'];
			}

			$specificStockEntryId = 'default';
			if (array_key_exists('stock_entry_id', $requestBody) && !empty($requestBody['stock_entry_id']))
			{
				$specificStockEntryId = $requestBody['stock_entry_id'];
			}

			$locationId = null;
			if (array_key_exists('location_id', $requestBody) && !empty($requestBody['location_id']) && is_numeric($requestBody['location_id']))
			{
				$locationId = $requestBody['location_id'];
			}

			$recipeId = null;
			if (array_key_exists('recipe_id', $requestBody) && is_numeric($requestBody['recipe_id']))
			{
				$recipeId = $requestBody['recipe_id'];
			}

			$consumeExact = false;
			if (array_key_exists('exact_amount', $requestBody))
			{
				$consumeExact = $requestBody['exact_amount'];
			}

			$allowSubproductSubstitution = false;
			if (array_key_exists('allow_subproduct_substitution', $requestBody))
			{
				$allowSubproductSubstitution = $requestBody['allow_subproduct_substitution'];
			}

			$transactionId = null;
			$transactionId = StockService::GetInstance()->ConsumeProduct($args['productId'], $requestBody['amount'], $spoiled, $transactionType, $specificStockEntryId, $recipeId, $locationId, $transactionId, $allowSubproductSubstitution, $consumeExact);
			$args['transactionId'] = $transactionId;
			return $this->StockTransactions($request, $response, $args);
		});
	}

	/**
	 * POST /api/stock/products/by-barcode/{barcode}/consume - resolves the barcode to a
	 * product id and delegates to ConsumeProduct; when the barcode is a Grocycode
	 * carrying a stock entry id, that id is injected as the stock_entry_id body field.
	 * 400 error response when the barcode is unknown.
	 */
	public function ConsumeProductByBarcode(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($args, $request, $response)
		{
			$args['productId'] = StockService::GetInstance()->GetProductIdFromBarcode($args['barcode']);

			if (Grocycode::Validate($args['barcode']))
			{
				$gc = new Grocycode($args['barcode']);
				if ($gc->GetExtraData())
				{
					$requestBody = $request->getParsedBody();
					$requestBody['stock_entry_id'] = $gc->GetExtraData()[0];
					$request = $request->withParsedBody($requestBody);
				}
			}

			return $this->ConsumeProduct($request, $response, $args);
		});
	}

	/**
	 * GET /api/stock - returns all products currently in stock with their amounts (200).
	 */
	public function CurrentStock(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		// GetCurrentStock() reads stock_current via raw SQL (PDO::FETCH_OBJ), not a LessQL
		// Result, so it never passes through FilteredApiResponse's redaction - it is hand
		// built and redacted here, by the same 'stock_current' entity name permission_fields
		// carries the price-visibility row under.
		$currentStock = FieldPolicy::GetInstance()->RedactRows('stock_current', StockService::GetInstance()->GetCurrentStock());
		return $this->ApiResponse($response, $currentStock);
	}

	/**
	 * GET /api/stock/volatile - returns { "due_products": [], "overdue_products": [],
	 * "expired_products": [], "missing_products": [] }; the numeric query parameter
	 * due_soon_days (default 5) controls the "due soon" horizon.
	 */
	public function CurrentVolatileStock(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$nextXDays = 5;

		if (isset($request->getQueryParams()['due_soon_days']) && !empty($request->getQueryParams()['due_soon_days']) && is_numeric($request->getQueryParams()['due_soon_days']))
		{
			$nextXDays = $request->getQueryParams()['due_soon_days'];
		}

		// GetDueProducts()/GetExpiredProducts() are GetCurrentStock() with an extra WHERE -
		// the same stock_current rows CurrentStock() (GET /api/stock) redacts, carrying the
		// same 'value' field. GetMissingProducts() reads stock_missing_products, which has
		// no price-bearing column, so it is returned as-is.
		$fieldPolicy = FieldPolicy::GetInstance();
		$dueProducts = $fieldPolicy->RedactRows('stock_current', StockService::GetInstance()->GetDueProducts($nextXDays, true));
		$overdueProducts = $fieldPolicy->RedactRows('stock_current', StockService::GetInstance()->GetDueProducts(-1));
		$expiredProducts = $fieldPolicy->RedactRows('stock_current', StockService::GetInstance()->GetExpiredProducts());
		$missingProducts = StockService::GetInstance()->GetMissingProducts();
		return $this->ApiResponse($response, [
			'due_products' => $dueProducts,
			'overdue_products' => $overdueProducts,
			'expired_products' => $expiredProducts,
			'missing_products' => $missingProducts
		]);
	}

	/**
	 * PUT /api/stock/entry/{entryId} - edits a single stock entry.
	 * Requires the STOCK_EDIT permission (403 otherwise).
	 * Body fields: amount (required), open and purchased_date (both read
	 * unconditionally), best_before_date (ISO date), price, location_id,
	 * shopping_location_id and note.
	 * Returns the stock_log rows of the resulting transaction (200) or a 400 error response.
	 */
	public function EditStockEntry(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_EDIT);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		return $this->HandleApiCall($response, function () use ($args, $request, $requestBody, $response)
		{
			if ($requestBody === null)
			{
				throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
			}

			if (!array_key_exists('amount', $requestBody))
			{
				throw new \Exception('An amount is required');
			}

			$bestBeforeDate = null;
			if (array_key_exists('best_before_date', $requestBody) && IsIsoDate($requestBody['best_before_date']))
			{
				$bestBeforeDate = $requestBody['best_before_date'];
			}

			$price = null;
			if (array_key_exists('price', $requestBody) && is_numeric($requestBody['price']))
			{
				$price = $requestBody['price'];
			}

			$locationId = null;
			if (array_key_exists('location_id', $requestBody) && is_numeric($requestBody['location_id']))
			{
				$locationId = $requestBody['location_id'];
			}

			$shoppingLocationId = null;
			if (array_key_exists('shopping_location_id', $requestBody) && is_numeric($requestBody['shopping_location_id']))
			{
				$shoppingLocationId = $requestBody['shopping_location_id'];
			}

			$note = null;
			if (array_key_exists('note', $requestBody))
			{
				$note = $requestBody['note'];
			}

			$transactionId = StockService::GetInstance()->EditStockEntry($args['entryId'], $requestBody['amount'], $bestBeforeDate, $locationId, $shoppingLocationId, $price, $requestBody['open'], $requestBody['purchased_date'], $note);
			$args['transactionId'] = $transactionId;
			return $this->StockTransactions($request, $response, $args);
		});
	}

	/**
	 * POST /api/stock/entry/{entryId}/measure - records a new measurement of an
	 * already-open, single-unit stock entry (ADR-0022, docs/plans/landed/28-open-container-measurement.md).
	 * Requires the STOCK_EDIT permission (403 otherwise).
	 * Body fields: amount and qu_id (both required, the reading and the unit it was taken
	 * in), gross (bool, default false) and tare (required, same unit, when gross is true -
	 * the contract names the reading gross explicitly so a client cannot subtract tare
	 * twice; ADR-0022 decision 4).
	 * Returns the stock_log rows of the resulting transaction (200) or a 400 error response
	 * (entry not found, not a coherent single opened container, or the unit does not convert
	 * to the product's stock unit).
	 */
	public function MeasureStockEntry(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_EDIT);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		return $this->HandleApiCall($response, function () use ($args, $request, $requestBody, $response)
		{
			if ($requestBody === null)
			{
				throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
			}

			if (!array_key_exists('amount', $requestBody))
			{
				throw new \Exception('An amount is required');
			}

			if (!array_key_exists('qu_id', $requestBody))
			{
				throw new \Exception('A qu_id is required');
			}

			$measurement = [
				'amount' => $requestBody['amount'],
				'qu_id' => $requestBody['qu_id'],
				'is_gross' => array_key_exists('gross', $requestBody) ? boolval($requestBody['gross']) : false,
				'tare' => array_key_exists('tare', $requestBody) ? $requestBody['tare'] : null,
			];

			$transactionId = StockService::GetInstance()->MeasureStockEntry($args['entryId'], $measurement);
			$args['transactionId'] = $transactionId;
			return $this->StockTransactions($request, $response, $args);
		});
	}

	/**
	 * GET /api/stock/barcodes/external-lookup/{barcode} - looks up the barcode via the
	 * configured external barcode lookup plugin; with query parameter add=true the
	 * found product is also created. Requires the MASTER_DATA_EDIT permission (403
	 * otherwise). Returns the lookup result (200) or a 400 error response.
	 */
	public function ExternalBarcodeLookup(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

		return $this->HandleApiCall($response, function () use ($args, $request, $response)
		{
			$addFoundProduct = false;
			if (isset($request->getQueryParams()['add']) && ($request->getQueryParams()['add'] === 'true' || $request->getQueryParams()['add'] === 1))
			{
				$addFoundProduct = true;
			}

			return $this->ApiResponse($response, StockService::GetInstance()->ExternalBarcodeLookup($args['barcode'], $addFoundProduct));
		});
	}

	/**
	 * POST /api/stock/products/{productId}/inventory - sets the stock amount of a
	 * product to a new absolute value (inventory correction booking).
	 * Requires the STOCK_INVENTORY permission (403 otherwise).
	 * Body fields: new_amount (required), best_before_date (ISO date), purchased_date
	 * (ISO date), location_id, price, shopping_location_id, stock_label_type (default 0)
	 * and note.
	 * Returns the stock_log rows of the resulting transaction (200) or a 400 error response.
	 */
	public function InventoryProduct(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_INVENTORY);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		return $this->HandleApiCall($response, function () use ($args, $request, $requestBody, $response)
		{
			if ($requestBody === null)
			{
				throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
			}

			if (!array_key_exists('new_amount', $requestBody))
			{
				throw new \Exception('An new amount is required');
			}

			$bestBeforeDate = null;
			if (array_key_exists('best_before_date', $requestBody) && IsIsoDate($requestBody['best_before_date']))
			{
				$bestBeforeDate = $requestBody['best_before_date'];
			}

			$purchasedDate = null;
			if (array_key_exists('purchased_date', $requestBody) && IsIsoDate($requestBody['purchased_date']))
			{
				$purchasedDate = $requestBody['purchased_date'];
			}

			$locationId = null;
			if (array_key_exists('location_id', $requestBody) && is_numeric($requestBody['location_id']))
			{
				$locationId = $requestBody['location_id'];
			}

			$price = null;
			if (array_key_exists('price', $requestBody) && is_numeric($requestBody['price']))
			{
				$price = $requestBody['price'];
			}

			$shoppingLocationId = null;
			if (array_key_exists('shopping_location_id', $requestBody) && is_numeric($requestBody['shopping_location_id']))
			{
				$shoppingLocationId = $requestBody['shopping_location_id'];
			}

			$stockLabelType = 0;
			if (array_key_exists('stock_label_type', $requestBody) && is_numeric($requestBody['stock_label_type']))
			{
				$stockLabelType = $requestBody['stock_label_type'];
			}

			$note = null;
			if (array_key_exists('note', $requestBody))
			{
				$note = $requestBody['note'];
			}

			$transactionId = StockService::GetInstance()->InventoryProduct($args['productId'], $requestBody['new_amount'], $bestBeforeDate, $locationId, $price, $shoppingLocationId, $purchasedDate, $stockLabelType, $note);
			$args['transactionId'] = $transactionId;
			return $this->StockTransactions($request, $response, $args);
		});
	}

	/**
	 * POST /api/stock/products/by-barcode/{barcode}/inventory - resolves the barcode to
	 * a product id and delegates to InventoryProduct (same body fields/responses);
	 * 400 error response when the barcode is unknown.
	 */
	public function InventoryProductByBarcode(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($args, $request, $response)
		{
			$args['productId'] = StockService::GetInstance()->GetProductIdFromBarcode($args['barcode']);
			return $this->InventoryProduct($request, $response, $args);
		});
	}

	/**
	 * POST /api/stock/products/{productId}/open - marks the given amount of a product
	 * as opened. Requires the STOCK_OPEN permission (403 otherwise).
	 * Body fields: amount (required), stock_entry_id (open a specific entry) and
	 * allow_subproduct_substitution. Optionally, a measurement object records the
	 * container's contents as it is opened (ADR-0022, docs/plans/landed/28-open-container-measurement.md):
	 * measurement.amount and measurement.qu_id (both required within it), measurement.gross
	 * (bool, default false) and measurement.tare (required, same unit, when gross is true).
	 * A measurement requires stock_entry_id (a specific entry) and amount = 1.
	 * Returns the stock_log rows of the resulting transaction (200) or a 400 error response.
	 */
	public function OpenProduct(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_OPEN);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		return $this->HandleApiCall($response, function () use ($args, $request, $requestBody, $response)
		{
			if ($requestBody === null)
			{
				throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
			}

			if (!array_key_exists('amount', $requestBody))
			{
				throw new \Exception('An amount is required');
			}

			$specificStockEntryId = 'default';
			if (array_key_exists('stock_entry_id', $requestBody) && !empty($requestBody['stock_entry_id']))
			{
				$specificStockEntryId = $requestBody['stock_entry_id'];
			}

			$allowSubproductSubstitution = false;
			if (array_key_exists('allow_subproduct_substitution', $requestBody))
			{
				$allowSubproductSubstitution = $requestBody['allow_subproduct_substitution'];
			}

			$measurement = null;
			if (array_key_exists('measurement', $requestBody) && is_array($requestBody['measurement']))
			{
				$measurement = [
					'amount' => $requestBody['measurement']['amount'] ?? null,
					'qu_id' => $requestBody['measurement']['qu_id'] ?? null,
					'is_gross' => boolval($requestBody['measurement']['gross'] ?? false),
					'tare' => $requestBody['measurement']['tare'] ?? null,
				];
			}

			$transactionId = null;
			$transactionId = StockService::GetInstance()->OpenProduct($args['productId'], $requestBody['amount'], $specificStockEntryId, $transactionId, $allowSubproductSubstitution, $measurement);
			$args['transactionId'] = $transactionId;
			return $this->StockTransactions($request, $response, $args);
		});
	}

	/**
	 * POST /api/stock/products/by-barcode/{barcode}/open - resolves the barcode to a
	 * product id and delegates to OpenProduct; when the barcode is a Grocycode carrying
	 * a stock entry id, that id is injected as the stock_entry_id body field.
	 * 400 error response when the barcode is unknown.
	 */
	public function OpenProductByBarcode(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($args, $request, $response)
		{
			$args['productId'] = StockService::GetInstance()->GetProductIdFromBarcode($args['barcode']);

			if (Grocycode::Validate($args['barcode']))
			{
				$gc = new Grocycode($args['barcode']);
				if ($gc->GetExtraData())
				{
					$requestBody = $request->getParsedBody();
					$requestBody['stock_entry_id'] = $gc->GetExtraData()[0];
					$request = $request->withParsedBody($requestBody);
				}
			}

			return $this->OpenProduct($request, $response, $args);
		});
	}

	/**
	 * GET /api/stock/products/{productId} - returns aggregated stock details for a
	 * product (product master data, current stock amount/value, best before/next due
	 * date, average price/shelf life etc.). Returns 200 or a 400 error response.
	 */
	public function ProductDetails(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			$details = FieldPolicy::GetInstance()->RedactRow('product_details', StockService::GetInstance()->GetProductDetails($args['productId']));
			$details['product_barcodes'] = FieldPolicy::GetInstance()->RedactRows('product_barcodes', $details['product_barcodes']);
			return $this->ApiResponse($response, $details);
		});
	}

	/**
	 * GET /api/stock/products/by-barcode/{barcode} - resolves the barcode to a
	 * product id and delegates to ProductDetails; 400 error response when the
	 * barcode is unknown.
	 */
	public function ProductDetailsByBarcode(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			$productId = StockService::GetInstance()->GetProductIdFromBarcode($args['barcode']);
			$details = FieldPolicy::GetInstance()->RedactRow('product_details', StockService::GetInstance()->GetProductDetails($productId));
			$details['product_barcodes'] = FieldPolicy::GetInstance()->RedactRows('product_barcodes', $details['product_barcodes']);
			return $this->ApiResponse($response, $details);
		});
	}

	/**
	 * GET /api/stock/products/{productId}/price-history - returns the price history
	 * of a product (one row per stock_log entry that carries a price), oldest first.
	 * Returns 200 or a 400 error response.
	 */
	public function ProductPriceHistory(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		// The whole response is prices - "date"/"price"/"shopping_location" per stock_log
		// entry - so this is refusal (403), not per-field redaction of a body that would
		// otherwise be an empty array. See docs/plans/19-rbac.md piece 2's "the whole
		// endpoint is the field", and the products_price_history row in permission_fields.
		User::CheckPermission($request, User::PERMISSION_STOCK_PRICES_VIEW);
		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			return $this->ApiResponse($response, StockService::GetInstance()->GetProductPriceHistory($args['productId']));
		});
	}

	/**
	 * GET /api/stock/products/{productId}/entries - returns the individual (non-opened)
	 * stock entries of a product; the boolean query parameter include_sub_products
	 * (default false) also includes stock entries of sub products. Supports the
	 * generic list filtering/sorting/pagination query parameters. Returns 200.
	 */
	public function ProductStockEntries(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$allowSubproductSubstitution = false;
		if (isset($request->getQueryParams()['include_sub_products']) && filter_var($request->getQueryParams()['include_sub_products'], FILTER_VALIDATE_BOOLEAN) !== false)
		{
			$allowSubproductSubstitution = true;
		}

		return $this->FilteredApiResponse($request, $response, StockService::GetInstance()->GetProductStockEntries($args['productId'], false, $allowSubproductSubstitution), $request->getQueryParams());
	}

	/**
	 * GET /api/stock/locations/{locationId}/entries - returns the individual stock
	 * entries currently held at a given location. Supports the generic list
	 * filtering/sorting/pagination query parameters. Returns 200.
	 */
	public function LocationStockEntries(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		return $this->FilteredApiResponse($request, $response, StockService::GetInstance()->GetLocationStockEntries($args['locationId']), $request->getQueryParams());
	}

	/**
	 * GET /api/stock/products/{productId}/locations - returns the locations a product
	 * is stocked at with the amount at each; the boolean query parameter
	 * include_sub_products (default false) also includes locations of sub products.
	 * Supports the generic list filtering/sorting/pagination query parameters.
	 * Returns 200.
	 */
	public function ProductStockLocations(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$allowSubproductSubstitution = false;
		if (isset($request->getQueryParams()['include_sub_products']) && filter_var($request->getQueryParams()['include_sub_products'], FILTER_VALIDATE_BOOLEAN) !== false)
		{
			$allowSubproductSubstitution = true;
		}

		return $this->FilteredApiResponse($request, $response, StockService::GetInstance()->GetProductStockLocations($args['productId'], $allowSubproductSubstitution), $request->getQueryParams());
	}

	/**
	 * POST /api/stock/shoppinglist/remove-product - removes a product from a
	 * shopping list. Body fields: product_id (required, numeric), product_amount
	 * (default 1) and list_id (default 1).
	 * Requires the SHOPPINGLIST_ITEMS_DELETE permission (403 otherwise).
	 * Returns 204 on success or a 400 error response (e.g. missing product id).
	 */
	public function RemoveProductFromShoppingList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_DELETE);

		return $this->HandleApiCall($response, function () use ($request, $response)
		{
			$requestBody = $this->GetParsedAndFilteredRequestBody($request);

			$listId = 1;
			$amount = 1;
			$productId = null;

			if (array_key_exists('list_id', $requestBody) && !empty($requestBody['list_id']) && is_numeric($requestBody['list_id']))
			{
				$listId = $requestBody['list_id'];
			}

			if (array_key_exists('product_amount', $requestBody) && !empty($requestBody['product_amount']) && is_numeric($requestBody['product_amount']))
			{
				$amount = $requestBody['product_amount'];
			}

			if (array_key_exists('product_id', $requestBody) && !empty($requestBody['product_id']) && is_numeric($requestBody['product_id']))
			{
				$productId = $requestBody['product_id'];
			}

			if ($productId == null)
			{
				throw new \Exception('No product id was supplied');
			}

			StockService::GetInstance()->RemoveProductFromShoppingList($productId, $amount, $listId);
			return $this->EmptyApiResponse($response);
		});
	}

	/**
	 * GET /api/stock/bookings/{bookingId} - returns a single stock_log row by its id.
	 * Returns 200 or a 400 error response when the booking does not exist.
	 */
	public function StockBooking(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			$stockLogRow = $this->DB->stock_log($args['bookingId']);

			if ($stockLogRow === null)
			{
				throw new \Exception('Stock booking does not exist');
			}

			// The same redaction StockTransactions() applies to the same rows, under the
			// same entity name: this endpoint returns one stock_log row where that one
			// returns a transaction's worth of them, and a caller who may not see
			// stock_log.price may not see it one row at a time either. Issue #176 item 2.
			$stockLogRow = FieldPolicy::GetInstance()->RedactRow('stock_log', $stockLogRow);

			return $this->ApiResponse($response, $stockLogRow);
		});
	}

	/**
	 * GET /api/stock/entry/{entryId} - returns a single stock entry by its id (200).
	 */
	public function StockEntry(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		$entry = FieldPolicy::GetInstance()->RedactRow('stock', StockService::GetInstance()->GetStockEntry($args['entryId']));
		return $this->ApiResponse($response, $entry);
	}

	/**
	 * GET /api/stock/transactions/{transactionId} - returns all stock_log rows that
	 * belong to a given transaction id; also used internally by the other stock
	 * booking endpoints (AddProduct, ConsumeProduct, InventoryProduct, OpenProduct,
	 * TransferProduct, EditStockEntry) to return the rows of the booking they just made.
	 * Returns 200 or a 400 error response when no matching transaction is found.
	 */
	public function StockTransactions(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			$transactionRows = $this->DB->stock_log()->where('transaction_id = :1', $args['transactionId'])->fetchAll();
			if (count($transactionRows) === 0)
			{
				throw new \Exception('No transaction was found by the given transaction id');
			}

			$transactionRows = FieldPolicy::GetInstance()->RedactRows('stock_log', $transactionRows);

			return $this->ApiResponse($response, $transactionRows);
		});
	}

	/**
	 * POST /api/stock/products/{productId}/transfer - moves the given amount of a
	 * product from one location to another. Requires the STOCK_TRANSFER permission
	 * (403 otherwise). Body fields: amount, location_id_from and location_id_to
	 * (all required), and stock_entry_id to transfer a specific entry.
	 * Returns the stock_log rows of the resulting transaction (200) or a 400 error response.
	 */
	public function TransferProduct(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_TRANSFER);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		return $this->HandleApiCall($response, function () use ($args, $request, $requestBody, $response)
		{
			if ($requestBody === null)
			{
				throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
			}

			if (!array_key_exists('amount', $requestBody))
			{
				throw new \Exception('An amount is required');
			}

			if (!array_key_exists('location_id_from', $requestBody))
			{
				throw new \Exception('A transfer from location is required');
			}

			if (!array_key_exists('location_id_to', $requestBody))
			{
				throw new \Exception('A transfer to location is required');
			}

			$specificStockEntryId = 'default';

			if (array_key_exists('stock_entry_id', $requestBody) && !empty($requestBody['stock_entry_id']))
			{
				$specificStockEntryId = $requestBody['stock_entry_id'];
			}

			$transactionId = StockService::GetInstance()->TransferProduct($args['productId'], $requestBody['amount'], $requestBody['location_id_from'], $requestBody['location_id_to'], $specificStockEntryId);
			$args['transactionId'] = $transactionId;
			return $this->StockTransactions($request, $response, $args);
		});
	}

	/**
	 * POST /api/stock/products/by-barcode/{barcode}/transfer - resolves the barcode to
	 * a product id and delegates to TransferProduct; when the barcode is a Grocycode
	 * carrying a stock entry id, that id is injected as the stock_entry_id body field.
	 * 400 error response when the barcode is unknown.
	 */
	public function TransferProductByBarcode(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($args, $request, $response)
		{
			$args['productId'] = StockService::GetInstance()->GetProductIdFromBarcode($args['barcode']);

			if (Grocycode::Validate($args['barcode']))
			{
				$gc = new Grocycode($args['barcode']);
				if ($gc->GetExtraData())
				{
					$requestBody = $request->getParsedBody();
					$requestBody['stock_entry_id'] = $gc->GetExtraData()[0];
					$request = $request->withParsedBody($requestBody);
				}
			}

			return $this->TransferProduct($request, $response, $args);
		});
	}

	/**
	 * POST /api/stock/locations/{locationId}/weigh - weighs a vessel (a bin, a spice jar)
	 * and corrects its one stock entry to match. Requires the STOCK_EDIT permission
	 * (403 otherwise). Body field gross_amount is required; gross_qu_id is optional and,
	 * when given, must equal the location's own tare_qu_id - present so a client's unit
	 * mismatch is refused rather than silently misweighed (ADR-0022 question 5's "gross"
	 * contract; docs/plans/landed/29-working-container-replenishment.md).
	 * Returns the stock_log rows of the resulting transaction (200) or a 400 error response.
	 */
	public function WeighLocation(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_EDIT);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		return $this->HandleApiCall($response, function () use ($args, $request, $requestBody, $response)
		{
			if ($requestBody === null)
			{
				throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
			}

			if (!array_key_exists('gross_amount', $requestBody))
			{
				throw new \Exception('A gross_amount is required');
			}

			$grossQuId = array_key_exists('gross_qu_id', $requestBody) && $requestBody['gross_qu_id'] !== null
				? (int)$requestBody['gross_qu_id']
				: null;

			$transactionId = StockService::GetInstance()->WeighLocation((int)$args['locationId'], (float)$requestBody['gross_amount'], $grossQuId);
			$args['transactionId'] = $transactionId;
			return $this->StockTransactions($request, $response, $args);
		});
	}

	/**
	 * POST /api/stock/locations/by-label/{code}/weigh - resolves a scanned location label
	 * (a `vctl:` payload, plan 06/25) to a location id and delegates to WeighLocation. This
	 * is the device-facing route: a kitchen scale identifies the vessel by scanning its
	 * location label rather than knowing a numeric id (ADR-0022 decision 4).
	 * 400 error response when the label is unknown, retired, or resolves to no location.
	 */
	public function WeighLocationByLabel(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_EDIT);

		return $this->HandleApiCall($response, function () use ($args, $request, $response)
		{
			// Resolve() takes a per-kind callable rather than a single flag since plan 32
			// generalised it past locations. This endpoint only ever wants a location - scoping
			// the callable to that kind (rather than granting every kind and rejecting
			// afterwards) keeps Resolve()'s per-kind denial boundary intact: a scanned
			// recipe/chore/battery/product/stock_entry code is refused before any lookup for
			// it runs, not merely after.
			$resolved = (new LabelIdentityService(DatabaseService::GetInstance()->GetDbConnectionRaw()))
				->Resolve($args['code'], fn (string $kind): bool => $kind === 'location');

			if (($resolved['status'] ?? null) !== 'resolved' || ($resolved['kind'] ?? null) !== 'location')
			{
				throw new \Exception('Label does not resolve to an active location');
			}

			$args['locationId'] = $resolved['target']['id'];
			return $this->WeighLocation($request, $response, $args);
		});
	}

	/**
	 * POST /api/stock/bookings/{bookingId}/undo - reverts a single stock booking by
	 * its stock_log id. Requires the STOCK_EDIT permission (403 otherwise).
	 * Returns 204 on success or a 400 error response.
	 */
	public function UndoBooking(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_EDIT);

		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			$this->ApiResponse($response, StockService::GetInstance()->UndoBooking($args['bookingId']));
			return $this->EmptyApiResponse($response);
		});
	}

	/**
	 * POST /api/stock/transactions/{transactionId}/undo - reverts every booking that
	 * belongs to a given transaction id. Requires the STOCK_EDIT permission
	 * (403 otherwise). Returns 204 on success or a 400 error response.
	 */
	public function UndoTransaction(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_EDIT);

		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			$this->ApiResponse($response, StockService::GetInstance()->UndoTransaction($args['transactionId']));
			return $this->EmptyApiResponse($response);
		});
	}

	/**
	 * POST /api/stock/products/{productIdToKeep}/merge/{productIdToRemove} - merges
	 * two products, moving all stock, references and history from the
	 * productIdToRemove product onto productIdToKeep and deleting the former.
	 * Requires the STOCK_EDIT permission (403 otherwise). Returns 204 on success or a
	 * 400 error response (e.g. non-numeric ids).
	 */
	public function MergeProducts(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_EDIT);

		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			if (filter_var($args['productIdToKeep'], FILTER_VALIDATE_INT) === false || filter_var($args['productIdToRemove'], FILTER_VALIDATE_INT) === false)
			{
				throw new \Exception('Provided {productIdToKeep} or {productIdToRemove} is not a valid integer');
			}

			$this->ApiResponse($response, StockService::GetInstance()->MergeProducts($args['productIdToKeep'], $args['productIdToRemove']));
			return $this->EmptyApiResponse($response);
		});
	}
}
