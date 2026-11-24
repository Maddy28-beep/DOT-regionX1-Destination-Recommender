# Destination information enrichment — batch 2

Researched and applied locally on 19 November 2026. This continues the first enrichment batch; it does not replace it.

## Applied changes

11 existing destinations received 16 field updates and 26 additive amenity tags. The reviewed, replayable manifest is `database/data/destination-enrichment-batch2-2026-11-19.json`; every edit includes its source and expected previous value. Existing amenity tags were preserved.

| Destination | Updates | Source |
| --- | --- | --- |
| Eden Nature Park | Operator link; trails, playground, event space, camping, adventure facilities and overnight lodging | [Attractions](https://www.edennaturepark.com.ph/attractions/), [adventure](https://www.edennaturepark.com.ph/adventure/), [rooms](https://www.edennaturepark.com.ph/rooms/) |
| Philippine Eagle Center | Foundation visitor link; walking trails and guided tours | [Foundation visitor information](https://www.philippineeaglefoundation.org/pec) |
| Malagos Garden Resort | Operator link; pool, spa, overnight lodging, farm tours and playground | [Operator facilities](https://www.malagos.com/parks/), [operator 2026 brochure](https://images.squarespace-cdn.com/content/v1/5c64dd0a2727be078751d911/1770534218542-5A5RVLKED5ZC801IAQE3/BACK_2026.jpg) |
| Belviz Farm | Visitor description covering orchards, durian and advance confirmation | [Regional tourism directory](https://www.experiencedavao.com/destinations/davao-city/attractions/belviz-durian-farms) |
| Bemwa Farm Fresh Inc. | Description and **Restaurant (reported)** for its Marilog farm cafe | [Published visitor report, 2017](https://primer.com.ph/travel/2017/12/27/look-bemwa-farm-in-davao-city/) |
| Agriya Naturetainment | Developer link and attraction description; residential facilities explicitly kept separate | [Developer information](https://damosaland.com/agriya/) |
| Museo Dabawenyo | City contact link and museum tours | [City department services](https://davaocity.gov.ph/departments/economic-development/museo-dabawenyo/) |
| Cacao Culture — Poblacion | Branch address, daily schedule, operator link and chocolate/gift shop amenity | [Operator branch directory](https://www.cacaoculture.ph/pages/store-locations) |
| Paradise Island Park & Beach Resort | Verified operator and Facebook links; parking, dining, spa and overnight lodging | [Operator website](https://paradiseislandsamal.com/) |
| Davao Bamboo Sanctuary and Ecological Park | Operator link; spring pool, adventure facilities, day-use bamboo huts and massage services | [Operator day-tour information](https://davaobamboosanctuary.com/booking/daytour/) |
| Davao del Norte Sports and Tourism Complex | City tourism link and description; **Swimming Pool (reported)** and **Sports Courts (reported)** | [City tourism facility list](https://tagumcitytourism.wordpress.com/davao-del-norte-sports-and-tourism-complex/) |

Reported labels identify older or secondary facility evidence whose current availability needs confirmation. A published facility is not a guarantee that it is open or included in admission on every date.

## Values deliberately left unchanged

- Every entrance-fee minimum, maximum, price tier and visit-duration value across all 183 audited real-named destinations.
- All coordinates, accreditation, publication/eligibility fields, identities and photos.
- All listing and planner application code and existing amenities.
- Museo Dabawenyo remains an existing hidden reference listing; this pass does not publish it.

Unpublished fees and visit durations remain unconfirmed. A guided shuttle tour or massage length is not a total visit duration. Boat schedules and reservation-office hours are not resort opening hours.

### Evidence held back

- **Island Buenavista:** its operator amenities page redirects to a suspended page. Cached facility descriptions were not imported as current information.
- **Bamboo Sanctuary prices:** the operator publishes conditional day-tour packages including activities and snacks. These were not converted into a standalone entrance fee or inferred visit duration.
- **Davao Museum of History and Ethnography:** the catalogue already reflects newer October 2026 study-gallery information. The [2025 PIA relocation announcement](https://pia.gov.ph/news/davao-museum-gets-new-home-at-ateneo-de-davao/) does not override that newer information; facilities from the old Lanang site were not copied.
- **Bukid Amara:** information for the Lucban, Quezon property must not be assigned to the Davao listing.
- **Agriya:** residential pools, clubhouses and planned retail premises were not assigned to Naturetainment visitors.

## Verification

- Local-only importer preview and application: **11 destinations, 16 values, 26 amenities**.
- All 16 field updates and 26 amenity tags verified against the manifest.
- Protected fields, including fees, duration and coordinates, checked against the before snapshot for all 183 destinations: unchanged.
- Existing tags preserved. Fingerprints unchanged for photos, accreditation records, itineraries, itinerary items, itinerary matches, restaurants, accommodations, souvenir centers and packages.
- All 11 detail routes matched their existing public/hidden status; new amenity labels rendered on applicable public detail pages. This is server-rendered route verification, not a visual desktop/mobile review.
- Focused importer and detail/card regression tests: **24 passed, 707 assertions**.
- No commit, push or deployment performed.

## Remaining unconfirmed information

The current audit is `database/data/destination-enrichment-pending-2026-11-19.json`. Its 183 real-named active records include legacy records with a `[SAMPLE]` suffix, but exclude fictional names beginning with `Sample `.

| Gap | Destinations |
| --- | ---: |
| No sourced amenities recorded | 101 |
| No opening hours | 108 |
| No entrance-fee range | 116 |
| No price tier | 115 |
| No visit duration | 120 |
| Missing coordinates | 25 |
| No website URL | 162 |

Unknown means unconfirmed, not free, closed or unavailable. Some natural sites have no published built facilities; no minimum amenity count was fabricated.
