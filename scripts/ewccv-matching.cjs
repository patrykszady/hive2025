'use strict';

/**
 * EWCCV name/address/policy matching helpers.
 *
 * Pure functions, no Puppeteer — used by ewccv-scraper.cjs to (a) generate
 * narrower search terms when a vendor's full business name / full address
 * draws a blank from EWCCV's "contains" search, and (b) decide which of the
 * returned items (if any) is actually the vendor, instead of the loose
 * substring-containment check the scraper used before.
 */

// ── Shared vocabulary ───────────────────────────────────────────────────────
// Legal-entity suffixes that EWCCV's own records frequently drop, abbreviate,
// or spell differently than a vendor's W-9/COI name. Stripped from the END of
// the (punctuation-cleaned) token list, repeatedly, so "XYZ Corp Inc" reduces
// fully.
const LEGAL_SUFFIXES = new Set([
    'inc', 'llc', 'corp', 'corporation', 'co', 'company',
    'ltd', 'limited', 'llp', 'pc',
]);

// Generic trade/connector words common enough across construction vendors
// that they add noise rather than signal to a name match.
const GENERIC_WORDS = new Set([
    'construction', 'contractors', 'contracting', 'services', 'service',
    'remodeling', 'enterprises', 'group', 'builders', 'building',
    'electric', 'electrical', 'plumbing', 'heating', 'cooling',
    'flooring', 'painting', 'drywall', 'roofing', 'landscaping',
    'supply', 'and', 'the', 'of',
]);

const STREET_TYPES = new Set([
    'st', 'street', 'ave', 'avenue', 'rd', 'road', 'dr', 'drive',
    'ln', 'lane', 'ct', 'court', 'blvd', 'pkwy', 'hwy', 'way',
    'pl', 'cir', 'ter',
]);

const UNIT_KEYWORDS = /^(ste|suite|apt|apartment|unit|fl|floor|rm|room|bldg|building)\.?$/i;

const DIRECTIONS = new Set(['N', 'S', 'E', 'W', 'NE', 'NW', 'SE', 'SW']);

