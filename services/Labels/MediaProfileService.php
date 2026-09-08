<?php

namespace Victual\Services\Labels;

use Victual\Helpers\CanonicalJson;

/**
 * Immutable, versioned, digested media profiles, derived from a driver's validated
 * capability document.
 *
 * Deliberately not the printer row. A printer's address changes, its media is reconfigured
 * and its driver version is moved by an admin; the geometry a stored artifact was rendered
 * against cannot change without making that artifact a lie about what will come out. So a
 * profile is derived once from a `combinations` entry, digested, and never rewritten.
 *
 * The profile is also where physical-to-pixel rounding is specified, once. Two components
 * rounding independently is how issue #90 happened: the prototype authored against
 * `dots_total` while the library compared against `dots_printable`, and every endless print
 * was silently resampled.
 */
class MediaProfileService extends LabelService
{
    public const CONTRACT_VERSION = 1;

    /**
     * Derives - or returns - the profile for one printer's resolved combination.
     *
     * Idempotent by digest: the same combination out of the same immutable driver definition
     * produces the same document, so a second call returns the stored version rather than
     * minting a second one that says the same thing.
     */
    public function Ensure(array $printer, array $driver, array $combination): array
    {
        $this->Transaction();

        $key = $printer['driver_id'] . '/' . $printer['driver_schema_version'] . '/' . $printer['connection_type']
            . '/' . $combination['model'] . '/' . $combination['media'] . '/' . $combination['color_mode']
            . '/' . $combination['resolution_x'] . 'x' . $combination['resolution_y'];

        $document = $this->Document($printer, $driver, $combination);
        $digest = CanonicalJson::Digest($document);

        $existing = $this->Query('SELECT * FROM label_media_profiles WHERE profile_key=? AND document_digest=?', [$key, $digest])->fetch(\PDO::FETCH_ASSOC);
        if ($existing) {
            $existing['document'] = json_decode($existing['document'], true);
            return $existing;
        }

        $version = (int)$this->Query('SELECT COALESCE(MAX(version),0)+1 FROM label_media_profiles WHERE profile_key=?', [$key])->fetchColumn();

        $row = $this->Query('INSERT INTO label_media_profiles(profile_key,version,driver_id,driver_schema_version,model,media,connection_type,color_mode,dpi_x,dpi_y,raster_width_px,document,document_digest)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?::jsonb,?) RETURNING *',
            [$key, $version, $printer['driver_id'], $printer['driver_schema_version'], $combination['model'], $combination['media'],
                $printer['connection_type'], $combination['color_mode'], (int)$combination['resolution_x'], (int)$combination['resolution_y'],
                $document['raster_width_px'], $this->Json($document), $digest])->fetch(\PDO::FETCH_ASSOC);
        $row['document'] = $document;
        return $row;
    }

    public function Get(int $profileId): array
    {
        $row = $this->Query('SELECT * FROM label_media_profiles WHERE id=?', [$profileId])->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            $this->Refuse('profile_id', 'not_found', 'No such media profile');
        }
        $row['document'] = json_decode($row['document'], true);
        return $row;
    }

    /**
     * The profile document.
     *
     * Every length is micrometres on the way in, because that is the unit ADR-0019's
     * capability contract states its geometry in and converting once here is one conversion
     * rather than one per consumer. Explicit units are load-bearing: a contract that says
     * "width: 696" is issue #90 written down again.
     */
    private function Document(array $printer, array $driver, array $combination): array
    {
        $dpiX = (int)$combination['resolution_x'];
        $dpiY = (int)$combination['resolution_y'];
        $widthUm = (int)$combination['printable_width_um'];

        $length = $combination['printable_length_um'];
        $lengthRules = is_array($length)
            ? ['kind' => 'endless', 'min_um' => (int)$length['min'], 'max_um' => (int)$length['max']]
            : ['kind' => 'die_cut', 'fixed_um' => (int)$length];

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'driver_id' => $printer['driver_id'],
            'driver_schema_version' => $printer['driver_schema_version'],
            'connection_type' => $printer['connection_type'],
            'model' => $combination['model'],
            'media' => $combination['media'],
            'color_mode' => $combination['color_mode'],
            'dpi_x' => $dpiX,
            'dpi_y' => $dpiY,
            'printable_width_um' => $widthUm,
            'length_rules' => $lengthRules,
            'feed_direction' => $combination['feed_direction'],
            // The raster is exactly this wide. ADR-0019 gives the artifact form
            // `geometry: fixed_grid`, so the worker scales nothing and a grid that does not
            // match is a refusal rather than a resize - which is the half of issue #90 the
            // document format can actually prevent.
            'raster_width_px' => self::Pixels($widthUm, $dpiX),
            'pixel_policy' => [
                'version' => 1,
                // Rounding is stated once, here, and every consumer applies this rule
                // rather than its own: physical micrometres to pixels rounds to nearest,
                // and content is never resampled to make a number come out.
                'rounding' => 'nearest',
                'threshold' => ['method' => 'luminance', 'black_at_or_below' => 127],
                'palette' => $combination['color_mode'] === 'black_red'
                    ? ['#ffffff', '#000000', '#ff0000']
                    : ['#ffffff', '#000000'],
            ],
            // Declared non-printing transport padding may be added by the worker; content
            // may not be resampled to fit it. The two are different operations and only one
            // of them changes what the label says.
            'transport_padding' => ['leading_px' => 0, 'trailing_px' => 0],
        ];
    }

    public static function Pixels(int $micrometres, int $dpi): int
    {
        return (int)round($micrometres * $dpi / 25400.0);
    }

    /** Millimetres to device pixels on one axis, by the profile's stated rounding. */
    public static function PixelsFromMm(float $millimetres, int $dpi): int
    {
        return (int)round($millimetres * $dpi / 25.4);
    }
}
