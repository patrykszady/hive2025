#!/usr/bin/env bash
#
# Host provisioning for the Menards receipt browser.
#
#   bash scripts/provision-menards-browser.sh            # full provision (may need sudo)
#   bash scripts/provision-menards-browser.sh repack     # repack the extension only (no sudo)
#
# FULL PROVISION — once per server, then only when the host itself changes:
#
#   1. Installs Xvfb/x11vnc/websockify/noVNC (the headless display and the way a
#      person can look at it), xdotool (how `menards:browser login` types), and
#      ghostscript (PdfMerger's fallback merger).
#
#   2. Installs Google Chrome from Google's .deb rather than the distro's
#      chromium-browser, which on Ubuntu 24.04 is a snap shim that does not work
#      reliably headless.
#
#   3. Packs the receipt extension and force-installs it through Chrome
#      enterprise policy. Chrome removed --load-extension in 137, and 151
#      ignores the --disable-features escape hatch, so the only ways in are the
#      "Load unpacked" button — a human at a VNC session, lost whenever the
#      profile is rebuilt — or a managed policy, which survives both.
#
#   Every step checks before acting, and sudo is only invoked for steps that are
#   actually needed — a re-run on a provisioned box uses no sudo at all. When
#   sudo IS needed, has no cached credential, and there is no terminal to ask on
#   (a Forge deploy), the script prints the exact commands for a human instead
#   of hanging silently at a password prompt nobody can see.
#
# REPACK — safe on every deploy, and the deploy script runs it:
#
#   Re-stages the extension source, skips out if nothing changed (hash marker),
#   otherwise packs a new .crx with the EXISTING signing key and refreshes
#   update.xml. No sudo: everything it touches lives in EXT_HOME, which full
#   provisioning chowned to the deploy user. The policy file is untouched — it
#   names only the extension id and the update.xml path, neither of which
#   changes.
#
#   Each pack stamps a generated, strictly increasing version into the STAGED
#   manifest (never the repo's): Chrome only applies a force-installed update
#   when the version increases, so packing unchanged "1.0.0" forever would mean
#   no extension change ever reached the browser.
#
# The last line of repack output is machine-readable — "REPACK: changed",
# "REPACK: unchanged", or "REPACK: unavailable" — for `menards:browser ensure`.
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXT_SRC="$APP_DIR/scripts/menards-receipt-extension"
# Overridable so the pack/policy flow is testable on a dev box without root.
EXT_HOME="${MENARDS_EXT_HOME:-/opt/menards-extension}"
POLICY_DIR="${MENARDS_POLICY_DIR:-/etc/opt/chrome/policies/managed}"
CHROME="${MENARDS_CHROME:-/usr/bin/google-chrome-stable}"
KEY="$EXT_HOME/extension.pem"
MODE="${1:-provision}"

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

# Run a command as root, without ever hanging a non-interactive run at a
# password prompt. Deploys have no tty: a prompt there just freezes the deploy.
as_root() {
    if [ "$(id -u)" = 0 ]; then "$@"; return; fi
    if sudo -n true 2>/dev/null; then sudo -n "$@"; return; fi
    if [ -t 0 ]; then sudo "$@"; return; fi
    echo "NEEDS ROOT (no tty to ask for a password): $*" >&2
    echo "Run the full provision once by hand: bash scripts/provision-menards-browser.sh" >&2
    exit 1
}

# Hash of the extension source that actually ships — defaults.json included.
# It MUST ship: a packed, policy-installed extension resolves
# chrome.runtime.getURL('defaults.json') inside its own installed copy, not in
# this source directory, so config left out of the package simply does not
# exist as far as the extension is concerned. (The first version stripped it
# and the production extension could never have learned its URL or token while
# every status check read the source file and said "configured".) Including it
# in the hash is what makes a token rotation repack — its content is otherwise
# byte-stable across rewrites.
source_hash() {
    (cd "$EXT_SRC" && find . -type f -print0 | sort -z \
        | xargs -0 sha256sum | sha256sum | cut -c1-16)
}

# Increasing 4-component version, one tick per second, every component within
# Chrome's 0–65535 range: 1.<days since 2026-01-01>.<HHMM>.<SS>. Derived from a
# SINGLE reading of the clock: three separate date calls could straddle UTC
# midnight and emit a version below the one already installed, which Chrome
# silently refuses to downgrade to — and the hash marker would then pin that
# never-installed pack as current.
generated_version() {
    local now days rem hhmm ss
    now=$(date -u +%s)
    days=$(( (now - 1767225600) / 86400 ))   # 1767225600 = 2026-01-01 UTC
    rem=$(( now % 86400 ))
    hhmm=$(( rem / 3600 * 100 + rem % 3600 / 60 ))
    ss=$(( rem % 60 ))
    echo "1.${days}.${hhmm}.${ss}"
}

