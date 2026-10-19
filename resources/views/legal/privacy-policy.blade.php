@extends('layouts.app')

@section('title', 'Privacy Policy — ExploreDVO')

@section('content')
<div class="dash-shell">
    <div class="dash-header">
        <div class="container">
            <div>
                <h1>Privacy Policy</h1>
                <div class="sub">Last updated {{ \Illuminate\Support\Carbon::parse('2026-10-15')->format('F j, Y') }} &middot; what ExploreDVO collects, why, how long it is kept, and how to reach us about it.</div>
            </div>
        </div>
    </div>

    <div class="dash-body">
        <div class="container" style="max-width:820px;">

            <div class="panel">
                <div class="panel-body legal-content">
                    <p>ExploreDVO is a tourism information platform built for the Department of Tourism
                        Region XI (DOT Region XI). This policy describes, plainly and specifically, what
                        information the platform collects from each type of person who uses it, why, how
                        long it is kept, and what you can do about it. It replaces any blanket "no personal
                        data collected" claim that used to appear in the site footer &mdash; that line was
                        inaccurate for the optional health/accessibility information travelers can choose to
                        share, and for the establishment-partner accounts described below.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>1. Travelers planning a trip</h2></div></div>
                <div class="panel-body legal-content">
                    <p><strong>No account is needed.</strong> You do not register, log in, or give us your
                        name or email to plan a trip, browse listings, save places, check in with a QR
                        code, or use the chatbot.</p>
                    <p>What is collected instead:</p>
                    <ul>
                        <li><strong>Trip preferences</strong> &mdash; travel dates, budget, travel type,
                            accommodation preference, interests, and an optional starting location (rounded
                            to roughly 100&nbsp;metres, never your exact position), together with the
                            itinerary built from them. None of this is linked to your name.</li>
                        <li><strong>Health or accessibility information (optional)</strong> &mdash; if you
                            choose to answer the accessibility questions in the trip planner, that
                            information is saved so the recommendation engine can favor suitable
                            destinations. It is only ever attached to that one trip plan, never to an
                            identity, and is only collected if you give consent on that screen. It is
                            deleted together with the trip plan.</li>
                        <li><strong>An anonymous browser token</strong> &mdash; a random identifier stored in
                            a cookie on your device for up to one year, used only to (a) stop the same
                            visitor's QR check-in from being counted twice in one day at the same place,
                            (b) keep your saved places attached to your own browser, and (c) attach a
                            review you post to your own browser. It is not derived from your device, IP
                            address, or any personal detail, and is never linked to a name. Clearing your
                            browser's cookies discards it.</li>
                        <li><strong>Chatbot questions</strong> &mdash; your messages to the assistant are
                            logged to improve its answers, but carry no browser token, cookie, or other link
                            back to you. @if (filled(config('services.groq.key')))
                            To help word its answers, the assistant also sends the text of your question
                            (and nothing else about you: no browser token, name or location) to an outside
                            AI service, Groq, whose servers are outside the Philippines. Questions about one
                            named place are answered from our own data and are not sent. @else
                            Today the assistant answers using the site's own rules and does not
                            send your question to any outside service; if that ever changes, this policy
                            will be updated to say so. @endif Do not type personal details into the chat.</li>
                        <li><strong>Exit survey responses (optional)</strong> &mdash; the survey is
                            anonymous and asks for no name or email. Your answers (places visited, ratings,
                            spending, where you came from) are not linked to your browser token or to any
                            account. They may be connected to the trip plan you made in the same browser
                            session, so DOT Region XI can compare what was planned with what was visited;
                            that connection carries no name or contact detail.</li>
                        <li><strong>Routine server records</strong> &mdash; like any website, our web server
                            records the IP address, time, page requested and browser type of each request.
                            These records are used to keep the site secure and to slow down abuse (for
                            example, too many sign-in attempts), and are deleted automatically after about
                            14&nbsp;days.</li>
                    </ul>
                    <p><strong>How long this is kept:</strong></p>
                    <ul>
                        <li>Anonymous trip plans, the preferences they were built from, and any
                            health or accessibility answers: <strong>deleted automatically 30&nbsp;days
                            after the plan was made</strong>. If an exit survey is still connected to a
                            plan, the plan's personal details (accessibility notes and starting point) are
                            cleared instead, and the survey answers stay for statistics.</li>
                        <li>Chatbot questions: <strong>deleted automatically after 30&nbsp;days</strong>.</li>
                        <li>QR check-ins, reviews, saved places and exit survey answers: kept, because DOT
                            Region XI uses them for visit counts and tourism statistics. They are linked
                            only to the random browser token (or to nothing), never to a name.</li>
                        <li>Itineraries you save to the optional account below: kept until you ask for them
                            to be deleted.</li>
                        <li>Backups: our nightly backups are kept for 14&nbsp;days, so a record we have
                            deleted can still sit in a backup for up to that long before it disappears.</li>
                    </ul>
                    <p>Because anonymous records carry no name, email or other identifier, we cannot tell
                        which record is yours; this is why they are removed on a schedule and not on
                        individual request.</p>
                    <p><strong>Optional traveler account:</strong> if you want an itinerary or saved places
                        to survive past your browser session, you may create a free account using an alias
                        and password &mdash; never your real name or email. It is used solely to keep the
                        itineraries and places you explicitly save; it is never linked to your exit survey
                        responses or your anonymous browser token, and health/accessibility answers are
                        removed rather than kept when an itinerary is saved to it. Your password is stored
                        only as a secure hash. Creating one is entirely optional and never required to
                        plan or view a trip.</p>
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
                        purposes and is not sold or shared with third parties. It is kept while the account
                        is active.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>3. DOT Region XI staff</h2></div></div>
                <div class="panel-body legal-content">
                    <p>Administrator accounts (name, email, login credentials) are used internally by DOT
                        Region XI staff to manage listings, review accreditation, and view tourism analytics.
                        This data is not visible to the public. Every sign-in, failed sign-in and change an
                        administrator makes is written to an audit log with the date, time, the record
                        affected and the IP address, so that changes can be traced. Passwords and the
                        contents typed into forms are never stored in the log.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>4. Outside services and where data is stored</h2></div></div>
                <div class="panel-body legal-content">
                    <ul>
                        <li><strong>Maps and directions.</strong> Maps are drawn with tiles from
                            OpenStreetMap, and the route and turn-by-turn directions on an itinerary page
                            are requested from a public OpenStreetMap-based routing service, which receives
                            the coordinates of the stops (not your name). Like any website you visit, these
                            services can see your IP address.</li>
                        <li><strong>Address search.</strong> If you type an address as your starting point,
                            the text you type is sent from our server to @if (filled(config('services.geoapify.key')))
                            Geoapify's address search service (outside the Philippines) @else
                            OpenStreetMap's address search service @endif to suggest matches.</li>
                        <li><strong>Your device's location.</strong> Only used if you press "Use my current
                            location" and allow it in your browser, and rounded to roughly 100&nbsp;metres
                            before it is stored.</li>
                        <li><strong>Hosting.</strong> ExploreDVO's data is stored on a cloud server provided
                            by DigitalOcean in Singapore.</li>
                    </ul>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>5. How this information is used</h2></div></div>
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
                        <li>Keeping the site secure and available.</li>
                    </ul>
                    <p>We do not sell any of this information, and we do not use it for advertising.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>6. How we protect it</h2></div></div>
                <div class="panel-body legal-content">
                    <ul>
                        <li>Passwords are stored only as secure hashes, and sign-in, registration and trip
                            requests are rate-limited to slow down guessing and abuse.</li>
                        <li>Administrator and partner pages need a sign-in and are limited by role; every
                            administrator change is logged.</li>
                        <li>The server accepts connections only for the website and for key-based
                            administration, the database is not reachable from the internet, repeated failed
                            sign-ins to the server are blocked, and security updates are applied
                            automatically.</li>
                        <li>The database and uploaded files are backed up nightly, and the newest backup is
                            also copied to a second computer.</li>
                    </ul>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>7. Your rights</h2></div></div>
                <div class="panel-body legal-content">
                    <p>Under the Data Privacy Act you have the right to be informed about how your personal
                        data is processed, to access it, to correct it, to object to its processing, to ask
                        for it to be blocked or erased, and to claim compensation for damage from its
                        unlawful use. For partner and staff accounts, write to us at the address below and
                        we will act on your request. For anonymous traveler records, which we cannot trace to
                        a person, the scheduled deletion described in section 1 applies. If you believe your
                        rights were not respected, you may also complain to the National Privacy Commission
                        (privacy.gov.ph).</p>
                </div>
            </div>

            <div class="panel" id="ra-10173">
                <div class="panel-head"><div><h2>8. Data Privacy Act (RA 10173) Notice</h2></div></div>
                <div class="panel-body legal-content">
                    <p>This platform is designed around data minimization, a core principle of Republic
                        Act No. 10173 (the Data Privacy Act of 2012): the traveler-facing side of the site
                        was deliberately built to need no account and no traveler-identifying information
                        at all -- the one optional exception, a free alias-based account with no real
                        identity, exists purely for a traveler's own convenience -- and any sensitive
                        information a traveler does choose to share
                        (health or accessibility details) is collected only with consent, kept only for the
                        trip plan it was given with, deleted with it, and never linked to an identity.</p>
                    <p>Establishment partners and DOT staff accounts do involve personal and business data,
                        as described in sections 2 and 3 above, and that data is handled under the same Act's
                        requirements for lawful processing, proportionality, and security.</p>
                    <p>Questions, concerns, or requests regarding this notice can be sent to DOT Region XI at
                        <a href="mailto:{{ config('dot.contact_email') }}">{{ config('dot.contact_email') }}</a>.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>9. Contact</h2></div></div>
                <div class="panel-body legal-content">
                    <p>{{ config('dot.office_address') }}<br>
                        <a href="mailto:{{ config('dot.contact_email') }}">{{ config('dot.contact_email') }}</a></p>
                </div>
            </div>

        </div>
    </div>
</div>
@endsection
