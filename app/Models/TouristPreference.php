<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

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
        'travel_days', 'travel_type', 'travel_purpose', 'visitor_type', 'place_of_origin', 'budget', 'accommodation_pref',
        'resort_setting', 'distance_pref', 'transport_pref', 'accessibility_notes', 'start_date', 'arrival_time',
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

    /**
     * What to call the starting point on the page.
     *
     * The label the traveller typed is only the truth when it was located: an address the geocoder could not place
     * leaves the plan sequenced from the regional baseline, and the page must say that rather than "starting from"
     * a place the distances were never measured from.
     */
    public function startLabel(): string
    {
        return $this->origin() !== null && filled($this->origin_label) ? $this->origin_label : 'Davao City centre';
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

    /**
     * The dates this trip covers: the start date the traveller gave (today if
     * none) through the last travel day. Used to skip places that are closed
     * on those dates.
     *
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon}
     */
    public function travelWindow(): array
    {
        // No start date given: today in the Philippines (the server runs on UTC, which is still yesterday until 8 AM).
        $from = ($this->start_date ?? Carbon::parse(now('Asia/Manila')->toDateString()))->copy()->startOfDay();
        $days = max(1, (int) $this->travel_days);

        return [$from, $from->copy()->addDays($days - 1)];
    }
}
