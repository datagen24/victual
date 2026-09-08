<?php

namespace Victual\Services\Labels;

use Victual\Helpers\CanonicalJson;

/**
 * Reads the values a label will carry, in the transaction that authorizes reading them.
 *
 * The rule this exists to enforce is plan 27 piece 2's: **clients submit entity references,
 * never purported values.** A request names location 4 and a template; what location 4 is
 * called is read here, under the permission the field catalogue declares, and is immutable
 * afterwards. Nothing a caller sent reaches a label.
 *
 * Formatting inputs are pinned rather than read from the host. `locale` and `timezone` are
 * columns because the same capture has to render the same way in six months, and a renderer
 * that consulted its own environment would make an exact reprint a different label whenever
 * the deployment moved.
 */
class LabelCaptureService extends LabelService
{
    public function __construct(\PDO $db, private $permissionCheck = null)
    {
        parent::__construct($db);
    }

    /**
     * @param callable(string):bool|null $permissionCheck Answers whether the caller holds a
     *        permission. Injected rather than reaching for the session, so the regression
     *        suite can exercise an unauthorized caller without inventing one.
     */
    public function Capture(string $entityKind, int $targetId, ?string $labelUid, array $fieldNames, string $locale, string $timezone, ?int $userId): array
    {
        $this->Transaction();

        $catalogue = FieldCatalogue::For($entityKind);
        $table = FieldCatalogue::TableFor($entityKind);

        $columns = ['id'];
        foreach ($fieldNames as $field) {
            if (!isset($catalogue[$field])) {
                $this->Refuse('fields', 'unknown_field', 'Field "' . $field . '" is not in the catalogue for ' . $entityKind);
            }
            $this->Authorize($catalogue[$field]['permission'], $field);
            $columns[] = $catalogue[$field]['column'];
        }
        $columns = array_values(array_unique($columns));

        // Column-listed rather than SELECT *, for the reason group A already gives for the
        // locations reads: the import epoch is a server-owned generation and never leaves
        // through a path that did not mean to expose it.
        $row = $this->Query('SELECT ' . implode(',', $columns) . " FROM $table WHERE id=?", [$targetId])->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            $this->Refuse('target_id', 'not_found', 'No such ' . $entityKind);
        }

        $captured = [];
        foreach ($fieldNames as $field) {
            $definition = $catalogue[$field];
            $value = $row[$definition['column']] ?? null;
            if ($value === null || $value === '') {
                if ($definition['null'] === 'error') {
                    $this->Refuse($field, 'field_unavailable', 'Field "' . $field . '" has no value and the catalogue does not allow an empty one');
                }
                $value = '';
            }
            $value = (string)$value;
            if (mb_strlen($value) > $definition['max_length']) {
                // Truncating here would put a silently wrong string on a physical object.
                $this->Refuse($field, 'value_out_of_range', 'Field "' . $field . '" is longer than the catalogue allows (' . $definition['max_length'] . ')');
            }
            $captured[$field] = $value;
        }

        return $this->Insert($entityKind, $targetId, $labelUid, $captured, $locale, $timezone, 0, $userId);
    }

    /** A synthetic capture for a preview. It names no target, so it can never be promoted. */
    public function CaptureSample(string $entityKind, array $fieldNames, string $locale, string $timezone, ?int $userId): array
    {
        $this->Transaction();
        $sample = FieldCatalogue::SampleFor($entityKind);
        $captured = [];
        foreach ($fieldNames as $field) {
            $captured[$field] = $sample[$field] ?? 'Sample';
        }
        return $this->Insert($entityKind, null, null, $captured, $locale, $timezone, 1, $userId);
    }

    public function Get(int $captureId): array
    {
        $row = $this->Query('SELECT * FROM label_captures WHERE id=?', [$captureId])->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            $this->Refuse('capture_id', 'not_found', 'No such capture');
        }
        $row['captured_fields'] = json_decode($row['captured_fields'], true);
        return $row;
    }

    private function Insert(string $entityKind, ?int $targetId, ?string $labelUid, array $captured, string $locale, string $timezone, int $isSample, ?int $userId): array
    {
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $this->Refuse('timezone', 'invalid_value', 'A capture pins a named timezone, not an offset and not the host default');
        }
        $document = ['fields' => $captured, 'locale' => $locale, 'timezone' => $timezone, 'entity_kind' => $entityKind, 'target_id' => $targetId, 'is_sample' => $isSample === 1];
        $digest = CanonicalJson::Digest($document);
        $row = $this->Query('INSERT INTO label_captures(entity_kind,target_id,label_uid,captured_fields,locale,timezone,is_sample,digest,captured_by_user_id)
            VALUES (?,?,?,?::jsonb,?,?,?,?,?) RETURNING *',
            [$entityKind, $targetId, $labelUid, $this->Json($captured), $locale, $timezone, $isSample, $digest, $userId])->fetch(\PDO::FETCH_ASSOC);
        $row['captured_fields'] = $captured;
        return $row;
    }

    private function Authorize(string $permission, string $field): void
    {
        $check = $this->permissionCheck;
        $allowed = $check === null ? true : (bool)$check($permission);
        if (!$allowed) {
            // The message names the permission rather than the value, because an
            // unauthorized caller must learn neither the captured value nor whether the
            // target exists.
            $this->Refuse($field, 'forbidden', 'Reading "' . $field . '" needs ' . $permission);
        }
    }
}
