{{--
    The ExploreDVO mark: Mt. Apo under a rising sun with the Philippine Eagle above, in a thin gold ring.

    A component rather than an <img> repeated in every header, footer and sign-in page, so changing the logo is one
    file. The picture is decorative (the wordmark beside it says "ExploreDVO"), hence the empty alt. The files are the
    approved artwork on a white disc, in two sizes, so a phone screen gets the sharper one (public/images/logo/).
--}}
<img {{ $attributes->merge([
    'src' => asset('images/logo/mark-96.webp'),
    'srcset' => asset('images/logo/mark-96.webp').' 96w, '.asset('images/logo/mark-192.webp').' 192w',
    'sizes' => '48px',
    'alt' => '',
    'width' => 96,
    'height' => 96,
    'decoding' => 'async',
]) }}>
