/**
 * EWCCV Session Bridge — service worker.
 *
 * ewccv.com's search API is gated by reCAPTCHA v3 Enterprise. Hive's server
 * cannot pass it: provider-solved tokens (anti-captcha, 2captcha) and tokens
 * minted by EWCCV's own page inside an automated Chrome are all rejected —
 * verified as *structurally valid* but scored too low, which no solver fixes.
 * Your browser passes it without trying, because you are a real person on a
 * residential connection.
 *
 * So this worker asks the EWCCV tab for the accessToken the site issues after
 * a successful verification, and posts it to Hive, which uses it for the
 * searches. Same play as the Yelp session bridge on gs.construction.
 *
 * The fetch runs HERE rather than in the content script: service-worker
 * requests to a host in `host_permissions` skip CORS preflight entirely, so no
 * server-side CORS config is needed.
 *
 * The credential lives minutes and covers ~25 searches, so it is minted on
 * demand: while an ewccv.com tab is open this worker asks Hive once a minute
 * whether a lookup is waiting, and mints only then (and on every page load,
 * when something is waiting). The button always sends. A pinned ewccv.com
 * tab therefore keeps Hive supplied without anyone pressing anything.
 */

const DEFAULTS = {
    serverUrl: 'https://hive.contractors',
    token: '',
    enabled: true,
};

/** Ask Hive for pending work this often while an ewccv.com tab is open. */
const POLL_ALARM = 'poll-ewccv-pending';
const POLL_MINUTES = 1;

/**
 * After a mint fails (reCAPTCHA refused the page's attempt) or Hive refuses
 * the page's own copy as already spent, the automatic path waits this long
 * before trying again: every attempt is another reCAPTCHA execution, and a
 * minute-by-minute drumbeat of them only lowers the score further. A page
 * load (a search by hand) or the button clears the wait.
 */
const BACKOFF_MINUTES = 10;
let backoffUntil = 0;
let lastRefusedToken = null;

async function settings() {
    return { ...DEFAULTS, ...(await chrome.storage.sync.get(Object.keys(DEFAULTS))) };
}

async function setStatus(status) {
    await chrome.storage.local.set({ lastStatus: { ...status, at: new Date().toISOString() } });
}

/** The EWCCV tab to talk to, if one is open. */
async function findEwccvTab() {
    const tabs = await chrome.tabs.query({ url: 'https://www.ewccv.com/*' });
    // Prefer the search page — that is where the app has its state loaded.
    return tabs.find((t) => t.url.includes('/cvs/search')) || tabs[0] || null;
}

async function mintFromTab(tabId) {
    try {
        return await chrome.tabs.sendMessage(tabId, { type: 'hive-ewccv-mint' });
    } catch (e) {
        return { ok: false, error: 'Could not reach the EWCCV tab: ' + e.message };
    }
}

/** What Hive is waiting for: { pending, running, session } or null when unreachable. */
async function pendingWork(cfg) {
    try {
        const res = await fetch(`${cfg.serverUrl.replace(/\/+$/, '')}/api/ewccv/pending`, {
            headers: { Accept: 'application/json', Authorization: `Bearer ${cfg.token}` },
        });
        if (!res.ok) return null;
        return await res.json();
    } catch {
        return null;
    }
}

/**
 * The tab is logged out: sign it in with the link Hive holds (the server
 * obtains EWCCV's emailed sign-in link for its own runs). When Hive has none
 * yet it requests one by email, and the next poll tries again. The page
 * load that follows triggers the usual pending check, which mints.
 */
async function signInTab(cfg, tab) {
    let body = null;
    try {
        const res = await fetch(`${cfg.serverUrl.replace(/\/+$/, '')}/api/ewccv/sign-in-link`, {
            headers: { Accept: 'application/json', Authorization: `Bearer ${cfg.token}` },
        });
        body = res.ok ? await res.json() : null;
    } catch {
        body = null;
    }
    if (!body?.url) {
        const r = { ok: false, error: body?.message || 'The EWCCV tab is logged out and Hive has no sign-in link yet; it is requesting one.' };
        await setStatus(r);
        return r;
    }
    await setStatus({ ok: true, message: 'Signing the EWCCV tab in with a link from Hive…' });
    await chrome.tabs.update(tab.id, { url: body.url });
    return { ok: true, message: 'Signing in…' };
}

/**
 * The automatic path: mint and send only when Hive has a lookup waiting and
 * no run is on. Anything else would spend a credential for nothing.
 */
