<?php

namespace App\Support;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The filters each listing page offers beside its grid, in the manner of a booking site: the choices differ by what is
 * being browsed, because the data does.
 *
 *   Stays          price per night, guest rating, DOT classification, amenities, open now
 *   Destinations   entry fee, guest rating, facilities, open now
 *   Dining         cuisine, guest rating, amenities, open now
 *   Packages       total price, length, difficulty, guest rating, who it suits and what is on the trip
 *   Souvenirs      guest rating, amenities, open now
 *   Tour operators specialization, guest rating
 *
 * groups() describes what the panel draws; apply() narrows a query by what the request asks for; labels() words the
 * "Showing" chips. The search box, province and budget tiles, and the price boxes that the hero search also fills
 * (price_min, price_max), stay with the controllers.
 */
final class ListingFilters
{
    /** @var array<string, array{model: class-string, table: string, price: ?string, open: bool, extras: list<string>}> */
    private const KINDS = [
        'accommodation' => ['model' => Accommodation::class, 'table' => 'accommodations', 'price' => 'Price per night, in pesos', 'open' => true, 'extras' => ['stay_class']],
        'destination' => ['model' => Destination::class, 'table' => 'destinations', 'price' => 'Entry fee, in pesos', 'open' => true, 'extras' => []],
        'restaurant' => ['model' => Restaurant::class, 'table' => 'restaurants', 'price' => null, 'open' => true, 'extras' => ['cuisine']],
        'package' => ['model' => Package::class, 'table' => 'packages', 'price' => 'Total price, in pesos', 'open' => false, 'extras' => ['days', 'difficulty']],
        'souvenir_center' => ['model' => SouvenirCenter::class, 'table' => 'souvenir_centers', 'price' => null, 'open' => true, 'extras' => []],
        'tour_operator' => ['model' => TourOperator::class, 'table' => 'tour_operators', 'price' => null, 'open' => false, 'extras' => ['spec']],
    ];

    public const RATINGS = ['4.5' => '4.5+', '4' => '4+', '3.5' => '3.5+', '3' => '3+'];

    public const DAYS = ['1' => 'Day trip', '2-3' => '2 to 3 days', '4-up' => '4 days or more'];

    /** The panel's groups for a kind. Each: key, type (range|rating|toggle|checks), label, names (its parameters), and what it needs. */
    public static function groups(string $kind, Request $request): array
    {
        $cfg = self::KINDS[$kind];
        $groups = [];

        if ($kind === 'restaurant') {
            $counts = \App\Models\RestaurantFoodPreference::confirmed()
                ->whereHas('restaurant', fn ($q) => $q->publiclyVisible())
                ->select('preference', DB::raw('count(*) as total'))->groupBy('preference')->pluck('total', 'preference')->all();
            $groups[] = ['key' => 'food', 'type' => 'checks', 'label' => 'Food preferences', 'names' => ['food'],
                'options' => \App\Models\RestaurantFoodPreference::OPTIONS,
                'counts' => array_replace(array_fill_keys(array_keys(\App\Models\RestaurantFoodPreference::OPTIONS), 0), $counts),
                'fold' => 0, 'open' => true, 'description' => 'Source-confirmed options only. Multiple choices require all selected preferences.'];
        }

        if ($cfg['price']) {
            $groups[] = ['key' => 'price', 'type' => 'range', 'label' => $cfg['price'], 'names' => ['price_min', 'price_max'], 'bounds' => self::priceBounds($kind)];
        }
        $groups[] = ['key' => 'rating', 'type' => 'rating', 'label' => 'Guest rating', 'names' => ['rating_min'], 'options' => self::RATINGS];

        foreach ($cfg['extras'] as $extra) {
            $group = match ($extra) {
                'stay_class' => self::checks('stay_class', 'DOT classification', self::counted($cfg['model'], 'dot_classification')),
                'cuisine' => self::checks('cuisine', 'Cuisine', self::counted($cfg['model'], 'cuisine_type'), 6),
                'difficulty' => self::checks('difficulty', 'Difficulty', self::counted($cfg['model'], 'difficulty')),
                'spec' => self::checks('spec', 'Specialization', self::counted($cfg['model'], 'specialization'), 6),
                'days' => ['key' => 'days', 'type' => 'checks', 'label' => 'Length', 'names' => ['days'], 'options' => self::DAYS, 'counts' => [], 'fold' => 0],
            };
            // A list that cannot split the results (one choice holds nearly everything) is no filter, so it is left out.
            if ($group['counts'] === [] || count(array_filter($group['counts'], fn ($n) => $n >= 2)) >= 2 || self::picked($request, $group['key'])) {
                $groups[] = $group;
            }
        }

        $amenities = self::amenityOptions($kind);
        if ($amenities['options']) {
            $label = match ($kind) {
                'destination' => 'Facilities',
                'package' => 'Who it suits and what is included',
                default => 'Amenities',
            };
            $groups[] = ['key' => 'amenity', 'type' => 'checks', 'label' => $label, 'names' => ['amenity'], 'options' => $amenities['options'], 'counts' => $amenities['counts'], 'fold' => 6];
        }

        if ($cfg['open']) {
            $groups[] = ['key' => 'open_now', 'type' => 'toggle', 'label' => 'Availability', 'names' => ['open_now'], 'text' => 'Open now (not closed)'];
        }

        // Whatever is chosen stays offered, even if nothing is left to count.
        foreach ($groups as &$group) {
            if ($group['type'] === 'checks') {
                foreach (self::picked($request, $group['key']) as $picked) {
                    if (! isset($group['options'][$picked])) {
                        $group['options'][$picked] = $group['key'] === 'amenity' ? (Amenities::CATALOG[$picked][0] ?? $picked) : $picked;
                    }
                }
            }
        }
        unset($group);

        return $groups;
    }

