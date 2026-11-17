(function () {
    'use strict';
    document.querySelectorAll('[data-davo-guide-video], [data-davo-building-video], [data-davo-survey-video], [data-davo-resume-video]').forEach(function (video) {
    var building = video.hasAttribute('data-davo-building-video');
    var buildingActive = false;
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    var inView = !window.IntersectionObserver;
    var replayPending = true;
    video.muted = true;
    video.loop = false;
    function play() {
        var attempt = video.play();
        if (attempt && attempt.catch) attempt.catch(function () { /* Keep the still poster when autoplay is unavailable. */ });
    }
    function sync() {
        if (reducedMotion.matches || document.hidden || (building ? !buildingActive : !inView)) video.pause();
        else if (replayPending || !video.ended) {
            if (replayPending) {
                video.currentTime = 0;
                replayPending = false;
            }
            play();
        }
    }
    var replayEvent = building ? 'davo:itinerary-building' : video.hasAttribute('data-davo-survey-video') ? 'davo:survey-step-change' : video.hasAttribute('data-davo-resume-video') ? null : 'davo:planner-step-change';
    if (replayEvent) {
    document.addEventListener(replayEvent, function () {
        video.pause();
        replayPending = true;
        if (building) buildingActive = true;
        sync();
    });
    }
    if (building) document.addEventListener('davo:itinerary-building-stop', function () {
        buildingActive = false;
        sync();
    });
    // Pointing at Davo (or tapping him on a touch screen) makes him move again, unless he is already moving. The loading clip
    // is left alone: it belongs to the wait for the itinerary.
    if (!building) {
        var again = function (event) {
            if (reducedMotion.matches || document.hidden) return;
            if (event && event.type === 'pointerenter' && event.pointerType && event.pointerType !== 'mouse') return;
            if (!video.paused && !video.ended) return;
            video.currentTime = 0;
            replayPending = false;
            play();
        };
        video.addEventListener('pointerenter', again);
        video.addEventListener('click', again);
    }
    document.addEventListener('visibilitychange', sync);
    if (reducedMotion.addEventListener) reducedMotion.addEventListener('change', sync);
    else reducedMotion.addListener(sync);
    if (!building && window.IntersectionObserver) {
        var observer = new IntersectionObserver(function (entries) {
            inView = entries[0].isIntersecting;
            sync();
        });
        observer.observe(video);
    }
    sync();
    });
})();
