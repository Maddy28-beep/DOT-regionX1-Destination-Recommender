"""Builds the DRAFT claims register from the plain text of the v2 manuscript (one paragraph per line).
usage: python tools/claims/build_register.py v2_text.txt docs/Claims_Register_DRAFT"""
import csv, re, sys

src, out = sys.argv[1], sys.argv[2]
lines = open(src, encoding='utf8').read().split('\n')
end = max(i for i, l in enumerate(lines) if l.strip() == 'References')
RUN = '2026-11-08'
EV = 'docs/Prep_Evaluation_Before_Freeze.md'

# (regex on the sentence, section prefix or None, status, how it is produced, result now)
RULES = [
 (r'18 of the 24 destinations \(75\.0%\)|4\.1 times better|18\.1%', '2.3.9', 'STALE', 'deploy/qa/eval/eval_embeddings.php', 'now 19 of 24 (79.2%), chance 12.7%, 6.2x on the 24 official places'),
 (r'same, 18 of 24', '2.3.9', 'STALE', 'deploy/qa/eval/eval_embeddings.php (WITH_OLLAMA=1)', 'without tags now 19 of 24 (79.2%), unchanged from with tags'),
 (r'84\.1%', '2.3.9', 'STALE', 'deploy/qa/eval/eval_embeddings.php', 'mean closest similarity now 83.4% (81.2% without tags)'),
 (r'39 of the 60|\(65\.0%\)|in 9 further trips', '2.3.9', 'STALE', 'php artisan itineraries:evaluate-themes --trips=60', 'now 38 of 60 (63.3%), 15 undone'),
 (r'73\.2%|76\.0%|2\.8 percentage', '2.3.9', 'STALE', 'php artisan itineraries:evaluate-themes --trips=60', 'now 74.3% to 76.8% (+2.5 points)'),
 (r'8,311|7,737|6\.9%', '2.3.9', 'WRONG', 'php artisan itineraries:evaluate-themes --trips=60', 'travelling rose 9,484 to 9,875 km (+4.1%); the claim of less travelling does not hold'),
 (r'1\.78 to 1\.57|1\.57', '2.3.9', 'STALE', 'php artisan itineraries:evaluate-themes --trips=60', 'now 1.59 to 1.54'),
 (r'18\.3%', '2.3.9', 'STALE', 'php artisan itineraries:evaluate-themes --trips=60', 'largest single trip now +40.0% (inside the 25% + 3 km allowance)'),
 (r'150 random trips|27 of 113|12 of 113|37\.2 km|33\.5 km|18\.1 km to 18\.8|19 trips reduced', '2.3.9', 'WRONG', 'deploy/qa/eval/eval_stay_distance.php', 'trips of 2+ days use one stay per night; both rules give 16.7% / 19.3% of trips over 50 km'),
 (r'24 destinations held in the catalogue|19 of them|24 destinations in the catalogue|Destinations embedded|24 of 24', '2.3.9', 'STALE', 'SELECT count(*) FROM destinations; embeddings coverage', 'catalogue now 151 destinations (24 official, 127 sample); 139 public'),
 (r'60 kilometers|more than 60', '2.3.9', 'VERIFIED', 'SimilarDestinationService::MAX_DISTANCE_KM; deploy/qa/eval/substitute_checks.php', 'limit 60 km; largest offered 55.9 km; 10 of 10 forged replacements refused'),
 (r'Philippine Eagle Center|Mount Apo', '2.3.9', 'STALE', 'deploy/qa/eval/eval_embeddings.php', 'Eagle Center rank 1 for Wildlife; Mount Apo rank 11 of 115 for Hiking'),
 (r'convention center ranked second', '2.3.9', 'STALE', 'deploy/qa/eval/eval_embeddings.php', 'SMX is rank 15 of 151 for Shopping & Souvenirs now'),
 (r'December 1|JKM Mini Zoo|Davao Crocodile Park, in a single step', '2.3.9', 'NOT RE-RUN', 'live check of October, not repeatable on today data', 'Crocodile Park is unaccredited today; People\'s Park is the closed place instead'),
 (r'Confidence=75%|60% of all tourist visitation|[Ss]upport (of )?0\.60|confidence (of )?0\.75', '2.3.3', 'VERIFIED', 'deploy/qa/eval/apriori_table10.php', 'Samal to Altavista support 0.60, confidence 0.75 reproduced with the real service'),
 (r'within 25 ?(km|kilometers)|within 75 ?(km|kilometers)|radius of (25|75)|(?<![\d.])(25|75) (km|kilometers)(?!/h| per)', None, 'WRONG', 'ContentBasedRecommendationService::RANGE_RADIUS_KM', 'code: Close 5 km; Commutable 15 to 50 km by transport mode'),
 (r'0\.30|0\.20|0\.15|30%|20%|15%', None, 'CHECK', 'ContentBasedRecommendationService weights', 'code weights PM 0.30, RS 0.20, PS 0.20, DS 0.15, AS 0.15; compare each sentence by hand'),
 (r'4\.87|4\.60|4\.31|4\.09|3\.96', None, 'STALE', 'deploy/qa/eval/eval_tables.php', 'arithmetic reproduces (audit 2026-11-07) but the places cannot occur today; replace with a labelled run'),
 (r'Samal Island|People.s Park|Crocodile Park', None, 'FLAG', 'destinations.itinerary_role / operating_status / accreditation', "names a place that cannot be recommended today (Samal Island is an excluded area, People's Park is temporarily closed, Crocodile Park is unaccredited): re-run or relabel the example"),
]
CITE = re.compile(r'\[\d+\]|\bet al\b|\b(19|20)\d\d\b')

head = ''
rows = []
for n, l in enumerate(lines[:end], 1):
    t = l.strip()
    if re.match(r'^\d+(\.\d+){0,4}\s+[A-Z]', t) and len(t) < 120:
        head = t
    if len(t) < 60:
        continue
    for s in re.split(r'(?<=[.!?])\s+(?=[A-Z(])', t):
        if not re.search(r'\d', s) or len(s) < 25:
            continue
        sec = head.split(' ')[0] if head else ''
        status, how, result = 'NOT YET CHECKED', '', ''
        for rx, sp, st, h, r in RULES:
            if (sp is None or sec.startswith(sp)) and re.search(rx, s):
                status, how, result = st, h, r
                break
        else:
            if CITE.search(s) and not re.search(r'%|km|\bper\b', s):
                status, how, result = 'CITATION', 'compare with the reference list', ''
        rows.append([f'C{len(rows)+1:03d}', head, n, s.strip(), ', '.join(re.findall(r'\d[\d,.]*%?', s))[:80], status, how, RUN if how else '', result])

with open(out + '.csv', 'w', newline='', encoding='utf-8-sig') as f:
    w = csv.writer(f)
    w.writerow(['id', 'section', 'paragraph', 'claim', 'numbers', 'status', 'produced_by', 'run_date', 'result_now'])
    w.writerows(rows)

from collections import Counter
c = Counter(r[5] for r in rows)
print(len(rows), dict(c))
