<?php

/*
 * Data retention (RA 10173, Data Privacy Act of 2012).
 *
 * ExploreDVO has no tourist accounts by default, so a trip plan is anonymous
 * personal data: where someone starts from, their dates, and sometimes health or
 * accessibility answers. It is only needed while the traveller is planning the
 * trip, so it is deleted after a fixed time instead of being kept forever.
 * A plan the traveller deliberately saved to their own optional account is kept
 * until they delete it.
 *
 * Run by `php artisan privacy:purge-anonymous-data`, scheduled daily.
 */
return [
    // Days an anonymous itinerary (and the preferences it was built from) is kept.
    'retention_days' => (int) env('PRIVACY_RETENTION_DAYS', 30),

    // Days chatbot questions are kept; people sometimes type personal details into a chat box.
    'chatbot_retention_days' => (int) env('PRIVACY_CHATBOT_RETENTION_DAYS', 30),

    // Hours a trial-mode (?test=1) exit survey is kept before it is cleared automatically.
    'test_survey_hours' => (int) env('PRIVACY_TEST_SURVEY_HOURS', 24),
];
