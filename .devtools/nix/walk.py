#!/usr/bin/env python3
"""Walk a running Victual: every top-level page, the read side of the API, and one write cycle.

    .devtools/nix/walk.py --base-url http://127.0.0.1:8080

It exists for plan 20 (issue #133), and answers the question the differential suite cannot:
does the *image* serve. The suites run on PHP that setup-php or a Debian image provides, so a
code path that needs an extension nix/php.nix does not enable would pass every one of them and
answer 500 in production. This drives the real thing over HTTP - through nginx, FastCGI, the
extension list the image actually has and whatever database role the app container actually
holds - and reports every response that is not what a healthy instance answers.

What it does, in order:

  1. Logs in as the seeded administrator. A fresh database forces a password change before any
     page renders, so if that is pending it is satisfied through the API first (--new-password).
  2. GETs every page the navigation links to, following redirects. A page must end in 200.
  3. GETs every parameterless GET path in the OpenAPI document the instance itself serves,
     and lists every entity the generic /objects endpoint exposes. 2xx is healthy; a 5xx is a
     failure; a 4xx is reported and is a failure only if it is not one the endpoint is documented
     to give an unconfigured instance (see EXPECTED_API_STATUS).
  4. One write cycle through the API: create, edit and delete a location, and book stock in and
     out of a product. That is what proves the database role the app container holds can
     write rows, draw from sequences and fire the stock triggers - and that it does so without
     needing any DDL.

Exit status 0 means every check passed, 1 means at least one did not. Standard library only,
so it runs unchanged on a CI runner and on a laptop.
"""
import argparse
import time
import http.cookiejar
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

# Paths that are not pages: assets, the API, and the two that end the session or hand out a file.
NOT_A_PAGE = re.compile(r"^/(api|packages|img|css|js|viewcache|logout|files)(/|$)|\.(css|js|png|ico|json|map|woff2?|txt)$")

# API paths that legitimately answer something other than 2xx on a freshly bootstrapped
# instance. Each is a documented behaviour, not a tolerance: a calendar feed with nothing to
# put in it, for instance, is the endpoint saying so.
EXPECTED_API_STATUS = {
    # Needs a printer configured; without one the answer is a 4xx by design.
    "/print/shoppinglist/thermal": {400, 404},
}


