<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    public $timestamps = false;

    protected $fillable = ['name'];

    public function destinations(): HasMany
    {
        return $this->hasMany(Destination::class);
    }

    public function accommodations(): HasMany
    {
        return $this->hasMany(Accommodation::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function souvenirCenters(): HasMany
    {
        return $this->hasMany(SouvenirCenter::class);
    }

    public function restaurants(): HasMany
    {
        return $this->hasMany(Restaurant::class);
    }

    public function tourOperators(): HasMany
    {
        return $this->hasMany(TourOperator::class);
    }
}
