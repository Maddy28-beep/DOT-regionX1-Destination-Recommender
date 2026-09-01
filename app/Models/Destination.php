<?php

namespace App\Models;

use App\Models\Concerns\HasArchiving;
use App\Models\Concerns\HasListingPhotos;
use App\Models\Concerns\PresentsAsPosterCard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Destination extends Model
{
    use HasListingPhotos, HasArchiving, PresentsAsPosterCard;

    const UPDATED_AT = null;

    protected $fillable = [
        'slug', 'name', 'location', 'region_id', 'type', 'description',
        'image_path', 'is_accredited', 'rating', 'review_count', 'price_tier',
        'entry_fee_min', 'entry_fee_max', 'distance_km', 'visit_duration',
        'best_time', 'hours', 'latitude', 'longitude', 'featured',
    ];

    protected function casts(): array
    {
        return [
            'is_accredited' => 'boolean',
            'featured' => 'boolean',
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
