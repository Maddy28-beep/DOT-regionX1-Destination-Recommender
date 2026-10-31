<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Region;
use App\Support\SampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where a listing's accreditation comes from decides the one word shown beside it:
 *   DOT Accredited  - an accreditation record that is not a placeholder and carries a number;
 *   Sample Data     - every record is a placeholder (a temporary demonstration record);
 *   Not Yet Verified - nothing on file that supports an accreditation (no record, or only records without a number).
 * A missing record is never read as official. None of this decides whether a listing takes part in planning: that is the
 * is_accredited flag, and every state here stays eligible (see SampleParticipationTest).
 */
class ProvenanceStatusTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function dest(string $name, array $records = [], array $over = []): Destination
    {
        $d = Destination::create($over + [
            'slug' => str($name)->slug().'-'.(++$this->n), 'name' => $name, 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Nature & Leisure', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'latitude' => 7.1, 'longitude' => 125.6,
        ]);
        foreach ($records as $r) {
            AccreditationRecord::create($r + ['listing_kind' => 'destination', 'listing_id' => $d->id, 'status' => 'Active', 'issue_date' => '2026-01-01', 'expiration_date' => '2028-01-01', 'is_placeholder' => false]);
        }
        app(SampleData::class)->flush();

        return $d;
    }

    private function official(string $name, array $over = []): Destination
    {
        return $this->dest($name, [['accreditation_number' => 'DOT-R11-TST-'.str_pad((string) (++$this->n), 5, '0', STR_PAD_LEFT).'-2026']], $over);
    }

    private function sample(string $name, array $over = []): Destination
    {
        return $this->dest($name.' [SAMPLE]', [['accreditation_number' => 'SAMPLE-'.str_pad((string) (++$this->n), 4, '0', STR_PAD_LEFT), 'is_placeholder' => true]], $over);
    }

    // ---- the rule ----------------------------------------------------------------------------------------

    public function test_a_real_record_with_a_number_is_official_all_placeholders_are_sample_and_no_record_is_unverified(): void
    {
        $sd = app(SampleData::class);

        $this->assertSame('official', $sd->status($this->official('Real Falls')));
        $this->assertSame('sample', $sd->status($this->sample('Demo Falls')));
        $this->assertSame('unverified', $sd->status($this->dest('Unrecorded Falls')), 'no record: never official');
    }

    public function test_a_record_without_an_accreditation_number_does_not_make_a_listing_official(): void
    {
        $d = $this->dest('Blank Number Falls', [['accreditation_number' => '   ']]);

        $this->assertSame('unverified', app(SampleData::class)->status($d));
    }

    public function test_a_real_record_makes_a_sample_official_at_once_even_with_the_placeholder_still_on_file(): void
    {
        $d = $this->sample('Soon Real');
        $this->assertSame('sample', app(SampleData::class)->status($d));

        AccreditationRecord::create(['listing_kind' => 'destination', 'listing_id' => $d->id, 'accreditation_number' => 'DOT-R11-TST-99999-2026', 'status' => 'Active',
            'issue_date' => '2026-01-01', 'expiration_date' => '2028-01-01', 'is_placeholder' => false]);

        $this->assertSame('official', app(SampleData::class)->status($d->fresh()), 'the record observer clears the cache');
    }

    public function test_counts_split_into_official_sample_and_unverified(): void
    {
        $this->official('A');
        $this->official('B');
        $this->sample('C');
        $this->dest('D');

        $this->assertSame(['total' => 4, 'sample' => 1, 'real' => 2, 'unverified' => 1], app(SampleData::class)->split(Destination::publiclyVisible()));
    }

    // ---- the catalogue cards ----------------------------------------------------------------------------------

    public function test_the_catalogue_card_says_one_of_three_things(): void
    {
        $this->official('Official Park');
        $this->sample('Demo Park');
        $this->dest('Unrecorded Park');

        $html = $this->get('/destinations')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/dcard__dot is-official[^>]*>.*?DOT Accredited/s', $html);
        $this->assertMatchesRegularExpression('/dcard__dot is-sample[^>]*>.*?Sample Data/s', $html);
        $this->assertMatchesRegularExpression('/dcard__dot is-unverified[^>]*>.*?Not Yet Verified/s', $html);
        $this->assertSame(1, substr_count($html, 'DOT Accredited</span>'), 'only the one official record is called accredited');
    }

    public function test_a_listing_with_no_record_is_never_called_dot_accredited_on_its_page_or_card(): void
    {
        $d = $this->dest('Unrecorded Park');

        $page = $this->get(route('destinations.show', $d))->assertOk()->getContent();
        $this->assertStringContainsString('Not Yet Verified', $page);
        $this->assertStringNotContainsString('dpost-badge-accredited is-official', $page);
        $this->assertStringNotContainsString('DOT Accredited', $page);
    }

    public function test_the_destination_page_badge_follows_the_same_rule(): void
    {
        $this->get(route('destinations.show', $this->official('Official Park')))->assertOk()
            ->assertSee('dpost-badge-accredited is-official', false)->assertSee('DOT Accredited');
        $this->get(route('destinations.show', $this->sample('Demo Park')))->assertOk()
            ->assertSee('dpost-badge-accredited is-sample', false)->assertSee('Sample Data');
    }

    public function test_the_stay_page_badge_follows_the_same_rule(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City'])->id;
        $make = function (string $name, ?array $record) use ($region) {
            $a = Accommodation::create(['slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region, 'type' => 'Hotel',
                'is_accredited' => true, 'rating' => 0, 'review_count' => 0]);
            if ($record) {
                AccreditationRecord::create($record + ['listing_kind' => 'accommodation', 'listing_id' => $a->id, 'status' => 'Active', 'issue_date' => '2026-01-01', 'expiration_date' => '2028-01-01']);
            }
            app(SampleData::class)->flush();

            return $a;
        };

        $this->get(route('accommodations.show', $make('Official Inn', ['accreditation_number' => 'DOT-R11-HTL-00001-2026', 'is_placeholder' => false])))->assertOk()->assertSee('DOT Accredited');
        $this->get(route('accommodations.show', $make('Demo Inn', ['accreditation_number' => 'DOT-XI-2025-0009', 'is_placeholder' => true])))->assertOk()->assertSee('Sample Data');
        // (The page also lists the other stays as nearby cards, which carry their own labels; the page's own badge is the hero one.)
        $unrecorded = $this->get(route('accommodations.show', $make('Unrecorded Inn', null)))->assertOk()->assertSee('dpost-badge-accredited is-unverified', false)->assertSee('Not Yet Verified')->getContent();
        $this->assertStringNotContainsString('dpost-badge-accredited is-official', $unrecorded);
    }

    // ---- the home page ----------------------------------------------------------------------------------------

    public function test_the_home_page_never_calls_a_sample_or_unrecorded_feature_dot_verified(): void
    {
        $this->sample('Demo Park', ['featured' => true]);

        $home = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('a DOT-verified must-see', $home);
        $this->assertStringContainsString('A featured sample place', $home);
        $this->assertStringNotContainsString('Verified DOT-accredited', $home);
        $this->assertStringNotContainsString('connects travelers directly with DOT-verified', $home);
        $this->assertStringContainsString('Official DOT records are marked DOT Accredited', $home);
    }

    public function test_the_featured_banner_says_what_each_kind_of_record_is(): void
    {
        $official = $this->official('Official Park', ['featured' => true]);
        $this->get(route('home'))->assertOk()->assertSee('a DOT-accredited must-see');

        $official->update(['featured' => false]);
        $this->dest('Unrecorded Park', [], ['featured' => true]);
        $this->get(route('home'))->assertOk()->assertSee('A featured place whose DOT accreditation is not yet verified.');
    }

    public function test_the_site_description_makes_no_accreditation_claim(): void
    {
        $this->assertStringNotContainsString('DOT-accredited destinations', $this->get(route('home'))->assertOk()->getContent());
    }
}
