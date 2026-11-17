@extends('layouts.admin')

@section('title', 'Visitor origin — DOT Admin')
@section('page-title', 'Visitor origin')
@section('page-sub', 'Where visitors say they travel from, anonymous counts only')

@section('content')
<div class="adm-origin" data-visitor-origin>
    <form method="get" class="adm-toolbar" aria-label="Date range">
        <label>Measure <select name="metric">
            <option value="responses" @selected($metric === 'responses')>Confirmed origins</option>
            <option value="generated" @selected($metric === 'generated')>Origins with a generated itinerary</option>
        </select></label>
        <label>From <input type="date" name="from" value="{{ $from }}"></label>
        <label>To <input type="date" name="to" value="{{ $to }}"></label>
        <button class="btn btn-primary btn-sm" type="submit"><x-icon name="filter" />Apply filters</button>
        @if ($from || $to)<a class="adm-icon-btn" href="{{ route('admin.visitor-origin', ['metric' => $metric]) }}" title="Clear date filters" aria-label="Clear date filters"><x-icon name="x" /></a>@endif
        <a class="btn btn-outline btn-sm" data-origin-export href="{{ route('admin.visitor-origin.export', array_filter(['from' => $from, 'to' => $to, 'metric' => $metric])) }}"><x-icon name="arrow-up-right" />Export CSV</a>
        <span class="adm-toolbar__note">Dates are in Philippine time; "To" includes the whole day. New responses use their confirmation day.</span>
    </form>

    <div class="adm-origin-summary"><strong>{{ $tables['By country']['total'] }}</strong><span>{{ $metric === 'generated' ? 'origin responses with a generated itinerary' : 'confirmed origin responses' }} in this period</span><small>Groups need {{ $min }} responses to appear separately.</small></div>
    <details class="adm-origin-method"><summary>Counting and privacy</summary>
    <p class="adm-origin__note" data-origin-note>
        These counts are kept permanently and are anonymous: only the day, the country, the province (Philippines) and the city are stored, with a number.
        No name, browser identifier, IP address, time of day, trip or pick-up place is kept in the permanent totals.
        <strong>Counting rule:</strong> new responses count once per browser per Philippine day, even without a generated trip. A changed answer replaces that day's earlier answer.
        A protected daily browser identifier is retained separately for up to 30 days to prevent repeats. Different browsers, devices or cleared cookies may still count separately.
        Responses with a generated itinerary are tracked separately from this update onward, once per browser per day; earlier completions were not backfilled. Historical origin totals retain the former session counting rule.
        Groups smaller than {{ $min }} are never shown on their own: they are added together under "Other". The CSV has the same totals, not one row per day.
    </p>
    </details>
    <div class="adm-origin-grid">
    @foreach ($tables as $title => $t)
        <section class="adm-card" aria-label="{{ $title }}">
            <div class="adm-card__head"><div><h2>{{ $title }}</h2><p>{{ match ($title) { 'Domestic or international' => 'Philippine residents and residents of other countries', 'By country' => 'Country of residence selected by the visitor', 'Philippine visitors by province' => 'Province selected by Philippine residents', default => 'Optional city or municipality entered by the visitor' } }}</p></div></div>
            <div class="adm-card__body">
                @if (! $t['enough'])
                    <p class="adm-origin__empty" data-origin-empty>
                        <span>{{ $t['total'] }} recorded</span><span>Waiting for at least {{ $min }} responses</span>
                    </p>
                @else
                    <table class="adm-table">
                        <thead><tr><th scope="col">Place</th><th scope="col">Responses</th></tr></thead>
                        <tbody>
                            @foreach ($t['rows'] as $row)
                                <tr><td>{{ $row['label'] }}</td><td>{{ $row['count'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </section>
    @endforeach
    </div>
</div>
@endsection
