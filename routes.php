<?php

use Victual\Controllers\Api\BatteriesApiController;
use Victual\Controllers\Api\CalendarApiController;
use Victual\Controllers\Api\ChoresApiController;
use Victual\Controllers\Api\FilesApiController;
use Victual\Controllers\Api\GenericEntityApiController;
use Victual\Controllers\Api\OpenApiController;
use Victual\Controllers\Api\PrintApiController;
use Victual\Controllers\Api\RecipesApiController;
use Victual\Controllers\Api\RolesApiController;
use Victual\Controllers\Api\StockApiController;
use Victual\Controllers\Api\SystemApiController;
use Victual\Controllers\Api\TasksApiController;
use Victual\Controllers\Api\UsersApiController;
use Victual\Controllers\BatteriesController;
use Victual\Controllers\CalendarController;
use Victual\Controllers\ChoresController;
use Victual\Controllers\EquipmentController;
use Victual\Controllers\GenericEntityController;
use Victual\Controllers\LoginController;
use Victual\Controllers\RecipesController;
use Victual\Controllers\StockController;
use Victual\Controllers\StockReportsController;
use Victual\Controllers\SystemController;
use Victual\Controllers\TasksController;
use Victual\Controllers\UsersController;
use Victual\Middleware\PathParameterMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

