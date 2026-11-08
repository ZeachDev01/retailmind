(function () {
    'use strict';

    const notices = document.querySelectorAll('.forecast-notice');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    notices.forEach((notice) => {
        notice.setAttribute('role', 'status');
        notice.setAttribute('aria-live', 'polite');
        notice.title = 'Click to dismiss';
        const dismiss = () => {
            notice.classList.add('is-fading');
            window.setTimeout(() => {
                if (notice.isConnected) notice.remove();
            }, reducedMotion ? 0 : 250);
        };
        notice.addEventListener('click', dismiss);
        window.setTimeout(dismiss, 8000);
    });
})();
