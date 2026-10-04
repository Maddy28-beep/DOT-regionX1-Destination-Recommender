<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ExitSurveyVisit extends Model
{
    public $timestamps = false;

    /** Rows belonging to a test survey are hidden along with it (see ExitSurvey::booted()). */
    protected static function booted(): void
    {
        static::addGlobalScope('counted', fn ($query) => $query->whereNotIn(
            $query->qualifyColumn('exit_survey_id'),
            fn ($sub) => $sub->select('id')->from('exit_surveys')->where('data_source', 'test')
        ));
    }

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
