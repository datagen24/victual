<?php

namespace Victual\Services\Labels;

/**
 * Fonts and raster images, validated on decode rather than on what the upload claimed.
 *
 * Plan 27 piece 2: version 1 accepts approved font formats and raster images; SVG and any
 * remote retrieval are excluded. Fonts are pinned by content and carry licensing metadata,
 * because a household that prints a label with a font is redistributing that font's output
 * and the record of what it was allowed to do has to live beside the bytes.
 *
 * "Validated by decoded dimensions" is not a formality. A declared `image/png` that decodes
 * to nothing is exactly the upload a bounds check on the declared type lets through, and a
 * renderer discovering it is a render that fails at print time instead of an upload that
 * fails while somebody is looking at it.
 */
class LabelAssetService extends LabelService
{
    private const FONT_MIMES = ['font/ttf' => 'ttf', 'font/otf' => 'otf', 'font/sfnt' => 'ttf'];
    private const IMAGE_MIMES = ['image/png' => 'png'];

    /** Decoded pixels, not compressed bytes: the bound a decompression bomb is measured against. */
    private const MAX_IMAGE_PIXELS = 16000000;

    public function Store(string $name, string $kind, string $mimeType, string $bytes, string $licence, ?string $licenceNotice): array
    {
        $this->Transaction();

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$/D', $name)) {
            $this->Refuse('name', 'invalid_value', 'An asset name is 1-64 characters of letters, digits, space, dot, underscore and hyphen');
        }
        if (trim($licence) === '') {
            $this->Refuse('licence', 'invalid_value', 'An asset records the licence it is used under');
        }
        if (strlen($bytes) === 0 || strlen($bytes) > LabelByteStore::MAX_BYTES) {
            $this->Refuse('content', 'value_out_of_range', 'An asset is between 1 and ' . LabelByteStore::MAX_BYTES . ' bytes');
        }

        $digest = hash('sha256', $bytes);
        $existing = $this->Query('SELECT * FROM label_assets WHERE content_digest=?', [$digest])->fetch(\PDO::FETCH_ASSOC);
        if ($existing) {
            // Identical bytes are one asset. Returning the stored row rather than refusing
            // makes an upload idempotent without a key, and a second name for the same font
            // would give two template versions different asset ids for the same glyphs.
            return $existing;
        }

        $decoded = match ($kind) {
            'font' => $this->DecodeFont($mimeType, $bytes),
            'image' => $this->DecodeImage($mimeType, $bytes),
            default => $this->Refuse('asset_kind', 'invalid_value', 'An asset is a font or an image'),
        };

        $extension = ($kind === 'font' ? self::FONT_MIMES[$mimeType] : self::IMAGE_MIMES[$mimeType]);
        $fileName = LabelByteStore::NameFor('asset', $digest, $extension);
        (new LabelByteStore($this->db))->Put(LabelByteStore::GROUP_ASSETS, $fileName, $bytes, $mimeType);

