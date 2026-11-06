@extends('layouts.app')

@section('title', 'Partner Account Request — ExploreDVO')
@push('styles')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@800&family=Fraunces:wght@600;700&family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="{{ asset('css/portal-login.css') }}?v={{ filemtime(public_path('css/portal-login.css')) }}">
@endpush

@section('content')
<div class="portal-signin portal-signin--traveler portal-signin--partner">
    <div class="portal-signin__card">
        <section class="portal-signin__main" aria-labelledby="partner-register-title">
            <span class="portal-signin__welcome">for tour operators &amp; establishments</span>
            <h1 id="partner-register-title">Request a partner account</h1>
            <p class="portal-signin__subtitle">Submitted accounts stay pending until reviewed by a DOT Region XI admin.</p>

            <ol class="portal-next" aria-label="What happens next">
                <li><span aria-hidden="true">1</span> You submit this request</li>
                <li><span aria-hidden="true">2</span> A DOT Region XI admin verifies your accreditation</li>
                <li><span aria-hidden="true">3</span> You sign in and manage your listing</li>
            </ol>

            @if ($errors->any())
                <div class="alert alert-error" role="alert">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('portal.establishment.register') }}">
                @csrf

                <div class="form-section">
                    <p class="portal-form-label"><span aria-hidden="true">1</span> Business details</p>

                    <div class="portal-form-grid">
                        <div class="field">
                            <label for="business_name">Business name</label>
                            <input type="text" id="business_name" name="business_name" value="{{ old('business_name') }}" required autofocus>
                        </div>

                        <div class="field">
                            <label for="listing_kind">Establishment type</label>
                            {{-- Already the site's custom select: `.field select` strips the OS
                                 control and supplies the shared chevron, so no extra class. --}}
                            <select id="listing_kind" name="listing_kind" required>
                                <option value="">Select...</option>
                                <option value="accommodation" @selected(old('listing_kind') === 'accommodation')>Accommodation</option>
                                <option value="restaurant" @selected(old('listing_kind') === 'restaurant')>Restaurant</option>
                                <option value="package" @selected(old('listing_kind') === 'package')>Tour Package Provider</option>
                                <option value="souvenir_center" @selected(old('listing_kind') === 'souvenir_center')>Souvenir Center</option>
                                <option value="tour_operator" @selected(old('listing_kind') === 'tour_operator')>Tour Operator</option>
                            </select>
                        </div>

                        <div class="field portal-form-grid__full">
                            <label for="claimed_accreditation_number">DOT accreditation number (if any)</label>
                            <input type="text" id="claimed_accreditation_number" name="claimed_accreditation_number" value="{{ old('claimed_accreditation_number') }}"
                                   aria-describedby="accreditation_hint">
                        </div>
                    </div>

                    {{-- The one place where someone discovers they need accreditation is
                         the field asking for its number, so the way to get one belongs
                         here. Carried as a .privacy-note callout -- the treatment Plan
                         Your Trip uses for guidance that must not be skimmed past. --}}
                    <div class="privacy-note" id="accreditation_hint">
                        <x-icon name="shield-check" />
                        <p>
                            ExploreDVO lists DOT-accredited businesses &mdash; it does not issue accreditation itself.
                            Not accredited yet?
                            <a href="{{ config('dot.accreditation_portal') }}" target="_blank" rel="noopener noreferrer" class="ext-link">
                                Apply on the DOT accreditation portal
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                    <path d="M15 3h6v6"/><path d="M10 14 21 3"/>
                                </svg>
                                <span class="sr-only">(opens in a new tab)</span>
                            </a>.
                            You can still submit this request now; a DOT Region XI admin verifies accreditation before approving it.
                        </p>
                    </div>
                </div>

                <div class="form-section">
                    <p class="portal-form-label"><span aria-hidden="true">2</span> Account credentials</p>

                    <div class="portal-form-grid">
                        <div class="field portal-form-grid__full">
                            <label for="email">Email address</label>
                            <input type="email" id="email" name="email" value="{{ old('email') }}" required>
                        </div>

                        <div class="field">
                            <label for="password">Password</label>
                            <div class="password-field">
                                <input type="password" id="password" name="password" required minlength="8"
                                       autocomplete="new-password" aria-describedby="password_hint">
                                @include('partials.password-toggle', ['target' => 'password'])
                            </div>
                            {{-- States the rule the validator actually applies (min:8).
                                 Anything stricter here is a promise the backend does not keep. --}}
                            <p class="portal-signin__hint" id="password_hint">At least 8 characters.</p>
                        </div>

                        <div class="field">
                            <label for="password_confirmation">Confirm password</label>
                            <div class="password-field">
                                <input type="password" id="password_confirmation" name="password_confirmation" required minlength="8"
                                       autocomplete="new-password">
                                @include('partials.password-toggle', ['target' => 'password_confirmation'])
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <p class="portal-form-label"><span aria-hidden="true">3</span> Contact person</p>

                    <div class="portal-form-grid">
                        <div class="field">
                            <label for="contact_person">Contact person</label>
                            <input type="text" id="contact_person" name="contact_person" value="{{ old('contact_person') }}" required>
                        </div>

                        <div class="field">
                            <label for="contact_number">Contact number</label>
                            <input type="text" id="contact_number" name="contact_number" value="{{ old('contact_number') }}" required>
                        </div>
                    </div>
                </div>

                {{--
                    Consent is an explicit act, not something inferred from pressing
                    Submit: this form collects credentials and business records. The
                    `accepted` rule on the server is what enforces it -- the HTML
                    `required` attribute only stops an honest browser.
                --}}
                <label class="field-check consent-check portal-consent">
                    <input type="checkbox" name="terms_accepted" value="1" required @checked(old('terms_accepted'))>
                    <span>
                        I agree to ExploreDVO&rsquo;s
                        <a href="{{ route('legal.terms') }}" target="_blank" rel="noopener">Terms of Service</a>
                        and
                        <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener">Privacy Policy</a>,
                        including how my business and account information will be used.
                    </span>
                </label>

                <button type="submit" class="portal-signin__submit">Submit for Review &rarr;</button>
            </form>

            <p class="portal-signin__help">Already approved? <a href="{{ route('portal.login') }}">Sign in</a></p>
        </section>
    </div>
</div>
@endsection
