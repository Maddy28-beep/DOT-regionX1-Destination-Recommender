<?php

namespace App\Services\Recommendation;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns itineraries coded from published sources (a CSV, one row per place per
 * itinerary day) into Apriori transactions.
 *
 * Each (itinerary, day) becomes one basket: the places of this catalogue that
 * appear on that day. A basket is saved as an exit_surveys row with
 * data_source = 'itinerary' and nothing else filled in -- it is expert
 * planning, not a tourist's account, and every report that talks about
 * respondents leaves these rows out. Apriori reads them like any other
 * transaction and can be told to include or ignore them (see
 * AprioriService::onlySources()).
 *
 * Days, not whole trips: two places on the same day are a much stronger
 * "visited together" than two places three days apart, and a long blog post
 * that lists everything near Davao would otherwise add hundreds of pairs.
 * A basket with fewer than two matched places says nothing about co-visiting
 * and is dropped; one with more than $maxItems is almost certainly a page
 * that lists everything rather than one day, and is skipped and reported.
 *
 * Provenance (source address, day, accessed date) is stored with each row, and
 * is also what makes re-importing the same file harmless.
 */
class ItineraryBasketImporter
{
    public const SOURCE = 'itinerary';

    /** Words that mark a row as infrastructure rather than somewhere a visitor goes. */
    private const NOT_A_PLACE = [
        'airport', 'terminal', 'wharf', 'seaport', 'pier', 'city hall', 'hospital',
        'medical center', 'water district', 'bus station',
    ];

    /** Bare area names, never a stop of their own. */
    private const AREA_NAMES = ['davao', 'davao city', 'davao region', 'davao del sur', 'davao del norte', 'davao oriental', 'davao de oro', 'davao occidental', 'samal', 'mati', 'caraga'];

    /**
     * Spellings the same place goes by in published itineraries, written as the
     * normalized form on the left and the catalogue name on the right.
     */
    private const ALIASES = [
        'talicud island' => 'Talikud Island',
        'giant clams sanctuary' => 'Giant Clam Sanctuary',
        'giant clam sanctuary samal' => 'Giant Clam Sanctuary',
        'monfort bat sanctuary' => 'Monfort Bat Cave',
        'monfort bat cave samal' => 'Monfort Bat Cave',
        'bat caves' => 'Monfort Bat Cave',
        'sabang cliff diving' => 'Sabang Cliff',
        'kaputian beach park' => 'Kaputian Beach',
        'philippine eagle foundation' => 'Philippine Eagle Center',
        'philippine eagle sanctuary' => 'Philippine Eagle Center',
        'red planet hotel' => 'Red Planet Davao',
        'dahican beach resort spa dbrs' => 'Dahican Beach Resort and Spa',
    ];

    /** @var array<string, array<string, array{0: string, 1: int}>>|null normalized name => ["kind:id" => [kind, id]] */
    private ?array $index = null;

    /** Set when the existing baskets are about to be replaced (a rebuild), so they must not count as already imported, even in a dry run. */
    public bool $ignoreExisting = false;

    /** Itinerary ids to leave out (for example a page that turned out not to be an itinerary). @var array<int, string> */
    public array $skipItineraries = [];

    /** @var array<string, true> provenance keys already handled by this importer, so a dry run over several files still sees repeats */
    private array $seen = [];

