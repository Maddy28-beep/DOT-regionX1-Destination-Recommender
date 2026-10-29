<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\ListingPhoto;
use App\Models\Region;
use App\Models\SystemAuditLog;
use App\Services\Admin\DataCompleteness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Data completeness: what each listing still needs, and the gaps that can be closed on the spot.
 */
class AdminDataCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create(['email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);
    }

    private function destination(string $name, array $extra = []): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create($extra + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region->id,
            'type' => 'Nature', 'description' => 'A place.',
        ]);
    }

    private function photo(Destination $d, string $path = 'p.jpg', array $extra = []): ListingPhoto
    {
        return ListingPhoto::create($extra + ['listing_kind' => 'destination', 'listing_id' => $d->id, 'path' => $path, 'is_primary' => true]);
    }

    /** Enough ticked amenities that the amenities check passes. */
    private function amenities($destination): void
    {
        app(\App\Services\Amenities\AmenityStore::class)->sync('destination', $destination, ['parking', 'restroom', 'wifi', 'ramp']);
    }

    private function theme(Destination $d): void
    {
        DB::table('destination_embeddings')->insert([
            'destination_id' => $d->id, 'model' => 'test', 'dimensions' => 2, 'text_hash' => 'h'.$d->id, 'vector' => '[0.1,0.2]',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_listing_with_nothing_filled_in_fails_every_check_that_applies(): void
    {
        $this->destination('Bare Falls', ['description' => null]);

        $failing = app(DataCompleteness::class)->failing('destinations');

        $this->assertCount(1, $failing);
        $this->assertEqualsCanonicalizing(['coords', 'hours', 'embed', 'desc', 'photo', 'amenities'], $failing[0]['miss']);
    }

    public function test_a_complete_listing_passes_everything(): void
    {
        $d = $this->destination('Whole Falls', ['latitude' => 7.07, 'longitude' => 125.6, 'hours' => 'Daily 8-5']);
        $this->photo($d, 'p.jpg', ['source_name' => 'Owner']);
        $this->theme($d);
        $this->amenities($d);

        $service = app(DataCompleteness::class);

        $this->assertSame([], $service->failing('destinations'));
        $this->assertSame(100, $service->summary('destinations')['readiness']);
    }

    public function test_a_photo_without_a_credit_fails_the_credit_check_but_a_placeholder_drawing_is_not_a_photo(): void
    {
        $d = $this->destination('Uncredited Falls', ['latitude' => 7.07, 'longitude' => 125.6, 'hours' => 'x']);
        $this->theme($d);
        $this->amenities($d);
        $this->photo($d, 'real.jpg');
        $this->photo($d, 'placeholder.svg', ['is_primary' => false]);

        $this->assertSame(['credit'], app(DataCompleteness::class)->failing('destinations')[0]['miss']);

        ListingPhoto::where('path', 'real.jpg')->update(['source_name' => 'Someone']);
        $this->assertSame([], app(DataCompleteness::class)->failing('destinations'));
    }

    public function test_only_the_checks_that_apply_to_a_kind_of_listing_are_counted(): void
    {
        $service = app(DataCompleteness::class);

        $this->assertContains('hours', $service->applicable('destinations'));
        $this->assertContains('embed', $service->applicable('destinations'));
        $this->assertNotContains('hours', $service->applicable('accommodations'));
        $this->assertNotContains('embed', $service->applicable('restaurants'));
        $this->assertContains('hours', $service->applicable('restaurants'));
    }

    public function test_an_expired_accreditation_is_a_gap_and_archived_listings_are_not_counted(): void
    {
        $d = $this->destination('Lapsed Falls', ['latitude' => 7.07, 'longitude' => 125.6, 'hours' => 'x']);
        $this->photo($d, 'p.jpg', ['source_name' => 'Owner']);
        $this->theme($d);
        $this->amenities($d);
        AccreditationRecord::create(['listing_kind' => 'destination', 'listing_id' => $d->id, 'accreditation_number' => 'N1', 'status' => 'Expired', 'issue_date' => now()->subYears(2), 'expiration_date' => now()->subDay()]);
        $gone = $this->destination('Gone Falls');
        $gone->update(['archived_at' => now()]);

        $failing = app(DataCompleteness::class)->failing('destinations');

        $this->assertCount(1, $failing);
        $this->assertSame(['accred'], $failing[0]['miss']);
    }

    public function test_the_worst_places_come_first(): void
    {
        $this->destination('Zed Almost', ['latitude' => 7.07, 'longitude' => 125.6, 'hours' => 'x']);
        $this->destination('Alpha Bare');

        $names = array_column(app(DataCompleteness::class)->failing('destinations'), 'name');

        $this->assertSame('Alpha Bare', $names[0]);
    }

    public function test_the_page_needs_an_admin_and_shows_the_numbers(): void
    {
        $this->get('/portal/admin/data-completeness')->assertRedirect();

        $this->destination('Bare Falls');
        $html = $this->actingAs($this->admin(), 'admin')->get('/portal/admin/data-completeness')->assertOk()->getContent();

        $this->assertStringContainsString('Data completeness', $html);
        $this->assertStringContainsString('Bare Falls', $html);
        $this->assertStringContainsString('1 of 1 missing', $html);
        $this->assertStringContainsString('Map position', $html);
        $this->assertStringContainsString('admin-data-completeness.js', $html);
    }

    public function test_a_gap_is_a_link_to_the_edit_form_so_it_works_without_javascript(): void
    {
        $d = $this->destination('Bare Falls');

        $html = $this->actingAs($this->admin(), 'admin')->get('/portal/admin/data-completeness')->getContent();

        $this->assertStringContainsString('href="'.route('admin.listings.edit', ['destinations', $d->id]).'"', $html);
    }

    public function test_the_list_can_be_filtered_to_one_check(): void
    {
        $this->destination('Needs Position');
        $this->destination('Has Position', ['latitude' => 7.07, 'longitude' => 125.6]);

        $html = $this->actingAs($this->admin(), 'admin')->get('/portal/admin/data-completeness?check=coords')->getContent();

        $this->assertStringContainsString('Needs Position', $html);
        $this->assertStringNotContainsString('data-name="Has Position"', $html);
    }

    public function test_an_unknown_type_goes_back_to_destinations(): void
    {
        $this->actingAs($this->admin(), 'admin')->get('/portal/admin/data-completeness?type=nope')->assertRedirect(route('admin.data-completeness'));
    }

    // ---- fixing a gap

    public function test_a_position_pasted_from_google_maps_is_saved_and_the_numbers_come_back(): void
    {
        $d = $this->destination('Bare Falls');

        $this->actingAs($this->admin(), 'admin')
            ->postJson("/portal/admin/data-completeness/destinations/{$d->id}", ['check' => 'coords', 'position' => '(7.0707, 125.6087)'])
            ->assertOk()
            ->assertJsonPath('summary.missing.coords', 0)
            ->assertJsonPath('row.miss', ['hours', 'embed', 'amenities', 'photo']);

        $d->refresh();
        $this->assertEquals(7.0707, (float) $d->latitude);
        $this->assertEquals(125.6087, (float) $d->longitude);
    }

    public function test_a_position_outside_the_davao_region_or_swapped_is_refused(): void
    {
        $d = $this->destination('Bare Falls');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->postJson("/portal/admin/data-completeness/destinations/{$d->id}", ['check' => 'coords', 'position' => '125.6087, 7.0707'])
            ->assertStatus(422)->assertJsonValidationErrors(['position']);
        $this->actingAs($admin, 'admin')->postJson("/portal/admin/data-completeness/destinations/{$d->id}", ['check' => 'coords', 'position' => '14.5995, 120.9842'])
            ->assertStatus(422);
        $this->actingAs($admin, 'admin')->postJson("/portal/admin/data-completeness/destinations/{$d->id}", ['check' => 'coords', 'position' => 'somewhere'])
            ->assertStatus(422);

        $this->assertNull($d->fresh()->latitude);
    }

    public function test_opening_hours_are_saved_on_a_destination_and_refused_where_there_are_none(): void
    {
        $d = $this->destination('Bare Falls');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->postJson("/portal/admin/data-completeness/destinations/{$d->id}", ['check' => 'hours', 'hours' => 'Daily 8:00 AM–5:00 PM'])->assertOk();
        $this->assertSame('Daily 8:00 AM–5:00 PM', $d->fresh()->hours);

        $region = Region::first();
        $stay = Accommodation::create(['slug' => 's', 'name' => 'A Stay', 'location' => 'Davao City', 'region_id' => $region->id, 'type' => 'Hotel']);
        $this->actingAs($admin, 'admin')->postJson("/portal/admin/data-completeness/accommodations/{$stay->id}", ['check' => 'hours', 'hours' => 'x'])->assertStatus(422);
    }

    public function test_a_photo_credit_goes_on_the_real_photos_only(): void
    {
        $d = $this->destination('Bare Falls');
        $real = $this->photo($d, 'real.jpg');
        $drawing = $this->photo($d, 'drawing.svg', ['is_primary' => false]);

        $this->actingAs($this->admin(), 'admin')
            ->postJson("/portal/admin/data-completeness/destinations/{$d->id}", ['check' => 'credit', 'source_name' => 'Philippine Eagle Foundation', 'source_url' => 'https://example.org/permission'])
            ->assertOk();

        $this->assertSame('Philippine Eagle Foundation', $real->fresh()->source_name);
        $this->assertSame('https://example.org/permission', $real->fresh()->source_url);
        $this->assertNull($drawing->fresh()->source_name, 'A placeholder drawing is not credited.');
    }

    public function test_a_credit_needs_a_name_and_a_real_link_when_there_is_one(): void
    {
        $d = $this->destination('Bare Falls');
        $this->photo($d);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->postJson("/portal/admin/data-completeness/destinations/{$d->id}", ['check' => 'credit', 'source_name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['source_name']);
        $this->actingAs($admin, 'admin')->postJson("/portal/admin/data-completeness/destinations/{$d->id}", ['check' => 'credit', 'source_name' => 'X', 'source_url' => 'not a link'])
            ->assertStatus(422)->assertJsonValidationErrors(['source_url']);
    }

    public function test_only_the_three_typed_gaps_can_be_saved_here_and_an_unknown_kind_is_a_404(): void
    {
        $d = $this->destination('Bare Falls');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->postJson("/portal/admin/data-completeness/destinations/{$d->id}", ['check' => 'embed'])->assertStatus(422);
        $this->actingAs($admin, 'admin')->postJson("/portal/admin/data-completeness/widgets/{$d->id}", ['check' => 'coords', 'position' => '7.07, 125.6'])->assertNotFound();
    }

    public function test_a_save_is_written_to_the_audit_log_against_the_listing(): void
    {
        $d = $this->destination('Bare Falls');

        $this->actingAs($this->admin(), 'admin')->postJson("/portal/admin/data-completeness/destinations/{$d->id}", ['check' => 'coords', 'position' => '7.07, 125.6'])->assertOk();

        $log = SystemAuditLog::where('action', 'admin.data-completeness.fix')->first();
        $this->assertNotNull($log);
        $this->assertSame('destinations', $log->affected_table);
        $this->assertSame((string) $d->id, $log->affected_record_id);
    }

    public function test_the_sidebar_links_to_it(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get('/portal/admin')->getContent();

        $this->assertStringContainsString(route('admin.data-completeness'), $html);
        $this->assertStringContainsString('Data completeness', $html);
    }
}
