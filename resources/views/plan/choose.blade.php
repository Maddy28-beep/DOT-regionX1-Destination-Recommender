@extends('layouts.app')

@section('title', 'Plan Your Trip — ExploreDVO')

@section('content')

@php
    // One tick, reused for every "what you get" line.
    $tick = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>';
@endphp

<link rel="stylesheet" href="{{ asset('css/plan-choice.css') }}?v={{ filemtime(public_path('css/plan-choice.css')) }}">

<div class="dash-shell">
    <div class="dash-header">
        <div class="container">
            <div>
                <span class="poster-kicker" style="font-size:1.05rem;">let's get started</span>
                <h1 class="page-title" style="font-size:1.9rem; margin:0;">How would you like to plan your trip?</h1>
                <div class="sub">Two ways to go about it &mdash; pick whichever fits, you're not locked in.</div>
                {{-- Progress, not three look-alike pills: step 1 is filled, 2 and 3 are outlined and muted to show they are ahead. --}}
                <ol class="pc-stepper" aria-label="How planning works">
                    <li aria-current="step"><span class="pc-stepper__dot" aria-hidden="true">1</span><span class="pc-stepper__label"><span class="sr-only">Step 1, current: </span>Choose a path</span></li>
                    <li><span class="pc-stepper__dot" aria-hidden="true">2</span><span class="pc-stepper__label"><span class="sr-only">Step 2: </span>Tell us about your trip</span></li>
                    <li><span class="pc-stepper__dot" aria-hidden="true">3</span><span class="pc-stepper__label"><span class="sr-only">Step 3: </span>Get your plan</span></li>
                </ol>
            </div>
        </div>
    </div>

    <div class="dash-body">
        <div class="container">
            <div class="pcx-grid">

                <div class="pcx-card pcx-card--rec">
                    <span class="pcx-badge">Recommended</span>
                    <div class="feature-stamp feature-stamp--leaf">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path fill="currentColor" d="M5 3.6h14a1.6 1.6 0 0 1 1.6 1.6v14.4A1.6 1.6 0 0 1 19 21.2H5a1.6 1.6 0 0 1-1.6-1.6V5.2A1.6 1.6 0 0 1 5 3.6z"/>
                            <path style="fill:var(--stamp-ink)" d="M6.6 8.6h10.8v2.4H6.6zM6.6 13h6.4v2.4H6.6z"/>
                        </svg>
                    </div>
                    <h3>Personalized Itinerary</h3>
                    <p class="pcx-lead">
                        Answer a few questions about your budget, duration, interests and travel
                        style, and we'll build a custom day-by-day plan around your own preferences.
                    </p>
                    <ul class="pcx-meta">
                        <li>About 2 minutes</li>
                        <li>3 short steps</li>
                    </ul>
                    <ul class="pcx-get">
                        <li>{!! $tick !!} Places picked to fit your interests</li>
                        <li>{!! $tick !!} A route with travel times, plus a stay, meals and shops</li>
                        <li>{!! $tick !!} Change it and regenerate as often as you like</li>
                    </ul>
                    <p class="pcx-best"><strong>Best if</strong> you want a trip built just for you.</p>
                    <a href="{{ route('plan.edit') }}" class="btn btn-primary">Plan My Trip &rarr;</a>
                </div>

                <div class="pcx-card">
                    <div class="feature-stamp feature-stamp--gold">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path fill="currentColor" d="M12 2.2 14.4 4l3-.2 1 2.8 2.6 1.6-.9 2.9.9 2.9-2.6 1.6-1 2.8-3-.2L12 21.8 9.6 20l-3 .2-1-2.8L3 15.8l.9-2.9L3 10l2.6-1.6 1-2.8 3 .2z"/>
                            <path d="m8.4 12.1 2.5 2.5 4.8-5.2" fill="none" style="stroke:var(--stamp-ink)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h3>Tour Packages</h3>
                    <p class="pcx-lead">
                        Already know you want a guided trip? Browse ready-made, day-by-day
                        itineraries offered directly by tour operators.
                    </p>
                    <ul class="pcx-meta">
                        <li>Browse in seconds</li>
                        <li>Price per person shown</li>
                    </ul>
                    <ul class="pcx-get">
                        <li>{!! $tick !!} Trips run by tour operators, each marked as an official DOT record or sample data</li>
                        <li>{!! $tick !!} Inclusions and the day-by-day schedule up front</li>
                        <li>{!! $tick !!} Open any package to see who provides it</li>
                    </ul>
                    <p class="pcx-best"><strong>Best if</strong> you want a guided trip without planning it yourself.</p>
                    <a href="{{ route('packages.index') }}" class="btn btn-outline">Browse Tour Packages &rarr;</a>
                </div>
            </div>

            <section class="pcx-help" aria-labelledby="pcxHelpTitle">
                <h2 id="pcxHelpTitle">Not sure which to pick?</h2>
                <div class="pcx-help__options" data-pcx-options>
                    <button type="button" aria-pressed="false" data-answer="Go with a personalized itinerary: it adapts to your budget, time and interests.">I want it built around me</button>
                    <button type="button" aria-pressed="false" data-answer="Browse tour packages: the days are already planned and an accredited operator provides the trip.">I'd rather have a guide</button>
                    <button type="button" aria-pressed="false" data-answer="Start with a personalized itinerary, then look at the packages for ideas. You can switch anytime.">I'm just exploring</button>
                </div>
                <p class="pcx-help__answer" data-pcx-answer role="status" hidden></p>
            </section>

            <ul class="pcx-trust">
                <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>No account needed</li>
                <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 5 6v6c0 4.4 3 7.6 7 9 4-1.4 7-4.6 7-9V6z"/><path d="m9 12 2 2 4-4"/></svg>DOT-accredited places are marked; sample data is labelled</li>
                <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>Your answers stay anonymous</li>
            </ul>
        </div>
    </div>
</div>

<script>
    (function () {
        var box = document.querySelector('[data-pcx-options]');
        var answer = document.querySelector('[data-pcx-answer]');
        if (!box || !answer) return;

        box.addEventListener('click', function (e) {
            var button = e.target.closest('button[data-answer]');
            if (!button) return;

            box.querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-pressed', b === button ? 'true' : 'false'); });
            answer.textContent = button.dataset.answer;
            answer.hidden = false;
        });
    })();
</script>

@endsection
