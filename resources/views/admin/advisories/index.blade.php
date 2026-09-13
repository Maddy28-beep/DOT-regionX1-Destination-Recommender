@extends('layouts.admin')

@section('title', 'Advisories — DOT Admin')
@section('page-title', 'Advisories')
@section('page-sub', 'Post a notice for a specific listing or the whole platform')

@section('content')

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>{{ $advisories->total() }} Advisories</h2>
        </div>
        <a href="{{ route('admin.advisories.create') }}" class="btn btn-primary">Post Advisory</a>
    </div>

    <div class="table-scroll">
    <table class="data-table">
        <thead>
            <tr>
                <th>Title</th><th>Applies To</th><th>Severity</th><th>Window</th><th>Posted By</th><th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($advisories as $advisory)
                <tr>
                    <td>
                        {{ $advisory->title }}
                        <div class="cell-muted" style="font-size:.82rem; margin-top:2px;">{{ \Illuminate\Support\Str::limit($advisory->message, 80) }}</div>
                    </td>
                    <td class="cell-muted">
                        @if ($advisory->listing)
                            {{ $advisory->listing->name }}
                        @else
                            All of ExploreDVO
                        @endif
                    </td>
                    <td>
                        <span class="status-pill {{ $advisory->severity === 'danger' ? 'status-expired' : 'status-active' }}">{{ ucfirst($advisory->severity) }}</span>
                    </td>
                    <td class="cell-muted">
                        @if ($advisory->starts_at || $advisory->ends_at)
                            {{ $advisory->starts_at?->format('M j, Y') ?? 'Now' }} &ndash; {{ $advisory->ends_at?->format('M j, Y') ?? 'Until removed' }}
                        @else
                            Until removed
                        @endif
                    </td>
                    <td class="cell-muted">{{ $advisory->admin?->full_name ?? '—' }}</td>
                    <td>
                        <div class="util-row">
                            <a href="{{ route('admin.advisories.edit', $advisory) }}" class="btn btn-primary btn-xs">Edit</a>
                            <form method="POST" action="{{ route('admin.advisories.destroy', $advisory) }}" onsubmit="return confirm('Remove this advisory? Travelers will no longer see it.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline btn-xs">Remove</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="cell-muted">No advisories posted yet.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

<x-admin-pagination :paginator="$advisories" />

@endsection
