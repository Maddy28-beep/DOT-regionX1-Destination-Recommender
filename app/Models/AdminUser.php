<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class AdminUser extends Authenticatable
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'username',
        'password_hash',
        'full_name',
        'role',
    ];

    protected $hidden = ['password_hash'];

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function verifiedAccreditations(): HasMany
    {
        return $this->hasMany(AccreditationRecord::class, 'verified_by');
    }

    public function reviewedEstablishments(): HasMany
    {
        return $this->hasMany(EstablishmentAccount::class, 'reviewed_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(SystemAuditLog::class, 'admin_id');
    }
}
