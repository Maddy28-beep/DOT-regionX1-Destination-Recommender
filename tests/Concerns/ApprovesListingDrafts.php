<?php

namespace Tests\Concerns;

use App\Models\EstablishmentAccount;
use App\Services\Listings\ListingDraft;

/** Edits on a listing wait in a draft; tests that look at the public result send the draft and let DOT approve it. */
trait ApprovesListingDrafts
{
    protected function approveDraftOf(EstablishmentAccount $account): void
    {
        $drafts = app(ListingDraft::class);
        $drafts->submit($account);
        $submission = \App\Models\ListingSubmission::where('establishment_account_id', $account->id)->where('status', 'pending')->latest('id')->first();
        if ($submission) {
            $drafts->approve($submission, 'Test admin');
        }
    }
}
