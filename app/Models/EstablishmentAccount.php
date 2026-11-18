<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\DB;

class EstablishmentAccount extends User
{
    /** What only a business has lives in `establishment_profiles`; the model reads and writes it as if it were its own. */
    public const PROFILE = [
        'business_name', 'listing_kind', 'claimed_accreditation_number', 'certificate_file_name', 'matched_listing_id', 'portal_key',
        'contact_number', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $fillable = [
        'business_name', 'listing_kind', 'claimed_accreditation_number',
        'certificate_file_name', 'matched_listing_id', 'portal_key', 'email',
        'password_hash', 'contact_person', 'contact_number', 'status',
        'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    /** Profile values waiting to be written beside the user row. */
    private array $profileToSave = [];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Only establishments, each with its profile alongside.
        static::addGlobalScope('establishments', function (Builder $q) {
            $q->join('establishment_profiles', 'establishment_profiles.user_id', '=', 'users.id')
                ->where('users.role', User::ESTABLISHMENT)
                ->select(array_merge(['users.*'], array_map(fn ($c) => "establishment_profiles.$c", self::PROFILE)));
        });

        // The profile columns are not on the users table: set them aside before the user row is written, put them back after.
        static::saving(function (self $account) {
            if (! $account->exists) {
                $account->role = User::ESTABLISHMENT;
                $account->setAttribute('submitted_at', $account->getAttribute('submitted_at') ?? now());
            }
            $account->profileToSave = array_intersect_key($account->getDirty(), array_flip(self::PROFILE));
            foreach (array_keys($account->profileToSave) as $column) {
                unset($account->attributes[$column]);
            }
        });

        static::saved(function (self $account) {
            if ($account->profileToSave === []) {
                return;
            }
            $values = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, $account->profileToSave);
            $row = DB::table('establishment_profiles')->where('user_id', $account->getKey());
            $row->exists() ? $row->update($values) : DB::table('establishment_profiles')->insert(['user_id' => $account->getKey()] + $values);
            foreach ($account->profileToSave as $column => $value) {
                $account->attributes[$column] = $value;
            }
            $account->profileToSave = [];
            $account->syncOriginal();
        });
    }

    /** fresh() and refresh() skip global scopes, and the scope is what brings the profile in. */
    public function fresh($with = [])
    {
        return $this->exists ? $this->newQuery()->with(is_string($with) ? func_get_args() : $with)->whereKey($this->getKey())->first() : null;
    }

    public function refresh()
    {
        if (! $this->exists) {
            return $this;
        }
        $this->setRawAttributes($this->newQuery()->whereKey($this->getKey())->firstOrFail()->attributes);
        $this->load(collect($this->relations)->reject(fn ($relation) => $relation instanceof \Illuminate\Database\Eloquent\Relations\Pivot)->keys()->all());
        $this->syncOriginal();

        return $this;
    }

    /** The contact person is the account's name. */
    protected function contactPerson(): Attribute
    {
        return Attribute::make(get: fn ($value, array $attributes) => $attributes['name'] ?? null, set: fn ($value) => ['name' => $value]);
    }

    public function matchedListing(): MorphTo
    {
        return $this->morphTo('listing', 'listing_kind', 'matched_listing_id');
    }

    /**
     * The one status the partner dashboard reports, everywhere.
     *
     * Account approval and accreditation validity are separate facts, and the
     * dashboard used to show them side by side with no relationship -- hence
     * "Approved" sitting next to "Expired" and reading as a contradiction.
     * This folds both into a single effective state, ordered by what the
     * partner most needs to act on.
     *
     * Public visibility is taken from the listing flags that scopePubliclyVisible()
     * actually filters on (is_accredited AND not archived), never from the
     * AccreditationRecord status string -- that is a separate, human-maintained
     * field and the two can legitimately disagree.
     *
     * @return array{label:string, tone:string, actionRequired:bool, detail:?string}
     */
    public function portalStatus(): array
    {
        $listing = $this->matchedListing;

        if ($this->status === 'pending') {
            return ['label' => 'Pending Review', 'tone' => 'warn', 'actionRequired' => false,
                'detail' => 'A DOT Region XI admin is verifying your accreditation details.'];
        }

        if ($this->status === 'rejected') {
            return ['label' => 'Not Approved', 'tone' => 'danger', 'actionRequired' => true,
                'detail' => $this->review_note ?: 'Please contact DOT Region XI for more information.'];
        }

        if (! $listing) {
            return ['label' => 'Not Linked', 'tone' => 'warn', 'actionRequired' => false,
                'detail' => "Your account is approved, but a DOT Admin hasn't linked it to a catalog entry yet."];
        }

        $accreditation = $listing->accreditationRecords()->latest('expiration_date')->first();
        $isVisible = (bool) ($listing->is_accredited && ! $listing->archived_at);

        if ($accreditation?->status === 'Expired') {
            return [
                'label' => 'Accreditation Expired',
                'tone' => 'danger',
                'actionRequired' => true,
                'detail' => 'Your DOT accreditation expired on '.$accreditation->expiration_date->format('M d, Y').'. '
                    .($isVisible
                        ? 'Your listing is still visible, but DOT Region XI may hide it at any time.'
                        : "Your listing is hidden from public search until it's renewed."),
            ];
        }

        if (! $isVisible) {
            return ['label' => 'Listing Hidden', 'tone' => 'danger', 'actionRequired' => true,
                'detail' => 'Your listing is not currently visible in public search. Contact DOT Region XI to have it restored.'];
        }

        if ($accreditation?->status === 'Expiring Soon') {
            return ['label' => 'Renewal Due', 'tone' => 'warn', 'actionRequired' => true,
                'detail' => 'Your accreditation expires on '.$accreditation->expiration_date->format('M d, Y').'. Coordinate renewal with DOT Region XI.'];
        }

        return ['label' => 'Active', 'tone' => 'success', 'actionRequired' => false, 'detail' => null];
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }

    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'user', 'user_type', 'user_id');
    }
}
