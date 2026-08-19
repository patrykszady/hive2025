/**
 * EWCCV Session Bridge — content script (isolated world).
 *
 * Content scripts cannot see page globals, and the credential we need lives
 * behind two of them (`grecaptcha.enterprise` and the Redux store). So this
 * script does only two things: inject the page-world minter when asked, and
 * relay its answer back to the service worker, which owns the network call.
 */
(function () {
    'use strict';

    let pending = null;

    window.addEventListener('message', (event) => {
        if (event.source !== window) return;
        if (!event.data || event.data.source !== 'hive-ewccv-bridge') return;
        if (!pending) return;

        const respond = pending;
        pending = null;
        respond({
            ok: !!event.data.ok,
            error: event.data.error,
            accessToken: event.data.accessToken,
            jwt: event.data.jwt,
            stateToken: event.data.stateToken,
        });
    });

    chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
        if (msg?.type !== 'hive-ewccv-mint') return;

        pending = sendResponse;

        const s = document.createElement('script');
        s.src = chrome.runtime.getURL('minter.js');
        // Re-injectable: a fresh <script> each time so repeat sends re-run it.
        s.onload = () => s.remove();
        (document.head || document.documentElement).appendChild(s);

        // Never leave the popup spinning if the page never answers.
        setTimeout(() => {
            if (pending) {
                const respond = pending;
                pending = null;
                respond({ ok: false, error: 'Timed out reading the EWCCV session.' });
            }
        }, 30000);

        return true; // async sendResponse
    });
})();
