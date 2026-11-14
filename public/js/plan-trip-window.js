/*
 * Trip form (trip_location_v2): the trip's real start and end, in Philippine time.
 *
 * Shows the length as you type ("3 days, 5 hours") and says what is wrong the moment it is wrong, using the browser's own
 * validity messages so the wizard stops on this step. The server checks the same rules again and is the one that decides.
 * The rules and the wording mirror App\Support\TripWindow. Dates are wall-clock Philippine time: a value such as
 * 2026-12-01T14:00 is never converted, so the device's time zone changes nothing.
 */
(() => {
    'use strict';

    const box = document.querySelector('[data-trip-window]');
    const start = document.getElementById('start_at');
    const end = document.getElementById('end_at');
    const line = document.querySelector('[data-trip-duration]');
    if (!box || !start || !end || !line) return;

    const minHours = Number(box.dataset.minHours) || 3;
    const maxDays = Number(box.dataset.maxDays) || 30;
    const nowValue = box.dataset.now || '';
    const allowPast = box.dataset.allowPast === '1';

    // "2026-12-01T14:00" -> minutes since 1970 as if that wall clock were UTC. Only differences are used, so the zone drops out.
    const minutes = value => {
        const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value || '');
        return m ? Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) / 60000 : null;
    };
    const dayNumber = value => {
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(value || '');
        return m ? Date.UTC(+m[1], +m[2] - 1, +m[3]) / 86400000 : null;
    };
    const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;
    const text = total => {
        const parts = [];
        const days = Math.floor(total / 1440), hours = Math.floor(total % 1440 / 60), mins = total % 60;
        if (days) parts.push(plural(days, 'day'));
        if (hours) parts.push(plural(hours, 'hour'));
        if (mins) parts.push(plural(mins, 'minute'));
        return parts.join(', ') || '0 minutes';
    };

    const check = () => {
        const s = minutes(start.value), e = minutes(end.value), now = minutes(nowValue);
        let startMessage = '', endMessage = '';

        if (s !== null && now !== null && !allowPast && s < now) startMessage = 'Start must be in the future (Philippine time).';
        if (s !== null && e !== null) {
            if (e <= s) endMessage = 'End must be after the start.';
            else if (e - s < minHours * 60) endMessage = `End must be at least ${minHours} hours after the start.`;
            else if (e - s > maxDays * 1440 || dayNumber(end.value) - dayNumber(start.value) + 1 > maxDays) endMessage = `A trip can be at most ${maxDays} days long.`;
        }
        start.setCustomValidity(startMessage);
        end.setCustomValidity(endMessage);
        end.min = start.value || '';

        line.classList.toggle('is-error', Boolean(startMessage || endMessage));
        if (startMessage || endMessage) line.textContent = startMessage || endMessage;
        else if (s !== null && e !== null) line.textContent = `Your trip is ${text(e - s)} long.`;
        else line.textContent = 'Choose a start and an end to see how long your trip is.';
    };

    start.addEventListener('input', check);
    start.addEventListener('change', check);
    end.addEventListener('input', check);
    end.addEventListener('change', check);
    check();
})();
