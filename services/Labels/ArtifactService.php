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
    private const FILE_LOCK_CLASS = 1986618177;

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

        // Collection owns the same lock while deciding whether the last uncollected
        // reference still needs the bytes. Wait for that decision before checking for a
        // live manifest or recreating the file. The collector only try-locks, so this
        // blocking acquisition cannot form a lock cycle with another collection.
        $this->LockFile(LabelByteStore::GROUP_ARTIFACTS, $fileName);
        $store = new LabelByteStore($this->db);
        if ($store->Get(LabelByteStore::GROUP_ARTIFACTS, $fileName) === null) {
            $store->Put(LabelByteStore::GROUP_ARTIFACTS, $fileName, $bytes, self::MIME_TYPE);
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
     *
     * The render queue's claim calls this on every poll with a $limit, so a large backlog is
     * worked off a batch at a time rather than inside one request.
     *
     * Nothing here waits for another claim (#899). The rows are taken SKIP LOCKED, and the
     * decision whether the stored bytes are still shared is made under a per-file advisory
     * try-lock. Without it, two claims holding two previews of one file would each see the
     * other's row still uncollected (the other's update is uncommitted), both keep the
     * bytes, and nobody ever deletes them. With it, the claim that loses the try-lock leaves
     * its row uncollected, and a later claim collects it once the holder has committed.
     * Promote() takes the same row lock, so a row it holds is skipped here and, once
     * promoted, is no longer a preview.
     *
     * Lock order inside one claim: ReapExpired's rows, ExpirePending's rows (SKIP LOCKED),
     * these rows (SKIP LOCKED), the per-file try-locks (never wait), then the settle loop's
     * rows (SKIP LOCKED). ReapExpired can wait, and so can DeadLetterWaitingJobs, which
     * updates print_jobs and outbox rows without SKIP LOCKED and is reached from ExpirePending
     * and, after this method's row and advisory locks are held, from the settle loop's
     * RecordInvalid. No cycle results: nothing ever waits on a try-lock, only Promote waits on
     * an artifact row, PromotePreview takes no print_jobs or outbox lock before Promote, and
     * the rows collected here are unreferenced by any print job.
     *
     * Correctness relies on READ COMMITTED: the loser's later sharer count needs a fresh
     * per-statement snapshot. Nothing in the codebase sets another isolation level.
     *
     * @return int The artifacts collected; rows skipped because another claim holds them are not counted.
     */
    public function CollectExpiredPreviews(?int $limit = null): int
    {
        $this->Transaction();
        $rows = $this->Query("SELECT a.id,a.file_group,a.file_name FROM label_artifacts a
            JOIN label_render_requests r ON r.id=a.render_request_id
            WHERE a.retention_class='preview' AND a.collected_at IS NULL
              AND r.expires_at IS NOT NULL AND r.expires_at<=CURRENT_TIMESTAMP
              AND NOT EXISTS (SELECT 1 FROM print_jobs j WHERE j.artifact_id=a.id)
            ORDER BY a.id" . ($limit === null ? '' : ' LIMIT ' . $limit) . "
            FOR UPDATE OF a SKIP LOCKED")->fetchAll(\PDO::FETCH_ASSOC);

        $store = new LabelByteStore($this->db);
        $collected = 0;
        foreach ($rows as $row) {
            if (!$this->TryLockFile($row['file_group'], $row['file_name'])) {
                continue;
            }
            // Reference-aware over the bytes as well as over the row: another manifest may
            // point at the same picture, and collecting this one must not take that one's
            // bytes with it.
            $shared = (int)$this->Query('SELECT COUNT(*) FROM label_artifacts WHERE file_group=? AND file_name=? AND collected_at IS NULL AND id<>?',
                [$row['file_group'], $row['file_name'], $row['id']])->fetchColumn();
            if ($shared === 0) {
                $store->Delete($row['file_group'], $row['file_name']);
            }
            // The manifest row stays. Removing an image leaves the record that an image
            // existed and was retained until a stated date, which is what makes an
            // unexplained print explainable afterwards.
            $this->Query('UPDATE label_artifacts SET collected_at=CURRENT_TIMESTAMP WHERE id=?', [$row['id']]);
            $collected++;
        }
        return $collected;
    }

    /**
     * Takes the transaction-scoped advisory lock on one stored file without waiting.
     *
     * The class id is "vicA" (0x76696341), apart from PostgresDialect's "vicS" stock lock; the
     * object id is a hash of the file, so a collision only causes one extra skip.
     */
    private function TryLockFile(string $group, string $name): bool
    {
        return (bool)$this->Query('SELECT pg_try_advisory_xact_lock(?::int, hashtext(?))', [self::FILE_LOCK_CLASS, $group . '/' . $name])->fetchColumn();
    }

    /** Takes the transaction-scoped lock shared with collection, waiting for its decision. */
    private function LockFile(string $group, string $name): void
    {
        $this->Query('SELECT pg_advisory_xact_lock(?::int, hashtext(?))', [self::FILE_LOCK_CLASS, $group . '/' . $name]);
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

        // Modules are located in the reading frame and then carried onto the raster by the
        // turn TemplateDocument's docblock states. Reading axes take the dpi of the physical
        // axis they become, which is why a quarter turn swaps them here as well.
        $orientation = (int)($input['document']['canvas']['orientation_deg'] ?? 0);
        $quarter = $orientation === 90 || $orientation === 270;
        $readingDpiX = (int)$profile[$quarter ? 'dpi_y' : 'dpi_x'];
        $readingDpiY = (int)$profile[$quarter ? 'dpi_x' : 'dpi_y'];
        $readingWidth = $quarter ? $facts['height'] : $facts['width'];
        $readingHeight = $quarter ? $facts['width'] : $facts['height'];

        foreach ($input['document']['elements'] as $element) {
            if ($element['type'] !== 'qr') {
                continue;
            }
            $matrix = $input['qr'][$element['id']];

            // The symbol is drawn unturned in a frame of its own, each axis at the dpi of the
            // reading axis it lands on, then turned by the element's own rotation_deg and
            // placed with its footprint's top-left at (x_mm, y_mm) - the same rule, one level
            // in, as the canvas turn below.
            $turn = (int)($element['rotation_deg'] ?? 0);
            $elementQuarter = $turn === 90 || $turn === 270;
            $localDpiX = $elementQuarter ? $readingDpiY : $readingDpiX;
            $localDpiY = $elementQuarter ? $readingDpiX : $readingDpiY;
            $modulePxX = MediaProfileService::PixelsFromMm($element['module_mm'], $localDpiX);
            $modulePxY = MediaProfileService::PixelsFromMm($element['module_mm'], $localDpiY);

            if ($modulePxX < 1 || $modulePxY < 1) {
                $this->Refuse('elements.' . $element['id'], 'qr_too_small', 'A module of ' . $element['module_mm'] . ' mm is under one device pixel on this profile');
            }

            $span = $matrix['size'] + 2 * $element['quiet_zone_modules'];
            $localWidth = $span * $modulePxX;
            $localHeight = $span * $modulePxY;
            $footX = MediaProfileService::PixelsFromMm($element['x_mm'], $readingDpiX);
            $footY = MediaProfileService::PixelsFromMm($element['y_mm'], $readingDpiY);

            for ($row = 0; $row < $matrix['size']; $row++) {
                for ($column = 0; $column < $matrix['size']; $column++) {
                    // The centre of the module, so a half-pixel of rounding either way in
                    // the renderer does not turn agreement into a failure.
                    $localX = ($element['quiet_zone_modules'] + $column) * $modulePxX + intdiv($modulePxX, 2);
                    $localY = ($element['quiet_zone_modules'] + $row) * $modulePxY + intdiv($modulePxY, 2);
                    [$turnedX, $turnedY] = self::TurnPoint($localX, $localY, $localWidth, $localHeight, $turn);
                    $readingX = $footX + $turnedX;
                    $readingY = $footY + $turnedY;

                    if ($readingY >= $readingHeight || $readingX >= $readingWidth) {
                        $this->Refuse('elements.' . $element['id'], 'qr_outside_artifact', 'The QR symbol extends past the artifact at module ' . $row . ',' . $column);
                    }
                    [$x, $y] = self::TurnPoint($readingX, $readingY, $readingWidth, $readingHeight, $orientation);

                    $dark = $pixels[$y][$x] === $darkIndex;
                    if ($dark !== ($matrix['modules'][$row][$column] === '1')) {
                        $this->Refuse('elements.' . $element['id'], 'qr_payload_mismatch', 'The artifact QR differs from the symbol for the pinned payload at module ' . $row . ',' . $column);
                    }
                }
            }
        }
    }

    /**
     * Where pixel (x, y) of a W x H image lands when the image is turned clockwise by
     * `degrees`: TemplateDocument's convention, shared by the canvas turn and an element's.
     *
     * @return array{0: int, 1: int}
     */
    private static function TurnPoint(int $x, int $y, int $width, int $height, int $degrees): array
    {
        return match ($degrees) {
            90 => [$height - 1 - $y, $x],
            180 => [$width - 1 - $x, $height - 1 - $y],
            270 => [$y, $width - 1 - $x],
            default => [$x, $y],
        };
    }
}
