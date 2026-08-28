<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\EstablishmentAccount;
use App\Models\Notification;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Smoke coverage for the partner portal after the shared form-styling pass.
 *
 * These assert the shared components are actually reaching every module --
 * the failure mode being guarded against is one page quietly keeping a raw
 * <select> or a hardcoded tone colour and drifting from the others again.
 */
class EstablishmentPortalRendersTest extends TestCase
{
    use RefreshDatabase;

    private function partner(): EstablishmentAccount
    {
        $region = Region::create(['name' => 'Davao City']);

        $listing = Accommodation::create([
            'slug' => 'test-resort',
            'name' => 'Test Resort',
            'location' => 'Davao City',
            'region_id' => $region->id,
            'type' => 'Hotel',
            'is_accredited' => true,
            'rating' => 4.5,
            'review_count' => 2,
            'price_tier' => 'Mid-range',
            'price_per_night' => 3500,
        ]);

        return EstablishmentAccount::create([
            'business_name' => 'Test Resort',
            'listing_kind' => 'accommodation',
            'matched_listing_id' => $listing->id,
            'portal_key' => (string) Str::uuid(),
            'email' => 'partner@test.example.com',
            'password_hash' => Hash::make('irrelevant-actingAs-is-used'),
            'contact_person' => 'Test Partner',
            'contact_number' => '09170000000',
            'status' => 'approved',
            'submitted_at' => now(),
        ]);
    }

    /** Every module renders without error for a linked, approved partner. */
    public function test_all_portal_pages_render(): void
    {
        $partner = $this->partner();

        $pages = [
            '/portal/establishment',
            '/portal/establishment/listing',
            '/portal/establishment/photos',
            '/portal/establishment/reviews',
            '/portal/establishment/notifications',
            '/portal/establishment/notifications?filter=unread',
        ];

        foreach ($pages as $uri) {
            $this->actingAs($partner, 'establishment')
                ->get($uri)
                ->assertOk();
        }
    }

    /** No module may ship a native, unstyled select. */
    public function test_every_select_uses_the_shared_custom_treatment(): void
    {
        $partner = $this->partner();

        foreach (['/portal/establishment/listing', '/portal/establishment/photos'] as $uri) {
            $html = $this->actingAs($partner, 'establishment')->get($uri)->getContent();

            preg_match_all('/<select[^>]*>/', $html, $matches);
            $this->assertNotEmpty($matches[0], "Expected at least one select on {$uri}");

            foreach ($matches[0] as $tag) {
                $this->assertStringContainsString('form-select', $tag,
                    "Unstyled <select> found on {$uri}: {$tag}");
            }
        }
    }

    /** The native "Choose Files | No file chosen" control must never render. */
    public function test_file_input_uses_the_shared_label_pattern(): void
    {
        $html = $this->actingAs($this->partner(), 'establishment')
            ->get('/portal/establishment/photos')
            ->getContent();

        $this->assertStringContainsString('file-field__btn', $html);
        $this->assertStringContainsString('data-file-status', $html);
        $this->assertMatchesRegularExpression('/<label class="file-field">/', $html);
    }

    /** Tone colours come from tokens, never inline hexes. */
    public function test_no_hardcoded_tone_hexes_in_portal_markup(): void
    {
        $partner = $this->partner();

        foreach (['/portal/establishment', '/portal/establishment/listing', '/portal/establishment/photos'] as $uri) {
            $html = $this->actingAs($partner, 'establishment')->get($uri)->getContent();

            $this->assertDoesNotMatchRegularExpression('/#b5680a|#fff3e0|#fdecea|#e7f6ee/i', $html,
                "Hardcoded tone hex leaked into {$uri}");
        }
    }

    /**
     * The bug this guards: an approved account whose accreditation had lapsed
     * showed "Approved" on the status card while the panel below said
     * "Expired". Both now read portalStatus(), so they cannot disagree.
     */
    public function test_status_card_reflects_lapsed_accreditation_not_account_approval(): void
    {
        $partner = $this->partner();

        AccreditationRecord::create([
            'listing_kind' => 'accommodation',
            'listing_id' => $partner->matched_listing_id,
            'accreditation_number' => 'DOT-XI-TEST-1',
            'status' => 'Expired',
            'issue_date' => now()->subYears(2),
            'expiration_date' => now()->subMonth(),
        ]);

        $status = $partner->fresh()->portalStatus();
        $this->assertSame('Accreditation Expired', $status['label']);
        $this->assertTrue($status['actionRequired']);

        $html = $this->actingAs($partner, 'establishment')->get('/portal/establishment')->getContent();

        $this->assertStringContainsString('Accreditation Expired', $html);
        $this->assertStringContainsString('Action Required', $html);
        // The stale account-approval wording must not be presented as the status.
        $this->assertStringNotContainsString('>Approved<', $html);
        // The portal-wide alert bar is driven by the same method.
        $this->assertStringContainsString('portal-alert', $html);
    }

    /** A healthy listing shows no action-required alert bar. */
    public function test_healthy_account_shows_no_alert_bar(): void
    {
        $partner = $this->partner();

        AccreditationRecord::create([
            'listing_kind' => 'accommodation',
            'listing_id' => $partner->matched_listing_id,
            'accreditation_number' => 'DOT-XI-TEST-2',
            'status' => 'Active',
            'issue_date' => now()->subMonths(3),
            'expiration_date' => now()->addYear(),
        ]);

        $this->assertSame('Active', $partner->fresh()->portalStatus()['label']);

        $html = $this->actingAs($partner, 'establishment')->get('/portal/establishment')->getContent();
        $this->assertStringNotContainsString('portal-alert', $html);
    }

    /** The QR panel shows the URL the code actually encodes. */
    public function test_qr_panel_shows_encoded_destination(): void
    {
        $partner = $this->partner();
        $listing = $partner->matchedListing;

        $html = $this->actingAs($partner, 'establishment')
            ->get('/portal/establishment/listing')
            ->getContent();

        $expected = route('check-in', ['type' => 'accommodations', 'id' => $listing->id]);

        $this->assertStringContainsString('qr-frame', $html);
        $this->assertStringContainsString(e($expected), $html);
        $this->assertStringContainsString('Download QR Code', $html);
    }

    /**
     * Notifications must no longer self-clear on view, or the Unread filter
     * would always render empty.
     */
    public function test_viewing_notifications_does_not_mark_them_read(): void
    {
        $partner = $this->partner();

        Notification::create([
            'user_type' => 'establishment',
            'user_id' => $partner->id,
            'title' => 'Accreditation renewed',
            'message' => 'Your accreditation was renewed.',
            'is_read' => false,
        ]);

        $this->actingAs($partner, 'establishment')->get('/portal/establishment/notifications')->assertOk();

        $this->assertSame(1, $partner->notifications()->where('is_read', false)->count(),
            'Viewing the list should leave unread state alone.');

        $this->actingAs($partner, 'establishment')
            ->post('/portal/establishment/notifications/read-all')
            ->assertRedirect();

        $this->assertSame(0, $partner->notifications()->where('is_read', false)->count(),
            'Mark all as read should clear it.');
    }
}
