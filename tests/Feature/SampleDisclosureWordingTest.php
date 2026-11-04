<?php

namespace Tests\Feature;

use App\Models\AccreditationRecord;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Sample data must never be counted or described as DOT-accredited. */
class SampleDisclosureWordingTest extends TestCase
{
    use RefreshDatabase;

    private function landmark(string $slug): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        Destination::create([
            'slug' => $slug, 'name' => $slug, 'location' => 'Davao City', 'region_id' => $region->id,
            'type' => 'Nature', 'is_accredited' => false, 'rating' => 4.0, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ]);
    }

    private function destination(string $slug, bool $sample, int $n): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $d = Destination::create([
            'slug' => $slug, 'name' => $slug, 'location' => 'Davao City', 'region_id' => $region->id,
            'type' => 'Nature', 'is_accredited' => true, 'rating' => 4.0, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ]);
        AccreditationRecord::create([
            'listing_kind' => 'destination', 'listing_id' => $d->id,
            'accreditation_number' => ($sample ? 'SAMPLE-' : 'DOT-').$n, 'status' => 'Active',
            'issue_date' => now()->subMonths(3), 'expiration_date' => now()->addYear(), 'is_placeholder' => $sample,
        ]);
    }

    public function test_admin_overview_counts_official_and_sample_destinations_separately(): void
    {
        $this->destination('official-a', false, 1);
        $this->destination('official-b', false, 2);
        $this->destination('sample-a', true, 1);
        $this->destination('sample-b', true, 2);
        $this->destination('sample-c', true, 3);
        $this->landmark('public-landmark');

        $admin = AdminUser::create(['email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);
        $html = $this->actingAs($admin, 'admin')->get(route('admin.overview'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/adm-kpi__val">2<\/div><div class="adm-kpi__lab">Accredited Destinations</', $html);
        $this->assertMatchesRegularExpression('/adm-kpi__val">3<\/div><div class="adm-kpi__lab">Sample Destinations</', $html);

        // The two tiles are not the total: the unflagged landmark is reported, and the line adds up to the total.
        $text = preg_replace('/\s+/', ' ', strip_tags($html));
        $this->assertStringContainsString('2 DOT-accredited (official record) + 3 sample data + 1 not counted in either', $text);
        $this->assertStringContainsString('= 6 in total', $text);

        // The records themselves are untouched: five flagged, one not, and the counting changed nothing.
        $this->assertSame(5, Destination::where('is_accredited', true)->count());
        $this->assertSame(6, Destination::count());
    }

    public function test_start_page_and_terms_use_the_approved_wording(): void
    {
        $start = $this->get(route('plan.choose'))->assertOk()->getContent();
        $this->assertStringContainsString('DOT-accredited places are marked; sample data is labelled', $start);
        $this->assertStringNotContainsString('Only DOT-accredited places', $start);

        $terms = preg_replace('/\s+/', ' ', $this->get(route('legal.terms'))->assertOk()->getContent());
        $this->assertStringContainsString('discover destinations featuring DOT-accredited establishments and clearly labelled sample data', $terms);
    }
}
