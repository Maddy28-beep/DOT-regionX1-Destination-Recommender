@php
    $total = $photos->count();
@endphp

@if ($total > 0)
    <div data-gallery data-photos='@json($photos->map(fn ($p) => ["url" => $p->url(), "category" => $p->category])->values())'>
        {{-- Mobile: swipeable full-photo carousel (touch-native via scroll-snap) --}}
        <div class="gallery-hero-mobile" @if($total > 1) data-carousel @endif>
            <div class="carousel-track">
                @foreach ($photos as $i => $photo)
                    <a href="#" class="carousel-slide" data-open="{{ $i }}">
                        <img src="{{ $photo->url() }}" alt="{{ $title }}">
                    </a>
                @endforeach
            </div>
            <div class="badges">
                @if ($isAccredited)
                    <span class="dpost-badge-accredited" title="DOT Accredited">
                    <svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <circle cx="10" cy="10" r="8.5" fill="none" stroke="currentColor" stroke-width="1.4"/>
                        <circle cx="10" cy="10" r="3.4" fill="currentColor"/>
                    </svg>
                    DOT Accredited
                </span>
                @else
                    <span></span>
                @endif
                <span class="dpost-badge-rating">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="currentColor" d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg>
                @if ($reviewCount > 0) {{ $rating }} &middot; {{ $reviewCount }} reviews @else New @endif
            </span>
            </div>
            @if ($total > 1)
                <div class="carousel-dots">
                    @foreach ($photos as $i => $photo)
                        <span class="dot {{ $i === 0 ? 'active' : '' }}"></span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Desktop: Agoda-style grid (main + thumbnails) --}}
        <div class="gallery-hero {{ $total === 1 ? 'single' : '' }}">
            <a href="#" class="g-main" data-open="0">
                <img src="{{ $photos[0]->url() }}" alt="{{ $title }}">
            </a>
            @if ($total >= 2)
                @foreach ($photos->slice(1, 2) as $i => $photo)
                    <a href="#" class="g-thumb {{ $total === 2 ? 'g-thumb-span' : '' }}" data-open="{{ $i + 1 }}">
                        <img src="{{ $photo->url() }}" alt="{{ $title }}">
                        @if ($loop->last && $total > 3)
                            <div class="g-more-overlay">+{{ $total - 3 }} more</div>
                        @endif
                    </a>
                @endforeach
            @endif
            <div class="badges">
                @if ($isAccredited)
                    <span class="dpost-badge-accredited" title="DOT Accredited">
                    <svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <circle cx="10" cy="10" r="8.5" fill="none" stroke="currentColor" stroke-width="1.4"/>
                        <circle cx="10" cy="10" r="3.4" fill="currentColor"/>
                    </svg>
                    DOT Accredited
                </span>
                @else
                    <span></span>
                @endif
                <span class="dpost-badge-rating">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="currentColor" d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg>
                @if ($reviewCount > 0) {{ $rating }} &middot; {{ $reviewCount }} reviews @else New @endif
            </span>
            </div>
        </div>

        @php $categories = $photos->groupBy('category'); @endphp
        @if ($categories->count() > 1)
            <div class="gallery-cats">
                @foreach ($categories as $cat => $group)
                    <a href="#" class="chip" data-open="{{ $photos->search(fn ($p) => $p->id === $group->first()->id) }}">{{ $cat }} ({{ $group->count() }})</a>
                @endforeach
            </div>
        @endif

        <div class="lightbox" data-lightbox-el>
            <button type="button" class="lb-close" data-close aria-label="Close">&times;</button>
            <button type="button" class="lb-prev" data-prev aria-label="Previous photo"><x-icon name="chevron-left" /></button>
            <img class="lb-img" src="" alt="">
            <button type="button" class="lb-next" data-next aria-label="Next photo"><x-icon name="chevron-right" /></button>
            <div class="lb-meta"></div>
        </div>
    </div>

    <h1 class="poster-title" style="margin:20px 0 4px; font-size:clamp(1.6rem, 4vw, 2.2rem); color:var(--ocean-teal-dark);">{{ $title }}</h1>
    <div style="color:var(--muted); font-size:.95rem;">{{ $subtitle }}</div>
@else
    <div class="detail-hero" style="background:{{ $fallbackGradient }}">
        <div class="badges">
            @if ($isAccredited)
                <span class="dpost-badge-accredited" title="DOT Accredited">
                    <svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <circle cx="10" cy="10" r="8.5" fill="none" stroke="currentColor" stroke-width="1.4"/>
                        <circle cx="10" cy="10" r="3.4" fill="currentColor"/>
                    </svg>
                    DOT Accredited
                </span>
            @else
                <span></span>
            @endif
            <span class="dpost-badge-rating">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="currentColor" d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg>
                @if ($reviewCount > 0) {{ $rating }} &middot; {{ $reviewCount }} reviews @else New @endif
            </span>
        </div>
        <div class="detail-hero-content">
            <h1>{{ $title }}</h1>
            <div class="loc">{{ $subtitle }}</div>
        </div>
    </div>
@endif
