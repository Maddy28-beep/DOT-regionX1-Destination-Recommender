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
        {--holdout : Run the hold-out set: 30 trips that differ from the others in start day, start point, length, arrival and interests (alone, or with --extended, in addition to the others)}
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

    /**
     * The hold-out set. Whoever tunes the planner against the core and extended trips has seen those; these are the
     * trips they have not. They differ in what the others hold fixed: they start on a Monday, Wednesday or Friday
     * (not a Saturday), from the Ecoland terminal, the Sasa port or the city centre as well as the airport or nowhere,
     * with three interests each, and with lengths, arrivals and budgets taken in a different order. A plan that is
     * only good on the trips it was tuned on scores worse here, so this is the score to trust.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function holdoutScenarios(): array
    {
        $interests = ['Beach & Island', 'Nature & Adventure', 'Cultural Heritage', 'Wildlife', 'Food Tourism', 'Shopping & Souvenirs', 'Hiking & Trekking', 'Relaxation & Wellness'];
        $modes = ['jeep', 'taxi', 'personal_car', 'tour_operator', 'rental_car'];
        $tiers = ['near', 'moderate', 'far'];
        $budgets = ['Budget-Friendly', 'Mid-range', 'Premium'];
        $types = ['Solo', 'Couple', 'Family', 'Friends', 'Business'];
        $arrivals = ['07:30', '12:00', '15:30', '19:00', null, '10:30'];
        $days = [2, 3, 4, 7, 9, 5];
        $starts = ['Wednesday', 'Friday', 'Monday'];
        $origins = [
            null,
            ['lat' => self::AIRPORT['lat'], 'lng' => self::AIRPORT['lng'], 'label' => self::AIRPORT['label']],
            ['lat' => 7.056, 'lng' => 125.602, 'label' => 'Davao City Overland Transport Terminal, Ecoland'],
            ['lat' => 7.127, 'lng' => 125.663, 'label' => 'Port of Sasa'],
            ['lat' => 7.066, 'lng' => 125.607, 'label' => 'San Pedro Street, Davao City'],
        ];

        $out = [];
        for ($i = 0; $i < 30; $i++) {
            $tier = $tiers[$i % 3];
            $mode = $modes[($i * 2 + 1) % 5];
            $n = $days[$i % 6];
            $wanted = [$interests[($i * 3) % 8], $interests[($i * 3 + 2) % 8], $interests[($i * 3 + 5) % 8]];
            $out[] = ['name' => sprintf('Hold-out %02d %s/%s, %d days, %s', $i + 1, $tier, $mode, $n, implode(' + ', $wanted)),
                'travel_days' => $n, 'travel_type' => $types[($i + 3) % 5], 'budget' => $budgets[($i + 1) % 3], 'distance_pref' => $tier,
                'transport_pref' => $mode, 'arrival_time' => $arrivals[($i + 2) % 6], 'origin' => $origins[$i % 5],
                'start' => $starts[$i % 3], 'activities' => $wanted];
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public static function scenarios(bool $extended = false, bool $holdout = false): array
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

        $set = $holdout && ! $extended ? [] : $core;
        $set = $extended ? array_merge($set, self::extendedScenarios()) : $set;
        $set = $holdout ? array_merge($set, self::holdoutScenarios()) : $set;

        return array_map(fn (array $s) => $s + $base, $set);
    }

    public function handle(ItineraryGenerationService $generator, ItineraryAudit $audit): int
    {
        $filter = (string) $this->option('scenario');
        $canDepart = Schema::hasColumn('tourist_preferences', 'departure_time');
        $scenarios = array_values(array_filter(self::scenarios((bool) $this->option('extended'), (bool) $this->option('holdout')),
            fn ($s) => ($filter === '' || stripos($s['name'], $filter) !== false) && ($canDepart || empty($s['departure_time']))));
        if ($scenarios === []) {
            $this->error('No scenario matches that name.');

            return self::FAILURE;
        }

        // Next Saturday unless a trip says otherwise, so every trip meets a weekend and the weekdays after it.
        $nextDay = fn (string $day) => now('Asia/Manila')->next($day)->startOfDay();
        $rows = [];
        $perCheck = [];
        $clean = 0;
        $verified = 0;
        $refused = 0;

        foreach ($scenarios as $scenario) {
            DB::beginTransaction();
            try {
                $preference = TouristPreference::create([
                    'travel_days' => $scenario['travel_days'], 'travel_type' => $scenario['travel_type'], 'travel_purpose' => $scenario['travel_purpose'],
                    'visitor_type' => $scenario['visitor_type'], 'budget' => $scenario['budget'], 'accommodation_pref' => $scenario['accommodation_pref'],
                    'distance_pref' => $scenario['distance_pref'], 'transport_pref' => $scenario['transport_pref'],
                    'start_date' => $nextDay($scenario['start'] ?? 'Saturday')->toDateString(), 'arrival_time' => $scenario['arrival_time'],
                    'origin_lat' => $scenario['origin']['lat'] ?? (! empty($scenario['airport']) ? self::AIRPORT['lat'] : null),
                    'origin_lng' => $scenario['origin']['lng'] ?? (! empty($scenario['airport']) ? self::AIRPORT['lng'] : null),
                    'origin_label' => $scenario['origin']['label'] ?? (! empty($scenario['airport']) ? self::AIRPORT['label'] : null),
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
            } catch (\Illuminate\Validation\ValidationException $e) {
                $refused++;
                $checks = [['key' => 'feasibility', 'label' => 'The requested trip can be fulfilled', 'status' => 'fail',
                    'detail' => 'No itinerary saved: '.implode(' ', \Illuminate\Support\Arr::flatten($e->errors()))]];
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
                $perCheck[$c['key']]['label'] = match ($c['key']) {
                    'day_km' => "No day drives further than a round trip to the edge of the trip's reach",
                    'travel_share' => 'No day spends more than 40% of its time travelling',
                    default => $c['label'],
                };
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
        $this->info("{$refused} request(s) were declined as infeasible; these are not saved as successful itineraries.");

        $min = $this->option('min-pass');

        return ($min !== null && $rate < (float) $min) ? self::FAILURE : self::SUCCESS;
    }
}
