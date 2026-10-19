<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One mood card of the landing page's "What Davao is known for" row (it began as a slide of the
 * "Popular Right Now" carousel, hence the table name). See the migration for what each column is for.
 */
class PostcardSlide extends Model
{
    /** Web path prefix of files written by the admin upload (public disk, via the storage symlink). */
    public const UPLOAD_PREFIX = 'storage/postcards/';

    protected $fillable = [
        'kicker', 'title', 'location', 'cta_label', 'cta_url',
        'image_path', 'image_srcset', 'thumb_path', 'alt_text',
        'focal_x', 'focal_y', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'image_srcset' => 'array',
            'focal_x' => 'integer',
            'focal_y' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function imageUrl(): string
    {
        // Higher-resolution restorations for the four bundled photographs.
        // Admin-uploaded replacements continue to use their own files.
        if (in_array($this->image_path, array_map(
            fn ($name) => 'images/postcards/'.$name.'.webp',
            ['cultural-heritage', 'mountain-peak', 'wildlife', 'island-beach']
        ), true)) {
            $enhanced = str_replace('.webp', '-enhanced.webp', $this->image_path);
            if (is_file(public_path($enhanced))) {
                return asset($enhanced);
            }
        }

        return asset($this->image_path);
    }

    /**
     * The photo at about the size a mood card shows it (a few hundred pixels wide), so a card does not download the
     * full-size picture. A bundled photograph has a "-card" copy beside it; an uploaded one has its smaller widths
     * in image_srcset, and the smallest that is at least 480px is used.
     */
    public function cardUrl(): string
    {
        $card = preg_replace('/\.webp$/', '-card.webp', $this->image_path);
        if ($card !== $this->image_path && is_file(public_path($card))) {
            return asset($card);
        }

        $variant = collect($this->image_srcset ?? [])->sortBy('w')->first(fn (array $v) => ($v['w'] ?? 0) >= 480);

        return $variant ? asset($variant['path']) : $this->imageUrl();
    }

    public function thumbUrl(): string
    {
        return asset($this->thumb_path);
    }

    /** "…/w640.webp 640w, …/w1280.webp 1280w, …/full.webp 1920w", or null when only one size exists. */
    public function srcset(): ?string
    {
        $sizes = collect($this->image_srcset ?? [])
            ->map(fn (array $v) => asset($v['path']).' '.$v['w'].'w');

        return $sizes->isEmpty() ? null : $sizes->implode(', ');
    }

    /** CSS focal point, e.g. "30% 45%". */
    public function focalPoint(): string
    {
        return $this->focal_x.'% '.$this->focal_y.'%';
    }

    /** Catalogue path => the model that lists it, for counting what a card's link leads to. */
    private const CATALOGUES = [
        'destinations' => \App\Models\Destination::class,
        'accommodations' => \App\Models\Accommodation::class,
        'restaurants' => \App\Models\Restaurant::class,
        'packages' => \App\Models\Package::class,
        'souvenir-centers' => \App\Models\SouvenirCenter::class,
        'tour-operators' => \App\Models\TourOperator::class,
    ];

    /**
     * How many places the card's link leads to, or null when it does not lead to a catalogue (a single place's
     * own page, an outside link). It applies the same filters the catalogue page applies to the same link, so
     * the number on the card is the number of results the click lands on.
     */
    public function listingCount(): ?int
    {
        $parts = parse_url($this->cta_url) ?: [];
        $model = self::CATALOGUES[trim((string) ($parts['path'] ?? ''), '/')] ?? null;

        if ($model === null || isset($parts['host'])) {
            return null;
        }

        parse_str((string) ($parts['query'] ?? ''), $filters);

        $query = method_exists($model, 'scopePubliclyVisible') ? $model::publiclyVisible() : $model::query();

        foreach (['type', 'cuisine_type', 'price_tier', 'region_id', 'specialization'] as $column) {
            if (! empty($filters[$column]) && is_scalar($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['interest']) && is_scalar($filters['interest']) && $model === \App\Models\Destination::class) {
            $query->whereIn('type', \App\Services\Recommendation\ContentBasedRecommendationService::typesForInterest((string) $filters['interest']));
        }

        if (! empty($filters['q']) && is_scalar($filters['q'])) {
            \App\Support\SearchTerm::contains($query, 'name', (string) $filters['q']);
        }

        return $query->count();
    }

    /** Whether the photo's file is actually there, so a missing one shows the placeholder rather than a broken image. */
    public function hasImageFile(): bool
    {
        return is_file(public_path($this->image_path));
    }

    /** The CTA target as an href: stored paths are root-relative, so they follow the app URL. */
    public function ctaHref(): string
    {
        return str_starts_with($this->cta_url, '/') ? url($this->cta_url) : $this->cta_url;
    }

    /** Directory (on the public disk) this slide's uploaded files live in, or null for files shipped with the app. */
    public function uploadDirectory(): ?string
    {
        if (! str_starts_with($this->image_path, self::UPLOAD_PREFIX)) {
            return null;
        }

        return dirname(substr($this->image_path, strlen('storage/')));
    }
}
