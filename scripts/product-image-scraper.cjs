#!/usr/bin/env node
'use strict';

/**
 * Product Image Scraper (Stealth)
 *
 * Uses Puppeteer + stealth plugin to extract product images from
 * bot-protected sites (homedepot.com, kohler.com, ferguson.com, walmart.com, etc.)
 *
 * Usage:  node scripts/product-image-scraper.cjs <config.json>
 *
 * Config JSON keys:
 *   urls           – Object { index: url } of product page URLs to scrape
 *   chromePath     – Path to Chrome binary (optional)
 *   captchaApiKey  – anticaptcha API key (optional, for reCAPTCHA/Turnstile solving)
 *   twoCaptchaKey  – 2captcha API key (optional, for reCAPTCHA/Turnstile solving)
 *   headless       – true (default) | false for visible browser
 *   delayMs        – delay in ms after page load (default 3000)
 *   timeoutMs      – navigation timeout per page in ms (default 20000)
 *
 * Output: JSON to stdout: { "index": "imageUrl"|null, ... }
 */

const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
const RecaptchaPlugin = require('puppeteer-extra-plugin-recaptcha');
const fs = require('fs');

puppeteer.use(StealthPlugin());

// ── Config ────────────────────────────────────────────────────────────────────
const configPath = process.argv[2];
if (!configPath) {
    process.stderr.write('Usage: node product-image-scraper.cjs <config.json>\n');
    process.exit(1);
}

const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));

// Set up captcha solving if keys provided (prefer anticaptcha, fallback 2captcha)
const captchaKey = config.captchaApiKey || config.twoCaptchaKey || null;
const captchaProvider = config.captchaApiKey ? 'anticaptcha' : (config.twoCaptchaKey ? '2captcha' : null);

if (captchaKey && captchaProvider) {
    puppeteer.use(RecaptchaPlugin({
        provider: { id: captchaProvider, token: captchaKey },
        visualFeedback: false,
    }));
}

const urls = config.urls || {};
const HEADLESS = config.headless !== false;
const DELAY = config.delayMs || 3000;
const TIMEOUT = config.timeoutMs || 20000;

// ── Helpers ───────────────────────────────────────────────────────────────────
function log(msg) { process.stderr.write(`[product-image-scraper] ${msg}\n`); }
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

// Skip patterns for non-product images
const SKIP_PATTERNS = [
    '.svg', 'data:', '1x1', 'pixel', 'favicon', 'cart', 'logo', 'icon',
    'menu', 'close', 'hamburger', 'carat', 'navigation', 'nav-', 'mainnav',
    'spinner', 'loading', 'placeholder', 'blank', 'spacer', 'arrow',
    'social', 'facebook', 'twitter', 'instagram', 'pinterest', 'youtube',
    'badge', 'banner', 'promo', 'ad-', 'ads/', 'advertisement',
    'header-', 'footer-', 'btn-', 'button',
];

// Patterns that indicate an error/404 page image, not a real product image
const ERROR_IMAGE_PATTERNS = [
    'pagenotfound', 'page-not-found', 'page_not_found',
    '404', 'error', 'not-found', 'notfound', 'no-image',
    'broken', 'unavailable', 'missing', 'default-image',
    'no-photo', 'nophoto', 'no-product', 'noproduct',
];

// Error page title patterns — if the page title matches these, skip extraction
const ERROR_TITLE_PATTERNS = [
    /error/i, /not found/i, /404/i, /page.?not.?found/i,
    /access denied/i, /forbidden/i, /blocked/i, /captcha/i,
    /verify/i, /security check/i, /robot/i, /bot/i,
];

/**
 * Check if an image URL looks like an error/404/placeholder image.
 */
function isErrorImage(url) {
    if (!url) return true;
    const lower = url.toLowerCase();
    return ERROR_IMAGE_PATTERNS.some(p => lower.includes(p));
}

/**
 * Extract the best product image URL from a fully rendered page.
 * Priority: og:image → JSON-LD → large img tags
 */
