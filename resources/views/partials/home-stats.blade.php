<div class="home-discovery-stats">
    <div class="stats">
        <div class="stats-strip">
            <div class="stat-item">
                <span class="stat-icon"><x-icon name="map-pin" /></span>
                <div class="stat-num" data-count-up>{{ $stats['destinations'] }}</div>
                <div class="stat-label">Destinations</div>
            </div>
            <div class="stat-item">
                <span class="stat-icon"><x-icon name="map" /></span>
                <div class="stat-num" data-count-up>{{ $stats['regions'] }}</div>
                <div class="stat-label" title="Davao City and five provinces; Samal is grouped with Davao del Norte.">Areas covered</div>
            </div>
            <div class="stat-item">
                <span class="stat-icon"><x-icon name="building" /></span>
                <div class="stat-num" data-count-up>{{ $stats['accommodations'] }}</div>
                <div class="stat-label">Accommodations</div>
            </div>
            {{-- Only show a rating when the catalogue has real ratings. --}}
            @if ($stats['avg_rating'])
                <div class="stat-item stat-item--rating">
                    <div class="stat-num" data-count-up>{{ number_format($stats['avg_rating'], 1) }}</div>
                    <div class="stat-label">Traveler Rating</div>
                </div>
            @endif
        </div>
    </div>
</div>
