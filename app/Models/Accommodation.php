<?php

namespace App\Models;

use App\Models\Concerns\HasArchiving;
use App\Models\Concerns\HasOperatingStatus;
use App\Models\Concerns\HasListingPhotos;
use App\Models\Concerns\PresentsAsPosterCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Accommodation extends Model
{
    use HasListingPhotos, HasArchiving, HasOperatingStatus, PresentsAsPosterCard;

    const UPDATED_AT = null;

    /**
     * The settings a resort can be filed under, in the order the planner offers them. A resort may carry
     * more than one ("Lake" and "All-Inclusive"); see resort_settings.
     */
    public const RESORT_SETTINGS = ['Beach', 'Mountain', 'Inland', 'Island', 'Lake', 'Hot Spring', 'All-Inclusive'];

    protected $fillable = [
        'slug', 'name', 'location', 'region_id', 'type', 'resort_settings', 'dot_classification',
        'description', 'image_path', 'is_accredited', 'rating', 'review_count',
        'price_tier', 'price_per_night', 'price_per_day', 'check_in', 'check_out', 'distance_km',
        'featured', 'latitude', 'longitude',
        'website_url', 'facebook_url', 'instagram_url', 'tiktok_url',
    ];

    protected function casts(): array
    {
        return [
            'is_accredited' => 'boolean',
            'featured' => 'boolean',
            'rating' => 'decimal:1',
            'price_per_night' => 'decimal:2',
            'price_per_day' => 'decimal:2',
            'distance_km' => 'decimal:1',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'coordinate_provenance' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $stay) {
            // A later owner/admin pin edit must not retain attribution for an older researched point.
            if ($stay->isDirty(['latitude', 'longitude']) && ! $stay->isDirty('coordinate_provenance')) {
                $stay->coordinate_provenance = null;
            }
        });
    }

    /**
     * Resorts filed under the given setting. The column holds "|Island|Lake|", so matching the setting between
     * its delimiters keeps "Inland" from matching anything it is merely a part of.
     */
    public function scopeWithResortSetting(Builder $query, string $setting): Builder
    {
        if ($setting === 'Beach') {
            return $query->where(fn (Builder $q) => $q
                ->where('type', 'Beach Resort')
                ->orWhere('resort_settings', 'like', '%|Beach|%')
                ->orWhere('name', 'like', '%Beach%'));
        }

        return $query->where('resort_settings', 'like', '%|'.$setting.'|%');
    }

    /** The column's stored form: "|Island|All-Inclusive|", or null for none. Unknown settings are dropped. */
    public static function encodeResortSettings(array $settings): ?string
    {
        $known = array_values(array_filter(self::RESORT_SETTINGS, fn ($s) => in_array($s, $settings, true)));

        return $known === [] ? null : '|'.implode('|', $known).'|';
    }

    /** @return list<string> the settings this listing is filed under, empty when unclassified */
    public function resortSettingList(): array
    {
        return array_values(array_filter(explode('|', (string) $this->resort_settings)));
    }

    /** @return list<string> human-readable resort classifications (e.g. "Mountain Resort", "Inland Resort") */
    public function resortClassificationLabels(): array
    {
        $settings = $this->resortSettingList();
        if ($this->type === 'Beach Resort' && ! in_array('Beach', $settings, true)) {
            array_unshift($settings, 'Beach');
        }

        return array_map(
            fn (string $s) => ($s === 'All-Inclusive' ? 'All Inclusive' : $s).' Resort',
            $settings
        );
    }

    public function posterUrl(): string
    {
        return route('accommodations.show', $this);
    }

    public function posterScene(): string
    {
        return $this->posterSceneVariant(['resort', 'resort-pool']);
    }

    public function posterTags(): array
    {
        $classifications = $this->resortClassificationLabels();
        $primary = $classifications !== [] ? implode(' · ', array_slice($classifications, 0, 2)) : $this->type;

        return array_values(array_filter([$primary, $this->dot_classification]));
    }

    public function posterPriceAmount(): ?string
    {
        return $this->price_per_night
            ? number_format($this->price_per_night).' / night'
            : null;
    }

    public function nightlyPricing(): array
    {
        return \App\Support\StayPricing::nightly($this);
    }

    /** Preserve recorded distances; otherwise estimate a straight line from the planner's Davao origin. */
    public function distanceFromCityCentreKm(): ?float
    {
        if ($this->distance_km !== null) {
            return (float) $this->distance_km;
        }
        if ($this->latitude === null || $this->longitude === null
            || ! is_finite((float) $this->latitude) || ! is_finite((float) $this->longitude)
            || abs((float) $this->latitude) > 90 || abs((float) $this->longitude) > 180) {
            return null;
        }
        $lat1 = deg2rad(\App\Services\Recommendation\ContentBasedRecommendationService::DEFAULT_ORIGIN_LAT);
        $lat2 = deg2rad((float) $this->latitude);
        $dLng = deg2rad((float) $this->longitude - \App\Services\Recommendation\ContentBasedRecommendationService::DEFAULT_ORIGIN_LNG);
        $a = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return round(6371 * 2 * asin(sqrt(max(0, min(1, $a)))), 1);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function roomTypes(): HasMany
    {
        return $this->hasMany(AccommodationRoomType::class);
    }

    public function itineraryItems(): HasMany
    {
        return $this->hasMany(ItineraryItem::class);
    }

    public function reviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'listing', 'listing_kind', 'listing_id');
    }

    public function visits(): MorphMany
    {
        return $this->morphMany(TouristVisit::class, 'listing', 'listing_kind', 'listing_id');
    }

    public function accreditationRecords(): MorphMany
    {
        return $this->morphMany(AccreditationRecord::class, 'listing', 'listing_kind', 'listing_id');
    }
}
