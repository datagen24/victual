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
	 * How many example values the document carries today: 40 Schema Object examples (the
	 * 17 this issue corrected and 23 that were already valid) and no Parameter, Header,
	 * MediaType or Example Object examples yet. Adding an example raises this number on
	 * purpose; a walker that quietly stops finding some lowers it and fails here.
	 */
	private const EXPECTED_EXAMPLE_COUNT = 40;

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

	public function testDateTimeValuesAreHeldToTheWireFormat(): void
	{
		// Opis enforces `format: date-time`, and the property's own `pattern` adds the wire
		// format's six fractional digits and `Z`, so the SQL-style timestamp these examples
		// used to carry is rejected.
		$document = self::loadDocument();
		$document->components->schemas->Product->example->row_created_timestamp = '2019-05-02 20:12:26';

		[, $failures] = self::validateExamples($document);

		self::assertCount(1, $failures, implode("\n", $failures));
		self::assertStringContainsString('/row_created_timestamp', $failures[0]);
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
			if ($result->isValid())
			{
				continue;
			}

			$leaves = [];
			self::leafErrors($result->error(), $leaves);
			$failures[] = $examplePointer . ': ' . implode('; ', array_unique($leaves));
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
