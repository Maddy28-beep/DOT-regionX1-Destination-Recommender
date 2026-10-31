{{--
    "What Davao is known for": the landing page's mood cards, under the hero.

    Pick a mood and the card opens the catalogue that fits it, with how many places are in it. Each card is a
    postcard_slides record (kicker, title, button label, link, photo, focal point), so the DOT admin keeps editing
    them in the same place. The count is worked out from the card's own link when it points at a catalogue
    (see PostcardSlide::listingCount()), so it is always the number of places the click lands on; a card that
    links to one place, or away from the site, simply has no count.

    On a wide screen the cards sit in one row; on a phone or tablet the row swipes sideways.

    $postcardSlides  Collection<PostcardSlide>, already active + ordered
--}}
@if ($postcardSlides->isNotEmpty())
    <section class="section landing-section landing-section--tint moods" id="known-for" aria-labelledby="moodsTitle">
        <div class="container">
            {{-- The same heading as every other landing section (Popular Destinations, Featured Tour Packages...). --}}
            <div class="dpost-head">
                <div>
                    <span class="dpost-kicker poster-kicker">a quick postcard tour</span>
                    <h2 class="poster-title" id="moodsTitle" style="color:var(--ocean-teal-dark);">What Davao is known for</h2>
                    <p>Pick a mood and we'll show you every spot that fits.</p>
                </div>
            </div>

            <ul class="moods__row" role="list">
                @foreach ($postcardSlides as $slide)
                    @php
                        $count = $slide->listingCount();
                        $label = trim(preg_replace('/\s*(?:→|->|&rarr;)\s*$/u', '', $slide->cta_label)) ?: 'Explore';
                    @endphp
                    <li class="moods__item">
                        <a href="{{ $slide->ctaHref() }}" class="mood"
                           aria-label="{{ $slide->title }}@if ($count !== null), {{ $count }} {{ \Illuminate\Support\Str::plural('place', $count) }}@endif">
                            <span class="mood__photo{{ $slide->hasImageFile() ? '' : ' mood__photo--none' }}" style="--focal: {{ $slide->focalPoint() }};">
                                @if ($slide->hasImageFile())
                                    <img src="{{ $slide->cardUrl() }}" alt="" width="450" height="760" loading="lazy" decoding="async">
                                @else
                                    <x-icon name="camera" />
                                @endif
                            </span>

                            @if ($count !== null)
                                <span class="mood__count">[<span data-count-up>{{ $count }}</span>] {{ \Illuminate\Support\Str::plural('place', $count) }}</span>
                            @endif

                            <span class="mood__copy">
                                <span class="mood__kicker poster-kicker">{{ $slide->kicker }}</span>
                                <span class="mood__name poster-title">{{ $slide->title }}</span>
                                <span class="mood__cta">{{ $label }} <x-icon name="arrow-right" /></span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif
