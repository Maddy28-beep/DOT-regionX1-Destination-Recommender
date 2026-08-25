<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExitSurveyActivity extends Model
{
    public $timestamps = false;

    protected $fillable = ['exit_survey_id', 'activity'];

    public function exitSurvey(): BelongsTo
    {
        return $this->belongsTo(ExitSurvey::class);
    }
}
