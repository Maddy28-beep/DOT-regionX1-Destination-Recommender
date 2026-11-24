# Davao City restaurants — research batch 3

24 November 2026. Research only: **five candidates with a real photo and named map coordinates**, one additional restaurant on hold due to conflicting operational information, and two deferred candidates. No restaurant, listing-photo, accreditation or itinerary database rows were added or changed. No listing/planner code changed.

Names, normalized aliases and proposed slugs were compared against all **74 current restaurant rows**, including archived/ineligible entries. No exact normalized name/alias or slug matches were found for these six candidates. This is a catalogue identity check, not an assertion that similarly named businesses cannot exist elsewhere.

All candidates retain **DOT accreditation not confirmed**. Tourism-directory inclusion and hotel affiliation are not accreditation evidence. A later import should use the existing information icon, with no DOT badge or invented accreditation record.

| Candidate | Branch/address | Coordinates | Published contact | Cuisine |
| --- | --- | --- | --- | --- |
| Bairrada Churrasqueira | The Compound, Tulip Drive, Juna, Matina | 7.0561327, 125.5887746 | +63 954 175 0252 | Portuguese |
| Taichou Izakaya Japanese Pub | Lacuesta Building, Suazo corner D. Ponce, Barangay 28-C | 7.0710821, 125.6187134 | +63 997 076 9623 | Japanese |
| Lara Mia Cafe & Bistro | University Avenue corner Talisay, Juna, Matina | 7.05597496, 125.594085693 | +63 932 828 3636 | Italian / cafe |
| De Bonte Koe — Rizal | Habana Compound, 29 J.P. Rizal, Barangay 3-A | 7.0670666, 125.6084848 | (082) 317 2217; +63 968 854 4516 | European |
| Omerta Bistro & Lounge | Roof deck, Bormaheco Building Phase 2, J.P. Laurel, Bajada | 7.0876003, 125.6096613 | +63 936 941 6476 | Italian / Spanish |

Coordinates are published named-place pins, not a field GPS survey. Omerta's coordinate identifies the building containing the rooftop venue. Proposed fields, descriptions, individual source URLs, null values, aliases and one selected photo per candidate are in `database/data/restaurant-candidates-davao-city-batch3-2026-11-24.json`.

## Sources and photos

- **Bairrada:** [Tatler 2026 venue profile](https://www.tatlerasia.com/dining/bairrada-churrasqueira) provides the address, phone, menu description, named map coordinates and dining-room photograph. Credit: Tatler Asia / Bairrada Churrasqueira. Schedule remains unconfirmed.
- **Taichou:** [Davao City tourism venue page](https://tourism.davaocity.gov.ph/explore-the-city/restaurants/restaurants/taichou-izakaya-japanese-pub/) supplies the named location, phone, storefront photograph and [map link](https://goo.gl/maps/soZUsT9FxSdST7nv8). Photo uploaded June 2022. Older directory and recent aggregator hours conflict; schedule remains null.
- **Lara Mia:** [MyDavaoCity's named Lara Mia section](https://www.mydavaocity.com/tag/lara-mia/) supplies the pizza photograph, credited to Ria Jose, published February 2019. [Waze's Talisay venue](https://www.waze.com/live-map/directions/lara-mia-talisay-davao-city?to=place.w.82313287.823067330.11072629) supplies the coordinates. October 2026 [operator posts mirrored by FoodBevg](https://www.foodbevg.com/PH/Davao-City/318630738201193/Lara-Mia-Caf%C3%A9-%26-Bistro) publish daily 10:00–21:00 and the same contact number; the older 2019 schedule is not used.
- **De Bonte Koe:** [Davao.page's specific Habana Compound listing](https://davao.page/restaurants/de-bonte-koe/) supplies the restaurant exterior. [Waze's named Rizal branch](https://www.waze.com/live-map/directions/ph/rehiyon-ng-davao/lungsod-ng-dabaw/de-bonte-koe-european-bar-and-restaurant?to=place.ChIJ0QfkFHht-TIRgIPNjVTc9LA) supplies coordinates and the landline. [July 2026 operator posts](https://www.findglocal.com/PH/Davao-City/101849211431882/De-Bonte-Koe-European-Bar-and-Restaurant) supply the mobile number. Sources give different closing times, so hours remain null. The Lanang branch is not substituted for Rizal.
- **Omerta:** [Davao City tourism](https://tourism.davaocity.gov.ph/explore-the-city/restaurants/ktv/omerta-incorporated/) supplies the real interior photograph, uploaded May 2022. It is only 275 × 183 pixels, so a full-width display will be soft. [Waze](https://www.waze.com/live-map/directions/ph/davao-region/davao-city/omerta-bistro-%2B-lounge?to=place.ChIJx-gvqz1t-TIRYBX-W9cCdCw) supplies coordinates. [June 2026 operator posts](https://www.findglocal.com/PH/Davao-City/774726179592224/Omert%C3%A0-Bistro-%26-Lounge) give Sun–Thu 11:00–22:00, Fri–Sat 11:00–01:00, matching Waze. This supersedes inconsistent older directory schedules. Outdoor seating and reservations are described on the [claimed venue listing](https://www.tripadvisor.com.ph/Restaurant_Review-g298459-d19923201-Reviews-Omerta_Bistro_Lounge-Davao_City_Davao_del_Sur_Province_Mindanao.html).

The photos are real source photographs, visually checked in a contact sheet; no generated illustrations were used. Original credits and watermarks are retained. Publication/capture dates are not all current. Files and SHA-256 hashes are saved under `storage/app/restaurant-research-batch3/`. Public reuse permission has not been established; this research does not assert a reuse licence or install the files on public listings.

## Held and deferred candidates

**Benjarong Davao — hold.** A real official Davao food photo, contact details and named map pin (7.10904, 125.650275) were found. However, the [hotel's dining page](https://www.dusit.com/dusitd2-davao/dining/benjarong-bar-and-restaurant/) says **“Temporarily Closed Until Further Notice”**, while the [separate brand page](https://www.benjarongkitchen.com/benjarong-davao/) still lists service times. Do not add it as an active meal stop until the operator resolves the conflict. The held photo is included in the review sheet with a clear hold label.

**Robata — deferred.** Reports indicate a change from its historical Azuela venue and a new Tulip venue. The reviewed historical Azuela article cannot prove a matching photo/pin for the current branch. Branch status and location must be resolved first.

**MISA — deferred.** Existence and its Lanang address are published, but an exact named map pin has not yet been verified, and opening-day sources differ. No guessed coordinate is supplied.

## Intentionally unfilled

No new price ranges, price tiers, visitor ratings, visit durations or unsupported amenities were invented. Only Lara Mia and Omerta have a sourced proposed weekly schedule in this batch. All other schedules await operator confirmation. Five ready candidates and one held candidate each have exactly one downloaded, source-matched image; none has been imported.