async function extractProductImage(page, baseUrl) {
    // First check if the page looks like an error page
    const title = await page.title().catch(() => '');
    if (ERROR_TITLE_PATTERNS.some(p => p.test(title))) {
        log(`  Skipping error page: "${title}"`);
        return null;
    }

    // Check page content length — very short pages are likely blocked/error
    const htmlLength = await page.evaluate(() => document.documentElement.innerHTML.length);
    if (htmlLength < 3000) {
        log(`  Skipping short page (${htmlLength} chars)`);
        return null;
    }

    return await page.evaluate((skipPatterns, errorPatterns) => {
        function looksLikeError(url) {
            if (!url) return true;
            const lower = url.toLowerCase();
            return errorPatterns.some(p => lower.includes(p));
        }

        // 1. og:image meta tag
        const ogMeta = document.querySelector('meta[property="og:image"], meta[name="og:image"]');
        if (ogMeta) {
            const content = ogMeta.getAttribute('content');
            if (content && content.length > 10 && !content.includes('placeholder') && !looksLikeError(content)) {
                return content.startsWith('http') ? content : new URL(content, window.location.origin).href;
            }
        }

        // 2. JSON-LD structured data (Product schema)
        const ldScripts = document.querySelectorAll('script[type="application/ld+json"]');
        for (const script of ldScripts) {
            try {
                const data = JSON.parse(script.textContent);
                const items = Array.isArray(data) ? data : [data];
                for (const item of items) {
                    if (item['@type'] === 'Product' && item.image) {
                        const img = Array.isArray(item.image) ? item.image[0] : item.image;
                        if (typeof img === 'string') return img;
                        if (img && img.url) return img.url;
                    }
                    // Check @graph
                    if (item['@graph']) {
                        for (const node of item['@graph']) {
                            if (node['@type'] === 'Product' && node.image) {
                                const img2 = Array.isArray(node.image) ? node.image[0] : node.image;
                                if (typeof img2 === 'string') return img2;
                                if (img2 && img2.url) return img2.url;
                            }
                        }
                    }
                }
            } catch (e) { /* ignore parse errors */ }
        }

        // 3. Large img tags — filter out noise, find product images
        const allImgs = Array.from(document.querySelectorAll('img'));
        const candidates = [];

        for (const img of allImgs) {
            const src = img.src || img.getAttribute('data-src') || img.getAttribute('data-lazy-src') || '';
            if (!src || src.startsWith('data:')) continue;

            const srcLower = src.toLowerCase();
            const skip = skipPatterns.some(p => srcLower.includes(p));
            if (skip) continue;

            // Must be a real image format
            if (!/\.(jpe?g|png|webp|avif)/i.test(src) && !src.includes('/images/') && !src.includes('/media/')) continue;

            // Prefer images with natural dimensions (already loaded) or explicit width/height
            const w = img.naturalWidth || parseInt(img.getAttribute('width') || '0');
            const h = img.naturalHeight || parseInt(img.getAttribute('height') || '0');

            // Score: bigger is better, product-related CSS classes/alt text boost score
            let score = Math.max(w, 1) * Math.max(h, 1);

            const alt = (img.getAttribute('alt') || '').toLowerCase();
            const classes = (img.className || '').toLowerCase();
            const parent = (img.parentElement?.className || '').toLowerCase();

            // Boost product-related images
            if (/product|hero|main|primary|gallery|feature/.test(classes + ' ' + parent)) score *= 10;
            if (alt && alt.length > 5) score *= 3; // Descriptive alt text = likely product

            // Penalize tiny images
            if (w > 0 && w < 50) score *= 0.01;
            if (h > 0 && h < 50) score *= 0.01;

            candidates.push({ src, score });
        }

        candidates.sort((a, b) => b.score - a.score);

        if (candidates.length > 0) {
            const best = candidates[0].src;
            return best.startsWith('http') ? best : new URL(best, window.location.origin).href;
        }

        return null;
    }, SKIP_PATTERNS, ERROR_IMAGE_PATTERNS);
}

