<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ExploreDVO — Discover the Wonders of Davao Region</title>
    @include('partials.head-assets')
</head>
<body class="hero-page">

@include('partials.header')

<section class="poster-hero">

    <div class="stamp-badge">
        <span class="stamp-badge__text"><strong>Official</strong><span>DOT Region XI</span><span>Philippines</span></span>
    </div>

    <div class="poster-hero__sun" aria-hidden="true"></div>

    <svg class="poster-hero__horizon" viewBox="0 0 1200 220" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path style="fill:var(--ocean-teal-dark)" d="M0,220 L0,150 L150,110 L300,150 L450,100 L600,140 L750,90 L900,150 L1050,120 L1200,150 L1200,220 Z"/>
        <path style="fill:var(--forest)" d="M0,220 L0,170 L120,80 L260,170 L400,60 L560,170 L680,110 L800,170 L950,90 L1100,170 L1200,140 L1200,220 Z"/>
    </svg>

    <svg class="poster-hero__banca" viewBox="0 0 60 70" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path fill="currentColor" d="M8,55 L52,55 L42,59 L18,59 Z"/>
        <line x1="28" y1="55" x2="28" y2="12" stroke="currentColor" stroke-width="1.5"/>
        <path fill="currentColor" d="M28,54 L28,16 L47,50 Z"/>
    </svg>

    <div class="poster-hero__content">
        <span class="poster-kicker poster-hero__kicker">welcome to</span>
        <h1 class="poster-title poster-hero__title">DAVAO REGION</h1>
        <p class="poster-hero__subhead">Sun-warmed islands, misty highlands, and the Philippine Eagle's home &mdash; discover DOT-accredited stays, tours, and eats across Region XI.</p>
    </div>

    <form class="ticket-search container" action="{{ route('tourist.register') }}" method="GET">
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
        <button type="submit" class="btn btn-accent">Search &rarr;</button>
    </form>

    <div class="stats">
        <div class="stats-strip">
            <div class="stat-item">
                <div class="stat-num">{{ $stats['destinations'] }}+</div>
                <div class="stat-label">Destinations</div>
            </div>
            <div class="stat-item">
                <div class="stat-num">{{ $stats['regions'] }}</div>
                <div class="stat-label">Cities &amp; Provinces</div>
            </div>
            <div class="stat-item">
                <div class="stat-num">{{ $stats['accommodations'] }}+</div>
                <div class="stat-label">Accommodations</div>
            </div>
            <div class="stat-item stat-item--rating">
                <div class="stat-num">{{ $stats['avg_rating'] ?: '4.8' }}</div>
                <div class="stat-label">Traveler Rating</div>
            </div>
        </div>
    </div>
</section>

<section class="postcard-section">
        <div class="postcard-slider" data-autoslide>
        <div class="postcard-slider__heading">
            <h2 class="poster-title">Popular Right Now</h2>
            <p>A quick postcard tour of what Region XI is known for.</p>
        </div>
        <button type="button" class="postcard-arrow postcard-arrow--prev" data-prev aria-label="Previous slide"><x-icon name="chevron-left" /></button>
        <button type="button" class="postcard-arrow postcard-arrow--next" data-next aria-label="Next slide"><x-icon name="chevron-right" /></button>
        <div class="postcard-track">
            <div class="postcard-card">
                <div class="postcard-card__scene">
                    <img src="{{ asset('images/postcards/cultural-heritage.jpg') }}" alt="T'boli performer in traditional dress playing a kudyapi in front of a native hut" loading="eager">
                </div>
                <div class="postcard-card__overlay"></div>
                <span class="postcard-card__label">Cultural Heritage</span>
            </div>

            <div class="postcard-card">
                <div class="postcard-card__scene">
                    <img src="{{ asset('images/postcards/mountain-peak.jpg') }}" alt="Mount Apo summit rising above the Davao Region foothills" loading="lazy">
                </div>
                <div class="postcard-card__overlay"></div>
                <span class="postcard-card__label">Mountain Peak</span>
            </div>

            <div class="postcard-card">
                <div class="postcard-card__scene">
                    <img src="{{ asset('images/postcards/wildlife.jpg') }}" alt="Aerial view of a forested bay and coastline in Davao Region" loading="lazy">
                </div>
                <div class="postcard-card__overlay"></div>
                <span class="postcard-card__label">Wildlife</span>
            </div>

            <div class="postcard-card">
                <div class="postcard-card__scene">
                    <img src="{{ asset('images/postcards/island-beach.jpg') }}" alt="Aerial view of a turquoise island coastline in Davao Region" loading="lazy">
                </div>
                <div class="postcard-card__overlay"></div>
                <span class="postcard-card__label">Island Beach</span>
            </div>
        </div>
        <div class="postcard-dots">
            <button type="button" class="dot active" aria-label="Go to slide 1"></button>
            <button type="button" class="dot" aria-label="Go to slide 2"></button>
            <button type="button" class="dot" aria-label="Go to slide 3"></button>
            <button type="button" class="dot" aria-label="Go to slide 4"></button>
        </div>
        </div>
