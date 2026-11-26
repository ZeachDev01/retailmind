(() => {
    const config = JSON.parse(document.getElementById('rm-cashier-login').textContent);
    const message = document.querySelector('.landing-login-form .error-msg');
    async function check() {
        try {
            const response = await fetch(config.endpoint, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                body: new URLSearchParams({csrf_token: config.csrf, action: 'status'}),
            });
            const status = await response.json();
            if (status.state === 'ready') {
                location.replace(status.redirect);
                return;
            }
            if (status.state === 'expired') {
                if (message) message.textContent = 'This login request expired or was rejected. Please log in again.';
                return;
            }
        } catch (_) {}
        setTimeout(check, 3000);
    }
    check();
})();
