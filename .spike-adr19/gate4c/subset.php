<?php
// Structural validation against the *supported subset*, which is not the same question as
// "does the validator throw". A schema can evaluate perfectly and still use a keyword the form
// renderer cannot honour — which is how a conditional would slip back in and be silently
// ignored at the only place a person sees it.
final class Subset
{
    // Version 1, from ADR-0019's settings-schema subset: scalars and enums, required, numeric
    // and length bounds, pattern, title and description.
    private const SCHEMA_KEYWORDS = [
        '$schema', '$comment', 'type', 'title', 'description', 'default',
        'properties', 'required', 'additionalProperties',
        'enum', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum',
        'minLength', 'maxLength', 'pattern',
    ];
    private const TYPES = ['object', 'string', 'boolean', 'integer', 'number'];

    /** @return string[] problems; empty means the schema is inside the subset */
    public static function check($schema, string $where = '(root)'): array
    {
        $problems = [];
        if (!is_object($schema)) {
            return ["$where: a schema must be an object (got " . gettype($schema) . ')'];
        }
        foreach ((array)$schema as $kw => $value) {
            if (!in_array($kw, self::SCHEMA_KEYWORDS, true)) {
                $problems[] = "$where: keyword \"$kw\" is outside the version 1 subset";
                continue;
            }
            if ($kw === 'type') {
                $types = is_array($value) ? $value : [$value];
                foreach ($types as $t) {
                    if (!in_array($t, self::TYPES, true)) {
                        $problems[] = "$where: type \"$t\" is outside the version 1 subset";
                    }
                }
            }
            if ($kw === 'properties') {
                if (!is_object($value)) {
                    $problems[] = "$where: properties must be an object";
                    continue;
                }
                foreach ((array)$value as $name => $sub) {
                    // The "//" comment defect lands here rather than at write time: a value
                    // under properties must itself be a schema, and a string is not one.
                    $problems = array_merge($problems, self::check($sub, "$where/properties/$name"));
                }
            }
            if ($kw === 'additionalProperties' && $value !== false) {
                $problems[] = "$where: additionalProperties must be false in version 1";
            }
        }
        if ($where === '(root)' && (($schema->type ?? null) !== 'object')) {
            $problems[] = '(root): the settings schema must be an object schema';
        }
        return $problems;
    }
}
