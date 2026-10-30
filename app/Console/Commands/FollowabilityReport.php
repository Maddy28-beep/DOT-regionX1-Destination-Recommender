<?php

namespace App\Console\Commands;

use App\Models\PreferenceActivity;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Support\ItineraryFollowability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * "Would a tourist actually follow these itineraries?" as a report, for one fixed set of 48 trips at a time, with Close
 * Proximity, Commutable and Remote kept apart.
 *
 * It only reports. No figure here changes a result, fails the run or moves a stop, and there is no option to make it do so:
 * the bands it counts (a longer drive, a long driving day, a long idle block, a late check-in) are descriptions, not rules.
 *
 * Calibration and held-out are two sets and are run separately, never added together. The held-out set is for REPORTING
 * what a change did to trips nobody tuned on; nothing here feeds a number back into any setting.
 *
 * Every trip is created inside a transaction and rolled back, so a run keeps nothing and is safe on any database.
 */
class FollowabilityReport extends Command
{
    protected $signature = 'itinerary:followability
        {--set= : calibration (trips 0-47) or holdout (trips 48-95); required, one per run}
        {--json= : Also write the figures to this file}
        {--details : List every trip}';

    protected $description = 'Report how followable a fixed set of generated itineraries is, per distance range (nothing is saved, nothing is enforced).';

    private const BANDS = ['near' => 'Close Proximity', 'moderate' => 'Commutable', 'far' => 'Remote'];

    private const ORIGINS = [['Ma-a, Talomo, Davao City', 7.0489, 125.5736], ['Poblacion, Davao City', 7.0645, 125.6070], ['Lanang, Davao City', 7.1042, 125.6458]];

    private const INTERESTS = [[], ['Nature & Adventure'], ['Beach & Island'], ['Cultural Heritage'], ['Wildlife', 'Nature & Adventure'], ['Hiking & Trekking'], ['Food Tourism', 'Shopping & Souvenirs'], ['Relaxation & Wellness']];

    private const PURPOSES = ['Leisure', 'Leisure', 'Educational', 'Medical', 'Visiting Friends/Family', 'Religious/Pilgrimage', 'Business', 'Leisure'];

    private const ARRIVALS = ['07:00', '09:00', '10:15', '11:00', '12:00', '13:30', '15:00', '08:00'];

    /**
     * Trip number 0-95 as a scenario. Built by arithmetic and never by chance, so two runs are the same trips: 1 to 5 days,
     * the three ranges in turn, eight interest sets, eight arrival times. Calibration trips all start at Ma-a; held-out trips
     * start at three different places.
     *
     * @return array<string, mixed>
     */
    public static function scenario(int $i): array
    {
        $origins = $i >= 48 ? self::ORIGINS : [self::ORIGINS[0]];
        [$label, $lat, $lng] = $origins[$i % count($origins)];

        return [
            'travel_days' => [1, 2, 3, 4, 5][$i % 5],
            'distance_pref' => array_keys(self::BANDS)[$i % 3],
            'travel_type' => ['Family', 'Couple', 'Friends', 'Solo'][$i % 4],
            'travel_purpose' => self::PURPOSES[$i % 8],
            'visitor_type' => ['First-time Visitor', 'Returning Visitor', 'Regular / Local'][$i % 3],
            'budget' => ['Budget-Friendly', 'Mid-range', 'Premium'][$i % 3],
            'arrival_time' => self::ARRIVALS[($i * 3) % 8],
            'activities' => self::INTERESTS[$i % 8],
            'origin' => [$label, $lat, $lng],
        ];
    }

    public function handle(ItineraryGenerationService $generator, ItineraryFollowability $followability): int
    {
        $set = (string) $this->option('set');
        if (! in_array($set, ['calibration', 'holdout'], true)) {
            $this->error('Choose one set: --set=calibration or --set=holdout. They are never run together.');

            return self::FAILURE;
        }

        $indexes = $set === 'holdout' ? range(48, 95) : range(0, 47);
        $start = now()->addDays(14)->toDateString();
        $byBand = ['near' => [], 'moderate' => [], 'far' => []];

        foreach ($indexes as $i) {
            $s = self::scenario($i);
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
                $trip = $generator->generate($preference->load('activities', 'amenities'), $lat, $lng);
                $figures = $followability->analyse($trip->fresh(), $s['distance_pref']);
                $figures['trip'] = $i;
                $byBand[$s['distance_pref']][] = $figures;
            } finally {
                DB::rollBack();
            }
        }

        $summary = array_map(fn (array $trips) => $followability->summarise($trips), $byBand);

