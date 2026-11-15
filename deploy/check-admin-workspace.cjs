const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const events = {};
const attributes = {};
const classes = new Set();
let focused;
const first = {setAttribute: (key, value) => { attributes[key] = value; }, focus: () => { focused = first; }};
const last = {focus: () => { focused = last; }};
const sidebar = {
    classList: {toggle: (key, value) => value ? classes.add(key) : classes.delete(key), contains: key => classes.has(key)},
    querySelector: () => first,
    querySelectorAll: query => query === 'a.active' ? [first] : [first, last],
    addEventListener: (name, fn) => { events['sidebar:'+name] = fn; }
};
const toggle = {setAttribute: (key, value) => { attributes[key] = value; }, focus: () => { focused = toggle; },
    addEventListener: (name, fn) => { events['toggle:'+name] = fn; }};
const scrim = {hidden: true, addEventListener: (name, fn) => { events['scrim:'+name] = fn; }};
const media = {matches: true, addEventListener: (name, fn) => { events['media:'+name] = fn; }};
const document = {
    getElementById: () => sidebar,
    querySelector: query => query === '[data-sidebar-toggle]' ? toggle : scrim,
    addEventListener: (name, fn) => { events['document:'+name] = fn; }
};
vm.runInNewContext(fs.readFileSync(process.argv[2], 'utf8'), {document, window: {matchMedia: () => media}});
assert.equal(sidebar.inert, true);
assert.equal(attributes['aria-current'], 'page');
events['toggle:click']();
assert.equal(classes.has('open'), true);
assert.equal(attributes['aria-expanded'], 'true');
assert.equal(scrim.hidden, false);
assert.equal(focused, first);
events['document:keydown']({key: 'Escape', preventDefault: () => {}});
assert.equal(classes.has('open'), false);
assert.equal(focused, toggle);
events['toggle:click']();
events['scrim:click']();
assert.equal(scrim.hidden, true);
media.matches = false;
events['media:change']();
assert.equal(sidebar.inert, false);
console.log('10 admin navigation assertions passed.');
