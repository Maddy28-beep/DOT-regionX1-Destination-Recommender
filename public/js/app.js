// ExploreDVO — card photo carousels + detail-page gallery lightbox.
// Vanilla JS, no dependencies.

/* ---------------------------------------------------------------------------
 * Toast notifications -- the single implementation for all three surfaces.
 *
 * app.js is loaded by layouts/app, layouts/admin and layouts/establishment
 * alike, so defining showToast here puts it on the public site, the partner
 * dashboard and the DOT Admin console at once. Do not copy this into a
 * surface-specific script; call window.showToast instead.
 *
 * Declared OUTSIDE the DOMContentLoaded handler below so the function object
 * exists the moment this file executes. Inline scripts in the page body run
 * during parsing, before any deferred script -- so a page that fires a toast
 * inline cannot call it directly. Those queue on window.__toastQueue and are
 * drained once the DOM is ready (see the drain at the end of this file).
 *
 * State lives entirely in this closure: a node and a setTimeout. Nothing is
 * persisted anywhere, which is precisely why a reload cannot resurrect one.
 * ------------------------------------------------------------------------- */
(function () {
    var DURATION_MS = 4000;
    var ICONS = {
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="4 12.5 9.5 18 20 6.5"/></svg>',
        error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5" stroke-width="2"/><line x1="12" y1="7" x2="12" y2="13"/><line x1="12" y1="16.8" x2="12" y2="16.9"/></svg>'
    };

    function stack() {
        var el = document.querySelector('.toast-stack');
        if (!el) {
            el = document.createElement('div');
            el.className = 'toast-stack';
            /*
             * "polite", not "assertive": these confirm something the user just
             * did. Assertive interrupts whatever a screen reader is currently
             * saying, which for a success message is rude rather than helpful.
             */
            el.setAttribute('aria-live', 'polite');
            el.setAttribute('aria-atomic', 'false');
            document.body.appendChild(el);
        }
        return el;
    }

    window.showToast = function (type, title, subtitle) {
        if (!title) return null;
        var kind = type === 'error' ? 'error' : 'success';

        var toast = document.createElement('div');
        toast.className = 'toast toast--' + kind;
        // role=status pairs with the container's aria-live so the whole card is
        // announced as one unit rather than word by word as it is assembled.
        toast.setAttribute('role', 'status');

        var badge = document.createElement('div');
        badge.className = 'toast__badge';
        badge.innerHTML = ICONS[kind];

        var body = document.createElement('div');
        body.className = 'toast__body';

        var heading = document.createElement('div');
        heading.className = 'toast__title';
        // textContent, never innerHTML: these strings carry listing and
        // business names that came from user input.
        heading.textContent = title;
        body.appendChild(heading);

        if (subtitle) {
            var sub = document.createElement('div');
            sub.className = 'toast__sub';
            sub.textContent = subtitle;
            body.appendChild(sub);
        }

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast__close';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><line x1="5" y1="5" x2="19" y2="19"/><line x1="19" y1="5" x2="5" y2="19"/></svg>';

        var progress = document.createElement('div');
        progress.className = 'toast__progress';
        progress.style.animationDuration = DURATION_MS + 'ms';

        toast.appendChild(badge);
        toast.appendChild(body);
        toast.appendChild(close);
        toast.appendChild(progress);
        stack().appendChild(toast);

        var timer = setTimeout(dismiss, DURATION_MS);
        var gone = false;

        function dismiss() {
            if (gone) return;
            gone = true;
            // Clearing matters on the click path: without it the timeout still
            // fires later and would remove whichever toast had since taken
            // this one's place in the stack.
            clearTimeout(timer);
            toast.classList.add('is-leaving');
            var drop = function () { if (toast.parentNode) toast.parentNode.removeChild(toast); };
            toast.addEventListener('animationend', drop, { once: true });
            // animationend never fires under prefers-reduced-motion, where the
            // animation is set to none, so the node would linger forever.
            setTimeout(drop, 400);
        }

        close.addEventListener('click', dismiss);
        return toast;
    };

    // Anything queued by an inline script before this file ran.
    window.__toastQueue = window.__toastQueue || [];
    document.addEventListener('DOMContentLoaded', function () {
        var queued = window.__toastQueue.splice(0);
        queued.forEach(function (args) { window.showToast.apply(null, args); });
        // Later pushes go straight through rather than sitting in the array.
        window.__toastQueue.push = function () {
            for (var i = 0; i < arguments.length; i++) window.showToast.apply(null, arguments[i]);
            return 0;
        };
    });
})();

