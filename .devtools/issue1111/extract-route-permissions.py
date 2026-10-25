#!/usr/bin/env python3
"""Static route -> permission-token extractor for issue 1111 (no PHP needed).

Usage, from the repository root:  python3 .devtools/issue1111/extract-route-permissions.py > .devtools/issue1111/routes-raw.tsv

Reads routes.php, finds each Slim route's handler method in controllers/**, and lists every
PERMISSION_* constant (and policy-object name) that appears in that method body, then follows
$this->/self::/static:: calls to other methods of the same class up to two levels
("via <method>"). Blind spots: Dispatch()-style controllers (labels) show only their top-level
check; policy objects (EntityReadPolicy, UserfileOwnerPolicy, FieldPolicy, RolesService::CheckMay*)
appear as names, not constants; checks in services/ are not followed. Output columns:
routes.php line, HTTP method, path (relative to its group: '' or '/api'), Controller::method,
tokens as NAME@line of the controllers file the token sits in.
"""
import re, glob, os

src = open('routes.php').read().split('\n')
routes = []
group = ''
for i, l in enumerate(src, 1):
    g = re.search(r"\$app->group\('([^']*)'", l)
    if g:
        group = g.group(1)
        continue
    m = re.search(r"\$\w+->(get|post|put|delete|patch)\(\s*'([^']*)'\s*,\s*\[\s*([\w\\]+)::class\s*,\s*'(\w+)'", l)
    if m:
        routes.append((i, m.group(1).upper(), group + m.group(2), m.group(3).split('\\')[-1], m.group(4)))

files = {os.path.basename(f)[:-4]: f for f in glob.glob('controllers/**/*.php', recursive=True)}
cache = {}

def methods(path):
    lines = open(path).read().split('\n')
    res, i = {}, 0
    while i < len(lines):
        m = re.match(r'\s*(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)', lines[i])
        if m:
            depth, seen, j = 0, False, i
            while j < len(lines):
                depth += lines[j].count('{') - lines[j].count('}')
                seen = seen or '{' in lines[j]
                if seen and depth <= 0:
                    break
                j += 1
            res[m.group(1)] = (i + 1, lines[i:j + 1])
            i = j
        i += 1
    return res

TOKEN = re.compile(r'PERMISSION_[A-Z_]+|CheckMayAdminister|CheckMayGrant|MayAdminister|EntityReadPolicy::\w+|UserfileOwnerPolicy::\w+|FieldPolicy|PricesVisible|DomainPermission')
SKIP = {'HandleApiCall', 'GenericErrorResponse', 'ApiResponse', 'InRequestTransaction', 'EmptyApiResponse'}

def tokens(cls, meth, seen, depth=0):
    if cls not in files:
        return []
    path = files[cls]
    cache.setdefault(path, methods(path))
    ms = cache[path]
    if meth not in ms:
        return []
    start, body = ms[meth]
    out = []
    for k, l in enumerate(body):
        if l.strip().startswith(('*', '//', '/*')):
            continue
        out += [f"{t.group(0).replace('PERMISSION_', '')}@{start + k}" for t in TOKEN.finditer(l)]
    if depth < 2:
        for l in body[1:]:
            if l.strip().startswith(('*', '//')):
                continue
            for m in re.finditer(r'(?:\$this|self|static)(?:->|::)(\w+)\(', l):
                h = m.group(1)
                if h in ms and h != meth and h not in seen and h not in SKIP:
                    seen.add(h)
                    out += [f"{x}(via {h})" for x in tokens(cls, h, seen, depth + 1)]
    return out

for ln, verb, path, cls, meth in routes:
    toks, seen = [], set()
    for t in tokens(cls, meth, set()):
        if t not in seen:
            seen.add(t)
            toks.append(t)
    print('\t'.join([str(ln), verb, path, f"{cls}::{meth}", ' '.join(toks)]))
