<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — UNIVERSAL POPUP MODAL TEMPLATE (NUSANTARA TOUCH)
 * ==============================================================================
 * Standar template popup Add / Edit / Form interaktif untuk seluruh website.
 * 
 * Karakteristik Desain:
 * 1. Seluruh 4 pucuk/sudut melingkar konsisten: border-radius: 28px (ultra-smooth).
 * 2. Ornamen Nusantara SAMA PERSIS DENGAN alert.js:
 *    - Gambar Gunungan Wayang (assets/img/gunungan.png) besar & tegas di kanan-bawah.
 *    - Tekstur latar belakang Batik Kawung geometris beropasitas elegan.
 *    - Ornamen Batik sudut Kiri-Atas (ORNAMENT_TL), Kanan-Atas (ORNAMENT_TR), & Kiri-Bawah (ORNAMENT_BL).
 *    - Garis pemisah judul bermotif Diamond Ketupat Batik (ORNAMENT_DIVIDER).
 * 3. Responsive di semua ukuran layar (Desktop, Tablet, & Smartphone 360px+).
 * 4. Animasi pop-in dan fade-out cubic-bezier berkecepatan mikrodetik.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    exit;
}

$baseUrl = defined('BASE_URL') ? BASE_URL : '';
$gununganPngUrl = $baseUrl . '/assets/img/gunungan.png';

// Data URI Ornamen Vektor Nusantara (SAMA PERSIS 100% DENGAN alert.js)
$ornamentGunungan = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 250'%3E%3Cg opacity='0.08' fill='none' stroke='%231D3B5E' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M100,10 C160,80 190,160 190,240 L10,240 C10,160 40,80 100,10 Z' stroke-width='6'/%3E%3Cpath d='M100,240 V150 C100,100 140,90 160,50 M100,180 C100,130 60,120 40,70' stroke-width='4'/%3E%3Cpath d='M100,220 Q160,220 160,170 T130,130 M100,220 Q40,220 40,170 T70,130' stroke-width='3'/%3E%3Cpath d='M100,180 Q130,180 130,150 T100,120 M100,180 Q70,180 70,150 T100,120' stroke-width='3'/%3E%3Ccircle cx='100' cy='200' r='10' stroke-width='4'/%3E%3Cpath d='M100,210 L110,220 L100,230 L90,220 Z' fill='%231D3B5E' stroke='none'/%3E%3C/g%3E%3C/svg%3E";

$ornamentKawung = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='0.6' opacity='0.05'%3E%3Cellipse cx='10' cy='10' rx='6' ry='6'/%3E%3Cellipse cx='30' cy='10' rx='6' ry='6'/%3E%3Cellipse cx='10' cy='30' rx='6' ry='6'/%3E%3Cellipse cx='30' cy='30' rx='6' ry='6'/%3E%3C/g%3E%3C/svg%3E";

// ORNAMENT_TL (Kiri-Atas Batik Klasik sama dengan alert.js)
$ornamentSudutTL = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 200'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round' opacity='0.20'%3E%3Cpath d='M0,60 Q60,60 60,0'/%3E%3Cpath d='M0,90 Q90,90 90,0'/%3E%3Cpath d='M0,120 Q120,120 120,0'/%3E%3Cpath d='M0,150 Q150,150 150,0'/%3E%3Cpath d='M0,180 Q180,180 180,0'/%3E%3Cpath d='M20,20 Q35,35 20,50'/%3E%3Cpath d='M20,20 Q35,5 50,20'/%3E%3Cpath d='M60,0 C72,15 62,30 48,24 C38,20 42,6 52,8 C58,10 58,18 52,20'/%3E%3Cpath d='M0,60 C15,72 30,62 24,48 C20,38 6,42 8,52 C10,58 18,58 20,52'/%3E%3Ccircle cx='30' cy='30' r='2' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='55' cy='55' r='1.8' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='80' cy='80' r='1.8' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='105' cy='105' r='2' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='130' cy='130' r='2' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='155' cy='155' r='2' fill='%231D3B5E' stroke='none'/%3E%3Cpath d='M100,10 L106,16 M106,10 L100,16'/%3E%3Cpath d='M10,100 L16,106 M16,100 L10,106'/%3E%3C/g%3E%3C/svg%3E";

