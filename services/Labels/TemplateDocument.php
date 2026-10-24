<?php

namespace Victual\Services\Labels;

/**
 * The version 1 label template document: what it may contain, and what it means.
 *
 * A Victual-defined format rather than an editor's internal object graph, per plan 27
 * piece 1. Fabric.js draws it in the browser and the Rust renderer reads it headlessly, and
 * neither owns it - which is why unknown keys, unknown element types and an unknown
 * schema_version are refused here rather than approximated by whichever component saw them
 * first.
 *
 * **Geometry is physical.** Every position and size is in millimetres and every type size is
 * in points, because the two devices this has to be right on have different horizontal and
 * vertical resolutions. Horizontal geometry resolves against dpi_x and vertical against
 * dpi_y; 11 pt on a 300 x 600 device is a nominal em of 45.8 x 91.7 device pixels, reached
 * by scaling outlines anisotropically rather than by resampling a raster. Two renderer
 * candidates in the 2026-09-07 comparison sized at `size_pt * dpi_y / 72` and then measured
 * horizontal advances in that space, making every string twice as wide as its physical size
 * - the same class of silent geometry error as issue #90, arriving from the document format
 * instead of from a driver. The format therefore never carries a pixel.
 *
 * **Schema 2 adds orientation, and nothing else** (issue #1033). `canvas.orientation_deg` is
 * 0, 90, 180 or 270: the design as somebody reads it, turned clockwise by that much onto the
 * tape. The canvas keys keep their physical meaning - `width_mm` is the roll's width and
 * `height_mm`/`max_height_mm` the length along the tape - so media checks do not change.
 * Elements are positioned in the reading frame, which on a quarter turn is the tape length
 * wide and the roll's width tall. In pixels, reading (x, y) of a W x H reading image lands at
 * physical (H-1-y, x) for 90, (W-1-x, H-1-y) for 180 and (y, W-1-x) for 270; the renderer,
 * ArtifactService's QR sampler and the editor each implement that one sentence. A schema 1
 * document is validated and re-emitted as schema 1, so a published digest never moves.
 *
 * A schema 2 text, qr, image or rect element may carry `rotation_deg` from the same set. It
 * turns the element by the same rule inside the reading frame: drawn unturned in a frame of
 * its own `width_mm` x `height_mm`, turned clockwise, and placed so that the footprint's
 * top-left corner is (`x_mm`, `y_mm`). A quarter-turned footprint is `height_mm` wide.
 */
class TemplateDocument
{
    /** The version a new template starts at; the editor writes 2 only when it turns the design. */
    public const SCHEMA_VERSION = 1;

    /** Every version this validator understands. */
    public const SCHEMA_VERSIONS = [1, 2];

    /** Clockwise quarter turns a schema 2 design may be read at. */
    public const ORIENTATIONS = [0, 90, 180, 270];

    /** Colours a v1 element may paint. `red` is what makes a document need two-colour media. */
    public const COLORS = ['black', 'red', 'white'];

    private const ELEMENT_TYPES = ['text', 'qr', 'image', 'line', 'rect'];

    private const CANVAS_KEYS = ['width_mm', 'height_mm', 'max_height_mm', 'margins_mm', 'orientation_deg'];
    private const MARGIN_KEYS = ['top', 'right', 'bottom', 'left'];
    private const DOCUMENT_KEYS = ['schema_version', 'entity_kind', 'canvas', 'elements'];

    private const TEXT_KEYS = ['type', 'id', 'x_mm', 'y_mm', 'width_mm', 'height_mm', 'field', 'literal',
        'font_asset', 'size_pt', 'align', 'valign', 'wrap', 'line_spacing', 'overflow', 'min_size_pt', 'color', 'rotation_deg'];
    private const QR_KEYS = ['type', 'id', 'x_mm', 'y_mm', 'source', 'module_mm', 'ec_level', 'quiet_zone_modules', 'color', 'rotation_deg'];
    private const IMAGE_KEYS = ['type', 'id', 'x_mm', 'y_mm', 'width_mm', 'height_mm', 'asset', 'fit', 'color', 'rotation_deg'];
    private const LINE_KEYS = ['type', 'id', 'x1_mm', 'y1_mm', 'x2_mm', 'y2_mm', 'stroke_mm', 'color'];
    private const RECT_KEYS = ['type', 'id', 'x_mm', 'y_mm', 'width_mm', 'height_mm', 'stroke_mm', 'fill', 'color', 'rotation_deg'];