class Walker:
    def __init__(self, base_url, user, password, new_password, known):
        self.base = base_url.rstrip("/")
        self.user = user
        self.password = password
        self.new_password = new_password
        self.known = known
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar), NoRedirect()
        )
        self.failures = []
        self.rows = []

    # -- transport -----------------------------------------------------------------------

    def request(self, method, path, body=None, form=None, follow=True):
        """Return (status, final_path, body_text). Redirects are followed by hand so the
        final path is known and a redirect loop is bounded."""
        headers = {"Origin": self.base}
        data = None
        if body is not None:
            data = json.dumps(body).encode()
            headers["Content-Type"] = "application/json"
        elif form is not None:
            data = urllib.parse.urlencode(form).encode()
            headers["Content-Type"] = "application/x-www-form-urlencoded"

        url = self.base + path
        for _ in range(8):
            req = urllib.request.Request(url, data=data, headers=headers, method=method)
            try:
                resp = self.opener.open(req, timeout=30)
                status, text, location = resp.status, resp.read().decode("utf-8", "replace"), None
            except urllib.error.HTTPError as err:
                status, text, location = err.code, err.read().decode("utf-8", "replace"), err.headers.get("Location")

            if follow and status in (301, 302, 303, 307, 308) and location:
                url = urllib.parse.urljoin(url, location)
                method, data = "GET", None
                headers.pop("Content-Type", None)
                continue
            return status, urllib.parse.urlparse(url).path, text
        return 599, path, "redirect loop"

    # -- recording -----------------------------------------------------------------------

    def record(self, kind, path, status, ok, note=""):
        expected_known = self.known.get(path) == status
        self.rows.append((kind, path, status, "ok" if ok else ("KNOWN" if expected_known else "FAIL"), note))
        if not ok and not expected_known:
            self.failures.append(f"{kind} {path} -> {status} {note}".strip())

    # -- phases --------------------------------------------------------------------------

    def login(self):
        self.request("GET", "/login")
        status, path, _ = self.request("POST", "/login", form={"username": self.user, "password": self.password}, follow=False)
        if status != 302:
            sys.exit(f"login as {self.user} answered {status}, expected a redirect")
        status, _, text = self.request("GET", "/api/user")
        if status != 200:
            sys.exit(f"GET /api/user after login answered {status}")
        user = json.loads(text)
        user = user[0] if isinstance(user, list) else user
        # A fresh database forces a password change before any *page* renders; the API is
        # exempt, so it is the way to satisfy it without a browser.
        if self.new_password and self.new_password != self.password:
            status, _, text = self.request("PUT", f"/api/users/{user['id']}", body={"username": self.user, "password": self.new_password, "current_password": self.password})
            self.record("setup", "PUT /api/users/{id} (password)", status, status in (200, 204), text[:120] if status >= 300 else "")
            # The must-change flag is cleared at *login*, by noticing that the password used is
            # no longer the seeded one, so changing it is not enough - it has to be used.
            self.password = self.new_password
            status, _, _ = self.request("POST", "/login", form={"username": self.user, "password": self.password}, follow=False)
            self.record("setup", "POST /login (new password)", status, status == 302)

    def pages(self):
        status, _, text = self.request("GET", "/stockoverview")
        if status != 200:
            self.record("page", "/stockoverview", status, False, "the navigation could not be read")
            return
        links = set()
        for href in re.findall(r'href="([^"#]+)"', text):
            parsed = urllib.parse.urlparse(href)
            if parsed.netloc and parsed.netloc != urllib.parse.urlparse(self.base).netloc:
                continue
            path = parsed.path
            if not path.startswith("/") or NOT_A_PAGE.search(path) or parsed.query:
                continue
            links.add(path)
        # The front door is not in the navigation, and it is a page a person reaches first.
        links.update({"/", "/stockoverview"})
        for path in sorted(links):
            status, final, _ = self.request("GET", path)
            # A redirect to the password-change page ends in 200 and renders nothing that was
            # asked for, so an unsatisfied forced change would make every page look healthy.
            stuck = "changepw" in final or final.startswith("/user/")
            self.record("page", path, status, status == 200 and not (stuck and path != final),
                        f"ended at {final}" if final != path else "")

    def api_reads(self):
        status, _, text = self.request("GET", "/api/openapi/specification")
        if status != 200:
            self.record("api", "/openapi/specification", status, False)
            return
        spec = json.loads(text)
        for path, ops in sorted(spec["paths"].items()):
            if "get" not in ops or "{" in path:
                continue
            status, _, body = self.request("GET", "/api" + path)
            allowed = EXPECTED_API_STATUS.get(path, set())
            self.record("api", path, status, 200 <= status < 300 or status in allowed, body[:100] if status >= 400 else "")

        entities = spec["components"]["schemas"]["ExposedEntity_NotIncludingNotListable"]["enum"]
        # The served document's enum carries an empty member; /api/objects/ with nothing after
        # it is not an entity and answers 404 by design.
        for entity in sorted(e for e in entities if e):
            status, _, body = self.request("GET", f"/api/objects/{entity}")
            self.record("api", f"/objects/{entity}", status, 200 <= status < 300, body[:100] if status >= 400 else "")

    def write_cycle(self):
        # Names carry the run's timestamp: locations and products are unique by name, and a
        # second walk of the same database would otherwise refuse its own fixtures.
        tag = str(int(time.time()))

        def check(label, resp, ok_statuses=range(200, 300)):
            status, _, text = resp
            self.record("write", label, status, status in ok_statuses, text[:160] if status not in ok_statuses else "")
            return status in ok_statuses, text

        ok, text = check("POST /objects/locations", self.request("POST", "/api/objects/locations", body={"name": f"walk-location-{tag}"}))
        if not ok:
            return
        location_id = json.loads(text)["created_object_id"]
        check("PUT /objects/locations/{id}", self.request("PUT", f"/api/objects/locations/{location_id}", body={"name": f"walk-location-{tag}-b"}))

        ok, text = check("POST /objects/products", self.request("POST", "/api/objects/products", body={
            "name": f"walk-product-{tag}", "location_id": location_id,
            "qu_id_purchase": 1, "qu_id_stock": 1, "qu_id_consume": 1, "qu_id_price": 1,
        }))
        if ok:
            product_id = json.loads(text)["created_object_id"]
            check("POST /stock/products/{id}/add", self.request("POST", f"/api/stock/products/{product_id}/add", body={"amount": 3, "transaction_type": "purchase"}))
            check("POST /stock/products/{id}/consume", self.request("POST", f"/api/stock/products/{product_id}/consume", body={"amount": 1, "transaction_type": "consume"}))
            check("GET /stock/products/{id}", self.request("GET", f"/api/stock/products/{product_id}"))
            # Booked stock keeps a product from being deleted, and the ledger is meant to be
            # exact history - so the product is left, named so it is recognisably the walk's.
        # The location above holds a product with booked stock, so deleting it would be refused
        # and would prove nothing about DELETE. A location with nothing in it is what shows the
        # delete path works, and only a success counts.
        ok, text = check("POST /objects/locations (disposable)", self.request("POST", "/api/objects/locations", body={"name": f"walk-disposable-{tag}"}))
        if ok:
            disposable_id = json.loads(text)["created_object_id"]
            check("DELETE /objects/locations/{id}", self.request("DELETE", f"/api/objects/locations/{disposable_id}"), ok_statuses=(200, 204))

    # -- report --------------------------------------------------------------------------

    def report(self):
        width = max(len(r[1]) for r in self.rows)
        for kind, path, status, verdict, note in self.rows:
            print(f"{verdict:5} {kind:5} {status}  {path:<{width}}  {note}")
        counts = {k: sum(1 for r in self.rows if r[0] == k) for k in ("page", "api", "write")}
        print()
        print(f"{counts['page']} pages, {counts['api']} API reads, {counts['write']} writes; "
              f"{len(self.failures)} failure(s), {sum(1 for r in self.rows if r[3] == 'KNOWN')} known")
        for failure in self.failures:
            print("  FAIL", failure)
        return 0 if not self.failures else 1


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--user", default="admin")
    parser.add_argument("--password", default="admin")
    parser.add_argument("--new-password", default="walk-password-1")
    parser.add_argument("--known", action="append", default=[], metavar="PATH=STATUS",
                        help="a path that is known to answer STATUS, with the issue that tracks it; "
                             "reported as KNOWN rather than FAIL")
    args = parser.parse_args()

    known = {}
    for item in args.known:
        path, _, status = item.rpartition("=")
        known[path] = int(status)

    walker = Walker(args.base_url, args.user, args.password, args.new_password, known)
    walker.login()
    walker.pages()
    walker.api_reads()
    walker.write_cycle()
    return walker.report()


if __name__ == "__main__":
    sys.exit(main())
