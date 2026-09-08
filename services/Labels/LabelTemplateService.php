<?php

namespace Victual\Services\Labels;

use Victual\Helpers\CanonicalJson;

/**
 * Template identity, the mutable draft, and immutable published versions.
 *
 * Publishing is where a document stops being editable and becomes something an artifact can
 * name. It validates the document, resolves every asset it references, and records the
 * digest - SHA-256 over the RFC 8785 canonical encoding, so that two publications of the
 * same design produce the same digest whatever order the editor serialized its keys in.
 */
class LabelTemplateService extends LabelService
{
    public function Create(string $name, ?string $description, string $entityKind, ?int $userId): array
    {
        $this->Transaction();
        FieldCatalogue::For($entityKind);
        if (trim($name) === '') {
            $this->Refuse('name', 'invalid_value', 'A template has a name');
        }
        $template = $this->Query('INSERT INTO label_templates(name,description,entity_kind) VALUES (?,?,?) RETURNING *', [$name, $description, $entityKind])->fetch(\PDO::FETCH_ASSOC);
        $this->Query('INSERT INTO label_template_drafts(template_id,document,revision_token,updated_by_user_id) VALUES (?,?::jsonb,?,?)',
            [$template['id'], $this->Json(self::EmptyDocument($entityKind)), self::Token(), $userId]);
        return $template;
    }

    public function GetDraft(int $templateId): array
    {
        $draft = $this->Query('SELECT d.*,t.entity_kind,t.name FROM label_template_drafts d JOIN label_templates t ON t.id=d.template_id WHERE d.template_id=?', [$templateId])->fetch(\PDO::FETCH_ASSOC);
        if (!$draft) {
            $this->Refuse('template_id', 'not_found', 'No such template');
        }
        $draft['document'] = json_decode($draft['document'], true);
        return $draft;
    }

    /**
     * A draft write carries the token it read. A stale token is a conflict rather than a
     * silent overwrite of whatever the other editor just saved - which is the whole reason
     * the token exists, since two people designing one label is the ordinary case rather
     * than the exotic one.
     */
    public function SaveDraft(int $templateId, array $document, string $revisionToken, ?int $userId): array
    {
        $this->Transaction();
        $draft = $this->Query('SELECT d.*,t.entity_kind FROM label_template_drafts d JOIN label_templates t ON t.id=d.template_id WHERE d.template_id=? FOR UPDATE OF d', [$templateId])->fetch(\PDO::FETCH_ASSOC);
        if (!$draft) {
            $this->Refuse('template_id', 'not_found', 'No such template');
        }
        if (!hash_equals((string)$draft['revision_token'], $revisionToken)) {
            $this->Refuse('revision_token', 'stale_revision', 'The draft changed since it was read; reload it and reapply the edit');
        }
        // Validated on save as well as on publish, so a designer learns about an unknown
        // property while looking at the element that carries it.
        $validated = TemplateDocument::Validate($document, $draft['entity_kind']);
        $token = self::Token();
        $this->Query('UPDATE label_template_drafts SET document=?::jsonb,revision_token=?,updated_at=CURRENT_TIMESTAMP,updated_by_user_id=? WHERE template_id=?',
            [$this->Json($validated['document']), $token, $userId, $templateId]);
        return ['template_id' => $templateId, 'revision_token' => $token, 'document' => $validated['document']];
    }

