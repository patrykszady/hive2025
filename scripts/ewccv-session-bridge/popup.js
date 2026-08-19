const statusEl = document.getElementById('status');
const btn = document.getElementById('send');

function render(s) {
    if (!s) { statusEl.textContent = 'Not sent yet.'; return; }
    statusEl.className = 's ' + (s.ok ? 'ok' : 'bad');
    const when = s.at ? new Date(s.at).toLocaleString() : '';
    statusEl.textContent = (s.ok ? (s.message || 'Sent.') : (s.error || 'Failed.')) + (when ? `\n${when}` : '');
}

chrome.storage.local.get('lastStatus').then((d) => render(d.lastStatus));

btn.addEventListener('click', async () => {
    btn.disabled = true;
    statusEl.className = 's';
    statusEl.textContent = 'Sending…';
    const res = await chrome.runtime.sendMessage({ type: 'hive-ewccv-push-now' });
    render({ ...res, at: new Date().toISOString() });
    btn.disabled = false;
});

document.getElementById('opts').addEventListener('click', (e) => {
    e.preventDefault();
    chrome.runtime.openOptionsPage();
});
