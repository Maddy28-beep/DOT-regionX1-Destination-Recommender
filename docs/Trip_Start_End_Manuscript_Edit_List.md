# Manuscript edit list: trip starts from a real pick-up location and a real start/end date-time

Status: **a list only. The manuscript has not been touched.** Apply these yourself (new or changed text in your current
edit colour) after the change is on the live site and you have re-checked the screens.

The change in one sentence: after "Plan my trip" the traveller says where they come from, where they are picked up, and
when the trip starts and ends (Philippine time). The old "Arrival date", "Arrival time", "Number of travel days" and
"Finish by" inputs no longer exist; the length of the trip is worked out from the two moments.

## 1. Chapters that describe the inputs (Methodology / System design)

| Where | What to change |
|---|---|
| Description of the trip-planning form / survey instrument | Replace the list "arrival date, arrival time, number of travel days, finish-by" with: **(1) where you are travelling from** (country; province only when the country is the Philippines; city or municipality optional; "Prefer not to say" allowed), **(2) pick-up location** (hotel, airport, port or any address inside Davao Region; defaults to Davao International Airport; free text allowed), **(3) start date and time**, **(4) end date and time**, both in Philippine time. |
| Order of the questions | Who is coming and where from, then pick-up location, then start and end, then interests and the remaining preferences (those are unchanged). |
| Validation rules (new paragraph or table) | End must be after start; at least 3 hours between them; at most 30 days (both limits are settings, not code); a start in the past is refused for travellers ("Start must be in the future") and allowed only for a signed-in administrator or when the demo setting is on; start at most two years ahead; pick-up must be inside Davao Region. |
| "Number of travel days" | State that it is **derived**: it is the number of calendar days the trip touches (1 Dec 14:00 to 3 Dec 10:00 is 3 days, matching the Day 1, 2, 3 tabs). The exact length is shown separately ("1 day, 20 hours"). |
| Time zone | State once: all times are Philippine time (Asia/Manila, UTC+8, no daylight saving), stored as wall-clock Philippine time and labelled "PHT". |

## 2. Algorithm / planner chapter

- **Day 1 begins at the pick-up location at the start time.** Nothing is scheduled before the start, and the travel from
  the pick-up location to the first stop is counted. (Before: Day 1 began from an arrival time and an optional start point.)
- **The last day ends at the end time, including the way back.** The planner reserves the return to the pick-up location
  plus a planning allowance of **2 hours** (`itinerary.departure.airport_buffer_minutes`, a setting). The itinerary says so:
  "The end time includes the return to your pick-up location, with 2 hours to spare." The allowance is a planning margin,
  not a routed travel time.
- **Partial first and last days** receive proportionally fewer stops. Opening hours and meal slots are checked against the
  real dates and weekdays of the trip.
- Remove or rewrite any sentence that says the planner uses "finish-by", "arrival time" or "number of days" as inputs. They
  are now derived from start and end and handed to the same scheduling code.
- The itinerary header reads: "From <origin> · Pick-up at <location> · <start> → <end> (<length>)".

## 3. Data dictionary / ERD (tourist_preferences)

Add: `start_at` (datetime, nullable), `end_at` (datetime, nullable), `duration_days` (small integer, nullable, calendar days
touched). Keep, and mark "legacy, still filled from the two moments so the switch can be turned off": `start_date`,
`arrival_time`, `end_time`, `end_date`, `travel_days`. `origin_lat`, `origin_lng`, `origin_label` are the **pick-up
location** (name unchanged). `place_of_origin` and the `visitor_origin_*` columns are the traveller's **home place**
(different thing; keep the explanation of the two, it is easy to confuse them).
Dictionary regeneration note: the dictionary is generated from the live schema, so regenerate it after the migration
`2026_11_14_000100_add_trip_start_end_to_preferences` is applied.

Old trips: a one-time migration fills `start_at`/`end_at` from the old fields (start = arrival date + arrival time, 09:00
when there was none; end = last day at the finish-by time, or 23:59). Mention this in the migration/backward-compatibility
paragraph.

## 4. Privacy / ethics chapter

See `Trip_Start_End_Privacy_Page_Edit_List.md`; keep the manuscript and the live Privacy page saying the same thing.
Points that belong in the manuscript too: the pick-up location is rounded and cleared with the plan after 30 days; the
origin answer is kept **per browser session** (a new submission replaces the earlier one), so counts are of sessions, not
of people; groups under 5 are never shown; the outside AI service (Groq) receives the number of days and place facts only,
**never** the pick-up location, the start/end times or the origin.

## 5. Results / testing chapter

Numbers to update after the final run on the merge commit (the figures below are from the build of this change):
full automated suite **1,935 tests** (only the 5 known image-library tests error on the developer's PC), QA sweep
**290/290 with the switch off and 298/298 with it on**, demo scenarios 1-5 render with a return stop. Add test topics:
start/end validation edges, Philippine-time boundaries, derived duration, planner never schedules before the start or after
the end, migration of old trips with and without an arrival time or finish-by.

## 6. Figures and screenshots that are now outdated

- The Plan My Trip form (all steps): new step order and the two date-time fields; the old "When are you visiting?" block with
  quick-date buttons, "Number of travel days" and "Finish by" is gone from the switch-on form.
- The itinerary page header (now shows origin, pick-up, start, end, length and the return-to-pick-up note).
- Any flowchart of the form or of "inputs to the planner"; the sequence diagram "form -> controller -> planner" (the
  controller now derives days/arrival/end time from start/end).
- Admin Trip Plans report: columns are Travel Type, Purpose, Budget, **Days (calendar days)**, **Starts**, Created.

## 7. Limitations / future work (short paragraph)

One pick-up location per trip and a single return point (the same place); times are Philippine time only; the 2-hour
allowance is a fixed planning margin; the legacy columns are kept until the switch has been permanent for a while, then they
can be dropped; saved trips whose start has passed ask for a new start instead of being re-planned in the past.

## 8. Wording that must **not** appear any more (search the manuscript)

"arrival date", "arrival time", "number of travel days" as an input, "finish by", "quick date buttons", "Where will you
start each day?" (now "Pick-up location" when the switch is on), "travel days (1-30)" as a form field.
