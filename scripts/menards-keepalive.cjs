#!/usr/bin/env node
'use strict';
/**
 * Keeps the server Chrome's Menards session from idling out between syncs.
 *
 * Menards drops an idle session within about an hour (2026-10-01: signed in
 * 23:21, the 00:31 sync got 401), and every new sign-in draws Imperva's
 * hCaptcha. So, every few minutes, this asks the receipt API one question
 * from inside the Menards tab the browser is parked on — the same first call
 * the extension's sync makes — and reports whether the session answered.
 *
 * It never navigates, opens or reloads a tab, and never launches a browser:
 * it attaches over the loopback DevTools port, runs one XHR in the PAGE's own
 * JavaScript world (Runtime.evaluate on the top frame, so Imperva's wrapper
 * stamps the call as it does the page's own; an isolated-world request gets
 * the challenge page instead — see page-bridge.js), and detaches. No
 * Runtime.enable, the call anti-bot scripts probe for.
 *
 * Input: one JSON object on stdin: { "port": 9298, "timeoutMs": 30000 }
 * Output: one JSON line on stdout:
 *   { ok, stage, url?, status?, json?, error? }
 *   ok: the call was made; status/json say what the API answered.
 */
process.env.REBROWSER_PATCHES_RUNTIME_FIX_MODE = process.env.REBROWSER_PATCHES_RUNTIME_FIX_MODE || 'alwaysIsolated';

const puppeteer = require('rebrowser-puppeteer-core');

const INITIALIZE = '/main/my-account/receipt-lookup/initialize.ajx';

/** Pages that are not a signed-in place to call from. */
const NOT_PARKED = /\/main\/(login|checkcredentials|error)/i;

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

/** Runs in the page: the extension's own first call, with the page's CSRF token. */
function pingExpression(timeoutMs) {
    return `new Promise((resolve) => {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', ${JSON.stringify(INITIALIZE)}, true);
        xhr.withCredentials = true;
        xhr.timeout = ${Number(timeoutMs)};
        xhr.setRequestHeader('Accept', 'application/json');
        const csrf = document.querySelector('meta[name="_csrf"]')?.getAttribute('content');
        if (csrf) xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
        xhr.onload = () => resolve({ status: xhr.status, json: (xhr.responseText || '').trim().startsWith('{') });
        xhr.onerror = () => resolve({ status: xhr.status, json: false, error: 'network error' });
        xhr.ontimeout = () => resolve({ status: 0, json: false, error: 'timed out' });
        xhr.send();
    })`;
}

async function main() {
    let input;
    try {
        input = JSON.parse(await readStdin());
    } catch (e) {
        return finish({ ok: false, stage: 'input', error: 'Expected one JSON object on stdin.' });
    }

    const port = Number(input.port || 9298);
    const timeoutMs = Number(input.timeoutMs || 30000);

    let browser;
    try {
        browser = await puppeteer.connect({
            browserURL: `http://127.0.0.1:${port}`,
            defaultViewport: null,
            // As in menards-signin.cjs: pages only, never the extension's workers.
            targetFilter: (target) => !['service_worker', 'shared_worker', 'worker', 'background_page', 'other', 'webview', 'browser_ui'].includes(target.type()),
        });
    } catch (e) {
        return finish({ ok: false, stage: 'connect', error: `Could not reach Chrome's DevTools port ${port}: ${e.message}` });
    }

    try {
        const pages = (await browser.pages()).filter((p) => p.url().startsWith('https://www.menards.com/main/') && !NOT_PARKED.test(p.url()));
        const page = pages.find((p) => p.url().includes('accountoverview')) || pages[0] || null;

        if (!page) {
            return finish({ ok: false, stage: 'find_tab', error: 'No tab is parked on a signed-in Menards page.' });
        }

        const session = await page.createCDPSession();
        try {
            const { result, exceptionDetails } = await session.send('Runtime.evaluate', {
                expression: pingExpression(timeoutMs),
                awaitPromise: true,
                returnByValue: true,
            });

            if (exceptionDetails) {
                return finish({ ok: false, stage: 'evaluate', url: page.url(), error: exceptionDetails.text || 'The call threw in the page.' });
            }

            const answer = result && result.value ? result.value : {};

            return finish({ ok: true, stage: 'called', url: page.url(), status: Number(answer.status || 0), json: !!answer.json, error: answer.error || null });
        } finally {
            await session.detach().catch(() => {});
        }
    } catch (e) {
        return finish({ ok: false, stage: 'error', error: e.message });
    } finally {
        // Detach only: the browser belongs to MenardsRemoteBrowserService.
        await browser.disconnect().catch(() => {});
    }
}

main().catch((e) => finish({ ok: false, stage: 'error', error: e.message }));
