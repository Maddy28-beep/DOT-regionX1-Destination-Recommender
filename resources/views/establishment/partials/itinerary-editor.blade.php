{{--
    The day-by-day editor: one card per day, saved as the "Title | Description" text the server reads (one day per line).
    Used by the package owner's listing form and by a tour operator's package form.

    $formId the form this sits in, $text the saved days as text, $field the name of the hidden textarea (default itinerary)
--}}
@php $field = $field ?? 'itinerary'; @endphp
<div class="itinerary-editor" id="itinerary-editor"></div>
<template id="itinerary-day-template">
    <div class="itinerary-day-card">
        <div class="itinerary-day-card__head">
            <span class="itinerary-day-card__label">Day <span data-day-number></span></span>
            <div class="itinerary-day-card__actions">
                <button type="button" data-move-up aria-label="Move this day earlier" title="Move earlier">&#9650;</button>
                <button type="button" data-move-down aria-label="Move this day later" title="Move later">&#9660;</button>
                <button type="button" data-remove aria-label="Remove this day" title="Remove day">&#10005;</button>
            </div>
        </div>
        <input type="text" data-day-title placeholder="What happens this day? e.g. Arrival &amp; Camp 1">
        <textarea data-day-description rows="2" placeholder="More detail (optional)"></textarea>
    </div>
</template>
<button type="button" class="btn btn-outline btn-sm" id="itinerary-add-day" style="margin-top:4px;">+ Add another day</button>
<textarea id="itinerary" name="{{ $field }}" hidden>{{ old($field, $text) }}</textarea>

<script>
    /*
     * Builds the "Title | Description" text from the day cards, right before the form is sent. The hidden textarea is
     * still a plain field, so the server reads the same text whether this script ran or not. It runs again each time
     * the form is loaded into the modal.
     */
    (function () {
        var form = document.getElementById(@json($formId));
        var editor = document.getElementById('itinerary-editor');
        var template = document.getElementById('itinerary-day-template');
        var addBtn = document.getElementById('itinerary-add-day');
        var hidden = document.getElementById('itinerary');
        if (!form || !editor || !template || !addBtn || !hidden) return;

        function renumber() {
            var cards = editor.querySelectorAll('.itinerary-day-card');
            cards.forEach(function (card, index) {
                card.querySelector('[data-day-number]').textContent = index + 1;
                card.querySelector('[data-move-up]').disabled = index === 0;
                card.querySelector('[data-move-down]').disabled = index === cards.length - 1;
            });
            var count = document.getElementById('itinerary-count');
            if (count) count.textContent = cards.length;
        }
        function addCard(title, description) {
            var card = template.content.firstElementChild.cloneNode(true);
            card.querySelector('[data-day-title]').value = title || '';
            card.querySelector('[data-day-description]').value = description || '';
            card.querySelector('[data-remove]').addEventListener('click', function () { card.remove(); renumber(); });
            card.querySelector('[data-move-up]').addEventListener('click', function () { var p = card.previousElementSibling; if (p) editor.insertBefore(card, p); renumber(); });
            card.querySelector('[data-move-down]').addEventListener('click', function () { var n = card.nextElementSibling; if (n) editor.insertBefore(n, card); renumber(); });
            editor.appendChild(card);
        }

        var lines = hidden.value.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
        if (lines.length) {
            lines.forEach(function (line) { var bar = line.indexOf('|'); if (bar === -1) addCard(line, ''); else addCard(line.slice(0, bar).trim(), line.slice(bar + 1).trim()); });
        } else {
            addCard('', '');
        }
        renumber();
        addBtn.addEventListener('click', function () { addCard('', ''); renumber(); });

        // Capture phase, so the text is ready before the modal's own submit handler reads the form.
        form.addEventListener('submit', function () {
            var out = [];
            editor.querySelectorAll('.itinerary-day-card').forEach(function (card) {
                var title = card.querySelector('[data-day-title]').value.trim();
                var description = card.querySelector('[data-day-description]').value.trim();
                if (!title) return;
                out.push(description ? title + ' | ' + description : title);
            });
            hidden.value = out.join('\n');
        }, true);
    })();
</script>
