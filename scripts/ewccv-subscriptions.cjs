#!/usr/bin/env node
/**
 * Read the EWCCV account's tracking subscriptions — headless, no captcha.
 *
 * EWCCV gates SEARCH behind reCAPTCHA v3 Enterprise, but its subscription
 * service authenticates with the ordinary login JWT (the `token` cookie), the
 * same one the magic-link sign-in produces. Measured, not assumed:
 *   GET /cvs/endpoint/subscription/subscription/all  →  200 with the JWT
 *   GET /cvs/endpoint/insureds/coverage/...          →  401 with the JWT
 *
 * So everything this account already tracks is readable from the backend with
 * no human, no browser window, and nothing to install. That is what drives the
 * "Verified" column: a policy EWCCV is actively tracking for us is a policy
 * EWCCV has confirmed exists and will email us about if it lapses.
 *
 * Read-only.
 *
 * Usage: node ewccv-subscriptions.cjs <config.json>
 * config: { loginUrl, outputDir, headless? }
 * Output (last stdout line): {"ok":true,"subscriptions":[...],"user":{...}}
 */
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
const fs = require('fs');
const path = require('path');

const { addExtra } = require('puppeteer-extra');
const puppeteer = addExtra(require('rebrowser-puppeteer-core'));
puppeteer.use(StealthPlugin());

const log = (m) => process.stderr.write(`[ewccv-subs] ${m}\n`);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const configPath = process.argv[2];
if (!configPath) {
    process.stderr.write('Usage: node ewccv-subscriptions.cjs <config.json>\n');
    process.exit(1);
}
const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));

function findChrome() {
    for (const c of [process.env.EWCCV_CHROME_PATH, '/usr/bin/google-chrome', '/usr/bin/chromium'].filter(Boolean)) {
        if (fs.existsSync(c)) return c;
    }
    const base = path.join(process.env.HOME || '/root', '.cache/puppeteer/chrome');
    if (fs.existsSync(base)) {
        for (const v of fs.readdirSync(base).sort().reverse()) {
            const p = path.join(base, v, 'chrome-linux64', 'chrome');
            if (fs.existsSync(p)) return p;
        }
    }
    return null;
}

(async () => {
    if (!config.loginUrl) {
        process.stdout.write(JSON.stringify({ ok: false, error: 'loginUrl is required' }));
        process.exit(1);
    }

    const chrome = findChrome();
    if (!chrome) {
        process.stdout.write(JSON.stringify({ ok: false, error: 'No Chrome binary found' }));
        process.exit(1);
    }

    const browser = await puppeteer.launch({
        headless: config.headless === false ? false : 'new',
        executablePath: chrome,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
        defaultViewport: { width: 1366, height: 900 },
    });

    try {
        const page = await browser.newPage();

        log('Signing in via magic link…');
        await page.goto(config.loginUrl, { waitUntil: 'networkidle2', timeout: 90000 });
        await sleep(5000);
        log(`URL after login: ${page.url()}`);

        // The terms gate can stand between the link and a usable session.
        try {
            const clicked = await page.evaluate(() => {
                const b = Array.from(document.querySelectorAll('button'))
                    .find((x) => /accept|agree/i.test(x.textContent || ''));
                if (b) { b.click(); return true; }
                return false;
            });
            if (clicked) { log('Accepted terms.'); await sleep(4000); }
        } catch (_) { /* no gate */ }

        const result = await page.evaluate(async () => {
            const BASE = '/cvs/endpoint/subscription';
            const ck = document.cookie.split(';').map((c) => c.trim()).find((c) => c.startsWith('token='));
            const jwt = ck ? decodeURIComponent(ck.split('=').slice(1).join('=')) : '';
            if (!jwt) return { ok: false, error: 'no session cookie after login' };

            const headers = { 'Content-Type': 'application/json', Authorization: `Bearer ${jwt}` };
            const out = { ok: true };

            try {
                const r = await fetch(`${BASE}/user/me`, { method: 'GET', headers });
                if (r.status === 200) out.user = (await r.json()).data || null;
                else out.userStatus = r.status;
            } catch (e) { out.userError = e.message; }

            try {
                const r = await fetch(`${BASE}/subscription/all`, { method: 'GET', headers });
                if (r.status !== 200) return { ok: false, error: `subscription/all returned ${r.status}` };
                out.subscriptions = (await r.json()).data || [];
            } catch (e) { return { ok: false, error: `subscription/all failed: ${e.message}` }; }

            return out;
        });

        if (!result.ok) {
            process.stdout.write(JSON.stringify(result));
            process.exit(1);
        }

        log(`Subscriptions: ${(result.subscriptions || []).length}`);
        process.stdout.write(JSON.stringify(result));
        process.exit(0);
    } catch (e) {
        process.stdout.write(JSON.stringify({ ok: false, error: e.message }));
        process.exit(1);
    } finally {
        await browser.close().catch(() => {});
    }
})();
