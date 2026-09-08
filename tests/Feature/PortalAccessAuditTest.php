<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\EstablishmentAccount;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A sweep of both consoles as each kind of account, covering the pages the
 * per-module tests do not: the report exports, the four listing modules
 * beyond destinations and accommodations, the create/edit/QR screens, and the
 * two partner states that are easy to forget because the happy path never
 * produces them -- an account still awaiting review, and one whose listing was
 * archived after approval (BlueJaz, in the live database).
 *
 * It also pins the authorization boundaries. There are only two staffed
 * guards and no traveller accounts at all, so "who can open what" is small
 * enough to assert exhaustively, and worth doing: a partner reading the admin
 * console would expose every other establishment's records.
 */
class PortalAccessAuditTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_PAGES = [
        '/portal/admin/',
        '/portal/admin/accreditation',
        '/portal/admin/association-rules',
        '/portal/admin/establishments',
        '/portal/admin/exit-surveys',
        '/portal/admin/reports',
        '/portal/admin/reports/print',
        '/portal/admin/reports/export.csv',
        '/portal/admin/listings/destinations',
        '/portal/admin/listings/accommodations',
        '/portal/admin/listings/restaurants',
        '/portal/admin/listings/packages',
        '/portal/admin/listings/souvenir-centers',
        '/portal/admin/listings/tour-operators',
        '/portal/admin/listings/destinations/create',
    ];

    private const PARTNER_PAGES = [
        '/portal/establishment',
        '/portal/establishment/listing',
        '/portal/establishment/listing/qr-code',
        '/portal/establishment/notifications',
        '/portal/establishment/photos',
        '/portal/establishment/reviews',
    ];

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'email' => 'audit-admin@dot.gov.ph',
            'password_hash' => Hash::make('irrelevant-actingAs-is-used'),
            'full_name' => 'Audit Admin',
            'role' => 'super_admin',
        ]);
    }

    private function listing(string $slug = 'audit-resort'): Accommodation
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Accommodation::create([
            'slug' => $slug, 'name' => 'Audit Resort', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Hotel', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 2, 'price_tier' => 'Mid-range',
        ]);
    }

    private function partner(?int $listingId, string $status = 'approved', string $email = 'audit-partner@test.example.com'): EstablishmentAccount
    {
        return EstablishmentAccount::create([
            'business_name' => 'Audit Resort',
            'listing_kind' => 'accommodation',
            'matched_listing_id' => $listingId,
            'portal_key' => (string) Str::uuid(),
            'email' => $email,
            'password_hash' => Hash::make('irrelevant-actingAs-is-used'),
            'contact_person' => 'Audit Partner',
            'contact_number' => '09170000000',
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }

    public function test_every_admin_page_renders(): void
    {
        $this->listing();
        Destination::create([
            'slug' => 'audit-destination', 'name' => 'Audit Destination', 'location' => 'Davao City',
            'region_id' => Region::sole()->id, 'type' => 'Wildlife', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 3, 'price_tier' => 'Mid-range',
        ]);

        $admin = $this->admin();

        foreach (self::ADMIN_PAGES as $uri) {
            // Status code rather than assertSuccessful(): the CSV export hands
            // back a StreamedResponse, which that assertion cannot touch.
            $status = $this->actingAs($admin, 'admin')->get($uri)->getStatusCode();

            $this->assertSame(200, $status, "Admin page {$uri} returned HTTP {$status}.");
        }
    }

    /**
     * The CSV export is the only route in the app that streams its body, and
     * EnsureVisitorToken -- which runs on every web request -- used to finish
     * by calling withCookie() on whatever came back. That helper exists only
     * on Laravel's own response classes, so a Symfony StreamedResponse blew up
     * with "Call to undefined method" and every admin who clicked Export got a
     * 500. Asserting the status alone would not have caught it once fixed, so
     * this pulls the body through and checks the cookie still rides along.
     */
    public function test_the_reports_csv_actually_downloads(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->get('/portal/admin/reports/export.csv');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $this->assertNotSame('', trim($response->streamedContent()),
            'The export should stream actual CSV rows, not an empty file.');

        $this->assertNotNull($response->headers->getCookies()[0] ?? null,
            'The visitor-token cookie must still be set on a streamed response.');
    }

    public function test_the_per_listing_admin_screens_render(): void
    {
        $destination = Destination::create([
            'slug' => 'audit-destination', 'name' => 'Audit Destination', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Wildlife',
            'is_accredited' => true, 'rating' => 4.5, 'review_count' => 3, 'price_tier' => 'Mid-range',
        ]);

        $admin = $this->admin();

        foreach (['edit', 'qr-code'] as $screen) {
            $this->actingAs($admin, 'admin')
                ->get("/portal/admin/listings/destinations/{$destination->id}/{$screen}")
                ->assertSuccessful("The {$screen} screen should render.");
        }
    }

    public function test_every_partner_page_renders_for_an_approved_account(): void
    {
        $partner = $this->partner($this->listing()->id);

        foreach (self::PARTNER_PAGES as $uri) {
            $this->actingAs($partner, 'establishment')->get($uri)
                ->assertSuccessful("Partner page {$uri} should render.");
        }
    }

    /**
     * An account is created the moment somebody registers and sits unmatched
     * until DOT reviews it, so every page has to cope with having no listing
     * behind it. Nothing here may 500 -- a partner who cannot even see their
     * own pending status has no way to tell whether the application worked.
     */
    public function test_every_partner_page_survives_an_account_still_awaiting_review(): void
    {
        $pending = $this->partner(null, 'pending', 'audit-pending@test.example.com');

        foreach (self::PARTNER_PAGES as $uri) {
            $response = $this->actingAs($pending, 'establishment')->get($uri);

            $this->assertLessThan(500, $response->getStatusCode(),
                "Pending partner page {$uri} returned {$response->getStatusCode()}; it must not fail outright.");
        }
    }

    /**
     * The live database has exactly this: BlueJaz Beach Resort closed
     * permanently and was archived, but its approved partner account is still
     * there and can still sign in.
     */
    public function test_every_partner_page_survives_the_listing_being_archived(): void
    {
        $listing = $this->listing();
        $partner = $this->partner($listing->id);
        $listing->archive();

        foreach (self::PARTNER_PAGES as $uri) {
            $response = $this->actingAs($partner, 'establishment')->get($uri);

            $this->assertLessThan(500, $response->getStatusCode(),
                "Archived-listing partner page {$uri} returned {$response->getStatusCode()}.");
        }
    }

    /** A partner must never be able to read the console that holds every other establishment. */
    public function test_a_partner_cannot_reach_the_admin_console(): void
    {
        $partner = $this->partner($this->listing()->id);

        foreach (self::ADMIN_PAGES as $uri) {
            $status = $this->actingAs($partner, 'establishment')->get($uri)->getStatusCode();

            $this->assertNotSame(200, $status,
                "An establishment partner was served {$uri} with HTTP 200.");
        }
    }

    public function test_an_admin_is_not_signed_into_the_partner_portal(): void
    {
        $admin = $this->admin();

        foreach (self::PARTNER_PAGES as $uri) {
            $status = $this->actingAs($admin, 'admin')->get($uri)->getStatusCode();

            $this->assertNotSame(200, $status,
                "The admin guard should not satisfy the establishment guard on {$uri}.");
        }
    }

    public function test_neither_console_is_readable_without_signing_in(): void
    {
        foreach (array_merge(self::ADMIN_PAGES, self::PARTNER_PAGES) as $uri) {
            $this->get($uri)->assertRedirect('/portal/login');
        }
    }
}
