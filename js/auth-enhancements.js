document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.passwordToggle);
            if (!input) return;
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            const icon = button.querySelector('i');
            if (icon) icon.className = visible ? 'ph ph-eye-slash' : 'ph ph-eye';
            button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        });
    });
    document.querySelectorAll('[data-confirm-password]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const password = document.getElementById(form.dataset.confirmPassword);
            const confirm = document.getElementById(form.dataset.confirmField);
            if (password && confirm && password.value !== confirm.value) {
                event.preventDefault();
                confirm.setCustomValidity('Passwords do not match.');
                confirm.reportValidity();
            }
        });
        const confirm = document.getElementById(form.dataset.confirmField);
        if (confirm) confirm.addEventListener('input', () => confirm.setCustomValidity(''));
    });
    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            if (!button || !form.checkValidity()) return;
            button.classList.add('is-loading');
            button.setAttribute('aria-busy', 'true');
        });
    });
});
