<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Exit survey QR code — DOT Admin</title>
    <style>
        :root { --ink: #1a2420; --muted: #5b6b64; --green: #0b5e52; --orange: #ff6b35; --paper: #fff7e9; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Poppins', -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: var(--ink); background: #eef2ef; }
        .bar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 14px 24px; background: #fff; border-bottom: 1px solid #dfe6e2; }
        .bar a, .bar button { font: inherit; font-weight: 600; font-size: .9rem; padding: 8px 16px; border-radius: 8px; border: 1.5px solid var(--green); background: transparent; color: var(--green); text-decoration: none; cursor: pointer; }
        .bar button { background: var(--green); color: #fff; }
        .warn { margin: 16px auto 0; max-width: 720px; padding: 12px 16px; border-radius: 10px; background: #fff3e0; color: #8a4b08; border: 1px solid #f4d9ae; font-size: .9rem; }
        .sheet { width: min(720px, calc(100% - 32px)); margin: 20px auto 40px; padding: 48px 40px 40px; text-align: center; background: var(--paper); border: 3px solid var(--green); border-radius: 20px; }
        .kicker { font-weight: 700; font-size: .8rem; letter-spacing: .18em; text-transform: uppercase; color: #d9472c; }
        h1 { margin: 8px 0 10px; font-size: clamp(2rem, 6vw, 2.9rem); line-height: 1.05; color: var(--green); }
        .lead { margin: 0 auto 24px; max-width: 460px; font-size: 1.05rem; line-height: 1.55; color: var(--muted); }
        .qr { display: block; width: min(340px, 80%); margin: 0 auto 18px; padding: 14px; background: #fff; border: 2px solid var(--ink); border-radius: 16px; }
        .steps { margin: 0 auto 18px; max-width: 460px; font-size: .95rem; color: var(--ink); }
        .url { display: inline-block; padding: 6px 14px; border-radius: 999px; background: #fff; border: 1px solid #dfe6e2; font-family: ui-monospace, Menlo, monospace; font-size: .85rem; word-break: break-all; }
        .foot { margin-top: 22px; font-size: .78rem; color: var(--muted); }
        @media print {
            @page { margin: 12mm; }
            body { background: #fff; }
            .bar, .warn { display: none; }
            .sheet { margin: 0 auto; width: 100%; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="bar">
        <button type="button" onclick="window.print()">Print this page</button>
        <a href="{{ route('admin.exit-survey-qr.svg') }}" download="exit-survey-qr-code.svg">Download QR (SVG)</a>
        <a href="{{ route('admin.exit-surveys') }}">Back to exit survey insights</a>
    </div>

    @if ($isLocal)
        <div class="warn">
            <strong>This code points to {{ $url }}.</strong> A phone can't open an address on your own computer.
            Open this page from the site's public address (and check <code>APP_URL</code>) before printing.
        </div>
    @endif

    <main class="sheet">
        <div class="kicker">how was your trip?</div>
        <h1>Tell us about your Davao visit</h1>
        <p class="lead">Finished your trip? Scan the code and share where you went. It takes about two minutes and helps DOT Region XI improve tourism.</p>

        <img class="qr" src="{{ $qr }}" alt="QR code that opens the visitor exit survey at {{ $url }}">

        <p class="steps"><strong>1.</strong> Open your phone camera &nbsp; <strong>2.</strong> Point it at the code &nbsp; <strong>3.</strong> Tap the link</p>
        <span class="url">{{ $url }}</span>

        <p class="foot">Anonymous: no name, email or account needed. Handled under the Data Privacy Act of 2012 (RA 10173) and used only for tourism analytics and service improvement.</p>
    </main>
</body>
</html>
