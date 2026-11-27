"""Map resolve.py's licence strings to SPDX identifiers and group them.

Usage: python3 -I group.py resolved.tsv [inventory.tsv]
Prints strings no rule maps (prefixed "?"), the count per licence set, and every jar whose
set contains an identifier outside PERMISSIVE. With a second argument, also writes one row
per jar: path, groupId, artifactId, version, SPDX identifiers, source of the evidence.

Several identifiers on one jar are the licences its POM or manifest lists. For every
multi-licence jar in Keycloak 26.8.1 those are alternatives (dual or triple licensing);
check a new release's flagged rows against the project's own licence page before relying
on that.
"""
import re
import sys
from collections import Counter, defaultdict

# First match wins, so the exception-bearing GPL forms come before plain GPL.
RULES = [
    (r"apache|LICENSE-2\.0", "Apache-2.0"),
    (r"universal foss exception", "GPL-2.0-only WITH Universal-FOSS-exception-1.0"),
    (r"classpath|CPE|secondary-gpl-2\.0-cp", "GPL-2.0-only WITH Classpath-exception-2.0"),
    (r"lesser general public|LGPL", "LGPL-2.1-or-later"),
    (r"epl-2\.0|epl 2\.0|eclipse public license.*2\.0|LICENSE_EPLv2", "EPL-2.0"),
    (r"epl-1\.0|eclipse public license.*1\.0", "EPL-1.0"),
    (r"edl|eclipse distribution", "BSD-3-Clause"),  # SPDX lists EDL-1.0 as BSD-3-Clause
    (r"mozilla public license.*2\.0|MPL 2\.0|MPL-2\.0", "MPL-2.0"),
    (r"h2database\.com/html/license", "MPL-2.0 | EPL-1.0"),
    (r"universal permissive", "UPL-1.0"),
    (r"bsd-3|bsd 3|new bsd|revised bsd|asm\.ow2\.io|antlr\.org/license|3-clause", "BSD-3-Clause"),
    (r"bsd-2|bsd 2|simplified bsd|2-clause", "BSD-2-Clause"),
    (r"\bmit-0\b", "MIT-0"),
    (r"\bmit\b", "MIT"),
    (r"bouncy castle", "MIT"),  # the Bouncy Castle licence is the MIT text
    (r"cc0|public domain", "CC0-1.0"),
    (r"\bcddl\b|common development and distribution", "CDDL-1.1"),
    (r"gnu general public|\bgpl", "GPL-2.0-only"),
]
PERMISSIVE = {"Apache-2.0", "BSD-3-Clause", "BSD-2-Clause", "MIT", "MIT-0", "CC0-1.0", "UPL-1.0"}


def ids(s):
    out = []
    for part in re.split(r";\s*|,\s*(?=https?:)", s):
        part = part.strip()
        if not part or part.lower().startswith(("link=", "description=", "see:")):
            continue
        for pat, spdx in RULES:
            if re.search(pat, part, re.I):
                out.append(spdx)
                break
        else:
            out.append("?" + part)
    return sorted(set(out))


rows = [l.rstrip("\n").split("\t") for l in open(sys.argv[1])]
counts = Counter()
flagged = defaultdict(list)
inventory = []
for r in rows:
    path, g, a, v, lic, src = (r + [""] * 6)[:6]
    if src == "build-output":
        expr = ["(build output)"]
    elif src == "unresolved" or src.startswith("generic-parent:"):
        expr = ["(unresolved)"]
    else:
        expr = ids(lic)
    key = " | ".join(expr)
    counts[key] += 1
    inventory.append([path, g, a, v, key, src])
    if any(e not in PERMISSIVE and e != "(build output)" for e in expr):
        flagged[key].append(f"{path}\t{lic}\t{src}")
for k, n in counts.most_common():
    print(f"{n:4d}  {k}")
print()
for k, paths in flagged.items():
    print(f"== {k}")
    for p in paths:
        print("   ", p)
if len(sys.argv) > 2:
    with open(sys.argv[2], "w") as f:
        f.write("path\tgroupId\tartifactId\tversion\tlicences\tsource\n")
        for row in inventory:
            f.write("\t".join(row) + "\n")