# Stage, version-stamp, pack, refresh update.xml, record the marker.
# Needs EXT_HOME to exist and be writable; creates the key on first pack.
pack_extension() {
    # A "key" field in the manifest would pin the extension id to a private key
    # this server does not have, so the policy below would force-install an id
    # that exists nowhere. That exact mismatch shipped once; never again.
    if python3 -c "import json,sys; sys.exit(0 if 'key' in json.load(open('$EXT_SRC/manifest.json')) else 1)"; then
        echo "ERROR: manifest.json contains a \"key\" field — remove it; the id must come from this server's signing key." >&2
        exit 1
    fi

    local version
    version=$(generated_version)

    rm -rf "$EXT_HOME/src" "$EXT_HOME/src.crx" "$EXT_HOME/src.pem"
    cp -r "$EXT_SRC" "$EXT_HOME/src"

    python3 - "$EXT_HOME/src/manifest.json" "$version" <<'PYEOF'
import json, sys
path, version = sys.argv[1], sys.argv[2]
d = json.load(open(path))
d['version'] = version
json.dump(d, open(path, 'w'), indent=2)
PYEOF

    # Chrome's output is captured, not discarded: a pack that starts failing
    # (a Chrome update, a removed binary) must say WHY, and under set -e a
    # silent death here once left callers reading an absent marker as "no
    # change" while the extension quietly went stale.
    local pack_out
    if [ -f "$KEY" ]; then
        pack_out=$("$CHROME" --pack-extension="$EXT_HOME/src" --pack-extension-key="$KEY" --no-sandbox 2>&1) \
            || { echo "ERROR: chrome pack failed:" >&2; echo "$pack_out" >&2; return 1; }
    else
        pack_out=$("$CHROME" --pack-extension="$EXT_HOME/src" --no-sandbox 2>&1) \
            || { echo "ERROR: chrome pack failed:" >&2; echo "$pack_out" >&2; return 1; }
        [ -f "$EXT_HOME/src.pem" ] || { echo "ERROR: packing produced no signing key" >&2; return 1; }
        mv "$EXT_HOME/src.pem" "$KEY"
        chmod 600 "$KEY"
    fi
    [ -s "$EXT_HOME/src.crx" ] || { echo "ERROR: packing produced no .crx" >&2; return 1; }
    mv -f "$EXT_HOME/src.crx" "$EXT_HOME/menards.crx"

    EXT_ID=$(openssl rsa -in "$KEY" -pubout -outform DER 2>/dev/null \
        | openssl dgst -sha256 -binary | head -c16 | xxd -p | tr -d '\n' | tr '0-9a-f' 'a-p')
    case "$EXT_ID" in
        [a-p]*) [ ${#EXT_ID} -eq 32 ] || { echo "ERROR: bad extension id '$EXT_ID'" >&2; return 1; } ;;
        *) echo "ERROR: could not derive an extension id from $KEY" >&2; return 1 ;;
    esac

    cat > "$EXT_HOME/update.xml" <<XML
<?xml version='1.0' encoding='UTF-8'?>
<gupdate xmlns='http://www.google.com/update2/response' protocol='2.0'>
  <app appid='$EXT_ID'>
    <updatecheck codebase='file://$EXT_HOME/menards.crx' version='$version' />
  </app>
</gupdate>
XML

    source_hash > "$EXT_HOME/.source-hash"
    # Owner-only: the packed .crx now carries the bridge token. Chrome runs as
    # this same user, so it can still read everything it needs.
    chmod -R go-rwx "$EXT_HOME"
    chmod 600 "$KEY"
    echo "id: $EXT_ID  version: $version"
}

# ── repack mode ───────────────────────────────────────────────────────────────
if [ "$MODE" = "repack" ]; then
    if [ ! -d "$EXT_HOME" ] || [ ! -f "$KEY" ]; then
        echo "Extension home or signing key missing — full provisioning has not run on this host."
        echo "REPACK: unavailable"
        exit 2
    fi

    if [ "${FORCE:-0}" != "1" ] && [ -f "$EXT_HOME/.source-hash" ] \
        && [ "$(source_hash)" = "$(cat "$EXT_HOME/.source-hash")" ]; then
        echo "Extension source unchanged since last pack."
        echo "REPACK: unchanged"
        exit 0
    fi

    if pack_extension; then
        echo "REPACK: changed"
        exit 0
    fi

    echo "REPACK: failed"
    exit 3
