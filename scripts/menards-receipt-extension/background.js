/**
 * Hive — Menards Receipt Sync (MV3 service worker)
 *
 * Owns the schedule and the hand-off to Hive. The fetching itself lives in
 * content.js, because only a content script on receiptLookup.html carries the
 * page's origin, cookies and CSRF token.
 *
 * Shape of a run:
 *   alarm fires -> open (or reuse) a receiptLookup.html tab -> ask the content
 *   script to sync -> POST the receipts to Hive -> close the tab if we opened it.
 *
 * The browser this runs in is a real signed-in Chromium kept alive on the server
 * under Xvfb (see MenardsRemoteLoginService), so no person is involved after the
 * one-time sign-in over noVNC.
 */

const RECEIPT_URL = 'https://www.menards.com/main/receiptLookup.html';
const ALARM = 'menards-receipt-sync';
const INGEST_PATH = '/api/menards/receipts';

/** How far back each run looks. Wide on purpose: re-importing is a no-op server side. */
const DEFAULT_LOOKBACK_DAYS = 14;

/**
 * How far back a first run reaches.
 *
 * A fixed floor, not a day count. Everything before this was already imported by
 * the old scraper, so reaching further back costs a download and an OCR pass per
 * receipt to arrive at "skipped" — a year's backfill spent ~20 minutes doing
 * exactly that. July is where the old scraper's coverage ends and the gap begins.
 */
const BACKFILL_SINCE = '2026-07-01';

/**
 * True when the extension looks like it is running in a person's own browser
 * rather than the server's. The server's own Chrome is Linux under Xvfb;
 * Windows or macOS means someone's daily browser.
 */
function isPersonalPlatform(platformString) {
    return !/linux/i.test(platformString || '');
}

/**
 * Personal vs. server mode.
 *
 * An explicit `personal` boolean in storage — set from the options page's
 * "Where is this running?" select — always wins. Absent that, detect from the
 * platform the browser itself reports.
 */
async function personalMode() {
    const { personal } = await chrome.storage.local.get('personal');
    if (typeof personal === 'boolean') return personal;

    const platform = navigator.userAgentData?.platform || navigator.platform || '';
    return isPersonalPlatform(platform);
}

/**
 * A short label for the browser this is, for Hive's status report — e.g.
 * "Microsoft Edge 156". Falls back to the raw user agent, capped, when
 * userAgentData is unavailable.
 */
function browserDescription() {
    const brands = navigator.userAgentData?.brands;

    if (Array.isArray(brands) && brands.length) {
        const real = brands.find(b => b.brand && !/not.*brand/i.test(b.brand) && b.brand !== 'Chromium')
            || brands.find(b => b.brand && !/not.*brand/i.test(b.brand));
        if (real) return `${real.brand} ${real.version}`.slice(0, 120);
    }

    return (navigator.userAgent || '').slice(0, 120);
}

async function settings() {
    const s = await chrome.storage.local.get(['serverUrl', 'token', 'lastSuccessAt', 'everSucceeded', 'solveChallenges']);

    return {
        serverUrl: (s.serverUrl || '').replace(/\/+$/, ''),
        token: s.token || '',
        lastSuccessAt: s.lastSuccessAt || null,
        everSucceeded: !!s.everSucceeded,
        // Buying hCaptcha tokens is OFF unless the server says otherwise: the
        // server clears the wall's checkbox itself, and a token injected while
        // it does resets the widget under its click. Six tokens on 2026-09-14
        // bought nothing but that.
        solveChallenges: s.solveChallenges === true,
    };
}

function sinceDate(days) {
    const d = new Date();
    d.setDate(d.getDate() - days);

    return d.toISOString().slice(0, 10);
}

async function note(text) {
    console.log('[menards-sync]', text);
    await chrome.storage.local.set({ lastMessage: `${new Date().toISOString()} ${text}` });
}

/** A tab on the receipt page, reusing one if the browser already has it open. */
/**
 * Give a freshly loaded receipt tab a moment to stop moving before it is
 * asked to work. On 2026-10-29 the first personal-browser run died with
 * "the message channel closed before a response was received": the page
 * navigated once more right after 'complete', taking the content script
 * with it. So: never let the browser discard this tab (Edge sleeps
 * background tabs), then wait until the URL and status have held still
 * for two consecutive checks — at least the old 4 s, at most ~20 s.
 */
