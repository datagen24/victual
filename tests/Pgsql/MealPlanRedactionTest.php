<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\RecipesController;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * GET /mealplan (RecipesController::MealPlan), rendered for a caller who may see the
 * price-bearing fields and for one who may not.
 *
 * The page serialises recipes_resolved into Victual.RecipesResolved for mealplan.js, so
 * that array leaves the server verbatim and is the one place a view datum is redacted
 * by FieldPolicy before rendering rather than at the API boundary (issue #176 item 4).
 * It answered HTTP 500 - FieldPolicy::RedactRows() takes rows and was handed a LessQL
 * Result - and no test rendered the page, which is how that got past both the redaction
 * work and the response-contract snapshot (that one calls API controllers only).
 *
 * The redacted field list is read from the live permission_fields table, as
 * ContractTest's redaction leg does, rather than hand-maintained here: a household that
 * widens the policy widens what this asserts. The restricted identity is the CHILD role,
 * which holds MEALPLAN_VIEW but not STOCK_PRICES_VIEW.
 */
class MealPlanRedactionTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static RecipesController $controller;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		$container = new \DI\Container();
		$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$controller = new RecipesController($container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'mealplan-caller', 'fixture')");

		// One recipe on today's meal plan: the meal_plan triggers give it the internal
		// recipe and week recipe MealPlan() selects recipes_resolved rows for.
		self::$db->exec("INSERT INTO recipes(name) VALUES ('Mealplan redaction stew')");
		$recipeId = (int)self::$db->query("SELECT id FROM recipes WHERE name = 'Mealplan redaction stew'")->fetchColumn();
		self::$db->exec("INSERT INTO meal_plan(day, type, recipe_id, recipe_servings) VALUES (CURRENT_DATE, 'recipe', $recipeId, 1)");
	}

	private static function grant(string $roleCode): void
	{
		$stmt = self::$db->prepare('SELECT id FROM roles WHERE code = ?');
		$stmt->execute([$roleCode]);
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');
		self::$db->exec('INSERT INTO user_roles(user_id, role_id) VALUES (9000, ' . (int)$stmt->fetchColumn() . ')');
	}

	/**
	 * Renders GET /mealplan and returns the Victual.RecipesResolved rows the page hands
	 * mealplan.js, decoded from the served HTML rather than taken from the controller's
	 * internals - so this is what a browser actually receives.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function renderedRecipesResolved(): array
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/mealplan');
		$response = self::$controller->MealPlan($request, new Response(), []);

		self::assertSame(200, $response->getStatusCode(), 'GET /mealplan renders');

		$html = (string)$response->getBody();
		self::assertSame(1, preg_match('/Victual\.RecipesResolved = (.*);\R/', $html, $matches), 'The page embeds Victual.RecipesResolved');

		$rows = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($rows);
		self::assertTrue(array_is_list($rows), 'mealplan.js walks Victual.RecipesResolved by index, so it has to serialise as a list');

		return $rows;
	}

	/** @return string[] The recipes_resolved fields permission_fields gates behind a permission. */
	private static function gatedFields(): array
	{
		$fields = self::$db->query("SELECT field FROM permission_fields WHERE entity = 'recipes_resolved' AND field <> '*'")->fetchAll(PDO::FETCH_COLUMN);
		self::assertNotEmpty($fields, 'permission_fields gates something on recipes_resolved, or this test asserts nothing');

		return $fields;
	}

	public function testMayViewPricesSeesTheGatedFields(): void
	{
		self::grant('ADMIN');
		$rows = self::renderedRecipesResolved();

		self::assertNotEmpty($rows, 'The fixture meal plan entry resolves to recipes_resolved rows');
		foreach ($rows as $row)
		{
			foreach (self::gatedFields() as $field)
			{
				self::assertArrayHasKey($field, $row, "ADMIN holds STOCK_PRICES_VIEW, so recipes_resolved.$field reaches the view");
			}
		}
	}

	public function testMayNotViewPricesDoesNotReceiveTheGatedFields(): void
	{
		self::grant('CHILD');
		$rows = self::renderedRecipesResolved();

		self::assertNotEmpty($rows, 'CHILD still gets the rows - only the gated fields are removed');
		foreach ($rows as $row)
		{
			self::assertArrayHasKey('recipe_id', $row, 'An ungated field survives redaction');
			foreach (self::gatedFields() as $field)
			{
				self::assertArrayNotHasKey($field, $row, "CHILD lacks STOCK_PRICES_VIEW, so recipes_resolved.$field must not be serialised into the page");
			}
		}
	}
}
