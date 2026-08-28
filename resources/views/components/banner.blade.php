@props(['tone' => 'info', 'action' => null])

{{--
    The one inline system-message component for the internal console --
    accreditation notices, "managed by DOT Admin" hints, empty-photo nudges.

    $tone: info | success | warn | danger. Only the tone tokens change; the
    icon slot, padding, radius and type scale are identical across all four,
    which is what keeps a warning in Overview reading the same as a warning
    in Photos. Message content is passed as the slot.
--}}

@php
    $tone = in_array($tone, ['info', 'success', 'warn', 'danger'], true) ? $tone : 'info';

    // Paths chosen per severity so the icon reinforces the tone token rather
    // than every banner sharing one generic glyph.
    $icon = match ($tone) {
        'success' => 'M20 6L9 17l-5-5',
        'warn' => 'M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
        'danger' => 'M12 8v5M12 16h.01M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z',
        default => 'M12 16v-5M12 8h.01M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z',
    };
@endphp

<div class="banner banner--{{ $tone }} {{ $action ? 'banner--with-action' : '' }}" role="{{ in_array($tone, ['warn', 'danger'], true) ? 'alert' : 'status' }}">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="{{ $icon }}"/>
    </svg>
    <p>{{ $slot }}</p>
    @if ($action)
        {{ $action }}
    @endif
</div>
