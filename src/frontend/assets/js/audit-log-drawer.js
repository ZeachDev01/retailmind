function initAuditLogDrawer() {
    const drawer = document.getElementById('auditDetailsDrawer');
    const body = document.getElementById('auditDetailsBody');
    const meta = document.getElementById('auditDetailsMeta');
    if (!drawer || !body) return;

    const setOpen = (open) => {
        drawer.classList.toggle('open', open);
        drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (!open) {
            body.replaceChildren();
            if (meta) meta.textContent = '';
        }
    };

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('.audit-details-trigger');
        if (trigger) {
            const template = trigger.parentElement.querySelector('.audit-details-template');
            if (!template) return;
            body.replaceChildren(template.content.cloneNode(true));
            if (meta) meta.textContent = trigger.getAttribute('aria-label')?.replace(/^View details for /, '') || '';
            setOpen(true);
            return;
        }
        if (event.target === drawer || event.target.closest('[data-close-drawer]')) setOpen(false);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAuditLogDrawer);
} else {
    initAuditLogDrawer();
}
