#!/usr/bin/env node
'use strict';

/**
 * EWCCV Workers Comp Coverage Verification Scraper
 *
 * Uses Puppeteer + stealth plugin + anti-captcha to:
 *   1. Log in via JWT magic link
 *   2. Accept Terms of Service (with reCAPTCHA v2 solve)
 *   3. Patch Redux store to load coverage states
 *   4. Solve reCAPTCHA v3 + verify to obtain accessToken
 *   5. For each vendor: search by address via direct API
 *   6. Navigate to matched result, enable TRACKING
 *
 * Usage:  node scripts/ewccv-scraper.cjs <config.json>
 *
 * Config JSON keys:
 *   loginUrl       – JWT magic link URL for EWCCV login
 *   captchaApiKey  – anti-captcha API key (ANTICAPTCHA_API_KEY)
 *   twoCaptchaKey  – 2captcha API key (TWOCAPTCHA_API_KEY) — used as primary, anti-captcha as fallback
 *   vendors        – Array of { id, businessName, address, city, state, zip, policyNumber }
 *   outputDir      – Directory for debug screenshots / results
 *   headless       – true (default) | false for visible browser
 *   delayMs        – base delay between actions in ms (default 3000)
 */

// EWCCV_USE_REBROWSER=1 (default) wraps rebrowser-puppeteer-core via puppeteer-extra's
// addExtra(); set EWCCV_USE_REBROWSER=0 to fall back to vanilla puppeteer-extra.
// rebrowser-puppeteer-core fixes runtime leaks (Object.getOwnPropertyDescriptors,
// sourceURL, contextId) that Akamai Bot Manager fingerprints — stealth plugin alone
// doesn't patch these.
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
const RecaptchaPlugin = require('puppeteer-extra-plugin-recaptcha');
const fs = require('fs');
const path = require('path');
const https = require('https');
const http = require('http');
const { spawn } = require('child_process');

const useRebrowser = process.env.EWCCV_USE_REBROWSER !== '0';
let puppeteer;
if (useRebrowser) {
    const { addExtra } = require('puppeteer-extra');
    const rebrowser = require('rebrowser-puppeteer-core');
    puppeteer = addExtra(rebrowser);
    process.stderr.write('[ewccv] Using rebrowser-puppeteer-core (anti-Akamai)\n');
} else {
    puppeteer = require('puppeteer-extra');
}

puppeteer.use(StealthPlugin());

// ── Proxy config (residential proxy to defeat Akamai IP reputation gating) ──
// Set EWCCV_PROXY_HOST + EWCCV_PROXY_PORT (and optionally EWCCV_PROXY_USER /
// EWCCV_PROXY_PASS) to route Chrome through a residential proxy. EWCCV_PROXY_SCHEME
// defaults to 'http' but can be 'socks5'. When unset, Chrome uses the host network.
const proxyHost = process.env.EWCCV_PROXY_HOST || '';
const proxyPort = process.env.EWCCV_PROXY_PORT || '';
// Bright Data rotates the residential exit IP per request unless the username
// carries a -session-<id> segment. That rotation is fatal here: the sign-in
// email is requested from one IP and the magic link opened from another, and
// the token is rejected. EWCCV_PROXY_SESSION pins one exit IP across the whole
// flow — the PHP command generates it and reuses it for both phases.
const proxySession = process.env.EWCCV_PROXY_SESSION || '';
const proxyUserBase = process.env.EWCCV_PROXY_USER || '';
const proxyUser = (proxyUserBase && proxySession && ! proxyUserBase.includes('-session-'))
    ? `${proxyUserBase}-session-${proxySession}`
    : proxyUserBase;
const proxyPass = process.env.EWCCV_PROXY_PASS || '';
const proxyScheme = process.env.EWCCV_PROXY_SCHEME || 'http';
const proxyServer = (proxyHost && proxyPort)
    ? `${proxyScheme}://${proxyHost}:${proxyPort}`
    : '';
if (proxyServer) {
    process.stderr.write(`[ewccv] Routing Chrome through proxy: ${proxyScheme}://${proxyHost}:${proxyPort}` +
        (proxyUser ? ` (auth: ${proxyUser})` : '') + '\n');
}
// reCAPTCHA v3 scores a browser near zero when its User-Agent contradicts the
// signals it cannot fake: navigator.userAgentData, the sec-ch-ua client hints,
// platform, and the real Chrome build. This script used to claim "Windows,
// Chrome 131" while running Chrome 146 on Linux — a mismatch that reads as an
// obvious bot and made EWCCV reject every token, including ones minted by the
// page's own grecaptcha. Presenting Chrome's genuine identity is the fix; a UA
// override is only applied if it MATCHES the running build.
// ── Official bypass key (no reCAPTCHA at all) ─────────────────────────────────
// EWCCV's own app supports GET /cvs/endpoint/recaptcha/verifybypasskey/?key=…
// which returns {canBypass, accessToken} and skips reCAPTCHA entirely — the
// sanctioned path for automated/partner access. Set EWCCV_BYPASS_KEY once NCCI
// issues one and every run becomes fully unattended: no captcha, no solver
// spend, no score roulette.
async function tryBypassKey(page, bypassKey) {
    if (!bypassKey) return { ok: false, reason: 'no_key_configured' };

    log('  Trying EWCCV bypass key…');
    const result = await page.evaluate(async (key) => {
        try {
            const res = await fetch(`/cvs/endpoint/recaptcha/verifybypasskey/?key=${encodeURIComponent(key)}`, {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' },
            });
            const json = await res.json();
            const data = json.data || {};
            if (data.canBypass && data.accessToken) {
                sessionStorage.setItem('accessToken', data.accessToken);
                return { ok: true, tokenLength: data.accessToken.length };
            }
            return { ok: false, reason: 'canBypass_false', status: res.status, body: JSON.stringify(json).substring(0, 200) };
        } catch (e) {
            return { ok: false, reason: 'bypass_error: ' + e.message };
        }
    }, bypassKey);

    if (result.ok) log(`  Bypass key accepted — accessToken obtained (${result.tokenLength} chars)`);
    else log(`  Bypass key not usable: ${result.reason}${result.body ? ' — ' + result.body : ''}`);

    return result;
}

async function applyConsistentUserAgent(page) {
    try {
        const real = await page.browser().userAgent();
        // Keep Chrome's own UA (headless builds report "HeadlessChrome" — strip
        // just that marker, which is the one signal worth hiding and which does
        // not contradict any client hint).
        const cleaned = real.replace('HeadlessChrome', 'Chrome');
        if (cleaned !== real) {
            await page.setUserAgent(cleaned);
        }
    } catch (e) {
        process.stderr.write(`[ewccv] userAgent alignment skipped: ${e.message}\n`);
    }
}

async function applyProxyAuth(page) {
    if (proxyServer && proxyUser) {
        try { await page.authenticate({ username: proxyUser, password: proxyPass }); }
        catch (e) { process.stderr.write(`[ewccv] proxy authenticate failed: ${e.message}\n`); }
    }
}

// ── Config ────────────────────────────────────────────────────────────────────
const configPath = process.argv[2];
if (!configPath) {
    process.stderr.write('Usage: node ewccv-scraper.cjs <config.json>\n');
    process.exit(1);
}

const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));

// ── Captcha provider setup ──────────────────────────────────────────────────
// Order can be flipped via EWCCV_PREFER_ANTICAPTCHA=1 (default: anti-captcha first
// because 2captcha tokens have been getting low scores against EWCCV verify).
const captchaProviders = [];
const preferAntiCaptcha = process.env.EWCCV_PREFER_ANTICAPTCHA !== '0';
const _addAntiCaptcha = () => {
    if (config.captchaApiKey) {
        captchaProviders.push({
            name: 'anti-captcha',
            baseUrl: 'https://api.anti-captcha.com',
            clientKey: config.captchaApiKey,
            pluginId: 'anticaptcha',
        });
    }
};
const _add2Captcha = () => {
    if (config.twoCaptchaKey) {
        captchaProviders.push({
            name: '2captcha',
            baseUrl: 'https://api.2captcha.com',
            clientKey: config.twoCaptchaKey,
            pluginId: '2captcha',
        });
    }
};
if (preferAntiCaptcha) { _addAntiCaptcha(); _add2Captcha(); }
else { _add2Captcha(); _addAntiCaptcha(); }

const activeCaptchaProvider = captchaProviders[0] || null;

if (activeCaptchaProvider) {
    puppeteer.use(RecaptchaPlugin({
        provider: { id: activeCaptchaProvider.pluginId, token: activeCaptchaProvider.clientKey },
        visualFeedback: true,
    }));
}

const OUTPUT_DIR = config.outputDir || '/tmp/ewccv-scraper';
const SCREENSHOT_DIR = config.screenshotDir || OUTPUT_DIR;
const DELAY = config.delayMs || 3000;

try { fs.mkdirSync(SCREENSHOT_DIR, { recursive: true }); } catch (e) { /* ignore */ }
const vendors = config.vendors || [];
const RECAPTCHA_V3_SITEKEY = '6LfsgGkqAAAAAJV4WuznMwTLMn6091VDUYNiIxGG';
const BASE_URL = 'https://www.ewccv.com';

// US state abbreviation → full name map (for resolving to numeric stateCode)
const STATE_NAMES = {
    AL:'Alabama',AK:'Alaska',AZ:'Arizona',AR:'Arkansas',CA:'California',
    CO:'Colorado',CT:'Connecticut',DE:'Delaware',FL:'Florida',GA:'Georgia',
    HI:'Hawaii',ID:'Idaho',IL:'Illinois',IN:'Indiana',IA:'Iowa',
    KS:'Kansas',KY:'Kentucky',LA:'Louisiana',ME:'Maine',MD:'Maryland',
    MA:'Massachusetts',MI:'Michigan',MN:'Minnesota',MS:'Mississippi',MO:'Missouri',
    MT:'Montana',NE:'Nebraska',NV:'Nevada',NH:'New Hampshire',NJ:'New Jersey',
    NM:'New Mexico',NY:'New York',NC:'North Carolina',ND:'North Dakota',OH:'Ohio',
    OK:'Oklahoma',OR:'Oregon',PA:'Pennsylvania',RI:'Rhode Island',SC:'South Carolina',
    SD:'South Dakota',TN:'Tennessee',TX:'Texas',UT:'Utah',VT:'Vermont',
    VA:'Virginia',WA:'Washington',WV:'West Virginia',WI:'Wisconsin',WY:'Wyoming',
    DC:'District of Columbia',
};

// ── Helpers ───────────────────────────────────────────────────────────────────
function log(msg)  { process.stderr.write(`[ewccv] ${msg}\n`); }
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

