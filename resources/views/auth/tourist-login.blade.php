@extends('layouts.app')

@section('title', 'Sign In — ExploreDVO')

@section('content')
<div class="auth-shell">
    <div class="auth-card">
        <a href="{{ route('home') }}" class="brand poster-title auth-brand">
            <x-brand-mark class="brand-icon" />
            Explore<span class="dot">DVO</span>
        </a>

        <span class="poster-kicker">welcome back</span>
        <h1 class="page-title">Sign In</h1>
        <p class="auth-sub">Access your saved itineraries and places.</p>

        @if (session('pending_save_itinerary'))
            <div class="privacy-note" style="margin-top:0;">
                <x-icon name="shield-check" />
                <p>Your current itinerary is waiting &mdash; sign in and it will be ready to save.</p>
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('account.login') }}">
            @csrf

            <div class="field">
                <label for="alias">Alias</label>
                <input type="text" id="alias" name="alias" value="{{ old('alias') }}" required autofocus>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="password-field">
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                    @include('partials.password-toggle', ['target' => 'password'])
                </div>
            </div>

            <button type="submit" class="btn btn-accent btn-block" style="margin-top:22px;">Sign In</button>
        </form>

        <p class="auth-foot">Don&rsquo;t have an account yet? <a href="{{ route('account.register') }}">Create one for free</a></p>
    </div>
</div>
@endsection
