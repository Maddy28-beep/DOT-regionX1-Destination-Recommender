<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AdminUser;
use App\Models\EstablishmentAccount;
use App\Models\ListingChange;
use App\Models\ListingPhoto;
use App\Models\ListingSubmission;
use App\Models\Notification;
use App\Models\Promotion;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What an establishment changes on its listing waits in a draft, goes to DOT when it is submitted, and is public only after an
 * admin approves it. Open or closed and taking things down stay immediate.
 */
class ListingApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function stay(): Accommodation
    {
        return Accommodation::create([
            'slug' => 'pearl-farm', 'name' => 'Pearl Farm Beach Resort', 'location' => 'Samal', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Resort', 'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
            'description' => 'Old words.',
        ]);
    }

    private function owner(Accommodation $stay): EstablishmentAccount
    {
        return EstablishmentAccount::create([
            'business_name' => $stay->name, 'listing_kind' => 'accommodation', 'matched_listing_id' => $stay->id, 'portal_key' => (string) Str::uuid(),
            'email' => 'owner@test.example.com', 'password_hash' => Hash::make('x'), 'contact_person' => 'Owner', 'contact_number' => '09170000000',
            'status' => 'approved', 'submitted_at' => now(),
        ]);
    }

    private function admin(): AdminUser
    {
        return AdminUser::firstOrCreate(['email' => 'dot@test.example.com'], ['password_hash' => Hash::make('x'), 'full_name' => 'Ana Reyes', 'role' => 'super_admin']);
    }

    private function saveDetails(EstablishmentAccount $owner, string $description = 'New words.'): void
    {
        $this->actingAs($owner, 'establishment')->put('/portal/establishment/listing', ['section' => 'details', 'description' => $description, 'price_tier' => 'Mid-range'])->assertRedirect();
    }

    public function test_saving_details_goes_to_the_draft_and_travelers_keep_the_approved_page(): void
    {
        $stay = $this->stay();
        $owner = $this->owner($stay);

        $this->saveDetails($owner);

        $this->assertSame('Old words.', $stay->fresh()->description);
        $this->get(route('accommodations.show', $stay))->assertOk()->assertSee('Old words.')->assertDontSee('New words.');
        $this->assertSame('draft', ListingSubmission::first()->status);
        $this->assertSame('details', ListingChange::first()->kind);
    }

    public function test_the_owner_sees_the_draft_over_the_listing_and_the_bar_to_send_it(): void
    {
        $stay = $this->stay();
        $owner = $this->owner($stay);
        $this->saveDetails($owner);

        $this->actingAs($owner, 'establishment')->get('/portal/establishment/listing')->assertOk()
            ->assertSee('You have 1 change not sent yet')->assertSee('Submit for approval')->assertSee('Discard changes')->assertSee('New words.');
    }

    public function test_a_second_save_of_the_same_kind_replaces_the_first_one(): void
    {
        $owner = $this->owner($this->stay());
        $this->saveDetails($owner, 'First try.');
        $this->saveDetails($owner, 'Second try.');

        $this->assertSame(1, ListingChange::count());
        $this->assertSame('Second try.', ListingChange::first()->payload['attributes']['description']);
    }

    public function test_submitting_sends_the_draft_to_dot_and_it_is_still_not_public(): void
    {
        $stay = $this->stay();
        $owner = $this->owner($stay);
        $this->saveDetails($owner);

        $this->actingAs($owner, 'establishment')->post('/portal/establishment/listing/submit')->assertRedirect(route('establishment.listing.edit'));

        $this->assertSame('pending', ListingSubmission::first()->status);
        $this->assertNotNull(ListingSubmission::first()->submitted_at);
        $this->assertSame('Old words.', $stay->fresh()->description);
        $this->actingAs($owner, 'establishment')->get('/portal/establishment/listing')->assertSee('Waiting for DOT');
    }

    public function test_an_empty_draft_cannot_be_submitted(): void
    {
        $owner = $this->owner($this->stay());
        $this->actingAs($owner, 'establishment')->post('/portal/establishment/listing/submit')->assertRedirect();
        $this->assertSame(0, ListingSubmission::count());
    }

    public function test_the_admin_sees_what_was_sent_and_never_a_draft(): void
    {
        $stay = $this->stay();
        $owner = $this->owner($stay);
        $this->saveDetails($owner, 'Words in a draft.');

        $this->actingAs($this->admin(), 'admin')->get('/portal/admin/listing-approvals')->assertOk()->assertDontSee('Words in a draft.')->assertSee('Nothing waiting');

        $this->actingAs($owner, 'establishment')->post('/portal/establishment/listing/submit');

        $this->actingAs($this->admin(), 'admin')->get('/portal/admin/listing-approvals')->assertOk()
            ->assertSee('Pearl Farm Beach Resort')->assertSee('Old words.')->assertSee('Words in a draft.')->assertSee('Approve all 1');
    }

    public function test_approving_publishes_every_change_and_tells_the_establishment(): void
    {
        $stay = $this->stay();
        $owner = $this->owner($stay);
        $this->saveDetails($owner);
        $this->actingAs($owner, 'establishment')->post('/portal/establishment/listing/submit');
        $submission = ListingSubmission::first();

        $this->actingAs($this->admin(), 'admin')->post("/portal/admin/listing-approvals/{$submission->id}/decide", ['decision' => 'approve'])->assertRedirect();

        $this->assertSame('New words.', $stay->fresh()->description);
        $this->assertSame('approved', $submission->fresh()->status);
        $this->assertSame('Ana Reyes', $submission->fresh()->reviewed_by);
        $this->get(route('accommodations.show', $stay))->assertSee('New words.');
        $this->assertTrue(Notification::where('user_type', 'establishment')->where('user_id', $owner->id)->where('title', 'Your changes are approved')->exists());
    }

    public function test_returning_needs_a_note_and_the_owner_reads_it_word_for_word(): void
    {
        $stay = $this->stay();
        $owner = $this->owner($stay);
        $this->saveDetails($owner);
        $this->actingAs($owner, 'establishment')->post('/portal/establishment/listing/submit');
        $submission = ListingSubmission::first();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post("/portal/admin/listing-approvals/{$submission->id}/decide", ['decision' => 'return', 'note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($admin, 'admin')->post("/portal/admin/listing-approvals/{$submission->id}/decide", ['decision' => 'return', 'note' => 'Please shorten the description.'])->assertRedirect();

        $this->assertSame('Old words.', $stay->fresh()->description);
        $this->actingAs($owner, 'establishment')->get('/portal/establishment/listing')->assertSee('Returned by DOT')->assertSee('Please shorten the description.')->assertSee('Edit and resubmit');
    }

    public function test_a_returned_submission_can_be_reopened_fixed_and_sent_again(): void
    {
        $stay = $this->stay();
        $owner = $this->owner($stay);
        $this->saveDetails($owner);
        $this->actingAs($owner, 'establishment')->post('/portal/establishment/listing/submit');
        $submission = ListingSubmission::first();
        $this->actingAs($this->admin(), 'admin')->post("/portal/admin/listing-approvals/{$submission->id}/decide", ['decision' => 'return', 'note' => 'Shorten it.']);

        $this->actingAs($owner, 'establishment')->post("/portal/establishment/listing/reopen/{$submission->id}")->assertRedirect();
        $this->assertSame('draft', $submission->fresh()->status);

        $this->saveDetails($owner, 'Shorter.');
        $this->actingAs($owner, 'establishment')->post('/portal/establishment/listing/submit');
        $this->assertSame('pending', $submission->fresh()->status);
        $this->assertSame('Shorter.', $submission->fresh()->changes->first()->payload['attributes']['description']);
    }

    public function test_discarding_throws_the_draft_away(): void
    {
        $stay = $this->stay();
        $owner = $this->owner($stay);
        $this->saveDetails($owner);

        $this->actingAs($owner, 'establishment')->post('/portal/establishment/listing/discard')->assertRedirect();

        $this->assertSame(0, ListingSubmission::count());
        $this->assertSame(0, ListingChange::count());
        $this->assertSame('Old words.', $stay->fresh()->description);
    }

    public function test_a_new_photo_is_hidden_until_dot_approves_it(): void
    {
        Storage::fake('public');
        $stay = $this->stay();
        $owner = $this->owner($stay);

        $this->actingAs($owner, 'establishment')->post('/portal/establishment/photos', [
            'photos' => [UploadedFile::fake()->image('pool.jpg', 800, 600)], 'category' => 'Exterior', 'own' => 1,
        ])->assertRedirect();

        $photo = ListingPhoto::withoutGlobalScopes()->first();
        $this->assertSame('draft', $photo->review_status);
        $this->assertSame(0, $stay->fresh()->photos()->count(), 'Travelers cannot see it.');
        $this->assertCount(1, $stay->fresh()->photosWithDrafts, 'The owner can.');

        $this->actingAs($owner, 'establishment')->post('/portal/establishment/listing/submit');
        $this->assertSame('pending', ListingPhoto::withoutGlobalScopes()->first()->review_status);
        $this->assertSame(0, $stay->fresh()->photos()->count());

        $submission = ListingSubmission::first();
        $this->actingAs($this->admin(), 'admin')->get('/portal/admin/listing-approvals')->assertSee('New photo');
        $this->actingAs($this->admin(), 'admin')->post("/portal/admin/listing-approvals/{$submission->id}/decide", ['decision' => 'approve']);

        $this->assertNull(ListingPhoto::withoutGlobalScopes()->first()->review_status);
        $this->assertSame(1, $stay->fresh()->photos()->count());
    }

    public function test_a_new_offer_is_hidden_until_dot_approves_it(): void
    {
        $stay = $this->stay();
        $owner = $this->owner($stay);

        $this->actingAs($owner, 'establishment')->post('/portal/establishment/promotions', ['title' => 'Lunch for two'])->assertRedirect();

        $this->assertSame(0, Promotion::active()->count());
        $this->get(route('accommodations.show', $stay))->assertOk()->assertDontSee('Lunch for two');

        $this->actingAs($owner, 'establishment')->post('/portal/establishment/listing/submit');
        $submission = ListingSubmission::first();
        $this->actingAs($this->admin(), 'admin')->post("/portal/admin/listing-approvals/{$submission->id}/decide", ['decision' => 'approve']);

        $this->assertSame(1, Promotion::active()->count());
        $this->get(route('accommodations.show', $stay))->assertSee('Lunch for two');
    }

    public function test_discarding_removes_the_photos_and_offers_that_were_only_waiting(): void
    {
        Storage::fake('public');
        $stay = $this->stay();
        $owner = $this->owner($stay);
        $this->actingAs($owner, 'establishment')->post('/portal/establishment/photos', ['photos' => [UploadedFile::fake()->image('a.jpg')], 'category' => 'Exterior', 'own' => 1]);
        $this->actingAs($owner, 'establishment')->post('/portal/establishment/promotions', ['title' => 'Deal']);

        $this->actingAs($owner, 'establishment')->post('/portal/establishment/listing/discard');

        $this->assertSame(0, ListingPhoto::withoutGlobalScopes()->count());
        $this->assertSame(0, Promotion::withoutGlobalScopes()->count());
    }

    public function test_open_or_closed_is_live_at_once_and_stays_out_of_the_draft(): void
    {
        $stay = $this->stay();
        $owner = $this->owner($stay);

        $this->actingAs($owner, 'establishment')->put('/portal/establishment/listing', ['section' => 'status', 'operating_status' => 'temporarily_closed', 'closure_reason' => 'Typhoon'])->assertRedirect();

        $this->assertSame('temporarily_closed', $stay->fresh()->operating_status);
        $this->assertSame(0, ListingSubmission::count());
    }

    public function test_taking_a_live_photo_down_is_immediate(): void
    {
        Storage::fake('public');
        $stay = $this->stay();
        $owner = $this->owner($stay);
        $photo = ListingPhoto::create(['listing_kind' => 'accommodation', 'listing_id' => $stay->id, 'path' => 'x/live.jpg', 'category' => 'General', 'sort_order' => 1]);

        $this->actingAs($owner, 'establishment')->delete("/portal/establishment/photos/{$photo->id}")->assertRedirect();

        $this->assertSame(0, ListingPhoto::count());
    }

    public function test_another_establishment_cannot_touch_a_waiting_photo(): void
    {
        Storage::fake('public');
        $stay = $this->stay();
        $owner = $this->owner($stay);
        $this->actingAs($owner, 'establishment')->post('/portal/establishment/photos', ['photos' => [UploadedFile::fake()->image('a.jpg')], 'category' => 'Exterior', 'own' => 1]);
        $photo = ListingPhoto::withoutGlobalScopes()->first();

        $other = Accommodation::create(['slug' => 'other', 'name' => 'Other Inn', 'location' => 'Davao City', 'region_id' => $stay->region_id, 'type' => 'Hotel', 'is_accredited' => true]);
        $stranger = EstablishmentAccount::create([
            'business_name' => 'Other Inn', 'listing_kind' => 'accommodation', 'matched_listing_id' => $other->id, 'portal_key' => (string) Str::uuid(),
            'email' => 'other@test.example.com', 'password_hash' => Hash::make('x'), 'contact_person' => 'Other', 'contact_number' => '09170000001', 'status' => 'approved', 'submitted_at' => now(),
        ]);

        $this->actingAs($stranger, 'establishment')->delete("/portal/establishment/photos/{$photo->id}")->assertForbidden();
        $this->assertSame(1, ListingPhoto::withoutGlobalScopes()->count());
    }
}
