@extends('layouts.establishment')

@section('title', 'Notifications — Partner Dashboard')
@section('page-title', 'Notifications')
@section('page-sub', 'Accreditation updates and alerts about your listing')

@section('content')

<div class="panel">
    <div class="panel-head">
        <div class="pill-toggle">
            <a href="{{ route('establishment.notifications') }}" class="{{ $filter === 'all' ? 'active' : '' }}">
                All <span class="count">{{ $totalCount }}</span>
            </a>
            <a href="{{ route('establishment.notifications', ['filter' => 'unread']) }}" class="{{ $filter === 'unread' ? 'active' : '' }}">
                Unread <span class="count">{{ $unreadCount }}</span>
            </a>
        </div>

        @if ($unreadCount > 0)
            <form method="POST" action="{{ route('establishment.notifications.read-all') }}">
                @csrf
                <button type="submit" class="btn btn-outline">Mark all as read</button>
            </form>
        @endif
    </div>
    <div class="panel-body">
        @forelse ($notifications as $notification)
            <div class="review-item {{ $notification->is_read ? '' : 'is-unread' }}">
                <div class="author">
                    {{ $notification->title }}
                    @unless ($notification->is_read)
                        <span class="status-pill status-pending">New</span>
                    @endunless
                </div>
                <p class="comment">{{ $notification->message }}</p>
                <div class="owner-reply-label" style="margin-top:4px;">{{ $notification->created_at->format('M d, Y g:i A') }}</div>
            </div>
        @empty
            <div class="empty-panel" style="padding:32px 24px;">
                <div class="icon"><x-icon name="bell" /></div>
                <h3>{{ $filter === 'unread' ? 'Nothing unread' : 'No notifications yet' }}</h3>
                <p>{{ $filter === 'unread'
                    ? "You're all caught up. Switch to All to see earlier notifications."
                    : 'Accreditation updates and alerts about your listing will appear here.' }}</p>
            </div>
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
