<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keeps accreditation status and public listing visibility in sync with expiration_date (Sec. 2.2.3.1.7)
Schedule::command('accreditation:sync-status')->daily();
