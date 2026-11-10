<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Region;
use App\Models\TouristVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Post-trial prep (g): rows pointing at a deleted listing are counted, and removed only on request. */
class ListingOrphansCommandTest extends TestCase
{
    use RefreshDatabase;

    private function stay(): Accommodation
    {
        return Accommodation::create(['slug' => 's', 'name' => 'A Stay', 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Hotel']);
    }

    private function visit(string $kind, int $id): void
    {
        DB::table('tourist_visits')->insert([
            'visitor_token' => str_repeat('a', 36), 'listing_kind' => $kind, 'listing_id' => $id,
            'visit_date' => now()->toDateString(), 'source' => 'qr_scan', 'created_at' => now(),
        ]);
    }

    public function test_it_reports_orphans_and_removes_nothing_by_default(): void
    {
        $live = $this->stay();
        $this->visit('accommodation', $live->id);
        $this->visit('accommodation', 99999);
        $this->visit('unknown_kind', 1);

        $this->artisan('listings:orphans')->expectsOutputToContain('2 found')->assertSuccessful();
        $this->assertSame(3, TouristVisit::withoutGlobalScopes()->count());
    }

    public function test_delete_removes_only_the_orphans(): void
    {
        $live = $this->stay();
        $this->visit('accommodation', $live->id);
        $this->visit('accommodation', 99999);

        $this->artisan('listings:orphans --delete')->expectsOutputToContain('1 removed')->assertSuccessful();
        $this->assertSame(1, TouristVisit::withoutGlobalScopes()->count());
        $this->assertSame($live->id, (int) TouristVisit::withoutGlobalScopes()->value('listing_id'));
    }
}
