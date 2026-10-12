<?php

namespace Victual\Tests\Pgsql;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Issue #800 (OpenAPI audit #796): every `example` and `examples` value in
 * victual.openapi.json must be valid against the schema it is attached to.
 *
 * An example that fails its own schema teaches a generated client, a mock server and a
 * reader the wrong wire format. Before this test, 17 examples carried stringly-typed ids
 * and booleans, pre-ADR-0027 timestamps and a null where an object is required, and
 * nothing could see it because examples are never asserted against anything.
 *
 * The walker visits the whole document, not a list of known locations, so a new example
 * anywhere - a component schema, an inline request or response schema, a Parameter,
 * Header or MediaType, an Example Object - is checked the day it is added. What it cannot
 * place it refuses (see collect()) rather than skipping, and the exact count below stops
 * the walker from silently shrinking.
 *
 * Validation is Opis with the whole document as the base: each example is validated
 * against a `$ref` to the schema's own JSON pointer, so `$ref`, `allOf` and `oneOf`
 * resolve as a client generator would resolve them. Defaults are disabled so validation
 * cannot rewrite the example.
 */
class OpenApiExamplesTest extends TestCase
{
	/**
	 * How many example values the document carries today: 43 Schema Object examples (the
	 * 17 issue 800 corrected, 23 that were already valid, and the three on the schemas of
	 * GET /files/{group}/{fileName}'s 200 response headers that issue 806 added) and no
	 * Parameter, Header, MediaType or Example Object examples yet. Adding an example raises
	 * this number on purpose; a walker that quietly stops finding some lowers it and fails
	 * here.
	 */
	private const EXPECTED_EXAMPLE_COUNT = 43;

	private const DOCUMENT_ID = 'https://victual.invalid/victual.openapi.json';

	/**
	 * Keys whose value is a map of arbitrary names to objects. The names are data, not
	 * keywords, so a schema property that happens to be called `example` is not an
	 * example.
	 */
	private const NAME_MAPS = [
		'properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas',
		'schemas', 'paths', 'responses', 'parameters', 'headers', 'requestBodies', 'content',
		'links', 'callbacks', 'securitySchemes', 'pathItems', 'mapping', 'variables',
		'webhooks', 'encoding', 'scopes',
	];

	/** Keys whose value is instance data. Never descended into: it may contain a key called `example`. */
	private const DATA_KEYS = ['example', 'examples', 'default', 'enum', 'const'];

	/**
	 * Example pointer => keys the example may carry although the schema does not declare
	 * them. Deliberately empty: a schema that really allows extra keys says so with
	 * `additionalProperties`, and an example should not rely on keys nobody documented.
	 */
	private const ALLOWED_UNDECLARED_KEYS = [];

	public function testEveryExampleInTheDocumentIsValidAgainstItsSchema(): void
	{
		[$checked, $failures] = self::validateExamples(self::loadDocument());

		self::assertSame(
			[],
			$failures,
			count($failures) . " example(s) fail their schema:\n" . implode("\n", $failures)
		);
		self::assertSame(self::EXPECTED_EXAMPLE_COUNT, $checked, 'the walker found a different number of examples than the document is known to hold');
	}

	public function testTheWalkerReportsAnInvalidExample(): void
	{
		$document = self::loadDocument();
		// The negative control: the pre-fix Product example sent the id as a string.
		$document->components->schemas->Product->example->id = '1';

		[$checked, $failures] = self::validateExamples($document);

		self::assertSame(self::EXPECTED_EXAMPLE_COUNT, $checked);
		self::assertCount(1, $failures, implode("\n", $failures));
		self::assertStringStartsWith('/components/schemas/Product/example:', $failures[0]);
		self::assertStringContainsString('/id', $failures[0]);
	}

	public function testDateTimeFormatIsEnforcedNotJustThePattern(): void
	{
		// Matches the property's `pattern` exactly (six fractional digits and `Z`) but is not
		// a date, so only Opis enforcing `format: date-time` can reject it.
		$document = self::loadDocument();
		$document->components->schemas->Product->example->row_created_timestamp = '2019-13-45T00:00:00.000000Z';

		[, $failures] = self::validateExamples($document);

		self::assertCount(1, $failures, implode("\n", $failures));
		self::assertStringContainsString('/row_created_timestamp', $failures[0]);
		self::assertStringContainsString("must match the 'date-time' format", $failures[0]);
	}

