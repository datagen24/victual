"""Prepare the issue 648 Swift decode check.

usage: python3 -I prepare.py <victual.openapi.json> <contract-admin.json> <out-dir>

Writes <out-dir>/Sources/UnionCheck/openapi.json: the document with the two
GET /objects union schemas copied into components as ListUnion and SingleUnion, so
swift-openapi-generator names them. Writes <out-dir>/rows.json: one row per listable
entity. A row takes its keys from the recorded response shape in contract-admin.json,
or, where nothing was recorded, from the declared properties of the member the
document maps that entity to. Values are typed placeholders; an enum field takes the
first value of the entity's own member's enum, or of the first schema that declares an
enum under that key, so a value never fails for being made up.
Entities with neither a recorded shape nor a mapped member are left out.
"""
import json
import sys

doc_path, snap_path, out_dir = sys.argv[1:4]
doc = json.load(open(doc_path, encoding="utf-8"))
snap = json.load(open(snap_path, encoding="utf-8"))
schemas = doc["components"]["schemas"]
INSTANT = "2026-10-12T00:00:00.000000Z"


def union(path):
    schema = doc["paths"][path]["get"]["responses"]["200"]["content"]["application/json"]["schema"]
    return schema.get("items", schema)


list_union, single_union = union("/objects/{entity}"), union("/objects/{entity}/{objectId}")
keyword = "anyOf" if "anyOf" in list_union else "oneOf"
schemas["ListUnion"] = {keyword: list_union[keyword]}
schemas["SingleUnion"] = {keyword: single_union[keyword]}
json.dump(doc, open(f"{out_dir}/Sources/UnionCheck/openapi.json", "w", encoding="utf-8"))

mapping = list_union.get("x-victual-entity-schemas", {})
listable = [e for e in schemas["ExposedEntity"]["enum"] if e not in schemas["ExposedEntityNoListing"]["enum"]]


def value_for(prop):
    if "$ref" in prop:
        target = schemas[prop["$ref"].split("/")[-1]]
        return value_for(target) if "enum" in target or target.get("type") not in (None, "object") else {}
    if "enum" in prop:
        values = [v for v in prop["enum"] if v is not None]
        return values[0] if values else None
    if prop.get("format") == "date-time":
        return INSTANT
    if prop.get("format") == "date":
        return "2026-10-12"
    types = prop.get("type")
    types = [t for t in (types if isinstance(types, list) else [types]) if t != "null"]
    return {"integer": 1, "number": 1.5, "string": "x", "boolean": True, "object": {}, "array": []}.get(
        types[0] if types else None, "x"
    )


def value_for_recorded(kind):
    if isinstance(kind, (dict, list)):
        return {} if isinstance(kind, dict) else []
    kind = [k for k in kind.split("|") if k != "null"][0] if "|" in kind else kind
    return {
        "integer": 1, "number": 1.5, "string": "x", "boolean": True, "null": None,
        "string(date-time)": INSTANT, "string(date)": "2026-10-12",
    }.get(kind, "x")


enumerated = {}
for schema in schemas.values():
    for key, prop in (schema.get("properties") or {}).items():
        target = schemas.get(prop.get("$ref", "").split("/")[-1], prop) if "$ref" in prop else prop
        if "enum" in target:
            enumerated.setdefault(key, prop)

rows = {}
for entity in listable:
    member = schemas.get(mapping.get(entity, ""), {}).get("properties", {})
    own = {**enumerated, **member}
    recorded = snap.get(f"GET /api/objects/{{entity}} ({entity})", {}).get("shape")
    shape = recorded[0] if isinstance(recorded, list) and recorded else recorded
    if isinstance(shape, dict) and shape and "__empty_list__" not in shape:
        row = {
            key: value_for(own[key]) if key in own and ("enum" in own[key] or "$ref" in own[key])
            else value_for_recorded(kind)
            for key, kind in shape.items() if key != "userfields"
        }
        rows[entity] = {"source": "recorded", "row": row}
    elif member:
        rows[entity] = {"source": "member", "row": {k: value_for(v) for k, v in member.items() if k != "userfields"}}

json.dump({"mapping": mapping, "rows": rows}, open(f"{out_dir}/rows.json", "w", encoding="utf-8"))
print(f"{len(rows)} entities ({sum(r['source'] == 'recorded' for r in rows.values())} from recorded shapes), "
      f"{len(mapping)} mapped, union keyword {keyword}")
