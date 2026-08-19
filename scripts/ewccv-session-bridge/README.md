# EWCCV Session Bridge

Lets hive.contractors verify subcontractors' workers comp policies on
ewccv.com — without the server ever having to pass a captcha.

## Why this exists

ewccv.com's search API is gated by **reCAPTCHA v3 Enterprise**, and the server
cannot pass it. This was chased to the bottom on 2026-08-16:

- Provider-solved tokens (anti-captcha and 2captcha, at every score target)
  are rejected.
- So are tokens minted by **EWCCV's own page** inside an automated Chrome —
  headless *and* headed, on a residential IP.
- The tokens are not malformed. A control probe proved it: an invalid token
  comes back `"reason":"MALFORMED"`, ours `"reason":"No reason provided"`.
  EWCCV evaluates them and Google simply scores an automated session too low.

Everything else about the integration already works unattended: login by magic
link, filing the sign-in email, searching, subscribing, persisting. Only the
credential was missing.

Your browser earns that credential without trying, because you are a real
person on a residential connection. So the server stops fighting the wall and
borrows the session you already have — the same play as the Yelp Session Bridge
on gs.construction, which solved this exact class of problem there.

## What is actually sent

One value: the **accessToken** EWCCV issues after a successful verification —
the credential its search API wants — plus the login JWT so the server can act
as the same signed-in user. Nothing else is collected, and no other site is
touched: `host_permissions` covers only `www.ewccv.com` and the Hive server.

The POST runs in the service worker, which bypasses CORS for hosts in
`host_permissions` — no preflight, no server-side CORS config.

## Install

1. Open `chrome://extensions`
2. Turn on **Developer mode** (top right)
3. **Load unpacked** → select this folder
4. Click the extension → **Settings**
5. Server URL: `https://hive.contractors` (or `http://127.0.0.1:8000` for dev)
6. Bridge token: the `EWCCV_BRIDGE_TOKEN` value from the server's `.env`

## Use

Sign in to [ewccv.com](https://www.ewccv.com/cvs/search) as normal and leave a
tab open. That's it.

The extension sends the session:

- **when an ewccv.com page finishes loading** (i.e. right after you sign in),
- **every 20 minutes** while a tab is open, so the server's copy stays fresh,
- **on demand**, via the popup's *Send session now* button.

If the site refuses to mint a token silently, do one search on the page by hand
and press send again — a search always produces one, and the extension prefers
an existing token over minting its own.

## How the server uses it

`ScrapeEwccvTracking` reads the handed-off token and injects it into the
scraper's session, which then skips every captcha path and searches via the
API. A session older than **45 minutes is ignored** rather than used: EWCCV's
accessToken is session-scoped, and a stale one just fails searches a fresh one
would have completed.

## Security notes

- The bridge token is a bearer credential. Anyone holding it can write the
  server's EWCCV session. Rotate `EWCCV_BRIDGE_TOKEN` if it leaks.
- Auth is compared in constant time; an unconfigured server returns 503 rather
  than treating an empty secret as a match.
- The stored session expires after 12 hours regardless, so a dead token cannot
  linger.

## The sanctioned alternative

EWCCV's own app supports a bypass key:

```
GET /cvs/endpoint/recaptcha/verifybypasskey/?key=<KEY> → {canBypass, accessToken}
```

That is how NCCI supports automated/partner access, and it needs no browser at
all. Support for it is already wired in — set `EWCCV_BYPASS_KEY` and the
extension becomes unnecessary. Worth asking NCCI for one; until then, this
bridge is the way.
