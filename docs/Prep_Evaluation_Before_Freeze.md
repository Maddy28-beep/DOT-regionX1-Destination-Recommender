# Section 2.3.9 and Tables 7, 8, 10, 12 re-run: BEFORE the data freeze

**Every number below is "before freeze".** Repeat all of it after the freeze (see `Data_Freeze_Runbook.md`) and compare.

- Data snapshot: production dump taken 2026-11-08 (`prod_2026-11-08.dump`, sha256 starts `1acf9d3d8e6f3c35`), restored into the local database `restore_check` with the beta migration. 151 destinations: 24 official, 127 sample.
- Run date: 2026-11-08. Code: branch `phase2/post-trial`, scripts committed in `ddb628d` (code under test = beta `f90f3ac` plus the prep commits, none of which changes the planner).
- Raw output of every run: `docs/eval-before-freeze/`. Scripts: `deploy/qa/eval/`. All of them are read-only or roll their transaction back.
- Embedding model: `nomic-embed-text` (local Ollama). The stored vectors were built from the pre-freeze text; 120 of them are stale against the current text recipe (see the runbook), which is part of what the freeze fixes.
- Fixtures (stated exactly, as the audit asked):
  - **official** = 24 places that are not sample data: ids 17, 9, 15, 10, 11, 13, 12, 14, 16, 22, 23, 8, 18, 19, 20, 24, 21, 43, 44, 45, 46, 47, 48, 49 (names in `eval_embeddings_before.txt`). Note ids 22, 23 and 24 are three near-identical Elysia Wellness Spa rows, which inflates neighbour matches.
  - **plannable** = 115 places that are accredited, not archived, not role "excluded" and not closed (sample places included). Ids listed in the same file.
  - The paper's "24 destinations in the catalogue" were never frozen as a list. These two fixtures replace it, and the second one is what a trip can actually draw from today.

## 1. Closest-match tag test (paper: 18/24 = 75.0%, chance 18.1%, 4.1x; without tags 18/24; mean similarity 84.1%)

Script `eval_embeddings.php`, with Ollama for the "without tags" rows.

| Fixture | Condition | Closest shares a tag | Random other | Ratio | Mean closest similarity |
|---|---|---|---|---|---|
| official (24) | stored vectors | 19 of 24 (79.2%) | 12.7% | 6.24x | 83.4% |
| official (24) | fresh embed, with tags | 19 of 24 (79.2%) | 12.7% | 6.24x | 83.4% |
| official (24) | fresh embed, WITHOUT tags | 19 of 24 (79.2%) | 12.7% | 6.24x | 81.2% |
| plannable (115) | stored vectors | 61 of 65 evaluable (93.8%) | 30.4% | 3.08x | 84.7% |
| plannable (115) | fresh embed, with tags | 65 of 65 (100.0%) | 30.4% | 3.29x | 83.0% |
| plannable (115) | fresh embed, WITHOUT tags | 59 of 61 evaluable (96.7%) | 30.4% | 3.18x | 81.3% |

Reading: the claim "the model recognises alike places from descriptions, not only from tags" still holds (removing the tags does not lower the official result). The headline numbers differ from the paper (79.2% and 6.2x, not 75.0% and 4.1x). On the plannable pool the sample places are templated, so a high match rate there says little about real places. The stored plannable row (93.8%) is below the fresh one (100%) because the stored vectors are stale.

## 2. 60-trip theme-day test (paper: 39 of 60 regrouped, 73.2 to 76.0%, 8,311 to 7,737 km = 6.9% less, types 1.78 to 1.57, largest +18.3%)

`php artisan itineraries:evaluate-themes --trips=60` (fixed seed 2026, rolled back).

| Measure | Result now |
|---|---|
| Trips regrouped | 38 of 60 (63.3%) |
| Similarity of places sharing a day | 74.3% to 76.8% (+2.5 points) |
| Place types per day | 1.59 to 1.54 |
| Regrouping undone (fewer stops) | 15 |
| Total travelling | 9,484 km to 9,875 km (**+4.1%**) |
| Largest single trip | +40.0% (inside the 25% + 3 km allowance on a short route) |

