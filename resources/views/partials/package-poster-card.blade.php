@props(['package', 'scene', 'mostPopular' => false])

<a href="{{ route('packages.show', $package) }}" class="dpost-card pkg-card">
    <div class="dpost-card__art">
        @include('partials.poster-illustration', ['scene' => $scene])
        <div class="halftone"></div>
        <div class="dpost-card__scrim"></div>

        @if ($mostPopular)
            <span class="dpost-ribbon dpost-ribbon--sm">Most Popular</span>
        @endif

        @if ($package->is_accredited)
            <span class="dpost-badge-accredited" title="DOT Accredited">
                <svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <circle cx="10" cy="10" r="8.5" fill="none" stroke="currentColor" stroke-width="1.4"/>
                    <circle cx="10" cy="10" r="3.4" fill="currentColor"/>
                </svg>
                DOT Accredited
            </span>
        @endif

        <span class="dpost-badge-rating">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="currentColor" d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg>
            @if ($package->review_count > 0) {{ number_format($package->rating, 1) }} @else New @endif
        </span>

        <span class="dpost-card__name">{{ $package->name }}</span>
    </div>

    <div class="pkg-card__body">
        <span class="pkg-itinerary">
            <svg viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="1.5" y="2.5" width="13" height="12" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.2"/><line x1="1.5" y1="6" x2="14.5" y2="6" stroke="currentColor" stroke-width="1.2"/><line x1="4.5" y1="1" x2="4.5" y2="4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><line x1="11.5" y1="1" x2="11.5" y2="4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
            {{ $package->duration_label }}@if($package->region) &middot; {{ $package->region->name }}@endif
        </span>

        <div class="dpost-tags">
            @if ($package->type)<span class="dpost-tag">{{ $package->type }}</span>@endif
            @if ($package->provider_name)<span class="dpost-tag">{{ $package->provider_name }}</span>@endif
        </div>

        <div class="pkg-footer">
            <div>
                @if ($package->price_per_pax)
                    <div class="pkg-price__starting">Starting at</div>
                    <div class="pkg-price"><span class="currency">&#8369;</span>{{ number_format($package->price_per_pax) }}</div>
                    <div class="pkg-price__label">per pax</div>
                @else
                    <div class="pkg-price pkg-price--tier">{{ $package->price_tier ?? 'View pricing' }}</div>
                @endif
            </div>
            <span class="btn pkg-book-btn">View Package &rarr;</span>
        </div>
    </div>
</a>
