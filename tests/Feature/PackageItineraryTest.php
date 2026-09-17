<?php

namespace Tests\Feature;

use App\Models\EstablishmentAccount;
use App\Models\Package;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DOT asked for full/complete packages a tourist can follow directly instead
 * of building their own itinerary. A package's day-by-day breakdown is
 * self-service -- managed by the tour operator like price, description, and
 * photos already are -- with no separate DOT approval step before it goes
 * live on the public listing page.
 */
class PackageItineraryTest extends TestCase
{
    use RefreshDatabase;

    private function partnerWithPackage(): array
    {
        $package = Package::create([
            'slug' => 'itinerary-test-package', 'name' => 'Itinerary Test Package', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);

        $partner = EstablishmentAccount::create([
            'business_name' => 'Itinerary Test Package', 'listing_kind' => 'package',
            'matched_listing_id' => $package->id, 'portal_key' => (string) Str::uuid(),
            'email' => 'itinerary@test.example.com', 'password_hash' => Hash::make('x'),
            'contact_person' => 'Tester', 'contact_number' => '09170000000',
            'status' => 'approved', 'submitted_at' => now(),
        ]);

        return [$partner, $package];
    }

    public function test_a_tour_operator_can_add_a_day_by_day_itinerary(): void
    {
        [$partner, $package] = $this->partnerWithPackage();

        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', [
                'itinerary' => "Arrival & Camp 1 | Trek to Camp 1, briefing with your guide.\nSummit Push | Pre-dawn ascent, descend after.\nDescent & Departure",
            ])
            ->assertRedirect(route('establishment.overview'));

        $days = $package->itineraryDays()->get();
        $this->assertCount(3, $days);
        $this->assertSame(1, $days[0]->day_number);
        $this->assertSame('Arrival & Camp 1', $days[0]->title);
        $this->assertSame('Trek to Camp 1, briefing with your guide.', $days[0]->description);
        $this->assertSame('Descent & Departure', $days[2]->title);
        $this->assertNull($days[2]->description);
    }

    /** No DOT approval step: saving publishes the itinerary immediately, same as every other self-service field. */
    public function test_the_itinerary_is_published_immediately(): void
    {
        [$partner, $package] = $this->partnerWithPackage();

        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', ['itinerary' => 'Day one on the water'])
            ->assertRedirect();

        $this->get(route('packages.show', $package))
            ->assertOk()
            ->assertSee('Day one on the water');
    }

    public function test_re_saving_a_shorter_itinerary_drops_the_removed_days(): void
    {
        [$partner, $package] = $this->partnerWithPackage();

        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', ['itinerary' => "Day A\nDay B\nDay C"]);
        $this->assertCount(3, $package->itineraryDays()->get());

        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', ['itinerary' => 'Day A only']);

        $days = $package->itineraryDays()->get();
        $this->assertCount(1, $days);
        $this->assertSame('Day A only', $days[0]->title);
    }

    public function test_clearing_the_itinerary_removes_every_day(): void
    {
        [$partner, $package] = $this->partnerWithPackage();

        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', ['itinerary' => "Day A\nDay B"]);
        $this->assertCount(2, $package->itineraryDays()->get());

        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', ['itinerary' => '']);

        $this->assertCount(0, $package->itineraryDays()->get());
    }

    /** A non-package establishment (e.g. an accommodation) has no itinerary concept -- the field must be silently ignored, not error. */
    public function test_a_non_package_establishment_is_unaffected_by_the_itinerary_field(): void
    {
        $listing = \App\Models\Accommodation::create([
            'slug' => 'itinerary-test-inn', 'name' => 'Itinerary Test Inn', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);

        $partner = EstablishmentAccount::create([
            'business_name' => 'Itinerary Test Inn', 'listing_kind' => 'accommodation',
            'matched_listing_id' => $listing->id, 'portal_key' => (string) Str::uuid(),
            'email' => 'notpackage@test.example.com', 'password_hash' => Hash::make('x'),
            'contact_person' => 'Tester', 'contact_number' => '09170000000',
            'status' => 'approved', 'submitted_at' => now(),
        ]);

        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', ['itinerary' => 'Day A'])
            ->assertRedirect();

        $this->assertSame(0, \App\Models\PackageItineraryDay::count());
    }
}
