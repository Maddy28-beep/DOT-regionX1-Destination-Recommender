<?php

namespace App\Models;

use App\Models\Concerns\HasArchiving;
use App\Models\Concerns\HasListingPhotos;
use App\Models\Concerns\PresentsAsPosterCard;
use App\Models\Concerns\RanksByRating;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Package extends Model
{
    use HasListingPhotos, HasArchiving, PresentsAsPosterCard, RanksByRating;

    const UPDATED_AT = null;

    protected $fillable = [
        'slug', 'name', 'location', 'region_id', 'duration_label', 'duration_days',
        'description', 'image_path', 'is_accredited', 'price_per_pax', 'price_tier',
        'rating', 'review_count', 'type', 'featured', 'provider_name', 'tour_operator_id', 'latitude', 'longitude',
        'website_url', 'facebook_url', 'instagram_url', 'tiktok_url',
        'status', 'meeting_point', 'submitted_at', 'reviewed_at', 'review_note', 'difficulty', 'accessibility_note',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_CHANGES = 'changes_requested';

    public const STATUS_LIVE = 'live';

    public const STATUS_PAUSED = 'paused';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_IN_REVIEW => 'In DOT review',
        self::STATUS_CHANGES => 'Changes needed',
        self::STATUS_LIVE => 'Live',
        self::STATUS_PAUSED => 'Paused',
    ];

    /** What a package can include, as a tour operator ticks them. Saved as PackageInclusion rows. */
    public const INCLUSION_CHOICES = ['Hotel transfers', 'Meals', 'Entrance fees', 'Licensed guide', 'Boat or van', 'Equipment', 'Insurance', 'Overnight stay', 'Snacks and water'];

    protected static function booted(): void
    {
        // DOT's own form can still switch a package on or off with "accredited". Keep the status honest when it does,
        // unless the change is a status change that already set both (see transitionTo()).
        // A package made without a status (DOT's own form, the seeders) is live if it is accredited and a draft if not.
        static::creating(function (Package $package) {
            if (! array_key_exists('status', $package->getAttributes())) {
                $package->status = $package->is_accredited ? self::STATUS_LIVE : self::STATUS_DRAFT;
            }
        });

        // Its ticked features go with it (they are keyed by kind and id, so nothing cascades them).
        static::deleting(function (Package $package) {
            ListingAmenity::where('listing_kind', 'package')->where('listing_id', $package->id)->delete();
        });

        static::saving(function (Package $package) {
            if ($package->isDirty('is_accredited') && ! $package->isDirty('status') && $package->exists) {
                $package->status = $package->is_accredited
                    ? self::STATUS_LIVE
                    : ($package->status === self::STATUS_LIVE ? self::STATUS_PAUSED : $package->status);
            }
        });
    }

    /**
     * Move a package to a new status, keep is_accredited in step (a package is public exactly when it is live),
     * and write the history entry. $note is the reason DOT gave, if any.
     */
    public function transitionTo(string $status, string $action, string $actorType, ?string $actorName, ?string $note = null): void
    {
        $this->forceFill([
            'status' => $status,
            'is_accredited' => $status === self::STATUS_LIVE,
            'submitted_at' => $action === 'submitted' ? now() : $this->submitted_at,
            'reviewed_at' => in_array($action, ['approved', 'changes_requested'], true) ? now() : $this->reviewed_at,
            'review_note' => $action === 'changes_requested' ? $note : ($action === 'approved' ? null : $this->review_note),
        ])->save();

        $this->events()->create(['actor_type' => $actorType, 'actor_name' => $actorName, 'action' => $action, 'note' => $note]);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PackageEvent::class)->latest('id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    protected function casts(): array
    {
        return [
            'is_accredited' => 'boolean',
            'featured' => 'boolean',
            'rating' => 'decimal:1',
            'price_per_pax' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function posterUrl(): string
    {
        return route('packages.show', $this);
    }

    public function posterScene(): string
    {
        return self::illustrationScene($this->name);
    }

    /** A package's sub-line leads with how long it runs, not where it starts. */
    public function posterMeta(): string
    {
        return collect([$this->duration_label, $this->region?->name])
            ->filter()
            ->unique()
            ->implode(' · ');
    }

    public function posterTags(): array
    {
        return array_values(array_filter([$this->type, $this->provider_name]));
    }

    public function posterPriceAmount(): ?string
    {
        return $this->price_per_pax
            ? number_format($this->price_per_pax).' / pax'
            : null;
    }

    /**
     * Keyed by name so a rename falls back to the generic scene instead of
     * breaking, matching Destination::illustrationScene().
     */
    public static function illustrationScene(string $name): string
    {
        return [
            'Mount Apo 3-Day Summit Trek' => 'mountain-peak',
            'Dahican Beach Surf & Chill Package' => 'surf',
            'Samal Island Hopping Day Tour' => 'island',
        ][$name] ?? 'default';
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function tourOperator(): BelongsTo
    {
        return $this->belongsTo(TourOperator::class);
    }

    public function inclusions(): HasMany
    {
        return $this->hasMany(PackageInclusion::class);
    }

    public function itineraryDays(): HasMany
    {
        return $this->hasMany(PackageItineraryDay::class)->orderBy('day_number');
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