async function settleTab(tabId) {
    await chrome.tabs.update(tabId, { autoDiscardable: false }).catch(() => {});

    let previous = null;
    let stableChecks = 0;

    for (let i = 0; i < 10 && stableChecks < 2; i++) {
        await new Promise(r => setTimeout(r, 2000));

        const t = await chrome.tabs.get(tabId).catch(() => null);
        const now = t ? `${t.status}|${t.url}` : null;

        stableChecks = now && now === previous && t.status === 'complete' ? stableChecks + 1 : 0;
        previous = now;
    }
}

/** Resolve once the tab reports a complete load, or after a minute regardless. */
function waitForLoad(tabId) {
    return new Promise(resolve => {
        const listener = (id, info) => {
            if (id === tabId && info.status === 'complete') {
                chrome.tabs.onUpdated.removeListener(listener);
                resolve();
            }
        };
        chrome.tabs.onUpdated.addListener(listener);
        setTimeout(() => {
            chrome.tabs.onUpdated.removeListener(listener);
            resolve();
        }, 60000);
    });
}

async function receiptTab() {
    const existing = await chrome.tabs.query({ url: 'https://www.menards.com/main/receiptLookup.html*' });

    if (existing.length) {
        // A page that sat open for hours is a page Imperva has had hours to
        // re-score: on 2026-09-14 the idle receipt tab drew the wall three
        // minutes after a successful sync, with nothing navigating it. A fresh
        // load gets a fresh evaluation, and the bridge and content script with it.
        await chrome.tabs.reload(existing[0].id);
        await waitForLoad(existing[0].id);
        await settleTab(existing[0].id);
        await assertOnReceiptPage(existing[0].id);

        return { tab: existing[0], opened: false };
    }

    const tab = await chrome.tabs.create({ url: RECEIPT_URL, active: false });

    // Wait for the content script to be live before messaging it.
    await waitForLoad(tab.id);

    // The SPA still has to boot after 'complete', and a personal browser's
    // first load may bounce once more (store selection, a session refresh).
    await settleTab(tab.id);

    await assertOnReceiptPage(tab.id);

    return { tab, opened: true };
}

/** Guards waitForPersonalSignIn() against a second listener on the same tab. */
/**
 * The tab a signed-out personal browser is waiting on, kept in session
 * storage rather than a module variable: MV3 stops this service worker after
 * ~30 s idle, and a person takes longer than that to sign in. The listeners
 * below are registered at the top level for the same reason — a listener
 * added inside a function dies with the worker.
 */
const WAITING_TAB_KEY = 'waitingForSignInTab';

async function waitingForSignInTabId() {
    try {
        const { [WAITING_TAB_KEY]: id } = await chrome.storage.session.get(WAITING_TAB_KEY);

        return typeof id === 'number' ? id : null;
    } catch {
        return null;
    }
}

