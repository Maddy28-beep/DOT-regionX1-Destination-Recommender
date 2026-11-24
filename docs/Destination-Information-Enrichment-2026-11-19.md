# Local destination information enrichment — 19 November 2026

Applied locally at the users request. Nothing committed, pushed or deployed. Listing views, filters, planner implementation, accreditation and itinerary eligibility are unchanged.

## Applied

- Audited all 183 active real-named destinations; 51 fictional Sample-prefix records were excluded from this work. Real-place records carrying an old SAMPLE suffix retain their identity and flags.
- Updated 196 field values across 81 destinations, including 74 coordinate pairs, 10 schedule texts, 16 descriptions, 4 location corrections/details, 14 official website/social links, one published admission price and its tier, and one source-reported hike duration. See the manifest for exact counts per field.
- Added 51 source-backed amenity tags to 26 destinations without removing existing tags. Secondary-source facilities carry a visible reported label.
- Missing coordinate pairs fell from 99 to 25. Coordinates are attraction references; a summit, reef or waterfall pin is not a verified vehicle entrance. Six overly broad/uncertain coordinate references were held.

## Places pictured by the user

| Destination | Applied information | Still unconfirmed |
| --- | --- | --- |
| Balite Hot Spring | Specific map point, spring information, reported soaking-area amenity | Current admission, hours, full facilities |
| Mount Karilongan | Community trail description, trail and guiding amenities | Precise visitor arrival point, current timetable and fees |
| Bluebird View Deck | Viewpoint coordinate, description, reported viewing-deck amenity | Current fee and hours |
| Matol Falls | Waterfall coordinate, reported trekking-trail amenity | Current guide arrangements, fees, hours, other facilities |
| Alina Cave | Alena/Alina entrance reference and guided-access amenity | Vehicle access, regular booking tariff and visit schedule |
| Fabians Peak | Maragusan location correction, summit reference and description | Exact visitor start, fees and current facilities |
| D’Fortees | Venue coordinate, description; reported pool, lodging, cottages, playground, viewpoint and garden paths | Current day-tour price and timetable |
| Jorge Town | Venue coordinate, description and published adventure facilities | Current admission and activity availability |

## Limits and remaining work

This is a sourced enrichment pass, not a claim that every field can be verified from public sources. Remaining empty fields are retained as unconfirmed; an absent amenity record does not mean a facility is unavailable. Fees, guide charges, transport and overnight room prices are different products and were not combined. Only Dayang Beach's published PHP100 day-tour charge was imported, with transport explicitly separate. No estimated prices or guessed visit durations were added. The displayed source-qualified schedules include map-listed hours and dated city/guide reports; these were not marked as independently operator-verified.

| Unconfirmed information | Destinations |
| --- | ---: |
| Coordinates | 25 |
| Hours | 108 |
| Admission price | 116 |
| Price tier | 115 |
| Visit duration | 120 |
| Best time | 182 |
| Any sourced amenities | 107 |

The machine-readable pending list covers every real-named listing and its remaining gaps: [destination-enrichment-pending-2026-11-19.json](../database/data/destination-enrichment-pending-2026-11-19.json). It is a collection queue, not an import.

The historical Bamboo Sanctuary domain had previously redirected to unrelated content; cached booking snippets were excluded despite exposing apparently usable prices. Museum location evidence was updated to the Arrupe Hall study gallery; the old Lanang pin and campus-centre substitutes were not imported. Fabian’s Peak was corrected to Maragusan based on matching map/visitor evidence. Current access must still be confirmed.

## Reproduce and verify

```powershell
php artisan destinations:enrich-local database/data/destination-enrichment-2026-11-19.json
php artisan destinations:enrich-local database/data/destination-enrichment-2026-11-19.json --apply
```

