<?php

namespace Victual\Services\Labels;

/**
 * What a print job pins.
 *
 * **Version 2**, per plan 27 piece 7: issuance now commits the mapping, the capture, the job
 * and this event atomically under a new payload version, because the job carries an artifact
 * it did not carry before. Version 1 was written while the artifact seam was still closed
 * and no deployment can hold a claimable v1 job - nothing was ever claimable - so the
 * version bump costs nothing and buys the honest thing: a payload a version cannot read is
 * dead-lettered with a reason rather than reinterpreted.
 *
 * The two halves have different jobs to do, and ADR-0019 decision item 4 is explicit about
 * it. Template identity and captured fields are **provenance**: they record which design and
 * which values the label was meant to express, which is what makes a wrong label diagnosable
 * and a revised print distinguishable from a reprint. The **artifact is the authority**: it
 * is what the worker sends and what an exact reprint replays. Where the two could disagree,
 * the bytes are what was printed.
 */
class PrintJobPayload
{
    public const PAYLOAD_VERSION = 2;

    public static function Build(string $uid, array $capture, int $printerId, string $operation, array $artifact, array $template, array $profile): array
    {
        return [
            'payload_version' => self::PAYLOAD_VERSION,
            'label_uid' => $uid,
            'kind' => $capture['entity_kind'],
            'operation' => $operation,
            // The only late-bound field, and it is late-bound because it describes the
            // delivery device rather than what happened. ADR-0019's consequences draw that
            // line explicitly.
            'printer_id' => $printerId,
            'captured_fields' => $capture['captured_fields'],
            'capture' => ['id' => (int)$capture['id'], 'digest' => $capture['digest'], 'locale' => $capture['locale'], 'timezone' => $capture['timezone']],
            'template' => $template,
            'profile' => ['id' => (int)$profile['id'], 'version' => (int)$profile['version'], 'digest' => $profile['document_digest']],
            // A reference to the stored bytes, the form, and the combination the artifact was
            // produced against - the three things ADR-0019 decision item 4 says a job names
            // about an artifact, and no more. The worker fetches the bytes rather than
            // receiving them inline.
            'artifact' => [
                'id' => (int)$artifact['id'],
                'form' => $artifact['form'],
                'byte_digest' => $artifact['byte_digest'],
                'byte_length' => (int)$artifact['byte_length'],
                'width_px' => (int)$artifact['width_px'],
                'height_px' => (int)$artifact['height_px'],
                'dpi_x' => (int)$artifact['dpi_x'],
                'dpi_y' => (int)$artifact['dpi_y'],
                'color_mode' => $artifact['color_mode'],
            ],
        ];
    }

    public static function DescribeUnreadable(mixed $payload): ?string
    {
        if (!is_array($payload) || ($payload['payload_version'] ?? null) !== self::PAYLOAD_VERSION) {
            return 'Unsupported label payload version';
        }
        if (!is_string($payload['label_uid'] ?? null) || !preg_match('/^[0-9A-F][0-9A-HJKMNP-TV-Z]{12}$/D', $payload['label_uid'])) {
            return 'Invalid label uid';
        }
        if (($payload['kind'] ?? null) !== 'location' || !is_int($payload['printer_id'] ?? null) || $payload['printer_id'] < 1) {
            return 'Invalid label target';
        }
        if (!in_array($payload['operation'] ?? null, ['issue', 'reprint', 'revised_print', 'promote_preview'], true)) {
            return 'Unknown print operation';
        }
        if (!is_string($payload['captured_fields']['location.name'] ?? null)) {
            return 'Missing captured location name';
        }
        if (!is_int($payload['artifact']['id'] ?? null) || !is_string($payload['artifact']['byte_digest'] ?? null)) {
            return 'Missing artifact reference';
        }
        if (($payload['artifact']['form'] ?? null) !== ArtifactService::FORM) {
            return 'Unsupported artifact form';
        }
        return null;
    }
}
