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
        document.querySelectorAll('[data-theme-select]').forEach(select => { select.value = mode; });
        window.dispatchEvent(new Event('retailmind:themechange'));
    };
    apply();
    device.addEventListener('change', () => { if (mode === 'system') apply(); });
    document.addEventListener('DOMContentLoaded', () => {
        const wrapper = document.createElement('label');
        wrapper.className = 'theme-control';
        wrapper.innerHTML = '<span>Theme</span><select aria-label="Display theme" data-theme-select><option value="light">Light</option><option value="dark">Dark</option><option value="system">System</option></select>';
        const desktop = document.querySelector('.global-topbar');
        const mobile = document.querySelector('.admin-mobile-topbar');
        if (desktop) {
            desktop.append(wrapper);
            if (mobile) mobile.append(wrapper.cloneNode(true));
        } else {
            const bar = document.createElement('div');
            bar.className = 'theme-topbar';
            bar.setAttribute('aria-label', 'Display tools');
            bar.append(wrapper);
            document.body.prepend(bar);
        }
        document.querySelectorAll('.landing-login-modal__body').forEach(panel => panel.prepend(wrapper.cloneNode(true)));
        const warning = document.createElement('div');
        warning.className = 'theme-save-alert';
        warning.setAttribute('role', 'alert');
        warning.hidden = true;
        document.body.append(warning);
        let saving = Promise.resolve();
        document.querySelectorAll('[data-theme-select]').forEach(select => select.addEventListener('change', () => {
            mode = select.value;
            apply();
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
        }));
        apply();
    });
})();
