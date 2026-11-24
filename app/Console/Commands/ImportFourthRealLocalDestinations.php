<?php

namespace App\Console\Commands;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Region;
use App\Support\ListingPhotoManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportFourthRealLocalDestinations extends Command
{
    protected $signature = 'destinations:import-fourth-real-local {--apply : Add the reviewed catalogue batch} {--allow-production : Apply on a live server (see the note in handle())}';

    protected $description = 'Add the fourth batch of 15 real destinations, with no claimed DOT accreditation (dry run by default).';

    public const DIRECTORY = 'database/data/listing-photos/real-destinations-batch4-2026-11-19';

    private function identity(string $name): string
    {
        $name = preg_replace('/\s*\[SAMPLE\]\s*$/i', '', $name);

        return preg_replace('/[^a-z0-9]/', '', Str::ascii(mb_strtolower($name)));
    }

    public function handle(): int
    {
        // Local and testing environments need no flag. A live server needs --allow-production: the project owner asked on 24 November 2026
        // for these places and photographs to be published on the live site, knowing the publication rights of the photographs are not
        // established (each photograph keeps its source and credit). Nothing is written without --apply, and the dry run shows the plan.
        if (! app()->environment(['local', 'testing']) && ! $this->option('allow-production')) {
            $this->error('This batch is applied locally unless you add --allow-production. Nothing changed.');

            return self::FAILURE;
        }

        $directory = base_path(self::DIRECTORY);
        $manifest = new ListingPhotoManifest($directory);
        $entries = $manifest->entries();
        $problems = $manifest->problems();
        if (count($entries) !== 15 || $problems) {
            foreach ($problems ?: ['The reviewed batch must contain 15 entries.'] as $problem) $this->error($problem);

            return self::FAILURE;
        }

        $plan = [];
        $seen = [];
        $existing = Destination::with('photos')->get();
        $stays = Accommodation::all();
        foreach ($entries as $entry) {
            $keys = array_unique(array_map($this->identity(...), [$entry['name'], ...($entry['aliases'] ?? [])]));
            $region = Region::where('name', $entry['region'])->first();
            $matches = $existing->filter(fn ($d) => in_array($this->identity($d->name), $keys, true) || $d->slug === $entry['slug']);
            $destination = $matches->first();
            $stayMatch = $stays->contains(fn ($stay) => in_array($this->identity($stay->name), $keys, true));
            $image = @getimagesize($directory.'/'.$entry['file']);
            if (! $region || array_intersect($keys, array_keys($seen)) || $matches->count() > 1 || $stayMatch
                || basename($entry['file']) !== $entry['file'] || ! $image || min($image[0], $image[1]) < 150
                || $entry['permission_basis'] !== 'team_testing_decision' || $entry['verification_status'] !== 'approved'
                || $entry['itinerary_role'] !== 'excluded'
                || ($destination && ($destination->region_id !== $region->id || $destination->isArchived()
                    || str_starts_with($destination->name, 'Sample ')
                    || ! in_array($this->identity($destination->name), $keys, true)))) {
                $this->error('Ambiguous destination, existing stay or invalid local entry: '.$entry['name'].'. Nothing changed.');

                return self::FAILURE;
            }
            foreach ($keys as $key) $seen[$key] = true;
            $needsPhoto = ! $destination?->photos->contains(fn ($photo) => ! $photo->isIllustration());
            $plan[] = compact('entry', 'region', 'destination', 'needsPhoto');
        }

        $new = count(array_filter($plan, fn ($p) => ! $p['destination']));
        $photos = count(array_filter($plan, fn ($p) => $p['needsPhoto']));
        $this->info("{$new} destinations and {$photos} photos ready.");
        if (! $this->option('apply')) {
            $this->line('Dry run: nothing changed.');

            return self::SUCCESS;
        }

        $changes = [];
        DB::transaction(function () use ($plan, $directory, &$changes) {
            foreach ($plan as $p) {
                ['entry' => $entry, 'region' => $region, 'destination' => $destination, 'needsPhoto' => $needsPhoto] = $p;
                if (! $destination) {
                    $destination = Destination::create([
                        ...array_intersect_key($entry, array_flip(['name', 'slug', 'location', 'type', 'description'])),
                        'region_id' => $region->id,
                        // Legacy public-eligibility flag; no accreditation provenance is created.
                        'is_accredited' => true,
                        // Preserve generated itineraries until operational visit details are verified.
                        'itinerary_role' => 'excluded',
                    ]);
                    $changes[] = ['destination_id' => $destination->id, 'slug' => $destination->slug, 'created' => true];
                }
                if ($needsPhoto) {
                    $path = 'listings/real-destinations-batch4-local-2026-11-19/'.$entry['file'];
                    if (Storage::disk('public')->exists($path)
                        && hash('sha256', Storage::disk('public')->get($path)) !== $entry['sha256']) {
                        throw new \RuntimeException('Refusing to replace an existing different photo: '.$path);
                    }
                    if (! Storage::disk('public')->put($path, File::get($directory.'/'.$entry['file']))) {
                        throw new \RuntimeException('Could not store '.$entry['file']);
                    }
                    $photo = $destination->photos()->create([
                        'path' => $path, 'category' => 'General', 'sort_order' => -1, 'is_primary' => true,
                        ...array_intersect_key($entry, array_flip(['source_url', 'source_name', 'source_type', 'permission_basis',
                            'permission_ref', 'permission_date', 'licence', 'credit_text', 'show_credit', 'verification_status', 'verified_by', 'verified_at'])),
                    ]);
                    $changes[] = ['destination_id' => $destination->id, 'photo_id' => $photo->id, 'path' => $path];
                }
            }
            if ($changes) File::put(storage_path('app/real-destinations-batch4-local-'.now()->format('Ymd-His-u').'.json'),
                json_encode($changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        });

        $this->info("{$new} destinations and {$photos} photos added. Accreditation remains unconfirmed; existing records unchanged.");

        return self::SUCCESS;
    }
}
