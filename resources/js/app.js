// Alpine.js is provided by Livewire (@livewireScripts), see docs/DECISIONS.md D-008.
// Components are registered on "alpine:init" (Livewire starts Alpine on DOMContentLoaded, after this module ran).

import './pwa';

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    /**
     * Age gate overlay (AG-10). CSS already hides it for a visitor whose cookie is enough (html[data-age-ok], set by an
     * inline script before the first paint); this only traps the focus and locks the background scroll while it is
     * open. There is no way to close it without an answer.
     */
    Alpine.data('ageGate', (need) => ({
        active: false,

        init() {
            const ok = parseInt(document.documentElement.dataset.ageOk || '0', 10);
            this.active = ok < need;
        },
    }));

    /**
     * Header search with suggestions (SF-21): debounce 300 ms, from 2 characters, up to 8 items,
     * arrow keys + Enter, Escape closes.
     */
    Alpine.data('searchSuggest', (url, initial = '') => ({
        query: initial,
        items: [],
        open: false,
        active: -1,
        timer: null,
        controller: null,

        onInput() {
            clearTimeout(this.timer);
            this.active = -1;

            if (this.query.trim().length < 2) {
                this.items = [];
                this.open = false;
                return;
            }

            this.timer = setTimeout(() => this.fetchItems(), 300);
        },

        async fetchItems() {
            this.controller?.abort();
            this.controller = new AbortController();

            try {
                const response = await fetch(`${url}?q=${encodeURIComponent(this.query.trim())}`, {
                    headers: { Accept: 'application/json' },
                    signal: this.controller.signal,
                });

                if (!response.ok) {
                    return;
                }

                const data = await response.json();
                this.items = (data.items || []).slice(0, 8);
                this.open = this.items.length > 0;
            } catch (error) {
                // Aborted or offline: keep the plain search form working.
            }
        },

        move(step) {
            if (!this.items.length) {
                return;
            }

            this.open = true;

            let next = this.active + step;
            if (next >= this.items.length) {
                next = -1;
            } else if (next < -1) {
                next = this.items.length - 1;
            }

            this.active = next;
        },

        choose(event) {
            if (this.open && this.active >= 0 && this.items[this.active]) {
                event.preventDefault();
                window.location.href = this.items[this.active].url;
            }
        },

        close() {
            this.open = false;
            this.active = -1;
        },
    }));

    /**
     * Product gallery (SF-30): arrows, thumbnails, keyboard, swipe, fullscreen view.
     */
    Alpine.data('productGallery', (count, colors = []) => ({
        index: 0,
        count,
        fullscreen: false,
        touchX: null,
        // VR-11: option value id of each photo (null = common); `color` is the selected colour value.
        colors,
        color: null,

        /** A photo is shown when it is common or belongs to the selected colour. */
        allowed(i) {
            return this.color === null || this.colors[i] == null || this.colors[i] === this.color;
        },

        shown() {
            return [...Array(this.count).keys()].filter((i) => this.allowed(i));
        },

        position() {
            return this.shown().indexOf(this.index) + 1;
        },

        total() {
            return this.shown().length;
        },

        setColor(id) {
            this.color = id ?? null;

            if (!this.allowed(this.index)) {
                this.index = this.shown()[0] ?? 0;
            }
        },

        go(i) {
            const shown = this.shown();

            if (shown.length === 0) {
                return;
            }

            if (shown.includes(i)) {
                this.index = i;

                return;
            }

            // Arrow keys step over the hidden photos: i is the neighbour of the current photo.
            const step = i > this.index ? 1 : -1;
            const at = shown.indexOf(this.index);
            this.index = shown[(at + step + shown.length) % shown.length];
        },

        next() {
            this.go(this.index + 1);
        },

        prev() {
            this.go(this.index - 1);
        },

        init() {
            // The state decides the scroll lock: the browser's back gesture (x-overlay) closes the view as well (MB-04, MB-12).
            this.$watch('fullscreen', (value) => document.body.classList.toggle('overflow-hidden', value));
        },

        openFullscreen() {
            this.fullscreen = true;
            this.$nextTick(() => this.$refs.close?.focus());
        },

        closeFullscreen() {
            this.fullscreen = false;
            this.$nextTick(() => this.$root.querySelectorAll('[data-gallery-open]')[this.index]?.focus());
        },

        touchStart(event) {
            this.touchX = event.changedTouches[0].clientX;
        },

        touchEnd(event) {
            if (this.touchX === null || this.total() < 2) {
                return;
            }

            const delta = event.changedTouches[0].clientX - this.touchX;
            this.touchX = null;

            if (Math.abs(delta) > 40) {
                delta < 0 ? this.next() : this.prev();
            }
        },
    }));
});

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    /**
     * Money like Money::format() on the server: «1 290,50 ₽» with a narrow no-break space between thousands (GP-10).
     */
    const formatKopecks = (kopecks) => {
        const rubles = String(Math.floor(kopecks / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, '\u202F');
        const fraction = kopecks % 100;

        return rubles + (fraction ? ',' + String(fraction).padStart(2, '0') : '') + '\u00A0\u20BD';
    };

    /** Unit price for a quantity: the last tier [min_qty, kopecks] reached, otherwise the base price (GP-10). */
    const tierUnit = (base, tiers, qty) => tiers.reduce((price, [min, tierPrice]) => (min <= qty ? tierPrice : price), base);

    /** The quantity typed into the quantity field of the buy block (`qty-{product}`), or null for other fields. */
    const typedQty = (event) => {
        const field = event.target;

        if (!(field instanceof HTMLInputElement) || !field.id.startsWith('qty-')) {
            return null;
        }

        const qty = parseInt(field.value, 10);

        return Number.isFinite(qty) && qty >= 1 ? qty : 1;
    };

    /**
     * Quantity tiers of the product page of a card without variants (GP-10): the unit price and the total follow the
     * quantity field; the data hold the viewer group's tiers only. Cards with variants use `variantPicker` instead.
     */
    Alpine.data('tierPrice', (base, tiers) => ({
        base,
        tiers,
        qty: 1,
        format: formatKopecks,

        unit() {
            return tierUnit(this.base, this.tiers, this.qty);
        },

        total() {
            return this.unit() * this.qty;
        },

        onQty(event) {
            const qty = typedQty(event);

            if (qty !== null) {
                this.qty = qty;
            }
        },

        reset() {
            this.qty = 1;
        },
    }));

    /**
     * Variant picker of the product page (VR-09, VR-10): reads the variants from <script id="variant-data"> and keeps one
     * selected value per axis. Choosing a value changes price, availability, article, buttons and photos locally, with no
     * request; the address gets `?variant=` through history.replaceState (the canonical link stays the card).
     * A combination without stock is shaded but selectable; one that does not exist moves the other axes to the nearest variant.
     */
    Alpine.data('variantPicker', (labels) => ({
        labels,
        axes: [],
        variants: [],
        selection: {},
        colorType: null,
        current: null,
        qty: 1,
        format: formatKopecks,

        init() {
            const data = JSON.parse(document.getElementById('variant-data')?.textContent ?? '{}');

            this.axes = data.axes ?? [];
            this.variants = data.variants ?? [];
            this.colorType = data.colorType ?? null;

            const start = this.variants.find((variant) => variant.id === data.selected) ?? this.variants[0];

            if (start) {
                this.apply(start, false);
            }
        },

        /** Variant record whose values equal the given selection, if any. */
        find(selection) {
            return this.variants.find((variant) => this.axes.every((axis, i) => variant.v[i] === selection[axis.id])) ?? null;
        },

        /** The record with the display fields the templates use. */
        view(variant) {
            if (!variant) {
                return null;
            }

            return {
                id: variant.id,
                sku: variant.sku,
                bc: variant.bc,
                p: variant.p,
                o: variant.o,
                d: variant.d,
                k: variant.k ?? null,
                t: variant.t ?? [],
                r: variant.r ?? null,
                s: variant.s,
                st: variant.st,
                max: variant.m,
                cartLabel: variant.br ? this.labels.reserve : (variant.bo ? this.labels.backorder : this.labels.cart),
                can: { cart: (variant.c & 1) > 0, reserve: (variant.c & 2) > 0, inquire: (variant.c & 4) > 0, out: (variant.c & 8) > 0 },
                label: this.axes.map((axis, i) => {
                    const value = axis.values.find((item) => item.id === variant.v[i]);

                    return value ? this.labels.pair.replace(':option', axis.name).replace(':value', value.text) : '';
                }).filter(Boolean).join(this.labels.separator),
            };
        },

        apply(variant, replaceUrl = true) {
            this.axes.forEach((axis, i) => {
                this.selection[axis.id] = variant.v[i];
            });
            this.current = this.view(variant);

            if (replaceUrl && window.history?.replaceState) {
                const url = new URL(window.location.href);

                url.searchParams.set('variant', String(variant.id));
                window.history.replaceState(null, '', url);
            }

            if (this.colorType !== null) {
                window.dispatchEvent(new CustomEvent('variant-color', { detail: { id: this.selection[this.colorType] ?? null } }));
            }
        },

        pick(axisId, valueId) {
            const wanted = { ...this.selection, [axisId]: valueId };
            const exact = this.find(wanted);

            if (exact) {
                this.apply(exact);

                return;
            }

            // The combination does not exist: take the variant with this value that shares most of the other choices, available first.
            const index = this.axes.findIndex((axis) => axis.id === axisId);
            const candidates = this.variants.filter((variant) => variant.v[index] === valueId);

            if (candidates.length === 0) {
                return;
            }

            const score = (variant) => this.axes.reduce((sum, axis, i) => sum + (variant.v[i] === this.selection[axis.id] ? 1 : 0), 0);

            candidates.sort((a, b) => (((a.c & 8) > 0) - ((b.c & 8) > 0)) || (score(b) - score(a)));
            this.apply(candidates[0]);
        },

        /** GP-10: unit price in kopecks of the selected variant for the typed quantity (null without group prices). */
        unitKop() {
            return this.current && this.current.k !== null ? tierUnit(this.current.k, this.current.t, this.qty) : null;
        },

        totalKop() {
            return this.unitKop() === null ? null : this.unitKop() * this.qty;
        },

        onQty(event) {
            const qty = typedQty(event);

            if (qty !== null) {
                this.qty = qty;
            }
        },

        resetQty() {
            this.qty = 1;
        },

        /** Shaded: choosing this value (other axes unchanged) gives a variant without stock or none at all. */
        shaded(axisId, valueId) {
            const variant = this.find({ ...this.selection, [axisId]: valueId });

            return !variant || (variant.c & 8) > 0;
        },

        valueText(axisId) {
            const axis = this.axes.find((item) => item.id === axisId);

            return axis?.values.find((value) => value.id === this.selection[axisId])?.text ?? '';
        },

        swatch(value) {
            if (value.image) {
                return `background-image: url("${encodeURI(value.image)}")`;
            }

            return /^#[0-9a-f]{3,8}$/i.test(value.hex ?? '') ? `background-color: ${value.hex}` : '';
        },
    }));

    /**
     * Phone input mask +7 (___) ___-__-__ (SF-44). Cosmetic only: the server normalizes and validates the number.
     */
    Alpine.data('phoneMask', () => ({
        format(event) {
            const input = event.target;
            let digits = input.value.replace(/\D/g, '');

            if (digits.startsWith('7') || digits.startsWith('8')) {
                digits = digits.slice(1);
            }

            digits = digits.slice(0, 10);

            if (digits === '') {
                input.value = '';
                return;
            }

            let result = '+7 (' + digits.slice(0, 3);
            if (digits.length >= 3) result += ')';
            if (digits.length > 3) result += ' ' + digits.slice(3, 6);
            if (digits.length > 6) result += '-' + digits.slice(6, 8);
            if (digits.length > 8) result += '-' + digits.slice(8, 10);

            input.value = result;
        },
    }));

    /**
     * Toast «Товар добавлен в корзину» (SF-41): shown for 6 seconds, announced through the aria-live wrapper.
     */
    Alpine.data('cartToast', (initial = null) => ({
        visible: initial !== null,
        message: initial?.message ?? '',
        error: initial?.error ?? false,
        timer: null,

        init() {
            if (this.visible) {
                this.schedule();
            }
        },

        show(detail = {}) {
            this.message = detail.message ?? '';
            this.error = Boolean(detail.error);
            this.visible = this.message !== '';
            this.schedule();
        },

        hide() {
            this.visible = false;
        },

        schedule() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.hide(), 6000);
        },
    }));

    /**
     * Home page slider (5.2): a scroll-snap track (native swipe), autoplay with an interval, pause on
     * hover / focus / hidden tab, a pause button and arrow keys. Autoplay is off for reduced motion.
     */
    Alpine.data('homeSlider', (options = {}) => ({
        index: 0,
        count: 0,
        playing: false,
        hover: false,
        focus: false,
        timer: null,
        frame: null,
        reduced: false,

        init() {
            this.count = this.$refs.track.children.length;
            this.reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            this.playing = Boolean(options.autoplay) && this.count > 1 && !this.reduced;

            if (this.playing) {
                this.start();
            }
        },

        destroy() {
            this.stop();
        },

        start() {
            this.stop();
            this.timer = setInterval(() => {
                if (!this.hover && !this.focus && !document.hidden) {
                    this.next();
                }
            }, options.interval || 5000);
        },

        stop() {
            clearInterval(this.timer);
            this.timer = null;
        },

        toggle() {
            this.playing = !this.playing;
            this.playing ? this.start() : this.stop();
        },

        go(i) {
            const track = this.$refs.track;
            this.index = (i + this.count) % this.count;
            track.scrollTo({ left: this.index * track.clientWidth, behavior: this.reduced ? 'auto' : 'smooth' });
        },

        next() {
            this.go(this.index + 1);
        },

        prev() {
            this.go(this.index - 1);
        },

        onScroll() {
            cancelAnimationFrame(this.frame);
            this.frame = requestAnimationFrame(() => {
                const track = this.$refs.track;
                this.index = Math.round(track.scrollLeft / (track.clientWidth || 1));
            });
        },
    }));

    /**
     * Product card «В корзину»: a plain POST form (works without JavaScript) sent in the background; the toast and
     * the mini cart are updated from the JSON answer (NF-02: no Livewire component per listing card).
     */
    Alpine.data('cardAdd', () => ({
        busy: false,

        async submit(event) {
            const form = event.target;
            this.busy = true;

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form),
                });
                const data = await response.json().catch(() => ({}));

                window.dispatchEvent(new CustomEvent('cart-toast', {
                    detail: { message: data.message || form.dataset.error, error: !response.ok },
                }));

                if (response.ok) {
                    window.Livewire?.dispatch('cart-updated');
                }
            } catch (error) {
                form.submit();
            } finally {
                this.busy = false;
            }
        },
    }));
});

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    /**
     * Resend button with a countdown (CA-04). `seconds` comes from the server (what is left of the resend pause); the
     * deadline is kept as a timestamp, so a throttled background tab still shows the right time. The server enforces
     * the pause anyway, this only disables the button and shows when it works again.
     */
    Alpine.data('resendCountdown', ({ seconds, wait, ready, label }) => ({
        left: Math.max(0, Math.ceil(seconds)),
        deadline: Date.now() + Math.max(0, seconds) * 1000,
        timer: null,
        announcement: '',

        init() {
            if (this.left > 0) {
                this.announcement = this.text();
                this.timer = setInterval(() => this.tick(), 1000);
            }
        },

        tick() {
            this.left = Math.max(0, Math.ceil((this.deadline - Date.now()) / 1000));

            if (this.left === 0) {
                clearInterval(this.timer);
                this.announcement = ready;
            } else if (this.left % 15 === 0) {
                this.announcement = this.text();
            }
        },

        text() {
            return wait.replace(':time', this.clock());
        },

        clock() {
            return Math.floor(this.left / 60) + ':' + String(this.left % 60).padStart(2, '0');
        },

        get label() {
            return this.left > 0 ? this.text() : label;
        },
    }));
});