let __screenshotSeq = 0;
function __ts() {
    const d = new Date();
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}${pad(d.getMonth()+1)}${pad(d.getDate())}_${pad(d.getHours())}${pad(d.getMinutes())}${pad(d.getSeconds())}`;
}
async function screenshot(page, name) {
    __screenshotSeq++;
    const seq = String(__screenshotSeq).padStart(3, '0');
    const fname = `${__ts()}_${seq}_${name}.png`;
    const p = path.join(SCREENSHOT_DIR, fname);
    await page.screenshot({ path: p, fullPage: true }).catch(() => {});
    log(`  screenshot → ${fname}`);
}

async function saveHtml(page, name) {
    const html = await page.content();
    fs.writeFileSync(path.join(SCREENSHOT_DIR, `_debug_${name}.html`), html);
}

function httpJsonPost(url, body) {
    return new Promise((resolve, reject) => {
        const data = JSON.stringify(body);
        const urlObj = new URL(url);
        const options = {
            method: 'POST',
            hostname: urlObj.hostname,
            path: urlObj.pathname,
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(data),
            },
        };
        const req = https.request(options, (res) => {
            let resp = '';
            res.on('data', chunk => resp += chunk);
            res.on('end', () => resolve(resp));
        });
        req.on('error', reject);
        req.write(data);
        req.end();
    });
}

// ── Launch Chrome directly (not via puppeteer.launch) ─────────────────────────
// This avoids --enable-automation and other Puppeteer-specific flags that
// reCAPTCHA Enterprise uses to detect automated browsers.
function findChromeBinary() {
    const candidates = [];

    // Prefer Puppeteer's bundled Linux Chrome — launches cleanly into its own debug port
    // even when Windows Chrome is already running. Windows Chrome via WSL hands off
    // to an existing chrome.exe process, so the new process never opens a debug port.
    const cacheDir = path.join(process.env.HOME || '/tmp', '.cache', 'puppeteer', 'chrome');
    if (fs.existsSync(cacheDir)) {
        const versions = fs.readdirSync(cacheDir).sort().reverse();
        for (const v of versions) {
            const bin = path.join(cacheDir, v, 'chrome-linux64', 'chrome');
            if (fs.existsSync(bin)) candidates.push(bin);
        }
    }

    // System Chrome
    candidates.push(
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium',
        '/opt/google/chrome/google-chrome',
        // Windows Chrome (last resort — see note above)
        '/mnt/c/Program Files/Google/Chrome/Application/chrome.exe',
    );

    for (const c of candidates) {
        if (fs.existsSync(c)) return c;
    }
    return null;
}

async function launchChromeDirectly(headless, userDataDir) {
    const chromeBin = findChromeBinary();
    if (!chromeBin) return null;

    const debugPort = 9222 + Math.floor(Math.random() * 1000);
    const profileDir = userDataDir || path.join(OUTPUT_DIR, '.chrome-profile');
    fs.mkdirSync(profileDir, { recursive: true });

    const isWindowsChrome = chromeBin.includes('.exe');

    // For Windows Chrome via WSL: convert Linux profile path to Windows path
    let profileArg = profileDir;
    if (isWindowsChrome && profileDir.startsWith('/')) {
        // Convert WSL path → Windows path (e.g., /home/x/... → \\wsl$\Ubuntu\home\x\...)
        // Use a temp Windows-native profile to avoid path issues
        const winProfile = process.env.TEMP || 'C:\\Temp';
        profileArg = `${winProfile}\\ewccv-chrome-profile`;
    }

    // Minimal flags — avoid automation-revealing flags for real Chrome
    const args = [
        `--remote-debugging-port=${debugPort}`,
        '--window-size=1366,900',
        `--user-data-dir=${profileArg}`,
        '--no-first-run',
        '--no-default-browser-check',
    ];

    if (proxyServer) {
        args.push(`--proxy-server=${proxyServer}`);
        // Bright Data intercepts TLS with its own CA — without this flag every
        // proxied HTTPS load dies with ERR_CERT_AUTHORITY_INVALID.
        args.push('--ignore-certificate-errors');
    }

    // Load the reCAPTCHA-solving extension (runs WITHOUT CDP for clean scores)
    const extSrcDir = path.join(__dirname, 'ewccv-extension');
    if (fs.existsSync(path.join(extSrcDir, 'manifest.json'))) {
        if (isWindowsChrome) {
            // Copy extension to Windows-accessible path
            const extDstDir = '/mnt/c/Temp/ewccv-extension';
            fs.mkdirSync(extDstDir, { recursive: true });
            for (const file of fs.readdirSync(extSrcDir)) {
                fs.copyFileSync(path.join(extSrcDir, file), path.join(extDstDir, file));
            }
            args.push('--load-extension=C:\\Temp\\ewccv-extension');
        } else {
            args.push(`--load-extension=${extSrcDir}`);
        }
        log('  Extension loaded from: ' + extSrcDir);
    }

    // Only add sandbox flags for Linux Chrome (not needed for Windows Chrome)
    if (!isWindowsChrome) {
        args.push('--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage');
    }

    if (headless) {
        args.push('--headless=new');
    }

    log(`  Launching Chrome directly: ${chromeBin}${isWindowsChrome ? ' (Windows Chrome)' : ''}`);
    log(`  Debug port: ${debugPort}, profile: ${isWindowsChrome ? profileArg : profileDir}`);

    const chromeProcess = spawn(chromeBin, args, {
        stdio: ['ignore', 'pipe', 'pipe'],
        detached: false,
    });

    // Wait for Chrome to start and expose debug endpoint
    let browserWSEndpoint = null;
    for (let i = 0; i < 30; i++) {
        await sleep(500);
        try {
            const json = await new Promise((resolve, reject) => {
                http.get(`http://127.0.0.1:${debugPort}/json/version`, (res) => {
                    let data = '';
                    res.on('data', chunk => data += chunk);
                    res.on('end', () => resolve(JSON.parse(data)));
                }).on('error', reject);
            });
            browserWSEndpoint = json.webSocketDebuggerUrl;
            break;
        } catch (e) {
            // Chrome not ready yet
        }
    }

    if (!browserWSEndpoint) {
        chromeProcess.kill();
        return null;
    }

    log(`  Chrome ready: ${browserWSEndpoint}`);
    return { browserWSEndpoint, chromeProcess, debugPort };
}

// ── Interactive reCAPTCHA fallback (manual mode) ──────────────────────────────
// When all automated reCAPTCHA approaches fail, wait for user to manually
// perform a search in the visible browser to obtain an accessToken.
async function waitForManualRecaptcha(page, timeoutMs = 300000) {
    log('');
    log('  ╔═══════════════════════════════════════════════════════════════╗');
    log('  ║  ACTION REQUIRED — MANUAL SEARCH NEEDED                      ║');
    log('  ║                                                               ║');
    log('  ║  Switch to the Chrome browser window and:                    ║');
    log('  ║    1. Click the "Address" tab                                ║');
    log('  ║    2. Type any address (e.g., 123 Main St)                   ║');
    log('  ║    3. Click the SEARCH button                                ║');
    log('  ║                                                               ║');
    log('  ║  This passes reCAPTCHA via real user interaction.             ║');
    log('  ║  After one search, all vendors will be processed via API.    ║');
    log('  ║  Waiting up to 5 minutes…                                    ║');
    log('  ╚═══════════════════════════════════════════════════════════════╝');
    log('');

    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        const token = await page.evaluate(() => {
            return sessionStorage.getItem('accessToken') || null;
        });
        if (token && token.length > 10) {
            log(`  Manual reCAPTCHA succeeded! accessToken obtained (length: ${token.length})`);
            return { ok: true, tokenLength: token.length };
        }
        await sleep(2000);
    }

    log('  Manual reCAPTCHA timed out after ' + Math.round(timeoutMs / 1000) + 's');
    return { ok: false, reason: 'manual_timeout' };
}

// ── Extension-based reCAPTCHA (disconnect CDP → extension runs clean) ─────────
// The Chrome extension runs a content script that:
//   1. Simulates page interaction (scroll, focus) for 12+ seconds
//   2. Executes grecaptcha.enterprise.execute() — gets a HIGH score because
//      navigator.webdriver is FALSE (no CDP connection)
//   3. Calls the verify endpoint → obtains accessToken
//   4. Stores the result in localStorage
//
// The scraper disconnects Puppeteer before the extension runs, then reconnects
// after to read the result. This completely avoids reCAPTCHA detecting CDP.
async function tryExtensionRecaptcha(page, browser, launchInfo) {
    if (!launchInfo || !launchInfo.debugPort) {
        log('  Extension reCAPTCHA: no launch info — skipping');
        return { ok: false, reason: 'no_launch_info' };
    }

    log('  Extension reCAPTCHA: setting trigger and scheduling page reload…');

    // 1. Set extension trigger in localStorage
    await page.evaluate(() => {
        localStorage.setItem('ewccv_trigger', '1');
        // Clear any previous result
        localStorage.removeItem('ewccv_result');
    });

    // 2. Schedule a full page reload AFTER disconnect (3 second delay)
    //    This ensures the page reloads WITHOUT CDP → navigator.webdriver = false
    await page.evaluate(() => {
        setTimeout(function () {
            window.location.href = window.location.origin + '/cvs/search';
        }, 3000);
    });

    // 3. Disconnect Puppeteer (CDP detaches → navigator.webdriver reverts to false)
    log('  Extension reCAPTCHA: disconnecting Puppeteer (CDP off)…');
    browser.disconnect();

    // 4. Wait for extension to complete its work
    //    Timing: 3s reload delay + 3s page render + 15s behavior sim + 10s reCAPTCHA/verify
    //    Total: ~31s — wait 45s to be safe
    log('  Extension reCAPTCHA: waiting 45s for extension to complete…');
    await sleep(45000);

    // 5. Reconnect Puppeteer via debug port
    log('  Extension reCAPTCHA: reconnecting…');
    let newBrowser, newPage;
    try {
        const json = await new Promise((resolve, reject) => {
            http.get(`http://127.0.0.1:${launchInfo.debugPort}/json/version`, (res) => {
                let data = '';
                res.on('data', chunk => data += chunk);
                res.on('end', () => resolve(JSON.parse(data)));
            }).on('error', reject);
        });
        newBrowser = await puppeteer.connect({
            browserWSEndpoint: json.webSocketDebuggerUrl,
            defaultViewport: { width: 1366, height: 900 },
        });
        const pages = await newBrowser.pages();
        newPage = pages.find(p => !p.isClosed()) || pages[0];
        if (!newPage) {
            return { ok: false, reason: 'no_page_after_reconnect' };
        }
    } catch (e) {
        log(`  Extension reCAPTCHA: reconnect failed — ${e.message}`);
        return { ok: false, reason: 'reconnect_failed: ' + e.message };
    }

    // 6. Read result from localStorage (poll briefly in case extension is still finishing)
    let extResult = null;
    for (let attempt = 0; attempt < 30; attempt++) {
        try {
            extResult = await newPage.evaluate(() => {
                const r = localStorage.getItem('ewccv_result');
                return r ? JSON.parse(r) : null;
            });
        } catch (e) {
            log(`  Extension reCAPTCHA: read attempt ${attempt + 1} error — ${e.message}`);
            await sleep(2000);
            continue;
        }
        if (extResult) break;
        if (attempt > 0 && attempt % 5 === 0) {
            log(`  Extension reCAPTCHA: no result yet (attempt ${attempt + 1}/30)…`);
        }
        await sleep(2000);
    }

    if (!extResult) {
        log('  Extension reCAPTCHA: no result after polling — extension may not have loaded');
        return { ok: false, reason: 'no_extension_result', browser: newBrowser, page: newPage };
    }

    // Clean up localStorage
    await newPage.evaluate(() => localStorage.removeItem('ewccv_result')).catch(() => {});

    if (extResult.ok) {
        log(`  Extension reCAPTCHA: SUCCESS — accessToken ${extResult.accessToken.length} chars`);
        // Ensure accessToken is in sessionStorage for searchViaApi()
        await newPage.evaluate((token) => {
            sessionStorage.setItem('accessToken', token);
        }, extResult.accessToken);
        return { ok: true, browser: newBrowser, page: newPage, accessToken: extResult.accessToken };
    }

    log(`  Extension reCAPTCHA: FAILED — ${extResult.error}`);
    log(`  Extension reCAPTCHA: webdriver=${extResult.webdriver}, tokenLen=${extResult.tokenLen}, httpStatus=${extResult.httpStatus}`);
    log(`  Extension reCAPTCHA: response=${JSON.stringify(extResult.response || {}).substring(0, 400)}`);
    return { ok: false, reason: extResult.error || 'verify_failed', browser: newBrowser, page: newPage };
}

