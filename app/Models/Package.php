<?php

namespace App\Models;

use App\Models\Concerns\HasArchiving;
use App\Models\Concerns\HasListingPhotos;
use App\Models\Concerns\PresentsAsPosterCard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Package extends Model
{
    use HasListingPhotos, HasArchiving, PresentsAsPosterCard;

    const UPDATED_AT = null;

    protected $fillable = [
        'slug', 'name', 'location', 'region_id', 'duration_label', 'duration_days',
        'description', 'image_path', 'is_accredited', 'price_per_pax', 'price_tier',
        'rating', 'review_count', 'type', 'featured', 'provider_name', 'tour_operator_id', 'latitude', 'longitude',
    ];

    protected function casts(): array
    {
        return [
            'is_accredited' => 'boolean',
            'featured' => 'boolean',
            'rating' => 'decimal:1',
            'price_per_pax' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function posterUrl(): string
    {
        return route('packages.show', $this);
    }

    public function posterScene(): string
    {
        return self::illustrationScene($this->name);
    }

    /** A package's sub-line leads with how long it runs, not where it starts. */
    public function posterMeta(): string
    {
        return collect([$this->duration_label, $this->region?->name])
            ->filter()
            ->unique()
            ->implode(' · ');
    }

    public function posterTags(): array
    {
        return array_values(array_filter([$this->type, $this->provider_name]));
    }

    public function posterPriceAmount(): ?string
    {
        return $this->price_per_pax
            ? number_format($this->price_per_pax).' / pax'
            : null;
    }

    /**
     * Keyed by name so a rename falls back to the generic scene instead of
     * breaking, matching Destination::illustrationScene().
     */
    public static function illustrationScene(string $name): string
    {
        return [
            'Mount Apo 3-Day Summit Trek' => 'mountain-peak',
            'Dahican Beach Surf & Chill Package' => 'surf',
            'Samal Island Hopping Day Tour' => 'island',
        ][$name] ?? 'default';
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function tourOperator(): BelongsTo
    {
        return $this->belongsTo(TourOperator::class);
    }

    public function inclusions(): HasMany
    {
        return $this->hasMany(PackageInclusion::class);
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