fi

[ "$MODE" = "provision" ] || { echo "usage: $0 [provision|repack]" >&2; exit 1; }

# ── full provision ────────────────────────────────────────────────────────────
say "Host packages"
MISSING=()
for pkg in xvfb x11vnc websockify novnc xdotool ghostscript; do
    dpkg -s "$pkg" >/dev/null 2>&1 || MISSING+=("$pkg")
done
if [ ${#MISSING[@]} -gt 0 ]; then
    echo "installing: ${MISSING[*]}"
    as_root apt-get update -qq
    as_root env DEBIAN_FRONTEND=noninteractive apt-get install -y "${MISSING[@]}"
else
    echo "already present"
fi

say "Google Chrome"
if [ -x "$CHROME" ]; then
    echo "already present: $("$CHROME" --version 2>/dev/null)"
else
    TMP=$(mktemp -d)
    curl -fsSL -o "$TMP/chrome.deb" \
        https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
    as_root env DEBIAN_FRONTEND=noninteractive apt-get install -y "$TMP/chrome.deb"
    rm -rf "$TMP"
    echo "installed: $("$CHROME" --version 2>/dev/null)"
fi

say "Packing the extension"
if [ ! -d "$EXT_HOME" ] || [ ! -w "$EXT_HOME" ]; then
    as_root mkdir -p "$EXT_HOME"
    # ${SUDO_USER:-$USER}: under `sudo bash …` $USER is root, and chowning to
    # root would leave every later no-sudo deploy-time repack unable to write.
    as_root chown "${SUDO_USER:-$USER}" "$EXT_HOME"
fi
pack_extension || exit 1

say "Chrome policy"
POLICY_FILE="$POLICY_DIR/menards-receipt-sync.json"
POLICY_CONTENT=$(cat <<JSON
{
  "ExtensionInstallForcelist": ["$EXT_ID;file://$EXT_HOME/update.xml"],
  "ExtensionInstallAllowlist": ["$EXT_ID"],
  "ExtensionSettings": {
    "$EXT_ID": {
      "installation_mode": "force_installed",
      "update_url": "file://$EXT_HOME/update.xml"
    }
  }
}
JSON
)
if [ -f "$POLICY_FILE" ] && [ "$(cat "$POLICY_FILE" 2>/dev/null)" = "$POLICY_CONTENT" ]; then
    echo "already in place"
# Unprivileged first: the dir may already be writable (a test override, or a
# root run). Escalate only when the plain write actually fails.
elif (mkdir -p "$POLICY_DIR" && printf '%s\n' "$POLICY_CONTENT" > "$POLICY_FILE" && chmod 644 "$POLICY_FILE") 2>/dev/null; then
    echo "written: $POLICY_FILE"
else
    # Via a temp file, not a pipe: `printf | as_root tee` makes stdin a pipe,
    # which as_root's [ -t 0 ] tty test reads as "no terminal" — wrongly
    # refusing an interactive user whose sudo merely wants a password.
    POLICY_TMP=$(mktemp)
    printf '%s\n' "$POLICY_CONTENT" > "$POLICY_TMP"
    as_root mkdir -p "$POLICY_DIR"
    as_root cp "$POLICY_TMP" "$POLICY_FILE"
    as_root chmod 644 "$POLICY_FILE"
    rm -f "$POLICY_TMP"
    echo "written: $POLICY_FILE (as root)"
fi

say "Done"
SERVER_ADDR=$(hostname -I 2>/dev/null | awk '{print $1}')
[ -n "$SERVER_ADDR" ] || SERVER_ADDR=$(hostname)
cat <<TXT
Extension id: $EXT_ID

Everything from here is one command — it starts the stack, configures the
extension from the environment, and signs in from receipt_accounts:

  php artisan menards:browser ensure    # every line of its status must read yes

It is also safe to run any time; the deploy script and the scheduler both do.

To watch the browser (optional — nothing requires it), tunnel and open noVNC.
The 127.0.0.1 in both is correct: the tunnel makes your machine's 127.0.0.1
reach this server's loopback, and these ports must never be public.

  ssh -L 6098:127.0.0.1:6098 $USER@$SERVER_ADDR
  http://127.0.0.1:6098/vnc.html?autoconnect=1&resize=scale
TXT
