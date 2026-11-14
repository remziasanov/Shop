// Telegram Mini App bridge (TM-07). Loaded only by layouts.telegram, i.e. in the Telegram mode (D-101, D-104).
// A small Alpine component: theme colours, safe areas, BackButton, MainButton, haptics, closing confirmation and the
// links that must leave the Mini App. Every page works without it: the buttons on the page stay visible and usable.

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    /** #rrggbb of any CSS colour (the MainButton wants hex); null when the browser cannot resolve it. */
    const toHex = (value) => {
        try {
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = 1;
            const context = canvas.getContext('2d', { willReadFrequently: true });
            context.clearRect(0, 0, 1, 1);
            context.fillStyle = '#000';
            context.fillStyle = value;
            context.fillRect(0, 0, 1, 1);
            const [r, g, b] = context.getImageData(0, 0, 1, 1).data;

            return '#' + [r, g, b].map((part) => part.toString(16).padStart(2, '0')).join('');
        } catch (error) {
            return null;
        }
    };

    Alpine.data('tgBridge', (config = {}) => ({
        tg: null,
        mainEl: null,
        mainQueued: false,
        writeAsked: false,

        init() {
            const tg = window.Telegram?.WebApp;

            if (!tg) {
                return;
            }

            this.tg = tg;

            // The page was opened outside Telegram (a cookie shared by the in-app browser, TM-03): drop the mode.
            if (!tg.initData) {
                this.leave();

                return;
            }

            const call = (fn) => {
                try {
                    fn();
                } catch (error) {
                    // An older client without the method: the page works without it.
                }
            };

            call(() => tg.ready());
            call(() => tg.expand());

            this.applyTheme();
            this.applySafeArea();
            call(() => tg.onEvent('themeChanged', () => this.applyTheme()));
            call(() => tg.onEvent('safeAreaChanged', () => this.applySafeArea()));
            call(() => tg.onEvent('contentSafeAreaChanged', () => this.applySafeArea()));

            call(() => this.initBackButton());
            call(() => this.initMainButton());
            call(() => this.initHaptics());
            this.initLinks();
            this.initCheckout();
            this.initShare();
            this.initPhone();
        },

        leave() {
            // One attempt per tab: a server that keeps the mode would otherwise reload forever.
            if (!config.leaveUrl || window.sessionStorage.getItem('tg_leave_tried')) {
                return;
            }

            window.sessionStorage.setItem('tg_leave_tried', '1');

            fetch(config.leaveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf },
            }).finally(() => window.location.reload());
        },

        // ---------------------------------------------------------------- theme and safe areas

        applyTheme() {
            const tg = this.tg;
            const params = tg.themeParams || {};
            const root = document.documentElement;

            Object.entries(params).forEach(([key, value]) => root.style.setProperty('--tg-theme-' + key.replace(/_/g, '-'), value));
            root.dataset.tgScheme = tg.colorScheme === 'dark' ? 'dark' : 'light';

            // The Telegram theme drives the page, text and lines in both modes (light / dark, themeChanged); the accent
            // stays the one of the shop unless the setting asks for the colours of Telegram.
            const tokens = {
                '--shop-bg': params.bg_color,
                '--shop-surface': params.section_bg_color || params.secondary_bg_color || params.bg_color,
                '--shop-ink': params.text_color,
                '--shop-muted': params.hint_color,
                '--shop-line': params.section_separator_color || (params.hint_color && params.bg_color ? 'color-mix(in srgb, var(--shop-muted) 30%, var(--shop-bg))' : null),
            };

            if (config.colors === 'telegram') {
                tokens['--shop-primary'] = params.button_color;
                tokens['--shop-on-primary'] = params.button_text_color;
            }

            ['--shop-bg', '--shop-surface', '--shop-ink', '--shop-muted', '--shop-line', '--shop-primary', '--shop-on-primary'].forEach((name) => {
                if (tokens[name]) {
                    root.style.setProperty(name, tokens[name]);
                } else {
                    root.style.removeProperty(name);
                }
            });

            if (params.bg_color) {
                try {
                    tg.setHeaderColor(params.bg_color);
                    tg.setBackgroundColor(params.bg_color);
                } catch (error) {
                    // Not supported by this client.
                }
            }

            this.syncMain();
        },

        applySafeArea() {
            const safe = this.tg.safeAreaInset || {};
            const content = this.tg.contentSafeAreaInset || {};
            const root = document.documentElement;
            const sum = (side) => (Number(safe[side]) || 0) + (Number(content[side]) || 0);

            root.style.setProperty('--shop-safe-top', sum('top') + 'px');
            root.style.setProperty('--shop-safe-right', sum('right') + 'px');
            root.style.setProperty('--shop-safe-bottom', sum('bottom') + 'px');
            root.style.setProperty('--shop-safe-left', sum('left') + 'px');
        },

        // ---------------------------------------------------------------- BackButton

        initBackButton() {
            const back = this.tg.BackButton;
            const start = window.location.pathname === '/';

            back.onClick(() => {
                if (window.history.length > 1) {
                    window.history.back();
                } else {
                    window.location.href = '/';
                }
            });

            if (start) {
                back.hide();
            } else {
                back.show();
            }
        },

        // ---------------------------------------------------------------- MainButton

        initMainButton() {
            const main = this.tg.MainButton;

            main.onClick(() => {
                if (this.mainEl && document.contains(this.mainEl)) {
                    this.mainEl.click();
                }
            });

            this.syncMain();

            // The page changes under Livewire and Alpine: look again after every change, once per frame.
            new MutationObserver(() => this.queueMain()).observe(document.body, {
                subtree: true,
                childList: true,
                characterData: true,
                attributes: true,
                attributeFilter: ['disabled', 'aria-disabled', 'aria-busy', 'hidden', 'style', 'class', 'data-tg-busy'],
            });
        },

        queueMain() {
            if (this.mainQueued) {
                return;
            }

            this.mainQueued = true;
            window.requestAnimationFrame(() => {
                this.mainQueued = false;
                this.syncMain();
            });
        },

        /** The first visible element marked data-tg-main is the page's main button. */
        findMain() {
            return Array.from(document.querySelectorAll('[data-tg-main]')).find((el) => el.getClientRects().length > 0) || null;
        },

        syncMain() {
            if (!this.tg || !this.tg.initData || !this.tg.MainButton) {
                return;
            }

            const main = this.tg.MainButton;
            const el = this.findMain();
            this.mainEl = el;

            if (!el) {
                main.hide();

                return;
            }

            let label = el.dataset.tgMainText || el.innerText || '';

            // Text for screen readers only («: название товара») is not for the button.
            el.querySelectorAll('.sr-only').forEach((node) => {
                label = label.replace(node.innerText || node.textContent || '', '');
            });

            const text = label.replace(/\s+/g, ' ').trim();
            const busy = el.getAttribute('aria-busy') === 'true' || el.hasAttribute('data-tg-busy') || (el.disabled && el.hasAttribute('wire:loading.attr'));
            const active = !(el.disabled || el.getAttribute('aria-disabled') === 'true');
            const params = { text: text.slice(0, 64) || ' ', is_visible: true, is_active: active && !busy };
            const style = window.getComputedStyle(document.documentElement);
            const color = toHex(style.getPropertyValue('--shop-primary').trim());
            const textColor = toHex(style.getPropertyValue('--shop-on-primary').trim());

            if (color) {
                params.color = color;
            }

            if (textColor) {
                params.text_color = textColor;
            }

            try {
                main.setParams(params);
            } catch (error) {
                main.setText(params.text);
                main.show();
            }

            if (busy) {
                main.showProgress(false);
            } else {
                main.hideProgress();
            }
        },

        // ---------------------------------------------------------------- haptics, closing confirmation

        initHaptics() {
            const haptic = this.tg.HapticFeedback;

            // The shared toast of «Товар добавлен в корзину» (SF-41).
            window.addEventListener('cart-toast', (event) => {
                haptic.notificationOccurred(event.detail?.error ? 'error' : 'success');
            });

            // A validation error shown in a form.
            new MutationObserver((records) => {
                const added = records.some((record) => Array.from(record.addedNodes).some((node) => node.nodeType === 1 && (node.matches?.('[role="alert"] *, p[id$="-error"]') || node.querySelector?.('p[id$="-error"]'))));

                if (added) {
                    haptic.notificationOccurred('error');
                }
            }).observe(document.body, { subtree: true, childList: true });
        },

        initCheckout() {
            const tg = this.tg;

            // Closing the Mini App with a half-filled checkout form asks for confirmation.
            document.addEventListener('input', (event) => {
                if (event.target.closest?.('[data-tg-checkout]')) {
                    try {
                        tg.enableClosingConfirmation();
                    } catch (error) {
                        // Not supported by this client.
                    }
                }
            });
            window.addEventListener('pagehide', () => {
                try {
                    tg.disableClosingConfirmation();
                } catch (error) {
                    // Not supported by this client.
                }
            });

            // TM-05: the bot may not write yet: ask once, before the form goes out. A refusal does not block the order.
            document.addEventListener('submit', (event) => {
                const form = event.target;

                if (!form.matches?.('[data-tg-checkout][data-tg-write]') || this.writeAsked || typeof tg.requestWriteAccess !== 'function') {
                    return;
                }

                event.preventDefault();
                event.stopImmediatePropagation();
                this.writeAsked = true;

                const finish = (allowed) => {
                    if (config.writeUrl) {
                        fetch(config.writeUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf },
                            body: JSON.stringify({ allowed: Boolean(allowed) }),
                        }).catch(() => {}).finally(() => form.requestSubmit());
                    } else {
                        form.requestSubmit();
                    }
                };

                try {
                    tg.requestWriteAccess((allowed) => finish(allowed));
                } catch (error) {
                    finish(false);
                }
            }, true);
        },

        // ---------------------------------------------------------------- links

        initLinks() {
            document.addEventListener('click', (event) => {
                if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) {
                    return;
                }

                const link = event.target.closest?.('a[href]');

                if (!link) {
                    return;
                }

                let url;

                try {
                    url = new URL(link.href, window.location.href);
                } catch (error) {
                    return;
                }

                if (url.protocol !== 'http:' && url.protocol !== 'https:') {
                    return;
                }

                if (/^(t|telegram)\.me$/i.test(url.hostname)) {
                    event.preventDefault();
                    this.tg.openTelegramLink(url.href);
                } else if (url.host !== window.location.host || link.hasAttribute('data-tg-browser')) {
                    event.preventDefault();
                    this.tg.openLink(url.href);
                }
            });
        },

        /** «Поделиться» on the product page: the share dialog of Telegram with the startapp link (TM-09). */
        initShare() {
            document.addEventListener('click', (event) => {
                const button = event.target.closest?.('[data-tg-share]');

                if (!button) {
                    return;
                }

                event.preventDefault();
                const link = button.dataset.tgShare;
                const text = button.dataset.tgShareText || '';
                this.tg.openTelegramLink('https://t.me/share/url?url=' + encodeURIComponent(link) + '&text=' + encodeURIComponent(text));
            });
        },

        /** «Подставить мой номер» (TG-09): the contact goes to the bot; the verified number comes back from the server. */
        initPhone() {
            document.addEventListener('click', (event) => {
                const button = event.target.closest?.('[data-tg-phone]');

                if (!button || typeof this.tg.requestContact !== 'function') {
                    return;
                }

                event.preventDefault();
                this.tg.requestContact((shared) => {
                    if (shared) {
                        this.pollPhone(button.dataset.tgPhone, 0);
                    }
                });
            });
        },

        pollPhone(target, attempt) {
            if (!config.phoneUrl || attempt > 8) {
                return;
            }

            window.setTimeout(() => {
                fetch(config.phoneUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                    .then((response) => response.json())
                    .then((data) => {
                        const input = document.getElementById(target);

                        if (data.phone && input) {
                            input.value = data.phone;
                            input.dispatchEvent(new Event('input', { bubbles: true }));
                        } else {
                            this.pollPhone(target, attempt + 1);
                        }
                    })
                    .catch(() => this.pollPhone(target, attempt + 1));
            }, 1000);
        },
    }));
});
