"""Resolve licences for jars that carry none, from Maven Central POMs and their parents.

Usage: python3 -I resolve.py jars.tsv > resolved.tsv
Input columns from jarlic.py. Output: path, g, a, v, licence, source, where source is
"jar-pom", "jar-manifest", "central:<g>:<a>:<v>" (the POM in the parent chain that
declared it), "override:<evidence>", "generic-parent:<g>:<a>:<v>" or "unresolved".

A licence inherited from an organisation-wide parent POM (GENERIC_PARENTS) describes that
POM, not the artifact: jboss-transaction-spi 8.0.0.Final inherits "Public Domain" from
org.jboss:jboss-parent:39, but its sources are LGPL-2.1-or-later. Such a row is reported as
"generic-parent" until OVERRIDES records the artifact's own licence and where it was read.
"""
import re
import sys
import time
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET

CENTRAL = "https://repo1.maven.org/maven2"
cache = {}

GENERIC_PARENTS = {("org.jboss", "jboss-parent")}

# groupId:artifactId:version -> (licence, evidence). Read 2026-11-27.
OVERRIDES = {
    "org.jboss:jboss-transaction-spi:8.0.0.Final": (
        "LGPL-2.1-or-later",
        "github.com/jbosstm/jboss-transaction-spi tag 8.0.0.Final (5aec0bfb, the jar's "
        "Scm-Revision): LICENSE is LGPL 2.1 and every src/main file says 2.1 or later",
    ),
}


def fetch(g, a, v):
    key = (g, a, v)
    if key in cache:
        return cache[key]
    url = f"{CENTRAL}/{g.replace('.', '/')}/{a}/{v}/{a}-{v}.pom"
    x = None
    for attempt in range(4):
        try:
            with urllib.request.urlopen(url, timeout=30) as r:
                x = ET.fromstring(r.read())
            break
        except urllib.error.HTTPError as e:
            if e.code == 404:
                break
            time.sleep(2 ** attempt)
        except (urllib.error.URLError, TimeoutError, ET.ParseError):
            time.sleep(2 ** attempt)
    cache[key] = x
    return x


def q(x, path):
    ns = x.tag.split("}")[0].strip("{") if x.tag.startswith("{") else ""
    p = "/".join(f"{{{ns}}}{s}" if ns else s for s in path.split("/"))
    return x.findall(p)


def text(x, path):
    e = q(x, path)
    return e[0].text.strip() if e and e[0].text else ""


def licences(g, a, v, depth=0):
    x = fetch(g, a, v)
    if x is None or depth > 8:
        return None, None
    names = [" ".join((text(l, "name") or text(l, "url")).split()) for l in q(x, "licenses/license")]
    names = [n for n in names if n]
    if names:
        if depth and (g, a) in GENERIC_PARENTS:
            return "; ".join(names), f"generic-parent:{g}:{a}:{v}"
        return "; ".join(names), f"central:{g}:{a}:{v}"
    pg, pa, pv = text(x, "parent/groupId"), text(x, "parent/artifactId"), text(x, "parent/version")
    if pg and pa and pv:
        return licences(pg, pa, pv, depth + 1)
    return None, None


def split_name(stem):
    # Quarkus names a jar <groupId>.<artifactId>-<version>.jar. An artifactId can hold a
    # version-like part (opentelemetry-apache-httpclient-4.3), so try every "-<digit>" split
    # from the right, and every "." split of the rest, until Maven Central has that POM.
    for m in reversed(list(re.finditer(r"-(?=\d)", stem))):
        ga, v = stem[: m.start()], stem[m.end():]
        parts = ga.split(".")
        for i in range(len(parts) - 1, 0, -1):
            g, a = ".".join(parts[:i]), ".".join(parts[i:])
            if fetch(g, a, v) is not None:
                return g, a, v
    return None


for line in open(sys.argv[1]):
    path, g, a, v, pl, bl, files = (line.rstrip("\n").split("\t") + [""] * 7)[:7]
    if pl:
        print("\t".join([path, g, a, v, pl, "jar-pom"])); continue
    if bl:
        print("\t".join([path, g, a, v, bl, "jar-manifest"])); continue
    stem = path.rsplit("/", 1)[-1][:-4]
    if path.startswith("lib/quarkus/") or path == "lib/quarkus-run.jar":
        # Written by Quarkus from the jars above (kc.sh build, or the release build for
        # quarkus-run.jar); no artifact of its own on Maven Central.
        print("\t".join([path, g, a, v, "", "build-output"])); continue
    if not g:
        r = split_name(stem)
        if r:
            g, a, v = r
    if f"{g}:{a}:{v}" in OVERRIDES:
        lic, why = OVERRIDES[f"{g}:{a}:{v}"]
        print("\t".join([path, g, a, v, lic, f"override:{why}"])); continue
    lic, src = (licences(g, a, v) if g else (None, None))
    print("\t".join([path, g, a, v, lic or "", src or "unresolved"]))
