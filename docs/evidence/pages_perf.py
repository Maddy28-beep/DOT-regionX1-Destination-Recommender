import time, statistics, json, urllib.request, urllib.error, sys
BASE='https://www.exploredavao.com'
paths=['/','/destinations','/accommodations','/restaurants','/plan/start','/plan','/advisories','/destinations/apo-golf-and-country-club']
N=20
out={}
for p in paths:
    ts=[];codes=[]
    for i in range(N):
        req=urllib.request.Request(BASE+p,headers={'User-Agent':'ExploreDVO-verification/2026-11-04 (controlled-beta evidence run)','Accept-Encoding':'gzip'})
        t=time.perf_counter()
        try:
            with urllib.request.urlopen(req,timeout=30) as r:
                r.read(); c=r.status
        except urllib.error.HTTPError as e:
            c=e.code
        except Exception as e:
            c=0
        ts.append(time.perf_counter()-t); codes.append(c)
        time.sleep(0.4)
    s=sorted(ts)
    out[p]={'n':N,'p50':round(statistics.median(ts),3),'p95':round(s[int(0.95*N)-1],3),'max':round(max(ts),3),'min':round(min(ts),3),'codes':{str(c):codes.count(c) for c in set(codes)}}
    print(p,out[p],flush=True)
json.dump(out,open('pages_perf.json','w'),indent=1)
