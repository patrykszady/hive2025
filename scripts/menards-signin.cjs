#!/usr/bin/env node
'use strict';
/**
 * Fills and submits the Menards sign-in form in the server's already-running
 * Chrome (the one MenardsRemoteBrowserService starts on DISPLAY :98).
 *
 * It never launches a browser: Imperva serves a Puppeteer-launched Chromium an
 * unrendered shell. It attaches over the loopback DevTools port for a few
 * seconds, types into the login tab with real (isTrusted) keyboard and mouse
 * input, clicks Sign In, waits for the page to leave login.html, and detaches.
 *
 * - rebrowser-puppeteer-core in "alwaysIsolated" mode never calls
 *   Runtime.enable, the CDP call anti-bot scripts probe for.
 * - defaultViewport: null leaves the window size alone (no emulation).
 * - Only the login tab is attached; the extension and other tabs are untouched.
 *
 * Input: one JSON object on stdin (never argv, so the password is not in ps):
 *   { "email": "...", "password": "...", "port": 9298,
 *     "loginUrlPattern": "menards.com/main/login", "timeoutMs": 45000 }
 * Output: one JSON line on stdout: { ok, url?, error?, stage }
 */
process.env.REBROWSER_PATCHES_RUNTIME_FIX_MODE = process.env.REBROWSER_PATCHES_RUNTIME_FIX_MODE || 'alwaysIsolated';

const puppeteer = require('rebrowser-puppeteer-core');

const EMAIL_SELECTORS = [
    'input[type="email"]',
    'input[autocomplete="username"]',
    'input[name*="email" i]',
    'input[id*="email" i]',
];
const PASSWORD_SELECTORS = ['input[type="password"]'];

function readStdin() {
    return new Promise((resolve, reject) => {
        let data = '';
        process.stdin.setEncoding('utf8');
        process.stdin.on('data', (chunk) => { data += chunk; });
        process.stdin.on('end', () => resolve(data));
        process.stdin.on('error', reject);
    });
}

function finish(result) {
    process.stdout.write(JSON.stringify(result) + '\n');
}

const pause = (min, max) => new Promise((r) => setTimeout(r, min + Math.floor(Math.random() * (max - min))));

/**
 * The page navigated or reloaded under us (Imperva and Menards both reload
 * login.html once shortly after it loads). Transient: find the fields again.
 */
function isNavigationError(e) {
    return /Execution context was destroyed|Cannot find context|detached|Target closed|navigat|Node is either not visible|not attached|same JavaScript world|Cannot find object with id|No node with given id/i.test(String(e && e.message));
}

/** First visible element matching any selector, or null. */
async function firstVisible(page, selectors) {
    for (const selector of selectors) {
        let handles = [];
        try {
            handles = await page.$$(selector);
        } catch (e) {
            if (isNavigationError(e)) {
                return null;
            }
            throw e;
        }
        for (const handle of handles) {
            try {
                const box = await handle.boundingBox();
                if (box && box.width > 0 && box.height > 0) {
                    return handle;
                }
            } catch (e) {
                if (!isNavigationError(e)) {
                    throw e;
                }
            }
            await handle.dispose().catch(() => {});
        }
    }
    return null;
}

async function waitForVisible(page, selectors, timeoutMs) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        const handle = await firstVisible(page, selectors);
        if (handle) {
            return handle;
        }
        await pause(400, 600);
    }
    return null;
}

/** The visible button whose text reads "Sign In", nearest the password field's form. */
async function signInButton(page) {
    const buttons = await page.$$('button, input[type="submit"]');
    let fallback = null;
    for (const button of buttons) {
        const box = await button.boundingBox();
        if (!box || box.width === 0 || box.height === 0) {
            continue;
        }
        const label = await button.evaluate((el) => (el.innerText || el.value || '').trim());
        if (/^sign\s*in$/i.test(label)) {
            return button;
        }
        if (!fallback && /sign\s*in/i.test(label)) {
            fallback = button;
        }
    }
    return fallback;
}

/** Click a field like a person, clear it, and type the value with human-ish pacing. */
async function fill(page, handle, value) {
    await handle.click({ delay: 60 + Math.floor(Math.random() * 60) });
    await pause(200, 400);
    await page.keyboard.down('Control');
    await page.keyboard.press('KeyA');
    await page.keyboard.up('Control');
    await page.keyboard.press('Backspace');
    await pause(150, 300);
    for (const char of value) {
        await page.keyboard.type(char);
        await pause(45, 130);
    }
}

