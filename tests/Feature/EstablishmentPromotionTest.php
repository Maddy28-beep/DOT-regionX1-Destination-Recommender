<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\EstablishmentAccount;
use App\Models\Promotion;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DOT asked that establishments be able to show their own discount codes and
 * promos, self-service, so travelers see them on the listing's public page.
 * Unlike Advisory (DOT-authored), an establishment may only manage promos on
 * its own matched listing -- these tests guard that boundary along with the
 * public display.
 */
class EstablishmentPromotionTest extends TestCase
{
    use RefreshDatabase;

    private function partnerWithListing(): array
    {
        $listing = Accommodation::create([
            'slug' => 'promo-test-resort', 'name' => 'Promo Test Resort', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);

        $partner = EstablishmentAccount::create([
            'business_name' => 'Promo Test Resort', 'listing_kind' => 'accommodation',
            'matched_listing_id' => $listing->id, 'portal_key' => (string) Str::uuid(),
            'email' => 'promo@test.example.com', 'password_hash' => Hash::make('x'),
            'contact_person' => 'Tester', 'contact_number' => '09170000000',
            'status' => 'approved', 'submitted_at' => now(),
        ]);

        return [$partner, $listing];
    }

    public function test_a_guest_cannot_reach_the_promotions_console(): void
    {
        $this->get('/portal/establishment/promotions')->assertRedirect('/portal/login');
    }

    public function test_an_establishment_can_add_a_promo_to_its_own_listing(): void
    {
        [$partner, $listing] = $this->partnerWithListing();

        $this->actingAs($partner, 'establishment')
            ->post('/portal/establishment/promotions', [
                'title' => '15% Off Walk-ins',
                'code' => 'PROMO15',
                'description' => 'Show this code at check-in.',
            ])
            ->assertRedirect();

        $promotion = Promotion::sole();
        $this->assertSame('accommodation', $promotion->listing_kind);
        $this->assertSame($listing->id, $promotion->listing_id);
        $this->assertSame('PROMO15', $promotion->code);
    }

    public function test_an_establishment_with_no_linked_listing_is_redirected(): void
    {
        $partner = EstablishmentAccount::create([
            'business_name' => 'Unlinked', 'listing_kind' => 'accommodation',
            'matched_listing_id' => null, 'portal_key' => (string) Str::uuid(),
            'email' => 'unlinked@test.example.com', 'password_hash' => Hash::make('x'),
            'contact_person' => 'Tester', 'contact_number' => '09170000000',
            'status' => 'approved', 'submitted_at' => now(),
        ]);

        $this->actingAs($partner, 'establishment')
            ->get('/portal/establishment/promotions')
            ->assertRedirect(route('establishment.overview'));
    }

    public function test_an_establishment_cannot_remove_another_establishments_promo(): void
    {
        [$partner] = $this->partnerWithListing();
        [$otherPartner, $otherListing] = $this->partnerWithListingNamed('other@test.example.com');

        $promotion = Promotion::create([
            'listing_kind' => 'accommodation', 'listing_id' => $otherListing->id,
            'title' => 'Not Yours', 'code' => 'NOPE',
        ]);

        $this->actingAs($partner, 'establishment')
            ->delete("/portal/establishment/promotions/{$promotion->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('promotions', ['id' => $promotion->id]);
    }

    public function test_an_establishment_can_remove_its_own_promo(): void
    {
        [$partner, $listing] = $this->partnerWithListing();

        $promotion = Promotion::create([
            'listing_kind' => 'accommodation', 'listing_id' => $listing->id,
            'title' => 'Gone Soon', 'code' => 'BYE',
        ]);

        $this->actingAs($partner, 'establishment')
            ->delete("/portal/establishment/promotions/{$promotion->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('promotions', ['id' => $promotion->id]);
    }

    public function test_a_promo_appears_on_the_listings_public_page(): void
    {
        [, $listing] = $this->partnerWithListing();

        Promotion::create([
            'listing_kind' => 'accommodation', 'listing_id' => $listing->id,
            'title' => '15% Off Walk-ins', 'code' => 'PROMO15',
        ]);

        $this->get(route('accommodations.show', $listing))
            ->assertOk()
            ->assertSee('15% Off Walk-ins')
            ->assertSee('PROMO15');
    }

    public function test_a_promo_does_not_appear_on_a_different_listing(): void
    {
        [, $listing] = $this->partnerWithListing();
        [, $otherListing] = $this->partnerWithListingNamed('other2@test.example.com');

        Promotion::create([
            'listing_kind' => 'accommodation', 'listing_id' => $listing->id,
            'title' => '15% Off Walk-ins', 'code' => 'PROMO15',
        ]);

        $this->get(route('accommodations.show', $otherListing))
            ->assertOk()
            ->assertDontSee('15% Off Walk-ins');
    }

    public function test_a_promo_outside_its_scheduled_window_does_not_show(): void
    {
        [, $listing] = $this->partnerWithListing();

        Promotion::create([
            'listing_kind' => 'accommodation', 'listing_id' => $listing->id,
            'title' => 'Not Yet Live', 'ends_at' => now()->subDay(),
        ]);

        $this->get(route('accommodations.show', $listing))
            ->assertOk()
            ->assertDontSee('Not Yet Live');
    }

    /** @return array{0: EstablishmentAccount, 1: Accommodation} */
    private function partnerWithListingNamed(string $email): array
    {
        $listing = Accommodation::create([
            'slug' => 'promo-test-resort-'.Str::random(6), 'name' => 'Other Resort', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);

        $partner = EstablishmentAccount::create([
            'business_name' => 'Other Resort', 'listing_kind' => 'accommodation',
            'matched_listing_id' => $listing->id, 'portal_key' => (string) Str::uuid(),
            'email' => $email, 'password_hash' => Hash::make('x'),
            'contact_person' => 'Tester', 'contact_number' => '09170000000',
            'status' => 'approved', 'submitted_at' => now(),
        ]);

        return [$partner, $listing];
    }
}