The command is local/testing only, defaults to preview, checks slug plus exact name, requires sources and expected old values, validates coordinate/fee pairs and allowed amenity keys, and uses an atomic transaction. It never accepts eligibility, accreditation, identity, ratings, photos or planner verification fields. An applied recovery journal is saved under storage/app before database commit. A repeated preview reports zero changes.

Validation: 24 focused tests passed with 707 assertions (new importer safeguards plus existing detail import, distance and entry-fee tests). All 196 applied field values and 51 amenities were checked against the manifest. Existing destination tags were preserved, as were protected fields on all 183 real-named listings. Fingerprints of photos, accreditation, itinerary, restaurant, stay, souvenir and package tables remained unchanged. Backend route checks passed for all 81 touched listings, including pre-existing non-public rows retaining their expected 404 responses. Browser visual checks could not be completed: the existing 8895 server did not expose these newly imported destinations, and the separate current-checkout preview could not be reached by the browser. No desktop/mobile visual pass is claimed.

## Applied field inventory

| Field | Values updated |
| --- | ---: |
| latitude | 74 |
| longitude | 74 |
| description | 16 |
| website_url | 12 |
| facebook_url | 2 |
| location | 4 |
| entry_fee_min | 1 |
| entry_fee_max | 1 |
| price_tier | 1 |
| hours | 10 |
| visit_duration | 1 |

## Amenity evidence

### Aguacan Cold Spring

