<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — UNIVERSAL CALENDAR & DATE-TIME PICKER (NUSANTARA TOUCH)
 * ==============================================================================
 * Komponen kalender dan pemilih waktu (Date-Time Picker) ultra-estetik untuk
 * seluruh modul website Ilmuna Learn (tenggat tugas, jadwal, kalender, dll.).
 * 
 * Karakteristik Desain & Responsivitas:
 * 1. ZERO CLIPPING GUARANTEE: Didesain agar TIDAK PERNAH TERPOTONG di resolusi
 *    atau ukuran layar berapapun (Desktop, Laptop 768p, Tablet, Smartphone 360px+).
 * 2. Smart Boundary Detection: Otomatis mendeteksi ruang atas/bawah/tengah layar.
 *    Di smartphone (<640px) atau layar sangat pendek, otomatis beralih menjadi
 *    Centered Dialog dengan backdrop blur yang sangat nyaman.
 * 3. Pemilih Waktu Cerdas (100% Interaktif Bisa Diklik):
 *    - Dropdown jam lengkap (00 - 23) & menit (00 - 59).
 *    - Tombol Preset Waktu 1-Klik: "07:00", "12:00", "15:00", "17:00", "21:00", "23:59".
 * 4. Ornamen Nusantara SAMA PERSIS DENGAN ALERT & MODAL:
 *    - Gunungan Wayang (assets/img/gunungan.png) & SVG fallback.
 *    - Tekstur Batik Kawung geometris.
 *    - Ornamen sudut Batik klasik.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    exit;
}

$baseUrl = defined('BASE_URL') ? BASE_URL : '';
$gununganPngUrl = $baseUrl . '/assets/img/gunungan.png';

$calGunungan = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 250'%3E%3Cg opacity='0.08' fill='none' stroke='%231D3B5E' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M100,10 C160,80 190,160 190,240 L10,240 C10,160 40,80 100,10 Z' stroke-width='6'/%3E%3Cpath d='M100,240 V150 C100,100 140,90 160,50 M100,180 C100,130 60,120 40,70' stroke-width='4'/%3E%3Cpath d='M100,220 Q160,220 160,170 T130,130 M100,220 Q40,220 40,170 T70,130' stroke-width='3'/%3E%3Cpath d='M100,180 Q130,180 130,150 T100,120 M100,180 Q70,180 70,150 T100,120' stroke-width='3'/%3E%3Ccircle cx='100' cy='200' r='10' stroke-width='4'/%3E%3C/g%3E%3C/svg%3E";

$calKawung = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='0.5' opacity='0.05'%3E%3Cellipse cx='10' cy='10' rx='6' ry='6'/%3E%3Cellipse cx='30' cy='10' rx='6' ry='6'/%3E%3Cellipse cx='10' cy='30' rx='6' ry='6'/%3E%3Cellipse cx='30' cy='30' rx='6' ry='6'/%3E%3C/g%3E%3C/svg%3E";

$calSudutTL = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='1.2' stroke-linecap='round' stroke-linejoin='round' opacity='0.18'%3E%3Cpath d='M0,30 Q30,30 30,0'/%3E%3Cpath d='M0,50 Q50,50 50,0'/%3E%3Cpath d='M0,70 Q70,70 70,0'/%3E%3Cpath d='M10,10 Q20,20 10,30'/%3E%3Cpath d='M10,10 Q20,0 30,10'/%3E%3Ccircle cx='25' cy='25' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='45' cy='45' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3C/g%3E%3C/svg%3E";
?>

<style id="ilmuna-calendar-styles">
/* ==============================================================================
 * ILMUNA UI — KALENDER & DATEPICKER NUSANTARA (ZERO CLIPPING SYSTEM)
 * ============================================================================== */
.ilm-cal-backdrop {
    position: fixed !important;
    inset: 0 !important;
    background: rgba(15, 29, 47, 0.5) !important;
    backdrop-filter: blur(4px) !important;
    -webkit-backdrop-filter: blur(4px) !important;
    z-index: 1000000 !important;
    display: none !important;
    opacity: 0 !important;
    transition: opacity 0.22s ease !important;
}

.ilm-cal-backdrop.is-open {
    display: block !important;
    opacity: 1 !important;
}

