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
function load({ tabs = {}, session = {} } = {}) {
    const notes = [];
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
        fetch: async () => ({ ok: true, status: 200, json: async () => ({ received: 0 }), text: async () => '' }),
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
                getURL: (p) => `chrome-extension://test/${p}`, sendMessage() {}, getManifest: () => ({ version: 'test' }),
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
            windows: { getAll: async () => [{ id: 1 }], create: async () => ({ id: 2, tabs: [{ id: 9 }] }), update: async () => {}, remove: async () => {} },
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

    return { hooks: sandbox.__hiveTest, notes, sessionStore, local };
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
