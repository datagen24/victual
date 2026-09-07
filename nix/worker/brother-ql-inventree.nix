# brother_ql-inventree, packaged here because nixpkgs does not carry it.
#
# What nixpkgs *does* carry, under `python3Packages.brother-ql`, is `brother_ql_next`
# 0.12.0 — a different maintained fork of the same abandoned upstream, with the same
# `brother_ql` import name. That is a genuine alternative and it is recorded in the spike
# finding rather than taken silently: the prototype's rendering, its geometry work and
# every physical print behind issue #90 were done against the inventree fork, so the
# spike packages the fork the evidence is about and notes the other.
#
# Every dependency it declares — click, packbits, pillow, pyusb, attrs — is already in
# nixpkgs, which is why this is one derivation rather than a chain of them.
{
  lib,
  buildPythonPackage,
  fetchPypi,
  makeBinaryWrapper,
  setuptools,
  click,
  packbits,
  pillow,
  pyusb,
  attrs,
}:

buildPythonPackage rec {
  pname = "brother_ql-inventree";
  version = "1.3";
  pyproject = true;

  src = fetchPypi {
    inherit version;
    pname = "brother_ql_inventree";
    hash = "sha256-JDNcpfSzRExpJpi1mUWafmxL0DbdWAB0xj05OCkU/KM=";
  };

  build-system = [ setuptools ];

  # The driver ships console scripts (`brother_ql`, `brother_ql_analyse`, …) and
  # wrapPythonPrograms wraps each in a bash script, so *this* package puts bash in the
  # worker's closure even after the worker's own wrapper stops doing it. Every Python
  # dependency with an entry point does the same — see the finding in the spike notes.
  nativeBuildInputs = [ makeBinaryWrapper ];

  dependencies = [
    click
    packbits
    pillow
    pyusb
    attrs
  ];

  # The distribution ships no test suite; the import is the check that the closure is
  # complete, which is exactly the question this spike asks.
  doCheck = false;
  pythonImportsCheck = [
    "brother_ql"
    "brother_ql.labels"
    "brother_ql.raster"
  ];

  meta = {
    description = "Python package to control Brother QL label printers (InvenTree fork)";
    homepage = "https://github.com/inventree/brother_ql";
    license = lib.licenses.gpl3Only;
  };
}
