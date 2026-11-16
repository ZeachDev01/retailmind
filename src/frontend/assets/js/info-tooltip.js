(() => {
    document.querySelectorAll('.info-tooltip-trigger').forEach(trigger => {
        const show = () => {
            trigger.parentElement.removeAttribute('data-dismissed');
            const box = trigger.parentElement.querySelector('.tooltip-box');
            const rect = trigger.getBoundingClientRect();
            let top = 8;
            document.querySelectorAll('.global-topbar,.admin-mobile-topbar').forEach(header => {
                const bounds = header.getBoundingClientRect();
                const position = getComputedStyle(header).position;
                if ((position === 'fixed' || position === 'sticky') && bounds.top <= 8 && bounds.bottom > 0) {
                    top = Math.max(top, bounds.bottom + 8);
                }
            });
            const above = Math.max(0, Math.min(rect.top, window.innerHeight - 4) - top - 4);
            const below = Math.max(0, window.innerHeight - Math.max(rect.bottom + 4, top) - 8);
            box.style.boxSizing = 'border-box';
            box.style.maxHeight = Math.max(above, below) + 'px';
            box.style.overflowY = 'auto';
            const placeBelow = box.offsetHeight <= below || below >= above;
            box.style.maxHeight = (placeBelow ? below : above) + 'px';
            box.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - box.offsetWidth - 8)) + 'px';
            box.style.top = Math.max(top, Math.min(window.innerHeight - box.offsetHeight - 8,
                placeBelow ? rect.bottom + 4 : rect.top - box.offsetHeight - 4)) + 'px';
        };
        trigger.addEventListener('keydown', event => {
            if (event.key === 'Escape') trigger.parentElement.setAttribute('data-dismissed', '');
        });
        window.addEventListener('scroll', () => {
            if (trigger.parentElement.hasAttribute('data-dismissed')) return;
            if (document.activeElement === trigger) show();
            else trigger.parentElement.setAttribute('data-dismissed', '');
        }, true);
        window.addEventListener('resize', () => {
            if (!trigger.parentElement.hasAttribute('data-dismissed') && document.activeElement === trigger) show();
        });
        trigger.addEventListener('focus', show);
        trigger.addEventListener('click', show);
        trigger.parentElement.addEventListener('mouseenter', show);
        trigger.parentElement?.addEventListener('mouseleave', () => trigger.parentElement.removeAttribute('data-dismissed'));
    });
})();
