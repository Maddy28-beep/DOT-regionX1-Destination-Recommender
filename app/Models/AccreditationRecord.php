<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AccreditationRecord extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'listing_kind', 'listing_id', 'accreditation_number', 'status',
        'issue_date', 'expiration_date', 'verified_by', 'verified_at', 'is_placeholder',
    ];

    /** Sample-data labels are read from memory per request; a saved or deleted record means they must be read again. */
    protected static function booted(): void
    {
        $flush = fn () => app(\App\Support\SampleData::class)->flush();
        static::saved($flush);
        static::deleted($flush);
    }

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expiration_date' => 'date',
            'verified_at' => 'datetime',
            'is_placeholder' => 'boolean',
        ];
    }

    public function listing(): MorphTo
    {
        return $this->morphTo('listing', 'listing_kind', 'listing_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'verified_by');
    }
}
