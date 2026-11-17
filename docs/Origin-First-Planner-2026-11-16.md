# Confirm origin before showing trip details

The JavaScript-enabled `trip_location_v2` planner now starts with only the manual home-origin controls. Trip dates, pick-up, companions/visitor type, Next/Submit and step shortcuts stay hidden until the origin-confirmation endpoint acknowledges a successful save. A pre-render enhancement marker applies the gate before deferred scripts run, avoiding an initial flash of trip fields. Without JavaScript, the existing native form remains available; the legacy feature-off flow is unchanged.

Validation errors, HTTP 419/500, network errors and unexpected success payloads keep the gate locked and leave a retry message beside Continue. An origin edited while saving cannot unlock the form with the previous answer's response. Editing a confirmed country, province or city relocks the form, returns to step one and requires confirmation again, while preserving entered trip choices.

Successful confirmation is cached only for the same origin on this page; wizard navigation reuses it rather than sending another confirmation. No home-origin values or confirmation flag are added to localStorage. The existing backend browser/day deduplication remains authoritative. No receipt, statistics, API or database schema changes were made.

Saved-draft offers appear after origin confirmation; resuming cannot jump past the gate. Final submission also verifies confirmation, with a second busy check after its asynchronous wait to prevent duplicate itinerary builds.

Verification: 26 DOM assertions ran against the actual privately rendered planner HTML and its wizard/country scripts, covering initial visibility, validation, payload scope, pending/error/race states, invalidation, cached confirmation, drafts, localStorage scope and duplicate final submission. The existing eight origin-script checks passed, along with JavaScript syntax/whitespace checks. Seven backend/HTTP smoke checks verified recording without a trip, browser/day deduplication and separate completion marking, with QA rows rolled back. Full visual browser QA was not performed.

DOM command: `node deploy/check-origin-gate.cjs <path-to-jsdom> <privately-rendered-plan.html>`. jsdom is a temporary QA dependency outside the project; no production dependency was added.

The four application files are live, with original SHA-256 checks and final source comparisons. Backup: `/var/backups/exploredvo/origin-gate-20261116-052432/`. Public HTTP verified the pending marker and exact capture script. Restore the four files from that backup and clear compiled views to roll back. Existing records, privacy suppression thresholds and origin aggregate counting were not changed.
