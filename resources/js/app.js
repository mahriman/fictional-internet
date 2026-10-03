document.querySelectorAll('[data-generation-form]').forEach((form) => {
    form.addEventListener('submit', () => {
        const submitButton = form.querySelector('[data-generation-submit]');
        const statusMessage = form.querySelector('[data-generation-status]');

        if (!submitButton || submitButton.disabled) {
            return;
        }

        submitButton.disabled = true;
        submitButton.textContent = 'Generating…';

        if (statusMessage) {
            statusMessage.hidden = false;
        }
    });
});
