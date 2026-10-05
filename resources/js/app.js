document.querySelectorAll('[data-generation-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const submitButton = form.querySelector('[data-generation-submit]');
        const statusMessage = form.querySelector('[data-generation-status]');

        if (!submitButton || form.dataset.submitting === 'true') {
            event.preventDefault();

            return;
        }

        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
        submitButton.disabled = true;
        submitButton.textContent = submitButton.dataset.progressLabel || 'Generating…';

        if (statusMessage) {
            statusMessage.hidden = false;
        }
    });
});
