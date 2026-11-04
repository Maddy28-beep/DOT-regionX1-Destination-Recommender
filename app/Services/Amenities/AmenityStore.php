<?php

namespace App\Services\Amenities;

use App\Models\DestinationTag;
use App\Models\ListingAmenity;
use App\Support\Amenities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes the ticked amenities of any listing, by catalog key.
 *
 * Destinations keep theirs as destination_tags (kind "amenity", the value being the catalog label), because the
 * recommender and the embeddings read them there; everything else uses listing_amenities. Callers never need to know
 * which: they ask for keys and give keys.
 */
class AmenityStore
{
    /** @return array<int, string> catalog keys, in catalog order */
    public function keys(string $kind, Model $listing): array
    {
        $ticked = $kind === 'destination'
            ? $this->destinationKeys($listing)
            : ListingAmenity::where('listing_kind', $kind)->where('listing_id', $listing->getKey())->pluck('amenity')->all();

        return array_values(array_filter(array_keys(Amenities::CATALOG), fn (string $k) => in_array($k, $ticked, true)));
    }

    /** Replace what is ticked with $keys (only choices this kind offers are kept). Values saved some other way are left alone. */
    public function sync(string $kind, Model $listing, array $keys, array $displayNames = []): array
    {
        $keys = Amenities::allowed($kind, $keys);

        DB::transaction(function () use ($kind, $listing, $keys, $displayNames) {
            $listing->newQuery()->whereKey($listing->getKey())->lockForUpdate()->firstOrFail();
            [$query, $column] = $this->records($kind, $listing);
            $value = fn (string $key) => $kind === 'destination' ? Amenities::label($key) : $key;
            $query->whereIn($column, array_map($value, Amenities::keysFor($kind)))
                ->whereNotIn($column, array_map($value, $keys))->delete();

            foreach ($keys as $key) {
                $identity = $kind === 'destination'
                    ? ['destination_id' => $listing->getKey(), 'kind' => 'amenity', 'value' => $value($key)]
                    : ['listing_kind' => $kind, 'listing_id' => $listing->getKey(), 'amenity' => $key];
                $record = $query->getModel()->newQuery()->firstOrCreate($identity);
                if (array_key_exists($key, $displayNames)) {
                    $name = trim((string) $displayNames[$key]);
                    $record->display_name = $name === '' ? null : $name;
                    $record->save();
                }
            }
        });
        $listing->unsetRelation('tags');

        return $keys;
    }

    public function displayNames(string $kind, Model $listing): array
    {
        [$query, $column] = $this->records($kind, $listing);
        $names = $query->whereNotNull('display_name')->pluck('display_name', $column);
        $labels = Amenities::keysByLabel();

        return $names->mapWithKeys(fn ($name, $value) => [$kind === 'destination' ? ($labels[$value] ?? $value) : $value => $name])->all();
    }

    private function records(string $kind, Model $listing): array
    {
        return $kind === 'destination'
            ? [DestinationTag::where('destination_id', $listing->getKey())->where('kind', 'amenity'), 'value']
            : [ListingAmenity::where('listing_kind', $kind)->where('listing_id', $listing->getKey()), 'amenity'];
    }

    /** Tick one more (used when a photo says it shows an amenity that is not ticked yet). */
    public function add(string $kind, Model $listing, string $key): void
    {
        if (! in_array($key, Amenities::FOR_KIND[$kind] ?? [], true)) {
            return;
        }

        $this->sync($kind, $listing, array_merge($this->keys($kind, $listing), [$key]));
    }

    /** @return array<int, string> */
    private function destinationKeys(Model $listing): array
    {
        $byLabel = Amenities::keysByLabel();

        return DestinationTag::where('destination_id', $listing->getKey())->where('kind', 'amenity')->pluck('value')
            ->map(fn ($v) => $byLabel[$v] ?? null)->filter()->values()->all();
    }
}
