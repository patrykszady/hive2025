// Personal-mode detection and defaults merging, exercised the same way
// bridge.test.mjs and challenge.test.mjs do: load the real source into a vm
// context behind a minimal chrome stub, never Chrome itself.
//   node --test scripts/menards-receipt-extension/tests/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const extDir = path.join(dir, '..');
const source = fs.readFileSync(path.join(extDir, 'background.js'), 'utf8');
const manifest = JSON.parse(fs.readFileSync(path.join(extDir, 'manifest.json'), 'utf8'));

/**
 * A chrome stub just thorough enough for background.js to load top to
 * bottom: every addListener call simply records itself, matching how the
 * real module registers its handlers once and never calls them during load.
 */
function recorder(calls) {
    return (...args) => { calls.push(args); };
}

function loadBackground() {
    const calls = [];

    const listenable = () => ({ addListener: recorder(calls), removeListener: recorder(calls) });

    const store = {};

    const sandbox = {
        console: { log() {}, warn() {}, error() {} },
        setTimeout, clearTimeout, Date, Promise, Map, Set, Object, Error, JSON, Array, String, Number,
        navigator: { userAgent: 'test-agent', userAgentData: undefined },
        fetch: async () => { throw new Error('fetch not stubbed for this test'); },
        chrome: {
            storage: {
                session: { get: async () => ({}), set: async () => {}, remove: async () => {} },
                local: {
                    get: async (keys) => {
                        if (keys == null) return { ...store };
                        const list = Array.isArray(keys) ? keys : [keys];
                        const out = {};
                        for (const k of list) if (k in store) out[k] = store[k];
                        return out;
                    },
                    set: async (obj) => { Object.assign(store, obj); },
                    remove: async (keys) => {
                        for (const k of (Array.isArray(keys) ? keys : [keys])) delete store[k];
                    },
                },
                onChanged: listenable(),
            },
            alarms: { create: recorder(calls), onAlarm: listenable() },
            runtime: {
                onMessage: listenable(),
                onStartup: listenable(),
                onInstalled: listenable(),
                getURL: (p) => `chrome-extension://test/${p}`,
                sendMessage: recorder(calls),
            },
            action: { onClicked: listenable() },
            webRequest: {
                onAuthRequired: listenable(),
                onCompleted: listenable(),
                onErrorOccurred: listenable(),
            },
            declarativeNetRequest: { updateSessionRules: async () => {} },
            tabs: {
                query: async () => [],
                get: async () => null,
                reload: async () => {},
                create: async () => ({ id: 1 }),
                remove: async () => {},
                sendMessage: async () => {},
                update: async () => {},
                onUpdated: listenable(),
                onRemoved: listenable(),
            },
            windows: { update: async () => {} },
            scripting: { executeScript: async () => {} },
        },
    };

    const ctx = vm.createContext(sandbox);
    vm.runInContext(source, ctx, { filename: 'background.js' });

    return { sandbox, calls };
}

test('background.js loads cleanly and exposes the pure test hooks', () => {
    const { sandbox } = loadBackground();

    assert.ok(sandbox.__hiveTest, 'globalThis.__hiveTest was not set');
    assert.equal(typeof sandbox.__hiveTest.isPersonalPlatform, 'function');
    assert.equal(typeof sandbox.__hiveTest.nextSettings, 'function');
});

test('isPersonalPlatform: Windows and macOS are personal, Linux is not', () => {
    const { isPersonalPlatform } = loadBackground().sandbox.__hiveTest;

    assert.equal(isPersonalPlatform('Win32'), true);
    assert.equal(isPersonalPlatform('Linux x86_64'), false);
    assert.equal(isPersonalPlatform('macOS'), true);
});

test('nextSettings: personal mode drops the proxy and forces solving off, whatever defaults.json says', () => {
    const { nextSettings } = loadBackground().sandbox.__hiveTest;

    const defaults = {
        serverUrl: 'https://hive.contractors',
        token: 'server-token',
        solveChallenges: true,
        proxy: { host: 'proxy.example:8080', username: 'u', password: 'p' },
    };

    const personal = nextSettings(defaults, {}, true);

    assert.equal(personal.serverUrl, 'https://hive.contractors');
    assert.equal(personal.token, 'server-token');
    assert.equal(personal.solveChallenges, false);
    assert.equal(personal.proxy, null);
});

