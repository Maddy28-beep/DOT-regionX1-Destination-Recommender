<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Health and accessibility needs stated while planning a trip.
 *
 * Belongs to the preference it was given with, not to a person -- there are
 * no accounts. The recommender uses it to weigh accessibility when ranking
 * destinations, and it lives exactly as long as the plan does.
 */
class TouristHealthProfile extends Model
{
    protected $fillable = ['preference_id', 'consent', 'consent_at', 'other_text'];

    protected function casts(): array
    {
        return [
            'consent' => 'boolean',
            'consent_at' => 'datetime',
        ];
    }

    public function preference(): BelongsTo
    {
        return $this->belongsTo(TouristPreference::class, 'preference_id');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(TouristHealthCondition::class, 'health_profile_id');
    }
}