    private const OVERFLOW = ['error', 'ellipsis', 'shrink_to_fit'];
    private const EC_LEVELS = ['L', 'M', 'Q', 'H'];

    /** Bounded so a document cannot ask a renderer to do unbounded work (plan 27 piece 8). */
    private const MAX_ELEMENTS = 64;
    private const MAX_LITERAL_LENGTH = 512;
    private const MAX_DIMENSION_MM = 2000.0;

    /**
     * Validates a document and returns it with defaults resolved.
     *
     * @throws LabelValidationException Naming the element and the property, because "invalid
     *                                  template" is not something a designer can act on
     * @return array{document: array, asset_ids: array, required_capabilities: array, fields: array}
     */
    public static function Validate(array $document, string $entityKind): array
    {
        self::OnlyKeys($document, self::DOCUMENT_KEYS, 'document');

        $schema = $document['schema_version'] ?? null;
        if (!in_array($schema, self::SCHEMA_VERSIONS, true)) {
            self::Refuse('schema_version', 'unsupported_version', 'Only template schema_version ' . implode(' and ', self::SCHEMA_VERSIONS) . ' are understood; an unknown version is refused rather than approximated');
        }
        if (($document['entity_kind'] ?? null) !== $entityKind) {
            self::Refuse('entity_kind', 'entity_kind_mismatch', 'The document is for entity kind "' . (string)($document['entity_kind'] ?? '') . '" and the template is for "' . $entityKind . '"');
        }

        $canvas = self::ValidateCanvas($document['canvas'] ?? null, $schema);
        $bounds = self::ReadingBounds($canvas);
        $elements = $document['elements'] ?? null;

        if (!is_array($elements) || !array_is_list($elements) || count($elements) === 0) {
            self::Refuse('elements', 'invalid_value', 'A template draws at least one element');
        }
        if (count($elements) > self::MAX_ELEMENTS) {
            self::Refuse('elements', 'value_out_of_range', 'A template draws at most ' . self::MAX_ELEMENTS . ' elements');
        }

        $catalogue = FieldCatalogue::For($entityKind);
        $seen = [];
        $assets = [];
        $fields = [];
        $usesRed = false;
        $normalized = [];

        foreach ($elements as $index => $element) {
            if (!is_array($element)) {
                self::Refuse("elements[$index]", 'invalid_value', 'An element is an object');
            }
            $type = $element['type'] ?? null;
            if (!in_array($type, self::ELEMENT_TYPES, true)) {
                self::Refuse("elements[$index].type", 'unsupported_element', 'Unknown element type "' . (string)$type . '"; v1 draws ' . implode(', ', self::ELEMENT_TYPES));
            }
            $id = $element['id'] ?? null;
            if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $id)) {
                self::Refuse("elements[$index].id", 'invalid_value', 'An element id is 1-64 characters of lowercase letters, digits, underscore and hyphen');
            }
            if (isset($seen[$id])) {
                self::Refuse("elements[$index].id", 'duplicate_element_id', 'Element id "' . $id . '" is used twice; errors name an element, so ids are unique');
            }
            $seen[$id] = true;

            // A schema 2 element other than a line may be turned too (phase 2 of #1033): the
            // same quarter turns, about the footprint, as TemplateDocument's docblock states.
            if (array_key_exists('rotation_deg', $element)) {
                if ($schema < 2) {
                    self::Refuse("elements.$id.rotation_deg", 'unknown_property', 'rotation_deg is a schema_version 2 property');
                }
                if (!in_array($element['rotation_deg'], self::ORIENTATIONS, true)) {
                    self::Refuse("elements.$id.rotation_deg", 'invalid_value', 'rotation_deg is one of ' . implode(', ', self::ORIENTATIONS));
                }
            }

