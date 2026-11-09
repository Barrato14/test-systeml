document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('loginForm');
    const togglePasswordBtn = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const emailInput = document.getElementById('email');
    const submitBtn = document.getElementById('submitBtn');

    // 1. Password Visibility Toggle
    if (togglePasswordBtn && passwordInput) {
        togglePasswordBtn.addEventListener('click', () => {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Toggle Phosphor Icon
            const icon = togglePasswordBtn.querySelector('i');
            if (type === 'text') {
                icon.classList.remove('ph-eye');
                icon.classList.add('ph-eye-slash');
            } else {
                icon.classList.remove('ph-eye-slash');
                icon.classList.add('ph-eye');
            }
        });
    }

    // 2. Form Validation & Submission State
    if (loginForm) {
        loginForm.addEventListener('submit', (e) => {
            let isValid = true;
            
            // Reset error states
            const inputGroups = document.querySelectorAll('.input-group');
            inputGroups.forEach(group => group.classList.remove('invalid'));

            // Basic Email Validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailInput.value.trim() || !emailRegex.test(emailInput.value.trim())) {
                emailInput.closest('.input-group').classList.add('invalid');
                isValid = false;
            }

            // Basic Password Validation (just checking if empty)
            if (!passwordInput.value.trim()) {
                passwordInput.closest('.input-group').classList.add('invalid');
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault(); // Stop submission if invalid
            } else {
                // If valid, show loading state to prevent double submission
                submitBtn.classList.add('loading');
                submitBtn.setAttribute('disabled', 'true');
                
                // Allow the form to submit naturally to PHP
            }
        });

        // Clear error state on input
        [emailInput, passwordInput].forEach(input => {
            if(input) {
                input.addEventListener('input', (e) => {
                    e.target.closest('.input-group').classList.remove('invalid');
                });
            }
        });
    }
});
