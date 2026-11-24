# Additional real restaurants — batch 2

24 November 2026. Added **12 restaurants locally, one real photograph each**, without replacing existing listings or photos. All 12 have sourced coordinates, address, cuisine and description; 11 have a published contact number and five have a usable opening schedule. Unpublished prices, ratings and conflicting schedules remain null. DOT accreditation is **not confirmed**: the existing information icon appears, with no accreditation badge.

## Added venues and location evidence

| Restaurant | Latitude, longitude | Location evidence |
| --- | --- | --- |
| Hey Monday! Deli — Poblacion | 7.074588, 125.6111161 | [Tatler](https://www.tatlerasia.com/dining/hey-monday-deli); the Poblacion branch, not the former Buhangin location |
| Tola — Kan-anan sa Balay Oboza | 7.066299, 125.6087195 | [Tatler](https://www.tatlerasia.com/dining/tola) |
| Huckleberry Southern Kitchen & Bar | 7.0662715, 125.6087333 | [City tourism's published map link](https://goo.gl/maps/hiedZuzmUid8Vxig9); distinct venue in the Oboza compound |
| Tiny Kitchen — F. Torres | 7.08108, 125.60969 | [Named map feature](https://ph.geoview.info/tiny_kitchen%2C1234697066n) |
| Made Simple — Matina | 7.0544484, 125.5890147 | [Tatler](https://www.tatlerasia.com/dining/made-simple) |
| The Vegan Dinosaur — Marfori | 7.0816517, 125.6017341 | [Operator's embedded map](https://www.thevegandinosaur.com/) |
| Luk Foo Palace — Acacia Hotel Davao | 7.0996375, 125.628518 | [Named Waze place](https://www.waze.com/live-map/directions/ph/davao-region/davao-city/luk-foo-palace-at-acacia-hotel-davao?to=place.ChIJHUfsbPht-TIRoZVfXJYwurU) |
| Madayaw Cafe — dusitD2 Davao | 7.109531, 125.650671 | [Official hotel location](https://www.dusit.com/dusitd2-davao/reservation/?rid=3877); building pin, not a surveyed restaurant entrance |
| Pilgrim Restaurant — Baganihan | 7.4689143, 125.2462309 | [Tatler](https://www.tatlerasia.com/dining/pilgrim); Marilog, within Davao City |
| Yellow Fin Seafood Restaurant — Ecoland | 7.055605349, 125.599372539 | [Branch-specific Waze place](https://www.waze.com/en-GB/live-map/directions/yellow-fin-seafood-restaurant-ecoland-quimpo-blvd-davao-city?to=place.w.82313287.823132866.5256068) |
| Davao Dencia's Restaurant | 7.069928565, 125.606980755 | [Named Waze place](https://www.waze.com/live-map/directions/dencias-restaurant-ilustre-st-davao-city?to=place.w.82313287.823198403.739628) |
| Lachi's — Poblacion Market Central | 7.07446, 125.61095 | [Named map feature](https://mapcarta.com/N12168983845); not the older Ruby Street branch |

These are published map positions, not independently surveyed GPS coordinates. Full source URLs, contacts, schedules, branch notes, photo credits and hashes are in `database/data/listing-photos/real-restaurants-batch2-2026-11-24/manifest.json`.

## Photos and uncertain details

All 12 photographs were visually reviewed against their venue or branch source. Sources include operators, Davao City tourism, Tatler, MyDavaoCity and Philippine Primer. Credits and watermarks are retained. Some are historical: Dencia's interior was published in 2014, Madayaw's image in 2020 and the city tourism images in 2022. They are not represented as photographs taken in 2026. Photo permission records cover the requested local testing; public reuse permission is not asserted.

Usable schedules were found for Hey Monday, Made Simple, Luk Foo, Yellow Fin Ecoland and Lachi's Poblacion. Conflicting hours were left blank; Madayaw's published breakfast closing-time typo was not imported. The Vegan Dinosaur's contact number remains unconfirmed. Prices and price tiers are not guessed.

## Import and verification

- Local/testing-only command: `php artisan restaurants:import-more-real-local`; append `--apply` to import. Production is refused.
- Result: **12 restaurants and 12 photos added**. Repeat preflight: **0 restaurants and 0 photos ready**. Name/alias checks include existing restaurants and stays; ambiguous matches abort the batch.
- **8,390 existing rows unchanged**, verified by before/after fingerprints across restaurant, accommodation, destination, photo, tag, accreditation, itinerary and itinerary-item tables.
- All 12 detail routes returned HTTP 200 with their expected photo. Every imported venue has exactly one real photo, source-matching fields and unverified accreditation status. Stored photo hashes match the reviewed manifest.
- **36 tests passed, 380 assertions** across the new importer, dining cards, meal windows and opening-day handling. No listing, filtering or planner implementation was changed. Sourced coordinates allow the existing planner to consider these restaurants through its usual rules.
- Browser checks at **1366 px desktop and 390 px phone**: name search and mobile filter submission returned the expected venue; real photos loaded; information icons appeared without accreditation badges; detail contact, hours and directions rendered. No horizontal page overflow was observed. Temporary viewport overrides were reset.
- Browser evidence: `storage/app/restaurant-research-batch2/ui/desktop.jpg` and `mobile.jpg`. Data verification: `storage/app/real-restaurants-batch2-verification-2026-11-24.json`.

No commit, push or deployment was performed.
