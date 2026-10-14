(() => {
    if (window.rmSessionPolicy) return;
    const element = document.getElementById('rm-session-policy');
    if (!element) return;
    window.rmSessionPolicy = true;
    const config = JSON.parse(element.textContent);
    let lastSent = 0;
    let timer;
    async function check(activity = false) {
        clearTimeout(timer);
        try {
            const response = await fetch(config.endpoint, {
                method: activity ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
                headers: activity ? {'Content-Type': 'application/x-www-form-urlencoded'} : {},
                body: activity ? new URLSearchParams({csrf_token: config.csrf}) : undefined,
            });
            if (response.status === 401) { location.replace(config.login); return; }
            if (response.ok) {
                const status = await response.json();
                timer = setTimeout(check, Math.min(30000, Math.max(1000, status.remaining * 1000)));
                return;
            }
        } catch (_) {}
        timer = setTimeout(check, 30000);
    }
    for (const name of ['keydown', 'pointerdown', 'input', 'wheel']) {
        document.addEventListener(name, event => {
            if (!event.isTrusted || Date.now() - lastSent < 15000) return;
            lastSent = Date.now();
            check(true);
        }, {passive: true});
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
    check();
})();
