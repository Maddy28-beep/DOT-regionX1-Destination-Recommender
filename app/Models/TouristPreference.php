<?php

namespace App\Models;

use App\Services\Recommendation\ContentBasedRecommendationService;
use App\Support\Features;
use App\Support\TripWindow;
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
        'departure_date', 'departure_time', 'departure_lat', 'departure_lng', 'departure_label',
        'end_date', 'end_time', 'start_at', 'end_at', 'duration_days',
        'visitor_origin_country_code', 'visitor_origin_region', 'visitor_origin_province', 'visitor_origin_city', 'visitor_origin_source',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'departure_date' => 'date',
            'end_date' => 'date',
            'departure_lat' => 'float',
            'departure_lng' => 'float',
            'origin_lat' => 'float',
            'origin_lng' => 'float',
        ];
    }

    /**
     * The trip's real start, in Philippine time: the moment the traveller is free at the pick-up location.
     * A trip saved before start and end existed is mapped from its arrival date and time (09:00 when none).
     */
    public function tripStart(): ?Carbon
    {
        return TripWindow::fromStorage($this->attributes['start_at'] ?? null) ?? $this->legacyWindow()[0] ?? null;
    }

    /**
     * The trip's real end, in Philippine time. A trip saved before start and end existed ends on its last day at the
     * finish-by time, or at 23:59 when there was none.
     */
    public function tripEnd(): ?Carbon
    {
        return TripWindow::fromStorage($this->attributes['end_at'] ?? null) ?? $this->legacyWindow()[1] ?? null;
    }

    /** The derived length in days (rounded up). The stored value, or the one an older trip works out to. */
    public function tripDurationDays(): ?int
    {
        if (($this->attributes['duration_days'] ?? null) !== null) {
            return (int) $this->attributes['duration_days'];
        }
        $start = $this->tripStart();
        $end = $this->tripEnd();

        return $start && $end ? TripWindow::durationDays($start, $end) : ((int) $this->travel_days ?: null);
    }

    /** @return array{0: Carbon, 1: Carbon}|null */
    private function legacyWindow(): ?array
    {
        return TripWindow::fromLegacy($this->start_date, $this->arrival_time, (int) $this->travel_days, $this->end_time);
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

    /**
     * The day the traveller leaves: the last day of the trip, arrival date + (days - 1). Null without an arrival date.
     * Derived, never entered: the planner places the departure on the last day whatever is stored in departure_date.
     */
    public function departureDate(): ?Carbon
    {
        if ($this->start_date === null) {
            return null;
        }

        return $this->start_date->copy()->startOfDay()->addDays(max(1, (int) $this->travel_days) - 1);
    }

    /**
     * Where and when the traveller leaves, when they said so: the departure time (HH:MM) and the point they leave
     * from. The point is the traveller's own choice -- nothing here guesses an airport -- so a time without a located
     * point reports no point, and the planner says that instead of inventing a transfer.
     *
     * @return array{time: string, point: array{lat: float, lng: float, label: string}|null}|null  null when no departure time was given
     */
    public function departure(): ?array
    {
        // trip_location_v2: there is no departure point any more. The traveller gives a "finish by" time, and the allowance
        // before it covers the journey from the last stop back to where the trip started (the start point, or the Davao City
        // centre baseline when none was given). 'finish' lets the schedule word it as a finish-by time, not a flight.
        if (Features::tripLocation()) {
            if (blank($this->end_time)) {
                return null;
            }

            $start = $this->origin() ?? [
                'lat' => ContentBasedRecommendationService::DEFAULT_ORIGIN_LAT,
                'lng' => ContentBasedRecommendationService::DEFAULT_ORIGIN_LNG,
            ];

            return [
                'time' => substr((string) $this->end_time, 0, 5),
                'point' => $start + ['label' => $this->startLabel()],
                'finish' => true,
            ];
        }

        if (blank($this->departure_time)) {
            return null;
        }

        $point = $this->departure_lat !== null && $this->departure_lng !== null
            ? ['lat' => (float) $this->departure_lat, 'lng' => (float) $this->departure_lng, 'label' => filled($this->departure_label) ? $this->departure_label : 'your departure point']
            : null;

        return ['time' => substr((string) $this->departure_time, 0, 5), 'point' => $point];
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
