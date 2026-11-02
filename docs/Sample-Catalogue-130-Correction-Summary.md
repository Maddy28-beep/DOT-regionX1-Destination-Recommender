# Correction Summary — Sample Catalogue 130 (F-01 to F-05 Applied)

> Applied to `docs/Sample-Catalogue-130-Review.md` only, following
> `docs/Sample-Catalogue-130-Final-Audit.md`. **No application code, database record, importer,
> migration, embedding, or deployment was touched.** Totals preserved: 54 destinations,
> 27 accommodations, 27 restaurants, 22 souvenir centres = **130 records**; seven system-region
> labels unchanged; 14 testing origins unchanged; eight Interests/Activities unchanged.

## Corrections applied

| Finding | Records | Change |
|---|---|---|
| F-01 Duplicate name | D-46 | Renamed "Sample Southern Coastal Viewpoint" -> **"Sample Southern Headland Viewpoint"** (Governor Generoso). D-38 (Sarangani) keeps its name. Coordinates unchanged. |
| F-02 Shopping coverage | D-03, D-27, D-49 | Added category tags **Shopping, Souvenir**. These are new tag *values* in the free-string `destination_tags.value` column — schema-legal, no migration, no taxonomy change. Without them, `InterestCoverage::covers()` could never match the Shopping interest (no type maps to it and no shopping keyword existed in tags). |
| F-03 Wrong tag | D-26 | Removed `Highest Peak` (retained `Hiking`). The tag is reserved for Apo-class records. |
| F-04 Type/tag mismatch (per instruction) | D-08 | Type changed `Beach & Surfing` -> **`Beach & Leisure`** (described activity is snorkelling; tags `Beach, Turtle Sanctuary` unchanged). |
| F-05 Santa Cruz node placement | D-49, A-26, R-26, V-22 | Coordinates relocated into the Santa Cruz coastal node (see below). Municipality stays **Santa Cruz**; system region stays Davao del Sur. |

## F-05 corrected coordinates and geographical basis

| Record | Old coordinates | Corrected coordinates |
|---|---|---|
| D-49 Sample Weaving & Culture House | 6.9350, 125.3500 | **6.8520, 125.4080** |
| A-26 Sample Coastal Homestay South | 6.9300, 125.3480 | **6.8480, 125.4120** |
| R-26 Sample Seaside Eatery South | 6.9320, 125.3490 | **6.8500, 125.4180** |
| V-22 Sample Coastal Craft Shop South | 6.9310, 125.3495 | **6.8530, 125.4140** |

**Basis:** Santa Cruz, Davao del Sur's poblacion/coastal centre is approximately 6.84, 125.42, and the
node's existing anchor record D-51 (Sample Coastal Mangrove Boardwalk) sits at 6.8450, 125.4150.
The corrected points lie within ~1 km of D-51 along the same coastal strip, so the five Santa Cruz-node
records (D-49, D-51, A-26, R-26, V-22) now form one coherent cluster: intra-node legs under ~2 km,
meal (10 km) and stay rules exercisable inside the node, and a clean ~20 km node separation from
Digos City. The old points (~13 km northwest, toward the Digos upland side) split the node.

**Verification limit (explicit):** exact municipal-boundary membership is **NOT VERIFIED**. No cadastral
or GIS boundary data was available. Placement is justified by proximity to the Santa Cruz town/coastal
centre and node coherence — the same town-centre method used for every record in the catalogue — not by
a boundary survey. If DOT supplies boundary data, these four points should be re-checked against it.

## Re-validation results (re-run after corrections)

Automated audit re-executed against the corrected proposal (amenity catalogue, name/coordinate
duplication, price bands, tag vocabulary, Haversine municipality-centre distances):

- Parsed **130/130** records (54/27/27/22). Category and region totals unchanged.
- **Duplicate venue names: none** (was 1: D-38/D-46). Exact duplicate coordinates: none.
- **Amenities: 130/130** have >= 4 amenities, 100% kind-legal against `Amenities::FOR_KIND`.
- **Price bands: 27/27** accommodation nightly rates inside their room-type bands.
- **Geography:** the four corrected Santa Cruz records no longer flag (now <1 km from the node anchor).
  Remaining distance flags are the audit's previously-passed intentional cases only: Kapatagan upland
  records ~22 km from Digos City centre (correct — Kapatagan is a Digos City barangay), Talikud Island
  records ~14-15 km from Babak centre (island geography), D-43 inland Cateel ~18 km (falls-park
  placement; boundary NOT VERIFIED, as before), Baganga-north coast ~13 km, Toril ~14 km.
- **Tag-vocabulary warnings for `Shopping`/`Souvenir` on D-03/D-27/D-49 are expected and intentional:**
  they are the F-02 correction itself (new free-string values), flagged by the checker precisely
  because they did not exist before. No other out-of-vocabulary tags.
- Samal cross-check unchanged: all Samal-region records on-island; ferry applies only to strait crossings.
- Interest coverage now holds as claimed, including Shopping & Souvenirs as a coverable sightseeing
  interest (D-03, D-27, D-49) in addition to souvenir-centre appendages.

**Conclusion: all 130 records pass the catalogue validation.** The proposal is ready for final review.

*Awaiting explicit implementation approval. Nothing has been implemented, imported, or deployed.*
