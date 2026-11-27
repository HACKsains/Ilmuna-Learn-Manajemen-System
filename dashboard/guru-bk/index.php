<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PORTAL GURU BK (CATATAN PELANGGARAN TERBARU, SKOR & PEMBINAAN)
 * ==============================================================================
 * Hak Akses: Role 'guru_bk' (dan 'admin' untuk pemantauan)
 * Fitur Utama Guru BK:
 *   1. Melihat catatan pelanggaran siswa terbaru dari seluruh guru (Grup WA & Realitas)
 *   2. Menentukan Tingkat & Skor Poin Pelanggaran siswa
 *   3. Mengelola alur status bertahap: Baru Dicatat → Dalam Pembinaan → Selesai
 *   4. Rekap Tabel Siswa & Ekspor Excel (.xlsx) Lumina Gold
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/../../includes/academic-sync-engine.php';

requireRole(['guru_bk', 'admin']);
$activeRole = hasRole('guru_bk') ? 'guru_bk' : 'admin';
$GLOBALS['activeRole'] = $activeRole;

if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
    require_once __DIR__ . '/../admin/catatan-pelanggaran-excel.php';
    exit;
}

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");

// Pastikan kolom panggilan orang tua tersedia di catatan_pelanggaran_siswa
try {
    $pelCols = array_column($pdo->query("SHOW COLUMNS FROM catatan_pelanggaran_siswa")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('perlu_panggilan_ortu', $pelCols, true)) {
        $pdo->exec("ALTER TABLE catatan_pelanggaran_siswa ADD COLUMN `perlu_panggilan_ortu` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status_penanganan`");
    }
    if (!in_array('jadwal_panggilan_ortu', $pelCols, true)) {
        $pdo->exec("ALTER TABLE catatan_pelanggaran_siswa ADD COLUMN `jadwal_panggilan_ortu` DATETIME NULL DEFAULT NULL AFTER `perlu_panggilan_ortu`");
    }
    if (!in_array('catatan_panggilan_ortu', $pelCols, true)) {
        $pdo->exec("ALTER TABLE catatan_pelanggaran_siswa ADD COLUMN `catatan_panggilan_ortu` VARCHAR(255) NULL DEFAULT NULL AFTER `jadwal_panggilan_ortu`");
    }
} catch (Throwable $eCol) {}

