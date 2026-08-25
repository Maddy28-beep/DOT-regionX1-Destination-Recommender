@php
    $gradients = [
        'linear-gradient(135deg,#c9932f,#916b19)',
        'linear-gradient(135deg,#0b6b4f,#14876a)',
        'linear-gradient(135deg,#ff6b35,#e2551f)',
        'linear-gradient(135deg,#1d6fa5,#0b4d75)',
    ];
    $gradient = $gradients[$restaurant->id % count($gradients)];
    $photos = $restaurant->relationLoaded('photos') ? $restaurant->photos : collect();
@endphp
<a href="{{ route('restaurants.show', $restaurant) }}" class="dest-card">
    @if ($photos->isNotEmpty())
        <div class="dest-media-carousel" @if($photos->count() > 1) data-carousel @endif>
            <div class="carousel-track">
                @foreach ($photos as $photo)
                    <div class="carousel-slide"><img src="{{ $photo->url() }}" loading="lazy" alt="{{ $restaurant->name }}"></div>
                @endforeach
            </div>
            @if ($restaurant->is_accredited)
                <span class="badge badge-accredited"><img src="{{ asset('images/dot-seal.jpg') }}" alt="DOT Seal"> DOT Accredited</span>
            @endif
            <span class="rating-pill">@if ($restaurant->review_count > 0) &#9733; {{ number_format($restaurant->rating, 1) }} @else Not yet rated @endif</span>
            <span class="dest-media-name">{{ $restaurant->name }}</span>
            @if ($photos->count() > 1)
                <div class="carousel-dots">
                    @foreach ($photos as $i => $photo)
                        <span class="dot {{ $i === 0 ? 'active' : '' }}"></span>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        <div class="dest-media" style="background:{{ $gradient }}">
            @if ($restaurant->is_accredited)
                <span class="badge badge-accredited"><img src="{{ asset('images/dot-seal.jpg') }}" alt="DOT Seal"> DOT Accredited</span>
            @endif
            <span class="rating-pill">@if ($restaurant->review_count > 0) &#9733; {{ number_format($restaurant->rating, 1) }} @else Not yet rated @endif</span>
            <span class="dest-media-name">{{ $restaurant->name }}</span>
        </div>
    @endif
    <div class="dest-body">
        <div class="dest-loc">{{ $restaurant->location }}@if($restaurant->region) &middot; {{ $restaurant->region->name }} @endif</div>
        <div class="dest-tags">
            @if ($restaurant->cuisine_type)<span class="dest-tag">{{ $restaurant->cuisine_type }}</span>@endif
        </div>
        <div class="dest-price">{{ $restaurant->price_tier ?? 'View menu' }}</div>
    </div>
</a>
