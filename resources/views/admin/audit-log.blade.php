@extends('layouts.admin')

@section('title', 'Audit Log — DOT Admin')
@section('page-title', 'Audit Log')
@section('page-sub', 'A record of every change made in this console, and who made it')

@section('content')

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>{{ $logs->total() }} recorded {{ \Illuminate\Support\Str::plural('action', $logs->total()) }}</h2>
            <p>Sign-ins and changes are recorded. What was typed into a form, and passwords, are never stored.</p>
        </div>
        <form method="GET" action="{{ route('admin.audit-log') }}" class="util-row">
            <input type="text" name="action" value="{{ $action }}" placeholder="Filter, e.g. listings or login" aria-label="Filter by action">
            <button type="submit" class="btn btn-outline">Filter</button>
        </form>
    </div>

    <div class="table-scroll">
    <table class="data-table">
        <thead>
            <tr><th>When</th><th>Admin</th><th>Action</th><th>Record</th><th>Details</th></tr>
        </thead>
        <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td>{{ $log->created_at?->format('M j, Y g:i A') }}</td>
                    <td>{{ $log->admin?->full_name ?? $log->admin?->email ?? 'Unknown' }}</td>
                    <td><code>{{ $log->action }}</code></td>
                    <td>
                        @if ($log->affected_table)
                            {{ $log->affected_table }}@if ($log->affected_record_id) #{{ $log->affected_record_id }}@endif
                        @else
                            <span class="cell-muted">&mdash;</span>
                        @endif
                    </td>
                    <td class="cell-muted" style="font-size:.85rem;">{{ $log->description }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="cell-muted">Nothing has been recorded yet.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>

    <div class="panel-body">{{ $logs->links() }}</div>
</div>

@endsection
