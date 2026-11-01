/**
 * ==============================================================================
 * ILMUNA LEARN — DASHBOARD NILAI HASIL ASESMEN JS ENGINE (PURE REACTIVE SPA)
 * ==============================================================================
 * Lumina Gold Aesthetic, WhatsApp-style instant search, Zero page reload,
 * 3-Step Filter Cascade (Kelas -> Mapel -> Asesmen),
 * Interactive CBT Breakdown Modal, Score Editor Modal, and Blob-based Exports.
 * ==============================================================================
 */

(function () {
    'use strict';

    const app = document.getElementById('appResults');
    if (!app) return;

    const AJAX_URL = app.dataset.ajax || window.location.pathname;

    // Pindahkan modals ke body agar stacking context dan backdrop blur sempurna
    ['modalCbtDetail', 'confirmModal'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el && el.parentNode && el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    });

    // Reactive State
    const state = {
        kategori: 'all',
        status: 'all',
        kelas_id: '',
        kmp_id: '',
        asesmen_id: '',
        search: '',
        page: 1,
        per_page: 20,
        total: 0,
        pages: 1,
        isFetching: false,
        rows: [],
        stats: {},
        meta: null
    };

    let searchTimer = null;
    let abortCtrl = null;

    /* ==============================================================================
     * 1. HELPER UTILITIES
     * ============================================================================== */
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function toast(type, msg) {
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            var normType = (type === 'danger' || type === 'error') ? 'error' : (type || 'info');
            var titleMap = { success: 'Berhasil', error: 'Gagal', warning: 'Peringatan', info: 'Informasi' };
            return Ilmuna.toast(normType, titleMap[normType] || 'Pemberitahuan', msg, 4000);
        }
    }

    function closeToast(el) {
        if (el) el.remove();
    }

    function confirmDialog(msg, opts) {
        opts = opts || {};
        if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
            const type = (opts.type === 'danger' || opts.type === 'error' || opts.type === 'delete') ? 'error' : (opts.type || 'warning');
            return Ilmuna.confirm(type, opts.title || 'Konfirmasi Tindakan', msg, opts.okText || 'Ya, Lanjutkan', opts.cancelText || 'Batal');
        }
        return new Promise(function (resolve) {
            const modal = document.getElementById('confirmModal');
            const titleEl = document.getElementById('confirmTitle');
            const msgEl = document.getElementById('confirmMsg');
            const okBtn = document.getElementById('btnConfirmOk');
            const cancelBtn = document.getElementById('btnConfirmCancel');

            if (!modal) {
                resolve(window.confirm(msg));
                return;
            }

            titleEl.textContent = opts.title || 'Konfirmasi Tindakan';
            msgEl.textContent = msg;
            okBtn.textContent = opts.okText || 'Ya, Lanjutkan';

            function cleanUp() {
                modal.classList.remove('is-open');
                okBtn.removeEventListener('click', onOk);
                cancelBtn.removeEventListener('click', onCancel);
            }

            function onOk() {
                cleanUp();
                resolve(true);
            }

            function onCancel() {
                cleanUp();
                resolve(false);
            }

            okBtn.addEventListener('click', onOk);
            cancelBtn.addEventListener('click', onCancel);
            modal.classList.add('is-open');
        });
    }

    function openModal(id) {
        const el = document.getElementById(id);
        if (el) el.classList.add('is-open');
    }

    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) el.classList.remove('is-open');
    }

    // Modal Close Buttons Handler
    document.querySelectorAll('[data-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const target = btn.dataset.close;
            if (target === '1') {
                const parentModal = btn.closest('.ilmuna-custom-modal, .ilmuna-access-modal');
                if (parentModal) parentModal.classList.remove('is-open');
            } else {
                closeModal(target);
            }
        });
    });

    /* ==============================================================================
     * 2. STEP SELECTION & UI STATE MANAGEMENT
     * ============================================================================== */
    function updateStepUI() {
        const boxKelas = document.getElementById('stepBoxKelas');
        const boxMapel = document.getElementById('stepBoxMapel');
        const boxAsesmen = document.getElementById('stepBoxAsesmen');
        const btnExportExcel = document.getElementById('btnExportExcel');
        const btnExportPdf = document.getElementById('btnExportPdf');

        // Step 1: Kelas
        if (state.kelas_id) {
            boxKelas?.classList.add('is-completed');
            boxKelas?.classList.remove('is-active');
        } else {
            boxKelas?.classList.remove('is-completed');
            boxKelas?.classList.add('is-active');
        }

        // Step 2: Mapel
        if (state.kmp_id) {
            boxMapel?.classList.add('is-completed');
            boxMapel?.classList.remove('is-active');
        } else if (state.kelas_id) {
            boxMapel?.classList.remove('is-completed');
            boxMapel?.classList.add('is-active');
        } else {
            boxMapel?.classList.remove('is-completed', 'is-active');
        }

        // Step 3: Asesmen
        if (state.asesmen_id) {
            boxAsesmen?.classList.add('is-completed');
            boxAsesmen?.classList.remove('is-active');
        } else if (state.kmp_id) {
            boxAsesmen?.classList.remove('is-completed');
            boxAsesmen?.classList.add('is-active');
        } else {
            boxAsesmen?.classList.remove('is-completed', 'is-active');
        }

        // Status Tombol Ekspor Excel & PDF
        const canExport = !!(state.kelas_id && state.asesmen_id);
        if (btnExportExcel) {
            btnExportExcel.disabled = !canExport;
            btnExportExcel.classList.toggle('is-disabled', !canExport);
            btnExportExcel.title = canExport ? "Ekspor Rekap Nilai ke Excel (.xlsx) via Blob" : "Silakan selesaikan Langkah 1 (Kelas), Langkah 2 (Mapel), dan Langkah 3 (Asesmen) dahulu";
        }
        if (btnExportPdf) {
            btnExportPdf.disabled = !canExport;
            btnExportPdf.classList.toggle('is-disabled', !canExport);
            btnExportPdf.title = canExport ? "Cetak / Unduh Laporan Rekap Nilai Resmi via PDF (Blob Method)" : "Silakan selesaikan Langkah 1 (Kelas), Langkah 2 (Mapel), dan Langkah 3 (Asesmen) dahulu";
        }
    }

    function renderStepPrompt() {
        const tbody = document.getElementById('tableResultsBody');
        const paginationInfo = document.getElementById('paginationInfo');
        const paginationBtns = document.getElementById('paginationBtns');
        if (!tbody) return;

        if (paginationBtns) paginationBtns.innerHTML = '';
        updateKpi({
            total_siswa: 0,
            total_dinilai: 0,
            rata_rata: '0.00',
            nilai_tertinggi: '0.00',
            nilai_terendah: '0.00',
            total_tuntas: 0,
            total_remedial: 0,
            cbt_selesai: 0,
            cbt_mengerjakan: 0,
            persen_tuntas: 0
        });

        if (!state.kelas_id) {
            if (paginationInfo) paginationInfo.textContent = 'Menunggu pemilihan Rombel Kelas (Langkah 1)...';
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align: center; padding: 64px 20px;">
                        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px;">
                            <div style="width: 68px; height: 68px; border-radius: 50%; background: #EFF6FF; color: #1D3B5E; display: flex; align-items: center; justify-content: center; font-size: 34px; box-shadow: 0 4px 12px rgba(29, 59, 94, 0.08);">
                                <iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon>
                            </div>
                            <h4 style="margin: 0; font-size: 17px; font-weight: 800; color: #1E293B;">Langkah 1: Silakan Pilih Rombel Kelas</h4>
                            <p style="margin: 0; font-size: 13.5px; color: #64748B; max-width: 480px; line-height: 1.5;">
                                Pilih Rombel Kelas pada formulir di atas. Sistem akan menyiapkan daftar mata pelajaran yang diajarkan pada kelas tersebut.
                            </p>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        if (!state.kmp_id) {
            if (paginationInfo) paginationInfo.textContent = 'Menunggu pemilihan Mata Pelajaran (Langkah 2)...';
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align: center; padding: 64px 20px;">
                        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px;">
                            <div style="width: 68px; height: 68px; border-radius: 50%; background: #F0FDFA; color: #0D7A87; display: flex; align-items: center; justify-content: center; font-size: 34px; box-shadow: 0 4px 12px rgba(13, 122, 135, 0.08);">
                                <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>
                            </div>
                            <h4 style="margin: 0; font-size: 17px; font-weight: 800; color: #1E293B;">Langkah 2: Silakan Pilih Mata Pelajaran</h4>
                            <p style="margin: 0; font-size: 13.5px; color: #64748B; max-width: 480px; line-height: 1.5;">
                                Rombel kelas telah dipilih. Silakan pilih mata pelajaran untuk memuat paket asesmen (Sumatif, Formatif, Diagnostik, atau Pengayaan/Remedial) yang tersedia.
                            </p>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        if (!state.asesmen_id) {
            if (paginationInfo) paginationInfo.textContent = 'Menunggu pemilihan Paket Asesmen (Langkah 3)...';
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align: center; padding: 64px 20px;">
                        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px;">
                            <div style="width: 68px; height: 68px; border-radius: 50%; background: #FFFBEB; color: #D97706; display: flex; align-items: center; justify-content: center; font-size: 34px; box-shadow: 0 4px 12px rgba(217, 119, 6, 0.08);">
                                <iconify-icon icon="solar:document-text-bold"></iconify-icon>
                            </div>
                            <h4 style="margin: 0; font-size: 17px; font-weight: 800; color: #1E293B;">Langkah 3: Silakan Pilih Paket Asesmen</h4>
                            <p style="margin: 0; font-size: 13.5px; color: #64748B; max-width: 480px; line-height: 1.5;">
                                Pilih paket asesmen untuk menampilkan rekapitulasi nilai siswa, lembar jawaban CBT, statistik ketuntasan KKTP, serta mengaktifkan ekspor Excel dan PDF.
                            </p>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }
    }

    /* ==============================================================================
     * 3. SHIMMER SKELETON LOADER (9 KOLOM PRESISI)
     * ============================================================================== */
    function renderSkeleton() {
        const tbody = document.getElementById('tableResultsBody');
        if (!tbody) return;

        let html = '';
        for (let i = 0; i < 5; i++) {
            html += `
            <tr class="ilmuna-skeleton-row">
                <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 22px; margin: 0 auto;"></div></td>
                <td>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div class="ilmuna-skeleton-circle"></div>
                        <div>
                            <div class="ilmuna-skeleton-box" style="width: 140px; margin-bottom: 4px;"></div><br>
                            <div class="ilmuna-skeleton-box" style="width: 90px; height: 10px;"></div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="ilmuna-skeleton-box" style="width: 160px; margin-bottom: 4px;"></div><br>
                    <div class="ilmuna-skeleton-box" style="width: 100px; height: 10px;"></div>
                </td>
                <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 90px; margin: 0 auto;"></div></td>
                <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 60px; margin: 0 auto;"></div></td>
                <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 45px; margin: 0 auto;"></div></td>
                <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 80px; margin: 0 auto;"></div></td>
                <td><div class="ilmuna-skeleton-box" style="width: 120px;"></div></td>
                <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 85px; margin: 0 auto;"></div></td>
            </tr>
            `;
        }
        tbody.innerHTML = html;
    }

    /* ==============================================================================
     * 4. KPI CARDS UPDATER
     * ============================================================================== */
    function updateKpi(stats) {
        if (!stats) return;

        const kpiTotalSiswa = document.getElementById('kpiTotalSiswa');
        const kpiDinilaiSub = document.getElementById('kpiDinilaiSub');
        const kpiRataRata = document.getElementById('kpiRataRata');
        const kpiTertinggiSub = document.getElementById('kpiTertinggiSub');
        const kpiTuntas = document.getElementById('kpiTuntas');
        const kpiTuntasPersen = document.getElementById('kpiTuntasPersen');
        const kpiRemedial = document.getElementById('kpiRemedial');
        const kpiCbtSelesai = document.getElementById('kpiCbtSelesai');
        const kpiCbtSub = document.getElementById('kpiCbtSub');

        if (kpiTotalSiswa) kpiTotalSiswa.textContent = stats.total_siswa ?? '-';
        if (kpiDinilaiSub) kpiDinilaiSub.textContent = (stats.total_dinilai ?? 0) + ' telah dinilai';
        if (kpiRataRata) kpiRataRata.textContent = stats.rata_rata ?? '-';
        if (kpiTertinggiSub) kpiTertinggiSub.textContent = 'Tertinggi: ' + (stats.nilai_tertinggi ?? '-') + ' • Terendah: ' + (stats.nilai_terendah ?? '-');
        if (kpiTuntas) kpiTuntas.textContent = stats.total_tuntas ?? '-';
        if (kpiTuntasPersen) kpiTuntasPersen.textContent = (stats.persen_tuntas ?? 0) + '% dari dinilai';
        if (kpiRemedial) kpiRemedial.textContent = stats.total_remedial ?? '-';
        if (kpiCbtSelesai) kpiCbtSelesai.textContent = stats.cbt_selesai ?? '-';
        if (kpiCbtSub) kpiCbtSub.textContent = 'Mengerjakan: ' + (stats.cbt_sedang ?? stats.cbt_mengerjakan ?? 0);
    }

    /* ==============================================================================
     * 5. TABLE ROW RENDERER (9 KOLOM PERSIS SESUAI THEAD)
     * ============================================================================== */
    function renderTableRows(rows) {
        const tbody = document.getElementById('tableResultsBody');
        if (!tbody) return;

        if (!rows || rows.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align: center; padding: 48px 20px;">
                        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px;">
                            <div style="width: 64px; height: 64px; border-radius: 50%; background: #F1F5F9; color: #94A3B8; display: flex; align-items: center; justify-content: center; font-size: 32px;">
                                <iconify-icon icon="solar:folder-error-bold"></iconify-icon>
                            </div>
                            <h4 style="margin: 0; font-size: 16px; font-weight: 700; color: #1E293B;">Tidak Ada Data Siswa Ditemukan</h4>
                            <p style="margin: 0; font-size: 13px; color: #64748B; max-width: 420px; line-height: 1.5;">
                                Tidak ditemukan data yang cocok dengan kriteria pencarian atau status filter saat ini.
                            </p>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        const startNo = ((state.page - 1) * state.per_page) + 1;
        let html = '';

        rows.forEach(function (row, idx) {
            const rowNo = startNo + idx;
            const initials = (row.nama_lengkap || 'S').trim().split(' ').slice(0, 2).map(n => n[0]).join('').toUpperCase();
            const hasScore = (row.nilai_angka !== null && row.nilai_angka !== undefined && row.nilai_angka !== '');
            const scoreNum = hasScore ? parseFloat(row.nilai_angka).toFixed(2) : '-';
            const kktpNum = parseFloat(row.kktp || 75).toFixed(0);
            const isTuntas = hasScore && (parseFloat(row.nilai_angka) >= parseFloat(kktpNum));
            const predikat = row.predikat || (hasScore ? (parseFloat(row.nilai_angka) >= 90 ? 'A' : (parseFloat(row.nilai_angka) >= 80 ? 'B' : (parseFloat(row.nilai_angka) >= 70 ? 'C' : 'D'))) : '-');

            // Badge Predikat Class
            const predikatClass = 'ilmuna-badge-predikat--' + (predikat || 'E');

            // Ketuntasan Badge
            let ketuntasanBadge = '';
            const kat = (row.kategori || row.kategori_asesmen || '').toLowerCase();
            if (hasScore) {
                if (kat === 'diagnostik') {
                    const dStatus = row.status_tuntas || (isTuntas ? 'Siap Belajar' : 'Perlu Pendampingan Khusus');
                    if (dStatus === 'Siap Belajar') {
                        ketuntasanBadge = `
                            <span class="ilmuna-badge-tuntas ilmuna-badge-tuntas--pass">
                                <iconify-icon icon="solar:check-circle-bold" width="13"></iconify-icon>
                                <span>Siap Belajar</span>
                            </span>
                        `;
                    } else if (dStatus === 'Perlu Penyesuaian Materi') {
                        ketuntasanBadge = `
                            <span class="ilmuna-badge-tuntas ilmuna-badge-tuntas--warning">
                                <iconify-icon icon="solar:info-circle-bold" width="13"></iconify-icon>
                                <span>Penyesuaian Materi</span>
                            </span>
                        `;
                    } else {
                        ketuntasanBadge = `
                            <span class="ilmuna-badge-tuntas ilmuna-badge-tuntas--remedial">
                                <iconify-icon icon="solar:danger-triangle-bold" width="13"></iconify-icon>
                                <span>Perlu Pendampingan</span>
                            </span>
                        `;
                    }
                } else if (kat === 'remedial') {
                    const rStatus = row.status_tuntas || (isTuntas ? (row.tipe_khusus === 'Pengayaan' ? 'Pengayaan Berhasil' : 'Tuntas Remedial') : 'Belum Tuntas');
                    const isTuntasRem = (rStatus === 'Tuntas Remedial' || rStatus === 'Pengayaan Berhasil' || isTuntas);
                    ketuntasanBadge = `
                        <span class="ilmuna-badge-tuntas ${isTuntasRem ? 'ilmuna-badge-tuntas--pass' : 'ilmuna-badge-tuntas--remedial'}">
                            <iconify-icon icon="${isTuntasRem ? 'solar:check-circle-bold' : 'solar:danger-triangle-bold'}" width="13"></iconify-icon>
                            <span>${escapeHtml(rStatus)}</span>
                        </span>
                    `;
                } else {
                    if (isTuntas) {
                        ketuntasanBadge = `
                            <span class="ilmuna-badge-tuntas ilmuna-badge-tuntas--pass">
                                <iconify-icon icon="solar:check-circle-bold" width="13"></iconify-icon>
                                <span>Tuntas</span>
                            </span>
                        `;
                    } else {
                        ketuntasanBadge = `
                            <span class="ilmuna-badge-tuntas ilmuna-badge-tuntas--remedial">
                                <iconify-icon icon="solar:danger-triangle-bold" width="13"></iconify-icon>
                                <span>Remedial</span>
                            </span>
                        `;
                    }
                }
            } else {
                ketuntasanBadge = `
                    <span class="ilmuna-badge-tuntas ilmuna-badge-tuntas--unrated">
                        <iconify-icon icon="solar:clock-circle-bold" width="13"></iconify-icon>
                        <span>Belum Dinilai</span>
                    </span>
                `;
            }

            // Kategori Badge
            let kategoriBadge = '';
            if (kat === 'sumatif') {
                kategoriBadge = '<span class="ilmuna-table-badge ilmuna-table-badge--navy" style="font-size: 10px; padding: 2px 7px;">Sumatif</span>';
            } else if (kat === 'formatif') {
                kategoriBadge = '<span class="ilmuna-table-badge ilmuna-table-badge--teal" style="font-size: 10px; padding: 2px 7px;">Formatif</span>';
            } else if (kat === 'diagnostik') {
                kategoriBadge = '<span class="ilmuna-table-badge ilmuna-table-badge--indigo" style="font-size: 10px; padding: 2px 7px;">Diagnostik</span>';
            } else if (kat === 'remedial') {
                const isPengayaan = (row.tipe_khusus === 'Pengayaan');
                kategoriBadge = isPengayaan 
                    ? '<span class="ilmuna-table-badge ilmuna-table-badge--purple" style="font-size: 10px; padding: 2px 7px;">Pengayaan</span>' 
                    : '<span class="ilmuna-table-badge ilmuna-table-badge--pink" style="font-size: 10px; padding: 2px 7px;">Remedial</span>';
            } else {
                kategoriBadge = '<span class="ilmuna-table-badge ilmuna-table-badge--slate" style="font-size: 10px; padding: 2px 7px;">' + escapeHtml(kat) + '</span>';
            }

            // CBT Status Badge
            const statusCbt = (row.status_cbt || row.cbt_status || 'Belum Mulai');
            let cbtBadge = '';
            if (statusCbt === 'Selesai' || statusCbt === 'selesai') {
                cbtBadge = `
                    <span class="ilmuna-table-badge ilmuna-table-badge--emerald">
                        <iconify-icon icon="solar:check-circle-bold" width="12"></iconify-icon>
                        <span>Selesai</span>
                    </span>
                `;
            } else if (statusCbt === 'Sedang Mengerjakan' || statusCbt === 'sedang_mengerjakan') {
                cbtBadge = `
                    <span class="ilmuna-table-badge ilmuna-table-badge--gold">
                        <iconify-icon icon="solar:stopwatch-play-bold" width="12"></iconify-icon>
                        <span>Mengerjakan</span>
                    </span>
                `;
            } else {
                cbtBadge = `
                    <span class="ilmuna-table-badge ilmuna-table-badge--slate">
                        <iconify-icon icon="solar:clock-circle-linear" width="12"></iconify-icon>
                        <span>Belum Mulai</span>
                    </span>
                `;
            }

            html += `
            <tr data-id="${row.id_siswa}" data-asesmen="${row.id_asesmen}" data-kategori="${row.kategori || row.kategori_asesmen}">
                <!-- 1. No -->
                <td style="text-align: center; font-weight: 700; color: #64748B;">
                    ${rowNo}
                </td>

                <!-- 2. Identitas Siswa & Rombel -->
                <td>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div class="ilmuna-user-avatar" style="width: 38px; height: 38px; border-radius: 10px; background: #EEF2F6; color: #1D3B5E; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 800; border: 1.5px solid #E2E8F0; flex-shrink: 0;">
                            ${escapeHtml(initials)}
                        </div>
                        <div style="min-width: 0;">
                            <div style="font-size: 13.5px; font-weight: 800; color: #1E293B; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                ${escapeHtml(row.nama_lengkap || '-')}
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px; font-size: 11.5px; color: #64748B; margin-top: 2px;">
                                <span>NISN: ${escapeHtml(row.nisn || row.nis || '-')}</span>
                                <span style="display: inline-block; width: 3px; height: 3px; border-radius: 50%; background: #94A3B8;"></span>
                                <span style="font-weight: 600; color: #0D7A87;">${escapeHtml(row.nama_kelas || '-')}</span>
                            </div>
                        </div>
                    </div>
                </td>

                <!-- 3. Paket Asesmen & Mapel -->
                <td>
                    <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 3px;">
                        ${kategoriBadge}
                        <span style="font-size: 13.5px; font-weight: 700; color: #1D3B5E;">${escapeHtml(row.judul_asesmen || '-')}</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px; font-size: 11.5px; color: #64748B;">
                        <span>${escapeHtml(row.nama_mapel || row.nama_mata_pelajaran || '-')}</span>
                        <span style="display: inline-block; width: 3px; height: 3px; border-radius: 50%; background: #94A3B8;"></span>
                        <span style="color: #D97706; font-weight: 600;">KKTP: ${kktpNum}</span>
                    </div>
                </td>

                <!-- 4. Status Ujian CBT -->
                <td style="text-align: center;">
                    ${cbtBadge}
                </td>

                <!-- 5. Nilai Angka -->
                <td style="text-align: center;">
                    <span class="ilmuna-score-val" style="font-size: 16px; font-weight: 900; color: ${hasScore ? '#1D3B5E' : '#94A3B8'};">
                        ${scoreNum}
                    </span>
                </td>

                <!-- 6. Predikat -->
                <td style="text-align: center;">
                    ${hasScore ? `<span class="ilmuna-badge-predikat ${predikatClass}">${predikat}</span>` : '<span style="color: #94A3B8; font-weight: 600;">-</span>'}
                </td>

                <!-- 7. Ketuntasan -->
                <td style="text-align: center;">
                    ${ketuntasanBadge}
                </td>

                <!-- 8. Catatan Evaluasi Guru -->
                <td>
                    <div style="font-size: 12px; color: #475569; max-width: 220px; line-height: 1.4; word-break: break-word;">
                        ${escapeHtml(row.catatan_evaluasi || '-')}
                    </div>
                </td>

                <!-- 9. Aksi (Hanya Lihat Jawaban CBT — Edit Nilai Dinonaktifkan) -->
                <td style="text-align: center;">
                    <div style="display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                        <button type="button" class="ilmuna-btn-icon btn-view-cbt" title="Lihat Lembar Jawaban CBT Siswa"
                                data-kategori="${row.kategori || row.kategori_asesmen}" data-asesmen="${row.id_asesmen}" data-siswa="${row.id_siswa}"
                                data-nama="${escapeHtml(row.nama_lengkap)}" data-nisn="${escapeHtml(row.nisn)}" data-kelas="${escapeHtml(row.nama_kelas)}"
                                data-skor="${scoreNum}" data-kktp="${kktpNum}">
                            <iconify-icon icon="solar:document-text-bold" width="16"></iconify-icon>
                        </button>
                    </div>
                </td>
            </tr>
            `;
        });

        tbody.innerHTML = html;
        bindRowActions();
    }

    /* ==============================================================================
     * 6. PAGINATION RENDERER
     * ============================================================================== */
    function renderPagination(p) {
        const infoEl = document.getElementById('paginationInfo');
        const btnsEl = document.getElementById('paginationBtns');
        if (!infoEl || !btnsEl) return;

        if (!p || p.total === 0) {
            infoEl.textContent = 'Menampilkan 0 siswa';
            btnsEl.innerHTML = '';
            return;
        }

        const start = ((p.page - 1) * p.per_page) + 1;
        const end = Math.min(p.page * p.per_page, p.total);
        infoEl.innerHTML = `Menampilkan <strong>${start}</strong> - <strong>${end}</strong> dari <strong>${p.total}</strong> siswa`;

        let btnsHtml = '';
        if (p.pages > 1) {
            // Tombol Prev
            btnsHtml += `
                <button type="button" class="ilmuna-btn-page ${p.page <= 1 ? 'is-disabled' : ''}" data-page="${p.page - 1}" ${p.page <= 1 ? 'disabled' : ''}>
                    <iconify-icon icon="solar:alt-arrow-left-linear" width="16"></iconify-icon>
                </button>
            `;

            // Page numbers
            const maxVisible = 5;
            let startPage = Math.max(1, p.page - Math.floor(maxVisible / 2));
            let endPage = Math.min(p.pages, startPage + maxVisible - 1);
            if (endPage - startPage + 1 < maxVisible) {
                startPage = Math.max(1, endPage - maxVisible + 1);
            }

            for (let i = startPage; i <= endPage; i++) {
                btnsHtml += `
                    <button type="button" class="ilmuna-btn-page ${i === p.page ? 'is-active' : ''}" data-page="${i}">
                        ${i}
                    </button>
                `;
            }

            // Tombol Next
            btnsHtml += `
                <button type="button" class="ilmuna-btn-page ${p.page >= p.pages ? 'is-disabled' : ''}" data-page="${p.page + 1}" ${p.page >= p.pages ? 'disabled' : ''}>
                    <iconify-icon icon="solar:alt-arrow-right-linear" width="16"></iconify-icon>
                </button>
            `;
        }

        btnsEl.innerHTML = btnsHtml;

        btnsEl.querySelectorAll('.ilmuna-btn-page:not(.is-disabled)').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const targetPage = parseInt(btn.dataset.page);
                if (targetPage && targetPage !== state.page) {
                    state.page = targetPage;
                    fetchList();
                }
            });
        });
    }

    /* ==============================================================================
     * 7. MAIN FETCH LIST (REACTIVE & ABORTABLE)
     * ============================================================================== */
    async function fetchList() {
        // Cek apakah tahapan seleksi Kelas dan Asesmen sudah selesai
        if (!state.kelas_id || !state.asesmen_id) {
            updateStepUI();
            renderStepPrompt();
            return;
        }

        if (state.isFetching && abortCtrl) {
            abortCtrl.abort();
        }

        abortCtrl = new AbortController();
        state.isFetching = true;
        renderSkeleton();

        const params = new URLSearchParams({
            action: 'list',
            kategori: state.kategori,
            status: state.status,
            id_kelas: state.kelas_id,
            id_kmp: state.kmp_id,
            id_asesmen: state.asesmen_id,
            search: state.search,
            page: state.page,
            per_page: state.per_page
        });

        try {
            const resp = await fetch(AJAX_URL + '?' + params.toString(), {
                signal: abortCtrl.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (!resp.ok) throw new Error('HTTP ' + resp.status);

            const json = await resp.json();
            if (json && json.success) {
                state.rows = json.data.items || json.data.rows || [];
                state.stats = json.data.stats || {};
                state.meta = json.data.meta || null;
                state.pagination = json.data.pagination || {};

                updateKpi(state.stats);
                renderTableRows(state.rows);
                renderPagination(state.pagination);
            } else {
                console.warn('Gagal memuat data hasil asesmen:', json.message);
            }
        } catch (err) {
            if (err.name !== 'AbortError') {
                console.error('Fetch error:', err);
            }
        } finally {
            state.isFetching = false;
        }
    }

    /* ==============================================================================
     * 8. ROW ACTIONS BINDING (LIHAT JAWABAN CBT — EDIT NILAI DINONAKTIFKAN)
     * ============================================================================== */
     function bindRowActions() {
        // Tombol Detail Jawaban CBT Siswa (Read-only)
        document.querySelectorAll('.btn-view-cbt').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openCbtDetailModal(btn.dataset);
            });
        });
    }

    /* ==============================================================================
     * 9. MODAL DETAIL CBT JAWABAN SISWA
     * ============================================================================== */
    async function openCbtDetailModal(data) {
        const modal = document.getElementById('modalCbtDetail');
        if (!modal) return;

        const namaEl = document.getElementById('cbtSiswaNama');
        const metaEl = document.getElementById('cbtSiswaMeta');
        const scoreEl = document.getElementById('cbtScoreSummary');
        const container = document.getElementById('cbtQuestionsContainer');

        if (namaEl) namaEl.textContent = data.nama || 'Siswa';
        if (metaEl) metaEl.textContent = `NISN: ${data.nisn || '-'} • Kelas: ${data.kelas || '-'}`;
        if (scoreEl) {
            scoreEl.innerHTML = `
                <div style="text-align: right;">
                    <div style="font-size: 20px; font-weight: 900; color: #1D3B5E;">Skor: ${data.skor || '-'}</div>
                    <div style="font-size: 11.5px; color: #64748B;">Target KKTP: ${data.kktp || 75}</div>
                </div>
            `;
        }

        container.innerHTML = `
            <div style="text-align: center; padding: 40px 20px;">
                <div class="ilmuna-skeleton-circle" style="width: 48px; height: 48px; margin: 0 auto 12px;"></div>
                <div style="font-size: 13.5px; color: #64748B;">Memuat rincian butir soal & jawaban siswa...</div>
            </div>
        `;

        openModal('modalCbtDetail');

        try {
            const params = new URLSearchParams({
                action: 'get_cbt_answers',
                kategori: data.kategori,
                id_asesmen: data.asesmen,
                id_siswa: data.siswa
            });

            const resp = await fetch(AJAX_URL + '?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const json = await resp.json();

            if (!json.success || !json.data || !json.data.questions || json.data.questions.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 36px 20px; background: #F8FAFC; border-radius: 12px; border: 1px dashed #CBD5E1;">
                        <iconify-icon icon="solar:info-circle-bold" width="36" style="color: #94A3B8; margin-bottom: 8px;"></iconify-icon>
                        <h5 style="margin: 0 0 4px; font-weight: 700; color: #334155;">Belum Ada Riwayat Jawaban CBT</h5>
                        <p style="margin: 0; font-size: 12.5px; color: #64748B;">
                            Siswa ini belum mengerjakan soal via CBT atau ujian dilaksanakan secara luring/offline non-CBT.
                        </p>
                    </div>
                `;
                return;
            }

            let qHtml = '';
            json.data.questions.forEach(function (q, idx) {
                const isCorrect = (q.is_correct === 1 || q.is_correct === true);
                const tLower = (q.tipe_soal || '').toLowerCase();
                const isEssay = tLower.includes('essay') || tLower.includes('uraian') || tLower.includes('tugas') || tLower.includes('studi kasus');
                let statusBadge = '';

                if (isEssay) {
                    const skor = parseFloat(q.poin_diperoleh || 0);
                    const maks = parseFloat(q.skor_bobot_maks || 20);
                    const badgeClass = skor >= (maks * 0.7) ? 'ilmuna-table-badge--emerald' : (skor > 0 ? 'ilmuna-table-badge--gold' : 'ilmuna-table-badge--rose');
                    statusBadge = '<span class="ilmuna-table-badge ' + badgeClass + '"><iconify-icon icon="solar:document-text-bold" width="13"></iconify-icon> Esai (' + skor + ' / ' + maks + ' pt)</span>';
                } else if (isCorrect) {
                    statusBadge = '<span class="ilmuna-table-badge ilmuna-table-badge--emerald"><iconify-icon icon="solar:check-circle-bold" width="13"></iconify-icon> Benar (' + (q.poin_diperoleh || 0) + ' pt)</span>';
                } else {
                    statusBadge = '<span class="ilmuna-table-badge ilmuna-table-badge--rose"><iconify-icon icon="solar:close-circle-bold" width="13"></iconify-icon> Salah (0 pt)</span>';
                }

                qHtml += `
                <div class="ilmuna-q-card" style="border: 1px solid #E2E8F0; border-radius: 12px; padding: 16px; margin-bottom: 12px; background: #FFFFFF;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <div style="font-weight: 800; font-size: 13.5px; color: #1E293B;">Nomor ${idx + 1} • <span style="text-transform: capitalize; font-weight: 600; color: #64748B;">${escapeHtml(q.tipe_soal || 'Soal')}</span></div>
                        <div>${statusBadge}</div>
                    </div>
                    <div style="font-size: 13.5px; color: #334155; line-height: 1.5;">
                        ${q.gambar_stimulus ? `<div style="margin-bottom: 10px;"><img src="${escapeHtml(q.gambar_stimulus)}" alt="Stimulus" style="max-width: 100%; max-height: 250px; border-radius: 8px; border: 1px solid #E2E8F0;"></div>` : ''}
                        ${q.stimulus ? `<div style="background: #F8FAFC; border-left: 3px solid #0D7A87; padding: 8px 12px; border-radius: 6px; margin-bottom: 10px; font-size: 12.5px;">${q.stimulus}</div>` : ''}
                        <div>${q.pertanyaan || '-'}</div>
                    </div>
                `;

                if (isEssay) {
                    qHtml += `
                    <div style="background: #F8FAFC; border: 1.5px solid #E2E8F0; border-radius: 10px; padding: 12px; margin-top: 10px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 11.5px; font-weight: 700; color: #475569;">Jawaban Siswa:</span>
                            <span style="font-size: 11.5px; font-weight: 800; color: #0D7A87;">Poin: ${q.poin_diperoleh || 0} / ${q.skor_bobot_maks || 0}</span>
                        </div>
                        <div style="font-size: 13px; color: #1E293B; line-height: 1.5; white-space: pre-wrap; background: #FFFFFF; border: 1px solid #CBD5E1; border-radius: 6px; padding: 10px;">${escapeHtml(q.jawaban_siswa || '(Tidak dijawab)')}</div>
                        ${q.catatan_koreksi_guru ? `
                        <div style="margin-top: 10px; background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 8px; padding: 10px 12px;">
                            <div style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 800; color: #166534; margin-bottom: 6px;">
                                <iconify-icon icon="solar:shield-check-bold" width="16"></iconify-icon>
                                <span>Rincian Evaluasi Rubrik Otomatis</span>
                            </div>
                            <div style="font-size: 11.5px; color: #14532D; line-height: 1.6; white-space: pre-wrap; font-family: monospace;">${escapeHtml(q.catatan_koreksi_guru)}</div>
                        </div>
                        ` : ''}
                    </div>
                    `;
                } else {
                    // Pilihan ganda
                    qHtml += `
                    <div style="margin-top: 12px; display: flex; flex-direction: column; gap: 6px;">
                        <div style="padding: 10px 14px; border-radius: 8px; font-size: 13px; display: flex; justify-content: space-between; align-items: center; ${isCorrect ? 'background: #ECFDF5; border: 1px solid #A7F3D0; color: #065F46;' : 'background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B;'}">
                            <span><strong>Pilihan Siswa:</strong> ${escapeHtml(q.jawaban_siswa || '(Tidak memilih)')}</span>
                            <iconify-icon icon="${isCorrect ? 'solar:check-circle-bold' : 'solar:close-circle-bold'}" width="16"></iconify-icon>
                        </div>
                        ${!isCorrect ? `
                        <div style="padding: 10px 14px; border-radius: 8px; font-size: 13px; display: flex; justify-content: space-between; align-items: center; background: #ECFDF5; border: 1px solid #A7F3D0; color: #065F46;">
                            <span><strong>Kunci Jawaban Benar:</strong> ${escapeHtml(q.kunci_jawaban || '-')}</span>
                            <iconify-icon icon="solar:verified-check-bold" width="16"></iconify-icon>
                        </div>
                        ` : ''}
                    </div>
                    `;
                }

                qHtml += `</div>`;
            });

            container.innerHTML = qHtml;

        } catch (err) {
            console.error('CBT answers error:', err);
            container.innerHTML = `
                <div style="text-align: center; padding: 24px; color: #EF4444;">
                    Terjadi kesalahan saat memuat rincian CBT siswa.
                </div>
            `;
        }
    }

    /* ==============================================================================
     * 11. EXPORT ENGINES (EXCEL XLSX & PDF VIA BLOB METHOD)
     * ============================================================================== */
    // A. Export Excel via Blob Spreadsheet
    document.getElementById('btnExportExcel')?.addEventListener('click', async function () {
        if (!state.kelas_id || !state.asesmen_id) {
            return;
        }

        // Fetch all matching records without pagination
        const params = new URLSearchParams({
            action: 'list',
            kategori: state.kategori,
            status: state.status,
            id_kelas: state.kelas_id,
            id_kmp: state.kmp_id,
            id_asesmen: state.asesmen_id,
            search: state.search,
            page: 1,
            per_page: 1000
        });

        try {
            const resp = await fetch(AJAX_URL + '?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const json = await resp.json();
            const items = json?.data?.items || json?.data?.rows || [];
            const meta = json?.data?.meta || {};

            if (items.length === 0) {
                return;
            }

            let xml = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
            xml += '<head><meta charset="utf-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Rekap Nilai</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head>';
            xml += '<body><table border="1" style="border-collapse: collapse; font-family: Calibri, sans-serif;">';
            
            // Header Info Sekolah & Asesmen
            xml += `<tr style="background: #1D3B5E; color: #FFFFFF; font-weight: bold; font-size: 14pt; height: 35px;"><th colspan="11">REKAPITULASI HASIL ASESMEN PEMBELAJARAN</th></tr>`;
            xml += `<tr><td colspan="2"><strong>Kelas / Rombel:</strong></td><td colspan="9">${escapeHtml(meta.nama_kelas || '-')} (${escapeHtml(meta.tahun_pelajaran || '-')})</td></tr>`;
            xml += `<tr><td colspan="2"><strong>Mata Pelajaran:</strong></td><td colspan="9">${escapeHtml(meta.nama_mata_pelajaran || '-')}</td></tr>`;
            xml += `<tr><td colspan="2"><strong>Paket Asesmen:</strong></td><td colspan="9">${escapeHtml(meta.judul_asesmen || '-')}</td></tr>`;
            xml += `<tr><td colspan="2"><strong>Target KKTP:</strong></td><td colspan="9">${escapeHtml(meta.kktp || 75)}</td></tr>`;
            xml += `<tr><td colspan="2"><strong>Guru Pengampu:</strong></td><td colspan="9">${escapeHtml(meta.nama_guru || '-')}</td></tr>`;
            xml += `<tr><td colspan="2"><strong>Tanggal Cetak:</strong></td><td colspan="9">${new Date().toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })}</td></tr>`;
            xml += `<tr><td colspan="11" style="height: 12px; background: #F1F5F9;"></td></tr>`;

            // Judul Kolom Tabel
            xml += '<tr style="background: #0D7A87; color: #FFFFFF; font-weight: bold; text-align: center; height: 30px;">';
            xml += '<th>No</th><th>NISN</th><th>Nama Lengkap Siswa</th><th>Kelas</th><th>Status Ujian CBT</th><th>Target KKTP</th><th>Nilai Akhir</th><th>Predikat</th><th>Ketuntasan</th><th>Catatan Guru</th><th>Tanggal Penilaian</th>';
            xml += '</tr>';

            items.forEach((row, i) => {
                const hasScore = (row.nilai_angka !== null && row.nilai_angka !== '' && row.nilai_angka !== undefined);
                const score = hasScore ? parseFloat(row.nilai_angka).toFixed(2) : '-';
                const kktp = parseFloat(row.kktp || 75).toFixed(0);
                const isTuntas = hasScore && (parseFloat(row.nilai_angka) >= parseFloat(kktp));
                const predikat = row.predikat || (hasScore ? (score >= 90 ? 'A' : (score >= 80 ? 'B' : (score >= 70 ? 'C' : 'D'))) : '-');
                const tuntasText = hasScore ? (row.status_tuntas || (isTuntas ? 'Tuntas' : 'Remedial')) : 'Belum Dinilai';

                xml += `<tr style="height: 25px;">`;
                xml += `<td style="text-align: center;">${i + 1}</td>`;
                xml += `<td style="mso-number-format:'\\@'; text-align: center;">${escapeHtml(row.nisn || row.nis || '-')}</td>`;
                xml += `<td>${escapeHtml(row.nama_lengkap || '-')}</td>`;
                xml += `<td style="text-align: center;">${escapeHtml(row.nama_kelas || '-')}</td>`;
                xml += `<td style="text-align: center;">${escapeHtml(row.status_cbt || 'Belum Mulai')}</td>`;
                xml += `<td style="text-align: center;">${kktp}</td>`;
                xml += `<td style="text-align: center; font-weight: bold;">${score}</td>`;
                xml += `<td style="text-align: center; font-weight: bold;">${predikat}</td>`;
                xml += `<td style="text-align: center;">${tuntasText}</td>`;
                xml += `<td>${escapeHtml(row.catatan_evaluasi || '-')}</td>`;
                xml += `<td style="text-align: center;">${escapeHtml(row.waktu_penilaian || '-')}</td>`;
                xml += `</tr>`;
            });

            xml += '</table></body></html>';

            // PURE BLOB METHOD EXPORT EXCEL
            const blob = new Blob([xml], { type: 'application/vnd.ms-excel;charset=utf-8' });
            const blobUrl = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = blobUrl;
            const safeTitle = (meta.judul_asesmen || 'rekap-nilai').toLowerCase().replace(/[^a-z0-9]/g, '-');
            a.download = `rekap-nilai-${safeTitle}-${new Date().toISOString().slice(0, 10)}.xls`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(blobUrl), 2000);
        } catch (err) {
            console.error('Export Excel error:', err);
        }
    });

    // B. Export PDF Menggunakan Metode BLOB (Pure Blob Print & Download)
    document.getElementById('btnExportPdf')?.addEventListener('click', async function () {
        if (!state.kelas_id || !state.asesmen_id) {
            return;
        }

        const params = new URLSearchParams({
            action: 'list',
            kategori: state.kategori,
            status: state.status,
            id_kelas: state.kelas_id,
            id_kmp: state.kmp_id,
            id_asesmen: state.asesmen_id,
            search: state.search,
            page: 1,
            per_page: 1000
        });

        try {
            const resp = await fetch(AJAX_URL + '?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const json = await resp.json();
            const items = json?.data?.items || json?.data?.rows || [];
            const stats = json?.data?.stats || {};
            const meta = json?.data?.meta || {};

            if (items.length === 0) {
                return;
            }

            const printDateStr = new Date().toLocaleDateString('id-ID', {
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            });

            let printHtml = `
            <!DOCTYPE html>
            <html lang="id">
            <head>
                <meta charset="utf-8">
                <title>Laporan Hasil Asesmen — ${escapeHtml(meta.judul_asesmen || 'Rekap Nilai')}</title>
                <style>
                    @page {
                        size: A4 portrait;
                        margin: 12mm 15mm 15mm 15mm;
                    }
                    * { box-sizing: border-box; }
                    body {
                        font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
                        margin: 0;
                        padding: 0;
                        color: #1E293B;
                        background: #FFFFFF;
                        font-size: 11pt;
                        line-height: 1.4;
                    }
                    .kop-surat {
                        display: flex;
                        align-items: center;
                        gap: 16px;
                        border-bottom: 3px double #1D3B5E;
                        padding-bottom: 10px;
                        margin-bottom: 14px;
                    }
                    .kop-logo {
                        width: 60px;
                        height: 60px;
                        border-radius: 12px;
                        background: #1D3B5E;
                        color: #FFFFFF;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 26px;
                        font-weight: 900;
                        flex-shrink: 0;
                    }
                    .kop-text { flex: 1; text-align: center; }
                    .kop-text h2 { margin: 0; font-size: 16pt; font-weight: 800; color: #1D3B5E; letter-spacing: 0.5px; }
                    .kop-text h3 { margin: 2px 0 0; font-size: 12pt; font-weight: 700; color: #0D7A87; }
                    .kop-text p { margin: 3px 0 0; font-size: 9pt; color: #64748B; }

                    .doc-title {
                        text-align: center;
                        margin-bottom: 14px;
                    }
                    .doc-title h4 {
                        margin: 0;
                        font-size: 13pt;
                        font-weight: 800;
                        color: #1E293B;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                    }
                    .doc-title span {
                        font-size: 9pt;
                        color: #64748B;
                    }

                    .meta-grid {
                        display: grid;
                        grid-template-columns: 1fr 1fr;
                        gap: 6px 20px;
                        background: #F8FAFC;
                        border: 1px solid #E2E8F0;
                        border-radius: 8px;
                        padding: 10px 14px;
                        margin-bottom: 14px;
                        font-size: 9.5pt;
                    }
                    .meta-row { display: flex; }
                    .meta-lbl { width: 130px; font-weight: 700; color: #475569; }
                    .meta-val { flex: 1; color: #0F172A; }

                    .stats-cards {
                        display: flex;
                        gap: 10px;
                        margin-bottom: 14px;
                    }
                    .stat-box {
                        flex: 1;
                        border: 1px solid #CBD5E1;
                        border-radius: 6px;
                        padding: 8px 10px;
                        text-align: center;
                        background: #FFFFFF;
                    }
                    .stat-val { font-size: 14pt; font-weight: 900; color: #1D3B5E; line-height: 1.1; }
                    .stat-lbl { font-size: 8pt; font-weight: 600; color: #64748B; text-transform: uppercase; margin-top: 2px; }

                    table.report-table {
                        width: 100%;
                        border-collapse: collapse;
                        font-size: 9.5pt;
                        margin-bottom: 18px;
                    }
                    table.report-table th, table.report-table td {
                        border: 1px solid #94A3B8;
                        padding: 6px 8px;
                    }
                    table.report-table th {
                        background: #1D3B5E;
                        color: #FFFFFF;
                        font-weight: 700;
                        text-align: center;
                        font-size: 9pt;
                    }
                    table.report-table td {
                        color: #1E293B;
                    }
                    .text-center { text-align: center; }
                    .text-right { text-align: right; }
                    .badge-tuntas { color: #047857; font-weight: 700; }
                    .badge-remedial { color: #B91C1C; font-weight: 700; }
                    .badge-unrated { color: #64748B; font-weight: 600; }

                    .signature-wrap {
                        display: flex;
                        justify-content: space-between;
                        margin-top: 24px;
                        page-break-inside: avoid;
                        font-size: 9.5pt;
                    }
                    .sig-block {
                        width: 240px;
                        text-align: center;
                    }
                    .sig-space {
                        height: 55px;
                    }
                    .sig-name {
                        font-weight: 700;
                        text-decoration: underline;
                        color: #0F172A;
                    }
                    .sig-nip {
                        font-size: 8.5pt;
                        color: #64748B;
                    }
                </style>
            </head>
            <body>
                <div class="kop-surat">
                    <div class="kop-logo">IL</div>
                    <div class="kop-text">
                        <h2>ILMUNA LEARNING MANAGEMENT SYSTEM</h2>
                        <h3>LEMBAGA PENDIDIKAN DAN PENGEMBANGAN PEMBELAJARAN</h3>
                        <p>Jl. Pendidikan No. 45, Kompleks Islamic Centre • Hotline: (021) 7890123 • info@ilmuna.sch.id</p>
                    </div>
                </div>

                <div class="doc-title">
                    <h4>DAFTAR NILAI &amp; HASIL ASESMEN SISWA</h4>
                    <span>Dicetak otomatis melalui Sistem Manajemen Penilaian Ilmuna Learn</span>
                </div>

                <div class="meta-grid">
                    <div class="meta-row"><span class="meta-lbl">Rombel / Kelas</span><span class="meta-val">: <strong>${escapeHtml(meta.nama_kelas || '-')}</strong> (${escapeHtml(meta.tahun_pelajaran || '-')})</span></div>
                    <div class="meta-row"><span class="meta-lbl">Target KKTP</span><span class="meta-val">: <strong>${escapeHtml(meta.kktp || 75)}</strong></span></div>
                    <div class="meta-row"><span class="meta-lbl">Mata Pelajaran</span><span class="meta-val">: ${escapeHtml(meta.nama_mata_pelajaran || '-')}</span></div>
                    <div class="meta-row"><span class="meta-lbl">Guru Pengampu</span><span class="meta-val">: ${escapeHtml(meta.nama_guru || '-')}</span></div>
                    <div class="meta-row"><span class="meta-lbl">Paket Asesmen</span><span class="meta-val">: <strong>${escapeHtml(meta.judul_asesmen || '-')}</strong></span></div>
                    <div class="meta-row"><span class="meta-lbl">Tanggal Cetak</span><span class="meta-val">: ${printDateStr}</span></div>
                </div>

                <div class="stats-cards">
                    <div class="stat-box"><div class="stat-val">${stats.total_siswa || items.length}</div><div class="stat-lbl">Total Siswa</div></div>
                    <div class="stat-box"><div class="stat-val">${stats.total_dinilai || 0}</div><div class="stat-lbl">Dinilai</div></div>
                    <div class="stat-box"><div class="stat-val">${stats.rata_rata || '0.00'}</div><div class="stat-lbl">Rata-Rata Kelas</div></div>
                    <div class="stat-box"><div class="stat-val" style="color: #047857;">${stats.total_tuntas || 0}</div><div class="stat-lbl">Siswa Tuntas</div></div>
                    <div class="stat-box"><div class="stat-val" style="color: #B91C1C;">${stats.total_remedial || 0}</div><div class="stat-lbl">Remedial</div></div>
                    <div class="stat-box"><div class="stat-val" style="color: #0D7A87;">${stats.persen_tuntas || 0}%</div><div class="stat-lbl">Ketuntasan</div></div>
                </div>

                <table class="report-table">
                    <thead>
                        <tr>
                            <th style="width: 32px;">No</th>
                            <th style="width: 90px;">NISN</th>
                            <th>Nama Lengkap Siswa</th>
                            <th style="width: 75px;">Kelas</th>
                            <th style="width: 80px;">Status CBT</th>
                            <th style="width: 55px;">Nilai</th>
                            <th style="width: 45px;">Grade</th>
                            <th style="width: 75px;">Ketuntasan</th>
                            <th>Catatan Guru</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            items.forEach((row, idx) => {
                const hasScore = (row.nilai_angka !== null && row.nilai_angka !== '' && row.nilai_angka !== undefined);
                const score = hasScore ? parseFloat(row.nilai_angka).toFixed(2) : '-';
                const kktp = parseFloat(row.kktp || 75).toFixed(0);
                const isTuntas = hasScore && (parseFloat(row.nilai_angka) >= parseFloat(kktp));
                const predikat = row.predikat || (hasScore ? (score >= 90 ? 'A' : (score >= 80 ? 'B' : (score >= 70 ? 'C' : 'D'))) : '-');
                const tuntasClass = hasScore ? (isTuntas ? 'badge-tuntas' : 'badge-remedial') : 'badge-unrated';
                const tuntasText = hasScore ? (row.status_tuntas || (isTuntas ? 'Tuntas' : 'Remedial')) : 'Belum';

                printHtml += `
                    <tr>
                        <td class="text-center">${idx + 1}</td>
                        <td class="text-center">${escapeHtml(row.nisn || row.nis || '-')}</td>
                        <td><strong>${escapeHtml(row.nama_lengkap || '-')}</strong></td>
                        <td class="text-center">${escapeHtml(row.nama_kelas || '-')}</td>
                        <td class="text-center" style="font-size: 8.5pt;">${escapeHtml(row.status_cbt || 'Belum Mulai')}</td>
                        <td class="text-center" style="font-weight: 800; font-size: 10.5pt;">${score}</td>
                        <td class="text-center" style="font-weight: 800;">${predikat}</td>
                        <td class="text-center ${tuntasClass}">${tuntasText}</td>
                        <td style="font-size: 8.5pt;">${escapeHtml(row.catatan_evaluasi || '-')}</td>
                    </tr>
                `;
            });

            printHtml += `
                    </tbody>
                </table>

                <div class="signature-wrap">
                    <div class="sig-block">
                        <div>Mengetahui,</div>
                        <div>Kepala Sekolah / Waka Kurikulum</div>
                        <div class="sig-space"></div>
                        <div class="sig-name">Dr. H. Muhammad Ilyas, M.Pd.</div>
                        <div class="sig-nip">NIP. 19780512 200312 1 004</div>
                    </div>
                    <div class="sig-block">
                        <div>Ditetapkan pada: ${printDateStr}</div>
                        <div>Guru Pengampu Mata Pelajaran</div>
                        <div class="sig-space"></div>
                        <div class="sig-name">${escapeHtml(meta.nama_guru || 'Guru Pengampu PAI')}</div>
                        <div class="sig-nip">NIP. 19850914 201101 2 018</div>
                    </div>
                </div>
            </body>
            </html>
            `;

            // METODE BLOB UNTUK EKSPOR / CETAK PDF
            const blob = new Blob([printHtml], { type: 'text/html;charset=utf-8' });
            const blobUrl = URL.createObjectURL(blob);

            let iframe = document.getElementById('printBlobIframe');
            if (!iframe) {
                iframe = document.createElement('iframe');
                iframe.id = 'printBlobIframe';
                iframe.style.position = 'fixed';
                iframe.style.right = '0';
                iframe.style.bottom = '0';
                iframe.style.width = '0';
                iframe.style.height = '0';
                iframe.style.border = '0';
                document.body.appendChild(iframe);
            }

            iframe.onload = function () {
                try {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                } catch (e) {
                    console.error('Print iframe error:', e);
                    window.open(blobUrl, '_blank');
                }
                setTimeout(() => URL.revokeObjectURL(blobUrl), 30000);
            };

            iframe.src = blobUrl;

        } catch (err) {
            console.error('Export PDF error:', err);
        }
    });

    /* ==============================================================================
     * 12. 3-STEP SELECTION CASCADE HANDLERS (KELAS -> MAPEL -> ASESMEN)
     * ============================================================================== */
    const selKelas = document.getElementById('filterKelas');
    const selMapel = document.getElementById('filterMapel');
    const selAsesmen = document.getElementById('filterAsesmen');

    // 1. Pilih Kelas (Langkah 1)
    selKelas?.addEventListener('change', async function () {
        const idKelas = this.value;
        state.kelas_id = idKelas;
        state.kmp_id = '';
        state.asesmen_id = '';
        state.page = 1;

        if (!idKelas) {
            // Reset Step 2 & 3
            if (selMapel) {
                selMapel.innerHTML = '<option value="">— 2. Pilih Kelas Dahulu —</option>';
                selMapel.disabled = true;
            }
            if (selAsesmen) {
                selAsesmen.innerHTML = '<option value="">— 3. Pilih Mapel Dahulu —</option>';
                selAsesmen.disabled = true;
            }
            updateStepUI();
            renderStepPrompt();
            return;
        }

        // Enable Step 2 & Fetch Mapel
        if (selMapel) {
            selMapel.disabled = true;
            selMapel.innerHTML = '<option value="">Memuat mata pelajaran...</option>';
        }
        if (selAsesmen) {
            selAsesmen.disabled = true;
            selAsesmen.innerHTML = '<option value="">— 3. Pilih Mapel Dahulu —</option>';
        }

        updateStepUI();
        renderStepPrompt();

        try {
            const resp = await fetch(AJAX_URL + '?action=get_mapel_by_kelas&id_kelas=' + encodeURIComponent(idKelas), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const res = await resp.json();

            if (res.success && Array.isArray(res.data) && res.data.length > 0) {
                let options = '<option value="">— 2. Pilih Mata Pelajaran —</option>';
                res.data.forEach(function (m) {
                    options += `<option value="${m.id_kmp}">${escapeHtml(m.nama_mata_pelajaran)} (${escapeHtml(m.kode_mata_pelajaran || 'MAPEL')})</option>`;
                });
                selMapel.innerHTML = options;
                selMapel.disabled = false;
            } else {
                selMapel.innerHTML = '<option value="">Tidak ada mata pelajaran di kelas ini</option>';
                selMapel.disabled = true;
            }
        } catch (err) {
            console.error('Fetch mapel error:', err);
            selMapel.innerHTML = '<option value="">Gagal memuat mata pelajaran</option>';
            selMapel.disabled = true;
        }

        updateStepUI();
    });

    // 2. Pilih Mata Pelajaran (Langkah 2)
    selMapel?.addEventListener('change', async function () {
        const idKmp = this.value;
        state.kmp_id = idKmp;
        state.asesmen_id = '';
        state.page = 1;

        if (!idKmp) {
            if (selAsesmen) {
                selAsesmen.innerHTML = '<option value="">— 3. Pilih Mapel Dahulu —</option>';
                selAsesmen.disabled = true;
            }
            updateStepUI();
            renderStepPrompt();
            return;
        }

        // Enable Step 3 & Fetch Asesmen
        if (selAsesmen) {
            selAsesmen.disabled = true;
            selAsesmen.innerHTML = '<option value="">Memuat paket asesmen...</option>';
        }

        updateStepUI();
        renderStepPrompt();

        try {
            const resp = await fetch(AJAX_URL + '?action=get_asesmen_by_kmp&id_kmp=' + encodeURIComponent(idKmp), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const res = await resp.json();

            if (res.success && Array.isArray(res.data) && res.data.length > 0) {
                let options = '<option value="">— 3. Pilih Paket Asesmen —</option>';
                res.data.forEach(function (a) {
                    let prefix = '[SUMATIF]';
                    if (a.kategori === 'formatif') {
                        prefix = '[FORMATIF]';
                    } else if (a.kategori === 'diagnostik') {
                        prefix = '[DIAGNOSTIK]';
                    } else if (a.kategori === 'remedial') {
                        prefix = (a.tipe_khusus === 'Pengayaan') ? '[PENGAYAAN]' : '[REMEDIAL]';
                    }

                    options += `<option value="${a.id}" data-kategori="${a.kategori}">
                        ${prefix} ${escapeHtml(a.judul_asesmen)} (KKTP: ${a.kktp || 75})
                    </option>`;
                });
                selAsesmen.innerHTML = options;
                selAsesmen.disabled = false;
            } else {
                selAsesmen.innerHTML = '<option value="">Tidak ada paket asesmen pada mapel ini</option>';
                selAsesmen.disabled = true;
            }
        } catch (err) {
            console.error('Fetch asesmen error:', err);
            selAsesmen.innerHTML = '<option value="">Gagal memuat paket asesmen</option>';
            selAsesmen.disabled = true;
        }

        updateStepUI();
    });

    // 3. Pilih Paket Asesmen (Langkah 3)
    selAsesmen?.addEventListener('change', function () {
        const idAsesmen = this.value;
        state.asesmen_id = idAsesmen;
        state.page = 1;

        if (!idAsesmen) {
            updateStepUI();
            renderStepPrompt();
            return;
        }

        // Simpan kategori terpilih dari option jika ada
        const selectedOpt = selAsesmen.options[selAsesmen.selectedIndex];
        if (selectedOpt && selectedOpt.dataset.kategori) {
            state.kategori = selectedOpt.dataset.kategori;
            // Sinkronkan pill kategori
            document.querySelectorAll('#filterKategoriPills .ilmuna-pill-btn').forEach(function (btn) {
                btn.classList.toggle('is-active', btn.dataset.kategori === state.kategori);
            });
        }

        updateStepUI();
        fetchList();
    });

    /* ==============================================================================
     * 13. FILTER TOOLBAR: PILLS, SEARCH, PER PAGE, RESET
     * ============================================================================== */
    // Filter Pills Kategori Asesmen
    document.querySelectorAll('#filterKategoriPills .ilmuna-pill-btn').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('#filterKategoriPills .ilmuna-pill-btn').forEach(p => p.classList.remove('is-active'));
            pill.classList.add('is-active');
            state.kategori = pill.dataset.kategori;
            state.page = 1;
            if (state.kelas_id && state.asesmen_id) {
                fetchList();
            }
        });
    });

    // Filter Pills Status Ketuntasan
    document.querySelectorAll('#filterStatusPills .ilmuna-pill-btn').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('#filterStatusPills .ilmuna-pill-btn').forEach(p => p.classList.remove('is-active'));
            pill.classList.add('is-active');
            state.status = pill.dataset.status;
            state.page = 1;
            if (state.kelas_id && state.asesmen_id) {
                fetchList();
            }
        });
    });

    // Filter Per Page
    document.getElementById('filterPerPage')?.addEventListener('change', function () {
        state.per_page = parseInt(this.value) || 20;
        state.page = 1;
        if (state.kelas_id && state.asesmen_id) {
            fetchList();
        }
    });

    // WhatsApp-Style Instant Search (250ms debounce)
    document.getElementById('filterSearch')?.addEventListener('input', function () {
        const val = this.value.trim();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            state.search = val;
            state.page = 1;
            if (state.kelas_id && state.asesmen_id) {
                fetchList();
            }
        }, 250);
    });

    // Tombol Reset Filter
    document.getElementById('btnResetFilter')?.addEventListener('click', function () {
        state.kategori = 'all';
        state.status = 'all';
        state.kelas_id = '';
        state.kmp_id = '';
        state.asesmen_id = '';
        state.search = '';
        state.page = 1;

        if (selKelas) selKelas.value = '';
        if (selMapel) {
            selMapel.innerHTML = '<option value="">— 2. Pilih Kelas Dahulu —</option>';
            selMapel.disabled = true;
        }
        if (selAsesmen) {
            selAsesmen.innerHTML = '<option value="">— 3. Pilih Mapel Dahulu —</option>';
            selAsesmen.disabled = true;
        }

        const searchInput = document.getElementById('filterSearch');
        if (searchInput) searchInput.value = '';

        document.querySelectorAll('#filterKategoriPills .ilmuna-pill-btn').forEach(p => {
            p.classList.toggle('is-active', p.dataset.kategori === 'all');
        });
        document.querySelectorAll('#filterStatusPills .ilmuna-pill-btn').forEach(p => {
            p.classList.toggle('is-active', p.dataset.status === 'all');
        });

        updateStepUI();
        renderStepPrompt();
    });

    /* ==============================================================================
     * 14. INITIAL BOOTSTRAP ON LOAD
     * ============================================================================== */
    updateStepUI();
    renderStepPrompt();

})();