/*
 * Phone behaviour (TZ 27, MB-03, MB-06, MB-07, MB-12).
 */

const root = document.documentElement;
const isPhone = () => window.matchMedia('(max-width: 767px)').matches;

/** MB-03: the bottom bar is hidden while a text field has the focus (the on-screen keyboard covers the page). */
const typesText = (element) => element instanceof HTMLElement
    && (element.matches('textarea')
        || (element.matches('input') && !/^(checkbox|radio|button|submit|reset|file|range|color|hidden|image)$/i.test(element.type)));

document.addEventListener('focusin', (event) => {
    if (typesText(event.target)) {
        root.dataset.kb = '';
    }
});

document.addEventListener('focusout', () => {
    // The focus may move to the next field: look at the active element after the browser has moved it.
    setTimeout(() => {
        if (!typesText(document.activeElement)) {
            delete root.dataset.kb;
        }
    }, 60);
});

/**
 * MB-06: the first field with an error is scrolled into view. A server-rendered page does it on load, a Livewire form after
 * the answer to a submit (a collapsed block that holds the field is opened first).
 */
const revealFirstError = () => {
    const field = document.querySelector('[aria-invalid="true"], [data-field-error]');

    if (!field) {
        return;
    }

    const block = field.closest('[data-collapse]');

    if (block && window.Alpine) {
        window.Alpine.$data(block).open = true;
    }

    setTimeout(() => {
        field.scrollIntoView({ block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });

        if (field.matches('input, select, textarea, button, a')) {
            field.focus({ preventScroll: true });
        }
    }, 30);
};

