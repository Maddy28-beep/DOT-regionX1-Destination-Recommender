<?php

namespace App\Services\Listings;

use App\Models\EstablishmentAccount;
use App\Models\ListingChange;
use App\Models\ListingPhoto;
use App\Models\ListingSubmission;
use App\Models\Notification;
use App\Models\Promotion;
use App\Services\Amenities\AmenityStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * What an establishment changes on its listing waits for DOT before travelers see it.
 *
 * Edits are staged in the account's draft (a ListingSubmission), one change per kind of edit: details, hours, position and
 * amenities replace an earlier staged edit of the same kind; every photo and every offer is its own change. A photo or offer
 * already exists as a row while it waits, but hidden (review_status). The establishment sees its own draft laid over the
 * listing; travelers keep seeing the approved page until an admin approves the submission.
 *
 * Not staged, on purpose: open or closed (a wrong "open" is worse than a late page), and taking a photo or offer down.
 */
class ListingDraft
{
    /** The draft being put together, if there is one. */
    public function current(EstablishmentAccount $account): ?ListingSubmission
    {
        return ListingSubmission::where('establishment_account_id', $account->id)->where('status', ListingSubmission::DRAFT)->latest('id')->first();
    }

    public function open(EstablishmentAccount $account): ListingSubmission
    {
        return $this->current($account) ?? ListingSubmission::create([
            'establishment_account_id' => $account->id,
            'listing_kind' => $account->listing_kind,
            'listing_id' => (int) $account->matched_listing_id,
            'status' => ListingSubmission::DRAFT,
        ]);
    }

    /** Stage an edit that replaces an earlier one of the same kind (details, hours, position, amenities). */
    public function stage(EstablishmentAccount $account, string $kind, array $payload): void
    {
        $draft = $this->open($account);
        $existing = $draft->changes()->where('kind', $kind)->first();
        $existing ? $existing->update(['payload' => $payload]) : $draft->changes()->create(['kind' => $kind, 'payload' => $payload]);
    }

    /** Stage a photo or an offer that has already been created, hidden. */
    public function stageItem(EstablishmentAccount $account, string $kind, array $payload): void
    {
        $this->open($account)->changes()->create(['kind' => $kind, 'payload' => $payload]);
    }

    /** The staged payload of one kind, or null. */
    public function payload(?ListingSubmission $draft, string $kind): ?array
    {
        return $draft?->changes->firstWhere('kind', $kind)?->payload;
    }

    /** The listing as the establishment sees it: its draft laid over the approved page (in memory only, never saved). */
    public function overlay(EstablishmentAccount $account, Model $listing): Model
    {
        $draft = $this->current($account)?->load('changes');
        foreach (['details', 'position', 'hours'] as $kind) {
            if ($payload = $this->payload($draft, $kind)) {
                $listing->forceFill($payload['attributes'] ?? []);
            }
        }

        return $listing;
    }

    public function hasChanges(?ListingSubmission $draft): bool
    {
        return $draft !== null && $draft->changes()->exists();
    }

    /** Send the draft to DOT. */
    public function submit(EstablishmentAccount $account): bool
    {
        $draft = $this->current($account);
        if (! $this->hasChanges($draft)) {
            return false;
        }
        DB::transaction(function () use ($draft) {
            $this->markItems($draft, 'pending');
            $draft->update(['status' => ListingSubmission::PENDING, 'submitted_at' => now(), 'note' => null]);
        });

        return true;
    }

    /** Throw the draft away: staged edits go, and so do the photos and offers that were only waiting. */
    public function discard(EstablishmentAccount $account): void
    {
        $draft = $this->current($account);
        if (! $draft) {
            return;
        }
        DB::transaction(function () use ($draft) {
            foreach ($draft->changes as $change) {
                $this->removeItem($change);
            }
            $draft->delete();
        });
    }

