<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — UNIVERSAL DATA TABLE & CARD TEMPLATE (NUSANTARA TOUCH)
 * ==============================================================================
 * Standar komponen tabel data, grid kartu, dan paginasi interaktif untuk seluruh
 * modul sistem Ilmuna Learn (Manajemen Tugas, Kelompok, Penilaian, dll.).
 * 
 * Karakteristik Desain:
 * 1. ZERO CLIPPING & FULLY RESPONSIVE:
 *    - Tabel dan kartu dipastikan kompak, tidak terpotong, tidak over-width, dan
 *      tidak kekecilan di layar apapun (Desktop, Laptop 768p, Tablet, Smartphone 360px+).
 * 2. Sentuhan Budaya Nusantara SAMA PERSIS DENGAN ALERT & MODAL:
 *    - Watermark Gunungan Wayang (assets/img/gunungan.png) anggun di pojok kanan bawah.
 *    - Tekstur latar belakang Batik Kawung geometris halus.
 *    - Garis pemisah / divider bermotif Ketupat Batik (ORNAMENT_DIVIDER).
 *    - Sudut melingkar halus konsisten: border-radius: 20px (ultra-smooth).
 * 3. Dual-View Mode Switcher:
 *    - Pengguna dapat beralih antara Mode Kartu (Card Grid) dan Mode Baris (Table Rows).
 *    - Preferensi tersimpan otomatis di localStorage.
 * 4. Paginasi Interaktif Lengkap:
 *    - Pilihan jumlah item per halaman (6, 12, 24, 48).
 *    - Navigasi halaman cepat: Prev, Next, First, Last, dan angka halaman aktif.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    exit;
}

$baseUrl = defined('BASE_URL') ? BASE_URL : '';
$gununganPngUrl = $baseUrl . '/assets/img/gunungan.png';

$tblGunungan = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 250'%3E%3Cg opacity='0.08' fill='none' stroke='%231D3B5E' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M100,10 C160,80 190,160 190,240 L10,240 C10,160 40,80 100,10 Z' stroke-width='6'/%3E%3Cpath d='M100,240 V150 C100,100 140,90 160,50 M100,180 C100,130 60,120 40,70' stroke-width='4'/%3E%3Cpath d='M100,220 Q160,220 160,170 T130,130 M100,220 Q40,220 40,170 T70,130' stroke-width='3'/%3E%3Cpath d='M100,180 Q130,180 130,150 T100,120 M100,180 Q70,180 70,150 T100,120' stroke-width='3'/%3E%3Ccircle cx='100' cy='200' r='10' stroke-width='4'/%3E%3C/g%3E%3C/svg%3E";

$tblKawung = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='0.5' opacity='0.035'%3E%3Cellipse cx='10' cy='10' rx='6' ry='6'/%3E%3Cellipse cx='30' cy='10' rx='6' ry='6'/%3E%3Cellipse cx='10' cy='30' rx='6' ry='6'/%3E%3Cellipse cx='30' cy='30' rx='6' ry='6'/%3E%3C/g%3E%3C/svg%3E";

$tblDivider = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 120 20'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='1.2' stroke-linecap='round' opacity='0.25'%3E%3Cpath d='M0,10 L35,10'/%3E%3Cpath d='M85,10 L120,10'/%3E%3Cpath d='M60,3 L68,10 L60,17 L52,10 Z'/%3E%3Ccircle cx='45' cy='10' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='75' cy='10' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='60' cy='10' r='1.2' fill='%231D3B5E' stroke='none'/%3E%3C/g%3E%3C/svg%3E";
?>

<style id="ilmuna-table-template-styles">
/* ==============================================================================
 * ILMUNA DATA CONTAINER — NUSANTARA TOUCH
 * ============================================================================== */
.ilm-data-wrapper {
    position: relative;
    width: 100%;
    margin-bottom: 24px;
}

.ilm-data-container {
    position: relative;
    width: 100%;
    background-color: #FFFFFF;
    background-image: url("<?= $tblKawung ?>");
    background-size: 32px 32px;
    background-repeat: repeat;
    border-radius: 20px !important;
    border: 1px solid rgba(226, 232, 240, 0.95);
    box-shadow: 0 4px 20px -4px rgba(29, 59, 94, 0.05);
    overflow: hidden;
    box-sizing: border-box;
}

/* HIASAN GUNUNGAN WAYANG DI KANAN BAWAH CONTAINER DATA */
.ilm-data-gunungan {
    position: absolute;
    right: -25px;
    bottom: -15px;
    width: 220px;
    height: 270px;
    background-image: url("<?= $gununganPngUrl ?>"), url("<?= $tblGunungan ?>");
    background-size: contain;
    background-position: right bottom;
    background-repeat: no-repeat;
    opacity: 0.12;
    pointer-events: none;
    z-index: 0;
}