/** A menards.com/main/ page that is not one of the pages that bounced us here. */
function looksSignedIn(url) {
    return /^https:\/\/www\.menards\.com\/main\//i.test(url || '')
        && !/\/(login|checkcredentials|error)\.html(?:[?#]|$)/i.test(url);
}

/**
 * Surface a signed-out tab to the person sitting at this browser, and wait
 * for them to sign in, rather than asking an operator to do it over noVNC —
 * there is no operator here, only whoever is in front of the keyboard.
 */
async function waitForPersonalSignIn(tabId) {
    try {
        await chrome.storage.session.set({ [WAITING_TAB_KEY]: tabId });
    } catch {
    }
}

chrome.tabs.onUpdated.addListener(async (id, _changeInfo, t) => {
    if (id !== await waitingForSignInTabId() || !looksSignedIn(t?.url)) return;

    await chrome.storage.session.remove(WAITING_TAB_KEY).catch(() => {});
    await note('signed in on the tab that was waiting — fetching receipts');
    setTimeout(() => run('signed in'), 3000);
});

chrome.tabs.onRemoved.addListener(async (id) => {
    if (id !== await waitingForSignInTabId()) return;

    await chrome.storage.session.remove(WAITING_TAB_KEY).catch(() => {});
});

/**
 * Menards bounces a signed-out request for the receipt page to login.html, where
 * our content script does not run — so messaging it fails with Chrome's opaque
 * "Could not establish connection. Receiving end does not exist." Checking where
 * the tab actually landed turns that into the one thing the operator can act on.
 *
 * In a personal browser there is no operator to reach over noVNC — there is
 * only whoever owns the browser, and their tab is already on screen. So
 * instead of the server-flavoured message, bring that tab to the front and
 * wait: run() picks back up on its own once they sign in (waitForPersonalSignIn).
 */
async function assertOnReceiptPage(tabId) {
    const current = await chrome.tabs.get(tabId).catch(() => null);
    const url = current?.url || '';

    if (url.includes('/main/receiptLookup.html')) return;

    if (await personalMode()) {
        await chrome.tabs.update(tabId, { active: true }).catch(() => {});
        if (current?.windowId != null) {
            await chrome.windows.update(current.windowId, { focused: true }).catch(() => {});
        }

        await waitForPersonalSignIn(tabId);

        throw new Error(
            'Not signed in to menards.com — sign in on the tab that just opened; the receipts follow by themselves.'
        );
    }

    throw new Error(
        `Not signed in to menards.com — the receipt page redirected to ${url || 'an unknown page'}. `
        + 'Sign in once over noVNC; the profile keeps the session.'
    );
}

/**
 * Ask the content script to run a sync, reviving it first if it has gone deaf.
 *
 * Reloading or updating the extension orphans the content scripts already
 * running in open tabs: the page keeps executing the old copy, which is no
 * longer connected to this service worker, so messaging it fails with Chrome's
 * "Receiving end does not exist." Because we deliberately reuse an open receipt
 * tab rather than disturbing it, that stale state would otherwise persist until
 * someone navigated the tab by hand. Injecting a fresh copy fixes it in place.
 */
async function sendSync(tabId, since) {
    try {
        return await chrome.tabs.sendMessage(tabId, { action: 'sync', since });
    } catch {
        // The bridge first, in the page's world, so the fresh content script
        // finds it in place (it checks a marker on <html>).
        await chrome.scripting.executeScript({ target: { tabId }, files: ['page-bridge.js'], world: 'MAIN' });
        await chrome.scripting.executeScript({ target: { tabId }, files: ['content.js'] });

        return await chrome.tabs.sendMessage(tabId, { action: 'sync', since });
    }
}

async function postToHive(payload) {
    const { serverUrl, token } = await settings();

    if (!serverUrl || !token) throw new Error('Hive URL/token not configured — open the extension options.');

    const res = await fetch(`${serverUrl}${INGEST_PATH}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        // personal: so Hive knows this report came from someone's own browser
        // rather than the server's, and can stand its own browser down.
        body: JSON.stringify({ ...payload, personal: await personalMode() }),
    });

    const body = await res.json().catch(() => ({}));

    if (!res.ok || !body.ok) throw new Error(body.error || `Hive returned HTTP ${res.status}`);

    return body;
}

/**
 * Tell Hive how this run went.
 *
 * The server cannot see this any other way: its own signed-in check reads the
 * window title, because navigating to menards.com to check is what draws the
 * Imperva challenge. So a dead session used to be invisible server-side —
 * four scheduled runs in a row failed on 2026-08-23 while `status` still said
 * signed_in: yes. Best effort: a reporting failure must never fail the sync.
 */
async function reportStatus(ok, error, receipts) {
    try {
        const { serverUrl, token } = await settings();
        if (!serverUrl || !token) return;

        await fetch(`${serverUrl}/api/menards/sync-status`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'Authorization': `Bearer ${token}`,
            },
            body: JSON.stringify({
                ok,
                error: error || null,
                receipts: receipts ?? null,
                // personal/browser: which browser this is, so Hive can stand
                // its own server browser down once a personal one is reporting.
                personal: await personalMode(),
                browser: browserDescription(),
            }),
        });
    } catch (e) {
        // Swallowed on purpose — see the docblock.
    }
}

/**
 * One sync, with a single fresh page load and second try when the first
 * attempt could not even reach the page. Two cases, both cheap next to a
 * whole day without receipts:
 *
 *  - Imperva challenged the API on this page load. The very same run a
 *    minute later passed on 2026-09-14: the token it judges by is refreshed
 *    per page load.
 *  - The page load itself failed and Chrome is showing its own error page,
 *    so the content script is not there to answer ("Frame with ID 0 is
 *    showing error page", "Receiving end does not exist"). A TLS handshake
 *    through the residential proxy dropped that way on 2026-09-23; the retry
 *    a minute later fetched five receipts.
 */
async function syncWithOneReload(tab, since) {
    const retryable = /challenge page|showing error page|Receiving end does not exist|Could not establish connection|message channel closed/i;

    let result;
    try {
        result = await sendSync(tab.id, since);
    } catch (err) {
        if (!retryable.test(err.message || '')) throw err;
        result = { ok: false, error: err.message };
    }

    if (result?.ok || !retryable.test(result?.error || '')) return result;

    await note(`first try failed (${result.error}) — reloading the receipt page and trying once more`);
    await new Promise(r => setTimeout(r, 15000));
    await chrome.tabs.reload(tab.id);
    await waitForLoad(tab.id);
    await new Promise(r => setTimeout(r, 4000));
    await assertOnReceiptPage(tab.id);

    return sendSync(tab.id, since);
}

async function run(reason) {
    const { everSucceeded } = await settings();
    const since = everSucceeded ? sinceDate(DEFAULT_LOOKBACK_DAYS) : BACKFILL_SINCE;

    await note(`run start (${reason}), since ${since}`);

    let opened = false;
    let tab = null;

    try {
        ({ tab, opened } = await receiptTab());

        const result = await syncWithOneReload(tab, since);

        if (!result?.ok) throw new Error(result?.error || 'content script returned no result');

        if (result.receipts.length === 0) {
            await note(`no receipts on or after ${since} — nothing to send`);
            await chrome.storage.local.set({ lastSuccessAt: new Date().toISOString(), everSucceeded: true, lastError: null });
            await reportStatus(true, null, 0);

            return;
        }

        const hive = await postToHive({
            since,
            scrapedAt: result.scrapedAt,
            receipts: result.receipts,
        });

        await chrome.storage.local.set({
            lastSuccessAt: new Date().toISOString(),
            everSucceeded: true,
            lastError: null,
        });

        await reportStatus(true, null, result.receipts.length);

        // Hive queues the import rather than running it in the request, so the
        // reply says "accepted", not "imported". How the import itself went is in
        // the menards log on the server; it is not known while this fetch is open.
        await note(`sent ${result.receipts.length} receipts; Hive queued ${hive.received ?? '?'} for import`
            + (result.errors.length ? `; ${result.errors.length} failed to download` : ''));
    } catch (err) {
        await chrome.storage.local.set({ lastError: err.message });
        await note(`FAILED: ${err.message}`);
        await reportStatus(false, err.message, null);
    } finally {
        // Only close what we opened — never a tab a person left open, and
        // never a personal-browser tab we are watching for a sign-in to land.
        if (opened && tab?.id && tab.id !== await waitingForSignInTabId()) {
            await chrome.tabs.remove(tab.id).catch(() => {});
        }
    }
}

chrome.runtime.onMessage.addListener(msg => {
    if (msg?.action === 'progress') note(msg.text);
    if (msg?.action === 'run') run('options page');
});

/**
 * Relay a challenge solve for challenge.js.
 *
 * The content script cannot hold the 2captcha key — a content script's source
 * is readable by the page it runs in. So it reports the sitekey here, and this
 * asks Hive, which owns the key and does the buying.
 *
 * Returns true to keep the message channel open for the async reply; without
 * it Chrome closes the port and sendResponse lands nowhere.
 */
chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (msg?.type === 'hive-challenge-unsolvable') {
        note(`challenge seen but unsolvable: ${msg.reason}`);
        return false;
    }

    if (msg?.type !== 'hive-solve-challenge') return false;

    (async () => {
        try {
            const { serverUrl, token, solveChallenges } = await settings();
            if (!serverUrl || !token) throw new Error('Hive URL/token not configured');

            if (!solveChallenges) {
                throw new Error('automatic solving is off — the server clears the checkbox itself; an image puzzle needs a human');
            }

            await note(`challenge: asking Hive to solve ${msg.siteKey}`);

            const res = await fetch(`${serverUrl}/api/menards/solve-challenge`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${token}`,
                },
                body: JSON.stringify({ siteKey: msg.siteKey, pageUrl: msg.pageUrl }),
            });

            const body = await res.json().catch(() => ({}));

            if (!res.ok || !body.ok || !body.token) {
                throw new Error(body.error || `solve endpoint returned ${res.status}`);
            }

            // user_agent is the Chrome this token was actually solved as
            // (NoneCap only — see MenardsCaptchaSolver::solveWithNoneCap()).
            // null for 2captcha or when NoneCap reports none; challenge.js
            // treats that as "nothing to match" and injects as before.
            await note(`challenge: token received${body.user_agent ? ` (solved as ${body.user_agent})` : ''}, injecting`);
            sendResponse({ ok: true, token: body.token, user_agent: body.user_agent || null });
        } catch (err) {
            await note(`challenge: ${err.message}`);
            sendResponse({ ok: false, error: err.message });
        }
    })();

    return true;
});

