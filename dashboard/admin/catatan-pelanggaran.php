<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — REKAP CATATAN PELANGGARAN SISWA PENUH (PANEL ADMINISTRATOR)
 * ==============================================================================
 * Khusus Rekap Penuh (Read-Only) dengan tampilan Tabel Siswa seperti Portal Guru:
 *   - Tab 1 (Utama): Rekap Tabel Siswa (Grup WA, Realitas, Total Kasus, Poin, Detail Riwayat)
 *   - Tab 2: Log Rincian Pelanggaran (semua kejadian lintas kelas, mapel, dan guru)
 *   - Filter lengkap: Kelas, Mata Pelajaran, Guru Pencatat, Lokasi, Tingkat, Status, Search
 *   - Ekspor Excel (.xlsx) Lumina Gold via catatan-pelanggaran-excel.php
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

requireRole('admin');

if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
    require_once __DIR__ . '/catatan-pelanggaran-excel.php';
    exit;
}

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");

// Pastikan tabel catatan_pelanggaran_siswa tersedia
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `catatan_pelanggaran_siswa` (
        `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_siswa` INT(11) UNSIGNED NOT NULL,
        `id_kelas` INT(11) UNSIGNED NOT NULL,
        `id_kelas_mata_pelajaran` INT(11) UNSIGNED NULL DEFAULT NULL,
        `id_guru` INT(11) UNSIGNED NULL DEFAULT NULL,
        `recorded_by_user_id` INT(11) NULL DEFAULT NULL,
        `recorded_by_role` ENUM('admin','guru') NOT NULL DEFAULT 'guru',
        `lokasi_kejadian` ENUM('Grup WA','Realitas') NOT NULL DEFAULT 'Realitas',
        `tanggal_kejadian` DATE NOT NULL,
        `waktu_kejadian` TIME NULL DEFAULT NULL,
        `kategori_pelanggaran` VARCHAR(120) NOT NULL,
        `tingkat_pelanggaran` ENUM('Ringan','Sedang','Berat') NOT NULL DEFAULT 'Ringan',
        `poin` INT(11) NOT NULL DEFAULT 5,
        `kronologi` TEXT NOT NULL,
        `bukti_keterangan` VARCHAR(255) NULL DEFAULT NULL,
        `tindak_lanjut` TEXT NULL DEFAULT NULL,
        `status_penanganan` ENUM('Baru','Pembinaan','Selesai') NOT NULL DEFAULT 'Baru',
        `tahun_pelajaran` VARCHAR(30) NULL DEFAULT '2026/2027',
        `semester` ENUM('Ganjil','Genap') NOT NULL DEFAULT 'Ganjil',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_pel_siswa` (`id_siswa`, `tanggal_kejadian`),
        INDEX `idx_pel_kelas_kmp` (`id_kelas`, `id_kelas_mata_pelajaran`),
        INDEX `idx_pel_lokasi` (`lokasi_kejadian`, `tingkat_pelanggaran`, `status_penanganan`),
        INDEX `idx_pel_guru` (`id_guru`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$kelasList = $pdo->query("SELECT id, nama_kelas FROM kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
$mapelList = $pdo->query("SELECT id, nama_mata_pelajaran FROM mata_pelajaran ORDER BY nama_mata_pelajaran ASC")->fetchAll(PDO::FETCH_ASSOC);
$guruList  = $pdo->query("SELECT id, nama_guru FROM guru ORDER BY nama_guru ASC")->fetchAll(PDO::FETCH_ASSOC);

/* ==============================================================================
 * AJAX JSON API (?api=1) — REKAP READ-ONLY ADMIN
 * ============================================================================== */
if (isset($_GET['api']) && $_GET['api'] === '1') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $fKelas = (int)($_GET['id_kelas'] ?? 0);
        $fMapel = (int)($_GET['id_mapel'] ?? 0);
        $fGuru  = (int)($_GET['id_guru'] ?? 0);

        $where = [];
        $bind  = [];
        if ($fKelas > 0) { $where[] = 'p.id_kelas = ?'; $bind[] = $fKelas; }
        if ($fMapel > 0) { $where[] = 'kmp.id_mata_pelajaran = ?'; $bind[] = $fMapel; }
        if ($fGuru > 0)  { $where[] = 'p.id_guru = ?'; $bind[] = $fGuru; }

        $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';
        $st = $pdo->prepare("
            SELECT p.*,
                   s.nama_lengkap AS nama_siswa, s.nis,
                   k.nama_kelas,
                   COALESCE(mp.nama_mata_pelajaran, 'Umum / Non-Mapel') AS nama_mapel,
                   COALESCE(g.nama_guru, u.full_name, 'Administrator') AS nama_pencatat
            FROM catatan_pelanggaran_siswa p
            JOIN siswa s ON s.id = p.id_siswa
            JOIN kelas k ON k.id = p.id_kelas
            LEFT JOIN kelas_mata_pelajaran kmp ON kmp.id = p.id_kelas_mata_pelajaran
            LEFT JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
            LEFT JOIN guru g ON g.id = p.id_guru
            LEFT JOIN users u ON u.id = p.recorded_by_user_id
            {$whereSql}
            ORDER BY p.tanggal_kejadian DESC, p.id DESC
            LIMIT 1000
        ");
        $st->execute($bind);
        $logs = $st->fetchAll(PDO::FETCH_ASSOC);

        $cWa = 0; $cReal = 0;
        $bySiswa = [];

        // Jika filter kelas dipilih, tampilkan seluruh siswa pada kelas tsb (seperti tabel absensi guru)
        if ($fKelas > 0) {
            $stS = $pdo->prepare("
                SELECT s.id, s.nis, s.nama_lengkap, k.nama_kelas
                FROM siswa s
                JOIN kelas k ON k.id = s.id_kelas
                WHERE s.id_kelas = ?
                ORDER BY s.nama_lengkap ASC
            ");
            $stS->execute([$fKelas]);
            foreach ($stS->fetchAll(PDO::FETCH_ASSOC) as $sRow) {
                $sid = (int)$sRow['id'];
                $bySiswa[$sid] = [
                    'id_siswa'      => $sid,
                    'nama_siswa'    => $sRow['nama_lengkap'],
                    'nis'           => $sRow['nis'],
                    'nama_kelas'    => $sRow['nama_kelas'],
                    'kasus_wa'      => 0,
                    'kasus_real'    => 0,
                    'total_kasus'   => 0,
                    'total_poin'    => 0,
                    'last_kategori' => '',
                    'terakhir_tgl'  => '',
                ];
            }
        }

        foreach ($logs as $r) {
            if ($r['lokasi_kejadian'] === 'Grup WA') $cWa++;
            else $cReal++;

            $sid = (int)$r['id_siswa'];
            if (!isset($bySiswa[$sid])) {
                $bySiswa[$sid] = [
                    'id_siswa'      => $sid,
                    'nama_siswa'    => $r['nama_siswa'],
                    'nis'           => $r['nis'],
                    'nama_kelas'    => $r['nama_kelas'],
                    'kasus_wa'      => 0,
                    'kasus_real'    => 0,
                    'total_kasus'   => 0,
                    'total_poin'    => 0,
                    'last_kategori' => $r['kategori_pelanggaran'],
                    'terakhir_tgl'  => $r['tanggal_kejadian'],
                ];
            }
            $bySiswa[$sid]['total_kasus']++;
            if ($r['lokasi_kejadian'] === 'Grup WA') $bySiswa[$sid]['kasus_wa']++;
            else $bySiswa[$sid]['kasus_real']++;
            $bySiswa[$sid]['total_poin'] += (int)$r['poin'];
            if ($bySiswa[$sid]['terakhir_tgl'] === '' || $r['tanggal_kejadian'] >= $bySiswa[$sid]['terakhir_tgl']) {
                $bySiswa[$sid]['terakhir_tgl']  = $r['tanggal_kejadian'];
                $bySiswa[$sid]['last_kategori'] = $r['kategori_pelanggaran'];
            }
        }

        $rekapSiswa = array_values($bySiswa);
        if ($fKelas <= 0) {
            usort($rekapSiswa, function ($a, $b) {
                return ($b['total_poin'] <=> $a['total_poin']) ?: ($b['total_kasus'] <=> $a['total_kasus']);
            });
        }

        echo json_encode([
            'success'     => true,
            'logs'        => $logs,
            'rekap_siswa' => $rekapSiswa,
            'counts'      => [
                'siswa' => count($rekapSiswa),
                'logs'  => count($logs),
                'wa'    => $cWa,
                'real'  => $cReal,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Kesalahan server: ' . $e->getMessage()]);
    }
    exit;
}

$pageTitle = 'Rekap Catatan Pelanggaran Siswa';
$pageIcon  = 'solar:shield-warning-bold';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/table-template.php';
require_once BASE_PATH . '/includes/modal-template.php';
?>
<style>
.pel-wrap { width: 100%; max-width: 100%; margin: 0 auto; }
.pel-badge-wa { background: rgba(22, 163, 74, 0.12); color: #15803D; border: 1px solid rgba(22, 163, 74, 0.25); padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
.pel-badge-real { background: rgba(29, 59, 94, 0.10); color: #1D3B5E; border: 1px solid rgba(29, 59, 94, 0.22); padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
.pel-badge-clean { background: #F1F5F9; color: #64748B; border: 1px solid #E2E8F0; padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
.pel-badge-ringan { background: rgba(245, 158, 11, 0.14); color: #B45309; }
.pel-badge-sedang { background: rgba(234, 88, 12, 0.14); color: #C2410C; }
.pel-badge-berat { background: rgba(220, 38, 38, 0.14); color: #DC2626; }
/* Tombol Seragam Tema Lumina Gold (Navy #1D3B5E, Teal #0D7A87, Gold #F59E0B) */
.ilmuna-nav-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 36px; padding: 0 14px; border-radius: 10px; font-size: 12.5px; font-weight: 700; cursor: pointer; border: none; transition: all .18s ease; white-space: nowrap; font-family: inherit; }
.ilmuna-nav-btn--primary { background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); color: #FFFFFF; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.28); }
.ilmuna-nav-btn--primary:hover { transform: translateY(-1px); box-shadow: 0 6px 15px rgba(245, 158, 11, 0.38); }
.ilmuna-nav-btn--ghost { background: #FFFFFF; color: #1D3B5E; border: 1.5px solid #CBD5E1; }
.ilmuna-nav-btn--ghost:hover { background: #F8FAFC; border-color: #0D7A87; color: #0D7A87; }
.ilmuna-nav-btn:disabled { opacity: 0.65; cursor: not-allowed; transform: none; }
.pel-btn-hist { display: inline-flex; align-items: center; gap: 5px; height: 32px; padding: 0 12px; border-radius: 9px; border: 1.5px solid #CBD5E1; background: #FFFFFF; color: #1D3B5E; font-size: 12px; font-weight: 700; cursor: pointer; transition: all .15s ease; font-family: inherit; }
.pel-btn-hist:hover { border-color: #0D7A87; color: #0D7A87; background: #F0FDFA; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.pel-hist-item { border: 1px solid #E2E8F0; border-radius: 12px; padding: 12px 14px; margin-bottom: 10px; background: #F8FAFC; }
.pel-hist-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; margin-bottom: 6px; }
</style>

<div class="pel-wrap">
    <!-- 1. SEGMENTED TABS (REKAP READ-ONLY) -->
    <div class="curriculum-tabs-wrapper" id="pelAdmTabs">
        <button type="button" class="curriculum-tab-btn is-active" data-tab="rekap_siswa">
            <iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon>
            <span class="curriculum-tab-label">Rekap Tabel Siswa</span>
            <span class="curriculum-tab-counter" id="admCntSiswa">0</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="logs">
            <iconify-icon icon="solar:clipboard-list-bold"></iconify-icon>
            <span class="curriculum-tab-label">Log Rincian Pelanggaran</span>
            <span class="curriculum-tab-counter" id="admCntAll">0</span>
        </button>
    </div>

    <!-- 2. FILTER & EXPORT TOOLBAR -->
    <div class="ilmuna-filter-box">
        <div class="ilmuna-filter-bottomline" style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid #E2E8F0;">
            <select id="admFilterKelas" class="ilmuna-select" onchange="PEL_ADM.loadData()">
                <option value="0">-- Semua Rombel Kelas --</option>
                <?php foreach ($kelasList as $k): ?>
                    <option value="<?= (int)$k['id'] ?>"><?= htmlspecialchars($k['nama_kelas'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <select id="admFilterMapel" class="ilmuna-select" onchange="PEL_ADM.loadData()">
                <option value="0">-- Semua Mata Pelajaran --</option>
                <?php foreach ($mapelList as $m): ?>
                    <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['nama_mata_pelajaran'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <select id="admFilterGuru" class="ilmuna-select" onchange="PEL_ADM.loadData()">
                <option value="0">-- Semua Guru Pencatat --</option>
                <?php foreach ($guruList as $g): ?>
                    <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['nama_guru'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <select id="admFilterStatus" class="ilmuna-select" onchange="PEL_ADM.render()">
                <option value="all">-- Semua Status --</option>
                <option value="Baru">Baru</option>
                <option value="Pembinaan">Pembinaan</option>
                <option value="Selesai">Selesai</option>
            </select>
        </div>

        <div class="ilmuna-filter-topline">
            <div class="ilmuna-pill-group" id="admLocPills">
                <button type="button" class="ilmuna-pill-btn is-active" data-loc="all">Semua</button>
                <button type="button" class="ilmuna-pill-btn" data-loc="Grup WA" style="color:#15803D;">Grup WA (<span id="admCntWa">0</span>)</button>
                <button type="button" class="ilmuna-pill-btn" data-loc="Realitas" style="color:#1D3B5E;">Realitas (<span id="admCntReal">0</span>)</button>
            </div>

            <div class="ilmuna-search-wrap">
                <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                <input type="text" id="admSearchInput" class="ilmuna-search-input" placeholder="Cari nama siswa, NIS, kelas, mapel, guru, kategori..." autocomplete="off">
            </div>

            <div class="ilmuna-filter-actions">
                <div class="ilmuna-nav-stat-inline">
                    <iconify-icon icon="solar:document-text-bold" width="14"></iconify-icon>
                    <strong id="admKpiCount">0</strong>
                    <span id="admKpiLabel">Siswa</span>
                </div>
                <button type="button" id="btnExportPelAdm" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" onclick="PEL_ADM.exportExcel(this)">
                    <iconify-icon icon="solar:file-download-bold" width="16" style="color:#10B981;"></iconify-icon>
                    <span>Ekspor Excel</span>
                </button>
            </div>
        </div>
    </div>

    <!-- 3A. TABEL REKAP PER SISWA (DEFAULT, ALA TABEL SISWA GURU) -->
    <div class="ilm-data-container" id="admRekapWrap">
        <div class="ilm-data-gunungan" aria-hidden="true"></div>
        <div class="ilm-table-responsive">
            <table class="ilm-table">
                <thead>
                    <tr>
                        <th style="width:52px;">No</th>
                        <th>Siswa</th>
                        <th>Kelas</th>
                        <th>Di Grup WA</th>
                        <th>Di Realitas</th>
                        <th>Total Kasus</th>
                        <th>Akumulasi Poin</th>
                        <th>Catatan Terakhir</th>
                        <th style="text-align:right;">Detail</th>
                    </tr>
                </thead>
                <tbody id="admRekapTbody">
                    <tr><td colspan="9" style="text-align:center;padding:34px;color:#64748B;">Memuat rekap pelanggaran siswa...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 3B. TABEL LOG RINCIAN PELANGGARAN (READ-ONLY) -->
    <div class="ilm-data-container" id="admLogWrap" style="display:none;">
        <div class="ilm-data-gunungan" aria-hidden="true"></div>
        <div class="ilm-table-responsive">
            <table class="ilm-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Siswa</th>
                        <th>Kelas &amp; Mapel</th>
                        <th>Lokasi</th>
                        <th>Kategori &amp; Kronologi</th>
                        <th>Tingkat &amp; Poin</th>
                        <th>Pencatat &amp; Status</th>
                    </tr>
                </thead>
                <tbody id="admLogTbody">
                    <tr><td colspan="7" style="text-align:center;padding:34px;color:#64748B;">Memuat log pelanggaran...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL DETAIL RIWAYAT PELANGGARAN SISWA (READ-ONLY) -->
<div class="ilm-modal-backdrop" id="modalDetailAdm">
    <div class="ilm-modal-dialog" style="max-width:700px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon" style="background:linear-gradient(135deg,#1D3B5E,#0D7A87);">
                    <iconify-icon icon="solar:history-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="admDetailTitle">Detail Riwayat Pelanggaran</h3>
                    <p class="ilm-modal-header__subtitle" id="admDetailSub">-</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalDetailAdm')" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <div class="ilm-modal-body" id="admDetailBody" style="max-height:calc(82vh - 140px);overflow-y:auto;"></div>
        <div class="ilm-modal-footer">
            <button type="button" class="ilm-modal-btn ilm-modal-btn--primary" onclick="closeIlmunaModal('modalDetailAdm')">Tutup</button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var API_URL   = <?= json_encode(BASE_URL . '/dashboard/admin/catatan-pelanggaran?api=1') ?>;
    var EXCEL_URL = <?= json_encode(BASE_URL . '/dashboard/admin/catatan-pelanggaran') ?>;

    var STATE = {
        logs: [],
        rekapSiswa: [],
        activeTab: 'rekap_siswa',
        locFilter: 'all'
    };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    async function fetchJsonSafe(url, opts, retryCount) {
        retryCount = retryCount || 0;
        var reqOpts = Object.assign({ credentials: 'same-origin' }, opts || {});
        reqOpts.headers = Object.assign({ 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, reqOpts.headers || {});
        try {
            var r = await fetch(url, reqOpts);
            var txt = await r.text();
            var trimmed = txt.trim();
            if (trimmed.charAt(0) !== '{' && trimmed.charAt(0) !== '[') {
                if (retryCount < 1) {
                    await new Promise(function (ok) { setTimeout(ok, 450); });
                    return fetchJsonSafe(url, opts, retryCount + 1);
                }
                if (trimmed.indexOf('GUARDIAN DOME') !== -1 || trimmed.indexOf('login') !== -1) {
                    window.location.reload();
                    return null;
                }
                throw new Error('Respons server tidak valid (HTTP ' + r.status + ').');
            }
            return JSON.parse(trimmed);
        } catch (err) {
            if (retryCount < 1) {
                await new Promise(function (ok) { setTimeout(ok, 450); });
                return fetchJsonSafe(url, opts, retryCount + 1);
            }
            throw err;
        }
    }

    window.PEL_ADM = {
        loadData: async function () {
            var k = document.getElementById('admFilterKelas').value || '0';
            var m = document.getElementById('admFilterMapel').value || '0';
            var g = document.getElementById('admFilterGuru').value || '0';
            try {
                var res = await fetchJsonSafe(API_URL + '&id_kelas=' + encodeURIComponent(k) + '&id_mapel=' + encodeURIComponent(m) + '&id_guru=' + encodeURIComponent(g));
                if (!res) return;
                if (res.success) {
                    STATE.logs = res.logs || [];
                    STATE.rekapSiswa = res.rekap_siswa || [];
                    var c = res.counts || { siswa: 0, logs: 0, wa: 0, real: 0 };
                    document.getElementById('admCntSiswa').textContent = c.siswa;
                    document.getElementById('admCntAll').textContent = c.logs;
                    document.getElementById('admCntWa').textContent = c.wa;
                    document.getElementById('admCntReal').textContent = c.real;
                    PEL_ADM.render();
                } else {
                    Ilmuna.error('Gagal Memuat', (res && res.message) || 'Tidak dapat mengambil rekap pelanggaran.');
                }
            } catch (e) {
                Ilmuna.error('Gagal Memuat', (e && e.message) ? e.message : 'Koneksi ke server terganggu. Silakan coba lagi.');
            }
        },
        render: function () {
            var q = (document.getElementById('admSearchInput').value || '').toLowerCase().trim();
            var isRekap = (STATE.activeTab === 'rekap_siswa');
            document.getElementById('admRekapWrap').style.display = isRekap ? '' : 'none';
            document.getElementById('admLogWrap').style.display = isRekap ? 'none' : '';

            if (isRekap) {
                var rList = STATE.rekapSiswa.filter(function (s) {
                    if (STATE.locFilter === 'Grup WA' && s.kasus_wa <= 0) return false;
                    if (STATE.locFilter === 'Realitas' && s.kasus_real <= 0) return false;
                    if (q) {
                        var hay = ((s.nama_siswa || '') + ' ' + (s.nis || '') + ' ' + (s.nama_kelas || '') + ' ' + (s.last_kategori || '')).toLowerCase();
                        if (hay.indexOf(q) === -1) return false;
                    }
                    return true;
                });
                document.getElementById('admKpiCount').textContent = rList.length;
                document.getElementById('admKpiLabel').textContent = 'Siswa';
                var rtb = document.getElementById('admRekapTbody');
                if (!rList.length) {
                    rtb.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:34px;color:#64748B;">Belum ada data rekap pelanggaran siswa pada filter ini.</td></tr>';
                    return;
                }
                rtb.innerHTML = rList.map(function (s, idx) {
                    var waBadge = s.kasus_wa > 0
                        ? '<span class="pel-badge-wa"><iconify-icon icon="solar:chat-round-dots-bold" width="13"></iconify-icon>' + s.kasus_wa + ' Kasus</span>'
                        : '<span class="pel-badge-clean">0</span>';
                    var realBadge = s.kasus_real > 0
                        ? '<span class="pel-badge-real"><iconify-icon icon="solar:users-group-rounded-bold" width="13"></iconify-icon>' + s.kasus_real + ' Kasus</span>'
                        : '<span class="pel-badge-clean">0</span>';
                    var pCls = s.total_poin >= 50 ? 'pel-badge-berat' : (s.total_poin >= 20 ? 'pel-badge-sedang' : (s.total_poin > 0 ? 'pel-badge-ringan' : ''));
                    var poinHtml = s.total_poin > 0
                        ? '<span class="ilmuna-badge ' + pCls + '">' + s.total_poin + ' Poin</span>'
                        : '<span class="pel-badge-clean">0 Poin</span>';
                    var lastHtml = s.total_kasus > 0
                        ? '<strong>' + esc(s.last_kategori || '-') + '</strong><div style="font-size:11px;color:#64748B;">' + esc(s.terakhir_tgl || '-') + '</div>'
                        : '<span style="font-size:12px;color:#94A3B8;">Belum ada pelanggaran</span>';
                    var detailBtn = s.total_kasus > 0
                        ? '<button type="button" class="pel-btn-hist" onclick="PEL_ADM.openDetail(' + s.id_siswa + ')"><iconify-icon icon="solar:eye-bold" width="14"></iconify-icon><span>Riwayat (' + s.total_kasus + ')</span></button>'
                        : '<span style="font-size:12px;color:#94A3B8;">-</span>';
                    return '<tr>'
                        + '<td data-label="No">' + (idx + 1) + '</td>'
                        + '<td data-label="Siswa"><strong>' + esc(s.nama_siswa) + '</strong><div style="font-size:11px;color:#64748B;">NIS: ' + esc(s.nis || '-') + '</div></td>'
                        + '<td data-label="Kelas"><strong>' + esc(s.nama_kelas) + '</strong></td>'
                        + '<td data-label="Grup WA">' + waBadge + '</td>'
                        + '<td data-label="Realitas">' + realBadge + '</td>'
                        + '<td data-label="Total Kasus"><strong>' + s.total_kasus + ' Kasus</strong></td>'
                        + '<td data-label="Akumulasi Poin">' + poinHtml + '</td>'
                        + '<td data-label="Terakhir">' + lastHtml + '</td>'
                        + '<td data-label="Detail" style="text-align:right;">' + detailBtn + '</td>'
                        + '</tr>';
                }).join('');
                return;
            }

            var stFilter = document.getElementById('admFilterStatus').value || 'all';
            var list = STATE.logs.filter(function (x) {
                if (STATE.locFilter !== 'all' && x.lokasi_kejadian !== STATE.locFilter) return false;
                if (stFilter !== 'all' && x.status_penanganan !== stFilter) return false;
                if (q) {
                    var hay = ((x.nama_siswa || '') + ' ' + (x.nis || '') + ' ' + (x.nama_kelas || '') + ' ' + (x.nama_mapel || '') + ' ' + (x.nama_pencatat || '') + ' ' + (x.kategori_pelanggaran || '') + ' ' + (x.kronologi || '')).toLowerCase();
                    if (hay.indexOf(q) === -1) return false;
                }
                return true;
            });

            document.getElementById('admKpiCount').textContent = list.length;
            document.getElementById('admKpiLabel').textContent = 'Catatan';
            var tb = document.getElementById('admLogTbody');
            if (!list.length) {
                tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:34px;color:#64748B;">Belum ada catatan pelanggaran pada filter ini.</td></tr>';
                return;
            }
            tb.innerHTML = list.map(function (r) {
                var locBadge = r.lokasi_kejadian === 'Grup WA'
                    ? '<span class="pel-badge-wa"><iconify-icon icon="solar:chat-round-dots-bold" width="13"></iconify-icon>Grup WA</span>'
                    : '<span class="pel-badge-real"><iconify-icon icon="solar:users-group-rounded-bold" width="13"></iconify-icon>Realitas</span>';
                var tCls = r.tingkat_pelanggaran === 'Berat' ? 'pel-badge-berat' : (r.tingkat_pelanggaran === 'Sedang' ? 'pel-badge-sedang' : 'pel-badge-ringan');
                var stColor = r.status_penanganan === 'Selesai' ? '#15803D' : (r.status_penanganan === 'Pembinaan' ? '#0D7A87' : '#B45309');
                return '<tr>'
                    + '<td data-label="Tanggal"><strong>' + esc(r.tanggal_kejadian) + '</strong><div style="font-size:11px;color:#64748B;">' + esc(r.waktu_kejadian ? String(r.waktu_kejadian).slice(0, 5) + ' WIB' : '-') + '</div></td>'
                    + '<td data-label="Siswa"><strong>' + esc(r.nama_siswa) + '</strong><div style="font-size:11px;color:#64748B;">NIS: ' + esc(r.nis || '-') + '</div></td>'
                    + '<td data-label="Kelas & Mapel"><strong>' + esc(r.nama_kelas) + '</strong><div style="font-size:11.5px;color:#0D7A87;">' + esc(r.nama_mapel) + '</div></td>'
                    + '<td data-label="Lokasi">' + locBadge + '</td>'
                    + '<td data-label="Kategori"><strong>' + esc(r.kategori_pelanggaran) + '</strong><div style="font-size:12px;color:#475569;margin-top:2px;">' + esc(r.kronologi) + '</div>'
                        + (r.tindak_lanjut ? '<div style="font-size:11.5px;color:#0D7A87;margin-top:2px;">Tindak Lanjut: ' + esc(r.tindak_lanjut) + '</div>' : '') + '</td>'
                    + '<td data-label="Tingkat"><span class="ilmuna-badge ' + tCls + '">' + esc(r.tingkat_pelanggaran) + ' · ' + (r.poin || 0) + ' Poin</span></td>'
                    + '<td data-label="Pencatat"><div style="font-weight:600;font-size:12px;color:#334155;">' + esc(r.nama_pencatat) + '</div><div style="font-weight:700;font-size:11.5px;color:' + stColor + ';">' + esc(r.status_penanganan) + '</div></td>'
                    + '</tr>';
            }).join('');
        },
        openDetail: function (siswaId) {
            var s = STATE.rekapSiswa.find(function (x) { return parseInt(x.id_siswa, 10) === parseInt(siswaId, 10); });
            if (!s) return;
            var list = STATE.logs.filter(function (x) { return parseInt(x.id_siswa, 10) === parseInt(siswaId, 10); });
            document.getElementById('admDetailTitle').textContent = s.nama_siswa + (s.nis ? ' (NIS: ' + s.nis + ')' : '');
            document.getElementById('admDetailSub').textContent = s.nama_kelas + ' · Total ' + s.total_kasus + ' Kasus (' + s.total_poin + ' Poin)';

            var body = document.getElementById('admDetailBody');
            if (!list.length) {
                body.innerHTML = '<p style="text-align:center;color:#64748B;padding:24px 0;">Belum ada riwayat pelanggaran.</p>';
            } else {
                body.innerHTML = list.map(function (r) {
                    var locBadge = r.lokasi_kejadian === 'Grup WA'
                        ? '<span class="pel-badge-wa">Grup WA</span>'
                        : '<span class="pel-badge-real">Realitas</span>';
                    var tCls = r.tingkat_pelanggaran === 'Berat' ? 'pel-badge-berat' : (r.tingkat_pelanggaran === 'Sedang' ? 'pel-badge-sedang' : 'pel-badge-ringan');
                    return '<div class="pel-hist-item">'
                        + '<div class="pel-hist-head">'
                            + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
                                + locBadge
                                + '<span class="ilmuna-badge ' + tCls + '">' + esc(r.tingkat_pelanggaran) + ' · ' + (r.poin || 0) + ' Poin</span>'
                                + '<span style="font-size:12px;color:#64748B;">' + esc(r.tanggal_kejadian) + (r.waktu_kejadian ? ' · ' + esc(String(r.waktu_kejadian).slice(0, 5)) + ' WIB' : '') + '</span>'
                            + '</div>'
                            + '<span style="font-size:11.5px;font-weight:700;color:#0D7A87;">' + esc(r.nama_mapel) + ' · ' + esc(r.nama_pencatat) + '</span>'
                        + '</div>'
                        + '<div style="font-weight:700;color:#0F172A;font-size:13.5px;">' + esc(r.kategori_pelanggaran) + '</div>'
                        + '<div style="font-size:12.5px;color:#475569;margin-top:4px;">' + esc(r.kronologi) + '</div>'
                        + (r.bukti_keterangan ? '<div style="font-size:12px;color:#64748B;margin-top:4px;"><strong>Bukti:</strong> ' + esc(r.bukti_keterangan) + '</div>' : '')
                        + (r.tindak_lanjut ? '<div style="font-size:12px;color:#0D7A87;margin-top:4px;"><strong>Tindak Lanjut:</strong> ' + esc(r.tindak_lanjut) + '</div>' : '')
                        + '</div>';
                }).join('');
            }
            openIlmunaModal('modalDetailAdm');
        },
        exportExcel: async function (btnEl) {
            var params = new URLSearchParams();
            params.set('action', 'export_excel');
            var k = document.getElementById('admFilterKelas').value || '0';
            var m = document.getElementById('admFilterMapel').value || '0';
            var g = document.getElementById('admFilterGuru').value || '0';
            var st = document.getElementById('admFilterStatus').value || 'all';
            var q = (document.getElementById('admSearchInput').value || '').trim();
            if (k !== '0') params.set('id_kelas', k);
            if (m !== '0') params.set('id_mapel', m);
            if (g !== '0') params.set('id_guru', g);
            if (STATE.locFilter === 'Grup WA' || STATE.locFilter === 'Realitas') params.set('lokasi', STATE.locFilter);
            if (st && st !== 'all') params.set('status', st);
            if (q) params.set('search', q);

            var origHtml = btnEl ? btnEl.innerHTML : '';
            if (btnEl) {
                btnEl.disabled = true;
                btnEl.innerHTML = '<iconify-icon icon="solar:refresh-bold" width="16" style="animation:spin 1s linear infinite;"></iconify-icon><span>Mengekspor...</span>';
            }
            try {
                var resp = await fetch(EXCEL_URL + '?' + params.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!resp.ok) {
                    var errText = 'Gagal mengekspor berkas Excel.';
                    try {
                        var j = await resp.json();
                        if (j && j.data && j.data.message) errText = j.data.message;
                    } catch (_) {}
                    throw new Error(errText);
                }
                var ctype = (resp.headers.get('Content-Type') || '').toLowerCase();
                if (ctype.indexOf('spreadsheetml') === -1 && ctype.indexOf('octet-stream') === -1) {
                    throw new Error('Respons server bukan berkas Excel (.xlsx). Silakan refresh halaman lalu coba lagi.');
                }
                var blob = await resp.blob();
                var disp = resp.headers.get('Content-Disposition') || '';
                var match = disp.match(/filename="?([^"]+)"?/i);
                var fname = (match && match[1]) ? match[1] : 'rekap-pelanggaran-siswa-ilmuna.xlsx';
                var dlUrl = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = dlUrl;
                a.download = fname;
                document.body.appendChild(a);
                a.click();
                setTimeout(function () {
                    URL.revokeObjectURL(dlUrl);
                    a.remove();
                }, 1500);
                if (window.Ilmuna && typeof Ilmuna.toast === 'function') {
                    Ilmuna.toast('success', 'Berhasil mengekspor ' + fname);
                } else {
                    Ilmuna.success('Berhasil', 'Berkas ' + fname + ' berhasil diunduh.');
                }
            } catch (err) {
                Ilmuna.error('Ekspor Gagal', err && err.message ? err.message : 'Gagal mengekspor Excel.');
            } finally {
                if (btnEl) {
                    btnEl.disabled = false;
                    btnEl.innerHTML = origHtml;
                }
            }
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('#pelAdmTabs .curriculum-tab-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                STATE.activeTab = btn.getAttribute('data-tab');
                document.querySelectorAll('#pelAdmTabs .curriculum-tab-btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                PEL_ADM.render();
            });
        });
        document.querySelectorAll('#admLocPills .ilmuna-pill-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                STATE.locFilter = btn.getAttribute('data-loc');
                document.querySelectorAll('#admLocPills .ilmuna-pill-btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                PEL_ADM.render();
            });
        });
        document.getElementById('admSearchInput').addEventListener('input', PEL_ADM.render);
        PEL_ADM.loadData();
    });
})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
