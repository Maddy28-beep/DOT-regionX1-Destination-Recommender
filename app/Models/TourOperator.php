<?php

namespace App\Models;

use App\Models\Concerns\HasArchiving;
use App\Models\Concerns\HasListingPhotos;
use App\Models\Concerns\PresentsAsPosterCard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TourOperator extends Model
{
    use HasListingPhotos, HasArchiving, PresentsAsPosterCard;

    const UPDATED_AT = null;

    protected $fillable = [
        'slug', 'name', 'location', 'region_id', 'specialization', 'description',
        'image_path', 'is_accredited', 'rating', 'review_count', 'price_tier',
        'contact_number', 'latitude', 'longitude',
    ];

    protected function casts(): array
    {
        return [
            'is_accredited' => 'boolean',
            'rating' => 'decimal:1',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function posterUrl(): string
    {
        return route('tour-operators.show', $this);
    }

    public function posterScene(): string
    {
        return $this->posterSceneVariant(['tour-van', 'tour-boat']);
    }

    public function posterTags(): array
    {
        return array_values(array_filter([$this->specialization]));
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
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
