// Vendor picker (<x-vendor-picker>): Flux's own searchable select, fed in the
// browser. The whole vendor list is fetched once per page from
// /vendors/picker-options; only the best matches (60 at most) are rendered as
// Flux <ui-option>s, built from a server-rendered Flux option, so a page with
// twenty pickers costs one fetch and a few dozen options, never 851 per
// picker. Flux keeps the selected value in the select itself, so rebuilding
// the options while typing never drops the selection.

const lists = new Map(); // url -> Promise<vendor[]>

function normalize(text) {
    return String(text ?? '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

function escapeHtml(text) {
    return String(text ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function loadVendors(url) {
    if (!lists.has(url)) {
        const promise = fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => {
                if (!response.ok) throw new Error(`vendor list: HTTP ${response.status}`);
                return response.json();
            })
            .then((body) => (body.vendors || []).map((vendor) => ({
                ...vendor,
                haystack: normalize([vendor.name, vendor.legal, vendor.city, vendor.state].filter(Boolean).join(' ')),
            })))
            .catch((error) => {
                lists.delete(url);
                throw error;
            });
        lists.set(url, promise);
    }
    return lists.get(url);
}

// A vendor saved in another tab or a long-lived page: ask for the list again.
window.hiveVendorPicker = { forget: () => lists.clear() };
document.addEventListener('vendor-picker:reload', () => lists.clear());

document.addEventListener('alpine:init', () => {
    window.Alpine.data('vendorPicker', (config) => ({
        value: '',            // the Livewire property (x-modelable) and the Flux select (x-model)
        query: '',            // the Flux search box (x-model)
        open: false,          // Flux's open / close events: the list renders only while open
        extra: config.extra || [],
        details: !!config.details,
        url: config.url,
        template: config.template || '',
        initial: null,        // the chosen option the server printed: {value, label}
        limit: config.limit || 60,
        vendors: [],
        loaded: false,
        loading: false,
        failed: false,
        reloadedFor: null,

        init() {
            // The server prints the chosen option so the button has its label
            // before the list arrives; read it before render() replaces it.
            const printed = this.$root.querySelector('[data-vendor-picker-options] ui-option');
            if (printed) this.initial = { value: printed.getAttribute('value'), label: printed.getAttribute('label') };
            // One cached fetch per page serves every picker on it.
            this.load();
            // A value the list does not hold (a vendor added since the page
            // loaded): fetch the list again, once per value.
            this.$watch('value', () => {
                if (this.loaded && this.hasValue && !this.known(this.value) && this.reloadedFor !== String(this.value)) {
                    this.reloadedFor = String(this.value);
                    lists.delete(this.url);
                    this.loaded = false;
                    this.load();
                }
            });
        },

        load() {
            if (this.loading) return;
            this.loading = true;
            this.failed = false;
            loadVendors(this.url)
                .then((vendors) => {
                    this.vendors = vendors;
                    this.loaded = true;
                    this.$nextTick(() => this.refreshButtonLabel());
                })
                .catch(() => { this.failed = true; })
                .finally(() => { this.loading = false; });
        },

        get hasValue() {
            return this.value !== '' && this.value !== null && this.value !== undefined;
        },

        known(value) {
            return this.extra.some((option) => option.value === String(value))
                || this.vendors.some((vendor) => String(vendor.id) === String(value));
        },

        optionLabel(vendor) {
            if (this.details && vendor.city) {
                return `${vendor.name} — ${vendor.city}${vendor.state ? ', ' + vendor.state : ''}`;
            }
            return vendor.name;
        },

        // The chosen option: a static choice, a vendor from the list, the one
        // the server printed, or a stand-in until the list knows it.
        get selectedOption() {
            if (!this.hasValue) return null;
            const value = String(this.value);
            const extra = this.extra.find((option) => option.value === value);
            if (extra) return extra;
            const vendor = this.vendors.find((candidate) => String(candidate.id) === value);
            if (vendor) return { value, label: this.optionLabel(vendor) };
            if (this.initial && String(this.initial.value) === value) return this.initial;
            return { value, label: this.loaded ? `Vendor #${value}` : '…' };
        },

        get terms() {
            return normalize(this.query.trim()).split(/\s+/).filter(Boolean);
        },

        matches(label) {
            const terms = this.terms;
            const text = normalize(label);
            return terms.every((term) => text.includes(term));
        },

        get filtered() {
            const terms = this.terms;
            if (!terms.length) return this.vendors.slice(0, this.limit);
            const whole = terms.join(' ');
            const scored = [];
            for (const vendor of this.vendors) {
                if (!terms.every((term) => vendor.haystack.includes(term))) continue;
                const name = normalize(vendor.name);
                const score = name.startsWith(whole) ? 0
                    : name.split(/\s+/).some((word) => word.startsWith(terms[0])) ? 1
                    : name.includes(whole) ? 2
                    : 3;
                scored.push([score, vendor]);
            }
            scored.sort((a, b) => a[0] - b[0] || a[1].name.localeCompare(b[1].name));
            return scored.slice(0, this.limit).map((entry) => entry[1]);
        },

        option(value, label, hidden = false) {
            const html = this.template
                .replaceAll('__VP_VALUE__', escapeHtml(value))
                .replaceAll('__VP_LABEL__', escapeHtml(label));
            return hidden ? html.replace('<ui-option', '<ui-option data-hidden') : html;
        },

        note(text) {
            return `<div class="px-2 py-1.5 text-sm text-zinc-500 dark:text-zinc-400">${escapeHtml(text)}</div>`;
        },

        // The options Flux shows: the static choices and the matching vendors,
        // plus the chosen one whenever it is not among them (hidden when it does
        // not match the search), because Flux labels its button from that option.
        // A closed picker keeps only the chosen option, so a page with many
        // pickers carries one option each instead of 60 (1.3 KB apiece).
        render() {
            if (!this.open) {
                const chosen = this.selectedOption;
                return chosen ? this.option(chosen.value, chosen.label) : '';
            }
            const rows = [];
            const seen = new Set();
            const add = (value, label, hidden = false) => {
                const key = String(value);
                if (seen.has(key)) return;
                seen.add(key);
                rows.push(this.option(value, label, hidden));
            };
            const listed = this.extra.filter((option) => this.matches(option.label))
                .map((option) => [option.value, option.label])
                .concat(this.filtered.map((vendor) => [String(vendor.id), this.optionLabel(vendor)]));
            const selected = this.selectedOption;
            if (selected && !listed.some(([value]) => value === String(selected.value))) {
                add(selected.value, selected.label, !this.matches(selected.label));
            }
            listed.forEach(([value, label]) => add(value, label));

            if (this.failed) rows.push(this.note('Could not load the vendor list. Reload the page to try again.'));
            else if (!this.loaded) rows.push(this.note('Loading vendors…'));

            requestAnimationFrame(() => this.highlight());

            return rows.join('');
        },

        select() {
            return this.$root.querySelector('ui-select');
        },

        // Like Flux's own list: on open the chosen option (or the first) is
        // highlighted, while searching the first match, so Enter picks it.
        highlight() {
            const activatable = this.select()?._activatable;
            if (!this.open || !activatable) return;
            if (this.query.trim()) {
                activatable.activateFirst?.();
                return;
            }
            const chosen = this.select().querySelector('ui-option[data-selected]:not([data-hidden])');
            activatable.activateSelectedOrFirst?.(chosen || undefined);
        },

        // Flux draws the button label once per value; when the list brings a
        // better label for the same value, have it draw again (setting the
        // value from code fires no input event, so Livewire is not touched).
        refreshButtonLabel() {
            const select = this.select();
            const option = this.selectedOption;
            if (!select || !option || (this.initial && this.initial.value === option.value && this.initial.label === option.label)) return;
            const current = select.value;
            if (current === '' || current === null || current === undefined) return;
            select.value = '';
            select.value = current;
        },
    }));
});
