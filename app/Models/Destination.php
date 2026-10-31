<?php

namespace App\Models;

use App\Models\Concerns\HasArchiving;
use App\Models\Concerns\HasOperatingStatus;
use App\Models\Concerns\HasListingPhotos;
use App\Models\Concerns\PresentsAsPosterCard;
use App\Models\Concerns\RanksByRating;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Destination extends Model
{
    use HasListingPhotos, HasArchiving, HasOperatingStatus, PresentsAsPosterCard, RanksByRating;

    const UPDATED_AT = null;

    /**
     * How a destination may be used in a generated plan (see the
     * add_itinerary_role migration): role => [label, what it means].
     */
    public const ITINERARY_ROLES = [
        'sightseeing' => ['Sightseeing attraction', 'Offered to every traveller who fits it.'],
        'optional' => ['Optional (only if the traveller asks)', 'Offered only when the traveller picked the matching interest, for example a spa for "Relaxation & Wellness".'],
        'excluded' => ['Not a sightseeing stop', 'Never offered in a generated itinerary. Use it for event venues and members\' clubs. The listing stays public.'],
    ];

    protected $fillable = [
        'slug', 'name', 'location', 'region_id', 'type', 'itinerary_role', 'description',
        'image_path', 'is_accredited', 'rating', 'review_count', 'price_tier',
        'entry_fee_min', 'entry_fee_max', 'distance_km', 'visit_duration',
        'best_time', 'hours', 'latitude', 'longitude', 'featured',
        'website_url', 'facebook_url', 'instagram_url', 'tiktok_url',
        'planner_verified_at', 'planner_verification_source',
        'planning_notes',
    ];

    protected function casts(): array
    {
        return [
            'is_accredited' => 'boolean',
            'featured' => 'boolean',
            'planner_verified_at' => 'datetime',
            'rating' => 'decimal:1',
            'entry_fee_min' => 'decimal:2',
            'entry_fee_max' => 'decimal:2',
            'distance_km' => 'decimal:1',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** Independently checked visitor sites may be planned without claiming DOT accreditation. */
    public function scopePlannerEligible(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(fn ($q) => $q->where('is_accredited', true)
            ->orWhere(fn ($verified) => $verified->whereNotNull('planner_verified_at')->whereNotNull('planner_verification_source')));
    }

    public function tags(): HasMany
    {
        return $this->hasMany(DestinationTag::class);
    }

    public function itineraryMatches(): HasMany
    {
        return $this->hasMany(ItineraryMatch::class);
    }

    public function itineraryItems(): HasMany
    {
        return $this->hasMany(ItineraryItem::class);
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

    /**
     * How far this place is from the city centre the planner starts from, in km, for the "km from city" line on a
     * card and on the detail page. The distance an editor recorded wins (it is usually a road figure); without one it
     * is the straight line from the place's coordinates, as dining cards already show. Null when the place has
     * neither, so nothing is invented.
     */
    public function distanceFromCityCentreKm(): ?float
    {
        if ($this->distance_km !== null) {
            return (float) $this->distance_km;
        }

        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        $lat1 = deg2rad(\App\Services\Recommendation\ContentBasedRecommendationService::DEFAULT_ORIGIN_LAT);
        $lat2 = deg2rad((float) $this->latitude);
        $dLng = deg2rad((float) $this->longitude - \App\Services\Recommendation\ContentBasedRecommendationService::DEFAULT_ORIGIN_LNG);
        $a = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return round(6371 * 2 * asin(min(1, sqrt($a))), 1);
    }

    /**
     * The entry fee for the listing card, without the peso sign (the card adds its own): "150" for a
     * single price, "150–450" for a range, "50 max" when the minimum is free, each per person. Null when the fee is free or not known,
     * so the card keeps saying "Free entry" or shows the price-band meter instead.
     */
    public function posterPriceAmount(): ?string
    {
        $min = (float) ($this->entry_fee_min ?? 0);
        $max = (float) ($this->entry_fee_max ?? 0);

        if ($min <= 0 && $max <= 0) {
            return null;
        }

        $max = max($min, $max);

        if ($min <= 0) {
            return number_format($max).' max / person'; // free to enter, some activities cost up to this
        }

        return ($min === $max ? number_format($min) : number_format($min).'–'.number_format($max)).' / person';
    }

    public function posterUrl(): string
    {
        return route('destinations.show', $this);
    }

    public function posterScene(): string
    {
        return self::illustrationScene($this->name);
    }

    public function posterTags(): array
    {
        return $this->relationLoaded('tags')
            ? $this->tags->take(2)->pluck('value')->all()
            : [];
    }

    /**
     * Curated flat-vector scene key for the homepage/detail-page poster
     * illustrations (see partials/poster-illustration.blade.php), keyed by
     * name so a rename falls back to the generic scene instead of breaking.
     */
    public static function illustrationScene(string $name): string
    {
        return [
            'Philippine Eagle Center' => 'eagle',
            'Samal Island' => 'island',
            'Eden Nature Park' => 'zipline',
            'Malagos Garden Resort' => 'garden',
            "People's Park" => 'heritage',
            'Davao Crocodile Park' => 'crocodile',
            'Mount Apo Natural Park' => 'mountain-peak',
            'Dahican Beach' => 'surf',
        ][$name] ?? 'default';
    }
}
