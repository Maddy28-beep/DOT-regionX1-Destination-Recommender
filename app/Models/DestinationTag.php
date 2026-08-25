<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DestinationTag extends Model
{
    public $timestamps = false;

    protected $fillable = ['destination_id', 'kind', 'value'];

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }
}
