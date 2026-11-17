@extends('layouts.app')

@section('title', 'Trip Recap — ExploreDVO')

@section('content')
<link rel="stylesheet" href="{{ asset('css/survey-recap.css') }}?v={{ filemtime(public_path('css/survey-recap.css')) }}">
<div class="survey-recap">
    <div class="container recap-container">
        <section class="recap-thanks" aria-labelledby="recapTitle">
            <img class="recap-thanks__davo" src="{{ asset('images/davo-wave-green.webp') }}" alt="Davo waving thank you" width="140" height="170">
            <div class="recap-thanks__copy">
                <span class="recap-success"><x-icon name="check" /> Feedback received</span>
                <h1 class="poster-title" id="recapTitle">Thank you for sharing!</h1>
                <p>Your anonymous response helps improve tourism in Davao.</p>
                <p class="recap-privacy"><x-icon name="shield-check" /> No name or account details linked.</p>
            </div>
            @if ($visited->isEmpty())
                <p class="recap-empty-note">No places added this time — your feedback still makes a difference.</p>
            @endif
        </section>

        {{-- Share only reported place names, their count and optional days; never private survey answers. --}}
        @if ($visited->isNotEmpty())
            <section class="recap-trip" id="tripCard" aria-labelledby="tripCardTitle">
                <div class="recap-trip__head">
                    <h2 id="tripCardTitle">My Davao Trip</h2>
                    <button type="button" class="btn btn-outline" id="shareTripCardBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 10.5 6.8-4M8.6 13.5l6.8 4"/></svg> Share Trip Card</button>
                </div>
                <div class="recap-trip__stats">
                    <span><x-icon name="map-pin" /><strong>{{ $visited->count() }}</strong> {{ Str::plural('Place', $visited->count()) }}</span>
                    @if ($daysStayed)
                        <span><x-icon name="calendar" /><strong>{{ $daysStayed }}</strong> {{ Str::plural('Day', $daysStayed) }}</span>
                    @endif
                </div>
                <p class="recap-trip__names">I explored: {{ $visited->pluck('name')->join(', ', ' and ') }}</p>
            </section>
            <section class="recap-section" aria-labelledby="visitedTitle">
                <div class="recap-section__head"><h2 class="poster-title" id="visitedTitle">Places You Visited</h2></div>
                <div class="recap-grid">
                    @foreach ($visited as $listing)
                        @include('partials.recap-listing-card', ['listing' => $listing])
                    @endforeach
                </div>
            </section>
        @elseif ($daysStayed)
            <p class="recap-days"><x-icon name="calendar" /> {{ $daysStayed }} {{ Str::plural('day', $daysStayed) }} stayed</p>
        @endif

        @if ($missed->isNotEmpty())
            <section class="recap-section" aria-labelledby="nextTimeTitle">
                <div class="recap-section__head">
                    <h2 class="poster-title" id="nextTimeTitle">Explore next time</h2>
                    <p>@if ($personalized) Based on your trip, here are a few more places you might enjoy on your next visit.
                    @else A few popular places you might enjoy exploring next time. @endif</p>
                </div>
                <div class="recap-grid">
                    @foreach ($missed as $destination)
                        @include('partials.recap-listing-card', ['listing' => $destination])
                    @endforeach
                </div>
            </section>
        @endif

        <div class="recap-actions">
            <a href="{{ route('destinations.index') }}" class="btn btn-primary">Explore more destinations <x-icon name="arrow-right" /></a>
            <a href="{{ route('home') }}" class="btn btn-outline">Back to home</a>
        </div>
    </div>
</div>

<script>
    (function () {
        var btn = document.getElementById('shareTripCardBtn');
        if (!btn) return;

        var text = {{ Illuminate\Support\Js::from(
            'My Davao Trip via ExploreDVO — '
            .$visited->count().' place'.($visited->count() === 1 ? '' : 's')
            .($daysStayed ? ', '.$daysStayed.' day'.($daysStayed === 1 ? '' : 's') : '')
            .($visited->isNotEmpty() ? '. I explored: '.$visited->pluck('name')->join(', ', ' and ').'.' : '.')
        ) }};

        function promptToCopy() {
            // Last-resort fallback for a browser that offers neither Web
            // Share nor clipboard-write permission -- select-and-copy still
            // works almost everywhere. Wrapped defensively: an automated or
            // otherwise locked-down browser can disable prompt() outright,
            // and that must not surface as an unhandled error either.
            try {
                window.prompt('Copy your trip summary:', text);
            } catch (e) {}
        }

        btn.addEventListener('click', function () {
            if (navigator.share) {
                navigator.share({ title: 'My Davao Trip', text: text }).catch(function () {});
                return;
            }

            if (!navigator.clipboard) {
                promptToCopy();
                return;
            }

            navigator.clipboard.writeText(text).then(function () {
                btn.textContent = 'Copied!';
                setTimeout(function () { btn.textContent = 'Share Trip Card'; }, 2000);
            }).catch(promptToCopy);
        });
    })();
</script>
@endsection
