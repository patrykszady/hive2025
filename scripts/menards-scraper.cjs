#!/usr/bin/env node
'use strict';

/**
 * Menards Receipt Scraper
 *
 * Uses Puppeteer + stealth plugin + 2captcha to:
 *   1. Log into menards.com
 *   2. Navigate to Receipt Lookup
 *   3. Iterate ALL cards in the Select Card dropdown
 *   4. For each card: paginate, select receipts within date range, Download Receipt
 *
 * Usage:  node scripts/menards-scraper.cjs <config.json>
 *
 * Config JSON keys:
 *   email          – Menards account email
 *   password       – Menards account password
 *   captchaApiKey  – 2captcha API key (optional if no CAPTCHA)
 *   outputDir      – Directory for receipt HTML files + manifest
 *   headless       – true (default) | false for visible browser
 *   since          – ISO date string cutoff (only receipts on/after this date)
 *   delayMs        – base delay between actions in ms (default 2000)
 *   userDataDir    – persistent Chromium profile directory. Strongly
 *                    recommended: without it every run is a brand-new browser
 *                    with no cookies and no device trust, which is what the
 *                    Imperva wall reacts to.
 *   proxy           – optional proxy URL, used only until the profile holds a
 *                    session (Imperva binds cookies to the issuing IP).
 *   cookies        – optional menards.com cookie jar borrowed from a real
 *                    browser (see scripts/menards-session-bridge). When present
 *                    the scraper replays it and skips sign-in altogether.
 */

const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
const RecaptchaPlugin = require('puppeteer-extra-plugin-recaptcha');
const fs = require('fs');
const path = require('path');

puppeteer.use(StealthPlugin());

// ── Config ────────────────────────────────────────────────────────────────────
const configPath = process.argv[2];
if (!configPath) {
    process.stderr.write('Usage: node menards-scraper.cjs <config.json>\n');
    process.exit(1);
}

const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));

if (config.captchaApiKey) {
    puppeteer.use(RecaptchaPlugin({
        // 'anticaptcha' is NOT a provider this plugin ships — puppeteer-extra-
        // plugin-recaptcha@3.6.8 bundles only dist/provider/2captcha.js, so every
        // solveRecaptchas() has thrown "Cannot find builtin provider with id
        // 'anticaptcha'" since 2026-03-22. Inside handleImpervaChallenge that is
        // swallowed by try/catch, but on the login path it is unguarded: the
        // moment Menards presents any [data-sitekey] element the run hard-dies
        // with an error that never mentions a captcha.
        provider: { id: '2captcha', token: config.captchaApiKey },
        visualFeedback: true,
    }));
}

const OUTPUT_DIR  = config.outputDir || '/tmp/menards-receipts';
const DELAY       = config.delayMs   || 2000;

// Date cutoff — receipts before this date are skipped
const SINCE = config.since ? new Date(config.since) : null;

// ── Helpers ───────────────────────────────────────────────────────────────────
function log(msg)       { process.stderr.write(`[menards] ${msg}\n`); }
function sleep(ms)      { return new Promise(r => setTimeout(r, ms)); }

async function screenshot(page, name) {
    const p = path.join(OUTPUT_DIR, `_debug_${name}.png`);
    await page.screenshot({ path: p, fullPage: true }).catch(() => {});
    log(`  screenshot → ${name}.png`);
}

async function saveHtml(page, name) {
    const html = await page.content();
    fs.writeFileSync(path.join(OUTPUT_DIR, `_debug_${name}.html`), html);
}

/** Try multiple selectors, return the first element found (or null). */
async function findFirst(page, selectors) {
    for (const sel of selectors) {
        try {
            const el = await page.$(sel);
            if (el) return { el, selector: sel };
        } catch {
            // Invalid selector (e.g. Playwright-only pseudo-classes) — skip
        }
    }
    return null;
}

/**
 * Set up CDP-based download handling so browser-triggered downloads
 * are saved to a known directory.
 */
async function setupDownloadBehavior(page, downloadPath) {
    const client = await page.createCDPSession();
    await client.send('Page.setDownloadBehavior', {
        behavior: 'allow',
        downloadPath: downloadPath,
    });
    return client;
}

/**
 * Wait for a new file to appear in the download directory.
 * Returns the full path of the downloaded file, or null on timeout.
 */
function waitForDownload(downloadDir, existingFiles, timeoutMs = 30000) {
    return new Promise((resolve) => {
        const startTime = Date.now();
        const interval = setInterval(() => {
            const currentFiles = fs.readdirSync(downloadDir);
            // Ignore .crdownload (Chrome partial) files
            const newFiles = currentFiles.filter(
                f => !existingFiles.includes(f) && !f.endsWith('.crdownload')
            );
            if (newFiles.length > 0) {
                clearInterval(interval);
                resolve(path.join(downloadDir, newFiles[0]));
            } else if (Date.now() - startTime > timeoutMs) {
                clearInterval(interval);
                resolve(null);
            }
        }, 500);
    });
}

/**
 * Parse a date from the transaction-id attribute or row date text.
 * txId format: "3131-7-8913-2026-03-20 14:07:33"
 * Row date text: "March 20, 2026 @ 2:07 PM"
 */
