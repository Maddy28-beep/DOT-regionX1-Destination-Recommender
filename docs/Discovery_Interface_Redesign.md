# Discovery card redesign (9 November 2026): local only, awaiting review

Branch `feature/context-aware-discovery`, started from `origin/main` (`38376ad`). Nothing is committed, pushed, merged or deployed. No migration was needed. The screenshots are in `docs/discovery-screenshots/`.

The "discovery interface" is the home-page hero card (the five tabs Destinations, Stays, Dining, Packages, Souvenirs).

## 1. What it was, and what was wrong

One card with the same boxes on every tab: a name search ("Search a place" and a variant per tab), four fixed budget buttons (Any budget, Budget-friendly, Mid-range, Premium), Where, and one or two of Interest, Kind of stay (the accommodation type: Hotel, Resort, ...), Cuisine or Trip length. The budget buttons meant the same three labels everywhere, whatever the catalogue records. There were no dates, guests or meals.

## 2. What it is now

Each tab is its own short form. Everything is optional, and every box opens on "Any".

| Tab | Boxes | Behind each box |
|---|---|---|
| Destinations | Budget (entry fee), Where, Interest | `entry_fee_min`/`entry_fee_max`; `region_id`; the existing interest list and `typesForInterest()` |
| Stays | Budget (per night), Where, Kind of stay (Any / Overnight / Day use), From, To, Guests (Adults, Children, Senior citizens, PWD with + and -) | `price_per_night`; `region_id`; see section 5; dates and guests are kept in the URL and shown as chips, not checked against anything (section 6) |
| Dining | Budget (price level), Meal (Any / Breakfast / Lunch / Dinner), Date, Cuisine, Where | `price_tier`; opening hours (section 4); `cuisine_type`; `region_id` |
| Packages | Budget (total price), Where, Interest, Trip length, Travelers | `price_per_pax` times travelers; `region_id`; existing interest and `duration_days` filters |
| Souvenirs | Where | `region_id` |

The general search box is gone from the card (removed from the markup, script and style, not hidden). The backend still reads `q`, so old links and the "Search by name" box on the listing pages keep working. The four budget buttons are gone, replaced by one dropdown per tab.

## 3. Budget dropdown

Options: **Any budget** (default, first), peso ranges, **Custom budget** (reveals Minimum and Maximum boxes; either may stay empty). It is checked in the browser (numbers only, minimum not above maximum, message under the boxes, form not sent) and again on the server. **Reset** puts every box back on Any.

What a budget means differs by tab, because the data does (`app/Support/BudgetRanges.php`):

| Tab | Basis | Data on file (8 Nov copy) | Ranges offered (listings in each) |
|---|---|---|---|
| Destinations | entry fee; a place fits when its fee range overlaps the budget | 145 of 151 have a fee | Free entry available (28), ₱1-100 (104), ₱101-300 (55), ₱301-1,000 (18), above ₱1,000 (10) |
| Stays | price per night | 255 of 255 | under ₱1,500 (16), ₱1,500-2,500 (28), ₱2,500-4,000 (200), ₱4,000+ (11) |
| Packages | total = per-person price x travelers (1 if none) | 5 of 5 | under ₱1,000 (1), ₱1,000-2,500 (2), ₱2,500-5,000 (1), ₱5,000+ (1) |
| Dining | **no peso price on file**; the existing price level | 72 of 81 have a level | Budget-friendly, Mid-range, Premium; no Custom |
| Souvenirs | **no price on file** | none | no budget box |

How the ranges were chosen: cut from the spread of the prices above so every range holds at least one listing. The card offers a range only while a visible listing still falls in it, so a choice never leads to an empty page. Custom has no such limit.

A place or stay with no price on file is kept by "Any budget" and left out when a budget is set.

## 4. Dining: meal and date

Restaurants have no "serves breakfast" field, but they have opening hours, and the planner already schedules breakfast at 07:00, lunch at 12:00 and dinner at 18:30. So a meal means "open at that hour", read with the existing `OpeningHours` parser (`app/Support/MealTimes.php`). A date also applies hours that name days ("Closed Mondays"). Hours the parser cannot read, or none on file, put no limit on the place, as elsewhere in the app. On the copy: Breakfast 5 of 63 restaurants, Lunch 63, Dinner 61.

**Dietary preference and food service (Plated / Buffet) are not on the card.** No restaurant record stores Halal, Vegetarian, Allergy-free, Plated or Buffet (checked the restaurant columns and the 81 descriptions; only a few mention breakfast or buffet in free text). Offering them would claim something the site cannot check, so the Dining tab says "Dietary needs and plated or buffet service are not recorded for restaurants yet."

## 5. Kind of stay: Any / Overnight / Day use

- **Any** (shown as exactly "Any", the default): no restriction.
- **Overnight**: every accredited lodging type (Hotel, Resort, Beach Resort, Condominium Unit, Homestay, Apartment Hotel) is somewhere to sleep, so this adds no limit. It shows as a chip.
- **Day use**: the accommodation table has **no field** for this, and no stay says it in its text on the 8 Nov copy, so the result today is **0 stays**, with a message that says why. The filter reads the listing description for "day use", "day-use", "day tour", "day pass" or "day guest". Returning to Any removes it.

