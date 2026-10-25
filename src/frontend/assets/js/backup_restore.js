// Read-only filtering of the history already authorized and rendered by PHP,
// plus submit-pending protection for the read-only package validation form.
(function () {
    "use strict";

    const restoreForm = document.querySelector("[data-restore-form]");
    if (restoreForm) {
        restoreForm.addEventListener("submit", function (event) {
            if (event.defaultPrevented) return;
            const button = restoreForm.querySelector("[data-backup-restore]");
            if (!button || button.dataset.rmRestorePending === "true") return;
            button.dataset.rmRestorePending = "true";
            button.setAttribute("aria-busy", "true");
            button.disabled = true;
            button.textContent = "Verifying & restoring database…";
        });
    }

    const tools = document.querySelector("[data-history-tools]");
    if (!tools) return;
    const search = document.getElementById("history-search");
    const outcome = document.getElementById("history-outcome");
    const rows = Array.from(document.querySelectorAll("[data-history-row]"));
    const records = rows.map(row => {
        const clone = row.cloneNode(true);
        clone.querySelectorAll("summary").forEach(node => node.remove());
        return { row, text: clone.textContent.toLowerCase() };
    });
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
