@extends('layouts.app')

@section('title', 'Accessibility Statement — ExploreDVO')

@section('content')
<div class="dash-shell">
    <div class="dash-header">
        <div class="container">
            <div>
                <h1>Accessibility Statement</h1>
                <div class="sub">Last updated {{ \Illuminate\Support\Carbon::parse('2026-09-01')->format('F j, Y') }}</div>
            </div>
        </div>
    </div>

    <div class="dash-body">
        <div class="container" style="max-width:820px;">

            <div class="panel">
                <div class="panel-body legal-content">
                    <p>ExploreDVO aims to be usable by as many visitors as possible, including people using
                        screen readers, keyboard-only navigation, or who prefer reduced motion. This page
                        describes what is currently in place and how to reach us if something isn't working
                        for you &mdash; it is a description of ongoing effort, not a claim of full
                        conformance to a specific standard.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>What's in place today</h2></div></div>
                <div class="panel-body legal-content">
                    <ul>
                        <li>Interactive controls such as the save/heart button expose their state to screen
                            readers (<code>aria-pressed</code>) and include a text label describing the
                            action, not just an icon.</li>
                        <li>Decorative icons are hidden from screen readers (<code>aria-hidden</code>) so
                            they don't add noise to what's read aloud.</li>
                        <li>Animations &mdash; such as the save/heart effect &mdash; are disabled automatically
                            if your operating system is set to reduce motion.</li>
                        <li>The chat assistant can be closed with the Escape key as well as by clicking, and
                            focus moves to its input field when opened.</li>
                        <li>The trip planner's accessibility/health questions exist specifically so the
                            recommendation engine can favor destinations suited to mobility, sensory, or other
                            needs you choose to share.</li>
                    </ul>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>Known limitations</h2></div></div>
                <div class="panel-body legal-content">
                    <p>This statement reflects the state of the platform as it is actively being developed.
                        Not every page has been individually audited against a formal standard such as
                        WCAG 2.1, and some areas &mdash; particularly data-heavy admin and partner-portal
                        screens &mdash; may not yet meet the same bar as the public-facing pages.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2>Let us know</h2></div></div>
                <div class="panel-body legal-content">
                    <p>If you run into something that doesn't work with the technology you use, please tell
                        us at <a href="mailto:{{ config('dot.contact_email') }}">{{ config('dot.contact_email') }}</a>
                        so it can be prioritized.</p>
                </div>
            </div>

        </div>
    </div>
</div>
@endsection
