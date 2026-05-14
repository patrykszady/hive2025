# Background Worker Droplet — Plan & Reference

> Status: **Reference only — not yet implemented.**
> Step 1 (queueing all `Schedule::call` work via `RunScheduledTask` on the
> `background` queue) was completed on the existing droplet so the same code
> will "just work" once a second worker droplet picks up that queue.

---

## Why a second droplet

The single DigitalOcean droplet currently runs:

- nginx + PHP-FPM (serves end-user requests, Livewire AJAX, dashboards)
- Horizon workers (queues: `default`, `long-running`, `background`)
- `php artisan schedule:run` every minute
- MySQL (local)
- Redis (local)
- Reverb (websockets)
- Various scheduled scrapers (Menards, Amazon orders, Plaid sync, etc.)

When a heavy scheduled task fires (Plaid sync over hundreds of transactions,
Amazon orders import, Menards receipt scrape, expense auto-match), CPU and
memory spike. PHP-FPM workers stall, MySQL gets slow on the same disk, and
end-users experience perceptible lag in the Livewire UI.

A second droplet dedicated to background work isolates that load.

---

## Target architecture

```
┌─────────────────────────────┐        ┌─────────────────────────────┐
│  Web Droplet                │        │  Worker Droplet             │
│  ─────────────              │        │  ──────────────             │
│  • nginx + PHP-FPM          │        │  • Horizon workers          │
│  • Reverb (websockets)      │        │    (background, default,    │
│  • schedule:run (optional;  │        │     long-running queues)    │
│    or move to worker)       │        │  • schedule:run             │
│  • Horizon supervisor       │        │  • headless Chrome / Dusk   │
│    (default queue only,     │        │    drivers for scrapers     │
│    fast user-facing jobs)   │        │  • Same git checkout        │
└──────────┬──────────────────┘        └──────────┬──────────────────┘
           │                                      │
           └────────────┬─────────────────────────┘
                        │
        ┌───────────────┴─────────────────┐
        │                                 │
┌───────▼──────────┐            ┌─────────▼──────────┐
│ Managed MySQL    │            │ Managed Redis      │
│ (DO Managed DB)  │            │ (DO Managed Redis) │
└──────────────────┘            └────────────────────┘

┌──────────────────────────────────────────────────────┐
│ DigitalOcean Spaces (S3-compatible)                  │
│ • receipt images, uploads, exports                    │
│ • shared by both droplets via S3 filesystem driver    │
└──────────────────────────────────────────────────────┘
```

---

## Prerequisites — make state shareable first

Before adding a second droplet, the following must move off the local web
droplet so both servers see the same state:

1. **MySQL → DigitalOcean Managed Database for MySQL**
   - Create a DO managed MySQL cluster in the same region.
   - `mysqldump` current DB, restore to managed cluster.
   - Update `.env` `DB_*` variables on web droplet, verify, then cut over.
   - Add the worker droplet's private IP to the trusted sources.

2. **Redis → DigitalOcean Managed Redis**
   - Used for cache, sessions, queue, Horizon, broadcast.
   - Create managed Redis in same region/VPC.
   - Update `.env` `REDIS_*` and `CACHE_DRIVER`/`QUEUE_CONNECTION`/`SESSION_DRIVER`.
   - Restart Horizon, PHP-FPM, Reverb.
   - Verify Horizon dashboard still works.

3. **Local file uploads → DigitalOcean Spaces (S3 driver)**
   - Audit `Storage::disk(...)` usage and any `storage_path()` writes that
     produce user-visible files (receipts, exports, generated PDFs).
   - Add Spaces credentials to `.env`, configure `config/filesystems.php`
     with an `s3` disk pointing at Spaces endpoint.
   - Migrate existing files: `aws s3 sync storage/app/public s3://...`.
   - Switch the default disk OR swap individual `Storage::disk('public')`
     callers to the Spaces disk.
   - Update `php artisan storage:link` strategy — public Spaces URLs replace
     the symlinked `public/storage`.

