<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\Region;
use App\Models\Restaurant;
use App\Services\Recommendation\AprioriService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Published itineraries coded into a CSV become Apriori baskets (one per
 * itinerary day), labelled as such and kept out of every respondent statistic.
 */
class ItineraryImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'itinerary_id,source_url,source_type,date_accessed,trip_length,day,place_name_as_written,matched_place,place_type,match_confidence';

    private int $regionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->regionId = Region::create(['name' => 'Davao City'])->id;
    }

    private function destination(string $name, bool $accredited = true): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->regionId,
            'type' => 'Nature', 'is_accredited' => $accredited, 'rating' => 4.5, 'review_count' => 3, 'price_tier' => 'Mid-range',
        ]);
    }

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'itin').'.csv';
        file_put_contents($path, self::HEADER."\n".implode("\n", $rows)."\n");

        return $path;
    }

    private function row(string $id, string $day, string $written, string $matched = '', string $type = 'destination', string $url = ''): string
    {
        return sprintf('%s,%s,blog,2026-10-04,3D2N,%s,"%s","%s",%s,', $id, $url ?: "https://example.test/{$id}", $day, $written, $matched, $type);
    }

    public function test_each_itinerary_day_becomes_one_labelled_basket(): void
    {
        $this->destination('Eden Nature Park');
        $this->destination('Philippine Eagle Center');
        $this->destination('Samal Island');

        $file = $this->csv([
            $this->row('I1', '1', 'Eden Nature Park', 'Eden Nature Park'),
            $this->row('I1', '1', 'Philippine Eagle Center', 'Philippine Eagle Center'),
            $this->row('I1', '2', 'Samal Island', 'Samal Island'),   // alone on day 2: dropped
            $this->row('I1', '2', 'Some Unlisted Beach'),            // unmatched
        ]);

        $this->artisan('itineraries:import', ['files' => [$file]])->assertSuccessful();

        $surveys = ExitSurvey::withoutGlobalScopes()->where('data_source', 'itinerary')->get();
        $this->assertCount(1, $surveys);
        $this->assertSame(2, $surveys->first()->visits()->count());
        $this->assertStringContainsString('src=https://example.test/I1 id=I1 day=1', $surveys->first()->comments);
        $this->assertNull($surveys->first()->overall_rating);
    }

    public function test_a_second_import_of_the_same_file_adds_nothing(): void
    {
        $this->destination('Eden Nature Park');
        $this->destination('Philippine Eagle Center');
        $file = $this->csv([
            $this->row('I1', '1', 'Eden Nature Park', 'Eden Nature Park'),
            $this->row('I1', '1', 'Philippine Eagle Center', 'Philippine Eagle Center'),
        ]);

        $this->artisan('itineraries:import', ['files' => [$file]])->assertSuccessful();
        $this->artisan('itineraries:import', ['files' => [$file]])->assertSuccessful();

        $this->assertSame(1, ExitSurvey::withoutGlobalScopes()->where('data_source', 'itinerary')->count());
    }

    public function test_dry_run_saves_nothing(): void
    {
        $this->destination('Eden Nature Park');
        $this->destination('Philippine Eagle Center');
        $file = $this->csv([
            $this->row('I1', '1', 'Eden Nature Park', 'Eden Nature Park'),
            $this->row('I1', '1', 'Philippine Eagle Center', 'Philippine Eagle Center'),
        ]);

        $this->artisan('itineraries:import', ['files' => [$file], '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, ExitSurvey::withoutGlobalScopes()->where('data_source', 'itinerary')->count());
    }

    public function test_names_are_matched_flexibly_but_ambiguity_is_never_guessed(): void
    {
        $eden = $this->destination('Eden Nature Park');
        $reference = $this->destination('Museo Dabawenyo', accredited: false);
        $shared = $this->destination('Malagos Garden Resort');
        Accommodation::create(['slug' => 'malagos-acc', 'name' => 'Malagos Garden Resort', 'location' => 'Davao City', 'region_id' => $this->regionId, 'is_accredited' => true]);
        $restaurant = Restaurant::create(['slug' => 'jacks-ridge', 'name' => "Jack's Ridge Resort and Restaurant", 'location' => 'Davao City', 'region_id' => $this->regionId, 'is_accredited' => true]);

        $file = $this->csv([
            // exact, an unaccredited reference place, a prefix of a longer name, a shared name resolved by type
            $this->row('I1', '1', 'Eden Nature Park'),
            $this->row('I1', '1', 'museo dabawenyo'),
            $this->row('I1', '1', "Jack\xEF\xBF\xBDs Ridge", '', 'restaurant'),
            $this->row('I1', '1', 'Malagos Garden Resort', '', 'destination'),
        ]);

        $this->artisan('itineraries:import', ['files' => [$file]])->assertSuccessful();

        $items = ExitSurvey::withoutGlobalScopes()->where('data_source', 'itinerary')->firstOrFail()
            ->visits->map(fn ($v) => $v->listing_kind.':'.$v->listing_id)->sort()->values()->all();

        $expected = collect([
            'destination:'.$eden->id, 'destination:'.$reference->id,
            'restaurant:'.$restaurant->id, 'destination:'.$shared->id,
        ])->sort()->values()->all();

        $this->assertSame($expected, $items);
    }

    public function test_infrastructure_rows_and_oversized_days_are_skipped(): void
    {
        foreach (['Eden Nature Park', 'Philippine Eagle Center', 'Samal Island'] as $name) {
            $this->destination($name);
        }

        $file = $this->csv([
            $this->row('I1', '1', 'Davao International Airport', '', 'other'),
            $this->row('I1', '1', 'Davao City', '', 'other'),
            $this->row('I1', '1', 'Eden Nature Park', 'Eden Nature Park'),
            $this->row('I1', '1', 'Philippine Eagle Center', 'Philippine Eagle Center'),
            // a day with three matched places, over a limit of two
            $this->row('I2', '1', 'Eden Nature Park', 'Eden Nature Park'),
            $this->row('I2', '1', 'Philippine Eagle Center', 'Philippine Eagle Center'),
            $this->row('I2', '1', 'Samal Island', 'Samal Island'),
        ]);

        $this->artisan('itineraries:import', ['files' => [$file], '--max-items' => 2])->assertSuccessful();

        $this->assertSame(1, ExitSurvey::withoutGlobalScopes()->where('data_source', 'itinerary')->count());
    }

    public function test_two_itineraries_on_one_page_stay_separate_baskets(): void
    {
        foreach (['Eden Nature Park', 'Philippine Eagle Center', 'Samal Island', "People's Park"] as $name) {
            $this->destination($name);
        }
        $url = 'https://example.test/shared';

        $file = $this->csv([
            $this->row('I1', '1', 'Eden Nature Park', 'Eden Nature Park', url: $url),
            $this->row('I1', '1', 'Philippine Eagle Center', 'Philippine Eagle Center', url: $url),
            $this->row('I2', '1', 'Samal Island', 'Samal Island', url: $url),
            $this->row('I2', '1', "People's Park", "People's Park", url: $url),
        ]);

        $this->artisan('itineraries:import', ['files' => [$file]])->assertSuccessful();

        $this->assertSame(2, ExitSurvey::withoutGlobalScopes()->where('data_source', 'itinerary')->count());
    }

    public function test_a_cut_down_copy_of_a_fuller_plan_on_the_same_page_is_dropped(): void
    {
        foreach (['Eden Nature Park', 'Philippine Eagle Center', 'Samal Island'] as $name) {
            $this->destination($name);
        }
        $url = 'https://example.test/copy';

        $file = $this->csv([
            $this->row('I1', '1', 'Eden Nature Park', 'Eden Nature Park', url: $url),
            $this->row('I1', '1', 'Philippine Eagle Center', 'Philippine Eagle Center', url: $url),
            $this->row('I1', '1', 'Samal Island', 'Samal Island', url: $url),
            $this->row('I2', '1', 'Eden Nature Park', 'Eden Nature Park', url: $url),
            $this->row('I2', '1', 'Philippine Eagle Center', 'Philippine Eagle Center', url: $url),
        ]);

        $this->artisan('itineraries:import', ['files' => [$file]])->assertSuccessful();

        $this->assertSame(1, ExitSurvey::withoutGlobalScopes()->where('data_source', 'itinerary')->count());
    }

    public function test_reset_rebuilds_from_all_the_files_given(): void
    {
        $this->destination('Eden Nature Park');
        $this->destination('Philippine Eagle Center');
        $this->destination('Samal Island');

        $first = $this->csv([
            $this->row('I1', '1', 'Eden Nature Park', 'Eden Nature Park'),
            $this->row('I1', '1', 'Philippine Eagle Center', 'Philippine Eagle Center'),
        ]);
        $second = $this->csv([
            $this->row('I2', '1', 'Philippine Eagle Center', 'Philippine Eagle Center'),
            $this->row('I2', '1', 'Samal Island', 'Samal Island'),
        ]);

        $this->artisan('itineraries:import', ['files' => [$first]])->assertSuccessful();
        $this->artisan('itineraries:import', ['files' => [$first, $second], '--reset' => true])
            ->expectsConfirmation('Delete 1 existing itinerary basket(s) and re-import from the given files?', 'yes')
            ->assertSuccessful();

        $this->assertSame(2, ExitSurvey::withoutGlobalScopes()->where('data_source', 'itinerary')->count());
    }

    public function test_a_shortened_resort_name_still_finds_its_listing(): void
    {
        $this->destination('Eden Nature Park');
        $resort = Accommodation::create(['slug' => 'pearl-farm', 'name' => 'Pearl Farm Beach Resort', 'location' => 'Samal', 'region_id' => $this->regionId, 'is_accredited' => true]);

        $this->artisan('itineraries:import', ['files' => [$this->csv([
            $this->row('I1', '1', 'Eden Nature Park', 'Eden Nature Park'),
            $this->row('I1', '1', 'Pearl Farm Resort', '', 'accommodation'),
        ])]])->assertSuccessful();

        $kinds = ExitSurvey::withoutGlobalScopes()->where('data_source', 'itinerary')->firstOrFail()
            ->visits->map(fn ($v) => $v->listing_kind.':'.$v->listing_id)->all();

        $this->assertContains('accommodation:'.$resort->id, $kinds);
    }

    public function test_a_file_missing_required_columns_fails_clearly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'itin').'.csv';
        file_put_contents($path, "foo,bar\n1,2\n");

        $this->artisan('itineraries:import', ['files' => [$path]])->expectsOutputToContain('Missing column')->assertFailed();
    }

    public function test_apriori_can_mine_itineraries_alone_or_leave_them_out(): void
    {
        $a = $this->destination('Eden Nature Park');
        $b = $this->destination('Philippine Eagle Center');
        $file = $this->csv([
            $this->row('I1', '1', 'Eden Nature Park', 'Eden Nature Park'),
            $this->row('I1', '1', 'Philippine Eagle Center', 'Philippine Eagle Center'),
            $this->row('I2', '1', 'Eden Nature Park', 'Eden Nature Park', url: 'https://example.test/other'),
            $this->row('I2', '1', 'Philippine Eagle Center', 'Philippine Eagle Center', url: 'https://example.test/other'),
        ]);
        $this->artisan('itineraries:import', ['files' => [$file]])->assertSuccessful();

        $apriori = app(AprioriService::class);

        $this->assertCount(2, $apriori->onlySources(['itinerary'])->topRules(10, 2, 0.1));
        $this->assertCount(0, $apriori->onlySources(['real'])->topRules(10, 2, 0.1));
        $this->assertSame(2, $apriori->onlySources(['itinerary'])->getAssociatedListings('destination', $a->id, 5, 0.1, 2)->first()['co_count']);
    }

    public function test_respondent_figures_leave_itinerary_baskets_out_and_the_rules_page_switches_source(): void
    {
        $this->destination('Eden Nature Park');
        $this->destination('Philippine Eagle Center');
        $this->artisan('itineraries:import', ['files' => [$this->csv([
            $this->row('I1', '1', 'Eden Nature Park', 'Eden Nature Park'),
            $this->row('I1', '1', 'Philippine Eagle Center', 'Philippine Eagle Center'),
        ])]])->assertSuccessful();
        ExitSurvey::create(['submitted_at' => now(), 'overall_rating' => 5, 'would_recommend' => 'Yes', 'data_source' => 'real']);

        $admin = AdminUser::create([
            'email' => 'itin-admin@x.test', 'password_hash' => Hash::make('x'),
            'full_name' => 'Admin', 'role' => 'super_admin',
        ]);

        // one respondent, not two
        $insights = $this->actingAs($admin, 'admin')->get(route('admin.exit-surveys'))->assertOk();
        $this->assertSame(1, $insights->viewData('count'));

        $this->get(route('admin.association-rules', ['source' => 'itinerary']))
            ->assertOk()
            ->assertSee('published itineraries, coded by hand')
            ->assertSee('Published itineraries (1)');

        $this->get(route('admin.association-rules', ['source' => 'real']))->assertOk()->assertSee('Tourist surveys (1)');
    }
}
