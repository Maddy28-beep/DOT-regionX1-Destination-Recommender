<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Itinerary extends Model
{
    use HasUuids;

    const CREATED_AT = 'generated_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'tourist_id', 'preference_id', 'total_days', 'est_budget_total',
        'est_party_size', 'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'est_budget_total' => 'decimal:2',
        ];
    }

    public function tourist(): BelongsTo
    {
        return $this->belongsTo(Tourist::class);
    }

    public function preference(): BelongsTo
    {
        return $this->belongsTo(TouristPreference::class, 'preference_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ItineraryMatch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ItineraryItem::class);
    }
}
