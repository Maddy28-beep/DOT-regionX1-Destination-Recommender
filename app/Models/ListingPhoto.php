<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class ListingPhoto extends Model
{
    const UPDATED_AT = null;

    /** The category the sample-image seeder gives generic pictures. */
    public const SAMPLE_CATEGORY = 'Sample image';

    protected $fillable = [
        'listing_kind', 'listing_id', 'path', 'category', 'sort_order', 'is_primary', 'source_url', 'source_name',
        'source_type', 'permission_basis', 'permission_ref', 'permission_date', 'licence', 'credit_text', 'show_credit',
        'verification_status', 'verified_by', 'verified_at',
    ];

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
}
