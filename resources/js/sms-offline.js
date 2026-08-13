/**
 * Offline SMS cache — single owner of the 'hive-sms-data' Cache API bucket.
 *
 * WHY this exists: /messages thread switches are Livewire POSTs, which the
 * Cache API cannot store. The server exposes GET twins instead
 * (SmsOfflineController: manifest + per-thread rendered HTML fragments) and
 * this module fetches + stores them in the background — site-wide, because
 * app.js survives every wire:navigate — so the last ~30 conversations open
 * instantly with real bubbles when the device is offline or on a bad link.
 *
 * Division of labor with the service worker (public/sw.js):
 *   - SW: caches the /messages page shell (the server-rendered thread list),
 *     recently viewed MMS images, and wipes everything on POST /logout.
 *   - This module: everything about fragments — fetching, storing, diffing
 *     via fingerprints, eviction, painting into the #sms-offline-pane overlay.
 * One owner per concern; the SW fetch table stays minimal around the push
 * notification handlers.
 *
 * Privacy: data is keyed per user (?u= on every URL + an owner marker entry).
 * Wiped on: logout (SW primary + form-submit backup here), owner mismatch at
 * boot (account switch), and any warm response that shows the session died
 * (401/419/redirect to login).
 */

const DATA_CACHE = 'hive-sms-data';
const MEDIA_CACHE = 'hive-sms-media';
const PAGE_CACHE_PREFIX = 'hive-pages-'; // deploy-versioned, owned by the SW
const OWNER_KEY_ENTRY = '/__sms_owner';
const SHELL_KEY = '/messages';

// Thread cap (30) is enforced server-side (SmsOfflineController::MANIFEST_THREAD_LIMIT);
// eviction below simply follows the manifest.
const WARM_FIRST_DELAY_MS = 10_000;  // after load, once the page is idle
const WARM_INTERVAL_MS = 10 * 60_000;
const FRAGMENT_GAP_MS = 300;         // polite: sequential fragment fetches
const SHELL_REFRESH_MIN_INTERVAL_MS = 5 * 60_000;
const REWARM_DEBOUNCE_MS = 4_000;    // per-thread, for incoming-message bursts
const RACE_PAINT_DELAY_MS = 400;     // slow-network: paint cached copy after this
const PROBE_URL = '/up';             // Laravel health route — no auth, no session
const PROBE_INTERVAL_MS = 5_000;     // recovery: re-check a suspect offline state
const RACE_STUCK_MS = 8_000;         // "updating…" with no real load → force replay

let ownerKey = null;    // '12-v' — from the hive-user-key meta
let stopped = false;    // set when the session is known dead; no more fetching
let warming = false;
let lastShellRefresh = 0;
let overlayMode = null; // null | 'offline' | 'race' | 'reconnecting'
let overlayThreadId = null;
let raceTimer = null;
let raceStuckTimer = null;
let probeTimer = null;
// Bumped whenever a real thread load lands or the page navigates. A race
// paint carries the generation it started under and aborts if it changed —
// without this, a thread landing DURING the async cached-copy read left the
// overlay painted on top of live content with nothing left to dissolve it.
let raceGeneration = 0;
const rewarmTimers = new Map();

const supported = () => 'caches' in window;

const smsStore = () => window.Alpine?.store ? window.Alpine.store('sms') : null;

/**
 * Run once Livewire is bootable. This module is loaded via dynamic import
 * (so it evaluates after window.Echo exists) — on slow connections that can
 * land AFTER livewire:init already fired, so waiting for the event alone
 * would never run the callback.
 */
function whenLivewireReady(fn) {
    if (window.Livewire?.hook) {
        fn();
    } else {
        document.addEventListener('livewire:init', fn, { once: true });
    }
}

/* ─── URLs ───────────────────────────────────────────────────────── */

const manifestUrl = () => `/messages/offline/manifest?u=${ownerKey}`;
const fragmentUrl = (id) => `/messages/offline/threads/${id}?u=${ownerKey}`;

/* ─── Politeness gates ───────────────────────────────────────────── */

