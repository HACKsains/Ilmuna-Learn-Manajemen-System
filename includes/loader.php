<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PAGE LOADER COMPONENT
 * ==============================================================================
 * Komponen page transition loader.
 * Di-require oleh sidebar.php dan dapat di-require mandiri di mana pun dibutuhkan.
 *
 * Cara pakai:
 *   require_once BASE_PATH . '/includes/loader.php';
 *
 * Berisi:
 *   1. CSS loader (style#ilmuna-loader-styles)
 *   2. Script inline — kunci scroll dari first paint
 *   3. HTML overlay #ilmunaPageLoader
 *   4. Noscript fallback
 *   5. Script controller — hide loader saat halaman siap
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    exit;
}
?>
<style id="ilmuna-loader-styles">
/* ==========================================================================
 * PAGE LOADER — VISIBLE BY DEFAULT, hidden only when page is ready
 * (semua ukuran sudah di-SCALE UP untuk lebih besar & jelas)
 * ========================================================================== */
.ilmuna-page-loader {
    position: fixed;
    inset: 0;
    z-index: 999999;
    display: flex;
    align-items: center;
    justify-content: center;
    background:
        radial-gradient(circle at 20% 25%, rgba(13, 122, 135, 0.07) 0%, transparent 55%),
        radial-gradient(circle at 80% 75%, rgba(20, 184, 166, 0.07) 0%, transparent 55%),
        #FFFFFF;
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    opacity: 1;
    visibility: visible;
    transition: opacity 0.5s cubic-bezier(0.4, 0, 0.2, 1),
                visibility 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    will-change: opacity;
}
.ilmuna-page-loader.is-hidden {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
}

/* Progress bar — lebih tebal */
.ilmuna-loader-bar {
    position: absolute; top: 0; left: 0; right: 0;
    height: 5px; overflow: hidden;
    background: rgba(13, 122, 135, 0.06);
}
.ilmuna-loader-bar span {
    display: block; height: 100%; width: 40%;
    background: linear-gradient(90deg, transparent, #1D3B5E, #0D7A87, #52B2DC, #14B8A6, #10B981, #34D399, transparent);
    animation: ilmunaBarSlide 1.4s cubic-bezier(0.4, 0, 0.2, 1) infinite;
    border-radius: 5px;
    box-shadow: 0 0 16px rgba(13, 122, 135, 0.5);
}
@keyframes ilmunaBarSlide {
    0%   { transform: translateX(-110%); }
    100% { transform: translateX(310%); }
}

/* Center content */
.ilmuna-loader-inner {
    display: flex; flex-direction: column;
    align-items: center; text-align: center;
    padding: 0 24px;
}

/* Rings — 180×180 px, border 5px, gap lebih lebar */
.ilmuna-loader-rings {
    position: relative;
    width: 180px;
    height: 180px;
    display: flex; align-items: center; justify-content: center;
}
.ilmuna-ring {
    position: absolute; border-radius: 50%;
    border: 5px solid transparent;
}
.ilmuna-ring-a {
    inset: 0;
    border-top-color: #1D3B5E;
    border-right-color: rgba(29, 59, 94, 0.15);
    animation: ilmunaSpin 1.8s cubic-bezier(0.5, 0.2, 0.5, 0.8) infinite;
}
.ilmuna-ring-b {
    inset: 22px;
    border-bottom-color: #0D7A87;
    border-left-color: rgba(13, 122, 135, 0.15);
    animation: ilmunaSpin 1.4s cubic-bezier(0.5, 0.2, 0.5, 0.8) infinite reverse;
}
.ilmuna-ring-c {
    inset: 44px;
    border-top-color: #52B2DC;
    border-right-color: rgba(82, 178, 220, 0.15);
    animation: ilmunaSpin 1s cubic-bezier(0.5, 0.2, 0.5, 0.8) infinite;
}
@keyframes ilmunaSpin { to { transform: rotate(360deg); } }

/* Center logo — 90×90 px, pure transparent PNG, no box, no aura */
.ilmuna-loader-logo {
    position: relative;
    width: 90px;
    height: 90px;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1;
    animation: ilmunaPulse 1.8s ease-in-out infinite;
    /* ❌ NO background ❌ NO border-radius ❌ NO overflow ❌ NO box-shadow */
}
.ilmuna-loader-logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
    filter: drop-shadow(0 6px 18px rgba(13, 122, 135, 0.32));
}
@keyframes ilmunaPulse {
    0%, 100% { transform: scale(1); }
    50%      { transform: scale(1.08); }
}