/* TOOLBAR ATAS: VIEW SWITCHER (CARD VS ROW TABLE) & QUICK STATS */
.ilm-data-toolbar {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 18px;
    background: rgba(255, 255, 255, 0.92);
    border-bottom: 1px solid rgba(241, 245, 249, 0.9);
    flex-wrap: wrap;
    gap: 12px;
}

.ilm-data-toolbar__left {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.ilm-data-toolbar__right {
    display: flex;
    align-items: center;
    gap: 10px;
}

/* VIEW SWITCHER BUTTONS (GRID CARD VS ROW TABLE) */
.ilm-view-switcher {
    display: inline-flex;
    align-items: center;
    background: #F1F5F9;
    border: 1px solid #E2E8F0;
    border-radius: 10px;
    padding: 2px;
    gap: 2px;
}

.ilm-view-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    height: 32px;
    padding: 0 12px;
    border-radius: 8px;
    border: none;
    background: transparent;
    color: #64748B;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
}

.ilm-view-btn:hover {
    color: var(--lumina-navy, #1D3B5E);
}

.ilm-view-btn.is-active {
    background: #FFFFFF;
    color: var(--lumina-teal, #0D7A87);
    box-shadow: 0 2px 6px rgba(13, 122, 135, 0.15);
}

/* ==============================================================================
 * TIPE 1: TABEL DATA PER BARIS (ROW TABLE VIEW) — COMPACT & ZERO CLIPPING
 * ============================================================================== */
.ilm-table-responsive {
    position: relative;
    z-index: 1;
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
}

.ilm-table-responsive::-webkit-scrollbar {
    height: 6px;
}
.ilm-table-responsive::-webkit-scrollbar-thumb {
    background: rgba(13, 122, 135, 0.2);
    border-radius: 10px;
}

.ilm-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    color: #334155;
    text-align: left;
    background: transparent;
}

.ilm-table th {
    background: rgba(248, 250, 252, 0.88);
    backdrop-filter: blur(4px);
    color: var(--lumina-navy, #1D3B5E);
    font-family: 'Outfit', sans-serif;
    font-weight: 800;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px 14px;
    border-bottom: 1.5px solid rgba(226, 232, 240, 0.95);
    white-space: nowrap;
}

.ilm-table td {
    padding: 12px 14px;
    border-bottom: 1px solid rgba(241, 245, 249, 0.8);
    vertical-align: middle;
    background: transparent;
    transition: background 0.15s ease;
}

.ilm-table tr:last-child td {
    border-bottom: none;
}

.ilm-table tr:hover td {
    background: rgba(13, 122, 135, 0.035);
}

/* ACTION BUTTONS DALAM TABEL */
.ilm-table-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 9px;
    border: 1px solid #E2E8F0;
    background: #FFFFFF;
    color: #475569;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.18s ease;
    text-decoration: none;
}

.ilm-table-btn:hover {
    transform: translateY(-1px);
    border-color: #0D7A87;
    color: #0D7A87;
    box-shadow: 0 3px 8px rgba(13, 122, 135, 0.15);
}

.ilm-table-btn--rose:hover {
    border-color: #F43F5E;
    color: #E11D48;
    box-shadow: 0 3px 8px rgba(244, 63, 94, 0.15);
}

.ilm-table-btn--primary {
    background: linear-gradient(135deg, #0D7A87 0%, #0a626c 100%);
    color: #FFFFFF;
    border: none;
    padding: 0 12px;
    width: auto;
    font-size: 11.5px;
    font-weight: 700;
}
.ilm-table-btn--primary:hover {
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(13, 122, 135, 0.3);
}

/* ==============================================================================
 * TIPE 2: GRID KARTU DATA (CARD VIEW) — COMPACT & MEWAH
 * ============================================================================== */
.ilm-card-grid {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
    gap: 16px;
    padding: 16px;
    box-sizing: border-box;
}

.ilm-compact-card {
    position: relative;
    background: rgba(255, 255, 255, 0.95);
    border: 1px solid rgba(226, 232, 240, 0.95);
    border-radius: 16px;
    padding: 16px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 2px 8px rgba(29, 59, 94, 0.03);
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    overflow: hidden;
    box-sizing: border-box;
}

.ilm-compact-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px -4px rgba(29, 59, 94, 0.1);
    border-color: rgba(13, 122, 135, 0.4);
}

.ilm-compact-card__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 10px;
}

