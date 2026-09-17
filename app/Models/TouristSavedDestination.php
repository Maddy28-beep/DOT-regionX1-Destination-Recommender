<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A place a logged-in tourist has saved, kept against their account.
 *
 * Deliberately separate from SavedListing (the anonymous, visitor_token-keyed
 * favorites list) rather than merging the two -- an account-scoped save and a
 * browser-scoped save are just different data, and never reconciled.
 */
class TouristSavedDestination extends Model
{
    public $timestamps = false;

    protected $fillable = ['tourist_account_id', 'listing_kind', 'listing_id', 'saved_at'];

    protected function casts(): array
    {
        return ['saved_at' => 'datetime'];
    }

    public function listing(): MorphTo
    {
        return $this->morphTo('listing', 'listing_kind', 'listing_id');
    }

    public function touristAccount(): BelongsTo
    {
        return $this->belongsTo(TouristAccount::class);
    }
}
