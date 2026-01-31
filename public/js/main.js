
// Toast Notification Helper (Unified)
window.Toast = {
    show(message, type = 'info', duration = 3500) {
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.style.cssText = [
                'position: fixed',
                'top: 20px',
                'right: 20px',
                'z-index: 9999',
                'display: flex',
                'flex-direction: column',
                'gap: 10px',
                'direction: rtl'
            ].join(';');
            document.body.appendChild(container);
        }

        const colors = {
            success: '#10b981',
            error: '#ef4444',
            warning: '#f59e0b',
            info: '#3b82f6'
        };
        const icons = {
            success: 'OK',
            error: 'ERR',
            warning: 'WARN',
            info: 'INFO'
        };

        const toast = document.createElement('div');
        const borderColor = colors[type] || colors.info;

        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.style.cssText = [
            'background: #ffffff',
            'color: #111827',
            'padding: 12px 16px',
            'border-radius: 10px',
            'box-shadow: 0 10px 20px rgba(0,0,0,0.12)',
            `border-right: 4px solid ${borderColor}`,
            'min-width: 240px',
            'max-width: 420px',
            'font-family: inherit',
            'font-size: 14px',
            'display: flex',
            'align-items: center',
            'gap: 8px',
            'opacity: 0',
            'transform: translateY(-8px)',
            'transition: opacity 0.2s ease, transform 0.2s ease'
        ].join(';');

        const icon = icons[type] || icons.info;
        toast.textContent = `${icon}: ${message}`;

        while (container.children.length >= 4) {
            container.removeChild(container.firstChild);
        }

        container.appendChild(toast);

        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        });

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-8px)';
            setTimeout(() => toast.remove(), 200);
        }, duration);
    }
};

// ------------------------------------------------------------
// Lightweight Browser Runtime Sensor (Event Emitter)
// ------------------------------------------------------------
(() => {
    const ENDPOINT = '/api/agent-event.php';
    const SESSION_KEY = 'bgl_agent_session';

    const getSession = () => {
        const existing = localStorage.getItem(SESSION_KEY);
        if (existing) return existing;
        const sid = 'sess-' + Math.random().toString(36).slice(2) + Date.now();
        localStorage.setItem(SESSION_KEY, sid);
        return sid;
    };

    const session = getSession();

    const send = (payload) => {
        try {
            const body = JSON.stringify({
                timestamp: Date.now() / 1000,
                session,
                ...payload,
            });
            navigator.sendBeacon?.(ENDPOINT, body) ||
                fetch(ENDPOINT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body,
                    keepalive: true,
                }).catch(() => {});
        } catch (e) {
            // Swallow to avoid impacting UX
        }
    };

    // Navigation events
    const recordRoute = (route) =>
        send({ event_type: 'route', route, target: document.title || '' });
    window.addEventListener('popstate', () => recordRoute(location.pathname));
    document.addEventListener('click', (e) => {
        const a = e.target.closest?.('a[href]');
        if (a && a.href.startsWith(location.origin)) {
            recordRoute(new URL(a.href).pathname);
        }
    });

    // User interactions
    document.addEventListener('click', (e) => {
        const target = e.target;
        if (!target) return;
        const descriptor = [
            target.tagName,
            target.id ? `#${target.id}` : '',
            target.className ? `.${String(target.className).split(' ').join('.')}` : '',
        ].join('');
        send({
            event_type: 'ui_click',
            target: descriptor.slice(0, 200),
            route: location.pathname,
        });
    });

    document.addEventListener('input', (e) => {
        const target = e.target;
        if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement)) return;
        send({
            event_type: 'ui_input',
            target: target.name || target.id || target.type,
            route: location.pathname,
        });
    });

    // Fetch instrumentation
    if (window.fetch) {
        const originalFetch = window.fetch;
        window.fetch = async (...args) => {
            const started = performance.now();
            const req = args[0];
            let url = typeof req === 'string' ? req : (req?.url ?? '');
            let method = (typeof req === 'object' && req?.method) ? req.method : 'GET';
            try {
                const resp = await originalFetch(...args);
                send({
                    event_type: 'api_call',
                    target: url,
                    method,
                    status: resp.status,
                    latency_ms: Math.round(performance.now() - started),
                    route: location.pathname,
                });
                return resp;
            } catch (err) {
                send({
                    event_type: 'api_call',
                    target: url,
                    method,
                    status: 0,
                    error: String(err),
                    latency_ms: Math.round(performance.now() - started),
                    route: location.pathname,
                });
                throw err;
            }
        };
    }

    // XHR instrumentation (for legacy calls)
    if (window.XMLHttpRequest) {
        const origOpen = XMLHttpRequest.prototype.open;
        const origSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (method, url, async, user, password) {
            this._bgl_meta = { method, url };
            return origOpen.call(this, method, url, async, user, password);
        };
        XMLHttpRequest.prototype.send = function (body) {
            const started = performance.now();
            this.addEventListener('loadend', function () {
                const meta = this._bgl_meta || {};
                send({
                    event_type: 'api_call',
                    target: meta.url,
                    method: meta.method,
                    status: this.status,
                    latency_ms: Math.round(performance.now() - started),
                    route: location.pathname,
                });
            });
            return origSend.call(this, body);
        };
    }

    // JS errors
    window.addEventListener('error', (e) => {
        send({
            event_type: 'js_error',
            target: e.filename,
            error: e.message,
            route: location.pathname,
        });
    });
    window.addEventListener('unhandledrejection', (e) => {
        send({
            event_type: 'js_error',
            target: 'promise',
            error: String(e.reason),
            route: location.pathname,
        });
    });

    // Initial page load marker
    recordRoute(location.pathname);
})();
window.showToast = window.Toast.show;

// Debug logger (disabled by default)
window.BGL_DEBUG = window.BGL_DEBUG ?? false;
window.BglLogger = window.BglLogger || {
    debug: (...args) => { if (window.BGL_DEBUG) console.log(...args); },
    info: (...args) => { if (window.BGL_DEBUG) console.info(...args); },
    warn: (...args) => { if (window.BGL_DEBUG) console.warn(...args); },
    error: (...args) => { console.error(...args); }
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
