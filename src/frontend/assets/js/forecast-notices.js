(function () {
    'use strict';

    const notices = document.querySelectorAll('.forecast-notice');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    notices.forEach((notice) => {
        window.setTimeout(() => {
            notice.classList.add('is-fading');
            window.setTimeout(() => {
                if (notice.isConnected) notice.remove();
            }, reducedMotion ? 0 : 250);
        }, 3000);
    });
})();
