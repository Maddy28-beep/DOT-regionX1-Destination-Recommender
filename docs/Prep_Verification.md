# Verification run, 8 November 2026 (prep mode)

Nothing was changed on production. Where production was touched at all, it was read-only or a rolled-back measurement, and the counts were compared before and after.

## 1. Nightly backup restored into a scratch database (database and uploaded files)

- Backup: `daily-20261107-180001` (made 02:00 Manila on 8 Nov), the newest nightly. Downloaded read-only; both files match the server's SHA-256 (`5f01296984b7fc99…` for the database, `41bc384d06c144b7…` for the uploads).
- Database: gunzip and `psql` into a new scratch database `nightly_restore`. 91 errors, every one `role "exploredvo" does not exist` (the harmless ownership message the restore guide predicts). No other error.
- Counts compared with the live production database, table by table (50 tables). 39 tables are identical. The 11 that differ are accounted for by activity after the backup was taken (about 45 minutes before the comparison, and the trial-day work in between), and by the two sample package schedules seeded after it:

| Table | Backup | Live | Why |
|---|---|---|---|
| itineraries / itinerary_items / itinerary_matches | 302 / 7459 / 6153 | 305 / 7561 / 6264 | 3 trips created after the backup |
| tourist_preferences, preference_activities, preference_amenities | 152, 266, 177 | 153, 268, 183 | same |
| chatbot_logs | 187 | 188 | one chatbot question |
| system_audit_logs | 79 | 81 | 2 audit rows written after the backup |
| package_itinerary_days | 0 | 2 | the two [SAMPLE] schedules seeded after the backup |
| sessions, cache | 135, 14 | 101, 16 | expiry and churn |

- Uploaded files: the archive holds 127 files, the same 127 as `storage/app/public` on the server. All 452 photo rows in the restored database point to a file that is in the backup (452 of 452 present, 0 missing).
- Not in the backup: 6 small files outside `public` (four `import-manifests/*.json`, two `.gitignore`). They are import records, not visitor content, and are recreatable from the repository. Documented limitation, no action needed.
- Verdict: **the nightly backup restores completely** (database and files).

## 2. Itinerary generation time on production (beta code, rolled back, Groq off, off-peak 02:50 Manila, load average 0.04)

| Request | Generation time |
|---|---|
| A: 3 days, Commutable, alone (2 runs) | 794 ms and 799 ms |
| B: 2 days, Close, alone | 554 ms |
| C: 4 days, Remote, alone | 2,261 ms |
| A, B and C started together on the one-vCPU droplet | 2,527 ms, 1,672 ms and 3,769 ms |

Before the beta (7 Nov, other code): A about 1.0 s, B about 0.55 s, C about 3.4 s; three at once 1.8 / 2.9 / 4.9 s. So the beta is not slower. Row counts for all 50 tables were identical before and after (the measurement rolled back). Peak memory 28 to 32 MB per request. Scripts: `qa7/prod_timing.php` (not in the repository; copied to `/dev/shm` for the run and removed afterwards).

## 3. Admin page timings (local copy of the production data, through the real HTTP kernel; a developer PC, not the one-vCPU server, so expect the server to be slower)

Median of 7 requests per page: overview 81 ms, establishments 67, accreditation 52, exit surveys 55, audit log 36, data completeness 171, reports 46 to 50, listings 54 to 55, package approvals 36, advisories 32, public destinations map 105. **Association rules 967 ms (max 1,196 ms)** is the slowest page by a long way; it recomputes the rules on every open. All return 200. Script: `deploy/qa/eval/admin_page_timings.php`.

## 4. 30-day purge log

Cannot be checked yet. The job `privacy:purge-anonymous-data` runs at 03:00 UTC (11:00 Manila). At 18:51 UTC on 7 Nov the schedule showed it due in 8 hours, and `storage/logs/privacy-purge.log` does not exist yet, so no scheduled run has ever been proven. After 11:00 Manila on 8 Nov, run:

```bash
ssh -i ~/.ssh/exploredvo_do root@209.97.174.246 "tail -n 20 /var/www/exploredvo/storage/logs/privacy-purge.log"
```

It should show a timestamped block with a "Retention 30 days" line and a count table. The count is probably all zeros, because nothing is 30 days old yet. A missing file after 11:00 would mean the scheduler does not run it. The cron entry that calls `schedule:run` every minute does exist.

## 5. Live rate-limit test (local copy only; `deploy/qa/eval/rate_limit_live.py` refuses any host that is not 127.0.0.1)

| Route | Limit | First blocked request | Answer |
|---|---|---|---|
| Portal sign-in (same account) | 5 a minute | #6 | 429 JSON, "Too many tries in a short time. Please wait 58 seconds…" (a browser gets a redirect with a "Please slow down" toast instead) |
| Chatbot message | 30 a minute | #31 | 429 "Too Many Attempts." |
| Exit survey (fresh browsers) | 10 an hour | #11 | 429, wait 3,594 s |
| Trip build (regenerate) | 30 a minute | #31 | 429 |
| QR check-in | 20 a minute | #21 when alone | 429 |

**Bug found and fixed on the branch (commit `34e5cf9`).** The routes that used a plain `throttle:N,M` (chatbot, QR check-in, reviews, address suggestions, admin search) shared one counter per address. Twelve chatbot messages left only 8 of the 20 allowed QR check-ins. On a shared Wi-Fi at an event this could block check-ins because other people were chatting. Each now has its own named limiter with the same limit and the same answer. Live check after the change: unchanged limits; 3 new tests (one fails before the change).

## 6. Steps for you: check the OSRM routes in a real browser

The route map in each itinerary day calls the public `router.project-osrm.org` from the visitor's own browser, so only a real browser on a real connection can confirm it.

1. Open `https://www.exploredavao.com/plan`, make the demo-safe trip 2 (Commutable, 3 days, Family, Leisure, First-time Visitor, Mid-range, origin Manila, arrival 09:00, start 14 or more days ahead, Nature & Adventure and Cultural Heritage).
2. On each day, scroll to the map. Within a few seconds the line under it should change from "Calculating route…" to "Approx. N km · M min driving time (via OpenStreetMap routing)", a green line should be drawn, and numbered directions should appear.
3. Compare N and M with the "Approx. … km, … min" figures printed on that day's travel rows. They are estimates, so they will not match exactly; flag any day where OSRM says more than about 1.5 times the page's time.
4. Open the browser's developer tools (F12), Network tab, filter "osrm". Each day should show one request with status 200. Note any 429 or timeout.
5. Failure case: in the Network tab choose "Offline" or block `router.project-osrm.org`, reload. The page must still show the itinerary, and the line under the map must read "Step-by-step directions are not available right now." (after at most 12 seconds), not stay on "Calculating route…".
6. Repeat step 2 on a phone on mobile data once.
7. Send me what you see for any day that fails (screenshot of the day and of the Network row).