.ilm-cal-picker {
    position: fixed !important;
    z-index: 1000001 !important;
    width: 345px !important;
    max-width: calc(100vw - 20px) !important;
    max-height: calc(100vh - 20px) !important;
    overflow-y: auto !important;
    overscroll-behavior: contain !important;
    background-color: #FFFFFF !important;
    background-image: url("<?= $calKawung ?>") !important;
    background-size: 30px 30px !important;
    background-repeat: repeat !important;
    /* SUDUT MELINGKAR KONSISTEN (24px) */
    border-radius: 24px !important;
    border: 1px solid rgba(226, 232, 240, 0.95) !important;
    box-shadow: 0 25px 65px -10px rgba(29, 59, 94, 0.3), 0 0 25px rgba(13, 122, 135, 0.12) !important;
    display: none !important;
    flex-direction: column !important;
    animation: ilmCalPopIn 0.24s cubic-bezier(0.34, 1.56, 0.64, 1) forwards !important;
    user-select: none !important;
    font-family: 'Plus Jakarta Sans', sans-serif !important;
    box-sizing: border-box !important;
}

.ilm-cal-picker.is-open {
    display: flex !important;
}

/* Scrollbar halus jika layar sangat pendek */
.ilm-cal-picker::-webkit-scrollbar {
    width: 5px;
}
.ilm-cal-picker::-webkit-scrollbar-track {
    background: transparent;
}
.ilm-cal-picker::-webkit-scrollbar-thumb {
    background: rgba(13, 122, 135, 0.25);
    border-radius: 10px;
}

/* MODE CENTER DI LAYAR PENDEK / HP / TABLET */
.ilm-cal-picker.is-centered-mode {
    position: fixed !important;
    left: 50% !important;
    top: 50% !important;
    transform: translate(-50%, -50%) !important;
    margin: 0 !important;
    box-shadow: 0 30px 85px rgba(15, 29, 47, 0.45) !important;
}