So Overnight and Day use are not real classifications yet. That is a gap in the data, not something I invented around. See the question in section 8.

## 6. Dates and guests

From and To are optional, checked (a real date, To not before From, not in the past on the card) and carried to the stays page as "Check-in" and "Check-out" chips. Guests are four + / - counters starting at 0, never below 0 or above 20, with a total ("Any group size" at 0). They are carried as chips. The filter panel on the stays page keeps them when another filter is applied.

**None of the three filters the results.** The site has no availability, room capacity or booking data, and children's ages are not used anywhere, so no age inputs were added. The card says so under the boxes.

## 7. Other sources used as they are

- Interests: `ExitSurveyController::ACTIVITIES` (8 existing values), the list the card already used; a test checks the card shows exactly those plus "Any interest".
- Cuisines: the distinct `cuisine_type` values of visible restaurants (as before).
- Where: the existing provinces and cities (`regions`), default "Anywhere in Region XI".
- No recommendation, Apriori, itinerary, authentication or database code was touched.

## 8. Not supported, and one question

| Asked for | Status |
|---|---|
| Overnight / Day use as real classes | Not in the data (section 5). Overnight = all lodging; Day use reads text, finds none today. **Question:** do you want a `stay_mode` detail added to listings (migration, partner-portal field, DOT data), or keep it as is until the data exists? |
| Dietary preference, Plated / Buffet | Not stored on restaurants. Not shown. |
| Dining budget per person in pesos | Restaurants carry only a price level. The dropdown offers the three levels. |
| Souvenir category and product price | No such columns on `souvenir_centers`. Souvenirs filter by place only. |
| Package travel dates, traveler mix | No availability or per-traveler rules. Packages get a traveler count that scales the total. |
| Stay dates and guests as filters | No availability or capacity data. Collected and displayed only. |
| Province, then city, as two steps | The `regions` table is one flat list of provinces and cities; kept as one selector. |
| The budget tiles on the listing pages' filter panel | Still the existing three-level tiles (they are the listing pages, not the hero card); say if you want them changed too. |

## 9. Files

Changed: `resources/views/welcome.blade.php`, `public/js/hero-search.js`, `public/css/hero-search.css` (and its `.min.css`), `app/Http/Controllers/{Search,Home,Destination,Accommodation,Restaurant,Package}Controller.php`, `app/Support/ActiveFilters.php`, the four listing views (`destinations`, `accommodations`, `restaurants`, `packages` `index.blade.php`: carry the new answers through the filter panel; the stays empty message), `tests/Feature/HeroSearchTest.php`.

New: `app/Support/BudgetRanges.php`, `app/Support/MealTimes.php`, `tests/Feature/DiscoveryCardTest.php`, this note, `docs/discovery-screenshots/`.

## 10. Run it locally

```bash
php artisan serve --port=8011   # from the branch's folder, with a local database
```

Open `http://localhost:8011/`, pick each tab, try Custom budget with a minimum above the maximum, and press Explore. Tests: `php vendor/bin/phpunit tests/Feature/DiscoveryCardTest.php tests/Feature/HeroSearchTest.php`.

## 11. Plan My Trip, step 1 ("Tell us about your trip"), made less confusing

Same fields, names and server behaviour; only wording, folding and clutter changed (`partials/plan-trip-basics.blade.php`, `plan/preferences.blade.php`, `public/js/plan-collapse.js`, `public/css/plan-trip-layout.css`).

- "Dates & arrival" is now **"When are you visiting?"**, with "Only the number of days is required. Everything else is optional." and "Quick pick" reads "Or pick quickly".
- The Arrival time box says in plain view what it does: "Helps us plan your first day. Leave it blank and we assume 8:00 AM."
- The whole Departure box (time, "Leaving from", airport/seaport/bus shortcuts) is **folded by default** behind one row, "I have a flight or bus to catch (Optional)". It opens by itself if it already holds an answer or an error, and on every screen size (before it was only folded on phones).
- "Where you start" is **"Where will you start each day?"** with one line of help. The "Start somewhere familiar" box (which repeated the heading) is gone, and the two overlapping privacy sentences are one: "Private: rounded to about 100 metres, never linked to your name, and cleared when you clear the trip."
- "Where you're from" is now **"About you"**.
- Wording the tests pin (the "Arrival time (optional)" label, "We allow 2 hours there before you leave.") was kept. Plan-related tests: 278 pass.

## 12. Accreditation mark on the card picture

On every listing card (destinations, stays, restaurants, and the same shared card for packages, souvenir centers and tour operators) the "DOT Accredited / Sample Data / Not Yet Verified" text line is replaced by a small mark in the bottom-right corner of the picture (`partials/listing-card.blade.php`, `.dcard__mark` in `public/css/app.css`):

- **Official DOT record**: the DOT medal (your image, cropped and resized to `public/images/dot-accredited-badge.webp`, 7 KB), about 30 px.
- **Sample data or no record on file**: a small gray "i" in a pale round chip (so it shows on any photo).
- Hover or screen reader: the full wording ("DOT Accredited", "Sample Data", "Not Yet Verified" plus the explanation) from `SampleData::label()/title()`. The status logic itself is unchanged.
- The rating stays in the card body; the tag row's left side is now empty. Tests in `ProvenanceStatusTest` now check the mark's label instead of the old text line.
