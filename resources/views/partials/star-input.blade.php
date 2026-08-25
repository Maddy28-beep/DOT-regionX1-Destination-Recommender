@php
    $current = old($name, $value ?? null);
@endphp
<div class="star-rating">
    @for ($i = 5; $i >= 1; $i--)
        <input type="radio" name="{{ $name }}" id="{{ $name }}_{{ $i }}" value="{{ $i }}" @checked((string) $current === (string) $i) {{ ($required ?? false) ? 'required' : '' }}>
        <label for="{{ $name }}_{{ $i }}" aria-label="{{ $i }} star{{ $i > 1 ? 's' : '' }}">&#9733;</label>
    @endfor
</div>
