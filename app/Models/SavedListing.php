<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A place someone has hearted, kept against an anonymous browser token rather
 * than an account.
 *
 * Replaces saved_destinations, which was keyed to a tourist and could only
 * hold destinations. This one is polymorphic, so the same control works on
 * destinations, accommodations, restaurants and souvenir centres.
 */
class SavedListing extends Model
{
    public $timestamps = false;

    protected $fillable = ['visitor_token', 'listing_kind', 'listing_id', 'saved_at'];

    protected function casts(): array
    {
        return ['saved_at' => 'datetime'];
    }

    public function listing(): MorphTo
    {
        return $this->morphTo('listing', 'listing_kind', 'listing_id');
    }
}
