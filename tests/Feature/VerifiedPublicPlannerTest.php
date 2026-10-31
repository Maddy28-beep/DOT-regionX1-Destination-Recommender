<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ContentBasedRecommendationService;
use App\Services\Recommendation\ItineraryAudit;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifiedPublicPlannerTest extends TestCase
{
    use RefreshDatabase;

    private function preference(int $days): TouristPreference
    {
        return TouristPreference::create(['travel_days'=>$days,'travel_type'=>'Solo','budget'=>'Mid-range',
            'accommodation_pref'=>'Any','distance_pref'=>'near','transport_pref'=>'personal_car',
            'start_date'=>'2026-11-07','travel_purpose'=>'Leisure','visitor_type'=>'First-time Visitor',
            'origin_lat'=>7.07,'origin_lng'=>125.61]);
    }

    private function place(int $n, array $extra=[]): Destination
    {
        return Destination::create($extra+['slug'=>'visitor-'.$n,'name'=>'Visitor site '.$n,'location'=>'Davao City',
            'region_id'=>Region::firstOrCreate(['name'=>'Davao City'])->id,'type'=>'Cultural Heritage',
            'latitude'=>7.07+$n*0.001,'longitude'=>125.61,'is_accredited'=>false,
            'planner_verified_at'=>'2026-10-31','planner_verification_source'=>'https://example.org/operator',
            'hours'=>'8:00 AM-5:00 PM','visit_duration'=>'60 minutes']);
    }

    public function test_only_explicitly_verified_public_places_are_eligible_and_accreditation_is_unchanged(): void
    {
        $verified=$this->place(1);
        $this->place(2,['planner_verified_at'=>null]);
        $this->place(3,['planner_verification_source'=>null]);
        $closed=$this->place(4,['operating_status'=>'closed']);
        $ranked=app(ContentBasedRecommendationService::class)->rank($this->preference(1))->pluck('destination.id')->all();
        $this->assertSame([$verified->id],$ranked);
        $this->assertFalse($verified->fresh()->is_accredited);
        $this->assertNotContains($closed->id,$ranked);
        $this->get(route('destinations.show',$verified))->assertOk()->assertSee('DOT accreditation has not been verified.');
        $unverified=Destination::where('slug','visitor-2')->firstOrFail();
        $this->get(route('destinations.show',$unverified))->assertNotFound();
    }

    public function test_five_distinct_visits_cover_five_days_before_second_slots_are_filled(): void
    {
        foreach(range(1,5) as $n)$this->place($n);
        $p=$this->preference(5);
        $trip=app(ItineraryGenerationService::class)->generate($p);
        $visits=$trip->items->where('kind','activity')->whereNotNull('destination_id');
        $this->assertSame([1,2,3,4,5],$visits->pluck('day_number')->unique()->sort()->values()->all());
        $this->assertCount(5,$visits->pluck('destination_id')->unique());
        $checks=collect(app(ItineraryAudit::class)->audit($trip,$p))->keyBy('key');
        $this->assertSame('pass',$checks['stops_per_day']['status']);
    }

    public function test_a_verified_public_site_can_cover_an_interest_in_the_audit(): void
    {
        $this->place(1);
        $p=$this->preference(1);
        $p->activities()->create(['activity'=>'Cultural Heritage']);
        $trip=app(ItineraryGenerationService::class)->generate($p);
        $checks=collect(app(ItineraryAudit::class)->audit($trip,$p))->keyBy('key');
        $this->assertSame('pass',$checks['interests']['status']);
    }

    public function test_an_unmapped_branch_does_not_hide_a_mapped_branch_of_the_same_spa(): void
    {
        $this->place(1,['name'=>'Shared Spa','type'=>'Wellness & Spa','is_accredited'=>true,'latitude'=>null,'longitude'=>null]);
        $mapped=$this->place(2,['name'=>'Shared Spa','type'=>'Wellness & Spa','is_accredited'=>true]);
        $p=$this->preference(1);
        $p->activities()->create(['activity'=>'Relaxation & Wellness']);
        $trip=app(ItineraryGenerationService::class)->generate($p);
        $this->assertTrue($trip->items->where('kind','activity')->contains('destination_id',$mapped->id));
    }

    public function test_an_exhausted_catalogue_is_reported_without_repeating_a_place_to_fill_days(): void
    {
        $this->place(1);
        $p=$this->preference(3);
        $trip=app(ItineraryGenerationService::class)->generate($p);
        $this->assertCount(1,$trip->items->where('kind','activity')->whereNotNull('destination_id'));
        $checks=collect(app(ItineraryAudit::class)->audit($trip,$p))->keyBy('key');
        $this->assertSame('fail',$checks['stops_per_day']['status']);
    }

    public function test_an_unfillable_available_interest_records_omission_by_default_and_declines_when_configured(): void
    {
        $this->place(1,['type'=>'Wildlife']);
        $this->place(2,['type'=>'Beach & Leisure']);
        $p=$this->preference(1);
        $p->update(['arrival_time'=>'13:00']);
        foreach(['Wildlife','Beach & Island'] as $interest)$p->activities()->create(['activity'=>$interest]);

        // Default: builds the 1-stop afternoon plan and audit reports the uncovered interest.
        $trip = app(ItineraryGenerationService::class)->generate($p);
        $this->assertCount(1, $trip->items->where('kind', 'activity')->whereNotNull('destination_id'));
        $this->assertSame('fail', collect(app(ItineraryAudit::class)->audit($trip, $p))->keyBy('key')['interests']['status']);
        $trip->items()->delete();
        $trip->matches()->delete();
        $trip->delete();

        // Deliberate rejection mode: declines and rolls back.
        config(['itinerary.planner.reject_incomplete_trips' => true]);
        try {
            app(ItineraryGenerationService::class)->generate($p);
            $this->fail('One remaining slot cannot cover two distinct interests when reject_incomplete_trips is enabled.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('activities',$e->errors());
            $this->assertDatabaseCount('itineraries',0);
            $this->assertDatabaseCount('itinerary_items',0);
        }
    }

    public function test_mapped_default_origin_uses_the_same_radius_as_the_audit_despite_an_old_distance_field(): void
    {
        $this->place(1,['distance_km'=>999]);
        $this->place(2,['distance_km'=>999]);
        $p=$this->preference(1);
        $p->update(['origin_lat'=>null,'origin_lng'=>null]);
        $ranker=app(ContentBasedRecommendationService::class);
        $this->assertCount(2,$ranker->rank($p));
        $this->assertSame('near',$ranker->lastRangeTierUsed);
    }

    public function test_a_mapped_branch_with_readable_hours_is_preferred_over_an_unconfirmed_branch(): void
    {
        $this->place(1,['name'=>'Same museum','hours'=>null]);
        $known=$this->place(2,['name'=>'Same museum']);
        $trip=app(ItineraryGenerationService::class)->generate($this->preference(1));
        $this->assertSame([$known->id],$trip->items->where('kind','activity')->whereNotNull('destination_id')->pluck('destination_id')->all());
    }
}
