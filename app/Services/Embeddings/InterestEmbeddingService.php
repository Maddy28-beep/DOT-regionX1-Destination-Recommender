<?php

namespace App\Services\Embeddings;

use App\Models\InterestEmbedding;
use Illuminate\Support\Collection;

/**
 * Matches a traveller's picked interests to places by meaning.
 *
 * The survey offers eight interests. Each is described in one short sentence (DESCRIPTIONS), and the
 * pretrained embedding model (the same one that embeds the places; inference only, nothing is trained)
 * turns that sentence into a vector. A place's match to the traveller is the cosine similarity between its
 * own vector and the average of the vectors of the interests they picked. This replaces matching by a
 * typed keyword list, which had to be edited by hand every time a new tag appeared and was the reason a
 * golf club counted as "Nature & Adventure".
 *
 * As with the places, the model is only needed to build the interest vectors (embeddings:build). They ship
 * in database/data/destination-embeddings.json, so the live server does plain arithmetic.
 *
 * Without stored vectors, fitFor() returns null and the recommender falls back to the keyword method,
 * so nothing breaks before the vectors exist.
 */
class InterestEmbeddingService
{
    /** The survey's interests, each with the sentence the model reads. The wording is the model's whole idea of the interest. */
    public const DESCRIPTIONS = [
        'Beach & Island' => 'Beaches, island hopping, snorkeling, swimming and surfing by the sea.',
        'Nature & Adventure' => 'Nature parks, gardens, forests, waterfalls and outdoor adventure such as ziplines and cool highland climate.',
        'Cultural Heritage' => 'Museums, historic sites, heritage places, local culture, traditions and city landmarks.',
        'Wildlife' => 'Seeing wild animals and birds, zoos, wildlife sanctuaries, the Philippine eagle and conservation.',
        'Food Tourism' => 'Local food, tasting experiences, farm tours with food, fruit, chocolate and culinary tours.',
        'Shopping & Souvenirs' => 'Shopping for souvenirs, local products, pasalubong, markets and handicrafts.',
        'Hiking & Trekking' => 'Hiking, trekking and mountain climbing, trails and summits.',
        'Relaxation & Wellness' => 'Relaxing and wellness: spa, massage, quiet resorts, rest and rejuvenation.',
    ];

    public function __construct(private readonly DestinationEmbeddingService $embeddings) {}

    public function textFor(string $interest): string
    {
        return $interest.'. '.self::DESCRIPTIONS[$interest];
    }

    /**
     * Embeds every interest whose description changed (or all of them with $force) and stores the result.
     *
     * @return array{embedded: int, unchanged: int, total: int}
     */
    public function build(bool $force = false): array
    {
        $existing = InterestEmbedding::pluck('text_hash', 'interest');
        $pending = [];

        foreach (array_keys(self::DESCRIPTIONS) as $interest) {
            $text = $this->textFor($interest);
            $hash = sha1($this->embeddings->model().'|query|'.$text);

            if ($force || ($existing[$interest] ?? null) !== $hash) {
                $pending[] = ['interest' => $interest, 'text' => $text, 'hash' => $hash];
            }
        }

        if ($pending !== []) {
            // These are what a traveller is looking for, so they are embedded as queries; the places were
            // embedded as documents. The model is trained to compare a query with documents.
            $vectors = $this->embeddings->embed(array_column($pending, 'text'), DestinationEmbeddingService::QUERY_PREFIX);

            foreach ($pending as $i => $row) {
                InterestEmbedding::updateOrCreate(
                    ['interest' => $row['interest']],
                    [
                        'model' => $this->embeddings->model(),
                        'dimensions' => count($vectors[$i]),
                        'text_hash' => $row['hash'],
                        'vector' => $vectors[$i],
                    ],
                );
            }
        }

        InterestEmbedding::whereNotIn('interest', array_keys(self::DESCRIPTIONS))->delete();

        return [
            'embedded' => count($pending),
            'unchanged' => count(self::DESCRIPTIONS) - count($pending),
            'total' => count(self::DESCRIPTIONS),
        ];
    }