        return $this->Query('INSERT INTO label_assets(name,asset_kind,mime_type,byte_length,content_digest,file_group,file_name,width_px,height_px,font_family,font_style,licence,licence_notice)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING *',
            [$name, $kind, $mimeType, strlen($bytes), $digest, LabelByteStore::GROUP_ASSETS, $fileName,
                $decoded['width_px'] ?? null, $decoded['height_px'] ?? null, $decoded['font_family'] ?? null, $decoded['font_style'] ?? null,
                $licence, $licenceNotice])->fetch(\PDO::FETCH_ASSOC);
    }

    public function Bytes(int $assetId): ?string
    {
        $asset = $this->Query('SELECT file_group,file_name FROM label_assets WHERE id=?', [$assetId])->fetch(\PDO::FETCH_ASSOC);
        return $asset ? (new LabelByteStore($this->db))->Get($asset['file_group'], $asset['file_name']) : null;
    }

    private function DecodeImage(string $mimeType, string $bytes): array
    {
        if (!isset(self::IMAGE_MIMES[$mimeType])) {
            $this->Refuse('mime_type', 'invalid_value', 'v1 accepts ' . implode(', ', array_keys(self::IMAGE_MIMES)) . '; SVG is excluded because it is a program, not a picture');
        }
        $size = @getimagesizefromstring($bytes);
        if ($size === false || (int)$size[0] < 1 || (int)$size[1] < 1) {
            $this->Refuse('content', 'invalid_value', 'The upload does not decode as an image');
        }
        if ($size['mime'] !== $mimeType) {
            $this->Refuse('mime_type', 'invalid_value', 'The bytes decode as ' . $size['mime'] . ' rather than the declared ' . $mimeType);
        }
        if ((int)$size[0] * (int)$size[1] > self::MAX_IMAGE_PIXELS) {
            $this->Refuse('content', 'value_out_of_range', 'The image decodes to more than ' . self::MAX_IMAGE_PIXELS . ' pixels');
        }
        return ['width_px' => (int)$size[0], 'height_px' => (int)$size[1]];
    }

    /**
     * Reads the sfnt `name` table for family and subfamily.
     *
     * Enough of the format to establish that the bytes really are a font and to record what
     * it calls itself - which is what a template pins. A document that names a font by
     * asset is not choosing a family and hoping; it is choosing these bytes.
     */
    private function DecodeFont(string $mimeType, string $bytes): array
    {
        if (!isset(self::FONT_MIMES[$mimeType])) {
            $this->Refuse('mime_type', 'invalid_value', 'v1 accepts ' . implode(', ', array_keys(self::FONT_MIMES)));
        }
        if (strlen($bytes) < 12) {
            $this->Refuse('content', 'invalid_value', 'The upload is too short to be a font');
        }
        $tag = substr($bytes, 0, 4);
        if (!in_array($tag, ["\x00\x01\x00\x00", 'OTTO', 'true'], true)) {
            $this->Refuse('content', 'invalid_value', 'The upload does not begin with an sfnt signature; a collection or a web format is not a v1 asset');
        }
        $tables = unpack('nnum', substr($bytes, 4, 2))['num'];
        $nameOffset = null;
        $nameLength = null;
        for ($i = 0; $i < $tables; $i++) {
            $entry = substr($bytes, 12 + $i * 16, 16);
            if (strlen($entry) < 16) {
                break;
            }
            if (substr($entry, 0, 4) === 'name') {
                $nameOffset = unpack('Noff', substr($entry, 8, 4))['off'];
                $nameLength = unpack('Nlen', substr($entry, 12, 4))['len'];
            }
        }
        if ($nameOffset === null || $nameOffset + $nameLength > strlen($bytes)) {
            $this->Refuse('content', 'invalid_value', 'The font carries no readable name table');
        }

        $table = substr($bytes, $nameOffset, $nameLength);
        $count = unpack('ncount', substr($table, 2, 2))['count'];
        $stringOffset = unpack('noff', substr($table, 4, 2))['off'];
        $found = [];
        for ($i = 0; $i < $count; $i++) {
            $record = substr($table, 6 + $i * 12, 12);
            if (strlen($record) < 12) {
                break;
            }
            $fields = unpack('nplatform/nencoding/nlanguage/nname/nlength/noffset', $record);
            if (!in_array($fields['name'], [1, 2], true)) {
                continue;
            }
            $value = substr($table, $stringOffset + $fields['offset'], $fields['length']);
            // Platform 3 (Windows) and platform 0 (Unicode) store UTF-16BE; platform 1 is
            // MacRoman, which for these two names is ASCII in every font worth shipping.
            if ($fields['platform'] === 3 || $fields['platform'] === 0) {
                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-16BE');
            }
            $value = trim((string)$value);
            if ($value !== '' && !isset($found[$fields['name']])) {
                $found[$fields['name']] = $value;
            }
        }
        if (!isset($found[1])) {
            $this->Refuse('content', 'invalid_value', 'The font names no family');
        }
        return ['font_family' => $found[1], 'font_style' => $found[2] ?? 'Regular'];
    }
}
