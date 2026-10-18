'use strict';
const assert = require('node:assert/strict');
const parser = require('../../frontend/assets/js/gcash-reference.js');
const rules = {min: 13, max: 13};
for (const input of ['0012345678901', '0012 345 678901', '0012-345-678901', 'Reference No.: 0012 345 678901', 'Ref No.\n0012345678901', '{"reference_number":"0012345678901"}', 'https://example.invalid/receipt?ref_no=0012345678901']) {
    assert.equal(parser.extract(input, rules), '0012345678901', input);
}
for (const input of ['09171234567', 'PHP 123.00', '0002010102122637GCash merchant QR', '12345678901234', 'javascript:alert(1)', '{"reference_number":1234567890123}', 'Reference No.: 0012345678901\nRef No.: 0098765432109', 'https://example.invalid/?ref_no=0012345678901&ref_no=0098765432109']) {
    assert.equal(parser.extract(input, rules), null, input);
}
assert.equal(parser.extract('Amount 100.00\nPhone 09171234567\nReference No. 0012 345 678901\nPaid', rules, true), '0012345678901');
assert.equal(parser.extract('0012345678901', rules, true), null, 'OCR requires a reference label');
assert.equal(parser.extract('Ref No. 00123O5678901', rules, true), null, 'OCR must not silently turn letters into digits');
assert.equal(parser.extract('123456', {min: 6, max: 8}), '123456');
console.log('GCash reference extraction: passed');