@keyframes ilmCalPopIn {
    from { opacity: 0; transform: scale(0.92) translateY(8px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}

.ilm-cal-picker.is-centered-mode {
    animation: ilmCalPopInCenter 0.24s cubic-bezier(0.34, 1.56, 0.64, 1) forwards !important;
}

@keyframes ilmCalPopInCenter {
    from { opacity: 0; transform: translate(-50%, -46%) scale(0.94); }
    to   { opacity: 1; transform: translate(-50%, -50%) scale(1); }
}

/* HIASAN BATIK SUDUT KIRI ATAS KALENDER */
.ilm-cal-picker::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 85px;
    height: 85px;
    background-image: url("<?= $calSudutTL ?>");
    background-size: contain;
    background-position: top left;
    background-repeat: no-repeat;
    opacity: 0.22;
    pointer-events: none;
    z-index: 0;
}

/* HIASAN GUNUNGAN WAYANG DI SUDUT KANAN BAWAH KALENDER */
.ilm-cal-picker::after {
    content: '';
    position: absolute;
    right: -25px;
    bottom: -15px;
    width: 180px;
    height: 230px;
    background-image: url("<?= $gununganPngUrl ?>"), url("<?= $calGunungan ?>");
    background-size: contain;
    background-position: right bottom;
    background-repeat: no-repeat;
    opacity: 0.18;
    pointer-events: none;
    z-index: 0;
}

/* HEADER KALENDER */
.ilm-cal-header {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 13px 18px 10px;
    background: rgba(255, 255, 255, 0.94);
    border-bottom: 1px solid #F1F5F9;
}

.ilm-cal-header__title {
    font-family: 'Outfit', sans-serif;
    font-size: 15.5px;
    font-weight: 800;
    color: var(--lumina-navy, #1D3B5E);
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
}

.ilm-cal-header__nav {
    display: flex;
    align-items: center;
    gap: 4px;
}

.ilm-cal-nav-btn {
    width: 30px;
    height: 30px;
    border-radius: 9px;
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    color: #475569;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.18s ease;
}

.ilm-cal-nav-btn:hover {
    background: rgba(13, 122, 135, 0.1);
    border-color: #0D7A87;
    color: #0D7A87;
}

/* SHORTCUT PRESETS HARI */
.ilm-cal-presets {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 7px 16px;
    background: rgba(248, 250, 252, 0.7);
    border-bottom: 1px solid #F1F5F9;
    overflow-x: auto;
    scrollbar-width: none;
}
.ilm-cal-presets::-webkit-scrollbar { display: none; }

.ilm-cal-preset-chip {
    padding: 4px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    background: #FFFFFF;
    border: 1px solid #CBD5E1;
    color: #475569;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.18s ease;
}
.ilm-cal-preset-chip:hover {
    background: #0D7A87;
    border-color: #0D7A87;
    color: #FFFFFF;
}

/* NAMA HARI DALAM SEMINGGU */
.ilm-cal-weekdays {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    padding: 8px 14px 4px;
    text-align: center;
    font-size: 11px;
    font-weight: 800;
    color: #64748B;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.ilm-cal-weekdays span:first-child {
    color: #EF4444; /* Minggu */
}

/* GRID TANGGAL */
.ilm-cal-grid {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 3px;
    padding: 4px 14px 8px;
}

.ilm-cal-cell {
    height: 33px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 600;
    color: #1E293B;
    cursor: pointer;
    transition: all 0.15s ease;
    background: transparent;
}

.ilm-cal-cell:hover {
    background: rgba(13, 122, 135, 0.12);
    color: #0D7A87;
}

.ilm-cal-cell.is-today {
    border: 1.5px solid #0D7A87;
    color: #0D7A87;
    font-weight: 800;
}

.ilm-cal-cell.is-selected {
    background: linear-gradient(135deg, #0D7A87 0%, #0a626c 100%) !important;
    color: #FFFFFF !important;
    font-weight: 800;
    box-shadow: 0 4px 12px rgba(13, 122, 135, 0.35);
}

.ilm-cal-cell.is-other-month {
    color: #94A3B8;
    opacity: 0.45;
}

/* TIME PICKER BAR (JAM & MENIT INTERAKTIF) */
.ilm-cal-time-bar {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 16px 5px;
    background: rgba(248, 250, 252, 0.9);
    border-top: 1px solid #F1F5F9;
    gap: 8px;
}

.ilm-cal-time-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    color: var(--lumina-navy, #1D3B5E);
}

.ilm-cal-time-controls {
    display: flex;
    align-items: center;
    gap: 5px;
}

.ilm-cal-select-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
}

.ilm-cal-time-select {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-color: #FFFFFF;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%230D7A87' d='m12 15l-5-5h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 6px center;
    background-size: 14px;
    border: 1.5px solid #CBD5E1;
    border-radius: 9px;
    padding: 4px 22px 4px 8px;
    font-family: 'Outfit', sans-serif;
    font-size: 13.5px;
    font-weight: 800;
    color: var(--lumina-navy, #1D3B5E);
    cursor: pointer;
    outline: none;
    transition: all 0.2s ease;
}

.ilm-cal-time-select:hover,
.ilm-cal-time-select:focus {
    border-color: #0D7A87;
    box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.15);
}

.ilm-cal-time-colon {
    font-family: 'Outfit', sans-serif;
    font-size: 15px;
    font-weight: 800;
    color: #94A3B8;
}

.ilm-cal-time-badge {
    font-size: 10.5px;
    font-weight: 800;
    color: #0D7A87;
    background: rgba(13, 122, 135, 0.1);
    padding: 2px 6px;
    border-radius: 6px;
    letter-spacing: 0.5px;
}

/* ROW PRESET JAM SEKALI KLIK */
.ilm-cal-time-presets {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 3px 16px 8px;
    background: rgba(248, 250, 252, 0.9);
    overflow-x: auto;
    scrollbar-width: none;
}
.ilm-cal-time-presets::-webkit-scrollbar { display: none; }

.ilm-cal-time-presets-title {
    font-size: 10px;
    font-weight: 700;
    color: #64748B;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-right: 2px;
}

.ilm-cal-preset-time-chip {
    padding: 3px 7px;
    border-radius: 7px;
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    font-size: 10.5px;
    font-weight: 700;
    color: #334155;
    cursor: pointer;
    transition: all 0.15s ease;
    white-space: nowrap;
}

.ilm-cal-preset-time-chip:hover {
    background: rgba(13, 122, 135, 0.1);
    border-color: #0D7A87;
    color: #0D7A87;
    transform: translateY(-1px);
}

.ilm-cal-preset-time-chip.is-gold {
    background: rgba(245, 158, 11, 0.12);
    border-color: rgba(245, 158, 11, 0.35);
    color: #B45309;
}
.ilm-cal-preset-time-chip.is-gold:hover {
    background: #F59E0B;
    border-color: #D97706;
    color: #FFFFFF;
}

/* FOOTER KALENDER */
.ilm-cal-footer {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 16px 12px;
    background: rgba(255, 255, 255, 0.95);
    border-top: 1px solid #F1F5F9;
}

.ilm-cal-btn-apply {
    background: linear-gradient(135deg, #0D7A87 0%, #0a626c 100%);
    color: #FFFFFF;
    border: none;
    border-radius: 10px;
    padding: 7px 15px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 3px 10px rgba(13, 122, 135, 0.25);
    transition: all 0.2s ease;
}
.ilm-cal-btn-apply:hover {
    transform: translateY(-1px);
    box-shadow: 0 5px 14px rgba(13, 122, 135, 0.35);
}

.ilm-cal-btn-cancel {
    background: #F1F5F9;
    color: #475569;
    border: 1px solid #E2E8F0;
    border-radius: 10px;
    padding: 7px 12px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}

/* SMARTPHONE RESPONSIVE (<= 640px) */
@media (max-width: 640px) {
    .ilm-cal-picker {
        width: min(345px, calc(100vw - 24px)) !important;
        border-radius: 20px !important;
    }
}
</style>

<!-- BACKDROP MODAL KALENDER UNTUK MENCEGAH CLIPPING -->
<div id="ilmunaCalendarBackdrop" class="ilm-cal-backdrop"></div>

<!-- ELEMEN DOM POPUP KALENDER TUNGGAL GLOBAL -->
<div id="ilmunaCalendarPicker" class="ilm-cal-picker" role="dialog" aria-modal="true">
    <div class="ilm-cal-header">
        <div class="ilm-cal-header__title" id="ilmCalMonthYear">
            <span id="ilmCalMonthLabel">November</span>
            <span id="ilmCalYearLabel" style="color: #0D7A87;">2026</span>
        </div>
        <div class="ilm-cal-header__nav">
            <button type="button" class="ilm-cal-nav-btn" id="ilmCalPrevBtn" title="Bulan Sebelumnya">
                <iconify-icon icon="solar:alt-arrow-left-bold"></iconify-icon>
            </button>
            <button type="button" class="ilm-cal-nav-btn" id="ilmCalNextBtn" title="Bulan Selanjutnya">
                <iconify-icon icon="solar:alt-arrow-right-bold"></iconify-icon>
            </button>
        </div>
    </div>

    <!-- PRESET PINTASAN HARI -->
    <div class="ilm-cal-presets">
        <button type="button" class="ilm-cal-preset-chip" data-preset="today">Hari Ini</button>
        <button type="button" class="ilm-cal-preset-chip" data-preset="tomorrow">Besok</button>
        <button type="button" class="ilm-cal-preset-chip" data-preset="in3days">+3 Hari</button>
        <button type="button" class="ilm-cal-preset-chip" data-preset="in7days">+1 Pekan</button>
    </div>

    <!-- HARI DALAM MINGGU -->
    <div class="ilm-cal-weekdays">
        <span>Min</span>
        <span>Sen</span>
        <span>Sel</span>
        <span>Rab</span>
        <span>Kam</span>
        <span>Jum</span>
        <span>Sab</span>
    </div>

    <!-- GRID TANGGAL -->
    <div class="ilm-cal-grid" id="ilmCalGrid"></div>

    <!-- BARIS PEMILIH JAM & MENIT ULTRA INTERAKTIF -->
    <div class="ilm-cal-time-bar" id="ilmCalTimeBar">
        <div class="ilm-cal-time-label">
            <iconify-icon icon="solar:clock-circle-bold" width="16" style="color: #0D7A87;"></iconify-icon>
            <span>Waktu:</span>
        </div>
        <div class="ilm-cal-time-controls">
            <div class="ilm-cal-select-wrap" title="Klik untuk memilih Jam">
                <select id="ilmCalHour" class="ilm-cal-time-select" aria-label="Pilih Jam">
                    <!-- Terisi via JS 00 - 23 -->
                </select>
            </div>
            <span class="ilm-cal-time-colon">:</span>
            <div class="ilm-cal-select-wrap" title="Klik untuk memilih Menit">
                <select id="ilmCalMinute" class="ilm-cal-time-select" aria-label="Pilih Menit">
                    <!-- Terisi via JS 00 - 59 -->
                </select>
            </div>
            <span class="ilm-cal-time-badge">WIB</span>
        </div>
    </div>

    <!-- PRESET JAM POPULER 1-KLIK -->
    <div class="ilm-cal-time-presets" id="ilmCalTimePresets">
        <span class="ilm-cal-time-presets-title">Preset:</span>
        <button type="button" class="ilm-cal-preset-time-chip" data-preset-time="07:00">07:00</button>
        <button type="button" class="ilm-cal-preset-time-chip" data-preset-time="07:30">07:30</button>
        <button type="button" class="ilm-cal-preset-time-chip" data-preset-time="08:00">08:00</button>
        <button type="button" class="ilm-cal-preset-time-chip" data-preset-time="08:30">08:30</button>
        <button type="button" class="ilm-cal-preset-time-chip" data-preset-time="09:00">09:00</button>
        <button type="button" class="ilm-cal-preset-time-chip" data-preset-time="10:00">10:00</button>
        <button type="button" class="ilm-cal-preset-time-chip" data-preset-time="12:00">12:00</button>
        <button type="button" class="ilm-cal-preset-time-chip" data-preset-time="13:00">13:00</button>
        <button type="button" class="ilm-cal-preset-time-chip" data-preset-time="14:00">14:00</button>
        <button type="button" class="ilm-cal-preset-time-chip" data-preset-time="15:00">15:00</button>
        <button type="button" class="ilm-cal-preset-time-chip is-gold" data-preset-time="23:59">23:59</button>
    </div>

    <!-- FOOTER AKSI -->
    <div class="ilm-cal-footer">
        <button type="button" class="ilm-cal-btn-cancel" id="ilmCalCancelBtn">Batal</button>
        <button type="button" class="ilm-cal-btn-apply" id="ilmCalApplyBtn">Pilih Tanggal</button>
    </div>
</div>

<script id="ilmuna-calendar-engine">
/**
 * Engine Kalender Nusantara Interaktif (Zero-Clipping Adaptive Positioning)
 */
(function() {
    let currentInput = null;
    let selectedYear = new Date().getFullYear();
    let selectedMonth = new Date().getMonth(); // 0-indexed
    let selectedDate = new Date().getDate();
    let isDateTimeMode = true;

    const picker = document.getElementById('ilmunaCalendarPicker');
    const backdrop = document.getElementById('ilmunaCalendarBackdrop');
    const grid = document.getElementById('ilmCalGrid');
    const monthLabel = document.getElementById('ilmCalMonthLabel');
    const yearLabel = document.getElementById('ilmCalYearLabel');
    const hourSelect = document.getElementById('ilmCalHour');
    const minuteSelect = document.getElementById('ilmCalMinute');
    const timeBar = document.getElementById('ilmCalTimeBar');
    const timePresets = document.getElementById('ilmCalTimePresets');

    // Inisialisasi Opsi Jam (00 - 23)
    if (hourSelect && hourSelect.options.length === 0) {
        for (let h = 0; h < 24; h++) {
            const val = String(h).padStart(2, '0');
            const opt = document.createElement('option');
            opt.value = val;
            opt.textContent = val;
            if (h === 23) opt.selected = true;
            hourSelect.appendChild(opt);
        }
    }

    // Inisialisasi Opsi Menit (00 - 59 lengkap)
    if (minuteSelect && minuteSelect.options.length === 0) {
        for (let m = 0; m < 60; m++) {
            const val = String(m).padStart(2, '0');
            const opt = document.createElement('option');
            opt.value = val;
            opt.textContent = val;
            if (m === 30) opt.selected = true;
            minuteSelect.appendChild(opt);
        }
    }

    const monthNames = [
        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];

    function renderCalendar() {
        monthLabel.textContent = monthNames[selectedMonth];
        yearLabel.textContent = selectedYear;
        grid.innerHTML = '';

        const firstDayOfMonth = new Date(selectedYear, selectedMonth, 1).getDay();
        const daysInMonth = new Date(selectedYear, selectedMonth + 1, 0).getDate();
        const daysInPrevMonth = new Date(selectedYear, selectedMonth, 0).getDate();

        const today = new Date();
        const isCurrentMonth = today.getFullYear() === selectedYear && today.getMonth() === selectedMonth;

        // Hari dari bulan sebelumnya
        for (let i = firstDayOfMonth - 1; i >= 0; i--) {
            const cell = document.createElement('div');
            cell.className = 'ilm-cal-cell is-other-month';
            cell.textContent = daysInPrevMonth - i;
            grid.appendChild(cell);
        }

        // Hari dalam bulan aktif
        for (let day = 1; day <= daysInMonth; day++) {
            const cell = document.createElement('div');
            cell.className = 'ilm-cal-cell';
            cell.textContent = day;

            if (isCurrentMonth && day === today.getDate()) {
                cell.classList.add('is-today');
            }
            if (day === selectedDate) {
                cell.classList.add('is-selected');
            }

            cell.addEventListener('click', () => {
                grid.querySelectorAll('.ilm-cal-cell').forEach(c => c.classList.remove('is-selected'));
                cell.classList.add('is-selected');
                selectedDate = day;
            });

            grid.appendChild(cell);
        }
    }

    let pickerMode = 'date';

    function openCalendar(targetInput, withTime = true, mode = 'auto') {
        if (!targetInput) return;
        currentInput = targetInput;

        var inputMode = targetInput.dataset.ilmCalendar || '';
        var inputType = targetInput.type || 'text';
        var inputName = (targetInput.name || targetInput.id || '').toLowerCase();

        var isTimeOnly = (mode === 'time' || inputMode === 'time' || inputType === 'time' || (mode === 'auto' && (inputName.includes('jam') || inputName.includes('time')) && !inputName.includes('tanggal') && !inputName.includes('tgl')));
        var isDateOnly = (mode === 'date' || inputMode === 'date' || inputType === 'date');
        
        pickerMode = isTimeOnly ? 'time' : (isDateOnly ? 'date' : (withTime ? 'datetime' : 'date'));
        isDateTimeMode = (pickerMode === 'datetime' || pickerMode === 'time');

        var headerNav   = picker.querySelector('.ilm-cal-header__nav');
        var presetsDate = picker.querySelector('.ilm-cal-presets');
        var weekdaysBar = picker.querySelector('.ilm-cal-weekdays');
        var calTitle    = document.getElementById('ilmCalMonthYear');
        var applyBtn    = document.getElementById('ilmCalApplyBtn');

        if (isTimeOnly) {
            if (headerNav) headerNav.style.display = 'none';
            if (presetsDate) presetsDate.style.display = 'none';
            if (weekdaysBar) weekdaysBar.style.display = 'none';
            if (grid) grid.style.display = 'none';
            if (calTitle) calTitle.innerHTML = '<span style="color:#0D7A87;display:inline-flex;align-items:center;gap:6px;font-size:15px;font-weight:700;"><iconify-icon icon="solar:clock-circle-bold" width="18"></iconify-icon> Pilih Jam (WIB)</span>';
            timeBar.style.display = 'flex';
            if (timePresets) timePresets.style.display = 'flex';
            if (applyBtn) applyBtn.textContent = 'Pilih Jam';
        } else {
            if (headerNav) headerNav.style.display = 'flex';
            if (presetsDate) presetsDate.style.display = 'flex';
            if (weekdaysBar) weekdaysBar.style.display = 'grid';
            if (grid) grid.style.display = 'grid';
            if (calTitle) calTitle.innerHTML = '<span id="ilmCalMonthLabel">' + monthNames[selectedMonth] + '</span> <span id="ilmCalYearLabel" style="color:#0D7A87;">' + selectedYear + '</span>';
            timeBar.style.display = (pickerMode === 'datetime') ? 'flex' : 'none';
            if (timePresets) timePresets.style.display = (pickerMode === 'datetime') ? 'flex' : 'none';
            if (applyBtn) applyBtn.textContent = (pickerMode === 'datetime') ? 'Pilih Tanggal & Jam' : 'Pilih Tanggal';
        }

        // Parse initial value jika ada
        const val = targetInput.value ? targetInput.value.trim() : '';
        if (val) {
            if (isTimeOnly && val.includes(':')) {
                const parts = val.split(':');
                setSelectValue(hourSelect, parts[0].padStart(2, '0'));
                if (parts.length > 1) setSelectValue(minuteSelect, parts[1].padStart(2, '0'));
            } else {
                const parsed = new Date(val);
                if (!isNaN(parsed.getTime())) {
                    selectedYear = parsed.getFullYear();
                    selectedMonth = parsed.getMonth();
                    selectedDate = parsed.getDate();
                    if (isDateTimeMode) {
                        const hh = String(parsed.getHours()).padStart(2, '0');
                        const mm = String(parsed.getMinutes()).padStart(2, '0');
                        setSelectValue(hourSelect, hh);
                        setSelectValue(minuteSelect, mm);
                    }
                }
            }
        } else if (isTimeOnly) {
            setSelectValue(hourSelect, '07');
            setSelectValue(minuteSelect, '30');
        }

        if (!isTimeOnly) {
            renderCalendar();
        }

        // Tampilkan picker terlebih dahulu untuk mengukur dimensi riil
        picker.classList.add('is-open');

        const viewportW = window.innerWidth;
        const viewportH = window.innerHeight;
        const pickerRect = picker.getBoundingClientRect();
        const pickerW = pickerRect.width || 345;
        const pickerH = pickerRect.height || 420;

        // JIKA LAYAR HP / TABLET (<= 640px) ATAU VIEWPORT PENDEK (<= pickerH + 20):
        // Aktifkan MODE CENTER DENGAN BACKDROP (ANTI-CLIPPING 100%)
        if (viewportW <= 640 || viewportH <= (pickerH + 30)) {
            picker.classList.add('is-centered-mode');
            picker.style.left = '50%';
            picker.style.top = '50%';
            picker.style.transform = 'translate(-50%, -50%)';
            if (backdrop) backdrop.classList.add('is-open');
            return;
        }

        // MODE DESKTOP: POSISIONING CERDAS DI DEKAT INPUT
        picker.classList.remove('is-centered-mode');
        picker.style.transform = 'none';
        if (backdrop) backdrop.classList.remove('is-open');

        const rect = targetInput.getBoundingClientRect();

        // 1. Horizontal Clamp (Mencegah terpotong di kanan atau kiri layar)
        let left = rect.left;
        if (left + pickerW > viewportW - 14) {
            left = viewportW - pickerW - 14;
        }
        left = Math.max(12, left);

        // 2. Vertical Smart Collision Detection (Mencegah terpotong di atas atau bawah layar)
        const spaceBelow = viewportH - rect.bottom;
        const spaceAbove = rect.top;

        let top;
        if (spaceBelow >= pickerH + 12) {
            // Cukup ruang di bawah input
            top = rect.bottom + 6;
        } else if (spaceAbove >= pickerH + 12) {
            // Cukup ruang di atas input
            top = rect.top - pickerH - 6;
        } else {
            // Jika kedua arah sempit (misal di modal scrolling): posisikan vertikal di tengah viewport paling aman
            top = Math.max(12, (viewportH - pickerH) / 2);
        }

        // Final Safety Clamp: Pastikan batas bawah TIDAK PERNAH melewati viewport
        if (top + pickerH > viewportH - 12) {
            top = viewportH - pickerH - 12;
        }
        top = Math.max(12, top);

        picker.style.left = Math.round(left) + 'px';
        picker.style.top = Math.round(top) + 'px';
    }

    function setSelectValue(selectEl, value) {
        if (!selectEl) return;
        let found = false;
        for (let i = 0; i < selectEl.options.length; i++) {
            if (selectEl.options[i].value === value) {
                selectEl.selectedIndex = i;
                found = true;
                break;
            }
        }
        if (!found) {
            // Jika menit unik, tambahkan opsi baru dinamis
            const opt = document.createElement('option');
            opt.value = value;
            opt.textContent = value;
            opt.selected = true;
            selectEl.appendChild(opt);
        }
    }

    function closeCalendar() {
        picker.classList.remove('is-open');
        picker.classList.remove('is-centered-mode');
        if (backdrop) backdrop.classList.remove('is-open');
        currentInput = null;
    }

    function applySelection() {
        if (!currentInput) return;

        const y = selectedYear;
        const m = String(selectedMonth + 1).padStart(2, '0');
        const d = String(selectedDate).padStart(2, '0');

        let hh = hourSelect ? hourSelect.value : '07';
        let mm = minuteSelect ? minuteSelect.value : '30';

        var isTimeOnly = (pickerMode === 'time' || currentInput.dataset.ilmCalendar === 'time' || currentInput.type === 'time');

        // Format untuk datetime-local vs standard text vs time
        if (isTimeOnly) {
            currentInput.value = `${hh}:${mm}`;
        } else if (currentInput.type === 'datetime-local') {
            currentInput.value = `${y}-${m}-${d}T${hh}:${mm}`;
        } else if (currentInput.type === 'date') {
            currentInput.value = `${y}-${m}-${d}`;
        } else {
            // Text input standard ISO/display
            currentInput.value = (pickerMode === 'datetime') ? `${y}-${m}-${d} ${hh}:${mm}:00` : `${y}-${m}-${d}`;
        }

        // Trigger event input & change agar sinkronisasi jam selesai langsung terhitung otomatis
        currentInput.dispatchEvent(new Event('input', { bubbles: true }));
        currentInput.dispatchEvent(new Event('change', { bubbles: true }));
        closeCalendar();
    }

    // Event Listeners Navigasi Bulan
    document.getElementById('ilmCalPrevBtn').addEventListener('click', () => {
        selectedMonth--;
        if (selectedMonth < 0) {
            selectedMonth = 11;
            selectedYear--;
        }
        renderCalendar();
    });

    document.getElementById('ilmCalNextBtn').addEventListener('click', () => {
        selectedMonth++;
        if (selectedMonth > 11) {
            selectedMonth = 0;
            selectedYear++;
        }
        renderCalendar();
    });

    document.getElementById('ilmCalCancelBtn').addEventListener('click', closeCalendar);
    document.getElementById('ilmCalApplyBtn').addEventListener('click', applySelection);

    // Preset Jam 1-Klik Handler
    document.querySelectorAll('.ilm-cal-preset-time-chip[data-preset-time]').forEach(btn => {
        btn.addEventListener('click', () => {
            const time = btn.dataset.presetTime;
            if (time) {
                const parts = time.split(':');
                setSelectValue(hourSelect, parts[0]);
                setSelectValue(minuteSelect, parts[1]);
            }
        });
    });

    // Preset Chips Handler
    picker.querySelectorAll('.ilm-cal-preset-chip[data-preset]').forEach(chip => {
        chip.addEventListener('click', () => {
            const p = chip.dataset.preset;
            const target = new Date();
            if (p === 'tomorrow') target.setDate(target.getDate() + 1);
            if (p === 'in3days') target.setDate(target.getDate() + 3);
            if (p === 'in7days') target.setDate(target.getDate() + 7);

            selectedYear = target.getFullYear();
            selectedMonth = target.getMonth();
            selectedDate = target.getDate();
            renderCalendar();
        });
    });

    // Close on click outside or on backdrop
    if (backdrop) {
        backdrop.addEventListener('click', closeCalendar);
    }

    document.addEventListener('click', (e) => {
        if (!picker.contains(e.target) && currentInput && !currentInput.contains(e.target)) {
            closeCalendar();
        }
    });

    // Handle ESC key to close calendar
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && picker.classList.contains('is-open')) {
            closeCalendar();
        }
    });

    // Handle window resize dynamically
    window.addEventListener('resize', () => {
        if (picker.classList.contains('is-open') && currentInput) {
            openCalendar(currentInput, isDateTimeMode, pickerMode);
        }
    });

    // Expose Global Helper
    window.IlmunaCalendar = {
        open: openCalendar,
        close: closeCalendar,
        bind: function(target, withTime = true, mode = 'auto') {
            var elements = [];
            if (typeof target === 'string') {
                try { elements = Array.from(document.querySelectorAll(target)); } catch (e) {}
            } else if (target instanceof HTMLElement) {
                elements = [target];
            } else if (target && (target instanceof NodeList || Array.isArray(target))) {
                elements = Array.from(target);
            }
            elements.forEach(function(el) {
                if (el.dataset.ilmCalendarBound) return;
                el.dataset.ilmCalendarBound = '1';
                el.style.cursor = 'pointer';

                var resolvedMode = mode;
                if (resolvedMode === 'auto') {
                    if (el.dataset.ilmCalendar === 'time' || el.type === 'time') {
                        resolvedMode = 'time';
                    } else if (el.dataset.ilmCalendar === 'datetime' || el.type === 'datetime-local') {
                        resolvedMode = 'datetime';
                    } else {
                        resolvedMode = withTime ? 'datetime' : 'date';
                    }
                }

                el.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    openCalendar(el, resolvedMode !== 'date', resolvedMode);
                });

                if (el.type === 'time') {
                    el.addEventListener('focus', function() {
                        if (typeof this.showPicker === 'function') {
                            try { this.showPicker(); } catch (err) {}
                        }
                    });
                }
            });
        }
    };

    // Auto-bind input secara komprehensif di seluruh modul website
    function autoBindAllCalendarInputs() {
        window.IlmunaCalendar.bind('[data-ilm-calendar="datetime"]', true, 'datetime');
        window.IlmunaCalendar.bind('[data-ilm-calendar="date"]', false, 'date');
        window.IlmunaCalendar.bind('[data-ilm-calendar="time"]', true, 'time');
        window.IlmunaCalendar.bind('input[type="date"]', false, 'date');
        window.IlmunaCalendar.bind('input[type="datetime-local"]', true, 'datetime');
        window.IlmunaCalendar.bind('input[type="time"]', true, 'time');
        window.IlmunaCalendar.bind('input.datepicker', false, 'date');
        window.IlmunaCalendar.bind('input.flatpickr-input', false, 'date');

        document.querySelectorAll('input').forEach(function(el) {
            if (el.dataset.ilmCalendarBound) return;
            var name = (el.name || el.id || '').toLowerCase();
            var isDateLike = name.includes('tanggal') || name.includes('tgl') || name.includes('deadline') || name.includes('due_date') || name.includes('kaldik') || name.includes('birth_date');
            var isTimeLike = (el.type === 'time' || name.includes('jam_mulai') || name.includes('time_start')) && !name.includes('selesai');

            if (isDateLike) {
                var withTime = name.includes('time') || name.includes('jam') || name.includes('due') || el.type === 'datetime-local';
                window.IlmunaCalendar.bind(el, withTime, withTime ? 'datetime' : 'date');
            } else if (isTimeLike) {
                window.IlmunaCalendar.bind(el, true, 'time');
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoBindAllCalendarInputs);
    } else {
        autoBindAllCalendarInputs();
    }
})();
</script>