// ORNAMENT_TR (Kanan-Atas Batik Klasik sama dengan alert.js)
$ornamentSudutTR = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 120 120'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='1.3' stroke-linecap='round' stroke-linejoin='round' opacity='0.16'%3E%3Cpath d='M120,40 Q80,40 80,0'/%3E%3Cpath d='M120,60 Q60,60 60,0'/%3E%3Cpath d='M120,80 Q40,80 40,0'/%3E%3Cpath d='M120,100 Q20,100 20,0'/%3E%3Cpath d='M80,0 C70,15 80,28 92,22 C100,18 96,8 88,10 C82,12 82,18 86,20'/%3E%3Cpath d='M120,40 C108,50 100,42 105,32 C108,26 116,28 114,35'/%3E%3Ccircle cx='60' cy='20' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='40' cy='40' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='20' cy='60' r='1.8' fill='%231D3B5E' stroke='none'/%3E%3Cpath d='M100,10 L105,15 M105,10 L100,15'/%3E%3C/g%3E%3C/svg%3E";

// ORNAMENT_BL (Kiri-Bawah Batik Klasik sama dengan alert.js)
$ornamentSudutBL = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 120 120'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='1.3' stroke-linecap='round' stroke-linejoin='round' opacity='0.16'%3E%3Cpath d='M0,80 Q40,80 40,120'/%3E%3Cpath d='M0,60 Q60,60 60,120'/%3E%3Cpath d='M0,40 Q80,40 80,120'/%3E%3Cpath d='M0,20 Q100,20 100,120'/%3E%3Cpath d='M40,120 C50,105 40,92 28,98 C20,102 24,112 32,110 C38,108 38,102 34,100'/%3E%3Cpath d='M0,80 C12,70 20,78 15,88 C12,94 4,92 6,85'/%3E%3Ccircle cx='60' cy='100' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='80' cy='80' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='100' cy='60' r='1.8' fill='%231D3B5E' stroke='none'/%3E%3Cpath d='M10,100 L15,105 M15,100 L10,105'/%3E%3C/g%3E%3C/svg%3E";

// ORNAMENT_DIVIDER (Garis Pembatas Bermotif Ketupat Batik sama dengan alert.js)
$ornamentDivider = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 120 20'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='1.2' stroke-linecap='round' opacity='0.35'%3E%3Cpath d='M0,10 L35,10'/%3E%3Cpath d='M85,10 L120,10'/%3E%3Cpath d='M60,3 L68,10 L60,17 L52,10 Z'/%3E%3Ccircle cx='45' cy='10' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='75' cy='10' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='60' cy='10' r='1.2' fill='%231D3B5E' stroke='none'/%3E%3C/g%3E%3C/svg%3E";
?>

<style id="ilmuna-universal-modal-styles">
/* ==============================================================================
 * ILMUNA UI — MODAL POPUP NUSANTARA (SELURUH SUDUT MELINGKAR 28px)
 * ============================================================================== */
.ilm-modal-backdrop {
    position: fixed !important;
    inset: 0 !important;
    background: rgba(15, 29, 47, 0.65) !important;
    backdrop-filter: blur(8px) !important;
    -webkit-backdrop-filter: blur(8px) !important;
    align-items: center !important;
    justify-content: center !important;
    z-index: 10500 !important;
    display: none !important;
    opacity: 0 !important;
    pointer-events: none !important;
    visibility: hidden !important;
    padding: 16px !important;
    box-sizing: border-box !important;
    transition: opacity 0.25s ease, visibility 0.25s !important;
}

.ilm-modal-backdrop.is-open,
.ilm-modal-backdrop.show {
    display: flex !important;
    opacity: 1 !important;
    pointer-events: auto !important;
    visibility: visible !important;
}

