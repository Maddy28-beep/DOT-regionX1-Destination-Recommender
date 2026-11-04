@extends('layouts.admin')

@section('title', 'Feedback Analysis — DOT Admin')
@section('page-title', 'Feedback Analysis')
@section('page-sub', 'Overall, service and amenities ratings from verified visitors, with statistics')

@section('content')

<div class="panel">
    <div class="panel-body">
        <form method="GET" class="filter-inline">
            <div class="field">
                <label for="from">From date</label>
                <input type="date" id="from" name="from" value="{{ $from }}">
            </div>
            <div class="field">
                <label for="to">To date</label>
                <input type="date" id="to" name="to" value="{{ $to }}">
            </div>
            <div class="field">
                <label for="establishment">Establishment</label>
                <select id="establishment" name="establishment">
                    <option value="">All establishments</option>
                    @foreach ($establishmentOptions as $o)
                        <option value="{{ $o['key'] }}" @selected($selectedKey === $o['key'])>{{ $o['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><button type="submit" class="btn btn-primary">Apply</button></div>
        </form>
    </div>
</div>

<div class="adm-card">
    <div class="adm-card__head">
        <div>
            <h2>{{ $selectedName ?? 'All establishments' }}</h2>
            <p>{{ $stats['review_count'] }} {{ \Illuminate\Support\Str::plural('review', $stats['review_count']) }} between {{ $from }} and {{ $to }}. Service and Amenities are optional for the reviewer, so they can have fewer ratings than the overall score.</p>
        </div>
    </div>
    <div class="adm-card__body">
        @if ($stats['review_count'] === 0)
            <p class="ptr-empty">No ratings yet in this period.</p>
        @else
            @include('partials.feedback-stats', ['stats' => $stats])
        @endif
    </div>
</div>

@if (! $selectedKey && count($perEstablishment))
    <div class="adm-card">
        <div class="adm-card__head"><div><h2>By establishment</h2><p>Means of the ratings left in the period. &ldquo;&mdash;&rdquo; means nobody rated that aspect.</p></div></div>
        <div class="adm-card__body" style="overflow-x:auto;">
            <table class="data-table adm-table">
                <thead>
                    <tr><th>Establishment</th><th>Reviews</th><th>Overall</th><th>Service</th><th>Amenities</th><th>Quality index</th></tr>
                </thead>
                <tbody>
                    @foreach ($perEstablishment as $row)
                        @php $a = $row['stats']['aspects']; @endphp
                        <tr>
                            <td><a href="{{ route('admin.feedback-analysis', ['from' => $from, 'to' => $to, 'establishment' => $row['key']]) }}"><strong>{{ $row['name'] }}</strong></a><div class="cell-sub">{{ $row['kind'] }}</div></td>
                            <td>{{ $row['stats']['review_count'] }}</td>
                            @foreach (['overall', 'service', 'amenities'] as $k)
                                <td>{{ $a[$k]['mean'] === null ? '—' : number_format($a[$k]['mean'], 2).' (n='.$a[$k]['n'].')' }}</td>
                            @endforeach
                            <td>{{ $row['stats']['index']['score'] === null ? 'Not enough reviews (fewer than 5)' : number_format($row['stats']['index']['score'], 1).'/100' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@endsection