    /**
     * @return array{
     *   rows: int, itineraries: int, days: int, baskets_created: int, baskets_duplicate: int,
     *   baskets_too_small: int, baskets_too_large: int, rows_matched: int, rows_not_a_place: int,
     *   rows_unmatched: int, unmatched: array<string, int>, ambiguous: array<string, array<int, string>>,
     *   large_baskets: array<int, string>
     * }
     */
    public function import(string $path, bool $dryRun = false, int $maxItems = 10): array
    {
        $baskets = [];
        $report = [
            'rows' => 0, 'itineraries' => 0, 'days' => 0,
            'baskets_created' => 0, 'baskets_duplicate' => 0, 'baskets_too_small' => 0, 'baskets_too_large' => 0,
            'rows_matched' => 0, 'rows_not_a_place' => 0, 'rows_unmatched' => 0,
            'unmatched' => [], 'ambiguous' => [], 'large_baskets' => [],
        ];
        $itineraries = [];

        foreach ($this->rows($path) as $row) {
            if (in_array(trim($row['itinerary_id'] ?? ''), $this->skipItineraries, true)) {
                continue;
            }

            $report['rows']++;
            $url = trim($row['source_url'] ?? '');
            $itineraries[($row['itinerary_id'] ?? '').'|'.$url] = true;

            $written = trim($row['place_name_as_written'] ?? '');
            $explicit = trim($row['matched_place'] ?? '');

            if ($written === '' && $explicit === '') {
                continue;
            }

            if ($explicit === '' && $this->isNotAPlace($written)) {
                $report['rows_not_a_place']++;

                continue;
            }

            [$match, $candidates] = $this->match(
                $explicit !== '' ? $explicit : $written,
                $explicit === '' ? null : $written,
                $this->kindFromType(trim($row['place_type'] ?? '')),
            );

            if ($match === null) {
                $report['rows_unmatched']++;
                $label = $written !== '' ? $written : $explicit;
                $report['unmatched'][$label] = ($report['unmatched'][$label] ?? 0) + 1;
                if (count($candidates) > 1) {
                    $report['ambiguous'][$label] = $candidates;
                }

                continue;
            }

            $report['rows_matched']++;
            $day = trim($row['day'] ?? '') !== '' ? trim($row['day']) : 'all';
            $key = $url.'|'.($row['itinerary_id'] ?? '').'|'.$day;
            $baskets[$key] ??= [
                'url' => $url, 'day' => $day, 'id' => $row['itinerary_id'] ?? '',
                'accessed' => trim($row['date_accessed'] ?? ''), 'items' => [],
            ];
            $baskets[$key]['items'][$match[0].':'.$match[1]] = $match;
        }

        arsort($report['unmatched']);
        $report['itineraries'] = count($itineraries);
        $report['days'] = count($baskets);

        // One page often holds several itineraries, and a shorter plan is frequently a
        // cut-down copy of a longer one. A basket wholly contained in a bigger basket from
        // the same address and a different itinerary adds nothing new, only a second count
        // of the same pairs, so it is dropped (largest first, keeping the fullest version).
        $kept = [];
        $report['baskets_repeat'] = 0;
        uasort($baskets, fn ($a, $b) => count($b['items']) <=> count($a['items']));
        foreach ($baskets as $key => $basket) {
            foreach ($kept as $other) {
                if ($other['url'] === $basket['url'] && $other['id'] !== $basket['id']
                    && ! array_diff_key($basket['items'], $other['items'])) {
                    $report['baskets_repeat']++;
                    unset($baskets[$key]);

                    continue 2;
                }
            }
            $kept[] = $basket;
        }

        DB::transaction(function () use ($baskets, $maxItems, $dryRun, &$report) {
            foreach ($baskets as $basket) {
                $size = count($basket['items']);
                $label = ($basket['id'] ?: $basket['url']).' day '.$basket['day'];

                if ($size < 2) {
                    $report['baskets_too_small']++;

                    continue;
                }
                if ($size > $maxItems) {
                    $report['baskets_too_large']++;
                    $report['large_baskets'][] = "{$label} ({$size} places)";

                    continue;
                }

                $provenance = 'itinerary src='.$basket['url'].' id='.$basket['id'].' day='.$basket['day'];
                $exists = isset($this->seen[$provenance]) || (! $this->ignoreExisting && ExitSurvey::withoutGlobalScopes()
                    ->where('data_source', self::SOURCE)->where('comments', $provenance)->exists());
                if ($exists) {
                    $report['baskets_duplicate']++;

                    continue;
                }

                $this->seen[$provenance] = true;

                if (! $dryRun) {
                    $survey = ExitSurvey::create([
                        'submitted_at' => $this->date($basket['accessed']),
                        'data_source' => self::SOURCE,
                        'comments' => $provenance,
                    ]);
                    foreach ($basket['items'] as [$kind, $id]) {
                        $survey->visits()->create(['listing_kind' => $kind, 'listing_id' => $id]);
                    }
                }

                $report['baskets_created']++;
            }
        });

        return $report;
    }

