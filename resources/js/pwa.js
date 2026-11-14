// Installed shop on a phone (MB-22, MB-23). No service worker: the shop is online-only (MB-25 is deferred).

const DISMISS_KEY = 'shop_pwa_hint_until';
const DISMISS_DAYS = 90;

/** True in the installed app (Android / desktop display-mode, iOS navigator.standalone). */
const standalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

/** The Telegram Mini App never shows the install hint (the server also leaves it out in Telegram mode). */
const inTelegram = () => Boolean(window.Telegram?.WebApp?.initData);

/** iPhone / iPad Safari: the only place where the instruction «Поделиться → На экран Домой» applies. */
const iosSafari = () => {
    const ua = window.navigator.userAgent;
    const ios = /iphone|ipad|ipod/i.test(ua) || (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);

    return ios && /safari/i.test(ua) && !/crios|fxios|edgios|yabrowser|opios/i.test(ua);
};

const dismissedUntil = () => {
    try {
        return parseInt(window.localStorage.getItem(DISMISS_KEY) || '0', 10);
    } catch (error) {
        return 0;
    }
};

const snooze = () => {
    try {
        window.localStorage.setItem(DISMISS_KEY, String(Date.now() + DISMISS_DAYS * 24 * 60 * 60 * 1000));
    } catch (error) {
        // Private mode: the sheet comes back on the next visit, nothing breaks.
    }
};

window.shopPwa = { standalone, inTelegram, iosSafari };

// The browser fires beforeinstallprompt once and early: keep the event until a component asks for it.
let deferredPrompt = null;

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredPrompt = event;
    window.dispatchEvent(new CustomEvent('pwa-installable'));
});

window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    window.dispatchEvent(new CustomEvent('pwa-installed'));
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('pwaInstall', () => ({
        canInstall: false,
        sheet: false,

        init() {
            if (standalone() || inTelegram()) {
                return;
            }

            this.canInstall = deferredPrompt !== null;
            window.addEventListener('pwa-installable', () => (this.canInstall = true));
            window.addEventListener('pwa-installed', () => {
                this.canInstall = false;
                this.sheet = false;
            });

            // iPhone: one bottom sheet, once. Not on pages whose bottom edge belongs to a sticky bar or has no bar
            // (product, cart, checkout): the sheet would hide the main buttons. The first display counts as the snooze.
            const body = document.body;

            if (!this.$el.hasAttribute('data-pwa-ios-sheet')) {
                return;
            }

            if (body.hasAttribute('data-sticky') || body.dataset.nav === 'off') {
                return;
            }

            this.sheet = iosSafari() && Date.now() > dismissedUntil();

            if (this.sheet) {
                snooze();
            }
        },

        async install() {
            if (!deferredPrompt) {
                return;
            }

            const prompt = deferredPrompt;
            deferredPrompt = null;
            this.canInstall = false;
            prompt.prompt();
            await prompt.userChoice.catch(() => null);
        },

        dismiss() {
            this.sheet = false;
            snooze();
        },
    }));
});

/** MB-23: in the installed app a link to another site opens in the browser, not inside the app window. */
const isExternal = (link) => {
    try {
        const url = new URL(link.href, window.location.href);

        return /^https?:$/.test(url.protocol) && url.origin !== window.location.origin;
    } catch (error) {
        return false;
    }
};

const openExternal = (link) => {
    if (isExternal(link) && !link.target) {
        link.target = '_blank';
        link.rel = 'noopener';
    }
};

document.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('a[href]') : null;

    if (link && standalone()) {
        openExternal(link);
    }
}, true);

document.addEventListener('DOMContentLoaded', () => {
    if (standalone()) {
        document.querySelectorAll('a[href]').forEach(openExternal);
    }
});
