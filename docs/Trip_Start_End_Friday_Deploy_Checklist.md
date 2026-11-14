# Friday-evening deploy checklist: trip start / end date-time (trip_location_v2)

Nothing below has been run. **No server step happens until you say "go".** Commands are labelled PowerShell (PC) or
Server (SSH). Stop at the first failure and report.

State at the time of writing (13 Nov 2026):
- origin/main = `be74422` (PR #248, Sunday scope). Production runs `be74422` with `trip_location_v2` **ON** (database row, set 12 Nov).
- Existing rollback tag `rollback-pre-trip-location-v2` = `fe7acff` (the version before the Sunday scope). Do not move it.
- This change is the PR from branch `feature/trip-start-end-datetime` (includes the four small admin/filter/province fixes). You merge it; I do not.

## Switch behaviour (read this first)

- The switch is a database row (`feature_flags`), so a deploy does not change it. Production is ON now.
- With the switch ON, the new form is what visitors see the moment the new code is live. To avoid a visitor submitting the new
  form in the few seconds between "code copied" and "migration finished" (the new columns do not exist until the migration
  runs), **turn the switch OFF just before the deploy and ON again after the smoke test.** With the switch OFF, visitors get the
  old form and old behaviour exactly, and the new code does not touch the new columns.
- Cost of that: the Visitor origin question and the new start/end form are off for roughly 10-15 minutes on Friday evening.

## 0. Before anything (PowerShell (PC))

0.1 Confirm the PR is merged by you and CI/tests were green: <https://github.com/Maddy28-beep/DOT-regionX1-Destination-Recommender/pulls>.
0.2 `git fetch origin; git rev-parse origin/main` -> write down the new merge SHA (call it `<MERGE_SHA>`). It must be different from `be74422` and must contain `95cf0c9`-equivalent changes (`git log origin/main --oneline -5`).
0.3 `git status` in `D:\Downloads\ExploreDVO` is clean on `main` (the deploy script packs `origin/main`, not your working tree).

## 1. Confirm origin/main (PowerShell (PC))

```powershell
git ls-remote origin main
```
Expected: `<MERGE_SHA>` only. If it is `be74422`, the PR is not merged yet: stop.

## 2. Rollback tag (PowerShell (PC))

The code to return to is what production runs now, `be74422`:
```powershell
git tag rollback-pre-trip-start-end be744226b75e4442f942ab2560e6eeadfd43775d
git push origin rollback-pre-trip-start-end
git ls-remote origin refs/tags/rollback-pre-trip-start-end
```
Expected: the last line shows `be744226...`. (Run this only if production is still at `be74422`; I will re-verify the
live commit by file hash first, as on 12 Nov.)

## 3. Backup (Server)

The deploy script takes `pre-deploy-<timestamp>-<sha>.sql.gz` automatically (step 1 of `server-update.sh`). For an extra,
named dump before the migration:
```bash
ssh -i ~/.ssh/exploredvo_do root@209.97.174.246
cd /var/www/exploredvo && sudo -u postgres pg_dump exploredvo | gzip > /var/backups/exploredvo/before-trip-start-end-$(date -u +%Y%m%d-%H%M).sql.gz && ls -la /var/backups/exploredvo | tail -3
```
Expected: a file of a few MB or more. (Use the backup directory the deploy script reports if it differs.)

## 4. Switch OFF, then deploy (Server, then PowerShell (PC))

4.1 Server: `cd /var/www/exploredvo && sudo -u www-data php artisan features:set trip_location_v2 off`
Expected: "trip_location_v2 is now off." Open <https://www.exploredavao.com/plan>: the old form (days, arrival date) shows.

4.2 PowerShell (PC), in `D:\Downloads\ExploreDVO`:
```powershell
.\deploy\deploy.ps1 -Yes
```
Expected: backup taken, code copied, `migrate --force` runs **one** migration (`2026_11_14_000100_add_trip_start_end_to_preferences`),
caches rebuilt, PHP reloaded, the six pages answer 200. If any step fails the script stops: do not continue; see Rollback below.

4.3 Server, confirm the migration and the backfill:
```bash
cd /var/www/exploredvo && sudo -u www-data php artisan migrate:status | tail -3
sudo -u postgres psql exploredvo -c "select count(*) total, count(start_date) dated, count(start_at) filled from tourist_preferences;"
```
Expected: migration "Ran"; `filled` = `dated` (every row that had an arrival date now has start_at/end_at).

4.4 With the switch still OFF, check the old behaviour is unchanged: <https://www.exploredavao.com/plan> shows the old form;
the six pages the script checks (/, /destinations, /accommodations, /plan/start, /advisories, /portal/login) return 200.

## 5. Live smoke test, switch ON (steps 5.1-5.11; steps marked ADMIN need you)

5.0 Server: `sudo -u www-data php artisan features:set trip_location_v2 on` (expected "now ON").
5.1 Open `/plan` on a phone-width window: step 1 order is Who's coming, About you (country/province), Pick-up location (Davao International Airport pre-filled), then "When does your trip start and end?". There is **no** Arrival date, Arrival time, Number of travel days or Finish by.
5.2 Choose Start = tomorrow 14:00 and End = two days later 10:00: the line under the fields reads "Your trip is 1 day, 20 hours long."
5.3 Try End before Start, then End 2 hours after Start, then a start in the past: each shows its own message and the wizard does not advance.
5.4 Submit a valid trip (origin: Philippines / Cebu / Cebu City). The itinerary opens with Day 1, 2, 3 tabs, and the header reads "From Cebu City, Cebu, Philippines · Pick-up at Davao International Airport · <start> PHT → <end> PHT (1 day, 20 hours)" plus "The end time includes the return to your pick-up location, with 2 hours to spare."
5.5 Day 1's first stop starts after the start time; the last day ends with "Trip ends: back at Davao International Airport" before the end time.
5.6 Plan a one-day trip, 09:00 to 18:00: it renders with a return stop and nothing after 18:00.
5.7 Open `/plan` again (same browser): the form shows the saved start/end. (To test the "start has passed" message you need a saved trip in the past: skip on production; it is covered by an automated test.)
5.8 Repeat 5.4 with country Japan and with "Prefer not to say": both render; the header shows "From Japan" / no "From".
5.9 ADMIN (you): sign in, open Reports > Trip Plans Created for today: columns are Travel Type, Purpose, Budget, **Days**, **Starts**, Created; the trip from 5.4 shows Days 3 and the start date/time. Tell me and I will wait.
5.10 ADMIN (you): open Visitor origin: filters work on Philippine time; the per-session note is shown; the counts include 5.4/5.8 (a group under 5 folds into "Other"; empty states say how many answers there are so far).
5.11 Browser console on `/plan` and the itinerary: no red errors. Privacy page loads and still has "Where you travel from".

Stop at the first failing step and go to Rollback.

## 6. How to turn trip_location_v2 off again (any time, takes effect immediately)

Server:
```bash
cd /var/www/exploredvo && sudo -u www-data php artisan features:set trip_location_v2 off
```
Expected: "now off". Visitors get the old form (days, arrival date, finish-by) and old behaviour; trips saved with the new form
keep working because the old columns (`travel_days`, `start_date`, `arrival_time`, `end_time`, `end_date`) are always filled
too. Back on: `... features:set trip_location_v2 on`; back to the file default: `... features:set trip_location_v2 default`.

## 7. Rollback (only if a step fails and switching off is not enough)

1. Switch OFF (section 6). This alone fixes any problem in the new form.
2. If the code itself is broken: PowerShell (PC): `git checkout rollback-pre-trip-start-end` then deploy that tag the way the
   Sunday rollback was prepared (deploy script packs `origin/main`, so revert on main with `git revert -m 1 <MERGE_SHA>`, push
   via PR, then `.\deploy\deploy.ps1 -Yes`). I will walk you through it; do not improvise.
3. The migration only **adds** three nullable columns; they can stay in the database after a code rollback. Do not drop them
   unless asked. If the database itself is damaged, restore `before-trip-start-end-*.sql.gz` (see `deploy/BACKUP-AND-RESTORE.md`).

## 8. After a good smoke test

Report back with the live commit (hash-verified), the migration status, the backfill counts and the smoke-test result. Remove
the test trips made in 5.4-5.8 only if you ask (they are deleted automatically after 30 days).

---

## Appendix: the unexplained 500 on GET /plan (investigation, 13 Nov)

- **Production:** nginx access log for 12-13 Nov shows `GET /plan` 200 x17, `GET /plan/start` 200 x11, `POST /plan` 302 x6 and 200 x1, `GET /plan/itinerary` 200 x4. **No 5xx on any /plan request.** (The only "5xx" lines are scanner garbage requests with malformed lines.) nginx error log: only scanner probes (`.env`, `host-info.php`). Laravel log: three errors on 12 Nov 18:51-18:52 ("column updated_at / created_at does not exist"); those were **my own read-only count queries** run on the server that evening, not web requests, and they changed nothing.
- **Local (where the 500 was seen):** `laravel.log` has two errors at 12 Nov 19:34:50 and 19:35:00, `InvalidFormatException: Unexpected data found` while rendering `partials/plan-trip-basics.blade.php`. Cause: while I was building the new feature, `TripWindow::parse()` let Carbon throw on a stored date-time that did not match the expected format. It was fixed minutes later (try/catch around the parse) and has tests ("tomorrow", date-only, 30 Feb, 25:00 values are refused without an exception). It happened only on my development copy, never on production, and never in a released commit.
- **Repeated requests after the fix** (local, switch on and off): first visit, second visit, 10 more GETs, `/plan/start`, itinerary before submit (302 to the form, correct), valid POST (302), GET /plan after submit (x6), itinerary after submit (200), invalid POSTs (end before start, garbage date, 30 Feb: 302 back with a message) each followed by GET /plan, a garbage session cookie, and HEAD: **all 200/302, no 500, no new log errors.**
- One side finding on my PC (not the app): the local Postgres and the preview web server stopped by themselves twice when a background task ended; I restarted them. This produced "connection refused" entries in the local log and curl status 000, unrelated to the feature.
