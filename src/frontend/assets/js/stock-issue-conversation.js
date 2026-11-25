document.querySelectorAll('.stock-conversation').forEach(thread => {
    const dialog = thread.querySelector('.conversation-dialog');
    const trigger = thread.querySelector('.conversation-trigger');
    const markRead = async () => {
        const form = thread.querySelector('.conversation-read-form');
        if (!dialog.open || !form || form.dataset.saving || document.hidden) return;
        form.dataset.saving = '1';
        try {
            const response = await fetch(location.href, {method: 'POST', body: new FormData(form), headers: {Accept: 'application/json'}});
            if (!response.ok || !(await response.json()).read) throw new Error('Read update failed');
            thread.querySelector('.conversation-unread')?.remove();
            form.remove();
        } catch {
            delete form.dataset.saving;
        }
    };
    const closeOutside = event => {
        const bounds = dialog.getBoundingClientRect();
        if (event.target === dialog && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) dialog.close();
    };
    const open = () => {
        if (dialog.open) return;
        dialog.showModal();
        window.RetailMindUI?.syncScrollLock?.();
        dialog.querySelector('h2').focus();
        dialog.addEventListener('click', closeOutside);
        document.addEventListener('visibilitychange', markRead);
        markRead();
    };
    trigger.addEventListener('click', open);
    dialog.querySelector('.conversation-close').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        dialog.removeEventListener('click', closeOutside);
        document.removeEventListener('visibilitychange', markRead);
        window.RetailMindUI?.syncScrollLock?.();
        trigger.focus({preventScroll: true});
    });
    if (dialog.dataset.reopen === 'true') {
        open();
        const url = new URL(location.href);
        url.searchParams.delete('conversation');
        history.replaceState(null, '', url);
    }
});

