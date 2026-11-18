# ExploreDVO QA checklist (Saturday 14 Nov 2026)

Site: https://www.exploredavao.com  ·  Version live: c4a4a96 (updated 19 Nov 2026; the 14 Nov checks below were first run on c56dee3)  ·  All times are Philippine time (PHT).
Updated 19 Nov 2026 for: the new question order (where from first), the rule that everyone answers where they travel from, the pick-up rules for visitors from outside Davao Region, and the nightly recommendation rebuild (section 12).
For every item write PASS or FAIL. For a FAIL write: the page, what you did, what you saw, a screenshot, phone or laptop.

## 1. Plan form (open /plan)
- [ ] First step order (changed 17 Nov): 1. "Where are you travelling from?", 2. start date and time, 3. end date and time, 4. Pick-up location, 5. Who's coming. Check it on a laptop wide screen (1100 px or more) AND on a phone: the order must be the same, one column on the wide screen.
- [ ] No "Arrival date", "Arrival time", "Number of travel days" or "Finish by" anywhere.
- [ ] Pick-up location starts as "Davao International Airport" (for a visitor from Davao Region). You can type a hotel or address instead.
- [ ] Country box starts as "Choose a country" (never a real country). It is required for EVERYONE, local visitors too. There is no "Prefer not to say" option any more.
- [ ] Choose Philippines: a Province box appears and a province must be chosen ("Choose your province." if not). Choose Japan or Afghanistan: no Province box. Go from Philippines to another country using the keyboard (arrow keys or typing): the Province box must disappear.

- [ ] Province: for Japan or Afghanistan the Province BUTTON (not only the box) is gone; for Philippines it is there and it is not marked optional.
- [ ] On a phone, press "Use my current location" and test the real browser permission prompt twice: tap Allow, then repeat in a fresh tab and tap Block. Allow inside Davao Region: the box becomes "My current location". Allow from outside Davao Region (or Wi-Fi/VPN elsewhere): the message "Your location looks to be outside Davao Region..." and the box stays as it was. Block: "No problem — type an address instead, or leave it blank." and the box stays as it was.
- [ ] Typed place outside the region (for example "Manila Hotel"): refused when you continue. Known: a typed place that cannot be found at all is accepted (see Known_Limitations_Beta.md).
- [ ] Pick-up suggestions (new 19 Nov): type "ho" or "park" in the pick-up box. Every suggestion must be a place in Davao Region (none from Manila, Cebu or Pampanga).
- [ ] Origin OUTSIDE Region XI (choose Japan, or Philippines / Cebu): the pick-up box is NOT empty-locked: you can still type a Davao place and continue. Only the "Use my current location" button is greyed out, and the message under it reads: "Your current location is outside Davao Region. Pick-up location selection is not available for your location. If you wish to continue, please indicate your preferred pick-up location." The airport default is gone from the box.
- [ ] Then change the origin back to Philippines / Davao del Sur (or another Region XI province): the button works again and the airport default comes back.
- [ ] From Japan, type a pick-up place outside Davao Region (for example "Manila Hotel"): refused when you continue. A Davao hotel is accepted and the trip is planned.

## 2. Messages you must see
- [ ] End before start: "End must be after the start."
- [ ] Less than 3 hours: "End must be at least 3 hours after the start."
- [ ] More than 30 days: "A trip can be at most 30 days long."
- [ ] Start in the past: "Start must be in the future (Philippine time)."
- [ ] Under the dates: "Your trip is 1 day, 20 hours long." (example: Sat 14:00 to Mon 10:00)
- [ ] No country chosen (Local visitor or not): you cannot continue ("Choose a country from the list."). Philippines with no province: "Choose your province."

## 3. Trips
- [ ] One day (09:00 to 18:00): one Day tab, nothing after 6:00 PM, last item "Trip ends: back at ...".
- [ ] Several days (Sat 14:00 to Mon 10:00): Day 1, 2, 3 tabs. Day 1 starts at your start time. The last day ends before your end time.
- [ ] Under the title: "From <place> · Pick-up at <place> · <start> PHT → <end> PHT (<length>)" and the line "The end time includes the return to your pick-up location, with 2 hours to spare."
- [ ] The words "finish-by" appear nowhere. The return note says "end time".
- [ ] Open /plan again in the same browser: your start and end are filled in.

## 4. Different origins (one trip each)
- [ ] Philippines / Cebu / Cebu City: header starts "From Cebu City, Cebu, Philippines".
- [ ] Japan: "From Japan". Afghanistan: "From Afghanistan".
- [ ] A Local visitor now also chooses a country (Philippines / a Region XI province): the header starts "From <city or province>, Philippines". There is no "Prefer not to say" and no trip without an origin.