/**
 * Match outgoing Imperva headers to whichever Chrome NoneCap actually solved
 * as (trial, 2026-10-22+).
 *
 * NoneCap ignores the user agent we ask it to solve as — it earns the token
 * on a random Chrome 152-154 while this browser's real Chrome is 151 — and
 * Imperva refused all four tokens from that mismatch. NoneCap's own docs say
 * to submit the token from the browser that matches the solve, which this
 * browser cannot BE, but can momentarily impersonate for the one request
 * that matters: the Incapsula resource Imperva's own callback POSTs the
 * token to. See MenardsCaptchaSolver::solveWithNoneCap().
 *
 * A session rule (not a persistent one) because this only needs to outlive
 * one page load, and declarativeNetRequest.updateSessionRules needs no
 * extra host permission beyond what the manifest already grants menards.com.
 */
const UA_RULE_ID = 9001;
const UA_RULE_URL_FILTER = 'menards.com/_Incapsula_Resource?SWCGHOEL';
/** Chrome never fired a completion event in testing within much less than this; see armUaRuleCleanup. */
const UA_RULE_FALLBACK_MS = 20000;

let uaRuleCleanupTimer = null;
let uaRuleRequestListenerAdded = false;

/**
 * sec-ch-ua / sec-ch-ua-full-version-list for the Chrome version embedded in
 * a user agent string like "...Chrome/153.0.0.0 Safari/537.36". Returns null
 * when the string does not look like Chrome's UA at all.
 */
