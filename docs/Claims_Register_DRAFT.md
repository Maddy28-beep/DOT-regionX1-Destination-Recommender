# Claims register: DRAFT (manuscript `ExploreDVO_manuscript_revised_green_v2.docx`)

Generated on 2026-11-08/09 by `tools/claims/build_register.py` from the plain text of v2 (references excluded). The full list, one row per numeric sentence, is `docs/Claims_Register_DRAFT.csv` (open in Excel).

## How complete is this

- 222 sentences in the body contain a number. **Only 22 of them are classified so far; 158 are marked NOT YET CHECKED and 42 are citations/dates to compare with the reference list.** This is a draft, not a finished register.
- What the classified rows rest on: the Section 2.3.9 re-run, the Table 10 and Apriori re-run, the Table 7/8/12 regeneration, and the code reading in the 2026-11-07 algorithm audit. Method and numbers: `docs/Prep_Evaluation_Before_Freeze.md`.
- Not covered row by row: numbers inside table cells (Tables 5, 6, 7, 8, 11, 12, 57 and the data dictionary), numbers in figures and screenshots, and the headings under which a sentence sits (section labels are approximate; the extractor only recognises numbered headings).
- The Unchecked rows are mostly design statements (formulas, weights, requirements, screen descriptions, data-dictionary text). They need a human read-through against the code; they are not known to be wrong.

## Status counts

| Status | Sentences |
|---|---|
| NOT YET CHECKED | 158 |
| CITATION | 42 |
| STALE | 9 |
| FLAG | 7 |
| VERIFIED | 2 |
| WRONG | 2 |
| CHECK | 1 |
| NOT RE-RUN | 1 |

Status meaning: VERIFIED = reproduced by a script on the 2026-11-08 data. STALE = reproduced, but the number is different now (the new result is in the table). WRONG = the paper describes behaviour the code no longer has, or a claim that does not hold. FLAG = names a place that cannot be recommended today. NOT RE-RUN = a one-off live check that cannot be repeated on today's data. CHECK = compare by hand. CITATION = year or reference number.

## Classified claims

