@extends('layouts.admin')

@section('title', 'Visitor origin — DOT Admin')
@section('page-title', 'Visitor origin')
@section('page-sub', 'Where visitors say they travel from, counts only')

@section('content')
<div class="adm-fit" data-visitor-origin>
    <form method="get" class="adm-toolbar" aria-label="Date range">
        <label>From <input type="date" name="from" value="{{ $from }}"></label>
        <label>To <input type="date" name="to" value="{{ $to }}"></label>
        <button class="adm-btn" type="submit">Apply</button>
        <span class="adm-toolbar__note">Groups smaller than {{ $min }} are never shown on their own. They are added together under "Other". Individual answers are kept for 30 days only, so this page covers the last 30 days at most.</span>
    </form>

    @foreach ([['Domestic or international', $split], ['By country', $countries], ['Philippine visitors by province', $provinces]] as [$title, $t])
        <section class="adm-card" aria-label="{{ $title }}">
            <div class="adm-card__head"><div><h2>{{ $title }}</h2></div></div>
            <div class="adm-card__body">
                @if (! $t['enough'])
                    <p class="adm-empty">Not enough answers yet to show this without identifying a small group (at least {{ $min }} needed).</p>
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
