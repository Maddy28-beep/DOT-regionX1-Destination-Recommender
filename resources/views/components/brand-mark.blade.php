@php
    /*
     * The ExploreDVO mark: Mt. Apo under a Davao sun, ringed in cream.
     *
     * A component rather than inlined markup because it renders at least twice
     * on every page (header and footer), and the gradient needs an id -- two
     * copies of a hard-coded id is invalid markup, and the second copy would
     * silently paint itself from the first one's definition.
     *
     * Colours are the brand's own and are deliberately NOT tokenised: this is
     * a fixed logo, so it must not restyle itself with its surroundings the
     * way the placeholder mark it replaced did. The cream ring is what keeps
     * it legible on the dark footer and over the hero photograph alike.
     */
    $gradientId = 'brand-mark-grad-'.\Illuminate\Support\Str::random(8);
@endphp

<svg {{ $attributes->merge(['viewBox' => '0 0 100 100']) }} xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <defs>
        <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#4CAF50"/>
            <stop offset="100%" stop-color="#1B5E20"/>
        </linearGradient>
    </defs>

    <circle cx="50" cy="50" r="48" fill="url(#{{ $gradientId }})" stroke="#FFFDD0" stroke-width="2"/>

    <circle cx="50" cy="48" r="22" fill="#F5A623"/>
    <g stroke="#F5A623" stroke-width="3" stroke-linecap="round">
        <line x1="50" y1="18" x2="50" y2="12"/>
        <line x1="33" y1="23" x2="28" y2="18"/>
        <line x1="67" y1="23" x2="72" y2="18"/>
    </g>

    <path d="M 12 75 L 35 45 L 48 58 L 68 32 L 88 75 Z" fill="#133316"/>
    <circle cx="68" cy="32" r="3" fill="#E63946"/>
</svg>
