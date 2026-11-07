# ExploreDVO beta: known limitations (plain language)

1. No booking. The system plans; it does not reserve or pay for anything.
2. No real-time traffic. Drive times are estimates from distance, not live conditions.
3. A trip cannot be edited by hand: no adding or reordering stops. Change the preferences and generate again.
4. Companion type (solo, couple, family, group) is recorded but does not change the plan yet.
5. Interest fit is relative within the places left after filtering, not an absolute score.
6. Ratings and popularity have no effect until a place has enough reviews.
7. Association rules ("people who visit X also visit Y") come from simulated data, not real visitors.
8. Partner portal actions are not written to the audit log yet.
9. Some days have a single far-away stop. 15 such days over 8 hours of driving appeared in 460 test trips (3 of 240 + 12 of 220 on two seeds, near/moderate/far preferences; the exact figure after the final code is in the Part A report). Another 12 are Remote trips, where long drives are expected. Each shows a visible warning: "about N hours of driving there and back for this one stop, more than a day should hold".
10. The planner leaves out places that are too far for one day and says so ("We left out N places that are too far to visit in a day").
11. Chatbot: asking about a closed place shows its card, with a closure notice in the text.
12. Stays: a stay more than 60 km from every stop is passed over, except on Remote trips.
13. Sample (placeholder) places are labelled as samples.
