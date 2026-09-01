<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Itinerary extends Model
{
    use HasUuids;

    const CREATED_AT = 'generated_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'preference_id', 'total_days', 'est_budget_total',
        'est_party_size', 'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'est_budget_total' => 'decimal:2',
        ];
    }

    public function preference(): BelongsTo
    {
        return $this->belongsTo(TouristPreference::class, 'preference_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ItineraryMatch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ItineraryItem::class);
    }

    /**
     * The places visited each day, in order, ready to map.
     *
     * Built from the whole schedule rather than from the sightseeing stops
     * alone: the restaurant lunch happens at and the hotel the traveller sleeps
     * in are places they have to get to, and leaving them off the map made it
     * describe a day nobody was actually having.
     *
     * Consecutive rows at the same place collapse to one stop -- dinner and the
     * overnight stay are two lines of the schedule but one journey -- and
     * listings without coordinates are kept rather than dropped. They cannot be
     * plotted, but they can still be handed to a maps app by name, and dropping
     * them silently was why a day with four places could show a map of one.
     *
     * @return array<int, array<int, array{label: string, address: string|null, lat: float|null, lng: float|null}>>
     */
    public function routeStops(): array
    {
        $byDay = [];

        foreach ($this->items->sortBy(['day_number', 'sort_order']) as $item) {
            $listing = $item->listing();

            if (! $listing) {
                continue;
            }

            $day = (int) $item->day_number;
            $previous = empty($byDay[$day]) ? null : end($byDay[$day]);

            if ($previous && $previous['label'] === $listing->name) {
                continue;
            }

            $byDay[$day][] = [
                'label' => $listing->name,
                'address' => $listing->location,
                'lat' => $listing->latitude === null ? null : (float) $listing->latitude,
                'lng' => $listing->longitude === null ? null : (float) $listing->longitude,
            ];
        }

        return $byDay;
    }

    /**
     * A Google Maps link covering every stop of a day, in order.
     *
     * Uses the keyless Maps URL scheme rather than the JavaScript or Embed
     * APIs, which both need a billing-enabled API key this project has
     * deliberately never required. It costs nothing, needs no account, and on a
     * phone it opens the Google Maps app with live navigation -- more use to a
     * traveller standing at the gate than an embedded picture of a route.
     *
     * A stop is passed as coordinates when we have them and as "name, address"
     * when we do not; Google resolves the text, so listings we could never plot
     * still appear on the route.
     *
     * @param  array<int, array{label: string, address: string|null, lat: float|null, lng: float|null}>  $stops
     */
    public static function googleMapsUrl(array $stops): ?string
    {
        $points = array_map(static function (array $stop): string {
            if ($stop['lat'] !== null && $stop['lng'] !== null) {
                return $stop['lat'].','.$stop['lng'];
            }

            return trim($stop['label'].', '.($stop['address'] ?? ''), ', ');
        }, $stops);

        if ($points === []) {
            return null;
        }

        // One place is not a route; send it to search so it still opens.
        if (count($points) === 1) {
            return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($points[0]);
        }

        $origin = array_shift($points);
        $destination = array_pop($points);

        $query = [
            'api' => 1,
            'origin' => $origin,
            'destination' => $destination,
            'travelmode' => 'driving',
        ];

        // The URL scheme accepts at most 9 intermediate waypoints.
        if ($points !== []) {
            $query['waypoints'] = implode('|', array_slice($points, 0, 9));
        }

        return 'https://www.google.com/maps/dir/?'.http_build_query($query);
    }
}
