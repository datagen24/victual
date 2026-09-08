<?php

namespace Victual\Services\Labels;

/**
 * Reads the facts `raster/png-indexed;v=1` names, straight out of the chunks.
 *
 * The form identifier pins colour type and bit depth rather than merely "PNG", because
 * without that "the same form" spans artifacts differing by an order of magnitude in size
 * and by whether the worker has to quantise before it can send. So the check is on IHDR and
 * PLTE, not on whether an image library was willing to open the file: GD will happily decode
 * an RGBA PNG and tell you its dimensions, which is exactly the artifact this form excludes.
 */
class IndexedPng
{
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** ADR-0021 prerequisite 2's selected form: colour type 3, bit depth 2. */
    public const COLOR_TYPE = 3;
    public const BIT_DEPTH = 2;

    /**
     * @return array{width: int, height: int, bit_depth: int, color_type: int, palette: array<int, string>}
     * @throws LabelValidationException
     */
    public static function Facts(string $bytes): array
    {
        if (strlen($bytes) < 8 || substr($bytes, 0, 8) !== self::SIGNATURE) {
            throw new LabelValidationException('artifact', 'invalid_artifact', 'The artifact is not a PNG');
        }

        $offset = 8;
        $header = null;
        $palette = null;
        $length = strlen($bytes);

        while ($offset + 8 <= $length) {
            $size = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);
            $data = substr($bytes, $offset + 8, $size);

            if ($offset + 12 + $size > $length) {
                throw new LabelValidationException('artifact', 'invalid_artifact', 'The artifact has a truncated ' . $type . ' chunk');
            }

            if ($type === 'IHDR') {
                $fields = unpack('Nwidth/Nheight/Cdepth/Ccolor', substr($data, 0, 10));
                $header = ['width' => $fields['width'], 'height' => $fields['height'], 'bit_depth' => $fields['depth'], 'color_type' => $fields['color']];
            } elseif ($type === 'PLTE') {
                if ($size % 3 !== 0) {
                    throw new LabelValidationException('artifact', 'invalid_artifact', 'The palette length is not a multiple of three');
                }
                $palette = [];
                for ($i = 0; $i < $size; $i += 3) {
                    $palette[] = sprintf('#%02x%02x%02x', ord($data[$i]), ord($data[$i + 1]), ord($data[$i + 2]));
                }
            } elseif ($type === 'IEND') {
                break;
            }

            $offset += 12 + $size;
        }

        if ($header === null) {
            throw new LabelValidationException('artifact', 'invalid_artifact', 'The artifact has no IHDR chunk');
        }
        if ($palette === null) {
            throw new LabelValidationException('artifact', 'invalid_artifact', 'The artifact carries no palette; raster/png-indexed;v=1 is an indexed image');
        }

        return $header + ['palette' => $palette];
    }

    /**
     * The pixel grid, as one string of palette indices per row.
     *
     * Decoded here rather than through GD because the verification has to read *indices*:
     * a comparison against the profile's palette that went through RGB would agree with an
     * artifact whose palette was reordered, and the index is what the worker sends.
     *
     * @return array<int, array<int, int>>
     */
    public static function Pixels(string $bytes, array $facts): array
    {
        $idat = '';
        $offset = 8;
        $length = strlen($bytes);

        while ($offset + 8 <= $length) {
            $size = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);
            if ($type === 'IDAT') {
                $idat .= substr($bytes, $offset + 8, $size);
            } elseif ($type === 'IEND') {
                break;
            }
            $offset += 12 + $size;
        }

        $raw = @gzuncompress($idat);
        if ($raw === false) {
            throw new LabelValidationException('artifact', 'invalid_artifact', 'The artifact image data does not inflate');
        }

        $depth = $facts['bit_depth'];
        $perByte = intdiv(8, $depth);
        $mask = (1 << $depth) - 1;
        $stride = intdiv($facts['width'] + $perByte - 1, $perByte);

        $rows = [];
        $previous = array_fill(0, $stride, 0);
        $position = 0;

        for ($y = 0; $y < $facts['height']; $y++) {
            if ($position >= strlen($raw)) {
                throw new LabelValidationException('artifact', 'invalid_artifact', 'The artifact has fewer scanlines than its header declares');
            }
            $filter = ord($raw[$position]);
            $position++;
            $line = array_values(unpack('C*', substr($raw, $position, $stride)));
            $position += $stride;

            // At bit depth 2 the filter unit is one byte, so Sub and Paeth both look back
            // exactly one byte. Reconstructing rather than requiring filter 0 keeps this
            // honest against any conformant encoder.
            for ($i = 0; $i < $stride; $i++) {
                $a = $i > 0 ? $line[$i - 1] : 0;
                $b = $previous[$i];
                $c = $i > 0 ? $previous[$i - 1] : 0;
                $line[$i] = match ($filter) {
                    0 => $line[$i],
                    1 => ($line[$i] + $a) & 0xFF,
                    2 => ($line[$i] + $b) & 0xFF,
                    3 => ($line[$i] + intdiv($a + $b, 2)) & 0xFF,
                    4 => ($line[$i] + self::Paeth($a, $b, $c)) & 0xFF,
                    default => throw new LabelValidationException('artifact', 'invalid_artifact', 'Unknown PNG filter type ' . $filter),
                };
            }
            $previous = $line;

            $row = [];
            for ($x = 0; $x < $facts['width']; $x++) {
                $byte = $line[intdiv($x, $perByte)];
                $shift = (($perByte - 1) - ($x % $perByte)) * $depth;
                $row[] = ($byte >> $shift) & $mask;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function Paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        return $pb <= $pc ? $b : $c;
    }
}
