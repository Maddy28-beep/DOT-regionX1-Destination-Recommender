@props(['title', 'message', 'actionUrl', 'actionLabel', 'secondaryUrl' => null, 'secondaryLabel' => null, 'variant' => 'search', 'adjustFilters' => false])

<div class="empty-state davo-empty-state">
    <img class="davo-empty-state__mascot" src="{{ asset($variant === 'saved' ? 'images/davo-saved.webp' : 'images/davo-search.webp') }}" alt="" width="290" height="435">
    <h2>{{ $title }}</h2>
    <p>{{ $message }}</p>
    <div class="empty-state__actions">
        @if ($adjustFilters)
            <button type="button" class="btn btn-primary" data-adjust-filters aria-controls="filterPanel"><x-icon name="filter" /> Adjust filters</button>
        @endif
        <a href="{{ $actionUrl }}" class="btn {{ $adjustFilters ? 'btn-outline' : 'btn-primary' }}">{{ $actionLabel }} <span aria-hidden="true">&rarr;</span></a>
        @if ($secondaryUrl && $secondaryLabel)
            <a href="{{ $secondaryUrl }}" class="btn btn-outline">{{ $secondaryLabel }}</a>
        @endif
    </div>
</div>
