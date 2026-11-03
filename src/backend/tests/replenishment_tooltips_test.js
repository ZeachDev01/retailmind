const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const events = {};
const box = {style: {}, offsetWidth: 240, offsetHeight: 80};
const wrapper = {
    dismissed: false,
    setAttribute() { this.dismissed = true; },
    removeAttribute() { this.dismissed = false; },
    addEventListener(name, handler) { events[name] = handler; },
    querySelector() { return box; },
};
const trigger = {
    parentElement: wrapper,
    addEventListener(name, handler) { events[name] = handler; },
    getBoundingClientRect() { return {left: 300, top: 700, bottom: 744}; },
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../frontend/assets/js/info-tooltip.js'), 'utf8'), {
    document: { querySelectorAll: () => [trigger], querySelector: () => null },
    window: {innerWidth: 390, innerHeight: 800, addEventListener() {}},
});
events.keydown({ key: 'Enter' });
assert.equal(wrapper.dismissed, false);
for (const reopen of ['focus', 'click', 'mouseleave']) {
    events.keydown({ key: 'Escape' });
    assert.equal(wrapper.dismissed, true, 'Escape must dismiss help without moving focus');
    events[reopen]();
    assert.equal(wrapper.dismissed, false, `${reopen} must allow help to reopen`);
}
events.focus();
assert.equal(box.style.left, '142px', 'Help stays within the narrow viewport');
assert.equal(box.style.top, '612px', 'Help flips above the trigger near the bottom');
console.log('Replenishment tooltip dismissal, placement and reopening checks passed.');