.ilm-modal-dialog {
    position: relative !important;
    width: 100% !important;
    max-width: 720px;
    background-color: #FFFFFF !important;
    background-image: url("<?= $ornamentKawung ?>") !important;
    background-size: 36px 36px !important;
    background-repeat: repeat !important;
    /* SELURUH PUCUK / 4 SUDUT AGAK LINGKARAN (MELINGKAR HALUS 28px) */
    border-radius: 28px !important;
    border: 1px solid rgba(226, 232, 240, 0.95) !important;
    box-shadow: 0 25px 65px -12px rgba(29, 59, 94, 0.28), 0 0 25px rgba(13, 122, 135, 0.12) !important;
    overflow: hidden !important;
    transform: scale(0.96) translateY(10px);
    transition: transform 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
    display: flex !important;
    flex-direction: column !important;
    max-height: calc(100vh - 40px) !important;
    box-sizing: border-box !important;
    text-align: left !important;
}

.ilm-modal-backdrop.is-open .ilm-modal-dialog,
.ilm-modal-backdrop.show .ilm-modal-dialog {
    transform: scale(1) translateY(0) !important;
}

/* HIASAN GUNUNGAN WAYANG DI SUDUT KANAN BAWAH MODAL (PERSIS SEPERTI ALERT.JS) */
.ilm-modal-gunungan {
    position: absolute !important;
    right: -25px !important;
    bottom: -15px !important;
    width: 310px !important;
    height: 380px !important;
    background-image: url("<?= $gununganPngUrl ?>"), url("<?= $ornamentGunungan ?>") !important;
    background-size: contain !important;
    background-position: right bottom !important;
    background-repeat: no-repeat !important;
    opacity: 0.18 !important;
    pointer-events: none !important;
    z-index: 1 !important;
}

/* HIASAN SUDUT KIRI ATAS BATIK (PERSIS SEPERTI ALERT.JS) */
.ilm-modal-ornament-tl {
    position: absolute !important;
    top: 0 !important;
    left: 0 !important;
    width: 160px !important;
    height: 160px !important;
    background-image: url("<?= $ornamentSudutTL ?>") !important;
    background-size: contain !important;
    background-position: top left !important;
    background-repeat: no-repeat !important;
    opacity: 0.22 !important;
    pointer-events: none !important;
    z-index: 1 !important;
}

/* HIASAN SUDUT KANAN ATAS BATIK (PERSIS SEPERTI ALERT.JS) */
.ilm-modal-ornament-tr {
    position: absolute !important;
    top: 0 !important;
    right: 0 !important;
    width: 120px !important;
    height: 120px !important;
    background-image: url("<?= $ornamentSudutTR ?>") !important;
    background-size: contain !important;
    background-position: top right !important;
    background-repeat: no-repeat !important;
    opacity: 0.18 !important;
    pointer-events: none !important;
    z-index: 1 !important;
}

/* HIASAN SUDUT KIRI BAWAH BATIK (PERSIS SEPERTI ALERT.JS) */
.ilm-modal-ornament-bl {
    position: absolute !important;
    bottom: 0 !important;
    left: 0 !important;
    width: 120px !important;
    height: 120px !important;
    background-image: url("<?= $ornamentSudutBL ?>") !important;
    background-size: contain !important;
    background-position: bottom left !important;
    background-repeat: no-repeat !important;
    opacity: 0.18 !important;
    pointer-events: none !important;
    z-index: 1 !important;
}

/* GARIS PEMBATAS BERMOTIF KETUPAT BATIK NUSANTARA */
.ilm-modal-divider {
    width: 100px !important;
    height: 16px !important;
    margin: 4px 0 2px !important;
    background-image: url("<?= $ornamentDivider ?>") !important;
    background-size: contain !important;
    background-position: left center !important;
    background-repeat: no-repeat !important;
    pointer-events: none !important;
    display: block !important;
}

/* MODAL HEADER */
.ilm-modal-header {
    position: relative !important;
    z-index: 2 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 20px 26px 18px !important;
    border-bottom: 1px solid rgba(241, 245, 249, 0.85) !important;
    background: rgba(255, 255, 255, 0.88) !important;
    backdrop-filter: blur(6px) !important;
    -webkit-backdrop-filter: blur(6px) !important;
}

.ilm-modal-header__brand {
    display: flex;
    align-items: center;
    gap: 14px;
}

