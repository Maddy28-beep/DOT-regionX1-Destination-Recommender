<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ExitSurveyVisit extends Model
{
    public $timestamps = false;

    protected $fillable = ['exit_survey_id', 'listing_kind', 'listing_id'];

    public function exitSurvey(): BelongsTo
    {
        return $this->belongsTo(ExitSurvey::class);
    }

    public function listing(): MorphTo
    {
        return $this->morphTo('listing', 'listing_kind', 'listing_id');
    }
}
