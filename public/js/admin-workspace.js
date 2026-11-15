(() => {
    'use strict';
    const sidebar = document.getElementById('adminSidebar');
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const scrim = document.querySelector('[data-sidebar-close]');
    if (!sidebar || !toggle || !scrim) return;
    const mobile = window.matchMedia('(max-width: 900px)');
    const setOpen = (open, returnFocus = false) => {
        sidebar.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
        scrim.hidden = !open;
        if (mobile.matches) sidebar.inert = !open;
        else sidebar.inert = false;
        if (open) (sidebar.querySelector('a.active') || sidebar.querySelector('a'))?.focus();
        else if (returnFocus) toggle.focus();
    };
    sidebar.querySelectorAll('a.active').forEach(link => link.setAttribute('aria-current', 'page'));
    toggle.addEventListener('click', () => setOpen(!sidebar.classList.contains('open')));
    scrim.addEventListener('click', () => setOpen(false, true));
    sidebar.addEventListener('click', event => {
        if (mobile.matches && event.target.closest('a')) setOpen(false);
    });
    document.addEventListener('keydown', event => {
        if (!mobile.matches || !sidebar.classList.contains('open')) return;
        if (event.key === 'Escape') { event.preventDefault(); setOpen(false, true); }
        if (event.key === 'Tab') {
            const links = [...sidebar.querySelectorAll('a[href]')];
            const first = links[0];
            const last = links[links.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); toggle.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); toggle.focus(); }
            else if (document.activeElement === toggle) { event.preventDefault(); (event.shiftKey ? last : first)?.focus(); }
        }
    });
    mobile.addEventListener('change', () => setOpen(false));
    setOpen(false);
})();
