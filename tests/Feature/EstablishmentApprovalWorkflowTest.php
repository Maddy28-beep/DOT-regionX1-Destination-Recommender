<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\EstablishmentAccount;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * An approved establishment account can sign in and edit its matched listing
 * immediately, so approval must never be allowed to run ahead of a real
 * matched_listing_id -- otherwise a partner is approved into a dashboard
 * with nothing to manage, and whatever gets linked later silently becomes
 * "theirs" with no separate review step at that point.
 */
class EstablishmentApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'email' => 'approval-admin@x.test', 'password_hash' => Hash::make('x'),
            'full_name' => 'Admin', 'role' => 'super_admin',
        ]);
    }

    private function pendingEstablishment(?int $matchedListingId = null): EstablishmentAccount
    {
        return EstablishmentAccount::create([
            'business_name' => 'Test Cafe', 'listing_kind' => 'restaurant',
            'email' => 'owner@testcafe.example.com', 'password_hash' => Hash::make('x'),
            'contact_person' => 'Jane Doe', 'contact_number' => '09170000000',
            'status' => 'pending', 'submitted_at' => now(),
            'matched_listing_id' => $matchedListingId,
            'portal_key' => \Illuminate\Support\Str::uuid(),
        ]);
    }

    public function test_approving_an_unlinked_establishment_is_rejected_and_status_is_unchanged(): void
    {
        $establishment = $this->pendingEstablishment(matchedListingId: null);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.establishments.approve', $establishment));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame('pending', $establishment->fresh()->status);
        $this->assertNull($establishment->fresh()->reviewed_by);
    }

    public function test_approving_a_linked_establishment_still_works_exactly_as_before(): void
    {
        $region = Region::create(['name' => 'Davao City']);
        $destination = Destination::create([
            'slug' => 'test-destination', 'name' => 'Test Destination', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ]);
        $establishment = $this->pendingEstablishment(matchedListingId: $destination->id);
        $establishment->update(['listing_kind' => 'destination']);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.establishments.approve', $establishment));

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertSame('approved', $establishment->fresh()->status);
        $this->assertNotNull($establishment->fresh()->reviewed_at);
    }

    public function test_rejecting_an_unlinked_establishment_is_unaffected_by_the_new_guard(): void
    {
        $establishment = $this->pendingEstablishment(matchedListingId: null);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.establishments.reject', $establishment));

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertSame('rejected', $establishment->fresh()->status);
    }

    public function test_the_approve_button_is_disabled_and_explained_when_not_linked(): void
    {
        $this->pendingEstablishment(matchedListingId: null);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.establishments', ['status' => 'pending']))
            ->getContent();

        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>\s*Approve\s*<\/button>/', $html);
        $this->assertStringContainsString('Link this establishment to an existing listing before approving.', $html);
    }
}
