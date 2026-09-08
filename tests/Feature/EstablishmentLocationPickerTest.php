<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\EstablishmentAccount;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An establishment placing itself on the map.
 *
 * 338 of 396 listings have no coordinates, which is what forces the
 * recommender back onto region centroids for distance. Geocoding the addresses
 * already on file was tried and abandoned -- they are barangay/purok level, so
 * the geocoder matched stray words and returned an elementary school for one
 * listing and a street in the wrong province for another, with confidence
 * scores that could not tell those apart from a good hit.
 *
 * The business is the only party that knows where it is. The bounds check
 * exists because a stored coordinate is trusted completely by
 * distanceKmFor(), while a missing one is honestly treated as unknown -- so a
 * wrong pin is worse than no pin.
 */
class EstablishmentLocationPickerTest extends TestCase
{
    use RefreshDatabase;

    private function partner(): EstablishmentAccount
    {
        $listing = Accommodation::create([
            'slug' => 'pin-test-resort', 'name' => 'Pin Test Resort', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);

        return EstablishmentAccount::create([
            'business_name' => 'Pin Test Resort', 'listing_kind' => 'accommodation',
            'matched_listing_id' => $listing->id, 'portal_key' => (string) Str::uuid(),
            'email' => 'pin@test.example.com', 'password_hash' => Hash::make('x'),
            'contact_person' => 'Tester', 'contact_number' => '09170000000',
            'status' => 'approved', 'submitted_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'description' => 'A quiet place by the water.',
            'price_tier' => 'Mid-range',
        ];
    }

    public function test_the_picker_appears_on_the_listing_page(): void
    {
        $html = $this->actingAs($this->partner(), 'establishment')
            ->get('/portal/establishment/listing')->assertOk()->getContent();

        $this->assertStringContainsString('location-picker', $html);
        $this->assertStringContainsString('You have not set this yet', $html,
            'A listing with no coordinates should say so, or nobody knows to fix it.');
    }

    public function test_an_establishment_can_pin_itself(): void
    {
        $partner = $this->partner();

        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', $this->form([
                'latitude' => 7.0644, 'longitude' => 125.6079,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $listing = $partner->matchedListing()->first();
        $this->assertEqualsWithDelta(7.0644, (float) $listing->latitude, 0.0001);
        $this->assertEqualsWithDelta(125.6079, (float) $listing->longitude, 0.0001);
    }

    /**
     * The failure mode geocoding produced: a confidently wrong point in
     * another region. A stored coordinate is treated as exact, so this has to
     * be refused rather than merely discouraged.
     */
    public function test_a_point_outside_davao_region_is_refused(): void
    {
        $partner = $this->partner();

        // Cebu -- where the geocoder put three Samal resorts.
        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', $this->form([
                'latitude' => 10.2794, 'longitude' => 123.9747,
            ]))
            ->assertSessionHasErrors('longitude');

        $this->assertNull($partner->matchedListing()->first()->latitude);
    }

    /** Half a coordinate is not a location. */
    public function test_a_latitude_without_a_longitude_is_refused(): void
    {
        $partner = $this->partner();

        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', $this->form(['latitude' => 7.0644]))
            ->assertSessionHasErrors('longitude');

        $this->assertNull($partner->matchedListing()->first()->latitude);
    }

    /**
     * Clearing has to be possible: an establishment that realises its pin is
     * wrong should be able to go back to "unknown", which the recommender
     * handles honestly, rather than leave a wrong point in place.
     */
    public function test_the_pin_can_be_cleared_again(): void
    {
        $partner = $this->partner();
        $listing = $partner->matchedListing()->first();
        $listing->forceFill(['latitude' => 7.0644, 'longitude' => 125.6079])->save();

        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', $this->form())
            ->assertSessionHasNoErrors();

        $this->assertNull($partner->matchedListing()->first()->latitude);
        $this->assertNull($partner->matchedListing()->first()->longitude);
    }

    /** Pinning must not disturb what the form already edited. */
    public function test_pinning_leaves_the_other_listing_fields_alone(): void
    {
        $partner = $this->partner();

        $this->actingAs($partner, 'establishment')
            ->put('/portal/establishment/listing', $this->form([
                'description' => 'Beachfront rooms and a dive shop.',
                'price_tier' => 'Premium',
                'latitude' => 7.1553, 'longitude' => 125.7080,
            ]))
            ->assertSessionHasNoErrors();

        $listing = $partner->matchedListing()->first();
        $this->assertSame('Beachfront rooms and a dive shop.', $listing->description);
        $this->assertSame('Premium', $listing->price_tier);
    }
}
