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
        'issue_date', 'expiration_date', 'verified_by', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expiration_date' => 'date',
            'verified_at' => 'datetime',
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
