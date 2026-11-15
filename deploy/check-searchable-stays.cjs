// node deploy/check-searchable-stays.cjs <path-to-jsdom> [path-to-survey-stays.js]
// jsdom is a temporary QA dependency, not a production dependency.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require(process.argv[2] || 'jsdom');
const row = index => `<fieldset data-stay-row><legend>Stay <span data-stay-number>${index + 1}</span></legend>
<label for="stay-place-${index}">Place you stayed</label>
<select id="stay-place-${index}" name="stays[${index}][place]" data-stay-choice data-enhanced="stay-search">
<option value="" disabled selected>Select where you stayed</option>
<option value="other">Other / not in the list</option><option value="friends_family">Stayed with friends or family</option><option value="day_trip">No overnight stay</option>
<option value="accommodation:1">Acacia Hotel — Lanang, Davao City</option>
<option value="accommodation:2">Tower Inn — E. Quirino Avenue, Davao City</option>
<option value="accommodation:3">Twin Mountain Resort — Bansalan, Davao del Sur</option></select>
<div data-stay-manual><input name="stays[${index}][name]" data-stay-name><input name="stays[${index}][locality]" data-stay-locality></div>
<button type="button" data-remove-stay hidden>Remove stay</button></fieldset>`;
const dom = new JSDOM(`<form><section data-survey-stays><div data-stay-rows>${row(0)}</div><button type="button" data-add-stay hidden>Add another</button><template data-stay-template>${row('__INDEX__')}</template></section></form>`, {runScripts: 'outside-only'});
const win = dom.window;
win.HTMLElement.prototype.scrollIntoView = function () {};
const sharedDropdowns = fs.readFileSync('public/js/app.js', 'utf8');
win.eval(sharedDropdowns.slice(sharedDropdowns.indexOf('// Shared form dropdowns.')));
win.eval(fs.readFileSync(process.argv[3] || 'public/js/survey-stays.js', 'utf8'));
win.document.dispatchEvent(new win.Event('DOMContentLoaded'));
const doc = win.document;
const input = doc.querySelector('[data-stay-search]');
const select = doc.querySelector('select');
const list = doc.querySelector('[role="listbox"]');
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };
const type = value => { input.value = value; input.dispatchEvent(new win.Event('input', {bubbles: true})); };
const key = value => input.dispatchEvent(new win.KeyboardEvent('keydown', {key: value, bubbles: true}));
const choose = text => Array.from(list.children).find(item => item.textContent.includes(text)).click();
check(!input.hidden && select.hidden, 'Visible typing field replaces native picker only after JavaScript initializes');
check(!doc.querySelector('.site-dropdown-trigger'), 'Shared dropdown enhancement does not create a duplicate non-typing control');
check(doc.querySelector('label').htmlFor === input.id, 'Visible input retains its label');
type('aca');
check(input.value === 'aca', 'Typed characters stay visible');
check(list.textContent.includes('Acacia Hotel') && !list.textContent.includes('Tower Inn'), 'Names filter as you type');
check(list.textContent.includes('Other / not in the list'), 'Manual entry remains available during filtering');
check(!input.checkValidity(), 'Unselected search text cannot silently submit as a missing stay');
type('BANSALAN');
check(list.textContent.includes('Twin Mountain Resort') && !list.textContent.includes('Acacia Hotel'), 'Area filtering is case insensitive');
type('tower davao');
check(list.textContent.includes('Tower Inn') && !list.textContent.includes('Acacia Hotel'), 'Name and city words can be combined');
key('ArrowDown'); key('Enter');
check(select.value === 'accommodation:2' && input.value.includes('Tower Inn'), 'Keyboard selection stores the actual accommodation ID and displays its label');
check(input.checkValidity() && list.hidden, 'Selection clears validation and closes results');
type('new unlisted rental');
check(select.value === '' && input.value === 'new unlisted rental', 'Editing a selection clears the old ID while retaining visible text');
check(doc.querySelector('[role="status"]').textContent.includes('No accommodation matches'), 'No-match state is explained');
choose('Other /');
check(select.value === 'other' && !doc.querySelector('[data-stay-manual]').hidden, 'Other reveals manual stay fields');
check(doc.querySelector('[data-stay-name]').required && !doc.querySelector('[data-stay-name]').disabled, 'Manual accommodation details remain required when Other is selected');
type('Acacia'); choose('Acacia Hotel');
check(select.value === 'accommodation:1' && doc.querySelector('[data-stay-manual]').hidden, 'Directory selection closes manual details');
check(doc.querySelector('[data-stay-name]').disabled, 'Stale manual data are excluded after switching to directory selection');
type('');
check(select.value === '' && input.checkValidity(), 'Clearing the optional field leaves no invented answer');
input.focus(); key('Escape');
check(list.hidden && input.getAttribute('aria-expanded') === 'false', 'Escape closes results');
key('ArrowUp'); key('Enter');
check(select.value === 'accommodation:3', 'ArrowUp from a closed list chooses its final option');
doc.querySelector('[data-add-stay]').click();
const inputs = doc.querySelectorAll('[data-stay-search]');
check(inputs.length === 2 && inputs[0].id !== inputs[1].id, 'Additional stays receive independent search controls and IDs');
inputs[1].value = 'Tower'; inputs[1].dispatchEvent(new win.Event('input', {bubbles: true}));
check(inputs[0].value.includes('Twin Mountain') && inputs[1].value === 'Tower', 'Typing in another stay does not change the first selection');
doc.querySelectorAll('[data-remove-stay]')[1].click();
check(doc.querySelectorAll('[data-stay-search]').length === 1 && doc.activeElement === input, 'Removing a stay returns focus to the remaining visible field');
for (let i = 0; i < 5; i++) doc.querySelector('[data-add-stay]').click();
check(doc.querySelectorAll('[data-stay-row]').length === 5 && doc.querySelector('[data-add-stay]').hidden, 'Five-stay limit is preserved');
dom.window.close();
console.log(`${checks} searchable accommodation assertions passed.`);
