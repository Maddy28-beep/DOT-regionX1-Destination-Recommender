<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Support\SmallGroupSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The admin view of visitor origin: permanent anonymous counts, groups under 5 never shown alone, a CSV of the same totals,
 * all behind the trip_location_v2 switch.
 */
class VisitorOriginAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create(['email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);
    }

    /** Put $n visitors into one group of the permanent table (one row per day, as the app keeps it). */
    private function visitors(int $n, string $country, ?string $province = null, string $source = 'chosen', string $city = '', string $day = '2026-11-13'): void
    {
        $key = ['day' => $day, 'answer' => $source, 'country_code' => $source === 'unspecified' ? '' : $country, 'province' => (string) $province, 'city' => $city];
        DB::table('visitor_origin_daily')->insertOrIgnore($key + ['visitors' => 0]);
        DB::table('visitor_origin_daily')->where($key)->increment('visitors', $n);
    }

    // ---- the suppression rule itself ----------------------------------------------------------------------

    public function test_groups_under_the_minimum_are_folded_into_other(): void
    {
        $r = SmallGroupSuppression::apply(['A' => 9, 'B' => 6, 'C' => 3, 'D' => 2], 5);

        $this->assertSame([['label' => 'A', 'count' => 9], ['label' => 'B', 'count' => 6], ['label' => 'Other (groups under 5)', 'count' => 5]], $r['rows']);
    }

    public function test_an_other_row_that_would_still_be_small_takes_the_smallest_group_with_it(): void
    {
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

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->get(route('admin.visitor-origin'))->assertRedirect(route('admin.overview'));
        $this->actingAs($admin, 'admin')->get(route('admin.visitor-origin.export'))->assertRedirect(route('admin.overview'));
    }

    public function test_it_needs_an_admin_login(): void
    {
        config(['features.trip_location_v2' => true]);

        $this->get(route('admin.visitor-origin'))->assertRedirect();
        $this->get(route('admin.visitor-origin.export'))->assertRedirect();
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
    }

    public function test_the_city_table_shows_only_cities_with_five_or_more_visitors(): void
    {
        config(['features.trip_location_v2' => true]);
        $this->visitors(6, 'PH', 'Cebu', 'chosen', 'Cebu City');
        $this->visitors(3, 'PH', 'Bohol', 'chosen', 'Tagbilaran');
        $this->visitors(2, 'PH', 'Leyte', 'chosen', 'Tacloban');
        $this->visitors(5, 'JP', null, 'chosen', 'Tokyo');

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.visitor-origin'))->assertOk()->getContent();
        preg_match('/By city or municipality.*$/s', $html, $m);

        $this->assertStringContainsString('Cebu City (Philippines)', $m[0]);
        $this->assertStringContainsString('Tokyo (Japan)', $m[0]);
        $this->assertStringNotContainsString('Tagbilaran', $html);
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

        $this->actingAs($admin, 'admin')->get(route('admin.visitor-origin'))->assertOk()
            ->assertSee('Not enough answers yet: 0 answers so far; at least 5 are needed.');

        $this->visitors(1, 'KR');
        $this->visitors(1, 'PH', 'Cebu');
        $html = $this->actingAs($admin, 'admin')->get(route('admin.visitor-origin'))->assertOk()->getContent();
        $this->assertStringContainsString('Not enough answers yet: 2 answers so far; at least 5 are needed.', $html);
        $this->assertStringContainsString('Not enough answers yet: 1 answer so far; at least 5 are needed.', $html);
        $this->assertStringNotContainsString('South Korea', $html);
        $this->assertSame(4, substr_count($html, 'data-origin-empty'), 'one message per table');
    }

    public function test_the_page_does_not_use_the_one_screen_layout_that_squeezed_empty_cards(): void
    {
        config(['features.trip_location_v2' => true]);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.visitor-origin'))->assertOk()->getContent();

        $this->assertStringContainsString('adm-origin', $html);
        $this->assertStringNotContainsString('adm-fit', $html);
    }

    public function test_apply_and_download_csv_are_styled_buttons_side_by_side(): void
    {
        config(['features.trip_location_v2' => true]);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.visitor-origin'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<button class="btn btn-primary btn-sm" type="submit">Apply</button>\s*<a class="btn btn-outline btn-sm" data-origin-export#', $html);
    }

    public function test_the_page_states_the_counting_rule_and_that_the_counts_are_kept(): void
    {
        config(['features.trip_location_v2' => true]);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.visitor-origin'))->assertOk()->getContent();

        $this->assertStringContainsString('Counting rule:', $html);
        $this->assertStringContainsString('one count per browser session; a changed answer replaces the earlier one', $html);
        $this->assertStringContainsString('counts twice', $html);
        $this->assertStringContainsString('kept permanently and are anonymous', $html);
        $this->assertStringNotContainsString('kept for 30 days only', $html);
    }

    public function test_the_date_filter_reads_the_philippine_day_and_to_includes_the_whole_day(): void
    {
        config(['features.trip_location_v2' => true]);
        $admin = $this->admin();

        $this->visitors(5, 'JP', null, 'chosen', '', '2026-11-12');
        $this->visitors(5, 'KR', null, 'chosen', '', '2026-11-13');
        $this->visitors(5, 'US', null, 'chosen', '', '2026-11-13');
        $this->visitors(5, 'AU', null, 'chosen', '', '2026-11-14');

        $html = $this->actingAs($admin, 'admin')->get(route('admin.visitor-origin', ['from' => '2026-11-13', 'to' => '2026-11-13']))->assertOk()->getContent();

        $this->assertStringContainsString('South Korea', $html);
        $this->assertStringContainsString('United States', $html, 'the whole of the last day is included');
        $this->assertStringNotContainsString('Japan', $html, 'the 12th is before From');
        $this->assertStringNotContainsString('Australia', $html, 'the 14th is after To');
        $this->assertStringContainsString('Philippine time', $html);
    }

    public function test_the_same_group_on_different_days_is_added_together(): void
    {
        config(['features.trip_location_v2' => true]);
        $this->visitors(3, 'KR', null, 'chosen', '', '2026-11-12');
        $this->visitors(3, 'KR', null, 'chosen', '', '2026-11-13');

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.visitor-origin'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#South Korea</td><td>6</td>#', $html, '3 + 3 reaches the minimum of 5 only when added');
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
        preg_match('/Philippine visitors by province.*?By city or municipality/s', $html, $m);

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

    // ---- the CSV ------------------------------------------------------------------------------------------

    public function test_the_csv_holds_the_same_suppressed_totals_and_no_per_day_rows(): void
    {
        config(['features.trip_location_v2' => true]);
        $this->visitors(3, 'KR', null, 'chosen', '', '2026-11-12');
        $this->visitors(3, 'KR', null, 'chosen', '', '2026-11-13');
        $this->visitors(2, 'JP');
        $this->visitors(3, 'TH');

        $admin = $this->admin();
        $response = $this->actingAs($admin, 'admin')->get(route('admin.visitor-origin.export'))->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('"South Korea",6', $csv, 'added over the days, and over the minimum');
        $this->assertStringNotContainsString('Japan', $csv, 'a group under 5 is never in the file');
        $this->assertStringNotContainsString('2026-11-12', $csv, 'no per-day rows');
        $this->assertStringContainsString('Counting rule', $this->actingAs($admin, 'admin')->get(route('admin.visitor-origin'))->getContent());
    }

    public function test_the_csv_follows_the_date_filter_and_says_when_there_is_too_little(): void
    {
        config(['features.trip_location_v2' => true]);
        $this->visitors(6, 'KR', null, 'chosen', '', '2026-11-12');

        $csv = $this->actingAs($this->admin(), 'admin')->get(route('admin.visitor-origin.export', ['from' => '2026-11-13']))->assertOk()->streamedContent();

        $this->assertStringNotContainsString('South Korea', $csv);
        $this->assertStringContainsString('Not enough answers yet: 0 so far; at least 5 are needed.', $csv);
    }

    public function test_a_csv_cell_can_never_start_a_spreadsheet_formula(): void
    {
        $method = new \ReflectionMethod(\App\Http\Controllers\Admin\AdminVisitorOriginController::class, 'safeCell');

        foreach (['=1+1', '+1', '-1', '@x', "\tx"] as $value) {
            $this->assertStringStartsWith("'", $method->invoke(null, $value), $value);
        }
        $this->assertSame('Cebu City (Philippines)', $method->invoke(null, 'Cebu City (Philippines)'));
    }
}
