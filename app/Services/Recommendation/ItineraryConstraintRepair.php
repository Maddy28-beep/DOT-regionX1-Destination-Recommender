<?php

namespace App\Services\Recommendation;

use App\Models\Itinerary;
use App\Models\TouristPreference;
use Illuminate\Support\Collection;

/** Bounded repairs are evaluated on complete schedules, including meals and return transfers. */
class ItineraryConstraintRepair
{
    public function __construct(private readonly ItineraryAudit $audit) {}

    public function repair(Itinerary $trip, TouristPreference $preference, Collection $ranked, array $capacities, callable $build, array $origin): void
    {
        $pool = $ranked->keyBy(fn ($r) => $r['destination']->id);
        $wanted = $preference->activities->pluck('activity')->filter(fn ($i) => $pool->contains(fn ($r) => InterestCoverage::covers($r['destination'], $i)))->values()->all();
        $read = fn () => $trip->items()->with('destination.tags', 'accommodation')->orderBy('sort_order')->get();
        $rows = $read();
        $score = $this->score($trip, $preference, $rows, $wanted);
        if (array_sum(array_slice($score, 0, 4)) == 0) {
            return;
        }
        $sequence = $rows->where('kind', 'activity')->whereNotNull('destination_id')
            ->map(fn ($r) => ['row' => $pool[$r->destination_id] ?? ['destination' => $r->destination], 'distance_km' => null])->values()->all();
        $days = $rows->where('kind', 'activity')->whereNotNull('destination_id')->pluck('day_number', 'destination_id')->all();
        $rebuild = function ($stops, $assignments) use ($trip, $build) {
            $trip->items()->delete();
            $trip->unsetRelation('items');
            $build($stops, $assignments);
        };
        $trials = 0;
        $changed = false;
        // A fixed trial cap gives the same preferences/data the same opportunity
        // to repair, regardless of server load while another audit is running.
        while ($trials < 120) {
            $used = array_column(array_column($sequence, 'row'), 'destination');
            $ids = array_map(fn ($d) => $d->id, $used);
            $missing = array_values(array_filter($wanted, fn ($interest) => ! collect($used)->contains(fn ($d) => InterestCoverage::covers($d, $interest))));
            $offers = [];
            // Stops on the days that break a limit are tried first, so the few trials go where they can help.
            $bad = $this->badDays($trip, $preference, $rows);
            $order = array_keys($sequence);
            usort($order, fn ($a, $b) => (int) isset($bad[$days[$sequence[$b]['row']['destination']->id] ?? 0]) <=> (int) isset($bad[$days[$sequence[$a]['row']['destination']->id] ?? 0]));
            $candidates = $pool->reject(fn ($r, $id) => in_array($id, $ids, true)
                || $r['destination']->latitude === null || $r['destination']->longitude === null);
            // Moving a retained interest to a quieter day can fix a long transfer without losing the visit.
            foreach ($order as $at) {
                if ($score[0] > 0) {
                    break; // Resolve missing geometry before optimising travel based on incomplete distances.
                }
                $entry = $sequence[$at];
                $id = $entry['row']['destination']->id;
                foreach ($capacities as $day => $slots) {
                    if ($days[$id] === $day || count(array_filter($days, fn ($d) => $d === $day)) >= count($slots)) {
                        continue;
                    }
                    $assignments = $days;
                    $assignments[$id] = $day;
                    $trial = $sequence;
                    usort($trial, fn ($a, $b) => $assignments[$a['row']['destination']->id] <=> $assignments[$b['row']['destination']->id]);
                    $offers[] = [$trial, $assignments];
                }
            }
            // Two visits on different days trade places: a far stop on a long day for a nearer one elsewhere.
            if ($score[0] == 0 && $bad) {
                foreach ($order as $at) {
                    $a = $sequence[$at]['row']['destination']->id;
                    if (! isset($bad[$days[$a]])) {
                        break;
                    }
                    foreach ($sequence as $other) {
                        $b = $other['row']['destination']->id;
                        if ($days[$b] === $days[$a]) {
                            continue;
                        }
                        $assignments = $days;
                        [$assignments[$a], $assignments[$b]] = [$days[$b], $days[$a]];
                        $trial = $sequence;
                        usort($trial, fn ($x, $y) => $assignments[$x['row']['destination']->id] <=> $assignments[$y['row']['destination']->id]);
                        $offers[] = [$trial, $assignments];
                    }
                }
            }
            // Replace a dropped/unverifiable/remote visit with a nearby visit, favouring uncovered interests.
            foreach ($order as $index) {
                $entry = $sequence[$index];
                $day = $days[$entry['row']['destination']->id];
                // Interests only this stop covers: a replacement that keeps them is worth more than a nearer one that does not.
                $sole = array_values(array_filter($wanted, fn ($i) => InterestCoverage::covers($entry['row']['destination'], $i)
                    && ! collect($sequence)->contains(fn ($o, $k) => $k !== $index && InterestCoverage::covers($o['row']['destination'], $i))));
                $near = $rows->where('kind', 'overnight')->firstWhere('day_number', $day)?->accommodation;
                $lat = $near?->latitude ?? $origin['lat'];
                $lng = $near?->longitude ?? $origin['lng'];
                $choices = $candidates->sortBy(fn ($r) => [
                    -count(array_filter($sole, fn ($i) => InterestCoverage::covers($r['destination'], $i))),
                    -count(array_filter($missing, fn ($i) => InterestCoverage::covers($r['destination'], $i))),
                    $this->km($lat, $lng, $r['destination']->latitude, $r['destination']->longitude),
                ])->take(8);
                foreach ($choices as $r) {
                    $trial = $sequence;
                    $trial[$index] = ['row' => $r, 'distance_km' => null];
                    $assignments = $days;
                    unset($assignments[$entry['row']['destination']->id]);
                    $assignments[$r['destination']->id] = $day;
                    $offers[] = [$trial, $assignments];
                }
            }
            foreach ($capacities as $day => $slots) {
                if (count(array_filter($days, fn ($d) => $d === $day)) >= count($slots)) {
                    continue;
                }
                foreach ($candidates->sortBy(fn ($r) => [
                    -count(array_filter($missing, fn ($i) => InterestCoverage::covers($r['destination'], $i))),
                    $this->km($origin['lat'], $origin['lng'], $r['destination']->latitude, $r['destination']->longitude),
                ])->take(6) as $r) {
                    $trial = $sequence;
                    $trial[] = ['row' => $r, 'distance_km' => null];
                    $assignments = $days + [$r['destination']->id => $day];
                    usort($trial, fn ($a, $b) => $assignments[$a['row']['destination']->id] <=> $assignments[$b['row']['destination']->id]);
                    $offers[] = [$trial, $assignments];
                }
            }
            foreach ($sequence as $index => $entry) {
                $trial = $sequence;
                array_splice($trial, $index, 1);
                $assignments = $days;
                unset($assignments[$entry['row']['destination']->id]);
                $offers[] = [$trial, $assignments];
            }
            $improved = false;
            // Do not spend the bounded search on moves that cannot cover a
            // missing interest while a replacement/addition could cover it.
            if ($missing !== []) {
                usort($offers, function ($a, $b) use ($missing) {
                    $uncovered = fn ($offer) => count(array_filter($missing, fn ($interest) => ! collect($offer[0])
                        ->contains(fn ($entry) => InterestCoverage::covers($entry['row']['destination'], $interest))));

                    return $uncovered($a) <=> $uncovered($b);
                });
            }
            foreach ($offers as [$trial, $assignments]) {
                if ($trials++ >= 120) {
                    break;
                }
                $rebuild($trial, $assignments);
                $trialRows = $read();
                $next = $this->score($trip, $preference, $trialRows, $wanted);
                // A repair never gives up something the traveller asked for (an interest) or leaves a day with nothing
                // to do in order to shorten a journey: those stay as they were and the audit reports the long day.
                if ($next[2] <= $score[2] && $next[3] <= $score[3] && array_slice($next, 0, 5) < array_slice($score, 0, 5)) {
                    // Keep only visits actually placed, so a closed visit cannot consume a repair slot.
                    $days = $trialRows->where('kind', 'activity')->whereNotNull('destination_id')->pluck('day_number', 'destination_id')->all();
                    $sequence = array_values(array_filter($trial, fn ($e) => isset($days[$e['row']['destination']->id])));
                    $score = $next;
                    $rows = $trialRows;
                    $improved = true;
                    $changed = true;
                    break;
                }
            }
            if (! $improved || array_sum(array_slice($score, 0, 4)) == 0) {
                break;
            }
        }
        $rebuild($sequence, $days);
        if ($changed && $trip->day_themes !== null) {
            $trip->update(['day_themes' => null]); // Original grouping measurements no longer describe these days.
        }
        // The caller checks remaining() before saving a successful itinerary;
        // exhaustion of this bounded search is not proof that a route is valid.
    }

