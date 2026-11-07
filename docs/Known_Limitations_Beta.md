# ExploreDVO beta: known limitations (plain language)

1. No booking. The system plans; it does not reserve or pay for anything.
2. No real-time traffic. Drive times are estimates from distance, not live conditions.
3. A trip cannot be edited by hand: no adding or reordering stops. Change the preferences and generate again.
4. Companion type (solo, couple, family, group) is recorded but does not change the plan yet.
5. Interest fit is relative within the places left after filtering, not an absolute score.
6. Ratings and popularity have no effect until a place has enough reviews.
7. Association rules ("people who visit X also visit Y") come from simulated data, not real visitors.
8. Partner portal actions are not written to the audit log yet.
9. Some days have a single far-away stop. Across 460 test trips (two seeds) 27 days came out over 8 hours of driving: 15 on ordinary trips and 12 on Remote trips, where long drives are expected. Each shows a visible warning: "about N hours of driving there and back for this one stop, more than a day should hold".
10. The planner leaves out places that are too far for one day and says so (the range-cap notice).
11. Chatbot: asking about a closed place gives a closure notice and no card.
12. Stays: a stay more than 60 km from every stop is passed over, except on Remote trips.
13. Sample (placeholder) places are labelled as samples.
