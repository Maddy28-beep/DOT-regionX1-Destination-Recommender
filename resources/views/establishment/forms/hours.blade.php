{{--
    Opening hours as seven rows. The server turns them into the one line of text listings keep
    (App\Support\OpeningHoursText). Expects $week (index 0 = Monday), $unreadable, $currentText.
--}}
<form method="POST" action="{{ route('establishment.listing.update') }}" id="hours-form">
    @csrf
    @method('PUT')
    <input type="hidden" name="section" value="hours">

    @if ($unreadable)
        <x-banner tone="info">
            Your hours are saved as text we cannot split by day (<em>{{ $currentText }}</em>). Saving here replaces them.
        </x-banner>
    @endif

    <div style="display:flex; justify-content:flex-end; margin:0 0 8px;">
        <button type="button" class="btn btn-outline btn-sm" id="hours-copy">Copy Monday to every day</button>
    </div>

    <div class="ptr-hours">
        @foreach (\App\Support\OpeningHoursText::FULL_DAYS as $i => $day)
            @php $row = old("hours.{$i}", $week[$i]); @endphp
            <div class="ptr-hours__row" data-day>
                <label class="ptr-hours__on">
                    <input type="checkbox" name="hours[{{ $i }}][on]" value="1" @checked(! empty($row['on']))>
                    <span>{{ substr($day, 0, 3) }}<span class="sr-only"> open</span></span>
                </label>
                <input type="time" name="hours[{{ $i }}][from]" value="{{ $row['from'] ?? '08:00' }}" aria-label="{{ $day }} opens">
                <input type="time" name="hours[{{ $i }}][to]" value="{{ $row['to'] ?? '17:00' }}" aria-label="{{ $day }} closes">
            </div>
        @endforeach
    </div>
    <p class="field-hint">Untick a day you are closed.</p>

    <div class="util-row" style="margin-top:22px;">
        <button type="submit" class="btn btn-primary">Save hours</button>
        <a href="{{ route('establishment.listing.edit') }}" class="btn btn-ghost" data-modal-close>Cancel</a>
    </div>
</form>

<script>
    (function () {
        var form = document.getElementById('hours-form');
        if (!form) return;
        var rows = [].slice.call(form.querySelectorAll('[data-day]'));
        function sync(row) {
            var on = row.querySelector('input[type="checkbox"]').checked;
            row.querySelectorAll('input[type="time"]').forEach(function (t) { t.disabled = !on; });
        }
        rows.forEach(function (row) { sync(row); row.querySelector('input[type="checkbox"]').addEventListener('change', function () { sync(row); }); });
        document.getElementById('hours-copy').addEventListener('click', function () {
            var first = rows[0];
            var on = first.querySelector('input[type="checkbox"]').checked;
            var times = first.querySelectorAll('input[type="time"]');
            rows.forEach(function (row) {
                row.querySelector('input[type="checkbox"]').checked = on;
                row.querySelectorAll('input[type="time"]').forEach(function (t, i) { t.value = times[i].value; });
                sync(row);
            });
        });
    })();
</script>
