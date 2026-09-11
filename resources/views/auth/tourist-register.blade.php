@extends('layouts.app')

@section('title', 'Create Account — ExploreDVO')

@section('content')
<div class="auth-shell">
    <div class="auth-card">
        <a href="{{ route('home') }}" class="brand poster-title auth-brand">
            <x-brand-mark class="brand-icon" />
            Explore<span class="dot">DVO</span>
        </a>

        <span class="poster-kicker">completely optional</span>
        <h1 class="page-title">Create a free account</h1>
        <p class="auth-sub">Keep your itineraries and saved places across visits and devices.</p>

        @if (session('pending_save_itinerary'))
            <div class="privacy-note" style="margin-top:0;">
                <x-icon name="shield-check" />
                <p>Your current itinerary is waiting &mdash; create an account below and it will be ready to save.</p>
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('account.register') }}">
            @csrf

            <div class="field">
                <label for="alias">Alias</label>
                <input type="text" id="alias" name="alias" value="{{ old('alias') }}" required autofocus
                       minlength="3" maxlength="30" pattern="[A-Za-z0-9_\-]+" aria-describedby="alias_hint">
                <p class="field-hint" id="alias_hint">3&ndash;30 characters, letters/numbers/dashes only. No real name needed.</p>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="password-field">
                    <input type="password" id="password" name="password" required minlength="8"
                           autocomplete="new-password" aria-describedby="password_hint">
                    @include('partials.password-toggle', ['target' => 'password'])
                </div>
                <p class="field-hint" id="password_hint">At least 8 characters.</p>
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <div class="password-field">
                    <input type="password" id="password_confirmation" name="password_confirmation" required minlength="8"
                           autocomplete="new-password">
                    @include('partials.password-toggle', ['target' => 'password_confirmation'])
                </div>
            </div>

            {{--
                No email, no real name, no phone -- an alias is a login handle,
                not an identity. There is also no password-reset flow (see
                auth/portal-login.blade.php's comment on the same limitation),
                so this is worth saying plainly before someone picks a password
                they can't recreate later.
            --}}
            <p class="field-hint">
                This account uses an alias instead of your real identity, and only keeps your
                itineraries and saved places. There's no password recovery &mdash; a lost
                password means creating a new account.
            </p>

            <button type="submit" class="btn btn-accent btn-block" style="margin-top:22px;">Create Account</button>
        </form>

        <p class="auth-foot">Already have an account? <a href="{{ route('account.login') }}">Sign in</a></p>
    </div>
</div>
@endsection
