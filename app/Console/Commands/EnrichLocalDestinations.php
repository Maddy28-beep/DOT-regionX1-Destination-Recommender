<?php

namespace App\Console\Commands;

use App\Models\Destination;
use App\Models\DestinationTag;
use App\Support\Amenities;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/** Sourced catalogue edits only. Never changes itinerary eligibility or accreditation. */
class EnrichLocalDestinations extends Command
{
    protected $signature = 'destinations:enrich-local {file : Reviewed enrichment JSON} {--apply : Apply the file; otherwise preview} {--allow-production : Apply on a live server (see the note in handle())}';

    protected $description = 'Fill reviewed destination details and add sourced amenities, with identity and old-value guards.';

    private const LIMITS = [
        'location' => 255, 'description' => 1000, 'hours' => 100, 'visit_duration' => 50,
        'best_time' => 80, 'price_tier' => 20, 'website_url' => 255, 'facebook_url' => 255,
        'latitude' => 30, 'longitude' => 30, 'entry_fee_min' => 30, 'entry_fee_max' => 30,
    ];

    public function handle(): int
    {
        // Local and testing environments need no flag. A live server needs --allow-production: the project owner asked on 24 November 2026
        // for these places and photographs to be published on the live site, knowing the publication rights of the photographs are not
        // established (each photograph keeps its source and credit). Nothing is written without --apply, and the dry run shows the plan.
        if (! app()->environment(['local', 'testing']) && ! $this->option('allow-production')) {
            $this->error('Local/testing only unless you add --allow-production. Nothing changed.');

            return self::FAILURE;
        }
        try {
            $path = (string) $this->argument('file');
            if (! is_file($path)) throw new RuntimeException('Manifest does not exist.');
            $manifest = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['format'] ?? null) !== 'destination-enrichment-v1' || empty($manifest['records'])) {
                throw new RuntimeException('Invalid or empty enrichment manifest.');
            }
            $apply = (bool) $this->option('apply');
            $undo = [];
            $report = DB::transaction(function () use ($manifest, $apply, &$undo) {
                $report = ['destinations' => 0, 'values' => 0, 'amenities' => 0];
                $seen = [];
                foreach ($manifest['records'] as $record) {
                    $slug = $record['slug'] ?? '';
                    if (! is_string($slug) || $slug === '' || isset($seen[$slug])) throw new RuntimeException('Missing or duplicate slug.');
                    $seen[$slug] = true;
                    $d = Destination::where('slug', $slug)->when($apply, fn ($q) => $q->lockForUpdate())->first();
                    if (! $d || $d->name !== ($record['name'] ?? null) || $d->archived_at !== null || str_starts_with(mb_strtolower($d->name), 'sample ')) {
                        throw new RuntimeException('Listing identity changed: '.$slug);
                    }
                    $changes = [];
                    foreach ($record['fields'] ?? [] as $field => $edit) {
                        $this->validateEdit($field, $edit);
                        $current = $d->getRawOriginal($field);
                        if ($this->same($field, $current, $edit['value'])) continue;
                        if (! array_key_exists('expected', $edit) || ! $this->same($field, $current, $edit['expected'])) {
                            throw new RuntimeException('Existing value changed: '.$slug.'.'.$field);
                        }
                        $changes[$field] = $edit['value'];
                    }
                    $lat = $changes['latitude'] ?? $d->latitude;
                    $lng = $changes['longitude'] ?? $d->longitude;
                    if (($lat === null) !== ($lng === null)) throw new RuntimeException('Coordinates require a pair.');
                    $min = $changes['entry_fee_min'] ?? $d->entry_fee_min;
                    $max = $changes['entry_fee_max'] ?? $d->entry_fee_max;
                    if ($min !== null && $max !== null && (float) $min > (float) $max) throw new RuntimeException('Inverted fee range.');
                    $newAmenities = [];
                    foreach ($record['amenities'] ?? [] as $amenity) {
                        $key = $amenity['key'] ?? '';
                        if (! in_array($key, Amenities::FOR_KIND['destination'], true) || ! $this->source($amenity['source'] ?? null)) {
                            throw new RuntimeException('Invalid or unsourced amenity: '.$slug);
                        }
                        $label = Amenities::label($key);
                        if (DestinationTag::where('destination_id', $d->id)->where('kind', 'amenity')->get()->contains(fn ($t) => mb_strtolower($t->value) === mb_strtolower($label))) continue;
                        $name = $amenity['display_name'] ?? null;
                        if ($name !== null && (! is_string($name) || mb_strlen($name) > 80)) throw new RuntimeException('Invalid amenity display name.');
                        $newAmenities[$key] = ['destination_id' => $d->id, 'kind' => 'amenity', 'value' => $label, 'display_name' => $name];
                    }
                    if ($changes || $newAmenities) {
                        $report['destinations']++;
                        $report['values'] += count($changes);
                        $report['amenities'] += count($newAmenities);
                        $undo[] = ['id' => $d->id, 'slug' => $slug, 'before' => array_intersect_key($d->getAttributes(), $changes), 'after' => $changes, 'amenities_added' => array_values($newAmenities)];
                        if ($apply) {
                            if ($changes) DB::table('destinations')->where('id', $d->id)->update($changes);
                            foreach ($newAmenities as $attributes) {
                                $tag = new DestinationTag(array_diff_key($attributes, ['display_name' => true]));
                                $tag->display_name = $attributes['display_name'];
                                $tag->save();
                            }
                        }
                    }
                }
                // Write the recovery journal before committing the database transaction.
                if ($apply && $undo && ! File::put(storage_path('app/destination-enrichment-'.now()->format('Ymd-His-u').'.json'), json_encode(['manifest' => $manifest, 'changes' => $undo], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
                    throw new RuntimeException('Could not write recovery journal.');
                }

                return $report;
            });
            $this->info(($apply ? 'Applied' : 'Preview').': '.json_encode($report));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Nothing changed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function validateEdit(string $field, mixed $edit): void
    {
        if (! isset(self::LIMITS[$field]) || ! is_array($edit) || ! $this->source($edit['source'] ?? null)) throw new RuntimeException('Disallowed or unsourced field: '.$field);
        $value = $edit['value'] ?? null;
        if (! is_scalar($value) || trim((string) $value) === '' || mb_strlen((string) $value) > self::LIMITS[$field]) throw new RuntimeException('Empty or invalid value: '.$field);
        if (in_array($field, ['latitude', 'longitude'], true)) {
            [$min, $max] = $field === 'latitude' ? [5.5, 8.3] : [125, 126.8];
            if (! is_numeric($value) || $value < $min || $value > $max) throw new RuntimeException('Coordinate outside Davao Region.');
        }
        if (str_starts_with($field, 'entry_fee_') && (! is_numeric($value) || $value < 0)) throw new RuntimeException('Invalid fee.');
        if ($field === 'price_tier' && ! in_array($value, ['Free', 'Budget-Friendly', 'Mid-range', 'Premium'], true)) throw new RuntimeException('Invalid price tier.');
        if (in_array($field, ['website_url', 'facebook_url'], true) && ! $this->source($value)) throw new RuntimeException('Invalid listing URL.');
        if ($field === 'facebook_url' && ! in_array(parse_url($value, PHP_URL_HOST), ['facebook.com', 'www.facebook.com'], true)) throw new RuntimeException('Invalid Facebook URL.');
    }

    private function source(mixed $value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) && in_array(parse_url($value, PHP_URL_SCHEME), ['https', 'http'], true);
    }

    private function same(string $field, mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) return $a === $b;
        if (in_array($field, ['latitude', 'longitude', 'entry_fee_min', 'entry_fee_max'], true)) return is_numeric($a) && is_numeric($b) && abs((float) $a - (float) $b) < 0.00000001;

        return (string) $a === (string) $b;
    }
}
