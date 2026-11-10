# Hand-offs: things only you can do (9 November 2026)

Production runs `main` at `04a6f1d`. Everything below is on `phase2/post-trial` (draft PR #236, not merged, not deployed). Nothing here changes production.

## 1. Question for DOT: the shared accreditation number

Send this as is, or shorten it.

> Good day. In the Region XI accreditation list we received, two establishments carry the same accreditation number, DOT-R11-TRA-01222-2024:
>
> - **Aries** (our record #8)
> - **A-Star** (our record #50)
>
> A-Star also appears as its own row in your sheet, with the same name, address and phone number as the entry we hold. Please confirm:
> 1. Is DOT-R11-TRA-01222-2024 the correct number for **both** businesses (for example, one owner, two branches), or was it entered twice by mistake?
> 2. If it belongs to only one, what is the correct number for the other, and its validity dates?
> 3. Are the two businesses the same establishment under two names?

What we hold today:

- Aries #8: the number above, from the DOT sheet.
- A-Star #50: the number above in the sheet, but the **local record is a placeholder**, DOT-XI-2025-0026, which is not an official number. I kept A-Star as a separate row because the sheet lists it separately.
- Until DOT answers, the app treats them as two listings. Do not merge or delete either. Do not present the number as verified in the paper.

## 2. Your re-embedding (120 of 151 vectors are stale)

The full steps, with every command, are in `docs/Data_Freeze_Runbook.md` (section "On your development machine"). In short:

1. Check out `phase2/post-trial` and apply the two data files to your local copy (`listings:anonymise-samples`, then `listings:apply-updates --replace-custom`, dry run first, then `--apply`).
2. Install Ollama, `ollama pull nomic-embed-text`, set `EMBEDDINGS_URL` and `EMBEDDINGS_MODEL=nomic-embed-text` in `.env` (same model as before).
3. `php artisan embeddings:build`. Expected last lines: `Embedded 120, unchanged 31, total 151.` and `Exported 151 vectors to database/data/destination-embeddings.json`.
4. Commit that JSON file and push it to `phase2/post-trial`, then send me the commit hash. I load it with `embeddings:import`, `embeddings:diagnose`, `embeddings:coverage` and re-run the checks.

Then I re-run `deploy/qa/eval/eval_embeddings.php` so Table 57 is "after freeze".

## 3. Other tasks only you can do

| # | Task | When | How |
|---|---|---|---|
| 1 | Repeat the package-page check ("Plan with this Package" on exactly two packages) on the live site | **Before the trial** | The only evidence for the build on `04a6f1d` is the 8 Nov manual check. |
| 2 | Check the landing page, footer logos and chatbot button on a real phone | Before the trial | Front-end speed changes #232 to #235; tests pass, nothing here has looked at a phone screen. |
| 3 | Route directions (OSRM) in a real browser | Before the trial if possible | Steps in `docs/Prep_Verification.md`. |
| 4 | Check the 30-day privacy purge log after its scheduled run (03:00 UTC, 11:00 Manila) | Now | Command in `docs/Prep_Verification.md`. No log existed when I looked. |
| 5 | Keep People's Park as is | Pre-demo | You told me it stays closed on purpose; confirm nothing in your demo script depends on it. |
| 6 | Remove `ACCREDITATION_TEST_GRACE_UNTIL=2026-12-31` from the production `.env` | After the trial | Otherwise expired listings stay visible. |
| 7 | Paste the R1 to R12, I1 to I14, A1 to A5 and X1 to X4 checklist lines | Whenever | I cannot read them anywhere; 35 rows of `Backend_Completeness_Table.md` are blank until then. |
| 8 | Decide on the orphan rows (7 rows point at a deleted listing) | After the trial | `php artisan listings:orphans` reports; `--delete` removes. Not run on production. A guard against new orphans is not built; tell me if you want one. |
| 9 | Decide the deploy scope after the trial | After the trial | `phase2/post-trial` holds five fixes (neutral ratings, survey and account unlinked, chatbot log, separate rate limits, Simulated label) plus the data freeze. You can take them all or pick. |
| 10 | Review the sample descriptions (54 rewritten, plus #57) | Before the freeze | `database/data/sample-descriptions-2026-11.csv`. |
| 11 | Re-run the 5 GD-dependent tests on a machine with GD | Any time | They error on this PC only because GD is missing; GD is present on the server. |
| 12 | Manuscript: apply the corrections in a pink-text **copy** of v2 | After you say | List of wrong sentences: `docs/Claims_Register_DRAFT.md`. I have not touched any manuscript file. |
| 13 | Merge or close PR #236 | After the trial | I will not merge it. |

## 4. Small doc number you may want to check

- `docs/Known_Limitations_Beta.md` item 9 says 27 long-drive days over 460 trips; the completeness table says 24 of about 1,870 days (a different counting basis: days over 8 hours of driving on single-stop days only, versus the 15 + 12 breakdown). Pick one definition before the defense so the two documents agree.
