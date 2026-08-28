<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'ExploreDVO')</title>
    @include('partials.head-assets')
</head>
<body>
    @include('partials.header')

    @yield('content')

    @include('partials.footer')
    @include('partials.chatbot-widget')

    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
</body>
</html>
