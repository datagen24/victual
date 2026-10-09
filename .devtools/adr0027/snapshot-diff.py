import json, sys, collections
def flat(n, p, out):
    if isinstance(n, dict):
        for k, v in n.items(): flat(v, p + '/' + k, out)
    elif isinstance(n, list):
        for i, v in enumerate(n): flat(v, p + '[]', out)
    else: out[p] = n
def tokens(s): return set(str(s).split('|'))
for name in ('contract-admin.json', 'contract-restricted.json'):
    a, b = {}, {}
    flat(json.load(open(sys.argv[1] + '/' + name)), '', a)
    flat(json.load(open(sys.argv[2] + '/' + name)), '', b)
    kinds = collections.Counter(); other = []
    for k in sorted(set(a) | set(b)):
        x, y = a.get(k), b.get(k)
        if x == y:
            if 'string(date)' in tokens(x): kinds['date unchanged'] += 1
            if 'string(date-time)' in tokens(x): kinds['already wire'] += 1
            continue
        tx, ty = tokens(x), tokens(y)
        if (tx - {'string(local-date-time)'}) == (ty - {'string(date-time)'}) and 'string(local-date-time)' in tx and 'string(date-time)' in ty:
            kinds['local-date-time -> date-time'] += 1
        elif (tx - {'string(local-date-time)'}) == (ty - {'string(date-time-offset)'}) and 'string(date-time-offset)' in ty:
            kinds['local-date-time -> date-time-offset'] += 1; other.append(('offset', k, x, y))
        elif (tx - {'string'}) == (ty - {'string(date-time)'}) and 'string' in tx and 'string(date-time)' in ty:
            kinds['string (PostgreSQL TIMESTAMPTZ text / format c) -> date-time'] += 1; other.append(('tz', k, x, y))
        else:
            kinds['OTHER'] += 1; other.append(('OTHER', k, x, y))
    left = sorted(k for b_ in [b] for k in b_ if 'local-date-time' in str(b_[k]))
    print(name, dict(kinds)); print('  local-date-time still present on branch:', left)
    for o in other: print('  ', o)
