<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationEmbedding;
use App\Models\InterestEmbedding;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Embeddings\DestinationEmbeddingService;
use App\Services\Embeddings\InterestEmbeddingService;
use App\Services\Recommendation\ContentBasedRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A traveller's picked interests are matched to places by meaning (cosine similarity of pretrained-embedding
 * vectors) instead of by typed keyword lists, with the keyword method as the fallback when the vectors are
 * not there. Vectors are written by hand; the model's HTTP endpoint is faked where the build step runs.
 */
class InterestEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    private Region $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->region = Region::create(['name' => 'Davao City']);
    }

    private function interest(string $name, array $vector): void
    {
        InterestEmbedding::create([
            'interest' => $name, 'model' => 'test', 'dimensions' => count($vector),
            'text_hash' => sha1($name), 'vector' => DestinationEmbeddingService::normalise($vector),
        ]);
    }

    private function place(string $name, ?array $vector, array $overrides = []): Destination
    {
        $destination = Destination::create($overrides + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => $this->region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 4.0, 'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => 7.07, 'longitude' => 125.61,
        ]);

        if ($vector) {
            DestinationEmbedding::create([
                'destination_id' => $destination->id, 'model' => 'test', 'dimensions' => count($vector),
                'text_hash' => sha1($name), 'vector' => DestinationEmbeddingService::normalise($vector),
            ]);
        }

        return $destination;
    }

    private function fit(array $picked, array $places): ?array
    {
        $result = app(InterestEmbeddingService::class)->fitFor($picked, collect($places));

        return $result?->mapWithKeys(fn ($v, $id) => [collect($places)->firstWhere('id', $id)->name => $v])->all();
    }

    // ---- the score

    public function test_places_are_scored_by_how_close_their_meaning_is_to_the_picked_interest(): void
    {
        $this->interest('Wildlife', [1, 0, 0]);
        $eagle = $this->place('Eagle Centre', [0.95, 0.05, 0]);
        $mid = $this->place('Garden', [0.6, 0.4, 0]);
        $beach = $this->place('Beach', [0.05, 0.95, 0]);

        $fit = $this->fit(['Wildlife'], [$eagle, $mid, $beach]);

        $this->assertSame(1.0, $fit['Eagle Centre'], 'The closest match scores 1.');
        $this->assertSame(0.0, $fit['Beach'], 'The furthest scores 0.');
        $this->assertGreaterThan(0.0, $fit['Garden']);
        $this->assertLessThan(1.0, $fit['Garden']);
    }

    public function test_several_picks_are_averaged_into_one_wish(): void
    {
        $this->interest('Wildlife', [1, 0, 0]);
        $this->interest('Beach & Island', [0, 1, 0]);
        $eagle = $this->place('Eagle Centre', [1, 0, 0]);
        $both = $this->place('Wildlife Island', [0.7, 0.7, 0]);
        $spa = $this->place('Spa', [0, 0, 1]);

        $fit = $this->fit(['Wildlife', 'Beach & Island'], [$eagle, $both, $spa]);

        $this->assertSame(1.0, $fit['Wildlife Island'], 'A place that is a bit of both beats one that is only half of it.');
        $this->assertGreaterThan($fit['Eagle Centre'], $fit['Wildlife Island']);
        $this->assertSame(0.0, $fit['Spa']);
    }

    public function test_a_place_without_a_vector_gets_the_neutral_score_and_all_equal_places_are_neutral(): void
    {
        $this->interest('Wildlife', [1, 0, 0]);
        $a = $this->place('Eagle Centre', [1, 0, 0]);
        $b = $this->place('Spa', [0, 1, 0]);
        $unknown = $this->place('New Place', null);

        $this->assertSame(0.5, $this->fit(['Wildlife'], [$a, $b, $unknown])['New Place']);

        $twin1 = $this->place('Twin One', [0.5, 0.5, 0]);
        $twin2 = $this->place('Twin Two', [0.5, 0.5, 0]);
        $this->assertSame(['Twin One' => 0.5, 'Twin Two' => 0.5], $this->fit(['Wildlife'], [$twin1, $twin2]));
    }

    public function test_it_steps_aside_when_it_cannot_score_anything(): void
    {
        $place = $this->place('Eagle Centre', [1, 0, 0]);

        $this->assertNull($this->fit(['Wildlife'], [$place]), 'No interest vectors stored yet.');

        $this->interest('Wildlife', [1, 0, 0]);
        $this->assertNull($this->fit([], [$place]), 'Nothing picked.');
        $this->assertNull($this->fit(['Underwater Basket Weaving'], [$place]), 'A pick the model has no vector for.');

        DestinationEmbedding::query()->delete();
        $this->assertNull($this->fit(['Wildlife'], [$place]), 'No place has a vector.');
    }

    // ---- inside the ranking

    private function preference(array $activities): TouristPreference
    {
        $p = TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'far',
        ]);
        foreach ($activities as $activity) {
            PreferenceActivity::create(['preference_id' => $p->id, 'activity' => $activity]);
        }

        return $p->load('activities', 'amenities');
    }

    public function test_the_ranking_uses_meaning_over_the_typed_tables_when_vectors_exist(): void
    {
        // By the typed table a golf club counts as "Nature & Adventure" and a zoo as "Wildlife".
        $golf = $this->place('Golf Club', [0.1, 0.9, 0], ['type' => 'Sports & Recreation']);
        $zoo = $this->place('Zoo', [0.9, 0.1, 0], ['type' => 'Wildlife']);
        $this->interest('Nature & Adventure', [0.9, 0.1, 0]);
        $pref = $this->preference(['Nature & Adventure']);

        $rows = app(ContentBasedRecommendationService::class)->rank($pref)->keyBy(fn ($r) => $r['destination']->name);

        $this->assertSame('semantic', $rows['Zoo']['interest_source']);
        $this->assertSame(1.0, $rows['Zoo']['interest_fit'], 'By meaning the zoo is the nature place.');
        $this->assertSame(0.0, $rows['Golf Club']['interest_fit'], 'And the golf club is not, whatever the table says.');
        $this->assertGreaterThan($rows['Golf Club']['drs'], $rows['Zoo']['drs']);
    }

    public function test_without_interest_vectors_the_typed_tables_still_decide(): void
    {
        $this->place('Golf Club', [0.1, 0.9, 0], ['type' => 'Sports & Recreation']);
        $this->place('Zoo', [0.9, 0.1, 0], ['type' => 'Wildlife']);
        $pref = $this->preference(['Nature & Adventure']);

        $rows = app(ContentBasedRecommendationService::class)->rank($pref)->keyBy(fn ($r) => $r['destination']->name);

        $this->assertSame('keyword', $rows['Golf Club']['interest_source']);
        $this->assertSame(1.0, $rows['Golf Club']['interest_fit'], 'The table calls a golf club Nature & Adventure.');
        $this->assertSame(0.0, $rows['Zoo']['interest_fit']);
    }

    public function test_only_the_interest_part_of_the_score_changes_not_the_other_factors(): void
    {
        $this->place('Golf Club', [0.1, 0.9, 0], ['type' => 'Sports & Recreation']);
        $this->place('Zoo', [0.9, 0.1, 0], ['type' => 'Wildlife']);
        $pref = $this->preference(['Nature & Adventure']);

        $before = app(ContentBasedRecommendationService::class)->rank($pref)->keyBy(fn ($r) => $r['destination']->name);
        $this->interest('Nature & Adventure', [0.9, 0.1, 0]);
        $after = app(ContentBasedRecommendationService::class)->rank($pref)->keyBy(fn ($r) => $r['destination']->name);

        foreach (['Golf Club', 'Zoo'] as $name) {
            foreach (['rs', 'ps', 'ds', 'as'] as $factor) {
                $this->assertSame($before[$name][$factor], $after[$name][$factor], "$name: $factor is untouched");
            }
        }
        $this->assertNotSame($before['Zoo']['pm'], $after['Zoo']['pm'], 'Only the preference match moved.');
    }

    // ---- building, exporting, importing

    public function test_the_build_embeds_the_interests_as_queries_and_skips_unchanged_ones(): void
    {
        config(['services.embeddings.url' => 'http://ollama.test']);
        $count = count(InterestEmbeddingService::DESCRIPTIONS);
        Http::fake(['ollama.test/api/embed' => Http::response(['embeddings' => array_fill(0, $count, [1.0, 0.0, 0.0])])]);

        $service = app(InterestEmbeddingService::class);
        $first = $service->build();
        $second = $service->build();

        $this->assertSame(['embedded' => $count, 'unchanged' => 0, 'total' => $count], $first);
        $this->assertSame(['embedded' => 0, 'unchanged' => $count, 'total' => $count], $second);
        $this->assertSame($count, InterestEmbedding::count());

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_starts_with($request['input'][0], 'search_query: Beach & Island'));
    }

    public function test_interest_vectors_travel_in_the_shipped_file_and_import_back(): void
    {
        $this->interest('Wildlife', [1, 0, 0]);
        $this->place('Eagle Centre', [1, 0, 0]);
        $path = sys_get_temp_dir().'/embeddings-'.uniqid().'.json';

        app(DestinationEmbeddingService::class)->export($path);
        $file = json_decode(file_get_contents($path), true);

        $this->assertSame(2, $file['format']);
        $this->assertArrayHasKey('Wildlife', $file['interests']);
        $this->assertArrayHasKey('eagle-centre', $file['embeddings']);

        InterestEmbedding::query()->delete();
        $this->assertSame(1, app(DestinationEmbeddingService::class)->importInterests($path));
        $this->assertSame(1, InterestEmbedding::count());

        unlink($path);
    }

    public function test_exporting_from_a_machine_without_interest_vectors_keeps_the_ones_in_the_file(): void
    {
        $this->interest('Wildlife', [1, 0, 0]);
        $this->place('Eagle Centre', [1, 0, 0]);
        $path = sys_get_temp_dir().'/embeddings-'.uniqid().'.json';
        app(DestinationEmbeddingService::class)->export($path);

        InterestEmbedding::query()->delete();   // e.g. a machine that only ran embeddings:build for a new place
        app(DestinationEmbeddingService::class)->export($path);

        $this->assertArrayHasKey('Wildlife', json_decode(file_get_contents($path), true)['interests']);

        unlink($path);
    }

    public function test_a_file_from_before_interest_vectors_existed_still_imports(): void
    {
        $this->place('Eagle Centre', [1, 0, 0]);
        $path = sys_get_temp_dir().'/embeddings-'.uniqid().'.json';
        file_put_contents($path, json_encode(['format' => 1, 'embeddings' => ['eagle-centre' => ['model' => 'test', 'text_hash' => 'x', 'vector' => [1, 0, 0]]]]));

        $this->assertSame(1, app(DestinationEmbeddingService::class)->import($path));
        $this->assertSame(0, app(DestinationEmbeddingService::class)->importInterests($path));

        unlink($path);
    }

    public function test_every_survey_interest_has_a_description(): void
    {
        $survey = ['Beach & Island', 'Nature & Adventure', 'Cultural Heritage', 'Wildlife', 'Food Tourism', 'Shopping & Souvenirs', 'Hiking & Trekking', 'Relaxation & Wellness'];

        $this->assertEqualsCanonicalizing($survey, array_keys(InterestEmbeddingService::DESCRIPTIONS));
        $this->assertStringContainsString('survey', 'survey'); // the list above is the survey's, see plan/preferences.blade.php
        $this->assertStringContainsString("'Beach & Island', 'Nature & Adventure', 'Cultural Heritage', 'Wildlife', 'Food Tourism', 'Shopping & Souvenirs', 'Hiking & Trekking', 'Relaxation & Wellness'", file_get_contents(resource_path('views/plan/preferences.blade.php')), 'The survey still offers exactly these interests.');
    }

    public function test_interests_only_updates_the_interests_and_leaves_the_place_vectors_in_the_file_alone(): void
    {
        config(['services.embeddings.url' => 'http://ollama.test']);
        $count = count(InterestEmbeddingService::DESCRIPTIONS);
        Http::fake(['ollama.test/api/embed' => Http::response(['embeddings' => array_fill(0, $count, [1.0, 0.0, 0.0])])]);

        $path = sys_get_temp_dir().'/embeddings-'.uniqid().'.json';
        $live = ['live-place' => ['model' => 'm', 'text_hash' => 'h', 'vector' => [0.1, 0.2, 0.3]]];
        file_put_contents($path, json_encode(['format' => 1, 'generated_at' => '2026-10-07', 'embeddings' => $live]));

        $service = app(InterestEmbeddingService::class);
        $service->build();
        $written = $service->exportToFile($path);
        $file = json_decode(file_get_contents($path), true);

        $this->assertSame($count, $written);
        $this->assertSame($live, $file['embeddings'], 'The live place vectors are exactly as they were.');
        $this->assertCount($count, $file['interests']);
        $this->assertSame(2, $file['format']);

        unlink($path);
    }

    public function test_the_build_command_has_an_interests_only_mode_that_does_not_embed_places(): void
    {
        config(['services.embeddings.url' => 'http://ollama.test']);
        $count = count(InterestEmbeddingService::DESCRIPTIONS);
        Http::fake(['ollama.test/api/embed' => Http::response(['embeddings' => array_fill(0, $count, [1.0, 0.0, 0.0])])]);
        $this->place('Local Only Place', null);

        $this->artisan('embeddings:build', ['--interests-only' => true, '--no-export' => true])->assertSuccessful();

        $this->assertSame($count, InterestEmbedding::count());
        $this->assertSame(0, DestinationEmbedding::count(), 'No place was embedded.');
        Http::assertSentCount(1);
    }
}