.ilm-compact-card__title {
    font-family: 'Outfit', sans-serif;
    font-size: 15px;
    font-weight: 700;
    color: var(--lumina-navy, #1D3B5E);
    line-height: 1.35;
    margin: 0;
}

.ilm-compact-card__meta {
    font-size: 11.5px;
    color: #64748B;
    margin-top: 3px;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.ilm-compact-card__desc {
    font-size: 12.5px;
    color: #475569;
    line-height: 1.45;
    margin: 6px 0 12px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.ilm-compact-card__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding-top: 10px;
    border-top: 1px solid #F1F5F9;
    margin-top: auto;
}

/* ==============================================================================
 * PAGINASI DATA UNIVERSAL — INTERAKTIF & ERGONOMIS
 * ============================================================================== */
.ilm-pagination-bar {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 18px;
    background: rgba(255, 255, 255, 0.95);
    border-top: 1px solid rgba(241, 245, 249, 0.9);
    flex-wrap: wrap;
    gap: 12px;
    font-size: 12.5px;
    color: #64748B;
}

.ilm-pagination-info {
    font-weight: 600;
}
.ilm-pagination-info strong {
    color: var(--lumina-navy, #1D3B5E);
}

.ilm-pagination-controls {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.ilm-pagination-limit {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
}

.ilm-pagination-select {
    padding: 4px 8px;
    border-radius: 8px;
    border: 1px solid #CBD5E1;
    background: #FFFFFF;
    font-size: 12px;
    font-weight: 700;
    color: var(--lumina-navy, #1D3B5E);
    cursor: pointer;
    outline: none;
}

.ilm-pagination-pages {
    display: flex;
    align-items: center;
    gap: 4px;
}

.ilm-page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 30px;
    height: 30px;
    padding: 0 6px;
    border-radius: 8px;
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    color: #475569;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
}

.ilm-page-btn:hover:not(:disabled) {
    border-color: #0D7A87;
    color: #0D7A87;
    background: rgba(13, 122, 135, 0.05);
}

.ilm-page-btn.is-active {
    background: linear-gradient(135deg, #0D7A87 0%, #0a626c 100%) !important;
    color: #FFFFFF !important;
    border-color: #0D7A87 !important;
    box-shadow: 0 2px 8px rgba(13, 122, 135, 0.28);
}

.ilm-page-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

/* RESPONSIVE SMARTPHONE (<= 640px) */
@media (max-width: 640px) {
    .ilm-data-container {
        border-radius: 16px !important;
    }
    .ilm-card-grid {
        grid-template-columns: 1fr !important;
        padding: 12px !important;
        gap: 12px !important;
    }
    .ilm-compact-card {
        padding: 14px !important;
    }
    .ilm-data-toolbar {
        padding: 10px 14px !important;
        flex-direction: column !important;
        align-items: flex-start !important;
    }
    .ilm-data-toolbar__right {
        width: 100% !important;
        justify-content: space-between !important;
    }
    .ilm-pagination-bar {
        padding: 12px 14px !important;
        flex-direction: column !important;
        align-items: center !important;
        text-align: center !important;
        gap: 10px !important;
    }
    .ilm-pagination-controls {
        justify-content: center !important;
        width: 100% !important;
    }
}
</style>

<script id="ilmuna-table-template-script">
/**
 * Universal Data Pagination & View Switcher Engine
 */
class IlmunaDataPaginator {
    constructor(config) {
        this.items = config.items || [];
        this.pageSize = config.pageSize || 12;
        this.currentPage = 1;
        this.containerId = config.containerId;
        this.paginationId = config.paginationId;
        this.renderRow = config.renderRow;
        this.renderCard = config.renderCard;
        this.viewMode = config.viewMode || 'card'; // 'card' atau 'table'
        this.viewModeKey = config.viewModeKey || 'ilm_view_mode';
        this.onPageChange = config.onPageChange || null;

        // Restore view mode preference from localStorage
        const savedView = localStorage.getItem(this.viewModeKey);
        if (savedView === 'card' || savedView === 'table') {
            this.viewMode = savedView;
        }

        this.init();
    }

    setItems(newItems) {
        this.items = newItems || [];
        this.currentPage = 1;
        this.render();
    }

    setViewMode(mode) {
        this.viewMode = mode;
        localStorage.setItem(this.viewModeKey, mode);
        this.render();
    }

    setPage(page) {
        const totalPages = Math.ceil(this.items.length / this.pageSize) || 1;
        if (page < 1) page = 1;
        if (page > totalPages) page = totalPages;
        this.currentPage = page;
        this.render();
        if (this.onPageChange) this.onPageChange(page);
    }

    setPageSize(size) {
        this.pageSize = parseInt(size, 10) || 12;
        this.currentPage = 1;
        this.render();
    }

    getCurrentPageItems() {
        const start = (this.currentPage - 1) * this.pageSize;
        return this.items.slice(start, start + this.pageSize);
    }

    init() {
        this.render();
    }

    render() {
        const container = document.getElementById(this.containerId);
        const pagBar = document.getElementById(this.paginationId);
        if (!container) return;

        const totalItems = this.items.length;
        const totalPages = Math.ceil(totalItems / this.pageSize) || 1;
        if (this.currentPage > totalPages) this.currentPage = totalPages;

        const pageItems = this.getCurrentPageItems();

        // Render Data Berdasarkan View Mode
        if (this.viewMode === 'table' && typeof this.renderRow === 'function') {
            container.innerHTML = `
                <div class="ilm-table-responsive">
                    <table class="ilm-table">
                        <thead id="${this.containerId}Thead"></thead>
                        <tbody id="${this.containerId}Tbody"></tbody>
                    </table>
                </div>
            `;
            const tbody = document.getElementById(`${this.containerId}Tbody`);
            const thead = document.getElementById(`${this.containerId}Thead`);
            this.renderRow(pageItems, tbody, thead);
        } else if (typeof this.renderCard === 'function') {
            container.innerHTML = `<div class="ilm-card-grid" id="${this.containerId}Grid"></div>`;
            const grid = document.getElementById(`${this.containerId}Grid`);
            this.renderCard(pageItems, grid);
        }

        // Render Paginasi Bar
        if (pagBar) {
            const startIdx = totalItems === 0 ? 0 : (this.currentPage - 1) * this.pageSize + 1;
            const endIdx = Math.min(startIdx + this.pageSize - 1, totalItems);

            pagBar.innerHTML = `
                <div class="ilm-pagination-info">
                    Menampilkan <strong>${startIdx} - ${endIdx}</strong> dari <strong>${totalItems}</strong> data
                </div>
                <div class="ilm-pagination-controls">
                    <div class="ilm-pagination-limit">
                        <span>Tampilkan:</span>
                        <select class="ilm-pagination-select" onchange="window.__ilmPaginators['${this.containerId}'].setPageSize(this.value)">
                            <option value="6" ${this.pageSize === 6 ? 'selected' : ''}>6</option>
                            <option value="12" ${this.pageSize === 12 ? 'selected' : ''}>12</option>
                            <option value="24" ${this.pageSize === 24 ? 'selected' : ''}>24</option>
                            <option value="48" ${this.pageSize === 48 ? 'selected' : ''}>48</option>
                        </select>
                    </div>
                    <div class="ilm-pagination-pages" id="${this.paginationId}Pages"></div>
                </div>
            `;

            const pagesWrap = document.getElementById(`${this.paginationId}Pages`);
            if (pagesWrap) {
                // Tombol Prev
                const prevBtn = document.createElement('button');
                prevBtn.type = 'button';
                prevBtn.className = 'ilm-page-btn';
                prevBtn.innerHTML = '<iconify-icon icon="solar:alt-arrow-left-linear"></iconify-icon>';
                prevBtn.title = 'Halaman Sebelumnya';
                prevBtn.disabled = this.currentPage <= 1;
                prevBtn.onclick = () => this.setPage(this.currentPage - 1);
                pagesWrap.appendChild(prevBtn);

                // Range halaman
                let startPage = Math.max(1, this.currentPage - 2);
                let endPage = Math.min(totalPages, startPage + 4);
                if (endPage - startPage < 4) {
                    startPage = Math.max(1, endPage - 4);
                }

                for (let p = startPage; p <= endPage; p++) {
                    const pageBtn = document.createElement('button');
                    pageBtn.type = 'button';
                    pageBtn.className = `ilm-page-btn ${p === this.currentPage ? 'is-active' : ''}`;
                    pageBtn.textContent = p;
                    pageBtn.onclick = () => this.setPage(p);
                    pagesWrap.appendChild(pageBtn);
                }

                // Tombol Next
                const nextBtn = document.createElement('button');
                nextBtn.type = 'button';
                nextBtn.className = 'ilm-page-btn';
                nextBtn.innerHTML = '<iconify-icon icon="solar:alt-arrow-right-linear"></iconify-icon>';
                nextBtn.title = 'Halaman Selanjutnya';
                nextBtn.disabled = this.currentPage >= totalPages;
                nextBtn.onclick = () => this.setPage(this.currentPage + 1);
                pagesWrap.appendChild(nextBtn);
            }
        }
    }
}

// Registry Paginator Global
window.__ilmPaginators = window.__ilmPaginators || {};
</script>
