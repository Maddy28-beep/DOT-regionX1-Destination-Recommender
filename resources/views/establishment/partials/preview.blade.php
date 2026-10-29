{{--
    How a traveller sees the listing right now: the cover photo, the name, price, hours and any promo showing.
    Expects $establishment, $listing.
--}}
@php
    $cover = $listing->photos->first(fn ($p) => $p->is_primary && ! $p->isIllustration()) ?? $listing->photos->first(fn ($p) => ! $p->isIllustration());
    $closedNotice = method_exists($listing, 'operatingNotice') ? $listing->operatingNotice() : null;
    $hoursColumn = \App\Services\Establishment\ListingReadiness::hoursColumn($establishment->listing_kind);
    $hoursText = $hoursColumn ? trim((string) $listing->{$hoursColumn}) : '';
    $price = match ($establishment->listing_kind) {
        'accommodation' => $listing->price_per_night ? '₱'.number_format($listing->price_per_night).' per night' : null,
        'package' => $listing->price_per_pax ? '₱'.number_format($listing->price_per_pax).' per person' : null,
        default => null,
    };
    $promos = \App\Models\Promotion::forListing($establishment->listing_kind, $listing->id)->active()->latest()->limit(3)->get();
    $kindLabel = ucwords(str_replace('_', ' ', $establishment->listing_kind));
@endphp
<div class="ptr-pv">
    <div class="ptr-pv__img" @if ($cover) style="background-image:url('{{ $cover->url() }}')" @endif>
        <span class="ptr-pv__tag">{{ $kindLabel }}</span>
        @if ($closedNotice)<span class="ptr-pv__tag ptr-pv__tag--warn">Closed</span>@endif
    </div>
    <div class="ptr-pv__body">
        <strong>{{ $listing->name }}</strong>
        <small>{{ $listing->location }}@if ($listing->review_count > 0) &middot; &#9733; {{ number_format($listing->rating, 1) }} ({{ $listing->review_count }}) @endif</small>
        @if ($price || $listing->price_tier)
            <div class="ptr-pv__line">{{ collect([$price, $listing->price_tier])->filter()->implode(' · ') }}</div>
        @endif
        @if ($hoursColumn)
            <div class="ptr-pv__line {{ $hoursText === '' ? 'is-warn' : '' }}">{{ $hoursText !== '' ? $hoursText : 'Opening hours not added yet' }}</div>
        @endif
        <div class="ptr-pv__line ptr-pv__line--muted">{{ $cover ? ($cover->creditLine() ?? 'Main photo has no credit') : 'No photo yet, travellers see an illustration' }}</div>
        @if ($promos->isNotEmpty())
            <div class="ptr-tags">@foreach ($promos as $promo)<span class="ptr-tag ptr-tag--promo">{{ $promo->title }}</span>@endforeach</div>
        @endif
    </div>
</div>
