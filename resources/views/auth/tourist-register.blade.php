@extends('layouts.app')

@section('title', 'Create Account — ExploreDVO')
@push('styles')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@800&family=Fraunces:wght@600;700&family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="{{ asset('css/portal-login.css') }}?v={{ filemtime(public_path('css/portal-login.css')) }}">
@endpush

@section('content')
<div class="portal-signin portal-signin--traveler portal-signin--register">
    <div class="portal-signin__card">
        <aside class="portal-poster portal-poster--photo" aria-label="Why create an account">
            <img class="portal-poster__art" src="{{ asset('images/postcards/island-beach-enhanced.webp') }}" alt="" width="1448" height="1086" decoding="async">
            <div class="portal-poster__intro">
                <a class="portal-poster__brand" href="{{ route('home') }}">
                    <x-brand-mark class="brand-icon" /> Explore<span>DVO</span>
                </a>
                <span class="portal-poster__badge">Free &middot; no email needed</span>
                <p class="portal-poster__headline">Keep your trips, skip the paperwork.</p>
                <p class="portal-poster__copy">Pick an alias and a password. Your itineraries and saved places follow you across visits and devices.</p>
            </div>
            <div class="portal-poster__network portal-poster__points">
                <ul>
                    <li><x-icon name="check" /> No real name or phone</li>
                    <li><x-icon name="check" /> Itineraries saved to your account</li>
                    <li><x-icon name="check" /> Completely optional</li>
                </ul>
            </div>
        </aside>

        <section class="portal-signin__main" aria-labelledby="traveler-register-title">
            <h1 id="traveler-register-title">Create a free account</h1>
            <p class="portal-signin__subtitle">Save your itineraries and places across visits.</p>

            @if (session('pending_save_itinerary'))
                <div class="privacy-note">
                    <x-icon name="shield-check" />
                    <p>Your current itinerary is waiting &mdash; create an account below and it will be ready to save.</p>
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-error" role="alert">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('account.register') }}" data-register-form>
                @csrf

                <div class="field">
                    <label for="alias">Alias</label>
                    <div class="portal-signin__input">
                        <x-icon name="user" aria-hidden="true" />
                        <input type="text" id="alias" name="alias" value="{{ old('alias') }}" required autofocus
                               minlength="3" maxlength="30" pattern="[A-Za-z0-9_\-]+" autocomplete="username" aria-describedby="alias_hint">
                        <span class="portal-signin__ok" data-alias-ok hidden aria-hidden="true"><x-icon name="check" /></span>
                    </div>
                    <p class="portal-signin__hint" id="alias_hint">3&ndash;30 characters, letters/numbers/dashes only. No real name needed.</p>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="portal-signin__input password-field">
                        <x-icon name="lock" aria-hidden="true" />
                        <input type="password" id="password" name="password" required minlength="8"
                               autocomplete="new-password" aria-describedby="password_hint">
                        @include('partials.password-toggle', ['target' => 'password'])
                    </div>
                    <div class="portal-strength" data-strength aria-hidden="true"><i></i><i></i><i></i><i></i></div>
                    <ul class="portal-checks" id="password_hint">
                        <li data-check="length"><x-icon name="circle" class="is-off" /><x-icon name="circle-check" class="is-on" /> 8+ characters</li>
                        <li data-check="mix"><x-icon name="circle" class="is-off" /><x-icon name="circle-check" class="is-on" /> Letters and numbers (stronger, optional)</li>
                    </ul>
                    <p class="sr-only" role="status" aria-live="polite" data-strength-text></p>
                </div>

                <div class="field">
                    <label for="password_confirmation">Confirm password</label>
                    <div class="portal-signin__input password-field">
                        <x-icon name="lock" aria-hidden="true" />
                        <input type="password" id="password_confirmation" name="password_confirmation" required minlength="8"
                               autocomplete="new-password" aria-describedby="match_hint">
                        @include('partials.password-toggle', ['target' => 'password_confirmation'])
                    </div>
                    <p class="portal-signin__hint" id="match_hint" data-match role="status" aria-live="polite"></p>
                </div>

                {{--
                    No email, no real name, no phone -- an alias is a login handle, not an identity. There is also no
                    password-reset flow (see auth/portal-login.blade.php's comment on the same limitation), so this is
                    worth saying plainly before someone picks a password they can't recreate later.
                --}}
                <div class="portal-signin__notice" role="note">
                    <x-icon name="alert-triangle" />
                    <p><strong>There is no password recovery.</strong> A lost password means creating a new account, so pick one you will remember.</p>
                </div>

                <button type="submit" class="portal-signin__submit">Create Account</button>
            </form>

            <p class="portal-signin__help">Already have an account? <a href="{{ route('account.login') }}">Sign in</a></p>
        </section>
    </div>
</div>
<script src="{{ asset('js/register-checks.js') }}?v={{ filemtime(public_path('js/register-checks.js')) }}" defer></script>
@endsection
