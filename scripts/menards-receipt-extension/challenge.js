/**
 * Imperva hCaptcha challenge solver.
 *
 * Runs in every menards.com frame. Imperva serves its wall inside
 * iframe#main-iframe[src*="_Incapsula_Resource"], and the hCaptcha widget lives
 * inside THAT — so this must be injected with all_frames, not just the top
 * document, or it never sees the widget it is meant to solve.
 *
 * It does not talk to 2captcha itself: the API key stays on the server. The
 * frame reports the sitekey to the background worker, which asks Hive for a
 * token and hands it back. Nothing here is secret, so nothing here holds a key.
 *
 * READ MenardsCaptchaSolver BEFORE ASSUMING THIS WORKS. The same solve already
 * succeeded once on the Puppeteer scraper — a valid 1034-character token — and
 * Imperva refused the session anyway, because it scores the browser and not the
 * token. This exists to test whether a real headed Chrome fares differently. If
 * the wall persists after injection, that is the answer, not a reason to retry.
 */

(() => {
    // One attempt per page load. A token costs money and a retry loop against a
    // wall that is refusing the BROWSER would spend it indefinitely for nothing.
    if (window.__hiveChallengeRan) return;
    window.__hiveChallengeRan = true;

    const log = (m, extra) => console.log(`[hive-challenge] ${m}`, extra ?? '');

    /**
     * The sitekey, from wherever this page happens to publish it. hCaptcha
     * widgets render as [data-sitekey], but Imperva sometimes only has it in
     * the iframe's own src, so both are worth reading.
     */
    function findSiteKey() {
        const el = document.querySelector('[data-sitekey]');
        if (el) {
            const k = el.getAttribute('data-sitekey');
            if (k) return k;
        }

        for (const f of document.querySelectorAll('iframe[src*="hcaptcha"]')) {
            const m = /[?&]sitekey=([0-9a-f-]{36})/i.exec(f.src || '');
            if (m) return m[1];
        }

        return null;
    }

    /** Is this actually an hCaptcha challenge, rather than an ordinary page? */
    function isChallenge() {
        return !!document.querySelector('[data-sitekey], iframe[src*="hcaptcha"]')
            || /Additional security check is required/i.test(document.body?.innerText || '');
    }

    /**
     * Apply the token the way the page expects to receive it.
     *
     * Setting the textarea alone is not enough: hCaptcha's host page reads its
     * response through a callback, and a value written without dispatching
     * anything leaves the form believing nothing was solved. So write every
     * response field present, fire input/change on each, then invoke whichever
     * callback the widget registered.
     */
    function applyToken(token) {
        let touched = 0;

        document.querySelectorAll(
            'textarea[name="h-captcha-response"], textarea[name="g-recaptcha-response"], #h-captcha-response, #g-recaptcha-response'
        ).forEach((el) => {
            el.value = token;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            touched++;
        });

        if (touched === 0) {
            // No field rendered yet — create the canonical one so a host script
            // reading it by name still finds the token.
            const ta = document.createElement('textarea');
            ta.name = 'h-captcha-response';
            ta.style.display = 'none';
            ta.value = token;
            document.body.appendChild(ta);
            touched++;
        }

        // The widget's own success callback, under the names hCaptcha's own
        // API and Imperva's wrapper use.
        const callbacks = [
            window.hcaptchaCallback,
            window.onHcaptchaSubmit,
            window.captchaCallback,
            window.onCaptchaSuccess,
        ];

        let called = 0;
        for (const cb of callbacks) {
            if (typeof cb === 'function') {
                try { cb(token); called++; } catch (e) { log('callback threw', e.message); }
            }
        }

        // data-callback names a global by string; honour it too.
        const host = document.querySelector('[data-callback]');
        const named = host && window[host.getAttribute('data-callback')];
        if (typeof named === 'function') {
            try { named(token); called++; } catch (e) { log('named callback threw', e.message); }
        }

        log(`token applied to ${touched} field(s), ${called} callback(s) fired`);

        // Some Imperva walls submit the enclosing form themselves once the
        // response is set; others wait for it. Submitting a form that has
        // already been submitted is harmless, doing nothing when it was needed
        // is not.
        const form = document.querySelector('form');
        if (form) {
            try { form.submit(); } catch (e) { log('form submit threw', e.message); }
        }
    }

    if (!isChallenge()) return;

    const siteKey = findSiteKey();

    if (!siteKey) {
        log('challenge detected but no sitekey found — reporting rather than guessing');
        chrome.runtime.sendMessage({ type: 'hive-challenge-unsolvable', reason: 'no sitekey on page' });
        return;
    }

    log(`challenge detected, sitekey ${siteKey}`);

    chrome.runtime.sendMessage(
        { type: 'hive-solve-challenge', siteKey, pageUrl: window.location.href },
        (reply) => {
            if (chrome.runtime.lastError) {
                log(`no reply from background: ${chrome.runtime.lastError.message}`);
                return;
            }

            if (!reply || !reply.ok || !reply.token) {
                log(`solve failed: ${(reply && reply.error) || 'no token returned'}`);
                return;
            }

            applyToken(reply.token);
        }
    );
})();
