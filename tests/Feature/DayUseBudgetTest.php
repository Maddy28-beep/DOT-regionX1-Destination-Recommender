<?php

namespace Tests\Feature;

use App\Models\{Accommodation, AdminUser, EstablishmentAccount, Region};
use App\Support\BudgetRanges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DayUseBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function stay(string $name, ?float $day = null, float $night = 3000, string $description = 'Day use available'): Accommodation
    {
        return Accommodation::create([
            'name' => $name, 'slug' => str($name)->slug(), 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'description' => $description, 'price_per_night' => $night, 'price_per_day' => $day,
            'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ]);
    }

    public function test_daily_presets_and_custom_amounts_resolve_independently_of_nightly_budgets(): void
    {
        foreach (['day-under-500' => [null, 499.99], 'day-500-1000' => [500.0, 999.99],
            'day-1000-2000' => [1000.0, 1999.99], 'day-2000-up' => [2000.0, null]] as $key => [$min, $max]) {
            $result = BudgetRanges::resolve('accommodations_day_use', $key, null, null);
            $this->assertSame($min, $result['min']); $this->assertSame($max, $result['max']);
            $response = $this->get('/search?purpose=accommodations&stay_mode=day_use&budget='.$key)->assertRedirect();
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);
            $this->assertSame('day_use', $params['stay_mode']);
            $this->assertEquals($min, $params['price_min'] ?? null); $this->assertEquals($max, $params['price_max'] ?? null);
        }
        $this->assertSame(1499.99, BudgetRanges::resolve('accommodations', 'under-1500', null, null)['max']);
        $this->assertNull(BudgetRanges::resolve('accommodations', 'day-under-500', null, null)['max']);
        $custom = $this->get('/search?purpose=accommodations&stay_mode=day_use&budget=custom&budget_min=250&budget_max=750')->assertRedirect();
        $this->assertStringContainsString('price_min=250', $custom->headers->get('Location'));
        $this->assertStringContainsString('price_max=750', $custom->headers->get('Location'));
    }

    public function test_day_use_filters_daily_prices_never_nightly_prices_and_keeps_unknown_rates_under_any(): void
    {
        $this->stay('Cheap daily expensive nightly', 350, 4000);
        $this->stay('Expensive daily cheap nightly', 1500, 300);
        $this->stay('Unknown daily', null, 200);
        $this->stay('Free daily', 0, 2000);
        $this->stay('Night only', null, 200, 'Overnight lodging');
        $within = $this->get('/accommodations?stay_mode=day_use&price_max=499.99')->assertOk();
        $this->assertSame(['Cheap daily expensive nightly', 'Free daily'], $within->viewData('accommodations')->pluck('name')->sort()->values()->all());
        $within->assertSee('<small>/ day</small>', false)->assertDontSee('<small>/ night</small>', false);
        $this->assertSame(['Expensive daily cheap nightly'], $this->get('/accommodations?stay_mode=day_use&price_min=1000&price_max=1999.99')->viewData('accommodations')->pluck('name')->all());
        $any = $this->get('/accommodations?stay_mode=day_use')->assertOk();
        $this->assertSame(4, $any->viewData('accommodations')->total());
        $any->assertSee('Unknown daily')->assertSee('Day-use rate not listed');
        $overnight = $this->get('/accommodations?stay_mode=overnight&price_max=499.99')->assertOk();
        $this->assertSame(['Expensive daily cheap nightly', 'Night only', 'Unknown daily'], $overnight->viewData('accommodations')->pluck('name')->sort()->values()->all());
        $overnight->assertSee('<small>/ night</small>', false);
    }

    public function test_a_recorded_daily_rate_offers_day_use_without_description_keywords(): void
    {
        $this->stay('Explicit daily rate', 500, 3000, 'A comfortable city hotel.');
        $this->assertSame(1, $this->get('/accommodations?stay_mode=day_use')->viewData('accommodations')->total());
    }

    public function test_unknown_rates_default_to_null_and_existing_nightly_prices_are_not_changed(): void
    {
        $stay = $this->stay('No recorded daily rate');
        $this->assertNull($stay->fresh()->price_per_day);
        $this->assertSame('3000.00', $stay->fresh()->price_per_night);
    }

    public function test_admins_can_set_clear_and_validate_daily_rates_without_changing_nightly_prices(): void
    {
        $stay = $this->stay('Admin stay');
        $admin = AdminUser::create(['email' => 'daily@dot.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Daily Admin', 'role' => 'super_admin']);
        $this->actingAs($admin, 'admin')->put('/portal/admin/listings/accommodations/'.$stay->id, ['name' => $stay->name, 'price_per_day' => 650])->assertSessionHasNoErrors();
        $this->assertSame('650.00', $stay->fresh()->price_per_day);
        $this->assertSame('3000.00', $stay->fresh()->price_per_night);
        $this->put('/portal/admin/listings/accommodations/'.$stay->id, ['name' => $stay->name, 'price_per_day' => -1])->assertSessionHasErrors('price_per_day');
        $this->put('/portal/admin/listings/accommodations/'.$stay->id, ['name' => $stay->name, 'price_per_day' => ''])->assertSessionHasNoErrors();
        $this->assertNull($stay->fresh()->price_per_day);
    }

    public function test_establishments_can_set_clear_and_validate_their_daily_rate(): void
    {
        $stay = $this->stay('Partner stay');
        $partner = EstablishmentAccount::create([
            'business_name' => $stay->name, 'listing_kind' => 'accommodation', 'matched_listing_id' => $stay->id,
            'portal_key' => (string) Str::uuid(), 'email' => 'daily@partner.test', 'password_hash' => Hash::make('x'),
            'contact_person' => 'Tester', 'contact_number' => '09170000000', 'status' => 'approved', 'submitted_at' => now(),
        ]);
        $this->actingAs($partner, 'establishment')->get('/portal/establishment/listing/details')->assertOk()->assertSee('name="price_per_day"', false);
        $this->put('/portal/establishment/listing', ['section' => 'details', 'price_amount' => 3000, 'price_per_day' => 700])->assertSessionHasNoErrors();
        $this->assertSame('700.00', $stay->fresh()->price_per_day);
        $this->assertSame('3000.00', $stay->fresh()->price_per_night);
        $this->put('/portal/establishment/listing', ['section' => 'details', 'price_amount' => 3000, 'price_per_day' => -5])->assertSessionHasErrors('price_per_day');
        $this->put('/portal/establishment/listing', ['section' => 'details', 'price_amount' => 3000])->assertSessionHasNoErrors();
        $this->assertSame('700.00', $stay->fresh()->price_per_day, 'Omitting the field does not clear it.');
        $this->put('/portal/establishment/listing', ['section' => 'details', 'price_amount' => 3000, 'price_per_day' => ''])->assertSessionHasNoErrors();
        $this->assertNull($stay->fresh()->price_per_day);
    }
}
