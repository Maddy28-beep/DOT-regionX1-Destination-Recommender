# ExploreDVO — Final Audit of Proposed 130-Record Sample Catalogue

> **Review-only audit.** The proposal file `docs/Sample-Catalogue-130-Review.md` was **not** modified.
> No records were inserted, updated, or deleted. No importer, migration, or deployment was executed.
> Audit date basis: committed codebase + the proposal file as written. Production database: **NOT ACCESSED**.

## 0. Audit method and totals

Automated checks were executed against the proposal file (parser + `App\Support\Amenities` catalogue +
Haversine municipality-centre checks), plus manual review of every record against the schema, the
recommendation/scheduling code, and Region XI geography.

| Category | Proposed | Audited | Passed | Requiring correction |
|---|---|---|---|---|
| Destinations | 54 | 54 | 49 | 5 (D-03, D-08, D-26, D-46, D-49*) |
| Accommodations | 27 | 27 | 26 | 1 (A-26*) |
| Restaurants | 27 | 27 | 26 | 1 (R-26*) |
| Souvenir centres | 22 | 22 | 21 | 1 (V-22*) |
| **Total** | **130** | **130** | **121** | **9 distinct records** |

\* D-49, A-26, R-26, V-22 share one geographical correction (Santa Cruz node placement, Finding F-05);
D-49 additionally appears in Finding F-02. Category totals in the proposal (54/27/27/22 = 130) are **correct**.
Testing origins audited: 14 (Section 5).

**Automated results (verbatim outcomes):** 130 records parsed; 0 exact duplicate coordinate pairs;
0 illegal amenity keys for kind; 0 records below the 4-amenity minimum; 0 accommodation nightly rates
outside their room-type bands; 1 exact duplicate venue name (D-38 = D-46).

## 1. Findings requiring correction (9 records)

### F-01 — Duplicate venue name: D-38 and D-46 (Severity: Medium)
Both are named **"Sample Southern Coastal Viewpoint"** (D-38 Sarangani, Davao Occidental; D-46 Governor
Generoso, Davao Oriental). Duplicate display names break the one-stop-per-business-name logic's intent
(`distinctByDestinationName` in itinerary generation), confuse exit-survey/Apriori labels, and violate the
proposal's own cross-kind/name dedupe gate.
**Correction:** rename D-46 to a distinct neutral fictional name, e.g. "Sample Southern Headland Viewpoint".
Coordinates and assignment unchanged.

### F-02 — Shopping interest cannot be covered as specified: D-03, D-27, D-49 (Severity: High for the stated goal)
The proposal claims Shopping & Souvenirs becomes coverable as sightseeing stops. Under the actual code it
does not: `InterestCoverage::covers()` and `interestSimilarity()` match interests by (a) destination `type`
via `TYPE_TO_INTEREST` — no type maps to Shopping — or (b) category-tag keyword (`shopping`, `souvenir`).
D-03 and D-27 carry only the tag `City Center`; D-49 carries only `Cultural Heritage`. No verified in-use
category tag named Shopping/Souvenir exists. As written, Shopping remains fillable **only** by souvenir
centres as itinerary appendages, exactly as today.
**Correction:** add category tags `Shopping` and `Souvenir` (new tag *values* — `destination_tags.value` is a
free string, so this is schema-legal and needs no migration or taxonomy change) to D-03, D-27, and D-49.
This must be stated explicitly in the import file, since these values do not yet exist in the catalogue.
The eight-interest taxonomy itself is untouched.

### F-03 — Inappropriate "Highest Peak" tag: D-26 (Severity: Low)
D-26 (crater-lake viewpoint trek, Maco) is tagged `Highest Peak`. That keyword exists for Mount Apo-class
records; on a Maco viewpoint it inflates hiking/peak matching and mis-describes the venue.
**Correction:** remove `Highest Peak` from D-26; retain `Hiking`. No other change.

