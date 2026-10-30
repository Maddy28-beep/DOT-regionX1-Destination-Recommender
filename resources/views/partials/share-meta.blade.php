{{--
    Description, canonical link and Open Graph tags, so a shared link (Facebook, Messenger) and a search result show a
    proper title, summary and picture. A page can set its own with @section('meta_description') / @section('meta_image');
    the landing page passes $metaTitle in directly because it does not use the shared layout.
--}}
@php
    $metaTitle = $metaTitle ?? trim($__env->yieldContent('title', 'ExploreDVO'));
    $metaDescription = trim($__env->yieldContent('meta_description', 'ExploreDVO is the Department of Tourism Region XI platform for the Davao Region: destinations, stays, restaurants and tour packages, with a personalized day-by-day itinerary and no sign-up needed.'));
    $metaImage = trim($__env->yieldContent('meta_image')) ?: asset(file_exists(public_path('video/hero-poster.jpg')) ? 'video/hero-poster.jpg' : 'images/davo.webp');
@endphp
<meta name="description" content="{{ $metaDescription }}">
<link rel="canonical" href="{{ url()->current() }}">
<meta property="og:site_name" content="ExploreDVO">
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $metaTitle }}">
<meta property="og:description" content="{{ $metaDescription }}">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:image" content="{{ $metaImage }}">
<meta name="twitter:card" content="summary_large_image">
