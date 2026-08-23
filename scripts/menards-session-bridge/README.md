# Menards Session Bridge

Lets hive.contractors pull Menards receipts without the server ever having to
pass Imperva.

## Why this exists

The receipt scraper's own sign-in stopped working in August 2026. The captcha is
not the problem — this was checked directly on 2026-08-22:

- **anti-captcha and 2captcha are both live and funded** ($8.49 / $4.77 at the
  time of writing) and the solver still returns a valid hCaptcha token. The
  2026-08-18 13:07 run logged `hCaptcha solved — token length: 1034`.
- Imperva refused the session **anyway**: `Imperva wall persisted after 3
  challenge attempts`.
- The 2026-08-18 01:07 run looked different but was the same failure. It
  reported `No cards found in the Select Card dropdown`, and the debug lines
  underneath show why: `Error page URL: .../main/login.html`. The sign-in had not
  stuck, so the dropdown was empty because it was the login page.

Solving the challenge no longer earns a usable session — Imperva scores the
browser (TLS/fingerprint, datacenter IP, behaviour), not the captcha. No amount
of solver spend changes that.

Your browser is never asked, because you are a real person on a residential
connection. So the server stops fighting the wall and borrows the session you
already have — the same play as the **Yelp cookie bridge on gs.construction**
(DataDome) and the **EWCCV Session Bridge** in this repo (reCAPTCHA Enterprise),
both of which solved this exact class of problem.

## What is actually sent

The **menards.com cookies only**, read via `chrome.cookies.getAll({domain:
'menards.com'})`. Nothing else is collected and no other site is touched:
`host_permissions` covers `www.menards.com` and the Hive server, and that is all.

The server rejects the payload outright if it is not a signed-in session — an
Imperva-only jar (`incap_ses_*`, `visid_incap_*`) means "this browser visited
menards.com", not "this browser is logged in", and handing that to the scraper
would fail 90 seconds into a Chromium run instead of here where you can see it.

The POST runs in the MV3 service worker, which carries no session and no CSRF
token, so the endpoint is bearer-authed and CSRF-exempt.

## Install

1. Set `MENARDS_BRIDGE_TOKEN` in `.env` on the server (and locally if you want to
   test against `127.0.0.1:8000`). Any long random string.
2. Open `chrome://extensions`, enable **Developer mode**, choose **Load
   unpacked**, and pick this folder.
3. Open the extension's **Options**, enter the Hive URL and the same token, and
   press **Save**.
4. Sign in at menards.com in that browser.
5. Press **Send session now**.

After that it re-sends every 3 hours on its own — Menards rotates session
cookies as you browse, so a jar captured once goes stale.

## How the scraper uses it

`ScrapeMenardsReceipts` reads the jar from the cache and passes it to
`menards-scraper.cjs` as `config.cookies`. When it is present the scraper
replays it and **skips sign-in altogether**; when it is absent the scraper logs
in as it always did, so an unpaired machine behaves exactly as before.

If Imperva refuses the borrowed jar the scraper fails with
`SESSION_NOT_ESTABLISHED: the borrowed session was refused` and exits 75, which
the command reports as a skip and retries on the next scheduled run rather than
alerting.

## The known risk

Imperva binds sessions harder than DataDome does: `incap_ses_*` / `visid_incap_*`
are often validated against IP and TLS fingerprint. Replayed from Forge's
datacenter IP they may still be refused, in which case cookie handoff is not
enough on its own and the extension has to do the *fetching* in the browser and
post the results instead. The scraper's error message above is what tells you
that has happened.

If it does work, the follow-up worth building is the equivalent of
`YelpKeepSession` on gs.construction: a scheduled server-side visit that keeps
the session from ageing out and writes rotated cookies back, which is what
removed the "leave Chrome open on your desktop" requirement there.
