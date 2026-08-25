@php
    $gradients = [
        'linear-gradient(135deg,#1d6fa5,#0b4d75)',
        'linear-gradient(135deg,#0b6b4f,#14876a)',
        'linear-gradient(135deg,#c9932f,#916b19)',
        'linear-gradient(135deg,#7a4fc9,#4f2f96)',
    ];
    $gradient = $gradients[$accommodation->id % count($gradients)];
    $photos = $accommodation->relationLoaded('photos') ? $accommodation->photos : collect();
@endphp
<a href="{{ route('accommodations.show', $accommodation) }}" class="dest-card">
    @if ($photos->isNotEmpty())
        <div class="dest-media-carousel" @if($photos->count() > 1) data-carousel @endif>
            <div class="carousel-track">
                @foreach ($photos as $photo)
                    <div class="carousel-slide"><img src="{{ $photo->url() }}" loading="lazy" alt="{{ $accommodation->name }}"></div>
                @endforeach
            </div>
            @if ($accommodation->is_accredited)
                <span class="badge badge-accredited"><img src="{{ asset('images/dot-seal.jpg') }}" alt="DOT Seal"> DOT Accredited</span>
            @endif
            <span class="rating-pill">@if ($accommodation->review_count > 0) &#9733; {{ number_format($accommodation->rating, 1) }} @else Not yet rated @endif</span>
            <span class="dest-media-name">{{ $accommodation->name }}</span>
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
            @if ($accommodation->is_accredited)
                <span class="badge badge-accredited"><img src="{{ asset('images/dot-seal.jpg') }}" alt="DOT Seal"> DOT Accredited</span>
            @endif
            <span class="rating-pill">@if ($accommodation->review_count > 0) &#9733; {{ number_format($accommodation->rating, 1) }} @else Not yet rated @endif</span>
            <span class="dest-media-name">{{ $accommodation->name }}</span>
        </div>
    @endif
    <div class="dest-body">
        <div class="dest-loc">{{ $accommodation->location }}@if($accommodation->region) &middot; {{ $accommodation->region->name }} @endif</div>
        <div class="dest-tags">
            @if ($accommodation->type)<span class="dest-tag">{{ $accommodation->type }}</span>@endif
            @if ($accommodation->dot_classification)<span class="dest-tag">{{ $accommodation->dot_classification }}</span>@endif
        </div>
        <div class="dest-price">
            @if ($accommodation->price_per_night)
                &#8369;{{ number_format($accommodation->price_per_night) }} / night
            @else
                {{ $accommodation->price_tier ?? 'View pricing' }}
            @endif
        </div>
    </div>
</a>
