<?php

namespace App\Services\Admin;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What each listing still needs so itineraries are accurate.
 *
 * A fixed list of checks, each with a plain reason it matters. For one kind of listing it says how many of the
 * active listings fail each check, how complete the kind is overall, and which places fail what.
 *
 * Nothing here is a judgement about quality: every check is "is this filled in", measured the same way for every
 * listing, so the number goes up only when someone adds the missing information.
 */
class DataCompleteness
{
    /** Listing types as the admin routes name them. `hours` is the column holding opening hours, when the type has one. */
    public const TYPES = [
        'destinations' => ['label' => 'Destinations', 'model' => Destination::class, 'kind' => 'destination', 'hours' => 'hours', 'themes' => true],
        'accommodations' => ['label' => 'Stays', 'model' => Accommodation::class, 'kind' => 'accommodation', 'hours' => null, 'themes' => false],
        'restaurants' => ['label' => 'Dining', 'model' => Restaurant::class, 'kind' => 'restaurant', 'hours' => 'opening_hours', 'themes' => false],
        'souvenir-centers' => ['label' => 'Souvenirs', 'model' => SouvenirCenter::class, 'kind' => 'souvenir_center', 'hours' => null, 'themes' => false],
        'tour-operators' => ['label' => 'Tour operators', 'model' => TourOperator::class, 'kind' => 'tour_operator', 'hours' => null, 'themes' => false],
        'packages' => ['label' => 'Packages', 'model' => Package::class, 'kind' => 'package', 'hours' => null, 'themes' => false],
    ];

    /**
     * The checks, most serious first. `fix` says how a gap is closed: typed in place ("coords", "hours", "credit"),
     * by a system job ("job"), through renewal ("renew"), or in the edit form ("edit").
     */
    public const CHECKS = [
        'accred' => ['label' => 'Accreditation expired', 'short' => 'Expired', 'severity' => 'high', 'fix' => 'renew',
            'why' => 'Hidden from tourists until it is renewed.'],
        'coords' => ['label' => 'Map position', 'short' => 'Position', 'severity' => 'high', 'fix' => 'coords',
            'why' => 'Without it a journey gets a flat time allowance and the stop is missing from the map.'],
        'hours' => ['label' => 'Opening hours', 'short' => 'Hours', 'severity' => 'mid', 'fix' => 'hours',
            'why' => 'The itinerary cannot warn that a place is closed on the day.'],
        'embed' => ['label' => 'Theme data', 'short' => 'Theme data', 'severity' => 'mid', 'fix' => 'job',
            'why' => 'Day themes (like "Wildlife day") only appear when every stop has it.'],
        'credit' => ['label' => 'Photo credit', 'short' => 'Photo credit', 'severity' => 'low', 'fix' => 'credit',
            'why' => 'A photo needs its source recorded before it can be shown with permission.'],
        'desc' => ['label' => 'Description', 'short' => 'Description', 'severity' => 'low', 'fix' => 'edit',
            'why' => 'Cards and pages look empty without one.'],
        'photo' => ['label' => 'Photo', 'short' => 'Photo', 'severity' => 'low', 'fix' => 'edit',
            'why' => 'A listing without a photo shows a placeholder.'],
    ];

    private const SEVERITY_WEIGHT = ['high' => 3, 'mid' => 2, 'low' => 1];

    /** The Davao Region, with a margin: a position outside it is a typo or a swapped pair, not a place. */
    public const LAT_RANGE = [5.5, 8.6];
    public const LNG_RANGE = [124.9, 127.2];

    public function types(): array
    {
        return self::TYPES;
    }

    public function isType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /** The checks that mean something for this kind of listing. */
    public function applicable(string $type): array
    {
        $config = self::TYPES[$type];

        return array_values(array_filter(array_keys(self::CHECKS), fn (string $check) => match ($check) {
            'hours' => $config['hours'] !== null,
            'embed' => $config['themes'],
            default => true,
        }));
    }

