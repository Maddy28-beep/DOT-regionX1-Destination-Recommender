<?php

namespace Tests\Feature;

use App\Http\Controllers\QrCodeController;
use App\Http\Middleware\EnsureVisitorToken;
use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * End-to-end cover for scanning an establishment's QR code.
 *
 * The scan is the whole visit-counting mechanism now: there is no traveler
 * account, so what matters is that anyone can scan, that a scan is counted,
 * and that the same phone scanning twice in a day still counts once.
 */
class QrCheckInFlowTest extends TestCase
{
    use RefreshDatabase;

    private function listing(): Destination
    {
        $region = Region::create(['name' => 'Davao City']);

        return Destination::create([
            'slug' => 'eden-nature-park', 'name' => 'Eden Nature Park',
            'location' => 'Toril, Davao City', 'region_id' => $region->id,
            'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);
    }

    /** The image endpoint returns a real, scannable SVG. */
    public function test_qr_endpoint_renders_an_svg_containing_the_check_in_url(): void
    {
        $listing = $this->listing();

        $admin = \App\Models\AdminUser::create([
            'email' => 'a@x.test', 'password_hash' => Hash::make('x'),
            'full_name' => 'Admin', 'role' => 'super_admin',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get("/portal/admin/listings/destinations/{$listing->id}/qr-code")
            ->assertOk();

        $this->assertStringContainsString('image/svg+xml', $response->headers->get('content-type'));

        $svg = $response->getContent();
        $this->assertStringContainsString('<svg', $svg);
        // A real QR has many modules; a blank/failed render would be tiny.
        $this->assertGreaterThan(2000, strlen($svg), 'SVG looks too small to be a real QR code.');
    }

    /** Each listing encodes its own distinct URL. */
    public function test_each_listing_encodes_a_distinct_url(): void
    {
        $a = $this->listing();
        $b = Destination::create([
            'slug' => 'other', 'name' => 'Other', 'location' => 'Davao City',
            'region_id' => $a->region_id, 'type' => 'Wildlife', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0,
        ]);

        $this->assertNotSame(
            QrCodeController::targetUrlFor('destination', $a),
            QrCodeController::targetUrlFor('destination', $b)
        );
    }

    /**
     * The point of the Data Privacy Act change: scanning works with no login
     * at all. A traveler standing at the gate is counted straight away rather
     * than being bounced to a registration form they will abandon.
     */
    public function test_scanning_needs_no_account(): void
    {
        $listing = $this->listing();

        $this->get("/check-in/destinations/{$listing->id}")
            ->assertRedirect(route('destinations.show', $listing))
            ->assertSessionHas('status');

        $this->assertSame(1, TouristVisit::count());
    }

    /** The recorded visit identifies a browser, never a person. */
    public function test_a_recorded_visit_holds_no_personal_data(): void
    {
        $listing = $this->listing();

        $this->get("/check-in/destinations/{$listing->id}");

        $visit = TouristVisit::sole();

        $this->assertSame('destination', $visit->listing_kind);
        $this->assertSame($listing->id, $visit->listing_id);
        $this->assertSame('qr_scan', $visit->source);
        $this->assertNotEmpty($visit->visitor_token);
        $this->assertArrayNotHasKey('tourist_id', $visit->getAttributes());
    }

    /**
     * Re-scanning the same code the same day must not double-count, AND must
     * still be a normal, friendly response.
     *
     * The row count alone was not enough: with the dedupe SELECT comparing
     * "2026-08-30" against a stored "2026-08-30 00:00:00" it never matched, so
     * every rescan fell through to an INSERT that the unique index rejected.
     * The count stayed at 1 — correct — while the traveler got a 500.
     */
    public function test_rescanning_the_same_day_does_not_double_count(): void
    {
        $listing = $this->listing();

        // withCookie keeps the same browser across requests, which is exactly
        // what a real traveler re-scanning at the gate would do.
        $token = (string) \Illuminate\Support\Str::uuid();

        foreach (range(1, 3) as $attempt) {
            $this->withCookie(EnsureVisitorToken::COOKIE, $token)
                ->get("/check-in/destinations/{$listing->id}")
                ->assertRedirect(route('destinations.show', $listing));
        }

        $this->assertSame(1, TouristVisit::count());
        $this->assertStringContainsString('already checked in', session('status'));
    }

    /** Two different phones at the same place on the same day are two visits. */
    public function test_two_browsers_are_counted_separately(): void
    {
        $listing = $this->listing();

        foreach ([(string) \Illuminate\Support\Str::uuid(), (string) \Illuminate\Support\Str::uuid()] as $token) {
            $this->withCookie(EnsureVisitorToken::COOKIE, $token)
                ->get("/check-in/destinations/{$listing->id}");
        }

        $this->assertSame(2, TouristVisit::count());
    }

    /** A code for a de-accredited listing must stop working. */
    public function test_check_in_is_refused_for_a_listing_that_lost_accreditation(): void
    {
        $listing = $this->listing();
        $listing->update(['is_accredited' => false]);

        $this->get("/check-in/destinations/{$listing->id}")->assertNotFound();

        $this->assertSame(0, TouristVisit::count());
    }
}
