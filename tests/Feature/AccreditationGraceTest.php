<?php

namespace Tests\Feature;

use App\Models\AccreditationRecord;
use App\Models\Accommodation;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\EstablishmentAccount;
use App\Models\Notification;
use App\Models\Package;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ACCREDITATION_TEST_GRACE_UNTIL: while it is on, the nightly accreditation:sync-status does not mark anything
 * Expired or Expiring Soon, for any kind of listing, and never changes an expiry date. The day after, it catches up.
 */
class AccreditationGraceTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function at(string $manila): void
    {
        Carbon::setTestNow(Carbon::parse($manila, 'Asia/Manila'));
    }

    /** One listing of every kind, each with one record. @return array<string, array{listing: object, record: AccreditationRecord}> */
    private function everyKind(string $expires, string $status = 'Active'): array
    {
        $region = Region::firstOrCreate(['name' => 'Davao City'])->id;
        $base = fn (string $name) => ['slug' => Str::slug($name).'-'.(++$this->n), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region,
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0];

        $made = [
            'destination' => Destination::create($base('Place') + ['type' => 'Nature & Leisure']),
            'accommodation' => Accommodation::create($base('Inn') + ['type' => 'Hotel']),
            'restaurant' => Restaurant::create($base('Eatery') + ['cuisine_type' => 'Cafe']),
            'souvenir_center' => SouvenirCenter::create($base('Shop')),
            'tour_operator' => TourOperator::create($base('Tours')),
            'package' => Package::create($base('Trip') + ['status' => 'live', 'price_per_pax' => 1000, 'duration_label' => '1 Day', 'price_tier' => 'Budget-Friendly', 'type' => 'Adventure']),
        ];

        $out = [];
        foreach ($made as $kind => $listing) {
            $out[$kind] = ['listing' => $listing, 'record' => AccreditationRecord::create([
                'listing_kind' => $kind, 'listing_id' => $listing->id, 'accreditation_number' => 'DOT-T-'.(++$this->n),
                'status' => $status, 'issue_date' => '2024-01-01', 'expiration_date' => $expires,
            ])];
        }

        return $out;
    }

    private function sync(): string
    {
        Artisan::call('accreditation:sync-status');

        return Artisan::output();
    }

    public function test_without_the_setting_the_sync_expires_every_kind_as_before(): void
    {
        $this->at('2026-11-02 00:05');
        $lapsed = $this->everyKind('2026-10-31');
        $soon = $this->everyKind('2026-11-20');

        $this->sync();

        foreach ($lapsed as $kind => $row) {
            $this->assertSame('Expired', $row['record']->fresh()->status, $kind);
            $this->assertFalse((bool) $row['listing']->fresh()->is_accredited, "{$kind} is hidden");
        }
        foreach ($soon as $kind => $row) {
            $this->assertSame('Expiring Soon', $row['record']->fresh()->status, $kind);
        }
    }

    public function test_during_the_grace_period_nothing_expires_for_any_kind_and_no_date_changes(): void
    {
        config(['dot.accreditation_test_grace_until' => '2026-12-31']);
        $this->at('2026-11-02 00:05');
        $lapsed = $this->everyKind('2026-10-31');
        $soon = $this->everyKind('2026-11-20');
        $owner = EstablishmentAccount::create([
            'business_name' => 'Inn', 'listing_kind' => 'accommodation', 'matched_listing_id' => $lapsed['accommodation']['listing']->id, 'portal_key' => (string) Str::uuid(),
            'email' => 'o@test.example.com', 'password_hash' => Hash::make('x'), 'contact_person' => 'O', 'contact_number' => '09170000000', 'status' => 'approved', 'submitted_at' => now(),
        ]);

        $out = $this->sync();

        foreach ($lapsed as $kind => $row) {
            $record = $row['record']->fresh();
            $this->assertSame('Active', $record->status, "{$kind} is not expired");
            $this->assertSame('2026-10-31', $record->expiration_date->toDateString(), "{$kind}: the real date is untouched");
            $this->assertTrue((bool) $row['listing']->fresh()->is_accredited, "{$kind} stays on the site");
        }
        foreach ($soon as $kind => $row) {
            $this->assertSame('Active', $row['record']->fresh()->status, "{$kind} is not Expiring Soon");
            $this->assertSame('2026-11-20', $row['record']->fresh()->expiration_date->toDateString());
        }
        $this->assertSame(0, Notification::where('user_id', $owner->id)->count(), 'no "expired" or "expiring soon" notice is sent');
        $this->assertStringContainsString('TEST GRACE PERIOD ON until 2026-12-31', $out);
        $this->assertStringContainsString('12 record(s)', $out);
    }

    public function test_the_last_day_of_the_grace_period_is_still_held_and_the_next_day_catches_up(): void
    {
        config(['dot.accreditation_test_grace_until' => '2026-12-31']);
        $rows = $this->everyKind('2026-10-31');

        $this->at('2026-12-31 23:55');
        $this->sync();
        $this->assertSame('Active', $rows['destination']['record']->fresh()->status, '31 Dec is inside the period');

        $this->at('2027-01-01 00:05');
        $out = $this->sync();

        foreach ($rows as $kind => $row) {
            $this->assertSame('Expired', $row['record']->fresh()->status, "{$kind} catches up on 1 Jan");
            $this->assertFalse((bool) $row['listing']->fresh()->is_accredited);
        }
        $this->assertStringNotContainsString('TEST GRACE PERIOD', $out);
    }

    public function test_it_only_holds_back_new_expiries_and_still_applies_renewals(): void
    {
        config(['dot.accreditation_test_grace_until' => '2026-12-31']);
        $this->at('2026-11-02 00:05');
        $alreadyExpired = $this->everyKind('2026-09-01', 'Expired')['destination'];
        $alreadyExpired['listing']->update(['is_accredited' => false]);
        $renewed = $this->everyKind('2027-06-30', 'Expired')['accommodation'];
        $renewed['listing']->update(['is_accredited' => false]);

        $this->sync();

        $this->assertSame('Expired', $alreadyExpired['record']->fresh()->status, 'a record that has already expired is not revived');
        $this->assertFalse((bool) $alreadyExpired['listing']->fresh()->is_accredited);
        $this->assertSame('Active', $renewed['record']->fresh()->status, 'a renewed record goes active as usual');
        $this->assertTrue((bool) $renewed['listing']->fresh()->is_accredited);
    }

    public function test_a_setting_that_is_not_a_date_is_reported_and_does_nothing(): void
    {
        config(['dot.accreditation_test_grace_until' => 'soon']);
        $this->at('2026-11-02 00:05');
        $rows = $this->everyKind('2026-10-31');

        $out = $this->sync();

        $this->assertStringContainsString('is not a date', $out);
        $this->assertSame('Expired', $rows['destination']['record']->fresh()->status, 'a typo does not silently switch expiry off or on');
    }

    public function test_an_empty_setting_is_off(): void
    {
        config(['dot.accreditation_test_grace_until' => '']);
        $this->at('2026-11-02 00:05');
        $rows = $this->everyKind('2026-10-31');

        $out = $this->sync();

        $this->assertSame('Expired', $rows['restaurant']['record']->fresh()->status);
        $this->assertStringNotContainsString('TEST GRACE PERIOD', $out);
        $this->assertStringNotContainsString('is not a date', $out);
    }

    public function test_the_admin_accreditation_page_says_when_the_period_is_on(): void
    {
        $admin = AdminUser::create(['email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);
        $this->at('2026-11-02 12:00');

        $this->actingAs($admin, 'admin')->get(route('admin.accreditation'))->assertOk()->assertDontSee('Test period:');

        config(['dot.accreditation_test_grace_until' => '2026-12-31']);
        $this->actingAs($admin, 'admin')->get(route('admin.accreditation'))->assertOk()
            ->assertSee('Test period: until Dec 31, 2026')->assertSee('real ones and have not been changed');

        $this->at('2027-01-02 12:00');
        $this->actingAs($admin, 'admin')->get(route('admin.accreditation'))->assertOk()->assertDontSee('Test period:');
    }
}
