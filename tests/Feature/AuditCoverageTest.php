<?php

namespace Tests\Feature;

use App\Models\AccreditationRecord;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\EstablishmentAccount;
use App\Models\Package;
use App\Models\Promotion;
use App\Models\Region;
use App\Models\SystemAuditLog;
use App\Models\TourOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit-trail coverage (system_audit_logs). READ-ONLY CHARACTERISATION, written before any fix:
 *  - what an administrator's console actions write (the middleware App\Http\Middleware\AuditAdminActions), and
 *  - what a partner's (establishment portal) actions write: today nothing, because system_audit_logs.admin_id is a NOT NULL
 *    foreign key to admin_users and the establishment route group has no audit middleware.
 * The "currently writes no row" tests describe today's behaviour; they are meant to be flipped when the gap is closed.
 * Set AUDIT_DUMP=<path> to write every row the admin tests created as JSON.
 */
class AuditCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create(['email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);
    }

    private function park(): Destination
    {
        return Destination::create([
            'slug' => 'peoples-park', 'name' => "People's Park", 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Nature & Leisure', 'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Free',
            'latitude' => 7.07, 'longitude' => 125.61,
        ]);
    }

    private function partnerFor(Destination $listing): EstablishmentAccount
    {
        return EstablishmentAccount::create([
            'business_name' => $listing->name, 'listing_kind' => 'destination', 'matched_listing_id' => $listing->id,
            'portal_key' => (string) Str::uuid(), 'email' => 'owner@test.example.com', 'password_hash' => Hash::make('x'),
            'contact_person' => 'Owner', 'contact_number' => '09170000000', 'status' => 'approved', 'submitted_at' => now(),
        ]);
    }

    private function dump(): void
    {
        if ($path = getenv('AUDIT_DUMP')) {
            file_put_contents($path, SystemAuditLog::orderBy('id')->get(['id', 'admin_id', 'action', 'affected_table', 'affected_record_id', 'description', 'created_at'])->toJson(JSON_PRETTY_PRINT));
        }
    }

    public function test_an_admin_closing_and_reopening_a_place_writes_one_audit_row_each_time(): void
    {
        Carbon::setTestNow('2026-11-07 10:00:00');
        $admin = $this->admin();
        $park = $this->park();

        $this->actingAs($admin, 'admin')->put(route('admin.listings.update', ['destinations', $park->id]), [
            'name' => "People's Park", 'operating_status' => 'temporarily_closed', 'reopens_on' => '2026-12-01', 'closure_reason' => 'Repairs',
        ])->assertRedirect();
        $this->assertSame('temporarily_closed', $park->fresh()->operating_status);
        $this->assertSame(1, SystemAuditLog::count());
        $close = SystemAuditLog::first();
        $this->assertSame($admin->id, $close->admin_id);
        $this->assertSame('admin.listings.update', $close->action);
        $this->assertSame('destinations', $close->affected_table);
        $this->assertSame((string) $park->id, $close->affected_record_id);
        $this->assertStringContainsString('operating_status=temporarily_closed', $close->description);

        // The reopening: the exact admin-console action the owner will take.
        $this->actingAs($admin, 'admin')->put(route('admin.listings.update', ['destinations', $park->id]), [
            'name' => "People's Park", 'operating_status' => 'open',
        ])->assertRedirect();
        $this->assertSame('open', $park->fresh()->operating_status);
        $this->assertNull($park->fresh()->reopens_on);
        $this->assertSame(2, SystemAuditLog::count());
        $this->assertStringContainsString('operating_status=open', SystemAuditLog::latest('id')->first()->description);

        $this->dump();
        Carbon::setTestNow();
    }

    public function test_other_admin_changes_each_write_a_row_but_only_say_which_fields_changed(): void
    {
        $admin = $this->admin();
        $park = $this->park();

        // listing edit (not the status)
        $this->actingAs($admin, 'admin')->put(route('admin.listings.update', ['destinations', $park->id]), ['name' => "People's Park", 'hours' => '6:00 AM-6:00 PM'])->assertRedirect();
        // advisory post
        $this->actingAs($admin, 'admin')->post(route('admin.advisories.store'), ['title' => 'Road closure', 'message' => 'Main road closed', 'severity' => 'info'])->assertRedirect();
        // accreditation renewal
        $record = AccreditationRecord::create([
            'listing_kind' => 'destination', 'listing_id' => $park->id, 'accreditation_number' => 'DOT-R11-TEST-1', 'status' => 'Expired',
            'issue_date' => now()->subYears(2), 'expiration_date' => now()->subMonth(), 'is_placeholder' => false,
        ]);
        $this->actingAs($admin, 'admin')->post(route('admin.accreditation.renew', $record), ['expiration_date' => now()->addYear()->toDateString()])->assertRedirect();
        // package review step (ask for changes)
        $operator = TourOperator::create(['slug' => 'op', 'name' => 'Op Tours', 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range']);
        $package = Package::create([
            'slug' => 'trek', 'name' => 'Summit Trek', 'location' => 'Davao del Sur', 'region_id' => $operator->region_id, 'is_accredited' => false, 'rating' => 0, 'review_count' => 0,
            'price_per_pax' => 6500, 'duration_label' => '3 Days', 'price_tier' => 'Premium', 'type' => 'Adventure', 'tour_operator_id' => $operator->id, 'status' => Package::STATUS_IN_REVIEW,
        ]);
        $this->actingAs($admin, 'admin')->post(route('admin.package-approvals.decide', $package), ['decision' => 'changes', 'note' => 'Add the itinerary'])->assertRedirect();

        $actions = SystemAuditLog::orderBy('id')->pluck('action')->all();
        $this->assertSame(['admin.listings.update', 'admin.advisories.store', 'admin.accreditation.renew', 'admin.package-approvals.decide'], $actions);

        // The rows now hold the new value: the renewal's new expiry date, the package review's decision, the advisory's record id.
        $renew = SystemAuditLog::where('action', 'admin.accreditation.renew')->first();
        $this->assertStringContainsString('expiration_date='.now()->addYear()->toDateString(), $renew->description);
        $decide = SystemAuditLog::where('action', 'admin.package-approvals.decide')->first();
        $this->assertStringContainsString('decision=changes', $decide->description);
        $advisory = SystemAuditLog::where('action', 'admin.advisories.store')->first();
        $this->assertSame((string) \App\Models\Advisory::first()->id, $advisory->affected_record_id);
        $this->assertSame('packages', $decide->affected_table);
        // The package keeps its own step history, with actor and note.
        $this->assertSame('changes_requested', $package->events()->first()->action);

        $this->dump();
    }

    public function test_a_partner_changing_their_places_status_currently_writes_no_audit_row(): void
    {
        $park = $this->park();
        $partner = $this->partnerFor($park);

        $this->actingAs($partner, 'establishment')->put('/portal/establishment/listing', [
            'operating_status' => 'temporarily_closed', 'reopens_on' => '2026-12-20', 'closure_reason' => 'Renovation',
        ])->assertRedirect();

        $this->assertSame('temporarily_closed', $park->fresh()->operating_status, 'the change was made');
        $this->assertSame(0, SystemAuditLog::count(), 'GAP: and nothing recorded who made it');
    }

    public function test_other_partner_changes_currently_write_no_audit_row_either(): void
    {
        $park = $this->park();
        $partner = $this->partnerFor($park);

        $this->actingAs($partner, 'establishment')->post('/portal/establishment/promotions', ['title' => '10% off'])->assertRedirect();
        $this->assertSame(1, Promotion::count());

        $this->assertSame(0, SystemAuditLog::count(), 'GAP: a promotion added by a partner leaves no audit row');
    }
}
