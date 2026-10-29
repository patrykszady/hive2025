/**
 * Signs the person in when Menards signs them out — personal browsers only.
 *
 * Runs on https://www.menards.com/main/login.html. It asks the service worker
 * for the saved Menards credentials; the worker hands them over only in
 * personal mode, with "sign in for me" switched on, and at most a few times
 * an hour. It then fills the form the way the server's Puppeteer script does
 * (the same field selectors, the nearest visible "Sign In" button below the
 * fields) with small human-like pauses, once per page load. Whatever Menards
 * answers next — the account page, an error, Imperva's "I am human" — is the
 * worker's business: it watches this tab's URL and either fetches the
 * receipts or brings the window to the front for a person.
 */
(() => {
    const EMAIL_SELECTORS = ['input[type="email"]', 'input[autocomplete="username"]', 'input[name*="email" i]', 'input[id*="email" i]'];
    const PASSWORD_SELECTORS = ['input[type="password"]'];

    const visible = (el) => {
        const r = el.getBoundingClientRect ? el.getBoundingClientRect() : { width: 1, height: 1 };

        return r.width > 0 && r.height > 0;
    };

    function findField(selectors) {
        for (const sel of selectors) {
            const el = Array.from(document.querySelectorAll(sel)).find(visible);
            if (el) return el;
        }

        return null;
    }

    /** The visible "Sign In" button nearest below the fields — Menards has one above them too, which submits nothing. */
    function pickButton(buttons, fieldsBottom) {
        let best = null;

        for (const button of buttons) {
            if (!visible(button)) continue;

            const label = (button.innerText || button.value || button.textContent || '').trim();
            if (!/sign\s*in|log\s*in/i.test(label)) continue;

            const top = button.getBoundingClientRect ? button.getBoundingClientRect().top : 0;
            if (top < fieldsBottom) continue;
            if (!best || top < best.top) best = { button, top };
        }

        return best ? best.button : null;
    }

    function type(el, value) {
        el.focus?.();
        const setter = Object.getOwnPropertyDescriptor(globalThis.HTMLInputElement?.prototype || {}, 'value')?.set;
        setter ? setter.call(el, value) : (el.value = value);
        el.dispatchEvent?.(new Event('input', { bubbles: true }));
        el.dispatchEvent?.(new Event('change', { bubbles: true }));
    }

    const wait = (ms) => new Promise((r) => setTimeout(r, ms));

    async function signIn(credentials) {
        const email = findField(EMAIL_SELECTORS);
        const password = findField(PASSWORD_SELECTORS);

        if (!email || !password) return { ok: false, reason: 'sign-in form not found on the page' };

        type(email, credentials.email);
        await wait(300 + Math.random() * 300);
        type(password, credentials.password);
        await wait(300 + Math.random() * 300);

        const bottom = Math.max(
            email.getBoundingClientRect ? email.getBoundingClientRect().bottom : 0,
            password.getBoundingClientRect ? password.getBoundingClientRect().bottom : 0,
        );
        const button = pickButton(Array.from(document.querySelectorAll('button, input[type="submit"]')), bottom);

        if (button) {
            button.click();
        } else if (password.form) {
            password.form.requestSubmit ? password.form.requestSubmit() : password.form.submit();
        } else {
            return { ok: false, reason: 'no Sign In button and no form to submit' };
        }

        return { ok: true, reason: button ? 'clicked Sign In' : 'submitted the form' };
    }

    globalThis.__hiveTest = Object.assign(globalThis.__hiveTest || {}, { findField, pickButton, signIn });

    if (!/\/main\/login\.html/i.test(window.location.pathname || '')) return;

    chrome.runtime.sendMessage({ type: 'hive-auto-signin', url: window.location.href }, async (reply) => {
        if (chrome.runtime.lastError || !reply?.credentials) return;

        const result = await signIn(reply.credentials);
        chrome.runtime.sendMessage({ type: 'hive-auto-signin-result', ...result });
    });
})();
