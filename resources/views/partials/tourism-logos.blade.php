{{-- The three tourism marks (Davao, Department of Tourism, Love the Philippines) on a light strip, so they read on dark
     backgrounds too. Used in the footer and on the partner sign-in card. --}}
<div class="tourism-strip {{ $class ?? '' }}" role="group" aria-label="Tourism partners">
    <img src="{{ asset('images/tourism-logos/davao.webp') }}" alt="Davao" class="tourism-strip__davao" width="200" height="67" loading="lazy" decoding="async">
    <img src="{{ asset('images/tourism-logos/dot.webp') }}" alt="Department of Tourism Philippines" class="tourism-strip__dot" width="100" height="99" loading="lazy" decoding="async">
    <img src="{{ asset('images/tourism-logos/love-philippines.webp') }}" alt="Love the Philippines" class="tourism-strip__love" width="220" height="94" loading="lazy" decoding="async">
</div>
