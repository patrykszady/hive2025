const FIELDS = ['serverUrl', 'token'];
const DEFAULTS = { serverUrl: 'https://hive.contractors', token: '' };

chrome.storage.sync.get(FIELDS).then((cfg) => {
    for (const f of FIELDS) document.getElementById(f).value = cfg[f] ?? DEFAULTS[f];
});

document.getElementById('save').addEventListener('click', async () => {
    const out = {};
    for (const f of FIELDS) out[f] = document.getElementById(f).value.trim();
    out.enabled = true;
    await chrome.storage.sync.set(out);
    document.getElementById('saved').textContent = 'Saved';
    setTimeout(() => { document.getElementById('saved').textContent = ''; }, 2000);
});
