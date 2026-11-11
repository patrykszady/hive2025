/* Skeleton shapes: placeholders drawn from the real content.
 *
 * Every lazy card starts as a hand-made skeleton (x-index-table.placeholder:
 * column defs, a row count, guessed heights). Once the real content has been
 * on screen, this records its shape — the box of every text run, badge,
 * button, avatar and row line, relative to the content region — in
 * localStorage, keyed by the region's name and the page address. The next
 * time the same page starts with that placeholder, the recorded shapes are
 * drawn in its place at exactly the recorded height, so the card is the same
 * size before and after the data arrives and nothing jumps (Patryk,
 * 2026-11-06: the Projects skeleton's rows were 8px taller than the rows).
 *
 * The contract is two attributes, shared by every card, nothing per component:
 *   data-skeleton-source="<name>"  on the loaded content's wrapper
 *   data-skeleton-target="<name>"  on the placeholder's wrapper
 * x-index-table sets both from its heading (or its skeleton-key prop). The
 * measured region is the wrapper's first <tbody> when it has one — the column
 * header row is real on both sides — else the wrapper itself. A pagination
 * footer (.index-table-footer) is recorded too, so the placeholder reserves
 * the same room for it.
 *
 * A page's first visit still shows the hand-made skeleton: nothing is
 * recorded yet. Shapes are replayed only when the placeholder's region is
 * within a quarter of the recorded width; x positions scale with it.
 */

const PREFIX = 'skel:v1:';
const INDEX_KEY = 'skel:v1:index';
const MAX_ENTRIES = 150;
const MAX_BARS = 500;

// Elements drawn as one box, without looking inside.
const ATOMIC = [
    '[data-flux-badge]', '[data-flux-button]', '[data-flux-avatar]', '[data-flux-icon]',
    '[data-flux-select]', '[data-flux-input]', '[data-flux-checkbox]', '[data-flux-switch]',
    'ui-select', 'ui-dropdown', 'ui-checkbox', 'img', 'svg', 'input', 'select', 'textarea', 'button',
].join(',');

// flux:skeleton's own shimmer classes, verbatim minus `relative` (the bars are
// positioned inline), so the bars animate like the hand-made skeleton's lines
// (the classes are in the build because that one uses them).
const SHIMMER = 'before:absolute before:inset-0 before:-translate-x-full overflow-hidden isolate '
    + '[:where(&)]:[--flux-shimmer-color:white] dark:[:where(&)]:[--flux-shimmer-color:var(--color-zinc-900)] '
    + 'before:z-10 before:animate-[flux-shimmer_2s_infinite] '
    + 'before:bg-gradient-to-r before:from-transparent before:via-[var(--flux-shimmer-color)]/50 dark:before:via-[var(--flux-shimmer-color)]/50 before:to-transparent';

const rnd = (n) => Math.round(n);

function keyFor(name) {
    return PREFIX + name + '|' + location.pathname + location.search;
}