function politeToFetch() {
    if (stopped || !navigator.onLine) return false;
    // navigator.connection is absent on Safari — treat as a fast connection.
    const conn = navigator.connection;
    if (conn) {
        if (conn.saveData) return false;
        if (/(^|-)2g$/.test(conn.effectiveType || '')) return false;
    }
    return true;
}

/* ─── Purge ──────────────────────────────────────────────────────── */

async function purgeAll() {
    if (!supported()) return;
    try {
        await caches.delete(DATA_CACHE);
        await caches.delete(MEDIA_CACHE);
        // The /messages shell lives in the SW's deploy-versioned page cache.
        const keys = await caches.keys();
        for (const key of keys) {
            if (key.startsWith(PAGE_CACHE_PREFIX)) {
                const cache = await caches.open(key);
                await cache.delete(SHELL_KEY);
            }
        }
    } catch (e) { /* storage may be unavailable (private mode) */ }
}

/** A warm response proving the session died server-side → wipe and stop. */
function sessionDied(res) {
    if (res.status === 401 || res.status === 419) return true;
    if (res.redirected) {
        try { return new URL(res.url).pathname === '/login'; } catch (e) { return true; }
    }
    return false;
}

async function handleDeadSession() {
    stopped = true;
    await purgeAll();
}

/* ─── Offline state ──────────────────────────────────────────────── */

/** One real request settles it — navigator.onLine and status 0 both lie. */
async function probeConnectivity() {
    try {
        const res = await fetch(PROBE_URL, { cache: 'no-store', credentials: 'same-origin' });
        return res.ok;
    } catch (e) {
        return false;
    }
}

/**
 * Offline mode gates thread taps to cached copies, so no Livewire request
 * runs and the "successful commit" recovery signal can never fire — without
 * this probe loop a false positive (aborted request) locks offline forever.
 */
function startRecoveryProbe() {
    if (probeTimer) return;
    probeTimer = setInterval(async () => {
        if (!navigator.onLine) return; // truly down — the 'online' event recovers
        if (await probeConnectivity()) setOffline(false);
    }, PROBE_INTERVAL_MS);
}

function stopRecoveryProbe() {
    clearInterval(probeTimer);
    probeTimer = null;
}

function setOffline(isOffline) {
    const store = smsStore();
    if (store) store.offline = isOffline;
    if (isOffline) startRecoveryProbe(); else stopRecoveryProbe();
    if (!isOffline) {
        setBanner('');
        // Overlay showing cached content while we were offline → resync the
        // real component now that requests can succeed again. A stuck 'race'
        // overlay ("updating…" whose real load died) gets the same replay.
        if ((overlayMode === 'offline' || overlayMode === 'race') && overlayThreadId) {
            reconnectReplay(overlayThreadId);
        }
    } else if (overlayMode === 'race') {
        // The "updating…" we promised will never arrive — the network just
        // died mid-race. The painted cached copy simply becomes the offline view.
        overlayMode = 'offline';
        setBanner('Offline — cached messages');
    }
}

function setBanner(text) {
    const store = smsStore();
    if (store) store.offlineBanner = text;
}

function ageLabel(fetchedAtIso) {
    if (!fetchedAtIso) return '';
    const ms = Date.now() - Date.parse(fetchedAtIso);
    if (!Number.isFinite(ms) || ms < 0) return '';
    const mins = Math.round(ms / 60_000);
    if (mins < 1) return 'updated just now';
    if (mins < 60) return `updated ${mins} min ago`;
    const hours = Math.round(mins / 60);
    if (hours < 24) return `updated ${hours} hr ago`;
    return `updated ${Math.round(hours / 24)} d ago`;
}

