<?php

namespace Victual\Tests\Pgsql;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Victual\Services\Labels\FieldCatalogue;

/**
 * Keeps the label designer's hand-written copy of the field catalogue equal to the server's.
 *
 * public/viewjs/labeltemplateeditor.js lists every merge field per entity kind and shows each
 * one's example value beside its description. The editor's authoritative preview prints
 * FieldCatalogue::SampleFor(), so an example that drifted from it would tell a designer one
 * thing and show them another. Needs no database.
 */
class LabelEditorFieldCatalogueTest extends TestCase
{
    /**
     * @return string the source of the editor script
     */
    private static function EditorSource(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/viewjs/labeltemplateeditor.js');
        self::assertIsString($source);

        return $source;
    }

    /**
     * The entity kinds the server has a field catalogue for.
     *
     * @return array<string, array{0: string}>
     */
    public static function Kinds(): array
    {
        $kinds = [];
        foreach (['location', 'product', 'stock_entry', 'recipe', 'chore', 'battery'] as $kind) {
            $kinds[$kind] = [$kind];
        }

        return $kinds;
    }

    /**
     * The editor lists exactly the fields the server accepts for a kind, in the server's order.
     */
    #[DataProvider('Kinds')]
    public function testEditorListsTheServersFieldKeys(string $kind): void
    {
        self::assertSame(1, preg_match("/'" . $kind . "': \\[([^\\]]*)\\]/", self::EditorSource(), $match), 'the editor lists ' . $kind);
        preg_match_all("/'([a-z_]+\\.[a-z_]+)'/", $match[1], $keys);

        self::assertSame(array_keys(FieldCatalogue::For($kind)), $keys[1]);
    }

    /**
     * Every field's example in the editor is the value the server's sample data holds for it.
     */
    #[DataProvider('Kinds')]
    public function testEditorExamplesEqualTheServersSampleData(string $kind): void
    {
        $source = self::EditorSource();
        foreach (FieldCatalogue::SampleFor($kind) as $key => $sample) {
            $found = preg_match("/'" . preg_quote($key, '/') . "': \\{[^\\n]*example: '((?:[^'\\\\]|\\\\.)*)' \\}/", $source, $match);
            self::assertSame(1, $found, 'the editor describes ' . $key);
            self::assertSame($sample, stripcslashes($match[1]), 'the example for ' . $key);
        }
    }

    /**
     * The editor describes no field the server does not have.
     */
    public function testEditorDescribesNoUnknownField(): void
    {
        preg_match_all("/^\\t\\t\\t'([a-z_]+\\.[a-z_]+)': \\{ name:/m", self::EditorSource(), $described);

        $known = [];
        foreach (self::Kinds() as [$kind]) {
            $known = array_merge($known, array_keys(FieldCatalogue::For($kind)));
        }
        self::assertSame($known, $described[1]);
    }
}
