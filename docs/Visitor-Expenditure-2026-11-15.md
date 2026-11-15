# Visitor Expenditure: 15 November 2026

## Investigation

Exit surveys already store an optional `estimated_total_spend` bracket. Existing admin insights showed a representative-value average near the bottom, rather than highlighting the five actual selected ranges. The original question says total trip spend for food, transport, activities and shopping, excluding accommodation paid separately in advance. Therefore these data must not be described as an exact, all-inclusive vacation cost, daily spending or measured regional tourism revenue.

## Deployed

- Dedicated Analytics navigation entry and page: `/portal/admin/expenditure`.
- Overview highlight with real-response coverage, the most reported range (or a tie/empty state), and a link to the report.
- Five-range table with visual bars, response counts and percentages; percentages use recognized spending answers as the denominator.
- Answered, unanswered and coverage statistics so optional omissions are visible.
- The same distribution appears near the top of Exit Survey Insights.
- Filters for dates, residency, visit type and travel purpose. Real tourist responses are the default. Demo inclusion is explicit and labeled; imported itinerary baskets and test surveys remain excluded.
- Aggregate CSV export uses the same source and filters as the screen, includes all five ranges, documents denominator and question scope, and identifies simulated exports. Invalid/reversed date filters are rejected. Formula-like filter values are escaped in the CSV.
- Existing averages remain secondary and are relabeled approximate/bracket-based. No exact spending sum is invented, and no survey wording, stored answer, source label or bracket definition was changed.

## Verification

Range/coverage tests passed for recognized, missing and invalid answers, zero-data selections, all five brackets, tied leaders and the open-ended top band. Ten read-only checks passed for real/demo source exclusions, residency/date filtering, CSV/screen parity and date validation. Thirty-seven admin page/filter states rendered successfully after deployment. No QA survey rows were added or changed. Visual browser verification of the new screen was not performed.

At verification, there were three real survey responses, two with a spending answer. The default report therefore reflects two real spending answers, not the 1,000 mixed Apriori transactions. Demo inclusion demonstrates larger-volume reporting but does not turn simulated data into observed tourism expenditure.

## Source and Deployment

New service: `app/Services/Admin/ExpenditureSummary.php`. New view/partial: `resources/views/admin/expenditure.blade.php` and `resources/views/admin/partials/expenditure-distribution.blade.php`. Existing deployed controller, sidebar, overview, exit-survey view, routes and stylesheet were updated with source-hash checks. No database migration or data seeding was performed.

Backup: `/var/backups/exploredvo/expenditure-20261115-133409/`. Routes/views were cleared and PHP-FPM reloaded. Unauthenticated expenditure requests still redirect to login. The exact nine-file change is preserved in `deploy/visitor-expenditure-2026-11-15.patch`, because deployed source differs from this checkout; unrelated local changes were preserved.
