// Service Worker Registration & Non-blocking Lifecycle Updates
(function() {
  'use strict';

  if (!('serviceWorker' in navigator)) {
    return;
  }

  var scriptEl = document.currentScript;
  var swUrl = (scriptEl && scriptEl.getAttribute('data-sw')) || '/sw.js';
  var swScope = (scriptEl && scriptEl.getAttribute('data-scope')) || '/';

  var currentWaitingWorker = null;
  var userRequestedReload = false;
  var refreshing = false;

  function ensureStyles() {
    if (document.getElementById('sw-update-styles')) {
      return;
    }
    var style = document.createElement('style');
    style.id = 'sw-update-styles';
    style.textContent = [
      '.sw-update-banner { position: fixed; bottom: 1.25rem; right: 1.25rem; z-index: 12000; max-width: min(28rem, calc(100vw - 2rem)); background: #1e293b; color: #ffffff; border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 10px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3); padding: 0.75rem 1rem; font-family: inherit; font-size: 0.9rem; line-height: 1.4; animation: sw-fade-in 0.25s ease-out; }',
      '@keyframes sw-fade-in { from { transform: translateY(0.75rem); opacity: 0; } to { transform: translateY(0); opacity: 1; } }',
      '.sw-update-banner__content { display: flex; align-items: center; gap: 0.75rem; }',
      '.sw-update-banner__text { flex: 1; font-weight: 500; cursor: pointer; }',
      '.sw-update-banner__reload { background: #2563eb; color: #ffffff; border: none; padding: 0.35rem 0.75rem; border-radius: 6px; font-size: 0.825rem; font-weight: 600; cursor: pointer; white-space: nowrap; }',
      '.sw-update-banner__reload:hover { background: #1d4ed8; }',
      '.sw-update-banner__close { background: transparent; color: rgba(255, 255, 255, 0.7); border: none; font-size: 1.25rem; line-height: 1; padding: 0 0.25rem; cursor: pointer; }',
      '.sw-update-banner__close:hover { color: #ffffff; }',
      '@media (max-width: 640px) { .sw-update-banner { left: 1rem; right: 1rem; bottom: 1rem; max-width: none; } }'
    ].join('\n');
    document.head.appendChild(style);
  }

  function triggerReload() {
    userRequestedReload = true;
    if (currentWaitingWorker) {
      currentWaitingWorker.postMessage({ type: 'SKIP_WAITING' });
    } else {
      window.location.reload();
    }
  }

  function showUpdateBanner(waitingWorker) {
    currentWaitingWorker = waitingWorker;
    if (document.getElementById('sw-update-banner')) {
      return;
    }
    ensureStyles();

    var banner = document.createElement('div');
    banner.id = 'sw-update-banner';
    banner.className = 'sw-update-banner';
    banner.setAttribute('role', 'status');
    banner.setAttribute('aria-live', 'polite');
    banner.innerHTML = [
      '<div class="sw-update-banner__content">',
      '  <span class="sw-update-banner__text" id="sw-update-text">Update available — Tap to reload</span>',
      '  <button type="button" class="sw-update-banner__reload" id="sw-update-reload-btn">Reload</button>',
      '  <button type="button" class="sw-update-banner__close" id="sw-update-dismiss-btn" aria-label="Dismiss">&times;</button>',
      '</div>'
    ].join('');

    banner.addEventListener('click', function(event) {
      if (event.target.closest('#sw-update-dismiss-btn')) {
        banner.remove();
        return;
      }
      triggerReload();
    });

    document.body.appendChild(banner);
  }

  navigator.serviceWorker.addEventListener('controllerchange', function() {
    if (userRequestedReload && !refreshing) {
      refreshing = true;
      window.location.reload();
    }
  });

  window.addEventListener('load', function() {
    navigator.serviceWorker.register(swUrl, { scope: swScope }).then(function(reg) {
      if (reg.waiting) {
        showUpdateBanner(reg.waiting);
        return;
      }

      function trackInstalling(worker) {
        if (!worker) return;
        worker.addEventListener('statechange', function() {
          if (worker.state === 'installed' && navigator.serviceWorker.controller) {
            showUpdateBanner(worker);
          }
        });
      }

      if (reg.installing) {
        trackInstalling(reg.installing);
      }

      reg.addEventListener('updatefound', function() {
        trackInstalling(reg.installing);
      });
    }).catch(function(err) {
      console.warn('RetailMind service worker registration failed:', err);
    });
  });
})();