    /**
     * Every active listing of the type that fails at least one check, worst first.
     *
     * @return array<int, array{id: int, name: string, where: ?string, miss: array<int, string>}>
     */
    public function failing(string $type): array
    {
        $config = self::TYPES[$type];
        $model = $config['model'];
        $applicable = $this->applicable($type);

        $rows = $model::query()->whereNull('archived_at')->orderBy('name')->get();
        $photos = DB::table('listing_photos')->where('listing_kind', $config['kind'])->get()->groupBy('listing_id');
        $themed = $config['themes'] ? DB::table('destination_embeddings')->pluck('destination_id')->flip() : collect();
        $accreditation = DB::table('accreditation_records')->where('listing_kind', $config['kind'])->get()->groupBy('listing_id');

        $failing = [];
        foreach ($rows as $row) {
            $miss = array_values(array_filter($applicable, fn (string $check) => $this->fails($check, $row, $config, $photos->get($row->getKey(), collect()), $themed, $accreditation->get($row->getKey(), collect()))));

            if ($miss !== []) {
                $failing[] = ['id' => (int) $row->getKey(), 'name' => (string) $row->name, 'where' => $row->location, 'miss' => $miss];
            }
        }

        usort($failing, fn (array $a, array $b) => $this->weight($b['miss']) <=> $this->weight($a['miss']) ?: strcmp($a['name'], $b['name']));

        return $failing;
    }

    /**
     * How many listings of the type there are, how many fail each check, and the overall completeness
     * (the average, over the checks that apply, of the share of listings that pass).
     *
     * @return array{total: int, missing: array<string, int>, readiness: int}
     */
    public function summary(string $type, ?array $failing = null): array
    {
        $failing ??= $this->failing($type);
        $total = (int) self::TYPES[$type]['model']::query()->whereNull('archived_at')->count();

        $missing = array_fill_keys($this->applicable($type), 0);
        foreach ($failing as $row) {
            foreach ($row['miss'] as $check) {
                $missing[$check]++;
            }
        }

        $shares = $total === 0 ? [1.0] : array_map(fn (int $count) => 1 - $count / $total, $missing);

        return ['total' => $total, 'missing' => $missing, 'readiness' => (int) round(array_sum($shares) / max(1, count($shares)) * 100)];
    }

    /** The share of listings passing one check, 0-100. */
    public function percent(array $summary, string $check): int
    {
        return $summary['total'] === 0 ? 100 : (int) round((1 - ($summary['missing'][$check] ?? 0) / $summary['total']) * 100);
    }

    /** One listing's own failures, for refreshing a row after it has been fixed. */
    public function missFor(string $type, Model $listing): array
    {
        $config = self::TYPES[$type];
        $photos = DB::table('listing_photos')->where('listing_kind', $config['kind'])->where('listing_id', $listing->getKey())->get();
        $themed = $config['themes'] ? DB::table('destination_embeddings')->where('destination_id', $listing->getKey())->pluck('destination_id')->flip() : collect();
        $accreditation = DB::table('accreditation_records')->where('listing_kind', $config['kind'])->where('listing_id', $listing->getKey())->get();

        return array_values(array_filter($this->applicable($type), fn (string $check) => $this->fails($check, $listing, $config, $photos, $themed, $accreditation)));
    }

    private function fails(string $check, Model $row, array $config, $photos, $themed, $accreditation): bool
    {
        // The seeded placeholder drawings are not photos.
        $real = $photos->filter(fn ($p) => ! str_ends_with(strtolower((string) $p->path), '.svg'));

        return match ($check) {
            'coords' => $row->latitude === null || $row->longitude === null,
            'hours' => $config['hours'] !== null && trim((string) ($row->{$config['hours']} ?? '')) === '',
            'desc' => trim((string) ($row->description ?? '')) === '',
            'photo' => $real->isEmpty(),
            'credit' => $real->isNotEmpty() && $real->every(fn ($p) => trim((string) $p->source_name) === '' && trim((string) $p->source_url) === ''),
            'embed' => ! $themed->has($row->getKey()),
            'accred' => $this->expired($accreditation),
            default => false,
        };
    }

    private function expired($records): bool
    {
        $latest = $records->sortByDesc('expiration_date')->first();

        return $latest && $latest->expiration_date && $latest->expiration_date < now()->toDateString();
    }

    private function weight(array $miss): int
    {
        return array_sum(array_map(fn (string $c) => self::SEVERITY_WEIGHT[self::CHECKS[$c]['severity']] ?? 1, $miss));
    }
}
