const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const events = {};
const wrapper = {
    dismissed: false,
    setAttribute() { this.dismissed = true; },
    removeAttribute() { this.dismissed = false; },
    addEventListener(name, handler) { events[name] = handler; },
};
const trigger = {
    parentElement: wrapper,
    addEventListener(name, handler) { events[name] = handler; },
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../frontend/assets/js/replenishment.js'), 'utf8'), {
    document: { querySelectorAll: () => [trigger], querySelector: () => null },
});
events.keydown({ key: 'Enter' });
assert.equal(wrapper.dismissed, false);
for (const reopen of ['focus', 'click', 'mouseleave']) {
    events.keydown({ key: 'Escape' });
    assert.equal(wrapper.dismissed, true, 'Escape must dismiss help without moving focus');
    events[reopen]();
    assert.equal(wrapper.dismissed, false, `${reopen} must allow help to reopen`);
}
console.log('Replenishment tooltip dismissal and reopening checks passed.');
