@extends('layouts.app')

@section('title', 'Partner Portal Sign In — ExploreDVO')

@section('content')
<div class="auth-shell">
    <div class="auth-card">
        <a href="{{ route('home') }}" class="brand poster-title auth-brand">
            <x-brand-mark class="brand-icon" />
            Explore<span class="dot">DVO</span>
        </a>

        <span class="poster-kicker">welcome back</span>
        <h1 class="page-title">Partner Portal Sign In</h1>
        <p class="auth-sub">DOT Region XI Partners &amp; Administrators</p>

        @if ($errors->any())
            <div class="alert alert-error">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        @php $portal = request('portal', old('portal', 'establishment')); @endphp

        {{--
            "Establishment / Tour Operator" needed 200px inside a 134px tab, so
            both states rendered as "Establishment / Tour Ope...". The tab is
            flex:1 in a two-up track, so there is no width to win back -- the
            label had to get shorter. The submit button below uses the same
            wording so the tab and the action it performs agree.
        --}}
        <div class="tabs">
            <a href="{{ route('portal.login', ['portal' => 'establishment']) }}" class="tab {{ $portal === 'establishment' ? 'active' : '' }}" style="text-decoration:none; text-align:center;">Partner / Operator</a>
            <a href="{{ route('portal.login', ['portal' => 'admin']) }}" class="tab {{ $portal === 'admin' ? 'active' : '' }}" style="text-decoration:none; text-align:center;">DOT Admin</a>
        </div>

        <form method="POST" action="{{ route('portal.login') }}">
            @csrf
            <input type="hidden" name="portal" value="{{ $portal }}">

            <div class="field">
                <label for="identifier">Email address</label>
                <input type="email" id="identifier" name="identifier" value="{{ old('identifier') }}" required autofocus>
            </div>

            <div class="field">
                <div class="field-label-row">
                    <label for="password">Password</label>
                    {{--
                        There is no self-service reset flow in this system: no
                        route, no controller, no token table, and both guards
                        store `password_hash` on their own models rather than
                        Laravel's users table, so the default broker would not
                        work unconfigured either.

                        Rather than link to a page that 404s, this points at the
                        recovery path that genuinely exists today -- accounts are
                        created and approved by DOT Region XI, so DOT Region XI
                        is who reissues them.
                    --}}
                    <a class="field-label-row__aside"
                       href="mailto:{{ config('dot.contact_email') }}?subject={{ rawurlencode('ExploreDVO portal - password reset request') }}">Forgot password?</a>
                </div>
                <div class="password-field">
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                    @include('partials.password-toggle', ['target' => 'password'])
                </div>
            </div>

            <button type="submit" class="btn btn-accent btn-block" style="margin-top:22px;">
                Log In as {{ $portal === 'admin' ? 'DOT Admin' : 'Partner / Operator' }}
            </button>
        </form>

        {{--
            Follows the active tab, the way the submit label already did. On the
            admin tab the establishment signup prompt was not merely irrelevant
            but misleading: DOT Admin is internal staff, and those accounts are
            issued by DOT, never self-requested.
        --}}
        @if ($portal === 'admin')
            <p class="auth-foot">
                DOT Region XI staff only. Trouble signing in?
                <a href="mailto:{{ config('dot.contact_email') }}?subject={{ rawurlencode('ExploreDVO admin portal - sign-in help') }}">Contact the system administrator</a>
            </p>
        @else
            <p class="auth-foot">Not an accredited establishment yet? <a href="{{ route('portal.establishment.register') }}">Request a partner account</a></p>
        @endif
    </div>
</div>
@endsection
