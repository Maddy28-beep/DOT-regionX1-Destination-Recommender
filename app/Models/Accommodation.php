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
        'price_tier', 'price_per_night', 'check_in', 'check_out', 'distance_km',
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
            'distance_km' => 'decimal:1',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
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
        return array_values(array_filter([$this->type, $this->dot_classification]));
    }

    public function posterPriceAmount(): ?string
    {
        return $this->price_per_night
            ? number_format($this->price_per_night).' / night'
            : null;
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
