{{--
    The three facts under a destination's hero: price tier (with a three-sign band), entry fee, and
    distance from the city centre.

    Entry fee reads "Free" only when the fee is recorded as 0; a place with no fee on record shows a dash
    instead of claiming it is free.

    $destination
--}}
@php
    $tierLabel = match ($destination->price_tier) {
        'Budget-Friendly' => 'Budget',
        null, '' => null,
        default => $destination->price_tier,
    };
    $tierLevel = $destination->posterTier();

    $min = $destination->entry_fee_min;
    $max = $destination->entry_fee_max;
    $fee = null;
    $feeIsFree = false;

    if ($min !== null || $max !== null) {
        $lo = (float) ($min ?? 0);
        $hi = (float) ($max ?? $min ?? 0);
        $hi = max($lo, $hi);

        if ($hi <= 0) {
            $feeIsFree = true;
        } elseif ($lo <= 0) {
            $fee = 'Up to '.number_format($hi);
        } elseif ($lo === $hi) {
            $fee = number_format($lo);
        } else {
            $fee = number_format($lo).'–'.number_format($hi);
        }
    }

    $distance = $destination->distance_km
        ? rtrim(rtrim(number_format((float) $destination->distance_km, 1), '0'), '.').' km'
        : null;
@endphp

<div class="stat-strip">
    <div class="stat-strip__item">
        <span class="stat-strip__icon"><x-icon name="tag" /></span>
        <div>
            <span class="stat-strip__label">Price tier</span>
            <span class="stat-strip__value">
                <span class="stat-strip__text">{{ $tierLabel ?? '—' }}</span>
                @if ($tierLevel !== null && $tierLevel > 0)
                    <span class="stat-strip__meter" aria-hidden="true">
                        @for ($i = 1; $i <= 3; $i++)<span class="{{ $i <= $tierLevel ? 'is-on' : '' }}">&#8369;</span>@endfor
                    </span>
                @endif
            </span>
        </div>
    </div>

    <div class="stat-strip__item">
        <span class="stat-strip__icon"><x-icon name="ticket" /></span>
        <div>
            <span class="stat-strip__label">Entry fee</span>
            <span class="stat-strip__value">
                <span class="stat-strip__text">
                    @if ($feeIsFree)
                        Free
                    @elseif ($fee)
                        <span class="currency">&#8369;</span>{{ $fee }}
                    @else
                        —
                    @endif
                </span>
            </span>
        </div>
    </div>

    <div class="stat-strip__item">
        <span class="stat-strip__icon"><x-icon name="map-pin" /></span>
        <div>
            <span class="stat-strip__label">From city center</span>
            <span class="stat-strip__value"><span class="stat-strip__text">{{ $distance ?? '—' }}</span></span>
        </div>
    </div>
</div>
