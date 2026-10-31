(() => {
    'use strict';
    function initialize() {
        const workspace = document.getElementById('backup-recovery');
        if (!workspace || workspace.dataset.tabsReady) return;
        workspace.dataset.tabsReady = 'true';
        const tabs = [...workspace.querySelectorAll('[role="tab"]')];
        const panels = tabs.map(tab => document.getElementById(tab.getAttribute('aria-controls')));
        function selectTab(tab, focus = false) {
            tabs.forEach((item, index) => {
                const selected = item === tab;
                item.setAttribute('aria-selected', String(selected));
                item.tabIndex = selected ? 0 : -1;
                panels[index].hidden = !selected;
            });
            if (focus) tab.focus();
        }
        function selectCurrent() {
            const target = document.getElementById(location.hash.slice(1));
            const panel = panels.find(item => item === target || item.contains(target));
            selectTab(tabs.find(tab => tab.getAttribute('aria-controls') === (panel?.id || workspace.dataset.recoveryTab)) || tabs[0]);
        }
        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => selectTab(tab));
            tab.addEventListener('keydown', event => {
                let next;
                if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
                if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
                if (event.key === 'Home') next = 0;
                if (event.key === 'End') next = tabs.length - 1;
                if (next === undefined) return;
                event.preventDefault();
                selectTab(tabs[next], true);
            });
        });
        workspace.querySelector('[role="tablist"]').hidden = false;
        selectCurrent();
        window.addEventListener('hashchange', selectCurrent);
    }
    if (window.RetailMindNavigation) window.RetailMindNavigation.initializePage(initialize);
    else initialize();
})();
