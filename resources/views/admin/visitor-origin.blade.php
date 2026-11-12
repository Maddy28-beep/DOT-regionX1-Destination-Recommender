@extends('layouts.admin')

@section('title', 'Visitor origin — DOT Admin')
@section('page-title', 'Visitor origin')
@section('page-sub', 'Where visitors say they travel from, counts only')

@section('content')
<div class="adm-origin" data-visitor-origin>
    <form method="get" class="adm-toolbar" aria-label="Date range">
        <label>From <input type="date" name="from" value="{{ $from }}"></label>
        <label>To <input type="date" name="to" value="{{ $to }}"></label>
        <button class="adm-btn" type="submit">Apply</button>
        <span class="adm-toolbar__note">Dates are in Philippine time; "To" includes the whole day.</span>
    </form>

    <p class="adm-origin__note" data-origin-note>
        Counts are per browser session; a new submission replaces the earlier one.
        Groups smaller than {{ $min }} are never shown on their own: they are added together under "Other".
        Individual answers are kept for 30 days only, so this page covers the last 30 days at most.
    </p>

    @foreach ([['Domestic or international', $split], ['By country', $countries], ['Philippine visitors by province', $provinces]] as [$title, $t])
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
