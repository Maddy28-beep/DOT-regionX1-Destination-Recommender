<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The admin Overview draws the check-in trend and the top places for 30 days, 90 days and all time. */
class AdminOverviewChartsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::firstOrCreate(['email' => 'overview@x.test'], [
            'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin',
        ]);
    }

    private function place(string $name): Destination
    {
        static $n = 0;
        $region = Region::first() ?? Region::create(['name' => 'Davao City']);

        return Destination::create([
            'slug' => 'ov-'.(++$n), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region->id,
            'type' => 'Nature', 'is_accredited' => true, 'rating' => 4.0, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ]);
    }

    private function visit(Destination $d, int $daysAgo, string $token): void
    {
        TouristVisit::create([
            'visitor_token' => $token, 'listing_kind' => 'destination', 'listing_id' => $d->id,
            'visit_date' => now('Asia/Manila')->subDays($daysAgo)->toDateString(), 'source' => 'qr_scan',
        ]);
    }

    private function chartData(string $html): array
    {
        $this->assertSame(1, preg_match('/<script type="application\/json" id="overview-chart-data">(.*?)<\/script>/s', $html, $m));

        return json_decode(html_entity_decode($m[1]), true);
    }

    public function test_the_overview_embeds_the_three_periods_and_loads_the_charts_locally(): void
    {
        $eagle = $this->place('Philippine Eagle Center [SAMPLE]');
        $eden = $this->place('Eden Nature Park');
        foreach (['a', 'b', 'c'] as $i => $t) {
            $this->visit($eagle, $i, $t);
        }
        $this->visit($eden, 5, 'd');
        $this->visit($eden, 60, 'e');
        $this->visit($eden, 200, 'f');

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.overview'))->assertOk()
            ->assertSee('id="checkin-line"', false)->assertSee('vendor/chartjs/chart.umd.js', false)
            ->assertSee('js/admin-overview-charts.js', false)->getContent();

        $data = $this->chartData($html);
        $this->assertSame(['30', '90', 'all'], array_map('strval', array_keys($data)));
        $this->assertSame(30, count($data['30']['labels']));
        $this->assertSame(90, count($data['90']['labels']));
        $this->assertSame(4, $data['30']['total'], 'the 60 and 200 day old visits fall outside 30 days');
        $this->assertSame(5, $data['90']['total']);
        $this->assertSame(6, $data['all']['total']);
        $this->assertSame(array_sum($data['all']['values']), $data['all']['total'], 'empty days are filled with 0, nothing is lost');
        $this->assertSame('Philippine Eagle Center', $data['30']['top'][0]['name'], 'the sample suffix is not shown');
        $this->assertSame(3, $data['30']['top'][0]['count']);
    }

    public function test_no_visits_still_renders_with_empty_periods(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.overview'))->assertOk()->getContent();
        $data = $this->chartData($html);

        $this->assertSame(0, $data['90']['total']);
        $this->assertSame([], $data['90']['top']);
        $this->assertNull($data['90']['peakDay']);
    }

    public function test_the_check_in_tile_compares_with_yesterday(): void
    {
        $d = $this->place('Eden Nature Park');
        $this->visit($d, 0, 'a');
        $this->visit($d, 0, 'b');
        $this->visit($d, 1, 'c');

        $this->actingAs($this->admin(), 'admin')->get(route('admin.overview'))->assertOk()->assertSee('1 more than yesterday');
    }
}
