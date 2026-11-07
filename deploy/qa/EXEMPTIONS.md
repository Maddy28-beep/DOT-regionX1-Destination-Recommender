# QA sweep: exemptions, allow-lists and changed assertions

`php deploy/qa/qa-sweep.php` (see the header of that file). This register lists everything in the sweep that is not a plain
"must be clean" check, and every check that was changed after it first failed. A reviewer should be able to disagree with any line.

## 1. Exemptions and allow-lists in the code

| # | Where | What is exempt | Why |
|---|---|---|---|
| 1 | D2 crawl | Routes starting with `_` and `storage` are not crawled | `_` = framework/debug routes; `storage/{path}` is the framework's local-disk file server (its PUT is attacked separately in D3) |
| 2 | D2 crawl | A 404 on a route **with a parameter** is not a failure; the sweep prints the list as a NOTE | The crawl supplies guessed ids (id 1, a package that is not in review, a hotel partner on tour-operator screens). Only fixed URLs that 404 fail |
| 3 | D2 crawl | A 404 for a partner on `portal/establishment/packages*` | Tour-package screens belong to tour-operator accounts; a hotel/attraction partner correctly gets 404 |
| 4 | D2 crawl | Login, register, forgot and reset pages are open to a guest | They are meant to be |
| 5 | D2 crawl | Only HTTP 5xx, stack-trace text, a placeholder accreditation number, and the role rules (guest/partner/admin pages) fail | It is one aggregated check, not one check per page |
| 6 | D1 | HTTP 422 is accepted as a normal answer from the planner form | 422 is the framework's validation answer; the check separately requires a readable message on refusals |
| 7 | D1 | An empty itinerary passes if the page contains one of: "no sightseeing / itinerary / destinations", "could not", "not available", "ran out", "Try " | Broad on purpose; it can pass a page that happens to contain "Try ". Closed-everywhere is checked precisely (see 14) |
| 8 | D1 | "Has stops" = the markup has `itin-stop` / `data-stop`, or the text "Day 1" | Layout-dependent; a redesign needs this updated |
| 9 | D5 | A chatbot message over 500 characters must be refused with HTTP 422 | That is the designed limit (`max:500`) |
| 10 | D5 | Review and exit-survey text over 500 characters must be refused, nothing stored | Designed limit (`max:500`) |
| 11 | D7 | The log line "No accredited destinations are available" is not counted | The closed-everywhere check in D1 raises it on purpose. The number of such lines is printed |
| 12 | D8 | Uploads are built from real tiny PNG/JPEG bytes, not by the GD library | GD is not installed on the QA machine (also why 5 repo tests fail locally) |
| 13 | D15 | HTTP 302, 401, 403, 404, 405 all count as "refused" for an admin endpoint called by a guest, tourist or partner; a 404 on an admin route is reported as a fault | 302 = sent to sign-in. A 405 would mean the method does not exist, so it proves nothing about authorisation; none occurred. Partner-area routes are attacked only as guest (a partner may use their own area; partner-to-partner is D3) |
| 14 | environment | `ACCREDITATION_TEST_GRACE_UNTIL=2026-12-31` is set | Production copy data has most stays expiring 31 Oct; without it the planner sees few listings. The live grace setting is still unverified |
| 15 | environment | `APP_ENV=testing` switches CSRF off | D10 flips the environment to `local` for its own checks so CSRF is really on |
| 16 | environment | The throttle is lifted for D1 and D6 (many requests) | The real limiter is tested separately in D6 and D11 |
| 17 | environment | The sweep runs on a copy of the production data from 2026-10-31, rolled back; it never touches production | By design (the script refuses a non-local database) |

## 2. Assertions that were changed to make a check pass, and why

Each of these was changed after a first failing run. In every case the cause was a fault in the sweep and not in the application; none was
a loosening to hide an application defect. The two real application defects the sweep found are in section 3.

| Check | Before | After | Reason |
|---|---|---|---|
| D1 invalid input refused (4 checks) | Passed or failed on a page that fell back to the previous trip | Each check starts with a flushed session | Session leaked between checks; an invalid POST redirected back to an old trip |
| D1 closed-everywhere | Looked for words in the page body | Reads the error toast ("No open places for this trip") | The app shows refusals as toasts, whose text is inside a script |
| D2 partner opens admin page | 30 "partner can open admin page" | Logs out every guard before each role | The previous role's admin login carried over; the "partner" was also an admin |
| D2 and D9 crawls | Built URLs from numeric ids and used the first record | Use each route's real binding field (slug) and a publicly visible record | Detail pages returned 404 and were never rendered; hidden records were picked. **The earlier D2 pass did not cover listing detail pages** |
| D6 rate limit | Expected HTTP 429 for a browser request | JSON gets 429; a browser gets a 302 with the flash "Please slow down - Too many tries in a short time. Please wait N seconds" | That is how the app is built (`back()` with a toast) |
| D8 "real PNG named .php" | Expected accepted | Expected rejected | The app is stricter than I assumed; rejecting is the safer behaviour |
| D11 lockout | Cleared the limiter with its raw key | Gives the limiter a fresh in-memory store each phase | The limiter hashes its keys and holds its own cache store |
| D12/D13 | Visitor cookie sent unencrypted | Sent encrypted, as a browser's cookie is | The app discards a plain `visitor_token`, so every request was a new visitor |
| D13 test switch | Looked at the newest row | Reads without the "counted" scope | The app hides `data_source=test` rows from normal queries |
| D14 closed place | Passed if the reply did not say "open daily" | Requires the reply to say it is closed and give no opening hours | The first version could not fail |
| D14 model scenarios | Asked about a named place | Asks a general question and requires that the outside service was actually called | Named-place questions never reach the model, so the AI path was untested |
| D3 | Tested `PUT promotions/{id}` and 5 guessed trip URLs | Tests every real mutating route that takes another partner's record id, and the real saved-trip routes, each with a positive control | The promotion edit URL does not exist (405) and most trip URLs were not routes, so those checks passed trivially |
| D5 | Review and survey posts only reached their refusals | Review goes through a real QR check-in; survey sends all required fields | Reviews need a signed QR scan first; surveys need a rating and recommend answer |

## 3. Application defects the sweep found (fixed with tests)

1. Chatbot, AI path: a closed place named by the model had its false "open daily" claim removed but was then described from the record with no word that it is closed; the model was also offered closed places. Fixed in `ChatbotReplyGuard` and `ChatbotService`; `tests/Feature/ChatbotClosedPlaceTest.php`.

## 4. Things the sweep cannot prove (printed as NOTE)

- `SESSION_SECURE_COOKIE` must be `true` in the live `.env` (cannot be shown on a local http copy).
- The genuine QR link has no expiry, by design; only forged expiring links were tested.
- Real calls to the outside AI service are never made; it is faked. Its real behaviour is covered by the guard tests, not by the sweep.
