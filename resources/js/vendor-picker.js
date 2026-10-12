// Vendor picker (<x-vendor-picker>): the whole vendor list, fetched once per
// page from /vendors/picker-options and filtered in the browser. Renders only
// the matches (60 at most), so a page with twenty pickers costs one fetch and
// a few dozen rows, never 851 options per picker. See the Blade component for
// the markup and VendorPickerOptionsController for the list.

const lists = new Map(); // url -> Promise<vendor[]>

function normalize(text) {
    return String(text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
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
        value: '',            // bound to the Livewire property through x-modelable
        extra: config.extra || [],
        details: !!config.details,
        url: config.url,
        limit: config.limit || 60,
        vendors: [],
        loaded: false,
        loading: false,
        failed: false,
        query: '',
        active: 0,

        init() {
            // A preselected vendor needs the list for its label; otherwise the
            // list loads when the picker first opens.
            this.$watch('value', (value) => {
                if (value !== '' && value !== null && value !== undefined && !this.isExtra(value)) this.load();
            });
            if (this.value !== '' && this.value !== null && this.value !== undefined && !this.isExtra(this.value)) this.load();
        },

        load() {
            if (this.loaded || this.loading) return;
            this.loading = true;
            this.failed = false;
            loadVendors(this.url)
                .then((vendors) => { this.vendors = vendors; this.loaded = true; })
                .catch(() => { this.failed = true; })
                .finally(() => { this.loading = false; });
        },

        isExtra(value) {
            return this.extra.some((option) => String(option.value) === String(value));
        },

        get selected() {
            if (this.value === '' || this.value === null || this.value === undefined) return null;
            const extra = this.extra.find((option) => String(option.value) === String(this.value));
            if (extra) return { label: extra.label };
            const vendor = this.vendors.find((candidate) => String(candidate.id) === String(this.value));
            return vendor ? { label: this.optionLabel(vendor) } : null;
        },

        get label() {
            return this.selected ? this.selected.label : '';
        },

        get hasValue() {
            return this.value !== '' && this.value !== null && this.value !== undefined;
        },

        optionLabel(vendor) {
            if (this.details && vendor.city) {
                return `${vendor.name} — ${vendor.city}${vendor.state ? ', ' + vendor.state : ''}`;
            }
            return vendor.name;
        },

        get terms() {
            return normalize(this.query.trim()).split(/\s+/).filter(Boolean);
        },

        get extraFiltered() {
            const terms = this.terms;
            if (!terms.length) return this.extra;
            return this.extra.filter((option) => terms.every((term) => normalize(option.label).includes(term)));
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

        get items() {
            return [...this.extraFiltered.map((option) => option.value), ...this.filtered.map((vendor) => vendor.id)];
        },

        opened() {
            this.load();
            this.query = '';
            this.active = 0;
            this.$nextTick(() => this.$refs.search?.focus());
        },

        close() {
            const overlay = this.$root.querySelector('[popover]');
            if (overlay && overlay.matches(':popover-open')) overlay.hidePopover();
        },

        choose(value) {
            this.value = value;
            this.close();
        },

        clear() {
            this.value = '';
        },

        move(step) {
            const count = this.items.length;
            if (!count) return;
            this.active = (this.active + step + count) % count;
            this.$nextTick(() => this.$refs.list?.querySelector('[data-active]')?.scrollIntoView({ block: 'nearest' }));
        },

        pick() {
            const value = this.items[this.active];
            if (value !== undefined) this.choose(value);
        },
    }));
});
