/*
 * Trip form, "Your trip": the days stepper, the dates under it, the quick dates and the arrival-time chips.
 *
 * All of it sits on top of the real inputs. The stepper's buttons change the number input; a quick date fills the
 * date input; a time chip writes its time into the time input, and "Exact time" takes that input into its own chip.
 * Without JavaScript the buttons and chips stay hidden and the plain inputs are all there is, so nothing here is
 * needed to submit the form.
 */
(() => {
    'use strict';

    const days = document.getElementById('travel_days');
    const startDate = document.getElementById('start_date');
    const fire = input => {
        input.dispatchEvent(new Event('input', {bubbles: true}));
        input.dispatchEvent(new Event('change', {bubbles: true}));
    };

    // ---- days: [-] 3 days [+], and the dates they make
    const datesLine = document.querySelector('[data-trip-dates]');
    const shortDate = date => date.toLocaleDateString('en-US', {month: 'short', day: 'numeric'});
    const tripDates = () => {
        if (!datesLine) return;
        const n = Math.min(30, Math.max(1, Number(days && days.value) || 1));
        if (!startDate || !startDate.value) {
            datesLine.textContent = 'Add an arrival date to see your dates.';
            datesLine.classList.remove('is-set');
            return;
        }
        const first = new Date(`${startDate.value}T00:00:00`);
        const last = new Date(first);
        last.setDate(first.getDate() + n - 1);
        datesLine.textContent = n === 1 ? shortDate(first) : `${shortDate(first)} – ${shortDate(last)}`;
        datesLine.classList.add('is-set');
    };

    document.querySelectorAll('[data-stepper]').forEach(stepper => {
        const input = stepper.querySelector('input[type="number"]');
        const unit = stepper.querySelector('[data-stepper-unit]');
        if (!input) return;
        stepper.classList.add('is-ready');

        const min = Number(input.min) || 1;
        const max = Number(input.max) || 30;
        const clamp = value => Math.min(max, Math.max(min, value));
        const refresh = () => {
            const value = Number(input.value);
            if (unit) unit.textContent = value === 1 ? 'day' : 'days';
            stepper.querySelectorAll('[data-step]').forEach(button => {
                button.disabled = Number(button.dataset.step) < 0 ? value <= min : value >= max;
            });
            tripDates();
        };

        stepper.querySelectorAll('[data-step]').forEach(button => button.addEventListener('click', () => {
            input.value = clamp((Number(input.value) || min) + Number(button.dataset.step));
            fire(input);
        }));
        input.addEventListener('input', refresh);
        input.addEventListener('change', () => { if (input.value !== '') input.value = clamp(Number(input.value)); refresh(); });
        refresh();
    });

    // ---- quick dates: This weekend / Next week / Next month
    const quick = document.querySelector('[data-quick-dates]');
    if (quick && startDate) {
        quick.hidden = false;
        const iso = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        quick.addEventListener('click', event => {
            const kind = event.target.closest('[data-quick]')?.dataset.quick;
            if (!kind) return;
            const date = new Date();
            date.setHours(0, 0, 0, 0);
            if (kind === 'weekend') date.setDate(date.getDate() + ((6 - date.getDay() + 7) % 7 || 7));   // the coming Saturday
            if (kind === 'week') date.setDate(date.getDate() + ((8 - date.getDay()) % 7 || 7));          // the coming Monday
            if (kind === 'month') date.setMonth(date.getMonth() + 1, 1);                                 // the 1st of next month
            startDate.value = iso(date);
            fire(startDate);
        });
    }
    if (startDate) {
        startDate.addEventListener('input', tripDates);
        startDate.addEventListener('change', tripDates);
        tripDates();
    }

    // ---- arrival time: Morning / Midday / Evening / Exact time
    document.querySelectorAll('[data-arrival]').forEach(box => {
        const chips = box.querySelector('[data-arrival-slots]');
        const input = box.querySelector('input[type="time"]');
        const exactChip = chips && chips.querySelector('[data-exact]');
        if (!chips || !input || !exactChip) return;
        box.classList.add('has-slots');

        const slots = [...chips.querySelectorAll('[data-time]')];
        const exactButton = exactChip.querySelector('[data-exact-open]');
        const exactSub = exactChip.querySelector('[data-exact-sub]');
        // The time input moves into its chip, so opening it changes nothing about the other chips.
        exactChip.append(input);
        let exactOpen = false;

        const clock = value => {
            const [h, m] = value.split(':').map(Number);
            return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h >= 12 ? 'PM' : 'AM'}`;
        };
        const refresh = () => {
            const value = input.value;
            const preset = slots.find(slot => slot.dataset.time === value);
            // A time that is not one of the three presets was typed in, so it is an exact time.
            const isExact = exactOpen || (value !== '' && !preset);
            slots.forEach(slot => slot.setAttribute('aria-pressed', String(!isExact && slot === preset)));
            exactButton.setAttribute('aria-pressed', String(isExact));
            exactChip.classList.toggle('is-open', isExact);
            exactSub.textContent = isExact && value ? clock(value) : 'pick hour & minute';
        };

        slots.forEach(slot => slot.addEventListener('click', () => {
            const already = slot.getAttribute('aria-pressed') === 'true';
            exactOpen = false;
            // Choosing the chosen one again clears it: the time is optional, and blank means "assume the earliest start".
            input.value = already ? '' : slot.dataset.time;
            fire(input);
            refresh();
        }));
        exactButton.addEventListener('click', () => {
            exactOpen = exactButton.getAttribute('aria-pressed') !== 'true';
            if (!exactOpen) { input.value = ''; fire(input); }
            refresh();
            if (exactOpen) input.focus();
        });
        input.addEventListener('input', refresh);
        input.addEventListener('change', refresh);
        refresh();
    });
})();
