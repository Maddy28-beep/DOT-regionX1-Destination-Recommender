@php $notice = $listing->operatingNotice(); @endphp
@if ($notice)
    <div class="operating-notice" role="alert">
        <strong>{{ $notice }}</strong>
        <span>Trip plans skip this place while it is closed.</span>
    </div>
@endif
