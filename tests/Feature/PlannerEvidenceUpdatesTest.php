<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Models\Restaurant;
use App\Support\OpeningHours;
use Database\Seeders\PlannerEvidenceUpdatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlannerEvidenceUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private function place(string $slug, string $name, ?string $hours = null): Destination
    {
        return Destination::create(['slug' => $slug, 'name' => $name, 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Nature & Leisure', 'latitude' => 7.1, 'longitude' => 125.5,
            'hours' => $hours, 'is_accredited' => true]);
    }

    public function test_evidence_updates_are_repeatable_and_resolve_hosts_without_local_numeric_ids(): void
    {
        $host = $this->place('malagos-garden-resort', 'Malagos Garden Resort');
        $eden = $this->place('eden-nature-park', 'Eden Nature Park');
        $paradise = $this->place('paradise-island-park-beach-resort', 'Paradise Island Park & Beach Resort');
        $this->place('imin-the-philippine-japan-historical-museum', 'IMIN The Philippine-Japan Historical Museum');
        $this->seed(PlannerEvidenceUpdatesSeeder::class);
        $this->seed(PlannerEvidenceUpdatesSeeder::class);

        $this->assertSame(3, Restaurant::count());
        $this->assertTrue(\App\Services\Recommendation\InterestCoverage::covers($eden->fresh('tags'), 'Wildlife'));
        $this->assertTrue(\App\Services\Recommendation\InterestCoverage::covers($paradise->fresh('tags'), 'Wildlife'));
        $this->assertSame(1, $paradise->tags()->where('value', 'Wildlife (aviary)')->count(), 'Source-backed categories are not duplicated.');
        $koi = Restaurant::where('slug', 'koi-cafe-malagos')->sole();
        $this->assertSame($host->id, $koi->host_destination_id);
        $this->assertFalse($koi->is_accredited);
        $this->assertNotNull($koi->planner_verified_at);
        $this->assertStringContainsString('malagos.com/dine', $koi->planner_verification_source);
        $this->assertSame([[360, 600], [1020, 1260]], OpeningHours::windows($koi->opening_hours));
        $this->assertSame([[480, 1020]], OpeningHours::windows(Restaurant::where('slug', 'viewdeck-cafe-malagos')->sole()->opening_hours));
        $this->assertSame([[0, 1440]], OpeningHours::windows(Restaurant::where('slug', 'kusina-dabaw-san-pedro')->sole()->opening_hours));
        $this->assertNull(\App\Support\VisitDuration::minutes($host->fresh()->visit_duration), 'A named short programme must not verify the whole park visit.');
        $museum = Destination::where('slug', 'imin-the-philippine-japan-historical-museum')->sole();
        $this->assertNull(OpeningHours::windowsOn($museum->hours, Carbon::parse('2026-11-08')), 'Sunday booking is unconfirmed.');
    }

    public function test_reseeding_preserves_subsequent_verified_hours(): void
    {
        $farm = $this->place('belviz-farm', 'Belviz Farm', '10:00 AM-3:00 PM');
        $this->seed(PlannerEvidenceUpdatesSeeder::class);
        $this->assertSame('10:00 AM-3:00 PM', $farm->fresh()->hours);

        $meal = Restaurant::where('slug', 'kusina-dabaw-san-pedro')->sole();
        $meal->update(['opening_hours' => '7:00 AM-8:00 PM']);
        $this->seed(PlannerEvidenceUpdatesSeeder::class);
        $this->assertSame('7:00 AM-8:00 PM', $meal->fresh()->opening_hours);
    }
}
