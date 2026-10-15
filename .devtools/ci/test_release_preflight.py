"""Release preflights must refuse unpublished images and temporary database readiness."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[2]


class ReleasePreflightTest(unittest.TestCase):
    def test_k3s_applies_only_after_every_image_is_available(self):
        for missing in (True, False):
            with self.subTest(missing=missing), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                (root / 'kubectl').write_text('''#!/bin/sh
if [ "$1" = kustomize ]; then
  printf '  image: registry/app:1\n  image: registry/web:1\n'
else
  echo applied >> "$CALLS"
  cat "$3" >> "$CALLS"
fi
''')
                (root / 'docker').write_text('''#!/bin/sh
echo "$3" >> "$CALLS"
[ "$MISSING" != yes ] || [ "$3" != registry/web:1 ]
''')
                for name in ('kubectl', 'docker'):
                    (root / name).chmod(0o755)
                env = dict(os.environ, PATH=f'{root}:{os.environ["PATH"]}',
                           CALLS=str(root / 'calls'), MISSING='yes' if missing else 'no')
                result = subprocess.run(['bash', str(ROOT / 'deploy/k3s/apply.sh')],
                                        env=env, capture_output=True, text=True)
                calls = (root / 'calls').read_text()
                self.assertIn('registry/app:1', calls)
                self.assertIn('registry/web:1', calls)
                self.assertEqual('applied' in calls, not missing)
                self.assertEqual(result.returncode == 0, not missing)

    def readiness_probe(self, script, never_ready=False):
        with tempfile.TemporaryDirectory() as directory:
            env = dict(os.environ, PROBE_COUNT=str(Path(directory) / 'count'),
                       NEVER_READY='yes' if never_ready else 'no')
            # The initialization server accepts Unix sockets, then shuts down.
            # TCP stays closed until the final server starts on the second poll.
            mock = '''
sleep() { :; }
docker() {
  if [ "$1" = run ]; then return 0; fi
  count=0
  if [ -f "$PROBE_COUNT" ]; then read -r count < "$PROBE_COUNT"; fi
  count=$((count + 1)); echo "$count" > "$PROBE_COUNT"
  if [ "$NEVER_READY" = yes ]; then return 2; fi
  case " $* " in
    *" -h 127.0.0.1 "*) [ "$count" -ge 2 ] ;;
    *) [ "$count" -eq 1 ] ;;
  esac
}
'''
            return subprocess.run(['bash', '-e', '-c', mock + script], env=env,
                                  capture_output=True, text=True, timeout=5).returncode

    def test_nix_waits_past_temporary_postgres_server(self):
        workflow = yaml.safe_load((ROOT / '.github/workflows/nix.yml').read_text())
        script = next(step['run'] for step in workflow['jobs']['flake']['steps']
                      if step.get('name') == 'Start PostgreSQL')
        self.assertEqual(self.readiness_probe(script), 0)
        self.assertNotEqual(self.readiness_probe(script, never_ready=True), 0)
        # Negative control reproduces the old socket probe's early success/final failure.
        old_socket_probe = script.replace(' -h 127.0.0.1', '')
        self.assertNotEqual(self.readiness_probe(old_socket_probe), 0)


if __name__ == '__main__':
    unittest.main()
