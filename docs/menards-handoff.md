# Menards receipt sync — handoff

Written 2026-08-23. Repo at `main` / `f1797e07`, working tree clean, deployed to
production.

## Where this stands in one paragraph

The old Puppeteer scraper is dead and its cron is removed. Receipts are now
fetched by a Chrome extension running inside a real, signed-in Chrome that lives
on the production server under a virtual display. Every piece of that is built,
deployed, and verified working on production **except** the last one: Imperva is
currently showing an hCaptcha challenge to the production server's IP, so the
browser cannot reach the receipt page. Clearing that needs one human click. See
"The only open problem".

## Why it is built this way

menards.com is behind Imperva. Two things it does, both confirmed by testing:

- A non-browser HTTP client (curl, Guzzle, `MenardsReceiptApi`) gets a 930-byte
  "Incapsula incident ID" block page served as **HTTP 200**. Not a 403 — a 200.
  This is why the old scraper's failures looked like successes for two weeks.
- A CDP-driven browser (Puppeteer) gets an unrendered shell.

A genuine browser with a session a person established is neither, and is served
normally. So: real Chrome on the server, human signs in once, and an extension
inside that browser calls Menards' own JSON endpoints. There is no CDP surface
and no DOM scraping.

**Do not** try to fix this with proxy rotation, stealth plugins, antidetect
browsers, TLS/fingerprint impersonation, or CAPTCHA-solving services. The user
has asked for 2captcha/anticaptcha more than once and I declined each time — a
solver's only purpose is to defeat the anti-bot control the site operator
deliberately installed, and owning the account does not change what that
mechanism is for. Everything in this system is built around that line. Hold it.

## The moving parts

```
production server
  Xvfb :98                     virtual display
  Google Chrome 151            headed, persistent profile at storage/app/menards-browser
    └─ receipt extension       force-installed by Chrome enterprise policy
         ├─ chrome.alarms      fires daily
         ├─ content.js         calls initialize.ajx / receipts.ajx / download.ajx
         └─ background.js      POSTs base64 PDFs to Hive
  x11vnc :5998 (loopback)      so a human can look at the screen
  websockify :6098 (loopback)  serves noVNC over the tunnel

Hive
  POST /api/menards/receipts   bearer-auth, writes a batch dir, queues the import
  ImportMenardsReceiptBatch    runs the existing importer (OCR, matching, dedup)
```

Nothing downstream of the ingest endpoint changed. OCR, expense matching and
de-duplication are the same code paths the browser scraper fed.

### Key files

| File | What it is |
|---|---|
| `app/Services/MenardsRemoteBrowserService.php` | browser lifecycle, automated login, wall detection |
| `app/Console/Commands/MenardsBrowser.php` | `menards:browser start\|stop\|status\|check\|login\|ensure` |
| `app/Http/Controllers/MenardsReceiptIngestController.php` | ingest endpoint (auth, validation, queues the job) |
| `app/Jobs/ImportMenardsReceiptBatch.php` | queued import |
| `scripts/menards-receipt-extension/` | the extension |
| `scripts/provision-menards-browser.sh` | host provisioning + `repack` mode |
| `scripts/forge-api.sh` | Forge API helper (`get-env`, `set-env-key`, deploy script) |

`scripts/menards-session-bridge/` and `app/Services/MenardsReceiptApi.php` are
**superseded**. The API service cannot work (Imperva blocks it) and is kept only
because it documents the three endpoints. Deleting both is fine.

## Commands

```bash
php artisan menards:browser ensure    # the one you want: idempotent, self-healing
php artisan menards:browser status    # every line should read yes
php artisan menards:browser login     # sign in from receipt_accounts
php artisan menards:browser start     # start/restart the stack
bash scripts/provision-menards-browser.sh          # one-time host setup (needs sudo)
bash scripts/provision-menards-browser.sh repack   # repack extension, no sudo
```

`ensure` is scheduled hourly (production only) and dispatched detached from the
deploy script. It holds a 15-minute cache lock so the deploy-time run and the
scheduled run cannot collide.

## What is verified, and what is not

**Verified on production:**
- Host provisioning, Chrome 151, extension force-installed by policy
  (`extension yes` — this was the piece I could not test from dev)
- `configured yes`, `posts_to https://hive.contractors`
- Env vars set via Forge API and confirmed by read-back
- Deploy script runs `ensure` detached (a foreground run hit Forge's 10-minute
  timeout and marked an otherwise-clean deploy as failed)

**Verified on dev only:**
- Full sign-in → receipts.ajx → download.ajx → POST → import chain
- Automated login (signed out, ran `menards:browser login`, signed back in with
  no human — no captcha at that time)