    /**
     * The stored vectors, as written into the shipped JSON file.
     *
     * @return array<string, array{model: string, text_hash: string, vector: array<int, float>}>
     */
    public function exportRows(): array
    {
        return InterestEmbedding::orderBy('interest')->get()
            ->mapWithKeys(fn (InterestEmbedding $e) => [$e->interest => [
                'model' => $e->model,
                'text_hash' => $e->text_hash,
                'vector' => array_map(fn ($x) => round((float) $x, 6), $e->vector),
            ]])
            ->all();
    }

    /**
     * Writes only the "interests" section of the shipped JSON file, leaving the destination vectors in it as
     * they are. This is for a machine whose own database is not the live catalogue (a laptop with an old
     * seed): writing the whole file from there would replace the live places' vectors with the wrong ones.
     */
    public function exportToFile(?string $path = null): int
    {
        $path ??= base_path(DestinationEmbeddingService::EXPORT_PATH);
        $data = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $rows = $this->exportRows();

        $data['format'] = 2;
        $data['generated_at'] = now()->toDateString();
        $data['embeddings'] ??= [];
        $data['interests'] = $rows;

        file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES));

        return count($rows);
    }

    /**
     * Loads vectors from the shipped JSON file's "interests" section. Unknown interests are ignored.
     *
     * @param  array<string, array{model?: string, text_hash?: string, vector?: array<int, float>}>  $rows
     */
    public function importRows(array $rows): int
    {
        $stored = 0;

        foreach ($rows as $interest => $row) {
            if (! array_key_exists($interest, self::DESCRIPTIONS) || empty($row['vector'])) {
                continue;
            }

            InterestEmbedding::updateOrCreate(
                ['interest' => $interest],
                [
                    'model' => $row['model'] ?? $this->embeddings->model(),
                    'dimensions' => count($row['vector']),
                    'text_hash' => $row['text_hash'] ?? '',
                    'vector' => $row['vector'],
                ],
            );
            $stored++;
        }

        return $stored;
    }

    /**
     * How well each candidate matches the interests the traveller picked, 0 to 1, by meaning.
     *
     * The traveller's picks are averaged into one vector, and each place's score is its cosine similarity to
     * it. Raw cosines from this model sit in a narrow band (roughly 0.6 to 0.8 for everything), so they are
     * stretched across the candidates being ranked: the closest match scores 1, the furthest 0. That makes
     * it a relative measure, like the popularity score next to it. If every candidate scores the same, they
     * all get the neutral 0.5.
     *
     * Returns null when this cannot be done -- nothing picked, a pick the model has no vector for, no vector
     * for any candidate -- and the caller uses the keyword method instead. A candidate with no vector of its
     * own gets the neutral 0.5.
     *
     * @param  array<int, string>  $selectedInterests
     * @param  Collection<int, \App\Models\Destination>  $candidates
     * @return Collection<int, float>|null  keyed by destination id
     */
    public function fitFor(array $selectedInterests, Collection $candidates): ?Collection
    {
        if ($selectedInterests === [] || $candidates->isEmpty()) {
            return null;
        }

        $vectors = InterestEmbedding::whereIn('interest', $selectedInterests)->get()->pluck('vector');

        if ($vectors->isEmpty()) {
            return null;
        }

        $wanted = DestinationEmbeddingService::normalise($this->mean($vectors->all()));

        $stored = \App\Models\DestinationEmbedding::whereIn('destination_id', $candidates->pluck('id'))
            ->get()->pluck('vector', 'destination_id');

        if ($stored->isEmpty()) {
            return null;
        }

        $raw = $stored->map(fn (array $vector) => DestinationEmbeddingService::similarity($wanted, $vector));
        $low = $raw->min();
        $high = $raw->max();

        return $candidates->mapWithKeys(function ($destination) use ($raw, $low, $high) {
            $cosine = $raw->get($destination->id);

            return [$destination->id => match (true) {
                $cosine === null, $high - $low < 1e-9 => 0.5,
                default => round(($cosine - $low) / ($high - $low), 4),
            }];
        });
    }

    /**
     * @param  array<int, array<int, float>>  $vectors
     * @return array<int, float>
     */
    private function mean(array $vectors): array
    {
        $count = count($vectors);
        $sum = array_fill(0, count($vectors[0]), 0.0);

        foreach ($vectors as $vector) {
            foreach ($vector as $i => $x) {
                $sum[$i] += $x;
            }
        }

        return array_map(fn ($x) => $x / $count, $sum);
    }
}
