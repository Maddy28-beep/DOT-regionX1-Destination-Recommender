// Optional exit-survey invitation. No survey answers or identities are stored here.
(function () {
    var state = document.getElementById('surveyInviteState');
    var panel = document.getElementById('surveyInvite');
    function read(store, key) { try { return store.getItem(key); } catch (_) { return null; } }
    function write(store, key, value) { try { store.setItem(key, value); } catch (_) {} }
    var local, session;
    try { local = window.localStorage; session = window.sessionStorage; } catch (_) {}
    if (state && state.dataset.completed === 'true') write(local, 'dvo-survey-completed', 'true');
    if (!panel) return;
    var preview = panel.dataset.preview === 'true';
    if (!preview && (state.dataset.completed === 'true' || read(local, 'dvo-survey-completed') === 'true')) return;
    if (!preview && Number(read(local, 'dvo-survey-snooze')) > Date.now()) return;
    if (!preview && read(session, 'dvo-survey-shown') === 'true') return;
    var views = Number(read(session, 'dvo-survey-views')) || 0;
    var path = location.pathname;
    if (read(session, 'dvo-survey-last-page') !== path) {
        views += 1;
        write(session, 'dvo-survey-views', String(views));
        write(session, 'dvo-survey-last-page', path);
    }
    var previousFocus;
    function dismiss() {
        var hadFocus = panel.contains(document.activeElement);
        panel.hidden = true;
        if (!preview) write(local, 'dvo-survey-snooze', String(Date.now() + 7 * 86400000));
        if (hadFocus && previousFocus && previousFocus.isConnected) previousFocus.focus();
    }
    panel.querySelectorAll('[data-survey-dismiss]').forEach(function (button) { button.addEventListener('click', dismiss); });
    panel.querySelector('[data-survey-answer]').addEventListener('click', function () {
        if (!preview) write(local, 'dvo-survey-snooze', String(Date.now() + 7 * 86400000));
    });
    panel.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { event.preventDefault(); dismiss(); }
    });
    function show() {
        if (document.hidden || document.body.classList.contains('mobile-menu-open') || document.querySelector('dialog[open]') || /^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement.tagName)) {
            setTimeout(show, 5000);
            return;
        }
        previousFocus = document.activeElement;
        panel.hidden = false;
        if (!preview) write(session, 'dvo-survey-shown', 'true');
        document.getElementById('surveyInviteAnnouncement').textContent = 'Finished exploring Davao? An optional exit survey invitation is available.';
    }
    if (preview) show();
    else if (views >= 3 || panel.dataset.checkIn === 'true') setTimeout(show, 30000);
})();
