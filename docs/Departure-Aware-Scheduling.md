# Departure-aware itinerary scheduling

Branch `feature/departure-aware-scheduling`. **Not merged, not deployed, migration not run on production.**

## What it does

A traveller may give an optional departure time and choose where they leave from. There is no departure date to enter: they leave on the last day of the trip, arrival date + (travel days - 1). The last day of the
itinerary is then built backwards from that departure, so the trip never plans a stop the traveller cannot leave
in time to catch their departure.

Latest set-off from the last stop = departure time − airport allowance − the planner's estimated journey to the
departure point.

## Inputs

| Field | Rule |
|---|---|
| Departure date | **Not entered; derived** as arrival date + (travel days - 1) and shown under the departure time ("Leaving November 8"; "Add your arrival date to see your departure date." without an arrival date). A `departure_date` sent by an old form is not read. The `departure_date` column is kept and holds the derived day (empty without an arrival date); the planner places the departure on the last day whatever it holds. |
| Departure time | Optional. Needs a departure point. On a one-day trip it must be after the arrival time. Works without an arrival date (the last day is then counted from today). |
| Leaving from | Needed with a time. Quick picks (Davao Airport, seaport, bus terminal, same as start) or a typed place that the address lookup can find. Never inferred: the app has no airport selector, so the traveller chooses. |

Travel days and the arrival date are unchanged. A traveller who gives nothing, or only an arrival time, gets exactly
the schedule they got before.

## The airport allowance

**Project-owner decision (2026-11-04): the preferred default is 120 minutes.**

- Default `120` in `config/itinerary.php` (`departure.airport_buffer_minutes`), overridable with the
  `ITINERARY_DEPARTURE_BUFFER_MINUTES` environment variable. It stays configurable.
- It is disclosed to the tourist on the planning form ("We allow 2 hours there before you leave.") and on the
  itinerary's departure row ("…a planning allowance and not an airline or airport rule").
- It is a planning allowance, not an airline rule. A traveller whose airline needs more should leave earlier.
- This decision settles the default and the documentation only. It does **not** authorise merging, deployment,
  running the migration, or any production change.

## How the schedule is built

- The restriction applies while the schedule is built, not as a warning afterwards. Every rebuild (stay
  settlement, filling an empty day, swaps) goes through the same builder, so the check is repeated after each
  change.
- Stops that cannot make the departure are moved to earlier days, or omitted with a recorded reason shown to the
  traveller ("not enough time to visit it and still reach your departure point before your departure").
- A visit that would run into lunch and miss the departure is shortened to end before lunch when that makes it.
- If no sightseeing fits on the last day, the day keeps the transfer rather than forcing a stop.
- The journey time comes from the existing travel-time estimate (straight line plus a detour allowance, at the
  slowest pace). No routing service is added and the itinerary says the figure is an estimate. If a stop has no
  coordinates, the estimate says it could not be measured and an allowance is used.

## Data

Migration `2026_11_04_000100_add_departure_to_preferences.php` adds five nullable columns to `tourist_preferences`:
`departure_date`, `departure_time`, `departure_lat`, `departure_lng`, `departure_label`. The location columns are
cleared by the existing anonymous-data purge. **Not run on production.**

## Tests

The tests (`DepartureAwareSchedulingTest`, `DepartureInputTest`) cover validation, the travel-time calculation,
feasibility, the final activity before the deadline, the allowance, a destination 20 km from the airport, a distant
destination excluded, swapping, insufficient time, flexible tourists, the existing arrival and sightseeing
constraints, same-date departure, invalid dates, an unmapped stop, and
end to end from form to itinerary page, plus the derived departure date (1 to 4 days, month end, year end, leap year, no arrival date,
stale posted dates). The full suite result is in the pull request; the 5 errors there are the missing PHP GD extension on the
development machine, also present on `main`.

## Limits

- Journey times are estimates, not routed times.
- Only one departure point per trip, on the last day.
- Nothing here changes scoring, Apriori, eligibility, driving budgets or meal scheduling.
