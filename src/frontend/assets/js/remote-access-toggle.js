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
    const configNode = document.getElementById('remote-access-toggle-config');
    const config = configNode ? JSON.parse(configNode.textContent || '{}') : {};
    const csrfToken = config.csrfToken || '';
    const apiUrl = config.apiUrl || '';
    const permanentMode = Boolean(config.permanent);

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
        if (!modePill) {
            return;
        }
        modePill.classList.remove('is-on', 'is-off', 'is-info');
        if (state === 'online') {
            modePill.classList.add('is-on');
            modeLabel.textContent = permanentMode ? 'Custom domain' : 'Quick tunnel';
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

    function setState(state, url) {
        toggle.checked = state !== 'offline';
        toggle.setAttribute('aria-checked', state === 'offline' ? 'false' : 'true');
        if (statusText) {
            statusText.textContent = state === 'online'
                ? 'Active & encrypted'
                : (state === 'starting' ? 'Connecting...' : 'Disabled');
        }
        setBadge(state);
        if (publicUrl) {
            if (url) {
                publicUrl.textContent = url;
            } else if (state === 'offline') {
                publicUrl.textContent = 'Tunnel is offline';
            }
        }
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
        toggle.disabled = false;
        confirmState('online');
        setState('online', data.url);
        setFeedback('Remote access tunnel is active and ready.');
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
                } else if (data && data.success && !data.online) {
                    stopPolling();
                    toggle.disabled = false;
                    confirmState('offline');
                    setState('offline');
                    setFeedback('Tunnel terminated unexpectedly.', true);
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
        setFeedback(shouldStart ? 'Starting portable tunnel daemon...' : 'Stopping tunnel daemon...');

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
                confirmState('offline');
                setState('offline');
                setFeedback('Remote access tunnel stopped.');
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
})();
