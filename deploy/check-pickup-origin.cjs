const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const listeners = {};
const locate = { disabled: false };
const pickup = { value: 'Chosen Davao hotel' };
const province = { value: 'Davao del Sur', hidden: false, closest: () => null, parentElement: null,
    addEventListener: () => {}, dispatchEvent: () => {} };
const city = { hidden: false, value: 'Tokyo' };
const country = { value: 'JP', form: { addEventListener: () => {} },
    addEventListener: (name, fn) => { listeners[name] = fn; } };
vm.runInNewContext(fs.readFileSync(process.argv[2], 'utf8'), {
    document: { getElementById: id => ({ visitor_origin_country: country, 'origin-btn': locate, origin_label: pickup })[id],
        querySelector: q => ({ '[data-origin-province]': province, '[data-origin-city]': city })[q] },
    window: { addEventListener: () => {} }, Event: class {}
});
for (const home of ['JP', 'PH', 'US']) {
    country.value = home;
    listeners.change();
    assert.equal(locate.disabled, false);
    assert.equal(pickup.value, 'Chosen Davao hotel');
    assert.equal(province.hidden, home !== 'PH');
}
console.log('9 origin/pick-up browser-script checks passed.');
