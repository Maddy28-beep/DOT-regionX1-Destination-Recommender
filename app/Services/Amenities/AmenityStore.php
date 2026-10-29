<?php

namespace App\Services\Amenities;

use App\Models\DestinationTag;
use App\Models\ListingAmenity;
use App\Support\Amenities;
use Illuminate\Database\Eloquent\Model;

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
    public function sync(string $kind, Model $listing, array $keys): array
    {
        $keys = Amenities::allowed($kind, $keys);

        if ($kind === 'destination') {
            $offered = array_map(fn (string $k) => Amenities::label($k), Amenities::FOR_KIND['destination']);
            DestinationTag::where('destination_id', $listing->getKey())->where('kind', 'amenity')->whereIn('value', $offered)->delete();
            foreach ($keys as $key) {
                DestinationTag::firstOrCreate(['destination_id' => $listing->getKey(), 'kind' => 'amenity', 'value' => Amenities::label($key)]);
            }
            $listing->unsetRelation('tags');

            return $keys;
        }

        ListingAmenity::where('listing_kind', $kind)->where('listing_id', $listing->getKey())->delete();
        foreach ($keys as $key) {
            ListingAmenity::create(['listing_kind' => $kind, 'listing_id' => $listing->getKey(), 'amenity' => $key]);
        }

        return $keys;
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
