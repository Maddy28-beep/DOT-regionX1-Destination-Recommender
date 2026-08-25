<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TouristPreference extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'tourist_id', 'travel_days', 'travel_type', 'travel_purpose', 'visitor_type', 'budget', 'accommodation_pref',
        'distance_pref', 'accessibility_notes', 'start_date',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
        ];
    }

    public function tourist(): BelongsTo
    {
        return $this->belongsTo(Tourist::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(PreferenceActivity::class, 'preference_id');
    }

    public function amenities(): HasMany
    {
        return $this->hasMany(PreferenceAmenity::class, 'preference_id');
    }

    public function itineraries(): HasMany
    {
        return $this->hasMany(Itinerary::class, 'preference_id');
    }
}
