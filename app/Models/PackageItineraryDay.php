<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageItineraryDay extends Model
{
    public $timestamps = false;

    protected $fillable = ['package_id', 'day_number', 'title', 'description'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
