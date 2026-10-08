<?php

namespace App\Console\Commands;

use App\Models\PreferenceActivity;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Measures what theme-based days change, for the manuscript: over many varied
 * trips, how often the days are regrouped, how much more alike the places
 * sharing a day become, and what that costs in travelling. Everything runs
 * inside a rolled-back transaction, so nothing is saved.
 */
class EvaluateThemeDays extends Command
{
    protected $signature = 'itineraries:evaluate-themes {--trips=60 : Number of varied trips to generate}';

    protected $description = 'Report how theme-based day grouping changes similarity within days and travelling, over many trips.';

    private const INTERESTS = ['Beach & Island', 'Nature & Adventure', 'Cultural Heritage', 'Wildlife', 'Food Tourism',
        'Shopping & Souvenirs', 'Hiking & Trekking', 'Relaxation & Wellness'];

    public function handle(ItineraryGenerationService $generator): int
    {
        mt_srand(2026); // same trips every run, so the numbers can be reproduced
        $trips = max(1, (int) $this->option('trips'));
        $rows = [];

        DB::beginTransaction();
        try {
            for ($i = 0; $i < $trips; $i++) {
                $preference = TouristPreference::create([
                    'travel_days' => mt_rand(2, 7), 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure',
                    'visitor_type' => 'First-time Visitor', 'budget' => ['Budget-Friendly', 'Mid-range', 'Premium'][mt_rand(0, 2)],
                    'accommodation_pref' => 'Any', 'distance_pref' => ['near', 'moderate', 'far'][mt_rand(0, 2)],
                    'variation' => 0,
                ]);
                $picked = array_rand(array_flip(self::INTERESTS), mt_rand(1, 3));
                foreach ((array) $picked as $interest) {
                    PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => $interest]);
                }
                $preference->load('activities', 'amenities');

                try {
                    $summary = $generator->generate($preference)->day_themes['summary'] ?? null;
                } catch (\Throwable) {
                    $summary = null;
                }
                if ($summary) {
                    $rows[] = $summary;
                }
            }
        } finally {
            DB::rollBack();
        }

        if ($rows === []) {
            $this->warn('No trip produced a grouping summary. Are destination vectors loaded (embeddings:import)?');

            return self::FAILURE;
        }

        $n = count($rows);
        $regrouped = count(array_filter($rows, fn ($r) => $r['regrouped']));
        $meanBefore = array_sum(array_column($rows, 'mean_similarity_before')) / $n;
        $meanAfter = array_sum(array_column($rows, 'mean_similarity_after')) / $n;
        $typesBefore = array_sum(array_column($rows, 'types_per_day_before')) / $n;
        $typesAfter = array_sum(array_column($rows, 'types_per_day_after')) / $n;
        $guarded = count(array_filter($rows, fn ($r) => ! empty($r['guard'])));
        $kmBefore = array_sum(array_column($rows, 'distance_before_km'));
        $kmAfter = array_sum(array_column($rows, 'distance_after_km'));
        $worst = max(array_map(fn ($r) => $r['distance_before_km'] > 0 ? $r['distance_after_km'] / $r['distance_before_km'] : 1.0, $rows));

        $this->info("Trips evaluated: {$n} of {$trips} generated (the rest had too few stops or days to regroup, or no stored vectors)");
        $this->line(sprintf('Trips whose days were regrouped: %d of %d (%.1f%%)', $regrouped, $n, $regrouped / $n * 100));
        $this->line(sprintf('Average similarity of places sharing a day: %.1f%% -> %.1f%% (+%.1f points)', $meanBefore, $meanAfter, $meanAfter - $meanBefore));
        $this->line(sprintf('Independent check (place types per day, lower = more alike, not using the vectors): %.2f -> %.2f', $typesBefore, $typesAfter));
        $this->line(sprintf('Trips where regrouping was undone because it would have placed fewer stops: %d', $guarded));
        $this->line(sprintf('Total travelling across all trips: %.0f km -> %.0f km (%+.1f%%)', $kmBefore, $kmAfter, ($kmAfter / max(1, $kmBefore) - 1) * 100));
        $this->line(sprintf('Largest single-trip increase in travelling: %+.1f%% (limit +25%% plus %.0f km allowance)', ($worst - 1) * 100, \App\Services\Recommendation\ThemeDayGrouper::DISTANCE_ALLOWANCE_KM));

        return self::SUCCESS;
    }
}
