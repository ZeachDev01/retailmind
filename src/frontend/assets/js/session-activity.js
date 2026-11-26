(() => {
    if (window.rmSessionPolicy) return;
    const element = document.getElementById('rm-session-policy');
    if (!element) return;
    window.rmSessionPolicy = true;
    const config = JSON.parse(element.textContent);
    let lastSent = 0;
    let timer;
    let ended = false;
    let prompting = false;
    async function confirmLogin(request) {
        if (prompting || !window.Swal || Swal.isVisible()) return;
        prompting = true;
        try {
            const result = await Swal.fire({
                title: 'Is this you?',
                text: 'Someone signed in with your account on another browser or device. Continue there? This device will be signed out.',
                icon: 'question', showCancelButton: true,
                confirmButtonText: 'Continue on other device', cancelButtonText: 'Reject login',
                allowOutsideClick: false, allowEscapeKey: false,
                preConfirm: async () => {
                    try {
                        const response = await fetch(config.transfer, {
                            method: 'POST', credentials: 'same-origin', cache: 'no-store',
                            body: new URLSearchParams({csrf_token: config.csrf, action: 'approve', request}),
                        });
                        if (!response.ok) throw new Error();
                        return true;
                    } catch (_) {
                        Swal.showValidationMessage('The login request expired or could not be approved. Try again.');
                        return false;
                    }
                },
            });
            if (result.isConfirmed) {
                ended = true;
                location.replace(config.login);
            } else {
                await fetch(config.transfer, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    body: new URLSearchParams({csrf_token: config.csrf, action: 'reject', request}),
                });
            }
        } catch (_) {} finally { prompting = false; }
    }
    async function check(activity = false) {
        if (ended) return;
        clearTimeout(timer);
        try {
            const response = await fetch(config.endpoint, {
                method: activity ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
                headers: activity ? {'Content-Type': 'application/x-www-form-urlencoded'} : {},
                body: activity ? new URLSearchParams({csrf_token: config.csrf}) : undefined,
            });
            if (response.status === 401) {
                ended = true;
                let status = {};
                try { status = await response.json(); } catch (_) {}
                const login = new URL(config.login, location.href);
                if (status.reason === 'session_replaced') login.searchParams.set('session', 'replaced');
                location.replace(login.href);
                return;
            }
            if (response.ok) {
                const status = await response.json();
                if (status.pendingLogin) void confirmLogin(status.pendingLogin);
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
