<!DOCTYPE html>
<html lang="en" class="home-slides">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ExploreDVO — Discover the Wonders of Davao Region</title>
    @include('partials.share-meta', ['metaTitle' => 'ExploreDVO — Discover the Wonders of Davao Region'])
    @include('partials.head-assets')
    <link rel="stylesheet" href="{{ \App\Support\StyleAsset::url('css/landing-sections.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\StyleAsset::url('css/mood-cards.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\StyleAsset::url('css/hero-search.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\StyleAsset::url('css/home-slides.css') }}">
    @if ($heroVideo && $heroVideo['poster'])
        {{-- Fetched in parallel with the stylesheet so the hero's ground is
             ready at first paint, rather than arriving after it. --}}
        <link rel="preload" as="image" href="{{ $heroVideo['poster'] }}" fetchpriority="high">
    @endif
</head>
<body class="hero-page">
<a class="skip-link" href="#main-content">Skip to main content</a>

@include('partials.header')
@include('partials.survey-invite-banner')

{{-- has-footage is set server-side so the painted horizon is never drawn in
     the first place when a clip is available. Waiting for JavaScript to hide
     it meant it always flashed first: an inline script is held until pending
     stylesheets apply, so it cannot run before the illustration has painted.
     The script below puts the illustration back if it decides against the
     video, and the <noscript> block does the same when there is no script at
     all. --}}
{{-- The picture and the card beneath it are one screen: on a tall display the next section must not peek in under the cue. --}}
<main id="main-content" tabindex="-1">
<div class="hero-screen">
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
            afford it. On a metered connection, or with reduced motion
            asked for, that script restores the painted hero instead.

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

        {{-- No script means no video, so the plain hero ground shows instead. --}}
        <noscript>
            <style>
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
             * afford one. Two reasons to refuse, and the painted hero is
             * restored in both:
             *
             *   1. Reduced motion -- a full-bleed moving background is exactly
             *      what that preference exists to stop.
             *   2. Data Saver, or a connection reporting itself as 2g/3g.
             *
             * A narrow screen is not a reason. Most visitors to a tourism site
             * are on a phone, and the clip is about 0.6 MB, so refusing phones
             * took the footage away from the very people it is for and saved
             * almost nothing. Data Saver and slow connections still opt out.
             */
            (function () {
                var video = document.currentScript.parentNode.querySelector('.poster-hero__video');
                if (!video) return;

                var refuse = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

                var connection = navigator.connection || {};
                if (connection.saveData === true || /(^|-)(2g|3g)$/.test(connection.effectiveType || '')) {
                    refuse = true;
                }

                var hero = video.closest('.poster-hero');

                // Declining the footage means putting the painted hero back,
                // since the markup shipped without it.
                var fallBack = function () {
                    hero.classList.remove('has-footage', 'has-video');
                    // The poster still is set inline for the footage path. Without the scrim that only
                    // comes with .has-footage it is a bright photo behind cream text, so on phones the
                    // title and subhead were unreadable. Clearing the inline style lets the painted hero's own dark gradient show.
                    hero.style.backgroundImage = '';
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

                // play() can reject on its own even when muted (a phone in
                // low-power mode, for one). The first frame stays on screen if
                // it does, and the first touch or scroll is allowed to start it.
                var start = function () {
                    var attempt = video.play();
                    if (attempt && typeof attempt.catch === 'function') {
                        attempt.catch(function () {});
                    }
                };
                /*
                 * Everything that touches the network lives in begin(). On a computer it runs at once, as before.
                 * On a phone it waits for the page's own load event: the 0.6 MB clip would otherwise share a slow
                 * connection with the stylesheet and the poster, and delay the first paint by seconds. The poster and
                 * the dark ground are already on screen, so the footage simply arrives a moment later.
                 */
                var begin = function () {
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

                    start();
                };

                /*
                 * On a phone the clip's first frame was the "largest paint" PageSpeed waited for (2.8 s, against 1.3 s
                 * for the poster on its own). So a phone starts the footage only once the page has settled: on the
                 * visitor's first touch, scroll or key press, or at the latest 8 s after load. The poster stays on
                 * screen until then. A computer still starts it at once.
                 */
                if (window.matchMedia('(max-width: 767px)').matches && document.readyState !== 'complete') {
                    var kick = ['touchstart', 'pointerdown', 'scroll', 'keydown'];
                    var began = false;
                    var wait;
                    var go = function () {
                        if (began) return;
                        began = true;
                        clearTimeout(wait);
                        kick.forEach(function (name) { window.removeEventListener(name, go); });
                        begin();
                    };
                    kick.forEach(function (name) { window.addEventListener(name, go, { passive: true }); });
                    window.addEventListener('load', function () { wait = setTimeout(go, 8000); }, { once: true });
                } else {
                    begin();
                }

                var retry = function () {
                    if (video.paused) start();
                    ['touchstart', 'pointerdown', 'scroll', 'keydown'].forEach(function (name) {
                        window.removeEventListener(name, retry);
                    });
                };
                ['touchstart', 'pointerdown', 'scroll', 'keydown'].forEach(function (name) {
                    window.addEventListener(name, retry, { passive: true });
                });
            })();
        </script>
    @endif

    <div class="hero-tourism-brands" aria-label="Tourism logos">
        <img src="{{ asset('images/tourism-logos/davao.webp') }}" alt="Davao" class="hero-tourism-brands__davao" width="200" height="67">
        <img src="{{ asset('images/tourism-logos/dot.webp') }}" alt="Department of Tourism Philippines" class="hero-tourism-brands__dot" width="100" height="99">
        <img src="{{ asset('images/tourism-logos/love-philippines.webp') }}" alt="Love the Philippines" class="hero-tourism-brands__love" width="220" height="94">
    </div>

    <div class="poster-hero__content">
        <span class="poster-kicker poster-hero__kicker">welcome to</span>
        <h1 class="poster-title poster-hero__title">DAVAO REGION</h1>
        <p class="poster-hero__subhead">Sun-warmed islands, misty highlands, and the Philippine Eagle's home &mdash; discover stays, tours, and eats across Region XI.</p>
    </div>

    {{-- The picture ends on a soft curve, not a ruler-straight line: it bulges down behind the search card and
         rises toward the sides, in the white of the ground below. --}}
    <svg class="hero-edge" viewBox="0 0 1440 120" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
        <path d="M0,120 L0,26 Q720,176 1440,26 L1440,120 Z"/>
    </svg>

</section>

{{--
    The hero's picture ends here, partway behind the search card: the card straddles its lower edge, and the
    rest of the card, the popular searches, the stats and the scroll cue sit on a light ground beneath it.
--}}
<div class="hero-lower">
    {{--
        Every option carries an explicit value. Without one a select submits
        its own label, so "Duration" arrived as the string "1–2 days" -- en
        dash and all -- and anything reading it had to parse display text back
        into a number. The labels stay free to change without breaking the
        search.

        Duration/Budget/Interest open on an empty "Any ..." value rather than
        a pre-picked option: SearchController already treats an unfilled
        field as "no opinion" via $request->filled(), so a default of
        "1-2 days" or "Beach & Island" was filtering results the visitor
        never actually chose. "I want to..." is the one exception -- it picks
        which catalogue is searched at all, not a filter on it, so it keeps a
        real default. data-empty-select marks which ones app.js should track
        for the .is-empty placeholder styling.
    --}}
    {{--
        The search card, in two parts: a white bar of tabs (the five catalogues, as radio inputs named
        "purpose", so the form works without script) floating over a light body, with Explore on the lower edge.

        Each tab is its own short form. A box carries data-for="<what it is>" and the script shows only the
        boxes the chosen tab uses (data-tab-fields on the radio), disabling the rest so they are never sent;
        SearchController ignores anything else on the server too. Everything is optional: every box opens on
        "Any ...", and the guest counters on 0, which put no limit on the results.

        There is no name search. Budget is a dropdown whose choices depend on the tab (App\Support\BudgetRanges):
        peso ranges where the catalogue records a price, the existing price levels for dining, and nothing for
        souvenir shops, which record no price.
    --}}
    @php
        $stayGuests = ['adults' => 'Adults', 'children' => 'Children', 'seniors' => 'Senior citizens', 'pwd' => 'PWD'];
        $today = now('Asia/Manila')->toDateString();
    @endphp
    <form class="hero-search" id="search" action="{{ route('search') }}" method="GET" data-hero-search>
        <div class="hero-search__bar">
            <fieldset class="hero-search__tabs">
                <legend class="sr-only">I want to&hellip;</legend>
                @foreach ([
                    ['destinations', 'Destinations', 'map-pin', 'budget where interest'],
                    ['accommodations', 'Stays', 'building', 'budget where stay_mode from to guests'],
                    ['restaurants', 'Dining', 'utensils', 'budget meal date cuisine where'],
                    ['packages', 'Packages', 'ticket', 'budget where interest duration travelers'],
                    ['souvenir_centers', 'Souvenirs', 'tag', 'where'],
                ] as [$value, $label, $icon, $fields])
                    <label class="hero-search__tab">
                        <input type="radio" name="purpose" value="{{ $value }}" data-tab-fields="{{ $fields }}" @checked($loop->first)>
                        <span class="hero-search__tab-body"><x-icon :name="$icon" /> {{ $label }}</span>
                    </label>
                @endforeach
            </fieldset>
        </div>

        <div class="hero-search__body">
            <div class="hero-search__grid" data-search-filters>
                {{-- Budget: one dropdown per tab, because what a budget means (and offers) differs by catalogue. --}}
                @foreach ($budgetOptions as $purpose => $options)
                    <div class="hero-search__box" data-for="budget" data-budget-box="{{ $purpose }}" @if ($purpose !== 'destinations') hidden @endif>
                        <x-icon name="tag" />
                        <label for="search-budget-{{ $purpose }}">Budget &middot; {{ $budgetBasis[$purpose] }}</label>
                        <select id="search-budget-{{ $purpose }}" name="budget" data-budget-select data-empty-select @if ($purpose !== 'destinations') disabled @endif>
                            @foreach ($options as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach

                <div class="hero-search__box hero-search__box--amounts hero-search__box--wide" data-custom-budget hidden>
                    <x-icon name="tag" />
                    <span class="hero-search__box-title" id="custom-budget-title">Your budget (&#8369;) &middot; either box can stay empty</span>
                    <div class="hero-search__amounts" role="group" aria-labelledby="custom-budget-title">
                        <label><span class="sr-only">Minimum budget in pesos</span>
                            <input type="text" inputmode="decimal" name="budget_min" placeholder="Minimum, e.g. 500" maxlength="9" autocomplete="off" disabled data-budget-min>
                        </label>
                        <span aria-hidden="true">to</span>
                        <label><span class="sr-only">Maximum budget in pesos</span>
                            <input type="text" inputmode="decimal" name="budget_max" placeholder="Maximum, e.g. 3000" maxlength="9" autocomplete="off" disabled data-budget-max>
                        </label>
                    </div>
                    <p class="hero-search__error" role="alert" data-budget-error hidden></p>
                </div>

                <div class="hero-search__box" data-for="meal" hidden>
                    <x-icon name="clock" />
                    <label for="search-meal">Meal</label>
                    <select id="search-meal" name="meal" data-empty-select disabled>
                        <option value="">Any meal</option>
                        @foreach ($mealOptions as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="hero-search__box" data-for="date" hidden>
                    <x-icon name="calendar" />
                    <label for="search-dining-date">Date</label>
                    <input type="date" id="search-dining-date" name="date" min="{{ $today }}" disabled>
                </div>
                <div class="hero-search__box" data-for="cuisine" hidden>
                    <x-icon name="utensils" />
                    <label for="search-cuisine">Cuisine</label>
                    <select id="search-cuisine" name="cuisine_type" data-empty-select disabled>
                        <option value="">Any cuisine</option>
                        @foreach ($cuisineTypes as $cuisine)
                            <option value="{{ $cuisine }}">{{ $cuisine }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="hero-search__box" data-for="where">
                    <x-icon name="map-pin" />
                    <label for="search-region">Where</label>
                    <select id="search-region" name="region_id" data-empty-select>
                        <option value="">Anywhere in Region XI</option>
                        @foreach ($regions as $region)
                            <option value="{{ $region->id }}">{{ $region->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="hero-search__box" data-for="interest">
                    <x-icon name="heart" />
                    <label for="interest">Interest</label>
                    {{-- Same list the Plan Your Trip survey uses (HomeController's $interestOptions, reused from
                         ExitSurveyController::ACTIVITIES) so the two can't drift apart. --}}
                    <select id="interest" name="interest" data-empty-select>
                        <option value="">Any interest</option>
                        @foreach ($interestOptions as $interest)
                            <option value="{{ $interest }}">{{ $interest }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="hero-search__box" data-for="stay_mode" hidden>
                    <x-icon name="building" />
                    <label for="search-stay-mode">Kind of stay</label>
                    <select id="search-stay-mode" name="stay_mode" data-empty-select disabled>
                        <option value="">Any</option>
                        @foreach ($stayModes as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="hero-search__box" data-for="from" hidden>
                    <x-icon name="calendar" />
                    <label for="search-from">From (check-in)</label>
                    <input type="date" id="search-from" name="from" min="{{ $today }}" disabled data-date-from>
                </div>
                <div class="hero-search__box" data-for="to" hidden>
                    <x-icon name="calendar" />
                    <label for="search-to">To (check-out)</label>
                    <input type="date" id="search-to" name="to" min="{{ $today }}" disabled data-date-to>
                </div>

                <div class="hero-search__box" data-for="duration" hidden>
                    <x-icon name="calendar" />
                    <label for="duration">Trip length</label>
                    <select id="duration" name="duration" data-empty-select disabled>
                        <option value="">Any length</option>
                        <option value="1-2">1&ndash;2 days</option>
                        <option value="3-4">3&ndash;4 days</option>
                        <option value="5-plus">5+ days</option>
                    </select>
                </div>
                <div class="hero-search__box hero-search__box--guests" data-for="travelers" hidden>
                    <x-icon name="users" />
                    <div class="hero-search__guest" data-counter>
                        <span class="hero-search__guest-name" id="guest-travelers">Travelers</span>
                        <div class="hero-search__stepper">
                            <button type="button" data-step="-1" aria-label="Fewer travelers" disabled>&minus;</button>
                            <input type="text" name="travelers" value="1" readonly data-min="1" data-max="30" aria-labelledby="guest-travelers" disabled>
                            <button type="button" data-step="1" aria-label="More travelers">+</button>
                        </div>
                    </div>
                </div>

                <fieldset class="hero-search__box hero-search__box--guests hero-search__box--wide" data-for="guests" hidden>
                    <legend class="hero-search__box-title">Guests &middot; optional</legend>
                    @foreach ($stayGuests as $key => $label)
                        <div class="hero-search__guest" data-counter>
                            <span class="hero-search__guest-name" id="guest-{{ $key }}">{{ $label }}</span>
                            <div class="hero-search__stepper">
                                <button type="button" data-step="-1" aria-label="Fewer {{ strtolower($label) }}" disabled>&minus;</button>
                                <input type="text" name="{{ $key }}" value="0" readonly data-min="0" data-max="20" aria-labelledby="guest-{{ $key }}" disabled data-guest>
                                <button type="button" data-step="1" aria-label="More {{ strtolower($label) }}">+</button>
                            </div>
                        </div>
                    @endforeach
                    <p class="hero-search__guest-total" aria-live="polite" data-guest-total>Any group size</p>
                </fieldset>
            </div>
            <div class="hero-search__foot">
                <p class="hero-search__hint" data-search-hint aria-live="polite"></p>
                <button type="button" class="hero-search__reset" data-search-reset>Reset</button>
            </div>
        </div>

        <button type="submit" class="btn btn-accent hero-search__go">Explore <x-icon name="arrow-right" /></button>
    </form>
    <script src="{{ asset('js/hero-search.js') }}?v={{ filemtime(public_path('js/hero-search.js')) }}" defer></script>



    <div class="stats">
        <div class="stats-strip">
            <div class="stat-item">
                <div class="stat-num" data-count-up>{{ $stats['destinations'] }}+</div>
                <div class="stat-label">Destinations</div>
            </div>
            <div class="stat-item">
                <div class="stat-num" data-count-up>{{ $stats['regions'] }}</div>
                <div class="stat-label">Cities &amp; Provinces</div>
            </div>
            <div class="stat-item">
                <div class="stat-num" data-count-up>{{ $stats['accommodations'] }}+</div>
                <div class="stat-label">Accommodations</div>
            </div>
            {{-- No fabricated placeholder here: this stood at a hard-coded
                 "4.8" whenever nothing was rated yet, which is a review score
                 no traveller ever gave. If there is nothing real to show, the
                 stat simply stands down. --}}
            @if ($stats['avg_rating'])
                <div class="stat-item stat-item--rating">
                    <div class="stat-num" data-count-up>{{ number_format($stats['avg_rating'], 1) }}</div>
                    <div class="stat-label">Traveler Rating</div>
                </div>
            @endif
        </div>
    </div>

    {{-- Phones only (see hero-search.css): the planner is the heart of the site, and the first screen is otherwise a search box. --}}
    <div class="hero-plan-cta">
        <p><strong>Not sure where to start?</strong> Tell us what you like and we will build your day-by-day plan.</p>
        <a href="{{ route('plan.edit') }}" class="btn btn-outline">Plan my trip <x-icon name="arrow-right" /></a>
    </div>

    <a href="#known-for" class="hero-scroll-cue">
        Discover Davao
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
    </a>
</div>
</div>{{-- /.hero-screen --}}

@include('partials.mood-cards')
@include('partials.culinary-highlights')

@php
    // Scene mapping now lives on Destination::illustrationScene() -- reused
    // by the destination detail page too, not just this homepage section.
    $featuredDestination = $destinations->sortByDesc('rating')->first();
    $gridDestinations = $featuredDestination
        ? $destinations->reject(fn ($d) => $d->id === $featuredDestination->id)
        : $destinations;
@endphp

<section class="section dpost-section landing-section landing-section--white home-split" id="destinations">
    <div class="container">
        {{-- Two screens on a desktop: the heading and the featured place, then the grid (see css/home-slides.css). --}}
        <div class="slide-part">
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
                    <p>Spots across the Davao Region, ranked by traveler ratings.</p>
                @else
                    <p>Spots across the Davao Region.</p>
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
                    @if ($featuredPhoto = $featuredDestination->coverPhoto())
                        <img src="{{ $featuredPhoto->cardUrl() }}" alt="" class="dpost-card__photo" loading="lazy" decoding="async">
                    @else
                        @include('partials.poster-illustration', ['scene' => $featuredScene])
                    @endif
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
                    @php $featuredProvenance = app(\App\Support\SampleData::class)->status($featuredDestination); @endphp
                    @if ($featuredProvenance === \App\Support\SampleData::OFFICIAL)
                        <p class="dpost-banner__blurb">Region XI's highest-rated destination &mdash; a DOT-accredited must-see that sets the bar for every other stop on your itinerary.</p>
                    @elseif ($featuredProvenance === \App\Support\SampleData::SAMPLE)
                        <p class="dpost-banner__blurb">A featured sample place &mdash; a temporary demonstration record, shown while the official DOT list is being completed.</p>
                    @else
                        <p class="dpost-banner__blurb">A featured place whose DOT accreditation is not yet verified.</p>
                    @endif
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

        </div>

        <div class="slide-part">
        <div class="dpost-grid">
            @forelse ($gridDestinations as $destination)
                @include('partials.listing-poster-card', ['listing' => $destination])
            @empty
                <p>Destinations will appear here once the catalog is seeded.</p>
            @endforelse
        </div>
        </div>
    </div>
</section>

@if ($packages->count())
{{-- The same card as the packages page and every other grid. This strip kept its own older card, which also
     ribboned one package "Most Popular" on a review-count proxy even while no package had a single review. --}}
<section class="section landing-section landing-section--tint slide-compact">
    <div class="container">
        <div class="dpost-head">
            <div>
                <span class="dpost-kicker poster-kicker">ready when you are</span>
                <h2 class="poster-title" style="color:var(--ocean-teal-dark);">Featured Tour Packages</h2>
                <p>All-inclusive experiences worth planning around.</p>
            </div>
            <a href="{{ route('packages.index') }}" class="btn dpost-cta">See all packages</a>
        </div>
        <div class="dpost-grid dpost-grid--three">
            @foreach ($packages as $package)
                @include('partials.listing-poster-card', ['listing' => $package])
            @endforeach
        </div>
    </div>
</section>
@endif

@include('partials.happening-soon')

<section class="section section-alt landing-section slide-compact" id="experiences">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="poster-kicker">how this works</span>
                <h2 class="poster-title" style="color:var(--ocean-teal-dark);">Plan smarter, not harder</h2>
                <p>ExploreDVO connects travelers directly with destinations, stays, and experiences across the Davao Region. Official DOT records are marked DOT Accredited; temporary demonstration records are marked Sample Data.</p>
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
                <h3>Clear Accreditation Labels</h3>
                <p>Official DOT Region XI records are marked DOT Accredited, temporary demonstration records are marked Sample Data, and anything we cannot confirm is marked Not Yet Verified.</p>
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

<section class="section about-region landing-section home-split" id="about-region">
    <div class="container">
        <div class="slide-part">
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

                <button type="button" class="read-more" data-read-more="aboutMoreText" aria-expanded="false" aria-controls="aboutMoreText" hidden>Read more</button>

                <p id="aboutMoreText" class="about-copy__more">What makes the region unusual is how close that wilderness sits to an ordinary working city. Davao City covers more ground than almost any other city in the country, yet the Philippine Eagle &mdash; the national bird, and one of the rarest raptors alive &mdash; still nests in the forest along its western edge. Fishing towns, durian orchards, Lumad communities and a full-service metro all share the same gulf, and moving between them takes hours rather than days.</p>

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

                <span class="about-regions-label">Browse by province</span>
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

        </div>

        <div class="slide-part">
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
    </div>
</section>

<section class="section landing-section landing-section--cream slide-compact">
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
                <h2 class="poster-title">Plan Your Davao Trip</h2>
                <p>
                    Two ways to get started &mdash; build a day-by-day plan around your own
                    preferences, or pick a ready-made itinerary from a tour operator.
                </p>
            </div>

            {{--
                Two related but distinct actions, not one CTA with a second
                tacked on: each gets its own line of context so a tourist
                can tell at a glance which path fits them, rather than
                landing on "Plan My Trip" by default and discovering tour
                packages exist somewhere else entirely.
            --}}
            <div class="cta-banner__paths">
                <div class="cta-banner__path">
                    <h3>Personalized itinerary</h3>
                    <p>Answer a few questions about your budget, duration and interests and get a custom day-by-day plan.</p>
                    <a href="{{ route('plan.edit') }}" class="btn">Plan my trip &rarr;</a>
                </div>
                <div class="cta-banner__path">
                    <h3>Tour packages</h3>
                    <p>Already know you want a guided trip? Browse ready-made itineraries offered by tour operators.</p>
                    <a href="{{ route('packages.index') }}" class="btn btn-ghost">Browse tour packages</a>
                </div>
            </div>
        </div>
    </div>
</section>
</main>

@include('partials.footer')
@include('partials.chatbot-widget')

<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
<script src="{{ asset('js/hero-fit.js') }}?v={{ filemtime(public_path('js/hero-fit.js')) }}" defer></script>
<script src="{{ asset('js/count-up.js') }}?v={{ filemtime(public_path('js/count-up.js')) }}" defer></script>
<script src="{{ asset('js/mood-slideshow.js') }}?v={{ filemtime(public_path('js/mood-slideshow.js')) }}" defer></script>
<script src="{{ asset('js/read-more.js') }}?v={{ filemtime(public_path('js/read-more.js')) }}" defer></script>
</body>
</html>
