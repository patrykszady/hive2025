// challenge.js must not even ask the background worker for a token unless
// storage says solving is on — the worker's own check proved unreliable
// (a stale cached worker ran for a day). Loaded into a sandbox that looks
// like an Imperva wall.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(dir, '..', 'challenge.js'), 'utf8');

function wall(solveChallenges) {
    const sent = [];
    const sandbox = {
        console: { log() {} },
        setTimeout,
        document: {
            querySelector: (sel) => sel.includes('data-sitekey') ? { getAttribute: () => 'dd6e16a7-972e-47d2-93d0-96642fb6d8de' } : null,
            querySelectorAll: () => [],
            body: { innerText: 'www.menards.com Additional security check is required' },
        },
        chrome: {
            storage: { local: { get: (key, cb) => cb({ solveChallenges }) } },
            runtime: { sendMessage: (msg) => { sent.push(msg); }, lastError: null },
        },
    };
    sandbox.window = { location: { href: 'https://www.menards.com/main/login.html' } };
    vm.runInContext(source, vm.createContext(sandbox), { filename: 'challenge.js' });

    return sent;
}

test('with solving off (the default) the wall is left alone: no message, no token request', async () => {
    const sent = wall(false);
    await new Promise(r => setTimeout(r, 10));
    assert.deepEqual(sent, []);
});

test('with solving on, the sitekey is reported for a solve', async () => {
    const sent = wall(true);
    await new Promise(r => setTimeout(r, 10));
    assert.equal(sent.length, 1);
    assert.equal(sent[0].type, 'hive-solve-challenge');
    assert.equal(sent[0].siteKey, 'dd6e16a7-972e-47d2-93d0-96642fb6d8de');
});