    /** A returned submission becomes the draft again, so it can be fixed and sent once more. */
    public function reopen(ListingSubmission $submission): bool
    {
        $account = $submission->account;
        if (! $account || $submission->status !== ListingSubmission::RETURNED || $this->current($account)) {
            return false;
        }
        DB::transaction(function () use ($submission) {
            $this->markItems($submission, 'draft');
            $submission->update(['status' => ListingSubmission::DRAFT]);
        });

        return true;
    }

    /** DOT approves: every change goes live. */
    public function approve(ListingSubmission $submission, string $by): void
    {
        $listing = $submission->listing();
        DB::transaction(function () use ($submission, $listing, $by) {
            foreach ($submission->changes as $change) {
                $this->apply($change, $submission, $listing);
            }
            $submission->update(['status' => ListingSubmission::APPROVED, 'reviewed_at' => now(), 'reviewed_by' => $by, 'note' => null]);
        });
        $this->tell($submission, 'Your changes are approved', ($listing?->name ?? 'Your listing').' is updated on ExploreDVO. Travelers see your changes now.');
    }

    /** DOT sends it back with a note the establishment reads word for word. */
    public function returnWith(ListingSubmission $submission, string $by, string $note): void
    {
        DB::transaction(function () use ($submission, $by, $note) {
            $this->markItems($submission, 'returned');
            $submission->update(['status' => ListingSubmission::RETURNED, 'reviewed_at' => now(), 'reviewed_by' => $by, 'note' => $note]);
        });
        $this->tell($submission, 'DOT sent your changes back', $note);
    }

    private function apply(ListingChange $change, ListingSubmission $submission, ?Model $listing): void
    {
        $payload = $change->payload;
        switch ($change->kind) {
            case 'details':
            case 'position':
            case 'hours':
                if ($listing) {
                    $listing->forceFill($payload['attributes'] ?? [])->save();
                    if ($change->kind === 'details' && isset($payload['itinerary'])) {
                        app(\App\Http\Controllers\Establishment\EstablishmentDashboardController::class)->syncItineraryDaysFor($listing, (string) $payload['itinerary']);
                    }
                }
                break;
            case 'amenities':
                if ($listing) {
                    app(AmenityStore::class)->sync($submission->listing_kind, $listing, $payload['amenities'] ?? [], $payload['names'] ?? []);
                }
                break;
            case 'photo':
                if ($photo = ListingPhoto::withoutGlobalScopes()->find($payload['id'] ?? 0)) {
                    $photo->update(['review_status' => null]);
                    if ($listing && ! empty($payload['amenity'])) {
                        app(AmenityStore::class)->add($submission->listing_kind, $listing, $payload['amenity']);
                    }
                }
                break;
            case 'promotion':
                Promotion::withoutGlobalScopes()->whereKey($payload['id'] ?? 0)->update(['review_status' => null]);
                break;
        }
    }

    /** Photos and offers wait hidden; their status follows the submission. */
    private function markItems(ListingSubmission $submission, string $status): void
    {
        foreach ($submission->changes()->whereIn('kind', ['photo', 'promotion'])->get() as $change) {
            $model = $change->kind === 'photo' ? ListingPhoto::class : Promotion::class;
            $model::withoutGlobalScopes()->whereKey($change->payload['id'] ?? 0)->update(['review_status' => $status]);
        }
    }

    private function removeItem(ListingChange $change): void
    {
        if ($change->kind === 'photo' && ($photo = ListingPhoto::withoutGlobalScopes()->find($change->payload['id'] ?? 0))) {
            Storage::disk('public')->delete($photo->path);
            $photo->delete();
        }
        if ($change->kind === 'promotion') {
            Promotion::withoutGlobalScopes()->whereKey($change->payload['id'] ?? 0)->delete();
        }
    }

    private function tell(ListingSubmission $submission, string $title, string $message): void
    {
        Notification::create(['user_type' => 'establishment', 'user_id' => $submission->establishment_account_id, 'title' => $title, 'message' => $message, 'is_read' => false]);
    }
}
