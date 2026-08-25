@extends('layouts.establishment')

@section('title', 'Notifications — Partner Dashboard')
@section('page-title', 'Notifications')
@section('page-sub', 'Accreditation updates and alerts about your listing')

@section('content')

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>{{ $notifications->total() }} Notification{{ $notifications->total() === 1 ? '' : 's' }}</h2>
        </div>
    </div>
    <div class="panel-body">
        @forelse ($notifications as $notification)
            <div class="review-item">
                <div class="author">{{ $notification->title }}</div>
                <p class="comment">{{ $notification->message }}</p>
                <div class="owner-reply-label" style="margin-top:4px;">{{ $notification->created_at->format('M d, Y g:i A') }}</div>
            </div>
        @empty
            <p style="color:var(--muted);">No notifications yet.</p>
        @endforelse
    </div>
</div>

@if ($notifications->hasPages())
    <div class="pagination">
        @if ($notifications->onFirstPage())
            <span class="disabled">&laquo;</span>
        @else
            <a href="{{ $notifications->previousPageUrl() }}">&laquo;</a>
        @endif

        @foreach ($notifications->getUrlRange(1, $notifications->lastPage()) as $page => $url)
            <span class="{{ $page === $notifications->currentPage() ? 'active' : '' }}"><a href="{{ $url }}">{{ $page }}</a></span>
        @endforeach

        @if ($notifications->hasMorePages())
            <a href="{{ $notifications->nextPageUrl() }}">&raquo;</a>
        @else
            <span class="disabled">&raquo;</span>
        @endif
    </div>
@endif

@endsection
