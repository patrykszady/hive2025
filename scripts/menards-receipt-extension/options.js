const $ = id => document.getElementById(id);

/**
 * What personalMode() in background.js would auto-detect on this machine —
 * duplicated here (it is one line) so the options page can show it without
 * round-tripping through the service worker.
 */
function detectedMode() {
    const platform = navigator.userAgentData?.platform || navigator.platform || '';
    return /linux/i.test(platform) ? 'Hive server' : 'Personal browser';
}

function modeLabel(personal) {
    if (personal === true) return 'Personal browser (set manually)';
    if (personal === false) return 'Hive server (set manually)';
    return `${detectedMode()} (auto-detected)`;
}

function render(state) {
    const el = $('status');
    const bits = [`Mode: ${modeLabel(state.personal)}`];
    const runBits = [];

    if (state.lastError) runBits.push(`Last error: ${state.lastError}`);
    if (state.lastSuccessAt) runBits.push(`Last success: ${new Date(state.lastSuccessAt).toLocaleString()}`);
    if (state.lastMessage) runBits.push(state.lastMessage);

    if (state.scheduleLine) bits.push(state.scheduleLine);
    bits.push(...(runBits.length ? runBits : ['Never run.']));

    el.className = state.lastError ? 'error' : 'muted';
    el.textContent = bits.join('\n');
}

async function refresh() {
    const s = await chrome.storage.local.get(['serverUrl', 'token', 'lastError', 'lastSuccessAt', 'lastMessage', 'personal', 'schedule']);
    $('serverUrl').value = s.serverUrl || '';
    $('token').value = s.token || '';
    $('personal').value = typeof s.personal === 'boolean' ? (s.personal ? 'personal' : 'server') : 'auto';

    const personal = typeof s.personal === 'boolean' ? s.personal : detectedMode() === 'Personal browser';
    if (personal) {
        const sched = { intervalMinutes: 60, windowStartHour: 7, windowEndHour: 19, timeZone: 'America/Chicago', ...(s.schedule || {}) };
        const alarm = await chrome.alarms.get('menards-receipt-sync').catch(() => null);
        const next = alarm ? new Date(alarm.scheduledTime).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '—';
        s.scheduleLine = `Checks every ${sched.intervalMinutes} min, ${sched.windowStartHour}:00–${sched.windowEndHour}:00 ${sched.timeZone} (set on Hive). Next check: ${next}.`;
    }

    render(s);
}

$('personal-auto-option').textContent = `Auto (detected: ${detectedMode()})`;

refresh();
// The worker writes progress into storage as it goes; mirror it live.
chrome.storage.onChanged.addListener(refresh);

$('save').addEventListener('click', async () => {
    await chrome.storage.local.set({
        serverUrl: $('serverUrl').value.trim(),
        token: $('token').value.trim(),
    });
    $('status').className = 'muted';
    $('status').textContent = 'Saved.';
});

$('personal').addEventListener('change', async () => {
    const v = $('personal').value;

    if (v === 'auto') {
        await chrome.storage.local.remove('personal');
    } else {
        await chrome.storage.local.set({ personal: v === 'personal' });
    }
});

$('sync').addEventListener('click', async () => {
    // Save first, so a token typed but not saved is not silently ignored.
    await chrome.storage.local.set({
        serverUrl: $('serverUrl').value.trim(),
        token: $('token').value.trim(),
    });
    $('status').className = 'muted';
    $('status').textContent = 'Starting sync…';
    chrome.runtime.sendMessage({ action: 'run' }).catch(() => {});
});

/**
 * Auto-start a sync when opened as options.html?sync=1
 *
 * This is how the Laravel scheduler triggers a fetch. A URL is the only way in
 * from outside: chrome.runtime.sendMessage can only be sent by an extension
 * page, and driving the button by screen coordinates was brittle — the earlier
 * approach clicked a fixed x/y that any layout change would break.
 *
 * The URL is a chrome-extension:// one, so opening it does NOT navigate
 * menards.com and cannot draw Imperva's challenge. The fetch itself reuses the
 * receipt tab that is already open and only makes XHR calls.
 */
if (new URLSearchParams(location.search).get('sync') === '1') {
    $('status').className = 'muted';
    $('status').textContent = 'Starting sync (scheduled)…';
    chrome.runtime.sendMessage({ action: 'run' }).catch(() => {});
}
