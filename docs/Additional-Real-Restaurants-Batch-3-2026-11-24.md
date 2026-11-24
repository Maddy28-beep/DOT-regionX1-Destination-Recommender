# Five additional restaurants — local import

24 November 2026. Added Bairrada Churrasqueira, Taichou Izakaya Japanese Pub, Lara Mia Cafe & Bistro, De Bonte Koe European Bar and Restaurant — Rizal, and Omerta Bistro & Lounge locally, following the user's explicit import request.

Each has exactly one reviewed real photograph, coordinates, address, cuisine, description and contact details. Lara Mia and Omerta have sourced schedules; other hours and unpublished prices remain unconfirmed. Directory pages are retained as provenance, not labeled as restaurant-owned websites. Photos keep their source credits and original watermarks. Some photos are historical; Omerta's source image is small (275 × 183 px). Public reuse permission is not asserted beyond the requested local use.

DOT accreditation is unconfirmed for all five. The existing information icon is rendered, with no DOT badge or accreditation record created. The legacy visibility flag enables public catalogue display; it is not accreditation evidence.

Benjarong remains excluded because of conflicting temporary-closure information. Robata and MISA also remain excluded pending branch/location verification. See [research and sources](Restaurant-Research-Batch-3-2026-11-24.md).

## Reproducible import

- `php artisan restaurants:import-third-real-local` previews the batch; `--apply` applies it. Only local/testing environments are accepted.
- Reviewed manifest and five files: `database/data/listing-photos/real-restaurants-batch3-2026-11-24/`.
- Files installed under `storage/app/public/listings/real-restaurants-batch3-local-2026-11-24/`.
- Initial result: **5 restaurants and 5 photos added**. Repeat preview: **0 restaurants and 0 photos ready**. Existing restaurant aliases and stay names are checked; existing photos are preserved.

## Verification

- **8,764 pre-existing catalogue and itinerary rows unchanged**, checked using row fingerprints. All five detail routes returned HTTP 200, and the expected public photo files matched their SHA-256 hashes. Imported fields and accreditation icon rendering were verified.
- **36 focused tests passed, 303 assertions**: repeat-safe import, alias reuse, ambiguous-name rejection, existing-stay rejection, production refusal, dining cards, meal windows and opening-day handling.
- Browser checked at **1366 px desktop and 390 px phone**. Bairrada and De Bonte Koe cards showed their real photos and information icon. Mobile filter submission returned the expected restaurant; long names stayed within the existing card layout. Detail pages showed the contact, source credit and map. No horizontal page overflow was observed; temporary viewport overrides were reset.
- Evidence: `storage/app/restaurant-research-batch3/ui/desktop.jpg`, `mobile.jpg`, and `storage/app/real-restaurants-batch3-verification-2026-11-24.json`.

Listing, search and planner implementation is unchanged. Sourced coordinates make the new restaurants available to the existing planner's normal selection rules. Existing records and prior local work were preserved. No commit, push or deployment was performed.
