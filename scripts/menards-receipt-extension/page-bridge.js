/**
 * Hive — Menards Receipt Sync (page-world bridge)
 *
 * Runs in the PAGE's JavaScript world on receiptLookup.html, not the
 * extension's isolated one. That distinction is the whole point: Imperva's
 * bot protection wraps the page's own XMLHttpRequest/fetch to stamp each API
 * call with a token it minted for this browser, and a request made from the
 * isolated world never passes through that wrapper. Since 2026-08-26 every
 * such request came back as the challenge page while the page itself loaded
 * fine — a signed-in browser that could not read its own receipts.
 *
 * content.js posts a request description to the window; this makes the call
 * with the page's real XMLHttpRequest and posts the answer back. Nothing
 * secret crosses: same origin, same cookies, same CSRF token the page uses.
 * The marker on <html> tells content.js the bridge is present.
 */
(() => {
    if (document.documentElement.dataset.hiveMenardsBridge) return;
    document.documentElement.dataset.hiveMenardsBridge = 'ready';

    const REQUEST = 'hive-menards-request';
    const RESPONSE = 'hive-menards-response';

    window.addEventListener('message', (event) => {
        if (event.source !== window) return;

        const msg = event.data;
        if (!msg || msg.type !== REQUEST || typeof msg.id !== 'string') return;

        const reply = (extra) => window.postMessage({ type: RESPONSE, id: msg.id, ...extra }, window.location.origin);

        try {
            const xhr = new XMLHttpRequest();
            xhr.open(msg.method || 'GET', msg.path, true);
            xhr.withCredentials = true;
            xhr.timeout = 60000;

            for (const [name, value] of Object.entries(msg.headers || {})) {
                xhr.setRequestHeader(name, value);
            }

            xhr.onload = () => reply({ ok: xhr.status >= 200 && xhr.status < 300, status: xhr.status, text: xhr.responseText });
            xhr.onerror = () => reply({ ok: false, status: xhr.status, text: '', error: 'network error' });
            xhr.ontimeout = () => reply({ ok: false, status: 0, text: '', error: 'timed out after 60s' });

            xhr.send(msg.body ?? null);
        } catch (e) {
            reply({ ok: false, status: 0, text: '', error: e.message });
        }
    });
})();
