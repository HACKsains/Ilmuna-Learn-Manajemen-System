/**
 * =============================================================================
 * ILMUNA LEARN - PWA Helper (registrasi Service Worker + tombol Install)
 * =============================================================================
 * Dimuat otomatis lewat renderPwaHead() di config.php.
 *
 * Tombol install cukup diberi atribut  data-pwa-install  , contoh:
 *   <button type="button" data-pwa-install hidden>Install Aplikasi</button>
 *
 * Perilaku:
 *  - Disembunyikan jika aplikasi sudah terpasang / dibuka dari aplikasi (standalone).
 *  - Chrome/Edge/Android : memunculkan dialog install bawaan browser.
 *  - iPhone/iPad (Safari): menampilkan panduan "Bagikan -> Tambah ke Layar Utama".
 *  - Browser lain        : menampilkan panduan install lewat menu browser.
 *
 * API global: window.IlmunaPWA.install(), .isInstalled(), .canPrompt()
 * =============================================================================
 */
(function (window, document, navigator) {
    'use strict';

    var script = document.currentScript;
    var BASE = (script && script.getAttribute('data-base')) || '';
    BASE = BASE.replace(/\/+$/, '') + '/';

    var deferredPrompt = null;
    var ua = navigator.userAgent || '';
    var isIOS = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var isInAppBrowser = /FBAN|FBAV|Instagram|Line\/|WhatsApp|TikTok/i.test(ua);

    function isStandalone() {
        return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
            (window.matchMedia && window.matchMedia('(display-mode: minimal-ui)').matches) ||
            navigator.standalone === true;
    }

    // ---------------------------------------------------------------------
    // 1. Registrasi Service Worker
    // ---------------------------------------------------------------------
    var secure = window.isSecureContext || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    if ('serviceWorker' in navigator && secure) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(BASE + 'sw.js', { scope: BASE })
                .catch(function (err) { console.warn('[IlmunaPWA] Gagal mendaftarkan service worker:', err); });
        });
    }

    // ---------------------------------------------------------------------
    // 2. Tombol install
    // ---------------------------------------------------------------------
    function buttons() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-pwa-install]'));
    }

    function refreshButtons() {
        var show = !isStandalone();
        buttons().forEach(function (btn) {
            btn.hidden = !show;
            btn.classList.toggle('pwa-ready', !!deferredPrompt);
        });
    }

    function showGuide(title, html) {
        if (window.Ilmuna && typeof window.Ilmuna.alert === 'function') {
            window.Ilmuna.alert('info', title, html, 'Mengerti');
        } else {
            var tmp = document.createElement('div');
            tmp.innerHTML = html.replace(/<br\s*\/?>/gi, '\n');
            window.alert(title + '\n\n' + (tmp.textContent || tmp.innerText || ''));
        }
    }

    function install() {
        if (isStandalone()) {
            showGuide('Sudah Terpasang', 'Aplikasi Ilmuna Learn sudah terpasang di perangkat ini.');
            return Promise.resolve('installed');
        }

        if (deferredPrompt) {
            var promptEvent = deferredPrompt;
            deferredPrompt = null;
            promptEvent.prompt();
            return promptEvent.userChoice.then(function (choice) {
                refreshButtons();
                return choice && choice.outcome;
            });
        }

        if (isIOS) {
            showGuide('Pasang di iPhone / iPad',
                'Buka halaman ini di <b>Safari</b>, lalu:<br>' +
                '1. Ketuk tombol <b>Bagikan</b> (ikon kotak dengan panah ke atas).<br>' +
                '2. Pilih <b>Tambah ke Layar Utama</b>.<br>' +
                '3. Ketuk <b>Tambah</b>.');
        } else if (isInAppBrowser) {
            showGuide('Buka di Browser',
                'Aplikasi tidak bisa dipasang dari browser bawaan aplikasi ini.<br>' +
                'Ketuk menu <b>⋮</b> lalu pilih <b>Buka di Chrome / Browser</b>, kemudian tekan tombol install lagi.');
        } else if (!secure) {
            showGuide('Belum Bisa Dipasang',
                'Instalasi aplikasi hanya tersedia melalui koneksi aman (<b>HTTPS</b>).');
        } else {
            showGuide('Pasang Aplikasi',
                'Buka menu browser (<b>⋮</b> atau <b>…</b>), lalu pilih<br>' +
                '<b>Instal aplikasi</b> / <b>Tambahkan ke Layar Utama</b>.<br><br>' +
                'Jika menu tidak muncul, muat ulang halaman lalu coba lagi.');
        }
        return Promise.resolve('manual');
    }

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();          // tahan mini-infobar, tampilkan lewat tombol kita
        deferredPrompt = e;
        refreshButtons();
    });

    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        refreshButtons();
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            window.Ilmuna.toast('success', 'Berhasil Dipasang', 'Ilmuna Learn sudah ada di layar utama perangkat Anda.');
        }
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-pwa-install]');
        if (!btn) return;
        e.preventDefault();
        install();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refreshButtons);
    } else {
        refreshButtons();
    }

    window.IlmunaPWA = {
        install: install,
        isInstalled: isStandalone,
        canPrompt: function () { return !!deferredPrompt; }
    };
})(window, document, navigator);