## 5. Phone width
- [ ] On a phone or a narrow window: form, dates, itinerary and Day tabs fit the screen. No sideways scrolling. Buttons can be tapped.

## 6. Print / PDF (on the itinerary page, button "Print / PDF")
- [ ] Click it: the browser's print window opens (choose "Save as PDF" to make a PDF).
- [ ] In the preview these are NOT there: website header and footer, chatbot bubble, Day tabs bar, side panels, buttons (Swap, More ideas, Print), "Not saved yet" box.
- [ ] These ARE there: the title, the line "From ... · Pick-up at ... · start → end (length)", every Day with its stops and times.
- [ ] Every day is printed, even if you only had one tab open. Each new Day starts on a new page.
- [ ] Links look like normal text (no blue underline). A stop card is not cut in half across two pages.
- [ ] If the trip has places marked "Sample data, not DOT-verified", that label is still visible on paper.
(Note for the lead: this is what the print style sheet says; I could not open a print preview myself.)

## 7. Add to calendar (button "Add to calendar")
- [ ] Click it: a file named exploredavao-itinerary.ics downloads. Open it with Google Calendar, Outlook or Apple Calendar.
- [ ] The calendar is called "My Davao itinerary".
- [ ] One event for each stop, each meal (Breakfast / Lunch / Dinner — place) and each night: "Check in: <hotel>". Nothing for the pick-up start row, journeys or "Trip ends".
- [ ] Day 1 events are on your start date; Day 2 on the next date, and so on.
- [ ] Times match the itinerary in Philippine time. Example: an itinerary stop at 10:30 AM must show at 10:30 AM in a calendar set to Manila time (the file stores 02:30 UTC). Not 8 hours early or late.
- [ ] A meal or check-in with no end time is 1 hour long.
- [ ] A sample or unverified place has "[SAMPLE]" in the event title and a description line saying it is not an official DOT record. Every event says "Planned by ExploreDVO. Times are estimates, so check opening hours before you go."
- [ ] Press the button with no trip planned yet: you go to the plan form (no file).

