(() => {
    const initialize = () => {
        const section = document.getElementById('open-cashier-shifts');
        if (!section) return;
        let loading = false;
        async function refresh() {
            if (document.hidden || loading || !section.isConnected) return;
            loading = true;
            try {
                const response = await fetch(section.dataset.shiftStatusUrl, {credentials: 'same-origin', cache: 'no-store'});
                if (!response.ok) throw new Error('Unavailable');
                const result = await response.json();
                if (!section.isConnected || section.contains(document.activeElement)) return;
                section.innerHTML = result.html;
            } catch (_) {
                if (!section.isConnected) return;
                section.innerHTML = '<div class="section-header"><h2 id="open-shifts-heading">Open cashier shifts</h2></div><p role="status">Open shifts unavailable. Retrying automatically.</p>';
            } finally { loading = false; }
        }
        window.setInterval(refresh, 30000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    };
    if (window.RetailMindNavigation) window.RetailMindNavigation.initializePage(initialize);
    else initialize();
})();
