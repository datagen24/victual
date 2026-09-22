#!/usr/bin/env python3
"""Check the Git index with Vale without changing the index or working files."""
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

import audit


def git(*arguments):
    return subprocess.check_output(['git', *arguments], cwd=audit.ROOT)


def relevant(path):
    return (audit.in_scope(path) or path == '.vale.ini'
            or path.startswith('.devtools/vale/') or path == '.githooks/pre-commit')


def main():
    try:
        changed = git('diff', '--cached', '--name-only', '--no-renames', '-z', '--').split(b'\0')
        if not any(relevant(os.fsdecode(path)) for path in changed if path):
            return 0

        # Use the caller's executable, the local pinned install, or PATH, in that order.
        local = audit.ROOT / '.devtools/vale/.bin' / ('vale.exe' if os.name == 'nt' else 'vale')
        executable = os.environ.get('VALE', str(local) if local.is_file() else 'vale')
        executable = shutil.which(executable)
        if not executable:
            raise ValueError('Vale is missing. Run python3 .devtools/vale/install.py '
                             '--directory .devtools/vale/.bin before committing.')
        executable = str(Path(executable).resolve())

        selected = []
        for record in git('ls-files', '--stage', '-z').split(b'\0'):
            if not record:
                continue
            metadata, encoded_path = record.split(b'\t', 1)
            mode, _, stage = metadata.split()
            path = os.fsdecode(encoded_path)
            if (audit.in_scope(path) or path == '.vale.ini'
                    or path in ('.devtools/vale/audit.py', '.devtools/vale/baseline.json')
                    or path.startswith('.devtools/vale/styles/')):
                if stage != b'0' or mode not in (b'100644', b'100755'):
                    raise ValueError(f'Expected a resolved regular file in the index: {path}')
                selected.append(encoded_path)

        environment = os.environ.copy()
        environment['GIT_DIR'] = os.fsdecode(git('rev-parse', '--absolute-git-dir')).strip()
        if environment.get('GIT_INDEX_FILE'):
            environment['GIT_INDEX_FILE'] = str(Path(environment['GIT_INDEX_FILE']).resolve())
        with tempfile.TemporaryDirectory(prefix='victual-prose-') as directory:
            # checkout-index reads staged blobs, including partial staging and renames.
            # A separate directory avoids stashing or altering the user's working copy.
            subprocess.run(['git', 'checkout-index', '--prefix=' + directory + '/', '-z', '--stdin'],
                           cwd=audit.ROOT, input=b'\0'.join(selected) + b'\0', check=True)
            environment['GIT_WORK_TREE'] = directory
            print('Checking staged documentation with Vale.', flush=True)
            return subprocess.run([sys.executable, '.devtools/vale/audit.py', '--check',
                                   '--vale', executable], cwd=directory, env=environment).returncode
    except (OSError, ValueError, subprocess.SubprocessError) as exc:
        print(f'Staged prose check failed: {exc}', file=sys.stderr)
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