    /**
     * What a finished plan still breaks, as the repair sees it: the number of days over the distance, travel-share or
     * hotel limits (0 means none). The generator refuses a successful result when this is nonzero.
     */
    public function remaining(Itinerary $trip, TouristPreference $preference): float
    {
        $rows = $trip->items()->with('destination.tags', 'accommodation')->orderBy('sort_order')->get();

        return $this->score($trip, $preference, $rows, [])[1];
    }

    /** The days whose distance, travel share or hotel distance is over the limit, as [day => true]. */
    private function badDays(Itinerary $trip, TouristPreference $preference, Collection $rows): array
    {
        $bad = [];
        $limit = $this->audit->dayKmLimit($preference, $trip->range_tier_used);
        foreach ($rows->groupBy('day_number') as $day => $dayRows) {
            $metrics = $this->audit->dayTravelMetrics($dayRows);
            if ($metrics['km'] > $limit || $metrics['share'] > $this->audit->travelShareLimit($preference, $trip->range_tier_used)) {
                $bad[$day] = true;
            }
        }

        return $bad;
    }

    private function score(Itinerary $trip, TouristPreference $preference, Collection $rows, array $wanted): array
    {
        $activities = $rows->where('kind', 'activity')->whereNotNull('destination_id');
        // An unlocated stop must never improve the apparent limits by hiding a long road transfer.
        $unlocated = $activities->filter(fn ($r) => $r->destination->latitude === null || $r->destination->longitude === null)->count();
        $hard = 0;
        $limit = $this->audit->dayKmLimit($preference, $trip->range_tier_used);
        foreach ($rows->groupBy('day_number') as $day) {
            $metrics = $this->audit->dayTravelMetrics($day);
            $hard += max(0, $metrics['km'] / $limit - 1) + max(0, $metrics['share'] / $this->audit->travelShareLimit($preference, $trip->range_tier_used) - 1);
        }
        foreach ($rows->where('kind', 'overnight') as $night) {
            foreach ($activities->where('day_number', $night->day_number) as $stop) {
                if ($night->accommodation && $stop->destination->latitude !== null) {
                    $hard += max(0, $this->km($night->accommodation->latitude, $night->accommodation->longitude,
                        $stop->destination->latitude, $stop->destination->longitude) / ItineraryAudit::MAX_STAY_KM - 1);
                }
            }
        }
        $missing = count(array_filter($wanted, fn ($i) => ! $activities->contains(fn ($r) => InterestCoverage::covers($r->destination, $i))));
        $expected = $this->audit->sightseeingDays($trip, $preference);
        $empty = count(array_diff($expected, $activities->pluck('day_number')->all()));

        return [$unlocated, round($hard, 6), $missing, $empty, max(0, $trip->total_days - 1 - $rows->where('kind', 'overnight')->count()),
            -$activities->count(), (int) $rows->where('kind', 'travel')->sum('travel_max_minutes')];
    }

    private function km($lat1, $lng1, $lat2, $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($a)));
    }
}
