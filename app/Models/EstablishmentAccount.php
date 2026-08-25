<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

class EstablishmentAccount extends Authenticatable
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'business_name', 'listing_kind', 'claimed_accreditation_number',
        'certificate_file_name', 'matched_listing_id', 'portal_key', 'email',
        'password_hash', 'contact_person', 'contact_number', 'status',
        'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function matchedListing(): MorphTo
    {
        return $this->morphTo('listing', 'listing_kind', 'matched_listing_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }

    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'user', 'user_type', 'user_id');
    }
}