function load(key) {
    try {
        const raw = localStorage.getItem(key);

        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function save(key, record) {
    try {
        localStorage.setItem(key, JSON.stringify(record));
        const index = Array.isArray(load(INDEX_KEY)) ? load(INDEX_KEY) : [];
        const next = [key, ...index.filter((k) => k !== key)];
        next.splice(MAX_ENTRIES).forEach((old) => localStorage.removeItem(old));
        localStorage.setItem(INDEX_KEY, JSON.stringify(next));
    } catch {
        // Storage full or unavailable: the hand-made skeleton stays.
    }
}

function regionOf(wrapper) {
    return wrapper.querySelector('tbody') || wrapper;
}

function radiusOf(el, style, rect) {
    let radius = parseFloat(style.borderTopLeftRadius) || 0;
    if (! radius && el.firstElementChild) {
        radius = parseFloat(getComputedStyle(el.firstElementChild).borderTopLeftRadius) || 0;
    }

    return rnd(Math.min(radius, rect.height / 2));
}

// One bar per rendered line of each text run, clipped to the element that
// holds it (a truncated cell's text runs past its box), a little thinner than
// the line so rows read as text, not slabs.
function textBars(el, elRect, baseRect, push) {
    for (const node of el.childNodes) {
        if (node.nodeType !== Node.TEXT_NODE || node.textContent.trim() === '') {
            continue;
        }
        const range = document.createRange();
        range.selectNodeContents(node);
        for (const r of range.getClientRects()) {
            const left = Math.max(r.left, elRect.left, baseRect.left);
            const right = Math.min(r.right, elRect.right, baseRect.right);
            if (right - left < 2 || r.height < 2) {
                continue;
            }
            const height = Math.max(8, rnd(r.height * 0.55));
            push({ left, top: r.top + (r.height - height) / 2, width: right - left, height }, 4);
        }
    }
}

export function record(wrapper) {
    const name = wrapper.getAttribute('data-skeleton-source');
    if (! name) {
        return;
    }
    const region = regionOf(wrapper);
    const base = region.getBoundingClientRect();
    if (base.width < 40 || base.height < 8) {
        return;
    }
    const bars = [];
    const rows = [];
    const push = (r, radius) => {
        if (bars.length < MAX_BARS && r.width >= 2 && r.height >= 2) {
            bars.push([rnd(r.left - base.left), rnd(r.top - base.top), rnd(r.width), rnd(r.height), radius]);
        }
    };
    const walk = (el) => {
        for (const child of el.children) {
            if (bars.length >= MAX_BARS) {
                return;
            }
            const style = getComputedStyle(child);
            if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') {
                continue;
            }
            const rect = child.getBoundingClientRect();
            if (! rect.width || ! rect.height) {
                continue;
            }
            if (child.tagName === 'TR') {
                rows.push([rnd(rect.top - base.top), rnd(rect.height)]);
            }
            if (child.matches(ATOMIC)) {
                push(rect, radiusOf(child, style, rect));
                continue;
            }
            textBars(child, rect, base, push);
            walk(child);
        }
    };
    walk(region);
    if (! bars.length) {
        return;
    }
    const footer = wrapper.querySelector('.index-table-footer');

    save(keyFor(name), {
        w: rnd(base.width),
        h: rnd(base.height),
        region: region === wrapper ? 'self' : 'tbody',
        bars,
        rows,
        footer: footer ? rnd(footer.getBoundingClientRect().height) : 0,
        t: Date.now(),
    });
}

export function apply(wrapper) {
    if (wrapper.hasAttribute('data-skeleton-applied')) {
        return false;
    }
    const name = wrapper.getAttribute('data-skeleton-target');
    const rec = name ? load(keyFor(name)) : null;
    if (! rec || ! Array.isArray(rec.bars) || ! rec.w || ! rec.h) {
        return false;
    }
    const tbody = wrapper.querySelector('tbody');
    const region = rec.region === 'tbody' ? tbody : (tbody ? null : wrapper);
    if (! region) {
        return false;
    }
    const width = region.getBoundingClientRect().width;
    if (! width) {
        return false;
    }
    const sx = width / rec.w;
    if (sx < 0.75 || sx > 1.34) {
        return false;
    }
    wrapper.setAttribute('data-skeleton-applied', '');

    let html = `<div class="skeleton-shapes" style="position:relative;height:${rec.h}px">`;
    (rec.rows || []).slice(0, -1).forEach(([y, h]) => {
        html += `<div class="skeleton-shapes-row" style="position:absolute;top:${y + h - 1}px"></div>`;
    });
    rec.bars.forEach(([x, y, w, h, r]) => {
        html += `<div class="skeleton-shape ${SHIMMER}" style="position:absolute;left:${rnd(x * sx)}px;top:${y}px;width:${rnd(w * sx)}px;height:${h}px;border-radius:${r}px"></div>`;
    });
    html += '</div>';

    if (region.tagName === 'TBODY') {
        const columns = region.closest('table')?.querySelectorAll('thead th, thead td').length || 1;
        region.innerHTML = `<tr><td colspan="${columns}" style="padding:0;border:0">${html}</td></tr>`;
    } else {
        region.innerHTML = html;
    }

    const footer = wrapper.querySelector('.index-table-footer');
    if (rec.footer > 0) {
        const reserve = footer || document.createElement('div');
        reserve.className = 'index-table-footer';
        reserve.style.height = rec.footer + 'px';
        reserve.style.overflow = 'hidden';
        if (! footer) {
            (region.closest('table') || region).insertAdjacentElement('afterend', reserve);
        }
    } else if (footer) {
        footer.remove();
    }

    return true;
}

export function applyAll(root = document) {
    if (! root.querySelectorAll) {
        return;
    }
    root.querySelectorAll('[data-skeleton-target]:not([data-skeleton-applied])').forEach(apply);
}

export function recordAll() {
    document.querySelectorAll('[data-skeleton-source]').forEach(record);
}

let recordTimer = null;
function scheduleRecord(delay = 80) {
    clearTimeout(recordTimer);
    recordTimer = setTimeout(() => requestAnimationFrame(recordAll), delay);
}

let applyQueued = false;
function scheduleApply() {
    if (applyQueued) {
        return;
    }
    applyQueued = true;
    requestAnimationFrame(() => {
        applyQueued = false;
        applyAll();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => applyAll());
} else {
    applyAll();
}

document.addEventListener('livewire:navigated', () => {
    applyAll();
    scheduleRecord();
});

document.addEventListener('livewire:init', () => {
    // After any component or island has morphed its content in, draw any
    // placeholder that arrived with it and remember what the real content
    // looks like now (sorts, filters and page changes keep the record fresh).
    window.Livewire.hook('morphed', () => {
        scheduleApply();
        scheduleRecord();
    });
    window.Livewire.hook('island.morphed', () => {
        scheduleApply();
        scheduleRecord();
    });
});

new MutationObserver(scheduleApply).observe(document.documentElement, { childList: true, subtree: true });

if (document.fonts?.ready) {
    document.fonts.ready.then(() => scheduleRecord(120));
} else {
    scheduleRecord(400);
}

window.skeletonShapes = { record, apply, applyAll, recordAll };