Reading: grouping still raises similarity, but the paper's "travelling fell 6.9%" does not reproduce. It rises by about 4%. The paper must say so, or drop the claim.

## 3. Stay-distance row (paper: stops over 50 km from the stay, 27 of 113 (24%) to 12 of 113 (11%) by counting the farthest stop)

`eval_stay_distance.php`, 150 random trips of 2 to 6 days per seed, the shipped rule against an in-memory copy with the farthest-stop weight at 0.

| Seed | Rule | Trips with a stop over 50 km from that night's stay | Mean farthest stop | Mean distance per stop |
|---|---|---|---|---|
| 2026 | farthest + total (shipped) | 25 of 150 (16.7%) | 26.1 km | 13.6 km |
| 2026 | total alone | 25 of 150 (16.7%) | 26.1 km | 13.6 km |
| 7 | farthest + total (shipped) | 29 of 150 (19.3%) | 28.4 km | 14.0 km |
| 7 | total alone | 29 of 150 (19.3%) | 28.4 km | 14.0 km |

Reading: **paper wrong.** The two rules give identical results because trips of two or more days no longer use that rule. They pick one stay per night near that day's last stop (`pickStays`); the farthest-stop weighting survives only in `pickAccommodation`, which serves one-day trips and a fallback. The paper's description of one trip-wide stay chosen by total plus farthest distance describes a path that is no longer used. The row should be rewritten with the per-night rule and the 16.7% / 19.3% figures, or removed.

## 4. Interest top and bottom ranking (paper: Philippine Eagle Center first for Wildlife and Mount Apo Natural Park for Hiking & Trekking; unrelated places such as the convention centre last; weakness: for Shopping & Souvenirs the convention centre ranked second)

`eval_embeddings.php`, stored vectors, top 3 and bottom 3 for each of the 8 interests on both fixtures (full list in the raw file), plus the rank of the places the paper names.

| Paper statement | Result now | Verdict |
|---|---|---|
| Philippine Eagle Center first for Wildlife | rank 1 of 115 plannable (and 1 of 151) | holds |
| Mount Apo Natural Park first for Hiking & Trekking | rank 11 of 115 plannable (16 of 151); on the 24 official places the top is Osmeña Park (66%) | does not hold as written |
| Convention centre last | SMX is in the bottom three for 6 of 8 interests on the official fixture (last for Beach & Island, Food, Nature & Adventure and Wildlife); rank 149 of 151 for Nature & Adventure | holds |
| Shopping & Souvenirs: convention centre ranked second | rank 15 of 151; not in the bottom three either | no longer reproduces |

Other readings: on the official fixture Wildlife top = Davao Crocodile Park (77%) and JKM Mini Zoo (73%); Food top = Bankerohan Public Market (79%); Shopping top = Cacao Culture Chocolate Shop (73%); Relaxation top = the three Elysia Wellness Spa rows (80%). On the plannable fixture the top places are sample places whose descriptions are templated by interest (for example Hiking top = "Sample Highland Tiered Falls & Trail"). That shows the method works on its input; it does not show quality on real places.

## 5. Substitute checks (paper: 0 unsuitable substitutes in automated tests)

`substitute_checks.php` (forged swap requests against the rule that the controller applies), plus the existing automated tests.

| Forged replacement | Accepted? |
|---|---|
| closed for good, temporarily closed with no reopening date, danger advisory, archived, excluded role, not accredited, already in the trip, over 60 km, non-existent id, the stop itself | no (10 of 10 refused) |
| a valid place that was rank 11 | accepted: the script prints FAIL, but that is its own label. After the injected closures removed three places from the top 10, the former rank-11 place legitimately moved into the offered list. It is a suitable place. |
| unmapped places offered despite unknown distance | 0 |