</section>

@php
    // Scene mapping now lives on Destination::illustrationScene() -- reused
    // by the destination detail page too, not just this homepage section.
    $featuredDestination = $destinations->sortByDesc('rating')->first();
    $gridDestinations = $featuredDestination
        ? $destinations->reject(fn ($d) => $d->id === $featuredDestination->id)
        : $destinations;
@endphp

<section class="section dpost-section" id="destinations">
    <div class="container">
        <div class="dpost-head">
            <div>
                <span class="dpost-kicker poster-kicker">handpicked for you</span>
                <h2 class="poster-title" style="color:var(--ocean-teal-dark);">Popular Destinations</h2>
                <p>Verified DOT-accredited spots across the Davao Region, ranked by traveler ratings.</p>
            </div>
            <div class="dpost-head__right">
                <svg class="dpost-flight" viewBox="0 0 160 46" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M4,34 C40,4 100,4 150,20" stroke="var(--stamp-red)" stroke-width="1.5" stroke-dasharray="5 5" fill="none"/>
                    <path d="M150,20 L136,14 L140,22 L132,26 Z" fill="var(--stamp-red)"/>
                </svg>
                <a href="{{ route('destinations.index') }}" class="btn dpost-cta">See all destinations</a>
            </div>
        </div>

        @if ($featuredDestination)
            @php $featuredScene = \App\Models\Destination::illustrationScene($featuredDestination->name); @endphp
            <a href="{{ route('destinations.show', $featuredDestination) }}" class="dpost-banner">
                <div class="dpost-banner__art">
                    @include('partials.poster-illustration', ['scene' => $featuredScene])
                    <div class="halftone"></div>
                    <span class="dpost-ribbon">Top Rated</span>
                </div>
                <div class="dpost-banner__copy">
                    <span class="dpost-banner__kicker poster-kicker">the crown jewel of</span>
                    <h3 class="poster-title dpost-banner__name">{{ $featuredDestination->name }}</h3>
                    <p class="dpost-banner__blurb">Region XI's highest-rated destination &mdash; a DOT-verified must-see that sets the bar for every other stop on your itinerary.</p>
                    <div class="dpost-banner__meta">
                        @if ($featuredDestination->review_count > 0)
                            <span class="dpost-banner__rating">&#9733; {{ number_format($featuredDestination->rating, 1) }}</span>
                        @endif
                        <span>{{ $featuredDestination->location }}@if($featuredDestination->region) &middot; {{ $featuredDestination->region->name }}@endif</span>
                    </div>
                    <span class="btn btn-accent dpost-banner__cta">Plan This Trip &rarr;</span>
                </div>
            </a>
        @endif

        <div class="dpost-grid">
            @forelse ($gridDestinations as $destination)
                @include('partials.listing-poster-card', ['listing' => $destination])
            @empty
                <p>Destinations will appear here once the catalog is seeded.</p>
            @endforelse
        </div>
    </div>
</section>

@if ($packages->count())
    @php
        // The platform doesn't track bookings, so "most popular" is a
        // review-count proxy -- the two top packages here are both
        // 4-star-plus, so rating alone can't tell them apart the way an
        // actual popularity signal would.
        $mostPopularPackage = $packages->sortByDesc('review_count')->first();
    @endphp
<section class="section">
    <div class="container">
        <div class="dpost-head">
            <div>
                <span class="dpost-kicker poster-kicker">ready when you are</span>
                <h2 class="poster-title" style="color:var(--ocean-teal-dark);">Featured Tour Packages</h2>
                <p>All-inclusive, DOT-accredited experiences worth planning around.</p>
            </div>
            <a href="{{ route('packages.index') }}" class="btn dpost-cta">See all packages</a>
        </div>
        <div class="dpost-grid">
            @foreach ($packages as $package)
                @include('partials.package-poster-card', [
                    'package' => $package,
                    'scene' => $package->posterScene(),
                    'mostPopular' => $mostPopularPackage && $mostPopularPackage->id === $package->id,
                ])
            @endforeach
        </div>
    </div>
