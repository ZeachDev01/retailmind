/**
 * remote-access-pill.js - Ticket #443
 * Global header remote access indicator and status synchronization
 */

(function () {
    'use strict';

    function initRemoteAccessPill() {
        const wrappers = document.querySelectorAll('[data-remote-access-wrapper]');
        if (!wrappers.length) return;

        // Resolve API endpoint from first wrapper's data attribute or default path
        const firstWrapper = wrappers[0];
        const apiUrl = firstWrapper.getAttribute('data-remote-api') || 'remote_access_api.php';

        function updateAllPills(status) {
            const isOnline = Boolean(status && status.online);
            const hasUrl = Boolean(status && status.url && status.url.trim());
            const statusText = isOnline ? (hasUrl ? 'Online' : 'Connecting') : 'Offline';
            const pillLabel = isOnline ? (hasUrl ? 'Remote Online' : 'Remote Connecting') : 'Remote Offline';
            const publicUrl = isOnline && hasUrl ? status.url.trim() : '';
            const descText = isOnline
                ? (hasUrl ? 'Public access is active through Cloudflare Tunnel.' : 'Tunnel is active. Resolving public URL...')
                : 'Remote access is currently offline.';

            wrappers.forEach(wrapper => {
                const trigger = wrapper.querySelector('[data-remote-pill-trigger]');
                const label = wrapper.querySelector('.remote-status-label');
                const badges = wrapper.querySelectorAll('[data-remote-badge]');
                const dots = wrapper.querySelectorAll('.remote-status-dot');
                const desc = wrapper.querySelector('[data-remote-desc]');
                const urlRow = wrapper.querySelector('[data-remote-url-row]');
                const urlInput = wrapper.querySelector('[data-remote-url-input]');

                if (trigger) {
                    trigger.classList.toggle('is-online', isOnline);
                    trigger.classList.toggle('is-offline', !isOnline);
                    trigger.setAttribute('aria-label', 'Remote Access: ' + statusText);
                    trigger.setAttribute('title', 'Remote Access: ' + statusText);
                }
                if (label) {
                    label.textContent = pillLabel;
                }
                badges.forEach(badge => {
                    badge.textContent = statusText;
                    badge.classList.toggle('badge-online', isOnline);
                    badge.classList.toggle('badge-offline', !isOnline);
                });
                dots.forEach(dot => {
                    dot.classList.toggle('is-online', isOnline);
                    dot.classList.toggle('is-offline', !isOnline);
                });
                if (desc) {
                    desc.textContent = descText;
                }
                if (urlRow) {
                    urlRow.classList.toggle('u-hidden', !isOnline);
                }
                if (urlInput) {
                    urlInput.value = publicUrl;
                    urlInput.placeholder = isOnline && !hasUrl ? 'Resolving URL...' : '';
                }
            });
        }

        async function fetchStatus() {
            try {
                const response = await fetch(apiUrl, {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                });
                if (response.ok) {
                    const data = await response.json();
                    if (data && data.success) {
                        updateAllPills(data);
                    }
                }
            } catch (_) {
                // Silently ignore network failures; existing status remains displayed
            }
        }

        let activePopover = null;

        function handleOutsideClick(e) {
            if (activePopover && !activePopover.contains(e.target)) {
                closeAllPopovers();
            }
        }

        function handleKeyDown(e) {
            if (e.key === 'Escape') {
                closeAllPopovers();
            }
        }

        function attachDismissListeners() {
            document.addEventListener('click', handleOutsideClick);
            document.addEventListener('keydown', handleKeyDown);
        }

        function detachDismissListeners() {
            document.removeEventListener('click', handleOutsideClick);
            document.removeEventListener('keydown', handleKeyDown);
        }

        function closeAllPopovers() {
            let hadOpen = false;
            wrappers.forEach(wrapper => {
                const popover = wrapper.querySelector('[data-remote-popover]');
                const trigger = wrapper.querySelector('[data-remote-pill-trigger]');
                if (popover && !popover.classList.contains('u-hidden')) {
                    popover.classList.add('u-hidden');
                    hadOpen = true;
                }
                if (trigger) trigger.setAttribute('aria-expanded', 'false');
            });
            activePopover = null;
            if (hadOpen) {
                detachDismissListeners();
            }
        }

        // Bind interactive triggers and copy actions
        wrappers.forEach(wrapper => {
            const trigger = wrapper.querySelector('[data-remote-pill-trigger]');
            const popover = wrapper.querySelector('[data-remote-popover]');
            const copyBtn = wrapper.querySelector('[data-remote-copy-btn]');
            const copyText = wrapper.querySelector('[data-remote-copy-text]');
            const feedback = wrapper.querySelector('[data-remote-feedback]');
            const urlInput = wrapper.querySelector('[data-remote-url-input]');

            if (wrapper.tagName && wrapper.tagName.toLowerCase() === 'details') {
                wrapper.addEventListener('toggle', function () {
                    if (trigger) trigger.setAttribute('aria-expanded', String(wrapper.open));
                    if (wrapper.open) {
                        fetchStatus();
                    }
                });
            } else if (trigger && popover) {
                trigger.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const wasOpen = !popover.classList.contains('u-hidden');
                    closeAllPopovers();
                    if (!wasOpen) {
                        popover.classList.remove('u-hidden');
                        trigger.setAttribute('aria-expanded', 'true');
                        activePopover = popover;
                        attachDismissListeners();
                        // Refresh status when opening popover
                        fetchStatus();
                    }
                });

                popover.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            }

            if (copyBtn && urlInput) {
                copyBtn.addEventListener('click', async function (e) {
                    e.stopPropagation();
                    const textToCopy = urlInput.value.trim();
                    if (!textToCopy) return;

                    let copied = false;
                    try {
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            await navigator.clipboard.writeText(textToCopy);
                            copied = true;
                        } else {
                            urlInput.select();
                            copied = document.execCommand('copy');
                        }
                    } catch (_) {
                        urlInput.select();
                        try {
                            copied = document.execCommand('copy');
                        } catch (err) {
                            copied = false;
                        }
                    }

                    if (copied) {
                        copyBtn.classList.add('btn-copied');
                        if (copyText) copyText.textContent = 'Copied!';
                        if (feedback) feedback.classList.remove('u-hidden');

                        setTimeout(() => {
                            copyBtn.classList.remove('btn-copied');
                            if (copyText) copyText.textContent = 'Copy';
                            if (feedback) feedback.classList.add('u-hidden');
                        }, 2000);
                    }
                });
            }
        });

        // Periodic background synchronization every 15 seconds
        setInterval(fetchStatus, 15000);

        // Fetch status when window regains focus
        window.addEventListener('focus', fetchStatus);

        // Expose helper on window for programmatic sync or external triggers
        window.RetailMindRemoteAccess = {
            sync: fetchStatus,
            update: updateAllPills
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRemoteAccessPill);
    } else {
        initRemoteAccessPill();
    }
})();