function chromeHeadersForUserAgent(userAgent) {
    const m = /Chrome\/([\d.]+)/.exec(userAgent || '');
    if (!m) return null;

    const full = m[1];
    const major = full.split('.')[0];

    return {
        'sec-ch-ua': `"Chromium";v="${major}", "Google Chrome";v="${major}", "Not-A.Brand";v="24"`,
        'sec-ch-ua-full-version-list': `"Chromium";v="${full}", "Google Chrome";v="${full}", "Not-A.Brand";v="24.0.0.0"`,
    };
}

/**
 * Add the session rule, then arm its removal — the rule must not outlive the
 * one request it exists for, or every OTHER request this browser makes
 * (including its own ordinary receipt sync) would carry a Chrome version
 * that does not match the TLS/JS fingerprint Imperva also reads.
 */
async function addUaRule(userAgent) {
    const headers = chromeHeadersForUserAgent(userAgent);

    if (!headers) {
        throw new Error(`could not find a Chrome version in "${userAgent}"`);
    }

    const rule = {
        id: UA_RULE_ID,
        priority: 1,
        action: {
            type: 'modifyHeaders',
            requestHeaders: [
                { header: 'user-agent', operation: 'set', value: userAgent },
                { header: 'sec-ch-ua', operation: 'set', value: headers['sec-ch-ua'] },
                { header: 'sec-ch-ua-full-version-list', operation: 'set', value: headers['sec-ch-ua-full-version-list'] },
            ],
        },
        condition: {
            urlFilter: UA_RULE_URL_FILTER,
            resourceTypes: ['xmlhttprequest'],
        },
    };

    // removeRuleIds alongside addRules in the same call: idempotent if a
    // previous rule (another frame's challenge, or a cleanup that never
    // fired) is still sitting there.
    await chrome.declarativeNetRequest.updateSessionRules({ addRules: [rule], removeRuleIds: [UA_RULE_ID] });

    await note(`challenge: session rule live — the Incapsula POST will carry "${userAgent}"`);

    armUaRuleCleanup();
}

