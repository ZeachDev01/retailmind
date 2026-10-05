(() => {
    'use strict';
    const config = window.retailmindTheme;
    const device = window.matchMedia('(prefers-color-scheme: dark)');
    const modes = ['light', 'dark', 'system'];
    let mode = config.mode;
    if (!config.authenticated) {
        try {
            const local = localStorage.getItem('retailmind.login-theme');
            if (modes.includes(local)) mode = local;
        } catch (_) { /* Storage is optional on shared/private browsers. */ }
    }
    const apply = () => {
        document.documentElement.dataset.theme = mode === 'system' ? (device.matches ? 'dark' : 'light') : mode;
        document.documentElement.style.colorScheme = document.documentElement.dataset.theme;
        document.querySelectorAll('img[src*="/assets/img/retailmind-"], link[rel="icon"][href*="retailmind-favicon-"], [data-brand-base]').forEach(image => {
            const attribute = image.tagName === 'LINK' ? 'href' : 'src';
            if (!image.dataset.brandBase) {
                const original = new URL(image.getAttribute(attribute), document.baseURI);
                const file = original.pathname.split('/').pop();
                const variants = {
                    'retailmind-logo-600x200.png': 'logos/STYLE/logo-header.png',
                    'retailmind-logo-1200x400.png': 'logos/STYLE/logo-header@2x.png',
                    'retailmind-icon-512.png': 'icons/STYLE/icon-64.png',
                    'retailmind-favicon-32.png': 'icons/STYLE/favicon-32.png'
                };
                if (!variants[file]) return;
                image.dataset.brandBase = new URL('retailmind-web-assets%20dark%20mode/', original).href;
                image.dataset.brandVariant = variants[file];
            }
            const theme = document.documentElement.dataset.theme;
            const source = image.dataset.brandBase + image.dataset.brandVariant.replace('STYLE', theme);
            image.setAttribute(attribute, source);
            if (image.dataset.brandVariant === 'logos/STYLE/logo-header.png') {
                image.srcset = source + ' 1x, ' + source.replace('logo-header.png', 'logo-header@2x.png') + ' 2x';
            } else if (image.dataset.brandVariant === 'icons/STYLE/icon-64.png') {
                image.srcset = source + ' 1x, ' + source.replace('icon-64.png', 'icon-192.png') + ' 2x';
            }
        });
        document.querySelectorAll('[data-theme-mode]').forEach(button => {
            button.setAttribute('aria-pressed', String(button.dataset.themeMode === mode));
        });
        document.querySelectorAll('[data-theme-current]').forEach(trigger => {
            const selected = trigger.parentElement.querySelector(`[data-theme-mode="${mode}"]`);
            trigger.dataset.themeCurrent = mode;
            trigger.innerHTML = selected.querySelector('svg').outerHTML + `<span>${mode[0].toUpperCase() + mode.slice(1)}</span>`;
            trigger.setAttribute('aria-label', 'Appearance: ' + mode);
        });
        window.dispatchEvent(new Event('retailmind:themechange'));
    };
    apply();
    device.addEventListener('change', () => { if (mode === 'system') apply(); });
    document.addEventListener('DOMContentLoaded', () => {
        const wrapper = document.createElement('div');
        wrapper.className = 'theme-control';
        const icons = {
            light: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>',
            dark: '<path d="M20.5 13A9 9 0 0 1 11 3.5 9 9 0 1 0 20.5 13Z"/>',
            system: '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M12 17v4m-4 0h8"/>'
        };
        wrapper.innerHTML = '<div class="theme-options" role="group" aria-label="Display theme">' + modes.map(value =>
            `<button type="button" data-theme-mode="${value}" aria-label="${value[0].toUpperCase() + value.slice(1)}" aria-pressed="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icons[value]}</svg><span>${value[0].toUpperCase() + value.slice(1)}</span></button>`
        ).join('') + '</div>';
        const desktop = document.querySelector('.global-topbar');
        const mobile = document.querySelector('.admin-mobile-topbar');
        const landingLogin = document.querySelector('.landing-nav__login');
        if (desktop) {
            const tools = document.createElement('div');
            tools.className = 'header-display-tools';
            const notification = desktop.querySelector('.global-notification-button');
            if (notification) tools.append(notification);
            tools.append(wrapper);
            desktop.append(tools);
            if (mobile) {
                const mobileTools = tools.cloneNode(true);
                const mobileControl = mobileTools.querySelector('.theme-control');
                const menu = document.createElement('details');
                menu.className = 'theme-control theme-mobile-menu';
                menu.innerHTML = '<summary class="theme-trigger" data-theme-current="system"></summary><div class="theme-menu-panel"><div class="theme-menu-title">Appearance</div></div>';
                menu.querySelector('.theme-menu-panel').append(mobileControl.querySelector('.theme-options'));
                menu.querySelector('[data-theme-mode="system"]>span').innerHTML = 'System<small>Follows your device</small>';
                mobileControl.replaceWith(menu);
                mobile.append(mobileTools);
                const search = desktop.querySelector('.global-search-trigger').cloneNode(true);
                search.classList.add('mobile-header-search');
                search.querySelector('kbd')?.remove();
                mobile.append(search);
            }
        } else if (landingLogin) {
            landingLogin.before(wrapper);
        } else {
            const bar = document.createElement('div');
            bar.className = 'theme-topbar';
            bar.setAttribute('aria-label', 'Display tools');
            bar.append(wrapper);
            document.body.prepend(bar);
        }
        document.querySelectorAll('.landing-login-modal__body').forEach(panel => panel.prepend(wrapper.cloneNode(true)));
        document.querySelectorAll('.user-modal-header>div, .user-drawer-identity>div, .checkout-dialog-header>div, .sale-receipt-dialog-header').forEach(panel => panel.append(wrapper.cloneNode(true)));
        const warning = document.createElement('div');
        warning.className = 'theme-save-alert';
        warning.setAttribute('role', 'alert');
        warning.hidden = true;
        (document.querySelector('.sale-receipt-dialog[open] .sale-receipt-dialog-header') || document.body).append(warning);
        let saving = Promise.resolve();
        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            document.querySelectorAll('.theme-mobile-menu[open]').forEach(menu => {
                menu.open = false;
                menu.querySelector('summary').focus();
            });
        });
        document.addEventListener('click', event => {
            document.querySelectorAll('.theme-mobile-menu[open]').forEach(menu => {
                if (!menu.contains(event.target)) menu.open = false;
            });
            const button = event.target.closest('[data-theme-mode]');
            if (!button) return;
            mode = button.dataset.themeMode;
            apply();
            const menu = button.closest('.theme-mobile-menu');
            if (menu) {
                menu.open = false;
                menu.querySelector('summary').focus();
            }
            if (!config.authenticated) {
                try { localStorage.setItem('retailmind.login-theme', mode); } catch (_) {}
                return;
            }
            const selected = mode;
            // Serialize writes: a slow earlier save must not overwrite the latest choice.
            saving = saving.then(async () => {
                try {
                    const response = await fetch(config.endpoint, {method: 'POST', credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
                        body: new URLSearchParams({mode: selected, csrf_token: config.csrf})});
                    const result = await response.json();
                    if (!response.ok || result.success !== true) throw new Error('unsaved');
                    if (selected === mode) warning.hidden = true;
                } catch (_) {
                    warning.textContent = 'Your display theme was not saved. Check your connection and choose your theme again.';
                    warning.hidden = false;
                }
            });
        });
        apply();
    });
})();