// ── Intercept page redirects ──────────────────────────────────────────────────
// The EWCCV React app redirects to /cvs/ on any 401 or verify failure.
// We intercept window.location.href assignments to prevent losing our session.
async function interceptRedirects(page) {
    // Forward EWCCV-INTERCEPT console logs from the page to our scraper output
    if (!page.__consoleHooked) {
        page.__consoleHooked = true;
        page.on('console', msg => {
            const txt = msg.text();
            if (txt.includes('EWCCV-INTERCEPT')) {
                log('    ' + txt);
            }
        });
    }
    // Use CDP + JS monkey-patching to intercept navigation requests
    // that go to /cvs/ (the React 401 redirect target).
    const client = await page.createCDPSession();
    await client.send('Page.setInterceptFileChooserDialog', { enabled: false }).catch(() => {});

    // Listen for frame navigation requests and cancel unwanted ones
    await page.evaluate(() => {
        if (window.__redirectIntercepted) return;
        window.__redirectIntercepted = true;
        window.__blockedRedirects = [];

        function isBlockedUrl(url) {
            return typeof url === 'string' && (url === '/cvs/' || url.endsWith('/cvs/'));
        }

        // Monkey-patch window.location.assign and window.location.replace
        const origAssign = window.location.assign.bind(window.location);
        const origReplace = window.location.replace.bind(window.location);

        window.location.assign = function(url) {
            if (isBlockedUrl(url)) {
                console.log('[EWCCV-INTERCEPT] Blocked location.assign:', url);
                window.__blockedRedirects.push({ type: 'assign', url, ts: Date.now() });
                return;
            }
            return origAssign(url);
        };
        window.location.replace = function(url) {
            if (isBlockedUrl(url)) {
                console.log('[EWCCV-INTERCEPT] Blocked location.replace:', url);
                window.__blockedRedirects.push({ type: 'replace', url, ts: Date.now() });
                return;
            }
            return origReplace(url);
        };

        // Monkey-patch window.location.href setter via navigation override
        // window.location is non-configurable, but we can intercept via
        // overriding the page's navigation by watching for beforeunload
        // and also hooking into the History API. The most reliable approach
        // is to define a setter on window.location for the main frame.
        // Since window.location itself can't be redefined, we use a
        // MutationObserver + beforeunload fallback.
        window.addEventListener('beforeunload', function(e) {
            // Can't fully prevent, but we track it
            console.log('[EWCCV-INTERCEPT] beforeunload event fired');
        });

        // Hook grecaptcha to capture which action/sitekey the EWCCV app actually uses
        window.__grecaptchaCalls = [];
        const tryHookGrecaptcha = () => {
            if (typeof grecaptcha === 'undefined') return false;
            const ent = grecaptcha.enterprise;
            if (!ent || ent.__hooked) return false;
            const origExecute = ent.execute.bind(ent);
            ent.execute = function(sitekey, opts) {
                const action = opts && opts.action;
                console.log('[EWCCV-INTERCEPT] grecaptcha.enterprise.execute called sitekey=' + sitekey + ' action=' + action);
                window.__grecaptchaCalls.push({ sitekey, action, ts: Date.now() });
                window.__lastGrecaptchaCall = { sitekey, action, ts: Date.now() };
                return origExecute(sitekey, opts);
            };
            ent.__hooked = true;
            return true;
        };
        if (!tryHookGrecaptcha()) {
            const hookInt = setInterval(() => {
                if (tryHookGrecaptcha()) clearInterval(hookInt);
            }, 200);
            setTimeout(() => clearInterval(hookInt), 30000);
        }

        // Override the fetch to intercept reCAPTCHA verify responses for debugging
        const origFetch = window.fetch;
        window.fetch = async function(...args) {
            const url = typeof args[0] === 'string' ? args[0] : args[0]?.url || '';

            // Intercept request body for verify calls
            if (url.includes('/recaptcha/v3/verify')) {
                try {
                    const init = args[1] || (typeof args[0] === 'object' ? args[0] : {});
                    const body = init.body;
                    const headers = init.headers || (args[0] && args[0].headers) || {};
                    let headerDump = {};
                    if (headers && typeof headers.forEach === 'function') {
                        headers.forEach((v, k) => { headerDump[k] = v; });
                    } else if (headers && typeof headers === 'object') {
                        headerDump = headers;
                    }
                    console.log('[EWCCV-INTERCEPT] reCAPTCHA verify REQUEST headers:', JSON.stringify(headerDump).substring(0, 800));
                    console.log('[EWCCV-INTERCEPT] reCAPTCHA verify REQUEST body:', typeof body === 'string' ? body.substring(0, 500) : '(non-string)');
                } catch (e) {}
            }

            const res = await origFetch.apply(this, args);

            // Intercept reCAPTCHA verify response
            if (url.includes('/recaptcha/v3/verify')) {
                const clone = res.clone();
                try {
                    const json = await clone.json();
                    console.log('[EWCCV-INTERCEPT] reCAPTCHA verify response:', JSON.stringify(json));
                    // Track the result
                    window.__lastRecaptchaVerifyResult = json;
                    // If verification failed, track it as a blocked redirect
                    // (since the saga will do window.location.href = "/cvs/")
                    if (json.data && json.data.verified === false) {
                        console.log('[EWCCV-INTERCEPT] reCAPTCHA verification FAILED — pre-empting redirect');
                        window.__blockedRedirects.push({ type: 'recaptcha_failed', url: '/cvs/', ts: Date.now() });
                    }
                } catch (e) {}
            }

            // Intercept search API 401 responses
            if (url.includes('/insureds/coverage/') && res.status === 401) {
                console.log('[EWCCV-INTERCEPT] Search API 401 — tracking redirect');
                window.__blockedRedirects.push({ type: 'search_401', url, ts: Date.now() });
            }

            return res;
        };

        // ── XHR (axios) interception — EWCCV uses axios for verify call ─────
        const OrigXHR = window.XMLHttpRequest;
        const origOpen = OrigXHR.prototype.open;
        const origSend = OrigXHR.prototype.send;
        const origSetHeader = OrigXHR.prototype.setRequestHeader;
        OrigXHR.prototype.open = function(method, url, ...rest) {
            this.__ewccvUrl = url;
            this.__ewccvMethod = method;
            this.__ewccvHeaders = {};
            return origOpen.call(this, method, url, ...rest);
        };
        OrigXHR.prototype.setRequestHeader = function(name, value) {
            if (this.__ewccvHeaders) this.__ewccvHeaders[name] = value;
            return origSetHeader.call(this, name, value);
        };
        OrigXHR.prototype.send = function(body) {
            const url = this.__ewccvUrl || '';
            const isVerify = typeof url === 'string' && url.includes('/recaptcha/v3/verify');
            if (isVerify) {
                console.log('[EWCCV-INTERCEPT] XHR verify REQUEST url:', url);
                console.log('[EWCCV-INTERCEPT] XHR verify REQUEST headers:', JSON.stringify(this.__ewccvHeaders));
                console.log('[EWCCV-INTERCEPT] XHR verify REQUEST body:', typeof body === 'string' ? body.substring(0, 500) : '(' + typeof body + ')');
                this.addEventListener('load', function() {
                    console.log('[EWCCV-INTERCEPT] XHR verify RESPONSE status:', this.status);
                    try {
                        console.log('[EWCCV-INTERCEPT] XHR verify RESPONSE body:', String(this.responseText).substring(0, 500));
                        const json = JSON.parse(this.responseText);
                        window.__lastRecaptchaVerifyResult = json;
                        if (json.data && json.data.verified === false) {
                            console.log('[EWCCV-INTERCEPT] XHR verify FAILED — pre-empting redirect');
                            window.__blockedRedirects.push({ type: 'recaptcha_failed_xhr', url: '/cvs/', ts: Date.now() });
                        }
                    } catch (_) {}
                });
            }
            return origSend.call(this, body);
        };
    });

    // Use CDP to intercept navigation at the protocol level
    try {
        await client.send('Fetch.enable', {
            patterns: [{ urlPattern: '*/cvs/', requestStage: 'Request' }],
        });
        client.on('Fetch.requestPaused', async (event) => {
            const url = event.request.url;
            // Block only the exact /cvs/ redirect (not sub-paths like /cvs/search)
            if (url.endsWith('/cvs/') || url.endsWith('/cvs')) {
                log(`    [INTERCEPT] Blocked navigation to: ${url}`);
                await client.send('Fetch.failRequest', {
                    requestId: event.requestId,
                    errorReason: 'Aborted',
                }).catch(() => {});
            } else {
                await client.send('Fetch.continueRequest', {
                    requestId: event.requestId,
                }).catch(() => {});
            }
        });
    } catch (e) {
        log(`    [INTERCEPT] CDP Fetch setup warning: ${e.message}`);
    }

    // ── CDP Network domain — captures verify request/response at protocol level
    // (works even when EWCCV cached fetch/XHR references before our JS hooks).
    try {
        await client.send('Network.enable');
        const verifyReqs = new Map(); // requestId → { url, method, postData, headers }
        client.on('Network.requestWillBeSent', (e) => {
            const url = e.request.url;
            if (url.includes('/recaptcha/v3/verify')) {
                verifyReqs.set(e.requestId, {
                    url,
                    method: e.request.method,
                    postData: e.request.postData,
                    headers: e.request.headers,
                });
                log(`    [CDP-VERIFY] REQUEST ${e.request.method} ${url}`);
                log(`    [CDP-VERIFY] REQ headers: ${JSON.stringify(e.request.headers).substring(0, 800)}`);
                log(`    [CDP-VERIFY] REQ body: ${(e.request.postData || '').substring(0, 500)}`);
            }
        });
        client.on('Network.responseReceived', async (e) => {
            if (verifyReqs.has(e.requestId)) {
                log(`    [CDP-VERIFY] RESPONSE status=${e.response.status} mimeType=${e.response.mimeType}`);
                // Wait briefly for response body to be available
                setTimeout(async () => {
                    try {
                        const body = await client.send('Network.getResponseBody', { requestId: e.requestId });
                        log(`    [CDP-VERIFY] RESP body: ${(body.body || '').substring(0, 500)}`);
                    } catch (err) {
                        log(`    [CDP-VERIFY] RESP body unavailable: ${err.message}`);
                    }
                }, 500);
            }
        });
    } catch (e) {
        log(`    [INTERCEPT] CDP Network setup warning: ${e.message}`);
    }
}

// ── reCAPTCHA v2 solver (with provider fallback) ──────────────────────────────
async function solveRecaptchaV2(page, siteKey) {
    if (captchaProviders.length === 0) {
        log('  WARNING: No captcha API keys — cannot solve reCAPTCHA');
        return null;
    }

    const pageUrl = page.url();

    for (const provider of captchaProviders) {
        try {
            log(`  Solving reCAPTCHA v2 via ${provider.name} (sitekey: ${siteKey.substring(0, 20)}…)`);

            const balanceResp = await httpJsonPost(`${provider.baseUrl}/getBalance`, {
                clientKey: provider.clientKey,
            });
            const balanceData = JSON.parse(balanceResp);
            if (balanceData.errorId !== 0) {
                log(`  ${provider.name} API key invalid: ${balanceData.errorCode} — trying next provider…`);
                continue;
            }
            log(`  ${provider.name} balance: $${balanceData.balance}`);

            const createResp = await httpJsonPost(`${provider.baseUrl}/createTask`, {
                clientKey: provider.clientKey,
                task: {
                    type: 'RecaptchaV2TaskProxyless',
                    websiteURL: pageUrl,
                    websiteKey: siteKey,
                },
            });
            const createData = JSON.parse(createResp);
            if (createData.errorId !== 0) {
                log(`  ${provider.name} v2 createTask failed: ${createData.errorCode} — ${createData.errorDescription}`);
                continue;
            }

            const taskId = createData.taskId;
            log(`  v2 Task ID: ${taskId} (${provider.name})`);

            let token = null;
            for (let attempt = 0; attempt < 60; attempt++) {
                await sleep(5000);
                const resultResp = await httpJsonPost(`${provider.baseUrl}/getTaskResult`, {
                    clientKey: provider.clientKey,
                    taskId: taskId,
                });
                const resultData = JSON.parse(resultResp);

                if (resultData.status === 'ready') {
                    token = resultData.solution?.gRecaptchaResponse;
                    break;
                }
                if (resultData.errorId !== 0) {
                    throw new Error(`v2 getTaskResult failed: ${resultData.errorCode}`);
                }
                log(`  Waiting for v2 solution… (attempt ${attempt + 1})`);
            }

            if (!token) throw new Error('reCAPTCHA v2 solve timed out');
            log(`  reCAPTCHA v2 solved via ${provider.name} — token length: ${token.length}`);
            return token;
        } catch (err) {
            log(`  ${provider.name} v2 failed: ${err.message}`);
            if (captchaProviders.indexOf(provider) < captchaProviders.length - 1) {
                log(`  Trying next captcha provider…`);
            }
        }
    }

    throw new Error('All captcha providers failed for reCAPTCHA v2');
}

// ── reCAPTCHA v3 solver (single provider) ─────────────────────────────────────
async function solveRecaptchaV3WithProvider(page, provider, minScore = 0.7) {
    const pageUrl = page.url();

    log(`  Solving reCAPTCHA v3 via ${provider.name} (minScore: ${minScore})…`);

    const createResp = await httpJsonPost(`${provider.baseUrl}/createTask`, {
        clientKey: provider.clientKey,
        task: {
            type: 'RecaptchaV3TaskProxyless',
            websiteURL: pageUrl,
            websiteKey: RECAPTCHA_V3_SITEKEY,
            minScore: minScore,
            pageAction: 'default',
            isEnterprise: true,
        },
    });
    const createData = JSON.parse(createResp);
    if (createData.errorId !== 0) {
        throw new Error(`createTask failed: ${createData.errorCode} — ${createData.errorDescription}`);
    }

    const taskId = createData.taskId;
    log(`  v3 Task ID: ${taskId} (${provider.name})`);

    let token = null;
    for (let attempt = 0; attempt < 60; attempt++) {
        await sleep(5000);
        const resultResp = await httpJsonPost(`${provider.baseUrl}/getTaskResult`, {
            clientKey: provider.clientKey,
            taskId: taskId,
        });
        const resultData = JSON.parse(resultResp);

        if (resultData.status === 'ready') {
            token = resultData.solution?.gRecaptchaResponse;
            break;
        }
        if (resultData.errorId !== 0) {
            throw new Error(`getTaskResult failed: ${resultData.errorCode}`);
        }
        if (attempt % 3 === 0) log(`  Waiting for v3 solution… (attempt ${attempt + 1})`);
    }

    if (!token) throw new Error('reCAPTCHA v3 solve timed out');
    log(`  reCAPTCHA v3 solved via ${provider.name} — token length: ${token.length}`);
    return token;
}

// ── Dismiss cookie consent banner if present ──────────────────────────────────
async function dismissCookieConsent(page) {
    const dismissed = await page.evaluate(() => {
        const buttons = Array.from(document.querySelectorAll('button, a'));
        const continueBtn = buttons.find(b =>
            b.textContent.trim().toLowerCase() === 'continue'
            && (b.closest('[class*="cookie"]')
                || b.closest('[class*="consent"]')
                || b.closest('[class*="snack"]')
                || document.body.innerText.includes('cookies'))
        );
        if (continueBtn) {
            continueBtn.click();
            return true;
        }
        return false;
    });
    if (dismissed) {
        log('  Dismissed cookie consent banner');
        await sleep(1000);
    }
}

