<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\TouristPreference;
use App\Services\Embeddings\SimilarDestinationService;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Support\OpeningHours;
use App\Support\Toast;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Similar-place swaps for the trip planner's itinerary page.
 *
 *   GET  /plan/itinerary/alternatives/{id}  -> the most similar places, as JSON
 *   POST /plan/itinerary/swap               -> rebuild the itinerary with one stop replaced
 *
 * The suggestions come from SimilarDestinationService (pretrained embeddings
 * ranked by cosine similarity). The swap itself rebuilds the schedule through
 * ItineraryGenerationService, so travel times, meals and association-rule
 * picks all follow the new place.
 */
class ItinerarySwapController extends Controller
{
    /** How many alternatives the page offers per stop. */
    private const OFFER = 3;

    public function __construct(
        private readonly SimilarDestinationService $similar,
        private readonly ItineraryGenerationService $generator,
    ) {}

    public function alternatives(Request $request, int $destinationId): JsonResponse
    {
        $itinerary = $this->currentItinerary($request);
        $original = $itinerary ? $this->stopInTrip($itinerary, $destinationId) : null;

        abort_if($original === null, 404);

        $closure = $this->similar->closure($original, $this->similar->windowFor($itinerary));

        return response()->json([
            'original' => ['id' => $original->id, 'name' => $original->name],
            'advisory' => $closure ? ['title' => $closure['reason'], 'message' => $closure['reason']] : null,
            'alternatives' => $this->similar->alternatives($original, $itinerary, self::OFFER)
                ->map(fn (array $row) => $this->present($row))
                ->all(),
        ]);
    }

