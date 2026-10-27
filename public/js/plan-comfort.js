/*
 * Trip form, "Getting around": shows the Jeepney / Taxi chips or the tour operator note under the chosen
 * transport card, and draws what a sample trip would take in each way of travelling.
 *
 * The speeds, and the overhead of each leg, are read from the page (TransportMode::comparison()) so the
 * bars use the same table the planner times journeys with. A sample is a road distance for each choice of
 * "how far"; the times are rounded to the quarter hour like the planner's own.
 */
(() => {
    'use strict';
    const box = document.querySelector('[data-transport-compare]');
    const form = document.getElementById('planPreferencesForm');
    if (!box || !form) return;

    let modes, samples;
    try {
        modes = JSON.parse(box.dataset.modes);
        samples = JSON.parse(box.dataset.samples);
    } catch (_) { return; }

    const checked = name => form.querySelector(`input[name="${name}"]:checked`);
    const quarter = minutes => Math.max(5, Math.round(minutes / 15) * 15);
    const format = minutes => {
        if (minutes < 60) return `${minutes} min`;
        const hours = Math.floor(minutes / 60), rest = minutes % 60;
        return rest ? `${hours} h ${rest} min` : `${hours} h`;
    };
    const range = (km, mode) => [
        quarter(km / mode.fast * 60 + mode.overhead),
        quarter(km / mode.slow * 60 + mode.overhead),
    ];

    const publicBox = form.querySelector('[data-transport-public]');
    const operatorBox = form.querySelector('[data-transport-operator]');
    const title = box.querySelector('[data-compare-title]');
    const bars = box.querySelector('[data-compare-bars]');
    const tip = box.querySelector('[data-compare-tip]');
    const names = { near: 'a trip around the city', moderate: 'a trip to a neighbouring city', far: 'a long trip across the region' };

    function chosenMode() {
        const transport = checked('transport_pref')?.value;
        if (transport === 'tour_operator') return 'tour_operator';
        if (transport === 'public') return checked('public_kind')?.value === 'taxi' ? 'taxi' : 'jeep';
        if (transport === 'personal_car' || transport === 'rental_car') return 'personal_car';
        return null;
    }

    function render() {
        const transport = checked('transport_pref')?.value;
        if (publicBox) publicBox.hidden = transport !== 'public';
        if (operatorBox) operatorBox.hidden = transport !== 'tour_operator';

        const distance = checked('distance_pref')?.value || 'moderate';
        const km = samples[distance] || samples.moderate;
        const mine = chosenMode();
        title.textContent = `What ${names[distance] || names.moderate} (about ${km} km) would take`;

        const rows = Object.entries(modes).map(([key, mode]) => ({ key, mode, time: range(km, mode) }));
        const longest = Math.max(...rows.map(row => row.time[1]));
        bars.replaceChildren(...rows.map(row => {
            const line = document.createElement('div');
            line.className = 'pc-bar' + (row.key === mine ? ' is-yours' : '');
            const name = document.createElement('span');
            name.className = 'pc-bar__name';
            name.textContent = row.mode.label;
            const track = document.createElement('span');
            track.className = 'pc-bar__track';
            const fill = document.createElement('span');
            fill.className = 'pc-bar__fill';
            fill.style.width = `${Math.round(row.time[1] / longest * 100)}%`;
            track.append(fill);
            const time = document.createElement('span');
            time.className = 'pc-bar__time';
            time.textContent = `${format(row.time[0])} – ${format(row.time[1])}`;
            line.append(name, track, time);
            return line;
        }));

        const own = mine ? range(km, modes[mine]) : null;
        if (own && own[1] >= 180) {
            const car = range(km, modes.personal_car);
            const slower = mine === 'jeep' || mine === 'taxi';
            tip.textContent = `Heads up: with ${modes[mine].label.toLowerCase()} this could take up to ${format(own[1])} one way.`
                + (slower && car[1] < own[1] ? ` A car would take about ${format(car[1])}.` : '')
                + ' We keep each day shorter to fit.';
            tip.hidden = false;
        } else {
            tip.hidden = true;
        }
    }

    form.addEventListener('change', event => {
        if (['transport_pref', 'public_kind', 'distance_pref'].includes(event.target.name)) render();
    });
    render();
})();
