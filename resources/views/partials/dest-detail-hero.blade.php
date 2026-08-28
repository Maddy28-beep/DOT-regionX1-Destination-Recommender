@props(['destination'])

{{--
    Illustrated fallback hero for destination detail pages with no uploaded
    photos yet. Reuses the same scene art, halftone texture, scrim, and
    stamp badges as the homepage poster cards/banner -- one visual language,
    not a one-off. Real photos (when present) still take priority; see
    destinations/show.blade.php for the branching.
--}}
@php $scene = \App\Models\Destination::illustrationScene($destination->name); @endphp

<div class="dest-detail-hero">
    @include('partials.poster-illustration', ['scene' => $scene])
    <div class="halftone"></div>
    <div class="dpost-card__scrim"></div>

    @if ($destination->is_accredited)
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
        @if ($destination->review_count > 0) {{ number_format($destination->rating, 1) }} &middot; {{ $destination->review_count }} reviews @else New @endif
    </span>

    <div class="dest-detail-hero__content">
        <h1>{{ $destination->name }}</h1>
        <div class="loc">{{ $destination->location }}@if($destination->region) &middot; {{ $destination->region->name }}@endif</div>
    </div>
</div>
