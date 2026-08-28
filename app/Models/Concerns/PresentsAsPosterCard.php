<?php

namespace App\Models\Concerns;

/**
 * Normalises the handful of fields the shared poster card needs so that one
 * Blade partial can render every listing type.
 *
 * The listing models already agree on name/location/region/rating/
 * review_count/is_accredited/price_tier -- everything else they disagree on
 * (which route, which illustration, what counts as a "tag", whether there is
 * a concrete price) is funnelled through the methods below. Adding a new
 * listing type means implementing two abstract methods, not writing another
 * card partial.
 */
trait PresentsAsPosterCard
{
    /** Detail-page URL for this listing. */
    abstract public function posterUrl(): string;

    /** Scene key understood by partials/poster-illustration.blade.php. */
    abstract public function posterScene(): string;

    /** Sub-line under the card title: place, then region if it adds anything. */
    public function posterMeta(): string
    {
        return collect([$this->location, $this->region?->name])
            ->filter()
            ->unique()
            ->implode(' · ');
    }

    /** Up to two short descriptors rendered as chips. */
    public function posterTags(): array
    {
        return [];
    }

    /**
     * How many of the three peso symbols are filled in.
     *
     * 0 renders as "Free" rather than three empty symbols; null means the
     * listing has no tier recorded, so the price row is skipped entirely
     * instead of implying "free".
     */
    public function posterTier(): ?int
    {
        return match ($this->price_tier) {
            'Free' => 0,
            'Budget-Friendly' => 1,
            'Mid-range' => 2,
            'Premium' => 3,
            default => null,
        };
    }

    /**
     * Concrete price shown beside the tier, e.g. "3,500 / night".
     *
     * Returned without the peso sign: the card wraps it in its own
     * <span class="currency"> so the symbol can be styled separately from the
     * digits. Barabara and Alfa Slab One both lack a ₱ glyph, so an unwrapped
     * symbol silently falls back to a mismatched system font.
     */
    public function posterPriceAmount(): ?string
    {
        return null;
    }

    /**
     * Deterministic pick from a list of scene variants, so a listing keeps the
     * same illustration on every page load and neighbouring cards in a grid
     * don't all land on the same one.
     */
    protected function posterSceneVariant(array $scenes): string
    {
        return $scenes[$this->id % count($scenes)];
    }
}
