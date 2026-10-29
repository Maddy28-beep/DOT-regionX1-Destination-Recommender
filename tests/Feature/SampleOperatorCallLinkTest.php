<?php

namespace Tests\Feature;

use App\Models\AccreditationRecord;
use App\Models\Region;
use App\Models\TourOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A sample operator's phone number is not verified: it may belong to a real business. It can be read, but the page
 * must not offer a one-tap call.
 */
class SampleOperatorCallLinkTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function operator(string $name, bool $sample): TourOperator
    {
        $o = TourOperator::create([
            'slug' => Str::slug($name), 'name' => $name, 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'contact_number' => '(082) 282 8336', 'latitude' => 7.07, 'longitude' => 125.61,
        ]);
        AccreditationRecord::create([
            'listing_kind' => 'tour_operator', 'listing_id' => $o->id, 'accreditation_number' => ($sample ? 'DOT-XI-' : 'DOT-R11-').(++$this->n),
            'status' => 'Active', 'issue_date' => now()->subYear(), 'expiration_date' => now()->addYear(), 'is_placeholder' => $sample,
        ]);

        return $o;
    }

    public function test_a_sample_operators_page_shows_the_number_but_no_call_link(): void
    {
        $html = $this->get(route('tour-operators.show', $this->operator('Sample Tours', true)))->assertOk()->getContent();

        $this->assertStringContainsString('(082) 282 8336', $html);
        $this->assertStringNotContainsString('href="tel:', $html);
    }

    public function test_a_real_operators_page_keeps_its_call_link(): void
    {
        $html = $this->get(route('tour-operators.show', $this->operator('Real Tours', false)))->assertOk()->getContent();

        $this->assertStringContainsString('href="tel:0822828336"', $html);
    }

    public function test_the_pickup_card_does_not_dial_a_sample_operator_but_dials_a_real_one(): void
    {
        $card = fn (array $ops) => view('partials.itinerary-planned-around', [
            'preference' => null,
            'operators' => collect($ops)->map(fn ($o) => ['operator' => $o, 'km' => 2.0]),
            'fmtKm' => fn ($km) => number_format($km, 1),
        ])->render();

        $sample = $card([$this->operator('Sample Tours', true)]);
        $this->assertStringContainsString('(082) 282 8336', $sample);
        $this->assertStringNotContainsString('href="tel:', $sample);

        $this->assertStringContainsString('href="tel:0822828336"', $card([$this->operator('Real Tours', false)]));
    }
}
