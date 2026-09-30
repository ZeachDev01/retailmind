// Read-only filtering of the history already authorized and rendered by PHP.
(function () {
    "use strict";
    const tools = document.querySelector("[data-history-tools]");
    if (!tools) return;
    const search = document.getElementById("history-search");
    const outcome = document.getElementById("history-outcome");
    const rows = Array.from(document.querySelectorAll("[data-history-row]"));
    const records = rows.map(row => ({ row, text: row.textContent.toLowerCase() }));
    const empty = document.querySelector("[data-history-empty]");
    const count = document.querySelector("[data-history-count]");
    function filter() {
        const query = search.value.trim().toLowerCase();
        let visible = 0;
        records.forEach(({ row, text }) => {
            const matches = text.includes(query) && (!outcome.value || row.dataset.outcome === outcome.value);
            row.hidden = !matches;
            if (matches) visible++;
        });
        empty.hidden = visible !== 0;
        count.textContent = visible + " of " + rows.length + " shown";
    }
    search.addEventListener("input", filter);
    outcome.addEventListener("change", filter);
    tools.hidden = false;
    filter();
})();
