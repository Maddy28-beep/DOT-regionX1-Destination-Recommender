const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/davo-planner-video.js'), 'utf8');

function fixture(reduced = false, building = false, survey = false) {
    const events = {};
    const videoEvents = {};
    let intersect;
    const video = { ended: false, currentTime: 0, plays: 0, paused: true, hasAttribute(name) { return name === 'data-davo-building-video' ? building : name === 'data-davo-survey-video' && survey; },
        addEventListener(name, callback) { videoEvents[name] = callback; },
        pause() { this.paused = true; },
        play() { this.plays++; this.paused = false; this.ended = false; return Promise.resolve(); } };
    const motion = { matches: reduced, addEventListener(name, callback) { this.change = callback; } };
    const document = { hidden: false, querySelectorAll() { return [video]; },
        addEventListener(name, callback) { events[name] = callback; } };
    function IntersectionObserver(callback) { intersect = callback; this.observe = () => {}; }
    vm.runInNewContext(source, { document, window: { matchMedia: () => motion, IntersectionObserver }, IntersectionObserver });
    return { video, document, motion,
        point: (pointerType = 'mouse') => videoEvents.pointerenter({ type: 'pointerenter', pointerType }),
        tap: () => videoEvents.click({ type: 'click' }), hasPointer: () => Boolean(videoEvents.pointerenter),
        step: () => events[building ? 'davo:itinerary-building' : survey ? 'davo:survey-step-change' : 'davo:planner-step-change'](),
        stop: () => events['davo:itinerary-building-stop'](),
        visible: value => { if (intersect) intersect([{ isIntersecting: value }]); }, visibility: () => events.visibilitychange() };
}

test('plays once on opening and replays on forward or backward step changes', () => {
    const page = fixture();
    assert.equal(page.video.plays, 0);
    page.visible(true);
    assert.equal(page.video.plays, 1);
    assert.equal(page.video.loop, false);
    page.video.currentTime = 5; page.video.ended = true; page.video.paused = true;
    page.visible(false); page.visible(true); page.visibility();
    assert.equal(page.video.plays, 1, 'scrolling or returning to the tab does not replay a finished clip');
    page.step();
    assert.equal(page.video.currentTime, 0);
    assert.equal(page.video.plays, 2);
    page.video.currentTime = 2;
    page.step();
    assert.equal(page.video.currentTime, 0);
    assert.equal(page.video.plays, 3);
});

test('pauses offscreen and waits to replay a changed step until visible', () => {
    const page = fixture(); page.visible(true);
    page.video.currentTime = 2;
    page.visible(false); assert.equal(page.video.paused, true);
    page.visible(true); assert.equal(page.video.currentTime, 2);
    page.visible(false); page.step();
    assert.equal(page.video.paused, true);
    page.visible(true); assert.equal(page.video.currentTime, 0);
});

test('respects reduced motion on opening and step changes', () => {
    const page = fixture(true); page.visible(true); page.step();
    assert.equal(page.video.plays, 0);
    page.motion.matches = false; page.motion.change();
    assert.equal(page.video.plays, 1);
});

test('building video starts immediately with loading, plays once, and restarts on retry', () => {
    const page = fixture(false, true);
    assert.equal(page.video.plays, 0);
    page.visible(true); assert.equal(page.video.plays, 0);
    page.step(); assert.equal(page.video.plays, 1, 'starts without waiting for an intersection callback');
    page.video.currentTime = 10; page.video.ended = true;
    page.visibility(); assert.equal(page.video.plays, 1);
    page.stop(); assert.equal(page.video.paused, true);
    page.visibility(); assert.equal(page.video.plays, 1);
    page.step();
    assert.equal(page.video.currentTime, 0);
    assert.equal(page.video.plays, 2);
});

test('building video respects reduced motion even when loading starts', () => {
    const page = fixture(true, true); page.step();
    assert.equal(page.video.plays, 0);
});

test('survey guide plays once on opening and restarts for each changed survey step', () => {
    const page = fixture(false, false, true);
    page.visible(true); assert.equal(page.video.plays, 1);
    page.video.currentTime = 5; page.video.ended = true;
    page.visibility(); assert.equal(page.video.plays, 1);
    page.step(); assert.equal(page.video.currentTime, 0);
    assert.equal(page.video.plays, 2);
});

test('pointing at a finished Davo replays him, and a tap does the same on a touch screen', () => {
    const page = fixture(); page.visible(true);
    page.video.currentTime = 7; page.video.ended = true; page.video.paused = true;
    page.point();
    assert.equal(page.video.currentTime, 0);
    assert.equal(page.video.plays, 2);
    page.video.currentTime = 7; page.video.ended = true; page.video.paused = true;
    page.point('touch');
    assert.equal(page.video.plays, 2, 'the touch pointer entering is not a hover');
    page.tap();
    assert.equal(page.video.currentTime, 0);
    assert.equal(page.video.plays, 3);
});

test('pointing at Davo while he is moving does not restart him', () => {
    const page = fixture(); page.visible(true);
    page.video.currentTime = 3; page.video.paused = false; page.video.ended = false;
    page.point();
    assert.equal(page.video.currentTime, 3);
    assert.equal(page.video.plays, 1);
});

test('hover replay respects reduced motion, and the loading clip does not take it', () => {
    const reduced = fixture(true); reduced.visible(true);
    reduced.video.ended = true; reduced.video.paused = true;
    reduced.point(); assert.equal(reduced.video.plays, 0);
    const loading = fixture(false, true);
    assert.equal(loading.hasPointer(), false);
});

test('survey guide replays on hover too', () => {
    const page = fixture(false, false, true); page.visible(true);
    page.video.currentTime = 5; page.video.ended = true; page.video.paused = true;
    page.point();
    assert.equal(page.video.currentTime, 0);
    assert.equal(page.video.plays, 2);
});

