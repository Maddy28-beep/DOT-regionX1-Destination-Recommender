<?php

namespace App\Models\Concerns;

use App\Models\ListingPhoto;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasListingPhotos
{
    public function photos(): MorphMany
    {
        return $this->morphMany(ListingPhoto::class, 'listing', 'listing_kind', 'listing_id')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order');
    }

    public function coverPhoto(): ?ListingPhoto
    {
        return $this->relationLoaded('photos')
            ? $this->photos->first()
            : $this->photos()->first();
    }
}
