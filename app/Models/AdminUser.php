<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A DOT admin: a row of `users` whose role is not "establishment". */
class AdminUser extends User
{
    protected $fillable = ['email', 'password_hash', 'full_name', 'role', 'name'];

    protected static function booted(): void
    {
        static::addGlobalScope('admins', fn (Builder $q) => $q->where('users.role', '!=', User::ESTABLISHMENT));
    }

    /** The admin's name; kept as full_name because that is what the screens and the seeders say. */
    protected function fullName(): Attribute
    {
        return Attribute::make(get: fn ($value, array $attributes) => $attributes['name'] ?? null, set: fn ($value) => ['name' => $value]);
    }

    public function verifiedAccreditations(): HasMany
    {
        return $this->hasMany(AccreditationRecord::class, 'verified_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(SystemAuditLog::class, 'admin_id');
    }
}
