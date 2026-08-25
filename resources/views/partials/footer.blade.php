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
                <a href="{{ route('tourist.register') }}">Create an account</a>
                <a href="{{ route('destinations.index') }}">Browse destinations</a>
                <a href="{{ route('accommodations.index') }}">Browse accommodations</a>
                <a href="{{ route('restaurants.index') }}">Browse restaurants</a>
                <a href="{{ route('packages.index') }}">Browse tour packages</a>
                <a href="{{ route('souvenir-centers.index') }}">Browse souvenir centers</a>
                <a href="{{ route('tour-operators.index') }}">Browse tour operators</a>
                <a href="{{ route('exit-survey.create') }}">Share your feedback</a>
                <a href="{{ route('tourist.login') }}">Sign in</a>
            </div>
            <div>
                <h4>For Partners</h4>
                <a href="{{ route('portal.establishment.register') }}">List your establishment</a>
                <a href="{{ route('portal.login') }}">Partner / DOT login</a>
            </div>
            <div>
                <h4>Department of Tourism</h4>
                <p>DOT Region XI &mdash; Davao Region</p>
                <p>Tourism analytics &amp; accreditation monitoring</p>
            </div>
        </div>
        <div class="footer-bottom">&copy; {{ date('Y') }} ExploreDVO &middot; Department of Tourism Region XI &middot; Data handled per RA 10173 (Data Privacy Act)</div>
    </div>
</footer>
