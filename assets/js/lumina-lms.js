/**
 * ==============================================================================
 * ILMUNA LEARN — LMS INTERACTIVE ENGINE & UNIFIED ALERT/TOAST/MODAL SYSTEM
 * ==============================================================================
 * Menggantikan dialog bawaan browser (alert, confirm) dengan modal & toast
 * kustom bertema ultra-modern White Glassmorphism & palet warna khas Ilmuna:
 * Navy (#1D3B5E), Teal (#0D7A87), Sky Blue (#52B2DC), Amber/Gold (#F59E0B).
 * ==============================================================================
 */

// Global resolver for Promise-based confirmation modal
window._currentConfirmResolver = null;

/**
 * Tutup modal konfirmasi tindakan
 */
window.ilmunaCloseConfirm = function () {
    const modal = document.getElementById('ilmunaModalConfirm');
    if (modal) {
        modal.classList.remove('active');
    }
    if (window._currentConfirmResolver) {
        window._currentConfirmResolver(false);
        window._currentConfirmResolver = null;
    }
};

/**
 * Universal Promise-Based Confirmation Dialog (Pengganti window.confirm bawaan browser)
 * @param {Object|string} options - Opsi konfirmasi atau teks pesan
 * @returns {Promise<boolean>}
 */
window.ilmunaConfirm = function (options) {
    if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
        const opts = typeof options === 'string' ? { message: options } : (options || {});
        const type = (opts.type === 'danger' || opts.type === 'error' || opts.type === 'delete') ? 'error' : (opts.type || 'warning');
        return Ilmuna.confirm(
            type,
            opts.title || 'Konfirmasi Tindakan',
            opts.message || opts.text || 'Apakah Anda yakin ingin melanjutkan tindakan ini?',
            opts.confirmText || opts.confirmBtn || 'Ya, Lanjutkan',
            opts.cancelText || opts.cancelBtn || 'Batal'
        ).then(function (ok) {
            if (ok && typeof opts.onConfirm === 'function') opts.onConfirm();
            if (!ok && typeof opts.onCancel === 'function') opts.onCancel();
            return ok;
        });
    }
    return new Promise((resolve) => {
        const opts = typeof options === 'string' ? { message: options } : (options || {});
        let modal = document.getElementById('ilmunaModalConfirm');

        // Jika modal belum ada di DOM (fallback independen)
        if (!modal) {
            modal = document.createElement('div');
            modal.className = 'ilmuna-modal';
            modal.id = 'ilmunaModalConfirm';
            modal.innerHTML = `
                <div class="ilmuna-popup">
                    <div class="ilmuna-popup-icon" id="ilmunaConfirmIcon">
                        <i class="fas fa-trash-can"></i>
                    </div>
                    <h3 id="ilmunaConfirmTitle">Konfirmasi Tindakan</h3>
                    <p id="ilmunaConfirmMessage">Apakah Anda yakin ingin melanjutkan tindakan ini?</p>
                    <div class="ilmuna-popup-btns">
                        <button type="button" class="ilmuna-btn-cancel" id="ilmunaConfirmCancelBtn" onclick="ilmunaCloseConfirm()">Batal</button>
                        <button type="button" class="ilmuna-btn-confirm" id="ilmunaConfirmOkBtn">Ya, Lanjutkan</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        const titleEl   = modal.querySelector('#ilmunaConfirmTitle');
        const msgEl     = modal.querySelector('#ilmunaConfirmMessage');
        const iconEl    = modal.querySelector('#ilmunaConfirmIcon');
        const cancelBtn = modal.querySelector('#ilmunaConfirmCancelBtn');
        const okBtn     = modal.querySelector('#ilmunaConfirmOkBtn');

        const title       = opts.title || 'Konfirmasi Tindakan';
        const message     = opts.message || 'Apakah Anda yakin ingin melanjutkan tindakan ini?';
        const confirmText = opts.confirmText || opts.confirmBtn || 'Ya, Lanjutkan';
        const cancelText  = opts.cancelText || 'Batal';
        const type        = (opts.type || 'danger').toLowerCase();

        if (titleEl)   titleEl.textContent = title;
        if (msgEl)     msgEl.textContent   = message;
        if (cancelBtn) cancelBtn.textContent = cancelText;
        if (okBtn)     okBtn.textContent   = confirmText;

        // Sesuaikan visual ikon & tombol aksi sesuai severity
        if (iconEl) {
            if (type === 'danger' || type === 'error') {
                iconEl.innerHTML = '<i class="fas fa-trash-can"></i>';
                iconEl.style.background = 'rgba(239, 68, 68, 0.1)';
                iconEl.style.borderColor = 'rgba(239, 68, 68, 0.25)';
                iconEl.style.color = '#EF4444';
                if (okBtn) {
                    okBtn.style.background = 'linear-gradient(135deg, #DC2626 0%, #B91C1C 100%)';
                    okBtn.style.boxShadow = '0 4px 14px rgba(220, 38, 38, 0.35)';
                    okBtn.style.color = '#FFFFFF';
                }
            } else if (type === 'warning') {
                iconEl.innerHTML = '<i class="fas fa-triangle-exclamation"></i>';
                iconEl.style.background = 'rgba(245, 158, 11, 0.12)';
                iconEl.style.borderColor = 'rgba(245, 158, 11, 0.25)';
                iconEl.style.color = '#F59E0B';
                if (okBtn) {
                    okBtn.style.background = 'linear-gradient(135deg, #F59E0B 0%, #D97706 100%)';
                    okBtn.style.boxShadow = '0 4px 14px rgba(245, 158, 11, 0.35)';
                    okBtn.style.color = '#FFFFFF';
                }
            } else {
                iconEl.innerHTML = '<i class="fas fa-circle-question"></i>';
                iconEl.style.background = 'rgba(13, 122, 135, 0.12)';
                iconEl.style.borderColor = 'rgba(13, 122, 135, 0.25)';
                iconEl.style.color = '#0D7A87';
                if (okBtn) {
                    okBtn.style.background = 'linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%)';
                    okBtn.style.boxShadow = '0 4px 14px rgba(13, 122, 135, 0.35)';
                    okBtn.style.color = '#FFFFFF';
                }
            }
        }

        modal.classList.add('active');
        window._currentConfirmResolver = resolve;

        okBtn.onclick = function () {
            modal.classList.remove('active');
            if (typeof opts.onConfirm === 'function') opts.onConfirm();
            resolve(true);
            window._currentConfirmResolver = null;
        };

        if (cancelBtn) {
            cancelBtn.onclick = function () {
                window.ilmunaCloseConfirm();
            };
        }
    });
};

/**
 * Override window.alert bawaan browser agar menggunakan Toast bertema
 */
window.alert = function (message) {
    if (window.Ilmuna && typeof window.Ilmuna.alert === 'function') {
        return Ilmuna.alert('info', 'Pemberitahuan Sistem', String(message), 'Tutup');
    }
    if (typeof showToast === 'function') {
        showToast('warning', String(message), 'Pemberitahuan Sistem');
    } else {
        console.warn('System Alert:', message);
    }
};

/**
 * Helper global untuk memanggil alert / toast kustom
 */
window.ilmunaAlert = function (message, title = 'Pemberitahuan', type = 'info') {
    if (window.Ilmuna && typeof window.Ilmuna.alert === 'function') {
        let normType = (type === 'danger' || type === 'error') ? 'error' : (type || 'info');
        return Ilmuna.alert(normType, title, String(message), 'Tutup');
    }
    return showToast(type, message, title);
};

// =============================================================================
// DOM CONTENT LOADED EVENT LISTENERS & AUTO-UPGRADERS
// =============================================================================
document.addEventListener('DOMContentLoaded', function () {
    // 1. Mobile Sidebar Toggle
    const toggleBtn = document.querySelector('.topbar-toggle-btn');
    const sidebar = document.querySelector('.app-sidebar');

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            sidebar.classList.toggle('show');
        });

        document.addEventListener('click', function (e) {
            if (!sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
                sidebar.classList.remove('show');
            }
        });
    }

    // 2. Password Visibility Toggle
    const togglePassButtons = document.querySelectorAll('.toggle-password-btn');
    togglePassButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);
            if (input) {
                const icon = this.querySelector('i');
                if (input.type === 'password') {
                    input.type = 'text';
                    if (icon) {
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    }
                } else {
                    input.type = 'password';
                    if (icon) {
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                }
            }
        });
    });

    // 3. Auto-upgrade any legacy forms containing onsubmit="return confirm(...)"
    document.querySelectorAll('form[onsubmit*="confirm("]').forEach(form => {
        const attr = form.getAttribute('onsubmit') || '';
        const match = attr.match(/confirm\s*\(\s*(['"])(.*?)\1\s*\)/);
        if (match && match[2]) {
            form.removeAttribute('onsubmit');
            form.setAttribute('data-confirm', match[2]);
            if (!form.getAttribute('data-confirm-title')) {
                form.setAttribute('data-confirm-title', 'Konfirmasi Tindakan');
            }
            if (!form.getAttribute('data-confirm-type')) {
                form.setAttribute('data-confirm-type', 'danger');
            }
        }
    });

    // 3b. Auto-upgrade any legacy links/buttons containing onclick="return confirm(...)"
    document.querySelectorAll('a[onclick*="confirm("], button[onclick*="confirm("]').forEach(el => {
        const attr = el.getAttribute('onclick') || '';
        const match = attr.match(/confirm\s*\(\s*(['"])(.*?)\1\s*\)/);
        if (match && match[2]) {
            el.removeAttribute('onclick');
            el.setAttribute('data-confirm', match[2]);
            if (!el.getAttribute('data-confirm-title')) {
                el.setAttribute('data-confirm-title', 'Konfirmasi Tindakan');
            }
            if (!el.getAttribute('data-confirm-type')) {
                el.setAttribute('data-confirm-type', 'danger');
            }
        }
    });

    // 4. Delegated Form Confirmation Interceptor for [data-confirm]
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!form || !form.getAttribute) return;

        const confirmMsg = form.getAttribute('data-confirm') || form.getAttribute('data-confirm-message');
        if (confirmMsg && !form.dataset.confirmed) {
            e.preventDefault();

            const confirmTitle = form.getAttribute('data-confirm-title') || 'Konfirmasi Tindakan';
            const confirmBtn   = form.getAttribute('data-confirm-btn')   || 'Ya, Lanjutkan';
            const confirmType  = form.getAttribute('data-confirm-type')  || 'danger';

            window.ilmunaConfirm({
                title: confirmTitle,
                message: confirmMsg,
                confirmText: confirmBtn,
                type: confirmType
            }).then(confirmed => {
                if (confirmed) {
                    form.dataset.confirmed = 'true';
                    form.submit();
                }
            });
        }
    }, true);

    // 4b. Delegated Click Interceptor for [data-confirm] on Links and Buttons
    document.addEventListener('click', function (e) {
        const el = e.target.closest('[data-confirm], [data-confirm-message]');
        if (!el || el.tagName === 'FORM' || el.dataset.confirmed) return;

        const confirmMsg = el.getAttribute('data-confirm') || el.getAttribute('data-confirm-message');
        if (confirmMsg) {
            e.preventDefault();
            e.stopPropagation();

            const confirmTitle = el.getAttribute('data-confirm-title') || 'Konfirmasi Tindakan';
            const confirmBtn   = el.getAttribute('data-confirm-btn')   || 'Ya, Lanjutkan';
            const confirmType  = el.getAttribute('data-confirm-type')  || 'danger';

            window.ilmunaConfirm({
                title: confirmTitle,
                message: confirmMsg,
                confirmText: confirmBtn,
                type: confirmType
            }).then(confirmed => {
                if (confirmed) {
                    el.dataset.confirmed = 'true';
                    if (el.tagName === 'A' && el.href) {
                        window.location.href = el.href;
                    } else if (el.type === 'submit') {
                        el.click();
                    } else if (typeof el.click === 'function') {
                        el.click();
                    }
                }
            });
        }
    }, true);

    // 5. Close Modal on Backdrop Click
    document.addEventListener('click', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('ilmuna-modal')) {
            e.target.classList.remove('active');
            if (e.target.id === 'ilmunaModalConfirm' && window._currentConfirmResolver) {
                window._currentConfirmResolver(false);
                window._currentConfirmResolver = null;
            }
        }
    });

    // =========================================================================
    // UNIVERSAL ALERT ENHANCEMENT (STANDARDIZING ALL IN-PAGE ALERTS + TOAST TRIGGER)
    // =========================================================================
    function enhanceAlerts() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            if (alert.dataset.enhanced === 'true') return;
            alert.dataset.enhanced = 'true';

            // Determine type
            let type = 'info';
            let iconClass = 'fas fa-info';
            let defaultTitle = 'Informasi Sistem';

            if (alert.classList.contains('alert-success')) {
                type = 'success';
                iconClass = 'fas fa-check';
                defaultTitle = 'Berhasil';
            } else if (alert.classList.contains('alert-danger') || alert.classList.contains('alert-error')) {
                type = 'danger';
                iconClass = 'fas fa-exclamation';
                defaultTitle = 'Perhatian';
            } else if (alert.classList.contains('alert-warning')) {
                type = 'warning';
                iconClass = 'fas fa-triangle-exclamation';
                defaultTitle = 'Peringatan';
            }

            // Extract existing text and icon
            let rawContent = alert.innerHTML;
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = rawContent;
            const existingIcon = tempDiv.querySelector('i, iconify-icon');
            if (existingIcon) {
                existingIcon.remove();
            }
            let cleanText = tempDiv.innerHTML.trim();
            if (tempDiv.children.length === 1 && (tempDiv.children[0].tagName === 'DIV' || tempDiv.children[0].tagName === 'P')) {
                cleanText = tempDiv.children[0].innerHTML.trim();
            }

            // Reconstruct alert with unified layout
            alert.innerHTML = `
                <div class="alert-icon-box">
                    <i class="${iconClass}"></i>
                </div>
                <div class="alert-content">
                    <div class="alert-title">${defaultTitle}</div>
                    <div class="alert-message">${cleanText}</div>
                </div>
                <button type="button" class="alert-close-btn" aria-label="Tutup" title="Tutup">
                    <i class="fas fa-times"></i>
                </button>
            `;

            // Close button click handler
            const closeBtn = alert.querySelector('.alert-close-btn');
            if (closeBtn) {
                closeBtn.addEventListener('click', function () {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px) scale(0.96)';
                    setTimeout(() => alert.remove(), 250);
                });
            }

            // Auto-trigger unified floating toast so the user gets instant, dynamic feedback!
            if (cleanText && alert.getAttribute('data-no-toast') !== 'true') {
                showToast(type, cleanText, defaultTitle);
            }

            // If dismissible, handle auto-dismiss
            if (alert.classList.contains('alert-dismissible')) {
                let dismissTimer = setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px) scale(0.96)';
                    setTimeout(() => alert.remove(), 250);
                }, 5000);

                // Pause timer on hover
                alert.addEventListener('mouseenter', () => clearTimeout(dismissTimer));
                alert.addEventListener('mouseleave', () => {
                    dismissTimer = setTimeout(() => {
                        alert.style.opacity = '0';
                        alert.style.transform = 'translateY(-10px) scale(0.96)';
                        setTimeout(() => alert.remove(), 250);
                    }, 2500);
                });
            }
        });
    }

    enhanceAlerts();
});

// =============================================================================
// GLOBAL TOAST NOTIFICATION ENGINE (100% STANDARDIZED WITH ALERTS & BRAND THEME)
// =============================================================================
function showToast(type, message, title = null, duration = 4500) {
    if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
        let normType = (type === 'danger' || type === 'error' || type === 'err') ? 'error' : ((type === 'warn' || type === 'warning') ? 'warning' : (type === 'success' ? 'success' : 'info'));
        let defaultTitle = normType === 'error' ? 'Terjadi Kesalahan' : (normType === 'success' ? 'Berhasil' : (normType === 'warning' ? 'Peringatan' : 'Pemberitahuan'));
        return Ilmuna.toast(normType, title || defaultTitle, message, duration);
    }
    let container = document.getElementById('ilmunaToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'ilmunaToastContainer';
        container.className = 'ilmuna-toast-container';
        document.body.appendChild(container);
    }

    type = (type || 'info').toLowerCase();
    if (type === 'error') type = 'danger';

    let iconClass = 'fas fa-info';
    let defaultTitle = 'Pemberitahuan';

    if (type === 'success') {
        iconClass = 'fas fa-check';
        defaultTitle = 'Berhasil';
    } else if (type === 'danger') {
        iconClass = 'fas fa-exclamation';
        defaultTitle = 'Terjadi Kesalahan';
    } else if (type === 'warning') {
        iconClass = 'fas fa-triangle-exclamation';
        defaultTitle = 'Peringatan';
    }

    const toast = document.createElement('div');
    toast.className = `ilmuna-toast toast-${type} alert-${type}`;
    toast.innerHTML = `
        <div class="alert-icon-box">
            <i class="${iconClass}"></i>
        </div>
        <div class="alert-content">
            <div class="alert-title">${title || defaultTitle}</div>
            <div class="alert-message">${message}</div>
        </div>
        <button type="button" class="alert-close-btn" aria-label="Tutup" title="Tutup">
            <i class="fas fa-times"></i>
        </button>
        <div class="ilmuna-toast-progress">
            <div class="ilmuna-toast-progress-bar"></div>
        </div>
    `;

    container.appendChild(toast);

    const progressBar = toast.querySelector('.ilmuna-toast-progress-bar');
    if (progressBar) {
        progressBar.style.transition = `width ${duration}ms linear`;
        setTimeout(() => {
            progressBar.style.width = '0%';
        }, 20);
    }

    let hideTimeout;
    function startTimer() {
        hideTimeout = setTimeout(() => {
            dismissToast();
        }, duration);
    }

    function dismissToast() {
        toast.classList.add('toast-hide');
        setTimeout(() => {
            toast.remove();
        }, 300);
    }

    startTimer();

    // Pause timer on hover
    toast.addEventListener('mouseenter', () => {
        clearTimeout(hideTimeout);
        if (progressBar) progressBar.style.transition = 'none';
    });

    toast.addEventListener('mouseleave', () => {
        hideTimeout = setTimeout(() => dismissToast(), 1500);
    });

    // Close button dismiss
    const closeBtn = toast.querySelector('.alert-close-btn');
    if (closeBtn) {
        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            clearTimeout(hideTimeout);
            dismissToast();
        });
    }

    return toast;
}

// Global Aliases
window.ilmunaToast = showToast;
window.showToast = showToast;

// Modal Helper Functions
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
        setTimeout(() => modal.classList.add('show'), 10);
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('show');
        setTimeout(() => modal.style.display = 'none', 300);
    }
}
