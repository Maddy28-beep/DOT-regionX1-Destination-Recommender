<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\Region;
use App\Models\SystemAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Browser-side security headers on every page, and an audit trail of what DOT admins do.
 */
class SecurityHeadersAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $email = 'admin@dot.test'): AdminUser
    {
        return AdminUser::create(['email' => $email, 'password_hash' => Hash::make('correct-password'), 'full_name' => 'Test Admin', 'role' => 'super_admin']);
    }

    private function place(): Destination
    {
        $region = Region::create(['name' => 'Davao City']);

        return Destination::create([
            'slug' => 'test-park', 'name' => 'Test Park', 'location' => 'Davao City', 'region_id' => $region->id,
            'type' => 'Nature & Leisure', 'is_accredited' => true,
        ]);
    }

    public function test_every_page_carries_the_security_headers(): void
    {
        $response = $this->get('/')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('geolocation=(self)', $response->headers->get('Permissions-Policy'));
        $this->assertStringContainsString("frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');

        $this->withServerVariables(['HTTPS' => 'on'])->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security');
    }

    public function test_portal_pages_are_not_cached_but_public_pages_are_unaffected(): void
    {
        $this->assertStringContainsString('no-store', $this->get('/portal/login')->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('no-store', (string) $this->get('/destinations')->headers->get('Cache-Control'));
    }

    public function test_a_change_made_by_an_admin_is_recorded_without_the_typed_values(): void
    {
        $admin = $this->admin();
        $place = $this->place();

        $this->actingAs($admin, 'admin')->put(route('admin.listings.update', ['destinations', $place->id]), [
            'name' => 'Very Private Edited Name', 'operating_status' => 'temporarily_closed', 'reopens_on' => '2026-12-01', 'closure_reason' => 'secret internal note',
        ])->assertRedirect();

        $log = SystemAuditLog::latest('id')->firstOrFail();
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame('admin.listings.update', $log->action);
        $this->assertSame('destinations', $log->affected_table);
        $this->assertSame((string) $place->id, $log->affected_record_id);
        $this->assertStringContainsString('operating_status=temporarily_closed', $log->description);
        $this->assertStringContainsString('fields:', $log->description);
        $this->assertStringNotContainsString('Very Private Edited Name', $log->description);
        $this->assertStringNotContainsString('secret internal note', $log->description);
    }

    public function test_page_views_and_forms_that_failed_are_not_recorded(): void
    {
        $admin = $this->admin();
        $place = $this->place();

        $this->actingAs($admin, 'admin')->get(route('admin.listings.index', 'destinations'))->assertOk();
        $this->actingAs($admin, 'admin')->put(route('admin.listings.update', ['destinations', $place->id]), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('system_audit_logs', 0);
    }

    public function test_archiving_is_recorded_against_the_right_record(): void
    {
        $admin = $this->admin();
        $place = $this->place();

        $this->actingAs($admin, 'admin')->post(route('admin.listings.archive', ['destinations', $place->id]))->assertRedirect();

        $this->assertDatabaseHas('system_audit_logs', [
            'admin_id' => $admin->id, 'action' => 'admin.listings.archive', 'affected_table' => 'destinations', 'affected_record_id' => (string) $place->id,
        ]);
    }

    public function test_sign_ins_and_failed_attempts_on_a_real_account_are_recorded(): void
    {
        $admin = $this->admin();

        $this->post('/portal/login', ['portal' => 'admin', 'identifier' => $admin->email, 'password' => 'wrong'])->assertRedirect();
        $this->assertDatabaseHas('system_audit_logs', ['admin_id' => $admin->id, 'action' => 'login_failed']);

        $this->post('/portal/login', ['portal' => 'admin', 'identifier' => $admin->email, 'password' => 'correct-password'])->assertRedirect();
        $this->assertDatabaseHas('system_audit_logs', ['admin_id' => $admin->id, 'action' => 'login']);
    }

    public function test_a_failed_attempt_on_an_unknown_email_is_not_recorded_and_does_not_break(): void
    {
        $this->post('/portal/login', ['portal' => 'admin', 'identifier' => 'nobody@dot.test', 'password' => 'x'])->assertRedirect();

        $this->assertDatabaseCount('system_audit_logs', 0);
    }

    public function test_the_audit_log_page_lists_entries_for_admins_only(): void
    {
        $admin = $this->admin();
        SystemAuditLog::create(['admin_id' => $admin->id, 'action' => 'admin.listings.update', 'affected_table' => 'destinations', 'affected_record_id' => '7', 'description' => 'PUT /portal/admin/listings/destinations/7']);
        SystemAuditLog::create(['admin_id' => $admin->id, 'action' => 'login', 'affected_table' => 'admin_users', 'affected_record_id' => $admin->id, 'description' => 'from 10.0.0.1']);

        $this->get(route('admin.audit-log'))->assertRedirect();

        $this->actingAs($admin, 'admin')->get(route('admin.audit-log'))
            ->assertOk()->assertSee('admin.listings.update')->assertSee('Test Admin')->assertSee('2 recorded actions');

        $this->actingAs($admin, 'admin')->get(route('admin.audit-log', ['action' => 'login']))
            ->assertOk()->assertSee('from 10.0.0.1')->assertDontSee('admin.listings.update');
    }

    public function test_creating_something_new_is_recorded_against_its_area(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post(route('admin.advisories.store'), [
            'title' => 'Road closure', 'message' => 'Main road closed', 'severity' => 'info',
        ])->assertRedirect();

        $this->assertDatabaseHas('system_audit_logs', ['admin_id' => $admin->id, 'action' => 'admin.advisories.store', 'affected_table' => 'advisories']);
    }

    public function test_a_failure_to_write_the_log_never_blocks_the_admins_action(): void
    {
        $admin = $this->admin();
        $place = $this->place();
        $this->mock(\App\Services\Audit\AuditLogger::class)->shouldReceive('recordRequest')->andThrow(new \RuntimeException('log table is gone'));

        $this->actingAs($admin, 'admin')->post(route('admin.listings.archive', ['destinations', $place->id]))->assertRedirect();

        $this->assertNotNull($place->fresh()->archived_at, 'the archive itself must still have happened');
    }
}