    /** Narrows $query by the request's rating, availability, amenities and the kind's own extras. */
    public static function apply(Builder $query, Request $request, string $kind): Builder
    {
        $cfg = self::KINDS[$kind];
        $table = $cfg['table'];

        if ($kind === 'restaurant') {
            foreach (self::offered($request, 'food', array_keys(\App\Models\RestaurantFoodPreference::OPTIONS)) as $preference) {
                $query->whereHas('foodPreferences', fn ($q) => $q->confirmed()->where('preference', $preference));
            }
        }

        $rating = self::scalar($request, 'rating_min');
        if (isset(self::RATINGS[$rating])) {
            $query->where("{$table}.rating", '>=', (float) $rating);
        }

        if ($cfg['open'] && $request->boolean('open_now')) {
            $query->where(fn ($q) => $q->whereNull("{$table}.operating_status")->orWhere("{$table}.operating_status", 'open'));
        }

        foreach (self::offered($request, 'amenity', Amenities::FOR_KIND[$kind] ?? []) as $key) {
            $query->whereExists(function ($sub) use ($kind, $table, $key) {
                if ($kind === 'destination') {
                    $sub->select(DB::raw(1))->from('destination_tags')
                        ->whereColumn('destination_tags.destination_id', "{$table}.id")
                        ->where('destination_tags.kind', 'amenity')
                        ->where('destination_tags.value', Amenities::CATALOG[$key][0] ?? $key);
                } else {
                    $sub->select(DB::raw(1))->from('listing_amenities')
                        ->whereColumn('listing_amenities.listing_id', "{$table}.id")
                        ->where('listing_amenities.listing_kind', $kind)
                        ->where('listing_amenities.amenity', $key);
                }
            });
        }

        foreach (['stay_class' => 'dot_classification', 'cuisine' => 'cuisine_type', 'difficulty' => 'difficulty', 'spec' => 'specialization'] as $param => $column) {
            if (in_array($param, $cfg['extras'], true) && ($picked = self::picked($request, $param))) {
                $query->whereIn("{$table}.{$column}", $picked);
            }
        }

        if (in_array('days', $cfg['extras'], true) && ($days = array_intersect(self::picked($request, 'days'), array_keys(self::DAYS)))) {
            $query->where(function ($q) use ($days, $table) {
                foreach ($days as $bucket) {
                    match ($bucket) {
                        '1' => $q->orWhere("{$table}.duration_days", '<=', 1),
                        '2-3' => $q->orWhereBetween("{$table}.duration_days", [2, 3]),
                        '4-up' => $q->orWhere("{$table}.duration_days", '>=', 4),
                    };
                }
            });
        }

        NearbyPlaces::apply($query, $request);

        return $query;
    }

