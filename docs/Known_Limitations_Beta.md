# ExploreDVO beta: known limitations (plain language)

For the professor's trial, 9 November 2026. Build: `main` at `04a6f1d` (production, verified on 8 November 2026 by comparing the server's files with that commit). It is the `beta-2026-11-09` build plus four front-end and photo-speed changes (#232 to #235) and docs; the planner, recommender and chatbot logic are unchanged from the beta tag.

1. No booking. The system plans; it does not reserve or pay for anything.
2. No real-time traffic. Drive times are estimates from distance, not live conditions.
3. A trip cannot be edited by hand: no adding or reordering stops. Change the preferences and generate again (a stop can be swapped for a similar place).
4. Companion type (solo, couple, family, group) is recorded but does not change the plan yet.
5. Interest fit is relative within the places left after filtering, not an absolute score.
6. Ratings and popularity have no effect until a place has enough reviews.
7. Association rules ("people who visit X also visit Y") come from simulated data, not real visitors.
8. Partner portal actions are not written to the audit log yet (admin actions are).
9. Some days have a single far-away stop. Across 460 test trips (two seeds) 27 days came out over 8 hours of driving: 15 on Close or Commutable trips and 12 on Remote trips, where long drives are expected. Each shows a visible warning: "About N hours of driving there and back for this one stop, more than a day should hold, so it has a day of its own. Consider staying closer, or spending a night nearby."
10. When a Close or Commutable trip has to widen its range, places that are too far for one day are left out and the page says so: "Some distant places were left out because they are too far for a single day. Choose 'Remote' to include them." On the live site this notice is rare (19 long or sparse-start test trips never triggered it); it is covered by automated tests.
11. Chatbot: asking about a closed place gives a closure notice and no card. It never invents a price or opening hours that are not in the record.
12. Stays: a stay more than 60 km from every stop of the trip is passed over, except on Remote trips.
13. Sample (placeholder) places are labelled "Sample data, not DOT-verified".
14. Package adoption ("Plan with this Package") needs a published day-by-day schedule. At deployment there were 5 live packages and 0 had schedule rows, so every package page offered "Plan My Trip" instead. For the trial two sample packages (Samal Island Hopping Day Tour and Davao City Cultural Heritage Tour) carry one illustrative sample day each, marked "[SAMPLE]"; the other 3 still have none.
15. Test setting to remove after the trial: `ACCREDITATION_TEST_GRACE_UNTIL=2026-12-31` is set on production, so listings whose accreditation expired stay visible and plannable until 31 December 2026. With the setting removed, an expired listing disappears from the public pages (checked on a restored copy). This is deliberate for the trial and must be removed afterwards.
16. People's Park is marked temporarily closed in the data (until the admin reopens it). While it is closed it is left out of trips and the chatbot says so.
17. The package page check ("Plan with this Package" on exactly the two sample packages, "Plan My Trip" with the hint on the other three) was verified on production only by the manual check of 8 November. It has not been re-run on the current build `04a6f1d`; repeat it on the live site before the trial.
