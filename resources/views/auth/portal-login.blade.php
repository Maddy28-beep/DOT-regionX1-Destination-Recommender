@extends('layouts.app')

@section('title', 'Partner Portal Sign In — ExploreDVO')

@section('content')
<div class="auth-shell">
    <div class="auth-card">
        <h1>Partner Portal Sign In</h1>
        <p class="auth-sub">DOT Region XI Partners &amp; Administrators</p>

        @if ($errors->any())
            <div class="alert alert-error">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @php $portal = request('portal', old('portal', 'establishment')); @endphp

        <div class="tabs">
            <a href="{{ route('portal.login', ['portal' => 'establishment']) }}" class="tab {{ $portal === 'establishment' ? 'active' : '' }}" style="text-decoration:none; text-align:center;">Establishment / Tour Operator</a>
            <a href="{{ route('portal.login', ['portal' => 'admin']) }}" class="tab {{ $portal === 'admin' ? 'active' : '' }}" style="text-decoration:none; text-align:center;">DOT Admin</a>
        </div>

        <form method="POST" action="{{ route('portal.login') }}">
            @csrf
            <input type="hidden" name="portal" value="{{ $portal }}">

            <div class="field">
                <label for="identifier">{{ $portal === 'admin' ? 'Username' : 'Email address' }}</label>
                <input type="text" id="identifier" name="identifier" value="{{ old('identifier') }}" required autofocus>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block" style="margin-top:22px;">
                Log In as {{ $portal === 'admin' ? 'DOT Admin' : 'Establishment / Tour Operator' }}
            </button>
        </form>

        <p class="auth-foot">Not an accredited establishment yet? <a href="{{ route('portal.establishment.register') }}">Request a partner account</a></p>
    </div>
</div>
@endsection
