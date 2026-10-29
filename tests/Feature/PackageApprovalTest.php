<?php

namespace Tests\Feature;

use App\Models\AccreditationRecord;
use App\Models\AdminUser;
use App\Models\EstablishmentAccount;
use App\Models\Notification;
use App\Models\Package;
use App\Models\Region;
use App\Models\TourOperator;
use App\Services\Admin\AdminAttention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tour operators add their own packages and DOT approves them before travellers can see them: draft, send, DOT's
 * decision (approve, or ask for changes with a note), pause and resume, and who may touch what.
 */
class PackageApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $name = 'Island Explorers', bool $accredited = true): TourOperator
    {
        $operator = TourOperator::create([
            'slug' => Str::slug($name), 'name' => $name, 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);

        AccreditationRecord::create([
            'listing_kind' => 'tour_operator', 'listing_id' => $operator->id, 'accreditation_number' => 'DOT-'.$operator->id,
            'status' => $accredited ? 'Active' : 'Expired', 'issue_date' => now()->subYears(2),
            'expiration_date' => $accredited ? now()->addYear() : now()->subMonth(),
        ]);

        return $operator;
    }

    private function account(TourOperator $operator, string $email = 'ops@test.example.com'): EstablishmentAccount
    {
        return EstablishmentAccount::create([
            'business_name' => $operator->name, 'listing_kind' => 'tour_operator', 'matched_listing_id' => $operator->id,
            'portal_key' => (string) Str::uuid(), 'email' => $email, 'password_hash' => Hash::make('x'),
            'contact_person' => 'Owner', 'contact_number' => '09170000000', 'status' => 'approved', 'submitted_at' => now(),
        ]);
    }

    private ?AdminUser $admin = null;

    private function admin(): AdminUser
    {
        return $this->admin ??= AdminUser::create(['email' => 'dot@test.example.com', 'password_hash' => Hash::make('x'), 'full_name' => 'Ana Reyes', 'role' => 'super_admin']);
    }

    private function draft(array $over = []): array
    {
        return $over + [
            'intent' => 'draft', 'name' => 'Samal Island Day Escape', 'description' => 'A relaxed day on Samal Island with a beach lunch and a snorkelling stop.',
            'price_per_pax' => 2450, 'price_tier' => 'Mid-range', 'meeting_point' => 'Sasa Wharf', 'inclusions' => ['Meals', 'Boat or van'],
            'itinerary' => "Hotel pickup and ferry | Meet at 7:30 AM\nBeach lunch",
        ];
    }

    private function makeAndSend(EstablishmentAccount $account): Package
    {
        $this->actingAs($account, 'establishment')->postJson('/portal/establishment/packages', $this->draft(['intent' => 'submit']))->assertOk();

        return Package::latest('id')->firstOrFail();
    }

    // ---------- the operator's side ----------

    public function test_a_new_package_is_a_draft_hidden_from_travellers(): void
    {
        $operator = $this->operator();
        $account = $this->account($operator);

        $this->actingAs($account, 'establishment')->postJson('/portal/establishment/packages', $this->draft())->assertOk()->assertJsonStructure(['redirect']);

        $package = Package::firstOrFail();
        $this->assertSame('draft', $package->status);
        $this->assertFalse($package->is_accredited);
        $this->assertSame($operator->id, $package->tour_operator_id);
        $this->assertSame(2, $package->itineraryDays()->count());
        $this->assertEqualsCanonicalizing(['Meals', 'Boat or van'], $package->inclusions->pluck('item')->all());
        $this->assertSame('2 days', $package->duration_label);

        auth()->forgetGuards();
        $this->get(route('packages.show', $package))->assertNotFound();
    }

    public function test_a_draft_needs_only_a_name(): void
    {
        $account = $this->account($this->operator());

        $this->actingAs($account, 'establishment')->postJson('/portal/establishment/packages', ['intent' => 'draft', 'name' => ''])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->actingAs($account, 'establishment')->postJson('/portal/establishment/packages', ['intent' => 'draft', 'name' => 'Just a name'])->assertOk();
    }

    public function test_sending_to_dot_needs_a_description_a_price_and_a_titled_day(): void
    {
        $account = $this->account($this->operator());
        $client = $this->actingAs($account, 'establishment');

        $client->postJson('/portal/establishment/packages', $this->draft(['intent' => 'submit', 'description' => 'Too short']))->assertStatus(422)->assertJsonValidationErrors(['description']);
        $client->postJson('/portal/establishment/packages', $this->draft(['intent' => 'submit', 'price_per_pax' => '']))->assertStatus(422)->assertJsonValidationErrors(['price_per_pax']);
        $client->postJson('/portal/establishment/packages', $this->draft(['intent' => 'submit', 'itinerary' => '']))->assertStatus(422)->assertJsonValidationErrors(['itinerary']);
        $this->assertSame(0, Package::count());

        $client->postJson('/portal/establishment/packages', $this->draft(['intent' => 'submit']))->assertOk();
        $package = Package::firstOrFail();
        $this->assertSame('in_review', $package->status);
        $this->assertNotNull($package->submitted_at);
        $this->assertFalse($package->is_accredited, 'in review means still hidden');
        $this->assertSame(['submitted'], $package->events->pluck('action')->all());
    }

    public function test_only_a_tour_operator_has_my_packages_and_only_for_their_own(): void
    {
        $mine = $this->account($this->operator());
        $other = $this->account($this->operator('Other Tours'), 'other@test.example.com');
        $package = $this->makeAndSend($other);

        $this->actingAs($mine, 'establishment')->get('/portal/establishment/packages')->assertOk()->assertDontSee('Samal Island Day Escape');
        $this->actingAs($mine, 'establishment')->get("/portal/establishment/packages/{$package->id}")->assertForbidden();
        $this->actingAs($mine, 'establishment')->putJson("/portal/establishment/packages/{$package->id}", $this->draft())->assertForbidden();
        $this->actingAs($mine, 'establishment')->post("/portal/establishment/packages/{$package->id}/withdraw")->assertForbidden();

        $stay = EstablishmentAccount::create([
            'business_name' => 'Inn', 'listing_kind' => 'accommodation', 'portal_key' => (string) Str::uuid(), 'email' => 'inn@test.example.com',
            'password_hash' => Hash::make('x'), 'contact_person' => 'Owner', 'contact_number' => '09170000000', 'status' => 'approved', 'submitted_at' => now(),
        ]);
        $this->actingAs($stay, 'establishment')->get('/portal/establishment/packages')->assertNotFound();
    }

    public function test_the_forms_are_fragments_for_the_modal_and_pages_otherwise(): void
    {
        $account = $this->account($this->operator());
        $package = $this->makeAndSend($account);

        foreach (['/portal/establishment/packages/create', "/portal/establishment/packages/{$package->id}/edit"] as $url) {
            $this->assertStringNotContainsString('<html', $this->actingAs($account, 'establishment')->get($url.'?modal=1')->assertOk()->getContent());
            $this->assertStringContainsString('<html', $this->actingAs($account, 'establishment')->get($url)->assertOk()->getContent());
        }

        $this->actingAs($account, 'establishment')->get('/portal/establishment/packages')->assertOk()->assertSee('Samal Island Day Escape')->assertSee('In DOT review');
        $this->actingAs($account, 'establishment')->get("/portal/establishment/packages/{$package->id}")->assertOk()->assertSee('It is with DOT');
    }

    /** The modal posts to form.action; a field named "action" (or "method") would replace that address with the field. */
    public function test_no_form_field_hides_the_forms_own_address_from_the_modal(): void
    {
        $account = $this->account($this->operator());
        $package = $this->makeAndSend($account);

        foreach (['/portal/establishment/packages/create', "/portal/establishment/packages/{$package->id}/edit"] as $url) {
            $html = $this->actingAs($account, 'establishment')->get($url.'?modal=1')->getContent();
            $this->assertDoesNotMatchRegularExpression('/name="(action|method|submit|target|enctype)"/', $html, $url);
        }
    }

    public function test_an_operator_can_withdraw_a_package_from_review_and_delete_a_draft_but_not_a_live_one(): void
    {
        $account = $this->account($this->operator());
        $package = $this->makeAndSend($account);

        $this->actingAs($account, 'establishment')->post("/portal/establishment/packages/{$package->id}/withdraw")->assertRedirect();
        $this->assertSame('draft', $package->fresh()->status);

        $this->actingAs($account, 'establishment')->delete("/portal/establishment/packages/{$package->id}")->assertRedirect(route('establishment.packages'));
        $this->assertNull(Package::find($package->id));

        $live = Package::create(['slug' => 'live-one', 'name' => 'Live One', 'is_accredited' => true, 'tour_operator_id' => $account->matched_listing_id]);
        $this->actingAs($account, 'establishment')->delete("/portal/establishment/packages/{$live->id}")->assertRedirect();
        $this->assertNotNull(Package::find($live->id), 'a live package is paused, not deleted');
    }

    public function test_a_package_can_be_copied_as_a_draft(): void
    {
        $account = $this->account($this->operator());
        $package = $this->makeAndSend($account);

        $this->actingAs($account, 'establishment')->post("/portal/establishment/packages/{$package->id}/duplicate")->assertRedirect();

        $copy = Package::where('id', '!=', $package->id)->firstOrFail();
        $this->assertSame('draft', $copy->status);
        $this->assertSame('Samal Island Day Escape (copy)', $copy->name);
        $this->assertSame(2, $copy->itineraryDays()->count());
        $this->assertSame(2, $copy->inclusions()->count());
        $this->assertNotSame($package->slug, $copy->slug);
    }

    // ---------- DOT's side ----------

    public function test_dot_sees_the_queue_and_an_approval_makes_the_package_live_and_tells_the_operator(): void
    {
        $operator = $this->operator();
        $account = $this->account($operator);
        $package = $this->makeAndSend($account);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get('/portal/admin/package-approvals')->assertOk()->assertSee('Samal Island Day Escape')->assertSee('Package Approvals');
        $this->actingAs($admin, 'admin')->get("/portal/admin/package-approvals/{$package->id}?modal=1")->assertOk()->assertSee('Operator accreditation is current')->assertSee('Your decision');

        $this->actingAs($admin, 'admin')->postJson("/portal/admin/package-approvals/{$package->id}/decide", ['decision' => 'approve'])->assertOk()->assertJsonStructure(['redirect']);

        $package->refresh();
        $this->assertSame('live', $package->status);
        $this->assertTrue($package->is_accredited);
        $this->assertNotNull($package->reviewed_at);
        $this->assertSame(['approved', 'submitted'], $package->events->pluck('action')->all());
        $this->assertSame('Ana Reyes', $package->events->first()->actor_name);

        $this->get(route('packages.show', $package))->assertOk();

        $note = Notification::where('user_id', $account->id)->firstOrFail();
        $this->assertSame('Package approved', $note->title);
        $this->assertSame('establishment', $note->user_type);
    }

    public function test_asking_for_changes_needs_a_note_and_the_operator_reads_it(): void
    {
        $account = $this->account($this->operator());
        $package = $this->makeAndSend($account);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->postJson("/portal/admin/package-approvals/{$package->id}/decide", ['decision' => 'changes'])->assertStatus(422)->assertJsonValidationErrors(['note']);
        $this->assertSame('in_review', $package->fresh()->status);

        $this->actingAs($admin, 'admin')->postJson("/portal/admin/package-approvals/{$package->id}/decide", ['decision' => 'changes', 'note' => 'Please say what time the boat leaves.'])->assertOk();

        $package->refresh();
        $this->assertSame('changes_requested', $package->status);
        $this->assertFalse($package->is_accredited);
        $this->assertSame('Please say what time the boat leaves.', $package->review_note);

        $this->actingAs($account, 'establishment')->get('/portal/establishment/packages')->assertOk()->assertSee('Please say what time the boat leaves.');
        $this->actingAs($account, 'establishment')->get('/portal/establishment')->assertOk()->assertSee('DOT asked for a change to 1 package');

        // the operator fixes it and sends it again
        $this->actingAs($account, 'establishment')->putJson("/portal/establishment/packages/{$package->id}", $this->draft(['intent' => 'submit', 'meeting_point' => 'Sasa Wharf, 7:30 AM']))->assertOk();
        $this->assertSame('in_review', $package->fresh()->status);
    }

    public function test_a_package_from_an_operator_whose_accreditation_lapsed_cannot_be_approved(): void
    {
        $account = $this->account($this->operator('Lapsed Tours', accredited: false));
        $package = $this->makeAndSend($account);

        $this->actingAs($this->admin(), 'admin')->postJson("/portal/admin/package-approvals/{$package->id}/decide", ['decision' => 'approve'])
            ->assertStatus(422)->assertJsonValidationErrors(['decision']);

        $this->assertSame('in_review', $package->fresh()->status);
        $this->assertFalse($package->fresh()->is_accredited);
        $this->assertStringContainsString('Cannot approve yet', $this->actingAs($this->admin(), 'admin')->get("/portal/admin/package-approvals/{$package->id}?modal=1")->getContent());
    }

    public function test_a_package_already_decided_cannot_be_decided_again(): void
    {
        $account = $this->account($this->operator());
        $package = $this->makeAndSend($account);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->postJson("/portal/admin/package-approvals/{$package->id}/decide", ['decision' => 'approve'])->assertOk();
        $this->actingAs($admin, 'admin')->postJson("/portal/admin/package-approvals/{$package->id}/decide", ['decision' => 'changes', 'note' => 'Changed my mind, sorry.'])->assertStatus(422);
        $this->assertSame('live', $package->fresh()->status);
    }

    public function test_the_queue_is_for_admins_only(): void
    {
        $account = $this->account($this->operator());

        $this->actingAs($account, 'establishment')->get('/portal/admin/package-approvals')->assertRedirect();
        $this->get('/portal/admin/package-approvals')->assertRedirect();
    }

    public function test_the_bell_and_sidebar_count_what_is_waiting_and_overdue_is_called_out(): void
    {
        $account = $this->account($this->operator());
        $package = $this->makeAndSend($account);

        $items = collect(app(AdminAttention::class)->items())->firstWhere('key', 'packages');
        $this->assertSame('1 package waiting for review', $items['title']);
        $this->assertSame('Tour operators are waiting for a decision.', $items['detail']);

        $package->update(['submitted_at' => now()->subDays(7)]);
        $this->assertStringContainsString('One has been waiting more than 3 days', collect(app(AdminAttention::class)->items())->firstWhere('key', 'packages')['detail']);

        $this->actingAs($this->admin(), 'admin')->get('/portal/admin/package-approvals')->assertOk()->assertSee('1 overdue')->assertSee('7 days waiting');
    }

    // ---------- live packages, pausing and resuming ----------

    public function test_editing_a_live_package_goes_live_straight_away_and_must_stay_complete(): void
    {
        $account = $this->account($this->operator());
        $package = $this->makeAndSend($account);
        $this->actingAs($this->admin(), 'admin')->postJson("/portal/admin/package-approvals/{$package->id}/decide", ['decision' => 'approve'])->assertOk();

        $this->actingAs($account, 'establishment')->putJson("/portal/establishment/packages/{$package->id}", $this->draft(['intent' => 'save', 'price_per_pax' => 2600]))->assertOk();
        $package->refresh();
        $this->assertSame('live', $package->status);
        $this->assertEquals(2600, $package->price_per_pax);

        $this->actingAs($account, 'establishment')->putJson("/portal/establishment/packages/{$package->id}", $this->draft(['intent' => 'save', 'description' => 'Short']))->assertStatus(422);
        $this->assertSame('live', $package->fresh()->status);
    }

    public function test_an_operator_can_pause_and_resume_but_not_while_their_accreditation_has_lapsed(): void
    {
        $operator = $this->operator();
        $account = $this->account($operator);
        $package = $this->makeAndSend($account);
        $this->actingAs($this->admin(), 'admin')->postJson("/portal/admin/package-approvals/{$package->id}/decide", ['decision' => 'approve'])->assertOk();

        $this->actingAs($account, 'establishment')->post("/portal/establishment/packages/{$package->id}/pause")->assertRedirect();
        $this->assertSame('paused', $package->fresh()->status);
        $this->assertFalse($package->fresh()->is_accredited);

        $operator->accreditationRecords()->update(['status' => 'Expired', 'expiration_date' => now()->subDay()]);
        $this->actingAs($account, 'establishment')->post("/portal/establishment/packages/{$package->id}/resume")->assertRedirect();
        $this->assertSame('paused', $package->fresh()->status, 'a lapsed operator cannot put a package back in front of travellers');

        $operator->accreditationRecords()->update(['status' => 'Active', 'expiration_date' => now()->addYear()]);
        $this->actingAs($account, 'establishment')->post("/portal/establishment/packages/{$package->id}/resume")->assertRedirect();
        $this->assertSame('live', $package->fresh()->status);
        $this->assertTrue($package->fresh()->is_accredited);
    }

    public function test_dot_can_pause_a_live_package_and_the_operator_is_told(): void
    {
        $account = $this->account($this->operator());
        $package = $this->makeAndSend($account);
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->postJson("/portal/admin/package-approvals/{$package->id}/decide", ['decision' => 'approve'])->assertOk();

        $this->actingAs($admin, 'admin')->postJson("/portal/admin/package-approvals/{$package->id}/pause")->assertOk();

        $this->assertSame('paused', $package->fresh()->status);
        $this->assertFalse($package->fresh()->is_accredited);
        $this->assertTrue(Notification::where('user_id', $account->id)->where('title', 'A package was paused by DOT')->exists());

        $this->actingAs($admin, 'admin')->postJson("/portal/admin/package-approvals/{$package->id}/resume")->assertOk();
        $this->assertSame('live', $package->fresh()->status);
    }

    public function test_packages_that_already_exist_keep_their_behaviour(): void
    {
        $accredited = Package::create(['slug' => 'old-live', 'name' => 'Old Live', 'is_accredited' => true]);
        $hidden = Package::create(['slug' => 'old-hidden', 'name' => 'Old Hidden', 'is_accredited' => false]);

        $this->assertSame('live', $accredited->fresh()->status);
        $this->assertSame('draft', $hidden->fresh()->status);

        // DOT's own form can still switch one on or off with the accredited flag, and the status follows
        $hidden->update(['is_accredited' => true]);
        $this->assertSame('live', $hidden->fresh()->status);
        $hidden->update(['is_accredited' => false]);
        $this->assertSame('paused', $hidden->fresh()->status);

        $this->assertSame([], Package::whereNotNull('submitted_at')->pluck('id')->all(), 'only packages an operator sent in are in the approvals queue');
    }
}