// ── Login & Accept ToS ────────────────────────────────────────────────────────
async function loginAndAcceptTerms(page) {
    log('Navigating to EWCCV login via magic link…');
    await page.goto(config.loginUrl, {
        waitUntil: 'networkidle2',
        timeout: 60000,
    });
    await sleep(DELAY);

    await dismissCookieConsent(page);

    log(`  URL after login: ${page.url()}`);
    await screenshot(page, '01_after_login');

    const pageText = await page.evaluate(() => document.body.innerText);
    if (!pageText.includes('Accept the terms')) {
        if (page.url().includes('/cvs/search')) {
            log('  Already on search page — skipping ToS');
            return true;
        }
        if (pageText.includes('LOGIN') && !page.url().includes('/cvs/search')) {
            log('  ERROR: Landed on homepage — JWT token may be invalid or expired');
            await saveHtml(page, '01_bad_token');
            return false;
        }
        if (page.url().includes('/cvs/')) {
            log('  Appears logged in — proceeding');
            return true;
        }
        log(`  Unexpected page after login`);
        await saveHtml(page, '01_unexpected');
        return false;
    }

    log('  On Terms of Service page — solving reCAPTCHA…');

    // Find reCAPTCHA sitekey for v2
    const siteKey = await page.evaluate(() => {
        const el = document.querySelector('.g-recaptcha, [data-sitekey]');
        return el ? el.getAttribute('data-sitekey') : null;
    });

    if (!siteKey) {
        log('  No visible sitekey — trying recaptcha plugin…');
        try {
            const { solved } = await page.solveRecaptchas();
            if (solved?.length > 0) {
                log('  Plugin solved reCAPTCHA');
                await sleep(2000);
            }
        } catch (e) {
            log(`  Plugin solve failed: ${e.message}`);
        }
    } else {
        log(`  reCAPTCHA v2 sitekey: ${siteKey}`);
        const token = await solveRecaptchaV2(page, siteKey);
        if (token) {
            await page.evaluate((tkn) => {
                const textarea = document.getElementById('g-recaptcha-response')
                    || document.querySelector('[name="g-recaptcha-response"]');
                if (textarea) {
                    textarea.style.display = 'block';
                    textarea.value = tkn;
                }
                if (typeof window.grecaptcha !== 'undefined') {
                    try {
                        if (window.___grecaptcha_cfg?.clients) {
                            for (const clientId of Object.keys(window.___grecaptcha_cfg.clients)) {
                                const client = window.___grecaptcha_cfg.clients[clientId];
                                const findCallback = (obj, depth = 0) => {
                                    if (depth > 5 || !obj) return null;
                                    for (const key of Object.keys(obj)) {
                                        if (typeof obj[key] === 'function' && key !== 'bind') return obj[key];
                                        if (typeof obj[key] === 'object' && obj[key] !== null) {
                                            const found = findCallback(obj[key], depth + 1);
                                            if (found) return found;
                                        }
                                    }
                                    return null;
                                };
                                const callback = findCallback(client);
                                if (callback) callback(tkn);
                            }
                        }
                    } catch (e) { /* ignore */ }
                }
            }, token);
        }
    }

    log('  Clicking ACCEPT button…');
    await screenshot(page, '02_before_accept');

    const acceptClicked = await page.evaluate(() => {
        const buttons = Array.from(document.querySelectorAll('button, [role="button"], a'));
        const accept = buttons.find(b =>
            b.textContent.trim().toUpperCase().includes('ACCEPT')
        );
        if (accept) { accept.click(); return true; }
        return false;
    });

    if (!acceptClicked) {
        log('  ERROR: Could not find ACCEPT button');
        await saveHtml(page, '02_no_accept_button');
        return false;
    }

    await sleep(DELAY);
    log(`  URL after accept: ${page.url()}`);
    await screenshot(page, '03_after_accept');

    if (page.url().includes('/cvs/search') || page.url().includes('/cvs/')) {
        log('  Successfully accepted ToS');
        return true;
    }

    // reCAPTCHA may have failed — retry
    const stillOnToS = await page.evaluate(() =>
        document.body.innerText.includes('Accept the terms')
    );
    if (stillOnToS) {
        log('  Still on ToS — reCAPTCHA may have failed. Retrying…');
        return loginAndAcceptTerms(page);
    }

    return page.url().includes('/cvs/');
}

// ── Patch Redux store: load coverage states ───────────────────────────────────
async function patchReduxStore(page) {
    for (let retry = 0; retry < 5; retry++) {
        if (retry > 0) {
            log(`    Redux patch retry ${retry}/4…`);
            await sleep(2000);
        }

        const result = await page.evaluate(() => {
            if (window.__EWCCV_REDUX_STORE__) {
                const store = window.__EWCCV_REDUX_STORE__;
                const state = store.getState();
                const hasStates = state.coverageStates && Array.isArray(state.coverageStates.statesList);
                if (!hasStates) store.dispatch({ type: 'FETCH_COVERAGE_STATES_REQUESTED' });
                return { ok: true, stateCount: hasStates ? state.coverageStates.statesList.length : 0, cached: true };
            }

            const root = document.getElementById('root');
            if (!root) return { ok: false, reason: 'no_root' };

            let startFiber = null;
            const fiberKey = Object.keys(root).find(k =>
                k.startsWith('__reactFiber$') || k.startsWith('__reactInternalInstance$')
            );
            if (fiberKey) startFiber = root[fiberKey];

            if (!startFiber && root._reactRootContainer) {
                try { startFiber = root._reactRootContainer._internalRoot.current; } catch (e) {}
            }
            if (!startFiber) {
                const containerKey = Object.keys(root).find(k => k.startsWith('__reactContainer$'));
                if (containerKey) startFiber = root[containerKey];
            }
            if (!startFiber) {
                const candidates = root.querySelectorAll('*');
                for (let i = 0; i < Math.min(candidates.length, 50); i++) {
                    const fk = Object.keys(candidates[i]).find(k =>
                        k.startsWith('__reactFiber$') || k.startsWith('__reactInternalInstance$')
                    );
                    if (fk) {
                        let f = candidates[i][fk];
                        while (f.return) f = f.return;
                        startFiber = f;
                        break;
                    }
                }
            }
            if (!startFiber) return { ok: false, reason: 'no_fiber' };

            let store = null;
            const queue = [startFiber];
            let attempts = 0;
            while (queue.length > 0 && attempts < 1000) {
                const f = queue.shift();
                attempts++;
                if (!f) continue;
                const sn = f.stateNode;
                if (sn && sn.store && typeof sn.store.dispatch === 'function') { store = sn.store; break; }
                const p = f.memoizedProps || f.pendingProps;
                if (p && p.store && typeof p.store.dispatch === 'function') { store = p.store; break; }
                if (f.child) queue.push(f.child);
                if (f.sibling) queue.push(f.sibling);
            }
            if (!store) return { ok: false, reason: 'no_store' };

            window.__EWCCV_REDUX_STORE__ = store;
            const state = store.getState();
            const hasStates = state.coverageStates && Array.isArray(state.coverageStates.statesList);
            if (!hasStates) store.dispatch({ type: 'FETCH_COVERAGE_STATES_REQUESTED' });
            return { ok: true, stateCount: hasStates ? state.coverageStates.statesList.length : 0 };
        });

        if (result.ok) {
            log(`    Redux patched: ${result.stateCount} states${result.cached ? ' (cached)' : ''}`);
            if (result.stateCount === 0) {
                await sleep(3000);
                const loaded = await page.evaluate(() => {
                    const store = window.__EWCCV_REDUX_STORE__;
                    if (!store) return 0;
                    const st = store.getState();
                    return st.coverageStates?.statesList?.length || 0;
                });
                log(`    States loaded: ${loaded}`);
            }
            return true;
        }
        if (retry === 4) log(`    Redux patch FAILED: ${result.reason}`);
    }
    return false;
}

// ── Simulate human behavior for reCAPTCHA v3 scoring ──────────────────────────
// reCAPTCHA Enterprise v3 collects user behavior data (mouse movements, scrolling,
// clicks, timing) from the moment the script loads. We need to build a natural
// behavior profile before executing the reCAPTCHA token to get a higher score.
async function simulateHumanBehavior(page, durationMs = 20000) {
    log('  Simulating human behavior for reCAPTCHA scoring…');
    const startTime = Date.now();

    const vp = page.viewport() || { width: 1366, height: 900 };
    const w = vp.width;
    const h = vp.height;

    // Ensure page has focus
    await page.bringToFront().catch(() => {});

    // Phase 1: Natural mouse movements — "looking around" the page
    const movePoints = [
        { x: w * 0.3, y: h * 0.2 },
        { x: w * 0.5, y: h * 0.4 },
        { x: w * 0.7, y: h * 0.3 },
        { x: w * 0.2, y: h * 0.6 },
        { x: w * 0.5, y: h * 0.5 },
        { x: w * 0.4, y: h * 0.7 },
        { x: w * 0.6, y: h * 0.2 },
    ];

    for (const pt of movePoints) {
        const jx = (Math.random() - 0.5) * 40;
        const jy = (Math.random() - 0.5) * 40;
        await page.mouse.move(pt.x + jx, pt.y + jy, {
            steps: 10 + Math.floor(Math.random() * 20),
        });
        await sleep(400 + Math.random() * 800);
    }

    // Phase 2: Scroll down and back up
    const scrollSteps = 3 + Math.floor(Math.random() * 3);
    for (let i = 0; i < scrollSteps; i++) {
        await page.mouse.wheel({ deltaY: 80 + Math.floor(Math.random() * 150) });
        await sleep(300 + Math.random() * 500);
    }
    await sleep(800);
    for (let i = 0; i < scrollSteps; i++) {
        await page.mouse.wheel({ deltaY: -(80 + Math.floor(Math.random() * 150)) });
        await sleep(300 + Math.random() * 500);
    }

    // Phase 3: Hover over form elements (simulates reading labels)
    const selectors = ['#state-selector', '#employerName', '#employer-form-search-button', '#datepicker'];
    for (const sel of selectors) {
        try {
            const el = await page.$(sel);
            if (el) {
                const box = await el.boundingBox();
                if (box) {
                    await page.mouse.move(
                        box.x + box.width * (0.3 + Math.random() * 0.4),
                        box.y + box.height * (0.3 + Math.random() * 0.4),
                        { steps: 8 + Math.floor(Math.random() * 12) }
                    );
                    await sleep(500 + Math.random() * 800);
                }
            }
        } catch (e) { /* element might not be visible */ }
    }

    // Phase 4: Click on neutral area + more movements
    await page.mouse.click(w * 0.85, h * 0.15);
    await sleep(300);

    for (let i = 0; i < 3; i++) {
        await page.mouse.move(
            100 + Math.random() * (w - 200),
            100 + Math.random() * (h - 200),
            { steps: 5 + Math.floor(Math.random() * 10) }
        );
        await sleep(300 + Math.random() * 600);
    }

    // Phase 5: Wait remaining time for reCAPTCHA behavior profiling
    const elapsed = Date.now() - startTime;
    const remaining = Math.max(0, durationMs - elapsed);
    if (remaining > 0) {
        log(`  Waiting ${Math.round(remaining / 1000)}s for reCAPTCHA behavior scoring…`);
        await sleep(remaining);
    }

    log('  Human behavior simulation complete');
}

