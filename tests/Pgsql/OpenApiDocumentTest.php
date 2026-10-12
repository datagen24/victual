<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Response;
use Victual\Controllers\Api\GenericEntityApiController;
use Victual\Controllers\Api\OpenApiController;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * The document `GET /api/openapi/specification` serves, as opposed to the committed
 * victual.openapi.json it is built from (issue #799).
 *
 * OpenApiController::DocumentationSpec() rewrites the committed document on every request:
 * it stamps the installed version, the instance's server URL and the key-management URL,
 * and derives five `ExposedEntity_*` enums by cloning `StringEnumTemplate`. The template's
 * enum is `[""]` - a placeholder so the committed document is a valid schema on its own -
 * so a derivation that does not empty it advertises "" as an entity the API does not
 * accept. ContractTest and WireContractTest compare the served routes and schemas with the
 * committed ones; neither asserted what the served enums contain, so the stray "" shipped.
 *
 * The controller is called directly (GenericQueryTest's pattern) so the test sees the
 * decoded body exactly as the route writes it. Request handling does not read the served
 * document: GenericEntityApiController decodes victual.openapi.json for itself, which
 * testRequestHandlingReadsTheCommittedVocabulariesNotTheServedOnes() pins.
 */
class OpenApiDocumentTest extends PgsqlSchemaTestCase
{
	private const USER_ENTITY = 'userentity-opendoc_gadgets';

	private static \DI\Container $container;
	private static OpenApiController $controller;

	/** @var array<string,mixed> victual.openapi.json, decoded */
	private static array $committed;

	/** @var array<string,mixed> what the route serves */
	private static array $served;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		// Without a user entity ExposedEntity_IncludingUserEntities and its derivation would
		// be the committed list plus 'users', and a derivation that dropped the userentities
		// query altogether would still pass.
		self::Pdo()->exec("INSERT INTO userentities (id, name, caption, description) VALUES (9700, 'opendoc_gadgets', 'Gadgets', 'a user entity for the served enums')");

		self::$container = $container = new \DI\Container();
		$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		$container->set('UrlManager', new \Victual\Helpers\UrlManager('https://victual.example'));
		self::$controller = new OpenApiController($container);

		self::$committed = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true, flags: JSON_THROW_ON_ERROR);

		$request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', 'https://victual.example/api/openapi/specification');
		$response = self::$controller->DocumentationSpec($request, new Response(), []);
		self::assertSame(200, $response->getStatusCode());
		self::$served = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
	}

	private static function committedEnum(string $schema): array
	{
		return self::$committed['components']['schemas'][$schema]['enum'];
	}

	private static function servedEnum(string $schema): array
	{
		return self::$served['components']['schemas'][$schema]['enum'];
	}

	/** The five derived schemas. */
	public static function DerivedEnums(): array
	{
		return [
			'IncludingUserEntities' => ['ExposedEntity_IncludingUserEntities'],
			'NotIncludingNotEditable' => ['ExposedEntity_NotIncludingNotEditable'],
			'IncludingUserEntities_NotIncludingNotEditable' => ['ExposedEntity_IncludingUserEntities_NotIncludingNotEditable'],
			'NotIncludingNotDeletable' => ['ExposedEntity_NotIncludingNotDeletable'],
			'NotIncludingNotListable' => ['ExposedEntity_NotIncludingNotListable']
		];
	}

	#[DataProvider('DerivedEnums')]
	public function testNoServedEntityEnumAdvertisesTheEmptyString(string $schema): void
	{
		self::assertNotContains('', self::servedEnum($schema), "$schema advertises \"\" as an entity the API does not accept");
		self::assertSame('string', self::$served['components']['schemas'][$schema]['type'], "$schema is not the template's shape");
	}

	#[DataProvider('DerivedEnums')]
	public function testNoServedEntityEnumRepeatsAMember(string $schema): void
	{
		$enum = self::servedEnum($schema);
		self::assertSame($enum, array_values(array_unique($enum)), "$schema lists a member twice");
	}

	public function testEachVariantIsExactlyItsDerivedSet(): void
	{
		$exposed = self::committedEnum('ExposedEntity');
		$withUsers = array_merge($exposed, ['users', self::USER_ENTITY]);
		$minus = fn (array $set, string $excluded) => array_values(array_diff($set, self::committedEnum($excluded)));
		$sorted = function (array $set) {
			sort($set);
			return $set;
		};

		self::assertContains(self::USER_ENTITY, self::servedEnum('ExposedEntity_IncludingUserEntities'), 'the fixture user entity is not served, so the user-entity cases below prove nothing');

		self::assertSame($sorted($withUsers), self::servedEnum('ExposedEntity_IncludingUserEntities'));
		self::assertSame($sorted($minus($exposed, 'ExposedEntityNoEdit')), self::servedEnum('ExposedEntity_NotIncludingNotEditable'));
		self::assertSame($sorted($minus($exposed, 'ExposedEntityNoDelete')), self::servedEnum('ExposedEntity_NotIncludingNotDeletable'));
		self::assertSame($sorted($minus($exposed, 'ExposedEntityNoListing')), self::servedEnum('ExposedEntity_NotIncludingNotListable'));

		// Stock entries are not editable, but their userfields are - the one hard-coded addition.
		self::assertSame(
			$sorted(array_merge($minus($withUsers, 'ExposedEntityNoEdit'), ['stock'])),
			self::servedEnum('ExposedEntity_IncludingUserEntities_NotIncludingNotEditable')
		);
	}

	public function testTheCommittedTemplateAndExclusionListsAreServedUnchanged(): void
	{
		foreach (['StringEnumTemplate', 'ExposedEntity', 'ExposedEntityNoEdit', 'ExposedEntityNoDelete', 'ExposedEntityNoListing', 'ExposedEntityEditRequiresAdmin'] as $schema)
		{
			self::assertSame(self::committedEnum($schema), self::servedEnum($schema), "$schema is not derived, so it must be served as committed");
		}
	}

	public function testTheInstallationMetadataIsStamped(): void
	{
		$installed = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/version.json'), true, flags: JSON_THROW_ON_ERROR);

		self::assertSame($installed['Version'], self::$served['info']['version']);
		self::assertStringEndsWith('/api', self::$served['servers'][0]['url']);
		self::assertStringContainsString('/manageapikeys', self::$served['info']['description']);
		self::assertStringNotContainsString('PlaceHolderManageApiKeysUrl', self::$served['info']['description']);
	}

	public function testRequestHandlingReadsTheCommittedVocabulariesNotTheServedOnes(): void
	{
		// If handling read the served document, emptying the derived enums would be a
		// behaviour change rather than a documentation fix. It reads its own copy of the file.
		$generic = new GenericEntityApiController(self::$container);
		$read = new \ReflectionMethod($generic, 'GetOpenApispec');
		$spec = $read->invoke($generic);

		self::assertSame(self::committedEnum('ExposedEntity'), $spec->components->schemas->ExposedEntity->enum);
		// The committed derived schemas carry no enum at all; only the served copy does.
		self::assertObjectNotHasProperty('enum', $spec->components->schemas->ExposedEntity_IncludingUserEntities);
	}
}
