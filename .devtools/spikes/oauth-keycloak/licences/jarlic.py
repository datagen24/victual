"""List each jar under a directory with the licence evidence it carries.

Usage: python3 -I jarlic.py <root> > out.tsv
Columns: path, groupId, artifactId, version, pom licences, manifest Bundle-License,
licence/notice file names inside the jar.
"""
import re
import sys
import zipfile
from pathlib import Path
import xml.etree.ElementTree as ET

root = Path(sys.argv[1])


def pom_info(z):
    for n in z.namelist():
        if n.startswith("META-INF/maven/") and n.endswith("/pom.xml"):
            try:
                x = ET.fromstring(z.read(n))
            except ET.ParseError:
                continue
            ns = {"m": x.tag.split("}")[0].strip("{")} if x.tag.startswith("{") else {"m": ""}
            def t(path):
                e = x.find(path, ns) if ns["m"] else x.find(path.replace("m:", ""))
                return e.text.strip() if e is not None and e.text else ""
            g = t("m:groupId") or t("m:parent/m:groupId")
            a = t("m:artifactId")
            v = t("m:version") or t("m:parent/m:version")
            lic = []
            for l in (x.findall("m:licenses/m:license", ns) if ns["m"] else x.findall("licenses/license")):
                name = l.find("m:name", ns) if ns["m"] else l.find("name")
                if name is not None and name.text:
                    lic.append(" ".join(name.text.split()))
            yield g, a, v, lic


for jar in sorted(root.rglob("*.jar")):
    try:
        z = zipfile.ZipFile(jar)
    except zipfile.BadZipFile:
        continue
    poms = list(pom_info(z))
    g = a = v = ""
    lic = []
    stem = jar.name[:-4]
    for pg, pa, pv, pl in poms:
        if f"{pg}.{pa}-{pv}" == stem or f"{pa}-{pv}" == stem or not g:
            g, a, v, lic = pg, pa, pv, pl
    bl = ""
    if "META-INF/MANIFEST.MF" in z.namelist():
        mf = z.read("META-INF/MANIFEST.MF").decode("utf-8", "replace").replace("\r\n ", "").replace("\n ", "")
        m = re.search(r"^Bundle-License:\s*(.+)$", mf, re.M)
        if m:
            bl = m.group(1).strip()
    files = sorted({Path(n).name for n in z.namelist()
                    if re.search(r"(licen[cs]e|notice|copying)", Path(n).name, re.I)
                    and n.count("/") <= 2})
    print("\t".join([str(jar.relative_to(root)), g, a, v, "; ".join(lic), bl, ",".join(files)]))