    /** @return \Generator<int, array<string, string>> */
    private function rows(string $path): \Generator
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read {$path}");
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ',', '"', '');
        if (! $header) {
            throw new RuntimeException('The file is empty.');
        }

        $header = array_map(fn ($h) => trim(ltrim((string) $h, "\xEF\xBB\xBF")), $header);
        foreach (['itinerary_id', 'source_url', 'day', 'place_name_as_written'] as $required) {
            if (! in_array($required, $header, true)) {
                throw new RuntimeException("Missing column: {$required}");
            }
        }

        while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($line === [null] || count($line) < 2) {
                continue;
            }
            $line = array_pad($line, count($header), '');
            yield array_combine($header, array_slice($line, 0, count($header)));
        }

        fclose($handle);
    }

    private function date(string $value): \Illuminate\Support\Carbon
    {
        try {
            return $value !== '' ? \Illuminate\Support\Carbon::parse($value) : now();
        } catch (\Throwable) {
            return now();
        }
    }

    private function isNotAPlace(string $name): bool
    {
        $lower = Str::lower($name);

        if (in_array($lower, self::AREA_NAMES, true)) {
            return true;
        }

        foreach (self::NOT_A_PLACE as $word) {
            if (str_contains($lower, $word)) {
                return true;
            }
        }

        return false;
    }

    /** Every word of the shorter name (at least three) appears, in order, inside the longer one: "Pearl Farm Resort" in "Pearl Farm Beach Resort". */
    private function wordsInOrder(string $short, string $long): bool
    {
        $a = explode(' ', $short);
        $b = explode(' ', $long);
        if (count($a) < 3 || count($a) >= count($b)) {
            return false;
        }

        $i = 0;
        foreach ($b as $word) {
            if ($word === $a[$i] && ++$i === count($a)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $name): string
    {
        $key = Str::slug($name, ' ');
        $key = preg_replace('/^the /', '', $key) ?? $key;

        return isset(self::ALIASES[$key]) ? Str::slug(self::ALIASES[$key], ' ') : $key;
    }

    /**
     * Resolve a name to one catalogue row: exact name first, then a name that
     * begins with the other (so "Jack's Ridge" finds "Jack's Ridge Resort and
     * Restaurant"). Anything that fits more than one row is left unmatched and
     * reported, never guessed.
     *
     * @return array{0: array{0: string, 1: int}|null, 1: array<int, string>}  [match, candidate labels]
     */
    private function match(string $name, ?string $alsoTry, ?string $kindHint = null): array
    {
        $index = $this->index();

        foreach (array_filter([$name, $alsoTry]) as $candidate) {
            $key = $this->normalize($candidate);
            if ($key !== '' && isset($index[$key])) {
                $chosen = $this->choose($index[$key], $kindHint);
                if ($chosen !== null) {
                    return [$chosen, []];
                }
            }
        }

        $key = $this->normalize($name);
        $found = [];
        if (strlen($key) >= 6 && (substr_count($key, ' ') >= 1 || strlen($key) >= 9)) {
            foreach ($index as $known => $entries) {
                $prefix = str_starts_with($known, $key.' ') || (strlen($known) >= 6 && str_starts_with($key, $known.' '))
                    || $this->wordsInOrder($key, $known) || $this->wordsInOrder($known, $key);
                if ($prefix) {
                    foreach ($entries as $id => $entry) {
                        $found[$id] = $entry;
                    }
                }
            }
        }

        if ($found !== []) {
            $chosen = $this->choose($found, $kindHint);
            if ($chosen !== null) {
                return [$chosen, []];
            }
        }

        return [null, array_keys($found)];
    }

    /**
     * One row out of the entries a name fits. A single entry is taken as is. When a
     * name is shared across kinds (a resort listed both as a destination and as
     * accommodation) the coded place type decides, and failing that a lone
     * destination wins, since a place named in an itinerary is somewhere to go.
     * Anything still ambiguous is left for the caller to report.
     *
     * @param  array<string, array{0: string, 1: int}>  $entries
     * @return array{0: string, 1: int}|null
     */
    private function choose(array $entries, ?string $kindHint): ?array
    {
        if (count($entries) === 1) {
            return array_values($entries)[0];
        }

        $narrow = fn (string $kind) => array_values(array_filter($entries, fn ($e) => $e[0] === $kind));

        if ($kindHint !== null && count($narrow($kindHint)) === 1) {
            return $narrow($kindHint)[0];
        }

        return count($narrow('destination')) === 1 ? $narrow('destination')[0] : null;
    }

    private function kindFromType(string $type): ?string
    {
        return match (Str::lower($type)) {
            'destination' => 'destination',
            'accommodation' => 'accommodation',
            'restaurant' => 'restaurant',
            'souvenir' => 'souvenir_center',
            'package' => 'package',
            default => null,
        };
    }

    /** @return array<string, array<string, array{0: string, 1: int}>> */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $this->index = [];
        $models = [
            'destination' => Destination::class,
            'accommodation' => Accommodation::class,
            'restaurant' => Restaurant::class,
            'souvenir_center' => SouvenirCenter::class,
            'package' => Package::class,
        ];

        foreach ($models as $kind => $model) {
            foreach ($model::query()->get(['id', 'name']) as $row) {
                $key = $this->normalize($row->name);
                if ($key !== '') {
                    $this->index[$key][$kind.':'.$row->id] = [$kind, (int) $row->id];
                }
            }
        }

        return $this->index;
    }
}
