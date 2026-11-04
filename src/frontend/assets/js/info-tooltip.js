(() => {
    document.querySelectorAll('.info-tooltip-trigger').forEach(trigger => {
        const show = () => {
            trigger.parentElement.removeAttribute('data-dismissed');
            const box = trigger.parentElement.querySelector('.tooltip-box');
            const rect = trigger.getBoundingClientRect();
            box.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - box.offsetWidth - 8)) + 'px';
            box.style.top = Math.max(8, rect.bottom + box.offsetHeight + 8 > window.innerHeight
                ? rect.top - box.offsetHeight - 8 : rect.bottom + 4) + 'px';
        };
        trigger.addEventListener('keydown', event => {
            if (event.key === 'Escape') trigger.parentElement.setAttribute('data-dismissed', '');
        });
        window.addEventListener('scroll', () => {
            if (document.activeElement === trigger) show();
            else trigger.parentElement.setAttribute('data-dismissed', '');
        }, true);
        trigger.addEventListener('focus', show);
        trigger.addEventListener('click', show);
        trigger.parentElement.addEventListener('mouseenter', show);
        trigger.parentElement?.addEventListener('mouseleave', () => trigger.parentElement.removeAttribute('data-dismissed'));
    });
})();
