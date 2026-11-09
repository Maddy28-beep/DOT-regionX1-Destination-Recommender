<?php

namespace App\Http\Controllers;

use App\Support\BudgetRanges;
use App\Support\MealTimes;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The homepage hero's search card.
 *
 * The tab ("purpose") chooses WHICH catalogue, the way a travel site's Hotels / Things-to-do tabs do, and the
 * answers become filters on it. Each tab asks only what its own catalogue can use (the card shows a tab only
 * those boxes, and this is the server-side half of the same rule), so nothing is passed along that the listing
 * page would have to ignore.
 *
 * There is no longer a name box on the card. A name typed in an old link (`q`) is still carried across, since
 * every listing page can search by name.
 */
class SearchController extends Controller
{
    /** The tab -> the catalogue that answers it. */
    private const PURPOSE_ROUTES = [
        'destinations' => 'destinations.index',
        'accommodations' => 'accommodations.index',
        'packages' => 'packages.index',
        'restaurants' => 'restaurants.index',
        'souvenir_centers' => 'souvenir-centers.index',
    ];

    /** The kinds of stay on the Stays tab. "Any" sends nothing. */
    public const STAY_MODES = ['overnight' => 'Overnight', 'day_use' => 'Day use'];

    /** Most of one kind of guest the counters allow. */
    private const MAX_GUESTS = 20;

    public function __invoke(Request $request): RedirectResponse
    {
        $purpose = (string) $request->string('purpose');
        $purpose = isset(self::PURPOSE_ROUTES[$purpose]) ? $purpose : 'destinations';

        $filters = [];

        // Old links may still carry a typed name.
        $name = mb_substr(trim((string) $request->string('q')), 0, 100);
        if ($name !== '') {
            $filters['q'] = $name;
        }

        // Where: every catalogue records a province or city.
        $region = filter_var($request->input('region_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($request->filled('region_id') && $region !== false) {
            $filters['region_id'] = $region;
        }

        // Budget: what the dropdown offers differs by catalogue (BudgetRanges).
        $budget = BudgetRanges::resolve($purpose, $request->input('budget'), $request->input('budget_min'), $request->input('budget_max'));
        if ($budget['error']) {
            return redirect()->to(route('home').'#search')->withInput()->with(Toast::error('Check the budget', $budget['error']));
        }
        if ($budget['min'] !== null) {
            $filters['price_min'] = $budget['min'];
        }
        if ($budget['max'] !== null) {
            $filters['price_max'] = $budget['max'];
        }
        if ($budget['tier'] !== null) {
            $filters['price_tier'] = $budget['tier'];
        }

        switch ($purpose) {
            case 'destinations':
                $this->carryText($request, $filters, 'interest');
                break;

            case 'packages':
                $this->carryText($request, $filters, 'interest');
                $this->carryText($request, $filters, 'duration');
                $travelers = $this->count($request, 'travelers', 30);
                if ($travelers > 1) {
                    $filters['travelers'] = $travelers;
                }
                break;

            case 'restaurants':
                $this->carryText($request, $filters, 'cuisine_type');
                $meal = (string) $request->string('meal');
                if (MealTimes::valid($meal)) {
                    $filters['meal'] = $meal;
                }
                $date = $this->date($request->input('date'));
                if ($request->filled('date') && ! $date) {
                    return $this->bad('Check the date', 'Pick the dining date from the calendar.');
                }
                if ($date) {
                    $filters['date'] = $date->toDateString();
                }
                break;

            case 'accommodations':
                $mode = (string) $request->string('stay_mode');
                if (isset(self::STAY_MODES[$mode])) {
                    $filters['stay_mode'] = $mode;
                }
                $from = $this->date($request->input('from'));
                $to = $this->date($request->input('to'));
                if (($request->filled('from') && ! $from) || ($request->filled('to') && ! $to)) {
                    return $this->bad('Check the dates', 'Pick the dates from the calendar.');
                }
                if ($from && $to && $to->lt($from)) {
                    return $this->bad('Check the dates', 'The check-out date is before the check-in date.');
                }
                if ($from) {
                    $filters['from'] = $from->toDateString();
                }
                if ($to) {
                    $filters['to'] = $to->toDateString();
                }
                foreach (['adults', 'children', 'seniors', 'pwd'] as $kind) {
                    $n = $this->count($request, $kind, self::MAX_GUESTS);
                    if ($n > 0) {
                        $filters[$kind] = $n;
                    }
                }
                break;

            case 'souvenir_centers':
                break;
        }

        return redirect()->route(self::PURPOSE_ROUTES[$purpose], $filters);
    }

    /** A value picked from a list on the page: kept short, nothing else is checked here. */
    private function carryText(Request $request, array &$filters, string $key): void
    {
        $value = mb_substr(trim((string) $request->string($key)), 0, 80);
        if ($value !== '') {
            $filters[$key] = $value;
        }
    }

    /** A counter: a whole number from 0 to $max, anything else counts as 0. */
    private function count(Request $request, string $key, int $max): int
    {
        $n = filter_var($request->input($key), FILTER_VALIDATE_INT);

        return $n === false ? 0 : max(0, min($max, $n));
    }

    private function date(mixed $value): ?Carbon
    {
        $text = trim((string) $value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            return null;
        }
        $date = Carbon::createFromFormat('Y-m-d', $text);

        return $date && $date->format('Y-m-d') === $text ? $date->startOfDay() : null;
    }

    private function bad(string $title, string $detail): RedirectResponse
    {
        return redirect()->to(route('home').'#search')->withInput()->with(Toast::error($title, $detail));
    }
}
