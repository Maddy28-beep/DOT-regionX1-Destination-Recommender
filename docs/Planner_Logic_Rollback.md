# Selective planner logic rollback

Restore the itinerary runtime from `98e786e`, the first parent of PR #179's merge (`39b5cf0`). This keeps the upstream remote-neighbourhood, arrival-day and return-home behavior that already existed on main before that PR.

The retained features are:

- Nearby accredited rental-car / tourist-transport operator suggestions, their contact links, and rental pick-up wording.
- Resort settings and filters, including Beach, Mountain, Inland, Island, Lake, Hot Spring and All Inclusive; reviewed classification corrections; preservation of manual changes and intentionally cleared values; no default Inland guess for an unclassified resort.

The previous ranking, scheduling, hotel selection, travel estimates, opening-hour interpretation and audit behavior are restored. The additional strict rejection / constraint-repair pipeline from PR #179 is removed. The generation service differs from the pre-PR version only to recognize a property filed as `Beach Resort` when the selected resort setting is Beach.

No database records are restored, deleted or reseeded by this change. Applied migrations, their history, verification columns and the wider itinerary-note column are retained. Existing public catalogue data, provenance files and manual publication utilities remain available; the previous planner's eligibility rules determine which records it uses. The default database seeder no longer automatically invokes the newer planner-data seeder.

Deploy the merged code normally. Do not run `migrate:rollback`, `migrate:fresh`, or restore an old SQL dump to perform this logic rollback.
