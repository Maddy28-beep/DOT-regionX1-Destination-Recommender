@extends('layouts.app')

@section('title', 'Create Account — ExploreDVO')

@section('content')
<div class="auth-shell">
    <div class="auth-card wide">
        <h1>Create your traveler account</h1>
        <p class="auth-sub">Sign up to get personalized destination recommendations and a day-by-day itinerary.</p>

        @if ($errors->any())
            <div class="alert alert-error">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('tourist.register') }}">
            @csrf
            <div class="field">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" value="{{ old('full_name') }}" required autofocus>
            </div>

            <div class="field">
                <label for="email">Email address</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required minlength="8">
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required minlength="8">
            </div>

            <div class="field">
                <label for="nationality">Nationality</label>
                <input type="text" id="nationality" name="nationality" value="{{ old('nationality') }}" required>
            </div>

            <div class="field">
                <label for="age_range">Age range</label>
                <select id="age_range" name="age_range" required>
                    <option value="">Select...</option>
                    <option value="Under 18">Under 18</option>
                    <option value="18-24">18-24</option>
                    <option value="25-34">25-34</option>
                    <option value="35-44">35-44</option>
                    <option value="45-59">45-59</option>
                    <option value="60+">60+</option>
                </select>
            </div>

            <div class="field">
                <label for="contact_number">Contact number (optional)</label>
                <input type="text" id="contact_number" name="contact_number" value="{{ old('contact_number') }}">
            </div>

            <label class="field-check">
                <input type="checkbox" name="privacy_consent" value="1" required>
                <span>I agree to the Data Privacy Notice (RA 10173).</span>
            </label>

            <button type="submit" class="btn btn-primary btn-block" style="margin-top:22px;">Create Account</button>
        </form>

        <p class="auth-foot">Already have an account? <a href="{{ route('tourist.login') }}">Sign in</a></p>
    </div>
</div>
@endsection
