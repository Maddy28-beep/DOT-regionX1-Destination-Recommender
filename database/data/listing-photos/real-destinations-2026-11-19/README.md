# Fifteen real destinations — local batch

Approved by the user on 19 November 2026 for local use, with one matched real photo per destination and no claim of DOT accreditation. `manifest.json` records names, locations, factual descriptions, image source URLs, credits, original image URLs, dimensions and SHA-256 hashes. Original files retain watermarks and source marks.

Preview / apply locally:

```powershell
D:/xampp/php/php.exe artisan destinations:import-real-local
D:/xampp/php/php.exe artisan destinations:import-real-local --apply
```

The command refuses production. The photos use `team_testing_decision`, not a claim of ownership or publication rights. No public publishing permission is asserted by downloading from a government or other public website.

Existing records and photos are preserved. Normalized names, slugs and known aliases prevent duplicates; ambiguous matches stop the entire batch. A repeat run adds zero records/photos. Each new destination has exactly one real photo with source credit. No accreditation records are created, so the existing public card and detail views show the unverified “i” mark rather than the DOT badge. The legacy `is_accredited` flag is used by this app for public eligibility; official accreditation provenance is independently derived from accreditation records.

Unknown rates, coordinates, hours, durations and amenities remain blank. These destination rows use the existing `excluded` itinerary role until operational visit details are verified. This keeps the catalogue additions from altering generated plans; no listing, filter, planner, approval or sharing code is changed. They remain searchable public destination entries locally.

Notes on photos: Energy Park shows its Dahon monument and retains Ida Nanette Damo's watermark/credit; Panabo shows its mangrove-park entrance at the inauguration; the Davao Museum photo shows the study-gallery opening at Ateneo, not the former Lanang facility; Rafflesia is a bloom photo, not a guarantee of availability. Museum access, mountain trails and seasonal visits require confirmation with the operator/LGU.

Verification: 11 feature tests / 501 assertions passed; the local import added 15 destinations and 15 photos, followed by a zero-add repeat preview. All 15 public detail pages returned HTTP 200 and referenced their real photos. Fingerprints of 7,769 existing destination, photo, tag, accreditation, itinerary and itinerary-item rows were unchanged. Responsive browser checks are recorded in `docs/Additional-Real-Destinations-2026-11-19.md`.
