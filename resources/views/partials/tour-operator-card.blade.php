@php
    $gradients = [
        'linear-gradient(135deg,#1d6fa5,#0b4d75)',
        'linear-gradient(135deg,#0b6b4f,#14876a)',
        'linear-gradient(135deg,#c9932f,#916b19)',
        'linear-gradient(135deg,#7a4fc9,#4f2f96)',
    ];
    $gradient = $gradients[$tourOperator->id % count($gradients)];
    $photos = $tourOperator->relationLoaded('photos') ? $tourOperator->photos : collect();
@endphp
<a href="{{ route('tour-operators.show', $tourOperator) }}" class="dest-card">
    @if ($photos->isNotEmpty())
        <div class="dest-media-carousel" @if($photos->count() > 1) data-carousel @endif>
            <div class="carousel-track">
                @foreach ($photos as $photo)
                    <div class="carousel-slide"><img src="{{ $photo->url() }}" loading="lazy" alt="{{ $tourOperator->name }}"></div>
                @endforeach
            </div>
            @if ($tourOperator->is_accredited)
                <span class="badge badge-accredited"><img src="{{ asset('images/dot-seal.jpg') }}" alt="DOT Seal"> DOT Accredited</span>
            @endif
            <span class="rating-pill">@if ($tourOperator->review_count > 0) &#9733; {{ number_format($tourOperator->rating, 1) }} @else Not yet rated @endif</span>
            <span class="dest-media-name">{{ $tourOperator->name }}</span>
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
            @if ($tourOperator->is_accredited)
                <span class="badge badge-accredited"><img src="{{ asset('images/dot-seal.jpg') }}" alt="DOT Seal"> DOT Accredited</span>
            @endif
            <span class="rating-pill">@if ($tourOperator->review_count > 0) &#9733; {{ number_format($tourOperator->rating, 1) }} @else Not yet rated @endif</span>
            <span class="dest-media-name">{{ $tourOperator->name }}</span>
        </div>
    @endif
    <div class="dest-body">
        <div class="dest-loc">{{ $tourOperator->location }}@if($tourOperator->region) &middot; {{ $tourOperator->region->name }} @endif</div>
        <div class="dest-tags">
            @if ($tourOperator->specialization)<span class="dest-tag">{{ $tourOperator->specialization }}</span>@endif
        </div>
        <div class="dest-price">{{ $tourOperator->price_tier ?? 'Contact for rates' }}</div>
    </div>
</a>
