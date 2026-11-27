"""SCIM cases against the spike realm. Prints one line per case and writes results.json.

M: membership removal and deactivation through SCIM, and their effect on a live session.
L: an admin-event poller that ends the user's sessions after a SCIM removal.
N: SCIM authorization.  X: externalId storage.
"""
import json
import time

from lib import (GROUP_SCHEMA, PATCH_SCHEMA, R, S, SCIM_JSON, USER_SCHEMA, admin_token, claims,
                 client_token, req, user_login, user_refresh)

RESULTS = []
STAMP = str(int(time.time()))


def case(cid, ok, detail):
    """Record and print one case; ok is True (pass), False (FAIL) or None (obs)."""
    tag = {True: "pass", False: "FAIL", None: "obs"}[ok]
    RESULTS.append({"case": cid, "result": tag, "detail": detail})
    print(f"{cid:4} {tag:4} {detail}")


def refused(status, body):
    """True only for the token endpoint's refusal of a dead grant: 400 with invalid_grant."""
    return status == 400 and isinstance(body, dict) and body.get("error") == "invalid_grant"


def error_of(body):
    """Return the OAuth error code of a response body, or the body itself if it is not JSON."""
    return body.get("error") if isinstance(body, dict) else str(body)[:80]


def scim(method, path, body, tok):
    """Call the realm's SCIM API with the SCIM media type."""
    return req(method, S + path, body, tok, ctype=SCIM_JSON)


def patch(path, ops, tok):
    """Send a SCIM PATCH with the given operations."""
    return scim("PATCH", path, {"schemas": [PATCH_SCHEMA], "Operations": ops}, tok)


def new_user(tok, admin, name, **extra):
    """Create an active user through SCIM and give it a password for the probe's logins."""
    body = {"schemas": [USER_SCHEMA], "userName": name, "active": True,
            "name": {"givenName": "Test", "familyName": name}, "emails": [{"value": name + "@example.test"}], **extra}
    s, u, _ = scim("POST", "/Users", body, tok)
    req("PUT", R + f"/users/{u['id']}/reset-password", {"type": "password", "value": "pw-" + name, "temporary": False}, admin)
    return s, u["id"]


def new_group(tok, name, uid):
    """Create a group through SCIM, then add one member with PATCH."""
    gid = scim("POST", "/Groups", {"schemas": [GROUP_SCHEMA], "displayName": name}, tok)[1]["id"]
    s = patch(f"/Groups/{gid}", [{"op": "add", "path": "members", "value": [{"value": uid}]}], tok)[0]
    return gid, s


def sessions(admin, uid):
    """Count a user's Keycloak user sessions."""
    return len(req("GET", R + f"/users/{uid}/sessions", token=admin)[1])


def membership(admin, tok):
    """Cases M0 to M8."""
    s, b, _ = scim("POST", "/Groups", {"schemas": [GROUP_SCHEMA], "displayName": "m0-" + STAMP,
                                       "members": [{"value": "x"}]}, tok)
    case("M0", None, f"group create with members -> {s} {b.get('detail') if isinstance(b, dict) else b}")
    s, plain = scim("POST", "/Users", {"schemas": [USER_SCHEMA], "userName": "m1-" + STAMP}, tok)[:2]
    enabled = req("GET", R + f"/users/{plain['id']}", token=admin)[1]["enabled"]
    case("M1", None, f"user created without 'active' -> {s}, Keycloak enabled={enabled}")
    name = "m-" + STAMP
    _, uid = new_user(tok, admin, name)
    gid, s = new_group(tok, "adults-" + name, uid)
    first = user_login(name, "pw-" + name)
    g = claims(first["access_token"]).get("groups")
    case("M2", g == ["adults-" + name], f"PATCH add member -> {s}; token groups {g}")
    t0 = time.time()
    s = patch(f"/Groups/{gid}", [{"op": "remove", "path": f'members[value eq "{uid}"]'}], tok)[0]
    left = [x["name"] for x in req("GET", R + f"/users/{uid}/groups", token=admin)[1]]
    case("M3", left == [], f"PATCH remove member -> {s}; Keycloak groups now {left} ({(time.time()-t0)*1000:.0f} ms)")
    n = sessions(admin, uid)
    case("M4", n == 0, f"user sessions after SCIM removal: {n} (answer 12 wants them ended on receipt)")
    s, nb = user_refresh(first["refresh_token"])
    g = claims(nb["access_token"]).get("groups") if s == 200 else nb
    case("M5", s == 200 and not g, f"refresh after removal -> {s}; groups {g}")
    case("M6", None, f"access token issued before the removal still says {claims(first['access_token']).get('groups')} until exp")
    second = user_login(name, "pw-" + name)
    s = patch(f"/Users/{uid}", [{"op": "replace", "path": "active", "value": False}], tok)[0]
    k = req("GET", R + f"/users/{uid}", token=admin)[1]
    case("M7", not k["enabled"], f"PATCH active=false -> {s}; enabled={k['enabled']}; sessions listed {sessions(admin, uid)}")
    s, nb = user_refresh(second["refresh_token"])
    case("M8", refused(s, nb), f"refresh after deactivation -> {s} {error_of(nb) if s != 200 else ''}")


