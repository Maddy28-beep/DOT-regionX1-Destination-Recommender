@extends('layouts.app')

@section('title', 'Sign In — ExploreDVO')
@push('styles')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@800&family=Fraunces:wght@600;700&family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="{{ asset('css/portal-login.css') }}?v={{ filemtime(public_path('css/portal-login.css')) }}">
@endpush

@section('content')
<div class="portal-signin portal-signin--traveler">
    <div class="portal-signin__card">
        <aside class="portal-poster portal-poster--photo" aria-label="Discover Davao Region">
            <img class="portal-poster__art" src="{{ asset('images/postcards/island-beach-enhanced.webp') }}" alt="" width="1448" height="1086" decoding="async">
            <div class="portal-poster__intro">
                <a class="portal-poster__brand" href="{{ route('home') }}">
                    <x-brand-mark class="brand-icon" /> Explore<span>DVO</span>
                </a>
                <span class="portal-poster__badge">Discover Davao Region</span>
                <p class="portal-poster__headline">Keep exploring, wherever you left off.</p>
                <p class="portal-poster__copy">Sign in to pick up your saved itineraries, favorite places, and travel plans across the region.</p>
            </div>
            <div class="portal-poster__network">
                <span class="portal-poster__compass" aria-hidden="true"><x-icon name="heart" /></span>
                <div><strong>FOR EVERY EXPLORER</strong><span>Save spots, build itineraries, travel smarter</span></div>
            </div>
        </aside>

        <section class="portal-signin__main" aria-labelledby="traveler-signin-title">
            <span class="portal-signin__welcome">welcome back</span>
            <h1 id="traveler-signin-title">Sign In</h1>
            <p class="portal-signin__subtitle">Use the alias and password you registered with.</p>

            @if (session('pending_save_itinerary'))
                <div class="privacy-note">
                    <x-icon name="shield-check" />
                    <p>Your current itinerary is waiting &mdash; sign in and it will be ready to save.</p>
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-error" role="alert">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('account.login') }}">
                @csrf

                <div class="field">
                    <label for="alias">Alias</label>
                    <div class="portal-signin__input">
                        <x-icon name="user" aria-hidden="true" />
                        <input type="text" id="alias" name="alias" value="{{ old('alias') }}" autocomplete="username" aria-describedby="alias-hint" required autofocus>
                    </div>
                    <small class="portal-signin__hint" id="alias-hint">Your alias is the name you picked when you registered, not your email.</small>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="portal-signin__input password-field">
                        <x-icon name="lock" aria-hidden="true" />
                        <input type="password" id="password" name="password" required autocomplete="current-password">
                        @include('partials.password-toggle', ['target' => 'password'])
                    </div>
                </div>

                <button type="submit" class="portal-signin__submit">Sign In</button>
            </form>

            <p class="portal-signin__help">Don&rsquo;t have an account yet? <a href="{{ route('account.register') }}">Create one for free</a></p>
            <p class="portal-signin__partner">Business partner or DOT staff? <a href="{{ route('portal.login') }}">Partner portal sign-in</a></p>
        </section>
    </div>
</div>
@endsection
