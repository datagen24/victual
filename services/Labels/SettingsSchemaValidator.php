<?php

namespace Victual\Services\Labels;

/** The only JSON Schema evaluation boundary; exceptions are refusals, never success. */
class SettingsSchemaValidator
{
    private const KEYWORDS = ['$schema','type','title','description','properties','required','additionalProperties',
     'enum','const','minimum','maximum','exclusiveMinimum','exclusiveMaximum','multipleOf','minLength','maxLength',
     'pattern','default'];
    public function AssertWithinSubset(mixed $schema, string $path = ''): void
    {
        if (!is_object($schema)) {
            throw new LabelValidationException($path, 'schema_unevaluable', 'Schema must be an object');
        }
        $root = $path === '';
        $types = $root ? ['object'] : ['string','boolean','integer','number'];
        if (!in_array($schema->type ?? null, $types, true)) {
            throw new LabelValidationException($path, 'schema_unevaluable', 'Only an object of scalar properties is supported');
        }
        if ($root && (($schema->additionalProperties ?? null) !== false || !is_object($schema->properties ?? null))) {
            throw new LabelValidationException($path, 'schema_unevaluable', 'Object schemas must reject unknown properties');
        }
        if (isset($schema->{'$schema'}) && !in_array($schema->{'$schema'}, ['https://json-schema.org/draft/2020-12/schema','http://json-schema.org/draft-07/schema#'], true)) {
            throw new LabelValidationException($path, 'schema_unevaluable', 'Unsupported schema dialect');
        }
        if (!$root && (isset($schema->required) || isset($schema->properties) || isset($schema->additionalProperties))) {
            throw new LabelValidationException($path, 'schema_unevaluable', 'Nested object constraints are not supported');
        }
        if (isset($schema->required) && (!is_array($schema->required) || array_filter($schema->required, fn ($key) => !is_string($key) || !property_exists($schema->properties, $key)))) {
            throw new LabelValidationException($path, 'schema_unevaluable', 'Required must name declared properties');
        }
        foreach (['minimum','maximum','exclusiveMinimum','exclusiveMaximum','multipleOf'] as $keyword) {
            if (isset($schema->$keyword) && !is_int($schema->$keyword) && !is_float($schema->$keyword)) {
                throw new LabelValidationException($path, 'schema_unevaluable', 'Numeric bound required');
            }
        }
        foreach (['minLength','maxLength'] as $keyword) {
            if (isset($schema->$keyword) && (!is_int($schema->$keyword) || $schema->$keyword < 0)) {
                throw new LabelValidationException($path, 'schema_unevaluable', 'Nonnegative length required');
            }
        }
        foreach (['title','description','pattern'] as $keyword) {
            if (isset($schema->$keyword) && !is_string($schema->$keyword)) {
                throw new LabelValidationException($path, 'schema_unevaluable', 'String schema annotation required');
            }
        }
        if (isset($schema->enum) && (!is_array($schema->enum) || !$schema->enum || array_filter($schema->enum, fn ($value) => !is_scalar($value)))) {
            throw new LabelValidationException($path, 'schema_unevaluable', 'Scalar enum required');
        }
        foreach (get_object_vars($schema) as $key => $value) {
            if (!in_array($key, self::KEYWORDS, true)) {
                throw new LabelValidationException($path . '/' . $key, 'schema_unevaluable', 'Unsupported schema keyword');
            }
            if ($key === 'properties') {
                if (!is_object($value)) {
                    throw new LabelValidationException($path, 'schema_unevaluable', 'Properties must be an object');
                }
                foreach (get_object_vars($value) as $name => $child) {
                    $this->AssertWithinSubset($child, $path . '/properties/' . $name);
                }
            }

        }
    }
    public function Validate(mixed $settings, mixed $schema): ?array
    {
        try {
            $this->AssertWithinSubset($schema);
            $validator = new \Opis\JsonSchema\Validator();
            $result = $validator->validate($settings, $schema);
            if ($result->isValid()) {
                return null;
            }
            $error = $result->error();
            while ($error->subErrors()) {
                $error = $error->subErrors()[0];
            }
            $path = $error->data()->fullPath();
            $field = implode('.', $path);
            if ($error->keyword() === 'additionalProperties') {
                $unknown = $error->args()['properties'] ?? [];
                if ($unknown) {
                    $field = implode('.', array_merge($path, [(string)$unknown[0]]));
                }
            }
            return ['field' => $field, 'code' => $error->keyword() === 'additionalProperties' ? 'unknown_property' : 'value_out_of_range', 'message' => $error->message()];
        } catch (\Throwable $error) {
            return ['field' => $error instanceof LabelValidationException ? $error->field : 'settings', 'code' => 'schema_unevaluable', 'message' => 'The settings schema cannot be evaluated'];
        }
    }
}
