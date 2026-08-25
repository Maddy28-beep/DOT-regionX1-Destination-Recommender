<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItineraryMatch extends Model
{
    public $timestamps = false;

    protected $fillable = ['itinerary_id', 'destination_id', 'rank', 'match_score'];

    protected function casts(): array
    {
        return [
            'match_score' => 'decimal:2',
        ];
    }

    public function itinerary(): BelongsTo
    {
        return $this->belongsTo(Itinerary::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }
}
