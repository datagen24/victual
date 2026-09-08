<?php

namespace Victual\Services\Labels;

use Victual\Helpers\CanonicalJson;

/**
 * Immutable bytes plus a manifest, and the verification that stands between a renderer's
 * output and something Victual is willing to print.
 *
 * **The bytes are the authority.** Template identity and captured fields are provenance -
 * they record which design and which values the label was meant to express - and where the
 * two could ever disagree, the bytes are what was printed. That is why a reprint replays
 * stored bytes and why a reprint whose bytes have been collected is refused rather than
 * quietly rerendered: a rerender is a different artifact that nobody compared to the first.
 *
 * **Nothing a renderer says about its own output is taken on trust.** The digest is computed
 * here over the bytes that arrived; the dimensions, colour type, bit depth and palette are
 * read out of the PNG chunks; and the QR is verified by sampling the pixels against the
 * module matrix Victual itself computed. A manifest field that merely repeated the
 * renderer's claim would be a record of what it intended rather than of what it produced.
 */
class ArtifactService extends LabelService
{
    /** ADR-0021 prerequisite 2, settled: PNG colour type 3 at bit depth 2 over the profile palette. */
    public const FORM = 'raster/png-indexed;v=1';
    public const MIME_TYPE = 'image/png';

    /**
     * Verifies a rendered artifact and commits it.
     *
     * @throws LabelValidationException With a code naming which check refused it
     */
    public function Accept(int $requestId, string $generationToken, string $bytes, string $rendererId, string $rendererVersion): array
    {
        $this->Transaction();

        $requests = new RenderRequestService($this->db);
        $request = $requests->AssertCurrent($requestId, $generationToken);
        $input = $requests->Input($request);
        $profile = $input['profile'];

        if (strlen($bytes) === 0 || strlen($bytes) > LabelByteStore::MAX_BYTES) {
            $this->Refuse('artifact', 'value_out_of_range', 'An artifact is between 1 and ' . LabelByteStore::MAX_BYTES . ' bytes');
        }

        $facts = IndexedPng::Facts($bytes);

        if ($facts['color_type'] !== IndexedPng::COLOR_TYPE || $facts['bit_depth'] !== IndexedPng::BIT_DEPTH) {
            // Not a size preference. `raster/png-indexed;v=1` names colour type and bit
            // depth, so an RGBA image is a different form, and a worker handed one would
            // have to quantise before it could send - which is the decision this form
            // exists to have already made.
            $this->Refuse('artifact', 'wrong_form', 'The artifact is colour type ' . $facts['color_type'] . ' at bit depth ' . $facts['bit_depth'] . '; ' . self::FORM . ' is colour type ' . IndexedPng::COLOR_TYPE . ' at bit depth ' . IndexedPng::BIT_DEPTH);
        }

        // fixed_grid: the pixel grid is the profile's, the worker scales nothing, and a
        // mismatch is a refusal rather than a resize. This is issue #90's defect closed on
        // the authoring side.
        if ($facts['width'] !== (int)$profile['raster_width_px']) {
            $this->Refuse('artifact', 'geometry_mismatch', 'The artifact is ' . $facts['width'] . ' px wide and the profile fixes the raster at ' . $profile['raster_width_px'] . ' px');
        }
        $this->AssertLength($facts['height'], $profile);

        $palette = $profile['pixel_policy']['palette'];
        if (array_slice($facts['palette'], 0, count($palette)) !== $palette) {
            $this->Refuse('artifact', 'palette_mismatch', 'The artifact palette is not the profile palette in the profile order');
        }
        if (count($facts['palette']) > count($palette)) {
            $this->Refuse('artifact', 'palette_mismatch', 'The artifact palette carries ' . (count($facts['palette']) - count($palette)) . ' colour(s) the profile does not permit');
        }

        $pixels = IndexedPng::Pixels($bytes, $facts);
        $this->AssertQr($input, $facts, $pixels, $profile);

        $digest = hash('sha256', $bytes);
        $fileName = LabelByteStore::NameFor('artifact', $digest, 'png');

        $existing = $this->Query('SELECT * FROM label_artifacts WHERE file_group=? AND file_name=?', [LabelByteStore::GROUP_ARTIFACTS, $fileName])->fetch(\PDO::FETCH_ASSOC);
        if (!$existing) {
            (new LabelByteStore($this->db))->Put(LabelByteStore::GROUP_ARTIFACTS, $fileName, $bytes, self::MIME_TYPE);
        }

        $manifest = [
            'form' => self::FORM,
            'byte_digest' => $digest,
            'byte_length' => strlen($bytes),
            'mime_type' => self::MIME_TYPE,
            'width_px' => $facts['width'],
            'height_px' => $facts['height'],
            'dpi_x' => (int)$profile['dpi_x'],
            'dpi_y' => (int)$profile['dpi_y'],
            'color_mode' => $profile['color_mode'],
            'palette' => $facts['palette'],
            'profile' => ['id' => (int)$profile['id'], 'version' => (int)$profile['version']],
            'template' => $input['template'],
            'capture' => ['id' => (int)$input['capture']['id'], 'digest' => $input['capture']['digest']],
            'assets' => array_map(static fn ($a) => ['id' => (int)$a['id'], 'name' => $a['name'], 'content_digest' => $a['content_digest']], $input['assets']),
            'renderer' => ['id' => $rendererId, 'version' => $rendererVersion],
            'validation' => ['form_checked' => true, 'geometry_checked' => true, 'palette_checked' => true, 'qr_checked' => count($input['qr']) > 0],
        ];

        $artifact = $this->Query('INSERT INTO label_artifacts(render_request_id,form,byte_digest,byte_length,mime_type,width_px,height_px,dpi_x,dpi_y,color_mode,profile_id,template_version_id,capture_id,renderer_id,renderer_version,manifest,manifest_digest,file_group,file_name,retention_class)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?::jsonb,?,?,?,?) RETURNING *',
            [$requestId, self::FORM, $digest, strlen($bytes), self::MIME_TYPE, $facts['width'], $facts['height'],
                (int)$profile['dpi_x'], (int)$profile['dpi_y'], $profile['color_mode'], (int)$profile['id'],
                $input['template']['template_version_id'], (int)$input['capture']['id'], $rendererId, $rendererVersion,
                $this->Json($manifest), CanonicalJson::Digest($manifest), LabelByteStore::GROUP_ARTIFACTS, $fileName,
                $request['purpose'] === 'production' ? 'retained' : 'preview'])->fetch(\PDO::FETCH_ASSOC);

        $this->Query("UPDATE label_render_requests SET state='ready',artifact_id=?,generation_token=NULL,lease_expires_at=NULL,error_code=NULL,error_element=NULL,error_detail=NULL WHERE id=?", [$artifact['id'], $requestId]);

        $artifact['manifest'] = $manifest;
        return $artifact;
    }

