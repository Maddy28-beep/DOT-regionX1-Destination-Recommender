{{-- The map position, on its own: the existing picker (Leaflet, bounded to the Davao Region). Expects $listing. --}}
<form method="POST" action="{{ route('establishment.listing.update') }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="section" value="position">

    @include('partials.location-picker', ['listing' => $listing])

    <div class="util-row" style="margin-top:22px;">
        <button type="submit" class="btn btn-primary">Save position</button>
        <a href="{{ route('establishment.listing.edit') }}" class="btn btn-ghost" data-modal-close>Cancel</a>
    </div>
</form>
