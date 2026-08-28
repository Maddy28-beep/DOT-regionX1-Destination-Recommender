<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

class EstablishmentAccount extends Authenticatable
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'business_name', 'listing_kind', 'claimed_accreditation_number',
        'certificate_file_name', 'matched_listing_id', 'portal_key', 'email',
        'password_hash', 'contact_person', 'contact_number', 'status',
        'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
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
