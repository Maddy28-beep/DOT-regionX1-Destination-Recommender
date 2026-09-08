<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ExploreDVO — Discover the Wonders of Davao Region</title>
    @include('partials.head-assets')
    @if ($heroVideo && $heroVideo['poster'])
        {{-- Fetched in parallel with the stylesheet so the hero's ground is
             ready at first paint, rather than arriving after it. --}}
        <link rel="preload" as="image" href="{{ $heroVideo['poster'] }}">
    @endif
</head>
<body class="hero-page">

@include('partials.header')

{{-- has-footage is set server-side so the painted horizon is never drawn in
     the first place when a clip is available. Waiting for JavaScript to hide
     it meant it always flashed first: an inline script is held until pending
     stylesheets apply, so it cannot run before the illustration has painted.
     The script below puts the illustration back if it decides against the
     video, and the <noscript> block does the same when there is no script at
     all. --}}
<section class="poster-hero{{ $heroVideo ? ' has-footage' : '' }}"
    @if ($heroVideo && $heroVideo['poster'])
        {{-- The clip's own first frame, as the ground the video paints onto.
             Inline because the URL is dynamic. Preloaded in the head so it is
             already decoded when the section paints. --}}
        style="background-image: url('{{ $heroVideo['poster'] }}');"
    @endif
