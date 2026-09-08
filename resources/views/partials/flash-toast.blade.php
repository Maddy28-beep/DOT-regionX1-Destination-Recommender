{{--
    Bridges a Laravel flash message to the shared toast component.

    Included by layouts/app, layouts/admin and layouts/establishment, so every
    surface raises confirmations the same way. It is one file with three
    includes, not three implementations -- change the behaviour here and all
    three follow.

    Place it AFTER the app.js tag. That script is deferred, so it has not run
    when this inline script executes during parsing; pushing onto
    window.__toastQueue hands the message over and app.js drains the queue on
    DOMContentLoaded. Calling window.showToast directly here would throw.

    Reads the flash, which Laravel clears once read -- so the message survives
    exactly one render. A reload has nothing left to show, which is the whole
    reason confirmations moved off the page and into this component.

    Validation errors are deliberately NOT routed here: they belong beside the
    field that failed and must stay until fixed, not slide away after four
    seconds.
--}}
@if (session('status') || session('error'))
    <script>
        window.__toastQueue = window.__toastQueue || [];
        window.__toastQueue.push([
            @json(session('error') ? 'error' : 'success'),
            @json(session('error') ?: session('status')),
            @json(session('status_detail'))
        ]);
    </script>
@endif
