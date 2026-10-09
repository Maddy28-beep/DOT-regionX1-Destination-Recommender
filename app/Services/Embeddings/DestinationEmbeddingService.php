<?php

namespace App\Services\Embeddings;

use App\Models\Destination;
use App\Models\DestinationEmbedding;
use Illuminate\Support\Facades\Http;

/**
 * Pretrained text-embedding step (manuscript: "Similar-place swaps").
 *
 * A pretrained sentence-embedding model (nomic-embed-text, served locally by
 * Ollama, inference only -- nothing is trained or fine-tuned here) turns each
 * destination's text into a vector. Places that mean similar things end up
 * close together, so the similarity of two places is the cosine of two vectors.
 *
 * The model is only needed when a destination is added or edited. Vectors are
 * stored in destination_embeddings and exported to
 * database/data/destination-embeddings.json, so the live server ranks swap
 * suggestions with plain arithmetic and no model at all.
 */
class DestinationEmbeddingService
{
    public const EXPORT_PATH = 'database/data/destination-embeddings.json';

    /** nomic-embed-text expects a task prefix on every document it embeds... */
    private const DOCUMENT_PREFIX = 'search_document: ';

    /** ...and a different one on what is being searched for (a traveller's interest). */
    public const QUERY_PREFIX = 'search_query: ';

    public function isConfigured(): bool
    {
        return filled(config('services.embeddings.url'));
    }

    public function model(): string
    {
        return (string) config('services.embeddings.model', 'nomic-embed-text');
    }

    /**
     * What the model reads for one place: what it is, what it offers, how it
     * is described. Location is left out on purpose: distance is checked
     * separately, and a town name would group places by address instead of by
     * what a visitor does there.
     */
    public function textFor(Destination $destination): string
    {
        $destination->loadMissing('tags');

        $categories = $destination->tags->where('kind', 'category')->pluck('value')->filter()->implode(', ');
        $amenities = $destination->tags->where('kind', 'amenity')->pluck('value')->filter()->implode(', ');

        return trim(implode('. ', array_filter([
            $destination->name,
            $destination->type,
            $categories !== '' ? 'Categories: '.$categories : null,
            $amenities !== '' ? 'Amenities: '.$amenities : null,
            trim((string) $destination->description),
        ])));
    }

