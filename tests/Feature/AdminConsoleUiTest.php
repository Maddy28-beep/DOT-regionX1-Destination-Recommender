<?php

namespace Tests\Feature;

use App\Models\AccreditationRecord;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\Tourist;
use App\Models\TouristPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Guards the DOT Admin polish pass. The failure mode being watched for is a
 * module drifting back to a tinted status pill, a duplicated control, or a
 * bulk path that doesn't apply the same side effects as the single-row one.
 */
class AdminConsoleUiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'email' => 'a@x.test', 'password_hash' => Hash::make('x'),
            'full_name' => 'Admin', 'role' => 'super_admin',
        ]);
    }

    /** @return array<int, array{0: Destination, 1: AccreditationRecord}> */
    private function seedListings(): array
    {
        $region = Region::create(['name' => 'Davao City']);
        $made = [];

        foreach (['Active', 'Expiring Soon', 'Expired'] as $i => $status) {
            $d = Destination::create([
                'slug' => 'dest-'.$i, 'name' => 'Dest '.$i, 'location' => 'Davao City',
                'region_id' => $region->id, 'type' => 'Nature', 'is_accredited' => true,
                'rating' => 4.2, 'review_count' => 1, 'price_tier' => 'Mid-range',
            ]);

            $made[] = [$d, AccreditationRecord::create([
                'listing_kind' => 'destination', 'listing_id' => $d->id,
                'accreditation_number' => 'DOT-XI-'.$i, 'status' => $status,
                'issue_date' => now()->subYear(), 'expiration_date' => now()->addDays([300, 30, -20][$i]),
            ])];
        }

        return $made;
    }

    public function test_every_admin_module_renders(): void
    {
        $this->seedListings();
        $admin = $this->admin();

        $modules = [
            '/portal/admin/listings/destinations',
            '/portal/admin/listings/accommodations',
            '/portal/admin/tourists',
            '/portal/admin/exit-surveys',
            '/portal/admin/association-rules',
            '/portal/admin/accreditation',
            '/portal/admin/reports',
        ];

        foreach ($modules as $uri) {
            $this->actingAs($admin, 'admin')->get($uri)->assertOk();
        }
    }

    /** Global rule: no status pill or data badge may carry a background fill. */
    public function test_status_pills_and_badges_are_border_only(): void
    {
        $css = file_get_contents(public_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\.admin \.status-pill \{[^}]*background:\s*transparent/s', $css,
            'Admin status pills must clear their background fill.'
        );
        $this->assertMatchesRegularExpression(
            '/\.admin \.status-pill \{[^}]*border:\s*1\.5px solid currentColor/s', $css,
            'Admin status pills must be border-only.'
        );
        // Tourist Profiles deliberately carries no badges at all -- those
        // columns are descriptive attributes, not states, so they render as
        // plain muted text. Status colour is reserved for accreditation state.
        $this->assertStringNotContainsString('.tag-badge', $css,
            'Tourist attribute badges were removed; no dead rules should remain.');
    }

    /** Sidebar collapses six listing links into one. */
    public function test_sidebar_has_single_manage_listings_entry(): void
    {
        $this->seedListings();

        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/portal/admin/listings/destinations')->getContent();

        // Scope to the sidebar: the in-page tab row still lists all six types,
        // which is the whole point of collapsing the duplicate nav links.
        preg_match('/<aside class="admin-sidebar".*?<\/aside>/s', $html, $m);
        $sidebar = $m[0] ?? '';
        $this->assertNotSame('', $sidebar, 'Could not locate the admin sidebar.');

        $this->assertSame(1, substr_count($sidebar, '>Manage Listings</a>'));
        $this->assertStringNotContainsString('Souvenir Centers', $sidebar);
        $this->assertStringNotContainsString('Tour Operators', $sidebar);

        // ...and confirm the tabs really do still cover every type.
        $this->assertStringContainsString('Souvenir Centers', $html);
        $this->assertStringContainsString('Tour Operators', $html);
    }

    /** Edit is the filled primary; Archive stays outlined. */
    public function test_edit_is_filled_and_archive_is_outline(): void
    {
        $this->seedListings();

        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/portal/admin/listings/destinations')->getContent();

        $this->assertStringContainsString('btn btn-primary btn-xs">Edit</a>', $html);
        $this->assertStringContainsString('btn btn-outline btn-xs">Archive</button>', $html);
    }

    /** Active rows need no action, so they get plain text instead of controls. */
    public function test_only_due_rows_get_renew_controls(): void
    {
        $this->seedListings();

        $html = $this->actingAs($this->admin(), 'admin')->get('/portal/admin/accreditation')->getContent();

        $this->assertStringContainsString('Not due yet', $html);
        // Three records seeded; only the two non-Active ones post a renewal.
        $this->assertSame(2, substr_count($html, '/renew"'));
    }

    public function test_bulk_selection_markup_present_on_both_tables(): void
    {
        $this->seedListings();
        $admin = $this->admin();

        foreach (['/portal/admin/listings/destinations', '/portal/admin/accreditation'] as $uri) {
            $html = $this->actingAs($admin, 'admin')->get($uri)->getContent();

            $this->assertStringContainsString('data-bulk-all', $html, "missing select-all on {$uri}");
            $this->assertStringContainsString('data-bulk-row', $html, "missing row checkbox on {$uri}");
            $this->assertStringContainsString('bulk-bar', $html, "missing bulk bar on {$uri}");
        }
    }

    public function test_bulk_archive_archives_every_selected_listing(): void
    {
        $made = $this->seedListings();
        $ids = array_map(fn ($pair) => $pair[0]->id, $made);

        $this->actingAs($this->admin(), 'admin')
            ->post('/portal/admin/listings/destinations/bulk', ['ids' => $ids, 'action' => 'archive'])
            ->assertRedirect();

        $this->assertSame(3, Destination::whereNotNull('archived_at')->count());
    }

    /** Bulk renewal must leave the same state behind as renewing one at a time. */
    public function test_bulk_renew_applies_the_same_side_effects_as_single_renew(): void
    {
        $made = $this->seedListings();
        $ids = array_map(fn ($pair) => $pair[1]->id, $made);

        // Take the listings out of public search first, so the re-flagging is observable.
        Destination::query()->update(['is_accredited' => false]);

        $this->actingAs($this->admin(), 'admin')
            ->post('/portal/admin/accreditation/bulk-renew', [
                'ids' => $ids,
                'expiration_date' => now()->addYear()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertSame(3, AccreditationRecord::where('status', 'Active')->count());
        $this->assertSame(3, Destination::where('is_accredited', true)->count(),
            'Renewal must put listings back into public search.');
    }

    public function test_association_rule_sort_key_is_whitelisted(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/portal/admin/association-rules?sort=not_a_column&dir=asc')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('not_a_column', $html,
            'An unknown sort key must fall back to the default, not be echoed back.');
    }

    /** Tourist attributes stay plain text -- no badge chrome in this table. */
    public function test_tourist_attributes_render_as_plain_text(): void
    {
        $tourist = Tourist::create([
            'full_name' => 'Maria Santos', 'email' => 't@x.test', 'password_hash' => Hash::make('x'),
            'nationality' => 'Filipino', 'age_range' => '25-34', 'privacy_consent' => true,
        ]);

        $pref = TouristPreference::create([
            'tourist_id' => $tourist->id, 'travel_type' => 'Family', 'budget' => 'Mid-range',
            'travel_days' => 4, 'accommodation_pref' => 'Hotel', 'distance_pref' => 'moderate',
        ]);

        foreach (['Beach', 'Hiking'] as $activity) {
            PreferenceActivity::create(['preference_id' => $pref->id, 'activity' => $activity]);
        }

        $html = $this->actingAs($this->admin(), 'admin')->get('/portal/admin/tourists')->getContent();

        $this->assertStringNotContainsString('tag-badge', $html, 'No badge chrome in Tourist Profiles.');

        // The values are still all present, just unadorned.
        $this->assertStringContainsString('Family', $html);
        $this->assertStringContainsString('Mid-range', $html);
        $this->assertStringContainsString('Beach, Hiking', $html);
    }

    public function test_reports_expose_quick_presets(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get('/portal/admin/reports')->getContent();

        foreach (['Last 7 days', 'Last 30 days', 'This Month', 'This Year', 'Custom'] as $preset) {
            $this->assertStringContainsString($preset, $html);
        }
    }
}
