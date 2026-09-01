<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One trip's travel-preference survey.
 *
 * Anonymous: it belongs to a planning session, not to a person. The site has
 * no tourist accounts, so nothing here identifies who filled it in.
 */
class TouristPreference extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'travel_days', 'travel_type', 'travel_purpose', 'visitor_type', 'budget', 'accommodation_pref',
        'distance_pref', 'accessibility_notes', 'start_date', 'arrival_time',
        'origin_lat', 'origin_lng', 'origin_label', 'variation',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'origin_lat' => 'float',
            'origin_lng' => 'float',
        ];
    }

    /**
     * Where this trip starts, if the traveller shared it.
     *
     * Null when they declined or the browser could not supply a position --
     * callers fall back to the region's default baseline rather than treating
     * a missing location as an error.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function origin(): ?array
    {
        if ($this->origin_lat === null || $this->origin_lng === null) {
            return null;
        }

        return ['lat' => (float) $this->origin_lat, 'lng' => (float) $this->origin_lng];
    }

    /** Health and accessibility needs stated while planning this trip, if any. */
    public function healthProfile(): HasOne
    {
        return $this->hasOne(TouristHealthProfile::class, 'preference_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(PreferenceActivity::class, 'preference_id');
    }

    public function amenities(): HasMany
    {
        return $this->hasMany(PreferenceAmenity::class, 'preference_id');
    }

    public function itineraries(): HasMany
    {
        return $this->hasMany(Itinerary::class, 'preference_id');
    }
}
