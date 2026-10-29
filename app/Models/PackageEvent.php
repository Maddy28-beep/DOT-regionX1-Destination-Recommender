<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in a package's history: who sent it, approved it, asked for changes or paused it, and the note. */
class PackageEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['package_id', 'actor_type', 'actor_name', 'action', 'note'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
