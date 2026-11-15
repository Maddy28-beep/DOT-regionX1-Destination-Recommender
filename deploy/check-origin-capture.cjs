const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const fields = {
    _token: {value: 'test-csrf'},
    visitor_origin_country: {name: 'visitor_origin_country', value: 'JP', checkValidity: () => true},
    visitor_origin_province: {name: 'visitor_origin_province', value: '', checkValidity: () => true},
    visitor_origin_city: {name: 'visitor_origin_city', value: 'Tokyo', checkValidity: () => true},
};
const form = {elements: {namedItem: key => fields[key]}};
const button = {dataset: {confirmUrl: '/plan/origin-confirmation'}, closest: () => form, addEventListener: () => {}};
const status = {};
let calls = 0;
let sent;
let success = true;
const window = {};
vm.runInNewContext(fs.readFileSync(process.argv[2], 'utf8'), {
    document: {querySelector: key => key === '[data-confirm-origin]' ? button : status},
    window, FormData,
    fetch: async (url, options) => { calls++; sent = options.body; return {ok: success,
        json: async () => success ? {message: 'Origin confirmed.'} : {errors: {visitor_origin_country: ['Choose a country.']}}}; }
});
(async () => {
    const first = window.confirmVisitorOrigin();
    const duplicate = window.confirmVisitorOrigin();
    assert.equal(first, duplicate);
    assert.equal(await first, true);
    assert.equal(calls, 1);
    assert.equal(sent.get('visitor_origin_city'), 'Tokyo');
    assert.deepEqual([...sent.keys()].sort(), ['_token', 'visitor_origin_city', 'visitor_origin_country', 'visitor_origin_province']);
    assert.equal(button.disabled, false);
    success = false;
    assert.equal(await window.confirmVisitorOrigin(), false);
    assert.equal(status.textContent, 'Choose a country.');
    console.log('8 origin-confirmation browser-script checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