// ── Search via UI (fill form, click search, read results from Redux) ──────────
// Uses the actual EWCCV search form instead of direct API calls.
// The React component handles reCAPTCHA verification internally when clicking search.
async function searchViaUI(page, vendor) {
    const fullStateName = STATE_NAMES[vendor.state?.toUpperCase()];
    if (!fullStateName) return { ok: false, reason: 'unknown_state: ' + vendor.state };

    // Ensure we're on the search page
    if (!page.url().includes('/cvs/search')) {
        await page.goto(`${BASE_URL}/cvs/search`, { waitUntil: 'networkidle2', timeout: 30000 });
        await sleep(2000);
        await patchReduxStore(page);
        await interceptRedirects(page);
    }

    // Clear previous search results from Redux
    await page.evaluate(() => {
        const store = window.__EWCCV_REDUX_STORE__;
        if (store) {
            store.dispatch({ type: 'SEARCH_BY_ADDRESS_SUCCEEDED', payload: { items: [] } });
        }
        // Also clear blocked redirects
        window.__blockedRedirects = [];
    });

    // 1. Switch to Address tab (tab index 2)
    log(`    UI: Switching to Address tab…`);
    const addressTabLabel = await page.$('#AddressTabLabel');
    if (!addressTabLabel) return { ok: false, reason: 'no_address_tab_label' };
    await addressTabLabel.click();
    await sleep(1000);

    // 2. Select state from MUI dropdown (inside address tab)
    log(`    UI: Selecting state "${fullStateName}"…`);

    // The address tab has its own state selector — find it within #address-tab
    const stateSelector = await page.$('#address-tab #state-selector');
    const fallbackSelector = stateSelector || await page.$('#state-selector');
    if (!fallbackSelector) return { ok: false, reason: 'no_state_selector' };

    // Check if correct state already selected
    const currentState = await page.evaluate((sel) => {
        const el = document.querySelector(sel);
        return el ? el.textContent.trim() : '';
    }, stateSelector ? '#address-tab #state-selector' : '#state-selector');

    if (!currentState.toLowerCase().includes(fullStateName.toLowerCase())) {
        await fallbackSelector.click();
        await sleep(1000);

        // MUI Select renders options in a portal (Popover) — look for MuiMenuItem
        const stateClicked = await page.evaluate((name) => {
            const options = Array.from(document.querySelectorAll(
                'li[role="option"], .MuiMenuItem-root, [data-value]'
            ));
            const nameLower = name.toLowerCase();
            const opt = options.find(o => o.textContent.trim().toLowerCase() === nameLower);
            if (opt) { opt.click(); return opt.textContent.trim(); }
            // Fuzzy match
            const fuzzy = options.find(o => o.textContent.trim().toLowerCase().includes(nameLower));
            if (fuzzy) { fuzzy.click(); return fuzzy.textContent.trim(); }
            return null;
        }, fullStateName);

        if (!stateClicked) {
            await page.keyboard.press('Escape');
            return { ok: false, reason: 'state_not_in_dropdown: ' + fullStateName };
        }
        log(`    UI: Selected "${stateClicked}"`);
        await sleep(1500);
    }

    // 3. Fill address fields with human-like typing
    const streetInput = await page.$('#streetLine1');
    if (!streetInput) return { ok: false, reason: 'no_street_input' };

    // Clear and type street address
    await streetInput.click({ clickCount: 3 });
    await sleep(200);
    await page.keyboard.press('Backspace');
    await sleep(200);
    const streetAddress = vendor.address || '';
    await streetInput.type(streetAddress, { delay: 40 + Math.random() * 40 });
    await sleep(400);
    log(`    UI: Typed street "${streetAddress}"`);

    // Fill city if available
    if (vendor.city) {
        const cityInput = await page.$('#city');
        if (cityInput) {
            await cityInput.click({ clickCount: 3 });
            await sleep(200);
            await page.keyboard.press('Backspace');
            await sleep(200);
            await cityInput.type(vendor.city, { delay: 40 + Math.random() * 40 });
            await sleep(300);
            log(`    UI: Typed city "${vendor.city}"`);
        }
    }

    // Fill zip if available
    if (vendor.zip) {
        const zipInput = await page.$('#zip');
        if (zipInput) {
            await zipInput.click({ clickCount: 3 });
            await sleep(200);
            await page.keyboard.press('Backspace');
            await sleep(200);
            await zipInput.type(String(vendor.zip), { delay: 40 + Math.random() * 40 });
            await sleep(300);
            log(`    UI: Typed zip "${vendor.zip}"`);
        }
    }

    // 4. Brief mouse movement before clicking search (natural behavior)
    const searchBtn = await page.$('#address-form-search-button');
    if (!searchBtn) return { ok: false, reason: 'no_address_search_button' };

    const btnBox = await searchBtn.boundingBox();
    if (btnBox) {
        await page.mouse.move(
            btnBox.x + btnBox.width / 2,
            btnBox.y + btnBox.height / 2,
            { steps: 8 + Math.floor(Math.random() * 10) }
        );
        await sleep(300 + Math.random() * 300);
    }

    // 5. Click search — triggers reCAPTCHA + search via the React component
    // Attach one-shot network logger covering the click + first 30s
    const requestLog = [];
    const onReq = (req) => {
        const u = req.url();
        if (u.startsWith('https://www.ewccv.com/cvs/')) {
            requestLog.push({ t: 'req', method: req.method(), url: u, ts: Date.now() });
        }
    };
    const onResp = async (resp) => {
        const u = resp.url();
        if (u.startsWith('https://www.ewccv.com/cvs/')) {
            requestLog.push({ t: 'resp', status: resp.status(), url: u, ts: Date.now() });
        }
    };
    page.on('request', onReq);
    page.on('response', onResp);

    // Snapshot Redux BEFORE click
    const reduxBefore = await page.evaluate(() => {
        const s = window.__EWCCV_REDUX_STORE__?.getState();
        if (!s) return 'no_store';
        return {
            recaptcha: s.recaptcha,
            searchAddress: s.search?.addressSearch ? { loadingStatus: s.search.addressSearch.loadingStatus, hasData: !!s.search.addressSearch.data } : null,
            sessionAccessToken: !!sessionStorage.getItem('accessToken'),
        };
    });
    log(`    UI: Redux BEFORE click: ${JSON.stringify(reduxBefore)}`);

    await searchBtn.click();
    log(`    UI: Clicked address search for "${streetAddress}, ${vendor.city || ''} ${vendor.zip || ''}" — waiting for results…`);

    // Snapshot Redux 1s and 5s after click
    await sleep(1000);
    const redux1s = await page.evaluate(() => {
        const s = window.__EWCCV_REDUX_STORE__?.getState();
        if (!s) return 'no_store';
        return {
            recaptcha: s.recaptcha,
            searchAddress: s.search?.addressSearch ? { loadingStatus: s.search.addressSearch.loadingStatus, hasData: !!s.search.addressSearch.data, error: s.search.addressSearch.data?.error } : null,
            blockedRedirects: (window.__blockedRedirects || []).length,
        };
    });
    log(`    UI: Redux +1s: ${JSON.stringify(redux1s)}`);

    await sleep(4000);
    const redux5s = await page.evaluate(() => {
        const s = window.__EWCCV_REDUX_STORE__?.getState();
        if (!s) return 'no_store';
        return {
            recaptcha: s.recaptcha,
            searchAddress: s.search?.addressSearch ? { loadingStatus: s.search.addressSearch.loadingStatus, hasData: !!s.search.addressSearch.data, error: s.search.addressSearch.data?.error } : null,
            blockedRedirects: (window.__blockedRedirects || []).slice(-5),
            grecaptchaCalls: (window.__grecaptchaCalls || []).length,
            lastVerify: window.__lastRecaptchaVerifyResult,
        };
    });
    log(`    UI: Redux +5s: ${JSON.stringify(redux5s)}`);
    log(`    UI: Network during 5s after click (${requestLog.length} requests):`);
    for (const r of requestLog.slice(-30)) {
        log(`      ${r.t.toUpperCase()} ${r.method || r.status} ${r.url.replace('https://www.ewccv.com', '')}`);
    }

    // 6. Wait for results (the app handles reCAPTCHA internally)
    let items = null;
    let stateCode = null;

    for (let attempt = 0; attempt < 45; attempt++) {
        await sleep(2000);

        const result = await page.evaluate(() => {
            const store = window.__EWCCV_REDUX_STORE__;
            if (!store) return { status: 'no_store' };

            const st = store.getState();
            const search = st.search?.addressSearch;
            const recaptcha = st.recaptcha;

            // Check for blocked redirects (from fetch intercept or location patches)
            const blocked = window.__blockedRedirects || [];
            const recentBlocked = blocked.filter(b =>
                typeof b === 'object' ? (Date.now() - b.ts < 120000) : true
            );

            // Check the last reCAPTCHA verify result (from our fetch intercept)
            const lastVerify = window.__lastRecaptchaVerifyResult;

            if (!search) {
                if (recentBlocked.length > 0) {
                    return { status: 'recaptcha_failed', redirects: recentBlocked.length, details: recentBlocked.slice(-2) };
                }
                return { status: 'no_search_state' };
            }

            if (search.loadingStatus) return { status: 'loading' };

            // Check if reCAPTCHA is in progress
            if (recaptcha?.shouldExecute && !recaptcha?.verified) {
                // Check if we already know the verify failed
                if (lastVerify?.data?.verified === false) {
                    return { status: 'recaptcha_failed', reason: 'verify_returned_false', redirects: recentBlocked.length };
                }
                return { status: 'recaptcha_pending', verified: recaptcha?.verified };
            }

            if (search.data?.items && search.data.items.length > 0) {
                return {
                    status: 'done',
                    items: search.data.items,
                    count: search.data.items.length,
                    stateCode: st.coverageStates?.selectedState?.stateCode || '',
                };
            }
            if (search.data?.error) {
                return { status: 'error', error: JSON.stringify(search.data.error).substring(0, 300) };
            }

            // If items is empty array (search completed with no results)
            if (search.data?.items && search.data.items.length === 0 && !search.loadingStatus) {
                if (recentBlocked.length > 0) {
                    return { status: 'recaptcha_failed', redirects: recentBlocked.length, details: recentBlocked.slice(-2) };
                }
                return { status: 'no_results' };
            }

            if (recentBlocked.length > 0) {
                return { status: 'recaptcha_failed', redirects: recentBlocked.length, details: recentBlocked.slice(-2) };
            }

            return { status: 'waiting', recaptchaState: recaptcha ? { shouldExecute: recaptcha.shouldExecute, verified: recaptcha.verified } : null };
        });

        if (result.status === 'done') {
            items = result.items;
            stateCode = result.stateCode;
            break;
        }
        if (result.status === 'no_results') {
            log('    UI: Search returned 0 results');
            return { ok: true, items: [], count: 0, stateCode: '' };
        }
        if (result.status === 'error') {
            return { ok: false, reason: 'search_error', error: result.error };
        }
        if (result.status === 'recaptcha_failed') {
            log(`    UI: reCAPTCHA failed (redirects: ${result.redirects}, details: ${JSON.stringify(result.details || [])})`);
            return { ok: false, reason: 'recaptcha_failed_ui' };
        }
        if (attempt > 0 && attempt % 5 === 0) {
            log(`      Still waiting… (attempt ${attempt + 1}/45, status: ${result.status}${result.recaptchaState ? ', recaptcha: ' + JSON.stringify(result.recaptchaState) : ''})`);
        }
        // After 20 seconds, take a screenshot for debugging
        if (attempt === 10) {
            await screenshot(page, `ui_search_waiting_${vendor.id}`);
        }
    }

    if (!items) {
        await screenshot(page, `ui_search_timeout_${vendor.id}`);
        return { ok: false, reason: 'ui_search_timeout' };
    }

    return { ok: true, items, count: items.length, stateCode };
}

