#!/usr/bin/env python3
"""Install the checksum-pinned Vale binary; no package manager is required."""
import argparse
import hashlib
import io
from pathlib import Path
import platform
import tarfile
import urllib.request
import zipfile

VERSION = '3.22.0'
CHECKSUMS = {
    ('Linux', 'x86_64'): ('Linux_64-bit.tar.gz', '52f5cd0314a1b7384cac6aa102a68193977312f6ba9c9f3ae001b5deec8e3a10'),
    ('Linux', 'aarch64'): ('Linux_arm64.tar.gz', '18f57dfe023e804159b2bb307f00274d801d426c518a8a0cc25d4f98bf035e73'),
    ('Darwin', 'x86_64'): ('macOS_64-bit.tar.gz', 'e54a94b86d45f8bdd751f31ad3d06d6ae04da1154e720a006ed0f2e03a675fff'),
    ('Darwin', 'arm64'): ('macOS_arm64.tar.gz', '7dea014d6586101f6f695105f2e8825b7297236df0af9e68e193bcb4e4d25f7e'),
    ('Windows', 'amd64'): ('Windows_64-bit.zip', '7e55b39881f48ced8cf27406129c065a20247800e82e816a9e79d06c4b1a199e'),
    ('Windows', 'arm64'): ('Windows_arm64.zip', '2289fc3ff04e450e57e3124c858cb8cbb34878c4d742fa8298e7f54f20280cd2'),
}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--directory', type=Path, required=True)
    args = parser.parse_args()
    key = (platform.system(), platform.machine().lower())
    if key not in CHECKSUMS:
        parser.error(f'Unsupported platform {key}; install Vale {VERSION} from its official release.')
    suffix, checksum = CHECKSUMS[key]
    url = f'https://github.com/vale-cli/vale/releases/download/v{VERSION}/vale_{VERSION}_{suffix}'
    with urllib.request.urlopen(url, timeout=60) as response:
        data = response.read()
    if hashlib.sha256(data).hexdigest() != checksum:
        raise SystemExit('Vale archive checksum mismatch; nothing installed.')
    name = 'vale.exe' if key[0] == 'Windows' else 'vale'
    # Extract only the expected executable, without trusting archive paths or ownership.
    if suffix.endswith('.zip'):
        with zipfile.ZipFile(io.BytesIO(data)) as archive:
            binary = archive.read(name)
    else:
        with tarfile.open(fileobj=io.BytesIO(data), mode='r:gz') as archive:
            member = archive.getmember(name)
            if not member.isfile():
                raise SystemExit('Vale archive executable is not a regular file.')
            binary = archive.extractfile(member).read()
    args.directory.mkdir(parents=True, exist_ok=True)
    target = args.directory / name
    target.write_bytes(binary)
    target.chmod(0o755)
    print(target.resolve())


if __name__ == '__main__':
    main()