    /** Chip words for the filters in this request, keyed by parameter (see ActiveFilters). */
    public static function labels(Request $request): array
    {
        $labels = [];
        $food = self::offered($request, 'food', array_keys(\App\Models\RestaurantFoodPreference::OPTIONS));
        if ($food) {
            $labels['food'] = 'Food: '.implode(', ', array_map(fn ($key) => \App\Models\RestaurantFoodPreference::OPTIONS[$key], $food));
        }
        $rating = self::scalar($request, 'rating_min');
        if (isset(self::RATINGS[$rating])) {
            $labels['rating_min'] = 'Rated '.self::RATINGS[$rating];
        }
        if ($request->boolean('open_now')) {
            $labels['open_now'] = 'Open now';
        }
        foreach (['amenity' => '', 'stay_class' => 'Class: ', 'cuisine' => 'Cuisine: ', 'difficulty' => 'Difficulty: ', 'spec' => '', 'days' => 'Length: '] as $param => $prefix) {
            if ($picked = self::picked($request, $param)) {
                $words = array_map(fn ($v) => match ($param) {
                    'amenity' => Amenities::CATALOG[$v][0] ?? $v,
                    'days' => self::DAYS[$v] ?? $v,
                    default => $v,
                }, $picked);
                $labels[$param] = $prefix.implode(', ', $words);
            }
        }

        return $labels;
    }

    /** @return array{0: int, 1: int, 2: int} the lowest, highest and step of the price slider, from the prices on file */
    private static function priceBounds(string $kind): array
    {
        $cfg = self::KINDS[$kind];
        $column = ['accommodation' => 'price_per_night', 'destination' => 'entry_fee_max', 'package' => 'price_per_pax'][$kind] ?? null;
        $top = $column ? (float) $cfg['model']::publiclyVisible()->max($column) : 0.0;
        $step = $top > 20000 ? 500 : ($top > 3000 ? 100 : 50);
        $max = max(1000, (int) (ceil($top / $step) * $step));

        return [0, $max, $step];
    }

    private static function scalar(Request $request, string $param): string
    {
        $value = $request->input($param);

        return is_scalar($value) ? (string) $value : '';
    }

    /** @return list<string> */
    private static function picked(Request $request, string $param): array
    {
        return array_values(array_unique(array_filter(
            (array) $request->input($param, []),
            fn ($v) => is_string($v) && $v !== '' && mb_strlen($v) <= 80
        )));
    }

    /** @param list<string> $allowed @return list<string> */
    private static function offered(Request $request, string $param, array $allowed): array
    {
        return array_values(array_intersect(self::picked($request, $param), $allowed));
    }

    private static function checks(string $key, string $label, array $counted, int $fold = 0): array
    {
        $values = array_map('strval', array_keys($counted));

        return ['key' => $key, 'type' => 'checks', 'label' => $label, 'names' => [$key], 'options' => array_combine($values, $values), 'counts' => $counted, 'fold' => $fold];
    }

    /** @return array<string, int> value => how many visible listings have it, most first */
    private static function counted(string $model, string $column): array
    {
        return $model::publiclyVisible()->whereNotNull($column)->where($column, '!=', '')
            ->select($column, DB::raw('count(*) as c'))->groupBy($column)->orderByDesc('c')->orderBy($column)
            ->pluck('c', $column)->map(fn ($c) => (int) $c)->all();
    }

    /** @return array{options: array<string, string>, counts: array<string, int>} key => label, key => count; only keys some listing has */
    private static function amenityOptions(string $kind): array
    {
        $cfg = self::KINDS[$kind];
        $offered = Amenities::FOR_KIND[$kind] ?? [];
        if (! $offered) {
            return ['options' => [], 'counts' => []];
        }
        $visible = $cfg['model']::publiclyVisible()->select('id');

        if ($kind === 'destination') {
            $byLabel = DB::table('destination_tags')->where('kind', 'amenity')->whereIn('destination_id', $visible)
                ->select('value', DB::raw('count(distinct destination_id) as c'))->groupBy('value')->pluck('c', 'value');
            $counts = [];
            foreach ($offered as $key) {
                $n = (int) ($byLabel[Amenities::CATALOG[$key][0] ?? $key] ?? 0);
                if ($n > 0) {
                    $counts[$key] = $n;
                }
            }
        } else {
            $counts = DB::table('listing_amenities')->where('listing_kind', $kind)->whereIn('listing_id', $visible)
                ->select('amenity', DB::raw('count(distinct listing_id) as c'))->groupBy('amenity')->pluck('c', 'amenity')
                ->map(fn ($c) => (int) $c)->all();
            $counts = array_intersect_key($counts, array_flip($offered));
        }
        arsort($counts);

        $options = [];
        foreach (array_keys($counts) as $key) {
            $options[$key] = Amenities::CATALOG[$key][0] ?? $key;
        }

        return ['options' => $options, 'counts' => $counts];
    }
}