/* ─── Warmer ─────────────────────────────────────────────────────── */

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function warmCycle() {
    if (warming || !politeToFetch() || document.visibilityState === 'hidden') return;
    warming = true;
    try {
        const res = await fetch(manifestUrl(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (sessionDied(res)) return void await handleDeadSession();
        if (!res.ok) return;

        const manifest = await res.json();
        const threads = Array.isArray(manifest.threads) ? manifest.threads : [];
        const cache = await caches.open(DATA_CACHE);

        // Diff: refetch only fragments that are missing or whose fingerprint
        // (count:maxId:maxUpdatedAt — stamped on the response by the server)
        // no longer matches the manifest.
        for (const { id, fingerprint } of threads) {
            if (!politeToFetch()) return;
            const cached = await cache.match(fragmentUrl(id));
            if (cached && cached.headers.get('X-Sms-Fingerprint') === fingerprint) continue;
            await warmFragment(cache, id);
            await sleep(FRAGMENT_GAP_MS);
        }

        // Evict fragments that dropped out of the top-30.
        const keep = new Set(threads.map((t) => String(t.id)));
        for (const request of await cache.keys()) {
            const match = new URL(request.url).pathname.match(/^\/messages\/offline\/threads\/(\d+)$/);
            if (match && !keep.has(match[1])) await cache.delete(request);
        }

        await maybeRefreshShell();
    } catch (e) { /* network hiccup — next cycle retries */ }
    finally { warming = false; }
}

async function warmFragment(cache, id) {
    const res = await fetch(fragmentUrl(id), {
        credentials: 'same-origin',
        headers: { Accept: 'text/html' },
    });
    if (sessionDied(res)) { await handleDeadSession(); return; }
    // cache.put keeps the response headers (X-Fetched-At / X-Sms-Fingerprint)
    // for banner age + manifest diffing.
    if (res.ok) await cache.put(fragmentUrl(id), res);
}

/**
 * Ask the SW to refresh its cached /messages shell (it intercepts any GET
 * with Accept: text/html for that path). Throttled — the shell is heavy.
 */
async function maybeRefreshShell(force = false) {
    if (!politeToFetch()) return;
    if (!force && Date.now() - lastShellRefresh < SHELL_REFRESH_MIN_INTERVAL_MS) return;
    lastShellRefresh = Date.now();
    try {
        const res = await fetch(SHELL_KEY, {
            credentials: 'same-origin',
            headers: { Accept: 'text/html' },
        });
        if (sessionDied(res)) await handleDeadSession();
    } catch (e) { /* offline — SW will serve the previous copy */ }
}

/** Re-warm one thread soon (incoming message), debounced per thread. */
function rewarmThread(threadId) {
    const id = parseInt(threadId, 10);
    if (!id || stopped || !supported()) return;
    // The open thread on /messages is already live on screen — don't spend
    // a request on it; it re-enters via fingerprint diff on the next cycle.
    if (window.location.pathname === '/messages' && smsStore()?.threadId === id) return;

    clearTimeout(rewarmTimers.get(id));
    rewarmTimers.set(id, setTimeout(async () => {
        rewarmTimers.delete(id);
        if (!politeToFetch()) return;
        try {
            await warmFragment(await caches.open(DATA_CACHE), id);
        } catch (e) { /* next full cycle catches up */ }
        maybeRefreshShell();
    }, REWARM_DEBOUNCE_MS));
}

/* ─── Overlay pane ───────────────────────────────────────────────── */

function pane() {
    return document.getElementById('sms-offline-pane');
}

function showPane(el) {
    el.classList.remove('hidden');
    el.classList.add('flex');
}

function hidePane() {
    const el = pane();
    if (el) {
        el.classList.add('hidden');
        el.classList.remove('flex');
        el.innerHTML = '';
    }
    clearTimeout(raceStuckTimer);
    overlayMode = null;
    overlayThreadId = null;
    setBanner('');
}

const NOT_CACHED_HTML = `
    <div class="flex flex-1 items-center justify-center h-full">
        <div class="text-center px-6">
            <h3 class="text-base lg:text-sm font-medium text-zinc-500 dark:text-zinc-400">Not available offline</h3>
            <p class="mt-1 text-sm lg:text-xs text-zinc-400 dark:text-zinc-500">This conversation hasn't been cached yet. Reconnect to load it.</p>
        </div>
    </div>`;

/**
 * Paint a cached conversation into the overlay. Called from the thread-list
 * offline tap gate and the slow-network race.
 * Returns true when a cached copy was painted.
 */
async function paintCached(threadId, mode, generation = null) {
    const el = pane();
    if (!el || !supported()) return false;

    const stale = () => mode === 'race' && generation !== null && generation !== raceGeneration;

    let res = null;
    try {
        const cache = await caches.open(DATA_CACHE);
        res = await cache.match(fragmentUrl(threadId));
    } catch (e) { /* treat as miss */ }

    if (stale()) return false; // real thread landed while we were reading

    if (!res) {
        if (mode === 'race') return false; // racing: no cached copy → let the skeleton ride
        el.innerHTML = NOT_CACHED_HTML;
        showPane(el);
        overlayMode = mode;
        overlayThreadId = threadId;
        setBanner('Offline — this conversation is not cached');
        dispatchSettle();
        return false;
    }

    const html = await res.text();
    if (stale()) return false;
    el.innerHTML = html;
    showPane(el);
    overlayMode = mode;
    overlayThreadId = threadId;

    const age = ageLabel(res.headers.get('X-Fetched-At'));
    setBanner(mode === 'race'
        ? `Slow connection — cached copy${age ? ' · ' + age : ''} · updating…`
        : `Offline — cached messages${age ? ' · ' + age : ''}`);

    // "updating…" is a promise — if the real load died (aborted request,
    // dropped response) no 'thread-ready' will ever dissolve this overlay.
    // After a grace period, stop waiting and re-request the live thread.
    if (mode === 'race') {
        clearTimeout(raceStuckTimer);
        raceStuckTimer = setTimeout(() => {
            if (overlayMode === 'race' && overlayThreadId === threadId && navigator.onLine) {
                reconnectReplay(threadId);
            }
        }, RACE_STUCK_MS);
    }

    // Settle the live component's plumbing (switching-skeleton timer,
    // scroll-to-bottom reset) exactly like a real thread load would.
    dispatchSettle();
    return true;
}

/**
 * The settle event paintCached fires so the live component's skeleton timer
 * and scroll plumbing run. It is TAGGED so our own 'thread-ready' listener can
 * tell it apart from a real Livewire thread load — without the tag, painting a
 * race overlay would dispatch 'thread-ready', which would immediately dissolve
 * that very overlay (cached copy flashed for one frame, then skeleton again).
 */
function dispatchSettle() {
    window.dispatchEvent(new CustomEvent('thread-ready', { detail: { source: 'sms-offline' } }));
}

/** Offline tap entry point (thread-list rows call this instead of Livewire). */
function openThread(threadId) {
    paintCached(parseInt(threadId, 10), 'offline');
}

/** Mobile back button inside the offline fragment. */
function closeThread() {
    hidePane();
}

/** Back online with a cached overlay showing → swap in the live component. */
function reconnectReplay(threadId) {
    overlayMode = 'reconnecting';
    try {
        window.Livewire.dispatch('loadThread', { threadId });
        window.Livewire.dispatch('threadSelected', { threadId, skipConversationLoad: true });
        // Same catch-up the page runs after a websocket reconnect.
        window.Livewire.dispatch('sms-refresh-threads');
        window.Livewire.dispatch('refreshMessages');
    } catch (e) {
        hidePane(); // Livewire unavailable — at least never strand the overlay
    }
}

/* ─── Wiring ─────────────────────────────────────────────────────── */

function wireEvents() {
    window.addEventListener('offline', () => setOffline(true));
    window.addEventListener('online', () => setOffline(false));

    // Slow-network race: a thread tap online shows a skeleton; if the server
    // hasn't answered after ~400ms and we hold a cached copy, paint it (with
    // an "updating…" note) instead of leaving the user staring at bones.
    // 'thread-ready' cancels the race or, if we already painted, dissolves
    // the overlay one tick after the real thread landed underneath.
    window.addEventListener('thread-switching', (event) => {
        const threadId = event.detail?.threadId;
        clearTimeout(raceTimer);
        if (!threadId) return;
        const generation = raceGeneration;
        raceTimer = setTimeout(() => {
            if (navigator.onLine) paintCached(threadId, 'race', generation);
        }, RACE_PAINT_DELAY_MS);
    });

    window.addEventListener('thread-ready', (event) => {
        clearTimeout(raceTimer);
        // Our own settle event (see dispatchSettle) — not a real thread load;
        // only a Livewire-dispatched thread-ready may dissolve the overlay.
        if (event.detail?.source === 'sms-offline') return;
        raceGeneration++; // invalidates any race paint still in flight
        if (overlayMode === 'race' || overlayMode === 'reconnecting') {
            setTimeout(hidePane, 0); // next tick: real content is already painted
        }
    });

    // Incoming-message re-warm — two triggers, one debounce:
    // on-page CustomEvent (Livewire echo listener) + our own Echo channel
    // (works on every page because app.js persists across wire:navigate).
    window.addEventListener('sms-incoming', (event) => {
        if (event.detail?.threadId) rewarmThread(event.detail.threadId);
    });
    try {
        window.Echo?.private('sms.notifications')
            .listen('SmsMessageReceived', (payload) => {
                if (payload?.threadId) rewarmThread(payload.threadId);
            });
    } catch (e) { /* Echo not configured — on-page trigger still works */ }

    // wire:navigate tears the /messages DOM down (and the pane with it) —
    // drop any overlay bookkeeping so a later reconnect doesn't replay a
    // thread into a page that no longer shows conversations.
    document.addEventListener('livewire:navigated', () => {
        clearTimeout(raceTimer);
        clearTimeout(raceStuckTimer);
        raceGeneration++;
        overlayMode = null;
        overlayThreadId = null;
        setBanner('');
    });

    // Belt-and-braces logout wipe (the SW's POST /logout intercept is primary).
    document.addEventListener('submit', (event) => {
        const action = event.target?.action || '';
        if (typeof action === 'string' && new URL(action, window.location.origin).pathname === '/logout') {
            purgeAll();
        }
    }, true);

    whenLivewireReady(() => {
        // Network-failure detection + noise suppression: while offline, every
        // wire:poll / queued request fails — flip the store flag and swallow
        // Livewire's default error UI instead of stacking alerts.
        window.Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                const networkFailure = !navigator.onLine || status === 0 || status === 503;
                if (!networkFailure) return;
                if (typeof preventDefault === 'function') preventDefault();
                if (!navigator.onLine) return void setOffline(true);
                // status 0 while the browser reports online is usually an
                // ABORTED request (fast thread switches, mid-request
                // navigation), not a dead network — flipping offline on it
                // stranded online users on cached copies. Verify first.
                probeConnectivity().then((ok) => {
                    if (!ok) setOffline(true);
                });
            });
        });

        // Any successful round trip proves the network is back.
        window.Livewire.hook('commit', ({ succeed }) => {
            succeed(() => {
                if (smsStore()?.offline) setOffline(false);
            });
        });
    });
}

