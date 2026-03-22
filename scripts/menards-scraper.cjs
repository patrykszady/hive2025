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
        const el = await page.$(sel);
        if (el) return { el, selector: sel };
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

// ── Login ─────────────────────────────────────────────────────────────────────
async function login(page) {
    log('Navigating to login page…');
    await page.goto('https://www.menards.com/main/login.html', {
        waitUntil: 'networkidle2',
        timeout: 60000,
    });

    // Wait for Vue.js to render the login form
    await sleep(3000);
    await screenshot(page, '01_login_page');
    await saveHtml(page, '01_login_page');

    if (!page.url().includes('login')) {
        log('Appears already logged in (redirected away).');
        return;
    }

    const signInTab = await page.$('[data-at-id="loginTab"]');
    if (signInTab) {
        await signInTab.click();
        await sleep(1000);
        log('  Clicked Sign In tab');
    }

    // Wait for an input field to appear (Vue rendering)
    await page.waitForSelector('input[type="email"], input[type="text"], #username', { timeout: 15000 }).catch(() => {});
    await sleep(500);

    const emailResult = await findFirst(page, [
        '#username',
        'input[type="email"]',
        '#loginForm input[name="username"]',
        'input[name="email"]',
        'input[name="logonId"]',
        'input[autocomplete="email"]',
        'input[autocomplete="username"]',
    ]);
    if (!emailResult) throw new Error('Cannot find email/username field on login page');
    log(`  email field → ${emailResult.selector}`);

    const passResult = await findFirst(page, [
        '#login-password',
        'input[type="password"]',
        '#loginForm input[name="password"]',
        'input[name="logonPassword"]',
    ]);
    if (!passResult) throw new Error('Cannot find password field on login page');
    log(`  password field → ${passResult.selector}`);

    await emailResult.el.click({ clickCount: 3 });
    await emailResult.el.type(config.email, { delay: 40 });
    await sleep(300);
    await passResult.el.click({ clickCount: 3 });
    await passResult.el.type(config.password, { delay: 40 });

    await page.evaluate(() => {
        for (const el of document.querySelectorAll('input[type="email"], input[type="text"], input[type="password"], #username, #login-password')) {
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

    const loginBtn = await page.$('[data-at-id="loginButton"]');
    if (loginBtn) {
        try {
            await page.waitForSelector('[data-at-id="loginButton"]:not([disabled])', { timeout: 5000 });
        } catch {
            log('  Login button still disabled — removing disabled attribute');
            await page.evaluate(() => {
                const btn = document.querySelector('[data-at-id="loginButton"]');
                if (btn) btn.removeAttribute('disabled');
            });
        }
        await loginBtn.click();
    } else {
        const submitResult = await findFirst(page, [
            '#loginForm button',
            'button[type="submit"]',
            'input[type="submit"]',
        ]);
        if (submitResult) {
            await submitResult.el.click();
        } else {
            await passResult.el.press('Enter');
        }
    }

    await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {});
    await sleep(DELAY);
    await screenshot(page, '04_after_login');
    await saveHtml(page, '04_after_login');

    const errorText = await page.$eval(
        '.error-message, .alert-danger, .login-error, .errorMessage',
        el => el.textContent.trim()
    ).catch(() => null);

    if (errorText) {
        throw new Error(`Login failed: ${errorText}`);
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

    const browser = await puppeteer.launch({
        headless: config.headless !== false ? 'new' : false,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--window-size=1280,900',
        ],
    });

    try {
        const page = await browser.newPage();
        await page.setViewport({ width: 1280, height: 900 });

        // Set up a temporary download directory for CDP-captured PDFs
        const downloadDir = path.join(OUTPUT_DIR, '_downloads');
        fs.mkdirSync(downloadDir, { recursive: true });
        await setupDownloadBehavior(page, downloadDir);

        await login(page);

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
            throw new Error('No cards found in the Select Card dropdown');
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
        log(`FATAL: ${err.message}\n${err.stack}`);
        console.log(JSON.stringify({ error: err.message }));
        process.exit(1);
    } finally {
        await browser.close();
    }
})();
