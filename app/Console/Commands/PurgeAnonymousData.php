<?php

namespace App\Console\Commands;

use App\Services\Privacy\AnonymousDataPurger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Daily retention job (see config/privacy.php and AnonymousDataPurger for exactly what it removes and keeps). */
class PurgeAnonymousData extends Command
{
    protected $signature = 'privacy:purge-anonymous-data
        {--days= : Keep anonymous trip data this many days (default from config/privacy.php)}
        {--dry-run : Count what would be removed, but remove nothing}';

    protected $description = 'Delete anonymous itineraries, trip preferences and chatbot questions older than the retention period.';

    public function handle(AnonymousDataPurger $purger): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;
        $dry = (bool) $this->option('dry-run');

        $counts = $purger->purge(now(), $days, $dry);

        $this->info(($dry ? '[dry run, nothing removed] ' : '').'Retention '.($days ?? config('privacy.retention_days')).' days.');
        $this->table(['What', 'Count'], [
            ['Anonymous itineraries', $counts['itineraries']],
            ['Trip preferences deleted', $counts['preferences_deleted']],
            ['Trip preferences scrubbed (kept for an exit survey)', $counts['preferences_scrubbed']],
            ['Chatbot questions', $counts['chatbot_logs']],
            ['Trial-mode exit surveys', $counts['test_surveys']],
        ]);

        // One line in the application log, so there is a record that the job ran and what it removed.
        if (! $dry) {
            Log::info('privacy:purge-anonymous-data', $counts);
        }

        return self::SUCCESS;
    }
}
