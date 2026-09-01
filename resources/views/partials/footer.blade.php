<footer class="site-footer" id="about">
    <div class="container">
        <div class="footer-grid">
            <div>
                <div class="footer-brand">Explore<span style="color:var(--gold)">DVO</span></div>
                <p>A tourism information and DOT-accreditation platform built for the Department of Tourism
                    Region XI, covering Davao City, Davao del Norte, Davao de Oro, Davao Occidental, Davao Oriental,
                    and Davao del Sur.</p>
            </div>
            <div>
                <h4>For Travelers</h4>
                <a href="{{ route('plan.edit') }}">Plan a trip</a>
                <a href="{{ route('destinations.index') }}">Browse destinations</a>
                <a href="{{ route('accommodations.index') }}">Browse accommodations</a>
                <a href="{{ route('restaurants.index') }}">Browse restaurants</a>
                <a href="{{ route('packages.index') }}">Browse tour packages</a>
                <a href="{{ route('souvenir-centers.index') }}">Browse souvenir centers</a>
                <a href="{{ route('tour-operators.index') }}">Browse tour operators</a>
                <a href="{{ route('exit-survey.create') }}">Share your feedback</a>
                <a href="{{ route('saved.index') }}">Saved places</a>
            </div>
            <div>
                <h4>For Partners</h4>
                {{-- Accreditation is applied for on the national DOT system, not
                     here; this site only lists businesses that already hold it. --}}
                <a href="{{ config('dot.accreditation_portal') }}" target="_blank" rel="noopener noreferrer" class="ext-link">
                    List your establishment
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                        <path d="M15 3h6v6"/><path d="M10 14 21 3"/>
                    </svg>
                    <span class="sr-only">(opens the DOT accreditation portal in a new tab)</span>
                </a>
                <a href="{{ route('portal.login') }}">Partner / DOT login</a>
            </div>
            <div>
                <h4>Department of Tourism</h4>
                <p>DOT Region XI &mdash; Davao Region</p>
                <p>Tourism analytics &amp; accreditation monitoring</p>
            </div>
        </div>
        <div class="footer-bottom">&copy; {{ date('Y') }} ExploreDVO &middot; Department of Tourism Region XI &middot; No traveler accounts, no personal data collected &middot; RA 10173 (Data Privacy Act)</div>
    </div>
</footer>
