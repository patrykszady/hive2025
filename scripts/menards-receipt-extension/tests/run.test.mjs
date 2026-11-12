// One run at a time, a second try when the tab vanishes, and the fetch that
// follows an automatic sign-in — the three ways personal-browser runs failed
// on 2026-11-11. Same approach as personal.test.mjs: the real background.js
// in a vm context behind a chrome stub, with the waits shortened.
//   node --test scripts/menards-receipt-extension/tests/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(dir, '..', 'background.js'), 'utf8');

const RECEIPT_URL = 'https://www.menards.com/main/receiptLookup.html';

/**
 * @param {object} tabs  overrides for chrome.tabs
 * @param {object} session  what chrome.storage.session starts with
 */
function load({ tabs = {}, session = {}, diskVersion = 'test', windows = {} } = {}) {
    const notes = [];
    const reloads = [];
    const local = { personal: 'personal', serverUrl: 'https://hive.test', token: 't' };
    const sessionStore = { ...session };
    const listenable = () => ({ addListener() {}, removeListener() {} });

    const get = (store) => async (keys) => {
        if (keys == null) return { ...store };
        const out = {};
        for (const k of (Array.isArray(keys) ? keys : [keys])) if (k in store) out[k] = store[k];
        return out;
    };
    const remove = (store) => async (keys) => { for (const k of (Array.isArray(keys) ? keys : [keys])) delete store[k]; };

    const sandbox = {
        console: { log() {}, warn() {}, error() {} },
        // Every wait in the source, shortened: the logic is what is under test.
        setTimeout: (fn, ms) => setTimeout(fn, Math.min(ms, 2)),
        clearTimeout, Date, Promise, Map, Set, Object, Error, JSON, Array, String, Number, Math, RegExp, Intl,
        navigator: { userAgent: 'test-agent', userAgentData: undefined, platform: 'Win32' },
        // The extension's own manifest as the folder holds it now; anything else is Hive.
        fetch: async (url) => String(url).startsWith('chrome-extension://')
            ? { ok: true, status: 200, json: async () => { if (diskVersion instanceof Error) throw diskVersion; return { version: diskVersion }; } }
            : { ok: true, status: 200, json: async () => ({ received: 0 }), text: async () => '' },
        chrome: {
            storage: {
                session: { get: get(sessionStore), set: async (o) => { Object.assign(sessionStore, o); }, remove: remove(sessionStore) },
                local: {
                    get: get(local),
                    set: async (o) => { if (o.lastMessage) notes.push(o.lastMessage.replace(/^\S+ /, '')); Object.assign(local, o); },
                    remove: remove(local),
                },
                onChanged: listenable(),
            },
            alarms: { create: async () => {}, clear: async () => {}, get: async () => null, onAlarm: listenable() },
            runtime: {
                onMessage: listenable(), onStartup: listenable(), onInstalled: listenable(),
                getURL: (p) => `chrome-extension://test/${p}`, sendMessage() {}, getManifest: () => ({ version: 'test' }), reload: () => { reloads.push(true); },
                getPlatformInfo: async () => ({ os: 'win' }),
            },
            action: { onClicked: listenable() },
            webRequest: { onAuthRequired: listenable(), onCompleted: listenable(), onErrorOccurred: listenable() },
            declarativeNetRequest: { updateSessionRules: async () => {} },
            proxy: { settings: { set: async () => {}, clear: async () => {} } },
            tabs: {
                query: async () => [],
                get: async (id) => ({ id, status: 'complete', url: RECEIPT_URL, windowId: 1 }),
                reload: async () => {},
                create: async () => ({ id: 1 }),
                remove: async () => {},
                sendMessage: async () => ({ ok: true, receipts: [], skipped: 0, errors: [] }),
                update: async () => {},
                // A load reports "complete" at once.
                onUpdated: { addListener(fn) { setTimeout(() => fn(sandbox.__lastTab, { status: 'complete' }), 1); }, removeListener() {} },
                onRemoved: listenable(),
                ...tabs,
            },
            windows: { getAll: async () => [{ id: 1 }], create: async () => ({ id: 2, tabs: [{ id: 9 }] }), update: async () => {}, remove: async () => {}, ...windows },
            scripting: { executeScript: async () => {} },
        },
    };

    // waitForLoad() listens for one tab id; hand it whichever tab was touched last.
    for (const name of ['reload', 'create']) {
        const original = sandbox.chrome.tabs[name];
        sandbox.chrome.tabs[name] = async (...args) => {
            const result = await original(...args);
            sandbox.__lastTab = name === 'create' ? result.id : args[0];
            return result;
        };
    }

    vm.runInContext(source, vm.createContext(sandbox), { filename: 'background.js' });

    return { hooks: sandbox.__hiveTest, notes, sessionStore, local, reloads };
}

