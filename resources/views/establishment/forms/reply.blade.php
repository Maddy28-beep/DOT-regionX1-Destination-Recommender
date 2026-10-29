{{-- Reply to a review, from a template if wanted. Expects $review. --}}
@php
    $first = trim(explode(' ', (string) ($review->author_name ?: 'there'))[0]);
    $templates = [
        'Thank you' => "Thank you so much, {$first}! We are glad you enjoyed your visit and hope to welcome you back soon.",
        'Sorry + follow up' => "We are sorry about this, {$first}. We have passed your comments to our team so it does not happen again. Please message us so we can make it right.",
        'Ask for details' => "Thank you for telling us, {$first}. Could you share which room or date this was, so we can look into it?",
    ];
@endphp
<form method="POST" action="{{ route('establishment.reviews.reply', $review) }}">
    @csrf

    <div class="ptr-quote">
        <strong>{{ $review->author_name ?? 'Traveler' }} &middot; <span class="ptr-stars" aria-label="{{ $review->rating }} out of 5">{{ str_repeat('★', $review->rating).str_repeat('☆', 5 - $review->rating) }}</span></strong>
        {{ $review->comment }}
    </div>

    <span class="ptr-eyebrow">Start from a template</span>
    <div class="ptr-templates">
        @foreach ($templates as $label => $text)
            <button type="button" class="btn btn-outline btn-sm" data-template="{{ $text }}">{{ $label }}</button>
        @endforeach
    </div>

    <div class="field">
        <label for="owner_reply">Your reply</label>
        <textarea id="owner_reply" name="owner_reply" rows="5" maxlength="500" required placeholder="Thank them, and answer anything they raised.">{{ old('owner_reply', $review->owner_reply) }}</textarea>
        <p class="field-hint" style="text-align:right;"><span id="owner_reply_count">0</span> / 500</p>
    </div>

    <div class="util-row" style="margin-top:22px;">
        <button type="submit" class="btn btn-primary">Post reply</button>
        <a href="{{ route('establishment.reviews') }}" class="btn btn-ghost" data-modal-close>Cancel</a>
    </div>
</form>

<script>
    (function () {
        var box = document.getElementById('owner_reply'), count = document.getElementById('owner_reply_count');
        if (!box || !count) return;
        function update() { count.textContent = box.value.length; }
        box.addEventListener('input', update);
        document.querySelectorAll('[data-template]').forEach(function (b) {
            b.addEventListener('click', function () { box.value = b.dataset.template; update(); box.focus(); });
        });
        update();
    })();
</script>
