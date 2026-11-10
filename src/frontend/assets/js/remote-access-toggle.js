(() => {
    const toggle = document.getElementById('remote-access-toggle');
    if (!toggle) {
        return;
    }

    const statusText = document.getElementById('remote-toggle-status-text');
    const feedback = document.getElementById('remote-access-feedback');
    const publicUrl = document.getElementById('tunnel-public-url');
    const modePill = document.getElementById('remote-mode-pill');
    const modeLabel = document.getElementById('remote-mode-label');
    const modeIcon = document.getElementById('remote-mode-icon');
    const providerText = document.getElementById('remote-active-provider');
    const linkValue = document.getElementById('remote-link-copy-value');
    const copyLink = document.getElementById('remote-copy-link');
    const linkEmpty = document.getElementById('remote-link-empty');
    const linkNote = document.getElementById('remote-link-note');
    const configNode = document.getElementById('remote-access-toggle-config');
    const config = configNode ? JSON.parse(configNode.textContent || '{}') : {};
    const csrfToken = config.csrfToken || '';
    const apiUrl = config.apiUrl || '';
    const permanentMode = Boolean(config.permanent);
    let activeMode = config.installed === false ? 'none' : (config.mode || (permanentMode ? 'permanent' : 'quick'));
    let savedUrl = config.configuredUrl || '';

    const providerButtons = document.querySelectorAll('[data-remote-provider]');
    const providerPanels = document.querySelectorAll('[data-remote-provider-panel]');
    providerButtons.forEach(button => button.addEventListener('click', event => {
        event.preventDefault();
        const selected = button.getAttribute('data-remote-provider');
        providerButtons.forEach(item => item.setAttribute('aria-pressed', String(item === button)));
        providerPanels.forEach(panel => { panel.hidden = panel.getAttribute('data-remote-provider-panel') !== selected; });
        if (window.location.protocol === 'http:' || window.location.protocol === 'https:') {
            const url = new URL(window.location.href);
            url.searchParams.set('provider', selected);
            window.history.replaceState(null, '', url);
        }
    }));

    let pollTimer = null;
    let pollInFlight = false;
    let operation = 0;
    let confirmedState = config.state === 'online' || config.state === 'starting'
        ? config.state
        : 'offline';

    function confirmState(state) {
        confirmedState = state;
    }

    function setBadge(state) {
        if (providerText) {
            providerText.textContent = activeMode === 'none' ? 'Not set up' : (activeMode === 'ngrok' ? 'ngrok Free' : (activeMode === 'permanent' ? 'Cloudflare' : 'Cloudflare Quick Tunnel'));
        }
        if (!modePill) {
            return;
        }
        modePill.classList.remove('is-on', 'is-off', 'is-info');
        if (state === 'online') {
            modePill.classList.add('is-on');
            modeLabel.textContent = 'Online';
            modeIcon.className = 'bi bi-globe2';
        } else if (state === 'starting') {
            modePill.classList.add('is-info');
            modeLabel.textContent = 'Connecting';
            modeIcon.className = 'bi bi-arrow-repeat';
        } else {
            modePill.classList.add('is-off');
            modeLabel.textContent = 'Offline';
            modeIcon.className = 'bi bi-cloud-slash';
        }
    }

    function setState(state, url, configuredUrl) {
        savedUrl = configuredUrl || url || savedUrl;
        toggle.checked = state !== 'offline';
        toggle.setAttribute('aria-checked', state === 'offline' ? 'false' : 'true');
        if (statusText) {
            statusText.textContent = state === 'online'
                ? 'Active & encrypted'
                : (state === 'starting' ? 'Checking the public connection…' : 'Connection is stopped or unavailable');
        }
        setBadge(state);
        if (publicUrl) {
            publicUrl.textContent = savedUrl;
            publicUrl.href = savedUrl || '#';
            publicUrl.hidden = !savedUrl;
        }
        if (linkValue) linkValue.value = savedUrl;
        if (copyLink) copyLink.disabled = !savedUrl;
        if (linkEmpty) linkEmpty.hidden = Boolean(savedUrl);
        if (linkNote) linkNote.textContent = state === 'online'
            ? 'Open this link from another device and sign in to RetailMind.'
            : (savedUrl ? 'Saved link. Public access has not been verified.' : 'Use ngrok Free now, or Cloudflare with your own domain.');
    }

    function setFeedback(message, isError) {
        if (!feedback) {
            return;
        }
        feedback.textContent = message || '';
        feedback.className = 'remote-feedback' + (isError ? ' is-error' : (message ? ' is-success' : ''));
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function isCurrent(generation) {
        return generation === operation;
    }

    async function request(action) {
        const options = action
            ? {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({action: action}),
                credentials: 'same-origin'
            }
            : {credentials: 'same-origin'};
        const response = await fetch(apiUrl, options);
        return response.json();
    }

    function renderOnline(data) {
        activeMode = data.installed === false ? 'none' : (data.mode || activeMode);
        toggle.disabled = false;
        confirmState('online');
        setState('online', data.url, data.configured_url);
        setFeedback('');
    }

    function pollStatus(generation) {
        stopPolling();
        let attempts = 0;
        pollTimer = setInterval(async () => {
            if (!isCurrent(generation)) {
                stopPolling();
                return;
            }
            if (pollInFlight) {
                return;
            }
            pollInFlight = true;
            attempts += 1;
            if (attempts > 15) {
                stopPolling();
                pollInFlight = false;
                if (isCurrent(generation)) {
                    toggle.disabled = false;
                    setFeedback('Connecting is taking longer than expected. Check the tunnel log.', true);
                }
                return;
            }
            try {
                const data = await request(null);
                if (!isCurrent(generation)) {
                    return;
                }
                if (data && data.success && data.status === 'online' && data.url) {
                    stopPolling();
                    renderOnline(data);
                } else if (data && data.success && data.status === 'offline') {
                    stopPolling();
                    toggle.disabled = false;
                    confirmState('offline');
                    setState('offline');
                    setFeedback('The connection is not running. Enable it or run its setup script.', true);
                }
            } catch (error) {
                // Ignore transient network errors while polling.
            } finally {
                pollInFlight = false;
            }
        }, 1500);
    }

    async function reconcile(generation, errorMessage) {
        try {
            const data = await request(null);
            if (!isCurrent(generation)) {
                return;
            }
            toggle.disabled = false;
            if (!data || !data.success) {
                setState(confirmedState);
                setFeedback(errorMessage, true);
                return;
            }
            if (data.status === 'online' && data.url) {
                renderOnline(data);
            } else if (data.status === 'starting') {
                confirmState('starting');
                setState('starting');
                setFeedback('Connecting...');
                pollStatus(generation);
            } else {
                confirmState('offline');
                setState('offline');
                setFeedback(errorMessage, true);
            }
        } catch (error) {
            if (!isCurrent(generation)) {
                return;
            }
            toggle.disabled = false;
            setState(confirmedState);
            setFeedback(errorMessage, true);
        }
    }

    toggle.addEventListener('change', async () => {
        const shouldStart = toggle.checked;
        operation += 1;
        const generation = operation;
        stopPolling();
        toggle.disabled = true;

        if (shouldStart) {
            setState('starting');
        } else {
            toggle.setAttribute('aria-checked', 'false');
            if (statusText) {
                statusText.textContent = 'Stopping...';
            }
            setBadge('starting');
        }
        setFeedback(shouldStart ? 'Starting the connection…' : 'Stopping the connection…');

        try {
            const data = await request(shouldStart ? 'start' : 'stop');
            if (!isCurrent(generation)) {
                return;
            }
            if (!data || !data.success) {
                const message = data && data.error
                    ? data.error
                    : (shouldStart ? 'Failed to start tunnel daemon.' : 'Failed to stop tunnel daemon.');
                await reconcile(generation, message);
                return;
            }
            if (shouldStart) {
                if (data.status === 'online' && data.url) {
                    renderOnline(data);
                } else if (data.status === 'starting') {
                    confirmState('starting');
                    setState('starting');
                    pollStatus(generation);
                } else {
                    toggle.disabled = false;
                    confirmState('offline');
                    setState('offline');
                    setFeedback('Failed to start tunnel daemon.', true);
                }
            } else {
                toggle.disabled = false;
                const state = data.status || 'offline';
                confirmState(state);
                setState(state, data.url, data.configured_url);
                setFeedback(state === 'offline' ? 'Remote access tunnel stopped.' : 'The connection is still stopping. Its status will refresh.');
            }
        } catch (error) {
            if (!isCurrent(generation)) {
                return;
            }
            await reconcile(generation, shouldStart
                ? 'Network error while starting the remote access tunnel.'
                : 'Network error while stopping the remote access tunnel.');
        }
    });

    if (config.state === 'starting') {
        toggle.disabled = true;
        pollStatus(operation);
    }
    setInterval(async () => {
        if (toggle.disabled || pollInFlight || document.visibilityState === 'hidden') return;
        const generation = operation;
        pollInFlight = true;
        try {
            const data = await request(null);
            if (!isCurrent(generation) || !data || !data.success) return;
            activeMode = data.installed === false ? 'none' : (data.mode || activeMode);
            confirmState(data.status);
            setState(data.status, data.url, data.configured_url);
            setFeedback('');
        } catch (_) {
            if (isCurrent(generation)) {
                setState('starting');
                setFeedback('Connection status unavailable. Test the connection again.', true);
            }
        } finally { pollInFlight = false; }
    }, 30000);
})();