async function main() {
    let input;
    try {
        input = JSON.parse(await readStdin());
    } catch (e) {
        return finish({ ok: false, stage: 'input', error: 'Expected one JSON object on stdin.' });
    }

    const { email, password } = input;
    const port = Number(input.port || 9298);
    const pattern = String(input.loginUrlPattern || 'menards.com/main/login');
    const timeoutMs = Number(input.timeoutMs || 45000);

    if (!email || !password) {
        return finish({ ok: false, stage: 'input', error: 'Email and password are required.' });
    }

    let browser;
    try {
        browser = await puppeteer.connect({
            browserURL: `http://127.0.0.1:${port}`,
            defaultViewport: null,
            // Leave the extension's workers and Chrome's own UI alone. Pages
            // stay in whatever their URL: a tab caught mid-load or mid-reload
            // is not on login.html yet and would otherwise never be attached.
            // The browser and "tab" container targets must stay in too:
            // Puppeteer waits on them while connecting and hangs without them.
            targetFilter: (target) => !['service_worker', 'shared_worker', 'worker', 'background_page', 'other', 'webview', 'browser_ui'].includes(target.type()),
        });
    } catch (e) {
        return finish({ ok: false, stage: 'connect', error: `Could not reach Chrome's DevTools port ${port}: ${e.message}` });
    }

    try {
        // The tab may still be loading or reloading login.html: give it a moment.
        let page = null;
        const findDeadline = Date.now() + 15000;
        while (!page && Date.now() < findDeadline) {
            page = (await browser.pages()).find((p) => p.url().includes(pattern)) || null;
            if (!page) {
                await pause(500, 800);
            }
        }
        if (!page) {
            return finish({ ok: false, stage: 'find_tab', error: 'No tab is on the Menards sign-in page.' });
        }

        // Up to three passes: a reload mid-way (the page's own, not ours)
        // throws away the typing, so find the fields again and start over.
        let submitted = false;
        for (let attempt = 1; attempt <= 3 && !submitted; attempt++) {
            try {
                const emailField = await waitForVisible(page, EMAIL_SELECTORS, Math.min(20000, timeoutMs));
                if (!emailField) {
                    return finish({ ok: false, stage: 'form', url: page.url(), error: 'The email field never appeared on the sign-in page.' });
                }
                const passwordField = await waitForVisible(page, PASSWORD_SELECTORS, 5000);
                if (!passwordField) {
                    return finish({ ok: false, stage: 'form', url: page.url(), error: 'The password field is missing from the sign-in page.' });
                }

                await fill(page, emailField, email);
                await pause(400, 800);
                await fill(page, passwordField, password);
                await pause(500, 900);

                const button = await signInButton(page);
                submitted = true;
                if (button) {
                    await button.click({ delay: 70 + Math.floor(Math.random() * 60) });
                } else {
                    await page.keyboard.press('Enter');
                }
            } catch (e) {
                if (submitted && isNavigationError(e)) {
                    break; // the click itself navigated: that is the submission
                }
                if (!isNavigationError(e) || attempt === 3) {
                    throw e;
                }
                submitted = false;
                process.stderr.write(`[menards-signin] page navigated during attempt ${attempt}; retrying\n`);
                await pause(1500, 2500);
            }
        }

        // Wait for the browser to leave the sign-in page (a sign-in navigates;
        // a rejected one stays put and shows an error).
        const deadline = Date.now() + Math.max(5000, timeoutMs - 10000);
        while (Date.now() < deadline) {
            if (!page.url().includes(pattern)) {
                return finish({ ok: true, stage: 'submitted', url: page.url() });
            }
            await pause(400, 600);
        }

        let message = '';
        try {
            message = await page.evaluate(() => {
                const el = document.querySelector('[role="alert"], .alert, .error, .invalid-feedback, .text-danger');
                return el ? el.innerText.trim().slice(0, 200) : '';
            });
        } catch (e) {
            // The page may have navigated between checks; nothing to read.
        }

        return finish({
            ok: false,
            stage: 'still_on_login',
            url: page.url(),
            error: message ? `Menards kept the sign-in page open: ${message}` : 'Menards kept the sign-in page open after submitting.',
        });
    } catch (e) {
        return finish({ ok: false, stage: 'error', error: e.message });
    } finally {
        // Detach only. Closing would kill the signed-in browser the extension runs in.
        await browser.disconnect();
    }
}

main().catch((e) => finish({ ok: false, stage: 'error', error: e.message }));
