// ExploreDVO — card photo carousels + detail-page gallery lightbox.
// Vanilla JS, no dependencies.

document.addEventListener('DOMContentLoaded', function () {
    // Sticky header: add shadow + compact slightly once the page scrolls.
    var header = document.querySelector('.site-header');
    if (header) {
        var applyScrolled = function () {
            header.classList.toggle('scrolled', window.scrollY > 8);
        };
        applyScrolled();
        window.addEventListener('scroll', applyScrolled, { passive: true });
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
});
