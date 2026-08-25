<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $reportType }} — ExploreDVO Report</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; color: #1f2a24; margin: 40px; }
        .brand { font-size: 1.3rem; font-weight: 800; color: #0b6b4f; }
        .brand span { color: #c9932f; }
        .meta { color: #6b7a72; font-size: .85rem; margin-top: 4px; }
        h1 { font-size: 1.4rem; margin: 28px 0 4px; }
        .summary { color: #4a5750; font-size: .92rem; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        th { text-align: left; text-transform: uppercase; font-size: .7rem; letter-spacing: .03em; color: #6b7a72; padding: 8px 10px; border-bottom: 2px solid #dfe6e1; }
        td { padding: 8px 10px; border-bottom: 1px solid #eef1ef; }
        .no-data { color: #6b7a72; font-style: italic; }
        .print-bar { margin-bottom: 24px; }
        .print-bar button { background: #0b6b4f; color: #fff; border: none; padding: 8px 16px; border-radius: 6px; font-size: .85rem; cursor: pointer; }
        .footer { margin-top: 30px; font-size: .72rem; color: #98a39c; }
        @media print {
            .no-print { display: none; }
            body { margin: 0.5in; }
        }
    </style>
</head>
<body>
    <div class="print-bar no-print">
        <button onclick="window.print()">Print / Save as PDF</button>
    </div>

    <div class="brand">Explore<span>DVO</span></div>
    <div class="meta">Department of Tourism Region XI &mdash; Tourism Analytics Report</div>
    <div class="meta">Date range: {{ \Carbon\Carbon::parse($from)->format('M j, Y') }} &ndash; {{ \Carbon\Carbon::parse($to)->format('M j, Y') }} &middot; Generated {{ now()->format('M j, Y g:i A') }}</div>

    <h1>{{ $reportType }}</h1>
    <p class="summary">{{ $report['summary'] }}</p>

    @if (empty($report['rows']))
        <p class="no-data">No records found for this report type in the selected date range.</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($report['headers'] as $header)
                        <th>{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($report['rows'] as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">ExploreDVO &middot; DOT Region XI &middot; Generated automatically from live system data.</div>
</body>
</html>
