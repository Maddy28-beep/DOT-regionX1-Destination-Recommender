<?php

namespace App\Models;

use App\Support\PhotoCardCopy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class ListingPhoto extends Model
{
    const UPDATED_AT = null;

    /** The category the sample-image seeder gives generic pictures. */
    public const SAMPLE_CATEGORY = 'Sample image';

    protected $fillable = [
        'review_status', 'listing_kind', 'listing_id', 'path', 'category', 'amenity', 'sort_order', 'is_primary', 'source_url', 'source_name',
        'source_type', 'permission_basis', 'permission_ref', 'permission_date', 'licence', 'credit_text', 'show_credit',
        'verification_status', 'verified_by', 'verified_at',
    ];

    /**
     * A photo (or offer) an establishment has added waits hidden until DOT approves it (review_status is set); only an
     * approved one, with no review_status, is ever public. The establishment's own pages and the admin queue read
     * through withoutGlobalScopes().
     */
    protected static function booted(): void
    {
        static::addGlobalScope('approved', fn (Builder $query) => $query->whereNull($query->getModel()->getTable().'.review_status'));
    }

    /** The establishment's routes name a photo that may still be waiting; ownership is checked there. */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->withoutGlobalScopes()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'show_credit' => 'boolean',
            'permission_date' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    /** A placeholder drawing or a generic sample picture: not a photograph of this place. */
    public function isIllustration(): bool
    {
        return str_ends_with(strtolower((string) $this->path), '.svg') || $this->category === self::SAMPLE_CATEGORY;
    }

    /** Shared-building imagery must never be presented as a photo of the rental unit. */
    public function scopeLabel(): ?string
    {
        return str_starts_with((string) $this->category, 'Complex exterior')
            ? 'Complex exterior · Unit not shown' : null;
    }

    /** Facility/accessibility evidence belongs with that feature, never as a listing cover. */
    public function isAmenityPhoto(): bool
    {
        return filled($this->amenity) || in_array(strtolower(trim((string) $this->category)), ['amenities', 'accessibility'], true);
    }

    public function scopeCoverEligible(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('amenity')->orWhereRaw("TRIM(amenity) = ''"))
            ->where(fn ($q) => $q->whereNull('category')->orWhereRaw("LOWER(TRIM(category)) NOT IN ('amenities', 'accessibility')"));
    }

    /**
     * The credit shown beside the photograph, or null when there is nothing to say (an illustration, or a photo
     * whose owner asked for no credit). A written credit wins; otherwise the recorded source is named, so no
     * picture of unknown origin is shown as if it were anyone's own.
     */
    public function creditLine(): ?string
    {
        if ($this->isIllustration() || $this->show_credit === false) {
            return null;
        }

        $credit = trim((string) $this->credit_text);
        if ($credit !== '') {
            return 'Photo: '.$credit;
        }

        $source = trim((string) $this->source_name);

        return $source !== '' ? 'Source: '.$source : null;
    }

    public function listing(): MorphTo
    {
        return $this->morphTo('listing', 'listing_kind', 'listing_id');
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    /**
     * The picture for a list card. When a smaller card copy exists (made by `php artisan photos:make-card-copies`) that is
     * used, otherwise the original, so nothing breaks where no copy has been made. Detail pages and galleries keep url().
     */
    public function cardUrl(): string
    {
        static $known = [];

        $variant = PhotoCardCopy::variantPath((string) $this->path);
        $known[$variant] ??= ! $this->isIllustration() && Storage::disk('public')->exists($variant);

        return $known[$variant] ? Storage::disk('public')->url($variant) : $this->url();
    }
}
