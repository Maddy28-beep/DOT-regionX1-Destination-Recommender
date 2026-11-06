<footer class="site-footer" id="about">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-intro">
                <div class="footer-brand-row">
                    <x-brand-mark class="footer-brand-icon" />
                    <div class="footer-brand">Explore<span class="footer-brand__dvo">DVO</span></div>
                </div>
                <p>A tourism information and DOT-accreditation platform built for the Department of Tourism
                    Region XI, covering Davao City, Davao del Norte, Davao de Oro, Davao Occidental, Davao Oriental,
                    and Davao del Sur.</p>
                <div class="footer-contact">
                    <p>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M12 21s7-6.5 7-12a7 7 0 10-14 0c0 5.5 7 12 7 12z"/><circle cx="12" cy="9" r="2.3"/>
                        </svg>
                        <span>{{ config('dot.office_address') }}</span>
                    </p>
                    <p>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M4 5h16v14H4V5z"/><path d="M4 6l8 7 8-7"/>
                        </svg>
                        <a href="mailto:{{ config('dot.contact_email') }}">{{ config('dot.contact_email') }}</a>
                    </p>
                </div>
                <div class="footer-social">
                    <p>Follow DOT Davao</p>
                    <nav aria-label="DOT Davao social media">
                        <a href="https://www.facebook.com/DoTDavao" target="_blank" rel="noopener noreferrer" aria-label="DOT Davao on Facebook (opens in a new tab)" title="Facebook"><x-icon name="facebook" aria-hidden="true" /></a>
                        <a href="https://www.instagram.com/dotdavao" target="_blank" rel="noopener noreferrer" aria-label="DOT Davao on Instagram (opens in a new tab)" title="Instagram"><x-icon name="instagram" aria-hidden="true" /></a>
                        <a href="https://www.youtube.com/@davaotourismdotph8487" target="_blank" rel="noopener noreferrer" aria-label="DOT Davao on YouTube (opens in a new tab)" title="YouTube">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="4"/><path d="m10 9 5 3-5 3z"/></svg>
                        </a>
                        <a href="https://www.tiktok.com/@dotdavao" target="_blank" rel="noopener noreferrer" aria-label="DOT Davao on TikTok (opens in a new tab)" title="TikTok"><x-icon name="tiktok" aria-hidden="true" /></a>
                    </nav>
                </div>
            </div>
            <div class="footer-acc" data-footer-acc="open">
                <h3 class="footer-acc__static">For Travelers</h3>
                <h3 class="footer-acc__title"><button type="button" class="footer-acc__btn" aria-expanded="true" aria-controls="footer-travelers">For Travelers<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></button></h3>
                <div class="footer-traveler-links footer-acc__panel" id="footer-travelers">
                <a href="{{ route('plan.edit') }}">Plan a trip</a>
                <a href="{{ route('destinations.index') }}">Browse destinations</a>
                <a href="{{ route('accommodations.index') }}">Browse accommodations</a>
                <a href="{{ route('restaurants.index') }}">Browse restaurants</a>
                <a href="{{ route('packages.index') }}">Browse tour packages</a>
                <a href="{{ route('souvenir-centers.index') }}">Browse souvenir centers</a>
                <a href="{{ route('tour-operators.index') }}">Browse tour operators</a>
                <a href="{{ route('exit-survey.create') }}">Share your feedback</a>
                <a href="{{ route('saved.index') }}">Saved places</a>
                <a href="{{ route('events.index') }}">Browse events</a>
                </div>
            </div>
            <div class="footer-acc" data-footer-acc="closed">
                <h3 class="footer-acc__static">For Partners</h3>
                <h3 class="footer-acc__title"><button type="button" class="footer-acc__btn" aria-expanded="true" aria-controls="footer-partners">For Partners<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></button></h3>
                <div class="footer-acc__panel" id="footer-partners">
                {{-- Starts the partner request here rather than bouncing straight
                     out to the national DOT system: a business that is already
                     accredited would have been sent away for nothing. The
                     request form links on to the accreditation portal for those
                     who still need it. --}}
                <a href="{{ route('portal.establishment.register') }}">List your establishment</a>
                <a href="{{ route('portal.login') }}">Partner / DOT login</a>
                </div>
            </div>
            <div class="footer-acc" data-footer-acc="closed">
                <h3 class="footer-acc__static">Department of Tourism</h3>
                <h3 class="footer-acc__title"><button type="button" class="footer-acc__btn" aria-expanded="true" aria-controls="footer-dot">Department of Tourism<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></button></h3>
                <div class="footer-acc__panel" id="footer-dot">
                <p>DOT Region XI &mdash; Davao Region</p>
                <p>Tourism analytics &amp; accreditation monitoring</p>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <nav class="footer-legal" aria-label="Legal">
                <a href="{{ route('legal.privacy') }}">Privacy Policy</a>
                <a href="{{ route('legal.terms') }}">Terms of Service</a>
                <a href="{{ route('legal.privacy') }}#ra-10173">Data Privacy Act (RA 10173) Notice</a>
                <a href="{{ route('legal.accessibility') }}">Accessibility Statement</a>
            </nav>
            <p class="footer-copyright">&copy; {{ date('Y') }} ExploreDVO &middot; Department of Tourism Region XI</p>
        </div>
    </div>
    <script src="{{ asset('js/footer-accordion.js') }}?v={{ filemtime(public_path('js/footer-accordion.js')) }}" defer></script>
</footer>