/* Brand — 22 px */
.ilmuna-loader-brand {
    margin-top: 44px;
    font-size: 22px;
    font-weight: 800;
    letter-spacing: 0.2em;
    color: #0F172A;
    text-transform: uppercase;
    animation: ilmunaFadeUp 0.8s cubic-bezier(0.4, 0, 0.2, 1) 0.1s both;
}
.ilmuna-loader-brand em { font-style: normal; color: #0D7A87; }

/* Sub-text + dots — 15 px */
.ilmuna-loader-text {
    margin-top: 14px;
    font-size: 15px;
    font-weight: 500;
    color: #94A3B8;
    letter-spacing: 0.02em;
    animation: ilmunaFadeUp 0.8s cubic-bezier(0.4, 0, 0.2, 1) 0.2s both;
}
.ilmuna-loader-text .ilmuna-dots {
    display: inline-block;
    margin-left: 3px;
    font-size: 20px;
    line-height: 1;
    vertical-align: -2px;
}
.ilmuna-loader-text .ilmuna-dots i {
    display: inline-block; font-style: normal;
    animation: ilmunaDot 1.4s ease-in-out infinite;
}
.ilmuna-loader-text .ilmuna-dots i:nth-child(1) { animation-delay: 0s; }
.ilmuna-loader-text .ilmuna-dots i:nth-child(2) { animation-delay: 0.2s; }
.ilmuna-loader-text .ilmuna-dots i:nth-child(3) { animation-delay: 0.4s; }

@keyframes ilmunaFadeUp {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}
@keyframes ilmunaDot {
    0%, 60%, 100% { opacity: 0.25; transform: translateY(0); }
    30%           { opacity: 1;    transform: translateY(-3px); }
}

/* Scroll lock while loading */
html.ilmuna-is-loading,
html.ilmuna-is-loading body { overflow: hidden !important; }

/* Responsive — kecilkan di layar kecil agar tetap proporsional */
@media (max-width: 480px) {
    .ilmuna-loader-rings { width: 140px; height: 140px; }
    .ilmuna-ring { border-width: 4px; }
    .ilmuna-ring-b { inset: 17px; }
    .ilmuna-ring-c { inset: 34px; }
    .ilmuna-loader-logo { width: 70px; height: 70px; }
    .ilmuna-loader-brand { font-size: 17px; margin-top: 34px; }
    .ilmuna-loader-text { font-size: 13px; }
}

/* Reduced motion */
@media (prefers-reduced-motion: reduce) {
    .ilmuna-ring, .ilmuna-loader-logo,
    .ilmuna-loader-bar span, .ilmuna-loader-text .ilmuna-dots i {
        animation-duration: 3s !important;
    }
}
</style>

<!-- ======================================================================
     PAGE LOADER — sudah visible dari awal (lihat CSS), JS hanya hide.
     ====================================================================== -->
<script>
    /* Kunci scroll & tandai sedang loading dari first paint */
    try { document.documentElement.classList.add('ilmuna-is-loading'); } catch (e) {}
</script>

<noscript>
    <!-- Fallback kalau JS mati: jangan biarkan loader menggantung -->
    <style>#ilmunaPageLoader { display: none !important; }</style>
</noscript>

<div id="ilmunaPageLoader" class="ilmuna-page-loader" role="status" aria-live="polite" aria-label="Memuat halaman">
    <div class="ilmuna-loader-bar"><span></span></div>
    <div class="ilmuna-loader-inner">
        <div class="ilmuna-loader-rings">
            <span class="ilmuna-ring ilmuna-ring-a"></span>
            <span class="ilmuna-ring ilmuna-ring-b"></span>
            <span class="ilmuna-ring ilmuna-ring-c"></span>
            <span class="ilmuna-loader-logo">
                <img src="<?= BASE_URL ?>/assets/images/icon.png" alt="Ilmuna Learn" draggable="false">
            </span>
        </div>
        <div class="ilmuna-loader-brand">ILMUNA <em>LEARN</em></div>
        <div class="ilmuna-loader-text">
            Menyiapkan halaman<span class="ilmuna-dots"><i>.</i><i>.</i><i>.</i></span>
        </div>
    </div>
</div>

<!-- ======================================================================
 * PAGE LOADER CONTROLLER
 * Loader sudah VISIBLE dari first paint (CSS).
 * Script ini hanya HIDE saat halaman siap — 1x per navigasi.
 * ====================================================================== -->
<script>
(function () {
    'use strict';

    var loader = document.getElementById('ilmunaPageLoader');
    if (!loader) return;

    var MIN_SHOW_MS = 600;      // durasi minimal loader terlihat
    var MAX_WAIT_MS = 6000;     // safety: paksa hide kalau load macet
    var start  = Date.now();
    var hidden = false;

    function hideLoader() {
        if (hidden) return;
        hidden = true;

        var elapsed = Date.now() - start;
        var wait    = Math.max(0, MIN_SHOW_MS - elapsed);

        setTimeout(function () {
            loader.classList.add('is-hidden');
            document.documentElement.classList.remove('ilmuna-is-loading');
        }, wait);
    }

    /* ---------- 1. Sudah selesai load? hide sekarang ---------- */
    if (document.readyState === 'complete') {
        hideLoader();
    } else {
        window.addEventListener('load', hideLoader, { once: true });
        setTimeout(hideLoader, MAX_WAIT_MS);
    }

    /* ---------- 2. bfcache (tombol Back): page sudah siap → hide instan ---------- */
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            hidden = true;
            loader.classList.add('is-hidden');
            document.documentElement.classList.remove('ilmuna-is-loading');
        }
    });
})();
</script>
