(() => {
    const script = document.currentScript;
    Swal.fire({
        icon: 'warning',
        title: 'Signed out after another login',
        text: 'Your account was signed in on another browser or device. If this was not you, change your password immediately.',
        confirmButtonText: 'Back to login',
        showDenyButton: true,
        denyButtonText: 'Forgot password',
        allowOutsideClick: false,
        allowEscapeKey: false,
        buttonsStyling: false,
        customClass: {
            confirmButton: 'btn btn-primary rm-swal-confirm',
            denyButton: 'btn btn-quiet rm-swal-cancel',
        },
    }).then(result => {
        location.replace(result.isDenied ? script.dataset.recovery : script.dataset.login);
    });
})();
