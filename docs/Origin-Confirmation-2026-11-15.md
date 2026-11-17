# Early Origin Confirmation: 15 November 2026

Deployed to the live application following explicit project-owner approval.

## Visitor Flow

The origin question has a "Confirm origin and continue" button that sends only country, province, optional city and CSRF token. No dates, pick-up point, health details or itinerary are required. It records an origin response even if the visitor leaves without generating a trip. The wizard's Next button and forward navigation also confirm origin before advancing out of its first step.

The adjacent notice discloses early statistical recording, protected browser deduplication and retention. Country/province are validated against the existing lists; city uses the existing cleaning rules. The endpoint uses the web/CSRF middleware, the trip-location feature flag and a 30-per-minute rate limit. Browser/network errors are shown rather than reporting a false success.

## Counting

- One response per browser token per Philippine calendar day. This is not a unique-person count; separate browsers, cleared cookies and separate devices can count separately.
- The receipt stores a daily HMAC of the token with the application key, never the raw cookie. Daily hashes differ across days.
- Repeated confirmations and expired browser sessions do not add another response when the cookie is retained.
- Corrections move the day's existing count to the new origin group.
- Successful itinerary generation marks the existing response as having a generated itinerary. Repeat generation does not add another origin or completion count for that browser/day.
- A legacy, already-counted trip in the same session/day is adopted rather than counted again.
- Permanent aggregate rows contain origin groups and two counters. Protected receipts are separate and scheduled for daily cleanup; the origin form discloses a 30-day retention period.
- Existing historical totals are retained with their former session-based counting semantics. Historical generated-itinerary counts were not backfilled.

## Admin

The visitor-origin report and CSV provide a Measure selector: "Confirmed origins" or "Origins with a generated itinerary". The latter counts browser/day responses that reached successful generation, not the number of all itineraries or unique people. Both retain the existing minimum group size of five. A newly recorded answer may therefore remain under "Other" or "Not enough answers yet".

## Verification

Sixteen backend regression checks passed against an isolated PostgreSQL database with transactional rollback, including confirmation without a trip, cookie-based deduplication across sessions, corrections, invalid inputs, new-day/new-browser counts, separate completion totals, legacy adoption, admin small-group suppression and the feature-off path. Eight browser-script assertions passed for payload scope, pending-request deduplication, success and validation-error handling. PHP/JavaScript syntax checks and Blade compilation passed.

Seven additional deployed smoke checks passed. The live origin confirmation, duplicate protection and completion marking were exercised inside a rolled-back transaction; no QA origin rows were committed. Public HTML includes the button and notice, the script responds successfully, and the receipt cleanup appears in the scheduler. Visual browser automation is unavailable in this chat and was not performed.

## Deployment

The database was backed up first. Seven existing files were hash-checked and backed up under `/var/backups/exploredvo/origin-capture-20261115-114007/`. The one additive migration adds `generated_visitors` to the aggregate table and creates the receipt table. Compiled routes/views were cleared and PHP-FPM reloaded. The public planner returned HTTP 200.

The exact ten-file change is preserved in `deploy/origin-confirmation-2026-11-15.patch`, because the deployed source differs from this dirty checkout. The new service, migration, script and diagnostic checks are also kept under `deploy/`; unrelated local source changes were not replaced. The temporary PostgreSQL test database was removed after verification.
