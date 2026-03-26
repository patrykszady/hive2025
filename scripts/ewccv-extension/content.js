/**
 * EWCCV Extension — Content Script
 *
 * Runs in the extension's isolated world on ewccv.com pages.
 * When a trigger is found in localStorage, injects the page-world script
 * that handles reCAPTCHA verification WITHOUT CDP/Puppeteer active.
 */
(function () {
    'use strict';

    // Only act when the scraper has set a trigger
    if (!localStorage.getItem('ewccv_trigger')) return;

    // Inject the page-world script (needs access to grecaptcha, Redux store, etc.)
    const s = document.createElement('script');
    s.src = chrome.runtime.getURL('injected.js');
    s.onload = function () { s.remove(); };
    (document.head || document.documentElement).appendChild(s);
})();
