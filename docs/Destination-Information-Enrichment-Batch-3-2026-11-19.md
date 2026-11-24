# Destination information enrichment — batch 3

Researched and applied locally on 19 November 2026. This pass adds 13 sourced amenity tags and 15 detail-field updates across 10 existing destinations. It does not replace the earlier two manifests.

The reviewed import file is `database/data/destination-enrichment-batch3-2026-11-19.json`. Each field edit records its exact previous value, proposed value, source and reason. Each amenity records its source, evidence and confirmation status. Existing tags were preserved.

## Applied information

| Existing destination | Added or clarified information | Evidence |
| --- | --- | --- |
| Rancho Palos Verdes — Mandug-Indangan | Visitor description and city visitor link; restaurant; reported driving range and putting green | [City restaurant directory](https://tourism.davaocity.gov.ph/explore-the-city/restaurants/restaurants/rancho-palos-verdes-golf-sports-club-inc/), [specialist golf directory](https://www.1golf.eu/en/club/rancho-palos-verdes-golf-country-club/) |
| Roxas Night Market | Published street/district, daily schedule and city information link; food stalls | [City schedule](https://tourism.davaocity.gov.ph/explore-the-city/restaurants/night-market/roxas-night-market/), [city street-food entry](https://tourism.davaocity.gov.ph/explore-the-city/restaurants/night-market/roxas-night-market/ukay2x-and-street-foods/) |
| Japanese Tunnel | City visitor link, published Hillcrest/Balusong Extension address and associated restaurant | [City resort directory](https://tourism.davaocity.gov.ph/explore-the-city/nightlife/resorts/d-japanese-tunnel-family-resort-and-restaurant/) |
| Kapalong Cave System — Okbot and Sua | Municipal visitor information and guided cave access | [Municipal guide](https://kapalong.gov.ph/tourism/attractions/) |
| Energy Park | Reported playground with installed play equipment | [April 2025 local inspection report](https://davao.bomboradyo.com/childrens-and-water-park-sa-tagum-city-abli-na-karong-mayo-1/) |
| Digos City Ecopark and Arboretum | San Nicolas/Tres de Mayo address; description; reported trails, picnic area and grilling area | [Regional address](https://www.experiencedavao.com/attractions/digos-city-ecopark-and-arboretum), [2025 field study, PDF pages 4 and 7](https://www.atlantis-press.com/article/126015180.pdf) |
| Salinta Monon Weaving Center | Visitor description and handmade textiles for sale | [Tourism Promotions Board visitor information](https://phworldexpo.tpb.gov.ph/category/davao-scrim/01-davao-scrim/) |
| City of Tagum Cultural Center | Named hall and event facilities confirmed by a July 2026 program | [CCP account published by PIA](https://pia.gov.ph/news/ccp-cultural-exchange-brings-the-2026-kaisa-sa-sining-apprenticeship-program-to-tagum-city/) |
| Mabunao Eco-Park | City visitor link, description and reported stepped walking path | [Panabo city visitor information](https://panabocity.gov.ph/mabunao-eco-park/) |
| Tagtugonan Falls | Municipal visitor link and guided canyoneering access | [Municipal guide](https://kapalong.gov.ph/tourism/attractions/), [March 2026 municipal activity report](https://kapalong.gov.ph/post/kapalong-records-149-adventure-participants-in-february-caving-and-canyoneering-activities) |

Reported labels remain visible for older or secondary facility evidence. Current access, maintenance conditions and bookings should be confirmed with the operator or local tourism office. The weaving center's label is **Handmade textiles for sale**; no separate shop building is asserted. The ecopark's **Grilling areas (reported)** label does not assert that fire pits exist. The Mabunao path is identified as stepped, with no accessibility claim.

Municipal references to accredited guides are evidence of guided access; they do not change this application's DOT listing accreditation fields.

## Information deliberately held back

- All fees, price tiers and visit durations remained unchanged. The Digos field study's historical no-fee statement was not converted into a current operator rate.
- The Ma-a Rancho Palos Verdes pool entry was not copied to the Mandug-Indangan venue.
- Energy Park construction/procurement plans were not treated as completed facilities. No pool was inferred from the name of the children's water park.
- A Tagum rental-schedule PDF was indexed but returned 502 when opened. No gazebo, city-hall atrium or rental prices were imported from snippets alone.
- Recent Pusan Point restoration reporting warrants fresh operator confirmation before adding old pool/campsite claims.
- Search results for Del Carmen, Siargao were rejected as evidence for Carmen, Davao del Norte.
- Japanese Tunnel and Roxas Night Market retain their existing hidden reference status. Legacy names, slugs and publication rules are unchanged.

## Validation

- Import preview and local application agreed: **10 destinations, 15 field values, 13 amenities**.
- All imported field values and amenities verified against the manifest.
- Every non-manifest destination field compared against the before snapshot across **183 real-named active destinations**: **zero unexpected changes**. This includes all fees, price tiers, durations, coordinates, identities, accreditation, planner verification and publication fields.
- All pre-existing tags preserved.
- Fingerprints unchanged for listing photos, accreditation records, itineraries, itinerary items, itinerary matches, restaurants, accommodations, souvenir centers and packages.
- **10 of 10** affected detail routes matched their existing public/hidden status; new amenity labels rendered on applicable public detail pages. This was a server-rendered route check, not a visual browser review.
- Focused importer and detail/card tests: **24 tests, 707 assertions passed**.
- No listing or planner application code changed. No commit, push or deployment performed.

## Remaining gaps

The updated pending matrix is `database/data/destination-enrichment-pending-2026-11-19.json`. It includes 183 real-named active destinations, including legacy names with a `[SAMPLE]` suffix; fictional names beginning with `Sample ` are excluded.

| Unconfirmed information | Destinations |
| --- | ---: |
| No sourced amenities recorded | 91 |
| Opening hours | 108 |
| Entrance-fee range | 116 |
| Price tier | 115 |
| Visit duration | 120 |
| Coordinates | 25 |
| Website URL | 156 |

Missing means unconfirmed, not free, closed, inaccessible or absent. Natural sites do not necessarily have built amenities. Remaining gaps require further destination-specific evidence; nothing was fabricated to meet a completeness threshold.
