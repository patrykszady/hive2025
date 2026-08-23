/**
 * Hive — Menards Session Bridge (MV3 service worker)
 *
 * Reads the menards.com cookies this browser already holds and posts them to
 * Hive, which replays them in the receipt scraper instead of signing in itself.
 *
 * Why this exists: the scraper's own sign-in stopped working in August 2026.
 * The captcha is NOT the problem — anti-captcha still solves the hCaptcha and
 * returns a valid token — but Imperva then refuses the session anyway, scoring
 * the automated browser rather than the challenge. The 2026-08-18 runs show it
 * plainly: "hCaptcha solved — token length: 1034" followed by "Imperva wall
 * persisted after 3 challenge attempts". No amount of solver spend changes a
 * fingerprint score.
 *
 * This browser is never asked, because it is a real person on a residential
 * connection. So the server stops trying to look human and borrows the session
 * that already exists. Same play as the Yelp cookie bridge on gs.construction
 * (DataDome) and the EWCCV session bridge (reCAPTCHA Enterprise).
 *
 * Scope: only menards.com cookies are read, and they go to exactly one place —
 * the Hive server configured on the options page. No other host is touched;
 * host_permissions covers menards.com and Hive only.
 */

const COOKIE_DOMAIN = 'menards.com';
const ALARM = 'menards-session-push';
const ENDPOINT = '/api/menards/session';

async function settings() {
    const { serverUrl, token } = await chrome.storage.local.get(['serverUrl', 'token']);

    return {
        serverUrl: (serverUrl || '').replace(/\/+$/, ''),
        token: token || '',
    };
}

async function collectCookies() {
    const jar = await chrome.cookies.getAll({ domain: COOKIE_DOMAIN });

    return jar.map(c => ({
        name: c.name,
        value: c.value,
        domain: c.domain,
        path: c.path,
        secure: c.secure,
        httpOnly: c.httpOnly,
        // Session cookies carry no expirationDate. Leaving the key off keeps
        // them session-scoped on the other side rather than inventing a expiry
        // that would outlive the session they represent.
        ...(c.expirationDate ? { expirationDate: Math.floor(c.expirationDate) } : {}),
    }));
}

function notify(title, message) {
    chrome.notifications.create({
        type: 'basic',
        // 1x1 transparent PNG — MV3 requires an iconUrl, and shipping a real
        // icon file for a notification nobody looks at twice is not worth it.
        iconUrl:
            'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC' +
            'AAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        title: `Hive — Menards: ${title}`,
        message,
    });
}

async function push(reason) {
    const { serverUrl, token } = await settings();

    if (!serverUrl || !token) {
        notify('Not configured', 'Open the extension options and set the Hive URL and bridge token.');

        return { ok: false, error: 'not configured' };
    }

    const cookies = await collectCookies();

    if (cookies.length === 0) {
        notify('No Menards cookies', 'Visit menards.com and sign in, then send again.');

        return { ok: false, error: 'no cookies' };
    }

    try {
        const res = await fetch(`${serverUrl}${ENDPOINT}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
            body: JSON.stringify({ cookies }),
        });

        const body = await res.json().catch(() => ({}));

        if (res.ok && body.ok) {
            await chrome.storage.local.set({
                lastPush: new Date().toISOString(),
                lastError: null,
            });
            notify('Session sent', `${body.stats?.kept ?? cookies.length} cookies stored (${reason}).`);

            return { ok: true };
        }

        // The server distinguishes "not signed in" from "nothing usable", and
        // says so in body.error — surface that verbatim rather than a status
        // code, because it is the message that tells you what to actually do.
        const error = body.error || `HTTP ${res.status}`;
        await chrome.storage.local.set({ lastError: error });
        notify('Hive rejected the session', error);

        return { ok: false, error };
    } catch (err) {
        await chrome.storage.local.set({ lastError: err.message });
        notify('Could not reach Hive', err.message);

        return { ok: false, error: err.message };
    }
}

chrome.action.onClicked.addListener(() => push('manual'));

chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (msg?.action === 'push') {
        // Returning true keeps the message channel open for the async reply.
        push('options page').then(sendResponse);

        return true;
    }
});

// Menards rotates session cookies as you browse, so a jar captured once goes
// stale. Re-sending on a schedule keeps the server's copy current without
// anyone having to remember to click.
chrome.alarms.create(ALARM, { periodInMinutes: 180 });
chrome.alarms.onAlarm.addListener(alarm => {
    if (alarm.name === ALARM) {
        push('scheduled');
    }
});
