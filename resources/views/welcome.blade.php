<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ExploreDVO — Discover the Wonders of Davao Region</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>

@include('partials.header')

<section class="hero">
    <div class="container">
        <span class="hero-eyebrow">Official Department of Tourism Region XI Platform</span>
        <h1>Discover the <span class="accent">Wonders</span><br>of Davao Region</h1>
        <p>Explore DOT-accredited destinations, world-class accommodations, and authentic tour packages
            across the Davao Region.</p>
    </div>

    <form class="search-card container" action="{{ route('tourist.register') }}" method="GET">
        <div class="field">
            <label for="purpose">I want to&hellip;</label>
            <select id="purpose" name="purpose">
                <option>Explore destinations</option>
                <option>Book accommodations</option>
                <option>Find tour packages</option>
                <option>Try local restaurants</option>
            </select>
        </div>
        <div class="field">
            <label for="duration">Duration</label>
            <select id="duration" name="duration">
                <option>1&ndash;2 days</option>
                <option>3&ndash;4 days</option>
                <option>5+ days</option>
            </select>
        </div>
        <div class="field">
            <label for="budget">Budget</label>
            <select id="budget" name="budget">
                <option>Budget-Friendly</option>
                <option>Mid-range</option>
                <option>Premium</option>
            </select>
        </div>
        <div class="field">
            <label for="interest">Interest</label>
            <select id="interest" name="interest">
                <option>Beach &amp; Island</option>
                <option>Nature &amp; Adventure</option>
                <option>Cultural Heritage</option>
                <option>Wildlife</option>
            </select>
        </div>
        <button type="submit" class="btn btn-accent">Plan My Trip</button>
    </form>

    <div class="stats">
        <div class="stat-card-hero">
            <span class="stat-icon-badge" style="background:rgba(255,107,53,.28);"><x-icon name="landmark" /></span>
            <div>
                <div class="stat-num">{{ $stats['destinations'] }}+</div>
                <div class="stat-label">Accredited Destinations</div>
            </div>
        </div>
        <div class="stat-card-hero">
            <span class="stat-icon-badge" style="background:rgba(245,166,35,.28);"><x-icon name="map" /></span>
            <div>
                <div class="stat-num">{{ $stats['regions'] }}</div>
                <div class="stat-label">Provinces &amp; Cities Covered</div>
            </div>
        </div>
        <div class="stat-card-hero">
            <span class="stat-icon-badge" style="background:rgba(29,111,165,.32);"><x-icon name="building" /></span>
            <div>
                <div class="stat-num">{{ $stats['accommodations'] }}+</div>
                <div class="stat-label">Partner Accommodations</div>
            </div>
        </div>
        <div class="stat-card-hero">
            <span class="stat-icon-badge" style="background:rgba(122,79,201,.32);"><x-icon name="star" /></span>
            <div>
                <div class="stat-num">{{ $stats['avg_rating'] ?: '4.8' }}</div>
                <div class="stat-label">Average Traveler Rating</div>
            </div>
        </div>
    </div>
</section>

<section class="section" id="destinations">
    <div class="container">
        <div class="section-head">
            <div>
                <h2>Popular Destinations</h2>
                <p>Verified DOT-accredited spots across the Davao Region, ranked by traveler ratings.</p>
            </div>
            <a href="{{ route('destinations.index') }}" class="btn btn-outline">See all destinations</a>
        </div>

        <div class="card-grid">
            @forelse ($destinations as $destination)
                @include('partials.dest-card', ['destination' => $destination])
            @empty
                <p>Destinations will appear here once the catalog is seeded.</p>
            @endforelse
        </div>
    </div>
</section>

@if ($packages->count())
<section class="section">
    <div class="container">
        <div class="section-head">
            <div>
                <h2>Featured Tour Packages</h2>
                <p>All-inclusive, DOT-accredited experiences ready to book.</p>
            </div>
            <a href="{{ route('packages.index') }}" class="btn btn-outline">See all packages</a>
        </div>
        <div class="card-grid">
            @foreach ($packages as $package)
                @include('partials.package-card', ['package' => $package])
            @endforeach
        </div>
    </div>
</section>
@endif

<section class="section section-alt" id="experiences">
    <div class="container">
        <div class="section-head">
            <div>
                <h2>Plan smarter, not harder</h2>
                <p>ExploreDVO connects travelers directly with DOT-verified destinations, stays, and experiences across the Davao Region.</p>
            </div>
        </div>
        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon"><x-icon name="target" /></div>
                <h3>Browse By Travel Style</h3>
                <p>Filter destinations, accommodations, and tour packages by your travel purpose, budget, duration, and interests.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><x-icon name="shield-check" /></div>
                <h3>Verified Accreditation</h3>
                <p>Every listing is checked against official DOT Region XI accreditation records.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><x-icon name="map-pin" /></div>
                <h3>Pinpoint Locations</h3>
                <p>See exactly where every destination, accommodation, and restaurant sits on an embedded map.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><x-icon name="chat" /></div>
                <h3>Guest Reviews &amp; Owner Responses</h3>
                <p>Read verified traveler reviews, complete with responses straight from establishment owners.</p>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="cta-banner">
            <div>
                <h2>Ready to explore the Davao Region?</h2>
                <p>Create a free account and get your personalized itinerary in minutes.</p>
            </div>
            <a href="{{ route('tourist.register') }}" class="btn btn-lg">Get Started Free</a>
        </div>
    </div>
</section>

@include('partials.footer')
@include('partials.chatbot-widget')

<script src="{{ asset('js/app.js') }}" defer></script>
</body>
</html>
