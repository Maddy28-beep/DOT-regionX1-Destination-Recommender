<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Review extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['listing_kind', 'listing_id', 'author_name', 'rating', 'comment', 'owner_reply', 'owner_replied_at'];

    protected function casts(): array
    {
        return [
            'owner_replied_at' => 'datetime',
        ];
    }

    public function listing(): MorphTo
    {
        return $this->morphTo('listing', 'listing_kind', 'listing_id');
    }
}
