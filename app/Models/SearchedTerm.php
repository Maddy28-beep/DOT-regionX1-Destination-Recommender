<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A search term and how many times it has been searched in one catalogue. Anonymous: see the migration.
 */
class SearchedTerm extends Model
{
    public $timestamps = false;

    protected $fillable = ['term', 'label', 'purpose', 'searches', 'last_searched_at'];

    protected function casts(): array
    {
        return ['last_searched_at' => 'datetime'];
    }
}
