<?php

namespace Tests\Feature;

use App\Models\AccreditationRecord;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The admin overview must say out loud when places are hidden or about to be. */
class AccreditationAlertTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'email' => 'a@x.test', 'password_hash' => Hash::make('x'),
            'full_name' => 'Admin', 'role' => 'super_admin',
        ]);
    }

    private function record(string $name, string $status, int $daysFromNow, int $n): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $d = Destination::create([
            'slug' => 'alert-'.$n, 'name' => $name, 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature', 'is_accredited' => $status !== 'Expired',
            'rating' => 4.2, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ]);

        AccreditationRecord::create([
            'listing_kind' => 'destination', 'listing_id' => $d->id,
            'accreditation_number' => 'DOT-ALERT-'.$n, 'status' => $status,
            'issue_date' => now()->subYear(), 'expiration_date' => now()->addDays($daysFromNow),
        ]);
    }

    public function test_banner_reports_expired_and_expiring_counts_and_names_the_places(): void
    {
        $this->record('Hidden Falls', 'Expired', -10, 1);
        $this->record('Soon Beach', 'Expiring Soon', 3, 2);
        $this->record('Later Park', 'Expiring Soon', 20, 3);
        $this->record('Fine Cave', 'Active', 300, 4);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.overview'))->assertOk()->getContent();

        $this->assertStringContainsString('Accreditation needs attention', $html);
        $this->assertStringContainsString('1 record expired', $html);
        $this->assertStringContainsString('2 expire within 30 days', $html);
        $this->assertStringContainsString('1 within 7 days', $html);
        $this->assertStringContainsString('Hidden Falls', $html);
        $this->assertStringContainsString('Soon Beach', $html);
        $this->assertStringNotContainsString('Fine Cave', $html);
    }

    public function test_no_banner_when_everything_is_current(): void
    {
        $this->record('Fine Cave', 'Active', 300, 1);

        $this->actingAs($this->admin(), 'admin')->get(route('admin.overview'))
            ->assertOk()
            ->assertDontSee('Accreditation needs attention');
    }
}