async function pushIfPending() {
    const cfg = await settings();
    if (!cfg.enabled || !cfg.token) return;
    if (!(await findEwccvTab())) return;
    if (Date.now() < backoffUntil) return;

    const work = await pendingWork(cfg);
    if (!work) return;
    if (!work.pending) {
        await setStatus({ ok: true, message: 'Nothing waiting at Hive; a credential is minted when a lookup is.' });
        return;
    }
    if (work.running) {
        await setStatus({ ok: true, message: `Hive is running a lookup (${work.pending} waiting); the next one is minted when it ends.` });
        return;
    }
    await push();
}

async function push({ interactive = false } = {}) {
    const cfg = await settings();

    if (!cfg.enabled) return { ok: false, error: 'Bridge is turned off.' };
    if (!cfg.token) return { ok: false, error: 'No token set — open the extension options.' };

    const tab = await findEwccvTab();
    if (!tab) {
        const r = { ok: false, error: 'No ewccv.com tab open. Open www.ewccv.com/cvs/search and sign in.' };
        await setStatus(r);
        if (interactive) notify(r);
        return r;
    }

    const minted = await mintFromTab(tab.id);
    if (minted && minted.signedIn === false) {
        return signInTab(cfg, tab);
    }
    if (!minted?.ok && /reCAPTCHA is not loaded/.test(minted?.error || '') && !/\/cvs\/search/.test(tab.url || '')) {
        // Signed in but not on the search page (just landed from the link): go there; the load mints.
        await chrome.tabs.update(tab.id, { url: 'https://www.ewccv.com/cvs/search' });
        return { ok: true, message: 'Opening the search page…' };
    }
    if (!minted?.ok) {
        const r = { ok: false, error: minted?.error || 'Could not read the EWCCV session.' };
        await setStatus(r);
        if (interactive) notify(r);
        if (!interactive) backoffUntil = Date.now() + BACKOFF_MINUTES * 60000;
        return r;
    }

    // The page's own copy, already refused once: sending it again changes
    // nothing. Wait for a fresh one (a search by hand produces it).
    if (!interactive && minted.reused && minted.accessToken === lastRefusedToken) {
        backoffUntil = Date.now() + BACKOFF_MINUTES * 60000;
        const r = { ok: false, error: 'EWCCV did not mint a fresh credential and the page\'s copy was already used. Do one search on ewccv.com by hand; it is picked up within a minute.' };
        await setStatus(r);
        return r;
    }

    let res;
    try {
        res = await fetch(`${cfg.serverUrl.replace(/\/+$/, '')}/api/ewccv/session`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${cfg.token}` },
            body: JSON.stringify({
                accessToken: minted.accessToken,
                jwt: minted.jwt || null,
                stateToken: minted.stateToken || null,
                // Hive refuses a reused (already spent) copy and says what to do.
                reused: !!minted.reused,
            }),
        });
    } catch (e) {
        const r = { ok: false, error: `Could not reach Hive: ${e.message}` };
        await setStatus(r);
        if (interactive) notify(r);
        return r;
    }

    let body = {};
    try { body = await res.json(); } catch { /* non-JSON error page */ }

    const result = res.ok
        ? { ok: true, message: body.message || 'EWCCV session sent to Hive.' }
        : { ok: false, error: body.error || `Hive returned ${res.status}.` };

    if (res.status === 409) {
        lastRefusedToken = minted.accessToken;
        if (!interactive) backoffUntil = Date.now() + BACKOFF_MINUTES * 60000;
    }

    await setStatus(result);
    if (interactive) notify(result);

    return result;
}

function notify(result) {
    chrome.notifications.create({
        type: 'basic',
        iconUrl: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciLz4=',
        title: result.ok ? 'EWCCV session sent' : 'EWCCV session not sent',
        message: result.ok ? result.message : result.error,
    });
}

chrome.runtime.onInstalled.addListener(() => {
    chrome.alarms.create(POLL_ALARM, { periodInMinutes: POLL_MINUTES });
});

chrome.runtime.onStartup.addListener(() => {
    chrome.alarms.create(POLL_ALARM, { periodInMinutes: POLL_MINUTES });
});

chrome.alarms.onAlarm.addListener((alarm) => {
    if (alarm.name === POLL_ALARM) pushIfPending();
});

// An EWCCV page just loaded: if Hive has a lookup waiting, this is the moment
// to mint — the page is fresh and the person is right there.
chrome.tabs.onUpdated.addListener((_tabId, info, tab) => {
    if (info.status === 'complete' && tab.url?.startsWith('https://www.ewccv.com/')) {
        backoffUntil = 0;
        setTimeout(() => pushIfPending(), 4000);
    }
});

chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (msg?.type === 'hive-ewccv-push-now') {
        backoffUntil = 0;
        push({ interactive: true }).then(sendResponse);
        return true;
    }
});
