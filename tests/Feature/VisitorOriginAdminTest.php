<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\TouristPreference;
use App\Support\SmallGroupSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The admin view of visitor origin: counts only, groups under 5 never shown alone, behind the trip_location_v2 switch. */
class VisitorOriginAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create(['email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);
    }

    private function visitors(int $n, string $country, ?string $province = null, string $source = 'chosen'): void
    {
        for ($i = 0; $i < $n; $i++) {
            $p = new TouristPreference();
            $p->forceFill([
                'travel_days' => 1, 'travel_type' => 'Couple', 'travel_purpose' => 'Leisure', 'visitor_type' => 'Regular / Domestic',
                'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
                'visitor_origin_country_code' => $source === 'unspecified' ? null : $country,
                'visitor_origin_province' => $province, 'visitor_origin_source' => $source,
            ])->save();
        }
    }

    // ---- the suppression rule itself ----------------------------------------------------------------------

    public function test_groups_under_the_minimum_are_folded_into_other(): void
    {
        $r = SmallGroupSuppression::apply(['A' => 9, 'B' => 6, 'C' => 3, 'D' => 2], 5);

        $this->assertSame([['label' => 'A', 'count' => 9], ['label' => 'B', 'count' => 6], ['label' => 'Other (groups under 5)', 'count' => 5]], $r['rows']);
    }

    public function test_an_other_row_that_would_still_be_small_takes_the_smallest_group_with_it(): void
    {
        // C alone (2) would reveal "2 people": B (5) is folded in so Other is 7 and no shown row can be subtracted to find 2.
        $r = SmallGroupSuppression::apply(['A' => 9, 'B' => 5, 'C' => 2], 5);

        $this->assertSame([['label' => 'A', 'count' => 9], ['label' => 'Other (groups under 5)', 'count' => 7]], $r['rows']);
    }

    public function test_a_table_smaller_than_the_minimum_shows_nothing(): void
    {
        $r = SmallGroupSuppression::apply(['A' => 2, 'B' => 2], 5);

        $this->assertFalse($r['enough']);
        $this->assertSame([], $r['rows']);
    }

    public function test_no_row_below_the_minimum_ever_appears(): void
    {
        foreach ([[1, 1, 1, 1, 1, 1], [4, 4, 4], [5, 1, 1, 1], [20, 3, 1], [5, 5, 4, 1], [7, 2]] as $set) {
            $r = SmallGroupSuppression::apply(array_combine(array_map(fn ($i) => 'G'.$i, array_keys($set)), $set), 5);
            foreach ($r['rows'] as $row) {
                $this->assertGreaterThanOrEqual(5, $row['count'], json_encode($set));
            }
        }
    }

    // ---- the page -----------------------------------------------------------------------------------------

    public function test_the_page_sends_you_to_the_overview_with_the_switch_off(): void
    {
        config(['features.trip_location_v2' => false]);

        $this->actingAs($this->admin(), 'admin')->get(route('admin.visitor-origin'))->assertRedirect(route('admin.overview'));
    }

    public function test_it_needs_an_admin_login(): void
    {
        config(['features.trip_location_v2' => true]);

        $this->get(route('admin.visitor-origin'))->assertRedirect();
    }

    public function test_it_shows_big_groups_and_hides_small_ones(): void
    {
        config(['features.trip_location_v2' => true]);
        $this->visitors(8, 'PH', 'Cebu');
        $this->visitors(6, 'PH', 'Iloilo');
        $this->visitors(3, 'PH', 'Bohol');
        $this->visitors(2, 'PH', 'Leyte');
        $this->visitors(6, 'KR');
        $this->visitors(2, 'JP');
        $this->visitors(3, 'TH');
        $this->visitors(5, 'NONE', null, 'unspecified');

        $page = $this->actingAs($this->admin(), 'admin')->get(route('admin.visitor-origin'))->assertOk();

        $page->assertSee('Philippines');
        $page->assertSee('Cebu');
        $page->assertSee('South Korea');
        $page->assertSee('Other (groups under 5)');
        $page->assertDontSee('Bohol');
        $page->assertDontSee('Japan');
        $page->assertDontSee('export', false);
        $page->assertDontSee('.csv', false);
    }

    public function test_with_too_few_answers_it_says_so_and_lists_nothing(): void
    {
        config(['features.trip_location_v2' => true]);
        $this->visitors(2, 'KR');

        $page = $this->actingAs($this->admin(), 'admin')->get(route('admin.visitor-origin'))->assertOk();

        $page->assertSee('Not enough answers yet');
        $page->assertDontSee('South Korea');
    }

    public function test_the_date_filter_limits_the_counts(): void
    {
        config(['features.trip_location_v2' => true]);
        $this->visitors(6, 'KR');

        $this->actingAs($this->admin(), 'admin')->get(route('admin.visitor-origin', ['from' => '2099-01-01']))
            ->assertOk()->assertSee('Not enough answers yet');
    }

    public function test_the_menu_link_shows_only_with_the_switch_on(): void
    {
        $admin = $this->admin();

        config(['features.trip_location_v2' => true]);
        $this->actingAs($admin, 'admin')->get(route('admin.overview'))->assertSee('Visitor origin');

        config(['features.trip_location_v2' => false]);
        $this->actingAs($admin, 'admin')->get(route('admin.overview'))->assertDontSee('Visitor origin');
    }
}