</section>
@endif

<section class="section section-alt" id="experiences">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="poster-kicker">how this works</span>
                <h2 class="poster-title" style="color:var(--ocean-teal-dark);">Plan smarter, not harder</h2>
                <p>ExploreDVO connects travelers directly with DOT-verified destinations, stays, and experiences across the Davao Region.</p>
            </div>
        </div>

        {{--
            Icons are drawn here rather than pulled from <x-icon>: the shared
            icon set is thin-stroke UI chrome sized for buttons and inputs,
            which reads as a stock icon kit at stamp size. These are solid
            flat-vector marks with details knocked back out to the stamp's own
            ink colour (--stamp-ink), matching the illustration style used for
            the destination scenes.
        --}}
        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-stamp feature-stamp--leaf">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <circle cx="12" cy="12" r="9.4" fill="none" stroke="currentColor" stroke-width="1.9"/>
                        <path fill="currentColor" d="M16.9 7.1 13.9 13.9 7.1 16.9 10.1 10.1z"/>
                        <circle cx="12" cy="12" r="1.5" style="fill:var(--stamp-ink)"/>
                    </svg>
                </div>
                <h3>Browse By Travel Style</h3>
                <p>Filter destinations, accommodations, and tour packages by your travel purpose, budget, duration, and interests.</p>
            </div>
            <div class="feature-card">
                <div class="feature-stamp feature-stamp--red">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path fill="currentColor" d="M12 2.2 19.6 5.3v6.1c0 4.7-3.2 8.8-7.6 10.4-4.4-1.6-7.6-5.7-7.6-10.4V5.3z"/>
                        <path fill="none" style="stroke:var(--stamp-ink)" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" d="m8.3 12.1 2.6 2.6 4.8-5.3"/>
                    </svg>
                </div>
                <h3>Verified Accreditation</h3>
                <p>Every listing is checked against official DOT Region XI accreditation records.</p>
            </div>
            <div class="feature-card">
                <div class="feature-stamp feature-stamp--ocean">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path fill="currentColor" d="M12 1.9a7.3 7.3 0 0 0-7.3 7.3c0 5.4 7.3 12.9 7.3 12.9s7.3-7.5 7.3-12.9A7.3 7.3 0 0 0 12 1.9z"/>
                        <circle cx="12" cy="9.2" r="2.7" style="fill:var(--stamp-ink)"/>
                    </svg>
                </div>
                <h3>Pinpoint Locations</h3>
                <p>See exactly where every destination, accommodation, and restaurant sits on an embedded map.</p>
            </div>
            <div class="feature-card">
                <div class="feature-stamp feature-stamp--gold">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path fill="currentColor" d="M3.6 2.6h11.6a2 2 0 0 1 2 2v5.2a2 2 0 0 1-2 2H8.9l-3.9 3v-3h-1.4a2 2 0 0 1-2-2V4.6a2 2 0 0 1 2-2z"/>
                        <path style="fill:var(--stamp-ink)" d="M11.4 12.6h9a2 2 0 0 1 2 2v3.6a2 2 0 0 1-2 2h-2.9l-3.3 2.6v-2.6h-2.8a2 2 0 0 1-2-2v-3.6a2 2 0 0 1 2-2z"/>
                        <path fill="currentColor" d="M12.4 13.6h8a1 1 0 0 1 1 1v3.6a1 1 0 0 1-1 1h-3.3l-2.2 1.7v-1.7h-2.5a1 1 0 0 1-1-1v-3.6a1 1 0 0 1 1-1z"/>
                    </svg>
                </div>
                <h3>Guest Reviews &amp; Owner Responses</h3>
                <p>Read verified traveler reviews, complete with responses straight from establishment owners.</p>
            </div>
        </div>
    </div>
</section>

