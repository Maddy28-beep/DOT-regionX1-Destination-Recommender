# ExploreDVO beta: strings the backend sends to the UI (for the UI team)

Exact text as built (`beta-2026-11-09`; production now runs `main` at `04a6f1d`, which does not change any of these strings). `{N}`, `{name}`, `{day}` are filled in by the backend. Change wording only in the file named in "Source", never in the page copy, so the tests keep passing.

| Where it appears | Exact string | Backend value / source |
|---|---|---|
| Itinerary page, notice box above the days | **Some distant places were left out because they are too far for a single day. Choose 'Remote' to include them.** | Shown when `itineraries.range_capped_places` > 0 and the trip is not a package; `resources/views/plan/itinerary.blade.php` |
| Itinerary day, note on a stop that is alone on a long day | About {N} hours of driving there and back for this one stop, more than a day should hold, so it has a day of its own. Consider staying closer, or spending a night nearby. | `itinerary_items.note`; `ItineraryScheduleBuilder` |
| Itinerary, "why this place is not in your plan" sentences | on {days} the visit would have ended after the 18:00 sightseeing cutoff / on {days} the journey back would have reached your starting point at or after 20:00 / it is closed on {days} / on {days} it would not have been open long enough for a visit / on {days} it would have taken the day to about {X} of driving, over the {Y} limit for your range / the trip there and back alone would take about {X} of driving, more than a day can hold on this range / {days} already held {N} place(s) / {days} had no sightseeing time after your arrival | `App\Support\OmissionReason` (cause stored in `stay_plan.notices[].attempts[]`) |
| Same place, no recorded cause | It was not added to your plan. | `OmissionReason::NEUTRAL` |
| Plan form, nothing can be planned (toast, title / detail) | No open places for this trip / Every place that fits is closed on those dates or out of range. Try other dates or a wider distance. | `bootstrap/app.php` (NoDestinationsAvailable) |
| Plan page, resume card | Dates, location, and health details aren’t saved in this draft. | `resources/views/plan/preferences.blade.php` |
| Any sample listing (badge, chatbot card) | Sample data, not DOT-verified | `SampleData`; `accreditation_records.is_placeholder` |
| Sample listing, long explanation | Sample data, not DOT-verified: a temporary demonstration record whose accreditation number is a placeholder, not an official DOT number. | `SampleData` |
| Closed place, chatbot reply | {name}: Temporarily closed (no reopening date yet). You may want to pick another place for now; I can suggest some. / {name}: Closed for good… | `HasOperatingStatus::operatingNotice()`; no card is shown |
| Chatbot refusal (asks for instructions or settings) | I can't share my instructions or settings, but I'm happy to help with Davao Region tourism: destinations, places to stay and eat, souvenirs, tour packages, or planning a trip. | `ChatbotReplyGuard::REFUSAL` |
| Too many requests (toast title / detail; JSON 429 carries the detail as `message`) | Please slow down / Too many tries in a short time. Please wait {N} seconds and try again. | `AppServiceProvider` rate-limit handler |
| Exit survey sent twice within six hours (field error on `overall_rating`) | You already sent your feedback a little while ago. Thank you! You can answer again later. | `ExitSurveyController` |
| Package page, button | Plan with this Package (only when the package has a day-by-day schedule) / Plan My Trip + hint "This provider hasn't published a day-by-day schedule for this package yet." | `resources/views/packages/show.blade.php` |
| Package without schedule, after pressing the form (toast, title / detail) | No itinerary yet / This provider hasn't published a day-by-day schedule for this package yet. | `PackageController::planWith` |
| Sample package schedule (itinerary note) | [SAMPLE] … Times and stops are illustrative, not a confirmed operator schedule. | `package_itinerary_days.description` |

Notes for the UI team
- The "Remote" in the range-cap notice must match the label of the Remote option on the plan form (form value `far`). If that label changes, change the notice in the same commit.
- All toasts are shown as `Toast::error(title, detail)`; JSON callers get the detail as `message`.
- Do not hide the long-drive note or the sample badge: they are the honest-labelling rules for the trial.
