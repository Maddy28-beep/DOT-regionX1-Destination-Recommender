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

    /** Every photo, including those still waiting for DOT: for the establishment's own pages only. */
    public function photosWithDrafts(): MorphMany
    {
        return $this->morphMany(ListingPhoto::class, 'listing', 'listing_kind', 'listing_id')
            ->withoutGlobalScopes()
            ->orderByDesc('is_primary')
            ->orderBy('sort_order');
    }

    public function coverPhoto(): ?ListingPhoto
    {
        return $this->relationLoaded('photos')
            ? $this->photos->first(fn ($photo) => !$photo->isAmenityPhoto())
            : $this->photos()->coverEligible()->first();
    }
}
