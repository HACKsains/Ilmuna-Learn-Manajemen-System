/**
 * =============================================================================
 * ILMUNA UI - Toast Notifications & Alert Modals (Nusantara Touch)
 * =============================================================================
 * Template alert global untuk seluruh website.
 *
 * Pemakaian:
 *   Ilmuna.toast('success', 'Sukses', 'Data berhasil diperbarui ke server!');
 *   Ilmuna.toast('error', 'Gagal', 'Koneksi terputus.', 0);          // 0 = tidak auto-close
 *
 *   Ilmuna.alert('info', 'Pembaruan', 'Versi terbaru tersedia.', 'Mengerti');
 *   Ilmuna.alert('error', 'Hapus Data?', 'Data tidak dapat dikembalikan.', 'Ya, Hapus!', true,
 *       function () { ... dijalankan saat dikonfirmasi ... },
 *       function () { ... dijalankan saat dibatalkan (opsional) ... });
 *
 *   // Versi Promise:
 *   Ilmuna.confirm('warning', 'Yakin?', 'Lanjutkan proses?', 'Ya').then(function (ok) { ... });
 *
 *   // Logout (BARU):
 *   Ilmuna.logout();                                    // → /logout.php (default)
 *   Ilmuna.logout({ url: '/auth/keluar.php' });         // → URL custom
 *   Ilmuna.logout({ redirect: false, onConfirm: fn });  // → callback saja
 *
 *   // Shortcut toast:
 *   Ilmuna.success('Judul', 'Pesan'); Ilmuna.info(...); Ilmuna.warning(...); Ilmuna.error(...);
 *
 * Tipe yang didukung: success | info | warning | error | logout (alias: danger -> error)
 *
 * Catatan: seluruh class CSS memakai prefix "ilm-ui-" agar tidak bentrok dengan
 * stylesheet lain di website (mis. lumina-lms.css yang juga memakai .ilmuna-toast / .ilmuna-modal).
 * =============================================================================
 */