- Ingest auth/validation (401 no token, 401 wrong token, 422 bad shape, 422
  non-PDF payload, database untouched)

**Never verified anywhere:**
- A complete end-to-end sync **on production**. It has never fetched a single
  receipt there. Everything upstream of the wall works; the wall is in the way.

## The only open problem

Production `status` currently reads:

```
running yes / chrome yes / extension yes / configured yes
signed_in  no
page       menards.com/main/receiptLookup.html - Google Chrome
```

That `page` value is the wall's signature. Every real Menards page titles itself
"… at Menards®"; the Imperva challenge page sets no `<title>`, so Chrome falls
back to the bare URL. `looksLikeChallengeWall()` keys on exactly that.

**To clear it:** tunnel in and click "I am human" once.

```bash
ssh -L 6098:127.0.0.1:6098 forge@159.89.236.131
# then, in a laptop browser:
http://127.0.0.1:6098/vnc.html?autoconnect=1&resize=scale
```

Move the mouse into the checkbox and click it — a real pointer movement usually
avoids escalation to an image puzzle. Then `menards:browser login` (or sign in by
hand), and `status` should read `signed_in yes` /
`page Receipt Lookup at Menards®`.

The dev box earned the same wall after roughly six sign-ins and dozens of
navigations in an hour, and it **cooled off on its own** within about twenty
minutes. So the wall may be rate-based rather than a permanent verdict on the IP.
That is the open question: if `storage/logs/menards-ensure.log` keeps showing
`Imperva challenge wall is up` over several days, the datacenter IP is the
problem and the honest options are an occasional manual click or accepting that
Menards does not want this IP automated. Not a solver.

## Gotchas that cost real time

- **`pgrep`/`pkill` with `shell_exec` self-match.** The command runs through
  `sh -c`, whose own argv contains the pattern, so an unguarded check always
  matches the asking shell — `status` reported everything healthy with the
  display long dead, and `stop` killed its own shell. `selfExcludingPattern()`
  wraps the first character in a class (`[X]vfb`). Same trap applies to any
  `pkill` you write in a Bash tool call: your own command line matches.
- **Chrome 151 ignores `--load-extension`.** Removed in 137; the
  `--disable-features=DisableLoadExtensionCommandLineSwitch` escape hatch no
  longer works either, nor does enabling Developer Mode first. Tested all three.
  Policy force-install or the "Load unpacked" button are the only ways in.
- **A packed extension reads `defaults.json` from inside its own package**, not
  from the source tree. Stripping it from the .crx meant production would have
  shown `configured yes` (that check read the source file) while the extension
  had no URL and no token, forever. Dev never shows this because unpacked
  extensions do read the source tree.
- **Menards' session cookie is non-persistent.** The profile sets
  `restore_on_startup=1` so Chrome persists session cookies across restarts;
  without it every restart signs you out.
- **Never `artisan optimize` / `config:cache` on this production box.** The
  deploy script has a long comment about it: `route:cache` drops Livewire's
  obfuscated script route and takes the whole front end down. Production runs
  deliberately uncached, which is also why new env vars work with no cache step.
- **Forge's env endpoint wants `{"environment": ...}`**, not the JSON:API shape
  the deploy-script endpoint uses. Wrong shape gets a 422.
- **Long-lived daemons must be dispatched detached from the deploy script**, or
  they hold the SSH channel to Forge's 10-minute timeout and the deploy is marked
  failed even though everything succeeded.

## Secrets — where they live, do not print them

- Menards credentials: `receipt_accounts.options` (JSON), password encrypted with
  `Crypt::encryptString`. One row has them.
- `MENARDS_BRIDGE_TOKEN`: local `.env` and Forge site env. Also written into
  `scripts/menards-receipt-extension/defaults.json` (gitignored) on every
  `menards:browser start`, and packed into the .crx.
- Extension signing key: `/opt/menards-extension/extension.pem` on the server,
  0600. Losing it changes the extension id and breaks the policy.
- The user pasted a dev sudo password and a broadly-scoped Forge API token into
  the transcript earlier, and the Menards account password turned out to be the
  same string as the sudo password. Rotating all three was recommended and has
  not been confirmed done.

## Suggested next steps, in order

1. Clear the wall on production and get one real end-to-end sync. Until that
   happens nothing here has actually delivered a receipt in production.
2. Watch `storage/logs/menards-ensure.log` for a few days to learn whether the
   wall is rate-based or permanent for that IP.
3. Delete `scripts/menards-session-bridge/` and `MenardsReceiptApi.php`.
4. Consider an alert when no ingest batch has arrived in N days. `ensure` logs
   this but nothing surfaces it, and a silent stop is exactly how the original
   two-week outage went unnoticed.