        $this->line($set === 'holdout'
            ? '<options=bold>HELD-OUT SET (trips 48-95): report only. These figures are not for tuning anything.</>'
            : '<options=bold>CALIBRATION SET (trips 0-47)</>');
        $this->line('Trips start '.$start.'. Nothing is saved and no figure here is a rule.');
        $this->table(['', ...array_values(self::BANDS)], $this->rows($summary));

        if ($this->option('details')) {
            foreach ($byBand as $band => $trips) {
                $this->line("\n<options=bold>".self::BANDS[$band].'</>');
                foreach ($trips as $t) {
                    $this->line(sprintf('  trip %2d, %d day(s): placed %d, omitted %d, longest day %d min, %d late check-in(s), %d stay change(s)',
                        $t['trip'], $t['days'], $t['coverage']['placed'], $t['coverage']['omitted'], $t['driving']['longest'], $t['late_checkins']['count'], $t['continuity']['changes']));
                }
            }
        }

        if ($path = (string) $this->option('json')) {
            file_put_contents($path, json_encode(['set' => $set, 'start' => $start, 'summary' => $summary, 'trips' => $byBand], JSON_PRETTY_PRINT));
            $this->line("Figures written to $path");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<string, mixed>>  $s  summary per band
     * @return list<array<int, string|int>>
     */
    private function rows(array $s): array
    {
        $col = fn (callable $get) => array_map(fn (string $band) => $get($s[$band]), array_keys(self::BANDS));
        $row = fn (string $label, callable $get) => [$label, ...$col($get)];
        $min = fn (int $m) => $m < 60 ? "$m min" : intdiv($m, 60).' h'.($m % 60 ? ' '.($m % 60).' min' : '');
        $cause = fn (string $c) => fn ($x) => $x['coverage']['causes'][$c] ?? 0;

        return [
            $row('Trips / days', fn ($x) => $x['trips'].' / '.$x['days']),
            $row('COVERAGE: destinations placed', fn ($x) => $x['coverage']['placed']),
            $row('  omitted (every one is listed on the page)', fn ($x) => $x['coverage']['omitted']),
            $row('    would end after the 18:00 cutoff', $cause('would_end_after_cutoff')),
            $row('    the day\'s driving limit was reached', $cause('drive_budget_reached')),
            $row('    the day already held its places', $cause('day_full')),
            $row('    closed / outside opening hours', fn ($x) => ($x['coverage']['causes']['closed_that_day'] ?? 0) + ($x['coverage']['causes']['outside_opening_hours'] ?? 0)),
            $row('    no reason recorded ("not added")', fn ($x) => $x['coverage']['omitted_neutral']),
            $row('DRIVING: average per day', fn ($x) => $min($x['driving']['average_per_day'])),
            $row('  longest day', fn ($x) => $min($x['driving']['longest_day'])),
            $row('  days over 4 h (the page\'s tip)', fn ($x) => $x['driving']['days_over_longer_drive']),
            $row('  days over '.($m = \App\Support\ItineraryDayNotes::longDrivingMinutes() / 60).' h (long driving day)', fn ($x) => $x['driving']['days_over_long']),
            $row('STAYS: nights / changes of stay', fn ($x) => $x['continuity']['nights'].' / '.$x['continuity']['changes']),
            $row('  nights without a stay', fn ($x) => $x['continuity']['nights_without_stay']),
            $row('LATE CHECK-INS (after 19:30)', fn ($x) => $x['late_checkins']['count']),
            $row('  minutes late (total, those with a time)', fn ($x) => $x['late_checkins']['minutes_late']),
            $row('  of which after midnight (no time shown)', fn ($x) => $x['late_checkins']['after_midnight']),
            $row('MEALS: sightseeing days / with lunch', fn ($x) => $x['meals']['sightseeing_days'].' / '.$x['meals']['with_lunch']),
            $row('  no lunch, arrived after the lunch hours', fn ($x) => $x['meals']['no_lunch_arrival_after_window']),
            $row('  no lunch, the page says it is not arranged', fn ($x) => $x['meals']['no_lunch_not_arranged_note']),
            $row('  no lunch, any other reason', fn ($x) => $x['meals']['no_lunch_other']),
            $row('  nights with no dinner (not late)', fn ($x) => $x['meals']['dinner_missing']),
            $row('ARRIVAL / TRANSFER DAYS', fn ($x) => $x['arrival_transfer_days']),
            $row('IDLE: free blocks of 4 h or more / longest', fn ($x) => $x['idle']['blocks'].' / '.$min($x['idle']['longest'])),
        ];
    }
}
