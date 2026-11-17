@extends('layouts.app')

@section('title', 'Trip Planner — ExploreDVO')

@section('content')

@php
    // One tick, reused for every "what you get" line.
    $tick = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>';
@endphp

<link rel="stylesheet" href="{{ asset('css/plan-choice.css') }}?v={{ filemtime(public_path('css/plan-choice.css')) }}">

<div class="dash-shell plan-choice">
    <div class="dash-header">
        <div class="container">
            <div>
                <h1 class="page-title">How would you like to plan your trip?</h1>
                <div class="sub">Choose the way you’d like to explore Davao.</div>
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

                <div class="pcx-card">
                    <div class="pcx-card__head">
                    <div class="pcx-icon"><x-icon name="map" aria-hidden="true" /></div>
                    <div>
                    <h3>Personalized Itinerary</h3>
                    <p class="pcx-lead">
                        Tell us your interests, budget, and travel style. Build a day-by-day plan around you.
                    </p>
                    </div>
                    </div>
                    <ul class="pcx-meta">
                        <li>About 2 minutes</li>
                        <li>4 planning steps</li>
                    </ul>
                    <ul class="pcx-get">
                        <li>{!! $tick !!} Places matched to your interests</li>
                        <li>{!! $tick !!} Travel times, stays, meals &amp; shops</li>
                        <li>{!! $tick !!} Edit and regenerate your plan</li>
                    </ul>
                    <p class="pcx-best">For travelers who want a flexible, personalized plan.</p>
                    <a href="{{ route('plan.edit') }}" class="btn btn-primary">Plan My Trip &rarr;</a>
                </div>

                <div class="pcx-card">
                    <div class="pcx-card__head">
                    <div class="pcx-icon"><x-icon name="ticket" aria-hidden="true" /></div>
                    <div>
                    <h3>Tour Packages</h3>
                    <p class="pcx-lead">
                        Explore ready-made itineraries from local tour operators and find a trip that fits.
                    </p>
                    </div>
                    </div>
                    <ul class="pcx-meta">
                        <li>Ready-made itineraries</li>
                        <li>Price per person shown</li>
                    </ul>
                    <ul class="pcx-get">
                        <li>{!! $tick !!} See the operator behind each trip</li>
                        <li>{!! $tick !!} View inclusions and day-by-day schedules</li>
                        <li>{!! $tick !!} Accreditation and sample-data labels shown</li>
                    </ul>
                    <p class="pcx-best">For travelers who prefer an operator-led trip.</p>
                    <a href="{{ route('packages.index') }}" class="btn btn-primary">Browse Tour Packages &rarr;</a>
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