    /**
     * Embeds several texts in one request.
     *
     * @param  array<int, string>  $texts
     * @param  string|null  $prefix  the model's task prefix; documents (places) by default
     * @return array<int, array<int, float>>  unit-length vectors, same order as $texts
     */
    public function embed(array $texts, ?string $prefix = null): array
    {
        if ($texts === []) {
            return [];
        }

        $response = Http::timeout((int) config('services.embeddings.timeout', 120))
            ->post(rtrim((string) config('services.embeddings.url'), '/').'/api/embed', [
                'model' => $this->model(),
                'input' => array_map(fn (string $t) => ($prefix ?? self::DOCUMENT_PREFIX).$t, array_values($texts)),
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Embedding request failed: HTTP '.$response->status().' '.$response->body());
        }

        $vectors = $response->json('embeddings');

        if (! is_array($vectors) || count($vectors) !== count($texts)) {
            throw new \RuntimeException('Embedding response did not return one vector per text.');
        }

        return array_map(fn (array $v) => self::normalise($v), $vectors);
    }

    /**
     * Embeds every destination whose text changed (or all of them with $force)
     * and stores the result.
     *
     * @return array{embedded: int, unchanged: int, total: int}
     */
    public function build(bool $force = false): array
    {
        $destinations = Destination::with('tags')->orderBy('id')->get();
        $existing = DestinationEmbedding::pluck('text_hash', 'destination_id');

        $pending = [];
        foreach ($destinations as $destination) {
            $text = $this->textFor($destination);
            $hash = sha1($this->model().'|'.$text);
            if ($force || ($existing[$destination->id] ?? null) !== $hash) {
                $pending[] = ['destination' => $destination, 'text' => $text, 'hash' => $hash];
            }
        }

        foreach (array_chunk($pending, 8) as $chunk) {
            $vectors = $this->embed(array_column($chunk, 'text'));
            foreach ($chunk as $i => $row) {
                DestinationEmbedding::updateOrCreate(
                    ['destination_id' => $row['destination']->id],
                    [
                        'model' => $this->model(),
                        'dimensions' => count($vectors[$i]),
                        'text_hash' => $row['hash'],
                        'vector' => $vectors[$i],
                    ],
                );
            }
        }

        // Drop vectors of destinations that no longer exist.
        DestinationEmbedding::whereNotIn('destination_id', $destinations->pluck('id'))->delete();

        return [
            'embedded' => count($pending),
            'unchanged' => $destinations->count() - count($pending),
            'total' => $destinations->count(),
        ];
    }

    /**
     * Writes every stored vector to the shipped JSON file: the destinations keyed by slug, and the survey
     * interests under "interests". If this machine has no interest vectors, the ones already in the file are
     * kept rather than dropped.
     */
    public function export(?string $path = null): int
    {
        $path ??= base_path(self::EXPORT_PATH);

        $interests = app(InterestEmbeddingService::class)->exportRows();
        if ($interests === [] && is_file($path)) {
            $interests = json_decode((string) file_get_contents($path), true)['interests'] ?? [];
        }
        $rows = DestinationEmbedding::with('destination:id,slug')->get()
            ->filter(fn (DestinationEmbedding $e) => $e->destination)
            ->sortBy(fn (DestinationEmbedding $e) => $e->destination->slug)
            ->mapWithKeys(fn (DestinationEmbedding $e) => [$e->destination->slug => [
                'model' => $e->model,
                'text_hash' => $e->text_hash,
                'vector' => array_map(fn ($x) => round((float) $x, 6), $e->vector),
            ]]);

        file_put_contents($path, json_encode(
            ['format' => 2, 'generated_at' => now()->toDateString(), 'embeddings' => $rows, 'interests' => $interests],
            JSON_UNESCAPED_SLASHES,
        ));

        return $rows->count();
    }

    /** Loads the shipped JSON file into the table. Safe to run repeatedly. Returns how many were stored. */
    public function import(?string $path = null): int
    {
        $path ??= base_path(self::EXPORT_PATH);

        if (! is_file($path)) {
            return 0;
        }

        $data = json_decode((string) file_get_contents($path), true);
        $bySlug = Destination::pluck('id', 'slug');
        $stored = 0;

        foreach (($data['embeddings'] ?? []) as $slug => $row) {
            $id = $bySlug[$slug] ?? null;
            if ($id === null || empty($row['vector'])) {
                continue;
            }
            DestinationEmbedding::updateOrCreate(
                ['destination_id' => $id],
                [
                    'model' => $row['model'] ?? $this->model(),
                    'dimensions' => count($row['vector']),
                    'text_hash' => $row['text_hash'] ?? '',
                    'vector' => $row['vector'],
                ],
            );
            $stored++;
        }

        return $stored;
    }

    /** Loads the "interests" section of the shipped JSON file. Returns how many interest vectors were stored. */
    public function importInterests(?string $path = null): int
    {
        $path ??= base_path(self::EXPORT_PATH);

        if (! is_file($path)) {
            return 0;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return app(InterestEmbeddingService::class)->importRows($data['interests'] ?? []);
    }

    /** @param  array<int, float|int>  $v */
    public static function normalise(array $v): array
    {
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));

        return $norm > 0 ? array_map(fn ($x) => $x / $norm, $v) : $v;
    }

    /**
     * Cosine similarity of two unit-length vectors (their dot product):
     * 1 for identical meaning, lower the less alike the places are.
     *
     * @param  array<int, float|int>  $a
     * @param  array<int, float|int>  $b
     */
    public static function similarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        $dot = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
        }

        return $dot;
    }
}
