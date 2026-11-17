const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/catalog-nearby.js'), 'utf8');

function fixture(supported = true) {
    function element(value = '') {
        const listeners = {};
        return { value, disabled: false, hidden: false, textContent: '',
            addEventListener(name, fn) { listeners[name] = fn; },
            dispatchEvent(event) { listeners[event.type]?.(event); },
            click() { listeners.click?.(); }, setAttribute() {}, removeAttribute() {} };
    }
    const origin = element(), lat = element(), lng = element(), locate = element(), clear = element(), status = element();
    let option, success, failure, updates = 0;
    origin.querySelector = () => option;
    origin.appendChild = value => { option = value; };
    const radios = [element(), element(), element(), element()];
    const fields = { '[data-nearby-origin]': origin, '[data-nearby-lat]': lat, '[data-nearby-lng]': lng,
        '[data-nearby-locate]': locate, '[data-nearby-clear]': clear, '[data-nearby-status]': status };
    const box = { querySelector: key => fields[key], querySelectorAll: () => radios,
        closest: () => ({ dispatchEvent() { updates++; } }) };
    const navigator = supported ? { geolocation: { getCurrentPosition(ok, fail) { success = ok; failure = fail; } } } : {};
    vm.runInNewContext(source, { document: { querySelector: () => box, createElement: () => element() }, navigator,
        Event: class { constructor(type) { this.type = type; } } });
    return { origin, lat, lng, locate, clear, status, radios,
        choose(value) { origin.value = value; origin.dispatchEvent({ type: 'change' }); },
        success(latitude = 7.06, longitude = 125.6) { success({ coords: { latitude, longitude } }); },
        fail(code = 1) { failure({ code }); }, updates: () => updates };
}

test('nearby stays inactive until an anchor is selected, and clearing removes coordinate inputs', () => {
    const p = fixture(); assert.ok(p.radios.every(r => r.disabled));
    p.choose('davao'); assert.ok(p.radios.every(r => !r.disabled));
    assert.equal(p.lat.disabled, true);
    p.clear.click(); assert.equal(p.origin.value, ''); assert.ok(p.radios.every(r => r.disabled));
});
test('successful location enables one nearby filter and submits coordinates only for current location', () => {
    const p = fixture(); p.locate.click(); assert.equal(p.locate.disabled, true);
    p.success(); assert.equal(p.origin.value, 'current'); assert.equal(p.lat.value, 7.06);
    assert.equal(p.lng.disabled, false); assert.equal(p.locate.disabled, false);
    p.choose('airport'); assert.equal(p.lat.value, ''); assert.equal(p.lng.disabled, true);
});
test('denied or unavailable location keeps manual choices usable', () => {
    const p = fixture(); p.choose('davao'); p.locate.click(); p.fail();
    assert.equal(p.origin.value, 'davao'); assert.match(p.status.textContent, /permission was not granted/);
    assert.equal(p.locate.disabled, false);
    const unsupported = fixture(false); unsupported.locate.click();
    assert.match(unsupported.status.textContent, /Choose a starting point/);
});
test('a late location result cannot overwrite a newer manual selection', () => {
    const p = fixture(); p.locate.click(); p.choose('airport'); p.success();
    assert.equal(p.origin.value, 'airport'); assert.equal(p.lat.value, '');
});
test('invalid location results do not activate nearby filtering', () => {
    const p = fixture(); p.locate.click(); p.success(NaN, 125);
    assert.equal(p.origin.value, ''); assert.equal(p.locate.disabled, false);
    assert.match(p.status.textContent, /could not find/);
});
