/**
 * EWCCV Extension — Injected Page-World Script
 *
 * This runs in the MAIN page world (same as grecaptcha, Redux, etc.)
 * and is injected by content.js ONLY when the scraper disconnects Puppeteer
 * and triggers a page reload.
 *
 * Because Puppeteer/CDP is disconnected at this point, navigator.webdriver
 * is FALSE, and reCAPTCHA Enterprise v3 gives a legitimate user score.
 *
 * Flow:
 *   1. Wait for React/reCAPTCHA to load
 *   2. Simulate passive page interaction (scroll, focus) for scoring
 *   3. Find Redux store → load coverage states
 *   4. Execute grecaptcha.enterprise.execute() → get token
 *   5. Call /cvs/endpoint/recaptcha/v3/verify/ → get accessToken
 *   6. Store result in localStorage for the scraper to read after reconnect
 */
(async function () {
    'use strict';

    var SITEKEY = '6LfsgGkqAAAAAJV4WuznMwTLMn6091VDUYNiIxGG';

    function log(msg) {
        console.log('[EWCCV-EXT] ' + msg);
    }

    function waitMs(ms) {
        return new Promise(function (r) { setTimeout(r, ms); });
    }

    function waitFor(check, timeoutMs, intervalMs) {
        timeoutMs = timeoutMs || 30000;
        intervalMs = intervalMs || 500;
        return new Promise(function (resolve, reject) {
            var start = Date.now();
            var timer = setInterval(function () {
                var result = check();
                if (result) {
                    clearInterval(timer);
                    resolve(result);
                } else if (Date.now() - start > timeoutMs) {
                    clearInterval(timer);
                    reject(new Error('waitFor timeout'));
                }
            }, intervalMs);
        });
    }

    // Check and consume trigger
    var trigger = localStorage.getItem('ewccv_trigger');
    if (!trigger) return;
    localStorage.removeItem('ewccv_trigger');
    log('Trigger found — starting reCAPTCHA verification (no CDP)');
    log('navigator.webdriver = ' + navigator.webdriver);

    function storeResult(obj) {
        localStorage.setItem('ewccv_result', JSON.stringify(obj));
    }

    // ── Find Redux store (same logic as scraper) ──────────────────
    function findReduxStore() {
        if (window.__EWCCV_REDUX_STORE__) return window.__EWCCV_REDUX_STORE__;

        var root = document.getElementById('root');
        if (!root) return null;

        var startFiber = null;
        var keys = Object.keys(root);
        for (var i = 0; i < keys.length; i++) {
            if (keys[i].indexOf('__reactFiber$') === 0 || keys[i].indexOf('__reactInternalInstance$') === 0) {
                startFiber = root[keys[i]];
                break;
            }
        }

        if (!startFiber && root._reactRootContainer) {
            try { startFiber = root._reactRootContainer._internalRoot.current; } catch (e) {}
        }
        if (!startFiber) {
            for (var j = 0; j < keys.length; j++) {
                if (keys[j].indexOf('__reactContainer$') === 0) {
                    startFiber = root[keys[j]];
                    break;
                }
            }
        }
        if (!startFiber) {
            var candidates = root.querySelectorAll('*');
            for (var k = 0; k < Math.min(candidates.length, 50); k++) {
                var cKeys = Object.keys(candidates[k]);
                for (var m = 0; m < cKeys.length; m++) {
                    if (cKeys[m].indexOf('__reactFiber$') === 0 || cKeys[m].indexOf('__reactInternalInstance$') === 0) {
                        var f = candidates[k][cKeys[m]];
                        while (f.return) f = f.return;
                        startFiber = f;
                        break;
                    }
                }
                if (startFiber) break;
            }
        }
        if (!startFiber) return null;

        var store = null;
        var queue = [startFiber];
        var attempts = 0;
        while (queue.length > 0 && attempts < 1000) {
            var node = queue.shift();
            attempts++;
            if (!node) continue;
            var sn = node.stateNode;
            if (sn && sn.store && typeof sn.store.dispatch === 'function') { store = sn.store; break; }
            var p = node.memoizedProps || node.pendingProps;
            if (p && p.store && typeof p.store.dispatch === 'function') { store = p.store; break; }
            if (node.child) queue.push(node.child);
            if (node.sibling) queue.push(node.sibling);
        }

        if (store) window.__EWCCV_REDUX_STORE__ = store;
        return store;
    }

    try {
        // ── 1. Wait for React app to mount ────────────────────────
        log('Waiting for page to render…');
        await waitMs(3000);

        if (!window.location.pathname.includes('/cvs/search') && !window.location.pathname.includes('/cvs/')) {
            storeResult({ ok: false, error: 'wrong_page: ' + window.location.pathname });
            return;
        }

        // ── 2. Simulate passive page interaction ──────────────────
        // reCAPTCHA Enterprise v3 scores based on user behavior.
        // Even basic scroll/focus/timing helps build a non-bot profile.
        log('Simulating page interaction for reCAPTCHA scoring…');

        // Scroll down slowly
        for (var si = 0; si < 5; si++) {
            window.scrollBy(0, 50 + Math.random() * 100);
            await waitMs(400 + Math.random() * 400);
        }
        // Scroll back up
        window.scrollTo({ top: 0, behavior: 'smooth' });
        await waitMs(1500);

        // Focus/blur form elements (if present)
        var formEls = document.querySelectorAll('input, select, button');
        for (var fi = 0; fi < Math.min(formEls.length, 4); fi++) {
            try {
                formEls[fi].focus();
                await waitMs(200 + Math.random() * 300);
                formEls[fi].blur();
            } catch (e) { /* some elements might not be focusable */ }
        }

        // Wait for reCAPTCHA to collect enough behavior data
        log('Waiting 12s for reCAPTCHA behavior profiling…');
        await waitMs(12000);

        // ── 3. Find Redux store ───────────────────────────────────
        log('Finding Redux store…');
        var store;
        try {
            store = await waitFor(findReduxStore, 15000, 1000);
        } catch (e) {
            storeResult({ ok: false, error: 'redux_store_timeout' });
            return;
        }
        log('Redux store found');

        // ── 4. Load coverage states ───────────────────────────────
        var state = store.getState();
        var hasStates = state.coverageStates && Array.isArray(state.coverageStates.statesList) && state.coverageStates.statesList.length > 0;
        if (!hasStates) {
            store.dispatch({ type: 'FETCH_COVERAGE_STATES_REQUESTED' });
            log('Dispatched FETCH_COVERAGE_STATES_REQUESTED');
            await waitMs(5000);
        }

        var statesList = store.getState().coverageStates?.statesList || [];
        log('Coverage states loaded: ' + statesList.length);

        if (statesList.length === 0) {
            storeResult({ ok: false, error: 'no_coverage_states' });
            return;
        }

        // ── 5. Build stateTokenString ─────────────────────────────
        var tokens = [];
        for (var ti = 0; ti < statesList.length; ti++) {
            if (statesList[ti].stateToken && statesList[ti].stateToken.length > 0) {
                tokens.push(statesList[ti].stateToken);
            }
        }
        var stateTokenString = tokens.join('-');
        log('stateTokenString: ' + stateTokenString.length + ' chars from ' + tokens.length + ' tokens');

        // ── 6. Wait for reCAPTCHA Enterprise ──────────────────────
        log('Waiting for grecaptcha.enterprise…');
        try {
            await waitFor(function () {
                return typeof grecaptcha !== 'undefined' && grecaptcha.enterprise;
            }, 30000);
        } catch (e) {
            storeResult({ ok: false, error: 'grecaptcha_not_available' });
            return;
        }
        log('reCAPTCHA Enterprise available');

        // ── 7. Execute reCAPTCHA v3 ───────────────────────────────
        log('Executing grecaptcha.enterprise.execute()…');
        var recaptchaToken;
        try {
            recaptchaToken = await grecaptcha.enterprise.execute(SITEKEY, { action: 'default' });
        } catch (e) {
            storeResult({ ok: false, error: 'recaptcha_execute_error: ' + e.message });
            return;
        }
        log('reCAPTCHA token obtained: ' + recaptchaToken.length + ' chars');

        // ── 8. Get cookie token ───────────────────────────────────
        var cookieMatch = document.cookie.match(/token=([^;]+)/);
        if (!cookieMatch) {
            storeResult({ ok: false, error: 'no_cookie_token' });
            return;
        }
        var cookieToken = decodeURIComponent(cookieMatch[1]);
        log('Cookie token: ' + cookieToken.length + ' chars');

        // ── 9. Call verify endpoint ───────────────────────────────
        log('Calling verify endpoint…');
        var verifyRes = await fetch('/cvs/endpoint/recaptcha/v3/verify/', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + cookieToken,
                'x-sec-source-ip': '127.0.0.1',
                'x-sec-tkn': stateTokenString,
            },
            body: JSON.stringify({ key: recaptchaToken }),
        });

        var verifyJson = await verifyRes.json();
        log('Verify HTTP ' + verifyRes.status + ': ' + JSON.stringify(verifyJson).substring(0, 300));

        // ── 10. Store result ──────────────────────────────────────
        var accessToken = verifyJson.data?.accessToken;
        if (verifyJson.data?.verified && accessToken) {
            sessionStorage.setItem('accessToken', accessToken);
            log('SUCCESS — accessToken: ' + accessToken.length + ' chars');
            storeResult({
                ok: true,
                accessToken: accessToken,
                cookieToken: cookieToken,
                statesList: statesList,
                webdriver: navigator.webdriver,
            });
        } else {
            log('Verify failed: ' + JSON.stringify(verifyJson));
            storeResult({
                ok: false,
                error: 'verify_failed',
                httpStatus: verifyRes.status,
                response: verifyJson,
                webdriver: navigator.webdriver,
                tokenLen: recaptchaToken.length,
            });
        }
    } catch (e) {
        log('Fatal error: ' + e.message);
        storeResult({ ok: false, error: e.message });
    }
})();
