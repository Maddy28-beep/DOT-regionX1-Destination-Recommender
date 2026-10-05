@extends('layouts.admin')

@section('title', 'Events — DOT Admin')
@section('page-title', 'Events')
@section('page-sub', 'Festivals, fairs and races shown on the public events calendar')

@section('content')

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>{{ $events->total() }} {{ ucfirst($filter) }} {{ \Illuminate\Support\Str::plural('event', $events->total()) }}</h2>
        </div>
        <a href="{{ route('admin.events.create') }}" class="btn btn-primary">Add Event</a>
    </div>

    <div class="panel-body" style="padding-bottom:0;">
        <div class="chip-row">
            @foreach (['upcoming' => 'Upcoming', 'past' => 'Past', 'archived' => 'Archived'] as $value => $label)
                <a href="{{ route('admin.events.index', ['show' => $value]) }}" class="chip {{ $filter === $value ? 'active' : '' }}">{{ $label }} ({{ $counts[$value] }})</a>
            @endforeach
        </div>
    </div>

    <div class="table-scroll">
    <table class="data-table">
        <thead>
            <tr>
                <th>Event</th><th>Category</th><th>Dates</th><th>Location</th><th>Status</th><th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($events as $event)
                <tr>
                    <td>
                        {{ $event->title }}
                        @if ($event->time_label)
                            <div class="cell-muted" style="font-size:.82rem; margin-top:2px;">{{ $event->time_label }}</div>
                        @endif
                    </td>
                    <td class="cell-muted">{{ $event->categoryLabel() }}</td>
                    <td class="cell-muted">{{ $event->dateRangeLabel() }}</td>
                    <td class="cell-muted">{{ $event->location }}</td>
                    <td>
                        @if ($event->archived_at)
                            <span class="status-pill status-hidden">Archived</span>
                        @elseif ($event->lastDay()->isPast() && ! $event->lastDay()->isToday())
                            <span class="status-pill status-pending">Ended</span>
                        @else
                            <span class="status-pill status-active">{{ $event->countdownLabel() }}</span>
                        @endif
                    </td>
                    <td>
                        <div class="util-row">
                            <a href="{{ route('admin.events.edit', $event) }}" class="btn btn-primary btn-xs">Edit</a>
                            @if ($event->archived_at)
                                <form method="POST" action="{{ route('admin.events.unarchive', $event) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-outline btn-xs">Restore</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.events.archive', $event) }}" onsubmit="return confirm('Archive this event? It will leave the public calendar.');">
                                    @csrf
                                    <button type="submit" class="btn btn-outline btn-xs">Archive</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="cell-muted">No {{ $filter }} events.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

<x-admin-pagination :paginator="$events" />

@endsection