### F-04 — Type/tag mismatch: D-08 (Severity: Low)
D-08 is typed `Beach & Surfing` but tagged `Beach, Turtle Sanctuary` with a snorkelling activity description
and no `Surfing` tag. Type and tags tell different stories to `TYPE_TO_INTEREST`, keyword matching, and
embedding text.
**Correction (choose one):** retype D-08 to `Beach & Leisure` (recommended — the described activity is
snorkelling), or add the `Surfing` tag and a surfing activity. Do not leave type and tags divergent.

### F-05 — Santa Cruz node coordinates sit ~13 km from Santa Cruz centre, toward Digos upland: D-49, A-26, R-26, V-22 (Severity: Medium)
All four records declare Santa Cruz (Davao del Sur) but share coordinates around 6.93, 125.35 — approximately
13 km northwest of Santa Cruz poblacion/coastal centre (~6.84, 125.42) and geographically closer to the
Digos/Kapatagan side, while the node's own boardwalk record D-51 sits at 6.8450, 125.4150. As placed, the
" Santa Cruz node" is split across two areas ~13 km apart, weakening the node coherence the catalogue is
designed to provide and risking misleading meal/stay distances. Municipal-boundary membership at that point
is **NOT VERIFIED** (no GIS/boundary data was available to this audit).
**Correction (choose one):** (a) relocate D-49, A-26, R-26, V-22 to the Santa Cruz coastal area around
6.85–6.88, 125.40–125.42 near D-51 (recommended); or (b) reassign their municipality to Digos City and treat
them as part of the Digos node. Do not import as-is.

### Observations that do NOT require correction (verified, with reasoning)
- **Kapatagan records (D-47, D-48, A-25, R-25, V-21)** are ~22 km from Digos City centre. Correct: Kapatagan
  is an upland barangay of Digos City; the distance is the intended lowland<->highland test leg. **Pass.**
- **Talikud records (D-08, D-11, A-07, R-07)** are ~14–15 km from the Samal (Babak) centre. Correct island
  geography; intra-island legs incur no ferry. **Pass.**
- **D-43 (Cateel, 7.7400, 126.2950)** is ~18 km inland-west of Cateel centre. Plausible for a falls park;
  exact municipal-boundary membership **NOT VERIFIED**. **Pass with note.**
- **Baganga-north records (A-22, R-22, V-19)** are ~13 km north of Baganga centre along the coast. Plausible
  coastal placement; boundary **NOT VERIFIED**. **Pass with note.**
- **V-04 (Toril, Davao City)** ~14 km from city centre. Toril is a Davao City district. **Pass.**
- **Souvenir-centre amenity sets** are near-identical across most V-records (the six legal keys). Per the
  approved correction, repeated realistic combinations are acceptable; all are kind-legal and >=4. **Pass.**
  Noted only so reviewers see it was checked, not overlooked.
- **Near-duplicate (not identical) names** — A-01/A-08, A-13/A-25, V-02/V-10/V-22, R-09/R-16 etc. are
  distinguished by regional qualifiers and remain unique for slug/name dedupe. **Pass.**

## 2. Record-by-record audit table

Verdict: PASS = importable as specified (subject to Section 3 implementation requirements).
FIX = apply the cited finding first. Amenity legality/count, tag vocabulary, price-band, hours-format,
and coordinate checks were automated for all 130 rows; geography and category judgements were manual.

### Destinations (54)

| ID | Verdict | ID | Verdict | ID | Verdict |
|---|---|---|---|---|---|
| D-01 | PASS | D-19 | PASS | D-37 | PASS |
| D-02 | PASS | D-20 | PASS | D-38 | PASS |
| D-03 | FIX (F-02: add Shopping/Souvenir tags) | D-21 | PASS | D-39 | PASS |
| D-04 | PASS | D-22 | PASS | D-40 | PASS |
| D-05 | PASS | D-23 | PASS | D-41 | PASS |
| D-06 | PASS (optional role correct) | D-24 | PASS (optional role correct) | D-42 | PASS |
| D-07 | PASS | D-25 | PASS | D-43 | PASS (note, Sec.1) |
| D-08 | FIX (F-04: retype or add Surfing tag) | D-26 | FIX (F-03: remove Highest Peak) | D-44 | PASS |
| D-09 | PASS | D-27 | FIX (F-02) | D-45 | PASS |
| D-10 | PASS | D-28 | PASS | D-46 | FIX (F-01: rename) |
| D-11 | PASS | D-29 | PASS | D-47 | PASS |
| D-12 | PASS | D-30 | PASS | D-48 | PASS (full-day duration intentional) |
| D-13 | PASS | D-31 | PASS (renamed, neutral) | D-49 | FIX (F-02 + F-05) |
| D-14 | PASS | D-32 | PASS | D-50 | PASS |
| D-15 | PASS | D-33 | PASS | D-51 | PASS |
| D-16 | PASS | D-34 | PASS | D-52 | PASS |
| D-17 | PASS | D-35 | PASS | D-53 | PASS |
| D-18 | PASS | D-36 | PASS | D-54 | PASS |

