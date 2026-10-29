// signin.js fills the Menards login form the way the server's Puppeteer
// script does, but only with credentials the service worker hands over.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(dir, '..', 'signin.js'), 'utf8');

function input(type, top, extra = {}) {
    return {
        type, value: '', events: [], focused: false, ...extra,
        getBoundingClientRect: () => ({ top, bottom: top + 30, width: 200, height: 30 }),
        focus() { this.focused = true; },
        dispatchEvent(e) { this.events.push(e.type); },
    };
}

function button(label, top, visibleBox = true) {
    return {
        innerText: label, clicked: 0,
        getBoundingClientRect: () => ({ top, bottom: top + 30, width: visibleBox ? 100 : 0, height: 30 }),
        click() { this.clicked++; },
    };
}

function page({ pathname = '/main/login.html', credentials = { email: 'crew@gs.construction', password: 'pw' }, withForm = true } = {}) {
    const email = input('email', 100, { autocomplete: 'username' });
    const password = input('password', 160);
    const above = button('Sign In', 20);
    const below = button('Sign In', 240);
    const other = button('Create account', 300);
    const sent = [];
    const sandbox = {
        console, setTimeout, Math, Object, Array, Event: class { constructor(type, init) { this.type = type; this.init = init; } },
        window: { location: { pathname, href: 'https://www.menards.com' + pathname } },
        document: {
            querySelectorAll: (sel) => {
                if (sel.includes('type="email"')) return [email];
                if (sel.includes('type="password"')) return [password];
                if (sel.startsWith('button')) return [above, below, other];
                return [];
            },
        },
        chrome: {
            runtime: {
                lastError: null,
                sendMessage: (msg, cb) => { sent.push(msg); if (msg.type === 'hive-auto-signin' && cb) cb({ credentials }); },
            },
        },
    };
    sandbox.globalThis = sandbox;
    vm.runInContext(source, vm.createContext(sandbox), { filename: 'signin.js' });

    return { sandbox, sent, email, password, above, below, other };
}

const settle = () => new Promise((r) => setTimeout(r, 1600));

test('on the login page it asks the worker for credentials, fills both fields and clicks the Sign In button below them', async () => {
    const { sent, email, password, above, below } = page();
    await settle();

    assert.equal(sent[0].type, 'hive-auto-signin');
    assert.equal(email.value, 'crew@gs.construction');
    assert.equal(password.value, 'pw');
    assert.ok(email.events.includes('input') && password.events.includes('change'));
    assert.equal(below.clicked, 1, 'the Sign In below the fields submits');
    assert.equal(above.clicked, 0, 'the Sign In above the fields submits nothing');
    assert.equal(sent.at(-1).type, 'hive-auto-signin-result');
    assert.equal(sent.at(-1).ok, true);
});

test('without credentials from the worker it touches nothing', async () => {
    const { sent, email, below } = page({ credentials: null });
    await settle();

    assert.equal(sent.length, 1);
    assert.equal(email.value, '');
    assert.equal(below.clicked, 0);
});

test('off the login page it does not even ask', async () => {
    const { sent } = page({ pathname: '/main/accountoverview.html' });
    await settle();

    assert.deepEqual(sent, []);
});