let submitted = false;

document.addEventListener('submit', () => {
    submitted = true;
}, true);

document.addEventListener('DOMContentLoaded', () => window.setTimeout(revealFirstError, 0));

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ succeed }) => {
        succeed(() => {
            if (submitted) {
                submitted = false;
                setTimeout(revealFirstError, 50);
            }
        });
    });
});

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    /**
     * MB-12: `x-overlay="open"` on a dialog, sheet or slide-over. On a phone opening it adds a history entry, so the back
     * button / the back gesture closes the window instead of leaving the page; closing it by hand removes that entry.
     */
    const stack = [];
    let ignorePops = 0;
    let leaving = false;

    /**
     * Leaving a page by navigation while an overlay is open (a form redirect, «Применить» in the filters, a sort link)
     * leaves the overlay's history entry behind. When such an entry is shown again and no overlay is open, step over it,
     * so that Back works as expected. After a reload the entry is only cleaned (stepping back would reopen ?inquiry=1 in a loop).
     */
    const skipLeftoverEntry = () => {
        if (leaving || stack.length > 0 || window.history.state?.shopOverlay !== true) {
            return;
        }

        const navigation = window.performance?.getEntriesByType?.('navigation')?.[0];

        if (navigation?.type === 'reload') {
            window.history.replaceState(null, '');

            return;
        }

        leaving = true;
        window.history.back();
    };

    skipLeftoverEntry();
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            leaving = false;
            skipLeftoverEntry();
        }
    });

    window.addEventListener('popstate', () => {
        if (ignorePops > 0) {
            ignorePops--;

            return;
        }

        const close = stack.pop();

        if (close) {
            close();
        }
    });

    Alpine.directive('overlay', (el, { expression }, { evaluate, evaluateLater, effect }) => {
        const read = evaluateLater(expression);
        let entry = null;

        effect(() => read((open) => {
            if (open && entry === null && isPhone() && !leaving) {
                entry = () => {
                    entry = null;
                    evaluate(`${expression} = false`);
                };
                stack.push(entry);
                window.history.pushState({ shopOverlay: true }, '');
            } else if (!open && entry !== null) {
                const index = stack.indexOf(entry);

                entry = null;

                if (index >= 0) {
                    stack.splice(index, 1);
                    ignorePops++;
                    window.history.back();
                }
            }
        }));
    });

    /**
     * MB-07: «Показать ещё» fetches the next page of the listing, appends its cards and moves the address to ?page=N
     * (history.replaceState). The classic pagination stays in the markup for robots and visitors without JavaScript;
     * on a phone it is hidden once this component runs.
     */
    Alpine.data('loadMore', (labels) => ({
        busy: false,
        failed: false,
        status: '',
        active: true,

        async load() {
            const button = this.$refs.more;
            const url = button?.dataset.next;

            if (!url || this.busy) {
                return;
            }

            this.busy = true;
            this.failed = false;

            try {
                const response = await fetch(url, { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });

                if (!response.ok) {
                    throw new Error('load-more');
                }

                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const grid = this.$root.querySelector('[data-listing-grid]');
                const items = [...page.querySelectorAll('[data-listing-grid] > li')];

                items.forEach((item) => grid.appendChild(document.importNode(item, true)));

                const next = page.querySelector('[data-load-more]')?.dataset.next ?? '';
                const counter = this.$refs.wrap.querySelector('[data-load-count]');
                const fresh = page.querySelector('[data-load-count]')?.textContent;

                button.dataset.next = next;

                if (counter && fresh) {
                    counter.textContent = fresh;
                }

                if (next === '') {
                    // The button that has focus is about to disappear: keep the keyboard position on the first new card.
                    const target = items.length > 0 ? grid.querySelector(`li:nth-child(${grid.children.length - items.length + 1}) a`) : null;

                    target?.focus({ preventScroll: true });
                    this.$refs.wrap.hidden = true;
                }

                window.history.replaceState(null, '', url);
                this.status = labels.loaded.replace(':count', String(items.length));
            } catch (error) {
                this.failed = true;
            } finally {
                this.busy = false;
            }
        },
    }));

    /**
     * MB-04: the sticky bar at the bottom of the product page. Its button presses the main button of the buy block
     * (cart, reservation or inquiry; the block keeps the quantity field and the forms). Without a selected variant it
     * scrolls to the picker and highlights it.
     */
    Alpine.data('stickyBuy', () => ({
        label: '',
        kind: null,

        init() {
            this.refresh();
        },

        /** The main button: the first visible one in the order cart, reservation, inquiry. */
        main() {
            const block = document.querySelector('[data-buy]');

            if (!block) {
                return null;
            }

            for (const key of ['cart', 'reservation', 'inquiry']) {
                const candidate = [...block.querySelectorAll(`[data-action^="${key}"]`)].find((item) => item.offsetParent !== null && !item.disabled);

                if (candidate) {
                    return candidate;
                }
            }

            return null;
        },

        refresh() {
            const button = this.main();

            this.kind = button ? button.dataset.action.split('-')[0] : null;

            if (!button) {
                this.label = '';

                return;
            }

            const copy = button.cloneNode(true);

            copy.querySelectorAll('.sr-only').forEach((node) => node.remove());
            this.label = copy.textContent.replace(/\s+/g, ' ').trim();
        },

        press(current, hasPicker) {
            if (hasPicker && !current) {
                const picker = document.querySelector('[data-variant-picker]');

                picker?.scrollIntoView({ block: 'center', behavior: 'smooth' });
                picker?.classList.remove('shop-highlight');
                // Restart the animation.
                void picker?.offsetWidth;
                picker?.classList.add('shop-highlight');

                return;
            }

            this.main()?.click();
        },
    }));

    /**
     * MB-08: a block that collapses on a phone (the content is always shown from 768 px). The state is kept by Alpine,
     * Livewire does not touch the element (wire:ignore.self in the markup).
     */
    Alpine.data('collapse', (open = false) => ({ open }));
});
