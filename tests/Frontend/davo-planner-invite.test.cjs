const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/davo-planner-invite.js'), 'utf8');

function fixture(storage = new Map()) {
    let now = 0, next = 0;
    const timers = new Map();
    function element(properties = {}) {
        const handlers = {};
        return Object.assign({
            addEventListener(name, callback) { (handlers[name] ||= []).push(callback); },
            emit(name, event = {}) { (handlers[name] || []).forEach(callback => callback({ target: this, ...event })); },
            closest() { return null; },
            focus() { document.activeElement = this; },
        }, properties);
    }
    const close = element(), link = element(), launcher = element(), panel = element({ hidden: true });
    const invite = element({ hidden: true, getAttribute() { return null; }, contains(target) { return [invite, close, link].includes(target); },
        querySelector(selector) { return selector.includes('close') ? close : link; } });
    const document = element({ hidden: false, activeElement: null, menuOpen: false,
        querySelectorAll() { return []; },
        getElementById(id) { return id === 'chatbot-toggle' ? launcher : panel; },
        querySelector(selector) { return selector === '[data-davo-invite]' ? invite : this.menuOpen; } });
    const window = element();
    vm.runInNewContext(source, { document, window, sessionStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) },
        Date: { now: () => now }, setTimeout(callback, delay) { const id = ++next; timers.set(id, { callback, at: now + delay }); return id; },
        clearTimeout(id) { timers.delete(id); } });
    function advance(duration) {
        const end = now + duration;
        for (;;) {
            const due = [...timers].filter(([, timer]) => timer.at <= end).sort((a,b) => a[1].at - b[1].at)[0];
            if (!due) break;
            now = due[1].at; timers.delete(due[0]); due[1].callback();
        }
        now = end;
    }
    return { advance, invite, close, link, launcher, document };
}

test('waits nine seconds and does not repeat after a reload in the same visit', () => {
    const storage = new Map(), page = fixture(storage);
    page.advance(8999); assert.equal(page.invite.hidden, true);
    page.advance(1); assert.equal(page.invite.hidden, false);
    const reload = fixture(storage); reload.advance(30000); assert.equal(reload.invite.hidden, true);
});
test('waits for search focus, open menus, and recent interaction to finish', () => {
    const page = fixture();
    page.document.activeElement = { closest: () => ({}) };
    page.advance(12000); assert.equal(page.invite.hidden, true);
    page.document.activeElement = null; page.document.menuOpen = true;
    page.advance(5000); assert.equal(page.invite.hidden, true);
    page.document.menuOpen = false; page.document.emit('pointerdown');
    page.advance(3999); assert.equal(page.invite.hidden, true);
    page.advance(1); assert.equal(page.invite.hidden, false);
});
test('auto-dismiss pauses during mouse hover and keyboard focus', () => {
    const page = fixture(); page.advance(9000);
    page.invite.emit('pointerenter', { pointerType: 'mouse' });
    page.advance(15000); assert.equal(page.invite.hidden, false);
    page.invite.emit('pointerleave');
    page.document.activeElement = page.link; page.invite.emit('focusin');
    page.advance(15000); assert.equal(page.invite.hidden, false);
    page.document.activeElement = null; page.invite.emit('focusout');
    page.advance(11999); assert.equal(page.invite.hidden, false);
    page.advance(1); assert.equal(page.invite.hidden, true);
});
test('close and chat launcher dismiss the invitation without changing chat state', () => {
    const page = fixture(); page.advance(9000); page.close.emit('click');
    assert.equal(page.invite.hidden, true); assert.equal(page.document.activeElement, page.launcher);
    const earlyChat = fixture(); earlyChat.launcher.emit('click'); earlyChat.advance(15000);
    assert.equal(earlyChat.invite.hidden, true);
});
