'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const { nameVariants, addressVariants, pickMatch, normalizePolicy, nameTokens } = require('./ewccv-matching.cjs');

// ── nameVariants ─────────────────────────────────────────────────────────────

test('nameVariants: legal suffix, generic word, then shrinking tokens', () => {
    assert.deepEqual(
        nameVariants('Mariusz Kot Construction, Inc'),
        ['Mariusz Kot Construction', 'Mariusz Kot', 'Mariusz', 'Kot']
    );
});

test('nameVariants: no generic word to drop, dedupes (b) against (a), capped at 4', () => {
    assert.deepEqual(
        nameVariants('JW Green Dump, Inc'),
        ['JW Green Dump', 'Green Dump', 'Green', 'Dump']
    );
});

test('nameVariants: never includes the original name', () => {
    const variants = nameVariants('Smartech Electric, Inc');
    assert.ok(!variants.includes('Smartech Electric, Inc'));
});

test('nameVariants: strips a dba tail', () => {
    const variants = nameVariants('ABC Holdings LLC dba Smartech Electric');
    assert.equal(variants[0], 'ABC Holdings');
});

test('nameVariants: single distinctive word collapses to no variants', () => {
    assert.deepEqual(nameVariants('Acme'), []);
});

test('nameVariants: empty input', () => {
    assert.deepEqual(nameVariants(''), []);
});

// ── addressVariants ──────────────────────────────────────────────────────────

test('addressVariants: drops suite and trailing street type, then number+street', () => {
    assert.deepEqual(
        addressVariants('1234 W Main St Ste 2'),
        ['1234 W Main', '1234 Main']
    );
});

test('addressVariants: no unit, no street type — nothing left to vary', () => {
    assert.deepEqual(addressVariants('1234 Main'), []);
});

test('addressVariants: capped at 2, never includes the original', () => {
    const variants = addressVariants('500 N Elm Avenue Apt 3B');
    assert.ok(variants.length <= 2);
    assert.ok(!variants.includes('500 N Elm Avenue Apt 3B'));
    assert.deepEqual(variants, ['500 N Elm', '500 Elm']);
});

test('addressVariants: empty input', () => {
    assert.deepEqual(addressVariants(''), []);
});

// ── normalizePolicy / nameTokens ──────────────────────────────────────────────

test('normalizePolicy strips everything but letters and digits, uppercases', () => {
    assert.equal(normalizePolicy('afwc-p 100134391'), 'AFWCP100134391');
    assert.equal(normalizePolicy(''), '');
    assert.equal(normalizePolicy(null), '');
});

test('nameTokens drops legal suffixes, generic words, and short tokens', () => {
    assert.deepEqual(nameTokens('Mariusz Kot Construction, Inc'), ['MARIUSZ', 'KOT']);
    assert.deepEqual(nameTokens('KOT CONSTRUCTION INC'), ['KOT']);
});

// ── pickMatch ────────────────────────────────────────────────────────────────

test('pickMatch: policy number containment wins tier 1', () => {
    const vendor = { businessName: 'Mariusz Kot Construction, Inc', policyNumber: '100134391' };
    const items = [
        { employerName: 'SOMETHING UNRELATED', policyNumber: 'AFWCP100134391' },
        { employerName: 'ANOTHER UNRELATED CO', policyNumber: 'ZZZ999999999' },
    ];
    const result = pickMatch(items, vendor);
    assert.equal(result.via, 'policyNumber');
    assert.equal(result.score, 1);
    assert.equal(result.matchedPolicy, 'AFWCP100134391');
    assert.equal(result.match, items[0]);
});

test('pickMatch: name tier score of 0.5 is not accepted against other candidates', () => {
    const vendor = { businessName: 'Mariusz Kot Construction, Inc' };
    const items = [
        { employerName: 'KOT CONSTRUCTION INC', policyNumber: 'X1' },
        { employerName: 'SOMETHING ELSE ENTIRELY', policyNumber: 'X2' },
    ];
    const result = pickMatch(items, vendor);
    assert.equal(result.match, null);
    assert.equal(result.via, null);
    assert.ok(result.candidates.length === 2);
    assert.equal(result.candidates[0].score, 0.5);
});