4. **Logs**
   - Either centralise to a managed log service (Papertrail, Logtail) OR
     accept that each droplet has its own `storage/logs/` and use Horizon's
     failed-jobs UI as the single source of truth for queue errors.

5. **Cron / scheduler ownership**
   - Decide which droplet runs `* * * * * php artisan schedule:run`.
   - Recommended: **only the worker droplet** runs the scheduler.
   - Every scheduled task already uses `->onOneServer()` — but that protection
     relies on a shared cache lock, which only works because Redis is shared
     (see step 2). Belt-and-suspenders: keep cron on one droplet only.

---

## Worker droplet provisioning

1. **Create droplet**
   - Same OS / PHP version / extensions as web droplet (replicate via
     snapshot of web droplet, then strip nginx/Reverb).
   - Place in same VPC as web droplet and managed services.
   - Size: start at 2 vCPU / 4 GB. Scale up if Horizon dashboard shows
     sustained high utilisation on the `background` queue.

2. **Deploy code**
   - Same git repo, same branch, same `.env` (with managed-DB credentials).
   - Run `composer install --no-dev --optimize-autoloader`.
   - Do **not** run `npm run build` here — no asset serving.
   - Run migrations from one droplet only (typically web during deploy).

3. **Disable web-facing services**
   - Stop / disable nginx and PHP-FPM (or never install them).
   - No public ports open except SSH from your bastion / IP.

4. **Enable Horizon**
   - `php artisan horizon` under supervisord/systemd.
   - The `config/horizon.php` already has `supervisor-1`, `long-running`,
     `background` defined. The worker droplet's Horizon will pick up jobs
     from all three from the shared Redis.
   - **Optional refinement:** edit `config/horizon.php` so the worker droplet
     only runs the `background` and `long-running` supervisors and the web
     droplet only runs `supervisor-1`. Achieve this by reading
     `gethostname()` or an env var (`HORIZON_ROLE=worker`) and switching the
     `environments` block accordingly.

5. **Enable scheduler**
   - Add cron entry: `* * * * * cd /var/www/hive2025 && php artisan schedule:run >> /dev/null 2>&1`
   - Disable the web droplet's cron entry (or leave it — `onOneServer()`
     prevents double-run, but only one is cleaner).

6. **Headless browser / Chromedriver**
   - The Menards scraper uses Chromedriver (`drivers/chromedriver` exists in
     repo). Install Chrome and dependencies on the worker droplet.

7. **Deploys**
   - Update deploy script to `git pull && composer install` on **both**
     droplets, then `php artisan horizon:terminate` on each (Horizon will
     restart via supervisord and pick up new code).
   - Run migrations from one droplet only.

---

## Verifying the split

After cutover:

- Horizon dashboard (`/horizon`) shows jobs being processed by the worker
  droplet's hostname (visible in supervisor names if you customised them, or
  via metrics).
- Web droplet CPU should drop noticeably during scheduled-task windows
  (every 10 minutes when the bulk-match jobs fire).
- `php artisan schedule:list` is identical on both droplets (same code).
- Failed jobs appear in `/horizon/failed` regardless of which droplet
  processed them.

---

## Rollback plan

Because both droplets share the same Redis and the queue connection is
identical, you can stop Horizon on the worker droplet at any time. Jobs
will simply queue up in Redis and the web droplet's Horizon will drain
them — exactly the behaviour from before introducing the worker droplet.

---

## Future optimisations

- **CDN for uploads:** put DigitalOcean's CDN in front of the Spaces bucket
  for receipt/image delivery.
- **Read replica MySQL:** if reporting queries get heavy, add a read replica
  and route Eloquent reads via Laravel's read/write split.
- **Separate scrape droplet:** if Menards/Amazon scrapes get blocked or need
  rotating IPs, peel them off into a third droplet using residential proxies.
- **Move Reverb to its own droplet** if websocket connections grow into the
  thousands.
