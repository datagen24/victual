<?php

namespace Victual\Services\Labels;

class DriverRegistryService extends LabelService
{
    public const AXES = ['model','media','resolution_x','resolution_y','color_mode'];
    public function Register(int $workerId, array $definitions): void
    {
        $this->Transaction();
        if (!$this->Query('SELECT id FROM label_workers WHERE id = ? AND active = 1 FOR UPDATE', [$workerId])->fetchColumn()) {
            $this->Refuse('worker', 'inactive_worker', 'Worker is inactive');
        }
        $seen = [];
        foreach ($definitions as $definition) {
            try {
                $this->ValidateDefinition($definition);
            } catch (LabelValidationException $error) {
                throw $error;
            } catch (\Throwable) {
                $this->Refuse('drivers', 'invalid_definition', 'Malformed driver definition');
            }
            $identity = $definition['driver_id'] . ':' . $definition['schema_version'];
            if (isset($seen[$identity])) {
                $this->Refuse('drivers', 'duplicate_driver', 'Duplicate driver version');
            }
            $seen[$identity] = true;
            $capability = $definition['capability_document'];
            $args = [$definition['driver_id'], $definition['schema_version'], $this->Json($capability['connection_types']),
             $this->Json($definition['discriminator_properties']), $this->Json($definition['combination_binding']),
             $this->Json($definition['settings_schemas']), $this->Json($capability), $workerId];
            $this->Query('INSERT INTO label_drivers(driver_id,schema_version,contract_version,connection_types,discriminator_properties,combination_binding,settings_schemas,capability_document,registered_by_worker_id) VALUES (?,?,1,?::jsonb,?::jsonb,?::jsonb,?::jsonb,?::jsonb,?) ON CONFLICT (driver_id,schema_version) DO NOTHING', $args);
            $matches = $this->Query('SELECT id FROM label_drivers WHERE driver_id=? AND schema_version=? AND connection_types=?::jsonb AND discriminator_properties=?::jsonb AND combination_binding=?::jsonb AND settings_schemas=?::jsonb AND capability_document=?::jsonb', array_slice($args, 0, 7))->fetchColumn();
            if (!$matches) {
                $this->Refuse('drivers', 'contradicting_registration', 'A registered driver version is immutable');
            }
        }
        $this->Query('DELETE FROM label_worker_capabilities WHERE worker_id=?', [$workerId]);
        foreach ($definitions as $definition) {
            $this->Query('INSERT INTO label_worker_capabilities(worker_id,driver_id,schema_version,artifact_forms,profile_contract_versions) VALUES (?,?,?,?::jsonb,?::jsonb)', [$workerId,$definition['driver_id'],$definition['schema_version'],$this->Json($definition['capability_document']['artifact_forms']),$this->Json($definition['profile_contract_versions'])]);
        }
        $this->Query('UPDATE label_workers SET last_registered_at=CURRENT_TIMESTAMP WHERE id=?', [$workerId]);
    }
    public function ValidateDefinition(array $definition): void
    {
        foreach (['driver_id','schema_version'] as $key) {
            if (!is_string($definition[$key] ?? null) || $definition[$key] === '') {
                $this->Refuse($key, 'invalid_definition', 'Missing driver identity');
            }
        }
        if (($definition['contract_version'] ?? null) !== 1) {
            $this->Refuse('contract_version', 'invalid_definition', 'Unsupported capability contract');
        }
        $cap = $definition['capability_document'] ?? [];
        foreach (['connection_types','models','combinations','artifact_forms','completion_evidence'] as $key) {
            if (!is_array($cap[$key] ?? null) || !array_is_list($cap[$key]) || !$cap[$key]) {
                $this->Refuse($key, 'invalid_definition', 'A nonempty list is required');
            }
        }
        foreach (self::AXES as $axis) {
            if (!is_string($definition['combination_binding'][$axis] ?? null) || $definition['combination_binding'][$axis] === '') {
                $this->Refuse('combination_binding', 'invalid_definition', 'Every axis needs a settings binding');
            }
        }
        if (!is_array($definition['discriminator_properties'] ?? null) || !is_array($definition['settings_schemas'] ?? null) || !$definition['settings_schemas']) {
            $this->Refuse('settings_schemas', 'invalid_definition', 'Missing discriminator schemas');
        }
        if (count($definition['settings_schemas']) > 256 || count($cap['combinations']) > 256) {
            $this->Refuse('settings_schemas', 'invalid_definition', 'At most 256 combinations are allowed');
        }
        $tuples = [];
        foreach ($definition['settings_schemas'] as $entry) {
            $selector = $entry['when'] ?? null;
            if (!is_array($selector) || array_diff(array_keys($selector), $definition['discriminator_properties']) || array_diff($definition['discriminator_properties'], array_keys($selector))) {
                $this->Refuse('settings_schemas', 'invalid_definition', 'Each schema must bind every discriminator');
            }
            ksort($selector);
            $key = $this->Json($selector);
            if (isset($tuples[$key])) {
                $this->Refuse('settings_schemas', 'invalid_definition', 'Ambiguous discriminator tuple');
            }
            $tuples[$key] = true;
            (new SettingsSchemaValidator())->AssertWithinSubset(json_decode($this->Json($entry['schema'] ?? null)));
        }
        foreach ($cap['combinations'] as $entry) {
            foreach (array_merge(self::AXES, ['connection_type','printable_width_um','printable_length_um','feed_direction']) as $key) {
                if (!array_key_exists($key, $entry)) {
                    $this->Refuse('combinations', 'invalid_definition', 'Missing combination field: ' . $key);
                }
            }
            if (!in_array($entry['connection_type'], $cap['connection_types'], true) || !in_array($entry['model'], $cap['models'], true)) {
                $this->Refuse('combinations', 'invalid_definition', 'Unknown model or connection type');
            }
            foreach (['resolution_x','resolution_y','printable_width_um'] as $key) {
                if (!is_int($entry[$key]) || $entry[$key] < 1) {
                    $this->Refuse($key, 'invalid_definition', 'Geometry must be positive');
                }
            }
            $length = $entry['printable_length_um'];
            if (!(is_int($length) && $length > 0) && !(is_array($length) && is_int($length['min'] ?? null) && is_int($length['max'] ?? null) && $length['min'] > 0 && $length['max'] >= $length['min'])) {
                $this->Refuse('printable_length_um', 'invalid_definition', 'Invalid media length');
            }
            $this->Provenance($entry);
        }
        foreach (['artifact_forms','completion_evidence'] as $list) {
            foreach ($cap[$list] as $entry) {
                if (!is_array($entry) || !is_array($entry['applies_to'] ?? null) || !$entry['applies_to']) {
                    $this->Refuse($list, 'invalid_definition', 'Explicit combination selectors are required');
                }
                foreach ($entry['applies_to'] as $selector) {
                    if (!is_array($selector) || !isset($selector['connection_type']) || array_diff(array_keys($selector), array_merge(self::AXES, ['connection_type']))) {
                        $this->Refuse($list, 'invalid_definition', 'Invalid combination selector');
                    }
                    if (!array_filter($cap['combinations'], fn ($combo) => self::Matches($combo, $selector))) {
                        $this->Refuse($list, 'invalid_definition', 'Selector matches no combination');
                    }
                }
                $this->Provenance($entry);
                if ($list === 'artifact_forms' && (!is_string($entry['form'] ?? null) || !in_array($entry['geometry'] ?? null, ['fixed_grid','device_placed'], true))) {
                    $this->Refuse($list, 'invalid_definition', 'Form and geometry are required');
                }
                if ($list === 'completion_evidence' && !in_array($entry['evidence'] ?? null, ['none','transport','device_reported'], true)) {
                    $this->Refuse($list, 'invalid_definition', 'Invalid completion evidence');
                }
            }
        }
        if (!is_array($definition['profile_contract_versions'] ?? null) || !$definition['profile_contract_versions']) {
            $this->Refuse('profile_contract_versions', 'invalid_definition', 'Profile contracts are required');
        }
    }
    private function Provenance(array $entry): void
    {
        if (!in_array($entry['provenance'] ?? null, ['demonstrated','advertised'], true)) {
            $this->Refuse('provenance', 'invalid_definition', 'Entry must state its provenance');
        }
    }
    public static function Matches(array $combination, array $selector): bool
    {
        foreach ($selector as $key => $value) {
            if (($combination[$key] ?? null) !== $value) {
                return false;
            }
        }
        return true;
    }
}
