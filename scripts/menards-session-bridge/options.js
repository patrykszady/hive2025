const $ = id => document.getElementById(id);

function render(state) {
    const el = $('status');

    if (state.lastError) {
        el.className = 'error';
        el.textContent = `Last error: ${state.lastError}`;

        return;
    }

    el.className = 'muted';
    el.textContent = state.lastPush
        ? `Last sent: ${new Date(state.lastPush).toLocaleString()}`
        : 'Never sent.';
}

chrome.storage.local.get(['serverUrl', 'token', 'lastPush', 'lastError']).then(state => {
    $('serverUrl').value = state.serverUrl || 'https://hive.contractors';
    $('token').value = state.token || '';
    render(state);
});

$('save').addEventListener('click', async () => {
    await chrome.storage.local.set({
        serverUrl: $('serverUrl').value.trim(),
        token: $('token').value.trim(),
    });

    $('status').className = 'muted';
    $('status').textContent = 'Saved.';
});

$('send').addEventListener('click', async () => {
    // Save first: pressing "Send" straight after typing a token, without
    // pressing "Save", otherwise posts with the previous one and reports a
    // confusing 401.
    await chrome.storage.local.set({
        serverUrl: $('serverUrl').value.trim(),
        token: $('token').value.trim(),
    });

    $('status').className = 'muted';
    $('status').textContent = 'Sending…';

    const result = await chrome.runtime.sendMessage({ action: 'push' }).catch(err => ({
        ok: false,
        error: err.message,
    }));

    if (result?.ok) {
        render({ lastPush: new Date().toISOString() });

        return;
    }

    render({ lastError: result?.error || 'unknown error' });
});
