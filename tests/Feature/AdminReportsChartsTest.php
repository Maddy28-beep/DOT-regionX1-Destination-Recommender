<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyVisit;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Models\TouristVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Objective 1.3.2.5: the admin reports page draws charts (Bootstrap layout + Chart.js) from the same
 * date-filtered data as the table, and everything is served locally.
 */
class AdminReportsChartsTest extends TestCase
{
    use RefreshDatabase;

    private const VISITS = 'Verified Visits (QR Check-ins)';

    private function admin(): AdminUser
    {
        return AdminUser::firstOrCreate(['email' => 'charts@x.test'], [
            'password_hash' => Hash::make('x'),
            'full_name' => 'Admin', 'role' => 'super_admin',
        ]);
    }

    private function destination(string $name): Destination
    {
        static $n = 0;
        $region = Region::first() ?? Region::create(['name' => 'Davao City']);

        return Destination::create([
            'slug' => 'chart-'.(++$n), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region->id,
            'type' => 'Nature', 'is_accredited' => true, 'rating' => 4.0, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ]);
    }

    private function visit(Destination $d, string $date, string $token): void
    {
        TouristVisit::create([
            'visitor_token' => $token, 'listing_kind' => 'destination', 'listing_id' => $d->id,
            'visit_date' => $date, 'source' => 'qr_scan',
        ]);
    }

    private function url(array $q): string
    {
        return '/portal/admin/reports?'.http_build_query($q);
    }

    /** The chart JSON the page embeds, decoded. */
    private function chartData(string $html): ?array
    {
        if (! preg_match('#<script type="application/json" id="report-chart-data">(.*?)</script>#s', $html, $m)) {
            return null;
        }

        return json_decode($m[1], true);
    }

    public function test_chart_data_contains_only_rows_inside_the_date_range(): void
    {
        $in = $this->destination('Inside Falls');
        $out = $this->destination('Outside Falls');
        $this->visit($in, '2026-10-02', 'a');
        $this->visit($in, '2026-10-02', 'b');
        $this->visit($in, '2026-10-04', 'a');
        $this->visit($out, '2026-09-15', 'c'); // before the range
        $this->visit($out, '2026-10-20', 'd'); // after the range

        $html = $this->actingAs($this->admin(), 'admin')
            ->get($this->url(['report_type' => self::VISITS, 'from' => '2026-10-01', 'to' => '2026-10-05']))
            ->assertOk()->getContent();

        $data = $this->chartData($html);
        $this->assertNotNull($data);
        $this->assertTrue($data['hasData']);

        // One bucket per day in the range, zero days included, and nothing from outside it.
        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05'], $data['timeline']['labels']);
        $this->assertSame([0, 2, 0, 1, 0], $data['timeline']['values']);
        $this->assertSame(['Inside Falls'], $data['top']['labels']);
        $this->assertSame([2], $data['top']['values']);
        $this->assertStringNotContainsString('Outside Falls', json_encode($data));
        $this->assertSame(['Destination'], $data['breakdown']['labels']);
        $this->assertSame([2], $data['breakdown']['values']);

        // Chart libraries are the local copies, and the table is still rendered.
        $this->assertStringContainsString('/vendor/chartjs/chart.umd.js', $html);
        $this->assertStringContainsString('/vendor/bootstrap/bootstrap.bundle.min.js', $html);
        $this->assertStringContainsString('Inside Falls', $html);
        $this->assertStringContainsString('data-bs-toggle="tab"', $html);
    }

