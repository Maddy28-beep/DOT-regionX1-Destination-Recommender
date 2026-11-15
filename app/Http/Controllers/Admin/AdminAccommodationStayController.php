<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExitSurvey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAccommodationStayController extends Controller
{
    private function report(Request $request): array
    {
        $input = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'status' => ['nullable', 'in:all,unverified,directory_match'],
        ]);
        $filters = ['from' => $input['from'] ?? '', 'to' => $input['to'] ?? '', 'status' => $input['status'] ?? 'unverified'];
        // Never include test, demo or itinerary-import rows in this follow-up report.
        $surveys = ExitSurvey::query()->where('data_source', 'real')
            ->when($filters['from'], fn ($q, $date) => $q->whereDate('submitted_at', '>=', $date))
            ->when($filters['to'], fn ($q, $date) => $q->whereDate('submitted_at', '<=', $date));
        $base = DB::table('exit_survey_stays as stays')
            ->joinSub((clone $surveys)->select('id', 'submitted_at'), 'surveys', 'surveys.id', '=', 'stays.exit_survey_id');
        $total = (clone $surveys)->count();
        $answered = (clone $base)->distinct()->count('stays.exit_survey_id');
        $unverified = (clone $base)->where('directory_status', 'unverified')->distinct()->count('stays.exit_survey_id');
        $private = (clone $base)->where('reported_kind', 'friends_family')->distinct()->count('stays.exit_survey_id');
        $dayTrips = (clone $base)->where('reported_kind', 'day_trip')->distinct()->count('stays.exit_survey_id');
        $groups = (clone $base)->whereIn('reported_kind', ['directory', 'other'])
            ->when($filters['status'] !== 'all', fn ($q) => $q->where('directory_status', $filters['status']))
            ->selectRaw('stays.accommodation_id, stays.directory_status, MIN(stays.place_name) as place_name, MIN(stays.locality) as locality, COUNT(DISTINCT stays.exit_survey_id) as responses, MIN(surveys.submitted_at) as first_reported, MAX(surveys.submitted_at) as last_reported')
            ->groupBy('stays.accommodation_id', 'stays.directory_status')
            ->groupByRaw('LOWER(stays.place_name), LOWER(stays.locality)')
            ->orderByDesc('responses')->orderBy('place_name')->orderBy('locality');
        return compact('filters', 'total', 'answered', 'unverified', 'private', 'dayTrips', 'groups');
    }

    public function index(Request $request)
    {
        $data = $this->report($request);
        $data['places'] = $data['groups']->paginate(25)->withQueryString();
        unset($data['groups']);
        return view('admin.accommodation-stays', $data);
    }

    public function export(Request $request)
    {
        $data = $this->report($request);
        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            $safe = fn ($value) => preg_match('/^[\s]*[=+@-]/u', (string) $value) ? "'".$value : $value;
            $write = fn ($row) => fputcsv($out, array_map($safe, $row), ',', '"', '');
            $write(['Accommodation stays reported by real exit-survey respondents']);
            $write(['From', $data['filters']['from'], 'To', $data['filters']['to'], 'Status', $data['filters']['status']]);
            $write(['Manual entries are unverified leads, not confirmed unaccredited businesses. Counts are survey responses, not unique tourists.']);
            $write(['Name as reported', 'City / area as reported', 'Directory status at submission', 'Survey responses', 'First reported', 'Last reported']);
            foreach ($data['groups']->cursor() as $place) {
                $write([$place->place_name, $place->locality, $place->directory_status === 'unverified' ? 'Unverified — manual entry' : 'Directory match — verify current accreditation', $place->responses, $place->first_reported, $place->last_reported]);
            }
            fclose($out);
        }, 'reported-accommodation-stays.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
