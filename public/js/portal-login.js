(() => {
    const card = document.querySelector('[data-portal-signin]');
    if (!card) return;

    const tabList = card.querySelector('[data-portal-tabs]');
    const tabs = [...tabList.querySelectorAll('[data-portal]')];
    const panel = card.querySelector('#portal-signin-panel');
    const portalInput = panel.querySelector('input[name="portal"]');
    const submit = panel.querySelector('[data-portal-submit]');
    const help = panel.querySelectorAll('[data-portal-help]');

    function select(tab) {
        portalInput.value = tab.dataset.portal;
        submit.textContent = 'Log In as ' + tab.textContent.trim();
        panel.setAttribute('aria-labelledby', tab.id);
        for (const option of tabs) {
            const active = option === tab;
            option.setAttribute('aria-selected', String(active));
            option.tabIndex = active ? 0 : -1;
        }
        for (const note of help) note.hidden = note.dataset.portalHelp !== portalInput.value;
    }

    function activate(tab) {
        if (portalInput.value === tab.dataset.portal) return;
        // Keep the selected guard in the URL used when failed sign-ins redirect back.
        history.replaceState(history.state, '', tab.href);
        select(tab);
    }

    tabList.setAttribute('role', 'tablist');
    panel.setAttribute('role', 'tabpanel');
    tabs.forEach((tab, index) => {
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-controls', panel.id);
        tab.removeAttribute('aria-current');
        tab.addEventListener('click', event => {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            activate(tab);
        });
        tab.addEventListener('keydown', event => {
            let target;
            switch (event.key) {
                case 'ArrowLeft': target = tabs[(index + tabs.length - 1) % tabs.length]; break;
                case 'ArrowRight': target = tabs[(index + 1) % tabs.length]; break;
                case 'Home': target = tabs[0]; break;
                case 'End': target = tabs[tabs.length - 1]; break;
                case ' ': target = tab; break;
                default: return;
            }
            event.preventDefault();
            activate(target);
            target.focus();
        });
    });
    select(tabs.find(tab => tab.dataset.portal === portalInput.value));
})();
