<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\TouristPreference;
use App\Services\Embeddings\SimilarDestinationService;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Support\Toast;
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

        $rebuilt = $this->generator->generate($preference, null, null, $swaps);

        $request->session()->put(TripPlannerController::ITINERARY_KEY, $rebuilt->id);

        return redirect()->route('plan.itinerary')->with(Toast::success(
            'Stop swapped',
            $original->name.' is now '.$offered['destination']->name.'. Your schedule was rebuilt around it.'
        ));
    }

    /** @param  array{destination: Destination, similarity: float, distance_km: ?float}  $row */
    private function present(array $row): array
    {
        $d = $row['destination'];

        return [
            'id' => $d->id,
            'name' => $d->name,
            'type' => $d->type,
            'location' => $d->location,
            'similarity' => (int) round($row['similarity'] * 100),
            'distance_km' => $row['distance_km'],
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