def logout_poller(admin, tok):
    """Cases L1 to L3: read GROUP_MEMBERSHIP DELETE admin events, then end that user's sessions."""
    name = "l-" + STAMP
    _, uid = new_user(tok, admin, name)
    gid, _ = new_group(tok, "kids-" + name, uid)
    first = user_login(name, "pw-" + name)
    since = int(time.time() * 1000) - 1
    patch(f"/Groups/{gid}", [{"op": "remove", "path": f'members[value eq "{uid}"]'}], tok)
    ev = req("GET", R + "/admin-events?resourceTypes=GROUP_MEMBERSHIP&operationTypes=DELETE&max=50", token=admin)[1]
    hits = [e for e in ev if e["time"] >= since and e["resourcePath"].endswith("/" + uid)]
    case("L1", len(hits) == 1, f"admin event for the removal: {hits[0]['resourcePath'] if hits else None}")
    t0 = time.time()
    s = req("POST", R + f"/users/{uid}/logout", {}, admin)[0]
    case("L2", sessions(admin, uid) == 0, f"admin logout -> {s}; sessions {sessions(admin, uid)}")
    s, nb = user_refresh(first["refresh_token"])
    case("L3", refused(s, nb), f"refresh after logout -> {s} {error_of(nb) if s != 200 else ''} ({(time.time()-t0)*1000:.0f} ms)")


def authorization(admin, tok):
    """Cases N1 to N4."""
    reader = client_token("scim-reader", "scim-reader-secret")
    case("N1", scim("GET", "/Users", None, reader)[0] == 200, "view-users caller can list Users")
    s = scim("POST", "/Users", {"schemas": [USER_SCHEMA], "userName": "n2-" + STAMP}, reader)[0]
    case("N2", s == 403, f"view-users caller creating a User -> {s}")
    s, b, _ = req("GET", S + "/Users", token=admin)
    case("N3", s == 401, f"token from another realm -> {s} {b.get('detail') if isinstance(b, dict) else ''}")
    name = "n4-" + STAMP
    new_user(tok, admin, name)
    s, b, _ = req("GET", S + "/Users", token=user_login(name, "pw-" + name)["access_token"])
    case("N4", s in (401, 403), f"end-user token from a public client -> {s} {b.get('detail') if isinstance(b, dict) else ''}")


def no_audience(admin):
    """Case N5: a confidential client with manage-users but no SCIM audience."""
    s, b, _ = req("GET", S + "/Users", token=client_token("scim-noaud", "scim-noaud-secret"))
    case("N5", s == 401, f"manage-users token without the SCIM audience -> {s} {b.get('detail') if isinstance(b, dict) else ''}")


def external_id(admin, tok):
    """Cases X1 and X2: externalId is kept only through a user-profile annotation."""
    up = req("GET", R + "/users/profile", token=admin)[1]
    bare = json.loads(json.dumps(up))
    for a in bare["attributes"]:
        if a["name"] == "upstreamId":
            a["annotations"] = {}
    if req("PUT", R + "/users/profile", bare, admin)[0] != 200:
        raise SystemExit("X1: could not remove the externalId annotation")
    try:
        s, u, _ = scim("POST", "/Users", {"schemas": [USER_SCHEMA], "userName": "x1-" + STAMP, "externalId": "oid-x1"}, tok)
        case("X1", None, f"no mapping: create -> {s}; externalId returned {u.get('externalId')}")
    finally:
        if req("PUT", R + "/users/profile", up, admin)[0] != 200:
            raise SystemExit("X1: could not restore the user profile; rerun setup on a fresh realm")
    s, u, _ = scim("POST", "/Users", {"schemas": [USER_SCHEMA], "userName": "x2-" + STAMP, "externalId": "oid-x2-" + STAMP}, tok)
    attr = req("GET", R + f"/users/{u['id']}", token=admin)[1].get("attributes", {}).get("upstreamId")
    found = scim("GET", f'/Users?filter=externalId%20eq%20%22oid-x2-{STAMP}%22', None, tok)[1].get("totalResults")
    case("X2", attr == ["oid-x2-" + STAMP] and found == 1, f"mapped to upstreamId: stored {attr}; filter finds {found}")


def main():
    """Run every case."""
    admin = admin_token()
    tok = client_token("scim-caller", "scim-caller-secret")
    features = {f["name"]: (f["type"], f["enabled"]) for f in req("GET", "/admin/serverinfo", token=admin)[1]["features"]}
    case("F0", features["SCIM_API"] == ("DEFAULT", True), f"SCIM_API feature {features['SCIM_API']}; SSF {features.get('SSF')}")
    cfg = scim("GET", "/ServiceProviderConfig", None, tok)[1]
    case("F1", None, f"patch={cfg['patch']['supported']} bulk={cfg['bulk']['supported']} etag={cfg['etag']['supported']} "
                     f"filter={cfg['filter']['supported']}")
    membership(admin, tok)
    logout_poller(admin, tok)
    authorization(admin, tok)
    no_audience(admin)
    external_id(admin, tok)
    json.dump(RESULTS, open("results.json", "w"), indent=2)


if __name__ == "__main__":
    main()
