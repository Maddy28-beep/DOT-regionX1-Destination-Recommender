<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'ExploreDVO')</title>
    @include('partials.share-meta')
    @include('partials.head-assets')
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to main content</a>
    @include('partials.header')
    @include('partials.survey-invite-banner')

    <main id="main-content" tabindex="-1">
        @yield('content')
    </main>

    @include('partials.footer')
    @include('partials.chatbot-widget')

    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
    @include('partials.flash-toast')
</body>
</html>
