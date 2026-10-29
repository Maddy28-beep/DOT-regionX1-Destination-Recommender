@extends('layouts.establishment')

@section('title', 'Notifications — Partner Dashboard')
@section('page-title', 'Notifications')
@section('page-sub', 'Accreditation updates and alerts about your listing')

@section('page-actions')
    @if ($unreadCount > 0)
        <form method="POST" action="{{ route('establishment.notifications.read-all') }}">
            @csrf
            <button type="submit" class="btn btn-outline"><x-icon name="check" /> Mark all as read</button>
        </form>
    @endif
@endsection

@section('content')

<div class="adm-toolbar" style="margin-bottom:12px;">
    <nav class="adm-seg" aria-label="Filter notifications">
        <a href="{{ route('establishment.notifications') }}" class="{{ $filter === 'all' ? 'on' : '' }}" @if ($filter === 'all') aria-current="true" @endif>All <span class="adm-seg__n">{{ $totalCount }}</span></a>
        <a href="{{ route('establishment.notifications', ['filter' => 'unread']) }}" class="{{ $filter === 'unread' ? 'on' : '' }}" @if ($filter === 'unread') aria-current="true" @endif>Unread <span class="adm-seg__n">{{ $unreadCount }}</span></a>
    </nav>
</div>

<div class="adm-card">
    <div class="adm-card__body">
        @forelse ($notifications as $notification)
            <div class="ptr-nt {{ $notification->is_read ? '' : 'is-unread' }}">
                <span class="ptr-nt__dot" @unless ($notification->is_read) role="img" aria-label="Unread" @endunless></span>
                <span class="ptr-nt__ic"><x-icon name="bell" /></span>
                <div class="ptr-nt__t">
                    <strong>{{ $notification->title }}</strong>
                    <span>{{ $notification->message }}</span>
                    <small>{{ $notification->created_at->timezone('Asia/Manila')->format('M d, Y g:i A') }}</small>
                </div>
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
    @if ($notifications->hasPages())
        <div class="adm-card__foot">@include('partials.pagination', ['paginator' => $notifications])</div>
    @endif
</div>

@endsection
