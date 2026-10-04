{{--
    "Popular Right Now": the full-bleed photo carousel under the hero.

    Everything shown (caption, call to action, photo, alt text, focal point)
    comes from the postcard_slides records. The caption and the button sit in a
    bar BELOW the photo rather than over it, so a bigger caption can never
    cover the subject. public/js/postcard-carousel.js drives it; without
    script the first slide, its caption and its button still read correctly.

    $postcardSlides  Collection<PostcardSlide>, already active + ordered
--}}
@if ($postcardSlides->isNotEmpty())
    @php($total = $postcardSlides->count())
    <section class="postcard-section" id="popular-right-now" aria-labelledby="popularRightNowTitle">
        <div class="pc" data-postcard-carousel data-count="{{ $total }}" aria-roledescription="carousel" aria-label="Popular right now">
            <div class="pc-stage" data-stage>
                @foreach ($postcardSlides as $slide)
                    <figure class="pc-slide{{ $loop->first ? ' is-active' : '' }}" data-slide
                        role="group" aria-roledescription="slide" aria-label="{{ $loop->iteration }} of {{ $total }}"
                        @unless ($loop->first) aria-hidden="true" @endunless
                        style="--focal: {{ $slide->focalPoint() }};">
                        <img src="{{ $slide->imageUrl() }}"
                            @if ($srcset = $slide->srcset()) srcset="{{ $srcset }}" sizes="100vw" @endif
                            alt="{{ $slide->alt_text }}"
                            @if ($loop->first) loading="eager" fetchpriority="high" @else loading="lazy" @endif
                            decoding="async">
                    </figure>
                @endforeach

                <div class="pc-heading">
                    <h2 class="poster-title" id="popularRightNowTitle">Popular Right Now</h2>
                    <p>A quick postcard tour of what Region XI is known for.</p>
                </div>

                @if ($total > 1)
                    <button type="button" class="pc-arrow pc-arrow--prev" data-prev aria-label="Previous slide"><x-icon name="chevron-left" /></button>
                    <button type="button" class="pc-arrow pc-arrow--next" data-next aria-label="Next slide"><x-icon name="chevron-right" /></button>
                @endif
            </div>

            <div class="pc-bar">
                <div class="pc-caption" data-caption aria-live="off" aria-atomic="true">
                    @foreach ($postcardSlides as $slide)
                        <div class="pc-caption__item" data-caption-item @unless ($loop->first) hidden @endunless>
                            <span class="pc-caption__kicker poster-kicker">{{ $slide->kicker }}</span>
                            <h3 class="pc-caption__name poster-title">{{ $slide->title }}</h3>
                            @if ($slide->location)
                                <p class="pc-caption__where"><x-icon name="map-pin" /> {{ $slide->location }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($total > 1)
                    <div class="pc-controls">
                        <div class="pc-thumbs" role="tablist" aria-label="Choose a slide">
                            @foreach ($postcardSlides as $slide)
                                <button type="button" class="pc-thumb{{ $loop->first ? ' is-active' : '' }}" data-thumb
                                    role="tab" aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                                    tabindex="{{ $loop->first ? '0' : '-1' }}"
                                    aria-label="{{ $loop->iteration }} of {{ $total }}: {{ $slide->title }}">
                                    <img src="{{ $slide->thumbUrl() }}" alt="" width="96" height="60" loading="lazy" decoding="async">
                                    <span class="pc-thumb__progress" aria-hidden="true"></span>
                                </button>
                            @endforeach
                        </div>
                        <button type="button" class="pc-toggle" data-toggle aria-label="Pause autoplay" aria-pressed="false">
                            <svg class="pc-toggle__pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>
                            <svg class="pc-toggle__play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5Z"/></svg>
                        </button>
                    </div>
                @endif

                <div class="pc-cta">
                    @foreach ($postcardSlides as $slide)
                        <a href="{{ $slide->ctaHref() }}" class="btn pc-cta__btn" data-cta @unless ($loop->first) hidden @endunless>{{ $slide->cta_label }}</a>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
@endif