test('pickMatch: that same 0.5-scoring item is accepted as a sole result (>= 0.34)', () => {
    const vendor = { businessName: 'Mariusz Kot Construction, Inc' };
    const items = [
        { employerName: 'KOT CONSTRUCTION INC', policyNumber: 'X1' },
    ];
    const result = pickMatch(items, vendor);
    assert.equal(result.via, 'sole_result');
    assert.equal(result.score, 0.5);
    assert.equal(result.matchedName, 'KOT CONSTRUCTION INC');
});

test('pickMatch: a clearly different sole result is rejected, but candidates are returned', () => {
    const vendor = { businessName: 'Mariusz Kot Construction, Inc' };
    const items = [
        { employerName: 'ACME ROOFING LLC', policyNumber: 'Q9' },
    ];
    const result = pickMatch(items, vendor);
    assert.equal(result.match, null);
    assert.equal(result.via, null);
    assert.equal(result.candidates.length, 1);
    assert.equal(result.candidates[0].employerName, 'ACME ROOFING LLC');
});

test('pickMatch: employer-name tier accepts a strong match (score >= 0.6)', () => {
    const vendor = { businessName: 'Smartech Electric, Inc' };
    const items = [
        { employerName: 'SMARTECH ELECTRIC LLC', policyNumber: 'W1' },
        { employerName: 'UNRELATED PLUMBING CO', policyNumber: 'W2' },
    ];
    const result = pickMatch(items, vendor);
    assert.equal(result.via, 'employerName');
    assert.equal(result.matchedName, 'SMARTECH ELECTRIC LLC');
});

test('pickMatch: no items at all returns a null match with empty candidates', () => {
    const result = pickMatch([], { businessName: 'Anything' });
    assert.equal(result.match, null);
    assert.deepEqual(result.candidates, []);
});

test('pickMatch: ties on tier 1 (policy containment) break by higher name score', () => {
    const vendor = { businessName: 'Mariusz Kot Construction, Inc', policyNumber: '100134391' };
    const items = [
        { employerName: 'OTHER COMPANY ENTIRELY', policyNumber: 'BFWCP100134391' },
        { employerName: 'KOT CONSTRUCTION INC', policyNumber: 'AFWCP100134391' },
    ];
    const result = pickMatch(items, vendor);
    assert.equal(result.via, 'policyNumber');
    assert.equal(result.matchedName, 'KOT CONSTRUCTION INC');
    assert.equal(result.match, items[1]);
});

test('pickMatch: a true tie (same tier, same score) keeps the first item', () => {
    const vendor = { businessName: 'Mariusz Kot Construction, Inc' };
    const items = [
        { employerName: 'KOT CONSTRUCTION INC', policyNumber: 'FIRST1' },
        { employerName: 'KOT CONSTRUCTION LLC', policyNumber: 'SECOND2' },
    ];
    const result = pickMatch(items, vendor);
    // Neither clears 0.6 and there are two items, so this is the no-match path —
    // but both score 0.5 and neither's policy matches (vendor has none), so a
    // genuine tie-break-by-first would only surface via pickBest in a tier that
    // *is* accepted. Exercise that directly with a vendor policy number that
    // matches both, forcing tier 1 with an equal-score tie.
    assert.equal(result.match, null);

    const vendorWithPolicy = { businessName: 'Mariusz Kot Construction, Inc', policyNumber: '555555' };
    const tiedItems = [
        { employerName: 'KOT CONSTRUCTION INC', policyNumber: '555555AAAAAA' },
        { employerName: 'KOT CONSTRUCTION LLC', policyNumber: '555555BBBBBB' },
    ];
    const tiedResult = pickMatch(tiedItems, vendorWithPolicy);
    assert.equal(tiedResult.via, 'policyNumber');
    assert.equal(tiedResult.match, tiedItems[0]);
});
