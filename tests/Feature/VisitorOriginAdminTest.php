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

    public function test_an_empty_table_always_says_why_and_how_many_answers_there_are(): void
    {
        config(['features.trip_location_v2' => true]);
        $admin = $this->admin();

        // No answers at all.
        $this->actingAs($admin, 'admin')->get(route('admin.visitor-origin'))->assertOk()
            ->assertSee('Not enough answers yet: 0 answers so far; at least 5 are needed.');

        // Two answers: the count is shown, the places are not.
        $this->visitors(1, 'KR');
        $this->visitors(1, 'PH', 'Cebu');
        $html = $this->actingAs($admin, 'admin')->get(route('admin.visitor-origin'))->assertOk()->getContent();
        $this->assertStringContainsString('Not enough answers yet: 2 answers so far; at least 5 are needed.', $html);
        $this->assertStringContainsString('Not enough answers yet: 1 answer so far; at least 5 are needed.', $html);
        $this->assertStringNotContainsString('South Korea', $html);
        $this->assertSame(3, substr_count($html, 'data-origin-empty'), 'one message per table');
    }

    public function test_the_page_does_not_use_the_one_screen_layout_that_squeezed_empty_cards(): void
    {
        config(['features.trip_location_v2' => true]);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.visitor-origin'))->assertOk()->getContent();

        $this->assertStringContainsString('adm-origin', $html);
        $this->assertStringNotContainsString('adm-fit', $html);
    }

    public function test_the_page_says_counts_are_per_browser_session(): void
    {
        config(['features.trip_location_v2' => true]);

        $this->actingAs($this->admin(), 'admin')->get(route('admin.visitor-origin'))->assertOk()
            ->assertSee('Counts are per browser session; a new submission replaces the earlier one.');
    }

    public function test_the_date_filter_reads_days_in_philippine_time_and_to_includes_the_whole_day(): void
    {
        config(['features.trip_location_v2' => true]);
        $admin = $this->admin();

        // 12 Nov 15:59:59 UTC is 12 Nov 23:59:59 in Manila; 12 Nov 17:00 UTC is 13 Nov 01:00; 13 Nov 15:59:59 UTC is 13 Nov 23:59:59; 13 Nov 16:00 UTC is 14 Nov 00:00.
        foreach (['2026-11-12 15:59:59' => 'JP', '2026-11-12 17:00:00' => 'KR', '2026-11-13 15:59:59' => 'US', '2026-11-13 16:00:00' => 'AU'] as $utc => $country) {
            for ($i = 0; $i < 5; $i++) {
                $p = new TouristPreference();
                $p->forceFill([
                    'travel_days' => 1, 'travel_type' => 'Couple', 'travel_purpose' => 'Leisure', 'visitor_type' => 'Regular / Foreign',
                    'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
                    'visitor_origin_country_code' => $country, 'visitor_origin_source' => 'chosen', 'created_at' => $utc,
                ])->save();
            }
        }

        $html = $this->actingAs($admin, 'admin')->get(route('admin.visitor-origin', ['from' => '2026-11-13', 'to' => '2026-11-13']))->assertOk()->getContent();

        $this->assertStringContainsString('South Korea', $html, '13 Nov 01:00 Manila is inside the 13th');
        $this->assertStringContainsString('United States', $html, '13 Nov 23:59 Manila is inside the 13th (the whole day is included)');
        $this->assertStringNotContainsString('Japan', $html, '12 Nov 23:59 Manila is the 12th');
        $this->assertStringNotContainsString('Australia', $html, '14 Nov 00:00 Manila is the 14th');
        $this->assertStringContainsString('Philippine time', $html);
    }

    public function test_a_bad_date_is_refused(): void
    {
        config(['features.trip_location_v2' => true]);

        $this->actingAs($this->admin(), 'admin')->get(route('admin.visitor-origin', ['from' => 'yesterday']))->assertSessionHasErrors('from');
    }

    public function test_the_province_table_counts_only_philippine_rows(): void
    {
        config(['features.trip_location_v2' => true]);
        $this->visitors(6, 'PH', 'Ilocos Norte');
        // A row that should never exist (a province saved with another country) must not reach the Philippine table.
        $this->visitors(6, 'AS', 'Ilocos Norte');

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.visitor-origin'))->assertOk()->getContent();
        preg_match('/Philippine visitors by province.*$/s', $html, $m);

        $this->assertStringContainsString('Ilocos Norte', $m[0]);
        $this->assertMatchesRegularExpression('#Ilocos Norte</td><td>6</td>#', $m[0], 'only the 6 Philippine rows are counted, not 12');
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
