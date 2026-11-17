@php
    $photo = $listing->coverPhoto();
    $saveSegment = \App\Http\Controllers\SavedListingController::segmentFor($listing);
    $status = app(\App\Support\SampleData::class)->status($listing);
    $closed = method_exists($listing, 'operatingBadge') ? $listing->operatingBadge() : null;
@endphp
<article class="recap-place">
    @if ($saveSegment)
        <x-save-heart :type="$saveSegment" :listing="$listing" />
    @endif
    <a href="{{ $listing->posterUrl() }}" class="recap-place__link">
        <div class="recap-place__photo">
            @if ($photo)
                <img src="{{ $photo->cardUrl() }}" alt="" loading="lazy" decoding="async">
                @if ($photo->isIllustration())<span class="recap-place__image-note">Illustration</span>@endif
            @else
                @include('partials.poster-illustration', ['scene' => $listing->posterScene()])
            @endif
            @if ($closed)<span class="recap-place__image-note">{{ $closed }}</span>@endif
        </div>
        <div class="recap-place__body">
            <h3>{{ $listing->displayName() }}</h3>
            @if ($listing->posterMeta())<p><x-icon name="map-pin" />{{ $listing->posterMeta() }}</p>@endif
            <small class="recap-place__status">{{ \App\Support\SampleData::label($status) }}</small>
            <span class="recap-place__cta">View {{ $listing instanceof \App\Models\Destination ? 'destination' : 'details' }} <x-icon name="arrow-right" /></span>
        </div>
    </a>
</article>