(function (window, document) {
    'use strict';

    if (window.Ilmuna && window.Ilmuna.__ilmUi) return; // cegah double-load

    var STYLE_ID = 'ilmuna-ui-styles';
    var CONTAINER_ID = 'ilm-ui-toast-container';

    // Folder "assets/" dihitung otomatis dari lokasi file alert.js ini
    var ASSET_DIR = (function () {
        var s = document.currentScript && document.currentScript.src;
        return s ? s.replace(/[^\/]*$/, '').replace(/js\/$/, '') : '';
    })();

    // =========================================================================
    // GAMBAR GUNUNGAN (ornamen pojok kanan-bawah pada TOAST & ALERT MODAL)
    // =========================================================================
    var GUNUNGAN_DEFAULT = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 250'%3E%3Cg opacity='0.05' fill='none' stroke='%231D3B5E' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M100,10 C160,80 190,160 190,240 L10,240 C10,160 40,80 100,10 Z' stroke-width='6'/%3E%3Cpath d='M100,240 V150 C100,100 140,90 160,50 M100,180 C100,130 60,120 40,70' stroke-width='4'/%3E%3Cpath d='M100,220 Q160,220 160,170 T130,130 M100,220 Q40,220 40,170 T70,130' stroke-width='3'/%3E%3Cpath d='M100,180 Q130,180 130,150 T100,120 M100,180 Q70,180 70,150 T100,120' stroke-width='3'/%3E%3Ccircle cx='100' cy='200' r='10' stroke-width='4'/%3E%3Cpath d='M100,210 L110,220 L100,230 L90,220 Z' fill='%231D3B5E' stroke='none'/%3E%3C/g%3E%3C/svg%3E";

    // Pengaturan gunungan ALERT MODAL
    // (gunungan.png berwarna -> dibuat samar seperti watermark. Naikkan untuk lebih jelas, mis. 0.12)
    var GUNUNGAN_OPACITY = 0.08;
    var GUNUNGAN_WIDTH = 220;
    var GUNUNGAN_HEIGHT = 275;
    var GUNUNGAN_RIGHT = -80;
    var GUNUNGAN_BOTTOM = -35;

    // Pengaturan gunungan TOAST
    var TOAST_GUNUNGAN_OPACITY = 0.1;
    var TOAST_GUNUNGAN_WIDTH = 65;
    var TOAST_GUNUNGAN_HEIGHT = 80;
    var TOAST_GUNUNGAN_RIGHT = -10;
    var TOAST_GUNUNGAN_BOTTOM = -10;

    // =========================================================================
    // ORNAMEN BATIK SUDUT
    // =========================================================================

    // KIRI-ATAS untuk ALERT (besar)
    var ORNAMENT_TL = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 200'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round' opacity='0.14'%3E%3Cpath d='M0,60 Q60,60 60,0'/%3E%3Cpath d='M0,90 Q90,90 90,0'/%3E%3Cpath d='M0,120 Q120,120 120,0'/%3E%3Cpath d='M0,150 Q150,150 150,0'/%3E%3Cpath d='M0,180 Q180,180 180,0'/%3E%3Cpath d='M20,20 Q35,35 20,50'/%3E%3Cpath d='M20,20 Q35,5 50,20'/%3E%3Cpath d='M60,0 C72,15 62,30 48,24 C38,20 42,6 52,8 C58,10 58,18 52,20'/%3E%3Cpath d='M0,60 C15,72 30,62 24,48 C20,38 6,42 8,52 C10,58 18,58 20,52'/%3E%3Ccircle cx='30' cy='30' r='2' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='55' cy='55' r='1.8' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='80' cy='80' r='1.8' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='105' cy='105' r='2' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='130' cy='130' r='2' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='155' cy='155' r='2' fill='%231D3B5E' stroke='none'/%3E%3Cpath d='M100,10 L106,16 M106,10 L100,16'/%3E%3Cpath d='M10,100 L16,106 M16,100 L10,106'/%3E%3C/g%3E%3C/svg%3E";

    // KIRI-ATAS versi KECIL untuk TOAST
    var ORNAMENT_TL_TOAST = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 60 60'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='1.2' stroke-linecap='round' stroke-linejoin='round' opacity='0.15'%3E%3Cpath d='M0,20 Q20,20 20,0'/%3E%3Cpath d='M0,35 Q35,35 35,0'/%3E%3Cpath d='M0,50 Q50,50 50,0'/%3E%3Cpath d='M10,10 Q18,18 10,26'/%3E%3Cpath d='M10,10 Q18,2 26,10'/%3E%3Cpath d='M20,0 C26,8 20,16 14,12 C10,9 12,3 18,4'/%3E%3Ccircle cx='25' cy='25' r='1.2' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='40' cy='40' r='1.2' fill='%231D3B5E' stroke='none'/%3E%3C/g%3E%3C/svg%3E";

    // KANAN-ATAS untuk ALERT
    var ORNAMENT_TR = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 120 120'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='1.3' stroke-linecap='round' stroke-linejoin='round' opacity='0.12'%3E%3Cpath d='M120,40 Q80,40 80,0'/%3E%3Cpath d='M120,60 Q60,60 60,0'/%3E%3Cpath d='M120,80 Q40,80 40,0'/%3E%3Cpath d='M120,100 Q20,100 20,0'/%3E%3Cpath d='M80,0 C70,15 80,28 92,22 C100,18 96,8 88,10 C82,12 82,18 86,20'/%3E%3Cpath d='M120,40 C108,50 100,42 105,32 C108,26 116,28 114,35'/%3E%3Ccircle cx='60' cy='20' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='40' cy='40' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='20' cy='60' r='1.8' fill='%231D3B5E' stroke='none'/%3E%3Cpath d='M100,10 L105,15 M105,10 L100,15'/%3E%3C/g%3E%3C/svg%3E";

    // KIRI-BAWAH untuk ALERT
    var ORNAMENT_BL = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 120 120'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='1.3' stroke-linecap='round' stroke-linejoin='round' opacity='0.12'%3E%3Cpath d='M0,80 Q40,80 40,120'/%3E%3Cpath d='M0,60 Q60,60 60,120'/%3E%3Cpath d='M0,40 Q80,40 80,120'/%3E%3Cpath d='M0,20 Q100,20 100,120'/%3E%3Cpath d='M40,120 C50,105 40,92 28,98 C20,102 24,112 32,110 C38,108 38,102 34,100'/%3E%3Cpath d='M0,80 C12,70 20,78 15,88 C12,94 4,92 6,85'/%3E%3Ccircle cx='60' cy='100' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='80' cy='80' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='100' cy='60' r='1.8' fill='%231D3B5E' stroke='none'/%3E%3Cpath d='M10,100 L15,105 M15,100 L10,105'/%3E%3C/g%3E%3C/svg%3E";

    // DIVIDER BAWAH JUDUL
    var ORNAMENT_DIVIDER = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 120 20'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='1.2' stroke-linecap='round' opacity='0.3'%3E%3Cpath d='M0,10 L35,10'/%3E%3Cpath d='M85,10 L120,10'/%3E%3Cpath d='M60,3 L68,10 L60,17 L52,10 Z'/%3E%3Ccircle cx='45' cy='10' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='75' cy='10' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='60' cy='10' r='1.2' fill='%231D3B5E' stroke='none'/%3E%3C/g%3E%3C/svg%3E";

    // TEKSTUR LATAR KAWUNG
    var ORNAMENT_KAWUNG = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='0.5' opacity='0.03'%3E%3Cellipse cx='10' cy='10' rx='6' ry='6'/%3E%3Cellipse cx='30' cy='10' rx='6' ry='6'/%3E%3Cellipse cx='10' cy='30' rx='6' ry='6'/%3E%3Cellipse cx='30' cy='30' rx='6' ry='6'/%3E%3C/g%3E%3C/svg%3E";

    function applyGunungan(src) {
        document.documentElement.style.setProperty('--ilm-ui-gunungan', 'url("' + src + '")');
    }

    applyGunungan(GUNUNGAN_DEFAULT);

    var img = new Image();
    img.onload = function () {
        applyGunungan(img.src);
    };
    img.onerror = function () {
        if (img.src !== GUNUNGAN_DEFAULT) {
            console.warn('[Ilmuna] Gambar gunungan gagal dimuat, memakai bawaan:', img.src);
            img.src = GUNUNGAN_DEFAULT;
        }
    };
    img.src = ASSET_DIR + 'img/gunungan.png';

    var bgPattern = 'var(--ilm-ui-gunungan, url("' + GUNUNGAN_DEFAULT + '"))';

    function iconBg(hex) {
        return "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Cpath d='M45,15 C20,20 10,50 20,75 C30,95 70,95 85,75 C95,50 85,15 55,10 C25,5 5,40 15,70 C25,95 85,90 90,60 C95,35 60,5 35,20 C15,35 25,80 50,85 C75,90 90,60 80,35 C70,10 30,20 20,45' fill='none' stroke='%23" + hex + "' stroke-width='4' stroke-linecap='round' opacity='0.3'/%3E%3C/svg%3E\")";
    }

    // Definisi tema: [warna utama, warna hover tombol]
    var themes = {
        success: ['0D7A87', '0a626c'], // Teal
        info: ['52B2DC', '3d9bc4'],    // Sky Blue
        warning: ['F59E0B', 'D97706'], // Amber
        error: ['E63946', 'C1121F'],   // Crimson Red
        logout: ['DC2626', 'B91C1C']   // Slate — netral & tegas, tidak "menakutkan" seperti error
    };

    function buildThemeCss() {
        var css = '';
        Object.keys(themes).forEach(function (name) {
            var c = '#' + themes[name][0];
            var h = '#' + themes[name][1];
            var t = '.ilm-ui-theme-' + name;
            css +=
                t + ' .ilm-ui-toast-accent { background-color: ' + c + '; }\n' +
                t + ' .ilm-ui-toast-icon { stroke: ' + c + '; }\n' +
                t + ' .ilm-ui-modal-icon-wrapper { background-image: ' + iconBg(themes[name][0]) + '; background-size: cover; color: ' + c + '; }\n' +
                t + ' .ilm-ui-modal-icon-wrapper svg { stroke: ' + c + '; }\n' +
                t + ' .ilm-ui-btn-confirm { background: ' + c + '; color: #fff; }\n' +
                t + ' .ilm-ui-btn-confirm:hover { background: ' + h + '; }\n';
        });
        return css;
    }

    function injectStyles() {
        if (document.getElementById(STYLE_ID)) return;
        var style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = [
            '/* ================= TOAST CSS ================= */',
            '#' + CONTAINER_ID + ' {',
            '    position: fixed; top: 20px; right: 20px; z-index: 100100;',
            '    display: flex; flex-direction: column; gap: 15px;',
            '}',
            '.ilm-ui-toast {',
            '    position: relative; width: 360px; max-width: calc(100vw - 40px); box-sizing: border-box; padding: 16px 20px 16px 60px;',
            '    background-color: #ffffff;',
            '    background-image: url("' + ORNAMENT_KAWUNG + '");',
            '    background-size: 30px 30px; background-repeat: repeat;',
            '    border-radius: 32px 0 16px 0;',
            '    box-shadow: 0 10px 30px rgba(29, 59, 94, 0.15), inset 0 0 0 1px rgba(29, 59, 94, 0.06);',
            '    overflow: hidden;',
            '    animation: ilmUiSlideInRight 0.4s cubic-bezier(0.25, 0.8, 0.25, 1) forwards;',
            '    display: flex; align-items: center; justify-content: space-between;',
            "    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;",
            '    text-align: left;',
            '}',
            '.ilm-ui-toast.ilm-ui-fade-out { animation: ilmUiFadeOutRight 0.4s ease forwards; }',
            '@keyframes ilmUiSlideInRight { from { transform: translateX(120%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }',
            '@keyframes ilmUiFadeOutRight { from { transform: translateX(0); opacity: 1; } to { transform: translateX(120%); opacity: 0; } }',
            '',
            '.ilm-ui-toast::before {',
            "    content: '';",
            '    position: absolute;',
            '    top: 0; left: 0;',
            '    width: 55px; height: 55px;',
            '    background-image: url("' + ORNAMENT_TL_TOAST + '");',
            '    background-size: contain; background-position: top left; background-repeat: no-repeat;',
            '    z-index: 0; pointer-events: none;',
            '}',
            '.ilm-ui-toast::after {',
            "    content: '';",
            '    position: absolute;',
            '    right: ' + TOAST_GUNUNGAN_RIGHT + 'px; bottom: ' + TOAST_GUNUNGAN_BOTTOM + 'px;',
            '    width: ' + TOAST_GUNUNGAN_WIDTH + 'px; height: ' + TOAST_GUNUNGAN_HEIGHT + 'px;',
            '    background-image: ' + bgPattern + ';',
            '    background-size: contain; background-position: right bottom; background-repeat: no-repeat;',
            '    opacity: ' + TOAST_GUNUNGAN_OPACITY + ';',
            '    z-index: 0; pointer-events: none;',
            '}',
            '',
            '.ilm-ui-toast-accent { position: absolute; left: 0; top: 0; bottom: 0; width: 6px; }',
            '.ilm-ui-toast-content { position: relative; z-index: 1; flex-grow: 1; }',
            '.ilm-ui-toast-title { margin: 0 0 4px 0; font-size: 15px; font-weight: 700; color: #1D3B5E; }',
            '.ilm-ui-toast-message { margin: 0; font-size: 13px; color: #4a5568; line-height: 1.4; }',
            '.ilm-ui-toast-icon { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); width: 26px; height: 26px; z-index: 1; }',
            '.ilm-ui-toast-icon svg { width: 100%; height: 100%; display: block; }',
            '',
            '.ilm-ui-toast-close { background: none; border: none; cursor: pointer; padding: 5px; position: relative; z-index: 1; opacity: 0.5; transition: opacity 0.2s; display: flex; align-items: center; justify-content: center; }',
            '.ilm-ui-toast-close:hover { opacity: 1; }',
            '.ilm-ui-toast-close svg { width: 14px; height: 14px; fill: #1D3B5E; }',
            '',
            '/* ================= ALERT MODAL CSS ================= */',
            '.ilm-ui-modal-overlay {',
            '    position: fixed; top: 0; left: 0; right: 0; bottom: 0;',
            '    background: rgba(29, 59, 94, 0.6); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);',
            '    z-index: 100200; display: flex; align-items: center; justify-content: center;',
            "    animation: ilmUiFadeIn 0.3s ease; font-family: 'Segoe UI', Tahoma, sans-serif;",
            '}',
            '.ilm-ui-modal {',
            '    background-color: #fff;',
            '    background-image: url("' + ORNAMENT_KAWUNG + '");',
            '    background-size: 40px 40px; background-repeat: repeat;',
            '    border-radius: 100px 0 40px 0;',
            '    width: 420px; max-width: 90%; box-sizing: border-box;',
            '    padding: 30px 25px; text-align: center; position: relative; overflow: hidden;',
            '    box-shadow: 0 20px 40px rgba(0,0,0,0.2), inset 0 0 0 1px rgba(29, 59, 94, 0.08);',
            '    animation: ilmUiPopUp 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;',
            '}',
            '.ilm-ui-modal::before {',
            "    content: '';",
            '    position: absolute;',
            '    top: 0; left: 0;',
            '    width: 160px; height: 160px;',
            '    background-image: url("' + ORNAMENT_TL + '");',
            '    background-size: contain; background-position: top left; background-repeat: no-repeat;',
            '    z-index: 0; pointer-events: none;',
            '}',
            '.ilm-ui-modal::after {',
            "    content: '';",
            '    position: absolute;',
            '    top: 0; right: 0;',
            '    width: 100px; height: 100px;',
            '    background-image: url("' + ORNAMENT_TR + '");',
            '    background-size: contain; background-position: top right; background-repeat: no-repeat;',
            '    z-index: 0; pointer-events: none;',
            '}',
            '.ilm-ui-modal-ornament-bl {',
            '    position: absolute;',
            '    bottom: 0; left: 0;',
            '    width: 100px; height: 100px;',
            '    background-image: url("' + ORNAMENT_BL + '");',
            '    background-size: contain; background-position: bottom left; background-repeat: no-repeat;',
            '    z-index: 0; pointer-events: none;',
            '}',
            '.ilm-ui-modal-divider {',
            '    width: 100px; height: 16px;',
            '    margin: 0 auto 10px;',
            '    background-image: url("' + ORNAMENT_DIVIDER + '");',
            '    background-size: contain; background-position: center; background-repeat: no-repeat;',
            '    position: relative; z-index: 1;',
            '}',
            '.ilm-ui-modal-gunungan {',
            '    position: absolute; z-index: 0; pointer-events: none;',
            '    right: ' + GUNUNGAN_RIGHT + 'px; bottom: ' + GUNUNGAN_BOTTOM + 'px;',
            '    width: ' + GUNUNGAN_WIDTH + 'px; height: ' + GUNUNGAN_HEIGHT + 'px;',
            '    background-image: ' + bgPattern + ';',
            '    background-size: contain; background-position: right bottom; background-repeat: no-repeat;',
            '    opacity: var(--ilm-ui-gunungan-opacity, ' + GUNUNGAN_OPACITY + ');',
            '}',
            '@keyframes ilmUiFadeIn { from { opacity: 0; } to { opacity: 1; } }',
            '@keyframes ilmUiFadeOut { from { opacity: 1; } to { opacity: 0; } }',
            '@keyframes ilmUiPopUp { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }',
            '',
            '.ilm-ui-modal-icon-wrapper { width: 84px; height: 84px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; position: relative; z-index: 1; }',
            '.ilm-ui-modal-icon-wrapper svg { width: 36px; height: 36px; fill: none; stroke-width: 2; position: relative; z-index: 2; }',
            '.ilm-ui-modal-title { font-size: 20px; font-weight: 700; color: #1D3B5E; margin: 0 0 2px; position: relative; z-index: 1; }',
            '.ilm-ui-modal-message { font-size: 14px; color: #4a5568; line-height: 1.5; margin: 0 0 25px; position: relative; z-index: 1; }',
            '.ilm-ui-modal-buttons { display: flex; gap: 12px; justify-content: center; position: relative; z-index: 1; }',
            '.ilm-ui-btn-modal { padding: 10px 24px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: 0.2s; font-family: inherit; }',
            '.ilm-ui-btn-cancel { background: #f1f5f9; color: #1D3B5E; }',
            '.ilm-ui-btn-cancel:hover { background: #e2e8f0; }',
            '',
            '/* ================= TEMA WARNA ================= */',
            buildThemeCss()
        ].join('\n');
        (document.head || document.documentElement).appendChild(style);
    }

    // Ikon unik sesuai tema (logout = pintu + panah keluar)
    var icons = {
        success: '<svg fill="none" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
        info: '<svg fill="none" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
        warning: '<svg fill="none" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
        error: '<svg fill="none" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
        logout: '<svg fill="none" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>'
    };

    var closeIcon = '<svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z"/></svg>';

    function normalizeType(type) {
        type = String(type || 'info').toLowerCase();
        if (type === 'danger' || type === 'delete') type = 'error';
        return icons[type] ? type : 'info';
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function whenReady(fn) {
        if (document.body) return fn();
        document.addEventListener('DOMContentLoaded', fn, { once: true });
    }

    // ---------------------------------------------------------------------
    // TOAST
    // ---------------------------------------------------------------------
    function toast(type, title, message, duration) {
        if (duration === undefined || duration === null) duration = 5000;
        type = normalizeType(type);
        injectStyles();

        var toastEl = document.createElement('div');
        toastEl.className = 'ilm-ui-toast ilm-ui-theme-' + type;
        toastEl.setAttribute('role', type === 'error' || type === 'warning' ? 'alert' : 'status');
        toastEl.innerHTML =
            '<div class="ilm-ui-toast-accent"></div>' +
            '<div class="ilm-ui-toast-icon">' + icons[type] + '</div>' +
            '<div class="ilm-ui-toast-content">' +
            '<h4 class="ilm-ui-toast-title">' + (title || '') + '</h4>' +
            '<p class="ilm-ui-toast-message">' + (message || '') + '</p>' +
            '</div>' +
            '<button type="button" class="ilm-ui-toast-close" aria-label="Tutup">' + closeIcon + '</button>';

        var timer = null;
        var removed = false;

        function removeToast() {
            if (removed) return;
            removed = true;
            clearTimeout(timer);
            toastEl.classList.add('ilm-ui-fade-out');
            setTimeout(function () {
                if (toastEl.parentNode) toastEl.parentNode.removeChild(toastEl);
            }, 400);
        }

        function startTimer(ms) {
            clearTimeout(timer);
            if (ms > 0) timer = setTimeout(removeToast, ms);
        }

        toastEl.querySelector('.ilm-ui-toast-close').addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            removeToast();
        });

        if (duration > 0) {
            toastEl.addEventListener('mouseenter', function () { clearTimeout(timer); });
            toastEl.addEventListener('mouseleave', function () { startTimer(1500); });
        }

        whenReady(function () {
            var container = document.getElementById(CONTAINER_ID);
            if (!container) {
                container = document.createElement('div');
                container.id = CONTAINER_ID;
                document.body.appendChild(container);
            }
            container.appendChild(toastEl);
            startTimer(duration);
        });

        return { el: toastEl, close: removeToast };
    }

    // ---------------------------------------------------------------------
    // ALERT MODAL
    // ---------------------------------------------------------------------
    function alertModal(type, title, message, confirmText, showCancel, onConfirm, onCancel, cancelText) {
        if (type && typeof type === 'object') {
            var o = type;
            type = o.type; title = o.title; message = o.message;
            confirmText = o.confirmText; showCancel = o.showCancel;
            onConfirm = o.onConfirm; onCancel = o.onCancel; cancelText = o.cancelText;
        }

        type = normalizeType(type);
        confirmText = confirmText || 'OK';
        cancelText = cancelText || 'Batal';
        showCancel = !!showCancel;
        injectStyles();

        var previousFocus = document.activeElement;
        var overlay = document.createElement('div');
        overlay.className = 'ilm-ui-modal-overlay';
        overlay.innerHTML =
            '<div class="ilm-ui-modal ilm-ui-theme-' + type + '" role="dialog" aria-modal="true">' +
            '<div class="ilm-ui-modal-gunungan" aria-hidden="true"></div>' +
            '<div class="ilm-ui-modal-ornament-bl" aria-hidden="true"></div>' +
            '<div class="ilm-ui-modal-icon-wrapper">' + icons[type] + '</div>' +
            '<h3 class="ilm-ui-modal-title">' + (title || '') + '</h3>' +
            '<div class="ilm-ui-modal-divider" aria-hidden="true"></div>' +
            '<p class="ilm-ui-modal-message">' + (message || '') + '</p>' +
            '<div class="ilm-ui-modal-buttons">' +
            (showCancel ? '<button type="button" class="ilm-ui-btn-modal ilm-ui-btn-cancel">' + escapeHtml(cancelText) + '</button>' : '') +
            '<button type="button" class="ilm-ui-btn-modal ilm-ui-btn-confirm">' + escapeHtml(confirmText) + '</button>' +
            '</div>' +
            '</div>';

        var modal = overlay.querySelector('.ilm-ui-modal');
        var btnConfirm = overlay.querySelector('.ilm-ui-btn-confirm');
        var btnCancel = overlay.querySelector('.ilm-ui-btn-cancel');
        var closed = false;

        function closeModal() {
            if (closed) return false;
            closed = true;
            document.removeEventListener('keydown', onKey, true);
            overlay.style.animation = 'ilmUiFadeOut 0.3s ease forwards';
            modal.style.animation = 'ilmUiFadeOut 0.3s ease forwards';
            setTimeout(function () {
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                if (previousFocus && typeof previousFocus.focus === 'function') {
                    try { previousFocus.focus(); } catch (e) { /* abaikan */ }
                }
            }, 300);
            return true;
        }

        function doConfirm() {
            if (closeModal() && typeof onConfirm === 'function') onConfirm();
        }

        function doCancel() {
            if (closeModal() && typeof onCancel === 'function') onCancel();
        }

        function onKey(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                showCancel ? doCancel() : doConfirm();
            } else if (e.key === 'Enter' && document.activeElement !== btnCancel) {
                e.preventDefault();
                doConfirm();
            }
        }

        btnConfirm.addEventListener('click', doConfirm);
        if (btnCancel) btnCancel.addEventListener('click', doCancel);

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay && showCancel) doCancel();
        });

        whenReady(function () {
            document.body.appendChild(overlay);
            document.addEventListener('keydown', onKey, true);
            try { btnConfirm.focus({ preventScroll: true }); } catch (e) { btnConfirm.focus(); }
        });

        return { el: overlay, close: doCancel };
    }

    // Versi Promise dari alert dengan tombol batal
    function confirmModal(type, title, message, confirmText, cancelText) {
        return new Promise(function (resolve) {
            alertModal(type, title, message, confirmText || 'Ya, Lanjutkan', true,
                function () { resolve(true); },
                function () { resolve(false); },
                cancelText);
        });
    }

    // ---------------------------------------------------------------------
    // LOGOUT — Konfirmasi + redirect (opsional)
    // ---------------------------------------------------------------------
    // Pemakaian:
    //   Ilmuna.logout();                                    // default /logout.php
    //   Ilmuna.logout({ url: '/auth/keluar.php' });         // URL custom
    //   Ilmuna.logout({ redirect: false, onConfirm: fn });  // callback saja (mis. CSRF POST)
    //
    // Auto-bind HTML:
    //   <a href="/logout.php" data-ilm-logout>Keluar</a>
    //   <button data-ilm-logout="/logout.php">Keluar</button>
    function logout(opts) {
        opts = opts || {};

        var url = opts.url;
        if (!url) {
            var base = window.ILMUNA_BASE_URL || window.BASE_URL || '';
            url = base + '/logout.php';
        }

        var doRedirect = opts.redirect !== false;
        var title = opts.title || 'Keluar dari Akun?';
        var message = opts.message || 'Anda akan keluar dari sesi ini. Pastikan semua perubahan sudah tersimpan.';
        var confirmText = opts.confirmText || 'Ya, Keluar';
        var cancelText = opts.cancelText || 'Batal';
        var type = opts.type || 'logout';

        return confirmModal(type, title, message, confirmText, cancelText).then(function (ok) {
            if (ok) {
                if (typeof opts.onConfirm === 'function') {
                    try { opts.onConfirm(); } catch (e) { console.error('[Ilmuna.logout] onConfirm:', e); }
                }
                if (doRedirect && url) {
                    window.location.href = url;
                }
            } else if (typeof opts.onCancel === 'function') {
                try { opts.onCancel(); } catch (e) { console.error('[Ilmuna.logout] onCancel:', e); }
            }
            return ok;
        });
    }

    if (typeof document !== 'undefined') injectStyles();

    var Ilmuna = {
        __ilmUi: true,
        toast: toast,
        alert: alertModal,
        confirm: confirmModal,
        logout: logout,
        escape: escapeHtml,
        setGunungan: function (src, opacity) {
            if (src) img.src = src;
            if (opacity !== undefined && opacity !== null) {
                document.documentElement.style.setProperty('--ilm-ui-gunungan-opacity', String(opacity));
            }
        },
        success: function (title, message, duration) { return toast('success', title, message, duration); },
        info: function (title, message, duration) { return toast('info', title, message, duration); },
        warning: function (title, message, duration) { return toast('warning', title, message, duration); },
        error: function (title, message, duration) { return toast('error', title, message, duration); }
    };

    window.Ilmuna = Ilmuna;

    // ---------------------------------------------------------------------
    // AUTO-BIND: <a data-ilm-logout> / <button data-ilm-logout>
    // ---------------------------------------------------------------------
    whenReady(function () {
        document.addEventListener('click', function (e) {
            var el = e.target;
            // Naik ke atas sampai ketemu atribut data-ilm-logout
            while (el && el !== document.body) {
                if (el.hasAttribute && el.hasAttribute('data-ilm-logout')) {
                    e.preventDefault();
                    var explicit = el.getAttribute('data-ilm-logout');
                    var fallback = el.getAttribute('href');
                    Ilmuna.logout({
                        url: explicit && explicit !== '' ? explicit : fallback
                    });
                    return;
                }
                el = el.parentNode;
            }
        });
    });
})(window, document);