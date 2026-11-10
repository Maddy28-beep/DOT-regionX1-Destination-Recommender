# Backend completeness table (8 to 9 November 2026)

Build checked: production's `main` at `04a6f1d` (verified file-for-file on the server), data = production dump of 2026-11-08 on a local copy. Branch `phase2/post-trial` fixes are marked "on branch".

**Read this first.** The items R1 to R12, I1 to I14, A1 to A5 and X1 to X4 are not defined anywhere I can read: not in the repository, not in `ExploreDVO_audit/`, and not in this session's saved history (I searched for them). I will not guess what they mean, so those 35 rows are marked **Not checked: definition missing**. Paste the checklist text (one line per item is enough) and I will fill the rows from the evidence below. D1 to D15 are the QA sweep sections and are complete.

Verdict column: Pass / Fail / Partial / Not checked. "Who is wrong" is only filled for a Fail or Partial: **paper wrong**, **code wrong** or **my decision** (a deliberate trade-off you own).

## D1 to D15: QA sweep (`php deploy/qa/qa-sweep.php`, seed 20261109, commit 04a6f1d, 290 of 290)

| ID | Section | Checks | Verdict | Evidence | Who is wrong / note |
|---|---|---|---|---|---|
| D1 | Planner edge cases | 131 | Pass | `docs/eval-before-freeze/qa_sweep_freeze_copy.txt`, `deploy/qa/qa-sweep.php` D1 | Partial caveat: an empty itinerary passes on broad wording (EXEMPTIONS #7); my decision |
| D2 | Route crawl | 1 (aggregated) | Pass | sweep D2; 404 on a parameter route is a note, not a pass (EXEMPTIONS #2) | one aggregated check by design |
| D3 | Authorisation | 27 | Pass | sweep D3 | none |
| D4 | Data-leak scan | 5 | Pass | sweep D4 | none |
| D5 | Hostile input | 32 | Pass | sweep D5 | none |
| D6 | Repeated actions | 6 | Pass | sweep D6, plus the live rate-limit run in `Prep_Verification.md` | none |
| D7 | Application log | 1 | Pass | sweep D7 | none |
| D8 | Photo uploads | 16 | Partial | sweep D8 uses real tiny PNG/JPEG bytes, not GD (EXEMPTIONS #12); the 5 repo tests that resize photos cannot run on this PC (no GD) | Not a defect: GD was confirmed present under php-fpm on the server on 8 Nov; re-run those 5 tests on a machine with GD |
| D9 | Stored XSS | 10 | Pass | sweep D9 | none |
| D10 | CSRF | 5 | Pass | sweep D10 | none |
| D11 | Sign-in flows | 19 | Pass | sweep D11; portal login throttle live-tested on the copy (blocked at attempt 6) | none |
| D12 | QR links | 11 | Pass | sweep D12; genuine, tampered and unsigned links checked on production on 8 Nov | none |
| D13 | Exit-survey limit | 7 | Pass | sweep D13; live: blocked at request 11 | none |
| D14 | Chatbot injection | 16 | Pass | sweep D14; production smoke test; `ChatbotReplyGuard` tests | none |
| D15 | Admin endpoints | 3 | Pass | sweep D15 | none |

(The check counts are the sweep's own per-section pass counts; they add to 290.)

## R1 to R12, I1 to I14, A1 to A5, X1 to X4

| Group | Rows | Verdict | Note |
|---|---|---|---|
| R1 to R12 | 12 | Not checked: definition missing | needs the checklist text |
| I1 to I14 | 14 | Not checked: definition missing | same |
| A1 to A5 | 5 | Not checked: definition missing | same |
| X1 to X4 | 4 | Not checked: definition missing | same |

## Other backend checks I did run (use these to fill the rows above once they are defined)

| Area | Verdict | Evidence | Who is wrong / note |
|---|---|---|---|
| Automated tests | Pass with a known gap | 1776 tests on the merged branch; 5 errors, all need GD (`docs/eval-before-freeze/phpunit_freeze_copy_tail.txt`) | not a defect |
| Trip rules over 460 trips (closed places, hours, expiry, advisories, stay distance, souvenir budget, forged swaps) | Pass | `docs/eval-before-freeze/trip_checks_freeze_copy_FZ_e.txt` and `_f.txt`: 0 failed rules | none |
| Single-stop days over 8 hours of driving | Partial | 24 of ~1,870 days over 460 trips; each shows a visible warning; Known Limitations item 9 | my decision (documented) |
| Stay selection | Partial | 16.7% and 19.3% of 150-trip samples have a stop over 50 km from that night's stay (`Prep_Evaluation_Before_Freeze.md` section 3) | paper wrong (describes the retired trip-wide stay rule) |
| RS/PS (ratings and popularity) | Fail on production, fixed on branch | production gives RS = 5.00 to every unrated place because one 5-star review sets the catalogue mean; branch uses neutral 3.0 below 30 reviews (`50d5734`) | code wrong |
| Amenities Score range | Pass in code, paper wrong | code 3 to 5, docstring fixed on branch | paper wrong |
| Association rules provenance | Partial | 750 admin-page rules, 0 from real visitors; "Simulated" label on branch (`e20a56b`) | my decision (disclose); paper must say so |
| Exit survey and account separation | Fail on production, fixed on branch | survey to preference to itinerary to account was joinable; `a0e140a` unlinks it; 0 rows were linked on production | code wrong |
| Chatbot error log | Fail on production, fixed on branch | the log recorded the exception message, which can echo the visitor's text; branch logs the class only (`034479e`) | code wrong |
| Rate limits | Partial | each limit works (live on a copy); routes with plain `throttle:N,M` shared one counter per address; fixed on branch (`34e5cf9`) | code wrong |
| Orphaned references | Partial | 7 rows point at a deleted listing (6 visits, 1 survey visit); `listings:orphans` on branch; not run on production | code wrong (no cascade on hard delete); cleanup is your decision |
| Backups | Pass | nightly backup of 8 Nov restored: database and 127 files, 452 of 452 photo rows have their file (`Prep_Verification.md`) | 6 non-public import files are not backed up, low risk |
| 30-day purge job | Not checked | first scheduled run is 03:00 UTC (11:00 Manila) on 8 Nov; no log file existed at the time | check the log after the run (command in `Prep_Verification.md`) |
| Accreditation grace setting | Partial | `ACCREDITATION_TEST_GRACE_UNTIL=2026-12-31` on production keeps expired listings visible | my decision, remove after the trial |
| Itinerary speed on the one-vCPU server | Pass | 0.55 to 2.3 s alone, 1.7 to 3.8 s three at once, Groq off | none |
| Embeddings | Partial | 120 of 151 vectors stale against the current text recipe | needs your re-embedding (`Data_Freeze_Runbook.md`) |
| Route directions (OSRM) in a real browser | Not checked | runs in the visitor's browser | your steps are in `Prep_Verification.md` |
| Package pages ("Plan with this Package" on exactly two packages) | Partial | verified on production only by the 8 Nov manual check, not on `04a6f1d` | you will repeat it before the trial |
| Front-end speed changes #232 to #235 on a real phone | Not checked | tests pass; nothing here looks at a phone screen | check the landing page, footer logos and chatbot button on a phone |
| Partner portal actions in the audit log | Fail (known) | not written to the audit log (`admin_id` is not nullable) | my decision, listed in Known Limitations item 8 |

## What is not 100%, and why

1. **35 checklist rows (R, I, A, X) are not checked** because I cannot read their definitions. This is the biggest gap in this table and it is mine to close once you send the text.
2. **Five fixes exist only on `phase2/post-trial` and are not on production** (neutral RS/PS, survey and account unlinked, chatbot log, separate rate limits, "Simulated" label). Production still has the old behaviour until you approve a deploy after the trial.
3. **The data freeze is prepared but not applied**, and 120 embeddings are stale until you re-embed. The paper's Sections 2.3.9 tables must be re-run after it.
4. **Four things only a person can check**: the OSRM routes in a real browser, the two package pages on the live site, the landing page on a real phone, and the purge log after 11:00 Manila.
5. **Known behaviour I accepted rather than fixed**: single-stop long-drive days (shown with a warning), companion type not changing the plan, simulated association rules, partner portal outside the audit log, expired listings visible until 31 Dec 2026.
6. **No real visitors have produced association rules, ratings or popularity yet**, so those parts are untested on real behaviour, not just untested in code.
