@extends('layouts.establishment')

@section('title', 'Insights — Partner Dashboard')
@section('page-title', 'Insights')
@section('page-sub', 'How travellers are finding and visiting '.$listing->name)

@section('page-actions')
    <nav class="adm-seg" aria-label="Time range">
        @foreach (\App\Services\Establishment\ListingInsights::RANGES as $days)
            <a href="{{ route('establishment.insights', ['range' => $days]) }}" class="{{ $range === $days ? 'on' : '' }}" @if ($range === $days) aria-current="true" @endif>{{ $days }} days</a>
        @endforeach
    </nav>
@endsection

@section('content')

@php
    $insights = \App\Services\Establishment\ListingInsights::class;
    $max = max(1, collect($series)->max('value'));
    $busiest = collect($series)->sortByDesc('value')->first();
    $visitChange = $insights::change($checkIns['total'], $checkIns['before']);
    $planChange = $tripPlans ? $insights::change($tripPlans['total'], $tripPlans['before']) : null;
@endphp

<div class="adm-kpis ptr-kpis" style="grid-template-columns: repeat({{ $tripPlans ? 2 : 1 }}, minmax(0, 1fr));">
    <div class="adm-kpi">
        <span class="adm-kpi__ic"><x-icon name="locate" /></span>
        <div>
            <div class="adm-kpi__val">{{ number_format($checkIns['total']) }}</div>
            <div class="adm-kpi__lab">QR check-ins, last {{ $range }} days</div>
            @if ($visitChange !== null)<div class="adm-kpi__flag ptr-flag--{{ $visitChange >= 0 ? 'up' : 'down' }}">{{ $visitChange >= 0 ? '↑' : '↓' }} {{ abs($visitChange) }}% on the {{ $range }} days before</div>@endif
        </div>
    </div>
    @if ($tripPlans)
        <div class="adm-kpi">
            <span class="adm-kpi__ic"><x-icon name="route" /></span>
            <div>
                <div class="adm-kpi__val">{{ number_format($tripPlans['total']) }}</div>
                <div class="adm-kpi__lab">trip plans included you</div>
                @if ($planChange !== null)<div class="adm-kpi__flag ptr-flag--{{ $planChange >= 0 ? 'up' : 'down' }}">{{ $planChange >= 0 ? '↑' : '↓' }} {{ abs($planChange) }}% on the {{ $range }} days before</div>@endif
            </div>
        </div>
    @endif
</div>

<div class="adm-cols ptr-cols2">
    <div class="adm-card">
        <div class="adm-card__head"><div><h2>QR check-ins</h2><p>Verified visits, by {{ $range === 90 ? 'week' : 'day' }}</p></div></div>
        <div class="adm-card__body ptr-pad">
            @if ($checkIns['total'] === 0)
                <p class="ptr-empty">No check-ins yet. Print your QR code from My Listing and put it where guests will see it.</p>
            @else
                <div class="ptr-chart" role="img" aria-label="Bar chart of {{ $checkIns['total'] }} check-ins over the last {{ $range }} days. Busiest: {{ $busiest['title'] }} with {{ $busiest['value'] }}.">
                    @foreach ($series as $bar)
                        <div><i style="height: {{ max(4, round($bar['value'] / $max * 80)) }}%" title="{{ $bar['title'] }}: {{ $bar['value'] }} {{ \Illuminate\Support\Str::plural('check-in', $bar['value']) }}"></i><small>{{ $bar['label'] }}</small></div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="adm-stack">
        @if ($tripPlans)
            <div class="adm-card">
                <div class="adm-card__head"><div><h2>Trip plans</h2><p>The day of the trip your place first appeared</p></div></div>
                <div class="adm-card__body">
                    <div class="ptr-pair"><span>Day 1</span><b>{{ $tripPlans['byDay'][1] }}</b></div>
                    <div class="ptr-pair"><span>Day 2</span><b>{{ $tripPlans['byDay'][2] }}</b></div>
                    <div class="ptr-pair"><span>Day 3 or later</span><b>{{ $tripPlans['byDay'][3] }}</b></div>
                </div>
            </div>
        @endif
        <div class="adm-card">
            <div class="adm-card__head"><div><h2>How these are counted</h2></div></div>
            <div class="adm-card__body ptr-pad">
                <p class="ptr-note" style="margin:0;">A check-in is one traveller scanning your QR code on one day. A trip plan counts once, however many times your place appears in it. Both are compared with the {{ $range }} days before.</p>
            </div>
        </div>
    </div>
</div>

@endsection
