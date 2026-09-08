<?php

namespace Victual\Services\Labels;

class PrinterConfigurationService extends LabelService
{
    public function Validate(array $printer): array
    {
        $driver = $this->Query('SELECT * FROM label_drivers WHERE driver_id=? AND schema_version=?', [$printer['driver_id'] ?? '',$printer['driver_schema_version'] ?? ''])->fetch(\PDO::FETCH_ASSOC);
        if (!$driver) {
            $this->Refuse('driver_schema_version', 'unknown_driver_version', 'Unknown driver version');
        }
        foreach (['name','connection','connection_type','model'] as $key) {
            if (!is_string($printer[$key] ?? null) || trim($printer[$key]) === '') {
                $this->Refuse($key, 'value_out_of_range', 'A nonempty value is required');
            }
        }
        if (!is_int($printer['worker_id'] ?? null) || !$this->Query('SELECT id FROM label_workers WHERE id=? AND active=1', [$printer['worker_id']])->fetchColumn()) {
            $this->Refuse('worker_id', 'value_out_of_range', 'An active worker is required');
        }
        $settings = $printer['settings'] ?? null;
        if (!is_array($settings) && !is_object($settings)) {
            $this->Refuse('settings', 'value_out_of_range', 'Settings must be an object');
        }
        $settings = (array)$settings;
        $schemas = json_decode($driver['settings_schemas'], true, 512, JSON_THROW_ON_ERROR);
        $discriminators = json_decode($driver['discriminator_properties'], true, 512, JSON_THROW_ON_ERROR);
        $tuple = [];
        foreach ($discriminators as $key) {
            $tuple[$key] = $key === 'model' || $key === 'connection_type' ? $printer[$key] : ($settings[$key] ?? null);
        }
        $matched = array_values(array_filter($schemas, fn ($entry) => DriverRegistryService::Matches($tuple, $entry['when'])));
        if (count($matched) !== 1) {
            $this->Refuse('settings', 'schema_unevaluable', 'No unique schema matches the discriminator tuple');
        }
        $validation = (new SettingsSchemaValidator())->Validate((object)$settings, json_decode($this->Json($matched[0]['schema'])));
        if ($validation) {
            $this->Refuse($validation['field'], $validation['code'], $validation['message']);
        }
        $binding = json_decode($driver['combination_binding'], true, 512, JSON_THROW_ON_ERROR);
        $combination = ['connection_type' => $printer['connection_type']];
        foreach (DriverRegistryService::AXES as $axis) {
            $combination[$axis] = $axis === 'model' ? $printer['model'] : ($settings[$binding[$axis]] ?? null);
        }
        $cap = json_decode($driver['capability_document'], true, 512, JSON_THROW_ON_ERROR);
        $matches = array_values(array_filter($cap['combinations'], fn ($entry) => DriverRegistryService::Matches($entry, $combination)));
        if (count($matches) !== 1) {
            $this->Refuse('settings', 'unsupported_combination', 'Unsupported combination: ' . $this->Json($combination));
        }
        $evidence = [];
        foreach ($cap['completion_evidence'] as $entry) {
            foreach ($entry['applies_to'] as $selector) {
                if (DriverRegistryService::Matches($matches[0], $selector)) {
                    $evidence[$entry['evidence']] = true;
                }
            }
        }
        if (count($evidence) !== 1) {
            $this->Refuse('settings', 'unsupported_combination', 'No unique completion evidence matches the combination');
        }
        return ['printer' => $printer,'combination' => $matches[0],'acknowledged_on' => isset($evidence['device_reported']) ? 'report' : 'send'];
    }
    public function Save(array $printer, ?int $id = null, bool $moveVersion = false): int
    {
        $this->Transaction();
        if ($id !== null) {
            $existing = $this->Query('SELECT * FROM label_printers WHERE id=? FOR UPDATE', [$id])->fetch(\PDO::FETCH_ASSOC);
            if (!$existing) {
                $this->Refuse('printer_id', 'not_found', 'Printer not found');
            }
            if (!$moveVersion && ($existing['driver_id'] !== ($printer['driver_id'] ?? null) || $existing['driver_schema_version'] !== ($printer['driver_schema_version'] ?? null))) {
                $this->Refuse('driver_schema_version', 'explicit_move_required', 'Use the schema-version move action');
            }
        }
        $this->Validate($printer);
        foreach (['active','is_default'] as $key) {
            if (!in_array($printer[$key] ?? ($key === 'active' ? 1 : 0), [0,1], true)) {
                $this->Refuse($key, 'value_out_of_range', 'Use 0 or 1');
            }
        }
        $args = [$printer['name'],$printer['description'] ?? null,$printer['active'] ?? 1,$printer['is_default'] ?? 0,$printer['worker_id'],$printer['driver_id'],$printer['driver_schema_version'],$printer['connection'],$printer['connection_type'],$printer['model'],$this->Json((object)$printer['settings'])];
        if ($id === null) {
            return (int)$this->Query('INSERT INTO label_printers(name,description,active,is_default,worker_id,driver_id,driver_schema_version,connection,connection_type,model,settings,settings_validated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?::jsonb,CURRENT_TIMESTAMP) RETURNING id', $args)->fetchColumn();
        }
        $args[] = $id;
        $this->Query('UPDATE label_printers SET name=?,description=?,active=?,is_default=?,worker_id=?,driver_id=?,driver_schema_version=?,connection=?,connection_type=?,model=?,settings=?::jsonb,settings_validated_at=CURRENT_TIMESTAMP WHERE id=?', $args);
        return $id;
    }
    public function Resolve(int $id): array
    {
        $printer = $this->Query('SELECT p.* FROM label_printers p JOIN label_workers w ON w.id=p.worker_id WHERE p.id=? AND p.active=1 AND w.active=1', [$id])->fetch(\PDO::FETCH_ASSOC);
        if (!$printer) {
            $this->Refuse('printer_id','inactive_printer','Printer or assigned worker is missing or inactive');
        }
        $printer['settings'] = json_decode($printer['settings'],true,512,JSON_THROW_ON_ERROR);
        return $this->Validate($printer);
    }
}