.ilm-modal-header__icon {
    width: 46px;
    height: 46px;
    border-radius: 14px;
    background: linear-gradient(135deg, rgba(13, 122, 135, 0.12) 0%, rgba(82, 178, 220, 0.18) 100%);
    border: 1px solid rgba(13, 122, 135, 0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--lumina-teal, #0D7A87);
    font-size: 24px;
    flex-shrink: 0;
}

.ilm-modal-header__title {
    margin: 0;
    font-family: 'Outfit', sans-serif;
    font-size: 18px;
    font-weight: 800;
    color: var(--lumina-navy, #1D3B5E);
    line-height: 1.25;
}

.ilm-modal-header__subtitle {
    margin: 2px 0 0;
    font-size: 12px;
    color: #64748B;
    font-weight: 500;
}

.ilm-modal-header__close {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #F1F5F9;
    border: 1px solid #E2E8F0;
    color: #64748B;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 18px;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.ilm-modal-header__close:hover {
    background: #FEE2E2;
    border-color: #FCA5A5;
    color: #DC2626;
    transform: rotate(90deg);
}

/* MODAL BODY */
.ilm-modal-body {
    position: relative !important;
    z-index: 2 !important;
    padding: 22px 26px !important;
    overflow-y: auto !important;
    flex: 1 1 auto !important;
    font-family: 'Plus Jakarta Sans', sans-serif !important;
    color: #334155 !important;
    font-size: 13.5px !important;
    line-height: 1.5 !important;
    background: transparent !important;
}

/* MODAL FOOTER */
.ilm-modal-footer {
    position: relative !important;
    z-index: 2 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 12px !important;
    padding: 16px 26px 20px !important;
    border-top: 1px solid rgba(241, 245, 249, 0.85) !important;
    background: rgba(255, 255, 255, 0.88) !important;
    backdrop-filter: blur(6px) !important;
    -webkit-backdrop-filter: blur(6px) !important;
    flex-shrink: 0 !important;
}

/* TOMBOL MODAL */
.ilm-modal-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    height: 42px;
    padding: 0 20px;
    border-radius: 12px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
    text-decoration: none;
}

.ilm-modal-btn--ghost {
    background: #F1F5F9;
    color: #475569;
    border: 1px solid #CBD5E1;
}

.ilm-modal-btn--ghost:hover {
    background: #E2E8F0;
    color: #0F172A;
}

.ilm-modal-btn--primary {
    background: linear-gradient(135deg, #0D7A87 0%, #0a626c 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(13, 122, 135, 0.28);
}

.ilm-modal-btn--primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(13, 122, 135, 0.38);
}

.ilm-modal-btn--gold {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(245, 158, 11, 0.28);
}

.ilm-modal-btn--gold:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(245, 158, 11, 0.38);
}

/* ==============================================================================
 * UNIVERSAL FORM CONTROLS — DROPDOWN, INPUT, & TEXTAREA ULTRA ESTETIK
 * ============================================================================== */
.ilm-form-group,
.tgs-form-group {
    margin-bottom: 16px;
    position: relative;
}

