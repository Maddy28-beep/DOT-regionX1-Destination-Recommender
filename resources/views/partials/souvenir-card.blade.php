@php
    $gradients = [
        'linear-gradient(135deg,#7a4fc9,#4f2f96)',
        'linear-gradient(135deg,#c9932f,#916b19)',
        'linear-gradient(135deg,#0b6b4f,#14876a)',
        'linear-gradient(135deg,#1d6fa5,#0b4d75)',
    ];
    $gradient = $gradients[$souvenirCenter->id % count($gradients)];
    $photos = $souvenirCenter->relationLoaded('photos') ? $souvenirCenter->photos : collect();
@endphp
<a href="{{ route('souvenir-centers.show', $souvenirCenter) }}" class="dest-card">
    @if ($photos->isNotEmpty())
        <div class="dest-media-carousel" @if($photos->count() > 1) data-carousel @endif>
            <div class="carousel-track">
                @foreach ($photos as $photo)
                    <div class="carousel-slide"><img src="{{ $photo->url() }}" loading="lazy" alt="{{ $souvenirCenter->name }}"></div>
                @endforeach
            </div>
            @if ($souvenirCenter->is_accredited)
                <span class="badge badge-accredited"><img src="{{ asset('images/dot-seal.jpg') }}" alt="DOT Seal"> DOT Accredited</span>
            @endif
            <span class="rating-pill">@if ($souvenirCenter->review_count > 0) &#9733; {{ number_format($souvenirCenter->rating, 1) }} @else Not yet rated @endif</span>
            <span class="dest-media-name">{{ $souvenirCenter->name }}</span>
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
            @if ($souvenirCenter->is_accredited)
                <span class="badge badge-accredited"><img src="{{ asset('images/dot-seal.jpg') }}" alt="DOT Seal"> DOT Accredited</span>
            @endif
            <span class="rating-pill">@if ($souvenirCenter->review_count > 0) &#9733; {{ number_format($souvenirCenter->rating, 1) }} @else Not yet rated @endif</span>
            <span class="dest-media-name">{{ $souvenirCenter->name }}</span>
        </div>
    @endif
    <div class="dest-body">
        <div class="dest-loc">{{ $souvenirCenter->location }}@if($souvenirCenter->region) &middot; {{ $souvenirCenter->region->name }} @endif</div>
    </div>
</a>