            $resolved = match ($type) {
                'text' => self::ValidateText($element, $id, $bounds, $catalogue, $assets, $fields, $usesRed),
                'qr' => self::ValidateQr($element, $id, $bounds, $usesRed),
                'image' => self::ValidateImage($element, $id, $bounds, $assets, $usesRed),
                'line' => self::ValidateLine($element, $id, $bounds, $usesRed),
                'rect' => self::ValidateRect($element, $id, $bounds, $usesRed),
            };
            // Kept only when stated, so a schema 2 document written before element rotation
            // existed normalizes to the same bytes, and the same digest, as it did then.
            if (array_key_exists('rotation_deg', $element)) {
                $resolved['rotation_deg'] = $element['rotation_deg'];
            }
            $normalized[] = $resolved;
        }

        // The capability the document requires is derived from what it draws, never declared:
        // a template that says "monochrome" while painting red would be admitted against a
        // printer that cannot print it, and the refusal would arrive at the device.
        $required = ['color_mode' => $usesRed ? 'black_red' : 'monochrome'];

        return [
            'document' => ['schema_version' => $schema, 'entity_kind' => $entityKind, 'canvas' => $canvas, 'elements' => $normalized],
            'asset_ids' => array_values(array_unique($assets)),
            'required_capabilities' => $required,
            'fields' => array_values(array_unique($fields)),
        ];
    }

    /**
     * The element that reaches furthest across the roll, and how far, in millimetres.
     *
     * `canvas.width_mm` bounds a document, and the media bounds the raster: the renderer
     * sizes its pixmap from the profile's `raster_width_px`, so a box between the two is
     * cropped on paper without a word. A canvas of 58.9 mm (the width the driver claimed for
     * DK-22251 until 2026-10-24) passes its own validation and still has 0.48 mm the 58.42 mm
     * roll cannot print. RenderRequestService compares this extent with the profile.
     *
     * Upright or half-turned, across the roll is reading x; on a quarter turn it is reading
     * y. A turned element's footprint is used, and a line's endpoints. A QR code is left out:
     * its size depends on the payload, and ArtifactService samples every rendered symbol.
     *
     * @param array $document A validated document, as Validate() returns it.
     * @return array{id: string, extent_mm: float}|null Null when no element is measured.
     */
    public static function FurthestAcrossRoll(array $document): ?array
    {
        $orientation = $document['canvas']['orientation_deg'] ?? 0;
        $quarter = $orientation === 90 || $orientation === 270;
        $furthest = null;
        foreach ($document['elements'] as $element) {
            if ($element['type'] === 'qr') {
                continue;
            }
            if ($element['type'] === 'line') {
                $extent = $quarter ? max($element['y1_mm'], $element['y2_mm']) : max($element['x1_mm'], $element['x2_mm']);
            } else {
                $turn = $element['rotation_deg'] ?? 0;
                [$across, $down] = $turn === 90 || $turn === 270
                    ? [$element['height_mm'], $element['width_mm']]
                    : [$element['width_mm'], $element['height_mm']];
                $extent = $quarter ? $element['y_mm'] + $down : $element['x_mm'] + $across;
            }
            if ($furthest === null || $extent > $furthest['extent_mm']) {
                $furthest = ['id' => (string)$element['id'], 'extent_mm' => (float)$extent];
            }
        }
        return $furthest;
    }

    /**
     * The extent elements are bounded by, in the reading frame.
     *
     * Upright, that is the roll's width across, and down it is a fixed `height_mm` when the
     * canvas has one. An automatic length is left to the renderer's length rules, which grow
     * the label to its content. A fixed length is a hard bound: the renderer draws at that
     * size and crops what passes it, which is how a box at 20.8 mm + 4.6 mm on a 25 mm label
     * lost its bottom on paper on 2026-10-23. On a quarter turn the reading width is the
     * length along the tape (fixed, or its automatic maximum) and the reading height is the
     * roll's width.
     *
     * Margins are not a bound here. The renderer uses them only to pad an automatic length,
     * and a frame drawn into the margin is a design, so the designer warns about an element
     * that crosses one rather than this refusing it.
     *
     * @return array{width_mm: float, height_mm: ?float}
     */
    private static function ReadingBounds(array $canvas): array
    {
        $orientation = $canvas['orientation_deg'] ?? 0;
        if ($orientation === 90 || $orientation === 270) {
            return ['width_mm' => $canvas['height_mm'] ?? $canvas['max_height_mm'], 'height_mm' => $canvas['width_mm']];
        }
        return ['width_mm' => $canvas['width_mm'], 'height_mm' => $canvas['height_mm']];
    }

    private static function ValidateCanvas(mixed $canvas, int $schema): array
    {
        if (!is_array($canvas)) {
            self::Refuse('canvas', 'invalid_value', 'A template has a canvas');
        }
        self::OnlyKeys($canvas, self::CANVAS_KEYS, 'canvas');

        $width = self::Millimetres($canvas['width_mm'] ?? null, 'canvas.width_mm', false);

        // Automatic height is bounded, per ADR-0021 decision item 1: content extent plus
        // explicit spacing, checked against the profile's length rules at render time. It
        // never crops and it never grows without a bound, because continuous tape is not an
        // unbounded canvas - so a document that leaves height open has to say how far.
        $height = array_key_exists('height_mm', $canvas) && $canvas['height_mm'] !== null
            ? self::Millimetres($canvas['height_mm'], 'canvas.height_mm', false)
            : null;
        $max = array_key_exists('max_height_mm', $canvas) && $canvas['max_height_mm'] !== null
            ? self::Millimetres($canvas['max_height_mm'], 'canvas.max_height_mm', false)
            : null;

        if ($height === null && $max === null) {
            self::Refuse('canvas.max_height_mm', 'unbounded_automatic_height', 'A canvas with automatic height states max_height_mm; continuous tape is not an unbounded canvas');
        }
        if ($height !== null && $max !== null && $max < $height) {
            self::Refuse('canvas.max_height_mm', 'value_out_of_range', 'max_height_mm is below the fixed height_mm');
        }

        $margins = $canvas['margins_mm'] ?? ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0];
        if (!is_array($margins)) {
            self::Refuse('canvas.margins_mm', 'invalid_value', 'Margins are an object of four millimetre values');
        }
        self::OnlyKeys($margins, self::MARGIN_KEYS, 'canvas.margins_mm');
        $resolved = [];
        foreach (self::MARGIN_KEYS as $side) {
            $resolved[$side] = self::Millimetres($margins[$side] ?? 0, "canvas.margins_mm.$side", true);
        }
        $orientation = 0;
        if (array_key_exists('orientation_deg', $canvas)) {
            if ($schema < 2) {
                self::Refuse('canvas.orientation_deg', 'unknown_property', 'orientation_deg is a schema_version 2 property');
            }
            $orientation = $canvas['orientation_deg'];
            if (!in_array($orientation, self::ORIENTATIONS, true)) {
                self::Refuse('canvas.orientation_deg', 'invalid_value', 'orientation_deg is one of ' . implode(', ', self::ORIENTATIONS));
            }
        }

        // Margins are in the reading frame, so on a quarter turn the pair that has to leave
        // room across the roll is top and bottom.
        $quarter = $orientation === 90 || $orientation === 270;
        if ($quarter ? $resolved['top'] + $resolved['bottom'] >= $width : $resolved['left'] + $resolved['right'] >= $width) {
            self::Refuse('canvas.margins_mm', 'value_out_of_range', ($quarter ? 'Vertical' : 'Horizontal') . ' margins leave no printable width');
        }

        $resolvedCanvas = ['width_mm' => $width, 'height_mm' => $height, 'max_height_mm' => $max, 'margins_mm' => $resolved];
        if ($schema >= 2) {
            $resolvedCanvas['orientation_deg'] = $orientation;
        }
        return $resolvedCanvas;
    }

    private static function ValidateText(array $e, string $id, array $canvas, array $catalogue, array &$assets, array &$fields, bool &$usesRed): array
    {
        self::OnlyKeys($e, self::TEXT_KEYS, "elements.$id");
        $box = self::Box($e, $id, $canvas);

        $hasField = array_key_exists('field', $e) && $e['field'] !== null;
        $hasLiteral = array_key_exists('literal', $e) && $e['literal'] !== null;

        if ($hasField === $hasLiteral) {
            self::Refuse("elements.$id.field", 'invalid_value', 'A text element carries exactly one of field or literal');
        }
        if ($hasField) {
            if (!is_string($e['field']) || !isset($catalogue[$e['field']])) {
                self::Refuse("elements.$id.field", 'unknown_field', 'Field "' . (string)$e['field'] . '" is not in the catalogue for this entity kind');
            }
            $fields[] = $e['field'];
        } else {
            if (!is_string($e['literal']) || $e['literal'] === '' || mb_strlen($e['literal']) > self::MAX_LITERAL_LENGTH) {
                self::Refuse("elements.$id.literal", 'value_out_of_range', 'A literal is 1 to ' . self::MAX_LITERAL_LENGTH . ' characters');
            }
        }

        $font = $e['font_asset'] ?? null;
        if (!is_string($font) || $font === '') {
            self::Refuse("elements.$id.font_asset", 'invalid_value', 'Text pins a font asset by name; there is no default font and no substitution');
        }
        $assets[] = $font;

        $size = $e['size_pt'] ?? null;
        if (!is_int($size) && !is_float($size)) {
            self::Refuse("elements.$id.size_pt", 'invalid_value', 'size_pt is a number of points');
        }
        $size = (float)$size;
        if ($size < 3.0 || $size > 288.0) {
            self::Refuse("elements.$id.size_pt", 'value_out_of_range', 'size_pt is between 3 and 288');
        }

        $overflow = $e['overflow'] ?? 'error';
        if (!in_array($overflow, self::OVERFLOW, true)) {
            self::Refuse("elements.$id.overflow", 'invalid_value', 'overflow is one of ' . implode(', ', self::OVERFLOW));
        }
        $minimum = null;
        if ($overflow === 'shrink_to_fit') {
            $minimum = $e['min_size_pt'] ?? null;
            if ((!is_int($minimum) && !is_float($minimum)) || (float)$minimum <= 0.0 || (float)$minimum > $size) {
                self::Refuse("elements.$id.min_size_pt", 'value_out_of_range', 'shrink_to_fit is bounded: min_size_pt is above zero and not above size_pt');
            }
            $minimum = (float)$minimum;
        } elseif (array_key_exists('min_size_pt', $e) && $e['min_size_pt'] !== null) {
            self::Refuse("elements.$id.min_size_pt", 'invalid_value', 'min_size_pt applies only to overflow shrink_to_fit');
        }

        $align = $e['align'] ?? 'left';
        if (!in_array($align, ['left', 'center', 'right'], true)) {
            self::Refuse("elements.$id.align", 'invalid_value', 'align is left, center or right');
        }
        $valign = $e['valign'] ?? 'top';
        if (!in_array($valign, ['top', 'middle', 'bottom'], true)) {
            self::Refuse("elements.$id.valign", 'invalid_value', 'valign is top, middle or bottom');
        }
        $wrap = $e['wrap'] ?? true;
        if (!is_bool($wrap)) {
            self::Refuse("elements.$id.wrap", 'invalid_value', 'wrap is a boolean');
        }
        $spacing = $e['line_spacing'] ?? 1.2;
        if ((!is_int($spacing) && !is_float($spacing)) || (float)$spacing < 0.5 || (float)$spacing > 4.0) {
            self::Refuse("elements.$id.line_spacing", 'value_out_of_range', 'line_spacing is between 0.5 and 4');
        }

        return $box + [
            'type' => 'text', 'id' => $id,
            'field' => $hasField ? $e['field'] : null,
            'literal' => $hasLiteral ? $e['literal'] : null,
            'font_asset' => $font, 'size_pt' => $size, 'align' => $align, 'valign' => $valign,
            'wrap' => $wrap, 'line_spacing' => (float)$spacing, 'overflow' => $overflow,
            'min_size_pt' => $minimum, 'color' => self::Color($e, $id, $usesRed),
        ];
    }

    private static function ValidateQr(array $e, string $id, array $canvas, bool &$usesRed): array
    {
        self::OnlyKeys($e, self::QR_KEYS, "elements.$id");

        // Never a user-editable literal for a production label. The payload is the server's,
        // which is what stops a designer pointing a shelf's QR at something else.
        if (($e['source'] ?? 'label.payload') !== 'label.payload') {
            self::Refuse("elements.$id.source", 'invalid_value', 'A QR encodes label.payload and nothing else; a literal payload is not a v1 feature');
        }

        $module = $e['module_mm'] ?? null;
        if ((!is_int($module) && !is_float($module)) || (float)$module < 0.15 || (float)$module > 5.0) {
            self::Refuse("elements.$id.module_mm", 'value_out_of_range', 'module_mm is the minimum physical module size, between 0.15 and 5');
        }
        $ec = $e['ec_level'] ?? 'M';
        if (!in_array($ec, self::EC_LEVELS, true)) {
            self::Refuse("elements.$id.ec_level", 'invalid_value', 'ec_level is one of ' . implode(', ', self::EC_LEVELS));
        }
        $quiet = $e['quiet_zone_modules'] ?? 4;
        if (!is_int($quiet) || $quiet < 4 || $quiet > 16) {
            self::Refuse("elements.$id.quiet_zone_modules", 'value_out_of_range', 'The quiet zone is at least the four modules the symbology requires');
        }

        return [
            'type' => 'qr', 'id' => $id,
            'x_mm' => self::Millimetres($e['x_mm'] ?? null, "elements.$id.x_mm", true),
            'y_mm' => self::Millimetres($e['y_mm'] ?? null, "elements.$id.y_mm", true),
            'source' => 'label.payload', 'module_mm' => (float)$module, 'ec_level' => $ec,
            'quiet_zone_modules' => $quiet, 'color' => self::Color($e, $id, $usesRed),
        ];
    }

    private static function ValidateImage(array $e, string $id, array $canvas, array &$assets, bool &$usesRed): array
    {
        self::OnlyKeys($e, self::IMAGE_KEYS, "elements.$id");
        $box = self::Box($e, $id, $canvas);
        $asset = $e['asset'] ?? null;
        if (!is_string($asset) || $asset === '') {
            self::Refuse("elements.$id.asset", 'invalid_value', 'An image element names a stored asset; there is no external URL and no remote retrieval');
        }
        $assets[] = $asset;
        $fit = $e['fit'] ?? 'contain';
        if (!in_array($fit, ['contain', 'stretch'], true)) {
            self::Refuse("elements.$id.fit", 'invalid_value', 'fit is contain or stretch');
        }
        return $box + ['type' => 'image', 'id' => $id, 'asset' => $asset, 'fit' => $fit, 'color' => self::Color($e, $id, $usesRed)];
    }

    private static function ValidateLine(array $e, string $id, array $canvas, bool &$usesRed): array
    {
        self::OnlyKeys($e, self::LINE_KEYS, "elements.$id");
        $stroke = $e['stroke_mm'] ?? null;
        if ((!is_int($stroke) && !is_float($stroke)) || (float)$stroke <= 0.0 || (float)$stroke > 20.0) {
            self::Refuse("elements.$id.stroke_mm", 'value_out_of_range', 'stroke_mm is above zero and at most 20');
        }
        return [
            'type' => 'line', 'id' => $id,
            'x1_mm' => self::Millimetres($e['x1_mm'] ?? null, "elements.$id.x1_mm", true),
            'y1_mm' => self::Millimetres($e['y1_mm'] ?? null, "elements.$id.y1_mm", true),
            'x2_mm' => self::Millimetres($e['x2_mm'] ?? null, "elements.$id.x2_mm", true),
            'y2_mm' => self::Millimetres($e['y2_mm'] ?? null, "elements.$id.y2_mm", true),
            'stroke_mm' => (float)$stroke, 'color' => self::Color($e, $id, $usesRed),
        ];
    }

    private static function ValidateRect(array $e, string $id, array $canvas, bool &$usesRed): array
    {
        self::OnlyKeys($e, self::RECT_KEYS, "elements.$id");
        $box = self::Box($e, $id, $canvas);
        $stroke = $e['stroke_mm'] ?? 0.0;
        if ((!is_int($stroke) && !is_float($stroke)) || (float)$stroke < 0.0 || (float)$stroke > 20.0) {
            self::Refuse("elements.$id.stroke_mm", 'value_out_of_range', 'stroke_mm is between 0 and 20');
        }
        $fill = $e['fill'] ?? null;
        if ($fill !== null && !in_array($fill, self::COLORS, true)) {
            self::Refuse("elements.$id.fill", 'invalid_value', 'fill is null or one of ' . implode(', ', self::COLORS));
        }
        if ($fill === 'red') {
            $usesRed = true;
        }
        if ((float)$stroke === 0.0 && $fill === null) {
            self::Refuse("elements.$id.stroke_mm", 'invalid_value', 'A rectangle with no stroke and no fill paints nothing');
        }
        return $box + ['type' => 'rect', 'id' => $id, 'stroke_mm' => (float)$stroke, 'fill' => $fill, 'color' => self::Color($e, $id, $usesRed)];
    }

    private static function Box(array $e, string $id, array $canvas): array
    {
        $x = self::Millimetres($e['x_mm'] ?? null, "elements.$id.x_mm", true);
        $y = self::Millimetres($e['y_mm'] ?? null, "elements.$id.y_mm", true);
        $w = self::Millimetres($e['width_mm'] ?? null, "elements.$id.width_mm", false);
        $h = self::Millimetres($e['height_mm'] ?? null, "elements.$id.height_mm", false);

        // Bounded by its footprint: a quarter-turned box is as wide as it is tall.
        $turn = $e['rotation_deg'] ?? 0;
        [$across, $down] = $turn === 90 || $turn === 270 ? [$h, $w] : [$w, $h];
        if ($x + $across > $canvas['width_mm'] + 0.0001) {
            self::Refuse("elements.$id.width_mm", 'element_outside_canvas', 'The element extends past the canvas width');
        }
        if ($canvas['height_mm'] !== null && $y + $down > $canvas['height_mm'] + 0.0001) {
            self::Refuse("elements.$id.height_mm", 'element_outside_canvas', 'The element extends past the bottom of the canvas: its fixed length when upright, the roll when the design is turned');
        }
        return ['x_mm' => $x, 'y_mm' => $y, 'width_mm' => $w, 'height_mm' => $h];
    }

    private static function Color(array $e, string $id, bool &$usesRed): string
    {
        $color = $e['color'] ?? 'black';
        if (!in_array($color, self::COLORS, true)) {
            self::Refuse("elements.$id.color", 'invalid_value', 'color is one of ' . implode(', ', self::COLORS));
        }
        if ($color === 'red') {
            $usesRed = true;
        }
        return $color;
    }

    private static function Millimetres(mixed $value, string $field, bool $allowZero): float
    {
        if (!is_int($value) && !is_float($value)) {
            self::Refuse($field, 'invalid_value', 'A physical dimension is a number of millimetres');
        }
        $value = (float)$value;
        if (is_nan($value) || is_infinite($value)) {
            self::Refuse($field, 'invalid_value', 'A physical dimension is finite');
        }
        if ($allowZero ? $value < 0.0 : $value <= 0.0) {
            self::Refuse($field, 'value_out_of_range', $allowZero ? 'A position is not negative' : 'A size is above zero');
        }
        if ($value > self::MAX_DIMENSION_MM) {
            self::Refuse($field, 'value_out_of_range', 'A physical dimension is at most ' . self::MAX_DIMENSION_MM . ' mm');
        }
        return $value;
    }

    /**
     * Unknown properties are refused rather than dropped. A designer that quietly loses a
     * property it did not recognise produces a label nobody asked for and no record of why.
     */
    private static function OnlyKeys(array $subject, array $allowed, string $where): void
    {
        foreach (array_keys($subject) as $key) {
            if (!in_array((string)$key, $allowed, true)) {
                self::Refuse($where . '.' . $key, 'unknown_property', 'Unknown property "' . $key . '"; v1 accepts ' . implode(', ', $allowed));
            }
        }
    }

    private static function Refuse(string $field, string $code, string $message): never
    {
        throw new LabelValidationException($field, $code, $message);
    }
}
