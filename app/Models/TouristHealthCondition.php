<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TouristHealthCondition extends Model
{
    public $timestamps = false;

    protected $fillable = ['health_profile_id', 'condition'];

    public function healthProfile(): BelongsTo
    {
        return $this->belongsTo(TouristHealthProfile::class, 'health_profile_id');
    }
}
