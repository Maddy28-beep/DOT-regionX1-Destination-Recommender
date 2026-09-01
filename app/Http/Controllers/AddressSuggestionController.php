<?php

namespace App\Http\Controllers;

use App\Services\Geocoding\AddressSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Address type-ahead for the trip planner's starting-point field.
 *
 * A thin, throttled proxy in front of AddressSuggestionService -- see that
 * class for why the lookup happens on the server rather than in the browser.
 */
class AddressSuggestionController extends Controller
{
    public function __invoke(Request $request, AddressSuggestionService $addresses): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:120'],
        ]);

        return response()->json([
            'results' => $addresses->suggest($data['q'])->all(),
        ]);
    }
}
