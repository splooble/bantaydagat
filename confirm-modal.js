function bdConfirm(message, options = {}) {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'bd-confirm-overlay';
        overlay.innerHTML = `
            <div class="bd-confirm-box">
                <p class="bd-confirm-message"></p>
                <div class="bd-confirm-actions">
                    <button type="button" class="bd-confirm-cancel">${options.cancelText || 'Cancel'}</button>
                    <button type="button" class="bd-confirm-ok">${options.confirmText || 'Confirm'}</button>
                </div>
            </div>
        `;
        // set message via textContent to avoid any HTML injection from dynamic messages
        overlay.querySelector('.bd-confirm-message').textContent = message;

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('bd-confirm-visible'));

        function cleanup(result) {
            overlay.classList.remove('bd-confirm-visible');
            setTimeout(() => overlay.remove(), 150);
            resolve(result);
        }

        overlay.querySelector('.bd-confirm-ok').addEventListener('click', () => cleanup(true));
        overlay.querySelector('.bd-confirm-cancel').addEventListener('click', () => cleanup(false));
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) cleanup(false);
        });
        document.addEventListener('keydown', function escHandler(e) {
            if (e.key === 'Escape') {
                cleanup(false);
                document.removeEventListener('keydown', escHandler);
            }
        });
    });
}

// Auto-wire any <form data-confirm="..."> to show the popup before submitting
function attachConfirmToForms(selector = 'form[data-confirm]') {
    document.querySelectorAll(selector).forEach((form) => {
        // avoid double-attaching if called more than once
        if (form.dataset.bdConfirmAttached) return;
        form.dataset.bdConfirmAttached = 'true';

        form.addEventListener('submit', async (e) => {
            if (form.dataset.bdConfirmed === 'true') {
                // already confirmed programmatically -- let it submit
                return;
            }
            e.preventDefault();
            const message = form.dataset.confirm || 'Are you sure you want to save this?';
            const confirmed = await bdConfirm(message);
            if (confirmed) {
                form.dataset.bdConfirmed = 'true';
                form.requestSubmit ? form.requestSubmit() : form.submit();
            }
        });
    });
}

// Inject the modal's styles once
(function injectStyles() {
    if (document.getElementById('bd-confirm-styles')) return;
    const style = document.createElement('style');
    style.id = 'bd-confirm-styles';
    style.textContent = `
        .bd-confirm-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.5);
            display: flex; align-items: center; justify-content: center;
            z-index: 9999; opacity: 0; transition: opacity 0.15s ease;
        }
        .bd-confirm-overlay.bd-confirm-visible { opacity: 1; }
        .bd-confirm-box {
            background: #fff; border-radius: 10px; padding: 1.5rem;
            max-width: 340px; width: 90%; box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            transform: scale(0.95); transition: transform 0.15s ease;
        }
        .bd-confirm-overlay.bd-confirm-visible .bd-confirm-box { transform: scale(1); }
        .bd-confirm-message { margin: 0 0 1.25rem; font-size: 15px; color: #1a2b22; line-height: 1.4; }
        .bd-confirm-actions { display: flex; gap: 8px; justify-content: flex-end; }
        .bd-confirm-actions button {
            border: none; border-radius: 6px; padding: 8px 16px;
            font-size: 14px; cursor: pointer; font-weight: 500;
        }
        .bd-confirm-cancel { background: #eee; color: #333; }
        .bd-confirm-ok { background: #0f6e56; color: #fff; }
    `;
    document.head.appendChild(style);
})();