Destination attribute notes (all verified): every record has type in the existing vocabulary, category tags
within the in-use vocabulary (except the deliberate F-02 addition), >=4 kind-legal amenities, parseable
hours format (including Closed Mon/Tue/Sun variants on D-04/D-37/D-41), parseable durations
(`90 minutes`/`2 hours`/`3 hours`/`4 hours`/`half day`/`full day`/`60 minutes`), fee min<=max consistent
with tier, and at least one Free and one Premium-or-Mid record per region for budget discrimination.
Wellness coverage does not rely on `family friendly` anywhere (checked). Hiking durations D-48 (full day)
will exercise the scheduler's 18:00 shortening logic — intended, not an error.

### Accommodations (27)

| ID | Verdict | ID | Verdict | ID | Verdict |
|---|---|---|---|---|---|
| A-01 | PASS | A-10 | PASS | A-19 | PASS |
| A-02 | PASS | A-11 | PASS | A-20 | PASS |
| A-03 | PASS | A-12 | PASS | A-21 | PASS |
| A-04 | PASS | A-13 | PASS | A-22 | PASS (note, Sec.1) |
| A-05 | PASS | A-14 | PASS | A-23 | PASS |
| A-06 | PASS | A-15 | PASS | A-24 | PASS |
| A-07 | PASS | A-16 | PASS | A-25 | PASS |
| A-08 | PASS | A-17 | PASS | A-26 | FIX (F-05) |
| A-09 | PASS | A-18 | PASS | A-27 | PASS |

Verified for all: type in the existing six-value vocabulary; resort settings only on Resort/Beach Resort
records; nightly rate inside at least one room-type band (automated, 27/27); room bands bracket the nightly
rate sensibly; check-in/out present and varied by kind; >=4 accommodation-legal amenities with no
homestay/elevator-class contradictions; `dot_classification` null throughout (correct — never invented).

### Restaurants (27)

| ID | Verdict | ID | Verdict | ID | Verdict |
|---|---|---|---|---|---|
| R-01 | PASS | R-10 | PASS | R-19 | PASS |
| R-02 | PASS | R-11 | PASS | R-20 | PASS |
| R-03 | PASS (split shift covers lunch) | R-12 | PASS (split) | R-21 | PASS (split) |
| R-04 | PASS | R-13 | PASS | R-22 | PASS (note) |
| R-05 | PASS | R-14 | PASS | R-23 | PASS |
| R-06 | PASS | R-15 | PASS | R-24 | PASS |
| R-07 | PASS | R-16 | PASS | R-25 | PASS |
| R-08 | PASS | R-17 | PASS | R-26 | FIX (F-05) |
| R-09 | PASS | R-18 | PASS | R-27 | PASS |

Verified for all: cuisine in the existing vocabulary; `price_tier` present; hours cover the 11:30-13:00
lunch-start window (split-shift R-03/R-12/R-21 verified to include it); >=4 restaurant-legal amenities;
specialties/dine-in/takeout correctly relegated to description (no unsupported columns claimed).

### Souvenir centres (22)

| ID | Verdict | ID | Verdict | ID | Verdict |
|---|---|---|---|---|---|
| V-01 | PASS | V-09 | PASS | V-17 | PASS |
| V-02 | PASS | V-10 | PASS | V-18 | PASS |
| V-03 | PASS | V-11 | PASS | V-19 | PASS (note) |
| V-04 | PASS | V-12 | PASS | V-20 | PASS |
| V-05 | PASS | V-13 | PASS | V-21 | PASS |
| V-06 | PASS | V-14 | PASS | V-22 | FIX (F-05) |
| V-07 | PASS | V-15 | PASS | | |
| V-08 | PASS | V-16 | PASS | | |

