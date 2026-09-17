<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A deliberately minimal, optional account: an alias and a password, nothing
 * else. It exists solely so a traveler who wants to keep an itinerary past
 * the browser session has somewhere to put it -- it is not, and must never
 * become, an identity system. See 2026_09_13_000000_create_tourist_accounts_table.
 */
class TouristAccount extends Authenticatable
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = ['alias', 'password_hash'];

    protected $hidden = ['password_hash'];

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function savedItineraries(): HasMany
    {
        return $this->hasMany(Itinerary::class, 'tourist_account_id')->latest('updated_at');
    }

    public function savedDestinations(): HasMany
    {
        return $this->hasMany(TouristSavedDestination::class);
    }
}
