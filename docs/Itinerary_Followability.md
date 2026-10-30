# Itinerary clarity and the followability report

This describes three additions that make a saved itinerary easier to understand and easier to check. **None of them changes how a
trip is chosen or scheduled.** Content-Based ranking, Apriori, the stay planner, the arrival rules, the meal windows, the 18:00
sightseeing cutoff, the distance thresholds and the drive budgets are as they were. The 96 audit trips build row for row identical
to the code before these changes (see "How that was checked").

## 1. Why a place was left out

Every place a trip sets out to visit and does not place is listed on the itinerary page, for every distance range (Close Proximity,
Commutable and Remote), including one-day trips.

The reason is what the schedule builder **recorded when it turned the place away**, nothing more:

| Recorded cause | What the page says |
|---|---|
| `would_end_after_cutoff` | on day N the visit would have ended after the 18:00 sightseeing cutoff |
| `drive_budget_reached` | on day N it would have taken the day to about X of driving, over the Y limit for your range |
| `day_full` | day N already held K places |
| `closed_that_day` | it is closed on day N |
| `outside_opening_hours` | on day N it would not have been open long enough for a visit |
| `no_sightseeing_time_that_day` | day N had no sightseeing time after your arrival |
| none recorded | "It was not added to your plan." |

The recorder (`ItineraryScheduleBuilder::recordAttempt`) is read-only with respect to the schedule: it is called beside decisions the
build already makes and writes into a private array. The attempts of each build are kept under a key made of the stops (in order) and
the stay of each night, and the notice for the finished trip reads the attempts of the build whose rows were kept, not of a candidate
build that was discarded. The old default ("did not fit before the 18:00 cutoff", given to every place whose round trip was within
the driving budget) was never established and is gone.

## 2. Notes on a day

`App\Support\ItineraryDayNotes` reads a saved itinerary's rows and returns facts the timeline does not make obvious:

- **Arrival/transfer day**: day 1 of a trip of two or more days with no sightseeing and a journey to the stay. It states the arrival
  time, the journey time, the stay and the first day with sightseeing. It does not say why the day is like that, because the rows
  do not record why.
- **Late check-in**: the time you reach the stay and the last journey. When the arrival is after midnight the schedule shows no time, and the note says "after midnight, so no set time is shown" instead of inventing one.
- **Lunch not arranged**: a "Free time until dinner" that covers the usual lunch hours on a sightseeing day with no lunch.
- **Long driving day**: a day with more than 480 minutes (8 h) of driving carries "Long driving day." on top of the page's existing
  tip (from 4 h of travel). The 480 is `config('itinerary.notes.long_driving_day_minutes')`. It is message-only: no code under
  `app/Services/Recommendation` reads it (a test checks this), it never moves or removes a stop, and it is not a cap.

Days with no lunch because the traveller landed after the lunch hours (13:30 and 15:00 arrivals) are by design: the builder starts no
lunch after 13:00 rather than faking one. They get no note.

## 3. The followability report

```
php artisan itinerary:followability --set=calibration
php artisan itinerary:followability --set=holdout --json=storage/followability_holdout.json
```

It builds one fixed set of 48 trips (the same trips every run) in transactions that are rolled back, so nothing is saved, and prints
Close Proximity, Commutable and Remote in separate columns: destination coverage and omissions by cause, driving per day, stay
continuity, late check-ins, meals, arrival/transfer days and idle blocks.

- **Calibration** (trips 0-47, all starting at Ma-a) and **held-out** (trips 48-95, three starting points) are different runs and are
  never added together. The held-out run is for reporting what a change did on trips nobody tuned on; no figure feeds back into any
  setting.
- The bands it counts (4 h, 8 h, a 4 h idle block, 19:30) describe a plan; they are not rules. There is no option that fails the run
  or changes a result, and a test asserts the command's only options are `--set`, `--json` and `--details`.
- "Omitted" counts places listed on the page. A place that the trip set out to visit and that appears neither as placed nor in the
  list cannot be seen from a saved trip; that cannot happen by construction and is checked in
  `ClarityOmissionReasonTest::test_every_place_left_out_has_a_notice_with_a_cause_the_builder_recorded`.

## How that was checked

`itinerary-audit/rows_snapshot.php` writes every row of the 96 audit trips (day, order, slot, kind, title, times, distance, travel
minutes, destination, accommodation, restaurant, souvenir centre, note, rule columns). The file from `main` (`ddb935f`) and the file
from this change were compared field by field with `rows_compare.py`: 2,483 rows, 0 differences.