document.addEventListener('DOMContentLoaded', function () {
    // Sticky header: add shadow + compact slightly once the page scrolls.
    //
    // Two separate thresholds, not one. A single boundary at scrollY > 8 could
    // flip the class on and off repeatedly: .scrolled also shrinks the bar from
    // 68px to 58px, and any layout shift that follows can push the scroll
    // position back across a single threshold, which toggles again. Requiring
    // 64px to switch on and 24px to switch off leaves a 40px dead zone that no
    // shift of that size can cross, so the state settles instead of shaking.
    //
    // Reads are also deferred to rAF: scroll fires far more often than the page
    // paints, and measuring scrollY inside the handler forced a layout on every
    // one of those events.
    var header = document.querySelector('.site-header');
    if (header) {
        var ON_AT = 64;
        var OFF_AT = 24;
        var ticking = false;

        var applyScrolled = function () {
            var y = window.scrollY;
            var isOn = header.classList.contains('scrolled');

            if (!isOn && y > ON_AT) header.classList.add('scrolled');
            else if (isOn && y < OFF_AT) header.classList.remove('scrolled');

            ticking = false;
        };

        applyScrolled();

        window.addEventListener('scroll', function () {
            if (ticking) return;
            ticking = true;
            window.requestAnimationFrame(applyScrolled);
        }, { passive: true });
    }

    // Card carousels: sync dot indicators to horizontal scroll position.
    document.querySelectorAll('[data-carousel]').forEach(function (root) {
        var track = root.querySelector('.carousel-track');
        var dots = root.querySelectorAll('.carousel-dots .dot');
        if (!track || dots.length < 2) return;

        track.addEventListener('scroll', function () {
            var index = Math.round(track.scrollLeft / track.clientWidth);
            index = Math.max(0, Math.min(dots.length - 1, index));
            dots.forEach(function (dot, i) { dot.classList.toggle('active', i === index); });
        }, { passive: true });
    });

    // Auto-advancing postcard slider (homepage "Popular Right Now"). Kept
    // separate from [data-carousel] above -- those are per-listing photo
    // galleries and should never autoplay while someone's scanning a grid
    // of many cards at once.
    document.querySelectorAll('[data-autoslide]').forEach(function (root) {
        var track = root.querySelector('.postcard-track');
        var cards = track ? Array.prototype.slice.call(track.querySelectorAll('.postcard-card')) : [];
        var dots = root.querySelectorAll('.postcard-dots .dot');
        if (!track || cards.length < 2) return;

        var index = 0;
        var timer = null;
        var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function setActiveDot(i) {
            dots.forEach(function (dot, di) { dot.classList.toggle('active', di === i); });
        }

        function goTo(i) {
            index = (i + cards.length) % cards.length;
            // Each card is exactly 100% of the track's width (see .postcard-track
            // .postcard-card), so index * clientWidth is always its scroll target.
            // offsetLeft would be wrong here -- it's relative to the nearest
            // positioned ancestor (.postcard-slider), not the scrolling track.
            track.scrollTo({ left: index * track.clientWidth, behavior: 'smooth' });
            setActiveDot(index);
        }

        function stop() {
            if (timer) { clearInterval(timer); timer = null; }
        }

        function start() {
            if (prefersReducedMotion) return;
            stop();
            timer = setInterval(function () { goTo(index + 1); }, 3500);
        }

        dots.forEach(function (dot, i) {
            dot.addEventListener('click', function () {
                goTo(i);
                start();
            });
        });

        var prevBtn = root.querySelector('[data-prev]');
        var nextBtn = root.querySelector('[data-next]');
        if (prevBtn) prevBtn.addEventListener('click', function () { goTo(index - 1); start(); });
        if (nextBtn) nextBtn.addEventListener('click', function () { goTo(index + 1); start(); });

        // Manual swipe/scroll should also move the dots and reset the timer,
        // so autoplay picks back up from wherever the visitor left it.
        var scrollTimeout = null;
        track.addEventListener('scroll', function () {
            var i = Math.max(0, Math.min(cards.length - 1, Math.round(track.scrollLeft / track.clientWidth)));
            index = i;
            setActiveDot(i);
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(start, 1500);
        }, { passive: true });

        root.addEventListener('mouseenter', stop);
        root.addEventListener('mouseleave', start);
        root.addEventListener('touchstart', stop, { passive: true });

        start();
    });

    // Detail-page gallery + lightbox.
    document.querySelectorAll('[data-gallery]').forEach(function (root) {
        var photos;
        try {
            photos = JSON.parse(root.dataset.photos || '[]');
        } catch (e) {
            photos = [];
        }
        if (!photos.length) return;

        var lightbox = root.querySelector('[data-lightbox-el]');
        var imgEl = lightbox.querySelector('.lb-img');
        var metaEl = lightbox.querySelector('.lb-meta');
        var current = 0;

        function render() {
            imgEl.src = photos[current].url;
            var label = (current + 1) + ' / ' + photos.length;
            if (photos[current].category) label += ' · ' + photos[current].category;
            metaEl.textContent = label;
        }

        function open(index) {
            current = index;
            render();
            lightbox.classList.add('open');
        }

        function close() {
            lightbox.classList.remove('open');
        }

        function next() {
            current = (current + 1) % photos.length;
            render();
        }

        function prev() {
            current = (current - 1 + photos.length) % photos.length;
            render();
        }

        root.querySelectorAll('[data-open]').forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                open(parseInt(trigger.dataset.open, 10));
            });
        });

        var closeBtn = lightbox.querySelector('[data-close]');
        var nextBtn = lightbox.querySelector('[data-next]');
        var prevBtn = lightbox.querySelector('[data-prev]');
        if (closeBtn) closeBtn.addEventListener('click', close);
        if (nextBtn) nextBtn.addEventListener('click', next);
        if (prevBtn) prevBtn.addEventListener('click', prev);

        lightbox.addEventListener('click', function (e) {
            if (e.target === lightbox) close();
        });

        document.addEventListener('keydown', function (e) {
            if (!lightbox.classList.contains('open')) return;
            if (e.key === 'Escape') close();
            if (e.key === 'ArrowRight') next();
            if (e.key === 'ArrowLeft') prev();
        });

        // Touch swipe: drag left/right on the photo to move between images.
        var touchStartX = null;
        lightbox.addEventListener('touchstart', function (e) {
            touchStartX = e.touches[0].clientX;
        }, { passive: true });
        lightbox.addEventListener('touchend', function (e) {
            if (touchStartX === null) return;
            var deltaX = e.changedTouches[0].clientX - touchStartX;
            if (Math.abs(deltaX) > 40) {
                deltaX < 0 ? next() : prev();
            }
            touchStartX = null;
        });
    });

    // Bulk row selection for admin tables. Each [data-bulk] wraps one table
    // plus its .bulk-bar; the bar stays hidden until something is checked, so
    // it costs no vertical space at rest.
    document.querySelectorAll('[data-bulk]').forEach(function (root) {
        var bar = root.querySelector('.bulk-bar');
        var count = root.querySelector('[data-bulk-count]');
        var toggleAll = root.querySelector('[data-bulk-all]');
        var boxes = Array.from(root.querySelectorAll('[data-bulk-row]'));
        if (!bar || !boxes.length) return;

        // Each bulk form posts its own ids[]; mirror the checked rows into
        // every form so whichever button is pressed submits the same set.
        var forms = Array.from(bar.querySelectorAll('form'));

        function sync() {
            var checked = boxes.filter(function (b) { return b.checked; });

            bar.classList.toggle('is-active', checked.length > 0);
            if (count) count.textContent = checked.length + ' selected';

            forms.forEach(function (form) {
                form.querySelectorAll('input[data-bulk-id]').forEach(function (n) { n.remove(); });
                checked.forEach(function (b) {
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'ids[]';
                    hidden.value = b.value;
                    hidden.setAttribute('data-bulk-id', '');
                    form.appendChild(hidden);
                });
            });

            if (toggleAll) {
                toggleAll.checked = checked.length === boxes.length && boxes.length > 0;
                toggleAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
            }
        }

        boxes.forEach(function (b) { b.addEventListener('change', sync); });

        if (toggleAll) {
            toggleAll.addEventListener('change', function () {
                boxes.forEach(function (b) { b.checked = toggleAll.checked; });
                sync();
            });
        }

        sync();
    });

    // Custom file inputs (.file-field): the native input is visually hidden,
    // so the chosen-file feedback the browser would normally render has to be
    // written back into the status span ourselves. Without JS the label still
    // opens the picker -- only this text stays on its server-rendered default.
    document.querySelectorAll('.file-field input[type="file"]').forEach(function (input) {
        var status = input.closest('.file-field').querySelector('[data-file-status]');
        if (!status) return;

        var idle = status.textContent;

        input.addEventListener('change', function () {
            var count = input.files.length;

            if (count === 0) {
                status.textContent = idle;
                status.classList.remove('has-files');
                return;
            }

            status.textContent = count === 1
                ? input.files[0].name
                : count + ' files selected';
            status.classList.add('has-files');
        });
    });

    // Save/unsave heart: toggled over fetch so hearting a place doesn't
    // reload the page. Delegated on document rather than bound per-form,
    // since a listing grid renders one of these per card and the same
    // handler should cover every one without a separate listener each.
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('.save-form');
        if (!form) return;

        var tokenMeta = document.querySelector('meta[name="csrf-token"]');
        var button = form.querySelector('button');
        if (!tokenMeta || !button) return; // let the plain form submit through

        e.preventDefault();

        fetch(form.getAttribute('action'), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': tokenMeta.content, 'Accept': 'application/json' },
        })
            .then(function (response) {
                if (!response.ok) throw new Error('save-toggle request failed');
                return response.json();
            })
            .then(function (data) {
                applySavedState(form, button, data.saved);
                // Without this the JS path succeeded silently while the no-JS
                // path got a flash message -- the same action confirming itself
                // only when JavaScript was off. The server sends the identical
                // two strings either way.
                if (data.title) window.showToast('success', data.title, data.detail);
            })
            .catch(function () {
                // Network hiccup or server error: fall back to a normal
                // full-page submit rather than leaving the heart stuck.
                form.submit();
            });
    });

    function applySavedState(form, button, saved) {
        button.classList.toggle('is-saved', saved);
        button.setAttribute('aria-pressed', saved ? 'true' : 'false');

        var svg = button.querySelector('svg');
        if (svg) svg.setAttribute('fill', saved ? 'currentColor' : 'none');

        var isIconVariant = form.classList.contains('save-form--icon');
        if (isIconVariant) {
            button.title = saved ? 'Remove from saved' : 'Save this place';
        } else {
            Array.prototype.forEach.call(button.childNodes, function (node) {
                if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
                    node.textContent = saved ? ' Saved' : ' Save this place';
                }
            });
        }

        var srOnly = button.querySelector('.sr-only');
        if (srOnly) {
            var name = srOnly.textContent.replace(/^(Remove|Save)\s+/, '');
            srOnly.textContent = (saved ? 'Remove ' : 'Save ') + name;
        }

        // Bounce the heart itself on every toggle, and on save (icon variant
        // only) send a few small hearts drifting outward -- the "something
        // just happened here" cue a page reload used to provide for free.
        form.classList.remove('save-form--pop');
        void form.offsetWidth; // restart the animation if clicked again quickly
        form.classList.add('save-form--pop');

        if (saved && isIconVariant && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            spawnHeartParticles(form);
        }
    }

    function spawnHeartParticles(form) {
        var offsets = [
            [-14, -22], [0, -28], [14, -22], [-8, -16],
        ];
        offsets.forEach(function (offset) {
            var particle = document.createElement('span');
            particle.className = 'save-heart-particle';
            particle.style.setProperty('--particle-end', 'translate(' + offset[0] + 'px, ' + offset[1] + 'px) scale(1)');
            particle.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 21s-7.5-4.6-10-9.3C.5 8 2 4 6 4c2 0 3.5 1.2 4.5 2.7C11.5 5.2 13 4 15 4c4 0 5.5 4 4 7.7C19.5 16.4 12 21 12 21z"/></svg>';
            form.appendChild(particle);
            particle.addEventListener('animationend', function () { particle.remove(); });
        });
    }

    // Trip planner pill-toggle chips (.chip-checkbox-grid): keeps a
    // .is-checked class on the label in sync with its checkbox, both on load
    // (for options pre-selected from a previous submission) and on every
    // change. The CSS also declares a :has(input:checked) rule for browsers
    // where that keeps working, but this class is what's actually relied on
    // -- :has() was found not to reliably repaint after a programmatic
    // checked-state change in testing, and a chip that silently stops
    // reflecting its own selection state is worse than not having the
    // effect at all.
    document.querySelectorAll('.chip-checkbox-grid .field-check').forEach(function (label) {
        var input = label.querySelector('input');
        if (!input) return;

        var sync = function () { label.classList.toggle('is-checked', input.checked); };
        sync();
        input.addEventListener('change', sync);
    });

    /*
     * Password reveal.
     *
     * Progressive enhancement: the buttons are rendered hidden-capable but do
     * nothing until this runs, and the field stays a normal masked input if it
     * never does -- so a JS failure costs the convenience, not the form.
     */
    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        var input = document.getElementById(button.getAttribute('data-password-toggle'));
        if (!input) return;

        button.addEventListener('click', function () {
            var revealed = input.type === 'text';
            input.type = revealed ? 'password' : 'text';
            button.classList.toggle('is-revealed', !revealed);
            button.setAttribute('aria-pressed', String(!revealed));
            button.setAttribute('aria-label', revealed ? 'Show password' : 'Hide password');
            /*
             * Returning focus to the input would be the obvious move, but it
             * drops the caret to position 0 in several browsers -- mid-typing
             * that silently rewrites the password. Focus stays on the button.
             */
        });
    });

    /*
     * The hero footage logic deliberately does NOT live here.
     *
     * This file is loaded with `defer`, so anything in it waits for the whole
     * document to parse -- measured at ~1450ms on the landing page, against
     * only ~350ms to actually fetch the clip. Running the gate from here left
     * the painted hero on screen for nearly two seconds before the video
     * appeared, which read as the page changing its mind.
     *
     * It now runs inline, immediately beneath the <video> element in
     * welcome.blade.php, so it starts during parsing instead.
     */

    /*
     * Tag search: replaces a static wall of checkboxes with a text field that
     * filters a list as the user types and adds a removable chip on click.
     *
     * State per instance lives entirely in the DOM: the chip container holds
     * one hidden <input> per selection (so the surrounding <form> submits
     * them exactly as it always did -- no controller change needed) and the
     * dropdown is rebuilt from the items/selected JSON embedded by the
     * component on every keystroke. Rebuilding rather than diffing is fine
     * at this scale (the largest list here is ~220 rows) and keeps the whole
     * thing easy to reason about instead of tracking indices by hand.
     */
    document.querySelectorAll('[data-tag-search]').forEach(function (root) {
        var box = root.querySelector('[data-tag-search-box]');
        var chipsEl = root.querySelector('[data-tag-search-chips]');
        var input = root.querySelector('[data-tag-search-input]');
        var dropdown = root.querySelector('[data-tag-search-dropdown]');
        var countEl = root.querySelector('[data-tag-search-count]');
        var hiddenHost = root.querySelector('[data-tag-search-hidden-inputs]');
        var fieldName = root.querySelector('[data-tag-search-name]').value;
        var items = JSON.parse(root.querySelector('[data-tag-search-items]').textContent || '[]');
        var selectedValues = JSON.parse(root.querySelector('[data-tag-search-selected]').textContent || '[]');

        var byValue = {};
        items.forEach(function (item) { byValue[item.value] = item; });

        var MAX_RESULTS = 8;
        var activeIndex = -1;

        function isSelected(value) { return selectedValues.indexOf(value) !== -1; }

        function updateCount() {
            countEl.textContent = selectedValues.length + (selectedValues.length === 1 ? ' selected' : ' selected');
        }

        function addChip(value) {
            var item = byValue[value];
            if (!item || isSelected(value)) return;

            selectedValues.push(value);

            var chip = document.createElement('span');
            chip.className = 'tag-search__chip';
            chip.setAttribute('data-value', value);

            var label = document.createElement('span');
            label.className = 'tag-search__chip-label';
            label.textContent = item.label;

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'tag-search__chip-remove';
            remove.setAttribute('aria-label', 'Remove ' + item.label);
            remove.textContent = '×';
            remove.addEventListener('click', function () { removeChip(value, chip); });

            chip.appendChild(label);
            chip.appendChild(remove);
            chipsEl.appendChild(chip);

            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = fieldName;
            hidden.value = value;
            hidden.setAttribute('data-value', value);
            hiddenHost.appendChild(hidden);

            updateCount();
        }

        function removeChip(value, chipEl) {
            selectedValues = selectedValues.filter(function (v) { return v !== value; });
            chipEl.remove();
            var hidden = hiddenHost.querySelector('[data-value="' + value.replace(/"/g, '\\"') + '"]');
            if (hidden) hidden.remove();
            updateCount();
        }

        function closeDropdown() {
            dropdown.hidden = true;
            dropdown.innerHTML = '';
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        }

        function highlight(index) {
            var options = dropdown.querySelectorAll('[data-option]');
            options.forEach(function (opt, i) { opt.classList.toggle('is-active', i === index); });
            activeIndex = index;
        }

        function renderDropdown() {
            var query = input.value.trim().toLowerCase();
            var matches = items.filter(function (item) {
                return !isSelected(item.value) && (query === '' || item.label.toLowerCase().indexOf(query) !== -1);
            }).slice(0, MAX_RESULTS);

            if (matches.length === 0) {
                closeDropdown();
                return;
            }

            dropdown.innerHTML = '';
            matches.forEach(function (item) {
                var li = document.createElement('li');
                li.setAttribute('data-option', '');
                li.setAttribute('data-value', item.value);
                li.setAttribute('role', 'option');
                li.textContent = item.label;
                li.addEventListener('mousedown', function (e) {
                    // mousedown (not click) fires before the input's blur, so
                    // the dropdown is still open when the value is read.
                    e.preventDefault();
                    addChip(item.value);
                    input.value = '';
                    renderDropdown();
                    input.focus();
                });
                dropdown.appendChild(li);
            });

            dropdown.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            highlight(-1);
        }

        // Existing selections (old() repopulation on a validation error)
        // render their chips up front, in the order they were submitted.
        selectedValues.slice().forEach(function (value) {
            selectedValues = selectedValues.filter(function (v) { return v !== value; });
            addChip(value);
        });

        input.addEventListener('input', renderDropdown);
        input.addEventListener('focus', renderDropdown);

        input.addEventListener('keydown', function (e) {
            var options = dropdown.querySelectorAll('[data-option]');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (dropdown.hidden) { renderDropdown(); return; }
                highlight(Math.min(activeIndex + 1, options.length - 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlight(Math.max(activeIndex - 1, 0));
            } else if (e.key === 'Enter') {
                if (!dropdown.hidden && activeIndex >= 0 && options[activeIndex]) {
                    e.preventDefault();
                    var value = options[activeIndex].getAttribute('data-value');
                    addChip(value);
                    input.value = '';
                    renderDropdown();
                }
            } else if (e.key === 'Escape') {
                closeDropdown();
            } else if (e.key === 'Backspace' && input.value === '') {
                // Backspace on an empty field removes the most recently added
                // chip, matching the pattern most chip inputs already use.
                var last = chipsEl.lastElementChild;
                if (last) removeChip(last.getAttribute('data-value'), last);
            }
        });

        document.addEventListener('click', function (e) {
            if (!box.contains(e.target) && !dropdown.contains(e.target)) {
                closeDropdown();
            }
        });

        updateCount();
    });
});

/*
 * Travel preference survey: "Where are you visiting from?" is required
 * unless the traveller already said they're local (Regular / Local) --
 * asking a resident where they're "visiting from" doesn't make sense. The
 * server enforces this either way (required_unless in TripPlannerController);
 * this only keeps the browser's own validation UI and hint text honest about
 * the same rule as the visitor-type dropdown changes, instead of showing a
 * red "required" outline on a field the server isn't actually going to
 * require for this traveller.
 */
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.querySelector('[data-local-toggle]');
    var field = document.querySelector('[data-origin-field]');
    if (!toggle || !field) return;

    var input = field.querySelector('input');
    var hint = field.querySelector('[data-origin-hint]');
    var label = field.querySelector('[data-origin-label]');
    var defaultHint = hint ? hint.textContent : '';

    function sync() {
        var isLocal = toggle.value === 'Regular / Local';
        input.required = !isLocal;
        if (label) label.textContent = isLocal ? 'Where are you visiting from? (optional)' : 'Where are you visiting from?';
        if (hint) hint.textContent = isLocal ? "Optional since you're local." : defaultHint;
    }

    toggle.addEventListener('change', sync);
    sync();
});