    public function swap(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'original_id' => ['required', 'integer'],
            'replacement_id' => ['required', 'integer', 'different:original_id'],
        ]);

        $itinerary = $this->currentItinerary($request);
        $preference = $this->currentPreference($request);
        $original = $itinerary ? $this->stopInTrip($itinerary, (int) $data['original_id']) : null;

        if (! $itinerary || ! $preference || ! $original) {
            return redirect()->route('plan.itinerary')
                ->with(Toast::success('Nothing to swap', 'That stop is not part of your current itinerary.'));
        }

        // Only places the suggester would actually offer can be swapped in, so a
        // hand-made request cannot slip in a closed, hidden or far-away place.
        $offered = $this->similar->alternatives($original, $itinerary, 10)
            ->first(fn (array $row) => $row['destination']->id === (int) $data['replacement_id']);

        if (! $offered) {
            return redirect()->route('plan.itinerary')
                ->with(Toast::success('Not available', 'That place cannot replace this stop.'));
        }

        // original id => replacement id, tracing back to the stop the planner first chose
        $swaps = $itinerary->swaps ?? [];
        $root = array_search($original->id, $swaps, true);
        $swaps[$root !== false ? $root : $original->id] = $offered['destination']->id;

        $replacement = $offered['destination'];
        $rebuilt = $this->generator->generate($preference, null, null, $swaps);

        // The replacement has to make it into the schedule. The planner leaves a stop out when it cannot be done
        // (it closes before the traveller could get there, or it would mean too much driving that day), and
        // swapping the original for nothing would be worse than not swapping.
        $placed = $rebuilt->items()->where('kind', 'activity')->where('destination_id', $replacement->id)->exists();

        if (! $placed) {
            $rebuilt->delete();

            return redirect()->route('plan.itinerary')->with(Toast::error(
                'Could not swap',
                $replacement->name.' does not fit your schedule — it may close before you could get there, or add too much driving to the day. Your itinerary is unchanged.'
            ));
        }

        $request->session()->put(TripPlannerController::ITINERARY_KEY, $rebuilt->id);

        $notes = $this->notes($itinerary, $rebuilt, $original, $replacement);

        return redirect()->route('plan.itinerary')->with(Toast::success(
            'Stop swapped',
            trim($original->name.' is now '.$replacement->name.'. Your schedule was rebuilt around it. '.implode(' ', $notes))
        ));
    }

    /**
     * Short heads-ups about what the swap did to the plan, in plain words: a stop that dropped out, a visit
     * that is shorter than usual because the place closes, a day that now ends late.
     *
     * @return list<string>
     */
    private function notes(Itinerary $before, Itinerary $after, Destination $original, Destination $replacement): array
    {
        $notes = [];

        // Anything else that was in the plan and no longer is.
        $ids = fn (Itinerary $it) => $it->items()->where('kind', 'activity')->whereNotNull('destination_id')->pluck('destination_id')->map(fn ($id) => (int) $id)->all();
        $lost = array_diff($ids($before), $ids($after), [$original->id]);

        if ($lost !== []) {
            $names = Destination::whereIn('id', $lost)->pluck('name')->implode(', ');
            $notes[] = 'Heads-up: '.$names.' no longer fits and has left the plan.';
        }

        $visit = $after->items()->where('kind', 'activity')->where('destination_id', $replacement->id)->first();

        if ($visit && $visit->starts_at && $visit->ends_at) {
            $minutes = Carbon::parse($visit->starts_at)->diffInMinutes(Carbon::parse($visit->ends_at));
            $closes = $this->closingTime($replacement, $visit);

            if ($closes && $minutes < 150) {
                $notes[] = $replacement->name.' closes at '.$closes.', so the visit is shortened to '.$this->duration($minutes).'.';
            }

            $day = $this->dayEnd($after, (int) $visit->day_number);
            $was = $this->dayEnd($before, (int) $visit->day_number);

            if ($day && $day >= '19:30:00' && (! $was || $day > $was)) {
                $notes[] = 'Day '.$visit->day_number.' now ends around '.Carbon::parse($day)->format('g:i A').'.';
            }
        }

        return $notes;
    }

    /** When the day's last row begins (departure on the final day, overnight otherwise). */
    private function dayEnd(Itinerary $itinerary, int $day): ?string
    {
        return $itinerary->items()->where('day_number', $day)->whereIn('kind', ['departure', 'overnight'])
            ->orderByDesc('sort_order')->value('starts_at');
    }

    /** The closing time of the window the visit falls in, when the place's hours can be read. */
    private function closingTime(Destination $destination, ItineraryItem $visit): ?string
    {
        $windows = OpeningHours::windows($destination->hours);

        if ($windows === null) {
            return null;
        }

        $start = Carbon::parse($visit->starts_at);
        $startMinute = $start->hour * 60 + $start->minute;

        foreach ($windows as [$open, $close]) {
            if ($startMinute >= $open && $startMinute < $close) {
                return Carbon::today()->addMinutes($close % 1440)->format('g:i A');
            }
        }

        return null;
    }

    private function duration(int $minutes): string
    {
        return $minutes % 60 === 0 ? ($minutes / 60).' '.($minutes === 60 ? 'hour' : 'hours') : $minutes.' minutes';
    }

    /**
     * One suggestion for the page: what it is, how close it is in meaning, and a short reason it is a fair swap.
     *
     * @param  array{destination: Destination, similarity: float, distance_km: ?float, interest_fit?: ?float}  $row
     */
    private function present(array $row): array
    {
        $d = $row['destination'];

        $reason = [];
        $fit = $row['interest_fit'] ?? null;

        if ($fit !== null) {
            $reason[] = $fit >= 0.5 ? 'Matches your interests' : 'Not one of your picked interests';
        }

        if (OpeningHours::windows($d->hours) !== null) {
            $reason[] = 'Open '.$d->hours;
        }

        return [
            'id' => $d->id,
            'name' => $d->name,
            'type' => $d->type,
            'location' => $d->location,
            'similarity' => (int) round($row['similarity'] * 100),
            'distance_km' => $row['distance_km'],
            'reason' => implode(' · ', $reason),
            'url' => route('destinations.show', $d),
        ];
    }

    private function stopInTrip(Itinerary $itinerary, int $destinationId): ?Destination
    {
        if ($itinerary->package_id) {
            return null;
        }

        return $itinerary->items()->where('destination_id', $destinationId)->exists()
            ? Destination::find($destinationId)
            : null;
    }

    private function currentItinerary(Request $request): ?Itinerary
    {
        $id = $request->session()->get(TripPlannerController::ITINERARY_KEY);

        return $id ? Itinerary::find($id) : null;
    }

    private function currentPreference(Request $request): ?TouristPreference
    {
        $id = $request->session()->get(TripPlannerController::PREFERENCE_KEY);

        return $id ? TouristPreference::with('activities', 'amenities')->find($id) : null;
    }
}
