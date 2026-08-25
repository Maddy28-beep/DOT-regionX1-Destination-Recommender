@extends('layouts.app')

@section('title', 'My Itinerary — ExploreDVO')

@section('content')
@php
    $itemsByDay = $itinerary->items->sortBy('id')->groupBy('day_number');
    $topMatches = $itinerary->matches->sortBy('rank')->take(5);
@endphp

<div class="dash-shell">
    <div class="dash-header">
        <div class="container">
            <div>
                <h1>My Itinerary</h1>
                <div class="sub">Generated {{ $itinerary->generated_at->format('F j, Y g:i A') }} &middot; {{ $itinerary->total_days }} day{{ $itinerary->total_days === 1 ? '' : 's' }}</div>
            </div>
            <a href="{{ route('tourist.dashboard') }}" class="btn btn-outline">Back to My Trip</a>
        </div>
    </div>

    <div class="dash-body">
        <div class="container">
            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Recommended Destinations</h2>
                        <p>Ranked by Destination Recommendation Score, based on your travel preferences (Content-Based Recommendation).</p>
                    </div>
                    <form method="POST" action="{{ route('tourist.itinerary.regenerate') }}" id="regenerate-form">
                        @csrf
                        <input type="hidden" name="lat" id="regenerate-lat">
                        <input type="hidden" name="lng" id="regenerate-lng">
                        <button type="submit" class="btn btn-primary">Regenerate Itinerary</button>
                    </form>
                </div>
                <div class="panel-body">
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Destination</th>
                                    <th>Match Score</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($topMatches as $match)
                                    <tr>
                                        <td>{{ $match->rank }}</td>
                                        <td><a href="{{ route('destinations.show', $match->destination) }}">{{ $match->destination->name }}</a></td>
                                        <td>{{ number_format($match->match_score, 2) }} / 5.00</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Day-by-Day Travel Plan</h2>
                        <p>Sequenced by geographic proximity (Haversine distance + Nearest Neighbor heuristic), with complementary establishments surfaced via Association Rule Mining (Apriori Algorithm).</p>
                    </div>
                </div>
                <div class="panel-body">
                    @if ($itemsByDay->isEmpty())
                        <div class="empty-panel">
                            <div class="icon"><x-icon name="compass" /></div>
                            <h3>No itinerary items yet</h3>
                            <p>Try regenerating your itinerary above.</p>
                        </div>
                    @else
                        @foreach ($itemsByDay as $day => $items)
                            @php
                                $dayStops = $items->map(function ($item) {
                                    $listing = $item->destination ?: $item->accommodation;
                                    if (! $listing || ! $listing->latitude || ! $listing->longitude) {
                                        return null;
                                    }
                                    return ['lat' => $listing->latitude, 'lng' => $listing->longitude, 'label' => $listing->name];
                                })->filter()->values()->all();
                            @endphp
                            <div class="itinerary-day" style="margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid var(--border);">
                                <h3 style="margin-bottom:10px;">Day {{ $day }}</h3>
                                @foreach ($items as $item)
                                    <div class="itinerary-item" style="display:flex; gap:12px; padding:10px 0; align-items:flex-start;">
                                        <span class="badge" style="flex-shrink:0; min-width:90px; text-align:center;">{{ $item->slot }}</span>
                                        <div style="flex:1;">
                                            @if ($item->destination)
                                                <strong><a href="{{ route('destinations.show', $item->destination) }}">{{ $item->destination->name }}</a></strong>
                                            @elseif ($item->accommodation)
                                                <strong><a href="{{ route('accommodations.show', $item->accommodation) }}">{{ $item->accommodation->name }}</a></strong>
                                            @endif
                                            @if ($item->note)
                                                <div class="sub" style="margin-top:2px;">{{ $item->note }}</div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach

                                @if (count($dayStops) >= 2)
                                    <details style="margin-top:12px;">
                                        <summary class="btn btn-outline" style="display:inline-block; cursor:pointer;">View turn-by-turn directions for Day {{ $day }}</summary>
                                        <div style="margin-top:12px;">
                                            @include('partials.route-map', ['stops' => $dayStops, 'mapId' => 'route-day-'.$day])
                                        </div>
                                    </details>
                                @endif
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>About This Itinerary</h2>
                    </div>
                </div>
                <div class="panel-body">
                    <p class="sub">This itinerary is a recommended travel plan generated from your stated preferences, ratings/popularity of accredited listings, and historical tourist visitation patterns. It does not perform booking, reservation, or payment transactions &mdash; feel free to customize it to your available time, budget, and personal preferences.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Optional: use live location as the itinerary's baseline (Sec. 2.3.4 baseline location logic).
    document.getElementById('regenerate-form')?.addEventListener('submit', function (e) {
        if (!navigator.geolocation) return;
        e.preventDefault();
        const form = this;
        navigator.geolocation.getCurrentPosition(
            function (pos) {
                document.getElementById('regenerate-lat').value = pos.coords.latitude;
                document.getElementById('regenerate-lng').value = pos.coords.longitude;
                form.submit();
            },
            function () { form.submit(); },
            { timeout: 3000 }
        );
    });
</script>
@endsection