// ── Main ──────────────────────────────────────────────────────────────────────
(async () => {
    const results = {};
    let browser;

    try {
        const launchArgs = [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--disable-blink-features=AutomationControlled',
            '--window-size=1920,1080',
        ];

        const launchOpts = {
            headless: HEADLESS ? 'new' : false,
            args: launchArgs,
            defaultViewport: { width: 1920, height: 1080 },
        };

        if (config.chromePath) {
            launchOpts.executablePath = config.chromePath;
        }

        browser = await puppeteer.launch(launchOpts);
        const page = await browser.newPage();

        // Realistic browser fingerprint
        await page.setUserAgent(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
        );

        // Set extra headers to look more legitimate
        await page.setExtraHTTPHeaders({
            'Accept-Language': 'en-US,en;q=0.9',
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Sec-Fetch-Dest': 'document',
            'Sec-Fetch-Mode': 'navigate',
            'Sec-Fetch-Site': 'none',
            'Sec-Fetch-User': '?1',
        });

        // Override webdriver property
        await page.evaluateOnNewDocument(() => {
            Object.defineProperty(navigator, 'webdriver', { get: () => false });
            // Override plugins to look real
            Object.defineProperty(navigator, 'plugins', {
                get: () => [1, 2, 3, 4, 5],
            });
            // Override languages
            Object.defineProperty(navigator, 'languages', {
                get: () => ['en-US', 'en'],
            });
            // Fake chrome runtime
            window.chrome = { runtime: {} };
        });

        const indices = Object.keys(urls);
        log(`Processing ${indices.length} URLs...`);

        for (const index of indices) {
            const url = urls[index];
            log(`[${index}] Navigating to: ${url}`);

            try {
                await page.goto(url, {
                    waitUntil: 'networkidle2',
                    timeout: TIMEOUT,
                });

                // Wait for dynamic content to render
                await sleep(DELAY);

                // Wait for SPA frameworks to finish rendering (handles React/Angular SPAs like brizo.com)
                try {
                    await page.waitForFunction(
                        () => document.querySelectorAll('img[src]').length > 2
                            || document.documentElement.innerHTML.length > 5000,
                        { timeout: 8000 }
                    );
                } catch (_spaWait) {
                    // Timeout — page may be an error page or very minimal, continue to extraction checks
                }

                // Try to solve captchas if present and plugin is loaded
                if (captchaKey) {
                    try {
                        const { solved } = await page.solveRecaptchas();
                        if (solved && solved.length > 0) {
                            log(`[${index}] Solved ${solved.length} captcha(s), waiting for redirect...`);
                            await sleep(3000);
                            // Some sites redirect after captcha solve
                            await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 10000 }).catch(() => {});
                        }
                    } catch (captchaErr) {
                        // No captcha found or solving failed — continue
                        log(`[${index}] Captcha check: ${captchaErr.message?.substring(0, 60) || 'none found'}`);
                    }
                }

                // Extract the product image
                const imageUrl = await extractProductImage(page, url);

                if (imageUrl && !isErrorImage(imageUrl)) {
                    log(`[${index}] Found image: ${imageUrl.substring(0, 100)}`);
                    results[index] = imageUrl;
                } else {
                    if (imageUrl) log(`[${index}] Rejected error/placeholder image: ${imageUrl.substring(0, 80)}`);
                    else log(`[${index}] No product image found`);
                    results[index] = null;
                }
            } catch (navErr) {
                log(`[${index}] Navigation error: ${navErr.message?.substring(0, 100)}`);
                results[index] = null;
            }
        }
    } catch (err) {
        log(`Fatal error: ${err.message}`);
    } finally {
        if (browser) {
            await browser.close().catch(() => {});
        }
    }

    // Output results as JSON to stdout
    process.stdout.write(JSON.stringify(results));
})();
