@props(['promotions', 'listing'])

{{--
    "Current promo" on a stay's page: the live promos its owner posted (only Showing ones are passed in), in the words the
    owner wrote. A promo is a title and a few words, never a computed price cut, so nothing here is struck through or
    shows a percentage. The code chip appears only when a code was entered; the validity line says what the dates mean.
    "Ask about this promo" goes to the stay's own website or social page: an inquiry, not a checkout, and left out when
    the stay has no link saved.
--}}
@php
    $contact = collect([$listing->website_url ?? null, $listing->facebook_url ?? null, $listing->instagram_url ?? null, $listing->tiktok_url ?? null])
        ->first(fn ($url) => filled($url));
@endphp

@foreach ($promotions as $promotion)
    <section class="promo-card" aria-labelledby="promo-title-{{ $promotion->id }}">
        <div class="promo-card__top">
            <span class="promo-card__eyebrow"><x-icon name="ticket" aria-hidden="true" />Your Promo</span>
            <span class="promo-card__status"><span class="promo-card__dot" aria-hidden="true"></span>Showing</span>
        </div>
        <h3 class="promo-card__title" id="promo-title-{{ $promotion->id }}">{{ $promotion->title }}</h3>
        @if (filled($promotion->description))
            <p class="promo-card__text">{{ $promotion->description }}</p>
        @endif
        <div class="promo-card__meta">
            @if (filled($promotion->code))
                <button type="button" class="promo-copy" data-copy-code="{{ $promotion->code }}" aria-label="Copy promo code {{ $promotion->code }}">
                    <span class="promo-copy__code">{{ $promotion->code }}</span>
                    <x-icon name="copy" class="promo-copy__icon" aria-hidden="true" />
                    <span class="promo-copy__msg" data-copy-msg aria-live="polite">Copy</span>
                </button>
            @endif
            <span class="promo-card__valid"><x-icon name="calendar" aria-hidden="true" />{{ $promotion->validityLine() }}</span>
        </div>
        @if ($contact)
            <a href="{{ $contact }}" target="_blank" rel="noopener noreferrer" class="promo-card__cta">Ask about this promo <x-icon name="arrow-right" aria-hidden="true" /><span class="sr-only">(opens in a new tab)</span></a>
        @endif
    </section>
@endforeach

@once
    <script src="{{ asset('js/promo-copy.js') }}?v={{ filemtime(public_path('js/promo-copy.js')) }}" defer></script>
@endonce