function parseTxDate(txId, dateText) {
    // Try parsing from txId first (more reliable)
    if (txId) {
        const match = txId.match(/(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})$/);
        if (match) return new Date(match[1] + 'T' + match[2]);
    }
    // Fallback: parse from display text
    if (dateText) {
        const cleaned = dateText.replace('@', '').replace(/\s+/g, ' ').trim();
        const d = new Date(cleaned);
        if (!isNaN(d.getTime())) return d;
    }
    return null;
}

/** Check if a receipt date is within our desired range */
function isWithinDateRange(txDate) {
    if (!SINCE) return true;   // no cutoff = take everything
    if (!txDate) return true;  // can't parse = include it to be safe
    return txDate >= SINCE;
}

// ── Imperva / hCaptcha handling ───────────────────────────────────────────────
async function handleImpervaChallenge(page) {
    const isImperva = await page.evaluate(() => {
        return !!document.querySelector('iframe[src*="_Incapsula_Resource"]')
            || document.title.includes('Incapsula')
            || !!document.querySelector('iframe#main-iframe[src*="_Incapsula_Resource"]');
    });

    if (!isImperva) return false;
    log('  Imperva/Incapsula challenge detected');
    await screenshot(page, '00_imperva_challenge');

    // ── Approach 1: Use the recaptcha plugin (supports hCaptcha + 2captcha natively) ──
    // The plugin auto-detects hCaptcha widgets including those inside iframes
    if (config.captchaApiKey) {
        log('  Attempting solve via recaptcha plugin (supports hCaptcha)…');
        try {
            const { captchas, solutions, solved, error } = await page.solveRecaptchas();
            log(`  Plugin result — captchas: ${captchas?.length || 0}, solved: ${solved?.length || 0}, error: ${error || 'none'}`);
            if (solved?.length > 0) {
                log('  Plugin solved the challenge successfully');
                await sleep(5000);
                // Check if we moved past the challenge
                const stillBlocked = await page.evaluate(() => {
                    return !!document.querySelector('iframe[src*="_Incapsula_Resource"]');
                });
                if (!stillBlocked) {
                    log('  Imperva challenge cleared!');
                    return true;
                }
                log('  Still on challenge page after plugin solve — will try manual approach');
            }
        } catch (pluginErr) {
            log(`  Plugin solve error: ${pluginErr.message}`);
        }
    }

    // ── Approach 2: Navigate into the Imperva iframe and try plugin there ──
    const mainIframe = await page.$('iframe#main-iframe');
    if (mainIframe) {
        const frame = await mainIframe.contentFrame();
        if (frame) {
            log('  Entered Imperva iframe — waiting for hCaptcha widget…');
            await sleep(5000); // let hCaptcha widget fully render

            // Try plugin on all frames explicitly
            if (config.captchaApiKey) {
                try {
                    const { solved } = await page.solveRecaptchas();
                    log(`  Plugin retry after iframe wait — solved: ${solved?.length || 0}`);
                    if (solved?.length > 0) {
                        await sleep(5000);
                        return true;
                    }
                } catch { /* ignore */ }
            }

            // ── Approach 3: Manual 2captcha JSON API v2 ──────────────────────
            // Extract sitekey from the iframe content
            const hcaptchaData = await frame.evaluate(() => {
                const hcDiv = document.querySelector('[data-sitekey], .h-captcha');
                if (hcDiv) return { sitekey: hcDiv.getAttribute('data-sitekey') };
                const hcIframe = document.querySelector('iframe[src*="hcaptcha.com"]');
                if (hcIframe) {
                    const match = hcIframe.src.match(/sitekey=([^&]+)/);
                    return match ? { sitekey: match[1] } : null;
                }
                return null;
            }).catch(() => null);

            if (hcaptchaData?.sitekey && config.captchaApiKey) {
                log(`  hCaptcha sitekey: ${hcaptchaData.sitekey}`);
                log(`  API key length: ${config.captchaApiKey.length}, starts with: ${config.captchaApiKey.substring(0, 4)}…`);

                try {
                    const https = require('https');

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

                    // ── Step 0: Validate API key by checking balance ──
                    log('  Checking anti-captcha balance…');
                    const balanceResp = await httpJsonPost('https://api.anti-captcha.com/getBalance', {
                        clientKey: config.captchaApiKey,
                    });
                    const balanceData = JSON.parse(balanceResp);
                    if (balanceData.errorId !== 0) {
                        throw new Error(`API key invalid: ${balanceData.errorCode} — ${balanceData.errorDescription}`);
                    }
                    log(`  Anti-captcha balance: $${balanceData.balance}`);

                    // ── Step 1: Submit hCaptcha via createTask ──
                    // ERROR_NO_SLOT_AVAILABLE is transient (no idle workers at our
                    // max bid) — retry with backoff instead of failing the whole run.
                    const pageUrl = page.url() || 'https://www.menards.com/main/login.html';
                    log('  Submitting hCaptcha to anti-captcha…');
                    let createData = null;
                    const CREATE_ATTEMPTS = 8;
                    for (let createAttempt = 1; createAttempt <= CREATE_ATTEMPTS; createAttempt++) {
                        const createResp = await httpJsonPost('https://api.anti-captcha.com/createTask', {
                            clientKey: config.captchaApiKey,
                            task: {
                                type: 'HCaptchaTaskProxyless',
                                websiteURL: pageUrl,
                                websiteKey: hcaptchaData.sitekey,
                            },
                        });
                        log(`  createTask response: ${createResp.trim()}`);
                        createData = JSON.parse(createResp);

                        if (createData.errorId === 0) break;

                        if (createData.errorCode !== 'ERROR_NO_SLOT_AVAILABLE' || createAttempt === CREATE_ATTEMPTS) {
                            throw new Error(`createTask failed: ${createData.errorCode} — ${createData.errorDescription}`);
                        }

                        const backoff = Math.min(15000 * createAttempt, 60000);
                        log(`  No anti-captcha workers available — retry ${createAttempt}/${CREATE_ATTEMPTS - 1} in ${backoff / 1000}s…`);
                        await sleep(backoff);
                    }

                    const taskId = createData.taskId;
                    log(`  Task ID: ${taskId}`);

                    // ── Step 2: Poll for result via getTaskResult ──
                    let token = null;
                    for (let attempt = 0; attempt < 60; attempt++) {
                        await sleep(5000);
                        const resultResp = await httpJsonPost('https://api.anti-captcha.com/getTaskResult', {
                            clientKey: config.captchaApiKey,
                            taskId: taskId,
                        });
                        const resultData = JSON.parse(resultResp);

                        if (resultData.status === 'ready') {
                            token = resultData.solution?.gRecaptchaResponse || resultData.solution?.token;
                            break;
                        }
                        if (resultData.errorId !== 0) {
                            throw new Error(`getTaskResult failed: ${resultData.errorCode}`);
                        }
                        log(`  Waiting for hCaptcha solution… (attempt ${attempt + 1})`);
                    }

                    if (!token) throw new Error('hCaptcha solve timed out after 5 minutes');
                    log(`  hCaptcha solved — token length: ${token.length}`);

                    // Inject the token into the iframe
                    await frame.evaluate((tkn) => {
                        const fire = (el) => {
                            el.dispatchEvent(new Event('input', { bubbles: true }));
                            el.dispatchEvent(new Event('change', { bubbles: true }));
                        };
                        const ta = document.querySelector('[name="h-captcha-response"], textarea[name="h-captcha-response"]');
                        if (ta) { ta.value = tkn; fire(ta); }
                        const gta = document.querySelector('[name="g-recaptcha-response"]');
                        if (gta) { gta.value = tkn; fire(gta); }
                        // Try hCaptcha JS callback
                        if (typeof window.hcaptcha !== 'undefined') {
                            try {
                                const wids = window.hcaptcha.getAllWidgets ? window.hcaptcha.getAllWidgets() : [0];
                                for (const w of wids) { try { window.hcaptcha.setResponse(tkn, w); } catch {} }
                            } catch {}
                        }
                        const hcEl = document.querySelector('[data-callback]');
                        if (hcEl) {
                            const cb = hcEl.getAttribute('data-callback');
                            if (cb && typeof window[cb] === 'function') window[cb](tkn);
                        }
                        const form = document.querySelector('form');
                        if (form) form.submit();
                    }, token);

                    // Also try on the parent page
                    await page.evaluate((tkn) => {
                        const ta = document.querySelector('[name="h-captcha-response"], [name="g-recaptcha-response"]');
                        if (ta) ta.value = tkn;
                        const form = document.querySelector('form');
                        if (form) form.submit();
                    }, token).catch(() => {});

                    await sleep(5000);
                    log(`  After hCaptcha submit — URL: ${page.url()}`);
                    await screenshot(page, '00_after_imperva');
                    return true;
                } catch (err) {
                    log(`  Manual hCaptcha solve failed: ${err.message}`);

                    // Transient captcha-capacity outage: nothing we can do this
                    // run — exit with EX_TEMPFAIL (75) so the artisan command
                    // logs a skip (not an error) and the next scheduled run retries.
                    if (err.message.includes('ERROR_NO_SLOT_AVAILABLE')) {
                        log('  SKIP: anti-captcha has no idle workers — deferring to the next scheduled run');
                        process.exit(75);
                    }
                }
            } else {
                log('  Could not find hCaptcha sitekey in iframe');
            }
        }
    }

    log('  WARNING: Imperva challenge could not be solved automatically');
    return false;
}

