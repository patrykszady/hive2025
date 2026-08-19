#!/usr/bin/env node
/**
 * Open EWCCV in a real, visible browser so a human can sign in and run one
 * search — then capture the search credential that produces, and hand it to
 * Hive.
 *
 * WHY THIS EXISTS
 *
 * ewccv.com gates its search API behind reCAPTCHA v3 Enterprise. That is a
 * *score*, not a puzzle: there is nothing for a captcha service to solve, and
 * tokens minted by an automated browser are refused no matter how they are
 * produced. A person driving a browser passes it without trying.
 *
 * This is the same shape gsc's Yelp integration settled on: do the credential
 * step once, in a headed browser against a PERSISTENT profile, then let
 * unattended runs reuse what that produced. ("headless credential submits get
 * flagged" — yelp-login.mjs.) Deliberately not an extension: the session lives
 * in the profile this scraper already uses, so nothing has to be installed or
 * kept running.
 *
 * WHAT IT CAPTURES
 *
 * `sessionStorage.accessToken` — issued by EWCCV after a successful
 * verification, and the credential its search API actually requires (the login
 * JWT alone returns 401; that was measured). EWCCV re-verifies only once per
 * 25 searches, so one capture covers a full backfill of every vendor.
 *
 * Usage:
 *   node ewccv-session-capture.cjs <config.json>
 *
 * config: { outputDir, timeoutMs?, startUrl? }
 * Output (last stdout line, JSON for the PHP caller):
 *   {"ok":true,"accessToken":"...","jwt":"...","stateToken":"..."}
 *   {"ok":false,"error":"..."}
 */
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
const fs = require('fs');
const path = require('path');

const { addExtra } = require('puppeteer-extra');
const puppeteer = addExtra(require('rebrowser-puppeteer-core'));
puppeteer.use(StealthPlugin());

const log = (m) => process.stderr.write(`[ewccv-capture] ${m}\n`);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const configPath = process.argv[2];
if (!configPath) {
    process.stderr.write('Usage: node ewccv-session-capture.cjs <config.json>\n');
    process.exit(1);
}
const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
const OUTPUT_DIR = config.outputDir || '/tmp';
const TIMEOUT_MS = config.timeoutMs || 600000;
const START_URL = config.startUrl || 'https://www.ewccv.com/cvs/search';

function findChrome() {
    const candidates = [
        process.env.EWCCV_CHROME_PATH,
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ].filter(Boolean);
    for (const c of candidates) {
        if (fs.existsSync(c)) return c;
    }
    // Whatever puppeteer downloaded.
    const base = path.join(process.env.HOME || '/root', '.cache/puppeteer/chrome');
    if (fs.existsSync(base)) {
        const versions = fs.readdirSync(base).sort().reverse();
        for (const v of versions) {
            const p = path.join(base, v, 'chrome-linux64', 'chrome');
            if (fs.existsSync(p)) return p;
        }
    }
    return null;
}

/** Read the credential the site issues once a search has been verified. */
async function readSession(page) {
    return page.evaluate(() => {
        const token = sessionStorage.getItem('accessToken');
        const ck = document.cookie.split(';').map((c) => c.trim()).find((c) => c.startsWith('token='));
        const jwt = ck ? decodeURIComponent(ck.split('=').slice(1).join('=')) : '';

        // The state token: EWCCV splits one session UUID across the first five
        // states in its list, so rejoining the non-empty ones rebuilds it.
        let stateToken = '';
        try {
            const root = document.getElementById('root');
            let fiber = null;
            for (const k of Object.keys(root || {})) if (k.startsWith('__reactContainer')) fiber = root[k];
            let n = fiber, store = null, hops = 0;
            while (n && hops++ < 3000) {
                const st = n.memoizedProps?.store || n.pendingProps?.store;
                if (st && typeof st.getState === 'function') { store = st; break; }
                n = n.child || n.sibling || (n.return && n.return.sibling) || n.return;
            }
            const list = store?.getState()?.coverageStates?.statesList || [];
            stateToken = list.reduce((a, s) => (s.stateToken && s.stateToken.length > 0 ? a.concat(s.stateToken) : a), []).join('-');
        } catch (_) { /* best effort */ }

        return { token, jwt, stateToken };
    }).catch(() => ({ token: null, jwt: '', stateToken: '' }));
}

(async () => {
    const chrome = findChrome();
    if (!chrome) {
        process.stdout.write(JSON.stringify({ ok: false, error: 'No Chrome binary found.' }));
        process.exit(1);
    }

    // The SAME profile the scraper uses, so anything earned here — cookies,
    // site data, the browser's standing with the site — is there for the
    // unattended runs that follow.
    const profileDir = path.join(OUTPUT_DIR, '.chrome-profile');
    fs.mkdirSync(profileDir, { recursive: true });

    log(`Chrome: ${chrome}`);
    log(`Profile: ${profileDir}`);
    log(`Display: ${process.env.DISPLAY || '(none)'}`);

    let browser;
    try {
        browser = await puppeteer.launch({
            headless: false, // a person is going to use this window
            executablePath: chrome,
            userDataDir: profileDir,
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--window-size=1400,950'],
            defaultViewport: null,
        });
    } catch (e) {
        process.stdout.write(JSON.stringify({ ok: false, error: `Could not start a visible browser: ${e.message}` }));
        process.exit(1);
    }

    try {
        const pages = await browser.pages();
        const page = pages[0] || await browser.newPage();
        await page.goto(START_URL, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});

        log('');
        log('╔════════════════════════════════════════════════════════════════╗');
        log('║  A browser window is open. In it:                              ║');
        log('║                                                                ║');
        log('║    1. Sign in (LOGIN → your email → click the emailed link)    ║');
        log('║    2. Run ONE search — any state, any employer name            ║');
        log('║                                                                ║');
        log('║  That is all. This window closes itself once the search        ║');
        log('║  succeeds, and every scheduled run afterwards uses what it     ║');
        log('║  produced.                                                     ║');
        log('╚════════════════════════════════════════════════════════════════╝');
        log('');

        const deadline = Date.now() + TIMEOUT_MS;
        let announced = false;

        while (Date.now() < deadline) {
            // The tab may have been navigated or replaced by the user.
            let target = page;
            try {
                const open = (await browser.pages()).filter((p) => !p.isClosed());
                target = open.find((p) => p.url().includes('ewccv.com')) || open[0] || page;
            } catch (_) { /* browser closing */ }

            const { token, jwt, stateToken } = await readSession(target);

            if (token && token.length > 20) {
                log(`Captured search credential (${token.length} chars).`);
                process.stdout.write(JSON.stringify({
                    ok: true, accessToken: token, jwt: jwt || null, stateToken: stateToken || null,
                }));
                await browser.close().catch(() => {});
                process.exit(0);
            }

            if (!announced && jwt) {
                log('Signed in. Now run one search in that window…');
                announced = true;
            }

            await sleep(2000);
        }

        log('Timed out waiting for a search.');
        process.stdout.write(JSON.stringify({ ok: false, error: 'Timed out waiting for a signed-in search in the browser window.' }));
        await browser.close().catch(() => {});
        process.exit(1);
    } catch (e) {
        process.stdout.write(JSON.stringify({ ok: false, error: e.message }));
        await browser.close().catch(() => {});
        process.exit(1);
    }
})();
