# Grid and Approval Controls: 15 November 2026

The supplied screenshot showed Approve/Reject labels wrapping within compressed table cells. The establishment table used percentage columns and two full-width flex children for the actions; the shared button styling allowed words to break.

Desktop approval controls are now fixed-size check/cross icon buttons. Both have record-specific accessible names and hover titles. Disabled approval still explains that a listing must be linked first. Mobile cards keep icon-plus-text actions, with no word breaking. Actions retain their existing POST routes, CSRF fields, disabled rules and matching behavior. The table keeps a bounded horizontal-scroll layout with enough width for its action column.

Shared grids now use a consistent eight-pixel radius, thin muted top accent, subtly tinted header band, one-pixel border and low elevation. Table striping and hover treatment match across modules. This covers table/report panels, KPIs, statistics, exit-survey KPIs, feedback aspects and mobile establishment cards. Modal interiors retain their borderless treatment.

Thirty-three admin page/filter states rendered successfully in staging and after deployment, including establishment status variants. Render checks also verify approval icon markup, accessible names and tooltips. No approval, rejection, matching or other mutation was submitted during tests. No database changes were deployed. Final browser viewport/pixel verification was not performed; testing confirms server rendering and markup, not actual geometry.

Two files changed: `public/css/admin-workspace.css` and the deployed `resources/views/admin/establishments.blade.php`. Originals were hash-checked and backed up to `/var/backups/exploredvo/admin-grid-20261115-125702/`. The exact patch is in `deploy/admin-grid-2026-11-15.patch`, preserving unrelated changes in this checkout. Compiled views were cleared, PHP-FPM reloaded, and the live stylesheet returned HTTP 200.