// ── Login ─────────────────────────────────────────────────────────────────────
/**
 * An Imperva-blocked page renders as an empty shell: no body text, no inputs.
 * The real Menards login page has a full nav + form (~17 inputs).
 */
async function pageLooksWalled(page) {
    return page.evaluate(() => {
        const text = (document.body?.innerText || '').trim();
        const inputs = document.querySelectorAll('input').length;
        return text.length < 50 && inputs === 0;
    }).catch(() => false);
}

async function login(page) {
    log('Navigating to login page…');
    // domcontentloaded, not networkidle2. Menards keeps analytics and sensor
    // connections open, so "no more than 2 in-flight requests for 500ms" can
    // simply never happen and the goto times out after 60s on a page that
    // rendered fine seconds earlier — which is exactly how the 2026-08-22 run
    // failed. Wait for the form we actually need instead of for silence.
    const response = await page.goto('https://www.menards.com/main/login.html', {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
    });

    // The login form is the real readiness signal. Absent it, we are either
    // still loading or looking at an Imperva shell — both handled below.
    await page.waitForSelector('#username, input[name="username"], input[type="password"]', {
        timeout: 30000,
    }).catch(() => log('  (login form not visible yet — continuing to the wall checks)'));
    log(`  HTTP status: ${response ? response.status() : 'no response'}`);
    log(`  Final URL: ${page.url()}`);
    // Imperva's sensor can trigger a quick re-navigation right after load,
    // destroying the execution context mid-call — tolerate it and settle.
    try {
        log(`  Page title: ${await page.title()}`);
    } catch (err) {
        log(`  Page title unavailable (navigation in flight: ${err.message}) — waiting for page to settle…`);
        await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 15000 }).catch(() => {});
        await sleep(2000);
        log(`  URL after settle: ${page.url()}`);
        log(`  Page title: ${await page.title().catch(() => '(unavailable)')}`);
    }

    // Check for Imperva/hCaptcha WAF challenge before anything else.
    // Even a correctly solved captcha sometimes fails to clear the wall —
    // Imperva scores the whole session (datacenter IPs are penalized) and can
    // keep serving an empty shell page. Retry the challenge cycle a few times;
    // if the wall persists, defer to the next scheduled run instead of failing.
    const WALL_ATTEMPTS = 3;
    for (let wallAttempt = 1; wallAttempt <= WALL_ATTEMPTS; wallAttempt++) {
        const impervaHandled = await handleImpervaChallenge(page);
        if (impervaHandled) {
            log(`  Imperva challenge handled (attempt ${wallAttempt}) — reloading login page…`);
            await page.goto('https://www.menards.com/main/login.html', {
                waitUntil: 'networkidle2',
                timeout: 60000,
            }).catch(() => {});
        }

        await sleep(3000);

        if (!(await pageLooksWalled(page))) {
            break; // real content rendered
        }

        if (wallAttempt === WALL_ATTEMPTS) {
            log(`  SKIP: Imperva wall persisted after ${WALL_ATTEMPTS} challenge attempts — deferring to the next scheduled run`);
            process.exit(75);
        }

        log(`  Page is still an empty Imperva shell — retrying challenge cycle (${wallAttempt}/${WALL_ATTEMPTS})…`);
        await sleep(10000 * wallAttempt);
        await page.goto('https://www.menards.com/main/login.html', {
            waitUntil: 'networkidle2',
            timeout: 60000,
        }).catch(() => {});
    }

    // Wait for Vue.js to render the login form
    await sleep(3000);
    log(`  URL after wait: ${page.url()}`);
    await screenshot(page, '01_login_page');
    await saveHtml(page, '01_login_page');

    if (!page.url().includes('login')) {
        log('Appears already logged in (redirected away).');
        return;
    }

    // Click Sign In tab (try multiple selectors — Menards periodically changes their markup)
    const signInTab = await findFirst(page, [
        '[data-at-id="loginTab"]',
        '[role="tab"]:first-child',
        '.tab-signin',
    ]);
    if (signInTab) {
        await signInTab.el.click();
        await sleep(1500);
        log('  Clicked Sign In tab');
    } else {
        // Try clicking tab via evaluate for text-based matching
        const clickedTab = await page.evaluate(() => {
            const tabs = document.querySelectorAll('button, [role="tab"], a');
            for (const t of tabs) {
                if (/^\s*sign\s*in\s*$/i.test(t.textContent)) {
                    t.click();
                    return true;
                }
            }
            return false;
        });
        if (clickedTab) {
            await sleep(1500);
            log('  Clicked Sign In tab (text match)');
        }
    }

    // Wait for an input field to appear (Vue rendering)
    await page.waitForSelector('input[type="email"], input[type="text"], input[type="password"], #username', { timeout: 15000 }).catch(() => {});
    await sleep(500);

    // Dump all visible inputs for debugging when field detection fails
    const inputDebug = await page.evaluate(() => {
        return Array.from(document.querySelectorAll('input')).map(el => ({
            id: el.id, name: el.name, type: el.type,
            placeholder: el.placeholder,
            autocomplete: el.autocomplete,
            ariaLabel: el.getAttribute('aria-label'),
            dataAtId: el.getAttribute('data-at-id'),
            className: el.className,
            visible: el.offsetParent !== null,
        }));
    });
    log(`  Found ${inputDebug.length} input(s): ${JSON.stringify(inputDebug)}`);

    let emailResult = await findFirst(page, [
        '#username',
        '#emailAddress',
        '#email',
        '#loginEmail',
        'input[type="email"]',
        '#loginForm input[name="username"]',
        'input[name="email"]',
        'input[name="emailAddress"]',
        'input[name="logonId"]',
        'input[autocomplete="email"]',
        'input[autocomplete="username"]',
        'input[data-at-id="emailAddress"]',
        'input[data-at-id="email"]',
        'input[aria-label*="mail" i]',
        'input[aria-label*="Email" i]',
        'input[placeholder*="mail" i]',
        'input[placeholder*="Email" i]',
    ]);

    // Fallback: find the input associated with an "Email" label
    if (!emailResult) {
        log('  Primary selectors failed — trying label-based fallback…');
        const fallbackEl = await page.evaluateHandle(() => {
            // Method 1: find label with "email" text, then the linked input
            for (const label of document.querySelectorAll('label')) {
                if (/email/i.test(label.textContent)) {
                    const forId = label.getAttribute('for');
                    if (forId) {
                        const input = document.getElementById(forId);
                        if (input) return input;
                    }
                    // label wraps the input
                    const input = label.querySelector('input');
                    if (input) return input;
                    // next sibling
                    const next = label.nextElementSibling;
                    if (next && next.tagName === 'INPUT') return next;
                }
            }
            // Method 2: first visible text/email input that isn't a password
            const inputs = document.querySelectorAll('input[type="text"], input[type="email"], input:not([type])');
            for (const inp of inputs) {
                if (inp.offsetParent !== null && inp.type !== 'hidden') return inp;
            }
            return null;
        });
        if (fallbackEl && fallbackEl.asElement()) {
            emailResult = { el: fallbackEl.asElement(), selector: '(label-based fallback)' };
        }
    }

    if (!emailResult) {
        // Extra debug: save page state right before failing
        await screenshot(page, '01b_email_field_not_found');
        await saveHtml(page, '01b_email_field_not_found');
        const bodyText = await page.evaluate(() => document.body?.innerText?.substring(0, 2000) || '(empty)');
        log(`  Page body text (first 2000 chars): ${bodyText}`);
        log(`  Current URL: ${page.url()}`);
        const cookies = await page.cookies();
        log(`  Cookies: ${JSON.stringify(cookies.map(c => c.name))}`);
        throw new Error('Cannot find email/username field on login page. Inputs found: ' + JSON.stringify(inputDebug));
    }
    log(`  email field → ${emailResult.selector}`);

    let passResult = await findFirst(page, [
        '#login-password',
        '#password',
        '#loginPassword',
        'input[type="password"]',
        '#loginForm input[name="password"]',
        'input[name="logonPassword"]',
        'input[name="password"]',
        'input[data-at-id="password"]',
    ]);
    if (!passResult) {
        await screenshot(page, '01c_password_field_not_found');
        await saveHtml(page, '01c_password_field_not_found');
        throw new Error('Cannot find password field on login page. Inputs found: ' + JSON.stringify(inputDebug));
    }
    log(`  password field → ${passResult.selector}`);

    await emailResult.el.click({ clickCount: 3 });
    await emailResult.el.type(config.email, { delay: 40 });
    await sleep(300);
    await passResult.el.click({ clickCount: 3 });
    await passResult.el.type(config.password, { delay: 40 });

    await page.evaluate(() => {
        for (const el of document.querySelectorAll('input[type="email"], input[type="text"], input[type="password"], input:not([type="hidden"])')) {
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    await sleep(500);
    await screenshot(page, '02_credentials_filled');

    const hasRecaptcha = await page.$('iframe[src*="recaptcha"], .g-recaptcha, [data-sitekey]');
    if (hasRecaptcha) {
        log('  reCAPTCHA detected — sending to 2captcha…');
        const { solved } = await page.solveRecaptchas();
        log(`  reCAPTCHA solved (${solved?.length || 0} challenge(s))`);
        await screenshot(page, '03_captcha_solved');
    }

    const loginBtn = await findFirst(page, [
        '[data-at-id="loginButton"]',
        'button[data-at-id="signInButton"]',
    ]);
    if (loginBtn) {
        try {
            await page.waitForSelector(`${loginBtn.selector}:not([disabled])`, { timeout: 5000 });
        } catch {
            log('  Login button still disabled — removing disabled attribute');
            await page.evaluate(() => {
                const btn = document.querySelector('[data-at-id="loginButton"], [data-at-id="signInButton"]');
                if (btn) btn.removeAttribute('disabled');
            });
        }
        await loginBtn.el.click();
    } else {
        const submitResult = await findFirst(page, [
            '#loginForm button',
            'button[type="submit"]',
            'input[type="submit"]',
        ]);
        if (!submitResult) {
            // Last resort: find button with "Sign In" text
            const signInBtn = await page.evaluateHandle(() => {
                for (const btn of document.querySelectorAll('button')) {
                    if (/sign\s*in/i.test(btn.textContent) && btn.offsetParent !== null) return btn;
                }
                return null;
            });
            if (signInBtn && signInBtn.asElement()) {
                await signInBtn.asElement().click();
            } else {
                await passResult.el.press('Enter');
            }
        } else {
            await submitResult.el.click();
        }
    }

    await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {});
    await sleep(DELAY);
    await screenshot(page, '04_after_login');
    await saveHtml(page, '04_after_login');

    // Classify what actually came back, instead of calling everything a wall.
    //
    // The saved dumps show at least four distinct outcomes that this code used
    // to collapse into "Imperva wall" or "navigation timeout":
    //   * 2026-08-21 19:07 — <title>Internal Service Error 500 at Menards®</title>
    //   * 2026-08-18 01:07 — reached checkcredentials.html, then zero cards
    //   * 2026-08-22 02:22 — login page rendered fully (15 inputs)
    //   * a Menards page whose SPA never hydrated (real title, zero inputs)
    // Only the first kind of shell is actually Imperva; the others are a server
    // error, a rendering failure, and a genuine sign-in. Reporting them as one
    // thing is what sent this investigation after a bot wall for days.
    const outcome = await page.evaluate(() => {
        const title = document.title || '';
        const text = (document.body?.innerText || '').trim();
        const html = document.documentElement?.outerHTML || '';

        return {
            title,
            url: location.href,
            textLen: text.length,
            inputs: document.querySelectorAll('input').length,
            // Genuine Incapsula markers — not merely "the page looks empty".
            imperva: /_Incapsula_Resource|Request unsuccessful|Incapsula incident/i.test(html)
                || !!document.querySelector('iframe[src*="_Incapsula_Resource"]'),
            serverError: /Internal Service Error|Service Unavailable|\b50[0-9]\b/i.test(title),
            errorText: (document.querySelector('.error-message, .alert-danger, .login-error, .errorMessage')?.textContent || '').trim(),
        };
    }).catch(() => null);

    if (outcome) {
        log(`  After login — title: "${outcome.title}" | url: ${outcome.url}`);
        log(`  Page shape — inputs: ${outcome.inputs}, text: ${outcome.textLen} chars, imperva markers: ${outcome.imperva}`);
    }

    if (outcome?.serverError) {
        await saveHtml(page, '04_server_error');
        // Menards' own application failed. Transient by nature, and nothing
        // about the scraper can fix it — retry rather than alarm.
        throw new Error(`MENARDS_SERVER_ERROR: the site returned "${outcome.title}" after submitting credentials.`);
    }

    if (outcome?.errorText) {
        throw new Error(`Login failed: ${outcome.errorText}`);
    }

    if (outcome?.imperva) {
        await saveHtml(page, '04_imperva_block');
        throw new Error(`IMPERVA_BLOCK: Incapsula markers present after sign-in (${outcome.url}).`);
    }

    // checkcredentials.html is the login form's own action target — it has been
    // since at least 2026-03-22, and landing on it is NORMAL, not a stall. What
    // matters is whether the page behind it hydrated.
    if (outcome && outcome.inputs === 0 && outcome.textLen < 50) {
        await saveHtml(page, '04_not_hydrated');
        throw new Error(`PAGE_NOT_HYDRATED: "${outcome.title}" rendered no content (${outcome.url}) — the SPA bootstrap did not run.`);
    }

    if (/\/login\.html/i.test(page.url()) || page.url().includes('error=true')) {
        await saveHtml(page, '04_login_bounced');
        throw new Error(`LOGIN_REJECTED: back on the login page (${page.url()}) after submitting credentials.`);
    }

    log(`  Login complete — URL: ${page.url()}`);
}

// ── Get all card options from the dropdown ────────────────────────────────────
async function getCardOptions(page) {
    return page.evaluate(() => {
        const select = document.querySelector('#paymentOptionSelect');
        if (!select) return [];
        return Array.from(select.options)
            .filter(o => o.value !== '0' && o.value !== 'add_card' && !o.disabled)
            .map(o => ({ value: o.value, text: o.textContent.trim() }));
    });
}

// ── Select a card and wait for receipts to load ──────────────────────────────
async function selectCard(page, cardOption) {
    log(`\n══════════════════════════════════════`);
    log(`Selecting card: ${cardOption.text}`);
    log(`══════════════════════════════════════`);

    await page.select('#paymentOptionSelect', cardOption.value);
    await sleep(DELAY);
}

// ── Get receipt rows from the current page ────────────────────────────────────
async function getPageRows(page) {
    return page.evaluate(() => {
        const rows = Array.from(document.querySelectorAll('div[id^="txrecRow-"].transaction-row'));
        return rows.map((row, idx) => {
            const divs = Array.from(row.children);
            const date = divs[1]?.textContent?.trim() || '';
            const store = divs[2]?.textContent?.trim() || '';
            const amount = divs[3]?.textContent?.trim() || '';
            const checkbox = row.querySelector('input[name="selectedTx"]');
            const txId = checkbox?.getAttribute('data-transaction-id') || '';
            const checkboxId = checkbox?.id || '';
            return { index: idx, date, store, amount, txId, checkboxId, rowId: row.id };
        });
    });
}

// ── Process one card: paginate, download each matching receipt individually ────
async function processCard(page, cardOption, cardIndex, downloadDir) {
    const allReceipts = [];
    let currentPage = 1;

    await screenshot(page, `card_${cardIndex}_page_01`);

    let hasMore = true;
    while (hasMore) {
        log(`\n  ━━━ ${cardOption.text} — Page ${currentPage} ━━━`);

        const rows = await getPageRows(page);
        log(`  Found ${rows.length} rows`);

        if (rows.length === 0) {
            log('  No receipts on this page');
            break;
        }

        // Determine which rows match our date range
        const matchingRows = [];
        let allTooOld = false;

        for (const row of rows) {
            const txDate = parseTxDate(row.txId, row.date);
            const inRange = isWithinDateRange(txDate);

            log(`    ${row.date}  ${row.store}  ${row.amount}  ${inRange ? '✓' : '✗ (too old)'}`);

            if (inRange) {
                matchingRows.push(row);
            } else if (SINCE && txDate && txDate < SINCE) {
                allTooOld = true;
            }
        }

        if (matchingRows.length > 0) {
            // Download each receipt INDIVIDUALLY to avoid multi-receipt PDFs
            for (const row of matchingRows) {
                log(`\n  Downloading receipt: ${row.date} — ${row.amount}`);

                // Uncheck all checkboxes first
                await page.evaluate(() => {
                    const allCb = document.querySelector('#allTx');
                    if (allCb && allCb.checked) allCb.click();
                    const cbs = document.querySelectorAll('input[name="selectedTx"]:checked');
                    cbs.forEach(cb => cb.click());
                });
                await sleep(300);

                // Check only this one row
                if (row.checkboxId) {
                    await page.evaluate((cbId) => {
                        const cb = document.getElementById(cbId);
                        if (cb && !cb.checked) cb.click();
                    }, row.checkboxId);
                }
                await sleep(500);

                // Snapshot files before download
                const filesBefore = fs.readdirSync(downloadDir)
                    .filter(f => !f.endsWith('.crdownload'));

                // Click "Download Receipt" button
                const downloadClicked = await page.evaluate(() => {
                    const btn = document.querySelector('#downloadReceipt');
                    if (btn) { btn.click(); return true; }
                    return false;
                });

                if (!downloadClicked) {
                    log('    WARNING: Could not find Download Receipt button — skipping');
                    continue;
                }

                // Wait for the PDF to appear in the download directory
                const downloadedFile = await waitForDownload(downloadDir, filesBefore, 30000);

                if (!downloadedFile) {
                    log('    WARNING: Download timed out — no PDF file appeared');
                    await screenshot(page, `card_${cardIndex}_download_timeout_p${currentPage}`);
                    continue;
                }

                log(`    Downloaded: ${path.basename(downloadedFile)}`);

                // Build a safe filename from the receipt metadata
                const txDate = parseTxDate(row.txId, row.date);
                const dateStr = txDate
                    ? txDate.toISOString().slice(0, 10)
                    : row.date.replace(/[^a-zA-Z0-9]/g, '_');
                const amountStr = row.amount.replace(/[^0-9.\-]/g, '');
                const safeStore = row.store.replace(/[^a-zA-Z0-9]/g, '_').substring(0, 30);
                const ext = path.extname(downloadedFile) || '.pdf';
                const newName = `menards_${dateStr}_${amountStr}_${safeStore}${ext}`;
                const newPath = path.join(OUTPUT_DIR, newName);

                fs.renameSync(downloadedFile, newPath);
                log(`    Renamed → ${newName}`);

                allReceipts.push({
                    card:       cardOption.text,
                    date:       row.date,
                    store:      row.store,
                    amount:     row.amount,
                    txId:       row.txId,
                    page:       currentPage,
                    file:       newName,
                    fileType:   ext.replace('.', ''),
                });

                await sleep(1000); // Brief pause between downloads
            }
        } else {
            log('  No matching receipts on this page');
        }

        // If all remaining receipts are too old, no need to continue paginating
        if (allTooOld) {
            log('  Remaining receipts are older than cutoff — stopping pagination for this card');
            break;
        }

        // ── Next page ─────────────────────────────────────────────────────
        const nextClicked = await page.evaluate(() => {
            const next = document.querySelector('button[data-testid="pagination-next"]');
            if (next && next.getAttribute('aria-disabled') !== 'true' && !next.disabled) {
                next.click();
                return true;
            }
            return false;
        });

        if (nextClicked) {
            currentPage++;
            await sleep(3000);
        } else {
            log('  No more pages for this card.');
            hasMore = false;
        }
    }

    return allReceipts;
}

// ── Main ──────────────────────────────────────────────────────────────────────
(async () => {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });

    log(`Date cutoff (since): ${SINCE ? SINCE.toISOString() : 'none (all dates)'}`);

    // Persistent profile. Every run used to launch a brand-new, cookie-less
    // Chromium: no Imperva cookies, no history, no device trust — a first-time
    // headless browser, every single time, which is the most bot-like thing we
    // could present. Keeping the profile lets Imperva's incap_*/visid_* cookies
    // and whatever device trust we earn survive between runs. This is the same
    // call the Yelp automation on gs.construction makes, where wiping the
    // profile on each attempt is what produced the challenge/429 spiral.
    const launchArgs = [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--window-size=1280,900',
    ];

    const browser = await puppeteer.launch({
        headless: config.headless !== false ? 'new' : false,
        userDataDir: config.userDataDir || undefined,
        args: launchArgs,
    });

    try {
        const page = await browser.newPage();
        await page.setViewport({ width: 1280, height: 900 });

        // Set up a temporary download directory for CDP-captured PDFs
        const downloadDir = path.join(OUTPUT_DIR, '_downloads');
        fs.mkdirSync(downloadDir, { recursive: true });
        await setupDownloadBehavior(page, downloadDir);

        // A borrowed session skips sign-in entirely. Imperva refuses the
        // scraper's own login even when the hCaptcha is solved, so when the
        // browser extension has handed us a real signed-in jar we replay that
        // instead of fighting the wall. Falls back to logging in when there is
        // no jar, so nothing changes on a box that has not paired one.
        const borrowed = Array.isArray(config.cookies) ? config.cookies : [];

        if (borrowed.length > 0) {
            log(`Using borrowed session — ${borrowed.length} cookie(s) from the browser bridge`);
            await page.setCookie(...borrowed);
            await page.goto('https://www.menards.com/main/home.html', {
                waitUntil: 'networkidle2',
                timeout: 60000,
            });
            await sleep(DELAY);
            await screenshot(page, '01_borrowed_session');

            if (/\/login\.html/i.test(page.url())) {
                await saveHtml(page, '01_borrowed_rejected');
                throw new Error(`SESSION_NOT_ESTABLISHED: the borrowed session was refused (landed on ${page.url()}) — re-send it from the browser extension.`);
            }
        } else {
            await login(page);
        }

        // Navigate to receipt lookup
        log('Navigating to receipt lookup…');
        await page.goto('https://www.menards.com/main/receiptLookup.html', {
            waitUntil: 'networkidle2',
            timeout: 60000,
        });
        await sleep(DELAY);
        await screenshot(page, '05_receipt_lookup');

        // Get all available cards
        const cards = await getCardOptions(page);
        log(`Found ${cards.length} card(s): ${cards.map(c => c.text).join(', ')}`);

        if (cards.length === 0) {
            // An empty dropdown almost never means "this account has no cards" —
            // it means we are not actually signed in and are looking at the login
            // page. Say that, instead of sending the next person to debug a
            // dropdown selector that was never the problem.
            const url = page.url();

            if (/\/login\.html|checkcredentials/i.test(url)) {
                throw new Error(`SESSION_NOT_ESTABLISHED: receipt lookup redirected to ${url} — the session did not survive (bot wall).`);
            }

            throw new Error(`No cards found in the Select Card dropdown (URL: ${url})`);
        }

        const allReceipts = [];

        for (let i = 0; i < cards.length; i++) {
            await selectCard(page, cards[i]);
            const cardReceipts = await processCard(page, cards[i], i, downloadDir);
            allReceipts.push(...cardReceipts);
            log(`  Card "${cards[i].text}" — ${cardReceipts.length} receipts collected`);

            // If there are more cards, go back to receipt lookup
            if (i < cards.length - 1) {
                await page.goto('https://www.menards.com/main/receiptLookup.html', {
                    waitUntil: 'networkidle2',
                    timeout: 60000,
                });
                await sleep(DELAY);
            }
        }

        const manifest = {
            scrapedAt:     new Date().toISOString(),
            since:         SINCE ? SINCE.toISOString() : null,
            totalReceipts: allReceipts.length,
            cards:         cards.map(c => c.text),
            receipts:      allReceipts,
        };

        fs.writeFileSync(
            path.join(OUTPUT_DIR, 'manifest.json'),
            JSON.stringify(manifest, null, 2),
        );

        console.log(JSON.stringify(manifest));
        log(`\nDone — ${allReceipts.length} receipts scraped across ${cards.length} card(s).`);
    } catch (err) {
        log(`FATAL: ${err.message}`);
        log(`Stack: ${err.stack}`);
        // Try to capture final page state for debugging
        try {
            const pages = await browser.pages();
            const activePage = pages[pages.length - 1];
            if (activePage) {
                await screenshot(activePage, 'XX_fatal_error');
                await saveHtml(activePage, 'XX_fatal_error');
                log(`  Error page URL: ${activePage.url()}`);
                log(`  Error page title: ${await activePage.title()}`);
            }
        } catch (debugErr) {
            log(`  (could not capture debug state: ${debugErr.message})`);
        }
        console.log(JSON.stringify({ error: err.message }));
        // Navigation/network timeouts are menards.com or Imperva having a slow
        // moment — same "transient, retry next scheduled run" class as
        // anti-captcha capacity (75), not a real scraper failure.
        // SESSION_NOT_ESTABLISHED is the bot wall silently refusing the session
        // rather than a broken scraper or bad credentials — those still fail hard
        // as "Login failed", so this cannot mask a real credential problem.
        // Only genuine network/site blips are transient. SESSION_NOT_ESTABLISHED
        // was in this list and should never have been: exit 75 is reported as a
        // SUCCESS by the command, which is precisely how a full week of zero
        // receipts went unnoticed. A session that will not establish needs a
        // human, so it must exit 1 and alarm.
        const transient = /Navigation timeout|net::ERR_TIMED_OUT|net::ERR_CONNECTION|net::ERR_NETWORK_CHANGED|MENARDS_SERVER_ERROR/i.test(err.message);
        process.exit(transient ? 75 : 1);
    } finally {
        await browser.close();
    }
})();
