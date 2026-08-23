#!/usr/bin/env bash
#
# One-time host provisioning for the Menards receipt browser.
#
# Run once per server, as the deploy user (Forge's `forge` has passwordless
# sudo). Safe to re-run: every step checks before acting.
#
#   bash scripts/provision-menards-browser.sh
#
# What it does, and why each piece exists:
#
#   1. Installs Xvfb/x11vnc/websockify/noVNC (the headless display and the way a
#      person can look at it), xdotool (how `menards:browser login` types), and
#      ghostscript (PdfMerger's fallback merger).
#
#   2. Installs Google Chrome from Google's .deb rather than the distro's
#      chromium-browser, which on Ubuntu 24.04 is a snap shim that does not work
#      reliably headless.
#
#   3. Force-installs the receipt extension through Chrome enterprise policy.
#      This is the step that makes the browser reproducible. Chrome removed
#      --load-extension in 137, and 151 ignores the --disable-features escape
#      hatch, so the only ways in are the "Load unpacked" button — which needs a
#      human at a VNC session and is lost whenever the profile is rebuilt — or a
#      managed policy, which survives both.
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXT_SRC="$APP_DIR/scripts/menards-receipt-extension"
EXT_HOME=/opt/menards-extension
POLICY_DIR=/etc/opt/chrome/policies/managed
CHROME=/usr/bin/google-chrome-stable

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

say "Host packages"
MISSING=()
for pkg in xvfb x11vnc websockify novnc xdotool ghostscript; do
    dpkg -s "$pkg" >/dev/null 2>&1 || MISSING+=("$pkg")
done
if [ ${#MISSING[@]} -gt 0 ]; then
    echo "installing: ${MISSING[*]}"
    sudo apt-get update -qq
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y "${MISSING[@]}"
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
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y "$TMP/chrome.deb"
    rm -rf "$TMP"
    echo "installed: $("$CHROME" --version 2>/dev/null)"
fi

say "Packing the extension"
# The signing key fixes the extension id. Losing it means a new id, which means
# the policy below points at an extension that no longer exists — so it is
# generated once and kept out of the app directory and out of git.
sudo mkdir -p "$EXT_HOME"
sudo chown "$USER" "$EXT_HOME"
KEY="$EXT_HOME/extension.pem"

rm -rf "$EXT_HOME/src"
cp -r "$EXT_SRC" "$EXT_HOME/src"
# defaults.json carries the bridge token and is rewritten by menards:browser
# start; it must not be baked into a signed package.
rm -f "$EXT_HOME/src/defaults.json"

if [ -f "$KEY" ]; then
    "$CHROME" --pack-extension="$EXT_HOME/src" --pack-extension-key="$KEY" --no-sandbox >/dev/null 2>&1 || true
else
    "$CHROME" --pack-extension="$EXT_HOME/src" --no-sandbox >/dev/null 2>&1 || true
    mv "$EXT_HOME/src.pem" "$KEY"
    chmod 600 "$KEY"
fi
mv -f "$EXT_HOME/src.crx" "$EXT_HOME/menards.crx"

EXT_ID=$(openssl rsa -in "$KEY" -pubout -outform DER 2>/dev/null \
    | openssl dgst -sha256 -binary | head -c16 | xxd -p | tr -d '\n' | tr '0-9a-f' 'a-p')
VERSION=$(python3 -c "import json;print(json.load(open('$EXT_SRC/manifest.json'))['version'])")
echo "id: $EXT_ID  version: $VERSION"

say "Chrome policy"
cat > "$EXT_HOME/update.xml" <<XML
<?xml version='1.0' encoding='UTF-8'?>
<gupdate xmlns='http://www.google.com/update2/response' protocol='2.0'>
  <app appid='$EXT_ID'>
    <updatecheck codebase='file://$EXT_HOME/menards.crx' version='$VERSION' />
  </app>
</gupdate>
XML

sudo mkdir -p "$POLICY_DIR"
sudo tee "$POLICY_DIR/menards-receipt-sync.json" >/dev/null <<JSON
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
sudo chmod 644 "$POLICY_DIR/menards-receipt-sync.json"
chmod -R a+rX "$EXT_HOME"

say "Done"
cat <<TXT
Extension id: $EXT_ID

Next, from the application directory:

  php artisan menards:browser check     # confirms every binary above
  php artisan menards:browser start     # display + browser + VNC, writes defaults.json
  php artisan menards:browser login     # signs in from receipt_accounts
  php artisan menards:browser status    # expect: running yes / chrome yes / signed_in yes

To watch it, tunnel and open noVNC — never expose these ports:

  ssh -L 6098:127.0.0.1:6098 $USER@<this-server>
  http://127.0.0.1:6098/vnc.html?autoconnect=1&resize=scale
TXT
