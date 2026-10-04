<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyVisit;
use App\Models\Region;
use Database\Seeders\DemoExitSurveySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The simulated exit surveys Apriori mines while real ones are collected must
 * be labelled as demo data, be reproducible, and never disturb a real survey.
 */
class DemoExitSurveySeederTest extends TestCase
{
    use RefreshDatabase;

    private function destinations(): void
    {
        foreach (['Davao City' => ['Eden Nature Park', 'Philippine Eagle Center', 'Malagos Garden Resort'], 'Davao Oriental' => ['Dahican Beach']] as $region => $names) {
            $regionId = Region::firstOrCreate(['name' => $region])->id;
            foreach ($names as $name) {
                Destination::create([
                    'slug' => str($name)->slug(), 'name' => $name, 'location' => $region,
                    'region_id' => $regionId, 'type' => 'Nature', 'is_accredited' => true,
                    'rating' => 4.5, 'review_count' => 3, 'price_tier' => 'Mid-range',
                ]);
            }
        }
    }

    private function realSurvey(): ExitSurvey
    {
        return ExitSurvey::create([
            'submitted_at' => now(), 'residency_type' => 'Domestic Tourist', 'visitor_type' => 'First-time Visitor',
            'origin' => 'Cebu City', 'travel_purpose' => 'Leisure', 'actual_days_stayed' => 2,
            'overall_rating' => 5, 'destination_relevant' => 5, 'itinerary_useful' => 5,
            'attractions_quality' => 5, 'accommodation_rating' => 5, 'transport_rating' => 5,
            'would_recommend' => 'Yes',
        ]);
    }

    public function test_every_generated_survey_is_labelled_demo(): void
    {
        $this->destinations();
        $this->seed(DemoExitSurveySeeder::class);

        $this->assertSame(300, ExitSurvey::where('data_source', 'demo')->count());
        $this->assertSame(0, ExitSurvey::where('data_source', 'real')->count());
        $this->assertGreaterThan(0, ExitSurveyVisit::count());
    }

    public function test_rerunning_replaces_demo_rows_and_never_touches_real_ones(): void
    {
        $this->destinations();
        $real = $this->realSurvey();

        $this->seed(DemoExitSurveySeeder::class);
        $firstVisits = ExitSurveyVisit::count();

        $this->seed(DemoExitSurveySeeder::class);

        $this->assertSame(300, ExitSurvey::where('data_source', 'demo')->count());
        $this->assertTrue(ExitSurvey::whereKey($real->id)->where('data_source', 'real')->exists());
        // fixed seed: same demo data every run
        $this->assertSame($firstVisits, ExitSurveyVisit::count());
    }

    public function test_the_association_rules_page_says_when_transactions_are_demo_data(): void
    {
        $this->destinations();
        $this->seed(DemoExitSurveySeeder::class);
        $admin = AdminUser::create([
            'email' => 'demo-admin@x.test', 'password_hash' => Hash::make('x'),
            'full_name' => 'Admin', 'role' => 'super_admin',
        ]);

        $this->actingAs($admin, 'admin')->get(route('admin.association-rules'))
            ->assertOk()
            ->assertSee('These rules mix three kinds of transaction')
            ->assertSee('simulated demo');
    }

    public function test_no_banner_when_every_transaction_is_real(): void
    {
        $this->destinations();
        $this->realSurvey();
        $admin = AdminUser::create([
            'email' => 'demo-admin@x.test', 'password_hash' => Hash::make('x'),
            'full_name' => 'Admin', 'role' => 'super_admin',
        ]);

        $this->actingAs($admin, 'admin')->get(route('admin.association-rules'))
            ->assertOk()
            ->assertDontSee('These rules mix three kinds of transaction');
    }
}
