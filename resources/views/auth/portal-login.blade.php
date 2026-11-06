@extends('layouts.app')

@section('title', 'Partner Portal Sign In — ExploreDVO')

@push('styles')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="{{ asset('css/portal-login.css') }}?v={{ filemtime(public_path('css/portal-login.css')) }}">
@endpush

@section('content')
@php
    $portal = request('portal', old('portal', 'establishment')) === 'admin' ? 'admin' : 'establishment';
@endphp
<div class="portal-signin">
    <div class="portal-signin__card" data-portal-signin>
        <aside class="portal-poster portal-poster--photo" aria-label="ExploreDVO partner network">
            <img class="portal-poster__art" src="{{ asset('images/postcards/mountain-peak-enhanced.webp') }}" alt="" width="1254" height="1254" decoding="async">
            <div class="portal-poster__intro">
                <a class="portal-poster__brand" href="{{ route('home') }}">
                    <x-brand-mark class="brand-icon" /> Explore<span>DVO</span>
                </a>
                <span class="portal-poster__badge">DOT Region XI · Davao</span>
                <p class="portal-poster__headline">Your gateway to Davao’s tourism network.</p>
                <p class="portal-poster__copy">Manage your listing and help travelers discover the Davao Region.</p>
            </div>
            <div class="portal-poster__network">
                <span class="portal-poster__compass" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m16 8-2.5 5.5L8 16l2.5-5.5L16 8Z"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3"/></svg>
                </span>
                <div>
                    <strong>EST. PARTNER NETWORK</strong>
                    <span>Accredited stays, tours &amp; operators</span>
                </div>
            </div>
        </aside>

        <section class="portal-signin__main" aria-labelledby="portal-signin-title">
            <span class="portal-signin__welcome">welcome back</span>
            <h1 id="portal-signin-title">Partner Portal Sign&nbsp;In</h1>
            <p class="portal-signin__subtitle">DOT Region XI Partners &amp; Administrators</p>

            <nav class="portal-signin__tabs" data-portal-tabs aria-label="Account type">
                <a id="portal-tab-establishment" href="{{ route('portal.login', ['portal' => 'establishment']) }}" data-portal="establishment" @if ($portal === 'establishment') aria-current="page" @endif>
                    <x-icon name="briefcase" aria-hidden="true" /> Partner / Operator
                </a>
                <a id="portal-tab-admin" href="{{ route('portal.login', ['portal' => 'admin']) }}" data-portal="admin" @if ($portal === 'admin') aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 22s7-3 7-9V5l-7-3-7 3v8c0 6 7 9 7 9Z"/></svg> DOT Admin
                </a>
            </nav>

            <div id="portal-signin-panel">
                @if ($errors->any())
                    <div class="alert alert-error" role="alert">
                        @foreach ($errors->all() as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('portal.login') }}">
                    @csrf
                    <input type="hidden" name="portal" value="{{ $portal }}">

                    <div class="field">
                        <label for="identifier">Email address</label>
                        <div class="portal-signin__input">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                            <input type="email" id="identifier" name="identifier" value="{{ old('identifier') }}" placeholder="you@company.com" autocomplete="username" required>
                        </div>
                    </div>

                    <div class="field">
                        <div class="field-label-row">
                            <label for="password">Password</label>
                            <a class="field-label-row__aside" href="mailto:{{ config('dot.contact_email') }}?subject={{ rawurlencode('ExploreDVO portal - password reset request') }}">Forgot password?</a>
                        </div>
                        <div class="portal-signin__input password-field">
                            <x-icon name="lock" aria-hidden="true" />
                            <input type="password" id="password" name="password" required autocomplete="current-password">
                            @include('partials.password-toggle', ['target' => 'password'])
                        </div>
                    </div>

                    <button type="submit" class="portal-signin__submit" data-portal-submit>
                        Log In as {{ $portal === 'admin' ? 'DOT Admin' : 'Partner / Operator' }}
                    </button>
                </form>

                <p class="portal-signin__help" data-portal-help="admin" @if ($portal !== 'admin') hidden @endif>
                    DOT Region XI staff only. Trouble signing in?
                    <a href="mailto:{{ config('dot.contact_email') }}?subject={{ rawurlencode('ExploreDVO admin portal - sign-in help') }}">Contact the system administrator</a>
                </p>
                <p class="portal-signin__help" data-portal-help="establishment" @if ($portal !== 'establishment') hidden @endif>
                    Not an accredited establishment yet? <a href="{{ route('portal.establishment.register') }}">Request a partner account</a>
                </p>
            </div>
        </section>
    </div>
</div>
<script src="{{ asset('js/portal-login.js') }}?v={{ filemtime(public_path('js/portal-login.js')) }}" defer></script>
@endsection