	public function testDateTimePatternIsEnforced(): void
	{
		$document = self::loadDocument();
		$document->components->schemas->Product->example->row_created_timestamp = '2019-05-02 20:12:26';

		[, $failures] = self::validateExamples($document);

		self::assertCount(1, $failures, implode("\n", $failures));
		self::assertStringContainsString('/row_created_timestamp', $failures[0]);
	}

	public function testAnExampleKeyTheSchemaDoesNotDeclareIsReported(): void
	{
		$document = self::loadDocument();
		$document->components->schemas->StockLocation->example->name = 'Fridge';

		[, $failures] = self::validateExamples($document);

		self::assertCount(1, $failures, implode("\n", $failures));
		self::assertStringStartsWith('/components/schemas/StockLocation/example:', $failures[0]);
		self::assertStringContainsString('/name is not declared', $failures[0]);
	}

	/**
	 * The document holds no Parameter, Header, MediaType, Example Object or allOf example
	 * today, so the count of 43 does not exercise those branches. This synthetic document
	 * carries one of each: valid, it must yield four examples and no failure; with every
	 * value broken, each location must be reported by its own pointer.
	 */
	public function testWalkerReachesHeadersMediaTypesExampleObjectsAndAllOf(): void
	{
		[$checked, $failures] = self::validateExamples(self::syntheticDocument(false));
		self::assertSame(4, $checked);
		self::assertSame([], $failures);

		[$checked, $failures] = self::validateExamples(self::syntheticDocument(true));
		self::assertSame(4, $checked);
		$expected = [
			'/paths/~1things/get/responses/200/headers/X-Total/example:' => '/ The data (string) must match the type: integer',
			'/paths/~1things/get/responses/200/content/application~1json/example:' => '/id The data (string) must match the type: integer',
			'/paths/~1things/get/responses/200/content/application~1json/examples/one/value:' => '/id The data (string) must match the type: integer',
			'/components/schemas/Both/example:' => '/id The data (string) must match the type: integer',
		];
		foreach ($expected as $prefix => $detail)
		{
			$line = array_values(array_filter($failures, static fn (string $f) => str_starts_with($f, $prefix)));
			self::assertCount(1, $line, "$prefix not reported in:\n" . implode("\n", $failures));
			self::assertStringContainsString($detail, $line[0]);
		}
		// The allOf member's key set is the union of both members, so `extra` is the only stray.
		self::assertStringContainsString('/extra is not declared', implode("\n", $failures));
		self::assertStringNotContainsString('/name is not declared', implode("\n", $failures));
		self::assertCount(4, $failures);
	}

	private static function syntheticDocument(bool $broken): object
	{
		$id = $broken ? '"1"' : '1';
		$both = $broken ? '{"id":"1","name":"x","extra":true}' : '{"id":1,"name":"x"}';

		return json_decode('{
			"openapi": "3.1.0",
			"paths": {"/things": {"get": {"responses": {"200": {
				"description": "ok",
				"headers": {"X-Total": {"schema": {"type": "integer"}, "example": ' . $id . '}},
				"content": {"application/json": {
					"schema": {"$ref": "#/components/schemas/Thing"},
					"example": {"id": ' . $id . '},
					"examples": {"one": {"value": {"id": ' . $id . '}}}
				}}
			}}}}},
			"components": {"schemas": {
				"Thing": {"type": "object", "properties": {"id": {"type": "integer"}}},
				"Both": {
					"allOf": [{"$ref": "#/components/schemas/Thing"}, {"type": "object", "properties": {"name": {"type": "string"}}}],
					"example": ' . $both . '
				}
			}}
		}', false, flags: JSON_THROW_ON_ERROR);
	}

