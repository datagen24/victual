"""release_version.py finds every copy of the version in the real files, bumps them all, and
--check names each one that disagrees.

The tests copy the repository's own files into a scratch tree, so a COPIES pattern that
stops matching the file it names fails here rather than on the next release.
"""
import json
from pathlib import Path
import shutil
import tempfile
import unittest
from unittest import mock

import release_version as rv

REAL_ROOT = rv.ROOT


class ScratchTree(unittest.TestCase):
    def setUp(self):
        scratch = tempfile.TemporaryDirectory()
        self.addCleanup(scratch.cleanup)
        self.root = Path(scratch.name)
        for glob, _ in rv.COPIES:
            for path in REAL_ROOT.glob(glob):
                target = self.root / path.relative_to(REAL_ROOT)
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy(path, target)
        shutil.copytree(REAL_ROOT / "docs/releases", self.root / "docs/releases")
        for name, value in {
            "ROOT": self.root,
            "VERSION_JSON": self.root / "version.json",
            "RELEASES": self.root / "docs/releases",
            "INDEX": self.root / "docs/releases/README.md",
        }.items():
            patcher = mock.patch.object(rv, name, value)
            patcher.start()
            self.addCleanup(patcher.stop)
        self.version, self.date = rv.current()


class CheckTest(ScratchTree):
    # Whether the committed tree agrees is the lint step's `--check`, which says how to fix
    # it; asserting it here as well would fail first, under a test name, on a release PR.

    def test_every_pattern_matches_the_file_it_names(self):
        for glob, pattern in rv.COPIES:
            with self.subTest(glob=glob):
                found = [o for p in rv.files(glob) for o in rv.occurrences(p.read_text(), pattern)]
                self.assertTrue(found, f"{pattern!r} matches nothing in {glob}")

    def test_a_stale_copy_is_named_with_its_line(self):
        chart = self.root / "deploy/helm/victual/Chart.yaml"
        chart.write_text(chart.read_text().replace(f'appVersion: "{self.version}"', 'appVersion: "9.9.9"'))
        problems = rv.check()
        self.assertEqual(len(problems), 1, problems)
        self.assertRegex(problems[0], r"^deploy/helm/victual/Chart\.yaml:\d+: 9\.9\.9, expected ")

    def test_a_missing_record_and_index_row_are_refused(self):
        (self.root / f"docs/releases/{self.version}.md").unlink()
        index = self.root / "docs/releases/README.md"
        index.write_text(index.read_text().replace(f"({self.version}.md)", "(gone.md)"))
        problems = rv.check()
        self.assertIn(f"docs/releases/{self.version}.md does not exist", problems)
        self.assertIn(f"docs/releases/README.md does not list {self.version}", problems)


class BumpTest(ScratchTree):
    def bump(self, version="9.8.7", date="2031-01-02"):
        rv.rewrite(version, date)
        rv.add_index_row(version, date)
        with mock.patch.object(rv, "merged_pull_requests", return_value=[(12, "fix: a thing")]), \
                mock.patch.object(rv, "repository_url", return_value="https://github.com/o/r"):
            return rv.draft_record(version, self.version)

    def test_every_copy_moves(self):
        self.bump()
        self.assertEqual(json.loads((self.root / "version.json").read_text()),
                         {"Version": "9.8.7", "ReleaseDate": "2031-01-02"})
        for glob, pattern in rv.COPIES:
            for path in rv.files(glob):
                for name, _, _, value in rv.occurrences(path.read_text(), pattern):
                    with self.subTest(path=path.name, group=name):
                        self.assertEqual(value, "9.8.7" if name[0] == "v" else "2031-01-02")

    def test_only_the_version_changes(self):
        before = {p: p.read_text() for g, _ in rv.COPIES for p in rv.files(g)}
        self.bump()
        for path, text in before.items():
            with self.subTest(path=path.name):
                undone = path.read_text().replace("9.8.7", self.version).replace("2031-01-02", self.date)
                self.assertEqual(undone, text)

    def test_the_draft_record_fails_check_until_edited(self):
        record = self.bump()
        self.assertIn("[PR 12](https://github.com/o/r/pull/12)", record.read_text())
        self.assertIn(f"[{self.version}]({self.version}.md)", record.read_text())
        self.assertEqual([p for p in rv.check() if "draft" in p],
                         ["docs/releases/9.8.7.md is still the generated draft; "
                          "fill it in and delete the DRAFT comment"])
        text = record.read_text()
        record.write_text(text[:text.index(rv.DRAFT_MARK)] + text[text.index("-->") + 4:])
        self.assertEqual(rv.check(), [])

    def test_a_second_bump_writes_no_second_row_or_record(self):
        self.bump()
        index = (self.root / "docs/releases/README.md").read_text()
        self.assertIsNone(self.bump())
        self.assertEqual((self.root / "docs/releases/README.md").read_text(), index)
        self.assertEqual(index.count("(9.8.7.md)"), 1)


class VersionFormatTest(unittest.TestCase):
    def test_accepts_the_versions_this_repository_has_tagged(self):
        for version in ("0.1.0-MVP", "0.2.0-MVP", "0.3.0", "0.4.1", "1.0.0-rc.1"):
            self.assertRegex(version, rv.VERSION_FORMAT)

    def test_refuses_what_an_image_or_chart_tag_cannot_carry(self):
        for version in ("v0.4.1", "0.4", "0.4.1+build", "0.4.1 ", ""):
            self.assertNotRegex(version, rv.VERSION_FORMAT)


if __name__ == "__main__":
    unittest.main()
