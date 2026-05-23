/**
 * Shared infinite horizontal scroll mixin for planner views (cards/table/gantt).
 *
 * Spread the returned object into your Alpine.data component and:
 *   1. Set x-ref="<refName>" on the scrollable element.
 *   2. Forward its @scroll.passive to this.onInfiniteScroll().
 *   3. Call this._initInfiniteScroll() inside your init().
 *   4. Optionally implement onInfiniteLoad() for post-load hooks (re-measure etc).
 *   5. Optionally implement onInfiniteScrollFrame() for per-rAF scroll work
 *      (kept off the synchronous scroll handler — runs at most once per frame).
 *
 * How scroll preservation works (the smooth part):
 *   When the user nears the left edge we call $wire.loadPreviousDays(), which
 *   prepends columns. We snapshot scrollWidth BEFORE the load and attach a
 *   ResizeObserver to the scrollable element's first child (the grid). The
 *   ResizeObserver callback fires *synchronously before the next paint*, so
 *   we can adjust scrollLeft in the same frame the browser would have painted
 *   the shift. Result: no visible jump, even on slow renders.
 *
 * Backend contract:
 *   - $wire.loadPreviousDays() prepends days
 *   - $wire.loadFutureDays()   appends  days
 */
window.plannerInfiniteScroll = function (refName) {
    return {
        _infRef: refName,
        // Distance (px) from either edge at which we kick off the next load.
        // Set generously so the new chunk has already arrived by the time the
        // user actually scrolls there — preload, not just-in-time load.
        _infThreshold: 1500,
        _infMinLoadIntervalMs: 600,
        isLoadingPrevious: false,
        isLoadingFuture: false,
        _pendingPrependScrollWidth: null,
        _lastLoadAt: 0,
        _resizeObserver: null,
        _scrollRaf: 0,

        _initInfiniteScroll() {
            this.$nextTick(() => {
                const container = this._infiniteScrollContainer();
                if (!container) return;

                // Observe the grid (first child) — its width changes the instant
                // a prepend/append morph commits to layout. The callback fires
                // before paint, which is exactly when we need to fix scrollLeft.
                const grid = container.firstElementChild;
                if (grid && 'ResizeObserver' in window) {
                    this._resizeObserver = new ResizeObserver(() => this._onGridResize());
                    this._resizeObserver.observe(grid);
                }

                this._ensureScrollable();
            });
        },

        _destroyInfiniteScroll() {
            this._resizeObserver?.disconnect();
            this._resizeObserver = null;
        },

        _infiniteScrollContainer() {
            return this.$refs[this._infRef] ?? null;
        },

        _ensureScrollable() {
            const container = this._infiniteScrollContainer();
            if (!container) return;
            const overflows = container.scrollWidth > container.clientWidth + this._infThreshold;
            if (overflows || this.isLoadingFuture) return;
            this._triggerLoad('end');
        },

        // Coalesce scroll-event work into one rAF tick. Avoids running the
        // edge-detection (and any per-scroll user work) dozens of times per
        // second during momentum scroll.
        onInfiniteScroll() {
            if (this._scrollRaf) return;
            this._scrollRaf = requestAnimationFrame(() => {
                this._scrollRaf = 0;
                this._checkEdges();
                if (typeof this.onInfiniteScrollFrame === 'function') {
                    this.onInfiniteScrollFrame();
                }
            });
        },

        _checkEdges() {
            const now = performance.now();
            if (now - this._lastLoadAt < this._infMinLoadIntervalMs) return;

            const container = this._infiniteScrollContainer();
            if (!container) return;

            const maxScroll = container.scrollWidth - container.clientWidth;
            const atLeft  = container.scrollLeft <= this._infThreshold;
            const atRight = container.scrollLeft >= maxScroll - this._infThreshold;

            if (atLeft && !this.isLoadingPrevious) {
                this._triggerLoad('start');
            } else if (atRight && !this.isLoadingFuture) {
                this._triggerLoad('end');
            }
        },

        _triggerLoad(direction) {
            const container = this._infiniteScrollContainer();
            if (!container) return;
            this._lastLoadAt = performance.now();

            if (direction === 'start') {
                this.isLoadingPrevious = true;
                // Snapshot scrollWidth so ResizeObserver can compute the delta.
                this._pendingPrependScrollWidth = container.scrollWidth;
                this.$wire.loadPreviousDays();
            } else {
                this.isLoadingFuture = true;
                this.$wire.loadFutureDays();
            }
        },

        // Runs synchronously before the next paint when the grid resizes.
        // For a prepend, shift scrollLeft by exactly the width that was added
        // so the viewport visually stays put.
        _onGridResize() {
            const container = this._infiniteScrollContainer();
            if (!container) return;

            if (this._pendingPrependScrollWidth !== null) {
                const delta = container.scrollWidth - this._pendingPrependScrollWidth;
                if (delta > 0) {
                    container.scrollLeft += delta;
                }
                this._pendingPrependScrollWidth = null;
            }

            this.isLoadingPrevious = false;
            this.isLoadingFuture = false;

            if (typeof this.onInfiniteLoad === 'function') {
                this.onInfiniteLoad();
            }

            // If after the load we're still not overflowing, fetch more so the
            // user can keep scrolling.
            requestAnimationFrame(() => this._ensureScrollable());
        },
    };
};
