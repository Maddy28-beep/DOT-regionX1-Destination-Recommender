<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageInclusion extends Model
{
    public $timestamps = false;

    protected $fillable = ['package_id', 'item'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