| ID | Section | Claim (start) | Status | Produced by | Run | Result now |
|---|---|---|---|---|---|---|
| C100 | 2.3.3 Entity Relationship Diagram | For example, if Samal Island has 100 reviews and the highest review count among all candidate destinations is also 100, the Popularity Score... | FLAG | destinations.itinerary_role / operating_status / accreditation | 2026-11-08 | names a place that cannot be recommended today (Samal Island is an excluded area, People's Park is temporarily closed, Crocodile Park is unaccredited): re-run or relabel the example |
| C108 | 2.3.3 Entity Relationship Diagram | Using Equation 3, the Destination Recommendation Score of Samal Island is computed as follows:... | FLAG | destinations.itinerary_role / operating_status / accreditation | 2026-11-08 | names a place that cannot be recommended today (Samal Island is an excluded area, People's Park is temporarily closed, Crocodile Park is unaccredited): re-run or relabel the example |
| C111 | 2.3.3 Entity Relationship Diagram | Therefore, the computed straight-line distance between Francisco Bangoy International Airport and Samal Island is approximately 7.5 kilomete... | FLAG | destinations.itinerary_role / operating_status / accreditation | 2026-11-08 | names a place that cannot be recommended today (Samal Island is an excluded area, People's Park is temporarily closed, Crocodile Park is unaccredited): re-run or relabel the example |
| C112 | 2.3.3 Entity Relationship Diagram | It converts it to an estimated road distance by multiplying it by a detour factor of 1.3 (about 9.8 kilometers in this example), estimates t... | FLAG | destinations.itinerary_role / operating_status / accreditation | 2026-11-08 | names a place that cannot be recommended today (Samal Island is an excluded area, People's Park is temporarily closed, Crocodile Park is unaccredited): re-run or relabel the example |
| C117 | 2.3.3 Entity Relationship Diagram | Confidence=75%The computed values indicate that 60% of all tourist visitation records include both Samal Island and Altavista Beach Resort, ... | VERIFIED | deploy/qa/eval/apriori_table10.php | 2026-11-08 | Samal to Altavista support 0.60, confidence 0.75 reproduced with the real service |
| C118 | 2.3.3 Entity Relationship Diagram | In the current implementation, an association rule is kept only when its two items appear together in at least two exit surveys and its conf... | CHECK | ContentBasedRecommendationService weights | 2026-11-08 | code weights PM 0.30, RS 0.20, PS 0.20, DS 0.15, AS 0.15; compare each sentence by hand |
| C128 | 2.3.3 Entity Relationship Diagram | For example, the stored vectors rank JKM Mini Zoo (similarity 0.82) as the closest match to Davao Crocodile Park, both being wildlife attrac... | FLAG | destinations.itinerary_role / operating_status / accreditation | 2026-11-08 | names a place that cannot be recommended today (Samal Island is an excluded area, People's Park is temporarily closed, Crocodile Park is unaccredited): re-run or relabel the example |
| C202 | 2.3.8 Prototypes | The stop displays the advisory and offers the closest open substitute, here Davao Crocodile Park (77% similar, 22.2 km away), with a single ... | FLAG | destinations.itinerary_role / operating_status / accreditation | 2026-11-08 | names a place that cannot be recommended today (Samal Island is an excluded area, People's Park is temporarily closed, Crocodile Park is unaccredited): re-run or relabel the example |
| C203 | 2.3.8 Prototypes | Davao Crocodile Park’s accreditation record has since expired (6 August 2026), so it is no longer offered as a substitute.... | FLAG | destinations.itinerary_role / operating_status / accreditation | 2026-11-08 | names a place that cannot be recommended today (Samal Island is an excluded area, People's Park is temporarily closed, Crocodile Park is unaccredited): re-run or relabel the example |
| C208 | 2.3.9 Testing and Evaluation of th | To check whether similar means alike, each of the 24 destinations in the catalogue was compared with its single closest match, and the check... | STALE | SELECT count(*) FROM destinations; embeddings coverage | 2026-11-08 | catalogue now 151 destinations (24 official, 127 sample); 139 public |
| C209 | 2.3.9 Testing and Evaluation of th | The closest match shared a category tag for 18 of the 24 destinations (75.0%), whereas a randomly chosen other destination would share one o... | STALE | deploy/qa/eval/eval_embeddings.php | 2026-11-08 | now 19 of 24 (79.2%), chance 12.7%, 6.2x on the 24 official places |
| C210 | 2.3.9 Testing and Evaluation of th | The result was the same, 18 of 24 (75.0%), which shows that the model recognizes similar places from their descriptions and does not merely ... | STALE | deploy/qa/eval/eval_embeddings.php (WITH_OLLAMA=1) | 2026-11-08 | without tags now 19 of 24 (79.2%), unchanged from with tags |
| C211 | 2.3.9 Testing and Evaluation of th | The mean similarity between a destination and its closest match was 84.1%.... | STALE | deploy/qa/eval/eval_embeddings.php | 2026-11-08 | mean closest similarity now 83.4% (81.2% without tags) |
| C213 | 2.3.9 Testing and Evaluation of th | The days were regrouped in 39 of the 60 trips (65.0%); a trip counts as regrouped only when its days changed and the average similarity with... | STALE | php artisan itineraries:evaluate-themes --trips=60 | 2026-11-08 | now 38 of 60 (63.3%), 15 undone |
| C214 | 2.3.9 Testing and Evaluation of th | The average similarity of the places sharing a day rose from 73.2% under the plain route order to 76.0% with theme-based days, a gain of 2.8... | STALE | php artisan itineraries:evaluate-themes --trips=60 | 2026-11-08 | now 74.3% to 76.8% (+2.5 points) |
| C215 | 2.3.9 Testing and Evaluation of th | A second measure that does not use the vectors, the number of different place types in a day (lower means more alike), fell from 1.78 to 1.5... | STALE | php artisan itineraries:evaluate-themes --trips=60 | 2026-11-08 | now 1.59 to 1.54 |
| C216 | 2.3.9 Testing and Evaluation of th | The largest increase on a single trip was 18.3%, within the limit of 25% plus the 3-kilometer allowance.... | STALE | php artisan itineraries:evaluate-themes --trips=60 | 2026-11-08 | largest single trip now +40.0% (inside the 25% + 3 km allowance) |
| C217 | 2.3.9 Testing and Evaluation of th | To check the choice of stay, 150 random trips of two to six days were generated and the distance from the chosen stay to each sightseeing st... | WRONG | deploy/qa/eval/eval_stay_distance.php | 2026-11-08 | trips of 2+ days use one stay per night; both rules give 16.7% / 19.3% of trips over 50 km |
| C218 | 2.3.9 Testing and Evaluation of th | Counting the farthest stop alongside the total distance, instead of the total alone, reduced the trips in which some stop lay more than 50 k... | WRONG | deploy/qa/eval/eval_stay_distance.php | 2026-11-08 | trips of 2+ days use one stay per night; both rules give 16.7% / 19.3% of trips over 50 km |
| C219 | 2.3.9 Testing and Evaluation of th | The tests confirm that the suggestions never include the stop itself, a place already in the itinerary, an archived or non-accredited place,... | VERIFIED | SimilarDestinationService::MAX_DISTANCE_KM; deploy/qa/eval/substitute_checks.php | 2026-11-08 | limit 60 km; largest offered 55.9 km; 10 of 10 forged replacements refused |
| C220 | 2.3.9 Testing and Evaluation of th | In a live check, when Eden Nature Park was marked temporarily closed until December 1, an itinerary for a trip starting October 12 left it o... | NOT RE-RUN | live check of October, not repeatable on today data | 2026-11-08 | Crocodile Park is unaccredited today; People's Park is the closed place instead |
| C221 | 2.3.9 Testing and Evaluation of th | These results come from the 24 destinations held in the catalogue when the tests were run (the public website now lists 19 of them, because ... | STALE | SELECT count(*) FROM destinations; embeddings coverage | 2026-11-08 | catalogue now 151 destinations (24 official, 127 sample); 139 public |

## Table claims checked by hand (not in the CSV)

| Table | Claim | Status | Produced by | Result now |
|---|---|---|---|---|
| Table 7/8 | Samal 4.87, Eden 4.60, Crocodile 4.31, Malagos 4.09, People's Park 3.96 | STALE | audit 2026-11-07 arithmetic; eval_tables.php | arithmetic reproduces from the inputs; the places cannot occur today; regenerate (Prep_Evaluation_Before_Freeze.md section 7) |
| Table 10 | Samal to Altavista support 0.60, confidence 0.75; Samal to Marina Tuna 0.40, 0.50 | VERIFIED | deploy/qa/eval/apriori_table10.php | reproduced exactly with the real service |
| Table 12 | Sample 3-day itinerary for Table 11 inputs | STALE | eval_tables.php | cannot be reproduced (Samal Island, People's Park and Malagos do not appear); replace with the labelled run |
| Table 57 | All rows of the embedding evaluation | STALE/WRONG | eval_embeddings.php; evaluate-themes; eval_stay_distance.php | see Prep_Evaluation_Before_Freeze.md sections 1 to 5 |
| Table 5 (weights) | PM .30, RS .20, PS .20, DS .15, AS .15 | VERIFIED | ContentBasedRecommendationService constants | code matches; examples in the paper used the old catalogue-mean RS |
| Filter radii | 25 km and 75 km | not found in the text | RANGE_RADIUS_KM | code is 5 km and 15 to 50 km; confirm what the paper's table says before editing |
| Meal and arrival times | lunch ends 2 PM, dinner 6:30, late 9:30 PM | WRONG | ItineraryScheduleBuilder constants | lunch starts 11:30 to 13:00, dinner 18:00 to 19:30, home by 20:00 |

## Facts for the paper that changed since v2

- Catalogue: 151 destinations (24 official, 127 sample), 139 public; plannable 115. v2 says 24 and 19.
- Association rules: 789 transactions (787 simulated, 2 real), 0 rules from real visitors.
- Chatbot uses the language model whenever a key is set; the planner is deterministic by default.
- Optional tourist accounts exist; surveys are no longer linkable to them (branch fix, not yet deployed).
- Ratings and popularity are neutral (3.0) below 30 reviews (branch fix, not yet deployed; production still shows RS 5.00).