// ── Verify reCAPTCHA v3 and obtain accessToken ────────────────────────────────
async function obtainAccessToken(page) {
    log('  Obtaining accessToken via reCAPTCHA v3 verify…');

    // Dump any grecaptcha calls the EWCCV app itself made — tells us the action it uses
    try {
        const grecaptchaCalls = await page.evaluate(() => window.__grecaptchaCalls || []);
        log(`    EWCCV native grecaptcha calls so far: ${JSON.stringify(grecaptchaCalls).substring(0, 500)}`);
    } catch (_) {}

    // Build stateTokenString from Redux states (reused across attempts)
    const stateTokenResult = await page.evaluate(() => {
        const store = window.__EWCCV_REDUX_STORE__;
        if (!store) return { str: '', count: 0, debug: 'no_store' };
        const states = store.getState().coverageStates?.statesList;
        if (!Array.isArray(states)) return { str: '', count: 0, debug: 'no_statesList' };
        const tokens = states.reduce((acc, s) => {
            if (s.stateToken && s.stateToken.length > 0) acc.push(s.stateToken);
            return acc;
        }, []);
        // Capture first 5 raw token values for debugging
        const sampleTokens = tokens.slice(0, 5).map(t => typeof t + ':' + String(t).substring(0, 40));
        return { str: tokens.join('-'), count: tokens.length, totalStates: states.length, sampleTokens };
    });
    const stateTokenString = stateTokenResult.str;
    log(`    stateTokenString len=${stateTokenString.length}, count=${stateTokenResult.count}, totalStates=${stateTokenResult.totalStates}`);
    log(`    sampleTokens: ${JSON.stringify(stateTokenResult.sampleTokens || stateTokenResult.debug)}`);
    if (stateTokenString.length > 0) log(`    first 120 chars: "${stateTokenString.substring(0, 120)}"`);

    // Helper: verify a v3 token against EWCCV endpoint
    async function verifyV3Token(v3Tok) {
        return page.evaluate(async (tok, stateTkn) => {
            const cookieToken = document.cookie.split(';').map(c => c.trim())
                .find(c => c.startsWith('token='));
            if (!cookieToken) return { ok: false, reason: 'no_cookie_token' };
            const rawValue = cookieToken.split('=').slice(1).join('=');
            // js-cookie's Cookies.get() decodes via decodeURIComponent — match that behavior
            const bearerToken = decodeURIComponent(rawValue);
            const wasDecoded = rawValue !== bearerToken;

            try {
                const res = await fetch('/cvs/endpoint/recaptcha/v3/verify/', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${bearerToken}`,
                        'x-sec-source-ip': '127.0.0.1',
                        'x-sec-tkn': stateTkn,
                    },
                    body: JSON.stringify({ key: tok }),
                });
                const json = await res.json();

                const diag = {
                    httpStatus: res.status,
                    cookieDecoded: wasDecoded,
                    bearerLen: bearerToken.length,
                    bearerStart: bearerToken.substring(0, 20),
                    stateTknLen: stateTkn.length,
                    recaptchaTokenLen: tok.length,
                    responseBody: JSON.stringify(json).substring(0, 300),
                };

                if (res.status !== 200) {
                    return { ok: false, reason: 'verify_http_' + res.status, diag };
                }

                if (json.data && json.data.verified && json.data.accessToken) {
                    sessionStorage.setItem('accessToken', json.data.accessToken);
                    return { ok: true, tokenLength: json.data.accessToken.length, diag };
                }
                const token = json.accessToken || json.data?.accessToken;
                if (token) {
                    sessionStorage.setItem('accessToken', token);
                    return { ok: true, tokenLength: token.length, diag };
                }
                return { ok: false, reason: 'no_accessToken_in_response', diag };
            } catch (e) {
                return { ok: false, reason: 'verify_error: ' + e.message };
            }
        }, v3Tok, stateTokenString);
    }

    // ── Control probe (EWCCV_DEBUG_CONTROL=1) ────────────────────────────────
    // Sends a deliberately invalid token first. If EWCCV answers identically to
    // a real token, it is not judging the token at all — it is refusing this
    // session (server-side bot scoring), and no captcha solver can ever help.
    // If the answers differ, our real tokens ARE evaluated and merely score low.
    if (process.env.EWCCV_DEBUG_CONTROL === '1') {
        const control = await verifyV3Token('CONTROL_INVALID_TOKEN_0000000000');
        log(`    [CONTROL] invalid-token verify → ${JSON.stringify(control).substring(0, 260)}`);
    }

    // ── Attempt 1: Native browser reCAPTCHA execution ────────────────────────
    // The page already loads grecaptcha.enterprise.js — execute it directly in the browser
    log('    Trying native browser reCAPTCHA v3 execution…');
    try {
        const nativeToken = await page.evaluate(async (sitekey) => {
            if (typeof grecaptcha === 'undefined' || !grecaptcha.enterprise) {
                return { ok: false, reason: 'grecaptcha_not_loaded' };
            }
            try {
                const token = await grecaptcha.enterprise.execute(sitekey, { action: 'default' });
                return { ok: true, token, length: token.length };
            } catch (e) {
                return { ok: false, reason: 'execute_error: ' + e.message };
            }
        }, RECAPTCHA_V3_SITEKEY);

        if (nativeToken.ok && nativeToken.token) {
            log(`    Native reCAPTCHA v3 token obtained — length: ${nativeToken.length}`);
            const verifyResult = await verifyV3Token(nativeToken.token);
            log(`    Verify result (native): ${JSON.stringify(verifyResult)}`);
            if (verifyResult.ok) return verifyResult;
            log('    Native token failed verification — falling back to external providers…');
        } else {
            log(`    Native reCAPTCHA not available: ${nativeToken.reason}`);
        }
    } catch (err) {
        log(`    Native reCAPTCHA error: ${err.message}`);
    }

    // ── Attempt 2: External captcha solving providers ────────────────────────
    if (captchaProviders.length === 0) {
        log('  WARNING: No captcha API keys — cannot solve reCAPTCHA v3');
        return { ok: false, reason: 'no_captcha_providers' };
    }

    for (const provider of captchaProviders) {
        let v3Token;
        try {
            v3Token = await solveRecaptchaV3WithProvider(page, provider, 0.9);
        } catch (err) {
            log(`    ${provider.name} v3 solve failed: ${err.message}`);
            if (captchaProviders.indexOf(provider) < captchaProviders.length - 1) {
                log(`    Trying next captcha provider…`);
            }
            continue;
        }

        if (!v3Token) {
            log(`    ${provider.name} returned no token, trying next…`);
            continue;
        }

        const verifyResult = await verifyV3Token(v3Token);
        log(`    Verify result (${provider.name}): ${JSON.stringify(verifyResult)}`);

        if (verifyResult.ok) {
            return verifyResult;
        }

        log(`    ${provider.name} token failed verification — trying next provider…`);
    }

    return { ok: false, reason: 'all_providers_failed_verification' };
}

// ── Resolve state abbreviation to numeric stateCode ──────────────────────────
// State objects in Redux: { stateCode: "12", name: "Illinois", stateToken: "uuid" }
// We need to map vendor state abbreviation "IL" → full name "Illinois" → stateCode "12"
function resolveStateCode(stateAbbr, statesList) {
    if (!stateAbbr || !Array.isArray(statesList)) return null;

    const fullName = STATE_NAMES[stateAbbr.toUpperCase()];
    if (!fullName) {
        log(`    WARNING: Unknown state abbreviation: ${stateAbbr}`);
        return null;
    }

    const match = statesList.find(s =>
        s.name && s.name.toLowerCase() === fullName.toLowerCase()
    );
    if (match && match.stateCode) {
        return match.stateCode;
    }

    log(`    WARNING: State "${fullName}" not found in ${statesList.length} EWCCV states`);
    return null;
}

// ── Search vendor via direct API call ─────────────────────────────────────────
async function searchViaApi(page, vendor, searchType) {
    const coverageDate = vendor.coverageDate || new Date().toLocaleDateString('en-US', {
        month: '2-digit', day: '2-digit', year: 'numeric',
    });

    const result = await page.evaluate(async (v, covDate, sType, stateNames) => {
        const accessToken = sessionStorage.getItem('accessToken');
        if (!accessToken) return { ok: false, reason: 'no_accessToken' };

        // Resolve state abbreviation → numeric stateCode via Redux statesList
        const store = window.__EWCCV_REDUX_STORE__;
        if (!store) return { ok: false, reason: 'no_redux_store' };

        const statesList = store.getState().coverageStates?.statesList;
        if (!Array.isArray(statesList) || statesList.length === 0) {
            return { ok: false, reason: 'no_statesList' };
        }

        // Map abbreviation → full name → stateCode
        const fullName = stateNames[v.state?.toUpperCase()];
        if (!fullName) return { ok: false, reason: 'unknown_state_abbr: ' + v.state };

        const stateObj = statesList.find(s => s.name && s.name.toLowerCase() === fullName.toLowerCase());
        if (!stateObj || !stateObj.stateCode) {
            return { ok: false, reason: 'state_not_in_ewccv: ' + fullName, availableStates: statesList.map(s => s.name).join(', ') };
        }

        const stateCode = stateObj.stateCode;
        const BASE = '/cvs/endpoint';
        const headers = {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${accessToken}`,
        };

        let url;
        if (sType === 'employer') {
            const name = encodeURIComponent(v.businessName);
            url = `${BASE}/insureds/coverage/stateCode/${stateCode}/searchType/employer/?employerName=${name}&searchMode=contains&coverageDate=${encodeURIComponent(covDate)}`;
        } else {
            // Address search
            let qs = `?streetLine1=${encodeURIComponent(v.address)}&coverageDate=${encodeURIComponent(covDate)}`;
            if (v.city) qs += `&city=${encodeURIComponent(v.city)}`;
            if (v.zip) qs += `&zipCode=${encodeURIComponent(v.zip)}`;
            url = `${BASE}/insureds/coverage/stateCode/${stateCode}/searchType/address/${qs}`;
        }

        console.log(`[EWCCV-API] ${sType} search: stateCode=${stateCode} (${fullName}), URL=${url}`);

        try {
            const res = await fetch(url, { method: 'GET', headers });
            const text = await res.text();
            let json;
            try { json = JSON.parse(text); } catch (e) {}

            if (res.status === 401) return { ok: false, reason: 'auth_expired', status: 401 };
            if (res.status !== 200) return { ok: false, reason: json?.error || `http_${res.status}`, status: res.status, body: (text || '').substring(0, 300) };

            if (json?.data?.items) {
                return { ok: true, items: json.data.items, count: json.data.items.length, stateCode };
            }
            return { ok: false, reason: 'no_items_in_response', data: JSON.stringify(json).substring(0, 300) };
        } catch (e) {
            return { ok: false, reason: 'fetch_error: ' + e.message };
        }
    }, vendor, coverageDate, searchType, STATE_NAMES);

    return result;
}

// ── Match search results to vendor ────────────────────────────────────────────
function matchResult(items, vendor) {
    if (!items || items.length === 0) return null;

    // Try policy number match first
    if (vendor.policyNumber) {
        const normalized = vendor.policyNumber.replace(/[\s-]/g, '').toUpperCase();
        const match = items.find(item => {
            const p = (item.policyNumber || item.writtenPolicyId || '').replace(/[\s-]/g, '').toUpperCase();
            return p === normalized || p.includes(normalized) || normalized.includes(p);
        });
        if (match) return { match, via: 'policyNumber' };
    }

    // Try employer name match
    if (vendor.businessName) {
        const normalized = vendor.businessName.toUpperCase().replace(/[^A-Z0-9]/g, '');
        const match = items.find(item => {
            const name = (item.employerName || item.name || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
            return name.includes(normalized) || normalized.includes(name);
        });
        if (match) return { match, via: 'employerName' };
    }

    // If only one result, use it
    if (items.length === 1) return { match: items[0], via: 'sole_result' };

    return null;
}

// ── Navigate to policy details and enable tracking ────────────────────────────
async function navigateToDetails(page, matchData) {
    // Dispatch SET_SELECTED_POLICY to Redux so the details page has the policy data
    const dispatched = await page.evaluate((data) => {
        const store = window.__EWCCV_REDUX_STORE__;
        if (!store) return false;
        store.dispatch({ type: 'SET_SELECTED_POLICY', payload: { selectedPolicy: data } });
        return true;
    }, matchData);

    if (!dispatched) {
        log('    Could not dispatch SET_SELECTED_POLICY');
        return false;
    }

    await page.goto(`${BASE_URL}/cvs/details`, {
        waitUntil: 'networkidle2',
        timeout: 30000,
    }).catch(() => {});

    await sleep(2000);
    return true;
}

// ── Enable tracking via subscription API ──────────────────────────────────────
// Directly calls the EWCCV tracking/subscription REST API instead of clicking
// UI buttons. This mirrors what the TrackingSignUp component does:
//   1. GET  /subscription/user/me            → fetch tracking user (userId, email)
//   2. GET  /subscription/subscription/...   → check existing subscription
//   3. POST /subscription/subscription/      → create subscription
async function enableTracking(page, vendor, matchedItem, searchMeta) {
    await screenshot(page, `detail_${vendor.id}`);

    const result = await page.evaluate(async (item, meta, loginEmail) => {
        const BASE = '/cvs/endpoint';
        const SUB_EP = 'subscription';

        // Read the cookie token (used for all tracking API calls)
        function getCookieToken() {
            const match = document.cookie.split(';').map(c => c.trim()).find(c => c.startsWith('token='));
            return match ? match.substring(6) : null;
        }

        const cookieToken = getCookieToken();
        if (!cookieToken) return { ok: false, reason: 'no_cookie_token' };

        const authHeaders = {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + cookieToken,
        };

        // Step 1: Fetch tracking user
        let userData = null;
        try {
            const userRes = await fetch(BASE + '/' + SUB_EP + '/user/me', {
                method: 'GET', headers: authHeaders,
            });
            if (userRes.status === 200) {
                const userJson = await userRes.json();
                if (userJson.data) userData = userJson.data;
            }
        } catch (e) {
            console.log('[EWCCV] fetchUser error:', e.message);
        }

        // If no user found, create one
        if (!userData) {
            try {
                const createRes = await fetch(BASE + '/' + SUB_EP + '/user/', {
                    method: 'POST',
                    headers: authHeaders,
                    body: JSON.stringify({ email: loginEmail }),
                });
                const createJson = await createRes.json();
                if (createRes.status === 200 && createJson.data) {
                    userData = createJson.data;
                } else {
                    return { ok: false, reason: 'create_user_failed', status: createRes.status, detail: JSON.stringify(createJson).substring(0, 300) };
                }
            } catch (e) {
                return { ok: false, reason: 'create_user_error: ' + e.message };
            }
        }

        if (!userData || !userData.userId) {
            return { ok: false, reason: 'no_user_data', data: JSON.stringify(userData).substring(0, 200) };
        }

        const userId = userData.userId;
        const email = userData.email || loginEmail;
        const writtenPolicyId = item.writtenPolicyId || item.policyNumber || '';

        // Step 2: Check if already subscribed
        try {
            const checkRes = await fetch(
                BASE + '/' + SUB_EP + '/subscription/userId=' + userId + '/writtenPolicyId=' + writtenPolicyId,
                { method: 'GET', headers: authHeaders }
            );
            if (checkRes.status === 200) {
                const checkJson = await checkRes.json();
                if (checkJson.data && Object.keys(checkJson.data).length > 0) {
                    return { ok: true, alreadyTracking: true, subscriptionId: checkJson.data.subscriptionId || null };
                }
            }
        } catch (e) {
            // Not fatal — subscription may just not exist yet
            console.log('[EWCCV] fetchSubscription check error:', e.message);
        }

        // Step 3: Build subscription payload (mirrors handleSearchResultsClick + createSubscription saga)
        // The payload shape: { ...searchResultItem, stateCode, coverageDate, searchType, searchTerm, userId, email }
        // With employer name base64-encoded if present
        const payload = {
            ...item,
            stateCode: meta.stateCode,
            coverageDate: meta.coverageDate,
            searchType: meta.searchType,
            searchTerm: meta.searchTerm,
            userId: userId,
            email: email,
        };

        // Encode employer name (base64) — mirrors encodeEmployerName() in bundle
        if (payload.policy && payload.policy.employer && payload.policy.employer.employer) {
            payload.policy.employer.employer = btoa(payload.policy.employer.employer);
        }
        if (payload.employer && payload.employer.employer) {
            payload.employer.employer = btoa(payload.employer.employer);
        }

        // Step 4: Create subscription
        try {
            const subRes = await fetch(BASE + '/' + SUB_EP + '/subscription/', {
                method: 'POST',
                headers: authHeaders,
                body: JSON.stringify(payload),
            });
            const subJson = await subRes.json();
            if (subRes.status === 200 && subJson.data) {
                return { ok: true, alreadyTracking: false, subscriptionData: subJson.data };
            }
            return { ok: false, reason: 'create_subscription_failed', status: subRes.status, detail: JSON.stringify(subJson).substring(0, 500) };
        } catch (e) {
            return { ok: false, reason: 'create_subscription_error: ' + e.message };
        }
    }, matchedItem, searchMeta, config.loginEmail || 'patryk.szady@live.com');

    if (!result.ok) {
        log(`    Tracking FAILED: ${result.reason}`);
        if (result.detail) log(`      Detail: ${result.detail}`);
        return { tracked: false, reason: result.reason };
    }

    if (result.alreadyTracking) {
        log('    Already tracking this policy');
        return { tracked: true, alreadyTracking: true };
    }

    log('    Subscription created successfully');
    return { tracked: true, alreadyTracking: false };
}

// ── Request login email ───────────────────────────────────────────────────────
async function acceptTermsIfPresent(page) {
    const onToS = await page.evaluate(() =>
        document.body.innerText.includes('Accept the terms')
    );
    if (!onToS) {
        return true;
    }

    log('  On Terms of Use page — solving reCAPTCHA…');
    await screenshot(page, 'tos_before_solve');

    const siteKey = await page.evaluate(() => {
        const el = document.querySelector('.g-recaptcha, [data-sitekey]');
        return el ? el.getAttribute('data-sitekey') : null;
    });

    if (!siteKey) {
        try {
            const { solved } = await page.solveRecaptchas();
            if (solved?.length > 0) {
                log('  Plugin solved reCAPTCHA');
                await sleep(2000);
            }
        } catch (e) {
            log(`  Plugin solve failed: ${e.message}`);
        }
    } else {
        log(`  reCAPTCHA v2 sitekey: ${siteKey}`);
        const token = await solveRecaptchaV2(page, siteKey);
        if (token) {
            await page.evaluate((tkn) => {
                const textarea = document.getElementById('g-recaptcha-response')
                    || document.querySelector('[name="g-recaptcha-response"]');
                if (textarea) {
                    textarea.style.display = 'block';
                    textarea.value = tkn;
                }
                if (typeof window.grecaptcha !== 'undefined') {
                    try {
                        if (window.___grecaptcha_cfg?.clients) {
                            for (const clientId of Object.keys(window.___grecaptcha_cfg.clients)) {
                                const client = window.___grecaptcha_cfg.clients[clientId];
                                const findCallback = (obj, depth = 0) => {
                                    if (depth > 5 || !obj) return null;
                                    for (const key of Object.keys(obj)) {
                                        if (typeof obj[key] === 'function' && key !== 'bind') return obj[key];
                                        if (typeof obj[key] === 'object' && obj[key] !== null) {
                                            const found = findCallback(obj[key], depth + 1);
                                            if (found) return found;
                                        }
                                    }
                                    return null;
                                };
                                const callback = findCallback(client);
                                if (callback) callback(tkn);
                            }
                        }
                    } catch (e) { /* ignore */ }
                }
            }, token);
        }
    }

    await screenshot(page, 'tos_after_solve');

    const acceptClicked = await page.evaluate(() => {
        const buttons = Array.from(document.querySelectorAll('button, [role="button"], a'));
        const accept = buttons.find(b =>
            b.textContent.trim().toUpperCase().includes('ACCEPT')
        );
        if (accept) { accept.click(); return true; }
        return false;
    });

    if (!acceptClicked) {
        log('  ERROR: Could not find ACCEPT button on ToS page');
        await saveHtml(page, 'tos_no_accept_button');
        return false;
    }

    log('  Clicked ACCEPT');
    await sleep(DELAY);
    await screenshot(page, 'tos_after_accept');

    const stillOnToS = await page.evaluate(() =>
        document.body.innerText.includes('Accept the terms')
    );
    return ! stillOnToS;
}

async function requestLoginEmail(page, email) {
    log('Navigating to EWCCV homepage to request login email…');

    const client = await page.createCDPSession();
    await client.send('Network.clearBrowserCookies');
    await client.detach();

    await page.goto(`${BASE_URL}/cvs/`, {
        waitUntil: 'networkidle2',
        timeout: 60000,
    });
    await sleep(DELAY);

    await dismissCookieConsent(page);
    await screenshot(page, 'homepage_loaded');

    // Accept terms of use if shown on homepage
    const termsOk = await acceptTermsIfPresent(page);
    if (! termsOk) {
        log('  ERROR: Could not accept terms of use');
        return false;
    }

    await screenshot(page, 'before_login_click');

    // Click LOGIN button (top-right header)
    const loginClicked = await page.evaluate(() => {
        const loginBtn = document.getElementById('login');
        if (loginBtn) { loginBtn.click(); return 'id:login'; }
        const buttons = Array.from(document.querySelectorAll('button, a, [role="button"]'));
        const btn = buttons.find(b => b.textContent.trim().toUpperCase() === 'LOGIN');
        if (btn) { btn.click(); return 'text:Login'; }
        return false;
    });

    if (!loginClicked) {
        log('  ERROR: Could not find Login button');
        await saveHtml(page, 'no_login_button');
        return false;
    }
    log(`  Clicked Login (${loginClicked})`);
    await sleep(DELAY);
    await screenshot(page, 'login_dialog_opened');

    // Wait for email input to appear (Material UI dialog can lazy-render)
    let emailInput = null;
    for (let i = 0; i < 10; i++) {
        emailInput = await page.$('input[type="email"], input[name="email"], input[placeholder*="email" i], #email');
        if (emailInput) break;
        await sleep(500);
    }
    // Fallback: any text input inside an open dialog
    if (! emailInput) {
        emailInput = await page.$('[role="dialog"] input[type="text"], .MuiDialog-root input[type="text"], [role="dialog"] input:not([type="hidden"])');
    }

    if (! emailInput) {
        log('  ERROR: No email input found in login dialog');
        await saveHtml(page, 'login_no_email_input');
        await screenshot(page, 'login_no_email_input');
        return false;
    }

    await emailInput.click({ clickCount: 3 });
    await sleep(200);
    await emailInput.type(email, { delay: 50 });
    log(`  Typed email: ${email}`);
    await screenshot(page, 'email_typed');
    await sleep(500);

    const submitClicked = await page.evaluate(() => {
        const dialog = document.querySelector('[role="dialog"], .MuiDialog-root');
        const scope = dialog || document;
        const buttons = Array.from(scope.querySelectorAll('button, input[type="submit"], [role="button"]'));
        const continueBtn = buttons.find(b => b.textContent.trim().toUpperCase() === 'CONTINUE');
        if (continueBtn) { continueBtn.click(); return 'CONTINUE'; }
        const signIn = buttons.find(b => {
            const text = b.textContent.trim().toUpperCase();
            return text.includes('SIGN IN') || text.includes('SUBMIT') || text.includes('SEND');
        });
        if (signIn) { signIn.click(); return signIn.textContent.trim(); }
        return false;
    });

    if (!submitClicked) {
        log('  No submit button — pressing Enter…');
        await page.keyboard.press('Enter');
    } else {
        log(`  Clicked "${submitClicked}"`);
    }

    await sleep(DELAY);
    await screenshot(page, 'after_submit');
    log('  Login email request submitted');
    return true;
}

// ── Main ──────────────────────────────────────────────────────────────────────
(async () => {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });

    // ── Mode: request-email ───────────────────────────────────────
    if (config.mode === 'request-email') {
        if (!config.loginEmail) {
            log('ERROR: loginEmail is required for request-email mode');
            process.exit(1);
        }
        log(`Mode: request-email — sending login link to ${config.loginEmail}`);

        const browser = await puppeteer.launch({
            headless: config.headless !== false ? 'new' : false,
            executablePath: findChromeBinary() || undefined,
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage',
                   '--disable-blink-features=AutomationControlled', '--window-size=1366,900',
                   ...(proxyServer ? [`--proxy-server=${proxyServer}`, '--ignore-certificate-errors'] : [])],
            defaultViewport: { width: 1366, height: 900 },
        });
        const page = await browser.newPage();
        await applyProxyAuth(page);
        await applyConsistentUserAgent(page);

        try {
            const sent = await requestLoginEmail(page, config.loginEmail);
            process.stdout.write(JSON.stringify({ emailSent: sent }));
            if (!sent) process.exit(1);
        } catch (err) {
            log(`ERROR: ${err.message}`);
            process.stdout.write(JSON.stringify({ emailSent: false, error: err.message }));
            process.exit(1);
        } finally {
            await browser.close();
        }
        process.exit(0);
    }

    // ── Mode: scrape (default) ────────────────────────────────────
    if (!config.loginUrl) {
        log('ERROR: loginUrl is required');
        process.exit(1);
    }
    if (vendors.length === 0) {
        log('No vendors to process');
        process.exit(0);
    }

    log(`Starting EWCCV scraper — ${vendors.length} vendor(s)`);
    log(`Headless: ${config.headless !== false}`);

    // Try launching Chrome directly (bypasses Puppeteer automation flags)
    // Fall back to puppeteer.launch if direct launch fails
    let browser, page, chromeProcess;
    const isHeadless = config.headless !== false;

    // Prepare extension path for both launch methods
    const extSrcDir = path.join(__dirname, 'ewccv-extension');
    const hasExtension = fs.existsSync(path.join(extSrcDir, 'manifest.json'));

    // launchInfo stores debug port/endpoint for reconnection after disconnect
    let launchInfo = null;

    const directLaunch = await launchChromeDirectly(isHeadless);
    if (directLaunch) {
        log('  Using direct Chrome launch (non-Puppeteer) for better reCAPTCHA scores');
        browser = await puppeteer.connect({
            browserWSEndpoint: directLaunch.browserWSEndpoint,
            defaultViewport: { width: 1366, height: 900 },
        });
        chromeProcess = directLaunch.chromeProcess;
        launchInfo = { debugPort: directLaunch.debugPort, type: 'direct' };
        // A page Chrome opened at startup was never routed through
        // puppeteer-extra's onPageCreated hook, so the stealth evasions are
        // NOT installed on it. Always create our own page (and discard the
        // startup tab) so the fingerprint patches actually apply — this is
        // what reCAPTCHA v3 scores.
        const startupPages = await browser.pages();
        page = await browser.newPage();
        for (const p of startupPages) { if (!p.isClosed()) await p.close().catch(() => {}); }
    } else {
        log('  Direct Chrome launch failed — falling back to puppeteer.launch');
        const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage',
                            '--disable-blink-features=AutomationControlled', '--window-size=1366,900'];
        if (proxyServer) { launchArgs.push(`--proxy-server=${proxyServer}`, '--ignore-certificate-errors'); }

        // Load extension via puppeteer.launch args
        if (hasExtension) {
            launchArgs.push(`--load-extension=${extSrcDir}`);
            log(`  Extension loaded via puppeteer.launch: ${extSrcDir}`);
        }

        browser = await puppeteer.launch({
            headless: isHeadless ? 'new' : false,
            executablePath: findChromeBinary() || undefined,
            args: launchArgs,
            defaultViewport: { width: 1366, height: 900 },
        });
        // Extract debug port from WebSocket URL for reconnection
        const wsUrl = browser.wsEndpoint();
        const debugPort = parseInt(new URL(wsUrl.replace('ws:', 'http:')).port, 10);
        launchInfo = { debugPort, type: 'puppeteer', wsEndpoint: wsUrl };
        log(`  puppeteer.launch debug port: ${debugPort}`);
        page = await browser.newPage();
    }

    await applyProxyAuth(page);
    await applyConsistentUserAgent(page);

    // Prove the stealth evasions are live on THIS page. reCAPTCHA v3 scores the
    // session, so a leak here (webdriver true, zero plugins, missing chrome
    // object) is the difference between a passing and a failing token.
    try {
        const fp = await page.evaluate(() => ({
            webdriver: navigator.webdriver,
            plugins: navigator.plugins.length,
            languages: (navigator.languages || []).join(','),
            chrome: typeof window.chrome,
            vendor: navigator.vendor,
            platform: navigator.platform,
        }));
        log(`  Fingerprint: ${JSON.stringify(fp)}`);
    } catch (e) { log(`  Fingerprint probe failed: ${e.message}`); }

    const results = { success: [], failed: [], skipped: [] };

    try {
        // Step 1: Login & accept ToS
        const loggedIn = await loginAndAcceptTerms(page);
        if (!loggedIn) {
            log('FATAL: Could not log in / accept terms');
            results.loginFailed = true;
            process.stdout.write(JSON.stringify(results));
            await browser.close();
            process.exit(2);
        }

        // Step 2: Navigate to search page and set up
        if (!page.url().includes('/cvs/search')) {
            await page.goto(`${BASE_URL}/cvs/search`, { waitUntil: 'networkidle2', timeout: 30000 });
            await sleep(DELAY);
        }

        // Step 3: Patch Redux to load states list
        const patched = await patchReduxStore(page);
        if (!patched) {
            log('WARNING: Could not patch Redux store — state resolution may fail');
        }

        // Step 4: Intercept page redirects (prevent React 401→/cvs/ redirects)
        await interceptRedirects(page);

        // Step 5: Obtain accessToken for API-driven search
        let useApiSearch = false;

        // ── Primary approach: stealth puppeteer + 2Captcha plugin ─────────
        // The extension-based CDP-disconnect approach proved unreliable
        // (cross-origin localStorage SecurityError after reconnect, plus
        // verify endpoint returning {verified:false}). We now go straight
        // to the headless automated path, which uses puppeteer-extra-plugin-
        // recaptcha with 2Captcha to solve and verify reCAPTCHA v3.
        // To re-enable the extension trick, set EWCCV_ENABLE_EXTENSION=1.
        if (process.env.EWCCV_ENABLE_EXTENSION === '1' && launchInfo && hasExtension) {
            log('  [opt-in] Trying extension-based reCAPTCHA…');
            const extResult = await tryExtensionRecaptcha(page, browser, launchInfo);

            if (extResult.ok) {
                useApiSearch = true;
                browser = extResult.browser;
                page = extResult.page;
                log('  Extension reCAPTCHA succeeded — will use API search for all vendors');
            } else {
                log(`  Extension reCAPTCHA failed: ${extResult.reason}`);
                if (extResult.browser) browser = extResult.browser;
                if (extResult.page) page = extResult.page;
            }
        }

        // ── Fallback: Headless automated approaches ──────────────────────
        // EWCCV_SKIP_API_SEARCH=1 forces UI-driven search (let EWCCV's React app
        // handle the reCAPTCHA verify call itself — it knows the right headers).
        // ── Bridged accessToken (browser extension) ──────────────────────
        // The strongest path: a credential minted in a REAL browser, where
        // Google's score is not the problem it is here. Inject it and every
        // captcha attempt below is skipped.
        if (!useApiSearch && config.bridgedAccessToken) {
            if (!page.url().includes('/cvs/search')) {
                await page.goto(`${BASE_URL}/cvs/search`, { waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {});
                await sleep(1500);
            }
            await page.evaluate((tok) => { sessionStorage.setItem('accessToken', tok); }, config.bridgedAccessToken);
            log(`  Using bridged accessToken from the browser extension (${config.bridgedAccessToken.length} chars) — no captcha needed.`);
            useApiSearch = true;
            await patchReduxStore(page);
            await interceptRedirects(page);
        }

        // A configured bypass key short-circuits every captcha path below.
        if (!useApiSearch && (config.bypassKey || process.env.EWCCV_BYPASS_KEY)) {
            if (!page.url().includes('/cvs/search')) {
                await page.goto(`${BASE_URL}/cvs/search`, { waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {});
                await sleep(1500);
            }
            const bypass = await tryBypassKey(page, config.bypassKey || process.env.EWCCV_BYPASS_KEY);
            if (bypass.ok) {
                useApiSearch = true;
                await patchReduxStore(page);
                await interceptRedirects(page);
            }
        }

        const skipApiSearch = process.env.EWCCV_SKIP_API_SEARCH === '1';
        if (!useApiSearch && isHeadless && !skipApiSearch) {
            log('  Falling back to automated reCAPTCHA approach…');
            await simulateHumanBehavior(page, 20000);
            const tokenResult = await obtainAccessToken(page);
            if (tokenResult.ok) {
                log(`  accessToken obtained (length: ${tokenResult.tokenLength})`);
                useApiSearch = true;
            } else {
                log(`  accessToken failed: ${tokenResult.reason}`);
            }
        } else if (skipApiSearch) {
            log('  EWCCV_SKIP_API_SEARCH=1 → going straight to UI-driven search');
        }

        // ── Manual reCAPTCHA fallback (visible mode only) ────────────────
        // EWCCV's reCAPTCHA v3 rejects every automated token from this
        // environment ("verified":false) — the provider tokens are valid but
        // Google scores the session too low. When a human is at the visible
        // browser, one hand-run search mints a real accessToken that every
        // subsequent vendor reuses via the API. Gated on visible mode so a
        // headless/cron run still fails fast instead of hanging 5 minutes.
        if (!useApiSearch && !isHeadless && process.env.EWCCV_MANUAL_RECAPTCHA !== '0') {
            if (!page.url().includes('/cvs/search')) {
                await page.goto(`${BASE_URL}/cvs/search`, { waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {});
                await sleep(1500);
            }
            const manual = await waitForManualRecaptcha(page);
            if (manual.ok) {
                log('  Manual accessToken captured — switching to API search for all vendors.');
                useApiSearch = true;
                await patchReduxStore(page);
                await interceptRedirects(page);
            } else {
                log(`  Manual reCAPTCHA fallback did not complete: ${manual.reason}`);
            }
        }

        if (!useApiSearch) {
            log('  WARNING: No accessToken — will try UI-driven search (may fail due to reCAPTCHA)');
        }

        // Re-patch Redux and intercept redirects after extension reconnect
        if (launchInfo && useApiSearch) {
            if (!page.url().includes('/cvs/search')) {
                await page.goto(`${BASE_URL}/cvs/search`, { waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {});
                await sleep(2000);
            }
            await patchReduxStore(page);
            await interceptRedirects(page);
        }

        log(`\nProcessing ${vendors.length} vendor(s)…\n`);

        // Step 6: For each vendor, search and enable tracking
        for (let i = 0; i < vendors.length; i++) {
            const vendor = vendors[i];
            log(`═══ Vendor ${i + 1}/${vendors.length}: ${vendor.businessName} (ID: ${vendor.id}) ═══`);

            if (!vendor.state || !vendor.address) {
                log('  SKIPPED: Missing state or address');
                results.skipped.push({ vendorId: vendor.id, businessName: vendor.businessName, reason: 'missing_address' });
                continue;
            }

            try {
                // Collect results from both search types
                let allItems = [];
                let lastApiResult = null;
                let resolvedStateCode = '';

                if (useApiSearch) {
                    // ── API-driven search (accessToken available) ─────────────
                    // Search by address first
                    const addrResult = await searchViaApi(page, vendor, 'address');
                    log(`    Address search: ${addrResult.ok ? addrResult.count + ' result(s)' : addrResult.reason}`);
                    if (addrResult.ok && addrResult.items) {
                        allItems.push(...addrResult.items);
                    }
                    lastApiResult = addrResult;

                    // Also try employer name search (more likely to find by business name)
                    const empResult = await searchViaApi(page, vendor, 'employer');
                    log(`    Employer search: ${empResult.ok ? empResult.count + ' result(s)' : empResult.reason}`);
                    if (empResult.ok && empResult.items) {
                        // Deduplicate by writtenPolicyId/policyNumber
                        for (const item of empResult.items) {
                            const key = item.writtenPolicyId || item.policyNumber || '';
                            if (key && !allItems.some(i => (i.writtenPolicyId || i.policyNumber) === key)) {
                                allItems.push(item);
                            } else if (!key) {
                                allItems.push(item);
                            }
                        }
                        lastApiResult = empResult;
                    }

                    // If we got a 401 from either, try re-obtaining accessToken then fall to UI
                    if ((addrResult.status === 401 || empResult.status === 401) && allItems.length === 0) {
                        log('    accessToken expired — re-verifying…');
                        const reToken = await obtainAccessToken(page);
                        if (reToken.ok) {
                            const retry = await searchViaApi(page, vendor, 'employer');
                            if (retry.ok && retry.items) allItems.push(...retry.items);
                            lastApiResult = retry;
                        } else {
                            log('    Re-verify failed — falling back to UI search');
                            useApiSearch = false;
                        }
                    }

                    resolvedStateCode = (addrResult.ok && addrResult.stateCode) || (empResult.ok && empResult.stateCode) || '';
                }

                // ── UI-driven search fallback ─────────────────────────────
                if (!useApiSearch && allItems.length === 0) {
                    log('    Using UI-driven search…');
                    // Brief human simulation before each UI search
                    await simulateHumanBehavior(page, 5000);

                    const uiResult = await searchViaUI(page, vendor);
                    log(`    UI search: ${uiResult.ok ? uiResult.count + ' result(s)' : uiResult.reason}`);
                    if (uiResult.ok && uiResult.items) {
                        allItems.push(...uiResult.items);
                        resolvedStateCode = uiResult.stateCode || '';
                    }
                    lastApiResult = uiResult;
                }

                if (allItems.length === 0) {
                    results.failed.push({
                        vendorId: vendor.id,
                        businessName: vendor.businessName,
                        reason: lastApiResult?.reason || 'no_results',
                    });
                    log(`  ✗ No results: ${lastApiResult?.reason || 'empty'}`);
                    continue;
                }

                log(`    Total unique results: ${allItems.length}`);

                // Match result
                const matched = matchResult(allItems, vendor);
                if (!matched) {
                    log(`    No matching result among ${allItems.length} items:`);
                    for (const item of allItems.slice(0, 10)) {
                        log(`      - ${item.employerName || item.name}: policy=${item.policyNumber || item.writtenPolicyId}`);
                    }
                    results.failed.push({
                        vendorId: vendor.id,
                        businessName: vendor.businessName,
                        reason: 'no_matching_result',
                        count: allItems.length,
                    });
                    continue;
                }

                log(`    Matched: ${matched.match.employerName || matched.match.name} via ${matched.via} (policy: ${matched.match.policyNumber || matched.match.writtenPolicyId})`);

                // Build search metadata (mirrors handleSearchResultsClick in the EWCCV app)
                const coverageDate = vendor.coverageDate || new Date().toLocaleDateString('en-US', {
                    month: '2-digit', day: '2-digit', year: 'numeric',
                });
                const searchMeta = {
                    stateCode: resolvedStateCode,
                    coverageDate: coverageDate,
                    searchType: matched.via === 'employerName' ? 'employer' : 'address',
                    searchTerm: matched.via === 'employerName' ? vendor.businessName : vendor.address,
                };

                // Navigate to details page (for screenshot/debugging)
                const nav = await navigateToDetails(page, matched.match);
                if (!nav) {
                    results.failed.push({ vendorId: vendor.id, businessName: vendor.businessName, reason: 'nav_to_details_failed' });
                    continue;
                }

                // Re-intercept redirects (new page load clears overrides)
                await interceptRedirects(page);

                // Enable tracking via subscription API
                const trackResult = await enableTracking(page, vendor, matched.match, searchMeta);
                if (trackResult.tracked || trackResult.alreadyTracking) {
                    results.success.push({
                        vendorId: vendor.id,
                        businessName: vendor.businessName,
                        matchType: matched.via,
                        tracked: trackResult.tracked,
                        alreadyTracking: trackResult.alreadyTracking || false,
                    });
                    log(`  ✓ Done: tracked=${trackResult.tracked}, already=${trackResult.alreadyTracking || false}`);
                } else {
                    results.failed.push({
                        vendorId: vendor.id,
                        businessName: vendor.businessName,
                        reason: trackResult.reason || 'tracking_failed',
                    });
                    log(`  ✗ Tracking failed: ${trackResult.reason || 'unknown'}`);
                }
            } catch (err) {
                log(`  ERROR: ${err.message}`);
                await screenshot(page, `error_${vendor.id}`).catch(() => {});
                results.failed.push({ vendorId: vendor.id, businessName: vendor.businessName, reason: err.message });
            }

            // Rate limit between vendors and recover page state
            if (i < vendors.length - 1) {
                const waitMs = DELAY + Math.random() * 2000;
                log(`  Waiting ${Math.round(waitMs)}ms…`);
                await sleep(waitMs);

                // Always recover the page for the next vendor — previous failures
                // (especially reCAPTCHA redirect blocks) can leave the page broken
                try {
                    const pages = await browser.pages();
                    page = pages.find(p => !p.isClosed()) || pages[0];
                    if (!page || page.isClosed()) {
                        log('    Page was closed — opening new tab');
                        page = await browser.newPage();
                        await applyProxyAuth(page);
                        await applyConsistentUserAgent(page);
                    }
                    await page.goto(`${BASE_URL}/cvs/search`, { waitUntil: 'networkidle2', timeout: 30000 });
                    await sleep(1000);
                    await patchReduxStore(page);
                    await interceptRedirects(page);
                } catch (recoverErr) {
                    log(`    Page recovery failed: ${recoverErr.message}`);
                }
            }
        }
    } catch (err) {
        log(`FATAL ERROR: ${err.message}`);
        await screenshot(page, 'fatal_error');
    } finally {
        await browser.close();
        // Kill Chrome process if we launched it directly
        if (chromeProcess) {
            try { chromeProcess.kill(); } catch (e) {}
        }
    }

    log(`\n${'═'.repeat(60)}`);
    log(`EWCCV Scraper Complete`);
    log(`  ✓ Success: ${results.success.length}`);
    log(`  ✗ Failed:  ${results.failed.length}`);
    log(`  ⊘ Skipped: ${results.skipped.length}`);
    log('═'.repeat(60));

    process.stdout.write(JSON.stringify(results));
})();
