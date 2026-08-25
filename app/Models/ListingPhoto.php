<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class ListingPhoto extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['listing_kind', 'listing_id', 'path', 'category', 'sort_order', 'is_primary'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function listing(): MorphTo
    {
        return $this->morphTo('listing', 'listing_kind', 'listing_id');
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
