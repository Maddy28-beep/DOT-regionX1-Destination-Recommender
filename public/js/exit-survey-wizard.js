(function () {
    'use strict';
    var root = document.querySelector('[data-survey-wizard]');
    if (!root) return;
    var form = root.querySelector('[data-survey-form]');
    var panels = Array.from(form.querySelectorAll('[data-survey-step]'));
    var progress = root.querySelector('[data-survey-progress]');
    var navigation = root.querySelector('[data-survey-navigation]');
    var submit = form.querySelector('[data-survey-submit]');
    var guideTitles = ['How was your Davao trip?', 'Where did your adventure take you?', 'One last thing — how did it feel?'];
    var guideCopy = [
        'Let’s start with a few trip details. You can skip anything on this step.',
        'Tell me what you explored and where you stayed. You can skip this step.',
        'Rate your visit and tell me if you’d recommend Davao. The extra ratings and comments are optional.'
    ];
    // Keep only the current conversational message visible; its heading is the speech bubble.
    var bubble = root.querySelector('.survey-guide__bubble');
    var guideMessages = guideTitles.map(function (title, index) {
        var message = document.createElement('div');
        message.className = 'survey-guide__message';
        var heading = document.createElement('h2');
        heading.textContent = title;
        var copy = document.createElement('p');
        copy.textContent = guideCopy[index];
        message.append(heading, copy);
        message.hidden = true;
        return message;
    });
    bubble.replaceChildren.apply(bubble, guideMessages);
    var stage = root.querySelector('[data-survey-stage]');
    function reserveStage() {
        // Let each card grow naturally. Reserve the tallest step in the page background
        // so changing steps does not move the page footer.
        var mobile = window.matchMedia('(max-width:640px)').matches;
        var panelHeight = Math.max.apply(Math, panels.map(function (panel) { return panel.scrollHeight; }));
        var guideSlack = 0;
        if (mobile) {
            var tallestMessage = Math.max.apply(Math, guideMessages.map(function (message) { return message.getBoundingClientRect().height; }));
            var imageHeight = root.querySelector('.survey-guide__davo').getBoundingClientRect().height;
            guideSlack = Math.max(imageHeight, tallestMessage) - Math.max(imageHeight, bubble.getBoundingClientRect().height);
        }
        stage.style.minHeight = Math.ceil(panelHeight + navigation.getBoundingClientRect().height + Math.max(0, guideSlack) + 2) + 'px';
    }
    var current = 0;
    function show(index, focus) {
        var previous = current;
        current = Math.max(0, Math.min(panels.length - 1, index));
        panels.forEach(function (panel, i) { panel.hidden = i !== current; });
        progress.querySelectorAll('[data-survey-go]').forEach(function (button, i) {
            if (i === current) button.setAttribute('aria-current', 'step');
            else button.removeAttribute('aria-current');
        });
        guideMessages.forEach(function (message, i) { message.hidden = i !== current; });
        root.querySelector('[data-survey-step-label]').textContent = 'Step ' + (current + 1) + ' of 3';
        root.querySelector('[data-survey-back]').hidden = current === 0;
        root.querySelector('[data-survey-skip]').hidden = current === 2;
        var next = root.querySelector('[data-survey-next-primary]');
        next.hidden = current === 2;
        submit.hidden = current !== 2;
        next.textContent = current === 0 ? 'Next: your visit →' : 'Next: your experience →';
        reserveStage();
        if (previous !== current) document.dispatchEvent(new CustomEvent('davo:survey-step-change'));
        if (focus) {
            // Only scroll when the step navigation is outside the readable area below the sticky header.
            var top = progress.getBoundingClientRect().top;
            if (top < 90 || top > window.innerHeight) progress.scrollIntoView({ block: 'start', behavior: 'auto' });
            panels[current].querySelector('h2').focus({ preventScroll: true });
        }
    }
    function validCurrent() {
        var fields = Array.from(panels[current].querySelectorAll('input, select, textarea'));
        var invalid = fields.find(function (field) { return !field.checkValidity(); });
        if (invalid) { invalid.reportValidity(); return false; }
        return true;
    }
    root.querySelectorAll('[data-survey-next]').forEach(function (button) {
        button.addEventListener('click', function () { if (validCurrent()) show(current + 1, true); });
    });
    root.querySelector('[data-survey-back]').addEventListener('click', function () { show(current - 1, true); });
    progress.querySelectorAll('[data-survey-go]').forEach(function (button) {
        button.addEventListener('click', function () {
            var target = Number(button.getAttribute('data-survey-go'));
            if (target <= current || validCurrent()) show(target, true);
        });
    });
    // Reveal invalid fields before native validation tries to focus them. All inputs remain enabled.
    form.addEventListener('invalid', function (event) {
        var panel = event.target.closest('[data-survey-step]');
        if (!panel) return;
        var details = event.target.closest('details');
        if (details) details.open = true;
        show(panels.indexOf(panel), false);
    }, true);
    form.addEventListener('keydown', function (event) {
        if (!event.defaultPrevented && event.key === 'Enter' && current < 2 && event.target.matches('input') && !event.target.closest('[data-tag-search], [data-survey-stays]')) {
            event.preventDefault();
            if (validCurrent()) show(current + 1, true);
        }
    });
    form.addEventListener('submit', function (event) {
        if (current < 2) { event.preventDefault(); if (validCurrent()) show(current + 1, true); }
    });
    root.classList.add('survey-wizard-ready');
    navigation.appendChild(submit);
    progress.hidden = false;
    navigation.hidden = false;
    show(Number(form.getAttribute('data-initial-step')) || 0, false);
    if (window.ResizeObserver) {
        var layoutObserver = new ResizeObserver(reserveStage);
        panels.forEach(function (panel) { layoutObserver.observe(panel); });
        form.querySelectorAll('.panel-head, .panel-body').forEach(function (section) { layoutObserver.observe(section); });
        guideMessages.forEach(function (message) { layoutObserver.observe(message); });
        layoutObserver.observe(navigation);
    }
})();
