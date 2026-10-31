# DOT Region XI working dataset

The prepared working dataset is `outputs/01a0dc53/davao-destination-working-dataset.xlsx`, relative to this directory. It was prepared on 26 September 2026 from the user's latest selected file, `C:/Users/sayman/Downloads/davao_coordinates_fixed (1).xlsx`. The earlier `outputs/01a0dc53/davao_coordinates_fixed.xlsx` remains a baseline copy.

The prepared workbook preserves the original three sheets and adds `DESTINATION PREP`, `COVERAGE`, and `EVALUATION CASES`. Original cell values, formulas, merged ranges, and frozen panes were compared with the source after export. No runtime data or model was changed.

This supersedes the earlier proposed workbook. `pending-coordinate-updates.json` contains earlier research only and has not been applied to this selected file.

## Inspected coverage

397 establishment records have latitude and longitude. Source labels: Verified 141, Approximate 163, Corrected 80, User supplied 12, Matched building 1. These inherited labels are not independent verification. Preserve all precision and review notes.

The ESTABLISHMENTS header is row 4. M:N are latitude and longitude; O:R hold location check, precision, review notes, and sources. Ignore blank separators. Preserve branch names and addresses when matching repeated accreditation numbers.

## Current recommendation architecture

- ContentBasedRecommendationService: deterministic weighted preference matching and geographic filtering.
- AprioriService: pairwise association rules from exit-survey itemsets. Establishment rows are not visit transactions. RecommendationDataSeeder generates example visits and surveys; distinguish these from observed tourist evidence.
- ItinerarySkeletonMlService: Phi-4-mini through Ollama, inference only. Assigns already-selected candidates to day/slot positions; no training or fine-tuning. Current context includes IDs, names, types, sequence positions, distances, and limited trip preferences.

## Prepared destination subset

17 destination-related records have stable place IDs, explicit branch identities, roles, activity tags, descriptions, and source links: 13 core attractions, 3 optional spa stops, and 1 event venue. This is a selected destination subset, not all 397 businesses. Geographic coverage is 13 records in Davao City, 3 in Davao del Norte, and 1 in Davao del Sur. Missing provinces in this subset do not imply that those provinces have no attractions.

10 named map candidates are recorded beside inherited coordinates, with calculated distances and review notes; none replaces the source coordinates. Map matches are not verified entrances. Bukid Amara has a roughly 16 km discrepancy requiring review. A published 2024 IMIN adult fee and two published schedules are explicitly marked as historical or requiring verification. Unsupported fees, visit durations, amenities, and accessibility remain blank or unknown. All 17 records still lack verified scheduling inputs.

16 synthetic evaluation scenarios include expected behavior, measurement criteria, and blank baseline/model result fields. All are `Not run`: they are a test plan, not observed tourist data, completed evaluations, or training examples. Workbook formulas and coordinate-distance calculations were checked; model quality has not been measured.

## Remaining preparation and integration

1. Review proposed place IDs, branch mapping, listing roles, and attraction eligibility before import. An accredited business is not automatically an attraction.
2. Verify coordinate candidates and current operations; extend destination coverage and collect sourced amenities and accessibility attributes.
3. Verify fee types, operating schedules and closed days, expected visit durations, and travel-time estimates. Unknown is not zero; nightly room rates are not entrance fees.
4. Pseudonymous trip/survey itemsets with dates and provenance. Keep real and synthetic records separate.
5. Independently reviewed evaluation cases: preference fit, budget, opening hours, route feasibility, duplicated/missing IDs, and model fallback. Compare model-assisted scheduling with the existing non-ML ordering.

Adding spreadsheet fields alone does not connect them to the application. Import mapping, prompt context, and deterministic validation must be updated when fields are adopted. The SQLite database, runtime JSON, seeders, and app behavior were not changed by this dataset selection.
