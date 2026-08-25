<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TouristHealthProfile extends Model
{
    protected $fillable = ['tourist_id', 'consent', 'consent_at', 'other_text'];

    protected function casts(): array
    {
        return [
            'consent' => 'boolean',
            'consent_at' => 'datetime',
        ];
    }

    public function tourist(): BelongsTo
    {
        return $this->belongsTo(Tourist::class);
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(TouristHealthCondition::class, 'health_profile_id');
    }
}
