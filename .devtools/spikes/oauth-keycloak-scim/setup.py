"""Create the spike realm: SCIM on, a SCIM caller, a read-only caller, a relying party, and a
user-profile attribute that stores SCIM externalId."""
import sys

from lib import B, R, REALM, S, client_ids, req, admin_token

SCIM_AUD = B + S  # the audience the SCIM endpoint requires: the realm's SCIM base URL


def audience_mapper():
    """Return a mapper that puts the SCIM base URL into the access token's audience."""
    return {"name": "scim-aud", "protocol": "openid-connect", "protocolMapper": "oidc-audience-mapper",
            "config": {"included.custom.audience": SCIM_AUD, "access.token.claim": "true"}}


def caller(admin, client_id, secret, roles, audience=True):
    """Create a confidential service-account client and grant it realm-management roles."""
    req("POST", R + "/clients", {"clientId": client_id, "publicClient": False, "serviceAccountsEnabled": True,
                                 "standardFlowEnabled": False, "secret": secret,
                                 "protocolMappers": [audience_mapper()] if audience else []}, admin)
    ids = client_ids(admin)
    sa = req("GET", R + f"/clients/{ids[client_id]}/service-account-user", token=admin)[1]["id"]
    rm = ids["realm-management"]
    grant = [r for r in req("GET", R + f"/clients/{rm}/roles", token=admin)[1] if r["name"] in roles]
    req("POST", R + f"/users/{sa}/role-mappings/clients/{rm}", grant, admin)


def main():
    """Build the realm from nothing; fail loudly if it already exists."""
    admin = admin_token()
    s, b, _ = req("POST", "/admin/realms", {"realm": REALM, "enabled": True, "scimApiEnabled": True,
                                             "adminEventsEnabled": True, "adminEventsDetailsEnabled": True,
                                             "eventsEnabled": True}, admin)
    if s != 201:
        sys.exit(f"realm create {s}: {b}")
    caller(admin, "scim-caller", "scim-caller-secret", {"manage-users", "view-users", "query-groups"})
    caller(admin, "scim-reader", "scim-reader-secret", {"view-users"})
    caller(admin, "scim-noaud", "scim-noaud-secret", {"manage-users", "view-users"}, audience=False)
    req("POST", R + "/clients", {"clientId": "victual", "publicClient": True, "directAccessGrantsEnabled": True,
                                 "standardFlowEnabled": False,
                                 "protocolMappers": [{"name": "groups", "protocol": "openid-connect",
                                                      "protocolMapper": "oidc-group-membership-mapper",
                                                      "config": {"claim.name": "groups", "full.path": "false",
                                                                 "access.token.claim": "true"}}]}, admin)
    up = req("GET", R + "/users/profile", token=admin)[1]
    up["attributes"].append({"name": "upstreamId", "displayName": "Upstream id", "multivalued": False,
                             "permissions": {"view": ["admin"], "edit": ["admin"]},
                             "annotations": {"kc.scim.schema.attribute": "externalId"}})
    print("user profile", req("PUT", R + "/users/profile", up, admin)[0])
    print("realm", REALM, "ready")


if __name__ == "__main__":
    main()
