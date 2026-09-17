@extends('layouts.app')

@section('title', 'Travel Advisories — ExploreDVO')

@section('content')

@php
    $badges = [
        'danger' => ['label' => 'Critical', 'icon' => 'alert-triangle'],
        'warning' => ['label' => 'Advisory', 'icon' => 'alert-triangle'],
        'info' => ['label' => 'Notice', 'icon' => 'megaphone'],
    ];
@endphp

<div class="page-head">
    <div class="container">
        <span class="poster-kicker">stay informed</span>
        <h1 class="poster-title">Travel Advisories</h1>
        <p>Current weather, safety, and travel notices for the Davao Region, issued by DOT Region XI.</p>
    </div>
</div>

<div class="section-tight">
    <div class="container">

        <div class="chip-row chip-row--poster">
            <a href="{{ route('advisories.index') }}" class="chip {{ $activeSeverity ? '' : 'active' }}">All Advisories</a>
            @foreach ($badges as $key => $meta)
                <a href="{{ route('advisories.index', ['severity' => $key]) }}" class="chip {{ $activeSeverity === $key ? 'active' : '' }}">
                    {{ $meta['label'] }}@if ($countsBySeverity->get($key)) ({{ $countsBySeverity->get($key) }})@endif
                </a>
            @endforeach
        </div>

        @if ($advisories->isEmpty())
            <div class="empty-panel">
                <div class="icon"><x-icon name="shield-check" /></div>
                <h3>{{ $activeSeverity ? 'No advisories at this level' : 'No active advisories' }}</h3>
                <p>
                    @if ($activeSeverity)
                        There's nothing posted at this severity right now &mdash; <a href="{{ route('advisories.index') }}">view all advisories</a>.
                    @else
                        Nothing currently posted for the Davao Region. Check back before your trip.
                    @endif
                </p>
            </div>
        @else
            <div class="advisory-list">
                @foreach ($advisories as $advisory)
                    @php $badge = $badges[$advisory->severity] ?? $badges['warning']; @endphp
                    <div class="advisory-list-card advisory-list-card--{{ $advisory->severity }}" id="advisory-{{ $advisory->id }}">
                        <div class="advisory-list-card__head">
                            <span class="advisory-list-card__badge">
                                <x-icon :name="$badge['icon']" />
                                {{ $badge['label'] }}
                            </span>
                            <span class="advisory-list-card__updated">Updated {{ $advisory->updated_at->format('M j, Y \\a\\t g:i A') }}</span>
                        </div>

                        <h3 class="advisory-list-card__title">{{ $advisory->title }}</h3>
                        <p class="advisory-list-card__message">{{ $advisory->message }}</p>

                        <div class="advisory-list-card__meta">
                            <span><strong>Applies to:</strong> {{ $advisory->listing->name ?? 'All of ExploreDVO' }}</span>
                            <span><strong>Issued by:</strong> DOT Region XI</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

@endsection
