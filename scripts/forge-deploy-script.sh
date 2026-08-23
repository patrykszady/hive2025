cd /home/forge/hive.contractors

# Stash any uncommitted changes (like auto-generated vendor files)
git stash

# Discard untracked files that would collide with tracked files coming in.
# public/build/* is committed here (see .gitignore's !/public/build), so a
# local `npm run build` that produced a file the incoming commit also tracks
# makes `git pull` abort with "untracked working tree files would be
# overwritten". `git stash` does not cover untracked files; a scoped clean of
# the build dir does. -d for dirs, -f to act, limited to public/build so
# nothing else is touched.
git clean -df public/build

git pull origin $FORGE_SITE_BRANCH

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Build frontend assets (VITE_* vars baked in at build time)
npm ci --no-audit --no-fund
npm run build

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock

if [ -f artisan ]; then
    $FORGE_PHP artisan migrate --force

    # Publish log-viewer assets (no --force option exists)
    $FORGE_PHP artisan log-viewer:publish

    # Scout reindexing after migrations (synchronous for deployment stability)
    echo 'Running Scout reindexing synchronously...'
    $FORGE_PHP artisan scout:reindex
    echo 'Scout reindexing completed!'
fi

$FORGE_PHP artisan horizon:terminate
$FORGE_PHP artisan reverb:restart
# NEVER `artisan optimize` here. It runs route:cache, which DROPS Livewire's
# obfuscated script route (/livewire-xxxxxxxx/livewire.js) -> 404 -> no
# Livewire AND no Alpine (Livewire bundles it) -> every interactive page is
# dead. It also runs config:cache, which makes env() return null at runtime
# (that blanked the app name out of every browser tab title). Both took the
# site down on 2026-08-15. Leave production UNCACHED unless someone has
# re-verified route caching against the Livewire prefix.
$FORGE_PHP artisan optimize:clear

# Smoke test: the single file whose absence kills the whole front end. If
# this fails the deploy is marked failed in Forge rather than going quietly.
# The pattern must stay loose around the filename: Livewire serves
# livewire.min.js in production (and livewire.js elsewhere), and matching
# only 'livewire\.js' made every deploy false-fail — which then skipped the
# Nightwatch restart below, because the script exits here on failure.
echo 'Verifying Livewire assets...'
LW_URL=$(curl -sS --max-time 20 https://hive.contractors/login | grep -oE 'src="[^"]*livewire[^"]*\.js[^"]*"' | head -1 | sed 's/^src="//; s/"$//')
if [ -z "$LW_URL" ]; then
    echo 'DEPLOY CHECK FAILED: no Livewire script tag found on /login'; exit 1
fi
LW_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$LW_URL")
if [ "$LW_CODE" != "200" ]; then
    echo "DEPLOY CHECK FAILED: Livewire JS returned $LW_CODE for $LW_URL"; exit 1
fi
echo "Livewire assets OK ($LW_URL)"

# Restart Nightwatch daemon with better error handling
echo 'Restarting Nightwatch daemon...'
sudo supervisorctl restart nightwatch-agent 2>/dev/null || {
    echo 'Nightwatch not running, starting it...'
    sudo supervisorctl start nightwatch-agent || echo 'Failed to start Nightwatch (may not be configured)'
}

# Menards receipt browser — dispatch one idempotent health pass, DETACHED.
#
# ensure starts a PERSISTENT browser (Xvfb/Chrome/x11vnc/websockify) that is
# meant to outlive the deploy. Run in the foreground it holds the deploy's SSH
# channel open until Forge's 10-minute timeout, and the deploy is marked FAILED
# even though the site itself deployed cleanly — which is exactly what happened
# on the first run (started..ended was exactly 600s). setsid + redirected fds +
# background returns the deploy immediately; the hourly scheduler owns steady
# state, and this just makes a post-deploy pass happen without waiting on it.
setsid $FORGE_PHP artisan menards:browser ensure >> storage/logs/menards-ensure.log 2>&1 < /dev/null &
echo 'menards:browser ensure dispatched in background (see storage/logs/menards-ensure.log)'
