@extends('layouts.admin')

@section('title', 'Visitor origin — DOT Admin')
@section('page-title', 'Visitor origin')
@section('page-sub', 'Where visitors say they travel from, anonymous counts only')

@section('content')
<div class="adm-origin" data-visitor-origin>
    <form method="get" class="adm-toolbar" aria-label="Date range">
        <label>From <input type="date" name="from" value="{{ $from }}"></label>
        <label>To <input type="date" name="to" value="{{ $to }}"></label>
        <button class="btn btn-primary btn-sm" type="submit">Apply</button>
        <a class="btn btn-outline btn-sm" data-origin-export href="{{ route('admin.visitor-origin.export', array_filter(['from' => $from, 'to' => $to])) }}">Download CSV</a>
        <span class="adm-toolbar__note">Dates are in Philippine time; "To" includes the whole day. The day is the day the visitor first planned a trip.</span>
    </form>

    <p class="adm-origin__note" data-origin-note>
        These counts are kept permanently and are anonymous: only the day, the country, the province (Philippines) and the city are stored, with a number.
        No name, browser, IP address, time of day, trip or pick-up place is kept with them, and they cannot be traced back to a person or a trip.
        <strong>Counting rule:</strong> one count per browser session; a changed answer replaces the earlier one. The same person using two browsers or devices counts twice,
        and someone who comes back after the trip details were deleted (30 days) counts as a new visit.
        Groups smaller than {{ $min }} are never shown on their own: they are added together under "Other". The CSV has the same totals, not one row per day.
    </p>

    @foreach ($tables as $title => $t)
        <section class="adm-card" aria-label="{{ $title }}">
            <div class="adm-card__head"><div><h2>{{ $title }}</h2></div></div>
            <div class="adm-card__body">
                @if (! $t['enough'])
                    <p class="adm-origin__empty" data-origin-empty>
                        Not enough answers yet: {{ $t['total'] }} {{ \Illuminate\Support\Str::plural('answer', $t['total']) }} so far; at least {{ $min }} are needed.
                    </p>
                @else
                    <table class="adm-table">
                        <thead><tr><th scope="col">Place</th><th scope="col">Visitors</th></tr></thead>
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
@endsection
