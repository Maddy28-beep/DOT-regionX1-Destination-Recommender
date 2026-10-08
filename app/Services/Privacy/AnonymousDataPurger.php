<?php

namespace App\Services\Privacy;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes anonymous trip data once it is older than the retention period.
 *
 * What is removed:
 *   - anonymous itineraries (no tourist account) generated before the cutoff,
 *     with their stops and rankings;
 *   - the trip preferences those itineraries were built from, with their health and
 *     accessibility answers, unless an exit survey still points at the preference
 *     (a survey needs its trip for the recap) -- in that case the preference is kept
 *     but its sensitive and location fields are cleared;
 *   - chatbot questions older than the chatbot retention period;
 *   - trial-mode (?test=1) exit surveys older than a day.
 *
 * What is never touched:
 *   - itineraries a traveller saved to their own account;
 *   - preferences that no itinerary was built from, which is exactly what the demo
 *     data looks like, so the demo survives;
 *   - real exit surveys, reviews, check-ins, listings and accounts.
 *
 * With $dryRun the same counts are worked out and then rolled back.
 */
class AnonymousDataPurger
{
    /**
     * @return array{itineraries: int, preferences_deleted: int, preferences_scrubbed: int, chatbot_logs: int, test_surveys: int}
     */
    public function purge(CarbonInterface $now, ?int $retentionDays = null, bool $dryRun = false): array
    {
        $retentionDays ??= (int) config('privacy.retention_days', 30);
        $cutoff = $now->copy()->subDays($retentionDays);
        $chatCutoff = $now->copy()->subDays((int) config('privacy.chatbot_retention_days', 30));
        $testCutoff = $now->copy()->subHours((int) config('privacy.test_survey_hours', 24));

        $counts = ['itineraries' => 0, 'preferences_deleted' => 0, 'preferences_scrubbed' => 0, 'chatbot_logs' => 0, 'test_surveys' => 0];

        DB::beginTransaction();
        try {
            // 1. old anonymous itineraries
            $old = DB::table('itineraries')->whereNull('tourist_account_id')->where('generated_at', '<', $cutoff)->get(['id', 'preference_id']);
            $itineraryIds = $old->pluck('id');
            $preferenceIds = $old->pluck('preference_id')->filter()->unique()->values();

            foreach (['itinerary_items', 'itinerary_matches'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->whereIn('itinerary_id', $itineraryIds)->delete();
                }
            }
            $counts['itineraries'] = DB::table('itineraries')->whereIn('id', $itineraryIds)->delete();

            // 2. their preferences, unless something else still uses them
            $stillUsedByItinerary = DB::table('itineraries')->whereIn('preference_id', $preferenceIds)->pluck('preference_id');
            $usedBySurvey = DB::table('exit_surveys')->whereIn('preference_id', $preferenceIds)->pluck('preference_id');
            $deletable = $preferenceIds->diff($stillUsedByItinerary)->diff($usedBySurvey)->values();
            $scrub = $preferenceIds->diff($stillUsedByItinerary)->intersect($usedBySurvey)->values();

            foreach ([$deletable, $scrub] as $ids) {
                $profileIds = DB::table('tourist_health_profiles')->whereIn('preference_id', $ids)->pluck('id');
                DB::table('tourist_health_conditions')->whereIn('health_profile_id', $profileIds)->delete();
                DB::table('tourist_health_profiles')->whereIn('id', $profileIds)->delete();
            }

            foreach (['preference_activities', 'preference_amenities'] as $table) {
                DB::table($table)->whereIn('preference_id', $deletable)->delete();
            }
            $counts['preferences_deleted'] = DB::table('tourist_preferences')->whereIn('id', $deletable)->delete();

            $counts['preferences_scrubbed'] = DB::table('tourist_preferences')->whereIn('id', $scrub)->update([
                'accessibility_notes' => null, 'origin_lat' => null, 'origin_lng' => null, 'origin_label' => null, 'place_of_origin' => null,
            ]);

            // 3. old chatbot questions
            if (Schema::hasTable('chatbot_logs')) {
                $counts['chatbot_logs'] = DB::table('chatbot_logs')->where('created_at', '<', $chatCutoff)->delete();
            }

            // 4. trial-mode exit surveys
            $counts['test_surveys'] = DB::table('exit_surveys')->where('data_source', 'test')->where('submitted_at', '<', $testCutoff)->delete();

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $counts;
    }
}
