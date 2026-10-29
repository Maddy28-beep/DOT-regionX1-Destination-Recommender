<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DataCompleteness;
use App\Support\Toast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "Data completeness": what each listing still needs so itineraries are accurate, with the three gaps that can be
 * closed by typing one thing (a map position, opening hours, a photo credit) fixable on the spot.
 *
 * Every other gap links to the listing's edit form. Saves go through the same admin middleware as the rest of the
 * console, so each one is written to the audit log with the listing it touched.
 */
class AdminDataCompletenessController extends Controller
{
    /** How many failing listings the page lists; the counts always cover all of them. */
    private const LIST_LIMIT = 100;

    public function __construct(private readonly DataCompleteness $completeness)
    {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $type = (string) $request->query('type', 'destinations');
        if (! $this->completeness->isType($type)) {
            return redirect()->route('admin.data-completeness');
        }

        $check = $request->query('check');
        $check = is_string($check) && isset(DataCompleteness::CHECKS[$check]) ? $check : null;

        $failing = $this->completeness->failing($type);
        $summary = $this->completeness->summary($type, $failing);
        $shown = $check ? array_values(array_filter($failing, fn (array $row) => in_array($check, $row['miss'], true))) : $failing;

        $totals = [];
        foreach (DataCompleteness::TYPES as $key => $config) {
            $totals[$key] = $config['model']::query()->whereNull('archived_at')->count();
        }

        return view('admin.data-completeness', [
            'type' => $type,
            'types' => DataCompleteness::TYPES,
            'totals' => $totals,
            'checks' => DataCompleteness::CHECKS,
            'applicable' => $this->completeness->applicable($type),
            'summary' => $summary,
            'percent' => fn (string $c) => $this->completeness->percent($summary, $c),
            'check' => $check,
            'rows' => array_slice($shown, 0, self::LIST_LIMIT),
            'rowCount' => count($shown),
            'limit' => self::LIST_LIMIT,
        ]);
    }

    /** Close one gap on one listing; the page then redraws that row and the numbers from what comes back. */
    public function fix(Request $request, string $type, int $id): JsonResponse|RedirectResponse
    {
        abort_unless($this->completeness->isType($type), 404);

        $config = DataCompleteness::TYPES[$type];
        $listing = $config['model']::findOrFail($id);
        $check = $request->validate(['check' => ['required', Rule::in(['coords', 'hours', 'credit'])]])['check'];

        match ($check) {
            'coords' => $this->saveCoords($request, $listing),
            'hours' => $this->saveHours($request, $listing, $config),
            'credit' => $this->saveCredit($request, $config['kind'], $listing->getKey()),
        };

        $message = DataCompleteness::CHECKS[$check]['label'].' saved';
        $detail = "{$listing->name}";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'detail' => $detail,
                'summary' => $this->completeness->summary($type),
                'row' => ['id' => $listing->getKey(), 'miss' => $this->completeness->missFor($type, $listing->refresh())],
            ]);
        }

        return redirect()->route('admin.data-completeness', ['type' => $type])->with(Toast::success($message, $detail));
    }

    /** "7.0707, 125.6087" as copied from Google Maps; two numbers, in either separator, and inside the Davao Region. */
    private function saveCoords(Request $request, $listing): void
    {
        $raw = (string) $request->validate(['position' => ['required', 'string', 'max:60']])['position'];
        $parts = preg_split('/[\s,;]+/', trim(str_replace(['(', ')', '°'], '', $raw)), -1, PREG_SPLIT_NO_EMPTY);

        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            throw ValidationException::withMessages(['position' => 'Enter two numbers, like 7.0707, 125.6087.']);
        }

        [$lat, $lng] = [(float) $parts[0], (float) $parts[1]];
        [$latMin, $latMax] = DataCompleteness::LAT_RANGE;
        [$lngMin, $lngMax] = DataCompleteness::LNG_RANGE;

        if ($lat < $latMin || $lat > $latMax || $lng < $lngMin || $lng > $lngMax) {
            throw ValidationException::withMessages(['position' => 'That is outside the Davao Region. Latitude and longitude may be swapped.']);
        }

        $listing->update(['latitude' => round($lat, 6), 'longitude' => round($lng, 6)]);
    }

    private function saveHours(Request $request, $listing, array $config): void
    {
        if ($config['hours'] === null) {
            throw ValidationException::withMessages(['hours' => 'This kind of listing has no opening hours.']);
        }

        $hours = $request->validate(['hours' => ['required', 'string', 'max:255']])['hours'];
        $listing->update([$config['hours'] => trim($hours)]);
    }

    /** The photo credit goes on every photo of the listing that has none: the real photos, not the placeholder drawings. */
    private function saveCredit(Request $request, string $kind, int|string $id): void
    {
        $data = $request->validate([
            'source_name' => ['required', 'string', 'max:150'],
            'source_url' => ['nullable', 'url', 'max:500'],
        ], ['source_url.url' => 'Enter the full address, starting with http:// or https://.']);

        DB::table('listing_photos')
            ->where('listing_kind', $kind)->where('listing_id', $id)
            ->whereRaw("lower(path) not like '%.svg'")
            ->where(fn ($q) => $q->whereNull('source_name')->orWhere('source_name', ''))
            ->where(fn ($q) => $q->whereNull('source_url')->orWhere('source_url', ''))
            ->update(['source_name' => trim($data['source_name']), 'source_url' => $data['source_url'] ?? null]);
    }
}