$app->group('', function (RouteCollectorProxy $group)
{
	// System routes
	$group->get('/', [SystemController::class, 'Root'])->setName('root');
	$group->get('/about', [SystemController::class, 'About']);
	$group->get('/manifest', [SystemController::class, 'Manifest']);
	$group->get('/barcodescannertesting', [SystemController::class, 'BarcodeScannerTesting']);

	// Login routes
	$group->get('/login', [LoginController::class, 'LoginPage'])->setName('login');
	$group->post('/login', [LoginController::class, 'ProcessLogin'])->setName('login');
	// POST rather than GET: logging out changes state, and a state-changing GET is
	// reachable by an <img> tag on any page the browser loads. Sweep finding S8.
	$group->post('/logout', [LoginController::class, 'Logout']);

	// Generic entity interaction
	$group->get('/userfields', [GenericEntityController::class, 'UserfieldsList']);
	$group->get('/userfield/{userfieldId}', [GenericEntityController::class, 'UserfieldEditForm']);
	$group->get('/userentities', [GenericEntityController::class, 'UserentitiesList']);
	$group->get('/userentity/{userentityId}', [GenericEntityController::class, 'UserentityEditForm']);
	$group->get('/userobjects/{userentityName}', [GenericEntityController::class, 'UserobjectsList']);
	$group->get('/userobject/{userentityName}/{userobjectId}', [GenericEntityController::class, 'UserobjectEditForm']);

	// User routes
	$group->get('/users', [UsersController::class, 'UsersList']);
	$group->get('/user/{userId}', [UsersController::class, 'UserEditForm']);
	$group->get('/user/{userId}/permissions', [UsersController::class, 'PermissionList']);
	$group->get('/usersettings', [UsersController::class, 'UserSettings']);

	$group->get('/roles', [UsersController::class, 'RolesList']);
	$group->get('/role/{roleId}', [UsersController::class, 'RoleEditForm']);

	// Stock master data routes
	$group->get('/products', [StockController::class, 'ProductsList']);
	$group->get('/product/{productId}', [StockController::class, 'ProductEditForm']);
	$group->get('/quantityunits', [StockController::class, 'QuantityUnitsList']);
	$group->get('/quantityunit/{quantityunitId}', [StockController::class, 'QuantityUnitEditForm']);
	$group->get('/quantityunitconversion/{quConversionId}', [StockController::class, 'QuantityUnitConversionEditForm']);
	$group->get('/productgroups', [StockController::class, 'ProductGroupsList']);
	$group->get('/productgroup/{productGroupId}', [StockController::class, 'ProductGroupEditForm']);
	$group->get('/product/{productId}/grocycode', [StockController::class, 'ProductGrocycodeImage']);

	// Stock handling routes
	$group->get('/stockoverview', [StockController::class, 'Overview']);
	$group->get('/stockentries', [StockController::class, 'Stockentries']);
	$group->get('/purchase', [StockController::class, 'Purchase']);
	$group->get('/consume', [StockController::class, 'Consume']);
	$group->get('/transfer', [StockController::class, 'Transfer']);
	$group->get('/inventory', [StockController::class, 'Inventory']);
	$group->get('/stockentry/{entryId}', [StockController::class, 'StockEntryEditForm']);
	$group->get('/stocksettings', [StockController::class, 'StockSettings']);
	$group->get('/locations', [StockController::class, 'LocationsList']);
	$group->get('/location/{locationId}', [StockController::class, 'LocationEditForm']);
	$group->get('/stockjournal', [StockController::class, 'Journal']);
	$group->get('/locationcontentsheet', [StockController::class, 'LocationContentSheet']);
	$group->get('/quantityunitpluraltesting', [StockController::class, 'QuantityUnitPluralFormTesting']);
	$group->get('/stockjournal/summary', [StockController::class, 'JournalSummary']);
	$group->get('/productbarcodes/{productBarcodeId}', [StockController::class, 'ProductBarcodesEditForm']);
	$group->get('/stockentry/{entryId}/grocycode', [StockController::class, 'StockEntryGrocycodeImage']);
	$group->get('/stockentry/{entryId}/label', [StockController::class, 'StockEntryGrocycodeLabel']);
	$group->get('/quantityunitconversionsresolved', [StockController::class, 'QuantityUnitConversionsResolved']);
	$group->get('/stockreports/spendings', [StockReportsController::class, 'Spendings']);

	// Stock price tracking
	$group->get('/shoppinglocations', [StockController::class, 'ShoppingLocationsList']);
	$group->get('/shoppinglocation/{shoppingLocationId}', [StockController::class, 'ShoppingLocationEditForm']);

	// Shopping list routes
	$group->get('/shoppinglist', [StockController::class, 'ShoppingList']);
	$group->get('/shoppinglistitem/{itemId}', [StockController::class, 'ShoppingListItemEditForm']);
	$group->get('/shoppinglist/{listId}', [StockController::class, 'ShoppingListEditForm']);
	$group->get('/shoppinglistsettings', [StockController::class, 'ShoppingListSettings']);

	// Recipe routes
	$group->get('/recipes', [RecipesController::class, 'Overview']);
	$group->get('/recipe/{recipeId}', [RecipesController::class, 'RecipeEditForm']);
	$group->get('/recipe/{recipeId}/pos/{recipePosId}', [RecipesController::class, 'RecipePosEditForm']);
	$group->get('/recipessettings', [RecipesController::class, 'RecipesSettings']);
	$group->get('/recipe/{recipeId}/grocycode', [RecipesController::class, 'RecipeGrocycodeImage']);

	// Meal plan routes
	$group->get('/mealplan', [RecipesController::class, 'MealPlan']);
	$group->get('/mealplansections', [RecipesController::class, 'MealPlanSectionsList']);
	$group->get('/mealplansection/{sectionId}', [RecipesController::class, 'MealPlanSectionEditForm']);

	// Chore routes
	$group->get('/choresoverview', [ChoresController::class, 'Overview']);
	$group->get('/choretracking', [ChoresController::class, 'TrackChoreExecution']);
	$group->get('/choresjournal', [ChoresController::class, 'Journal']);
	$group->get('/chores', [ChoresController::class, 'ChoresList']);
	$group->get('/chore/{choreId}', [ChoresController::class, 'ChoreEditForm']);
	$group->get('/choressettings', [ChoresController::class, 'ChoresSettings']);
	$group->get('/chore/{choreId}/grocycode', [ChoresController::class, 'ChoreGrocycodeImage']);

	// Battery routes
	$group->get('/batteriesoverview', [BatteriesController::class, 'Overview']);
	$group->get('/batterytracking', [BatteriesController::class, 'TrackChargeCycle']);
	$group->get('/batteriesjournal', [BatteriesController::class, 'Journal']);
	$group->get('/batteries', [BatteriesController::class, 'BatteriesList']);
	$group->get('/battery/{batteryId}', [BatteriesController::class, 'BatteryEditForm']);
	$group->get('/batteriessettings', [BatteriesController::class, 'BatteriesSettings']);
	$group->get('/battery/{batteryId}/grocycode', [BatteriesController::class, 'BatteryGrocycodeImage']);

	// Task routes
	$group->get('/tasks', [TasksController::class, 'Overview']);
	$group->get('/task/{taskId}', [TasksController::class, 'TaskEditForm']);
	$group->get('/taskcategories', [TasksController::class, 'TaskCategoriesList']);
	$group->get('/taskcategory/{categoryId}', [TasksController::class, 'TaskCategoryEditForm']);
	$group->get('/taskssettings', [TasksController::class, 'TasksSettings']);

	// Equipment routes
	$group->get('/equipment', [EquipmentController::class, 'Overview']);
	$group->get('/equipment/{equipmentId}', [EquipmentController::class, 'EditForm']);

	// Calendar routes
	$group->get('/calendar', [CalendarController::class, 'Overview']);

	// OpenAPI routes
	$group->get('/api', [OpenApiController::class, 'DocumentationUi']);
	$group->get('/manageapikeys', [OpenApiController::class, 'ApiKeysList']);
	// POST rather than GET, and for a much better reason than tidiness: as a GET this
	// creates an API key with an attacker-chosen description on any page load. Sweep
	// finding S8.
	$group->post('/manageapikeys/new', [OpenApiController::class, 'CreateNewApiKey']);
});

