<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedDestination extends Model
{
    const CREATED_AT = 'saved_at';

    const UPDATED_AT = null;

    protected $fillable = ['tourist_id', 'destination_id', 'saved_at'];

    public function tourist(): BelongsTo
    {
        return $this->belongsTo(Tourist::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }
}
