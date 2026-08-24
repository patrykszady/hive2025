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

async function settings() {
    const s = await chrome.storage.local.get(['serverUrl', 'token', 'lastSuccessAt', 'everSucceeded']);

    return {
        serverUrl: (s.serverUrl || '').replace(/\/+$/, ''),
        token: s.token || '',
        lastSuccessAt: s.lastSuccessAt || null,
        everSucceeded: !!s.everSucceeded,
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
async function receiptTab() {
    const existing = await chrome.tabs.query({ url: 'https://www.menards.com/main/receiptLookup.html*' });

    if (existing.length) {
        await assertOnReceiptPage(existing[0].id);

        return { tab: existing[0], opened: false };
    }

    const tab = await chrome.tabs.create({ url: RECEIPT_URL, active: false });

    // Wait for the content script to be live before messaging it.
    await new Promise(resolve => {
        const listener = (id, info) => {
            if (id === tab.id && info.status === 'complete') {
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

    // The SPA still has to boot after 'complete'.
    await new Promise(r => setTimeout(r, 4000));

    await assertOnReceiptPage(tab.id);

    return { tab, opened: true };
}

/**
 * Menards bounces a signed-out request for the receipt page to login.html, where
 * our content script does not run — so messaging it fails with Chrome's opaque
 * "Could not establish connection. Receiving end does not exist." Checking where
 * the tab actually landed turns that into the one thing the operator can act on.
 */
async function assertOnReceiptPage(tabId) {
    const current = await chrome.tabs.get(tabId).catch(() => null);
    const url = current?.url || '';

    if (!url.includes('/main/receiptLookup.html')) {
        throw new Error(
            `Not signed in to menards.com — the receipt page redirected to ${url || 'an unknown page'}. `
            + 'Sign in once over noVNC; the profile keeps the session.'
        );
    }
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
        body: JSON.stringify(payload),
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
            body: JSON.stringify({ ok, error: error || null, receipts: receipts ?? null }),
        });
    } catch (e) {
        // Swallowed on purpose — see the docblock.
    }
}

async function run(reason) {
    const { everSucceeded } = await settings();
    const since = everSucceeded ? sinceDate(DEFAULT_LOOKBACK_DAYS) : BACKFILL_SINCE;

    await note(`run start (${reason}), since ${since}`);

    let opened = false;
    let tab = null;

    try {
        ({ tab, opened } = await receiptTab());

        const result = await sendSync(tab.id, since);

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
        // Only close what we opened — never a tab a person left open.
        if (opened && tab?.id) await chrome.tabs.remove(tab.id).catch(() => {});
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
            const { serverUrl, token } = await settings();
            if (!serverUrl || !token) throw new Error('Hive URL/token not configured');

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

            await note('challenge: token received, injecting');
            sendResponse({ ok: true, token: body.token });
        } catch (err) {
            await note(`challenge: ${err.message}`);
            sendResponse({ ok: false, error: err.message });
        }
    })();

    return true;
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
async function seedDefaults() {
    try {
        const res = await fetch(chrome.runtime.getURL('defaults.json'));
        if (!res.ok) return;

        const d = await res.json();
        if (!d.serverUrl && !d.token) return;

        const current = await chrome.storage.local.get(['serverUrl', 'token']);
        const next = {
            serverUrl: d.serverUrl || current.serverUrl || '',
            token: d.token || current.token || '',
        };

        if (next.serverUrl !== current.serverUrl || next.token !== current.token) {
            await chrome.storage.local.set(next);
            await note(`applied configuration from defaults.json (posting to ${next.serverUrl})`);
        }
    } catch {
        // No defaults bundled — the options page is the other way in.
    }
}

chrome.runtime.onStartup.addListener(seedDefaults);

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
