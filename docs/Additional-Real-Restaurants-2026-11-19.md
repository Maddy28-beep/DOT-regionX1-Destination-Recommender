# Additional real restaurants — research batch 1

19 November 2026. **All eight restaurants were added locally, with one reviewed real photograph each**, following explicit user approval. No existing catalogue rows or photographs were replaced. Listing/filter/planner implementation is unchanged, and no accreditation records were created.

All eight are marked **DOT accreditation not confirmed** and display the existing unconfirmed information icon, with no claimed DOT accreditation badge. The existence of a restaurant, its appearance in a tourism directory and a hotel's status do not establish the restaurant's own accreditation.

| Restaurant | Location | Cuisine (broad description) | Photo source / credit |
| --- | --- | --- | --- |
| Bondi & Bourke — Davao | Rizal Street, Davao City, beside The Bourke Hotel | Modern Australian | [Bondi & Bourke (official Davao restaurant gallery)](https://bondiandbourke.com/restaurant-davao/) |
| Luz Kinilaw Place | Salmonan / Quezon Boulevard, Barangay 23-C, Davao City | Filipino seafood | [Melo Villareal / PinoyCravings, via Flickr](https://www.flickr.com/photos/melovillareal/2831061898) |
| Café Tavera | P. H. de Tavera corner Avanceña Street, Davao City | Filipino seafood | [Philippine Primer](https://primer.com.ph/food/location/philippines/davao/have-the-best-seafood-experience-at-cafe-tavera-in-davao/) |
| SkyView Restaurant — Blue Lotus Hotel | 7th floor, Blue Lotus Hotel, Quimpo Boulevard, Ecoland, Davao City | International / Asian | [nsnunag / TravellingPeoples.com](https://travellingpeoples.com/2024/03/23/cafes-restaurants-in-davao-city-davao-del-sur-philippines/) |
| Marugame Udon — SM Lanang Premier | SM Lanang Premier, J. P. Laurel Avenue, Davao City | Japanese udon | [nsnunag / TravellingPeoples.com](https://travellingpeoples.com/2024/03/23/cafes-restaurants-in-davao-city-davao-del-sur-philippines/) |
| Bawud — Dahican | Dahican Beach, Mati City, Davao Oriental | Filipino / casual dining | [nsnunag / TravellingPeoples.com](https://travellingpeoples.com/2024/03/21/cafes-restaurants-in-mati-city-davao-oriental-philippines/) |
| Mandarin Tea Garden — Mati | Mati City, Davao Oriental | Chinese | [nsnunag / TravellingPeoples.com](https://travellingpeoples.com/2024/03/21/cafes-restaurants-in-mati-city-davao-oriental-philippines/) |
| Sala Bistro — Mati | Mati City, Davao Oriental | Bistro / café meals | [nsnunag / TravellingPeoples.com](https://travellingpeoples.com/2024/03/21/cafes-restaurants-in-mati-city-davao-oriental-philippines/) |

## Photo selection and evidence

- **Bondi & Bourke — Davao:** [Selected photograph](https://bondiandbourke.com/wp-content/uploads/2018/02/bb1.png).
- **Luz Kinilaw Place:** [Selected photograph](https://live.staticflickr.com/3164/2831061898_fdb35f4047_b.jpg). [Additional evidence](https://tourism.davaocity.gov.ph/explore-the-city/restaurants/restaurants/luz-kinilaw-place/). Historic photograph published in 2008; not presented as a current view.
- **Café Tavera:** [Selected photograph](https://primer.com.ph/food/wp-content/uploads/sites/4/2017/04/tavera-interior.jpg). [Additional evidence](https://www.rotarydistrict3860.org/wp-content/d3860/East%20Davao/25_26/10_ebulletin_ISSUE_10_2026-02-16.pdf). Interior photograph published in 2017; verify current appearance and opening directly before import.
- **SkyView Restaurant — Blue Lotus Hotel:** [Selected photograph](https://i0.wp.com/travellingpeoples.com/wp-content/uploads/2024/03/img_3188-1.jpg?resize=768%2C1024&ssl=1). [Additional evidence](https://www.bluelotushotel.com/dining/).
- **Marugame Udon — SM Lanang Premier:** [Selected photograph](https://i0.wp.com/travellingpeoples.com/wp-content/uploads/2024/03/img_3029-1.jpg?resize=768%2C1024&ssl=1).
- **Bawud — Dahican:** [Selected photograph](https://i0.wp.com/travellingpeoples.com/wp-content/uploads/2024/03/img_2804-1.jpg?resize=768%2C1024&ssl=1).
- **Mandarin Tea Garden — Mati:** [Selected photograph](https://i0.wp.com/travellingpeoples.com/wp-content/uploads/2024/03/img_2696-2-1.jpg?resize=768%2C1024&ssl=1). Mati branch specifically; exact street address needs operator confirmation.
- **Sala Bistro — Mati:** [Selected photograph](https://i0.wp.com/travellingpeoples.com/wp-content/uploads/2024/03/img_2697-1-1.jpg?resize=768%2C1024&ssl=1). Mati venue specifically, not Sala restaurant in Makati. Exact street address needs operator confirmation.

The selected views show the venues except Bondi & Bourke's official Davao gallery photograph, which shows a plated dish. Luz Kinilaw's venue photograph is historical (2008); Café Tavera's interior is from a 2017 article. The five TravellingPeoples photos were published in March 2024; they are not represented as photographs taken in 2026. Watermarks remain intact. Files, SHA-256 hashes and original/source URLs are stored in storage/app/restaurant-research/manifest.json and photos/. The preview sheet is storage/app/restaurant-research/contact.jpg. No reusable public photo licence was established for these copies; attribution alone is not treated as publication permission.

## Duplicate checks and boundaries

- All 54 existing restaurant rows (including rows returned for archived names) were read for names, slugs and public locations. No normalized exact candidate-name or principal-alias matches. Recheck immediately before importing if the catalogue changes.
- Existing Marina Tuna/Marinatuna, Rekado, Swiss Deli, Tsuru, VANDA and Parola were excluded from this batch. Parola Resto Cafe Bar is not added as another name for the existing Parola-Baywalk restaurant.
- SkyView is the separately named restaurant on Blue Lotus Hotel's seventh floor, not another accommodation record. Official hotel dining page confirms this identity.
- Marugame is the SM Lanang Premier branch shown in the source article and selected storefront photo. Mandarin Tea Garden is the pictured Mati branch, not a generic chain record.
- Sala Bistro is the Mati venue, not Sala in Makati. The exact street addresses of Sala and Mandarin's Mati branch still need operator confirmation; no address or map pin was guessed.
- Bawud is the Dahican venue shown in the source, not another listing for Dahican Beach.
- Hours, prices, contact details, current operating status, map coordinates, ratings, booking availability, dietary certification and accreditation were not invented. Historical photographs establish photographed identity, not current appearance or opening status. Confirm current operation directly before publication.

## Applied local import and checks

- Reproducible local/testing-only command: `php artisan restaurants:import-real-local` (dry run), with `--apply` to import. Production is refused. Sources, aliases, credits, hashes and permission records are in database/data/listing-photos/real-restaurants-2026-11-19/manifest.json, alongside the eight photographs.
- All eight initial candidates passed fresh name/alias, region, photo-integrity and provenance checks. Import result: **8 restaurants and 8 photos added**. Repeat preflight: **0 restaurants and 0 photos ready**.
- Photos are stored locally under listings/real-restaurants-local-2026-11-19/. Permission is scoped to the user-authorized local testing; no additional public publication rights are asserted. Original credits and watermarks are retained.
- Unknown prices, opening hours, coordinates and contact details remain blank. No host relationship was guessed. The legacy public-eligibility flag permits catalogue display, while actual accreditation remains unconfirmed because no accreditation provenance was created.
- Existing automatic meal selection requires coordinates. All new coordinates remain null, preserving that candidate set without changing planner code.
- **8,195 existing restaurant, accommodation, destination, listing-photo, destination-tag, accreditation, itinerary and itinerary-item rows unchanged**, checked by SHA-256 fingerprints before and after import.
- All eight public detail routes returned HTTP 200 with their expected stored photo. Each imported restaurant has exactly one real photo and unconfirmed status. Report: storage/app/real-restaurants-verification-2026-11-19.json.
- **30 focused tests passed, 319 assertions**: local-only protection, duplicate/ambiguous alias handling, preserving existing photos, repeat-safe import, info-icon rendering, dining cards and meal windows. Two pre-existing PHPUnit doc-comment metadata warnings remain in unrelated tests.
- Responsive browser verification: **1366 px desktop and 390 px mobile**, including search, Japanese cuisine filtering, long branch-name wrapping, loaded photographs, photo credit and the unconfirmed icon. No horizontal page overflow. Temporary viewport reset afterwards.
- Screenshots: storage/app/real-restaurants-desktop-2026-11-19.jpg, storage/app/real-restaurants-mobile-2026-11-19.jpg, storage/app/real-restaurants-mobile-detail-2026-11-19.jpg.
- Not committed, pushed or deployed. Existing destination batches and unrelated local work remain intact.