Verified for all: >=4 souvenir-legal amenities (only six keys exist for this kind); products in description;
hours/price correctly treated as description text because `souvenir_centers` has **no** `opening_hours` or
`price_tier` column — the proposal states this honestly and the importer must not invent columns.
Coordinates present on all 22 (the current catalogue has only 4/10 mapped — the batch fixes that pattern).

## 3. ExploreDVO compatibility (verified against code, not assumed)

| Check | Result |
|---|---|
| Required fields per table | All proposal fields exist in migrations/models. Souvenir hours/price correctly excluded (no columns). Restaurant specialties/dine-in correctly description-only. Accommodation capacity correctly expressed via `accommodation_room_types` (exists, currently 0 rows) |
| System-region values | All assignments use the 7 seeded `regions` names; Samal separate. `region_id` FK + free-text `location` is the only geography the schema supports — proposal conforms |
| Types/tags/amenities | Types within observed vocabularies; category tags within in-use list except F-02's explicit new values (free-string column, legal); amenity keys 100% kind-legal (automated vs `Amenities::FOR_KIND`); destination amenity labels must be exact catalogue labels at import (importer validator required) |
| Accreditation/provenance | One `SAMPLE-####` placeholder (`is_placeholder=true`, Active to 2030-12-31) per record yields `SampleData status = sample`; `is_accredited=true` is the existing eligibility flag. Compatible with `HasArchiving::scopePubliclyVisible`, ranking pool, Apriori resolution, swaps. G1-G5 visibility fixes remain a precondition for public exposure (separate scope) |
| Eligibility & generation | All destinations `sightseeing` except D-06/D-24 `optional` (correct: enter pools only when Wellness is picked). Coordinates on 130/130 enable sequencing, stay costing, meal (10 km) and souvenir (20 km) rules, and swap distance gates. Hours/durations parse under `OpeningHours`/`PreferenceFit::hours` formats as specified |
| Stay/meal/souvenir logic | Stays: mapped, per-node pairs with type/price spread — continuity and justified-transfer both exercisable. Meals: every node has a lunch-covering restaurant within cluster range plus split-shift variants. Souvenirs: per-node records inside the last-day envelope |
| Samal ferry | All Samal-region records (D-07..D-14, A-04..A-07, R-04..R-07, V-05..V-07) have on-island coordinates (lat 6.94-7.13, lng 125.66-125.74); no mainland record uses the Samal region and vice versa (verified by coordinate/region cross-check). Ferry +30 min applies exactly to strait crossings |
| Embeddings dependency | New destinations have no vectors until `embeddings:build`/import. Theme days auto-disable on trips containing them until then; swaps exclude them. This is a deployment sequencing requirement, not a record defect — flagged for the readiness checklist |
| NOT VERIFIED | (1) Exact municipal-boundary membership of any coordinate (no GIS data available); (2) production catalogue counts/schema state (production DB not accessed; workspace `.env` points to PostgreSQL which this audit did not connect to); (3) real road distances/travel times (all distances herein are Haversine test geometry); (4) that the production server has run `embeddings:import` for interest vectors (deploy script says it does; not independently confirmed) |

## 4. Geographical coverage assessment

Environments: coastal (Samal, Carmen, Santa Maria, Mati/Dahican-side, Baganga, Santa Cruz, Sulop),
inland (Santo Tomas, Monkayo, Nabunturan, Bansalan), highland/mountainous (Maragusan, Kapatagan, Kapalong,
New Corella, Marilog-side via D-05), agricultural (Panabo, Carmen, Bansalan, Santo Tomas farm records),
urban (Davao City, Tagum, Mati, Digos, Nabunturan), island (Samal incl. Talikud). Every system region has
two nodes and at least one record of each catalogue kind per node area. Provinces that had **zero**
destinations (Davao de Oro, Davao Occidental) and zero accommodations (Davao Occidental) in the audited
seeded catalogue gain complete nodes. Unsampled municipalities (Asuncion, Braulio E. Dujali, Talaingod,
San Isidro in Davao del Norte; Laak, Mawab, Montevista, Pantukan partially via Compostela/Monkayo nodes;
Hagonoy, Kiblawan, Magsaysay, Malalag, Padada in Davao del Sur; Don Marcelino sampled, Sarangani sampled;
Boston, Caraga, Manay, Tarragona, Banaybanay, Lupon unsampled in Davao Oriental) are honestly listed as
remaining gaps for verified DOT data — the synthetic batch does not claim regional completeness.

