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

    $min = $destination->entry_fee_min;
    $max = $destination->entry_fee_max;
    $fee = '—';
    $feePeso = false;

    if ($min !== null || $max !== null) {
        $lo = (float) ($min ?? 0);
        $hi = max($lo, (float) ($max ?? $min ?? 0));
        $feePeso = $hi > 0;

        $fee = match (true) {
            $hi <= 0 => 'Free',
            $lo <= 0 => 'Up to '.number_format($hi),
            $lo === $hi => number_format($lo),
            default => number_format($lo).'–'.number_format($hi),
        };
    }

    $distance = $destination->distance_km
        ? rtrim(rtrim(number_format((float) $destination->distance_km, 1), '0'), '.').' km'
        : '—';
@endphp

@include('partials.stat-strip', ['items' => [
    ['icon' => 'tag', 'label' => 'Price tier', 'text' => $tierLabel ?? '—', 'meter' => $destination->posterTier()],
    ['icon' => 'ticket', 'label' => 'Entry fee', 'text' => $fee, 'peso' => $feePeso],
    ['icon' => 'map-pin', 'label' => 'From city center', 'text' => $distance],
]])
