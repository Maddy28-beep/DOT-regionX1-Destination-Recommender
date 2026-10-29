{{-- Photo credit for one photo, or for every photo that has none. Expects $photos, $single, $action. --}}
<form method="POST" action="{{ $action }}">
    @csrf
    @method('PUT')

    @if ($photos->isEmpty())
        <p class="ptr-empty">Every photo already has a credit.</p>
        <div class="util-row" style="margin-top:22px;">
            <a href="{{ route('establishment.photos') }}" class="btn btn-primary" data-modal-close>Close</a>
        </div>
    @else
        <div class="ptr-quote">
            <strong>{{ $single ? 'This photo' : $photos->count().' '.\Illuminate\Support\Str::plural('photo', $photos->count()).' without a credit' }}</strong>
            <ul class="ptr-thumbs">
                @foreach ($photos as $photo)
                    <li><img src="{{ $photo->url() }}" alt="{{ $listing->name }}, {{ $photo->category }}" width="64" height="48"></li>
                @endforeach
            </ul>
        </div>

        <div class="field">
            <label for="credit_text">Photographer or source</label>
            <input type="text" id="credit_text" name="credit_text" maxlength="120" value="{{ old('credit_text', $single ? $photos->first()->credit_text : '') }}" placeholder="e.g. Rey Santos or Pearl Farm staff">
        </div>
        <label class="ptr-check"><input type="checkbox" name="own" value="1" id="credit_own" @checked(old('own'))> These are my own photos</label>

        <div class="util-row" style="margin-top:22px;">
            <button type="submit" class="btn btn-primary">Save credit</button>
            <a href="{{ route('establishment.photos') }}" class="btn btn-ghost" data-modal-close>Cancel</a>
        </div>

        <script>
            (function () {
                var own = document.getElementById('credit_own'), text = document.getElementById('credit_text');
                if (!own || !text) return;
                function sync() { text.disabled = own.checked; }
                own.addEventListener('change', sync);
                sync();
            })();
        </script>
    @endif
</form>