$app->group('/api', function (RouteCollectorProxy $group)
{
	// OpenAPI
	$group->get('/openapi/specification', [OpenApiController::class, 'DocumentationSpec']);

	// System
	$group->get('/system/info', [SystemApiController::class, 'GetSystemInfo']);
	$group->get('/system/time', [SystemApiController::class, 'GetSystemTime']);
	$group->get('/system/db-changed-time', [SystemApiController::class, 'GetDbChangedTime']);
	$group->get('/system/config', [SystemApiController::class, 'GetConfig']);
	$group->post('/system/log-missing-localization', [SystemApiController::class, 'LogMissingLocalization']);
	$group->get('/system/localization-strings', [SystemApiController::class, 'GetLocalizationStrings']);

	// Generic entity interaction
	$group->get('/objects/{entity}', [GenericEntityApiController::class, 'GetObjects']);
	$group->get('/objects/{entity}/{objectId}', [GenericEntityApiController::class, 'GetObject']);
	$group->post('/objects/{entity}', [GenericEntityApiController::class, 'AddObject']);
	$group->put('/objects/{entity}/{objectId}', [GenericEntityApiController::class, 'EditObject']);
	$group->delete('/objects/{entity}/{objectId}', [GenericEntityApiController::class, 'DeleteObject']);
	$group->get('/userfields/{entity}/{objectId}', [GenericEntityApiController::class, 'GetUserfields']);
	$group->put('/userfields/{entity}/{objectId}', [GenericEntityApiController::class, 'SetUserfields']);

	// Files
	$group->put('/files/{group}/{fileName}', [FilesApiController::class, 'UploadFile']);
	$group->get('/files/{group}/{fileName}', [FilesApiController::class, 'ServeFile']);
	$group->delete('/files/{group}/{fileName}', [FilesApiController::class, 'DeleteFile']);

	// Users
	$group->get('/users', [UsersApiController::class, 'GetUsers']);
	$group->post('/users', [UsersApiController::class, 'CreateUser']);
	$group->put('/users/{userId}', [UsersApiController::class, 'EditUser']);
	$group->delete('/users/{userId}', [UsersApiController::class, 'DeleteUser']);
	$group->get('/users/{userId}/permissions', [UsersApiController::class, 'ListPermissions']);
	$group->post('/users/{userId}/permissions', [UsersApiController::class, 'AddPermission']);
	$group->put('/users/{userId}/permissions', [UsersApiController::class, 'SetPermissions']);

	// Role bundles
	$group->get('/roles', [RolesApiController::class, 'ListRoles']);
	$group->post('/roles', [RolesApiController::class, 'CreateRole']);
	$group->put('/roles/{roleId}', [RolesApiController::class, 'EditRole']);
	$group->delete('/roles/{roleId}', [RolesApiController::class, 'DeleteRole']);
	$group->get('/roles/{roleId}/permissions', [RolesApiController::class, 'ListPermissions']);
	$group->put('/roles/{roleId}/permissions', [RolesApiController::class, 'SetPermissions']);
	$group->get('/users/{userId}/roles', [RolesApiController::class, 'ListUserRoles']);
	$group->put('/users/{userId}/roles', [RolesApiController::class, 'SetUserRoles']);

	// User
	$group->get('/user', [UsersApiController::class, 'CurrentUser']);
	$group->get('/user/settings', [UsersApiController::class, 'GetUserSettings']);
	$group->get('/user/settings/{settingKey}', [UsersApiController::class, 'GetUserSetting']);
	$group->put('/user/settings/{settingKey}', [UsersApiController::class, 'SetUserSetting']);
	$group->delete('/user/settings/{settingKey}', [UsersApiController::class, 'DeleteUserSetting']);

	// Stock
	$group->get('/stock', [StockApiController::class, 'CurrentStock']);
	$group->get('/stock/entry/{entryId}', [StockApiController::class, 'StockEntry']);
	$group->put('/stock/entry/{entryId}', [StockApiController::class, 'EditStockEntry']);
	$group->get('/stock/volatile', [StockApiController::class, 'CurrentVolatileStock']);
	$group->get('/stock/products/{productId}', [StockApiController::class, 'ProductDetails']);
	$group->get('/stock/products/{productId}/entries', [StockApiController::class, 'ProductStockEntries']);
	$group->get('/stock/products/{productId}/locations', [StockApiController::class, 'ProductStockLocations']);
	$group->get('/stock/products/{productId}/price-history', [StockApiController::class, 'ProductPriceHistory']);
	$group->post('/stock/products/{productId}/add', [StockApiController::class, 'AddProduct']);
	$group->post('/stock/products/{productId}/consume', [StockApiController::class, 'ConsumeProduct']);
	$group->post('/stock/products/{productId}/transfer', [StockApiController::class, 'TransferProduct']);
	$group->post('/stock/products/{productId}/inventory', [StockApiController::class, 'InventoryProduct']);
	$group->post('/stock/products/{productId}/open', [StockApiController::class, 'OpenProduct']);
	$group->post('/stock/products/{productIdToKeep}/merge/{productIdToRemove}', [StockApiController::class, 'MergeProducts']);
	$group->get('/stock/products/by-barcode/{barcode}', [StockApiController::class, 'ProductDetailsByBarcode']);
	$group->post('/stock/products/by-barcode/{barcode}/add', [StockApiController::class, 'AddProductByBarcode']);
	$group->post('/stock/products/by-barcode/{barcode}/consume', [StockApiController::class, 'ConsumeProductByBarcode']);
	$group->post('/stock/products/by-barcode/{barcode}/transfer', [StockApiController::class, 'TransferProductByBarcode']);
	$group->post('/stock/products/by-barcode/{barcode}/inventory', [StockApiController::class, 'InventoryProductByBarcode']);
	$group->post('/stock/products/by-barcode/{barcode}/open', [StockApiController::class, 'OpenProductByBarcode']);
	$group->get('/stock/locations/{locationId}/entries', [StockApiController::class, 'LocationStockEntries']);
	$group->get('/stock/bookings/{bookingId}', [StockApiController::class, 'StockBooking']);
	$group->post('/stock/bookings/{bookingId}/undo', [StockApiController::class, 'UndoBooking']);
	$group->get('/stock/transactions/{transactionId}', [StockApiController::class, 'StockTransactions']);
	$group->post('/stock/transactions/{transactionId}/undo', [StockApiController::class, 'UndoTransaction']);
	$group->get('/stock/barcodes/external-lookup/{barcode}', [StockApiController::class, 'ExternalBarcodeLookup']);
	$group->get('/stock/products/{productId}/printlabel', [StockApiController::class, 'ProductPrintLabel']);
	$group->get('/stock/entry/{entryId}/printlabel', [StockApiController::class, 'StockEntryPrintLabel']);

	// Shopping list
	$group->post('/stock/shoppinglist/add-missing-products', [StockApiController::class, 'AddMissingProductsToShoppingList']);
	$group->post('/stock/shoppinglist/add-overdue-products', [StockApiController::class, 'AddOverdueProductsToShoppingList']);
	$group->post('/stock/shoppinglist/add-expired-products', [StockApiController::class, 'AddExpiredProductsToShoppingList']);
	$group->post('/stock/shoppinglist/clear', [StockApiController::class, 'ClearShoppingList']);
	$group->post('/stock/shoppinglist/add-product', [StockApiController::class, 'AddProductToShoppingList']);
	$group->post('/stock/shoppinglist/remove-product', [StockApiController::class, 'RemoveProductFromShoppingList']);

	// Recipes
	$group->post('/recipes/{recipeId}/add-not-fulfilled-products-to-shoppinglist', [RecipesApiController::class, 'AddNotFulfilledProductsToShoppingList']);
	$group->get('/recipes/{recipeId}/fulfillment', [RecipesApiController::class, 'GetRecipeFulfillment']);
	$group->post('/recipes/{recipeId}/consume', [RecipesApiController::class, 'ConsumeRecipe']);
	$group->get('/recipes/fulfillment', [RecipesApiController::class, 'GetRecipeFulfillment']);
	$group->Post('/recipes/{recipeId}/copy', [RecipesApiController::class, 'CopyRecipe']);
	$group->get('/recipes/{recipeId}/printlabel', [RecipesApiController::class, 'RecipePrintLabel']);


	// Chores
	$group->get('/chores', [ChoresApiController::class, 'Current']);
	$group->get('/chores/{choreId}', [ChoresApiController::class, 'ChoreDetails']);
	$group->post('/chores/{choreId}/execute', [ChoresApiController::class, 'TrackChoreExecution']);
	$group->post('/chores/executions/{executionId}/undo', [ChoresApiController::class, 'UndoChoreExecution']);
	$group->post('/chores/executions/calculate-next-assignments', [ChoresApiController::class, 'CalculateNextExecutionAssignments']);
	$group->get('/chores/{choreId}/printlabel', [ChoresApiController::class, 'ChorePrintLabel']);
	$group->post('/chores/{choreIdToKeep}/merge/{choreIdToRemove}', [ChoresApiController::class, 'MergeChores']);

	// Printing
	$group->get('/print/shoppinglist/thermal', [PrintApiController::class, 'PrintShoppingListThermal']);

	// Batteries
	$group->get('/batteries', [BatteriesApiController::class, 'Current']);
	$group->get('/batteries/{batteryId}', [BatteriesApiController::class, 'BatteryDetails']);
	$group->post('/batteries/{batteryId}/charge', [BatteriesApiController::class, 'TrackChargeCycle']);
	$group->post('/batteries/charge-cycles/{chargeCycleId}/undo', [BatteriesApiController::class, 'UndoChargeCycle']);
	$group->get('/batteries/{batteryId}/printlabel', [BatteriesApiController::class, 'BatteryPrintLabel']);

	// Tasks
	$group->get('/tasks', [TasksApiController::class, 'Current']);
	$group->post('/tasks/{taskId}/complete', [TasksApiController::class, 'MarkTaskAsCompleted']);
	$group->post('/tasks/{taskId}/undo', [TasksApiController::class, 'UndoTask']);

	// Calendar
	$group->get('/calendar/ical', [CalendarApiController::class, 'Ical'])->setName('calendar-ical');
	$group->get('/calendar/ical/sharing-link', [CalendarApiController::class, 'IcalSharingLink']);
// CorsMiddleware and JsonMiddleware used to be added here too. They are now application
// level (app.php), outside authentication, which is the only place they can treat a 401
// and an OPTIONS preflight like every other API response. PathParameterMiddleware stays
// on the group - it needs the matched route - and is still wrapped by both, since
// application-level middleware is outside a group's.
//
// The catch-all `$app->any('/api/{routes:.+}', ...)` that used to follow this group is
// gone with them. It existed for CORS preflights, which the application-level middleware
// now answers; it was also, because it was `any` rather than `options`, what answered
// every unmatched /api/* request with an empty 200. Those are now a 404 from Slim through
// ExceptionController, which is a deliberate behaviour change rather than a side effect:
// two code paths adding the same headers was the worse of the two options. Plan 11.
})->add(new PathParameterMiddleware($container, $app->getResponseFactory()));