test('two requests arriving together start one run, not two', async () => {
    const { hooks, notes } = load();

    await Promise.all([hooks.run('signed in'), hooks.run('signed in')]);

    assert.equal(notes.filter((n) => n.startsWith('run start')).length, 1, notes.join(' | '));
    assert.equal(notes.filter((n) => n.includes('already in progress')).length, 1);
    assert.equal(hooks.runState(), null, 'the run is released when it ends');
});

test('a run that is skipped as too soon releases its claim', async () => {
    const { hooks, notes, local } = load();
    local.lastRunStartedAt = new Date().toISOString();

    await hooks.run('scheduled');
    assert.ok(notes.some((n) => n.includes('skipping the scheduled request')), notes.join(' | '));
    assert.equal(hooks.runState(), null);

    await hooks.run('manual');
    assert.ok(notes.some((n) => n.startsWith('run start (manual)')));
});

test('a tab that vanishes mid-run gets a fresh tab and a second try', async () => {
    const created = [];
    const { hooks, notes } = load({
        tabs: {
            query: async () => [{ id: 7 }],
            reload: async (id) => { if (id === 7) throw new Error('No tab with id: 7.'); },
            create: async () => { created.push(8); return { id: 8 }; },
        },
    });

    await hooks.run('scheduled');

    assert.ok(notes.some((n) => n.includes('went away (No tab with id: 7.)')), notes.join(' | '));
    assert.deepEqual(created, [8]);
    assert.ok(notes.some((n) => n.startsWith('no new receipts')), 'the second try finished the run: ' + notes.join(' | '));
    assert.ok(!notes.some((n) => n.startsWith('FAILED')));
});

test('any other failure is still a failure, without a second tab', async () => {
    const created = [];
    const { hooks, notes } = load({
        tabs: {
            create: async () => { created.push(1); return { id: 1 }; },
            sendMessage: async () => ({ ok: false, error: 'HTTP 500 from the receipt list' }),
        },
    });

    await hooks.run('scheduled');

    assert.ok(notes.some((n) => n.startsWith('FAILED: HTTP 500')), notes.join(' | '));
    assert.equal(created.length, 1);
    assert.equal(hooks.tabIsGone(new Error('No tab with id: 12.')), true);
    assert.equal(hooks.tabIsGone(new Error('Receiving end does not exist.')), false);
});

test('half a minute after an automatic sign-in: signed in but nobody noticed → fetch now', async () => {
    const { hooks, notes, sessionStore } = load({ session: { waitingForSignInTab: 5 } });

    await hooks.checkAutoSignInOutcome();
    await new Promise((r) => setTimeout(r, 60));

    assert.ok(notes.some((n) => n.startsWith('signed in on the tab that was waiting')), notes.join(' | '));
    assert.equal('waitingForSignInTab' in sessionStore, false);
    assert.equal(notes.filter((n) => n.startsWith('run start (signed in)')).length, 1);
});

test('half a minute after an automatic sign-in: the tab is gone → stop waiting on it', async () => {
    const { hooks, notes, sessionStore } = load({
        session: { waitingForSignInTab: 5 },
        tabs: { get: async () => { throw new Error('No tab with id: 5.'); } },
    });

    await hooks.checkAutoSignInOutcome();

    assert.equal('waitingForSignInTab' in sessionStore, false);
    assert.equal(notes.length, 0);
});

test('new files in the folder: the next run reloads the extension instead of fetching', async () => {
    const { hooks, notes, reloads } = load({ diskVersion: '9.9.9' });

    await hooks.run('scheduled');

    assert.equal(reloads.length, 1);
    assert.ok(notes.some((n) => n.includes('the folder now holds 9.9.9 (running test)')), notes.join(' | '));
    assert.ok(!notes.some((n) => n.startsWith('run start')), 'no fetch with the old code');
    assert.equal(hooks.runState(), null);
});

test('the same version on disk, or a manifest caught mid-copy, never reloads', async () => {
    for (const diskVersion of ['test', new SyntaxError('Unexpected end of JSON input')]) {
        const { hooks, reloads, notes } = load({ diskVersion });

        await hooks.run('scheduled');

        assert.equal(reloads.length, 0);
        assert.ok(notes.some((n) => n.startsWith('run start')), notes.join(' | '));
    }
});

// ── Staying signed in ───────────────────────────────────────────────────────

test('keep-alive interval from Hive: missing → 30, 0 → off, never under 10', () => {
    const { keepAliveMinutesFrom } = load().hooks;

    assert.equal(keepAliveMinutesFrom(undefined), 30);
    assert.equal(keepAliveMinutesFrom('nonsense'), 30);
    assert.equal(keepAliveMinutesFrom(0), 0);
    assert.equal(keepAliveMinutesFrom(-5), 0);
    assert.equal(keepAliveMinutesFrom(3), 10);
    assert.equal(keepAliveMinutesFrom(45), 45);
});

