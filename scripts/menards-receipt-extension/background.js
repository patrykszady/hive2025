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

/** A first run should backfill history rather than only the recent window. */
const FIRST_RUN_LOOKBACK_DAYS = 365;

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

    if (existing.length) return { tab: existing[0], opened: false };

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

    return { tab, opened: true };
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

async function run(reason) {
    const { everSucceeded } = await settings();
    const since = sinceDate(everSucceeded ? DEFAULT_LOOKBACK_DAYS : FIRST_RUN_LOOKBACK_DAYS);

    await note(`run start (${reason}), since ${since}`);

    let opened = false;
    let tab = null;

    try {
        ({ tab, opened } = await receiptTab());

        const result = await chrome.tabs.sendMessage(tab.id, { action: 'sync', since });

        if (!result?.ok) throw new Error(result?.error || 'content script returned no result');

        if (result.receipts.length === 0) {
            await note(`no receipts on or after ${since} — nothing to send`);
            await chrome.storage.local.set({ lastSuccessAt: new Date().toISOString(), everSucceeded: true, lastError: null });

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

        await note(`sent ${result.receipts.length} receipts; Hive imported ${hive.imported ?? '?'}`
            + (result.errors.length ? `; ${result.errors.length} failed to download` : ''));
    } catch (err) {
        await chrome.storage.local.set({ lastError: err.message });
        await note(`FAILED: ${err.message}`);
    } finally {
        // Only close what we opened — never a tab a person left open.
        if (opened && tab?.id) await chrome.tabs.remove(tab.id).catch(() => {});
    }
}

chrome.runtime.onMessage.addListener(msg => {
    if (msg?.action === 'progress') note(msg.text);
    if (msg?.action === 'run') run('options page');
});

chrome.action.onClicked.addListener(() => run('manual'));

/**
 * Seed the Hive URL/token from a bundled defaults.json when storage is empty.
 *
 * The browser this runs in lives on a server and is reached only over VNC, so
 * making someone hand-type a 48-character token into an options page through a
 * remote framebuffer is a poor first-run experience. Anything already in storage
 * wins, so the options page still overrides this.
 */
async function seedDefaults() {
    const current = await chrome.storage.local.get(['serverUrl', 'token']);

    if (current.serverUrl && current.token) return;

    try {
        const res = await fetch(chrome.runtime.getURL('defaults.json'));
        if (!res.ok) return;

        const d = await res.json();

        await chrome.storage.local.set({
            serverUrl: current.serverUrl || d.serverUrl || '',
            token: current.token || d.token || '',
        });

        await note('seeded configuration from defaults.json');
    } catch {
        // No defaults bundled — the options page is the other way in.
    }
}

chrome.runtime.onStartup.addListener(seedDefaults);

chrome.runtime.onInstalled.addListener(async () => {
    await seedDefaults();

    // Daily. The server-side importer is idempotent, so a missed day is covered
    // by the next run's lookback window rather than needing a catch-up.
    await chrome.alarms.create(ALARM, { periodInMinutes: 60 * 24, delayInMinutes: 2 });
});

chrome.alarms.onAlarm.addListener(a => {
    if (a.name === ALARM) run('scheduled');
});
