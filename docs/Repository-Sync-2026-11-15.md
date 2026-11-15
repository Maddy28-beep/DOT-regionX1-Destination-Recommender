# Reconcile the deployed tourism updates

This branch starts from `origin/main` at `10e3643` and incorporates the changes deployed during this session. The original checkout at `D:/Downloads/ExploreDVO` remains untouched, including its staged and unstaged work.

All 41 changed application files were compared with a fresh, source-only snapshot of production and match after normalizing line endings. This includes the status-badge wrapping fix, which followed the earlier admin layout patch. The source includes:

- Admin workspace layout, consistent tables/actions, expenditure reporting and actual accommodation-stay reporting.
- Manual home origin separated from pick-up, early origin confirmation, browser/day receipts and completion counts.
- The shorter exit survey, optional actual stays with Other/manual entry, reuse of linked planner profile details, and simpler establishment reviews.
- Removal of the public Filters sidebar on all six catalogs while retaining sorting, categories, existing query state and Grid/Map controls.

The two additive migrations are included. Deployment patches, change notes, reusable diagnostics and the reviewed amenity enrichment scripts are retained. One-time publication helpers tied to temporary staging paths are not part of the branch. Archived unified diffs keep their original context whitespace; `.gitattributes` excludes those patch artifacts from whitespace checks.

The live 1,000 Apriori transactions, survey answers and amenity database rows are not Git source and were not exported. The existing demo-generation command, the recorded seed/batch notes and the enrichment scripts document those data changes. No credentials, environment files, uploads, production database dump or local database are included.

## Validation

The following checks passed during reconciliation:

- 82 isolated server checks/page states: 19 trip-context checks, 27 accommodation-stay checks, 18 quick-feedback checks and 18 catalog states. Every QA write was rolled back.
- 27 JavaScript behavioral assertions for admin navigation, origin confirmation and origin/pick-up independence.
- Syntax checks for all changed JavaScript and non-Blade PHP source.
- Expenditure range/count/coverage/empty-state/tie diagnostics.
- Application-source equality with production and review of the staged files for credentials and runtime artifacts.

The local dependency autoload scan and targeted PHPUnit run stalled without completing. They were stopped; this branch does not claim a complete local PHPUnit pass. The existing isolated server diagnostics above supplied the final functional verification. Full browser visual QA was not performed.

For JavaScript checks, pass the source file explicitly, for example `node deploy/check-admin-workspace.cjs public/js/admin-workspace.js`, `node deploy/check-origin-capture.cjs public/js/plan-origin-capture.js` and `node deploy/check-pickup-origin.cjs public/js/plan-origin-select.js`. Run transactional PHP diagnostics only in the isolated environment described in their source and the individual change notes; do not run database-reset helpers against production.
