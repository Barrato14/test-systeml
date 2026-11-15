(function () {
    const dialogs = document.querySelectorAll('[data-legal-dialog]');
    const openButtons = document.querySelectorAll('[data-legal-open]');
    let activeDialog = null;
    let lastFocused = null;

    function openDialog(type, button) {
        const dialog = document.querySelector('[data-legal-dialog="' + type + '"]');
        if (!dialog) return;
        lastFocused = button;
        activeDialog = dialog;
        dialog.hidden = false;
        document.body.classList.add('auth-legal-open');
        const closeButton = dialog.querySelector('.auth-legal-close');
        if (closeButton) closeButton.focus();
    }

    function closeDialog() {
        if (!activeDialog) return;
        activeDialog.hidden = true;
        activeDialog = null;
        document.body.classList.remove('auth-legal-open');
        if (lastFocused) lastFocused.focus();
    }

    openButtons.forEach(button => {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            openDialog(button.dataset.legalOpen, button);
        });
    });

    dialogs.forEach(dialog => {
        dialog.addEventListener('click', function (event) {
            if (event.target.closest('[data-legal-close]')) closeDialog();
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && activeDialog) closeDialog();
    });
})();