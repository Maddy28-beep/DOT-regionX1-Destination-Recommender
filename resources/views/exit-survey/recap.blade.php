@extends('layouts.app')

@section('title', 'Trip Recap — ExploreDVO')

@section('content')
<div class="page-head">
    <div class="container">
        <span class="poster-kicker">🎉 thank you for exploring davao!</span>
        <h1 class="poster-title">Your anonymous feedback has been submitted successfully.</h1>
        <p>Here's a recap of your ExploreDVO experience.</p>
    </div>
</div>

<div class="section-tight">
    <div class="container" style="max-width:820px;">

        {{--
            The trip card: the shareable summary. Kept to place names, the
            count and the day figure only -- never the survey's own answers,
            the visitor token, or anything from a tourist account, even for a
            logged-in tourist.
        --}}
        <div class="panel" id="tripCard">
            <div class="panel-body" style="text-align:center;">
                <p class="poster-kicker" style="margin-bottom:4px;">ExploreDVO</p>
                <h2 style="margin:0 0 14px;">My Davao Trip</h2>
                <div style="display:flex; justify-content:center; gap:28px; flex-wrap:wrap; margin-bottom:18px;">
                    <div>
                        <div class="stat-card-val" style="font-size:1.6rem;">📍 {{ $visited->count() }}</div>
                        <div class="stat-card-label">Place{{ $visited->count() === 1 ? '' : 's' }}</div>
                    </div>
                    @if ($daysStayed)
                        <div>
                            <div class="stat-card-val" style="font-size:1.6rem;">🗓️ {{ $daysStayed }}</div>
                            <div class="stat-card-label">Day{{ $daysStayed === 1 ? '' : 's' }}</div>
                        </div>
                    @endif
                </div>

                @if ($visited->isNotEmpty())
                    <p class="sub" style="margin-bottom:16px;">
                        I explored: {{ $visited->pluck('name')->join(', ', ' and ') }}
                    </p>
                @endif

                <button type="button" class="btn btn-outline" id="shareTripCardBtn">Share Trip Card</button>
            </div>
        </div>

        @if ($visited->isNotEmpty())
            <div class="section-head" style="margin-top:34px;">
                <div><h2 class="poster-title" style="color:var(--ocean-teal-dark);">Places You Visited</h2></div>
            </div>
            <div class="dpost-grid" style="margin-bottom:34px;">
                @foreach ($visited as $listing)
                    @include('partials.listing-poster-card', ['listing' => $listing])
                @endforeach
            </div>
        @else
            <div class="empty-state" style="margin-top:34px;">
                <p>Thanks for your feedback! Explore more of what Davao Region has to offer any time.</p>
            </div>
        @endif

        @if ($missed->isNotEmpty())
            <div class="section-head">
                <div>
                    <h2 class="poster-title" style="color:var(--ocean-teal-dark);">You Might Have Missed</h2>
                    <p>
                        @if ($personalized)
                            Based on your trip, here are a few more places you might enjoy on your next visit.
                        @else
                            A few popular places you might enjoy exploring next time.
                        @endif
                    </p>
                </div>
            </div>
            <div class="dpost-grid" style="margin-bottom:34px;">
                @foreach ($missed as $destination)
                    @include('partials.listing-poster-card', ['listing' => $destination])
                @endforeach
            </div>
        @endif

        <div class="empty-state__actions" style="justify-content:center;">
            <a href="{{ route('destinations.index') }}" class="btn btn-poster-primary">Explore More Destinations</a>
            <a href="{{ route('home') }}" class="btn btn-poster-ghost">Back to Home</a>
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