    /**
     * Publishes the current draft as the next immutable version.
     *
     * Structural validity is what this establishes - not fitness for every field value.
     * Overflow, glyph coverage, QR geometry and media compatibility are properties of a
     * particular capture on a particular profile, so they are checked at each render and
     * cannot be settled here.
     */
    public function Publish(int $templateId, ?int $userId): array
    {
        $this->Transaction();
        $draft = $this->Query('SELECT d.*,t.entity_kind,t.archived_at FROM label_template_drafts d JOIN label_templates t ON t.id=d.template_id WHERE d.template_id=? FOR UPDATE OF d', [$templateId])->fetch(\PDO::FETCH_ASSOC);
        if (!$draft) {
            $this->Refuse('template_id', 'not_found', 'No such template');
        }
        if ($draft['archived_at'] !== null) {
            $this->Refuse('template_id', 'archived', 'An archived template publishes nothing further');
        }

        $validated = TemplateDocument::Validate(json_decode($draft['document'], true), $draft['entity_kind']);

        $assetIds = [];
        foreach ($validated['asset_ids'] as $assetName) {
            $asset = $this->Query('SELECT id,asset_kind FROM label_assets WHERE name=?', [$assetName])->fetch(\PDO::FETCH_ASSOC);
            if (!$asset) {
                $this->Refuse('assets', 'asset_unavailable', 'The document references asset "' . $assetName . '", which is not stored');
            }
            $assetIds[$assetName] = (int)$asset['id'];
        }

        $digest = CanonicalJson::Digest($validated['document']);
        $version = (int)$this->Query('SELECT COALESCE(MAX(version),0)+1 FROM label_template_versions WHERE template_id=?', [$templateId])->fetchColumn();

        $row = $this->Query('INSERT INTO label_template_versions(template_id,version,document,document_digest,asset_ids,required_capabilities,published_by_user_id)
            VALUES (?,?,?::jsonb,?,?::jsonb,?::jsonb,?) RETURNING *',
            [$templateId, $version, $this->Json($validated['document']), $digest, $this->Json($assetIds), $this->Json($validated['required_capabilities']), $userId])->fetch(\PDO::FETCH_ASSOC);

        // The first published version becomes the default, because a template with versions
        // and no default is a template nothing can print from.
        $this->Query('UPDATE label_templates SET default_version_id=COALESCE(default_version_id,?) WHERE id=?', [$row['id'], $templateId]);
        return $row;
    }

    /**
     * The default pointer is administrative. Changing it affects new issuance and revised
     * prints only; it cannot touch a queued job, a preview or an existing artifact, each of
     * which pinned its version when it was created.
     */
    public function SetDefaultVersion(int $templateId, int $versionId): array
    {
        $this->Transaction();
        $version = $this->Query('SELECT id FROM label_template_versions WHERE id=? AND template_id=?', [$versionId, $templateId])->fetchColumn();
        if (!$version) {
            $this->Refuse('version_id', 'not_found', 'That version does not belong to this template');
        }
        return $this->Query('UPDATE label_templates SET default_version_id=? WHERE id=? RETURNING *', [$versionId, $templateId])->fetch(\PDO::FETCH_ASSOC);
    }

    public function Archive(int $templateId): array
    {
        $this->Transaction();
        return $this->Query('UPDATE label_templates SET archived_at=COALESCE(archived_at,CURRENT_TIMESTAMP) WHERE id=? RETURNING *', [$templateId])->fetch(\PDO::FETCH_ASSOC);
    }

    /** The published version a new print uses, refusing rather than falling back. */
    public function ResolveVersion(int $templateId, ?int $versionId): array
    {
        if ($versionId !== null) {
            $row = $this->Query('SELECT v.*,t.entity_kind FROM label_template_versions v JOIN label_templates t ON t.id=v.template_id WHERE v.id=? AND v.template_id=?', [$versionId, $templateId])->fetch(\PDO::FETCH_ASSOC);
        } else {
            $row = $this->Query('SELECT v.*,t.entity_kind FROM label_templates t JOIN label_template_versions v ON v.id=t.default_version_id WHERE t.id=? AND t.archived_at IS NULL', [$templateId])->fetch(\PDO::FETCH_ASSOC);
        }
        if (!$row) {
            $this->Refuse('template_version_id', 'unsupported_version', 'No published template version is available; an unavailable version is refused rather than replaced by the latest');
        }
        $row['document'] = json_decode($row['document'], true);
        $row['asset_ids'] = json_decode($row['asset_ids'], true);
        $row['required_capabilities'] = json_decode($row['required_capabilities'], true);
        return $row;
    }

    private static function EmptyDocument(string $entityKind): array
    {
        return [
            'schema_version' => TemplateDocument::SCHEMA_VERSION,
            'entity_kind' => $entityKind,
            'canvas' => ['width_mm' => 62.0, 'height_mm' => null, 'max_height_mm' => 100.0, 'margins_mm' => ['top' => 2.0, 'right' => 2.0, 'bottom' => 2.0, 'left' => 2.0]],
            'elements' => [[
                'type' => 'text', 'id' => 'name', 'x_mm' => 2.0, 'y_mm' => 2.0, 'width_mm' => 58.0, 'height_mm' => 10.0,
                'field' => $entityKind . '.name', 'literal' => null, 'font_asset' => 'default', 'size_pt' => 11.0,
                'align' => 'left', 'valign' => 'top', 'wrap' => true, 'line_spacing' => 1.2, 'overflow' => 'error',
                'min_size_pt' => null, 'color' => 'black',
            ]],
        ];
    }

    private static function Token(): string
    {
        return bin2hex(random_bytes(16));
    }
}