test('keep-alive slots fall on the clock, and it is due only when nothing ran lately', () => {
    const { nextSlot, keepAliveDue } = load().hooks;
    const at = (iso) => new Date(iso).getTime();

    assert.equal(nextSlot(at('2026-11-12T01:44:10Z'), 30), at('2026-11-12T02:00:00Z'));
    assert.equal(nextSlot(at('2026-11-12T02:00:00Z'), 30), at('2026-11-12T02:30:00Z'));
    assert.equal(nextSlot(at('2026-11-12T02:10:00Z'), 30), at('2026-11-12T02:30:00Z'));

    const now = at('2026-11-12T02:30:50Z');
    assert.equal(keepAliveDue('2026-11-12T02:00:20Z', 30, now), true, 'the hourly check was half an hour ago');
    assert.equal(keepAliveDue('2026-11-12T02:30:20Z', 30, now), false, 'a check just ran');
    assert.equal(keepAliveDue('2026-11-12T02:12:00Z', 30, now), false, 'a manual check 19 min ago keeps the session fresh enough');
    assert.equal(keepAliveDue(null, 30, now), true);
    assert.equal(keepAliveDue('2026-11-12T00:00:00Z', 0, now), false, 'off');
});

test('the keep-alive runs a check when one is due, and not when one just ran', async () => {
    const first = load();
    first.local.lastRunStartedAt = new Date(Date.now() - 31 * 60000).toISOString();
    await first.hooks.keepAlive();
    assert.ok(first.notes.some((n) => n.startsWith('run start (keep-alive)')), first.notes.join(' | '));

    const second = load();
    second.local.lastRunStartedAt = new Date(Date.now() - 4 * 60000).toISOString();
    await second.hooks.keepAlive();
    assert.equal(second.notes.length, 0);

    const off = load();
    off.local.schedule = { keepAliveMinutes: 0 };
    await off.hooks.keepAlive();
    assert.equal(off.notes.length, 0);
});

test('a keep-alive that finds the browser signed out never pulls a window forward', async () => {
    const focused = [];
    const signedOut = {
        tabs: {
            get: async (id) => ({ id, status: 'complete', url: 'https://www.menards.com/main/login.html', windowId: 1 }),
            update: async (id, props) => { if (props.active) focused.push(['tab', id]); },
        },
        windows: { update: async (id, props) => { if (props.focused) focused.push(['window', id]); } },
    };

    // Sign in by hand (no saved credentials): the keep-alive only says so…
    const quiet = load(signedOut);
    quiet.local.lastRunStartedAt = new Date(Date.now() - 40 * 60000).toISOString();
    await quiet.hooks.keepAlive();
    assert.ok(quiet.notes.some((n) => n.includes('found by the keep-alive')), quiet.notes.join(' | '));
    assert.deepEqual(focused, []);

    // …and the scheduled check is the one that brings the tab forward.
    const loud = load(signedOut);
    await loud.hooks.run('scheduled');
    assert.ok(focused.some(([what]) => what === 'tab'), 'the scheduled check still surfaces the sign-in tab');
});

test('a keep-alive sign-in that does not get through leaves the screen alone', async () => {
    const focused = [];
    const { hooks, notes } = load({
        session: { waitingForSignInTab: 5, waitingQuietly: true },
        tabs: {
            get: async (id) => ({ id, url: 'https://www.menards.com/main/login.html', windowId: 1 }),
            update: async (id, props) => { if (props.active) focused.push(id); },
        },
    });

    await hooks.checkAutoSignInOutcome();

    assert.deepEqual(focused, []);
    assert.ok(notes.some((n) => n.includes('leaving the tab for the next scheduled check')), notes.join(' | '));
});

test('the keep-alive stands down after a failed check, and resumes after a good one', async () => {
    const { hooks, notes, local } = load();
    local.lastRunStartedAt = new Date(Date.now() - 45 * 60000).toISOString();
    local.lastError = 'Not signed in to menards.com — signing in automatically; the receipts follow by themselves.';

    await hooks.keepAlive();
    assert.equal(notes.length, 0, 'no run while the last check failed');

    await hooks.run('scheduled');
    assert.equal(local.lastError, null, 'a good check clears the error');

    local.lastRunStartedAt = new Date(Date.now() - 45 * 60000).toISOString();
    await hooks.keepAlive();
    assert.ok(notes.some((n) => n.startsWith('run start (keep-alive)')), notes.join(' | '));
});
