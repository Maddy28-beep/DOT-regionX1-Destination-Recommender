<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryAudit;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifiedMealVenueTest extends TestCase
{
    use RefreshDatabase;

    private function listing(string $class, string $slug, array $extra = [])
    {
        return $class::create($extra + ['slug'=>$slug,'name'=>$slug,'region_id'=>Region::firstOrCreate(['name'=>'Davao City'])->id,
            'location'=>'Davao City','latitude'=>7.07,'longitude'=>125.61,'is_accredited'=>false]);
    }

    private function plan(?string $hours='6:00 AM-10:00 PM', array $venueExtra=[]): array
    {
        $hotel=$this->listing(Accommodation::class,'Hotel',['type'=>'Hotel','is_accredited'=>true]);
        $venue=$this->listing(Restaurant::class,'Named dining room',$venueExtra+['opening_hours'=>$hours,
            'host_accommodation_id'=>$hotel->id,'planner_verified_at'=>'2026-10-31','planner_verification_source'=>'https://example.org/operator']);
        $park=$this->listing(Destination::class,'Park',['type'=>'Nature & Leisure','is_accredited'=>true,'hours'=>'8:00 AM-5:00 PM','visit_duration'=>'120 minutes']);
        $p=TouristPreference::create(['travel_days'=>2,'travel_type'=>'Solo','budget'=>'Mid-range','accommodation_pref'=>'Any','distance_pref'=>'near',
            'start_date'=>'2026-11-07','arrival_time'=>'08:00','travel_purpose'=>'Leisure','visitor_type'=>'First-time Visitor','transport_pref'=>'personal_car']);
        $trip=Itinerary::create(['preference_id'=>$p->id,'total_days'=>2,'generated_at'=>now()]);
        app(ItineraryScheduleBuilder::class)->build($trip,[['row'=>['destination'=>$park]]],$p,[1=>['Morning'],2=>['Morning']],
            ['lat'=>7.07,'lng'=>125.61,'label'=>'Start','region'=>'Davao City'],['listing'=>$hotel]);

        return [$trip,$p,$venue,$hotel];
    }

    public function test_verified_host_venues_name_the_actual_breakfast_and_dinner_room(): void
    {
        [$trip,$p,$venue]=$this->plan();
        $breakfast=$trip->items()->where('day_number',2)->where('kind','meal')->where('title','like','Breakfast%')->firstOrFail();
        $dinner=$trip->items()->where('kind','meal')->where('title','like','Dinner%')->firstOrFail();
        $this->assertSame($venue->id,$breakfast->restaurant_id);
        $this->assertSame($venue->id,$dinner->restaurant_id);
        $this->assertSame($venue->id,$dinner->listing()->id);
        $this->assertInstanceOf(Restaurant::class,$dinner->listing());
        $this->assertFalse($venue->fresh()->is_accredited);
    }

    public function test_an_opening_window_must_cover_the_complete_meal(): void
    {
        [$trip,,$venue]=$this->plan('7:00 AM-7:15 AM');
        $breakfast=$trip->items()->where('day_number',2)->where('kind','meal')->where('title','like','Breakfast%')->firstOrFail();
        $this->assertNull($breakfast->restaurant_id);
        $this->assertStringContainsString('hours need confirmation',$breakfast->note);
    }

    public function test_a_closed_host_is_not_selected_even_with_readable_hours(): void
    {
        [$trip]=$this->plan('6:00 AM-10:00 PM',['operating_status'=>'closed']);
        $this->assertFalse($trip->items()->where('kind','meal')->whereNotNull('restaurant_id')->exists());
    }

    public function test_an_identified_host_with_unknown_hours_remains_unknown_in_the_audit(): void
    {
        [$trip,$p,$venue]=$this->plan(null);
        $breakfast=$trip->items()->where('day_number',2)->where('kind','meal')->where('title','like','Breakfast%')->firstOrFail();
        $this->assertSame($venue->id,$breakfast->restaurant_id);
        $this->assertStringContainsString('hours need confirmation',$breakfast->note);
        $checks=collect(app(ItineraryAudit::class)->audit($trip,$p))->keyBy('key');
        $this->assertSame('unknown',$checks['meal_venues']['status']);
    }

    public function test_verified_public_dining_links_show_the_actual_accreditation_status(): void
    {
        [,,$venue]=$this->plan();
        $this->get(route('restaurants.show',$venue))->assertOk()->assertSee('DOT accreditation has not been verified.');
        $unverified=$this->listing(Restaurant::class,'Unchecked dining room');
        $this->get(route('restaurants.show',$unverified))->assertNotFound();
    }

    public function test_merged_dinner_card_keeps_both_restaurant_and_hotel_links(): void
    {
        [$trip,$p,$venue,$hotel]=$this->plan();
        $dinner=$trip->items()->where('kind','meal')->where('title','like','Dinner%')->firstOrFail();
        $stay=$trip->items()->where('kind','overnight')->firstOrFail();
        $html=view('partials.itinerary-timeline-row',['item'=>$dinner,'preference'=>$p,'stay'=>$stay])->render();
        $this->assertStringContainsString(route('restaurants.show',$venue),$html);
        $this->assertStringContainsString(route('accommodations.show',$hotel),$html);
    }

    public function test_off_site_breakfast_accounts_for_both_transfers_and_keeps_the_0800_departure_floor(): void
    {
        $known=$this->listing(Restaurant::class,'Nearby breakfast',['opening_hours'=>'6:00 AM-10:00 PM',
            'latitude'=>7.071,'planner_verified_at'=>'2026-10-31','planner_verification_source'=>'https://example.org/operator']);
        [$trip]=$this->plan(null);
        $breakfast=$trip->items()->where('day_number',2)->where('kind','meal')->where('title','like','Breakfast%')->firstOrFail();
        $this->assertSame($known->id,$breakfast->restaurant_id);
        $this->assertSame('08:15',substr($breakfast->starts_at,0,5));
        $this->assertSame('08:45',substr($breakfast->ends_at,0,5));
        $firstJourney=$trip->items()->where('day_number',2)->where('kind','travel')->orderBy('sort_order')->firstOrFail();
        $this->assertSame('08:00',substr($firstJourney->starts_at,0,5));
    }
}
