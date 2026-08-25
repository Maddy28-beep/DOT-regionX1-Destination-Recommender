<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TouristVisit extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['tourist_id', 'listing_kind', 'listing_id', 'visit_date', 'source'];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
        ];
    }

    public function tourist(): BelongsTo
    {
        return $this->belongsTo(Tourist::class);
    }

    public function listing(): MorphTo
    {
        return $this->morphTo('listing', 'listing_kind', 'listing_id');
    }
}
