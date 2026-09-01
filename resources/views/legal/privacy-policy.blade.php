@extends('layouts.app')

@section('title', 'Privacy Policy — ExploreDVO')

@section('content')
<div class="dash-shell">
    <div class="dash-header">
        <div class="container">
            <div>
                <h1>Privacy Policy</h1>
                <div class="sub">Last updated {{ \Illuminate\Support\Carbon::parse('2026-09-01')->format('F j, Y') }} &middot; what ExploreDVO collects, why, and how to reach us about it.</div>
            </div>
        </div>
    </div>

    <div class="dash-body">
        <div class="container" style="max-width:820px;">

            <div class="panel">
                <div class="panel-body legal-content">
                    <p>ExploreDVO is a tourism information platform built for the Department of Tourism
                        Region XI (DOT Region XI). This policy describes, plainly and specifically, what
                        information the platform collects from each type of person who uses it, why, and
                        what you can do about it. It replaces any blanket "no personal data collected"
                        claim that used to appear in the site footer &mdash; that line was inaccurate for
                        the optional health/accessibility information travelers can choose to share, and
                        for the establishment-partner accounts described below.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>1. Travelers planning a trip</h2></div></div>
                <div class="panel-body legal-content">
                    <p><strong>There is no traveler account.</strong> You do not register, log in, or give
                        us your name or email to plan a trip, browse listings, save places, check in with a
                        QR code, or use the chatbot.</p>
                    <p>What is collected instead:</p>
                    <ul>
                        <li><strong>Trip preferences</strong> &mdash; travel dates, budget, travel type,
                            accommodation preference, interests, and an optional starting location (rounded
                            to roughly 100&nbsp;metres, never your exact position). None of this is linked to
                            your name or any identifier.</li>
                        <li><strong>Health or accessibility information (optional)</strong> &mdash; if you
                            choose to answer the accessibility questions in the trip planner, that
                            information is saved so the recommendation engine can favor suitable
                            destinations. It is only ever attached to that one trip plan, never to an
                            identity, and is only collected if you give consent on that screen.</li>
                        <li><strong>An anonymous browser token</strong> &mdash; a random identifier stored in
                            a cookie on your device, used only to (a) stop the same visitor's QR check-in
                            from being counted twice in one day at the same place, and (b) keep your saved
                            places attached to your own browser. It is not derived from your device, IP
                            address, or any personal detail, and is never linked to a name.</li>
                        <li><strong>Chatbot questions</strong> &mdash; your messages to the assistant are
                            logged to improve its answers, but carry no browser token, cookie, or other link
                            back to you at all.</li>
                        <li><strong>Exit survey responses (optional)</strong> &mdash; entirely separate and
                            anonymous; it is not linked to your trip plan, your browser token, or any other
                            record.</li>
                    </ul>
                    <p><strong>How long this is kept:</strong> your trip plan is retrieved using your
                        browser session, so once that session ends (you close the tab, or it times out) you
                        can no longer get back to it. The underlying record may still exist in our database
                        for aggregate analytics, but because it carries no name, email, or other identifier,
                        it cannot be traced back to you to be individually deleted on request.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>2. DOT-accredited establishment partners</h2></div></div>
                <div class="panel-body legal-content">
                    <p>Businesses that are already DOT-accredited can claim their listing and manage it
                        through the partner portal. This <strong>does</strong> require an account, and we do
                        collect personal/business information: business name, contact person, email address,
                        phone number, and any accreditation certificate you upload for verification. This is
                        used solely to verify your accreditation claim, let you manage your own listing, and
                        respond to reviews. It is visible to DOT Region XI administrators for verification
                        purposes and is not sold or shared with third parties.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>3. DOT Region XI staff</h2></div></div>
                <div class="panel-body legal-content">
                    <p>Administrator accounts (name, email, login credentials) are used internally by DOT
                        Region XI staff to manage listings, review accreditation, and view tourism analytics.
                        This data is not visible to the public.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>4. How this information is used</h2></div></div>
                <div class="panel-body legal-content">
                    <ul>
                        <li>Matching your stated preferences against destination attributes to recommend
                            places and build a day-by-day itinerary.</li>
                        <li>Finding patterns in anonymous, aggregate visitation data (which places tend to
                            be visited together) to suggest nearby meals, lodging, or shopping.</li>
                        <li>Producing verified visit counts per establishment from QR check-ins, and
                            aggregate satisfaction analytics from exit surveys, for DOT Region XI's own
                            tourism planning and reporting.</li>
                        <li>Verifying establishment accreditation claims.</li>
                    </ul>
                    <p>We do not sell any of this information, and we do not use it for advertising.</p>
                </div>
            </div>

            <div class="panel" id="ra-10173">
                <div class="panel-head"><div><h2>5. Data Privacy Act (RA 10173) Notice</h2></div></div>
                <div class="panel-body legal-content">
                    <p>This platform is designed around data minimization, a core principle of Republic
                        Act No. 10173 (the Data Privacy Act of 2012): the traveler-facing side of the site
                        was deliberately built to need no traveler account and no traveler-identifying
                        information at all, and any sensitive information a traveler does choose to share
                        (health or accessibility details) is collected only with consent, kept only for the
                        trip plan it was given with, and never linked to an identity.</p>
                    <p>Establishment partners and DOT staff accounts do involve personal and business data,
                        as described in sections 2 and 3 above, and that data is handled under the same Act's
                        requirements for lawful processing, proportionality, and security.</p>
                    <p>Questions, concerns, or requests regarding this notice can be sent to DOT Region XI at
                        <a href="mailto:{{ config('dot.contact_email') }}">{{ config('dot.contact_email') }}</a>.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>6. Contact</h2></div></div>
                <div class="panel-body legal-content">
                    <p>{{ config('dot.office_address') }}<br>
                        <a href="mailto:{{ config('dot.contact_email') }}">{{ config('dot.contact_email') }}</a></p>
                </div>
            </div>

        </div>
    </div>
</div>
@endsection
