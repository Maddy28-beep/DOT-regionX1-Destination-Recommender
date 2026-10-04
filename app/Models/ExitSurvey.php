<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExitSurvey extends Model
{
    use HasUuids;

    /**
     * Surveys submitted from /exit-survey?test=1 carry data_source = 'test'.
     * They exist so the pipeline can be tried end to end, and must never reach
     * a count, a chart, a report or an Apriori transaction -- so they are
     * hidden here, once, rather than filtered at every query that reads
     * surveys. Use withoutGlobalScopes() to reach them (see
     * `php artisan exit-survey:purge-test`).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('counted', fn ($query) => $query->where($query->qualifyColumn('data_source'), '!=', 'test'));
    }

    const CREATED_AT = 'submitted_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'preference_id', 'submitted_at', 'residency_type', 'visitor_type', 'origin', 'travel_purpose', 'actual_days_stayed',
        'estimated_total_spend', 'overall_rating', 'destination_relevant', 'itinerary_useful',
        'attractions_quality', 'accommodation_rating', 'transport_rating',
        'would_recommend', 'comments', 'data_source',
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

    /** Sources whose rows are people answering the survey (real tourists, or the simulated demo set). */
    public const RESPONDENT_SOURCES = ['real', 'demo'];

    /**
     * Rows that stand for a respondent. Itinerary baskets (coded from published
     * itineraries) are Apriori transactions only: they have no rating, origin or
     * spend, so every figure about "responses" leaves them out.
     */
    public function scopeRespondents($query)
    {
        return $query->whereIn($query->qualifyColumn('data_source'), self::RESPONDENT_SOURCES);
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
