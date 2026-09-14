// The bridge plumbing, without Chrome: page-bridge.js and content.js loaded
// into one sandbox sharing a fake window, so a request posted by the isolated
// side is answered by the page side through a fake XMLHttpRequest.
//   node --test scripts/menards-receipt-extension/tests/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const read = (f) => fs.readFileSync(path.join(dir, '..', f), 'utf8');

function sandbox({ withBridge = true, responder, fetchImpl } = {}) {
    const listeners = [];
    const requests = [];
    const html = { dataset: {} };
    // Inside a vm context `window` is the context's global proxy, not the
    // sandbox object — so the event source must be that proxy, or the
    // scripts' `event.source !== window` guard (correct in a real page)
    // drops every message.
    let self = null;
    const win = {
        location: { origin: 'https://www.menards.com' },
        addEventListener: (type, fn) => { if (type === 'message') listeners.push(fn); },
        postMessage: (data) => { for (const fn of [...listeners]) setTimeout(() => fn({ source: self, data }), 0); },
        setTimeout, clearTimeout, Date, console, Promise, Map, Set, Object, Error, JSON, Array, String, Number,
    };
    win.window = win;
    win.document = {
        documentElement: html,
        querySelector: (sel) => sel.includes('_csrf') ? { getAttribute: () => 'csrf-123' } : null,
    };
    win.chrome = { runtime: { onMessage: { addListener() {} }, sendMessage: async () => {} } };
    win.fetch = fetchImpl || (async () => { throw new Error('isolated fetch used'); });
    win.XMLHttpRequest = class {
        constructor() { this.headers = {}; }
        open(method, url) { this.method = method; this.url = url; }
        setRequestHeader(k, v) { this.headers[k] = v; }
        send(body) {
            this.body = body;
            requests.push(this);
            const r = responder(this);
            setTimeout(() => { this.status = r.status; this.responseText = r.text; this.onload(); }, 0);
        }
    };
    const ctx = vm.createContext(win);
    self = vm.runInContext('globalThis', ctx);
    if (withBridge) vm.runInContext(read('page-bridge.js'), ctx, { filename: 'page-bridge.js' });
    vm.runInContext(read('content.js'), ctx, { filename: 'content.js' });
    return { ctx, requests, html };
}

const call = (ctx, expr) => vm.runInContext(expr, ctx);

test('a GET goes through the page world with cookies, Accept and the CSRF token', async () => {
    const { ctx, requests, html } = sandbox({ responder: () => ({ status: 200, text: '{"paymentOptions":[{"tenderId":7}]}' }) });
    assert.equal(html.dataset.hiveMenardsBridge, 'ready');

    const data = await call(ctx, 'api(API.initialize)');

    assert.deepEqual(data, { paymentOptions: [{ tenderId: 7 }] });
    assert.equal(requests.length, 1);
    assert.equal(requests[0].method, 'GET');
    assert.equal(requests[0].url, '/main/my-account/receipt-lookup/initialize.ajx');
    assert.equal(requests[0].withCredentials, true);
    assert.equal(requests[0].headers.Accept, 'application/json');
    assert.equal(requests[0].headers['X-CSRF-TOKEN'], 'csrf-123');
    assert.equal(requests[0].body, null);
});

test('a POST carries a JSON body', async () => {
    const { ctx, requests } = sandbox({ responder: () => ({ status: 200, text: '{"transactionData":{"transactions":[]}}' }) });

    await call(ctx, 'api(API.receipts, { skuUpc: "", selectedPaymentOption: 7, pageNumber: 0, includeTotalAvailable: true })');

    assert.equal(requests[0].method, 'POST');
    assert.equal(requests[0].headers['Content-Type'], 'application/json');
    assert.deepEqual(JSON.parse(requests[0].body), { skuUpc: '', selectedPaymentOption: 7, pageNumber: 0, includeTotalAvailable: true });
});

test("Imperva's challenge page is named as such; other HTML as a lapsed session", async () => {
    const wall = sandbox({ responder: () => ({ status: 200, text: '<html style="height:100%"><head><META NAME="ROBOTS" CONTENT="NOINDEX, NOFOLLOW"><script src="/_Incapsula_Resource?SWJIYLWA=1"></script>' }) });
    await assert.rejects(call(wall.ctx, 'api(API.initialize)'), /Imperva's challenge page/);

    const login = sandbox({ responder: () => ({ status: 200, text: '<!DOCTYPE html><html><body>Sign In at Menards</body></html>' }) });
    await assert.rejects(call(login.ctx, 'api(API.initialize)'), /returned HTML — the browser session has expired, sign in again/);
});

test('an HTTP error is reported with its status', async () => {
    const { ctx } = sandbox({ responder: () => ({ status: 500, text: '' }) });
    await assert.rejects(call(ctx, 'api(API.initialize)'), /HTTP 500/);
});

test('without the bridge the isolated fetch is used, so an older pack still works', async () => {
    let fetched = null;
    const { ctx, requests } = sandbox({
        withBridge: false,
        responder: () => ({ status: 200, text: '' }),
        fetchImpl: async (url, init) => { fetched = { url, init }; return { ok: true, status: 200, text: async () => '{"ok":1}' }; },
    });

    const data = await call(ctx, 'api(API.initialize)');

    assert.deepEqual(data, { ok: 1 });
    assert.equal(requests.length, 0);
    assert.equal(fetched.url, '/main/my-account/receipt-lookup/initialize.ajx');
    assert.equal(fetched.init.credentials, 'include');
});

test('the sync waits for Imperva to refresh its token, then proceeds', async () => {
    const { ctx } = sandbox({ responder: () => ({ status: 200, text: '{"paymentOptions":[]}' }) });
    const win = ctx;
    // Sandbox document: give it a cookie jar the script can read.
    let cookie = 'reese84=old-token; SESSION=x';
    Object.defineProperty(win.document, 'cookie', { get: () => cookie, configurable: true });

    const pending = call(ctx, 'waitForImpervaToken(2000)');
    setTimeout(() => { cookie = 'reese84=fresh-token; SESSION=x'; }, 120);
    const outcome = await pending;

    assert.equal(outcome.refreshed, true);
    assert.ok(outcome.waitedMs >= 100 && outcome.waitedMs < 2000);
});

test('the sync gives up waiting for a token refresh after the deadline and still proceeds', async () => {
    const { ctx } = sandbox({ responder: () => ({ status: 200, text: '{"paymentOptions":[]}' }) });
    Object.defineProperty(ctx.document, 'cookie', { get: () => 'reese84=same-token', configurable: true });

    const outcome = await call(ctx, 'waitForImpervaToken(700)');

    assert.equal(outcome.refreshed, false);
    assert.equal(outcome.present, true);
    assert.ok(outcome.waitedMs >= 700);
});
