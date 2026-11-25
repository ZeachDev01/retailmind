(function () {
    const notificationList = document.querySelector('.notification-list[aria-label]');
    if (!notificationList) return;
    let swipe;
    let suppressClick = false;

    notificationList.addEventListener('pointerdown', event => {
        swipe = null;
        suppressClick = false;
        if (event.pointerType !== 'touch' || !event.isPrimary) return;
        const button = event.target.closest('[data-notification-dismissible]')?.querySelector('.notification-dismiss');
        if (button) swipe = {button, id: event.pointerId, x: event.clientX, y: event.clientY};
    });

    notificationList.addEventListener('pointercancel', () => { swipe = null; });
    notificationList.addEventListener('pointerup', event => {
        const start = swipe;
        swipe = null;
        if (!start || event.pointerId !== start.id) return;
        const distance = start.x - event.clientX;
        if (distance < 72 || distance < 1.5 * Math.abs(start.y - event.clientY)) return;
        suppressClick = true;
        start.button.form.requestSubmit(start.button);
    });

    notificationList.addEventListener('click', event => {
        if (suppressClick && event.pointerType === 'touch') event.preventDefault();
    }, true);
})();
