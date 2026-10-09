@props(['title', 'message', 'actionUrl', 'actionLabel', 'secondaryUrl' => null, 'secondaryLabel' => null, 'variant' => 'search'])

<div class="empty-state davo-empty-state">
    <img class="davo-empty-state__mascot" src="{{ asset($variant === 'saved' ? 'images/davo-saved.png' : 'images/davo-search.png') }}" alt="" width="120" height="144">
    <h2>{{ $title }}</h2>
    <p>{{ $message }}</p>
    <div class="empty-state__actions">
        <a href="{{ $actionUrl }}" class="btn btn-primary">{{ $actionLabel }} <span aria-hidden="true">&rarr;</span></a>
        @if ($secondaryUrl && $secondaryLabel)
            <a href="{{ $secondaryUrl }}" class="btn btn-outline">{{ $secondaryLabel }}</a>
        @endif
    </div>
</div>
