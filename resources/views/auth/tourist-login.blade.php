@extends('layouts.app')

@section('title', 'Sign In — ExploreDVO')

@section('content')
<div class="auth-shell">
    <div class="auth-card">
        <h1>Sign in to ExploreDVO</h1>
        <p class="auth-sub">Access your personalized recommendations and itineraries.</p>

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

        <form method="POST" action="{{ route('tourist.login') }}">
            @csrf
            <div class="field">
                <label for="email">Email address</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block" style="margin-top:22px;">Sign In</button>
        </form>

        <p class="auth-foot">New here? <a href="{{ route('tourist.register') }}">Create a traveler account</a></p>
    </div>
</div>
@endsection
