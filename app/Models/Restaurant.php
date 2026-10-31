<?php

namespace App\Models;

use App\Models\Concerns\HasArchiving;
use App\Models\Concerns\HasOperatingStatus;
use App\Models\Concerns\HasListingPhotos;
use App\Models\Concerns\PresentsAsPosterCard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Restaurant extends Model
{
    use HasListingPhotos, HasArchiving, HasOperatingStatus, PresentsAsPosterCard;

    const UPDATED_AT = null;

    protected $fillable = [
        'slug', 'name', 'location', 'region_id', 'cuisine_type', 'description',
        'image_path', 'is_accredited', 'rating', 'review_count', 'price_tier',
        'opening_hours', 'contact_number', 'latitude', 'longitude',
        'website_url', 'facebook_url', 'instagram_url', 'tiktok_url',
        'planner_verified_at', 'planner_verification_source', 'host_destination_id', 'host_accommodation_id',
    ];

    protected function casts(): array
    {
        return [
            'is_accredited' => 'boolean',
            'planner_verified_at' => 'datetime',
            'rating' => 'decimal:1',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function posterUrl(): string
    {
        return route('restaurants.show', $this);
    }

    public function scopePlannerEligible(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(fn ($q) => $q->where('is_accredited', true)
            ->orWhere(fn ($v) => $v->whereNotNull('planner_verified_at')->whereNotNull('planner_verification_source')));
    }

    public function posterScene(): string
    {
        return $this->posterSceneVariant(['dining', 'dining-cafe']);
    }

    public function posterTags(): array
    {
        return array_values(array_filter([$this->cuisine_type]));
    }

    /**
     * Straight-line distance from the city centre the recommender plans from, in km; null when the place has no
     * map location. Used for the "km from city" line on a dining card, as destinations show theirs.
     */
    public function distanceFromCityCentreKm(): ?float
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        $lat1 = deg2rad(\App\Services\Recommendation\ContentBasedRecommendationService::DEFAULT_ORIGIN_LAT);
        $lat2 = deg2rad((float) $this->latitude);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad((float) $this->longitude - \App\Services\Recommendation\ContentBasedRecommendationService::DEFAULT_ORIGIN_LNG);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return round(6371 * 2 * asin(min(1, sqrt($a))), 1);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function reviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'listing', 'listing_kind', 'listing_id');
    }

    public function visits(): MorphMany
    {
        return $this->morphMany(TouristVisit::class, 'listing', 'listing_kind', 'listing_id');
    }

    public function accreditationRecords(): MorphMany
    {
        return $this->morphMany(AccreditationRecord::class, 'listing', 'listing_kind', 'listing_id');
    }
}
