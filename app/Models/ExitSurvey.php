<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExitSurvey extends Model
{
    use HasUuids;

    const CREATED_AT = 'submitted_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'submitted_at', 'residency_type', 'visitor_type', 'origin', 'travel_purpose', 'actual_days_stayed',
        'overall_rating', 'destination_relevant', 'itinerary_useful',
        'attractions_quality', 'accommodation_rating', 'transport_rating',
        'would_recommend', 'comments',
    ];

    public function visits(): HasMany
    {
        return $this->hasMany(ExitSurveyVisit::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ExitSurveyActivity::class);
    }
}
