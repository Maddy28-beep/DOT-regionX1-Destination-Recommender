@if ($upcomingEvents->isNotEmpty())
<link rel="stylesheet" href="{{ asset('css/events.css') }}?v={{ filemtime(public_path('css/events.css')) }}">
<section class="section landing-section landing-section--cream">
    <div class="container">
        <div class="dpost-head">
            <div>
                <span class="dpost-kicker poster-kicker">save the date</span>
                <h2 class="poster-title" style="color:var(--ocean-teal-dark);">Happening soon</h2>
                <p>Festivals and fairs coming up across the Davao Region.</p>
            </div>
            <a href="{{ route('events.index') }}" class="btn dpost-cta">See full calendar</a>
        </div>
        <div class="happening-grid">
            @foreach ($upcomingEvents as $event)
                @include('events.card', ['event' => $event])
            @endforeach
        </div>
    </div>
</section>
@endif
