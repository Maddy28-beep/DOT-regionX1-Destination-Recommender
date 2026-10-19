<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminListingChecksTest extends TestCase
{
    use RefreshDatabase;

    private function setUpListing(): array
    {
        $region = Region::create(['name' => 'Davao City']);
        $d = Destination::create(['slug' => 'park', 'name' => 'Park', 'location' => 'Davao', 'region_id' => $region->id, 'type' => 'Nature', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range']);
        $admin = AdminUser::create(['email' => 'a@dot.test', 'password_hash' => Hash::make('x'), 'full_name' => 'A', 'role' => 'super_admin']);

        return [$d, $admin];
    }

    private function save(AdminUser $admin, Destination $d, array $over)
    {
        return $this->actingAs($admin, 'admin')->put("/portal/admin/listings/destinations/{$d->id}", $over + ['name' => 'Park', 'type' => 'Nature']);
    }

    public function test_a_pin_outside_the_davao_region_is_refused(): void
    {
        [$d, $admin] = $this->setUpListing();

        $this->save($admin, $d, ['latitude' => 7.07, 'longitude' => -125.6])->assertSessionHasErrors('longitude');   // sign slipped
        $this->save($admin, $d, ['latitude' => 125.6, 'longitude' => 7.07])->assertSessionHasErrors(['latitude', 'longitude']);   // swapped
        $this->save($admin, $d, ['latitude' => 7.07, 'longitude' => 125.6])->assertSessionHasNoErrors();
    }

    public function test_the_maximum_fee_cannot_be_below_the_minimum(): void
    {
        [$d, $admin] = $this->setUpListing();

        $this->save($admin, $d, ['entry_fee_min' => 500, 'entry_fee_max' => 100])->assertSessionHasErrors('entry_fee_max');
        $this->save($admin, $d, ['entry_fee_min' => 100, 'entry_fee_max' => 500])->assertSessionHasNoErrors();
    }

    public function test_hours_the_planner_cannot_read_are_called_out_and_readable_ones_are_not(): void
    {
        [$d, $admin] = $this->setUpListing();

        $this->save($admin, $d, ['hours' => '8am to 5'])->assertSessionHas('status_detail', fn ($v) => str_contains($v, 'cannot read these hours'));
        $this->save($admin, $d, ['hours' => 'Mon–Sat 8:00 AM–5:00 PM'])->assertSessionHas('status_detail', fn ($v) => ! str_contains($v, 'cannot read'));
        $this->save($admin, $d, ['hours' => '8:00 AM–5:00 PM'])->assertSessionHas('status_detail', fn ($v) => ! str_contains($v, 'cannot read'));
    }
}
