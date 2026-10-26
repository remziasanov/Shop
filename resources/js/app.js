// Alpine.js is provided by Livewire (@livewireScripts), see docs/DECISIONS.md D-008.
// Components are registered on "alpine:init" (Livewire starts Alpine on DOMContentLoaded, after this module ran).

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

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
    Alpine.data('productGallery', (count) => ({
        index: 0,
        count,
        fullscreen: false,
        touchX: null,

        go(i) {
            this.index = (i + this.count) % this.count;
        },

        next() {
            this.go(this.index + 1);
        },

        prev() {
            this.go(this.index - 1);
        },

        openFullscreen() {
            this.fullscreen = true;
            document.body.classList.add('overflow-hidden');
            this.$nextTick(() => this.$refs.close?.focus());
        },

        closeFullscreen() {
            this.fullscreen = false;
            document.body.classList.remove('overflow-hidden');
            this.$nextTick(() => this.$root.querySelectorAll('[data-gallery-open]')[this.index]?.focus());
        },

        touchStart(event) {
            this.touchX = event.changedTouches[0].clientX;
        },

        touchEnd(event) {
            if (this.touchX === null || this.count < 2) {
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
