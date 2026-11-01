<?php

namespace App\Console\Commands;

use App\Models\PreferenceActivity;
use App\Models\PreferenceAmenity;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryAudit;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds a fixed set of trips against the current catalogue and checks each plan (see ItineraryAudit), so "is the
 * itinerary accurate?" has a number: how many of the trips come out clean, and which rule fails most.
 *
 * Every trip is created and rolled back in a transaction, so it is safe to run against the live database: nothing is
 * kept. Run it before and after a change to the planner or to the data to see what moved.
 */
class AuditItineraries extends Command
{
    protected $signature = 'itinerary:audit
        {--scenario= : Run only the scenario whose name contains this text}
        {--extended : Also run the generated set: every distance choice with every way of travelling, one-interest trips, budget extremes and final-day departures}
        {--details : Print why each failing check failed}
        {--min-pass= : Exit with an error when fewer than this percentage of trips are clean}';

    protected $description = 'Build a fixed set of trips and check each itinerary for accuracy (nothing is saved).';

    private const AIRPORT = ['lat' => 7.130, 'lng' => 125.645, 'label' => 'Davao International Airport'];

    private const BASE = ['travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'transport_pref' => 'rental_car', 'accommodation_pref' => 'Any'];

    /**
     * The generated set, built by loops and never by chance, so a run is the same every time: every distance choice
     * with every way of travelling over trips of 1 to 10 days with differing arrivals, budgets and interests; each
     * interest on its own; the budget extremes; and final-day departures (only where the column exists).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function extendedScenarios(): array
    {
        $interests = ['Beach & Island', 'Nature & Adventure', 'Cultural Heritage', 'Wildlife', 'Food Tourism', 'Shopping & Souvenirs', 'Hiking & Trekking', 'Relaxation & Wellness'];
        $modes = ['jeep', 'taxi', 'personal_car', 'tour_operator', 'rental_car'];
        $tiers = ['near', 'moderate', 'far'];
        $budgets = ['Budget-Friendly', 'Mid-range', 'Premium'];
        $types = ['Solo', 'Couple', 'Family', 'Friends', 'Business'];
        $arrivals = ['06:00', '09:30', '13:00', '16:30', null, '11:00'];
        $days = [1, 2, 3, 4, 5, 6, 8, 10];

        $out = [];
        $i = 0;
        foreach ($tiers as $t => $tier) {
            foreach ($modes as $m => $mode) {
                $n = $days[($t * 5 + $m) % count($days)];
                $wanted = [$interests[$i % 8], $interests[($i + 3) % 8]];
                $out[] = ['name' => "Ext {$tier}/{$mode}, {$n} days, ".implode(' + ', $wanted), 'travel_days' => $n, 'travel_type' => $types[$i % 5],
                    'budget' => $budgets[$i % 3], 'distance_pref' => $tier, 'transport_pref' => $mode, 'arrival_time' => $arrivals[$i % 6],
                    'airport' => $i % 2 === 0, 'activities' => $wanted];
                $i++;
            }
        }

        foreach ($interests as $k => $interest) {
            $out[] = ['name' => "Only {$interest}", 'travel_days' => [2, 3][$k % 2], 'travel_type' => 'Couple', 'budget' => 'Mid-range',
                'distance_pref' => 'moderate', 'arrival_time' => '09:00', 'activities' => [$interest]];
        }

        $out[] = ['name' => 'Budget-Friendly far, four days', 'travel_days' => 4, 'travel_type' => 'Friends', 'budget' => 'Budget-Friendly',
            'distance_pref' => 'far', 'arrival_time' => '08:00', 'activities' => ['Nature & Adventure', 'Beach & Island']];
        $out[] = ['name' => 'Premium near, three days', 'travel_days' => 3, 'travel_type' => 'Couple', 'budget' => 'Premium',
            'distance_pref' => 'near', 'arrival_time' => '10:00', 'activities' => ['Relaxation & Wellness', 'Cultural Heritage']];

        foreach ([['Departure 14:00, three days', 3, '14:00'], ['Early departure 09:00, two days', 2, '09:00'], ['Departure 17:00, one day', 1, '17:00'], ['Departure 21:00, five days', 5, '21:00']] as [$name, $n, $at]) {
            $out[] = ['name' => $name, 'travel_days' => $n, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'distance_pref' => 'moderate',
                'arrival_time' => '08:30', 'airport' => true, 'departure_time' => $at, 'activities' => ['Nature & Adventure', 'Cultural Heritage']];
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public static function scenarios(bool $extended = false): array
    {
        $base = self::BASE;

        $core = [
            ['name' => 'Couple weekend, nature and culture', 'travel_days' => 2, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'distance_pref' => 'moderate', 'arrival_time' => '09:00', 'airport' => true, 'activities' => ['Nature & Adventure', 'Cultural Heritage']],
            ['name' => 'Solo day trip, budget, near', 'travel_days' => 1, 'travel_type' => 'Solo', 'budget' => 'Budget-Friendly', 'distance_pref' => 'near', 'arrival_time' => '08:00', 'activities' => ['Cultural Heritage', 'Food Tourism']],
            ['name' => 'Family three days, wildlife and nature', 'travel_days' => 3, 'travel_type' => 'Family', 'budget' => 'Mid-range', 'distance_pref' => 'moderate', 'arrival_time' => '09:00', 'airport' => true, 'activities' => ['Wildlife', 'Nature & Adventure', 'Beach & Island']],
            ['name' => 'Friends four days, far, adventure', 'travel_days' => 4, 'travel_type' => 'Friends', 'budget' => 'Mid-range', 'distance_pref' => 'far', 'arrival_time' => '10:00', 'activities' => ['Hiking & Trekking', 'Nature & Adventure', 'Beach & Island']],
            ['name' => 'Business traveller, late arrival', 'travel_days' => 2, 'travel_type' => 'Business', 'budget' => 'Premium', 'distance_pref' => 'near', 'arrival_time' => '18:30', 'airport' => true, 'activities' => ['Shopping & Souvenirs', 'Cultural Heritage']],
            ['name' => 'Foodie three days', 'travel_days' => 3, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'distance_pref' => 'near', 'arrival_time' => '09:00', 'activities' => ['Food Tourism', 'Shopping & Souvenirs', 'Cultural Heritage']],
            ['name' => 'Wellness couple, premium', 'travel_days' => 2, 'travel_type' => 'Couple', 'budget' => 'Premium', 'distance_pref' => 'moderate', 'arrival_time' => '11:00', 'activities' => ['Relaxation & Wellness', 'Beach & Island']],
            ['name' => 'Five days, five interests', 'travel_days' => 5, 'travel_type' => 'Friends', 'budget' => 'Mid-range', 'distance_pref' => 'moderate', 'arrival_time' => '09:00', 'airport' => true, 'activities' => ['Nature & Adventure', 'Cultural Heritage', 'Wildlife', 'Food Tourism', 'Hiking & Trekking']],
            ['name' => 'Budget family, early arrival', 'travel_days' => 1, 'travel_type' => 'Family', 'budget' => 'Budget-Friendly', 'distance_pref' => 'near', 'arrival_time' => '06:30', 'activities' => ['Wildlife', 'Nature & Adventure']],
            ['name' => 'Solo three days, no arrival time or start', 'travel_days' => 3, 'travel_type' => 'Solo', 'budget' => 'Mid-range', 'distance_pref' => 'moderate', 'arrival_time' => null, 'activities' => ['Cultural Heritage', 'Nature & Adventure', 'Food Tourism']],
            ['name' => 'Beach and island weekend', 'travel_days' => 2, 'travel_type' => 'Friends', 'budget' => 'Mid-range', 'distance_pref' => 'moderate', 'arrival_time' => '09:00', 'activities' => ['Beach & Island', 'Relaxation & Wellness']],
            ['name' => 'Long trip, seven days', 'travel_days' => 7, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'distance_pref' => 'far', 'arrival_time' => '09:00', 'airport' => true, 'activities' => ['Nature & Adventure', 'Cultural Heritage', 'Beach & Island', 'Wildlife']],
        ];

        return array_map(fn (array $s) => $s + $base, $extended ? array_merge($core, self::extendedScenarios()) : $core);
    }

    public function handle(ItineraryGenerationService $generator, ItineraryAudit $audit): int
    {
        $filter = (string) $this->option('scenario');
        $canDepart = Schema::hasColumn('tourist_preferences', 'departure_time');
        $scenarios = array_values(array_filter(self::scenarios((bool) $this->option('extended')),
            fn ($s) => ($filter === '' || stripos($s['name'], $filter) !== false) && ($canDepart || empty($s['departure_time']))));
        if ($scenarios === []) {
            $this->error('No scenario matches that name.');

            return self::FAILURE;
        }

        // Next Saturday, so every trip meets a weekend and the weekdays after it.
        $start = now('Asia/Manila')->next('Saturday')->startOfDay();
        $rows = [];
        $perCheck = [];
        $clean = 0;
        $verified = 0;

        foreach ($scenarios as $scenario) {
            DB::beginTransaction();
            try {
                $preference = TouristPreference::create([
                    'travel_days' => $scenario['travel_days'], 'travel_type' => $scenario['travel_type'], 'travel_purpose' => $scenario['travel_purpose'],
                    'visitor_type' => $scenario['visitor_type'], 'budget' => $scenario['budget'], 'accommodation_pref' => $scenario['accommodation_pref'],
                    'distance_pref' => $scenario['distance_pref'], 'transport_pref' => $scenario['transport_pref'],
                    'start_date' => $start->toDateString(), 'arrival_time' => $scenario['arrival_time'],
                    'origin_lat' => ! empty($scenario['airport']) ? self::AIRPORT['lat'] : null,
                    'origin_lng' => ! empty($scenario['airport']) ? self::AIRPORT['lng'] : null,
                    'origin_label' => ! empty($scenario['airport']) ? self::AIRPORT['label'] : null,
                ]);
                if (! empty($scenario['departure_time'])) {
                    $preference->forceFill(['departure_time' => $scenario['departure_time'], 'departure_lat' => self::AIRPORT['lat'],
                        'departure_lng' => self::AIRPORT['lng'], 'departure_label' => self::AIRPORT['label']])->save();
                }
                foreach ($scenario['activities'] as $activity) {
                    PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => $activity]);
                }
                foreach (['Restaurant', 'Parking Area'] as $amenity) {
                    PreferenceAmenity::create(['preference_id' => $preference->id, 'amenity' => $amenity]);
                }
                $preference->load('activities', 'amenities');

                $checks = $audit->audit($generator->generate($preference), $preference);
            } catch (\Throwable $e) {
                $checks = [['key' => 'built', 'label' => 'The plan could be built', 'status' => 'fail', 'detail' => class_basename($e).': '.$e->getMessage()]];
            } finally {
                DB::rollBack();
            }

            $isClean = $audit->clean($checks);
            $unknown = count(array_filter($checks, fn ($c) => $c['status'] === 'unknown'));
            $clean += $isClean ? 1 : 0;
            $verified += $isClean && $unknown === 0 ? 1 : 0;
            foreach ($checks as $c) {
                // the day-distance limit differs from trip to trip, so the summary names the rule, not one trip's number
                $perCheck[$c['key']]['label'] = $c['key'] === 'day_km' ? "No day drives further than a round trip to the edge of the trip's reach" : $c['label'];
                $perCheck[$c['key']][$c['status']] = ($perCheck[$c['key']][$c['status']] ?? 0) + 1;
            }
            $rows[] = [$scenario['name'], $isClean ? 'clean' : 'NOT clean', $unknown, implode(', ', array_column(array_filter($checks, fn ($c) => $c['status'] === 'fail'), 'key'))];

            if ($this->option('details')) {
                $this->line("<options=bold>{$scenario['name']}</>");
                foreach ($checks as $c) {
                    if ($c['status'] !== 'pass') {
                        $this->line(sprintf('  %-7s %s%s', strtoupper($c['status']), $c['label'], $c['detail'] !== '' ? ' — '.$c['detail'] : ''));
                    }
                }
            }
        }

        $this->newLine();
        $this->table(['Trip', 'Result', 'Unknown checks', 'Failing checks'], $rows);

        $total = count($scenarios);
        $this->table(['Check', 'Pass', 'Fail', 'Unknown'], array_map(fn ($c) => [
            $c['label'], $c['pass'] ?? 0, $c['fail'] ?? 0, $c['unknown'] ?? 0,
        ], array_values($perCheck)));

        $rate = round($clean / $total * 100);
        $this->info("{$clean} of {$total} trips are clean ({$rate}%). \"Unknown\" means the data could not say, and is not counted as a failure.");
        $this->info("{$verified} of {$total} are clean AND fully verified (no unknown check). A trip that is clean only because checks could not be decided is not counted here.");

        $min = $this->option('min-pass');

        return ($min !== null && $rate < (float) $min) ? self::FAILURE : self::SUCCESS;
    }
}
