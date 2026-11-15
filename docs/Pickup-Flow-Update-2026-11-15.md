# Pick-up Flow Update: 15 November 2026

Deployed a focused patch against the live application, whose source differs substantially from the current checkout. Unrelated local changes were preserved. The exact three-file patch is saved in `deploy/pickup-flow-2026-11-15.patch`.

## Behavior

- Visitor home origin remains manually selected country/province and typed city. It never disables GPS or changes a chosen pick-up location.
- The existing GPS coordinate check accepts a position inside Region XI and refuses positions outside it. The visitor can still choose a hotel, airport, port or address manually.
- The fresh form no longer silently chooses an airport. Pick-up is required under the live trip-location feature.
- An unresolved typed address, blank pick-up, or out-of-region point is rejected before preferences or visitor-origin statistics are saved.
- A typed address is resolved once during validation; those confirmed coordinates are reused when saving the preference.
- The label remains "Pick-up location". The form explains that transport collection/availability must be confirmed with the rental provider or tour operator; the itinerary does not book transport.
- The legacy feature-off flow retains its original optional-start behavior.

## Verification

- Seven standalone backend checks passed in an isolated copy, then again on the deployed application without database writes: Japan/Philippines home origin with Davao/Tokyo coordinates, blank start, unresolved address, and returning resolved coordinates.
- Nine JavaScript assertions passed for home-country changes, preserved pick-up, GPS availability, and province visibility.
- Patched PHP syntax and all Blade view compilation passed in isolation.
- Public `/plan` returns HTTP 200. Parsed live HTML confirms "Pick-up location", an empty required fresh pick-up field, and the transport booking clarification.
- Survey transaction total remains 1,000.
- PHPUnit is absent from the production dependencies. A PHPUnit regression test is supplied in `deploy/PickupOriginRegressionTest.php`, but was not run; the standalone checks were executed instead.
- Visual browser verification could not be performed because this chat's browser access is unavailable.

## Deployment and Limits

The three original files were backed up to `/var/backups/exploredvo/pickup-flow-20261115-110359/`. Deployment checked their hashes before replacement, cleared compiled views, and reloaded PHP-FPM. No database migration or seeding was performed.

The existing Region XI polygon remains approximate; this patch does not replace it with an official administrative boundary. Pickup selection does not create a car rental, tour booking or provider notification. Previously saved plans are not rewritten.
