@php
    $gradients = [
        'linear-gradient(135deg,#ff6b35,#e2551f)',
        'linear-gradient(135deg,#0b6b4f,#14876a)',
        'linear-gradient(135deg,#1d6fa5,#0b4d75)',
        'linear-gradient(135deg,#7a4fc9,#4f2f96)',
    ];
    $gradient = $gradients[$package->id % count($gradients)];
    $photos = $package->relationLoaded('photos') ? $package->photos : collect();
@endphp
<a href="{{ route('packages.show', $package) }}" class="dest-card">
    @if ($photos->isNotEmpty())
        <div class="dest-media-carousel" @if($photos->count() > 1) data-carousel @endif>
            <div class="carousel-track">
                @foreach ($photos as $photo)
                    <div class="carousel-slide"><img src="{{ $photo->url() }}" loading="lazy" alt="{{ $package->name }}"></div>
                @endforeach
            </div>
            @if ($package->is_accredited)
                <span class="badge badge-accredited"><img src="{{ asset('images/dot-seal.jpg') }}" alt="DOT Seal"> DOT Accredited</span>
            @endif
            <span class="rating-pill">@if ($package->review_count > 0) &#9733; {{ number_format($package->rating, 1) }} @else Not yet rated @endif</span>
            <span class="dest-media-name">{{ $package->name }}</span>
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
            @if ($package->is_accredited)
                <span class="badge badge-accredited"><img src="{{ asset('images/dot-seal.jpg') }}" alt="DOT Seal"> DOT Accredited</span>
            @endif
            <span class="rating-pill">@if ($package->review_count > 0) &#9733; {{ number_format($package->rating, 1) }} @else Not yet rated @endif</span>
            <span class="dest-media-name">{{ $package->name }}</span>
        </div>
    @endif
    <div class="dest-body">
        <div class="dest-loc">{{ $package->duration_label }}@if($package->region) &middot; {{ $package->region->name }} @endif</div>
        <div class="dest-tags">
            @if ($package->type)<span class="dest-tag">{{ $package->type }}</span>@endif
            @if ($package->provider_name)<span class="dest-tag">{{ $package->provider_name }}</span>@endif
        </div>
        <div class="dest-price">
            @if ($package->price_per_pax)
                &#8369;{{ number_format($package->price_per_pax) }} / pax
            @else
                {{ $package->price_tier ?? 'View pricing' }}
            @endif
        </div>
    </div>
</a>