>

    @if ($heroVideo)
        {{--
            Hero footage, carrying no src so the browser cannot start
            downloading before the script below has decided this visit can
            afford it. On a phone, a metered connection, or with reduced
            motion asked for, that script restores the painted hero instead.

            Muted + playsinline because no mobile browser will autoplay
            otherwise, and a background clip that makes noise is a bug rather
            than a feature.

            No poster frame: the hero's own dark ground already covers the
            moment before the first frame decodes, so a poster could only
            flash over it.
        --}}
        <video class="poster-hero__video" aria-hidden="true" tabindex="-1"
               muted loop playsinline preload="none"
               data-mp4="{{ $heroVideo['mp4'] }}"
               @if ($heroVideo['webm']) data-webm="{{ $heroVideo['webm'] }}" @endif></video>
        <div class="poster-hero__video-scrim" aria-hidden="true"></div>

        {{-- No script means no video, so the painted hero has to come back. --}}
        <noscript>
            <style>
                .poster-hero.has-footage .poster-hero__horizon,
                .poster-hero.has-footage .poster-hero__banca { display: block; }
                .poster-hero__video, .poster-hero__video-scrim { display: none; }
            </style>
        </noscript>

        <script>
            /*
             * Inline and immediately beneath the element on purpose. app.js is
             * deferred, so running this from there waited for the whole
             * document to parse -- ~1450ms, against ~350ms to actually fetch
             * the clip -- and the painted hero sat on screen for close to two
             * seconds before the video replaced it. Starting during parsing
             * cuts almost all of that.
             *
             * It still cannot run before the first paint, because a browser
             * holds even an inline script until pending stylesheets apply.
             * That is why the illustration is withheld server-side via
             * .has-footage and put back here, rather than the other way round.
             *
             * The <video> carries no src; this decides whether the visit can
             * afford one. Three reasons to refuse, and the painted hero is
             * restored in every one of them:
             *
             *   1. Reduced motion -- a full-bleed moving background is exactly
             *      what that preference exists to stop.
             *   2. A narrow screen. Most visitors to a tourism site are on a
             *      phone, often roaming.
             *   3. Data Saver, or a connection reporting itself as 2g/3g.
             */
            (function () {
                var video = document.currentScript.parentNode.querySelector('.poster-hero__video');
                if (!video) return;

                var refuse = window.matchMedia('(prefers-reduced-motion: reduce)').matches
                    || !window.matchMedia('(min-width: 900px)').matches;

                var connection = navigator.connection || {};
                if (connection.saveData === true || /(^|-)(2g|3g)$/.test(connection.effectiveType || '')) {
                    refuse = true;
                }

                var hero = video.closest('.poster-hero');

                // Declining the footage means putting the painted hero back,
                // since the markup shipped without it.
                var fallBack = function () {
                    hero.classList.remove('has-footage', 'has-video');
                };

                if (refuse) {
                    fallBack();

                    return;
                }

                /*
                 * Commit to the footage now, before it has loaded.
                 *
                 * The painted horizon sits above the video in the stack, so it
                 * has to be cleared for the footage to show at all -- waiting
                 * for loadeddata to do that meant the fallback was always
                 * visible first, however briefly. Clearing it up front lets
                 * the video paint the instant it has a frame, which on a
                 * refresh with the clip cached is effectively immediate.
                 *
                 * Safe because the hero's own ground is already the dark of
                 * the scrim: an empty <video> paints nothing, so what shows in
                 * the meantime is the same colour the footage arrives on.
                 */
                hero.classList.add('has-video');

                // preload="none" in the markup keeps the browser from fetching
                // before the checks above have run. Now that they have passed,
                // say it is wanted.
                video.preload = 'auto';

                /*
                 * If no frame ever arrives, put the illustration back. Both
                 * paths are needed: `error` does not fire reliably on a media
                 * element whose sources are <source> children, and a clip can
                 * also simply stall. loadeddata re-commits if it turns up late.
                 */
                var giveUp = setTimeout(function () {
                    if (video.readyState < 2) fallBack();
                }, 2500);

                video.addEventListener('error', function () {
                    clearTimeout(giveUp);
                    fallBack();
                }, { once: true });

                ['webm', 'mp4'].forEach(function (type) {
                    var url = video.getAttribute('data-' + type);
                    if (!url) return;

                    var source = document.createElement('source');
                    source.src = url;
                    source.type = type === 'webm' ? 'video/webm' : 'video/mp4';
                    video.appendChild(source);
                });

                video.load();

                // Re-commit if the clip turns up after the deadline above had
                // already put the illustration back.
                video.addEventListener('loadeddata', function () {
                    clearTimeout(giveUp);
                    hero.classList.add('has-video');
                }, { once: true });

                // play() can reject on its own even when muted; the
                // illustration is still there if it does.
                var attempt = video.play();
                if (attempt && typeof attempt.catch === 'function') {
                    attempt.catch(function () {});
                }
            })();
        </script>
    @endif

    <div class="stamp-badge">
        <span class="stamp-badge__text"><strong>Official</strong><span>DOT Region XI</span><span>Philippines</span></span>
    </div>

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

    {{--
        Every option carries an explicit value. Without one a select submits
        its own label, so "Duration" arrived as the string "1–2 days" -- en
        dash and all -- and anything reading it had to parse display text back
        into a number. The labels stay free to change without breaking the
        search.
    --}}
    <form class="ticket-search container" action="{{ route('search') }}" method="GET">
        <div class="field">
            <label for="purpose">I want to&hellip;</label>
            <select id="purpose" name="purpose">
                <option value="destinations">Explore destinations</option>
                <option value="accommodations">Book accommodations</option>
                <option value="packages">Find tour packages</option>
                <option value="restaurants">Try local restaurants</option>
            </select>
        </div>
        <div class="field">
            <label for="duration">Duration</label>
            <select id="duration" name="duration">
                <option value="1-2">1&ndash;2 days</option>
                <option value="3-4">3&ndash;4 days</option>
                <option value="5-plus">5+ days</option>
            </select>
        </div>
        <div class="field">
            <label for="budget">Budget</label>
            <select id="budget" name="budget">
                <option value="Budget-Friendly">Budget-Friendly</option>
                <option value="Mid-range">Mid-range</option>
                <option value="Premium">Premium</option>
            </select>
        </div>
        <div class="field">
            <label for="interest">Interest</label>
            <select id="interest" name="interest">
                <option value="Beach &amp; Island">Beach &amp; Island</option>
                <option value="Nature &amp; Adventure">Nature &amp; Adventure</option>
                <option value="Cultural Heritage">Cultural Heritage</option>
                <option value="Wildlife">Wildlife</option>
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
            {{-- No fabricated placeholder here: this stood at a hard-coded
                 "4.8" whenever nothing was rated yet, which is a review score
                 no traveller ever gave. If there is nothing real to show, the
                 stat simply stands down. --}}
            @if ($stats['avg_rating'])
                <div class="stat-item stat-item--rating">
                    <div class="stat-num">{{ number_format($stats['avg_rating'], 1) }}</div>
                    <div class="stat-label">Traveler Rating</div>
                </div>
            @endif
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
                {{-- Both this line and the banner's "Top Rated" ribbon below are
                     claims about traveller ratings, so they hold only while some
                     listing actually carries one. With the invented review counts
                     gone the catalogue starts unrated, and the ordering falls back
                     to the DOT-featured flag -- which is what the alternative
                     copy describes. --}}
                @if ($stats['avg_rating'])
                    <p>Verified DOT-accredited spots across the Davao Region, ranked by traveler ratings.</p>
                @else
                    <p>Verified DOT-accredited spots across the Davao Region.</p>
                @endif
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
                    @if ($stats['avg_rating'])
                        <span class="dpost-ribbon">Top Rated</span>
                    @else
                        <span class="dpost-ribbon">Featured</span>
                    @endif
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
                    Was a hand-drawn SVG locator. Replaced with a real map because the
                    illustration could not be checked against anything, and the
                    published maps considered as an alternative each carried a
                    licensing question -- one of them still labelled the province
                    Compostela Valley, seven years after it became Davao de Oro.
                    This one reads its names and counts from the regions table, so it
                    cannot drift from the catalogue.
                --}}
                @include('partials.region-map', ['regions' => $regionMap])
                <figcaption class="about-map__caption poster-kicker">
                    Accredited listings by province &mdash; tap a circle to browse
                </figcaption>
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
                <p>Answer a few questions and get a personalized day-by-day itinerary in minutes &mdash; no account needed.</p>
            </div>
            <a href="{{ route('plan.edit') }}" class="btn btn-lg">Build My Itinerary</a>
        </div>
    </div>
</section>

@include('partials.footer')
@include('partials.chatbot-widget')

<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
</body>
</html>
