const FIELDS = ['serverUrl', 'token', 'intervalMinutes'];
const DEFAULTS = { serverUrl: 'https://hive.contractors', token: '', intervalMinutes: 20 };

chrome.storage.sync.get(FIELDS).then((cfg) => {
    for (const f of FIELDS) document.getElementById(f).value = cfg[f] ?? DEFAULTS[f];
});

document.getElementById('save').addEventListener('click', async () => {
    const out = {};
    for (const f of FIELDS) out[f] = document.getElementById(f).value.trim();
    out.intervalMinutes = Math.max(5, Math.min(720, Number(out.intervalMinutes) || 20));
    out.enabled = true;
    await chrome.storage.sync.set(out);
    chrome.alarms.create('push-ewccv-session', { periodInMinutes: out.intervalMinutes });
    document.getElementById('saved').textContent = 'Saved';
    setTimeout(() => { document.getElementById('saved').textContent = ''; }, 2000);
});
