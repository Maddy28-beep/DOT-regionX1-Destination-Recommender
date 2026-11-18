<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Everyone who signs in to a portal: DOT admins and establishments, told apart by `role`. Travelers are not here on purpose;
 * their account is an alias and a password (TouristAccount) and nothing else.
 *
 * AdminUser and EstablishmentAccount are the two views of this table the portals use; each only sees its own people.
 */
class User extends Authenticatable
{
    use HasUuids;

    public const ESTABLISHMENT = 'establishment';

    const UPDATED_AT = null;

    protected $table = 'users';

    protected $fillable = ['role', 'name', 'email', 'password_hash', 'status'];

    protected $hidden = ['password_hash'];

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function isEstablishment(): bool
    {
        return $this->role === self::ESTABLISHMENT;
    }

    public function isAdmin(): bool
    {
        return ! $this->isEstablishment();
    }
}
