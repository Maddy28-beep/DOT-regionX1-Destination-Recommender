<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    \Illuminate\Support\Facades\DB::table('visitor_origin_receipts')
        ->where('day', '<', now('Asia/Manila')->subDays(30)->toDateString())->delete();
})->name('privacy:purge-origin-receipts')->dailyAt('03:15')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keeps accreditation status and public listing visibility in sync with expiration_date (Sec. 2.2.3.1.7)
Schedule::command('accreditation:sync-status')->daily();

// Retention (RA 10173): anonymous itineraries, their preferences, and chatbot questions are deleted after a fixed number of days.
// Its output is also appended to storage/logs/privacy-purge.log (one timestamped block per run), so the last run is easy to confirm.
Schedule::command('privacy:purge-anonymous-data')->dailyAt('03:00')->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/privacy-purge.log'));

// Counts the "people who visit A also visit B" pairs once a night, so suggestions can be read from a table instead of
// being re-counted from every recorded trip (read only while the association_rules_table switch is on).
Schedule::command('association-rules:build')->dailyAt('02:30')->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/association-rules.log'));

// Removes surveys submitted from /exit-survey?test=1 (and, by cascade, their places and activities).
Artisan::command('exit-survey:purge-test', function () {
    $deleted = \App\Models\ExitSurvey::withoutGlobalScopes()->where('data_source', 'test')->delete();
    $this->info("Removed {$deleted} test survey(s).");
})->purpose('Delete exit surveys submitted in test mode');
