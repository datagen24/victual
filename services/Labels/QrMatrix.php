<?php

namespace Victual\Services\Labels;

use jucksearm\barcode\lib\QRcode;

/**
 * The QR module matrix for a label payload, computed by Victual.
 *
 * The renderer is handed this matrix rather than a string to encode, and that is a decision
 * rather than a convenience. Three things follow from it:
 *
 * - **Verification becomes exact.** Plan 27 piece 4 requires the receiving service to check
 *   that the QR decodes to the pinned payload before marking an artifact ready. Re-encoding
 *   and comparing modules is a stronger statement than decoding - it pins the geometry as
 *   well as the content - but only if both sides chose the same mask, and they do here
 *   because only one side chooses.
 * - **The renderer needs no QR library**, which is closure it does not carry and a second
 *   implementation of a symbology that cannot disagree with the first.
 * - **A designer cannot point a shelf's QR anywhere.** `TemplateDocument` already refuses a
 *   literal payload; this is where the server-supplied one is produced.
 */
class QrMatrix
{
    /**
     * @return array{modules: array<int, string>, size: int, ec_level: string, payload: string}
     *         `modules` is one string of '0' and '1' per row, dark being '1'.
     */
    public static function For(string $payload, string $ecLevel): array
    {
        $encoded = (new QRcode($payload, $ecLevel))->getBarcodeArray();

        if (!is_array($encoded) || ($encoded['num_rows'] ?? 0) < 21) {
            throw new LabelValidationException('qr', 'qr_encoding_failed', 'The label payload does not encode as a QR symbol');
        }

        $rows = [];
        foreach ($encoded['bcode'] as $row) {
            $rows[] = implode('', array_map(static fn ($module) => $module ? '1' : '0', $row));
        }

        return ['modules' => $rows, 'size' => (int)$encoded['num_rows'], 'ec_level' => $ecLevel, 'payload' => $payload];
    }

    /** The payload a label uid is carried as. ADR-0011 fixes the prefix and the casing. */
    public static function Payload(string $uid): string
    {
        return 'VCTL:' . strtoupper($uid);
    }
}
