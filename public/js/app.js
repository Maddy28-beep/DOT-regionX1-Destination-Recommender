// ExploreDVO — card photo carousels + detail-page gallery lightbox.
// Vanilla JS, no dependencies.

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
            .then(function (data) { applySavedState(form, button, data.saved); })
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
});
