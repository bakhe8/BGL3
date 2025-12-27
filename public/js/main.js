
// Toast Notification Helper
window.showToast = function (message, type = 'success') {
    // Create toast container if not exists
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.style.cssText = 'position: fixed; bottom: 20px; left: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;';
        document.body.appendChild(container);
    }

    // Create toast element
    const toast = document.createElement('div');
    const bgColor = type === 'success' ? '#10b981' : '#ef4444';
    toast.style.cssText = `
        background-color: ${bgColor};
        color: white;
        padding: 12px 24px;
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        font-family: sans-serif;
        font-size: 14px;
        opacity: 0;
        transition: opacity 0.3s ease-in-out;
        min-width: 200px;
    `;
    toast.textContent = message;

    container.appendChild(toast);

    // Animate in
    requestAnimationFrame(() => {
        toast.style.opacity = '1';
    });

    // Remove after 3 seconds
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 3000);
};

document.addEventListener('DOMContentLoaded', () => {
    const fileInput = document.getElementById('import-file-input');
    if (fileInput) {
        fileInput.addEventListener('change', async (e) => {
            if (e.target.files.length > 0) {
                const formData = new FormData();
                formData.append('file', e.target.files[0]);

                try {
                    const res = await fetch('/api/import.php', { method: 'POST', body: formData });
                    if (res.ok) {
                        const txt = await res.text();
                        try {
                            const json = JSON.parse(txt);
                            if (json.success || json.status === 'success') {
                                showToast('Import successful!', 'success');
                                setTimeout(() => window.location.reload(), 1000); // Wait for toast
                            } else {
                                showToast('Import failed: ' + (json.message || txt), 'error');
                            }
                        } catch (e) {
                            window.location.reload();
                        }
                    } else {
                        showToast('Upload failed: ' + res.status, 'error');
                    }
                } catch (e) {
                    showToast('Network error during upload', 'error');
                }
            }
        });
    }

});
