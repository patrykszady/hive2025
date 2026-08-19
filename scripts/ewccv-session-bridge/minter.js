/**
 * EWCCV Session Bridge — page-world minter.
 *
 * Runs in the PAGE world (not the extension's isolated world) because it needs
 * the things only page JavaScript can see: `grecaptcha.enterprise` and the
 * React/Redux store holding the per-session state tokens.
 *
 * It reproduces exactly what ewccv.com's own "search" button does:
 *   1. grecaptcha.enterprise.execute(siteKey, {action: 'default'})
 *   2. POST /cvs/endpoint/recaptcha/v3/verify/ with
 *        Authorization: Bearer <the `token` cookie>
 *        x-sec-tkn:     <every state's stateToken, joined by "-">
 *        body:          {"key": <the reCAPTCHA token>}
 *   3. the response carries {verified: true, accessToken} — the credential the
 *      search API actually wants.
 *
 * The whole point of doing this HERE rather than on the server: the score
 * Google assigns is a property of the browser and the person driving it. This
 * is a real browser on a residential connection, so it passes; the server's
 * automated Chrome never does, no matter which captcha solver pays for a token.
 *
 * Nothing about the page is modified and no search is performed — this only
 * asks for the credential the app would ask for anyway.
 */
(function () {
    'use strict';

    // ewccv.com picks its site key by hostname; www.* maps to this one.
    const SITE_KEY = '6LfsgGkqAAAAAJV4WuznMwTLMn6091VDUYNiIxGG';

    function reply(payload) {
        window.postMessage({ source: 'hive-ewccv-bridge', ...payload }, window.location.origin);
    }

    /** Walk the React fiber tree to the Redux store the app mounted. */
    function findStore() {
        const root = document.getElementById('root');
        if (!root) return null;

        let fiber = null;
        for (const key of Object.keys(root)) {
            if (key.startsWith('__reactContainer')) fiber = root[key];
        }

        let node = fiber;
        let hops = 0;
        while (node && hops++ < 3000) {
            const store = node.memoizedProps?.store || node.pendingProps?.store;
            if (store && typeof store.getState === 'function') return store;
            node = node.child || node.sibling || (node.return && node.return.sibling) || node.return;
        }
        return null;
    }

    /**
     * EWCCV splits ONE session UUID across the first five states in its list
     * ("8b095d21" + "fd2b" + "4901" + "b7da" + "624416e3e114"), so rejoining
     * the non-empty stateTokens with "-" reconstitutes it. Looks like
     * obfuscation; treat it as the app does and just rejoin.
     */
    function stateTokenString(store) {
        const list = store?.getState()?.coverageStates?.statesList;
        if (!Array.isArray(list)) return '';
        return list
            .reduce((acc, s) => (s.stateToken && s.stateToken.length > 0 ? acc.concat(s.stateToken) : acc), [])
            .join('-');
    }

    function bearerFromCookie() {
        const raw = document.cookie.split(';').map((c) => c.trim()).find((c) => c.startsWith('token='));
        if (!raw) return '';
        return decodeURIComponent(raw.split('=').slice(1).join('='));
    }

    async function mint() {
        if (typeof grecaptcha === 'undefined' || !grecaptcha.enterprise) {
            return reply({ ok: false, error: 'reCAPTCHA is not loaded on this page yet — open ewccv.com/cvs/search and try again.' });
        }

        const jwt = bearerFromCookie();
        if (!jwt) {
            return reply({ ok: false, error: 'Not signed in to EWCCV — sign in first, then send the session.' });
        }

        const store = findStore();
        if (!store) {
            return reply({ ok: false, error: 'Could not read the EWCCV app state. Reload the page and try again.' });
        }

        // The states carry the session token; ask for them if they are not in yet.
        let stateTkn = stateTokenString(store);
        if (!stateTkn) {
            try { store.dispatch({ type: 'FETCH_COVERAGE_STATES_REQUESTED' }); } catch (_) { /* best effort */ }
            for (let i = 0; i < 20 && !stateTkn; i++) {
                await new Promise((r) => setTimeout(r, 500));
                stateTkn = stateTokenString(store);
            }
        }
        if (!stateTkn) {
            return reply({ ok: false, error: 'EWCCV has not issued a session token yet — open the search page and try again.' });
        }

        let recaptchaToken;
        try {
            recaptchaToken = await grecaptcha.enterprise.execute(SITE_KEY, { action: 'default' });
        } catch (e) {
            return reply({ ok: false, error: 'reCAPTCHA did not run: ' + e.message });
        }

        let res, json;
        try {
            res = await fetch('/cvs/endpoint/recaptcha/v3/verify/', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Authorization: `Bearer ${jwt}`,
                    'x-sec-source-ip': '127.0.0.1',
                    'x-sec-tkn': stateTkn,
                },
                body: JSON.stringify({ key: recaptchaToken }),
            });
            json = await res.json();
        } catch (e) {
            return reply({ ok: false, error: 'EWCCV verify request failed: ' + e.message });
        }

        const data = json?.data || {};

        if (!data.verified || !data.accessToken) {
            return reply({
                ok: false,
                error: `EWCCV did not verify this session${data.reason ? ` (${data.reason})` : ''}. Do one search on the page by hand, then press send again.`,
            });
        }

        reply({ ok: true, accessToken: data.accessToken, jwt, stateToken: stateTkn });
    }

    // Also honour a token the app itself already minted (i.e. you just searched):
    // cheaper and guaranteed-good, so prefer it when present.
    const existing = sessionStorage.getItem('accessToken');
    if (existing && existing.length > 20) {
        reply({ ok: true, accessToken: existing, jwt: bearerFromCookie(), stateToken: stateTokenString(findStore()) });
    } else {
        mint();
    }
})();
