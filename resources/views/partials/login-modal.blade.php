{{--
    The sign-in modal: the same card as the Sign In page (photo on one side, the form on the other), opened over the page
    when a visitor who is not signed in taps the heart on a place. It posts to the same route as the page, over fetch, and
    stays where the visitor was; the place they tapped is saved as soon as they are in (see TouristAuthController::login).

    Only for guests, and not on the sign-in or sign-up pages themselves. Its two stylesheets are fetched the first time the
    modal is opened, so a visitor who never taps a heart downloads neither. Its script is public/js/login-modal.js.
--}}
@guest('tourist')
    @unless (request()->routeIs('account.login', 'account.register', 'portal.*'))
        <div class="login-modal" id="loginModal" data-login-modal hidden
             data-styles='@json([asset('css/portal-login.css').'?v='.filemtime(public_path('css/portal-login.css')), asset('css/login-modal.css').'?v='.filemtime(public_path('css/login-modal.css'))])'>
            <div class="login-modal__backdrop" data-login-close></div>
            <div class="login-modal__dialog portal-signin portal-signin--traveler" role="dialog" aria-modal="true" aria-labelledby="loginModalTitle" tabindex="-1">
                <button type="button" class="login-modal__close" data-login-close aria-label="Close sign in">&times;</button>
                <div class="portal-signin__card">
                    <aside class="portal-poster portal-poster--photo" aria-hidden="true">
                        <img class="portal-poster__art" src="{{ asset('images/postcards/island-beach-card.webp') }}" alt="" width="640" height="480" loading="lazy" decoding="async">
                        <div class="portal-poster__intro">
                            <span class="portal-poster__brand">
                                <x-brand-mark class="brand-icon" /> Explore<span>DVO</span>
                            </span>
                            <span class="portal-poster__badge">Discover Davao Region</span>
                            <p class="portal-poster__headline">Keep exploring, wherever you left off.</p>
                            <p class="portal-poster__copy">Sign in to pick up your saved itineraries, favorite places, and travel plans across the region.</p>
                        </div>
                        <div class="portal-poster__network">
                            <span class="portal-poster__compass"><x-icon name="heart" /></span>
                            <div><strong>FOR EVERY EXPLORER</strong><span>Save spots, build itineraries, travel smarter</span></div>
                        </div>
                    </aside>

                    <section class="portal-signin__main">
                        <span class="portal-signin__welcome">welcome back</span>
                        <h2 id="loginModalTitle" class="login-modal__title">Sign In</h2>
                        <p class="portal-signin__subtitle" data-login-context>Sign in to save places to your favorites.</p>

                        <div class="alert alert-error" role="alert" data-login-error hidden></div>

                        <form method="POST" action="{{ route('account.login') }}" data-login-form novalidate>
                            @csrf
                            <input type="hidden" name="save_type" value="">
                            <input type="hidden" name="save_id" value="">

                            <div class="field">
                                <label for="lm-alias">Alias</label>
                                <div class="portal-signin__input">
                                    <x-icon name="user" aria-hidden="true" />
                                    <input type="text" id="lm-alias" name="alias" autocomplete="username" aria-describedby="lm-alias-hint" required>
                                </div>
                                <small class="portal-signin__hint" id="lm-alias-hint">Your alias is the name you picked when you registered, not your email.</small>
                            </div>

                            <div class="field">
                                <label for="lm-password">Password</label>
                                <div class="portal-signin__input password-field">
                                    <x-icon name="lock" aria-hidden="true" />
                                    <input type="password" id="lm-password" name="password" required autocomplete="current-password">
                                    @include('partials.password-toggle', ['target' => 'lm-password'])
                                </div>
                            </div>

                            <button type="submit" class="portal-signin__submit" data-login-submit>Sign In</button>
                        </form>

                        <p class="portal-signin__help">Don&rsquo;t have an account yet? <a href="{{ route('account.register') }}">Create one for free</a></p>
                    </section>
                </div>
            </div>
        </div>
        <script src="{{ asset('js/login-modal.js') }}?v={{ filemtime(public_path('js/login-modal.js')) }}" defer></script>
    @endunless
@endguest
