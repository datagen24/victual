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
 * Plan 32 brought the five kinds that used to print a Grocycode through the webhook:
 * `product`, `stock_entry`, `recipe`, `chore`, `battery`, alongside `location`.
 */
class FieldCatalogue
{
    /**
     * @return array<string, array{column: string, select?: string, permission: string, max_length: int, null: string, type: string}>
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
                    // Bare 'locations.id', matching TableFor('location') and the unaliased
                    // FROM clause LabelCaptureService::Capture() builds around it - the two
                    // already have to agree on the table name, this just also agrees on it
                    // carrying no alias.
                    'select' => '(SELECT r.path FROM locations_resolved r WHERE r.ancestor_location_id = locations.id AND r.descendant_location_id = locations.id) AS path',
                    'permission' => User::PERMISSION_STOCK_VIEW,
                    'max_length' => 750,
                    'null' => 'error',
                    'type' => 'string',
                ],
            ],
            'product' => [
                'product.name' => [
                    'column' => 'name',
                    'permission' => User::PERMISSION_STOCK_VIEW,
                    'max_length' => 240,
                    'null' => 'error',
                    'type' => 'string',
                ],
                'product.description' => [
                    'column' => 'description',
                    'permission' => User::PERMISSION_STOCK_VIEW,
                    'max_length' => 240,
                    'null' => 'empty',
                    'type' => 'string',
                ],
                'product.id' => [
                    'column' => 'id',
                    'permission' => User::PERMISSION_STOCK_VIEW,
                    'max_length' => 20,
                    'null' => 'error',
                    'type' => 'integer',
                ],
                // Nullable: product_group_id itself is nullable, and a group can be deleted
                // out from under a product without deleting the product (SET NULL).
                'product.group' => [
                    'column' => 'group_name',
                    'select' => '(SELECT pg.name FROM product_groups pg WHERE pg.id = products.product_group_id) AS group_name',
                    'permission' => User::PERMISSION_STOCK_VIEW,
                    'max_length' => 240,
                    'null' => 'empty',
                    'type' => 'string',
                ],
            ],
            'stock_entry' => [
                // A stock entry's own row carries no name; what a person reads on the label is
                // the product it holds.
                'stock_entry.product_name' => [
                    'column' => 'product_name',
                    'select' => '(SELECT p.name FROM products p WHERE p.id = stock.product_id) AS product_name',
                    'permission' => User::PERMISSION_STOCK_VIEW,
                    'max_length' => 240,
                    'null' => 'error',
                    'type' => 'string',
                ],
                // never_overdue products (due_type 2) carry entries with no best_before_date.
                'stock_entry.best_before_date' => [
                    'column' => 'best_before_date',
                    'permission' => User::PERMISSION_STOCK_VIEW,
                    'max_length' => 32,
                    'null' => 'empty',
                    'type' => 'string',
                ],
                'stock_entry.amount' => [
                    'column' => 'amount',
                    'permission' => User::PERMISSION_STOCK_VIEW,
                    'max_length' => 32,
                    'null' => 'error',
                    'type' => 'string',
                ],
                'stock_entry.qu_name' => [
                    'column' => 'qu_name',
                    'select' => '(SELECT qu.name FROM products p JOIN quantity_units qu ON qu.id = p.qu_id_stock WHERE p.id = stock.product_id) AS qu_name',
                    'permission' => User::PERMISSION_STOCK_VIEW,
                    'max_length' => 120,
                    'null' => 'error',
                    'type' => 'string',
                ],
                'stock_entry.purchased_date' => [
                    'column' => 'purchased_date',
                    'permission' => User::PERMISSION_STOCK_VIEW,
                    'max_length' => 32,
                    'null' => 'error',
                    'type' => 'string',
                ],
                // Nullable: a stock entry's location_id itself is nullable.
                'stock_entry.location_name' => [
                    'column' => 'location_name',
                    'select' => '(SELECT l.name FROM locations l WHERE l.id = stock.location_id) AS location_name',
                    'permission' => User::PERMISSION_STOCK_VIEW,
                    'max_length' => 120,
                    'null' => 'empty',
                    'type' => 'string',
                ],
                'stock_entry.id' => [
                    'column' => 'id',
                    'permission' => User::PERMISSION_STOCK_VIEW,
                    'max_length' => 20,
                    'null' => 'error',
                    'type' => 'integer',
                ],
            ],
            'recipe' => [
                'recipe.name' => [
                    'column' => 'name',
                    'permission' => User::PERMISSION_RECIPES_VIEW,
                    'max_length' => 240,
                    'null' => 'error',
                    'type' => 'string',
                ],
                'recipe.id' => [
                    'column' => 'id',
                    'permission' => User::PERMISSION_RECIPES_VIEW,
                    'max_length' => 20,
                    'null' => 'error',
                    'type' => 'integer',
                ],
            ],
            'chore' => [
                'chore.name' => [
                    'column' => 'name',
                    'permission' => User::PERMISSION_CHORES_VIEW,
                    'max_length' => 240,
                    'null' => 'error',
                    'type' => 'string',
                ],
                'chore.id' => [
                    'column' => 'id',
                    'permission' => User::PERMISSION_CHORES_VIEW,
                    'max_length' => 20,
                    'null' => 'error',
                    'type' => 'integer',
                ],
            ],
            'battery' => [
                'battery.name' => [
                    'column' => 'name',
                    // Batteries have no domain-scoped read permission (EntityReadPolicy maps
                    // 'batteries' to null - any authenticated caller may read them); the
                    // permission that gates the battery feature at all is the closest thing
                    // there is, and Authorize() still refuses a caller who lacks it.
                    'permission' => User::PERMISSION_BATTERIES,
                    'max_length' => 240,
                    'null' => 'error',
                    'type' => 'string',
                ],
                'battery.id' => [
                    'column' => 'id',
                    'permission' => User::PERMISSION_BATTERIES,
                    'max_length' => 20,
                    'null' => 'error',
                    'type' => 'integer',
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
            'product' => 'products',
            'stock_entry' => 'stock',
            'recipe' => 'recipes',
            'chore' => 'chores',
            'battery' => 'batteries',
            default => throw new LabelValidationException('entity_kind', 'unsupported_entity_kind', 'No field catalogue exists for entity kind "' . $entityKind . '"'),
        };
    }

    /**
     * The domain read permission `LabelsApiController::Operate()` checks alongside
     * `MASTER_DATA_EDIT`, per the maintainer's answer to plan 27 question 2 generalised across
     * kinds by plan 32: capturing a value takes the read grant that value's own domain uses
     * everywhere else, not a blanket one.
     */
    public static function DomainPermission(string $entityKind): string
    {
        return match ($entityKind) {
            'location', 'product', 'stock_entry' => User::PERMISSION_STOCK_VIEW,
            'recipe' => User::PERMISSION_RECIPES_VIEW,
            'chore' => User::PERMISSION_CHORES_VIEW,
            'battery' => User::PERMISSION_BATTERIES,
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
            'product' => ['product.name' => 'Sample product', 'product.description' => 'Sample data, not a real product', 'product.id' => '0', 'product.group' => 'Sample group'],
            'stock_entry' => ['stock_entry.product_name' => 'Sample product', 'stock_entry.best_before_date' => '2099-12-31', 'stock_entry.amount' => '1', 'stock_entry.qu_name' => 'piece', 'stock_entry.purchased_date' => '2099-01-01', 'stock_entry.location_name' => 'Sample shelf', 'stock_entry.id' => '0'],
            'recipe' => ['recipe.name' => 'Sample recipe', 'recipe.id' => '0'],
            'chore' => ['chore.name' => 'Sample chore', 'chore.id' => '0'],
            'battery' => ['battery.name' => 'Sample battery', 'battery.id' => '0'],
            default => throw new LabelValidationException('entity_kind', 'unsupported_entity_kind', 'No field catalogue exists for entity kind "' . $entityKind . '"'),
        };
    }
}
