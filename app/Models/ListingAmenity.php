<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One ticked amenity on a listing (every kind except destinations, whose amenities are destination_tags). */
class ListingAmenity extends Model
{
    public $timestamps = false;

    protected $fillable = ['listing_kind', 'listing_id', 'amenity'];
}
