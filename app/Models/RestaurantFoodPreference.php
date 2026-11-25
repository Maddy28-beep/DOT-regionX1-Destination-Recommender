<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantFoodPreference extends Model
{
    public const OPTIONS = ['halal_certified' => 'Halal-certified', 'vegetarian' => 'Vegetarian options', 'vegan' => 'Vegan options'];

    protected $fillable = ['restaurant_id', 'preference', 'status', 'source_url', 'source_name', 'evidence', 'checked_on',
        'certifier', 'certificate_reference', 'coverage', 'valid_from', 'expires_on'];

    protected function casts(): array
    {
        return array_fill_keys(['checked_on', 'valid_from', 'expires_on'], 'date');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** A menu claim never becomes a certificate; incomplete/expired evidence is treated as unknown. */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', 'confirmed')->whereIn('preference', array_keys(self::OPTIONS))
            ->whereNotNull('source_url')->where('source_url', 'like', 'https://%')
            ->whereNotNull('source_name')->where('source_name', '!=', '')
            ->whereNotNull('evidence')->where('evidence', '!=', '')
            ->whereDate('checked_on', '<=', today()->toDateString())
            ->where(function ($q) {
                $q->where('preference', '!=', 'halal_certified')->orWhere(function ($q) {
                    $q->where('preference', 'halal_certified')
                        ->whereNotNull('certifier')->where('certifier', '!=', '')
                        ->whereNotNull('certificate_reference')->where('certificate_reference', '!=', '')
                        ->whereNotNull('coverage')->where('coverage', '!=', '')
                        ->whereDate('valid_from', '<=', today()->toDateString())
                        ->whereDate('expires_on', '>=', today()->toDateString());
                });
            });
    }
}
