<?php

namespace App\Models\Concerns;

use App\Models\ListingPhoto;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasListingPhotos
{
    public function photos(): MorphMany
    {
        return $this->morphMany(ListingPhoto::class, 'listing', 'listing_kind', 'listing_id')
            ->orderByRaw("CASE WHEN path LIKE '%.svg' THEN 2 WHEN category = 'Sample image' THEN 1 ELSE 0 END")
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
