<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keeps accreditation status and public listing visibility in sync with expiration_date (Sec. 2.2.3.1.7)
Schedule::command('accreditation:sync-status')->daily();

// Retention (RA 10173): anonymous itineraries, their preferences, and chatbot questions are deleted after a fixed number of days.
Schedule::command('privacy:purge-anonymous-data')->dailyAt('03:00')->withoutOverlapping();

// Removes surveys submitted from /exit-survey?test=1 (and, by cascade, their places and activities).
Artisan::command('exit-survey:purge-test', function () {
    $deleted = \App\Models\ExitSurvey::withoutGlobalScopes()->where('data_source', 'test')->delete();
    $this->info("Removed {$deleted} test survey(s).");
})->purpose('Delete exit surveys submitted in test mode');
