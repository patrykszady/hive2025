/**
 * Hive — Menards Receipt Sync (content script)
 *
 * Runs on https://www.menards.com/main/receiptLookup.html inside a browser that
 * is already signed in, and calls the same three JSON endpoints the page's own
 * front-end calls. Captured from a real session on 2026-08-22:
 *
 *   GET  /main/my-account/receipt-lookup/initialize.ajx
 *        -> {"paymentOptions":[{tenderId, cardTypeName, maskedCardNumber}, …]}
 *   POST /main/my-account/receipt-lookup/receipts.ajx
 *        {"skuUpc":"", "selectedPaymentOption":<tenderId>, "pageNumber":0,
 *         "includeTotalAvailable":true}
 *        -> {"transactionData":{"totalAvailable":175,"transactions":[…]}}
 *   POST /main/my-account/receipt-lookup/download.ajx
 *        {"transactions":[{…transaction…, id:"<store>-<ws>-<seq>-<txDate>"}],
 *         "selectedPaymentOption":<tenderId>}
 *        -> {"receipt":"<base64 pdf>"}
 *
 * Every request is same-origin with the page's own cookies and CSRF token, so
 * there is nothing to imitate — this IS the signed-in browser making the call it
 * would make if a person clicked Download. No DOM scraping either: the old
 * scraper read div[id^="txrecRow-"] rows out of the rendered page, which is both
 * slower and breaks whenever the markup shifts.
 */

const API = {
    initialize: '/main/my-account/receipt-lookup/initialize.ajx',
    receipts: '/main/my-account/receipt-lookup/receipts.ajx',
    download: '/main/my-account/receipt-lookup/download.ajx',
};

/** Page size the site itself uses; totalAvailable drives how many pages we walk. */
const PAGE_SIZE = 10;

/** Stop conditions, so a first run on a 175-transaction card cannot run away. */
const MAX_PAGES_PER_CARD = 40;

/** Be unhurried between calls — this is a background sync, not a race. */
const DELAY_MS = 1200;

const sleep = ms => new Promise(r => setTimeout(r, ms));

function csrfToken() {
    // The token the front-end sends. Present as a meta tag on every rendered
    // page; window.__CSRF_TOKENS__ is where session/init.ajx stashes a refreshed
    // one, so prefer that when it exists.
    const meta = document.querySelector('meta[name="_csrf"]')?.getAttribute('content');
    return meta || null;
}

async function api(path, body) {
    const headers = { Accept: 'application/json' };
    const token = csrfToken();

    if (token) headers['X-CSRF-TOKEN'] = token;

    const init = {
        method: body ? 'POST' : 'GET',
        // Same-origin cookies. This is the whole point: the browser's real
        // session, not a replayed one.
        credentials: 'include',
        headers,
    };

    if (body) {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
    }

    const res = await fetch(path, init);

    if (!res.ok) throw new Error(`${path} -> HTTP ${res.status}`);

    const text = await res.text();

    // An HTML body means the session lapsed and we were handed a login page.
    if (text.trimStart().startsWith('<')) {
        throw new Error(`${path} returned HTML — the browser session has expired, sign in again.`);
    }

    return JSON.parse(text);
}

/** The composite key download.ajx wants; the server never sends it. */
function transactionId(t) {
    return `${t.storeNumber}-${t.workstationId}-${t.sequenceNumber}-${t.transactionDate}`;
}

/**
 * Walk every card, newest transactions first, stopping at `sinceDate`.
 *
 * Transactions come back newest-first, so once a page is entirely older than the
 * cutoff there is nothing further back worth reading on that card.
 */
async function collect(sinceDate, onProgress) {
    const { paymentOptions = [] } = await api(API.initialize);
    const wanted = [];

    for (const card of paymentOptions) {
        let page = 0;
        let total = null;

        while (page < MAX_PAGES_PER_CARD) {
            const data = await api(API.receipts, {
                skuUpc: '',
                selectedPaymentOption: card.tenderId,
                pageNumber: page,
                includeTotalAvailable: page === 0,
            });

            const td = data.transactionData || {};
            const rows = td.transactions || [];
            if (page === 0 && typeof td.totalAvailable === 'number') total = td.totalAvailable;

            if (rows.length === 0) break;

            const fresh = rows.filter(t => (t.businessTransactionDate || '') >= sinceDate);
            wanted.push(...fresh.map(t => ({ ...t, _card: card })));

            onProgress?.(`${card.cardTypeName} ${card.maskedCardNumber}: page ${page + 1}, ${fresh.length} in range`);

            // Every row on this page predates the cutoff — older pages will too.
            if (fresh.length === 0) break;
            if (total !== null && (page + 1) * PAGE_SIZE >= total) break;

            page++;
            await sleep(DELAY_MS);
        }

        await sleep(DELAY_MS);
    }

    return wanted;
}

/** Fetch the PDF for one transaction, base64 as the endpoint returns it. */
async function fetchReceipt(t) {
    const { _card, ...transaction } = t;
    const data = await api(API.download, {
        transactions: [{ ...transaction, id: transactionId(transaction) }],
        selectedPaymentOption: _card.tenderId,
    });

    if (!data.receipt) throw new Error('download.ajx returned no receipt payload');

    return data.receipt;
}

/**
 * Full sync. Returns the payload the Hive ingest endpoint expects.
 */
async function sync(sinceDate, onProgress) {
    const transactions = await collect(sinceDate, onProgress);
    onProgress?.(`${transactions.length} transactions on or after ${sinceDate}`);

    const receipts = [];
    const errors = [];

    for (const [i, t] of transactions.entries()) {
        try {
            const base64 = await fetchReceipt(t);

            receipts.push({
                date: t.businessTransactionDate,
                amount: t.transactionTotal,
                store: t.storeName,
                storeNumber: t.storeNumber,
                card: `${t._card.cardTypeName} ${t._card.maskedCardNumber}`,
                transactionId: transactionId(t),
                transactionType: t.transactionTypeCode,
                pdfBase64: base64,
            });

            onProgress?.(`downloaded ${i + 1}/${transactions.length} — ${t.businessTransactionDate} $${t.transactionTotal}`);
        } catch (err) {
            // One bad receipt must not abandon the rest of the run.
            errors.push({ transactionId: transactionId(t), error: err.message });
        }

        await sleep(DELAY_MS);
    }

    return { receipts, errors, scrapedAt: new Date().toISOString(), since: sinceDate };
}

// The service worker asks for a sync; the work happens here because only a
// content script on the page has its origin, cookies and CSRF token.
chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (msg?.action !== 'sync') return;

    sync(msg.since, p => chrome.runtime.sendMessage({ action: 'progress', text: p }).catch(() => {}))
        .then(result => sendResponse({ ok: true, ...result }))
        .catch(err => sendResponse({ ok: false, error: err.message }));

    // Keep the channel open for the async reply.
    return true;
});
