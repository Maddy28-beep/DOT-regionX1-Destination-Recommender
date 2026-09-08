<?php

namespace App\Console\Commands;

use App\Models\Destination;
use App\Models\PreferenceActivity;
use App\Models\PreferenceAmenity;
use App\Models\TouristPreference;
use App\Services\Recommendation\ContentBasedRecommendationService;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prints the full recommendation pipeline for one hypothetical trip: the
 * geographic filter's before/after counts, every candidate's PM/RS/PS/DS/AS/
 * DRS, and the resulting itinerary -- without saving anything.
 *
 * Exists because "the recommendations look wrong" is not something a
 * database query or a passing test can confirm or refute on its own; seeing
 * every score next to every destination is what actually answers it. The
 * test preference is created and rolled back in a transaction, so running
 * this against the production database is always safe.
 */
class DiagnoseRecommendations extends Command
{
    protected $signature = 'recommendation:diagnose
        {--days=3 : Trip length}
        {--purpose=Leisure : Travel purpose}
        {--budget=Mid-range : Budget}
        {--distance=moderate : near, moderate, or far}
        {--accommodation=Any : Accommodation preference}
        {--activity=* : Selected interest/activity (repeatable)}
        {--amenity=* : Selected amenity (repeatable)}
        {--origin-lat= : Optional starting latitude}
        {--origin-lng= : Optional starting longitude}';

    protected $description = 'Trace the destination recommendation pipeline for a hypothetical trip preference, without saving anything.';

    public function handle(ContentBasedRecommendationService $contentBased, ItineraryGenerationService $itineraryService): int
    {
        $catalogueSize = Destination::where('is_accredited', true)->whereNull('archived_at')->count();
        $this->info("Catalogue: {$catalogueSize} accredited, non-archived destinations.");

        DB::beginTransaction();

        try {
            $preference = TouristPreference::create([
                'travel_days' => (int) $this->option('days'),
                'travel_type' => 'solo',
                'travel_purpose' => $this->option('purpose'),
                'visitor_type' => 'First Time Visitor',
                'budget' => $this->option('budget'),
                'accommodation_pref' => $this->option('accommodation'),
                'distance_pref' => $this->option('distance'),
                'origin_lat' => $this->option('origin-lat') !== null ? (float) $this->option('origin-lat') : null,
                'origin_lng' => $this->option('origin-lng') !== null ? (float) $this->option('origin-lng') : null,
            ]);

            foreach ($this->option('activity') as $activity) {
                PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => $activity]);
            }
            foreach ($this->option('amenity') as $amenity) {
                PreferenceAmenity::create(['preference_id' => $preference->id, 'amenity' => $amenity]);
            }
            $preference->load('activities', 'amenities');

            $ranked = $contentBased->rank($preference);

            $this->line('');
            $this->info("Stage 1 -- geographic filter: tier used = {$contentBased->lastRangeTierUsed}"
                .($contentBased->lastRangeWidened ? ' (widened past the requested range to find enough candidates)' : ''));
            $this->info("Candidates before Stage 1: {$catalogueSize}   after Stage 1: {$ranked->count()}");

            $this->line('');
            $this->info('Stage 2 -- every scored candidate (PM / RS / PS / DS / AS / DRS):');
            $this->table(
                ['Destination', 'PM', 'RS', 'PS', 'DS', 'AS', 'DRS'],
                $ranked->map(fn (array $row) => [
                    $row['destination']->name, $row['pm'], $row['rs'], $row['ps'], $row['ds'], $row['as'], $row['drs'],
                ])->all()
            );

            $names = $ranked->map(fn (array $row) => $row['destination']->name);
            $this->info("Unique destination IDs: {$ranked->count()}   unique names: {$names->unique()->count()}"
                .($names->unique()->count() < $ranked->count()
                    ? ' (some are different branches of the same accredited business -- see Itinerary::distinctTopMatches())'
                    : ''));

            $itinerary = $itineraryService->generate($preference);

            $this->line('');
            $this->info('Resulting itinerary:');
            foreach ($itinerary->items->sortBy(['day_number', 'sort_order']) as $item) {
                $dest = $item->destination->name ?? null;
                $this->line("Day {$item->day_number} [{$item->kind}] {$item->title}".($dest ? " -> {$dest}" : ''));
            }

            // Only 'activity' rows count as a distinct stop: the lunch row at
            // that same spot legitimately shares its destination_id and is
            // not a second visit, so counting it here would flag every meal
            // eaten at the destination itself as a false "repeat".
            $stopNames = $itinerary->items->where('kind', 'activity')
                ->map(fn ($i) => $i->destination->name ?? null)->filter();
            $repeats = $stopNames->duplicates();
            $this->line('');
            if ($repeats->isEmpty()) {
                $this->info('No destination is visited more than once across the itinerary.');
            } else {
                $this->error('Visited more than once: '.$repeats->unique()->implode(', '));
            }

            $accommodation = $itinerary->items->firstWhere('kind', 'overnight')?->accommodation;
            if ($accommodation && $accommodation->latitude !== null) {
                $this->line('');
                $this->info("Accommodation: {$accommodation->name} (lat={$accommodation->latitude}, lng={$accommodation->longitude})");
                foreach ($itinerary->items->where('kind', 'activity') as $stop) {
                    if ($stop->destination && $stop->destination->latitude !== null) {
                        $km = $this->haversine(
                            (float) $accommodation->latitude, (float) $accommodation->longitude,
                            (float) $stop->destination->latitude, (float) $stop->destination->longitude
                        );
                        $this->line(sprintf('  %.1f km from %s', $km, $stop->destination->name));
                    }
                }
            }

            $this->line('');
            $this->info('Top 5 distinct recommended destinations:');
            foreach ($itinerary->distinctTopMatches(5) as $match) {
                $this->line("  #{$match->rank} {$match->destination->name} -- DRS {$match->match_score}");
            }
        } finally {
            DB::rollBack();
        }

        $this->line('');
        $this->comment('(Test preference and itinerary were rolled back -- nothing was saved.)');

        return self::SUCCESS;
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