.ilm-form-label,
.tgs-form-label {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    color: var(--lumina-navy, #1D3B5E) !important;
    margin-bottom: 7px !important;
    letter-spacing: 0.2px !important;
}

/* INPUT TEXT, NUMBER, URL, DATE */
.ilm-form-control,
.tgs-form-control {
    width: 100% !important;
    box-sizing: border-box !important;
    padding: 11px 16px !important;
    border-radius: 13px !important;
    border: 1.5px solid rgba(203, 213, 225, 0.9) !important;
    font-family: 'Plus Jakarta Sans', sans-serif !important;
    font-size: 13.5px !important;
    font-weight: 500 !important;
    color: #1E293B !important;
    background: rgba(255, 255, 255, 0.95) !important;
    outline: none !important;
    transition: all 0.22s cubic-bezier(0.25, 0.8, 0.25, 1) !important;
    box-shadow: 0 2px 4px rgba(29, 59, 94, 0.02) !important;
}

.ilm-form-control:hover,
.tgs-form-control:hover {
    border-color: #94A3B8 !important;
    background: #FFFFFF !important;
}

.ilm-form-control:focus,
.tgs-form-control:focus {
    border-color: var(--lumina-teal, #0D7A87) !important;
    background: #FFFFFF !important;
    box-shadow: 0 0 0 4px rgba(13, 122, 135, 0.16) !important;
}

/* SELECT / DROPDOWN MEWAH DENGAN CHEVRON CUSTOM */
select.ilm-form-control,
select.tgs-form-control,
.ilm-form-select {
    appearance: none !important;
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%230D7A87' d='m12 15l-5-5h10z'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 14px center !important;
    background-size: 18px !important;
    padding-right: 42px !important;
    cursor: pointer !important;
    font-weight: 600 !important;
}

select.ilm-form-control:disabled,
select.tgs-form-control:disabled {
    background-color: #F1F5F9 !important;
    opacity: 0.7 !important;
    cursor: not-allowed !important;
    border-color: #E2E8F0 !important;
}

textarea.ilm-form-control,
textarea.tgs-form-control {
    resize: vertical !important;
    min-height: 90px !important;
    line-height: 1.5 !important;
}

/* ==============================================================================
 * CLOUD UPLOAD DROPZONE ULTRA MODERN & INTERAKTIF
 * ============================================================================== */
.ilm-upload-dropzone {
    position: relative;
    border: 2px dashed rgba(13, 122, 135, 0.35);
    border-radius: 18px;
    background: linear-gradient(180deg, rgba(248, 250, 252, 0.85) 0%, rgba(241, 245, 249, 0.65) 100%);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    padding: 22px 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.25s ease;
    margin-bottom: 16px;
    box-sizing: border-box;
}

.ilm-upload-dropzone:hover,
.ilm-upload-dropzone.is-dragover {
    border-color: var(--lumina-teal, #0D7A87);
    background: rgba(13, 122, 135, 0.05);
    box-shadow: 0 8px 24px rgba(13, 122, 135, 0.12);
    transform: translateY(-1px);
}

.ilm-upload-dropzone input[type="file"] {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
    z-index: 5;
}

.ilm-upload-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    pointer-events: none;
}

.ilm-upload-icon-circle {
    width: 52px;
    height: 52px;
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(13, 122, 135, 0.12) 0%, rgba(82, 178, 220, 0.22) 100%);
    border: 1px solid rgba(13, 122, 135, 0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--lumina-teal, #0D7A87);
    font-size: 26px;
    margin-bottom: 2px;
    transition: transform 0.2s ease;
}

.ilm-upload-dropzone:hover .ilm-upload-icon-circle {
    transform: scale(1.08) rotate(3deg);
}

.ilm-upload-title {
    font-family: 'Outfit', sans-serif;
    font-size: 14.5px;
    font-weight: 700;
    color: var(--lumina-navy, #1D3B5E);
    line-height: 1.3;
}

.ilm-upload-highlight {
    color: var(--lumina-teal, #0D7A87);
    text-decoration: underline;
    text-underline-offset: 3px;
}

.ilm-upload-hint {
    font-size: 12px;
    color: #64748B;
    font-weight: 500;
}

.ilm-upload-badge-drive {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
    padding: 4px 10px;
    border-radius: 999px;
    background: rgba(13, 122, 135, 0.08);
    border: 1px solid rgba(13, 122, 135, 0.18);
    font-size: 11px;
    font-weight: 700;
    color: #0D7A87;
}

/* PREVIEW BERKAS TERPILIH */
.ilm-upload-preview {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #FFFFFF;
    border: 1.5px solid rgba(13, 122, 135, 0.35);
    border-radius: 14px;
    padding: 12px 16px;
    box-shadow: 0 4px 14px rgba(29, 59, 94, 0.06);
    position: relative;
    z-index: 6;
}

.ilm-upload-preview__info {
    display: flex;
    align-items: center;
    gap: 12px;
    overflow: hidden;
    text-align: left;
}

.ilm-upload-preview__icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: #FEE2E2;
    color: #DC2626;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}

.ilm-upload-preview__name {
    font-weight: 700;
    font-size: 13.5px;
    color: var(--lumina-navy, #1D3B5E);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 380px;
}

.ilm-upload-preview__size {
    font-size: 11.5px;
    color: #64748B;
    margin-top: 2px;
}

.ilm-upload-preview__remove {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #FEE2E2;
    color: #DC2626;
    border: 1px solid #FCA5A5;
    border-radius: 9px;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.ilm-upload-preview__remove:hover {
    background: #DC2626;
    color: #FFFFFF;
}

/* BERKAS AKTIF DARI DATABASE */
.ilm-upload-existing {
    margin-top: 10px;
    background: rgba(255, 255, 255, 0.95);
    border: 1px solid #CBD5E1;
    border-radius: 12px;
    padding: 10px 14px;
    font-size: 12.5px;
    color: #334155;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    text-align: left;
    position: relative;
    z-index: 6;
}

/* RESPONSIVE SMARTPHONE (360px - 768px) */
@media (max-width: 768px) {
    .ilm-modal-backdrop {
        padding: 10px !important;
    }
    .ilm-modal-dialog {
        border-radius: 22px !important;
        max-height: calc(100vh - 20px) !important;
    }
    .ilm-modal-header {
        padding: 16px 18px !important;
    }
    .ilm-modal-header__title {
        font-size: 16px !important;
    }
    .ilm-modal-body {
        padding: 18px !important;
    }
    .ilm-modal-footer {
        padding: 14px 18px 16px !important;
        flex-direction: column-reverse !important;
        gap: 8px !important;
    }
    .ilm-modal-btn {
        width: 100% !important;
        min-height: 44px !important;
    }
    .ilm-modal-gunungan {
        width: 200px !important;
        height: 250px !important;
        opacity: 0.14 !important;
    }
}
</style>

<script id="ilmuna-universal-modal-script">
/**
 * Auto-Decorator Ornamen Nusantara untuk Setiap Modal Dialog Ilmuna
 * Memastikan setiap .ilm-modal-dialog memiliki Gunungan Wayang & Ornamen Batik lengkap persis seperti alert.
 */
function decorateIlmunaModalDialogs() {
    document.querySelectorAll('.ilm-modal-dialog').forEach(function(dialog) {
        if (!dialog.querySelector('.ilm-modal-gunungan')) {
            const gunungan = document.createElement('div');
            gunungan.className = 'ilm-modal-gunungan';
            gunungan.setAttribute('aria-hidden', 'true');
            dialog.appendChild(gunungan);
        }
        if (!dialog.querySelector('.ilm-modal-ornament-tl')) {
            const tl = document.createElement('div');
            tl.className = 'ilm-modal-ornament-tl';
            tl.setAttribute('aria-hidden', 'true');
            dialog.appendChild(tl);
        }
        if (!dialog.querySelector('.ilm-modal-ornament-tr')) {
            const tr = document.createElement('div');
            tr.className = 'ilm-modal-ornament-tr';
            tr.setAttribute('aria-hidden', 'true');
            dialog.appendChild(tr);
        }
        if (!dialog.querySelector('.ilm-modal-ornament-bl')) {
            const bl = document.createElement('div');
            bl.className = 'ilm-modal-ornament-bl';
            bl.setAttribute('aria-hidden', 'true');
            dialog.appendChild(bl);
        }
        // Pastikan divider ketupat ada di bawah judul modal
        const header = dialog.querySelector('.ilm-modal-header');
        if (header && !header.querySelector('.ilm-modal-divider')) {
            const titleBox = header.querySelector('.ilm-modal-header__brand > div:last-child') || header.querySelector('.ilm-modal-header__brand');
            if (titleBox) {
                const titleEl = titleBox.querySelector('.ilm-modal-header__title');
                const div = document.createElement('div');
                div.className = 'ilm-modal-divider';
                div.setAttribute('aria-hidden', 'true');
                if (titleEl && titleEl.nextSibling) {
                    titleBox.insertBefore(div, titleEl.nextSibling);
                } else if (titleEl) {
                    titleBox.appendChild(div);
                }
            }
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', decorateIlmunaModalDialogs);
} else {
    decorateIlmunaModalDialogs();
}

/**
 * Helper JavaScript Global untuk Membuka & Menutup Modal Ilmuna
 */
function openIlmunaModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;

    // Pastikan ornamen terdekorasi
    decorateIlmunaModalDialogs();

    modal.style.display = 'flex';
    modal.classList.add('is-open');
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';

    const firstInput = modal.querySelector('input:not([type="hidden"]):not([readonly]), select, textarea');
    if (firstInput) {
        setTimeout(() => firstInput.focus(), 150);
    }
}

function closeIlmunaModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;

    modal.classList.remove('is-open');
    modal.classList.remove('show');

    setTimeout(() => {
        if (!modal.classList.contains('is-open') && !modal.classList.contains('show')) {
            modal.style.display = 'none';
        }
    }, 250);

    if (!document.querySelector('.ilm-modal-backdrop.is-open')) {
        document.body.style.overflow = '';
    }
}

// Global ESC key & backdrop click handler
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const activeModals = document.querySelectorAll('.ilm-modal-backdrop.is-open');
        activeModals.forEach(m => closeIlmunaModal(m.id));
    }
});

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('ilm-modal-backdrop') && e.target.classList.contains('is-open')) {
        closeIlmunaModal(e.target.id);
    }
});

/**
 * Inisialisasi Universal Cloud Upload Dropzone
 */
function initIlmunaDropzones() {
    document.querySelectorAll('.ilm-upload-dropzone').forEach(function(dropzone) {
        if (dropzone.__initialized) return;
        dropzone.__initialized = true;

        const fileInput = dropzone.querySelector('input[type="file"]');
        const emptyState = dropzone.querySelector('.ilm-upload-content');
        const previewState = dropzone.querySelector('.ilm-upload-preview');
        const previewName = dropzone.querySelector('.ilm-upload-preview__name');
        const previewSize = dropzone.querySelector('.ilm-upload-preview__size');
        const previewIcon = dropzone.querySelector('.ilm-upload-preview__icon');
        const removeBtn = dropzone.querySelector('.ilm-upload-preview__remove');

        if (!fileInput) return;

        function formatBytes(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function getFileIcon(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            if (['pdf'].includes(ext)) return '<iconify-icon icon="solar:file-text-bold" style="color:#DC2626;"></iconify-icon>';
            if (['doc', 'docx'].includes(ext)) return '<iconify-icon icon="solar:document-bold" style="color:#2563EB;"></iconify-icon>';
            if (['xls', 'xlsx'].includes(ext)) return '<iconify-icon icon="solar:table-bold" style="color:#16A34A;"></iconify-icon>';
            if (['ppt', 'pptx'].includes(ext)) return '<iconify-icon icon="solar:presentation-graph-bold" style="color:#EA580C;"></iconify-icon>';
            if (['zip', 'rar', '7z'].includes(ext)) return '<iconify-icon icon="solar:archive-bold" style="color:#D97706;"></iconify-icon>';
            if (['png', 'jpg', 'jpeg', 'webp', 'gif'].includes(ext)) return '<iconify-icon icon="solar:gallery-bold" style="color:#0D7A87;"></iconify-icon>';
            return '<iconify-icon icon="solar:file-bold" style="color:#475569;"></iconify-icon>';
        }

        function updatePreview(file) {
            if (!file) {
                if (emptyState) emptyState.style.display = 'flex';
                if (previewState) previewState.style.display = 'none';
                return;
            }
            if (previewName) previewName.textContent = file.name;
            if (previewSize) previewSize.textContent = formatBytes(file.size);
            if (previewIcon) previewIcon.innerHTML = getFileIcon(file.name);
            if (emptyState) emptyState.style.display = 'none';
            if (previewState) previewState.style.display = 'flex';
        }

        fileInput.addEventListener('change', function() {
            if (fileInput.files && fileInput.files[0]) {
                updatePreview(fileInput.files[0]);
            } else {
                updatePreview(null);
            }
        });

        // Drag & Drop visual feedback
        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('is-dragover');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('is-dragover');
            }, false);
        });

        dropzone.addEventListener('drop', function(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            if (files && files.length > 0) {
                fileInput.files = files;
                updatePreview(files[0]);
                fileInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        if (removeBtn) {
            removeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                fileInput.value = '';
                updatePreview(null);
                fileInput.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initIlmunaDropzones);
} else {
    initIlmunaDropzones();
}
</script>
