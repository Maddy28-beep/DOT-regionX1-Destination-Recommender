# Privacy page edit list: trip start, end, pick-up location, derived duration, origin per browser session

Status: **a list only. Nothing on `resources/views/legal/privacy-policy.blade.php` or the Terms page has been changed.**
Each item says where, what the page says today, and the proposed wording. All new wording sits inside the existing
`@if (\App\Support\Features::tripLocation())` blocks so that it only shows when the switch is on.

Facts checked in the code for this list:
- Start, end and the derived length are stored in `tourist_preferences` (`start_at`, `end_at`, `duration_days`) with the plan, not with a name or token.
- The pick-up location (`origin_lat`, `origin_lng`, `origin_label`) is the existing "starting location", rounded to about 100 m by the form; the purge job already clears those three columns and `place_of_origin` and the `visitor_origin_*` columns when a plan is kept for an exit survey, and deletes the whole row otherwise (30 days).
- The purge job does **not** null `start_at`/`end_at`/`duration_days` on a row that is kept for an exit survey. A date and time of a trip with no place attached is not personal on its own (same as the old `start_date`, which it also did not clear), but see decision 1 below.
- The outside AI service (Groq) receives number of days, distance range, budget, interests and place facts; it does **not** receive the pick-up location, the start/end times or the origin (`AiSkeletonAdvisor` docblock and code).
- The origin answer is stored on the trip plan of a browser session; submitting again replaces it. The admin "Visitor origin" counts are therefore counts of sessions.

## 1. Section 1, bullet "Trip preferences" (line ~39)

Today: "travel dates, budget, travel type, accommodation preference, interests, and an optional starting location (rounded to
roughly 100 metres, never your exact position) ..."

Proposed (switch on): "the **start and end date and time** of your trip (Philippine time), the **pick-up location** you
choose (a place name or an address in Davao Region, rounded to roughly 100 metres, never your exact position unless you
press "Use my current location"), budget, travel type, accommodation preference and interests, together with the itinerary
built from them. The length of your trip is worked out from the start and the end. None of this is linked to your name."

## 2. Section 1, paragraph "Where you travel from (optional)" (line ~78)

Add one sentence at the end: "Your answer belongs to the plan made in that browser session: if you plan again in the same
session, the new answer replaces the earlier one, so our counts are counts of plans made in browsers, not of people."

(Keep "fewer than 5 visitors is never shown on its own" and "deleted or cleared after 30 days" as they are.)

## 3. New short paragraph after it: "Your pick-up location and trip times"

"**Your pick-up location and trip times:** we ask where we should pick you up (your hotel, the airport or port, or any
address in Davao Region) and when your trip starts and ends, so the day-by-day plan begins and finishes where and when you
need. We use them only to build and show your itinerary, and to tell DOT Region XI, as counts, how long trips are and which
dates are busy. The pick-up location is not linked to your name and is cleared together with the plan after 30 days. If you
leave the pick-up location unchanged it is Davao International Airport."

## 4. Section "How long this is kept" (line ~83-)

No change in the periods. Optional addition to the first bullet: "(this includes the start and end times and the pick-up
location of the plan)". **Decision 1 (yours):** either (a) leave the purge as is and say nothing more, because a date and
time with no place is not personal; or (b) I extend the purge to also clear `start_at`, `end_at` and `duration_days` on rows
kept for an exit survey, and the page says "start and end times are cleared too". Recommendation: (a), because the survey
analysis may want trip length; say so explicitly if you choose it.

## 5. Groq paragraph "AI-assisted day plan" (line ~186-192)

Today: "It does not receive your name, browser token, starting point, dates, arrival time or anything you typed."
Proposed: "It does not receive your name, browser token, **pick-up location, start or end time, where you are travelling from**,
or anything you typed." (Still true; this only names the new inputs. It still receives the number of days.)

## 6. Device location paragraph (line ~201)

No change. The pick-up field's "Use my current location" button is the same one described there.

## 7. Section on what DOT Region XI sees / statistics

If the page lists the statistics DOT sees, add "trip length and start dates, as counts". The admin Trip Plans report shows
Days (calendar days) and Starts (date and time); it does not show origin or pick-up.

## 8. Terms of Use page

No change needed for the new inputs. If the Terms say "plans are suggestions", add that "the end time you give is a planning
target; we reserve about 2 hours before it for the way back, which is an allowance, not a guaranteed travel time."
(The itinerary page already states the allowance.)

## 9. Last-updated date and consent text

After any of the above is applied, change "Last updated" to the date it goes live. No consent checkbox is involved
(nothing here is a new category of personal data; the page's existing "optional / not linked to your name" framing covers it).

## 10. Plan form helper text (not the privacy page, for consistency)

The form already says (origin block) "Optional. Used for tourism statistics. Not linked to your name. Deleted after 30
days." The pick-up block says the pick-up point "has to be inside Davao Region", "private: rounded to about 100 metres,
never linked to your name". Nothing to change; keep these in step with section 3 above.
