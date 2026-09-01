<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One destination's place in the computed ranking, and the working behind it.
 *
 * match_score is the Destination Recommendation Score; pm/rs/ps/ds/as are the
 * five factors it was built from (Sec. 2.3.3, Equation 3).
 *
 * The factors are stored but not shown to travellers -- rendering all five made
 * the recommendation list unreadable. They are kept because they are the only
 * record of how a ranking was reached, which is the question an establishment
 * or a reviewer asks, and answering it from stored numbers beats recomputing
 * and hoping the answer still matches.
 */
class ItineraryMatch extends Model
{
    public $timestamps = false;

    protected $fillable = ['itinerary_id', 'destination_id', 'rank', 'match_score', 'pm', 'rs', 'ps', 'ds', 'as'];

    protected function casts(): array
    {
        return [
            'match_score' => 'decimal:2',
            'pm' => 'float',
            'rs' => 'float',
            'ps' => 'float',
            'ds' => 'float',
            'as' => 'float',
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
