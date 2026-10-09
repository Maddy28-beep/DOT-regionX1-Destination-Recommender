(() => {
    'use strict';
    const root = document.getElementById('planWizard');
    const form = document.getElementById('planPreferencesForm');
    if (!root || !form) return;
    const sections = [...form.querySelectorAll('.plan-section')];
    const sectionFor = name => form.elements.namedItem(name)?.closest('.plan-section');
    const basics = sectionFor('travel_days');
    const style = sectionFor('travel_type');
    const budget = sectionFor('budget');
    const origin = sectionFor('origin_label');
    const health = sectionFor('health_other');
    const interests = form.querySelector('[name="activities[]"]')?.closest('.plan-section');
    if (![basics, style, budget, origin, health, interests].every(Boolean)) return;
    const actions = form.querySelector('.plan-wizard-actions');
    const nav = root.querySelector('.plan-wizard-steps');
    const panel = root.querySelector('.plan-panel');
    const loading = document.getElementById('planLoading');
    const back = document.getElementById('planBack');
    const next = document.getElementById('planNext');
    const submit = form.querySelector('[type="submit"]');
    const status = document.getElementById('planStepStatus');
    const headings = ['Tell us about your trip.', 'What would you love to do?', 'Make it comfortable for you.'];
    const descriptions = [
        'The basics — when you’re coming and who’s coming with you.',
        'Choose the interests you’d like your trip to include.',
        'Choose your spending style and how far you’d like to explore.'
    ];
    const steps = headings.map((title, index) => {
        const step = document.createElement('section');
        step.id = `plan-step-${index}`;
        step.className = 'plan-wizard-step';
        step.setAttribute('aria-labelledby', `plan-step-heading-${index}`);
        const heading = document.createElement('h2');
        heading.id = `plan-step-heading-${index}`;
        heading.tabIndex = -1;
        heading.textContent = title;
        const intro = document.createElement('p');
        intro.className = 'plan-step-intro';
        intro.textContent = descriptions[index];
        step.append(heading, intro);
        form.insertBefore(step, actions);
        return step;
    });
    steps[0].append(basics, style, origin);
    steps[1].append(interests);
    steps[2].append(budget);
    // Pair duration with travel companions, then keep date/time together.
    const companions = form.querySelector('#travel_type').closest('.field');
    const arrivalDate = form.querySelector('#start_date').closest('.field');
    basics.querySelector('.filter-inline').insertBefore(companions, arrivalDate);
    origin.classList.add('plan-starting-point');
    form.querySelector('#distance_pref').closest('.field').classList.add('plan-field-wide');
    const purpose = form.querySelector('#travel_purpose').closest('.field');
    purpose.classList.add('plan-purpose');
    steps[1].insertBefore(purpose, interests);
    function optionalGroup(label, fields) {
        const options = document.createElement('details');
        options.className = 'plan-optional';
        const summary = document.createElement('summary');
        summary.textContent = label;
        options.append(summary, ...fields);
        steps[2].append(options);
        if ([...options.querySelectorAll('input,textarea')].some(el => el.type === 'checkbox' ? el.checked : el.value.trim())) options.open = true;
    }
    // Amenities is no longer wrapped in its own optional accordion here: it
    // became a required field (see TripPlannerController::update()) and
    // already lives correctly inside `interests`, appended into steps[1]
    // above -- collapsing a required field behind a closed "optional"
    // disclosure would hide the very thing validation is about to demand.
    optionalGroup('Health, accessibility & other needs · optional', [health, form.querySelector('#accessibility_notes').closest('.field')]);
    sections.forEach(section => section.classList.add('plan-section-group'));
    // Move the original elements rather than cloning them: names, values,
    // address-picker listeners and health consent all retain their behavior.
    root.classList.add('is-wizard');
    nav.hidden = false;
    status.hidden = false;
    form.noValidate = true;
    let current = 0;
    let busy = false;
    function show(index, focus = false) {
        current = index;
        steps.forEach((step, i) => { step.hidden = i !== index; });
        nav.querySelectorAll('[data-plan-step]').forEach((button, i) => {
            button.classList.toggle('is-complete', i < index);
            if (i === index) button.setAttribute('aria-current', 'step');
            else button.removeAttribute('aria-current');
        });
        back.hidden = index === 0;
        next.hidden = index === 2;
        submit.hidden = index !== 2;
        next.textContent = index === 0 ? 'Next: your interests →' : 'Next: your comfort →';
        status.textContent = `Step ${index + 1} of 3`;
        if (focus) {
            steps[index].querySelector('h2').focus({preventScroll: true});
            nav.scrollIntoView({block: 'start'});
        }
    }
    function validate(indices) {
        for (const index of indices) {
            const invalid = [...steps[index].querySelectorAll('input,select,textarea')].find(el => !el.checkValidity());
            if (invalid) {
                show(index);
                const details = invalid.closest('details');
                if (details) details.open = true;
                invalid.reportValidity();
                return false;
            }
        }
        return true;
    }
    next.addEventListener('click', () => { if (validate([current])) show(current + 1, true); });
    back.addEventListener('click', () => show(current - 1, true));
    nav.querySelectorAll('[data-plan-step]').forEach(button => button.addEventListener('click', () => {
        const target = Number(button.dataset.planStep);
        if (target <= current || validate(Array.from({length: target}, (_, i) => i))) show(target, true);
    }));
    const rows = [...loading.querySelectorAll('.plan-loading-workflow li')];
    const itineraryLink = document.getElementById('planViewItinerary');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const errorBox = document.createElement('div');
    errorBox.className = 'alert alert-error';
    errorBox.setAttribute('role', 'alert');
    errorBox.tabIndex = -1;
    errorBox.hidden = true;
    form.prepend(errorBox);
    function resetLoading() {
        busy = false;
        root.classList.remove('is-building');
        panel.hidden = false;
        nav.hidden = false;
        loading.hidden = true;
        loading.classList.remove('is-ready', 'is-finishing');
        loading.style.removeProperty('--plan-progress');
        itineraryLink.hidden = true;
        itineraryLink.removeAttribute('href');
        rows.forEach(row => { row.classList.remove('is-complete', 'is-active'); row.querySelector('span').textContent = ''; });
        document.getElementById('planLoadingTitle').textContent = 'Davo is planning your trip…';
    }
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy) return;
        if (current < 2) { if (validate([current])) show(current + 1, true); return; }
        if (!validate([0, 1, 2])) return;
        busy = true;
        errorBox.hidden = true;
        root.classList.add('is-building');
        panel.hidden = true;
        nav.hidden = true;
        loading.hidden = false;
        document.getElementById('planLoadingTitle').textContent = `Davo is planning your ${form.elements.namedItem('travel_days').value}-day trip…`;
        rows.forEach(row => row.classList.add('is-active'));
        loading.focus();
        loading.scrollIntoView({block: 'start'});
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: new FormData(form),
                headers: {Accept: 'application/json'}, credentials: 'same-origin'
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                resetLoading();
                const errors = data.errors || {};
                const field = [...form.elements].find(el => Object.keys(errors).some(key => key.split('.')[0] === el.name.replace(/\[\]$/, '')));
                if (field) {
                    show(Math.max(0, steps.indexOf(field.closest('.plan-wizard-step'))));
                    const details = field.closest('details');
                    if (details) details.open = true;
                }
                errorBox.textContent = response.status === 422
                    ? Object.values(errors).flat().join(' ') || 'Please check your answers.'
                    : response.status === 419 ? 'Your session expired. Refresh the page before trying again.'
                    : 'We could not finish your request. Your answers are still here; please try again.';
                errorBox.hidden = false;
                errorBox.focus();
                return;
            }
            if (!data.redirect || new URL(data.redirect, location.href).origin !== location.origin) throw new Error('Invalid response');
            // The operations have finished on the server. Reveal their
            // checks in sequence as a completion animation, not fake live progress.
            loading.classList.add('is-finishing');
            for (const [index, row] of rows.entries()) {
                row.classList.remove('is-active');
                row.classList.add('is-complete');
                row.querySelector('span').textContent = '✓';
                loading.style.setProperty('--plan-progress', `${(index + 1) / rows.length * 100}%`);
                if (!reducedMotion) await new Promise(resolve => setTimeout(resolve, 250));
            }
            loading.classList.add('is-ready');
            document.getElementById('planLoadingTitle').textContent = 'Your itinerary is ready';
            itineraryLink.href = data.redirect;
            itineraryLink.hidden = false;
            await new Promise(resolve => setTimeout(resolve, 400));
            location.assign(data.redirect);
        } catch (_) {
            resetLoading();
            errorBox.textContent = 'Connection interrupted. Your answers are still here. Check your connection before trying again.';
            errorBox.hidden = false;
            errorBox.focus();
        }
    });
    window.addEventListener('pageshow', () => {
        resetLoading();
        show(current);
    });
    const errors = window.planWizardErrors || [];
    const badField = [...form.elements].find(el => errors.some(key => key.split('.')[0] === el.name.replace(/\[\]$/, '')));
    if (badField) {
        const details = badField.closest('details');
        if (details) details.open = true;
        const index = steps.indexOf(badField.closest('.plan-wizard-step'));
        show(Math.max(0, index));
        badField.setAttribute('aria-invalid', 'true');
    } else show(0);
})();
