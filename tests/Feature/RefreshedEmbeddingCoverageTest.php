<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationEmbedding;
use App\Services\Embeddings\DestinationEmbeddingService;
use App\Services\Recommendation\ThemeDayGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshedEmbeddingCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function data(): array
    {
        return json_decode(file_get_contents(database_path('data/destination-embeddings.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function manifest(): array
    {
        return json_decode(file_get_contents(database_path('data/embedding-refresh-2026-11-01.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_shipped_vectors_cover_the_current_live_snapshot_and_are_compatible(): void
    {
        $data = $this->data();
        foreach ($this->manifest()['live_slugs'] as $slug) {
            $this->assertArrayHasKey($slug, $data['embeddings'], $slug);
        }
        foreach ($data['embeddings'] as $slug => $row) {
            $this->assertSame('nomic-embed-text', $row['model'], $slug);
            $this->assertCount(768, $row['vector'], $slug);
            $this->assertFalse(collect($row['vector'])->contains(fn ($n) => ! is_numeric($n) || ! is_finite((float) $n)), $slug);
            $this->assertEqualsWithDelta(1.0, array_sum(array_map(fn ($n) => $n * $n, $row['vector'])), 0.0001, $slug);
        }
    }

    public function test_existing_destination_and_interest_vectors_are_preserved(): void
    {
        $data = $this->data();
        $manifest = $this->manifest();
        foreach ($manifest['preserved_embeddings_sha256'] as $slug => $hash) {
            $this->assertSame($hash, hash('sha256', json_encode($data['embeddings'][$slug], JSON_UNESCAPED_SLASHES)), $slug);
        }
        $this->assertSame($manifest['preserved_interests_sha256'], hash('sha256', json_encode($data['interests'], JSON_UNESCAPED_SLASHES)));
    }

    public function test_import_fills_new_live_places_by_slug_and_unblocks_the_existing_grouper(): void
    {
        $manifest = $this->manifest();
        $slugs = ['camp-sabros-sample', 'mount-dinor-peak-sample', 'subon-cave-underground-stream-sample', 'santa-maria-viewdeck-sample'];
        $places = collect();
        foreach ($slugs as $i => $slug) {
            $source = $manifest['source_records'][$slug];
            $place = new Destination(['slug' => $slug, 'name' => $source['name'],
                'location' => 'Davao Region', 'type' => $source['type'], 'is_accredited' => true, 'latitude' => 7.1, 'longitude' => 125.5]);
            $place->id = 500 + $i * 31;
            $place->save();
            foreach ($source['categories'] as $tag) {
                $place->tags()->create(['kind' => 'category', 'value' => $tag]);
            }
            $places->push($place->load('tags'));
        }
        $sequence = $places->map(fn ($place) => ['row' => ['destination' => $place, 'drs' => 1.0], 'distance_km' => null])->all();
        $days = [1 => ['Morning', 'Afternoon'], 2 => ['Morning', 'Afternoon']];
        $origin = ['lat' => 7.1, 'lng' => 125.5];
        $this->assertNull(app(ThemeDayGrouper::class)->group($sequence, $days, $origin));
        $service = app(DestinationEmbeddingService::class);
        $this->assertSame(4, $service->import());
        $this->assertSame(4, $service->import());
        $this->assertSame(4, DestinationEmbedding::count());
        $this->assertSame([500, 531, 562, 593], DestinationEmbedding::orderBy('destination_id')->pluck('destination_id')->all());
        $result = app(ThemeDayGrouper::class)->group($sequence, $days, $origin);
        $this->assertNotNull($result);
        $this->assertCount(4, $result['sequence']);
        $this->assertCount(2, $result['days']);
        $this->artisan('embeddings:coverage', ['--json' => true])->expectsOutputToContain('"complete": true')->assertSuccessful();
    }

    public function test_coverage_check_reports_missing_vectors_without_writing_data(): void
    {
        Destination::create(['slug' => 'missing-vector', 'name' => 'Missing vector', 'location' => 'Davao', 'is_accredited' => true]);
        $this->artisan('embeddings:coverage', ['--json' => true])->expectsOutputToContain('missing-vector')->assertFailed();
        $this->assertSame(0, DestinationEmbedding::count());
    }

    public function test_coverage_check_rejects_empty_vectors(): void
    {
        $place = Destination::create(['slug' => 'empty-vector', 'name' => 'Empty vector', 'location' => 'Davao', 'is_accredited' => true]);
        DestinationEmbedding::create(['destination_id' => $place->id, 'model' => 'nomic-embed-text', 'dimensions' => 768, 'text_hash' => 'test', 'vector' => []]);
        $this->artisan('embeddings:coverage', ['--json' => true])->expectsOutputToContain('empty-vector')->assertFailed();
    }
}
