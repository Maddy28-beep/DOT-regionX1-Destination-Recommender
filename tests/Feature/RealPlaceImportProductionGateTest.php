<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\ListingPhoto;
use App\Models\Region;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The reviewed place and photograph batches run on a developer's computer without a flag. A live server runs them only with
 * --allow-production, and even then nothing is written without --apply (the dry run shows the plan).
 */
class RealPlaceImportProductionGateTest extends TestCase
{
    use RefreshDatabase;

    private const IMPORTS = [
        'destinations:import-real-local', 'destinations:import-more-real-local', 'destinations:import-third-real-local',
        'destinations:import-fourth-real-local', 'destinations:import-fifth-real-local',
        'restaurants:import-real-local', 'restaurants:import-more-real-local', 'restaurants:import-third-real-local',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        foreach (['Davao City', 'Davao del Norte', 'Davao de Oro', 'Davao del Sur', 'Davao Oriental', 'Davao Occidental'] as $name) {
            Region::firstOrCreate(['name' => $name]);
        }
    }

    public function test_a_live_server_refuses_every_batch_without_the_flag(): void
    {
        $this->app['env'] = 'production';

        foreach (self::IMPORTS as $command) {
            $this->artisan($command, ['--apply' => true])->expectsOutputToContain('--allow-production')->assertFailed();
        }

        $this->assertSame(0, Destination::count() + Restaurant::count() + ListingPhoto::count());
    }

    public function test_with_the_flag_a_live_server_gets_the_plan_and_changes_nothing_until_apply(): void
    {
        $this->app['env'] = 'production';

        foreach (self::IMPORTS as $command) {
            $this->artisan($command, ['--allow-production' => true])->expectsOutputToContain('Dry run: nothing changed.')->assertSuccessful();
        }

        $this->assertSame(0, Destination::count() + Restaurant::count() + ListingPhoto::count());
    }

    public function test_the_enrichment_gets_past_the_gate_with_the_flag_and_stops_on_a_missing_file(): void
    {
        $this->app->instance('env', 'production');

        $this->artisan('destinations:enrich-local', ['file' => 'missing.json'])->expectsOutputToContain('--allow-production')->assertFailed();
        $this->artisan('destinations:enrich-local', ['file' => 'missing.json', '--allow-production' => true])->expectsOutputToContain('Manifest does not exist')->assertFailed();
    }
}
