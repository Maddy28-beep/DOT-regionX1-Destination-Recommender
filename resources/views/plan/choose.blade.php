@extends('layouts.app')

@section('title', 'Plan Your Trip — ExploreDVO')

@section('content')

<div class="dash-shell">
    <div class="dash-header">
        <div class="container">
            <div>
                <span class="poster-kicker" style="font-size:1.05rem;">let's get started</span>
                <h1 class="page-title" style="font-size:1.9rem; margin:0;">How would you like to plan your trip?</h1>
                <div class="sub">Two ways to go about it &mdash; pick whichever fits, you're not locked in.</div>
            </div>
        </div>
    </div>

    <div class="dash-body">
        <div class="container">
            <div class="plan-choice-grid">
                <div class="feature-card plan-choice-card">
                    <div class="feature-stamp feature-stamp--leaf">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path fill="currentColor" d="M5 3.6h14a1.6 1.6 0 0 1 1.6 1.6v14.4A1.6 1.6 0 0 1 19 21.2H5a1.6 1.6 0 0 1-1.6-1.6V5.2A1.6 1.6 0 0 1 5 3.6z"/>
                            <path style="fill:var(--stamp-ink)" d="M6.6 8.6h10.8v2.4H6.6zM6.6 13h6.4v2.4H6.6z"/>
                        </svg>
                    </div>
                    <h3>Personalized Itinerary</h3>
                    <p>
                        Answer a few questions about your budget, duration, interests and travel
                        style, and we'll build a custom day-by-day plan around your own preferences.
                    </p>
                    <a href="{{ route('plan.edit') }}" class="btn btn-primary">Plan My Trip</a>
                </div>

                <div class="feature-card plan-choice-card">
                    <div class="feature-stamp feature-stamp--gold">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path fill="currentColor" d="M4 9.4h16a1 1 0 0 1 1 1V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-8.6a1 1 0 0 1 1-1z"/>
                            <path style="fill:var(--stamp-ink)" d="M9 9.4V7a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2.4h-2V7.4h-2v2z"/>
                        </svg>
                    </div>
                    <h3>Tour Packages</h3>
                    <p>
                        Already know you want a guided trip? Browse ready-made, day-by-day
                        itineraries offered directly by DOT-accredited tour operators.
                    </p>
                    <a href="{{ route('packages.index') }}" class="btn btn-outline">Browse Tour Packages</a>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection
