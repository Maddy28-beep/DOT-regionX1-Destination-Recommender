<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Tourist extends Authenticatable
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'full_name',
        'email',
        'password_hash',
        'nationality',
        'age_range',
        'gender',
        'contact_number',
        'privacy_consent',
        'privacy_consent_at',
        'preferred_language',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'privacy_consent' => 'boolean',
            'privacy_consent_at' => 'datetime',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(TouristPreference::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(TouristVisit::class);
    }

    public function savedDestinations(): HasMany
    {
        return $this->hasMany(SavedDestination::class);
    }

    public function itineraries(): HasMany
    {
        return $this->hasMany(Itinerary::class);
    }

    public function chatbotLogs(): HasMany
    {
        return $this->hasMany(ChatbotLog::class);
    }

    public function healthProfile(): HasOne
    {
        return $this->hasOne(TouristHealthProfile::class);
    }

    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'user', 'user_type', 'user_id');
    }
}
