<?php

namespace Victual\Services\Labels;

use Victual\Controllers\Users\User;

/**
 * What a template may put on a label, per entity kind.
 *
 * Plan 27 piece 2: **clients submit entity references, never purported values.** A caller
 * asks for a label for location 4; it does not get to say what location 4 is called. So each
 * field declares where its value comes from, which permission reading it needs, how long it
 * may be, and what happens when it is null - and capture reads them all in one authorized
 * transaction rather than trusting anything that arrived in a request body.
 *
 * `location` is the only kind wave 3b issues labels for. The other two kinds exist in the
 * `labels` table's CHECK from migration 0269 and get their catalogues with the plans that
 * bring them.
 */
class FieldCatalogue
{
    /**
     * @return array<string, array{column: string, permission: string, max_length: int, null: string, type: string}>
     */
    public static function For(string $entityKind): array
    {
        return match ($entityKind) {
            'location' => [
                'location.name' => [
                    'column' => 'name',
                    'permission' => User::PERMISSION_STOCK_VIEW,
                    'max_length' => 120,
                    // A location always has a name; a null here would be a defect rather
                    // than a household leaving a field blank, so it is an error.
                    'null' => 'error',
                    'type' => 'string',
                ],
                'location.description' => [
                    'column' => 'description',
                    'permission' => User::PERMISSION_STOCK_VIEW,
                    'max_length' => 240,
                    'null' => 'empty',
                    'type' => 'string',
                ],
                'location.id' => [
                    'column' => 'id',
                    'permission' => User::PERMISSION_STOCK_VIEW,
                    'max_length' => 20,
                    'null' => 'error',
                    'type' => 'integer',
                ],
                // Plan 08's locations_resolved (migrations/0273.pgsql.sql) owns path
                // resolution; this reads its self row rather than walking parent_location_id
                // again. 'select' overrides what LabelCaptureService::Capture() puts in its
                // SELECT list for this field, since the value is not a stored column. Bound
                // by hierarchy_depth_limit() (6) times a name comfortably longer than
                // location.name's own cap, plus " / " separators - a location with no self
                // row in locations_resolved (data older than migration 0273, or a restore
                // that bypassed its triggers - the app itself refuses to create one this deep)
                // has no path to print, and 'null' => 'error' refuses the capture rather than
                // printing a blank line, per issue 137's "the renderer refuses rather than
                // resamples".
                'location.path' => [
                    'column' => 'path',
                    'select' => '(SELECT r.path FROM locations_resolved r WHERE r.ancestor_location_id = locations.id AND r.descendant_location_id = locations.id) AS path',
                    'permission' => User::PERMISSION_STOCK_VIEW,
                    'max_length' => 750,
                    'null' => 'error',
                    'type' => 'string',
                ],
            ],
            default => throw new LabelValidationException('entity_kind', 'unsupported_entity_kind', 'No field catalogue exists for entity kind "' . $entityKind . '"'),
        };
    }

    /** The table a kind's fields are read from. */
    public static function TableFor(string $entityKind): string
    {
        return match ($entityKind) {
            'location' => 'locations',
            default => throw new LabelValidationException('entity_kind', 'unsupported_entity_kind', 'No field catalogue exists for entity kind "' . $entityKind . '"'),
        };
    }

    /**
     * Sample values for a preview of a template, marked as sample data.
     *
     * These create no mapping and no job, and a preview built from them cannot be promoted
     * to a print - which is the whole reason they are allowed to be invented here while a
     * production capture may not be.
     */
    public static function SampleFor(string $entityKind): array
    {
        return match ($entityKind) {
            'location' => ['location.name' => 'Sample shelf', 'location.description' => 'Sample data, not a real location', 'location.id' => '0', 'location.path' => 'Sample floor / Sample room / Sample shelf'],
            default => throw new LabelValidationException('entity_kind', 'unsupported_entity_kind', 'No field catalogue exists for entity kind "' . $entityKind . '"'),
        };
    }
}
