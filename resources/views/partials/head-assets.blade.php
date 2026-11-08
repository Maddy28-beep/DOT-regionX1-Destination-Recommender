{{--
    Font + stylesheet loading for every layout.

    Order matters here. Barabara used to arrive ~800ms after first paint, so
    headings rendered in the fallback and then visibly snapped to the real face
    on every load. Two things caused that:

    1. The Google Fonts stylesheet was pulled in with `@import` at the top of
       app.css. An @import can only be discovered after app.css has downloaded
       AND parsed, which serialises the whole chain:
       HTML -> app.css -> googleapis css -> gstatic font files.
       Measured: app.css finished at 1022ms, the font CSS started at 1024ms,
       and the actual font files did not start until 1406ms.
    2. Nothing was preloaded, so BARABARA-final.otf only began downloading at
       1416ms once the browser hit an element that needed it.

    Declaring both in the document head lets the preload scanner start every
    font request while the HTML is still being parsed, in parallel rather than
    in sequence. `crossorigin` is required on the font preload even though the
    file is same-origin: fonts are always fetched in CORS mode, and without it
    the browser downloads the file a second time instead of reusing the
    preload.
--}}

{{-- The same mark as <x-brand-mark />, so the tab matches the page. Kept under images/ so it also loads on the tunnelled survey. --}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
<link rel="icon" href="{{ asset('images/logo/favicon-32.png') }}" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="{{ asset('images/logo/apple-touch-icon.png') }}">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
{{-- Loaded without blocking the first paint: it is a second server away, so as a normal stylesheet the page waited for it
     (PageSpeed: render-blocking requests). The text shows in the fallback face and switches when the font arrives
     (display=swap), as it already did. The noscript line keeps it working with JavaScript off. --}}
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Alfa+Slab+One&family=Caveat:wght@600;700&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Alfa+Slab+One&family=Caveat:wght@600;700&display=swap"></noscript>

<link rel="preload" href="{{ asset('fonts/BARABARA-final.otf') }}" as="font" type="font/otf" crossorigin>

<link rel="stylesheet" href="{{ \App\Support\StyleAsset::url('css/app.css') }}">
{{-- The phone navigation drawer: kept in its own file so app.css is not carrying it. --}}
<link rel="stylesheet" href="{{ \App\Support\StyleAsset::url('css/mobile-drawer.css') }}">