test('nextSettings: server mode keeps the proxy and whatever solveChallenges defaults.json sends', () => {
    const { nextSettings } = loadBackground().sandbox.__hiveTest;

    const defaults = {
        serverUrl: 'https://hive.contractors',
        token: 'server-token',
        solveChallenges: true,
        proxy: { host: 'proxy.example:8080', username: 'u', password: 'p' },
    };

    const server = nextSettings(defaults, {}, false);

    assert.equal(server.solveChallenges, true);
    assert.deepEqual(server.proxy, { host: 'proxy.example:8080', username: 'u', password: 'p' });
});

test('nextSettings: falls back to whatever is already in storage when defaults.json omits a field', () => {
    const { nextSettings } = loadBackground().sandbox.__hiveTest;

    const current = { serverUrl: 'https://old.example', token: 'old-token', solveChallenges: true, proxy: null };
    const next = nextSettings({}, current, false);

    assert.equal(next.serverUrl, 'https://old.example');
    assert.equal(next.token, 'old-token');
    assert.equal(next.solveChallenges, true);
    assert.equal(next.proxy, null);
});

test('manifest declares no new permission for the personal sign-in flow (tabs/windows need none for update)', () => {
    assert.ok(Array.isArray(manifest.permissions));
    assert.ok(!manifest.permissions.includes('tabs'), 'tabs permission is not needed for tabs.update/active');
    assert.ok(!manifest.permissions.includes('windows'), 'windows permission is not needed for windows.update');
});

test('reportStatus() and postToHive() both post a personal field to Hive', () => {
    const reportBody = source.slice(source.indexOf('async function reportStatus('), source.indexOf('async function syncWithOneReload('));
    assert.match(reportBody, /personal:\s*await personalMode\(\)/, 'reportStatus does not send personal');
    assert.match(reportBody, /browser:\s*browserDescription\(\)/, 'reportStatus does not send browser');

    const postBody = source.slice(source.indexOf('async function postToHive('), source.indexOf('async function reportStatus('));
    assert.match(postBody, /personal:\s*await personalMode\(\)/, 'postToHive does not send personal');
});

test('the hourly window is 7:00–18:59 in the schedule zone, and an empty window means always', () => {
    const { withinWindow } = loadBackground().sandbox.__hiveTest;
    const sched = { windowStartHour: 7, windowEndHour: 19, timeZone: 'UTC' };
    const at = (h) => new Date(Date.UTC(2026, 9, 29, h, 30));

    assert.equal(withinWindow(sched, at(6)), false);
    assert.equal(withinWindow(sched, at(7)), true);
    assert.equal(withinWindow(sched, at(18)), true);
    assert.equal(withinWindow(sched, at(19)), false);
    assert.equal(withinWindow({ windowStartHour: 0, windowEndHour: 0, timeZone: 'UTC' }, at(3)), true);
});

test('the window follows the configured time zone, not the browser clock', () => {
    const { hourIn } = loadBackground().sandbox.__hiveTest;
    // 13:00 UTC is 08:00 in Chicago (CDT) on 2026-10-29.
    assert.equal(hourIn('America/Chicago', new Date(Date.UTC(2026, 9, 29, 13, 0))), 8);
});

test('checks are aimed at the top of the hour', () => {
    const { nextTopOfHour } = loadBackground().sandbox.__hiveTest;
    const t = Date.UTC(2026, 9, 29, 14, 17, 3);
    assert.equal(nextTopOfHour(t), Date.UTC(2026, 9, 29, 15, 0, 0));
});

test('delivered receipt ids are remembered, deduplicated and capped newest-last', () => {
    const { mergeKnownIds } = loadBackground().sandbox.__hiveTest;
    // Array.from: the sandbox hands back arrays from its own realm, which strict deepEqual rejects by prototype.
    assert.deepEqual(Array.from(mergeKnownIds(['a', 'b'], ['b', 'c'])), ['a', 'b', 'c']);
    assert.deepEqual(Array.from(mergeKnownIds(['a', 'b', 'c'], ['d'], 3)), ['b', 'c', 'd']);
});

test('the sync message carries the ids already sent, so the page downloads only new receipts', () => {
    const source = fs.readFileSync(path.join(dir, '..', 'background.js'), 'utf8');
    assert.match(source, /\{ action: 'sync', since, knownIds \}/);
    assert.match(source, /SETTINGS_PATH = '\/api\/menards\/extension-settings'/);
});
