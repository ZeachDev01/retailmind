(() => {
    'use strict';

    const panel = document.querySelector('[data-live-flow]');
    if (!panel) {
        return;
    }

    const visual = panel.closest('.landing-hero__visual');
    const state = panel.querySelector('[data-flow-state]');
    const steps = Array.from(panel.querySelectorAll('[data-flow-step]'));
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');

    if (steps.length === 0) {
        return;
    }

    const CYCLE = 2400;
    let index = -1;
    let timer = 0;
    let running = false;
    let onScreen = false;

    function paint(next) {
        index = next;
        const step = steps[index];
        panel.dataset.stage = step.dataset.flowStep;
        if (visual) {
            visual.dataset.stage = step.dataset.flowStep;
        }
        if (state) {
            state.textContent = step.dataset.flowState || 'Ready';
        }
        steps.forEach((item, position) => {
            item.classList.toggle('is-active', position === index);
        });
    }

    function tick() {
        timer = window.setTimeout(() => {
            if (!running) {
                return;
            }
            paint((index + 1) % steps.length);
            tick();
        }, CYCLE);
    }

    function start() {
        if (running || reduce.matches || !onScreen || document.hidden || document.body.classList.contains('landing-modal-open')) {
            return;
        }
        running = true;
        paint(index < 0 ? 0 : index);
        tick();
    }

    function stop() {
        running = false;
        window.clearTimeout(timer);
    }

    if (reduce.matches) {
        return;
    }

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            onScreen = entry.isIntersecting;
            if (onScreen) {
                start();
            } else {
                stop();
            }
        });
    }, { threshold: 0.25 });
    observer.observe(panel);

    new MutationObserver(() => {
        if (document.body.classList.contains('landing-modal-open')) {
            stop();
        } else {
            start();
        }
    }).observe(document.body, { attributes: true, attributeFilter: ['class'] });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stop();
        } else {
            start();
        }
    });

    reduce.addEventListener('change', () => {
        if (reduce.matches) {
            stop();
        } else {
            start();
        }
    });
})();