/**
 * Remove the rule once the Incapsula POST it exists for is done, or after a
 * flat timeout, whichever comes first.
 *
 * challenge.js cannot observe that POST itself — Imperva's own script fires
 * it from the page's MAIN world, and challenge.js is an isolated-world
 * content script, which has no view into functions the page's own script
 * calls. webRequest, however, watches the network layer underneath both
 * worlds, so the background CAN see it complete; that observation is on a
 * best-effort basis alongside the timeout, not instead of it, because
 * webRequest can also simply not fire (a request dropped by Imperva before
 * it reaches the network stack, a frame torn down mid-request).
 */
function armUaRuleCleanup() {
    clearUaRuleCleanup();

    const onSettled = () => removeUaRule('observed the Incapsula request settle');

    if (!uaRuleRequestListenerAdded) {
        const filter = { urls: ['*://www.menards.com/_Incapsula_Resource*'] };
        chrome.webRequest.onCompleted.addListener(onSettled, filter);
        chrome.webRequest.onErrorOccurred.addListener(onSettled, filter);
        uaRuleRequestListenerAdded = true;
    }

    uaRuleCleanupTimer = setTimeout(
        () => removeUaRule(`${UA_RULE_FALLBACK_MS / 1000}s fallback — no completion was observed`),
        UA_RULE_FALLBACK_MS,
    );
}

function clearUaRuleCleanup() {
    if (uaRuleCleanupTimer) {
        clearTimeout(uaRuleCleanupTimer);
        uaRuleCleanupTimer = null;
    }
}

/** Idempotent: safe to call with no rule present, and safe to call twice. */
async function removeUaRule(reason) {
    clearUaRuleCleanup();

    try {
        await chrome.declarativeNetRequest.updateSessionRules({ removeRuleIds: [UA_RULE_ID] });
        await note(`challenge: session rule removed (${reason})`);
    } catch (e) {
        await note(`challenge: removing the session rule failed: ${e.message}`);
    }
}

/**
 * challenge.js's two calls around injecting the token: add the rule before,
 * remove it after. Separate from the hive-solve-challenge listener above so
 * a pack that predates this still gets a token back from that one — this
 * listener answering `false` for every other message type is what lets both
 * listeners coexist.
 */
chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (msg?.type === 'hive-ua-rule-add') {
        (async () => {
            try {
                await addUaRule(msg.userAgent);
                sendResponse({ ok: true });
            } catch (err) {
                await note(`challenge: could not add the session rule: ${err.message}`);
                sendResponse({ ok: false, error: err.message });
            }
        })();

        return true;
    }

    if (msg?.type === 'hive-ua-rule-remove') {
        removeUaRule('challenge.js asked for cleanup').finally(() => sendResponse({ ok: true }));

        return true;
    }

    return false;
});

chrome.action.onClicked.addListener(() => run('manual'));

/**
 * Apply the Hive URL/token from the bundled defaults.json.
 *
 * defaults.json is written by the server from its own config on every
 * `menards:browser start`, which makes it the source of truth here — so it is
 * applied on every startup, not only when storage is empty. That is what lets a
 * rotated token or a corrected APP_URL reach the extension without someone
 * retyping it into an options page through a remote framebuffer. (The first
 * version seeded only into empty storage, which meant a fix to either value
 * silently never took effect on a browser that had already run once.)
 *
 * Values typed into the options page therefore survive only until the next
 * browser restart. On the server that is the intended order of authority; on a
 * dev profile with no defaults.json bundled, the options page remains the way in.
 */

/**
 * The pure merge behind seedDefaults(): defaults.json's values win over
 * whatever is already in storage, except in personal mode, where the
 * server's proxy credentials and its automatic-solving choice must never
 * apply — a personal browser has no proxy, and never buys a captcha solve.
 */
function nextSettings(defaults, current, personal) {
    const next = {
        serverUrl: defaults.serverUrl || current.serverUrl || '',
        token: defaults.token || current.token || '',
        solveChallenges: typeof defaults.solveChallenges === 'boolean' ? defaults.solveChallenges : current.solveChallenges === true,
        // The residential proxy's credentials (see onAuthRequired below);
        // null when the server has no proxy configured.
        proxy: defaults.proxy && defaults.proxy.host ? defaults.proxy : null,
    };

    if (personal) {
        next.solveChallenges = false;
        next.proxy = null;
    }

    return next;
}

