<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DestinationEmbedding extends Model
{
    protected $fillable = ['destination_id', 'model', 'dimensions', 'text_hash', 'vector'];

    protected function casts(): array
    {
        return ['vector' => 'array'];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }
}