<section class="section about-region" id="about-region">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="poster-kicker">the region</span>
                <h2 class="poster-title" style="color:var(--ocean-teal-dark);">About Davao Region</h2>
                <p>Home to the country's highest peak and the last strongholds of the Philippine Eagle.</p>
            </div>
        </div>

        <div class="about-grid">
            <div class="about-copy">
                <p>Stretching inland from the Davao Gulf, Region XI climbs from lowland rainforest and coconut country into the cool, mist-held highlands around Mount Apo &mdash; at 2,954 metres, the highest ground in the Philippines. The change happens quickly: a single morning can take you from a beachfront on Samal to a highland trail where the temperature drops ten degrees and the birdsong changes entirely.</p>

                <p>What makes the region unusual is how close that wilderness sits to an ordinary working city. Davao City covers more ground than almost any other city in the country, yet the Philippine Eagle &mdash; the national bird, and one of the rarest raptors alive &mdash; still nests in the forest along its western edge. Fishing towns, durian orchards, Lumad communities and a full-service metro all share the same gulf, and moving between them takes hours rather than days.</p>

                @php
                    /*
                     * Curated order (coastal north down to the southern tip, island
                     * last) rather than the alphabetical order the query returns --
                     * it matches how the illustrated map reads. Anything seeded later
                     * that isn't in this list still gets a pill, appended at the end.
                     */
                    $regionOrder = [
                        'Davao City', 'Davao del Norte', 'Davao del Sur', 'Davao de Oro',
                        'Davao Oriental', 'Davao Occidental', 'Island Garden City of Samal',
                    ];
                    $regionLabels = ['Island Garden City of Samal' => 'IGACOS / Samal'];
                    $byName = $regions->keyBy('name');
                    $orderedRegions = collect($regionOrder)
                        ->map(fn ($name) => $byName->get($name))
                        ->filter()
                        ->concat($regions->reject(fn ($r) => in_array($r->name, $regionOrder, true)));
                @endphp

                <div class="about-regions">
                    @foreach ($orderedRegions as $region)
                        <a href="{{ route('destinations.index', ['region_id' => $region->id]) }}" class="about-region-pill">
                            {{ $regionLabels[$region->name] ?? $region->name }}
                        </a>
                    @endforeach
                </div>
            </div>

            <figure class="about-map">
                {{--
                    Hand-drawn locator, not a map embed: seven interlocking pieces in
                    stepped shades of the leaf/ocean palette, seamed in cream so the
                    region reads as a set of parts rather than one silhouette. Shapes
                    are deliberately stylised -- close enough to be recognisable,
                    simple enough to stay legible at card size.
                --}}
                <svg class="about-map__svg" viewBox="0 0 400 480" xmlns="http://www.w3.org/2000/svg" role="img"
                     aria-label="Stylised map of Davao Region showing its seven provinces and cities, with Mount Apo marked">
                    <g class="about-map__pieces" stroke="#FFF7E9" stroke-width="2.5" stroke-linejoin="round">
                        <path fill="#12836F" d="M62,92 L188,66 L214,128 L190,198 L92,208 L52,150 Z"/>
                        <path fill="#2f9e8f" d="M188,66 L300,58 L322,124 L288,186 L190,198 L214,128 Z"/>
                        <path fill="#0B5E52" d="M300,58 L362,80 L370,196 L326,292 L276,262 L288,186 L322,124 Z"/>
                        <path fill="#0b6b4f" d="M92,208 L190,198 L288,186 L276,262 L232,302 L140,298 L100,262 Z"/>
                        <path fill="#1E5C43" d="M100,262 L140,298 L204,344 L210,410 L152,418 L104,356 Z"/>
                        <path fill="#084d39" d="M104,356 L152,418 L142,458 L76,442 L60,380 Z"/>
                    </g>

                    {{-- Davao Gulf, drawn after the land so the coastline reads as a bite out of it --}}
                    <path fill="#7fd4d8" opacity=".55" stroke="#FFF7E9" stroke-width="2.5" stroke-linejoin="round"
                          d="M232,302 L276,262 L300,352 L266,438 L210,410 L204,344 Z"/>
                    {{-- Sized to carry its own label rather than to scale: at true relative
                         size the island is too small for "IGACOS" to sit on it. --}}
                    <path fill="#BFE3E0" stroke="#FFF7E9" stroke-width="2.5" stroke-linejoin="round"
                          d="M234,330 L266,338 L278,364 L262,392 L232,386 L222,356 Z"/>

                    {{-- Mount Apo: the Davao City / Davao del Sur seam, where the real summit sits --}}
                    <g class="about-map__peak">
                        <path d="M126,258 L138,280 L114,280 Z" fill="#FFF7E9" stroke="#1a2420" stroke-width="1.3" stroke-linejoin="round"/>
                        <path d="M126,258 L131,267 L121,267 Z" fill="#e1e8e4"/>
                    </g>
                    <text class="about-map__peaklabel" x="126" y="294" text-anchor="middle">Mt. Apo</text>

                    <g class="about-map__labels" text-anchor="middle">
                        <text x="128" y="136">Davao<tspan x="128" dy="12">del Norte</tspan></text>
                        <text x="252" y="120">Davao<tspan x="252" dy="12">de Oro</tspan></text>
                        <text x="322" y="176">Davao<tspan x="322" dy="12">Oriental</tspan></text>
                        <text x="196" y="240">Davao City</text>
                        <text x="150" y="356">Davao<tspan x="150" dy="12">del Sur</tspan></text>
                        <text x="104" y="404">Davao<tspan x="104" dy="12">Occidental</tspan></text>
                        <text class="about-map__label--dark" x="250" y="366">IGACOS</text>
                    </g>

                    <g class="about-map__compass" transform="translate(50,46)">
                        <circle r="20" fill="none" style="stroke:var(--ocean-teal-dark)" stroke-width="1.5" opacity=".55"/>
                        <path d="M0,-15 L4.5,-3 L0,0 L-4.5,-3 Z" style="fill:var(--stamp-red)"/>
                        <path d="M0,15 L4.5,3 L0,0 L-4.5,3 Z" style="fill:var(--ocean-teal-dark)" opacity=".65"/>
                        <text class="about-map__compass-n" y="-24" text-anchor="middle">N</text>
                    </g>
                </svg>

                <figcaption class="about-map__caption poster-kicker">Davao Region at a glance</figcaption>
            </figure>
        </div>

        <div class="dyk-strip">
            <span class="dyk-strip__label poster-kicker">did you know</span>
            <div class="dyk-items">
                @php
                    $facts = [
                        ['Home to the Philippine Eagle', "the national bird, and a raptor found nowhere outside the Philippines."],
                        ['Mount Apo rises 2,954 metres', 'making it the highest point in the country.'],
                        ['The durian capital', 'Davao Region grows more of it than anywhere else in the Philippines.'],
                        ['Samal is a 15-minute crossing', 'the island sits just offshore from Davao City.'],
                    ];
                @endphp
                @foreach ($facts as [$lead, $tail])
                    <div class="dyk-item">
                        <svg class="dyk-star" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path fill="currentColor" d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14l-5-4.87 6.91-1.01L12 2z"/>
                        </svg>
                        <p><strong>{{ $lead }}</strong> &mdash; {{ $tail }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="cta-banner">
            {{--
                Extends the hero's motif rather than repeating it: the same
                layered ridge silhouettes and glowing sun, masked so they fade
                out before they reach the copy on the left.
            --}}
            <svg class="cta-banner__scene" viewBox="0 0 420 240" preserveAspectRatio="xMidYMax slice" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <defs>
                    <radialGradient id="ctaSunGlow">
                        <stop offset="0%" style="stop-color:var(--sun-glow)" stop-opacity=".95"/>
                        <stop offset="55%" style="stop-color:var(--sunset-gold)" stop-opacity=".4"/>
                        <stop offset="100%" style="stop-color:var(--sunset-gold)" stop-opacity="0"/>
                    </radialGradient>
                    <linearGradient id="ctaFade" x1="0" y1="0" x2="1" y2="0">
                        <stop offset="0%" stop-color="#fff" stop-opacity="0"/>
                        <stop offset="48%" stop-color="#fff" stop-opacity="1"/>
                    </linearGradient>
                    <mask id="ctaSceneMask">
                        <rect width="420" height="240" fill="url(#ctaFade)"/>
                    </mask>
                </defs>
                <g mask="url(#ctaSceneMask)">
                    <circle cx="298" cy="92" r="92" fill="url(#ctaSunGlow)"/>
                    <circle cx="298" cy="92" r="42" style="fill:var(--sun-glow)" opacity=".5"/>
                    <path style="fill:var(--ocean-teal-dark)" opacity=".34" d="M0,240 L0,178 L70,150 L140,180 L210,138 L280,172 L350,132 L420,166 L420,240 Z"/>
                    <path style="fill:var(--forest)" opacity=".42" d="M0,240 L0,202 L60,152 L130,202 L200,130 L280,202 L340,162 L420,198 L420,240 Z"/>
                </g>
            </svg>

            <div class="halftone"></div>

            <div class="cta-banner__copy">
                <span class="poster-kicker">your move</span>
                <h2 class="poster-title">Ready to explore the Davao Region?</h2>
                <p>Create a free account and get your personalized itinerary in minutes.</p>
            </div>
            <a href="{{ route('tourist.register') }}" class="btn btn-lg">Get Started Free</a>
        </div>
    </div>
</section>

@include('partials.footer')
@include('partials.chatbot-widget')

<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
</body>
</html>