Largest distance among the offered ten: 55.9 km (limit 60 km). The paper's "0 unsuitable substitutes" holds.

## 6. Apriori Table 10 and rule counts

`apriori_table10.php` reproduces the paper's example exactly with the real service: Samal Island to Altavista Beach Resort, support 0.60, confidence 0.75, co-occurrence 3; Samal Island to Marina Tuna Restaurant, support 0.40, confidence 0.50, co-occurrence 2. Equations 8 and 9 hold. The service returns no lift value (the paper must not claim one).

`apriori_counts.php` on the production data:

| Source | Transactions | Admin page rules (co 2+, conf 15%+) | Planner rules (co 2+, conf 10%+) |
|---|---|---|---|
| all | 789 (787 demo, 2 real) | 750 | 937 |
| demo only | 787 | 749 | 936 |
| real only | 2 | **0** | **0** |
| itinerary only | 0 | 0 | 0 |

Independent brute-force recount: 750 rules, equal to the service, 0 mismatches. Of the rules the planner can use, 174 pair a plannable destination with a stay, restaurant or souvenir shop, and none of them rests on real visitors. This is why item 1c labels them "Simulated".

## 7. Tables 7, 8 and 12 regenerated from places plannable now

`eval_tables.php`, inputs of Table 11 (3 days, Leisure, First-time Visitor, Moderate budget, Beach Resort, Nature & Adventure + Wildlife + Cultural Heritage, Family, arrival 08:00, Francisco Bangoy International Airport, start 2026-12-14). Table 11 does not give the distance range, so all three were run. Full tables, the Table 12 itinerary for each range, the swap example and the theme-day example are in `eval_tables_before.txt`.

Top five (Tables 7 and 8) for the Commutable range:

| Rank | Destination | PM | RS | PS | DS | AS | DRS |
|---|---|---|---|---|---|---|---|
| 1 | Upland Botanical Park (sample) | 4.67 | 3.00 | 3.00 | 5.00 | 5.00 | 4.10 |
| 2 | Highland Flower Park (sample) | 4.53 | 3.00 | 3.00 | 5.00 | 5.00 | 4.06 |
| 3 | Eden Nature Park (sample record) | 4.50 | 3.00 | 3.00 | 5.00 | 5.00 | 4.05 |
| 4 | Malagos Garden Resort (sample record) | 4.37 | 3.00 | 3.00 | 5.00 | 5.00 | 4.01 |
| 5 | East Reef Marine Learning Park (sample) | 4.25 | 3.00 | 3.00 | 5.00 | 5.00 | 3.98 |

Notes: RS and PS are 3.00 for every place because the catalogue has too few reviews (this run used the beta code; the 30-review threshold of item 1b gives the same 3.00 on purpose). Eden Nature Park and Malagos Garden Resort are among the seven core records that are placeholders in the data, which is why they carry the sample label. Samal Island (an area, role "excluded"), People's Park (temporarily closed) and Davao Crocodile Park (accreditation expired) cannot appear, so the paper's original Table 7 places cannot be reproduced and the table has to be replaced. Replace it with a real labelled run, as above, and say that every row is sample data except where marked.

## 8. Trip checks and QA sweep on the frozen copy (not before freeze: this is the freeze rehearsal)

| Check | Result |
|---|---|
| Trip rules (F1 closed restaurants, F2 18:00 cutoff, expiry, closure, advisories, stay distance, souvenir budget), seed 20261107 x 240 and seed 777 x 220 | 0 errors, 0 failed rules in both |
| Days over 8 hours of driving with a single stop | 5 (seed e) and 19 (seed f) = 24, of which Close and Commutable 10, Remote 14. Beta copy gave 27 (15 and 12); the data differ, so this is not a regression. |
| QA sweep D1 to D15 | 290 of 290 |
| PHPUnit | 1751 tests, 5 errors, all five need the GD extension |
