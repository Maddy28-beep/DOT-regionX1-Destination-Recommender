# Planner Data Audit: 15 November 2026

Live database inspected through the deployed DataCompleteness service. Counts below concern all non-archived records, including records not currently public. A missing amenity check means fewer than the service's required number of recognized amenities; it does not prove that the place lacks those facilities.

| Type | Records | Missing coordinates | Missing hours | Missing amenities | Expired accreditation records |
|---|---:|---:|---:|---:|---:|
| Destinations | 151 | 0 | 7 | 84 | 5 |
| Accommodations | 255 | 0 | N/A | 228 | 214 |
| Dining | 80 | 0 | 3 | 53 | 8 |
| Souvenirs | 30 | 0 | N/A | 8 | 1 |
| Tour operators | 106 | 2 | N/A | N/A | 0 |
| Packages | 5 | 5 | N/A | N/A | 0 |

All 151 destinations have embeddings and descriptions. All other listing types also have descriptions. Presence checks do not establish coordinate precision, current operating hours, or factual accuracy. Packages are trips, so missing package coordinates require interpretation rather than automatic geocoding.

## Priority Work

1. Confirm accreditation with DOT/owners, especially the 214 stays flagged by expiration date. Do not renew dates or change accreditation flags without supporting records.
2. Collect the ten missing hours from operators. Destination gaps: Bankerohan Public Market, Kaputian Beach, Lon Wa Buddhist Temple, Osmena Park, Paradise Island Park & Beach Resort, San Pedro Cathedral, Davao Chinatown. Dining gaps: Resto-Beach at Paradise Island, Rhapsody Asian Fusion at Aria Hotel, Vista Restaurant at Eden.
3. Add sourced amenities. Do not infer accessibility from a venue's type or photos. Retain existing amenities when adding evidence-backed entries.
4. Audit duplicate venue representations before consolidation: accommodation IDs 3 and 58 both represent Paradise Island. No consolidation was attempted because references and provenance need separate review.
5. Review scheduling duration consumption. The current local scheduler uses fixed 150/90-minute allowances, while live destinations carry values from 30 minutes to multi-day climbs. Filling this field alone does not change timing. A parser and scheduler integration need focused implementation and feasibility tests; no algorithm changes were made in this pass.
6. Evaluate geographically usable Apriori companion coverage before creating more demo surveys. Current verified total: 1,000 transactions, 997 demo and 3 real, with 753 qualifying rules at the admin defaults. Preserve the current 1,000 target.

## First Verified Enrichment Batch

`deploy/enrich-verified-amenities-2026-11-15.php` previews by default and applies only with `--apply`, from the application root. It checks exact IDs/names and adds missing amenity rows in a database transaction without replacing existing values. It records each addition and source in the application log.

Accommodation IDs 3 and 58: parking and restaurant from the resort's official homepage/dining page; room Wi-Fi and air conditioning from its official room listings. These are accommodation amenities, not a claim that day visitors receive room facilities. Sources checked on 15 November 2026:

- https://paradiseislandsamal.com/
- https://paradiseislandsamal.com/dining/
- https://paradiseislandsamal.com/rooms/

The published ferry service window was not used as opening hours. No prices, accessibility claims, survey rows, accreditation status, or model vectors are changed by this batch. Accommodation amenity rows are not destination embedding inputs.

Applied and verified on the live server: eight amenity rows added after a successful database backup. A second preview reports zero pending changes. Stays failing the amenity completeness check decreased from 228 to 226; the transaction total remains 1,000. This improves recorded facilities; no measured itinerary-quality gain is claimed.