	/**
	 * Resolves a local `$ref` chain. A pointer token is percent-decoded and then
	 * un-escaped, the reverse of escape().
	 */
	private static function resolve(object $document, object $schema): object
	{
		for ($hops = 0; isset($schema->{'$ref'}) && $hops < 16; $hops++)
		{
			$schema = self::pointerGet($document, substr($schema->{'$ref'}, 1));
		}

		return $schema;
	}

	private static function pointerGet(object $document, string $pointer): object
	{
		$node = $document;
		foreach (array_filter(explode('/', $pointer), static fn (string $t) => $t !== '') as $token)
		{
			$token = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($token));
			$node = is_array($node) ? $node[(int)$token] : $node->{$token};
		}

		return $node;
	}

	/** Every member schema a value may be validated against: the schema itself plus its allOf, anyOf and oneOf members. */
	private static function members(object $document, object $schema, int $depth = 0): array
	{
		$schema = self::resolve($document, $schema);
		$all = [$schema];
		if ($depth < 8)
		{
			foreach (['allOf', 'anyOf', 'oneOf'] as $keyword)
			{
				foreach ($schema->{$keyword} ?? [] as $member)
				{
					$all = array_merge($all, self::members($document, $member, $depth + 1));
				}
			}
		}

		return $all;
	}

	/**
	 * The property names a schema declares, across its allOf/anyOf/oneOf members, or null
	 * when it cannot say: it declares no properties at all (a free-form object), or it
	 * says extra keys are welcome with an explicit `additionalProperties` that is not false.
	 */
	private static function declaredKeys(object $document, object $schema): ?array
	{
		$names = [];
		foreach (self::members($document, $schema) as $member)
		{
			if (isset($member->additionalProperties) && $member->additionalProperties !== false)
			{
				return null;
			}
			foreach (array_keys(get_object_vars($member->properties ?? new \stdClass())) as $name)
			{
				$names[$name] = true;
			}
		}

		return $names === [] ? null : array_keys($names);
	}

	/**
	 * Appends one line for every key in $value (recursively, following the declared
	 * property and item schemas) that the schema does not declare. An undeclared key is a
	 * field the wire does not have, or one the document forgot to describe; either way a
	 * reader is misled, and Opis cannot say so because object schemas are open by default.
	 */
	private static function undeclaredKeys(object $document, object $schema, mixed $value, string $path, string $pointer, array &$into, int $depth = 0): void
	{
		if ($depth > 24)
		{
			return;
		}
		if (is_array($value))
		{
			foreach ($value as $index => $item)
			{
				foreach (self::members($document, $schema) as $member)
				{
					if (isset($member->items) && is_object($member->items))
					{
						self::undeclaredKeys($document, $member->items, $item, "$path/$index", $pointer, $into, $depth + 1);
					}
				}
			}

			return;
		}
		if (!is_object($value))
		{
			return;
		}

		$declared = self::declaredKeys($document, $schema);
		foreach ($value as $key => $child)
		{
			if ($declared !== null && !in_array($key, $declared, true) && !in_array($key, self::ALLOWED_UNDECLARED_KEYS[$pointer] ?? [], true))
			{
				$into[] = "$path/$key is not declared by the schema";
				continue;
			}
			foreach (self::members($document, $schema) as $member)
			{
				if (isset($member->properties->{$key}) && is_object($member->properties->{$key}))
				{
					self::undeclaredKeys($document, $member->properties->{$key}, $child, "$path/$key", $pointer, $into, $depth + 1);
				}
			}
		}
	}

	private static function loadDocument(): object
	{
		return json_decode(file_get_contents(dirname(__DIR__, 2) . '/victual.openapi.json'), false, flags: JSON_THROW_ON_ERROR);
	}

	/**
	 * @return array{0:int,1:list<string>} the number of examples checked, and one line per failure
	 */
	private static function validateExamples(object $document): array
	{
		$found = [];
		self::collect($document, '', $found);

		$failures = [];
		foreach ($found as [$examplePointer, $value, $schemaPointer])
		{
			// A fresh validator and a fresh copy of the base per example: Opis is not
			// guaranteed to leave a reused schema object untouched between validations.
			$validator = new Validator();
			$validator->parser()->setOption('allowDefaults', false);
			$validator->setMaxErrors(PHP_INT_MAX);
			$validator->setStopAtFirstError(false);

			$base = json_decode(json_encode($document));
			$base->{'$id'} = self::DOCUMENT_ID;
			$base->{'$ref'} = '#' . $schemaPointer;

			$result = $validator->validate(json_decode(json_encode($value)), $base);
			$leaves = [];
			if (!$result->isValid())
			{
				self::leafErrors($result->error(), $leaves);
			}
			self::undeclaredKeys($document, self::pointerGet($document, $schemaPointer), $value, '', $examplePointer, $leaves);
			if ($leaves !== [])
			{
				$failures[] = $examplePointer . ': ' . implode('; ', array_unique($leaves));
			}
		}

		return [count($found), $failures];
	}

	private static function leafErrors(ValidationError $error, array &$into): void
	{
		$subs = $error->subErrors();
		if ($subs === [])
		{
			$args = $error->args();
			$message = preg_replace_callback(
				'/\{(\w+)\}/',
				static fn (array $m) => array_key_exists($m[1], $args) ? (is_scalar($args[$m[1]]) ? (string)$args[$m[1]] : json_encode($args[$m[1]])) : $m[0],
				$error->message()
			);
			$into[] = '/' . implode('/', $error->data()->fullPath()) . ' ' . $message;

			return;
		}
		foreach ($subs as $sub)
		{
			self::leafErrors($sub, $into);
		}
	}

	/**
	 * Collects [pointer of the example value, the value, pointer of the schema it must
	 * satisfy] for every example under $node.
	 *
	 * A node with a `schema` is a Parameter, Header or MediaType: its `example`, and the
	 * `value` of each of its Example Objects, are validated against that schema. Any other
	 * node is a Schema Object, and its `example` and its `examples` array are validated
	 * against the node itself.
	 */
	private static function collect(mixed $node, string $pointer, array &$found): void
	{
		if (is_array($node))
		{
			foreach ($node as $index => $child)
			{
				self::collect($child, $pointer . '/' . $index, $found);
			}

			return;
		}
		if (!is_object($node))
		{
			return;
		}

		if (isset($node->schema) && is_object($node->schema))
		{
			$schemaPointer = $pointer . '/schema';
			if (property_exists($node, 'example'))
			{
				$found[] = [$pointer . '/example', $node->example, $schemaPointer];
			}
			if (property_exists($node, 'examples'))
			{
				self::assertTrue(is_object($node->examples), "$pointer/examples must be a map of Example Objects");
				foreach ($node->examples as $name => $example)
				{
					$at = $pointer . '/examples/' . self::escape((string)$name);
					// `externalValue` and the serialised forms are not JSON instances to validate;
					// refuse them here so one added later is a conscious decision.
					self::assertTrue(is_object($example) && property_exists($example, 'value'), "$at carries no inline `value`, which this test cannot validate");
					$found[] = [$at . '/value', $example->value, $schemaPointer];
				}
			}
		}
		else
		{
			if (property_exists($node, 'example'))
			{
				$found[] = [$pointer . '/example', $node->example, $pointer];
			}
			if (property_exists($node, 'examples'))
			{
				self::assertTrue(is_array($node->examples), "$pointer/examples is not a Schema Object's array and has no sibling schema, so this test cannot place it");
				foreach ($node->examples as $index => $example)
				{
					$found[] = [$pointer . '/examples/' . $index, $example, $pointer];
				}
			}
		}

		foreach (get_object_vars($node) as $key => $child)
		{
			if (in_array($key, self::DATA_KEYS, true))
			{
				continue;
			}
			if (in_array($key, self::NAME_MAPS, true) && is_object($child))
			{
				foreach ($child as $name => $member)
				{
					self::collect($member, $pointer . '/' . self::escape($key) . '/' . self::escape((string)$name), $found);
				}
				continue;
			}
			self::collect($child, $pointer . '/' . self::escape($key), $found);
		}
	}

	/** RFC 6901 escaping, then percent-encoding so the pointer is a legal URI fragment. */
	private static function escape(string $token): string
	{
		return rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $token));
	}
}
