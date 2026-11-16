# Display written feedback to DOT admins

Exit-survey comments were stored and available in the existing report export, but Exit Survey Insights had no written-comment section. Visitor Feedback similarly queried rating fields without rendering establishment review text. This was a display/query gap, not a requirement for visitors to submit their answers again.

Exit Surveys now shows Comments & suggestions prominently below the filters. Visitor Feedback shows Written reviews with the establishment name and any existing owner reply. Both show the full saved text, submission time in Philippine time, rating, newest-first ordering and pagination (15 per page). Cross-links distinguish vacation survey comments from establishment reviews. No visitor token or author identity is rendered.

Survey comments use exactly the existing source/date/profile filters: real responses by default, demo comments only through the explicit simulated checkbox and individually labeled, test-mode and itinerary-import records always excluded. Establishment review comments follow the existing establishment and date filters; that page defaults to its last-30-day range. An older review may require widening the date filter. Existing rating/statistics queries are unchanged.

The shared partial uses Blade escaping and preserves line breaks with CSS. HTML entered by visitors remains readable plain text rather than executable markup. No migration or data backfill was needed, and no existing submitted text was edited or deleted.

Twenty transaction-only checks passed for survey/review storage, actual comment rendering, full text, script escaping, demo/test separation, filters, pagination, owner replies, token exclusion and authenticated routes. All QA rows were rolled back. PHP syntax and diff whitespace checks passed. Full visual browser QA was not performed.

Production inventory at verification: three real exit-survey comments, zero test survey comments and one establishment review with text. After deployment the actual authenticated Exit Surveys route rendered all three real survey comments; Visitor Feedback rendered zero written reviews in its default date range, with the existing older/out-of-range review still stored. The admin must widen the date filter to read an out-of-range review. Both routes continue redirecting unauthenticated visitors to login, and the new CSS responds HTTP 200.

Six application files are deployed. Original SHA-256 checks, a backup, final comparisons, compiled-view clearing and PHP-FPM reload were completed. Backup: `/var/backups/exploredvo/written-feedback-20261116-054133/`. Restore the four original controller/view files and clear views to roll back; the unused new partial/CSS can remain. No production response rows were changed.

Diagnostic: `deploy/check-written-feedback.php` in the isolated current-code environment. `--inventory` reports only counts without reading comment text into the output; the QA run uses an outer transaction and rolls back its fixtures. A complete local PHPUnit run is not claimed because that runner previously stalled.