$kelasList = $pdo->query("SELECT id, nama_kelas FROM kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
$mapelList = $pdo->query("SELECT id, nama_mata_pelajaran FROM mata_pelajaran ORDER BY nama_mata_pelajaran ASC")->fetchAll(PDO::FETCH_ASSOC);
$guruList  = $pdo->query("SELECT id, nama_guru FROM guru ORDER BY nama_guru ASC")->fetchAll(PDO::FETCH_ASSOC);

/* ==============================================================================
 * AJAX JSON API (?api=1) — GURU BK
 * ============================================================================== */
if (isset($_GET['api']) && $_GET['api'] === '1') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $act = trim((string)($_POST['api_action'] ?? $_GET['api_action'] ?? 'list'));

    try {
        if ($act === 'list') {
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
                       COALESCE(g.nama_guru, u.full_name, 'Guru') AS nama_pencatat
                FROM catatan_pelanggaran_siswa p
                JOIN siswa s ON s.id = p.id_siswa
                JOIN kelas k ON k.id = p.id_kelas
                LEFT JOIN kelas_mata_pelajaran kmp ON kmp.id = p.id_kelas_mata_pelajaran
                LEFT JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
                LEFT JOIN guru g ON g.id = p.id_guru
                LEFT JOIN users u ON u.id = p.recorded_by_user_id
                {$whereSql}
                ORDER BY FIELD(p.status_penanganan, 'Baru', 'Pembinaan', 'Selesai') ASC, p.created_at DESC, p.id DESC
                LIMIT 1000
            ");
            $st->execute($bind);
            $logs = $st->fetchAll(PDO::FETCH_ASSOC);

            $cBaru = 0; $cBina = 0; $cSelesai = 0; $cWa = 0; $cReal = 0;
            $bySiswa = [];

            foreach ($logs as $r) {
                if ($r['status_penanganan'] === 'Selesai') $cSelesai++;
                elseif ($r['status_penanganan'] === 'Pembinaan') $cBina++;
                else $cBaru++;

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
                if ($r['tanggal_kejadian'] >= $bySiswa[$sid]['terakhir_tgl']) {
                    $bySiswa[$sid]['terakhir_tgl']  = $r['tanggal_kejadian'];
                    $bySiswa[$sid]['last_kategori'] = $r['kategori_pelanggaran'];
                }
            }

            $rekapSiswa = array_values($bySiswa);
            usort($rekapSiswa, function ($a, $b) {
                return ($b['total_poin'] <=> $a['total_poin']) ?: ($b['total_kasus'] <=> $a['total_kasus']);
            });

            echo json_encode([
                'success'     => true,
                'logs'        => $logs,
                'rekap_siswa' => $rekapSiswa,
                'counts'      => [
                    'all'     => count($logs),
                    'baru'    => $cBaru,
                    'binaan'  => $cBina,
                    'selesai' => $cSelesai,
                    'wa'      => $cWa,
                    'real'    => $cReal,
                    'siswa'   => count($rekapSiswa),
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Penetapan Skor Poin, Tingkat, Tindak Lanjut & Keputusan Panggilan Orang Tua oleh Guru BK
        if ($act === 'set_score') {
            $id             = (int)($_POST['id'] ?? 0);
            $tingkat        = in_array($_POST['tingkat_pelanggaran'] ?? '', ['Ringan', 'Sedang', 'Berat'], true) ? $_POST['tingkat_pelanggaran'] : 'Ringan';
            $poin           = max(0, min(100, (int)($_POST['poin'] ?? 5)));
            $tindak         = trim((string)($_POST['tindak_lanjut'] ?? ''));
            $perluPanggilan = ((int)($_POST['perlu_panggilan_ortu'] ?? 0) === 1) ? 1 : 0;
            $jadwalRaw      = trim((string)($_POST['jadwal_panggilan_ortu'] ?? ''));
            $catPanggilan   = trim((string)($_POST['catatan_panggilan_ortu'] ?? ''));
            $jadwalVal      = null;
            if ($perluPanggilan === 1 && $jadwalRaw !== '') {
                $tsJ = strtotime(str_replace('T', ' ', $jadwalRaw));
                if ($tsJ !== false) $jadwalVal = date('Y-m-d H:i:s', $tsJ);
            }
            if ($perluPanggilan === 0) {
                $jadwalVal = null;
                $catPanggilan = '';
            }

            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID catatan tidak valid.']);
                exit;
            }
            $stEx = $pdo->prepare("SELECT id, id_siswa, id_kelas_mata_pelajaran, poin, status_penanganan FROM catatan_pelanggaran_siswa WHERE id = ? LIMIT 1");
            $stEx->execute([$id]);
            $ex = $stEx->fetch(PDO::FETCH_ASSOC);
            if (!$ex) {
                echo json_encode(['success' => false, 'message' => 'Catatan pelanggaran tidak ditemukan.']);
                exit;
            }
            if ($ex['status_penanganan'] !== 'Pembinaan') {
                $msgLock = $ex['status_penanganan'] === 'Selesai'
                    ? 'Catatan yang sudah Selesai terkunci dan tidak dapat diubah lagi.'
                    : 'Atur skor hanya dapat dilakukan saat status sudah Dalam Pembinaan.';
                echo json_encode(['success' => false, 'message' => $msgLock]);
                exit;
            }

            $sidVal   = (int)$ex['id_siswa'];
            $penBefore = function_exists('ilmuna_get_pelanggaran_penalty_map')
                ? ilmuna_get_pelanggaran_penalty_map($pdo, 0, [$sidVal])
                : [];
            $oldTotalPoin = (int)($penBefore[$sidVal]['total_poin'] ?? 0);

            $pdo->prepare("
                UPDATE catatan_pelanggaran_siswa
                SET tingkat_pelanggaran = ?,
                    poin = ?,
                    tindak_lanjut = ?,
                    perlu_panggilan_ortu = ?,
                    jadwal_panggilan_ortu = ?,
                    catatan_panggilan_ortu = ?
                WHERE id = ?
            ")->execute([
                $tingkat, $poin, $tindak !== '' ? $tindak : null,
                $perluPanggilan, $jadwalVal, $catPanggilan !== '' ? $catPanggilan : null,
                $id
            ]);

            $penAfter = function_exists('ilmuna_get_pelanggaran_penalty_map')
                ? ilmuna_get_pelanggaran_penalty_map($pdo, 0, [$sidVal])
                : [];
            $newTotalPoin = (int)($penAfter[$sidVal]['total_poin'] ?? 0);

            // Otomatis kalkulasikan perubahan Indeks Kedisiplinan BK (30%) ke seluruh Penilaian Afektif siswa tsb
            if ($oldTotalPoin !== $newTotalPoin && function_exists('ilmuna_apply_pelanggaran_delta_to_afektif')) {
                ilmuna_apply_pelanggaran_delta_to_afektif($pdo, $sidVal, null, $oldTotalPoin, $newTotalPoin);
            }

            $pMsg = $perluPanggilan === 1
                ? ' Notifikasi Panggilan Orang Tua otomatis dikirim ke Portal & SSO Orang Tua.'
                : '';
            echo json_encode(['success' => true, 'message' => 'Skor pelanggaran (' . $poin . ' poin — ' . $tingkat . ') berhasil ditetapkan & disinkronkan ke Penilaian Afektif.' . $pMsg]);
            exit;
        }

        // Perubahan Status Bertahap oleh Guru BK: Baru → Pembinaan → Selesai
        if ($act === 'update_status') {
            $id = (int)($_POST['id'] ?? 0);
            $nextStatus = trim((string)($_POST['next_status'] ?? ''));
            if ($id <= 0 || !in_array($nextStatus, ['Pembinaan', 'Selesai'], true)) {
                echo json_encode(['success' => false, 'message' => 'Permintaan perubahan status tidak valid.']);
                exit;
            }
            $stEx = $pdo->prepare("SELECT id, status_penanganan, poin FROM catatan_pelanggaran_siswa WHERE id = ? LIMIT 1");
            $stEx->execute([$id]);
            $ex = $stEx->fetch(PDO::FETCH_ASSOC);
            if (!$ex) {
                echo json_encode(['success' => false, 'message' => 'Catatan pelanggaran tidak ditemukan.']);
                exit;
            }
            $cur = (string)$ex['status_penanganan'];
            $validStep = ($cur === 'Baru' && $nextStatus === 'Pembinaan') || ($cur === 'Pembinaan' && $nextStatus === 'Selesai');
            if (!$validStep) {
                echo json_encode(['success' => false, 'message' => 'Urutan status harus bertahap: Baru Dicatat → Dalam Pembinaan → Selesai.']);
                exit;
            }
            $pdo->prepare("UPDATE catatan_pelanggaran_siswa SET status_penanganan = ? WHERE id = ?")->execute([$nextStatus, $id]);
            $label = $nextStatus === 'Pembinaan' ? 'Dalam Pembinaan' : 'Selesai';
            echo json_encode(['success' => true, 'message' => 'Status penanganan diperbarui menjadi ' . $label . '.']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Kesalahan server: ' . $e->getMessage()]);
    }
    exit;
}

$pageTitle = 'Guru BK — Pelanggaran Terbaru & Pembinaan';
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
.pel-st-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.pel-st-baru { background: rgba(245, 158, 11, 0.14); color: #B45309; border: 1px solid rgba(245, 158, 11, 0.30); }
.pel-st-pembinaan { background: rgba(13, 122, 135, 0.12); color: #0D7A87; border: 1px solid rgba(13, 122, 135, 0.28); }
.pel-st-selesai { background: rgba(22, 163, 74, 0.12); color: #15803D; border: 1px solid rgba(22, 163, 74, 0.28); }
.pel-act-cell { display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }
/* Tombol Tema Lumina Gold */
.ilmuna-nav-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 36px; padding: 0 14px; border-radius: 10px; font-size: 12.5px; font-weight: 700; cursor: pointer; border: none; transition: all .18s ease; white-space: nowrap; font-family: inherit; }
.ilmuna-nav-btn--primary { background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); color: #FFFFFF; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.28); }
.ilmuna-nav-btn--primary:hover { transform: translateY(-1px); box-shadow: 0 6px 15px rgba(245, 158, 11, 0.38); }
.ilmuna-nav-btn--ghost { background: #FFFFFF; color: #1D3B5E; border: 1.5px solid #CBD5E1; }
.ilmuna-nav-btn--ghost:hover { background: #F8FAFC; border-color: #0D7A87; color: #0D7A87; }
.ilmuna-nav-btn:disabled { opacity: 0.65; cursor: not-allowed; transform: none; }
.pel-btn-score { display: inline-flex; align-items: center; gap: 5px; height: 30px; padding: 0 11px; border-radius: 8px; border: none; background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); color: #FFFFFF; font-size: 11.5px; font-weight: 700; cursor: pointer; box-shadow: 0 3px 10px rgba(245, 158, 11, 0.24); transition: all .15s ease; font-family: inherit; }
.pel-btn-score:hover { transform: translateY(-1px); box-shadow: 0 5px 14px rgba(245, 158, 11, 0.34); }
.pel-btn-step { display: inline-flex; align-items: center; gap: 5px; height: 30px; padding: 0 11px; border-radius: 8px; border: none; font-size: 11.5px; font-weight: 700; cursor: pointer; transition: all .15s ease; font-family: inherit; color: #FFFFFF; }
.pel-btn-step--binaan { background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%); box-shadow: 0 3px 10px rgba(29, 59, 94, 0.22); }
.pel-btn-step--binaan:hover { transform: translateY(-1px); box-shadow: 0 5px 14px rgba(13, 122, 135, 0.32); }
.pel-btn-step--selesai { background: linear-gradient(135deg, #0D7A87 0%, #15803D 100%); box-shadow: 0 3px 10px rgba(13, 122, 135, 0.24); }
.pel-btn-step--selesai:hover { transform: translateY(-1px); box-shadow: 0 5px 14px rgba(21, 128, 61, 0.32); }
.pel-btn-hist { display: inline-flex; align-items: center; gap: 5px; height: 32px; padding: 0 12px; border-radius: 9px; border: 1.5px solid #CBD5E1; background: #FFFFFF; color: #1D3B5E; font-size: 12px; font-weight: 700; cursor: pointer; transition: all .15s ease; font-family: inherit; }
.pel-btn-hist:hover { border-color: #0D7A87; color: #0D7A87; background: #F0FDFA; }
.pel-info-box { padding: 12px 14px; border-radius: 12px; background: #F8FAFC; border: 1px solid #E2E8F0; margin-bottom: 14px; font-size: 12.5px; color: #334155; }
.pel-info-box strong { color: #1D3B5E; }
.pel-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.pel-field { margin-bottom: 14px; }
.pel-field label { display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 6px; }
.pel-input, .pel-textarea { width: 100%; border: 1.5px solid #CBD5E1; border-radius: 10px; padding: 10px 12px; font-size: 13.5px; color: #0F172A; background: #fff; font-family: inherit; box-sizing: border-box; }
.pel-input:focus, .pel-textarea:focus { outline: none; border-color: #0D7A87; box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.12); }
.pel-textarea { min-height: 76px; resize: vertical; }
.pel-hist-item { border: 1px solid #E2E8F0; border-radius: 12px; padding: 12px 14px; margin-bottom: 10px; background: #F8FAFC; }
.pel-hist-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; margin-bottom: 6px; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
@media (max-width: 768px) { .pel-form-grid { grid-template-columns: 1fr; } }
</style>

<div class="pel-wrap">
    <!-- 1. SEGMENTED TABS -->
    <div class="curriculum-tabs-wrapper" id="bkTabs">
        <button type="button" class="curriculum-tab-btn is-active" data-tab="latest">
            <iconify-icon icon="solar:shield-warning-bold"></iconify-icon>
            <span class="curriculum-tab-label">Catatan Pelanggaran Terbaru</span>
            <span class="curriculum-tab-counter" id="bkCntAll">0</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="rekap_siswa">
            <iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon>
            <span class="curriculum-tab-label">Rekap Tabel Siswa</span>
            <span class="curriculum-tab-counter" id="bkCntSiswa">0</span>
        </button>
    </div>

    <!-- 2. FILTER & EXPORT TOOLBAR -->
    <div class="ilmuna-filter-box">
        <div class="ilmuna-filter-bottomline" style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid #E2E8F0;">
            <select id="bkFilterKelas" class="ilmuna-select" onchange="PEL_BK.loadData()">
                <option value="0">-- Semua Rombel Kelas --</option>
                <?php foreach ($kelasList as $k): ?>
                    <option value="<?= (int)$k['id'] ?>"><?= htmlspecialchars($k['nama_kelas'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <select id="bkFilterMapel" class="ilmuna-select" onchange="PEL_BK.loadData()">
                <option value="0">-- Semua Mata Pelajaran --</option>
                <?php foreach ($mapelList as $m): ?>
                    <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['nama_mata_pelajaran'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <select id="bkFilterGuru" class="ilmuna-select" onchange="PEL_BK.loadData()">
                <option value="0">-- Semua Guru Pelapor --</option>
                <?php foreach ($guruList as $g): ?>
                    <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['nama_guru'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <select id="bkFilterLokasi" class="ilmuna-select" onchange="PEL_BK.render()">
                <option value="all">-- Semua Lokasi (Grup WA &amp; Realitas) --</option>
                <option value="Grup WA">Di Grup WA</option>
                <option value="Realitas">Di Realitas (Tatap Muka)</option>
            </select>
        </div>

        <div class="ilmuna-filter-topline">
            <div class="ilmuna-pill-group" id="bkStatusPills">
                <button type="button" class="ilmuna-pill-btn is-active" data-status="all">Semua</button>
                <button type="button" class="ilmuna-pill-btn" data-status="Baru" style="color:#B45309;">Belum Dibina (<span id="bkCntBaru">0</span>)</button>
                <button type="button" class="ilmuna-pill-btn" data-status="Pembinaan" style="color:#0D7A87;">Dalam Pembinaan (<span id="bkCntBina">0</span>)</button>
                <button type="button" class="ilmuna-pill-btn" data-status="Selesai" style="color:#15803D;">Selesai (<span id="bkCntSelesai">0</span>)</button>
            </div>

            <div class="ilmuna-search-wrap">
                <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                <input type="text" id="bkSearchInput" class="ilmuna-search-input" placeholder="Cari siswa, NIS, kelas, guru pelapor, kategori, kronologi..." autocomplete="off">
            </div>

            <div class="ilmuna-filter-actions">
                <div class="ilmuna-nav-stat-inline">
                    <iconify-icon icon="solar:document-text-bold" width="14"></iconify-icon>
                    <strong id="bkKpiCount">0</strong>
                    <span id="bkKpiLabel">Catatan</span>
                </div>
                <button type="button" id="btnExportPelBk" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" onclick="PEL_BK.exportExcel(this)">
                    <iconify-icon icon="solar:file-download-bold" width="16" style="color:#10B981;"></iconify-icon>
                    <span>Ekspor Excel</span>
                </button>
            </div>
        </div>
    </div>

    <!-- 3A. TABEL CATATAN PELANGGARAN TERBARU (PENILAIAN SKOR & PEMBINAAN BK) -->
    <div class="ilm-data-container" id="bkLatestWrap">
        <div class="ilm-data-gunungan" aria-hidden="true"></div>
        <div class="ilm-table-responsive">
            <table class="ilm-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Siswa</th>
                        <th>Kelas &amp; Mapel</th>
                        <th>Lokasi</th>
                        <th>Pelanggaran &amp; Kronologi</th>
                        <th>Skor BK</th>
                        <th>Status</th>
                        <th style="text-align:right;">Aksi Guru BK</th>
                    </tr>
                </thead>
                <tbody id="bkLatestTbody">
                    <tr><td colspan="8" style="text-align:center;padding:34px;color:#64748B;">Memuat catatan pelanggaran terbaru...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 3B. TABEL REKAP PER SISWA -->
    <div class="ilm-data-container" id="bkRekapWrap" style="display:none;">
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
                        <th>Akumulasi Skor BK</th>
                        <th>Catatan Terakhir</th>
                        <th style="text-align:right;">Detail</th>
                    </tr>
                </thead>
                <tbody id="bkRekapTbody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL PENETAPAN SKOR & PEMBINAAN (GURU BK) -->
<div class="ilm-modal-backdrop" id="modalScoreBk">
    <div class="ilm-modal-dialog" style="max-width:620px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon" style="background:linear-gradient(135deg,#1D3B5E,#0D7A87);">
                    <iconify-icon icon="solar:medal-star-circle-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title">Tetapkan Skor &amp; Tindak Lanjut BK</h3>
                    <p class="ilm-modal-header__subtitle">Penilaian skor poin pelanggaran &amp; catatan pembinaan Bimbingan Konseling</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalScoreBk')" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>

        <form id="formScoreBk" onsubmit="PEL_BK.saveScore(event)">
            <input type="hidden" id="bkScoreId" value="0">
            <div class="ilm-modal-body" style="max-height:calc(84vh - 150px);overflow-y:auto;">
                <div class="pel-info-box" id="bkScoreSummary"></div>

                <div class="pel-form-grid">
                    <div class="pel-field">
                        <label for="bkScoreTingkat">Tingkat Pelanggaran</label>
                        <select id="bkScoreTingkat" class="pel-input" onchange="PEL_BK.onTingkatChange()">
                            <option value="Ringan">Ringan (Rekomendasi 5 Poin)</option>
                            <option value="Sedang">Sedang (Rekomendasi 15 Poin)</option>
                            <option value="Berat">Berat (Rekomendasi 30 Poin)</option>
                        </select>
                    </div>
                    <div class="pel-field">
                        <label for="bkScorePoin">Skor Poin Pelanggaran</label>
                        <input type="number" id="bkScorePoin" class="pel-input" min="0" max="100" value="5" required>
                    </div>
                </div>

                <div class="pel-field">
                    <label for="bkScoreTindak">Catatan Tindak Lanjut / Pembinaan BK</label>
                    <textarea id="bkScoreTindak" class="pel-textarea" placeholder="Misal: Konseling individu, surat pernyataan, pemanggilan orang tua/wali..."></textarea>
                </div>

                <div class="pel-field" style="padding:12px 14px;border-radius:12px;background:#FEF2F2;border:1.5px solid #FECACA;margin-bottom:0;">
                    <label for="bkPerluPanggilan" style="color:#991B1B;display:flex;align-items:center;gap:6px;">
                        <iconify-icon icon="solar:bell-bing-bold" width="16" style="color:#DC2626;"></iconify-icon>
                        <span>Perlu Panggilan Orang Tua / Wali?</span>
                    </label>
                    <select id="bkPerluPanggilan" class="pel-input" onchange="PEL_BK.onPanggilanChange()" style="background:#FFFFFF;">
                        <option value="0">Tidak Perlu Panggilan Orang Tua</option>
                        <option value="1">Ya — Perlu Panggilan Orang Tua (Kirim Notifikasi ke SSO &amp; Portal Ortu)</option>
                    </select>
                    <div id="bkPanggilanDetailBox" style="display:none;margin-top:10px;">
                        <div class="pel-field" style="margin-bottom:8px;">
                            <label for="bkJadwalPanggilan" style="color:#991B1B;">Jadwal Panggilan Orang Tua (Opsional)</label>
                            <div style="position:relative;">
                                <input type="text" id="bkJadwalPanggilan" class="pel-input" data-ilm-calendar="datetime" readonly placeholder="Pilih tanggal & jam panggilan..." style="cursor:pointer;background:#FFFFFF;padding-right:38px;">
                                <iconify-icon icon="solar:calendar-date-bold" onclick="document.getElementById('bkJadwalPanggilan').click()" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#0D7A87;cursor:pointer;font-size:18px;"></iconify-icon>
                            </div>
                        </div>
                        <div class="pel-field" style="margin-bottom:0;">
                            <label for="bkCatatanPanggilan" style="color:#991B1B;">Ruang / Keterangan Panggilan BK</label>
                            <input type="text" id="bkCatatanPanggilan" class="pel-input" placeholder="Misal: Ruang BK Lt. 1 — Dimohon kehadiran Ayah/Ibu/Wali" style="background:#FFFFFF;">
                        </div>
                    </div>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalScoreBk')">Batal</button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:disk-bold"></iconify-icon>
                    <span>Simpan Skor BK</span>
                </button>
            </div>
        </form>
    </div>
</div>

<?php if (function_exists('loadCentralTemplates')) { loadCentralTemplates(['calendar']); } ?>

<!-- MODAL DETAIL RIWAYAT SISWA (GURU BK) -->
<div class="ilm-modal-backdrop" id="modalDetailBk">
    <div class="ilm-modal-dialog" style="max-width:700px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon" style="background:linear-gradient(135deg,#1D3B5E,#0D7A87);">
                    <iconify-icon icon="solar:history-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="bkDetailTitle">Riwayat Pelanggaran Siswa</h3>
                    <p class="ilm-modal-header__subtitle" id="bkDetailSub">-</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalDetailBk')" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <div class="ilm-modal-body" id="bkDetailBody" style="max-height:calc(82vh - 140px);overflow-y:auto;"></div>
        <div class="ilm-modal-footer">
            <button type="button" class="ilm-modal-btn ilm-modal-btn--primary" onclick="closeIlmunaModal('modalDetailBk')">Tutup</button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var API_URL   = <?= json_encode(BASE_URL . '/dashboard/guru-bk/?api=1') ?>;
    var EXCEL_URL = <?= json_encode(BASE_URL . '/dashboard/guru-bk/') ?>;

    var STATE = {
        logs: [],
        rekapSiswa: [],
        activeTab: 'latest',
        statusFilter: 'all'
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

    function statusBadgeHtml(st) {
        if (st === 'Selesai') {
            return '<span class="pel-st-badge pel-st-selesai"><iconify-icon icon="solar:check-circle-bold" width="13"></iconify-icon>Selesai</span>';
        }
        if (st === 'Pembinaan') {
            return '<span class="pel-st-badge pel-st-pembinaan"><iconify-icon icon="solar:shield-user-bold" width="13"></iconify-icon>Dalam Pembinaan</span>';
        }
        return '<span class="pel-st-badge pel-st-baru"><iconify-icon icon="solar:clock-circle-bold" width="13"></iconify-icon>Baru Dicatat</span>';
    }

    function scoreBadgeHtml(r) {
        var p = parseInt(r.poin || 0, 10);
        if (p <= 0) {
            return '<span class="pel-badge-clean">Belum Diskor</span>';
        }
        var cls = r.tingkat_pelanggaran === 'Berat' ? 'pel-badge-berat' : (r.tingkat_pelanggaran === 'Sedang' ? 'pel-badge-sedang' : 'pel-badge-ringan');
        return '<span class="ilmuna-badge ' + cls + '">' + esc(r.tingkat_pelanggaran) + ' · ' + p + ' Poin</span>';
    }

    function bkActionsHtml(r, reopenSiswaId) {
        var sidArg = reopenSiswaId ? (', ' + parseInt(reopenSiswaId, 10)) : ', 0';
        var html = '';
        if (r.status_penanganan === 'Baru') {
            html += '<button type="button" class="pel-btn-step pel-btn-step--binaan" onclick="PEL_BK.advanceStatus(' + r.id + ', \'Pembinaan\'' + sidArg + ')"><iconify-icon icon="solar:shield-user-bold" width="14"></iconify-icon><span>Dalam Pembinaan</span></button>';
        } else if (r.status_penanganan === 'Pembinaan') {
            html += '<button type="button" class="pel-btn-score" onclick="PEL_BK.openScoreModal(' + r.id + ')"><iconify-icon icon="solar:medal-star-circle-bold" width="14"></iconify-icon><span>Atur Skor</span></button>';
            html += '<button type="button" class="pel-btn-step pel-btn-step--selesai" onclick="PEL_BK.advanceStatus(' + r.id + ', \'Selesai\'' + sidArg + ')"><iconify-icon icon="solar:check-circle-bold" width="14"></iconify-icon><span>Selesai</span></button>';
        }
        return html || '<span style="font-size:12px;color:#15803D;font-weight:700;">Tuntas</span>';
    }

    window.PEL_BK = {
        loadData: async function () {
            var k = document.getElementById('bkFilterKelas').value || '0';
            var m = document.getElementById('bkFilterMapel').value || '0';
            var g = document.getElementById('bkFilterGuru').value || '0';
            try {
                var res = await fetchJsonSafe(API_URL + '&api_action=list&id_kelas=' + encodeURIComponent(k) + '&id_mapel=' + encodeURIComponent(m) + '&id_guru=' + encodeURIComponent(g));
                if (!res) return;
                if (res.success) {
                    STATE.logs = res.logs || [];
                    STATE.rekapSiswa = res.rekap_siswa || [];
                    var c = res.counts || { all: 0, baru: 0, binaan: 0, selesai: 0, siswa: 0 };
                    document.getElementById('bkCntAll').textContent = c.all;
                    document.getElementById('bkCntSiswa').textContent = c.siswa;
                    document.getElementById('bkCntBaru').textContent = c.baru;
                    document.getElementById('bkCntBina').textContent = c.binaan;
                    document.getElementById('bkCntSelesai').textContent = c.selesai;
                    PEL_BK.render();
                } else {
                    Ilmuna.error('Gagal Memuat', (res && res.message) || 'Tidak dapat memuat catatan pelanggaran.');
                }
            } catch (e) {
                Ilmuna.error('Gagal Memuat', (e && e.message) ? e.message : 'Koneksi ke server terganggu. Silakan coba lagi.');
            }
        },
        render: function () {
            var q = (document.getElementById('bkSearchInput').value || '').toLowerCase().trim();
            var locF = document.getElementById('bkFilterLokasi').value || 'all';
            var isRekap = (STATE.activeTab === 'rekap_siswa');
            document.getElementById('bkLatestWrap').style.display = isRekap ? 'none' : '';
            document.getElementById('bkRekapWrap').style.display = isRekap ? '' : 'none';

            if (isRekap) {
                var rList = STATE.rekapSiswa.filter(function (s) {
                    if (locF === 'Grup WA' && s.kasus_wa <= 0) return false;
                    if (locF === 'Realitas' && s.kasus_real <= 0) return false;
                    if (q) {
                        var hay = ((s.nama_siswa || '') + ' ' + (s.nis || '') + ' ' + (s.nama_kelas || '') + ' ' + (s.last_kategori || '')).toLowerCase();
                        if (hay.indexOf(q) === -1) return false;
                    }
                    return true;
                });
                document.getElementById('bkKpiCount').textContent = rList.length;
                document.getElementById('bkKpiLabel').textContent = 'Siswa';
                var rtb = document.getElementById('bkRekapTbody');
                if (!rList.length) {
                    rtb.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:34px;color:#64748B;">Belum ada data rekap siswa pada filter ini.</td></tr>';
                    return;
                }
                rtb.innerHTML = rList.map(function (s, idx) {
                    var waBadge = s.kasus_wa > 0 ? '<span class="pel-badge-wa">' + s.kasus_wa + ' Kasus</span>' : '<span class="pel-badge-clean">0</span>';
                    var realBadge = s.kasus_real > 0 ? '<span class="pel-badge-real">' + s.kasus_real + ' Kasus</span>' : '<span class="pel-badge-clean">0</span>';
                    var pCls = s.total_poin >= 50 ? 'pel-badge-berat' : (s.total_poin >= 20 ? 'pel-badge-sedang' : (s.total_poin > 0 ? 'pel-badge-ringan' : ''));
                    var poinHtml = s.total_poin > 0 ? '<span class="ilmuna-badge ' + pCls + '">' + s.total_poin + ' Poin</span>' : '<span class="pel-badge-clean">0 Poin</span>';
                    return '<tr>'
                        + '<td data-label="No">' + (idx + 1) + '</td>'
                        + '<td data-label="Siswa"><strong>' + esc(s.nama_siswa) + '</strong><div style="font-size:11px;color:#64748B;">NIS: ' + esc(s.nis || '-') + '</div></td>'
                        + '<td data-label="Kelas"><strong>' + esc(s.nama_kelas) + '</strong></td>'
                        + '<td data-label="Grup WA">' + waBadge + '</td>'
                        + '<td data-label="Realitas">' + realBadge + '</td>'
                        + '<td data-label="Total"><strong>' + s.total_kasus + ' Kasus</strong></td>'
                        + '<td data-label="Skor BK">' + poinHtml + '</td>'
                        + '<td data-label="Terakhir"><strong>' + esc(s.last_kategori) + '</strong><div style="font-size:11px;color:#64748B;">' + esc(s.terakhir_tgl) + '</div></td>'
                        + '<td data-label="Detail" style="text-align:right;"><button type="button" class="pel-btn-hist" onclick="PEL_BK.openDetail(' + s.id_siswa + ')"><iconify-icon icon="solar:eye-bold" width="14"></iconify-icon><span>Riwayat (' + s.total_kasus + ')</span></button></td>'
                        + '</tr>';
                }).join('');
                return;
            }

            var list = STATE.logs.filter(function (x) {
                if (STATE.statusFilter !== 'all' && x.status_penanganan !== STATE.statusFilter) return false;
                if (locF !== 'all' && x.lokasi_kejadian !== locF) return false;
                if (q) {
                    var hay = ((x.nama_siswa || '') + ' ' + (x.nis || '') + ' ' + (x.nama_kelas || '') + ' ' + (x.nama_mapel || '') + ' ' + (x.nama_pencatat || '') + ' ' + (x.kategori_pelanggaran || '') + ' ' + (x.kronologi || '')).toLowerCase();
                    if (hay.indexOf(q) === -1) return false;
                }
                return true;
            });
            document.getElementById('bkKpiCount').textContent = list.length;
            document.getElementById('bkKpiLabel').textContent = 'Catatan';
            var tb = document.getElementById('bkLatestTbody');
            if (!list.length) {
                tb.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:34px;color:#64748B;">Belum ada catatan pelanggaran pada filter ini.</td></tr>';
                return;
            }
            tb.innerHTML = list.map(function (r) {
                var locBadge = r.lokasi_kejadian === 'Grup WA'
                    ? '<span class="pel-badge-wa"><iconify-icon icon="solar:chat-round-dots-bold" width="13"></iconify-icon>Grup WA</span>'
                    : '<span class="pel-badge-real"><iconify-icon icon="solar:users-group-rounded-bold" width="13"></iconify-icon>Realitas</span>';
                return '<tr>'
                    + '<td data-label="Tanggal"><strong>' + esc(r.tanggal_kejadian) + '</strong><div style="font-size:11px;color:#64748B;">' + esc(r.waktu_kejadian ? String(r.waktu_kejadian).slice(0, 5) + ' WIB' : '-') + '</div></td>'
                    + '<td data-label="Siswa"><strong>' + esc(r.nama_siswa) + '</strong><div style="font-size:11px;color:#64748B;">NIS: ' + esc(r.nis || '-') + '</div></td>'
                    + '<td data-label="Kelas & Mapel"><strong>' + esc(r.nama_kelas) + '</strong><div style="font-size:11.5px;color:#0D7A87;">' + esc(r.nama_mapel) + '</div><div style="font-size:11px;color:#64748B;">Pelapor: ' + esc(r.nama_pencatat) + '</div></td>'
                    + '<td data-label="Lokasi">' + locBadge + '</td>'
                    + '<td data-label="Pelanggaran"><strong>' + esc(r.kategori_pelanggaran) + '</strong><div style="font-size:12px;color:#475569;margin-top:2px;">' + esc(r.kronologi) + '</div>'
                        + (r.tindak_lanjut ? '<div style="font-size:11.5px;color:#0D7A87;margin-top:2px;"><strong>Pembinaan BK:</strong> ' + esc(r.tindak_lanjut) + '</div>' : '')
                        + (parseInt(r.perlu_panggilan_ortu || 0, 10) === 1
                            ? '<div style="margin-top:5px;display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;background:#FEF2F2;color:#DC2626;border:1px solid #FECACA;font-size:11px;font-weight:800;"><iconify-icon icon="solar:bell-bing-bold" width="13"></iconify-icon><span>Perlu Panggilan Ortu' + (r.jadwal_panggilan_ortu ? ' · ' + esc(String(r.jadwal_panggilan_ortu).slice(0, 16)) : '') + '</span></div>'
                            : '') + '</td>'
                    + '<td data-label="Skor BK">' + scoreBadgeHtml(r) + '</td>'
                    + '<td data-label="Status">' + statusBadgeHtml(r.status_penanganan) + '</td>'
                    + '<td data-label="Aksi BK" style="text-align:right;"><div class="pel-act-cell">' + bkActionsHtml(r, 0) + '</div></td>'
                    + '</tr>';
            }).join('');
        },
        onTingkatChange: function () {
            var v = document.getElementById('bkScoreTingkat').value;
            var map = { 'Ringan': 5, 'Sedang': 15, 'Berat': 30 };
            if (map[v] !== undefined) document.getElementById('bkScorePoin').value = map[v];
        },
        onPanggilanChange: function () {
            var v = document.getElementById('bkPerluPanggilan').value;
            document.getElementById('bkPanggilanDetailBox').style.display = (v === '1') ? 'block' : 'none';
        },
        openScoreModal: function (id) {
            var r = STATE.logs.find(function (x) { return parseInt(x.id, 10) === parseInt(id, 10); });
            if (!r) return;
            document.getElementById('bkScoreId').value = String(r.id);
            document.getElementById('bkScoreTingkat').value = r.tingkat_pelanggaran || 'Ringan';
            var curPoin = parseInt(r.poin || 0, 10);
            document.getElementById('bkScorePoin').value = curPoin > 0 ? curPoin : 5;
            document.getElementById('bkScoreTindak').value = r.tindak_lanjut || '';
            var needCall = parseInt(r.perlu_panggilan_ortu || 0, 10) === 1 ? '1' : '0';
            document.getElementById('bkPerluPanggilan').value = needCall;
            document.getElementById('bkJadwalPanggilan').value = r.jadwal_panggilan_ortu ? String(r.jadwal_panggilan_ortu).slice(0, 16) : '';
            document.getElementById('bkCatatanPanggilan').value = r.catatan_panggilan_ortu || '';
            PEL_BK.onPanggilanChange();
            document.getElementById('bkScoreSummary').innerHTML =
                '<div style="font-weight:700;font-size:14px;color:#1D3B5E;margin-bottom:4px;">' + esc(r.nama_siswa) + ' · ' + esc(r.nama_kelas) + '</div>'
                + '<div><strong>Mapel / Pelapor:</strong> ' + esc(r.nama_mapel) + ' (' + esc(r.nama_pencatat) + ')</div>'
                + '<div><strong>Lokasi &amp; Waktu:</strong> ' + esc(r.lokasi_kejadian) + ' · ' + esc(r.tanggal_kejadian) + (r.waktu_kejadian ? ' ' + esc(String(r.waktu_kejadian).slice(0, 5)) + ' WIB' : '') + '</div>'
                + '<div style="margin-top:6px;"><strong>Kategori:</strong> ' + esc(r.kategori_pelanggaran) + '</div>'
                + '<div><strong>Kronologi:</strong> ' + esc(r.kronologi) + '</div>'
                + (r.bukti_keterangan ? '<div><strong>Bukti:</strong> ' + esc(r.bukti_keterangan) + '</div>' : '');
            closeIlmunaModal('modalDetailBk');
            openIlmunaModal('modalScoreBk');
        },
        saveScore: async function (e) {
            e.preventDefault();
            var fd = new FormData();
            fd.append('api_action', 'set_score');
            fd.append('id', document.getElementById('bkScoreId').value);
            fd.append('tingkat_pelanggaran', document.getElementById('bkScoreTingkat').value);
            fd.append('poin', document.getElementById('bkScorePoin').value);
            fd.append('tindak_lanjut', document.getElementById('bkScoreTindak').value.trim());
            fd.append('perlu_panggilan_ortu', document.getElementById('bkPerluPanggilan').value);
            fd.append('jadwal_panggilan_ortu', document.getElementById('bkJadwalPanggilan').value.trim());
            fd.append('catatan_panggilan_ortu', document.getElementById('bkCatatanPanggilan').value.trim());
            try {
                var res = await fetchJsonSafe(API_URL, { method: 'POST', body: fd });
                if (!res) return;
                if (res.success) {
                    closeIlmunaModal('modalScoreBk');
                    Ilmuna.success('Skor Disimpan', res.message);
                    PEL_BK.loadData();
                } else {
                    Ilmuna.error('Gagal', (res && res.message) || 'Gagal menyimpan skor BK.');
                }
            } catch (err) {
                Ilmuna.error('Kesalahan', (err && err.message) ? err.message : 'Gagal menghubungi server.');
            }
        },
        advanceStatus: async function (id, nextStatus, reopenSiswaId) {
            var label = nextStatus === 'Pembinaan' ? 'Dalam Pembinaan' : 'Selesai';
            var ok = await Ilmuna.confirm(
                'info',
                'Tindak Lanjut BK: ' + label + '?',
                'Ubah status penanganan pelanggaran ini menjadi "' + label + '"?',
                'Ya, ' + label,
                'Batal'
            );
            if (!ok) return;
            var fd = new FormData();
            fd.append('api_action', 'update_status');
            fd.append('id', id);
            fd.append('next_status', nextStatus);
            try {
                var res = await fetchJsonSafe(API_URL, { method: 'POST', body: fd });
                if (!res) return;
                if (res.success) {
                    Ilmuna.success('Status Diperbarui', res.message);
                    await PEL_BK.loadData();
                    if (reopenSiswaId) {
                        PEL_BK.openDetail(reopenSiswaId);
                    }
                } else {
                    Ilmuna.error('Gagal', (res && res.message) || 'Gagal memperbarui status.');
                }
            } catch (err) {
                Ilmuna.error('Kesalahan', (err && err.message) ? err.message : 'Gagal menghubungi server.');
            }
        },
        openDetail: function (siswaId) {
            var s = STATE.rekapSiswa.find(function (x) { return parseInt(x.id_siswa, 10) === parseInt(siswaId, 10); });
            if (!s) return;
            var list = STATE.logs.filter(function (x) { return parseInt(x.id_siswa, 10) === parseInt(siswaId, 10); });
            document.getElementById('bkDetailTitle').textContent = s.nama_siswa + (s.nis ? ' (NIS: ' + s.nis + ')' : '');
            document.getElementById('bkDetailSub').textContent = s.nama_kelas + ' · Total ' + s.total_kasus + ' Kasus (' + s.total_poin + ' Poin BK)';
            var body = document.getElementById('bkDetailBody');
            body.innerHTML = list.map(function (r) {
                var locBadge = r.lokasi_kejadian === 'Grup WA'
                    ? '<span class="pel-badge-wa">Grup WA</span>'
                    : '<span class="pel-badge-real">Realitas</span>';
                return '<div class="pel-hist-item">'
                    + '<div class="pel-hist-head">'
                        + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
                            + locBadge + scoreBadgeHtml(r) + statusBadgeHtml(r.status_penanganan)
                            + '<span style="font-size:12px;color:#64748B;">' + esc(r.tanggal_kejadian) + '</span>'
                        + '</div>'
                        + '<div class="pel-act-cell">' + bkActionsHtml(r, s.id_siswa) + '</div>'
                    + '</div>'
                    + '<div style="font-weight:700;color:#0F172A;font-size:13.5px;">' + esc(r.kategori_pelanggaran) + '</div>'
                    + '<div style="font-size:12.5px;color:#475569;margin-top:4px;">' + esc(r.kronologi) + '</div>'
                    + '<div style="font-size:11.5px;color:#64748B;margin-top:4px;">Mapel: ' + esc(r.nama_mapel) + ' · Pelapor: ' + esc(r.nama_pencatat) + '</div>'
                    + (r.tindak_lanjut ? '<div style="font-size:12px;color:#0D7A87;margin-top:4px;"><strong>Pembinaan BK:</strong> ' + esc(r.tindak_lanjut) + '</div>' : '')
                    + '</div>';
            }).join('');
            openIlmunaModal('modalDetailBk');
        },
        exportExcel: async function (btnEl) {
            var params = new URLSearchParams();
            params.set('action', 'export_excel');
            var k = document.getElementById('bkFilterKelas').value || '0';
            var m = document.getElementById('bkFilterMapel').value || '0';
            var g = document.getElementById('bkFilterGuru').value || '0';
            var loc = document.getElementById('bkFilterLokasi').value || 'all';
            var q = (document.getElementById('bkSearchInput').value || '').trim();
            if (k !== '0') params.set('id_kelas', k);
            if (m !== '0') params.set('id_mapel', m);
            if (g !== '0') params.set('id_guru', g);
            if (loc !== 'all') params.set('lokasi', loc);
            if (STATE.statusFilter !== 'all') params.set('status', STATE.statusFilter);
            if (q) params.set('search', q);

            var origHtml = btnEl ? btnEl.innerHTML : '';
            if (btnEl) {
                btnEl.disabled = true;
                btnEl.innerHTML = '<iconify-icon icon="solar:refresh-bold" width="16" style="animation:spin 1s linear infinite;"></iconify-icon><span>Mengekspor...</span>';
            }
            try {
                var resp = await fetch(EXCEL_URL + '?' + params.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!resp.ok) throw new Error('Gagal mengekspor berkas Excel.');
                var ctype = (resp.headers.get('Content-Type') || '').toLowerCase();
                if (ctype.indexOf('spreadsheetml') === -1 && ctype.indexOf('octet-stream') === -1) {
                    throw new Error('Respons server bukan berkas Excel (.xlsx).');
                }
                var blob = await resp.blob();
                var disp = resp.headers.get('Content-Disposition') || '';
                var match = disp.match(/filename="?([^"]+)"?/i);
                var fname = (match && match[1]) ? match[1] : 'rekap-pelanggaran-bk-ilmuna.xlsx';
                var dlUrl = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = dlUrl;
                a.download = fname;
                document.body.appendChild(a);
                a.click();
                setTimeout(function () { URL.revokeObjectURL(dlUrl); a.remove(); }, 1500);
                if (window.Ilmuna && typeof Ilmuna.toast === 'function') {
                    Ilmuna.toast('success', 'Berhasil mengekspor ' + fname);
                } else {
                    Ilmuna.success('Berhasil', 'Berkas ' + fname + ' berhasil diunduh.');
                }
            } catch (err) {
                Ilmuna.error('Ekspor Gagal', err && err.message ? err.message : 'Gagal mengekspor Excel.');
            } finally {
                if (btnEl) { btnEl.disabled = false; btnEl.innerHTML = origHtml; }
            }
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('#bkTabs .curriculum-tab-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                STATE.activeTab = btn.getAttribute('data-tab');
                document.querySelectorAll('#bkTabs .curriculum-tab-btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                PEL_BK.render();
            });
        });
        document.querySelectorAll('#bkStatusPills .ilmuna-pill-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                STATE.statusFilter = btn.getAttribute('data-status');
                document.querySelectorAll('#bkStatusPills .ilmuna-pill-btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                PEL_BK.render();
            });
        });
        document.getElementById('bkSearchInput').addEventListener('input', PEL_BK.render);
        PEL_BK.loadData();
    });
})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