## 5. Testing origins audit (14)

| ID | Origin / environment | Verdict |
|---|---|---|
| O-01 | Baganga (remote coastal) | PASS — tests secondary-node self-sufficiency |
| O-02 | Santa Maria (coastal) | PASS — first Davao Occidental local trips (strengthened once F-05 is fixed at the *other* Santa Cruz) |
| O-03 | Jose Abad Santos (remote southern coastal) | PASS — sparse-node honesty, 20:00 rule |
| O-04 | Santo Tomas (inland agricultural) | PASS |
| O-05 | Panabo City (agricultural) | PASS — intra-province cross-municipality |
| O-06 | Maragusan (mountainous) | PASS — highland durations vs 18:00 cutoff |
| O-07 | Kapatagan, Digos (highland) | PASS |
| O-08 | Marilog, Davao City (upland district) | PASS with note — coordinates ~7.42,125.30 are approximately Marilog; exact district centre NOT VERIFIED |
| O-09 | Talikud Island (island) | PASS — zero-ferry intra-island validation |
| O-10 | Kaputian, Samal (island->urban) | PASS — ferry charged exactly on strait legs |
| O-11 | Monkayo (inland) | PASS |
| O-12 | Malalag (coastal, Davao del Sur) | PASS — origin only; correctly no venue named for its bay |
| O-13 | Governor Generoso (southern coastal) | PASS |
| O-14 | Carmen (coastal agricultural) | PASS |

Coverage: coastal x5, inland x3, mountainous x3, agricultural x3, urban (via nodes/T-scenarios; no
dedicated urban origin in O-list — Davao City/Tagum/Mati/Digos origins are exercised as principal-node
anchors and in the earlier T-scenarios), island x2, ferry-dependent x1 explicit (O-10) plus O-09 negative
control. **Missing scenario (recommended addition, not a catalogue defect):** one long interprovincial origin
(e.g. Mati City -> Davao City, 4-day far trip with per-night stays) to stress the 420-minute far budget and
stay-transfer acceptance end-to-end; and one Nabunturan urban origin for symmetry with other capitals.
Both can be added at test-design time without changing any record.

## 6. Final evaluation and approval recommendation

- **Total audited:** 130 records + 14 origins.
- **Passed as specified:** 121 records; 14/14 origins (1 with a coordinate note).
- **Requiring correction:** 9 records — D-03, D-27, D-49 (F-02 tags; D-49 also F-05), D-08 (F-04), D-26 (F-03),
  D-46 (F-01 rename), A-26, R-26, V-22 (F-05 relocation/reassignment).
- **Sufficiency:** with F-01..F-05 applied, the 130 records are sufficient for meaningful Region XI beta
  testing of recommendation, itinerary generation, stay/meal/souvenir selection, geographical filtering,
  opening-hours handling, and provenance labelling across all seven system regions and all eight interests.
  They do not, and must never be represented to, validate real tourism supply, real visitor behaviour,
  ratings differentiation, or real-world prices/hours/travel times (Section 8 of the proposal, endorsed).

**Recommendation: APPROVE WITH CORRECTIONS.** Authorise implementation only after the proposal file is
updated for F-01..F-05 (five small, record-level edits; no count, distribution, or taxonomy changes) and
re-reviewed, and only together with the previously scoped preconditions (importer tag-writing + listing-kind
importer, G3 visibility fix, embeddings rebuild, dry-run review before `--apply`).

*This audit did not modify the proposal, any code, or any database. Awaiting explicit approval.*
