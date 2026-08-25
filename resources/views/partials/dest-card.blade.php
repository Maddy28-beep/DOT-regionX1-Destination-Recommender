@php
    $gradients = [
        'linear-gradient(135deg,#0b6b4f,#14876a)',
        'linear-gradient(135deg,#ff6b35,#e2551f)',
        'linear-gradient(135deg,#1d6fa5,#0b4d75)',
        'linear-gradient(135deg,#7a4fc9,#4f2f96)',
        'linear-gradient(135deg,#c9932f,#916b19)',
        'linear-gradient(135deg,#2f9e8f,#1b6b60)',
    ];
    $gradient = $gradients[$destination->id % count($gradients)];
    $photos = $destination->relationLoaded('photos') ? $destination->photos : collect();
@endphp
<a href="{{ route('destinations.show', $destination) }}" class="dest-card">
    @if ($photos->isNotEmpty())
        <div class="dest-media-carousel" @if($photos->count() > 1) data-carousel @endif>
            <div class="carousel-track">
                @foreach ($photos as $photo)
                    <div class="carousel-slide"><img src="{{ $photo->url() }}" loading="lazy" alt="{{ $destination->name }}"></div>
                @endforeach
            </div>
            @if ($destination->is_accredited)
                <span class="badge badge-accredited"><img src="{{ asset('images/dot-seal.jpg') }}" alt="DOT Seal"> DOT Accredited</span>
            @endif
            <span class="rating-pill">@if ($destination->review_count > 0) &#9733; {{ number_format($destination->rating, 1) }} @else Not yet rated @endif</span>
            <span class="dest-media-name">{{ $destination->name }}</span>
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
            @if ($destination->is_accredited)
                <span class="badge badge-accredited"><img src="{{ asset('images/dot-seal.jpg') }}" alt="DOT Seal"> DOT Accredited</span>
            @endif
            <span class="rating-pill">@if ($destination->review_count > 0) &#9733; {{ number_format($destination->rating, 1) }} @else Not yet rated @endif</span>
            <span class="dest-media-name">{{ $destination->name }}</span>
        </div>
    @endif
    <div class="dest-body">
        <div class="dest-loc">{{ $destination->location }}@if($destination->region) &middot; {{ $destination->region->name }} @endif</div>
        <div class="dest-tags">
            @foreach ($destination->tags->take(3) as $tag)
                <span class="dest-tag">{{ $tag->value }}</span>
            @endforeach
        </div>
        <div class="dest-price">{{ $destination->price_tier ?? 'View pricing' }}</div>
    </div>
</a>