async function seedDefaults() {
    try {
        const res = await fetch(chrome.runtime.getURL('defaults.json'));
        if (!res.ok) return;

        const d = await res.json();
        if (!d.serverUrl && !d.token) return;

        const personal = await personalMode();
        const current = await chrome.storage.local.get(['serverUrl', 'token', 'solveChallenges', 'proxy']);
        const next = nextSettings(d, current, personal);

        if (next.serverUrl !== current.serverUrl || next.token !== current.token || next.solveChallenges !== current.solveChallenges
            || JSON.stringify(next.proxy) !== JSON.stringify(current.proxy || null)) {
            await chrome.storage.local.set(next);
            await note(`applied configuration from defaults.json (posting to ${next.serverUrl}; automatic captcha solving ${next.solveChallenges ? 'on' : 'off'}; proxy ${next.proxy ? next.proxy.host : 'none'}${personal ? '; personal browser' : ''})`);
        }
    } catch {
        // No defaults bundled — the options page is the other way in.
    }
}

// Pure pieces, for tests (tests/personal.test.mjs) — no chrome calls inside.
globalThis.__hiveTest = { isPersonalPlatform, nextSettings };

/**
 * Answer the residential proxy's 407s on Chrome's behalf. `--proxy-server`
 * carries no credentials, and everything this browser sends to Menards (and
 * to hCaptcha, for the solver plugin) goes through that proxy. The
 * credentials arrive in defaults.json like the Hive token does. A request is
 * answered once: a second challenge for the same request means the
 * credentials are wrong, and cancelling beats looping on it.
 */
const answeredProxyAuth = new Set();

async function proxyAuthResponse(details) {
    if (!details.isProxy) return {};
    // A personal browser never has a proxy configured (nextSettings() keeps
    // storage clear of one), but answering nothing here too means a stray
    // proxy setting left over from a mode switch can never be used either.
    if (await personalMode()) return {};

    const { proxy } = await chrome.storage.local.get('proxy');
    if (!proxy || !proxy.username) return {};
    if (details.challenger && details.challenger.host && details.challenger.host !== proxy.host) return {};

    if (answeredProxyAuth.has(details.requestId)) {
        await note(`proxy ${proxy.host} refused the credentials — check CAPTCHA_PROXY_* on the server`);
        return { cancel: true };
    }
    answeredProxyAuth.add(details.requestId);
    if (answeredProxyAuth.size > 500) answeredProxyAuth.clear();

    return { authCredentials: { username: proxy.username, password: proxy.password } };
}

// An asyncBlocking listener answers through the callback Chrome hands it; a
// returned Promise is ignored, and the first version returned one — so Chrome
// put its own proxy sign-in dialog on screen and nothing loaded (2026-09-23).
chrome.webRequest.onAuthRequired.addListener(
    (details, asyncCallback) => {
        proxyAuthResponse(details).then(asyncCallback, () => asyncCallback({}));
    },
    { urls: ['<all_urls>'] },
    ['asyncBlocking'],
);

/**
 * A browser that was closed (a laptop shut over a weekend, the owner's Edge
 * quit for the night) catches up on restart rather than waiting for the next
 * scheduled trigger — the daily alarm drifts from whenever Chrome happens to
 * start, which on a personal browser could be days after the last success.
 */
const STARTUP_STALE_MS = 20 * 60 * 60 * 1000;

chrome.runtime.onStartup.addListener(async () => {
    await seedDefaults();

    const { lastSuccessAt } = await chrome.storage.local.get('lastSuccessAt');

    if (!lastSuccessAt || Date.now() - new Date(lastSuccessAt).getTime() > STARTUP_STALE_MS) {
        run('startup');
    }
});

chrome.runtime.onInstalled.addListener(async () => {
    await seedDefaults();

    // A once-daily BACKSTOP, not the schedule.
    //
    // Runs are normally started by Laravel (`menards:browser sync`, four times a
    // day at 08:00/12:00/16:00/20:00 Central), because a schedule that lives in
    // routes/console.php is visible in `schedule:list`, logged, and changeable
    // without repacking and reinstalling this extension. This alarm exists so
    // that a broken cron does not mean silence — it drifts from whenever Chrome
    // last started, which is exactly why it is not the primary trigger.
    //
    // The importer is idempotent, so an extra run costs nothing but a few
    // requests.
    await chrome.alarms.create(ALARM, { periodInMinutes: 60 * 24, delayInMinutes: 2 });
});

chrome.alarms.onAlarm.addListener(a => {
    if (a.name === ALARM) run('scheduled');
});