- pool (reported): [source](https://wayph.com/aguacan-coldspring-resort-maragusan/).

### Alina Cave

- guided_tours: [source](https://kapalong.gov.ph/tourism/attractions/).

### Balite Hot Spring

- hot_springs (reported): [source](https://mywanderstory.com/2017/08/05/balite-hot-spring-davao-oriental/).

### Bamboo Peak

- walking_trails (reported): [source](https://logsac.blogspot.com/2021/06/hiking-barangay-jose-rizals-bamboo-peak.html).

### Bilawa Mainit Hot Waterfall

- hot_springs: [source](https://davaodeoro.gov.ph/bilawa-mainit-hot-waterfalls/).

### Bluebird View Deck

- viewing_decks (reported): [source](https://www.syramay.com/blue-bird-view-deck-samal-island/).

### Camp Damada

- restaurant: [source](https://www.nabunturan.gov.ph/tourist-destination).
- campsites: [source](https://www.nabunturan.gov.ph/tourist-destination).

### D'Fortees Nature Park and Inland Resort

- pool (reported): [source](https://thegeestravel.com/d-fortees-nature-park/).
- overnight_lodging (reported): [source](https://thegeestravel.com/d-fortees-nature-park/).
- day_use_cottages (reported): [source](https://thegeestravel.com/d-fortees-nature-park/).
- playgrounds (reported): [source](https://thegeestravel.com/d-fortees-nature-park/).
- viewing_decks (reported): [source](https://thegeestravel.com/d-fortees-nature-park/).
- walking_trails (reported): [source](https://thegeestravel.com/d-fortees-nature-park/).

### Davao City Library and Information Center

- wifi: [source](https://davaocity.gov.ph/education/davao-city-library-opens-with-all-inclusive-features/).
- ac: [source](https://davaocity.gov.ph/education/davao-city-library-opens-with-all-inclusive-features/).
- event_spaces: [source](https://davaocity.gov.ph/education/davao-city-library-opens-with-all-inclusive-features/).

### Dayang Beach

- overnight_lodging: [source](https://www.dayangbeach.com/rates-and-fees).

### Doña Vicenta Park

- walking_trails: [source](https://davaocity.gov.ph/environment/dona-vicenta-park-now-open/).
- visitor_bridges: [source](https://davaocity.gov.ph/environment/dona-vicenta-park-now-open/).
- event_spaces: [source](https://davaocity.gov.ph/environment/dona-vicenta-park-now-open/).

### Jorge Town

- adventure_facilities: [source](https://davaodeoro.gov.ph/jorge-town/).

### Kagan Dinagsaan Peak

- walking_trails: [source](https://www.experiencedavao.com/activities/kagan-dinagsaan-peak).

### Los Amigos Park

- parking: [source](https://davaocity.gov.ph/environment/los-amigos-park-opens-to-public/).
- playgrounds: [source](https://davaocity.gov.ph/environment/los-amigos-park-opens-to-public/).
- walking_trails: [source](https://davaocity.gov.ph/environment/los-amigos-park-opens-to-public/).
- day_use_cottages: [source](https://davaocity.gov.ph/environment/los-amigos-park-opens-to-public/).

### Marawer Hot Spring

- hot_springs (reported): [source](https://manakaayugbukid.com/marawer-hot-springs-kapatagan-guide/).

### Matol Falls

- walking_trails (reported): [source](https://www.mindanaotimes.com.ph/dot-xi-officially-kicks-off-davao-adventure-challenge-season-2/).

### Mount Karilongan

- walking_trails: [source](https://www.ivolunteer.com.ph/time-volunteer/7783).
- guided_tours: [source](https://www.ivolunteer.com.ph/time-volunteer/7783).

### Mount Lamabal

- walking_trails: [source](https://www.experiencedavao.com/destinations/davao-del-sur/matanao/attractions/mt-lamabal).

### Mount Loay

- walking_trails: [source](https://mirror.pia.gov.ph/news/2022/05/31/sta-cruz-tourists-mostly-seek-outdoor-adventure-sites).

### Mount Puting Bato

- walking_trails: [source](https://rcmb11.ncmb.gov.ph/health-and-wellness-through-hiking-and-self-love/).

### New Barili Heights and Sights

- restaurant: [source](https://davaodeoro.gov.ph/new-barili-heights-and-sights/).
- overnight_lodging: [source](https://davaodeoro.gov.ph/new-barili-heights-and-sights/).

### Panabo Mangrove Park

- walking_trails: [source](https://panabocity.gov.ph/panabo-mangrove-park-ecotourism-project-inaugurated/).
- day_use_cottages: [source](https://panabocity.gov.ph/panabo-mangrove-park-ecotourism-project-inaugurated/).

### Rancho Palos Verdes Golf & Sports Club Inc.

- pool (reported): [source](https://stalucialand.com.ph/projects/rancho-palos-verdes-2/).
- sports_courts (reported): [source](https://stalucialand.com.ph/projects/rancho-palos-verdes-2/).
- event_spaces (reported): [source](https://stalucialand.com.ph/projects/rancho-palos-verdes-2/).
- restaurant (reported): [source](https://stalucialand.com.ph/projects/rancho-palos-verdes-2/).
- gyms (reported): [source](https://stalucialand.com.ph/projects/rancho-palos-verdes-2/).

### Roxas Greenway Park

- walking_trails: [source](https://davaocity.gov.ph/local-government/davao-citys-roxas-greenway-park-opens/).
- visitor_bridges: [source](https://davaocity.gov.ph/local-government/davao-citys-roxas-greenway-park-opens/).

### Sandunganan Peak

- walking_trails: [source](https://pia.gov.ph/news/retracing-watchers-trail-gazing-at-same-skyline-atop-sandunganan-peak/).
- guided_tours: [source](https://pia.gov.ph/news/retracing-watchers-trail-gazing-at-same-skyline-atop-sandunganan-peak/).

### Toril Public Park

- parking: [source](https://davaocity.gov.ph/infrastructure-development/renovated-toril-public-park-offers-amenities-for-visitors/).
- restroom: [source](https://davaocity.gov.ph/infrastructure-development/renovated-toril-public-park-offers-amenities-for-visitors/).
- playgrounds: [source](https://davaocity.gov.ph/infrastructure-development/renovated-toril-public-park-offers-amenities-for-visitors/).
- walking_trails: [source](https://davaocity.gov.ph/infrastructure-development/renovated-toril-public-park-offers-amenities-for-visitors/).


