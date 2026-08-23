const $ = id => document.getElementById(id);

function render(state) {
    const el = $('status');
    const bits = [];

    if (state.lastError) bits.push(`Last error: ${state.lastError}`);
    if (state.lastSuccessAt) bits.push(`Last success: ${new Date(state.lastSuccessAt).toLocaleString()}`);
    if (state.lastMessage) bits.push(state.lastMessage);

    el.className = state.lastError ? 'error' : 'muted';
    el.textContent = bits.join('\n') || 'Never run.';
}

async function refresh() {
    const s = await chrome.storage.local.get(['serverUrl', 'token', 'lastError', 'lastSuccessAt', 'lastMessage']);
    $('serverUrl').value = s.serverUrl || '';
    $('token').value = s.token || '';
    render(s);
}

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
