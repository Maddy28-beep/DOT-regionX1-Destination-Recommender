@extends('layouts.app')

@section('title', 'Terms of Service — ExploreDVO')

@section('content')
<div class="dash-shell">
    <div class="dash-header">
        <div class="container">
            <div>
                <h1>Terms of Service</h1>
                <div class="sub">Last updated {{ \Illuminate\Support\Carbon::parse('2026-09-01')->format('F j, Y') }}</div>
            </div>
        </div>
    </div>

    <div class="dash-body">
        <div class="container" style="max-width:820px;">

            <div class="panel">
                <div class="panel-body legal-content">
                    <p><em>This is a plain-language draft describing what the platform actually does. It
                        has not been reviewed by legal counsel and should be before it is relied on as a
                        binding agreement.</em></p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>1. What ExploreDVO is</h2></div></div>
                <div class="panel-body legal-content">
                    <p>ExploreDVO is an informational tourism platform for Davao Region, built for the
                        Department of Tourism Region XI. It helps visitors discover DOT-accredited
                        destinations, accommodations, restaurants, tour packages, souvenir centers, and tour
                        operators, and generates suggested itineraries based on preferences you provide.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>2. No bookings or payments happen here</h2></div></div>
                <div class="panel-body legal-content">
                    <p>ExploreDVO does not process reservations, bookings, or payments of any kind. Contact
                        details shown for accommodations, restaurants, and tour operators are provided so
                        you can arrange your visit directly with that establishment. Any transaction you make
                        is between you and that establishment, not with ExploreDVO or DOT Region XI.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>3. Accreditation information</h2></div></div>
                <div class="panel-body legal-content">
                    <p>Listings marked as DOT-accredited reflect accreditation records maintained by DOT
                        Region XI at the time they were last verified. Accreditation status can change; we
                        aim to keep this current but recommend confirming directly with an establishment for
                        anything time-sensitive.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>4. Itineraries and recommendations are suggestions</h2></div></div>
                <div class="panel-body legal-content">
                    <p>Generated itineraries, distance estimates, and travel times are planning aids based on
                        the information available to the system, not routed driving directions or a
                        guarantee of feasibility. Always confirm opening hours, availability, and travel
                        conditions before you go.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>5. Establishment partner accounts</h2></div></div>
                <div class="panel-body legal-content">
                    <p>A business that registers a partner account represents that it holds valid DOT
                        accreditation and that the information it submits is accurate. DOT Region XI reviews
                        and may approve, reject, or request more information for any submission.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>6. Changes to these terms</h2></div></div>
                <div class="panel-body legal-content">
                    <p>These terms may be updated from time to time as the platform changes. The date at the
                        top of this page reflects the last update.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>7. Contact</h2></div></div>
                <div class="panel-body legal-content">
                    <p>Questions about these terms can be sent to
                        <a href="mailto:{{ config('dot.contact_email') }}">{{ config('dot.contact_email') }}</a>.</p>
                </div>
            </div>

        </div>
    </div>
</div>
@endsection