function scheduleWarm() {
    const kickoff = () => {
        const idle = window.requestIdleCallback || ((fn) => setTimeout(fn, 1_500));
        idle(() => warmCycle(), { timeout: 15_000 });
    };
    setTimeout(kickoff, WARM_FIRST_DELAY_MS);
    setInterval(() => warmCycle(), WARM_INTERVAL_MS);
}

async function boot() {
    if (!supported()) return;

    const meta = document.querySelector('meta[name="hive-user-key"]');
    const metaKey = meta?.content || '';

    if (!metaKey) {
        // Guest page (login etc.) — never keep another user's messages around.
        await purgeAll();
        return;
    }

    ownerKey = metaKey.replace(':', '-');

    try {
        const cache = await caches.open(DATA_CACHE);
        const marker = await cache.match(OWNER_KEY_ENTRY);
        const storedKey = marker ? await marker.text() : null;
        if (storedKey && storedKey !== ownerKey) {
            // Different account (or client/vendor mode) than the cached data.
            await purgeAll();
        }
        (await caches.open(DATA_CACHE)).put(OWNER_KEY_ENTRY, new Response(ownerKey));
    } catch (e) { /* storage unavailable — run without persistence */ }

    wireEvents();
    scheduleWarm();

    // Landing on /messages already offline: reflect it in the store so the
    // thread-list tap gate and banner engage immediately. Alpine's store
    // exists once Livewire has booted — cover both orderings.
    if (!navigator.onLine) {
        whenLivewireReady(() => queueMicrotask(() => setOffline(true)));
    }
}

window.HiveSmsOffline = { openThread, closeThread, warmNow: warmCycle, purge: purgeAll };

boot();
