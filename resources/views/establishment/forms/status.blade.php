{{-- Operating status, on its own. Expects $listing. --}}
<form method="POST" action="{{ route('establishment.listing.update') }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="section" value="status">

    @include('partials.operating-status-fields', ['listing' => $listing])

    <div class="util-row" style="margin-top:8px;">
        <button type="submit" class="btn btn-primary">Save status</button>
        <a href="{{ route('establishment.listing.edit') }}" class="btn btn-ghost" data-modal-close>Cancel</a>
    </div>
</form>
