@extends('layouts.app')

@section('title', 'Partner Account Request — ExploreDVO')

@section('content')
<div class="auth-shell">
    <div class="auth-card wide">
        <h1>Request a partner account</h1>
        <p class="auth-sub">Submitted accounts stay pending until reviewed by a DOT Region XI admin.</p>

        @if ($errors->any())
            <div class="alert alert-error">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('portal.establishment.register') }}">
            @csrf
            <div class="field">
                <label for="business_name">Business name</label>
                <input type="text" id="business_name" name="business_name" value="{{ old('business_name') }}" required autofocus>
            </div>

            <div class="field">
                <label for="listing_kind">Establishment type</label>
                <select id="listing_kind" name="listing_kind" required>
                    <option value="">Select...</option>
                    <option value="accommodation" @selected(old('listing_kind') === 'accommodation')>Accommodation</option>
                    <option value="restaurant" @selected(old('listing_kind') === 'restaurant')>Restaurant</option>
                    <option value="package" @selected(old('listing_kind') === 'package')>Tour Package Provider</option>
                    <option value="souvenir_center" @selected(old('listing_kind') === 'souvenir_center')>Souvenir Center</option>
                    <option value="tour_operator" @selected(old('listing_kind') === 'tour_operator')>Tour Operator</option>
                </select>
            </div>

            <div class="field">
                <label for="claimed_accreditation_number">DOT accreditation number (if any)</label>
                <input type="text" id="claimed_accreditation_number" name="claimed_accreditation_number" value="{{ old('claimed_accreditation_number') }}">
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
                <label for="contact_person">Contact person</label>
                <input type="text" id="contact_person" name="contact_person" value="{{ old('contact_person') }}" required>
            </div>

            <div class="field">
                <label for="contact_number">Contact number</label>
                <input type="text" id="contact_number" name="contact_number" value="{{ old('contact_number') }}" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block" style="margin-top:22px;">Submit for Review</button>
        </form>

        <p class="auth-foot">Already approved? <a href="{{ route('portal.login') }}">Sign in</a></p>
    </div>
</div>
@endsection
