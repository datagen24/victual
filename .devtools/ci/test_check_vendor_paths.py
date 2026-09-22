import tempfile
import unittest
from pathlib import Path

from check_vendor_paths import select_files, violations


class VendorPathTests(unittest.TestCase):
    def test_the_reference_that_blocked_the_suite(self):
        line = "$font = file_get_contents(VICTUAL_ROOT_PATH . '/packages/php-di/php-di/website/fonts/fontawesome-webfont.ttf');"
        self.assertEqual(violations(line), ["packages/php-di/php-di/website/fonts/fontawesome-webfont.ttf"])

    def test_the_two_paths_a_dist_install_always_has(self):
        for line in [
            "require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';",
            "$loader = require VICTUAL_ROOT_PATH . '/packages/autoload.php';",
            "php packages/bin/phpunit --configuration phpunit.xml",
            "exec('packages/bin/psalm --output-format=json')",
        ]:
            with self.subTest(line=line):
                self.assertEqual(violations(line), [])

    def test_a_package_source_file_is_refused_even_though_dist_ships_it(self):
        # Dist archives do carry src/, so this one would work - and it reaches around the
        # autoloader into a dependency's file layout, which is the next thing to break on a
        # patch release. The check does not distinguish the two, and says so by refusing.
        self.assertEqual(violations("require '/packages/slim/slim/Slim/App.php';"),
                         ["packages/slim/slim/Slim/App.php"])

    def test_the_yarn_tree_is_a_separate_question(self):
        self.assertEqual(violations("file_get_contents('public/packages/fabric/dist/fabric.js')"), [])

    def test_several_references_are_all_reported_in_order(self):
        text = "'/packages/a/b/tests/f.bin'\n'/packages/autoload.php'\n'/packages/c/d/doc/g.bin'\n"
        self.assertEqual(violations(text), ["packages/a/b/tests/f.bin", "packages/c/d/doc/g.bin"])

    def test_only_php_under_the_two_roots_is_read(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for relative in ["tests/Pgsql/OneTest.php", ".devtools/labels/two-tests.php",
                             "tests/Pgsql/helper.sh", "services/Labels/LabelAssetService.php"]:
                (root / relative).parent.mkdir(parents=True, exist_ok=True)
                (root / relative).write_text("")
            (root / "tests/Support").mkdir(parents=True, exist_ok=True)
            selected = [str(path.relative_to(root)) for path in select_files(root)]
            self.assertEqual(sorted(selected), [".devtools/labels/two-tests.php", "tests/Pgsql/OneTest.php"])


if __name__ == "__main__":
    unittest.main()
