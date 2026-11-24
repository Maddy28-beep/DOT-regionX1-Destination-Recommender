# Real destinations — fourth local batch

Applied locally on 19 November 2026: **15 new destinations and 15 real photographs**, one per destination. Existing photos and catalogue records are preserved. Source URLs, original image URLs, credits, aliases and SHA-256 hashes are recorded in the adjacent batch manifest.

## Identity decisions

- **Langan Falls replaces Langan River Park**, as authorized by the user after the exact park photograph could not be verified. These are not claimed to be the same attraction. The replacement photograph comes from the [Mount Apo Geopark geosite page](https://mountapogeopark.com/geosite/langan-falls/).
- **Big Ligid Island only:** Little Ligid is already listed as Island Buenavista (destination 194). The proposed grouped Ligid entry was narrowed to Big Ligid to avoid that overlap. Its credited photograph shows visitors on the Big Ligid shoreline, from Kuya Baroy's location-specific video thumbnail.
- Mount Fortune View uses Martina De Castro's credited location-specific video thumbnail, showing the viewpoint. Neither thumbnail is a generated illustration.
- Sandunganan/Gorilla Peak, Bamboo Peak/Buntod Ta Laya, Tomari/Tumari Falls and FGR Park/Mati Baywalk remain one record per physical destination. Names and aliases were checked against destinations (including archived entries) and accommodations before mutation.
- Candelaria's image is explicitly described as an inauguration photograph. Sinoron's image is described as the natural rock wall and water droplets, and Tagabuli's as the view from a documented islet cruise. No generic scenery is substituted without identifying what it shows.

## Added records and photo sources

| Destination | Region | Photo credit and source |
| --- | --- | --- |
| Doña Vicenta Park | Davao City | [Davao City Information Office, via SunStar](https://www.sunstar.com.ph/davao/local-news/dona-vicenta-park-now-open) |
| Los Amigos Park | Davao City | [Davao CIO Photo, via SunStar](https://www.sunstar.com.ph/davao/local-news/los-amigos-park-opens-to-public) |
| DECA Wakeboard Park Davao | Davao City | [Jojie Alcantara, via DOT Region XI](https://www.experiencedavao.com/activities/wakeboarding) |
| Toril Public Park | Davao City | [Meant to Go](https://meanttogo.com/toril-public-park/) |
| Candelaria Park | Davao City | [City Government of Davao](https://x.com/davaocitygov/status/1368021474544521217) |
| Tagtugonan Falls | Davao del Norte | [DOT Region XI / Davao Adventure Challenge](https://dotxievents.com/) |
| Big Ligid Island | Davao del Norte | [Kuya Baroy / Ligid Dako Island video](https://www.youtube.com/watch?v=GI9SrY969TI) |
| Bamboo Peak | Davao del Sur | [Julius R. Paner / LOGSAC](https://logsac.blogspot.com/2021/06/hiking-barangay-jose-rizals-bamboo-peak.html) |
| Mount Fortune View | Davao del Sur | [Martina De Castro](https://www.youtube.com/watch?v=T0PPqReXLe8) |
| Tomari Falls | Davao del Sur | [Julius R. Paner / LOGSAC](https://logsac.blogspot.com/2020/09/top-day-hiking-sites-in-sta-cruz.html) |
| Sinoron Ecotourism Park | Davao del Sur | [Julius R. Paner / LOGSAC](https://logsac.blogspot.com/2020/09/top-day-hiking-sites-in-sta-cruz.html) |
| Tagabuli Sea Rock Garden | Davao del Sur | [Julius R. Paner / LOGSAC](https://logsac.blogspot.com/2016/10/sta-cruz-mangrove-sanctuary-and-secret.html) |
| Sandunganan Peak | Davao Oriental | [DOT Region XI](https://www.experiencedavao.com/activities/sandunganan-peak) |
| Mayor Francisco G. Rabat Park and Baywalk | Davao Oriental | [Kadayawan / Wikimedia Commons · CC BY-SA 3.0](https://commons.wikimedia.org/wiki/File:Mati_Park_and_Baywalk.JPG) |
| Langan Falls | Davao del Sur | [Mount Apo Geopark](https://mountapogeopark.com/geosite/langan-falls/) |

## Accreditation, operational data and local photo scope

- No accreditation records created. All 15 use the existing unconfirmed information icon; no DOT accreditation badge is claimed. The existing legacy public-eligibility flag is separate from actual accreditation provenance.
- Unknown fees, coordinates, hours and durations were not invented. New destinations retain itinerary_role=excluded until operational visit details are verified, preserving existing itinerary generation. Existing detail-template fallback wording is unchanged.
- Photo verification refers to identity/source review, not DOT accreditation. Photos were authorized for local testing; no general public publication licence is asserted. Mati's photograph records Kadayawan's CC BY-SA 3.0 licence and link. Watermarks are preserved.
- Listing templates, filters, routes and planner implementation are unchanged.

## Reproducible import and verification

Command: php artisan destinations:import-fourth-real-local (dry run); add --apply to import. Local/testing only. Repeat-safe; rejects ambiguous aliases, stay collisions, missing photo files and invalid provenance before changing records.

- Initial preflight: 15 destinations / 15 photographs ready. Applied: 15 / 15. Repeated preflight: 0 / 0.
- 7865 prior destination, photo, destination-tag, accreditation, itinerary and itinerary-item rows compared by SHA-256: **zero changed rows**.
- All 15 publicly visible detail routes returned HTTP 200 and referenced the expected locally stored photo. Each new destination has exactly one real photo and unconfirmed status.
- Responsive browser checks: 1366 px desktop and 390 px phone listing/detail; primary photo, credit and info icon visible; no horizontal page overflow. Browser viewport reset afterwards.
- Screenshots: storage/app/fourth-destinations-desktop-2026-11-19.jpg, storage/app/fourth-destinations-mobile-2026-11-19.jpg, storage/app/fourth-destinations-mobile-detail-2026-11-19.jpg.
- Final focused regression suite: **13 tests passed, 508 assertions**. Two pre-existing PHPUnit metadata deprecation warnings remain in unrelated planner tests.

Not committed, pushed or deployed. Previous batches and unrelated local work remain intact.