    public function test_empty_range_shows_an_honest_empty_state_and_loads_no_chart_scripts(): void
    {
        $this->visit($this->destination('Far Away'), '2026-01-10', 'a');

        $html = $this->actingAs($this->admin(), 'admin')
            ->get($this->url(['report_type' => self::VISITS, 'from' => '2026-10-01', 'to' => '2026-10-05']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('No data to chart for this report type in the selected date range.', $html);
        $this->assertNull($this->chartData($html));
        $this->assertStringNotContainsString('chart.umd.js', $html);
        $this->assertStringNotContainsString('<canvas', $html);
        $this->assertStringContainsString('No records found for this report type in the selected date range.', $html);
    }

    public function test_filters_persist_and_the_page_has_no_remote_assets(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')
            ->get($this->url(['report_type' => 'Exit Survey Responses', 'from' => '2026-10-01', 'to' => '2026-10-05', 'include_simulated' => 1]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('name="from" value="2026-10-01"', $html);
        $this->assertStringContainsString('name="to" value="2026-10-05"', $html);
        $this->assertMatchesRegularExpression('/<option value="Exit Survey Responses"\s+selected/', $html);
        $this->assertMatchesRegularExpression('/name="include_simulated" value="1"\s+checked/', $html);
        $this->assertStringContainsString('include_simulated=1', $html); // export links keep the switch

        // Privacy: nothing for this feature comes from a third-party host.
        $this->assertDoesNotMatchRegularExpression('#(src|href)="https?://(?!localhost|127\.0\.0\.1)[^"]*(jsdelivr|cdnjs|unpkg|bootstrapcdn)#i', $html);
    }

    public function test_exit_survey_charts_honour_range_and_the_simulated_switch(): void
    {
        $make = fn (string $when, string $source, int $rating) => ExitSurvey::create([
            'submitted_at' => $when, 'data_source' => $source, 'overall_rating' => $rating,
        ]);
        $make('2026-10-02 04:00:00', 'real', 5);
        $make('2026-10-02 05:00:00', 'real', 4);
        $make('2026-10-03 04:00:00', 'demo', 1);   // simulated
        $make('2026-09-01 04:00:00', 'real', 2);   // out of range
        $base = ['report_type' => 'Exit Survey Responses', 'from' => '2026-10-01', 'to' => '2026-10-05'];

        $real = $this->chartData($this->actingAs($this->admin(), 'admin')->get($this->url($base))->getContent());
        $this->assertSame([0, 2, 0, 0, 0], $real['timeline']['values']);
        $this->assertSame([0, 0, 0, 1, 1], $real['breakdown']['values']); // ratings 1..5

        $withSim = $this->chartData($this->actingAs($this->admin(), 'admin')->get($this->url($base + ['include_simulated' => 1]))->getContent());
        $this->assertSame([0, 2, 1, 0, 0], $withSim['timeline']['values']);
        $this->assertSame([1, 0, 0, 1, 1], $withSim['breakdown']['values']);
    }

    public function test_destination_visits_and_trip_plans_charts_use_the_same_range(): void
    {
        $d = $this->destination('Surveyed Park');
        $inSurvey = ExitSurvey::create(['submitted_at' => '2026-10-02 04:00:00', 'data_source' => 'real']);
        $outSurvey = ExitSurvey::create(['submitted_at' => '2026-08-02 04:00:00', 'data_source' => 'real']);
        foreach ([$inSurvey, $outSurvey] as $s) {
            ExitSurveyVisit::create(['exit_survey_id' => $s->id, 'listing_kind' => 'destination', 'listing_id' => $d->id]);
        }

        $dv = $this->chartData($this->actingAs($this->admin(), 'admin')->get($this->url([
            'report_type' => 'Destination Visits', 'from' => '2026-10-01', 'to' => '2026-10-05',
        ]))->getContent());
        $this->assertSame([0, 1, 0, 0, 0], $dv['timeline']['values']);
        $this->assertSame([1], $dv['top']['values']);

        $plan = TouristPreference::create(['travel_days' => 2, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Hotel', 'distance_pref' => 'near']);
        $plan->forceFill(['created_at' => '2026-10-03 03:00:00'])->save();
        $old = TouristPreference::create(['travel_days' => 3, 'travel_type' => 'Solo', 'budget' => 'Mid-range', 'accommodation_pref' => 'Hotel', 'distance_pref' => 'near']);
        $old->forceFill(['created_at' => '2026-07-03 03:00:00'])->save();

        $tp = $this->chartData($this->actingAs($this->admin(), 'admin')->get($this->url([
            'report_type' => 'Trip Plans Created', 'from' => '2026-10-01', 'to' => '2026-10-05',
        ]))->getContent());
        $this->assertSame([0, 0, 1, 0, 0], $tp['timeline']['values']);
        $this->assertSame(['Couple'], $tp['breakdown']['labels']);
    }

    public function test_long_ranges_group_by_month_and_every_report_type_renders(): void
    {
        $this->visit($this->destination('Year Park'), '2026-03-10', 'a');
        $admin = $this->admin();

        $data = $this->chartData($this->actingAs($admin, 'admin')->get($this->url([
            'report_type' => self::VISITS, 'from' => '2026-01-01', 'to' => '2026-12-31',
        ]))->getContent());
        $this->assertCount(12, $data['timeline']['labels']);
        $this->assertSame(1, $data['timeline']['values'][2]); // March
        $this->assertSame('Month', $data['timeline']['axis']);

        foreach (['Verified Visits (QR Check-ins)', 'Exit Survey Responses', 'Accreditation Status', 'Destination Visits', 'Trip Plans Created'] as $type) {
            $this->actingAs($admin, 'admin')->get($this->url(['report_type' => $type, 'from' => '2026-01-01', 'to' => '2026-12-31']))->assertOk();
        }
    }

    public function test_csv_export_is_unchanged_by_the_charts(): void
    {
        $d = $this->destination('CSV Falls');
        $this->visit($d, '2026-10-02', 'a');
        $this->visit($d, '2026-10-02', 'b');
        $this->visit($this->destination('Not In Range'), '2026-09-02', 'c');

        $response = $this->actingAs($this->admin(), 'admin')
            ->get('/portal/admin/reports/export.csv?'.http_build_query(['report_type' => self::VISITS, 'from' => '2026-10-01', 'to' => '2026-10-05']));
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertSame("Place,Type,\"Unique Visitors\"\n\"CSV Falls\",Destination,2\n", $csv);
        $this->assertStringNotContainsString('chart', strtolower($csv));
    }

    public function test_print_view_still_renders(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/portal/admin/reports/print?'.http_build_query(['report_type' => self::VISITS, 'from' => '2026-10-01', 'to' => '2026-10-05']))
            ->assertOk()->assertDontSee('report-chart-data', false);
    }
}
