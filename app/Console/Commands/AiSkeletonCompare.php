<?php

namespace App\Console\Commands;

use App\Models\Itinerary;
use App\Models\PreferenceActivity;
use App\Models\TouristPreference;
use App\Services\Chatbot\GroqChatbotClient;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Support\ItineraryFollowability;
use App\Support\OfflineSkeletonClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Proves the AI-assisted skeleton stage is bounded, on the 96 fixed FollowabilityReport scenarios.
 *
 * Each trip is generated three ways inside a transaction that is rolled back (nothing is saved, safe on any database):
 *   1. baseline       the stage switched off
 *   2. AI-assisted    an OFFLINE stand-in answers with a deliberately different but valid grouping (never the network)
 *   3. AI failed      the stand-in throws, like a timeout
 * and checks the invariants the stage promises. It only reports; the exit code is non-zero only if an invariant breaks.
 */
class AiSkeletonCompare extends Command
{
    protected $signature = 'itinerary:ai-skeleton-compare
        {--set=all : calibration (trips 0-47), holdout (trips 48-95) or all (0-95)}
        {--details : List every trip}';

    protected $description = 'Compare baseline vs AI-assisted ordering on the FollowabilityReport scenarios with an offline stand-in (no network, nothing saved).';

    public function handle(ItineraryFollowability $followability): int
    {
        $set = (string) $this->option('set');
        $indexes = match ($set) {
            'calibration' => range(0, 47),
            'holdout' => range(48, 95),
            'all' => range(0, 95),
            default => null,
        };
        if ($indexes === null) {
            $this->error('Choose --set=calibration, --set=holdout or --set=all.');

            return self::FAILURE;
        }

        $savedConfig = [config('services.groq.key'), config('ai-skeleton.enabled')];
        $start = now()->addDays(14)->toDateString();
        $tally = ['trips' => 0, 'asked' => 0, 'applied' => 0, 'changed_plan' => 0, 'fewer_stops' => 0, 'new_destinations' => 0, 'more_late_checkins' => 0, 'failed_differs' => 0];
        $rows = [];

        try {
            foreach ($indexes as $i) {
                $s = FollowabilityReport::scenario($i);
                [$label, $lat, $lng] = $s['origin'];
                DB::beginTransaction();
                try {
                    $preference = TouristPreference::create([
                        'travel_days' => $s['travel_days'], 'travel_type' => $s['travel_type'], 'travel_purpose' => $s['travel_purpose'],
                        'visitor_type' => $s['visitor_type'], 'budget' => $s['budget'], 'accommodation_pref' => 'Any',
                        'distance_pref' => $s['distance_pref'], 'start_date' => $start, 'arrival_time' => $s['arrival_time'],
                        'origin_lat' => $lat, 'origin_lng' => $lng, 'origin_label' => $label,
                    ]);
                    foreach ($s['activities'] as $activity) {
                        PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => $activity]);
                    }
                    $preference->load('activities', 'amenities');

                    $base = $this->generateWith($preference, $lat, $lng, 'off');
                    $ai = $this->generateWith($preference, $lat, $lng, 'reverse');
                    $failed = $this->generateWith($preference, $lat, $lng, 'fail');

                    $baseFigures = $followability->analyse($base['trip']->fresh(), $s['distance_pref']);
                    $aiFigures = $followability->analyse($ai['trip']->fresh(), $s['distance_pref']);

                    $tally['trips']++;
                    $tally['asked'] += $ai['calls'] > 0 ? 1 : 0;
                    $applied = $ai['trip']->fresh()->ml_skeleton_applied === true;
                    $tally['applied'] += $applied ? 1 : 0;
                    $tally['changed_plan'] += $ai['signature'] !== $base['signature'] ? 1 : 0;
                    $fewer = $aiFigures['coverage']['placed'] < $baseFigures['coverage']['placed'];
                    $tally['fewer_stops'] += $fewer ? 1 : 0;
                    $new = array_diff($ai['placed'], $base['matches']) !== [];
                    $tally['new_destinations'] += $new ? 1 : 0;
                    $late = $aiFigures['late_checkins']['count'] > $baseFigures['late_checkins']['count'];
                    $tally['more_late_checkins'] += $late ? 1 : 0;
                    $tally['failed_differs'] += $failed['signature'] !== $base['signature'] ? 1 : 0;

                    $rows[] = [$i, $s['distance_pref'], $s['travel_days'], $ai['calls'] > 0 ? ($applied ? 'applied' : 'declined') : 'not asked',
                        $baseFigures['coverage']['placed'].' -> '.$aiFigures['coverage']['placed'],
                        $baseFigures['late_checkins']['count'].' -> '.$aiFigures['late_checkins']['count']];
                } finally {
                    DB::rollBack();
                }
            }
        } finally {
            config(['services.groq.key' => $savedConfig[0], 'ai-skeleton.enabled' => $savedConfig[1]]);
            app()->forgetInstance(GroqChatbotClient::class);
        }

        if ($this->option('details')) {
            $this->table(['trip', 'range', 'days', 'AI suggestion', 'stops placed', 'late check-ins'], $rows);
        }

        $this->line("<options=bold>AI-assisted skeleton vs baseline, {$tally['trips']} trips ({$set}). Offline stand-in, nothing saved.</>");
        $this->table(['Check', 'Count'], [
            ['Trips', $tally['trips']],
            ['Trips where the stand-in was asked', $tally['asked']],
            ['  suggestion kept (plan shaped by it)', $tally['applied']],
            ['  plan differs from baseline', $tally['changed_plan']],
            ['INVARIANT: AI plan places fewer stops than baseline', $tally['fewer_stops']],
            ['INVARIANT: AI plan places a destination outside the ranked candidates', $tally['new_destinations']],
            ['INVARIANT: AI plan has more late check-ins than baseline', $tally['more_late_checkins']],
            ['INVARIANT: failed call differs from baseline', $tally['failed_differs']],
        ]);

        $broken = $tally['fewer_stops'] + $tally['new_destinations'] + $tally['more_late_checkins'] + $tally['failed_differs'];
        $this->line($broken === 0 ? 'All invariants hold.' : "<error>{$broken} invariant violation(s).</error>");

        return $broken === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{trip: Itinerary, signature: array, placed: array, matches: array, calls: int}
     */
    private function generateWith(TouristPreference $preference, float $lat, float $lng, string $mode): array
    {
        $stub = $mode === 'off' ? null : new OfflineSkeletonClient($mode);
        if ($stub) {
            app()->instance(GroqChatbotClient::class, $stub);
            config(['services.groq.key' => 'offline-stub', 'ai-skeleton.enabled' => 'true']);
        } else {
            config(['ai-skeleton.enabled' => 'false']);
        }

        $trip = app()->make(ItineraryGenerationService::class)->generate($preference, $lat, $lng);
        $items = $trip->items()->orderBy('day_number')->orderBy('sort_order')->get();

        return [
            'trip' => $trip,
            'signature' => $items->map(fn ($r) => [$r->day_number, $r->sort_order, $r->kind, $r->destination_id, $r->title])->all(),
            'placed' => $items->where('kind', 'activity')->whereNotNull('destination_id')->pluck('destination_id')->unique()->values()->all(),
            'matches' => $trip->matches()->pluck('destination_id')->all(),
            'calls' => $stub?->calls ?? 0,
        ];
    }
}
