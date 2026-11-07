"""Live rate-limit test. ONLY against a local copy (BASE must be http://127.0.0.1:...). Never point this at production."""
import re, sys, requests
BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8211'
assert BASE.startswith('http://127.0.0.1') or BASE.startswith('http://localhost'), 'refusing to run against a non-local host'
# JSON accept header: a blocked browser page is a redirect with a toast (HTTP 302); a blocked JSON/API request is HTTP 429.
H = {'User-Agent': 'rate-limit-copy-test', 'Accept': 'application/json'}

def tok(html):
    m = re.search(r'name="_token" value="([^"]+)"', html) or re.search(r'name="csrf-token" content="([^"]+)"', html)
    return m.group(1) if m else None

def hammer(label, make, n, expect_after):
    codes = []
    body429 = None
    for i in range(n):
        r = make(i)
        codes.append(r.status_code)
        if r.status_code == 429 and body429 is None:
            body429 = (r.headers.get('Retry-After'), r.text[:200].replace('\n', ' '))
    first = codes.index(429) + 1 if 429 in codes else None
    print(f'{label:34s} requests={n:3d} first 429 at request #{first} (expected after {expect_after}) | statuses {sorted(set(codes))} | Retry-After/body: {body429}')

# 1. portal login: 5 a minute per address + identifier
s = requests.Session(); s.headers.update(H)
def login(i):
    t = tok(s.get(BASE + '/portal/login').text)
    return s.post(BASE + '/portal/login', data={'_token': t, 'email': 'nobody@example.invalid', 'password': 'wrong-password-1'}, allow_redirects=False)
hammer('portal login (same identifier)', login, 8, 5)

# 2. chatbot: 30 a minute
c = requests.Session(); c.headers.update(H)
t = tok(c.get(BASE + '/plan').text)
def chat(i):
    return c.post(BASE + '/chatbot/message', json={'message': 'hello'}, headers={'X-CSRF-TOKEN': t, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'})
hammer('chatbot message', chat, 34, 30)

# 3. QR check-in: 20 a minute
q = requests.Session(); q.headers.update(H); q.headers['Accept'] = 'text/html'
hammer('check-in (unsigned link)', lambda i: q.get(BASE + '/check-in/destination/2', allow_redirects=False), 24, 20)

# 4. exit survey: 10 an hour per address (a fresh browser each time, so the 6-hour per-browser rule does not hide it)
def survey(i):
    e = requests.Session(); e.headers.update(H)
    t = tok(e.get(BASE + '/exit-survey').text)
    return e.post(BASE + '/exit-survey', data={'_token': t, 'overall_rating': 4, 'would_recommend': 'Yes'}, allow_redirects=False)
hammer('exit survey (fresh browsers)', survey, 12, 10)

# 5. trip build: 30 a minute (POST /plan/itinerary/regenerate without a plan still passes through the throttle first)
b = requests.Session(); b.headers.update(H)
t = tok(b.get(BASE + '/plan').text)
hammer('trip-build (regenerate)', lambda i: b.post(BASE + '/plan/itinerary/regenerate', data={'_token': t}, allow_redirects=False), 33, 30)
