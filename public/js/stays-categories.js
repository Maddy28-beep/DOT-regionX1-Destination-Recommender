/* Presentation only: expose existing category links while retaining their URLs and selection. */
(() => {
    const bar = document.querySelector('.catalog-refresh .catalog-categories');
    const more = bar?.querySelector('.catalog-categories__more');
    if (!more) return;

    const extras = [...bar.querySelectorAll(':scope > .catalog-categories__extra')];
    const featured = [...bar.querySelectorAll(':scope > .chip:not(.catalog-categories__extra)')];
    const menu = [...more.querySelectorAll('.catalog-categories__menu a')];
    const summary = more.querySelector('summary');
    const label = [...summary.childNodes].find(node => node.nodeType === Node.TEXT_NODE && node.textContent.trim());
    const originalLabel = label.textContent;
    const originalActive = summary.classList.contains('active');
    let lastWidth = -1;

    function fit() {
        more.hidden = false;
        label.textContent = originalLabel;
        summary.classList.toggle('active', originalActive);
        menu.forEach(link => { link.hidden = false; });
        extras.forEach(link => { link.style.removeProperty('display'); });

        // The existing phone/tablet layout exposes every category in a swipeable row.
        if (!window.matchMedia('(min-width: 901px)').matches) return;

        // Measure natural sizes before distributing leftover space across visible buttons.
        [...featured, ...extras, more].forEach(link => { link.style.flex = '0 0 auto'; });
        const style = getComputedStyle(bar);
        const available = bar.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
        const gap = parseFloat(style.columnGap) || 0;
        extras.forEach(link => { link.style.display = 'inline-flex'; });
        const widths = extras.map(link => link.getBoundingClientRect().width);
        const baseWidth = featured.reduce((sum, link) => sum + link.getBoundingClientRect().width, 0);
        const allWidth = baseWidth + widths.reduce((sum, width) => sum + width, 0)
            + gap * (featured.length + extras.length - 1);
        let used = baseWidth + gap * featured.length + more.getBoundingClientRect().width;

        extras.forEach((link, index) => {
            const fits = allWidth <= available || used + gap + widths[index] <= available;
            link.style.display = fits ? 'inline-flex' : 'none';
            if (fits) used += gap + widths[index];
            const duplicate = menu.find(item => item.href === link.href);
            if (duplicate) duplicate.hidden = fits;
        });

        more.hidden = menu.every(link => link.hidden);
        const activeVisible = extras.some(link => link.style.display !== 'none' && link.hasAttribute('aria-current'));
        if (activeVisible) {
            label.textContent = (more.dataset.moreLabel || 'More categories') + ' ';
            summary.classList.remove('active');
        }
        [...featured, ...extras, more].forEach(link => { link.style.removeProperty('flex'); });
    }

    fit();
    document.fonts.ready.then(fit);
    new ResizeObserver(() => {
        const width = bar.getBoundingClientRect().width;
        if (width === lastWidth) return;
        lastWidth = width;
        fit();
    }).observe(bar);
})();
