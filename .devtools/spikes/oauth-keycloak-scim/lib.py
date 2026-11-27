"""HTTP helpers for the SCIM spike: Keycloak admin, SCIM caller and end-user tokens."""
import base64
import json
import os
import urllib.error
import urllib.parse
import urllib.request

B = "http://127.0.0.1:8180"
REALM = "scim"
R = f"/admin/realms/{REALM}"
S = f"/realms/{REALM}/scim/v2"
SCIM_JSON = "application/scim+json"
USER_SCHEMA = "urn:ietf:params:scim:schemas:core:2.0:User"
GROUP_SCHEMA = "urn:ietf:params:scim:schemas:core:2.0:Group"
PATCH_SCHEMA = "urn:ietf:params:scim:api:messages:2.0:PatchOp"
HERE = os.path.dirname(os.path.abspath(__file__))


def req(method, url, body=None, token=None, ctype="application/json", form=False):
    """Send one request to Keycloak and return (status, decoded body, headers)."""
    data, headers = None, {}
    if body is not None:
        if form:
            data = urllib.parse.urlencode(body).encode()
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        else:
            data = json.dumps(body).encode()
            headers["Content-Type"] = ctype
    if token:
        headers["Authorization"] = "Bearer " + token
    r = urllib.request.Request(B + url, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(r) as resp:
            text = resp.read().decode()
            status, hdrs = resp.status, dict(resp.headers)
    except urllib.error.HTTPError as e:
        text, status, hdrs = e.read().decode(), e.code, dict(e.headers)
    try:
        return status, json.loads(text), hdrs
    except ValueError:
        return status, text, hdrs


def token(realm, **form):
    """Return the token endpoint's JSON for a grant in a realm."""
    return req("POST", f"/realms/{realm}/protocol/openid-connect/token", form, form=True)[1]


def admin_token():
    """Return a master-realm admin access token."""
    pw = open(os.path.join(HERE, ".admin.pw")).read().strip()
    return token("master", grant_type="password", client_id="admin-cli", username="admin", password=pw)["access_token"]


def client_token(client_id, secret):
    """Return a client-credentials access token from the spike realm."""
    return token(REALM, grant_type="client_credentials", client_id=client_id, client_secret=secret)["access_token"]


def user_login(username, password):
    """Log a user in to the spike's relying party with the password grant."""
    return token(REALM, grant_type="password", client_id="victual", username=username, password=password, scope="openid")


def user_refresh(refresh_token):
    """Refresh a user's tokens; return (status, body)."""
    s, b, _ = req("POST", f"/realms/{REALM}/protocol/openid-connect/token",
                  {"grant_type": "refresh_token", "client_id": "victual", "refresh_token": refresh_token}, form=True)
    return s, b


def claims(jwt):
    """Decode a JWT's payload without verifying it."""
    return json.loads(base64.urlsafe_b64decode(jwt.split(".")[1] + "=="))


def client_ids(admin):
    """Map clientId to internal id for the spike realm."""
    return {c["clientId"]: c["id"] for c in req("GET", R + "/clients", token=admin)[1]}