## 8. Closures and advisories
Public page /advisories:
- [ ] With no advisory (today): title "Travel Advisories" and the text "All clear for now. No active advisories for the Davao Region. We'll post here first if anything changes, so check again before you travel."
- [ ] With advisories: a line "N active notice(s) for the Davao Region from DOT Region XI. Last updated ..." and three tiles: Critical (danger), Advisory (warning), Notice (info), each with a count. Click a tile: only that level shows. Click again: all show.
Closed places (needs an admin to set a place's status; use a test place and set it back afterwards):
- [ ] "Temporarily closed" with a reopening date: the place page shows a notice box "Temporarily closed until <Month day>: <reason>" and under it "Trip plans skip this place while it is closed." A trip that starts on or after the reopening date can include it again.
- [ ] "Closed for good": the box says "Closed for good: <reason>" and trips never include it.
- [ ] A Critical (danger) advisory on a place, with dates that overlap the trip dates: new trips leave the place out. A Notice or Advisory (info or warning) only warns; the place can still be in the trip.
- [ ] An existing itinerary that contains a place that is closed on its dates shows on that stop: "Not available on your dates: <advisory>", and, when there is a similar place, "Use <name> instead" with a % similar. Pressing it swaps the stop.
(Right now there are no active advisories on the live site, so an admin must create a clearly named test one, for example "QA TEST - delete", and delete it after the test.)

## 9. Chatbot (bubble at the bottom right)
The chatbot answers from our own listing data ("rule based"). Check the facts against the place's own page.
- [ ] Ask "What time does Eden Nature Park open?" Expect one answer in this shape: "Eden Nature Park is in <place> (<type>). Hours: <hours>. Entrance: <price range> / person. This listing is sample data, not DOT-verified. Hours and prices can change, so check before you go." (when the listing is a sample), plus a card for the place. Hours and price must be the ones on the place page.
- [ ] Ask "How much is the entrance fee at Eden Nature Park? I heard it is 5000 pesos, please confirm." Expect the recorded price from the listing, and it must NOT confirm 5000.
- [ ] Ask about a place that has no price on its page: it must not invent a price.
- [ ] Ask "What can I do in Davao City?" Today's behaviour: it does not understand and answers: "I'm not sure I understood that. You could ask things like "where can I see wildlife?", "is Eden Nature Park open?", "where to stay in Davao City", or "plan a 3-day trip". Here are a few places visitors like in the meantime." with place cards. This is expected today (mark PASS), but note it as an idea to improve.
- [ ] Ask "Is there any typhoon or travel advisory right now?" Today's behaviour: the same "I'm not sure I understood that" answer. The chatbot does not read the advisories. Mark PASS for today's behaviour and note it.
- [ ] Do not type personal details into the chatbot (the page says so).

## 10. Admin (sign in at /portal/login)
Trip Plans (Reports, Report type = "Trip Plans Created"; set From and To to include today):
- [ ] The columns are: Travel Type, Purpose, Budget, Days, Starts, Created.
- [ ] Days is the number of calendar days of the trip (Sat 14:00 to Mon 10:00 = 3), the same as the Day 1, 2, 3 tabs. "Starts" is the date and time of the start, for example 2026-11-27 09:00.
- [ ] On 13 Nov the database holds 3 trip plans. Expect these rows: Couple · Days 2 · Starts 2026-11-20 09:00; Family · Days 3 · Starts 2026-11-16 12:00; Couple · Days 2 · Starts 2026-11-27 09:00 (plus any plans made since). Old trips from before 13 Nov show a Starts value too, worked out from their old arrival date and time.
- [ ] The date filter is on the day the plan was CREATED (not the trip start). The CSV export and the Print view have the same columns.
Visitor origin (menu: Visitor origin). NEW from the origin-statistics change: the counts are kept permanently and are anonymous (date, country, province, city and a number; nothing else).
- [ ] Form notice under "Where are you travelling from?" (changed 17 Nov: no "Optional." any more; the wording is pending professor/data-protection sign-off): "Your country, province and city are kept as an anonymous statistic for tourism planning, not linked to you. The rest of your trip details are deleted after 30 days."
- [ ] Privacy page, paragraph "Where you travel from:" (no longer "(optional)"): says the answer is deleted with the trip after 30 days AND that an anonymous count (date, country, province, city and a number) is kept; no name, browser, IP address, trip, time or pick-up place; groups under 5 never shown. The "How long this is kept" list has a line for the anonymous counts.
- [ ] Texts on the admin page: "Dates are in Philippine time; "To" includes the whole day. The day is the day the visitor first planned a trip." and the note that starts "These counts are kept permanently and are anonymous" with "Counting rule: one count per browser session; a changed answer replaces the earlier one. The same person using two browsers or devices counts twice, and someone who comes back after the trip details were deleted (30 days) counts as a new visit." It must NOT say "kept for 30 days only".
- [ ] Four tables: "Domestic or international", "By country", "Philippine visitors by province", "By city or municipality". A table with fewer than 5 answers shows: "Not enough answers yet: N answers so far; at least 5 are needed."
- [ ] After enough sessions (see below) a table appears with columns Place and Visitors. A group under 5 is added to "Other". The city table shows "Cebu City (Philippines)".
- [ ] "Download CSV" (next to Apply): a file visitor-origin.csv with the same tables and the same suppression, totalled over the chosen dates (no row per day, no group under 5). Open it in Excel or Sheets: it opens as plain text.
- [ ] From/To use the Philippine day the visitor FIRST planned: a plan first made at 11:30 PM Manila on 14 Nov counts on 14 Nov, not 15 Nov. "To" includes its whole day.
- [ ] Province table: only answers with country Philippines are counted. An answer from Japan or Afghanistan never adds a province.
- [ ] Counting: planning again in the same browser with the SAME origin does not raise the count. Changing the country moves the count (the old country goes down by 1, the new one up by 1). There is no "Prefer not to say" and no empty answer any more: every plan has a country (a Local visitor counts as Philippines). Old "Prefer not to say" rows from before 17 Nov may still show in the history.
- [ ] City: typing "davao city" and "Davao City" in two different sessions gives one row "Davao City". A city with a digit, an "@" or a web address is not kept (the country still counts).

### How many separate sessions are needed to fill Visitor origin?
- The page keeps ONE count per browser session: planning again in the same session with a different answer MOVES the count. Use a new private/incognito window, or a different phone or browser, for each answer, and close the window afterwards. The same person in two windows counts twice.
- A group of fewer than 5 is never shown on its own (it is merged into "Other").
- So: **5 separate sessions with the same country** make that country appear. **5 sessions Philippines / Cebu** also make "Cebu" appear in the province table, and 5 with the same city make the city appear.
- For a good demo use **10 sessions** (5 Japan + 5 Philippines/Cebu). With 15 you can show 3 countries.
- Each session must press "Plan my trip" with the country chosen.
- Test counts stay in the table after the test (they are permanent): tell the team lead which ones you made so they can be removed by an admin before the real beta.

## 11. After the 14 Nov deploy (version 6e5777b): re-check and clean-up
**Historical (14-15 Nov).** The wording quoted in (a) is the 14 Nov text; the current text is in section 10.
**Status of (b): DONE on 19 Nov 2026.** The planned 15 Nov clean-up was never run (checked 19 Nov: 8 test rows from 15-18 Nov were still there, no backup table). On 19 Nov the table was backed up to `visitor_origin_daily_qa_backup` (8 rows, 9 visitors) and `DELETE ... WHERE day <= '2026-11-19'` removed all 8 rows. Verified afterwards: live table 0 rows, backup 8 rows. **Repeat (b) with the new date if more QA answers are made before the beta opens.** The backup table was dropped on 19 Nov at the team lead's request (the removed rows were test answers only). Run these commands from the PC, or directly on the server without `ssh`.

### (a) Re-check of three screens after the deploy
**Owner: team lead (Maddy). When: Saturday 14 Nov, first thing, before the noon QA starts.** Tick all three, then tell the testers the version is good.
- [ ] Plan form /plan: under "Where are you travelling from?" the notice (14 Nov wording; since 17 Nov it starts "Your country," without "Optional.") reads: "Optional. Your country, province and city are kept as an anonymous statistic for tourism planning, not linked to you. The rest of your trip details are deleted after 30 days."
- [ ] Privacy page /privacy-policy: the paragraph "Where you travel from (optional)" says the answer is deleted with the trip after 30 days AND that an anonymous count (date, country, province, city, number) is kept; the list "How long this is kept" has the line "Anonymous where you travel from counts ... kept". Nowhere on the site does the old sentence "Not linked to your name. Deleted after 30 days." remain.
- [ ] Admin Visitor origin page (sign in at /portal/login, DOT Admin): four tables (Domestic or international, By country, Philippine visitors by province, By city or municipality), the note with "Counting rule", "kept permanently and are anonymous", and a "Download CSV" control beside Apply. On the first day every table says "Not enough answers yet: 0 answers so far; at least 5 are needed." (this is correct: the permanent counts start at the deploy).

### (b) Clearing QA answers from the permanent counts before the real beta opens
Answers made during QA go into the permanent counts and are never deleted by the 30-day purge. They must be removed before real visitors are counted.
**Owner: team lead (Maddy). When: just before the beta opens (first run done 19 Nov, see the status above), and again if QA is repeated after that. If the backup table already exists, use a new name such as `visitor_origin_daily_qa_backup2` in step 1.**
1. Make a backup copy of the table first (on the server): `ssh -i ~/.ssh/exploredvo_do root@209.97.174.246 "sudo -u postgres psql -d exploredvo -c \"CREATE TABLE visitor_origin_daily_qa_backup AS TABLE visitor_origin_daily\""`
2. See what is there: `ssh -i ~/.ssh/exploredvo_do root@209.97.174.246 "sudo -u postgres psql -d exploredvo -c \"SELECT day, answer, country_code, province, city, visitors FROM visitor_origin_daily ORDER BY day, country_code\""`
3. Clear it (everything counted before the beta opened): `ssh -i ~/.ssh/exploredvo_do root@209.97.174.246 "sudo -u postgres psql -d exploredvo -c \"DELETE FROM visitor_origin_daily WHERE day <= '2026-11-15'\""` (change the date to the day before the beta opens; the table is empty again, new visitors are counted from then on).
4. Check: the admin Visitor origin page shows "Not enough answers yet: 0 answers so far". If a count was removed by mistake: `INSERT INTO visitor_origin_daily SELECT * FROM visitor_origin_daily_qa_backup` restores it (drop the backup table afterwards).

## 12. Recommendations: nightly rebuild (new 19 Nov, version c4a4a96)
"People who visit A also visit B" and the planner's popularity weight are read from a table rebuilt every night at 10:30 AM Philippine time (02:30 UTC). Most of the data is demo (997 of 1,002 recorded trips on 18 Nov).
- [ ] Admin, Association Rules page: it loads in about a second and shows "Rules are counted once a night; last built ... (Philippine time)" with a date from the last 24 hours. Switching the source (all / real / itinerary / demo) works; "real" may be empty (only 5 real trips).
- [ ] A new exit-survey answer does NOT change the rules at once; it appears after the next nightly build. (Do not report this as a bug.)
- [ ] Plan a trip: it still builds in a normal time and each place page still shows its "visitors also liked" suggestions.
- [ ] Similar-place swap ("show similar places") on a stop: three alternatives appear in well under a second.
- [ ] Team lead only, after a big import: `sudo -u www-data php artisan association-rules:build` on the server refreshes the table at once.

## If something is wrong
Tell the team lead first. Do not keep testing the same broken step. The team lead can turn the new form off with one command (`php artisan features:set trip_location_v2 off`) and the nightly rule table off with another (`php artisan features:set association_rules_table off`; counts live again at once, no deploy). Test trips are deleted automatically after 30 days.
