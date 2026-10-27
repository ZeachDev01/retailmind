(function (root) {
    'use strict';
    const normalize = value => String(value).replace(/[\s-]+/g, '');
    const valid = (value, rules) => new RegExp('^[0-9]{' + rules.min + ',' + rules.max + '}$').test(value);
    const keys = ['reference_number', 'reference_no', 'ref_no', 'reference', 'gcash_reference'];
    function extract(text, rules, labeledOnly = false) {
        text = String(text).trim();
        if (text.length > 4096) return null;
        if (!labeledOnly && valid(normalize(text), rules)) return normalize(text);
        const candidates = new Set();
        const add = value => { const candidate = normalize(value); if (valid(candidate, rules)) candidates.add(candidate); };
        // Require a label for receipt OCR: amounts, phone numbers, and dates are not references.
        const labels = /\b(?:reference\s*(?:number|no\.?)?|ref\.?\s*(?:number|no\.?))\s*[:#=\-]?\s*([0-9](?:[0-9 \t-]*[0-9])?)/gi;
        for (const match of text.matchAll(labels)) add(match[1]);
        if (!labeledOnly) {
            try {
                const data = JSON.parse(text);
                if (data && typeof data === 'object' && !Array.isArray(data)) {
                    // Strings preserve leading zeroes; JSON numbers may already have lost them.
                    for (const key of keys) if (typeof data[key] === 'string') add(data[key]);
                }
            } catch (_) { /* Most scanners send plain text, not JSON. */ }
            try {
                const url = new URL(text);
                if (['https:', 'http:'].includes(url.protocol)) {
                    for (const key of keys) for (const value of url.searchParams.getAll(key)) add(value);
                }
            } catch (_) { /* A reference need not be a URL. */ }
        }
        return candidates.size === 1 ? [...candidates][0] : null;
    }
    root.GCashReference = {extract, normalize, valid};
    if (typeof module !== 'undefined' && module.exports) module.exports = root.GCashReference;
})(typeof window === 'undefined' ? globalThis : window);
