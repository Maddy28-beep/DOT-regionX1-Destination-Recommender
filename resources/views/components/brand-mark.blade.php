{{--
    The owner-supplied ExploreDVO eagle, durian and Davao Region circular emblem.

    A component rather than an <img> repeated in every header, footer and sign-in page, so changing the logo is one
    file. The picture is decorative (the wordmark beside it says "ExploreDVO"), hence the empty alt.
    Responsive files preserve the supplied transparent artwork without redrawing it.
--}}
<img {{ $attributes->merge([
    'src' => asset('images/logo/eagle-durian-96.webp'),
    'srcset' => asset('images/logo/eagle-durian-96.webp').' 96w, '.asset('images/logo/eagle-durian-192.webp').' 192w',
    'sizes' => '48px',
    'alt' => '',
    'width' => 96,
    'height' => 96,
    'decoding' => 'async',
]) }}>