    public function Get(int $artifactId): array
    {
        $row = $this->Query('SELECT * FROM label_artifacts WHERE id=?', [$artifactId])->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            $this->Refuse('artifact_id', 'not_found', 'No such artifact');
        }
        $row['manifest'] = json_decode($row['manifest'], true);
        return $row;
    }

    /**
     * The bytes, or a refusal saying they were collected.
     *
     * Never a rerender. Plan 27 piece 5: rerendering is never silently substituted for
     * missing bytes, because the substitute is a different label that nobody compared to
     * the one this job was authorized to print.
     */
    public function Bytes(int $artifactId): string
    {
        $artifact = $this->Get($artifactId);
        if ($artifact['collected_at'] !== null) {
            $this->Refuse('artifact_id', 'artifact_collected', 'The artifact bytes have been collected; an exact reprint is refused rather than rerendered');
        }
        $bytes = (new LabelByteStore($this->db))->Get($artifact['file_group'], $artifact['file_name']);
        if ($bytes === null) {
            $this->Refuse('artifact_id', 'artifact_collected', 'The artifact bytes are gone; an exact reprint is refused rather than rerendered');
        }
        return $bytes;
    }

    /**
     * Promotion changes an artifact's retention class, and promotion and collection must not
     * race - so it takes the row lock collection also takes, and refuses an artifact already
     * collected rather than promoting a row whose bytes have left.
     */
    public function Promote(int $artifactId): array
    {
        $this->Transaction();
        $artifact = $this->Query('SELECT * FROM label_artifacts WHERE id=? FOR UPDATE', [$artifactId])->fetch(\PDO::FETCH_ASSOC);
        if (!$artifact) {
            $this->Refuse('artifact_id', 'not_found', 'No such artifact');
        }
        if ($artifact['collected_at'] !== null) {
            $this->Refuse('artifact_id', 'artifact_collected', 'That artifact has been collected and cannot be promoted');
        }
        return $this->Query("UPDATE label_artifacts SET retention_class='retained' WHERE id=? RETURNING *", [$artifactId])->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Collects unpromoted preview artifacts past their expiry.
     *
     * Reference-aware: an artifact a job points at is never collected whatever its retention
     * class says, because the job is the reference and the maintainer's answer to question 4
     * keeps print images until the label is retired.
     */
    public function CollectExpiredPreviews(): int
    {
        $this->Transaction();
        $rows = $this->Query("SELECT a.id,a.file_group,a.file_name FROM label_artifacts a
            JOIN label_render_requests r ON r.id=a.render_request_id
            WHERE a.retention_class='preview' AND a.collected_at IS NULL
              AND r.expires_at IS NOT NULL AND r.expires_at<=CURRENT_TIMESTAMP
              AND NOT EXISTS (SELECT 1 FROM print_jobs j WHERE j.artifact_id=a.id)
            FOR UPDATE OF a")->fetchAll(\PDO::FETCH_ASSOC);

        $store = new LabelByteStore($this->db);
        foreach ($rows as $row) {
            $store->Delete($row['file_group'], $row['file_name']);
            // The manifest row stays. Removing an image leaves the record that an image
            // existed and was retained until a stated date, which is what makes an
            // unexplained print explainable afterwards.
            $this->Query('UPDATE label_artifacts SET collected_at=CURRENT_TIMESTAMP WHERE id=?', [$row['id']]);
        }
        return count($rows);
    }

    private function AssertLength(int $height, array $profile): void
    {
        $rules = $profile['length_rules'];
        if ($rules['kind'] === 'die_cut') {
            $expected = MediaProfileService::Pixels((int)$rules['fixed_um'], (int)$profile['dpi_y']);
            if ($height !== $expected) {
                $this->Refuse('artifact', 'geometry_mismatch', 'Die-cut media fixes the artifact at ' . $expected . ' px; the artifact is ' . $height . ' px');
            }
            return;
        }
        $min = MediaProfileService::Pixels((int)$rules['min_um'], (int)$profile['dpi_y']);
        $max = MediaProfileService::Pixels((int)$rules['max_um'], (int)$profile['dpi_y']);
        if ($height < $min || $height > $max) {
            $this->Refuse('artifact', 'media_incompatible', 'The artifact is ' . $height . ' px long and this endless media permits ' . $min . ' to ' . $max);
        }
    }

    /**
     * Samples the artifact's QR area and requires it to be exactly the matrix Victual
     * computed for the pinned payload.
     *
     * Re-encoding rather than decoding, and it is the stronger check: a decoder answers
     * "these modules mean that string", while this answers "these pixels are that symbol at
     * that geometry". Both would catch a QR pointing at the wrong shelf; only this one
     * catches a symbol drawn at the wrong module size, which is the failure that scans
     * indoors and not on a cold shelf.
     */
    private function AssertQr(array $input, array $facts, array $pixels, array $profile): void
    {
        $darkIndex = array_search('#000000', $profile['pixel_policy']['palette'], true);
        if ($darkIndex === false) {
            $this->Refuse('artifact', 'palette_mismatch', 'The profile palette has no black to draw a QR in');
        }

        foreach ($input['document']['elements'] as $element) {
            if ($element['type'] !== 'qr') {
                continue;
            }
            $matrix = $input['qr'][$element['id']];
            $modulePxX = MediaProfileService::PixelsFromMm($element['module_mm'], (int)$profile['dpi_x']);
            $modulePxY = MediaProfileService::PixelsFromMm($element['module_mm'], (int)$profile['dpi_y']);

            if ($modulePxX < 1 || $modulePxY < 1) {
                $this->Refuse('elements.' . $element['id'], 'qr_too_small', 'A module of ' . $element['module_mm'] . ' mm is under one device pixel on this profile');
            }

            $originX = MediaProfileService::PixelsFromMm($element['x_mm'], (int)$profile['dpi_x']) + $element['quiet_zone_modules'] * $modulePxX;
            $originY = MediaProfileService::PixelsFromMm($element['y_mm'], (int)$profile['dpi_y']) + $element['quiet_zone_modules'] * $modulePxY;

            for ($row = 0; $row < $matrix['size']; $row++) {
                for ($column = 0; $column < $matrix['size']; $column++) {
                    // The centre of the module, so a half-pixel of rounding either way in
                    // the renderer does not turn agreement into a failure.
                    $x = $originX + (int)($column * $modulePxX + intdiv($modulePxX, 2));
                    $y = $originY + (int)($row * $modulePxY + intdiv($modulePxY, 2));

                    if ($y >= $facts['height'] || $x >= $facts['width']) {
                        $this->Refuse('elements.' . $element['id'], 'qr_outside_artifact', 'The QR symbol extends past the artifact at module ' . $row . ',' . $column);
                    }

                    $dark = $pixels[$y][$x] === $darkIndex;
                    if ($dark !== ($matrix['modules'][$row][$column] === '1')) {
                        $this->Refuse('elements.' . $element['id'], 'qr_payload_mismatch', 'The artifact QR differs from the symbol for the pinned payload at module ' . $row . ',' . $column);
                    }
                }
            }
        }
    }
}