// ── Small string helpers ────────────────────────────────────────────────────
function cleanPunctuation(s) {
    return s
        .replace(/[.,]/g, '')
        .replace(/[^A-Za-z0-9]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function stripDbaTail(name) {
    const m = name.match(/\bd\.?b\.?a\.?\b/i);
    if (!m) return name;
    return name.slice(0, m.index).trim();
}

function stripLegalSuffixes(tokens) {
    const result = tokens.slice();
    while (result.length > 1 && LEGAL_SUFFIXES.has(result[result.length - 1].toLowerCase())) {
        result.pop();
    }
    return result;
}

// Picks the `n` longest tokens (by length, ties broken by original position)
// and returns them back in their original relative order.
function topNByLength(tokens, n) {
    const withIndex = tokens.map((t, i) => ({ t, i, len: t.length }));
    withIndex.sort((a, b) => b.len - a.len || a.i - b.i);
    const top = withIndex.slice(0, n);
    top.sort((a, b) => a.i - b.i);
    return top.map((x) => x.t);
}

// ── Name variants ────────────────────────────────────────────────────────────
/**
 * Ordered, deduplicated alternative employer-name search terms for a
 * business name, never including the original name itself. Capped at 4.
 *
 * @param {string} businessName
 * @returns {string[]}
 */
function nameVariants(businessName) {
    if (!businessName) return [];

    const original = businessName.trim();
    const variants = [];
    const add = (v) => {
        if (v && v !== original && !variants.includes(v)) variants.push(v);
    };

    // (a) punctuation + legal suffixes + "dba ..." tail removed
    const noDba = stripDbaTail(original);
    const cleaned = cleanPunctuation(noDba);
    const tokens = cleaned.length ? cleaned.split(' ') : [];
    const noSuffixTokens = stripLegalSuffixes(tokens);
    add(noSuffixTokens.join(' '));

    // (b) additionally without generic trade words
    const distinctiveTokens = noSuffixTokens.filter((t) => !GENERIC_WORDS.has(t.toLowerCase()));
    add(distinctiveTokens.join(' '));

    // (c) the two longest remaining distinctive tokens, in original order
    if (distinctiveTokens.length > 0) {
        add(topNByLength(distinctiveTokens, 2).join(' '));
    }

    // (d) the single longest distinctive token, if it's long enough to carry signal
    const longest = topNByLength(distinctiveTokens, 1)[0];
    if (longest && longest.length >= 4) {
        add(longest);
    }

    // (e) the last distinctive token, if it's long enough to carry signal
    const last = distinctiveTokens[distinctiveTokens.length - 1];
    if (last && last.length >= 3) {
        add(last);
    }

    return variants.slice(0, 4);
}

// ── Address variants ─────────────────────────────────────────────────────────
function stripUnit(tokens) {
    const out = [];
    for (const t of tokens) {
        if (UNIT_KEYWORDS.test(t) || t.startsWith('#')) break;
        out.push(t);
    }
    return out;
}

function stripTrailingStreetType(tokens) {
    if (tokens.length > 1 && STREET_TYPES.has(tokens[tokens.length - 1].toLowerCase())) {
        return tokens.slice(0, -1);
    }
    return tokens;
}

/**
 * Up to 2 distinct alternative street-line search terms for an address,
 * never including the original address itself.
 *
 * @param {string} address
 * @returns {string[]}
 */
function addressVariants(address) {
    if (!address) return [];

    const original = address.trim();
    const variants = [];
    const add = (v) => {
        if (v && v !== original && !variants.includes(v)) variants.push(v);
    };

    const cleaned = cleanPunctuation(original);
    const tokens = cleaned.length ? cleaned.split(' ') : [];

    // (a) no unit/suite/apt part, no trailing street-type word
    const withoutUnit = stripUnit(tokens);
    const withoutStreetType = stripTrailingStreetType(withoutUnit);
    add(withoutStreetType.join(' '));

    // (b) house number + first street word after an optional direction
    if (withoutStreetType.length > 0) {
        const [number, ...rest] = withoutStreetType;
        const dirSkip = (rest.length > 0 && DIRECTIONS.has(rest[0].toUpperCase())) ? 1 : 0;
        const streetWord = rest[dirSkip];
        if (streetWord) {
            add(`${number} ${streetWord}`);
        }
    }

    return variants.slice(0, 2);
}

// ── Matching ──────────────────────────────────────────────────────────────────
/**
 * @param {string} s
 * @returns {string} A–Z0–9 only, uppercased.
 */
function normalizePolicy(s) {
    return (s || '').toString().toUpperCase().replace(/[^A-Z0-9]/g, '');
}

function rawTokens(name) {
    if (!name) return [];
    return name.toUpperCase().match(/[A-Z0-9]+/g) || [];
}

/**
 * Uppercase letter/digit tokens with legal suffixes, generic trade words,
 * and tokens under 2 chars dropped.
 *
 * @param {string} name
 * @returns {string[]}
 */
function nameTokens(name) {
    return rawTokens(name).filter((t) =>
        t.length >= 2 && !LEGAL_SUFFIXES.has(t.toLowerCase()) && !GENERIC_WORDS.has(t.toLowerCase())
    );
}

// Every word but the legal suffix and the connectors: an initial ("M Power")
// and the trade words ("Services") count here, so two firms that share only
// their distinctive words are told apart.
const CONNECTORS = new Set(['and', 'the', 'of']);

function fullTokens(name) {
    return rawTokens(name).filter((t) => !LEGAL_SUFFIXES.has(t.toLowerCase()) && !CONNECTORS.has(t.toLowerCase()));
}

function overlap(ours, theirs) {
    if (ours.length === 0) return 0;
    const oursUnique = Array.from(new Set(ours));
    const theirSet = new Set(theirs);
    const shared = oursUnique.filter((t) => theirSet.has(t)).length;
    return shared / Math.max(oursUnique.length, theirSet.size);
}

function policiesMatch(aNorm, bNorm) {
    if (!aNorm || !bNorm) return false;
    if (aNorm === bNorm) return true;
    return aNorm.length >= 6 && bNorm.length >= 6 && (aNorm.includes(bNorm) || bNorm.includes(aNorm));
}

function editDistance(a, b) {
    let previous = Array.from({ length: b.length + 1 }, (_, j) => j);
    for (let i = 1; i <= a.length; i++) {
        const current = [i];
        for (let j = 1; j <= b.length; j++) {
            current[j] = Math.min(previous[j] + 1, current[j - 1] + 1, previous[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
        }
        previous = current;
    }
    return previous[b.length];
}

/**
 * How likely two policy numbers are the same policy, 0..1, with case, dashes
 * and spaces ignored: 1 for the same number, 0.95 when one holds the other
 * (an insurer's prefix, "AFWCP" + "100134391"), else one minus the edit
 * distance over the longer length, so a missing or mistyped character in a
 * 14-character number still scores 0.93 (Patryk, 2026-10-14).
 */
function policySimilarity(a, b) {
    const x = normalizePolicy(a);
    const y = normalizePolicy(b);
    if (!x || !y) return 0;
    if (policiesMatch(x, y)) return x === y ? 1 : 0.95;
    return 1 - editDistance(x, y) / Math.max(x.length, y.length);
}

// The same policy, whatever the names say.
const POLICY_SAME = 0.95;
// Nearly the same number (a typo, a dropped character): the same policy only
// when the name agrees as well, since an agency numbers its policies in
// sequence — M Power's 6JUBA421140425 and PHD Glass's 6JUBA421161325 are
// three characters apart.
const POLICY_LIKELY = 0.85;
const NAME_BACKS_POLICY = 0.5;

// score = shared distinctive tokens / the LARGER of the two token sets, so a
// candidate that merely contains our one distinctive word scores low: on
// 2026-10-13 "Smartech Electric, Inc" was matched to "ZOLO CARBON SMARTECH
// INC" (and subscribed to) because the old ratio looked only at our side.
// Falls back to unfiltered tokens when our name has no distinctive ones left
// (e.g. it is entirely a legal suffix / generic word).
function similarityScore(ourName, theirName) {
    let ours = nameTokens(ourName);
    let theirs = nameTokens(theirName);
    if (ours.length === 0) {
        ours = rawTokens(ourName);
        theirs = rawTokens(theirName);
    }
    if (ours.length === 0) return 0;

    const oursUnique = Array.from(new Set(ours));
    const theirsUnique = Array.from(new Set(theirs));
    const theirSet = new Set(theirsUnique);
    const shared = oursUnique.filter((t) => theirSet.has(t)).length;
    return shared / Math.max(oursUnique.length, theirsUnique.length);
}

// The name decides only when there is no policy number to compare: none on
// the certificate, or EWCCV hiding its own. A certificate whose number EWCCV
// does not list is never matched by name — "POWER SOLUTION SERVICES INC"
// scored 1.0 against "M Power Solution Company" on distinctive words and was
// tracked in its place (Patryk, 2026-10-14: "we should always match the
// policy number"). Against a hidden number the whole name must agree,
// initials and trade words included.
const STRICT_WITHOUT_POLICY = 0.75;

function nameMatchAllowed(candidate, vendorPolicyNorm, threshold) {
    if (candidate.score < threshold) return false;
    if (!vendorPolicyNorm) return true;
    if (normalizePolicy(candidate.policy)) return false;
    return candidate.score >= STRICT_WITHOUT_POLICY && candidate.fullScore >= STRICT_WITHOUT_POLICY;
}

// Tie-break among several same-tier candidates: prefer one whose policy
// number also matches the vendor's, else the highest score, else the first.
function pickBest(candidates, vendorPolicyNorm) {
    let pool = candidates;

    if (vendorPolicyNorm) {
        const withPolicyMatch = pool.filter((c) => policiesMatch(normalizePolicy(c.policy), vendorPolicyNorm));
        if (withPolicyMatch.length > 0) pool = withPolicyMatch;
    }

    const maxScore = Math.max(...pool.map((c) => c.score));
    const topScored = pool.filter((c) => c.score === maxScore);
    return topScored[0];
}

/**
 * @param {Array<object>} items EWCCV result items ({policyNumber|writtenPolicyId, employerName|name, stateCode, ...}).
 * @param {{businessName?: string, policyNumber?: string}} vendor
 * @returns {{match: object|null, via: string|null, score: number, matchedName: string|null, matchedPolicy: string|null, candidates: Array<{employerName: string, policyNumber: string, stateCode: *, score: number}>}}
 */
/** The scalar fields of an EWCCV result item, kept for comparison with the vendor on file. */
function scalarFields(item) {
    const out = {};
    for (const [key, val] of Object.entries(item || {})) {
        if (val === null || ['string', 'number', 'boolean'].includes(typeof val)) out[key] = val;
    }
    return out;
}

function pickMatch(items, vendor) {
    const empty = { match: null, via: null, score: 0, matchedName: null, matchedPolicy: null, candidates: [] };
    if (!items || items.length === 0) return empty;

    const scored = items.map((item) => {
        const name = item.employerName || item.name || '';
        const policy = item.policyNumber || item.writtenPolicyId || '';
        // The best score across every name the business has used (aliases from
        // older certificates), so "Smartech Inc" on file still matches today's name.
        const ourNames = [vendor.businessName, ...(vendor.aliases || [])].filter(Boolean);
        const score = ourNames.length ? Math.max(...ourNames.map((n) => similarityScore(n, name))) : 0;
        const fullScore = ourNames.length ? Math.max(...ourNames.map((n) => overlap(fullTokens(n), fullTokens(name)))) : 0;
        const policyScore = policySimilarity(vendor.policyNumber, policy);
        return { item, name, policy, score, fullScore, policyScore };
    });

    const candidates = scored
        .slice()
        .sort((a, b) => b.score - a.score)
        .slice(0, 10)
        .map((s) => ({ employerName: s.name, policyNumber: s.policy, stateCode: s.item.stateCode, score: s.score, policyScore: Number(s.policyScore.toFixed(2)), fields: scalarFields(s.item) }));

    const vendorPolicyNorm = normalizePolicy(vendor.policyNumber);

    // Tier 0: the FEIN, when we know it — nothing is more exact.
    const ourFein = String(vendor.fein || '').replace(/[^0-9]/g, '');
    if (ourFein.length >= 9) {
        const feinMatches = scored.filter((s) => String(s.item.fein || '').replace(/[^0-9]/g, '') === ourFein);
        if (feinMatches.length > 0) {
            const chosen = pickBest(feinMatches, vendorPolicyNorm);
            return {
                match: chosen.item, via: 'fein', score: 1,
                matchedName: chosen.name, matchedPolicy: chosen.policy, candidates,
            };
        }
    }

    // Tier 1: the same policy number (equal, or one holding the other).
    // Tier 1b: nearly the same number, with the name agreeing.
    if (vendorPolicyNorm) {
        const policyMatches = scored.filter((s) => s.policyScore >= POLICY_SAME);
        if (policyMatches.length > 0) {
            const chosen = pickBest(policyMatches, vendorPolicyNorm);
            return {
                match: chosen.item, via: 'policyNumber', score: 1, policyScore: chosen.policyScore,
                matchedName: chosen.name, matchedPolicy: chosen.policy, candidates,
            };
        }
        const likely = scored
            .filter((s) => s.policyScore >= POLICY_LIKELY && Math.max(s.score, s.fullScore) >= NAME_BACKS_POLICY)
            .sort((a, b) => b.policyScore - a.policyScore || b.score - a.score);
        if (likely.length > 0) {
            const chosen = likely[0];
            return {
                match: chosen.item, via: 'policyNumber', score: chosen.score, policyScore: chosen.policyScore,
                matchedName: chosen.name, matchedPolicy: chosen.policy, candidates,
            };
        }
    }

    // Tier 2: name similarity.
    const nameMatches = scored.filter((s) => nameMatchAllowed(s, vendorPolicyNorm, 0.6));
    if (nameMatches.length > 0) {
        const chosen = pickBest(nameMatches, vendorPolicyNorm);
        return {
            match: chosen.item, via: 'employerName', score: chosen.score,
            matchedName: chosen.name, matchedPolicy: chosen.policy, candidates,
        };
    }

    // Tier 3: a sole result sharing at least half of the distinctive words.
    if (items.length === 1 && nameMatchAllowed(scored[0], vendorPolicyNorm, 0.5)) {
        const chosen = scored[0];
        return {
            match: chosen.item, via: 'sole_result', score: chosen.score,
            matchedName: chosen.name, matchedPolicy: chosen.policy, candidates,
        };
    }

    return { ...empty, candidates };
}

module.exports = {
    policySimilarity,
    nameVariants,
    addressVariants,
    pickMatch,
    normalizePolicy,
    nameTokens,
};
