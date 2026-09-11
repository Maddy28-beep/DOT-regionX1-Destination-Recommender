<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExitSurvey extends Model
{
    use HasUuids;

    const CREATED_AT = 'submitted_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'preference_id', 'submitted_at', 'residency_type', 'visitor_type', 'origin', 'travel_purpose', 'actual_days_stayed',
        'estimated_daily_spend', 'overall_rating', 'destination_relevant', 'itinerary_useful',
        'attractions_quality', 'accommodation_rating', 'transport_rating',
        'would_recommend', 'comments',
    ];

    /**
     * The trip plan this survey is reporting back on, when the traveller
     * still had one in session. Null for anyone who filled the survey in
     * without ever making a plan -- which is allowed, and why the weight
     * learning treats it as optional evidence rather than required.
     */
    public function preference(): BelongsTo
    {
        return $this->belongsTo(TouristPreference::class, 'preference_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(ExitSurveyVisit::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ExitSurveyActivity::class);
    }
}
