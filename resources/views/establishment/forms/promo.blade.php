{{-- Add a promo. Dates are optional: with none it shows straight away and stays until removed. --}}
<form method="POST" action="{{ route('establishment.promotions.store') }}" id="promo-form">
    @csrf

    <div class="ptr-two">
        <div class="field">
            <label for="promo_title">Title</label>
            <input type="text" id="promo_title" name="title" maxlength="150" required value="{{ old('title') }}" placeholder="e.g. Weekday dive package">
        </div>
        <div class="field">
            <label for="promo_code">Promo code (optional)</label>
            <input type="text" id="promo_code" name="code" maxlength="30" value="{{ old('code') }}" placeholder="e.g. DIVE20">
        </div>
    </div>

    <div class="field">
        <label for="promo_description">Details (optional)</label>
        <textarea id="promo_description" name="description" rows="3" maxlength="500">{{ old('description') }}</textarea>
    </div>

    <div class="ptr-two">
        <div class="field">
            <label for="promo_starts">Starts (optional)</label>
            <input type="date" id="promo_starts" name="starts_at" value="{{ old('starts_at') }}">
        </div>
        <div class="field">
            <label for="promo_ends">Ends (optional)</label>
            <input type="date" id="promo_ends" name="ends_at" value="{{ old('ends_at') }}">
        </div>
    </div>

    <div class="ptr-preview" aria-live="polite">
        <span class="ptr-eyebrow">How it will look</span>
        <div class="ptr-promo-tag"><span class="ptr-tag ptr-tag--promo" id="promo-tag">Your promo</span> <span class="status-pill status-active" id="promo-state">Showing</span></div>
        <p class="field-hint" id="promo-hint" style="margin:6px 0 0;">With no dates it shows straight away and stays on until you remove it.</p>
    </div>

    <div class="util-row" style="margin-top:22px;">
        <button type="submit" class="btn btn-primary">Add promo</button>
        <a href="{{ route('establishment.promotions') }}" class="btn btn-ghost" data-modal-close>Cancel</a>
    </div>
</form>

<script>
    (function () {
        var form = document.getElementById('promo-form');
        if (!form) return;
        var title = document.getElementById('promo_title'), code = document.getElementById('promo_code');
        var from = document.getElementById('promo_starts'), to = document.getElementById('promo_ends');
        var tag = document.getElementById('promo-tag'), state = document.getElementById('promo-state'), hint = document.getElementById('promo-hint');
        var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        function nice(iso) { var p = iso.split('-'); return MONTHS[+p[1] - 1] + ' ' + (+p[2]); }
        function update() {
            var today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
            tag.textContent = (title.value.trim() || 'Your promo') + (code.value.trim() ? ' · code ' + code.value.trim() : '');
            var s = 'live';
            if (to.value && to.value < today) s = 'ended'; else if (from.value && from.value > today) s = 'sched';
            state.className = 'status-pill ' + (s === 'live' ? 'status-active' : s === 'sched' ? 'status-pending' : 'status-expired');
            state.textContent = s === 'live' ? 'Showing' : s === 'sched' ? 'Scheduled' : 'Ended';
            hint.textContent = s === 'sched' ? 'It switches on by itself on ' + nice(from.value) + (to.value ? ' and off after ' + nice(to.value) + '.' : '.')
                : s === 'ended' ? 'That end date has passed, so it would not show.'
                : (to.value ? 'It is showing now and switches off after ' + nice(to.value) + '.' : 'With no dates it shows straight away and stays on until you remove it.');
        }
        [title, code, from, to].forEach(function (f) { f.addEventListener('input', update); });
        update();
    })();
</script>
