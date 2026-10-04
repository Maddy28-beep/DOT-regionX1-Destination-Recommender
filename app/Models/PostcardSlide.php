<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One slide of the homepage "Popular Right Now" carousel. See the migration
 * for what each column is for.
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
