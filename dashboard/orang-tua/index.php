<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PORTAL MONITORING ORANG TUA / WALI (MULTI-ANAK)
 * ==============================================================================
 * Hak Akses: Role 'orang_tua' (dan 'admin' untuk pratinjau)
 * Fitur Utama:
 *   1. Multi-Anak Switcher (`orang_tua_siswa`): 1 akun orang tua dapat memantau
 *      1, 2, atau lebih anak di sekolah yang sama tanpa pindah akun.
 *   2. Monitoring Lengkap Per Anak (100% AJAX Lazy Load, Zero Page Reload):
 *      - Nilai Mata Pelajaran (Kognitif, Afektif, Psikomotorik, Nilai Akhir)
 *      - Tugas (Status pengumpulan, tenggat waktu, nilai)
 *      - Proyek & P5 (Kelompok, status pengumpulan, nilai)
 *      - Absensi & Kehadiran (Rekap H/I/S/A/T, persentase, log terbaru)
 *      - Catatan Pelanggaran & Indeks Disiplin BK (Grup WA & Realitas, skor, pembinaan)
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

requireRole(['orang_tua', 'admin']);
$activeRole = hasRole('orang_tua') ? 'orang_tua' : 'admin';
$GLOBALS['activeRole'] = $activeRole;

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");

$currentUser  = getCurrentUser();
$idUserLogged = (int)($currentUser['id'] ?? 0);
$isStrictOrtu = ($activeRole === 'orang_tua' || !hasRole('admin'));

// Cari profil orang_tua dan daftar anak yang terhubung di orang_tua_siswa
$ortuProfile = null;
$childrenList = [];

try {
    if ($isStrictOrtu) {
        $stO = $pdo->prepare("SELECT * FROM orang_tua WHERE id_user = ? LIMIT 1");
        $stO->execute([$idUserLogged]);
        $ortuProfile = $stO->fetch(PDO::FETCH_ASSOC);

        if (!$ortuProfile && !empty($currentUser['email'])) {
            $stO2 = $pdo->prepare("SELECT * FROM orang_tua WHERE LOWER(email_gmail) = LOWER(?) LIMIT 1");
            $stO2->execute([trim((string)$currentUser['email'])]);
            $ortuProfile = $stO2->fetch(PDO::FETCH_ASSOC);
        }

        if ($ortuProfile) {
            $stC = $pdo->prepare("
                SELECT s.id AS id_siswa, s.nis, s.nisn, s.nama_lengkap, s.id_kelas,
                       COALESCE(k.nama_kelas, '-') AS nama_kelas, ots.hubungan
                FROM orang_tua_siswa ots
                JOIN siswa s ON s.id = ots.id_siswa
                LEFT JOIN kelas k ON k.id = s.id_kelas
                WHERE ots.id_orang_tua = ?
                ORDER BY ots.is_primary DESC, k.nama_kelas ASC, s.nama_lengkap ASC
            ");
            $stC->execute([(int)$ortuProfile['id']]);
            $childrenList = $stC->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        // Admin preview: tampilkan daftar siswa yang sudah terhubung orang tua (atau 25 siswa pertama)
        $childrenList = $pdo->query("
            SELECT s.id AS id_siswa, s.nis, s.nisn, s.nama_lengkap, s.id_kelas,
                   COALESCE(k.nama_kelas, '-') AS nama_kelas, 'Pratinjau Admin' AS hubungan
            FROM siswa s
            LEFT JOIN kelas k ON k.id = s.id_kelas
            ORDER BY k.nama_kelas ASC, s.nama_lengkap ASC
            LIMIT 30
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $eInit) {}

$allowedChildMap = [];
foreach ($childrenList as $ch) {
    $allowedChildMap[(int)$ch['id_siswa']] = $ch;
}

/* ==============================================================================
 * AJAX JSON API (?api=1) — MONITORING LENGKAP 1 ANAK
 * ============================================================================== */
if (isset($_GET['api']) && $_GET['api'] === '1') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $idSiswa = (int)($_GET['id_siswa'] ?? 0);
        if ($idSiswa <= 0 || !isset($allowedChildMap[$idSiswa])) {
            echo json_encode(['success' => false, 'message' => 'Data anak tidak ditemukan atau bukan anak yang terhubung ke akun Anda.']);
            exit;
        }
        $child   = $allowedChildMap[$idSiswa];
        $idKelas = (int)($child['id_kelas'] ?? 0);

        // 1. NILAI PER MATA PELAJARAN
        $nilaiRows = [];
        try {
            $stN = $pdo->prepare("
                SELECT mp.nama_mata_pelajaran, mp.kode_mapel,
                       r.rata_kognitif, r.rata_afektif, r.predikat_afektif,
                       r.rata_psikomotorik, r.predikat_psikomotorik,
                       r.nilai_akhir, r.predikat_akhir
                FROM rekap_nilai_siswa_mapel r
                JOIN kelas_mata_pelajaran kmp ON kmp.id = r.id_kelas_mata_pelajaran
                JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
                WHERE r.id_siswa = ?
                ORDER BY mp.nama_mata_pelajaran ASC
            ");
            $stN->execute([$idSiswa]);
            $nilaiRows = $stN->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $eN) {}

        // Fallback jika rekap_nilai_siswa_mapel kosong: ambil dari nilai_asesmen_afektif & psikomotorik
        if (empty($nilaiRows) && $idKelas > 0) {
            try {
                $stN2 = $pdo->prepare("
                    SELECT mp.nama_mata_pelajaran, mp.kode_mapel,
                           NULL AS rata_kognitif,
                           (SELECT ROUND(AVG(na.nilai_angka), 1) FROM nilai_asesmen_afektif na WHERE na.id_siswa = ? AND na.id_kelas_mata_pelajaran = kmp.id) AS rata_afektif,
                           NULL AS predikat_afektif,
                           (SELECT ROUND(AVG(np.nilai_angka), 1) FROM nilai_asesmen_psikomotorik np WHERE np.id_siswa = ? AND np.id_kelas_mata_pelajaran = kmp.id) AS rata_psikomotorik,
                           NULL AS predikat_psikomotorik,
                           NULL AS nilai_akhir,
                           NULL AS predikat_akhir
                    FROM kelas_mata_pelajaran kmp
                    JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
                    WHERE kmp.id_kelas = ?
                    ORDER BY mp.nama_mata_pelajaran ASC
                ");
                $stN2->execute([$idSiswa, $idSiswa, $idKelas]);
                $nilaiRows = $stN2->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $eN2) {}
        }

        // 2. TUGAS SISWA
        $tugasRows = [];
        try {
            $stT = $pdo->prepare("
                SELECT t.id, t.judul_tugas, t.tenggat_waktu, mp.nama_mata_pelajaran,
                       pt.status_pengumpulan, pt.waktu_pengumpulan,
                       (SELECT nt.nilai FROM nilai_tugas nt WHERE nt.id_tugas = t.id AND nt.id_siswa = ? ORDER BY nt.id DESC LIMIT 1) AS nilai_tugas
                FROM tugas t
                JOIN kelas_mata_pelajaran kmp ON kmp.id = t.id_kelas_mata_pelajaran
                JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
                LEFT JOIN pengumpulan_tugas pt ON pt.id_tugas = t.id AND pt.id_siswa = ?
                WHERE kmp.id_kelas = ?
                ORDER BY t.tenggat_waktu DESC, t.id DESC
                LIMIT 100
            ");
            $stT->execute([$idSiswa, $idSiswa, $idKelas]);
            $tugasRows = $stT->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $eT) {}

        // 3. PROYEK & P5
        $proyekRows = [];
        try {
            $stP = $pdo->prepare("
                SELECT p.id, p.judul_proyek, p.tenggat_waktu, mp.nama_mata_pelajaran,
                       (SELECT pk.nama_kelompok FROM proyek_kelompok pk
                        JOIN proyek_kelompok_anggota pka ON pka.id_proyek_kelompok = pk.id
                        WHERE pk.id_proyek = p.id AND pka.id_siswa = ? LIMIT 1) AS nama_kelompok,
                       (SELECT pp.status_pengumpulan FROM pengumpulan_proyek pp
                        WHERE pp.id_proyek = p.id AND pp.id_siswa = ? ORDER BY pp.id DESC LIMIT 1) AS status_pengumpulan,
                       (SELECT pp.nilai FROM pengumpulan_proyek pp
                        WHERE pp.id_proyek = p.id AND pp.id_siswa = ? ORDER BY pp.id DESC LIMIT 1) AS nilai_proyek
                FROM proyek p
                JOIN kelas_mata_pelajaran kmp ON kmp.id = p.id_kelas_mata_pelajaran
                JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
                WHERE kmp.id_kelas = ?
                ORDER BY p.tenggat_waktu DESC, p.id DESC
                LIMIT 60
            ");
            $stP->execute([$idSiswa, $idSiswa, $idSiswa, $idKelas]);
            $proyekRows = $stP->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $eP) {}

        // 4. ABSENSI & KEHADIRAN
        $absSummary = ['H' => 0, 'I' => 0, 'S' => 0, 'A' => 0, 'T' => 0, 'total' => 0, 'persen' => 100];
        $absLogs = [];
        try {
            $stA = $pdo->prepare("
                SELECT a.pertemuan_ke, a.status, a.metode_absen, a.waktu_absen, a.keterangan,
                       COALESCE(mp.nama_mata_pelajaran, 'Umum') AS nama_mapel
                FROM absensi a
                LEFT JOIN kelas_mata_pelajaran kmp ON kmp.id = a.id_kelas_mata_pelajaran
                LEFT JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
                WHERE a.id_siswa = ?
                ORDER BY a.waktu_absen DESC, a.id DESC
                LIMIT 120
            ");
            $stA->execute([$idSiswa]);
            $absLogs = $stA->fetchAll(PDO::FETCH_ASSOC);
            foreach ($absLogs as $al) {
                $stCode = strtoupper((string)($al['status'] ?? ''));
                if (isset($absSummary[$stCode])) $absSummary[$stCode]++;
                $absSummary['total']++;
            }
            if ($absSummary['total'] > 0) {
                $validH = $absSummary['H'] + $absSummary['T'] + $absSummary['I'] + $absSummary['S'];
                $absSummary['persen'] = round(($validH / $absSummary['total']) * 100, 1);
            }
        } catch (Throwable $eA) {}

        // 5. CATATAN PELANGGARAN & INDEKS DISIPLIN BK
        $pelLogs = [];
        $pelSummary = ['total_kasus' => 0, 'wa' => 0, 'realitas' => 0, 'total_poin' => 0, 'disiplin_bk' => 100];
        try {
            $stPel = $pdo->prepare("
                SELECT p.*,
                       COALESCE(mp.nama_mata_pelajaran, 'Umum / Non-Mapel') AS nama_mapel,
                       COALESCE(g.nama_guru, u.full_name, 'Guru') AS nama_pencatat
                FROM catatan_pelanggaran_siswa p
                LEFT JOIN kelas_mata_pelajaran kmp ON kmp.id = p.id_kelas_mata_pelajaran
                LEFT JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
                LEFT JOIN guru g ON g.id = p.id_guru
                LEFT JOIN users u ON u.id = p.recorded_by_user_id
                WHERE p.id_siswa = ?
                ORDER BY p.tanggal_kejadian DESC, p.id DESC
            ");
            $stPel->execute([$idSiswa]);
            $pelLogs = $stPel->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pelLogs as $pl) {
                $pelSummary['total_kasus']++;
                if (($pl['lokasi_kejadian'] ?? '') === 'Grup WA') $pelSummary['wa']++;
                else $pelSummary['realitas']++;
                $pelSummary['total_poin'] += (int)($pl['poin'] ?? 0);
            }
            $pelSummary['disiplin_bk'] = max(0, 100 - $pelSummary['total_poin']);
        } catch (Throwable $ePel) {}

        // 6. PERIZINAN SISWA (IZIN / SAKIT & STATUS PERSETUJUAN)
        $izinLogs = [];
        $izinSummary = ['total' => 0, 'disetujui' => 0, 'menunggu' => 0, 'ditolak' => 0];
        try {
            $stIz = $pdo->prepare("
                SELECT pz.*,
                       COALESCE(mp.nama_mata_pelajaran, 'Umum / Harian') AS nama_mapel,
                       jp.pertemuan_ke
                FROM perizinan pz
                LEFT JOIN kelas_mata_pelajaran kmp ON pz.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN jadwal_pelajaran jp ON pz.id_jadwal = jp.id
                WHERE pz.id_siswa = ?
                ORDER BY pz.tanggal_izin DESC, pz.id DESC
                LIMIT 100
            ");
            $stIz->execute([$idSiswa]);
            $izinLogs = $stIz->fetchAll(PDO::FETCH_ASSOC);
            foreach ($izinLogs as $iz) {
                $izinSummary['total']++;
                $sp = (string)($iz['status_perizinan'] ?? '');
                if (stripos($sp, 'Disetujui') !== false || ($iz['approval_guru'] ?? '') === 'Approved') {
                    $izinSummary['disetujui']++;
                } elseif (stripos($sp, 'Ditolak') !== false || ($iz['approval_guru'] ?? '') === 'Rejected') {
                    $izinSummary['ditolak']++;
                } else {
                    $izinSummary['menunggu']++;
                }
            }
        } catch (Throwable $eIz) {}

        echo json_encode([
            'success'     => true,
            'child'       => $child,
            'nilai'       => $nilaiRows,
            'tugas'       => $tugasRows,
            'proyek'      => $proyekRows,
            'absensi'     => ['summary' => $absSummary, 'logs' => $absLogs],
            'perizinan'   => ['summary' => $izinSummary, 'logs' => $izinLogs],
            'pelanggaran' => ['summary' => $pelSummary, 'logs' => $pelLogs],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Kesalahan server: ' . $e->getMessage()]);
    }
    exit;
}

$pageTitle = 'Monitoring Anak — Portal Orang Tua';
$pageIcon  = 'solar:users-group-two-rounded-bold';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/table-template.php';
require_once BASE_PATH . '/includes/modal-template.php';
?>
<style>
.ortu-mon-wrap { width: 100%; max-width: 100%; margin: 0 auto; }
/* Standar Tab Konsisten README.md Section 4.1 (ala curriculum.php) */
.curriculum-main-tabs { display: flex; align-items: center; gap: 8px; background: #F1F5F9; border: 1px solid #E2E8F0; border-radius: 14px; padding: 6px; margin-bottom: 14px; overflow-x: auto; scrollbar-width: none; }
.curriculum-main-tabs::-webkit-scrollbar { display: none; }
.curriculum-tab-btn { display: inline-flex; align-items: center; gap: 8px; padding: 9px 15px; border-radius: 10px; border: 1px solid transparent; background: transparent; font-size: 13px; font-weight: 700; color: #475569; cursor: pointer; white-space: nowrap; transition: all .18s ease; font-family: inherit; }
.curriculum-tab-btn:hover { color: #1D3B5E; background: rgba(255, 255, 255, 0.65); }
.curriculum-tab-btn.is-active { background: #FFFFFF; color: #0D7A87; border-color: #CBD5E1; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08); }
.curriculum-tab-counter { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 20px; padding: 0 7px; border-radius: 10px; font-size: 11px; font-weight: 800; background: #E2E8F0; color: #475569; }
.curriculum-tab-btn.is-active .curriculum-tab-counter { background: #CCFBF1; color: #0F766E; }
/* Standar Filter & Action Toolbar README.md Section 4.2 (ala assessment-access.php) */
.ilmuna-filter-box { background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 16px; padding: 12px 14px; margin-bottom: 14px; box-shadow: 0 4px 16px -4px rgba(29, 59, 94, 0.05); }
.ilmuna-filter-topline { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.ilmuna-pill-group { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.ilmuna-pill-btn { display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px; border-radius: 10px; border: 1.5px solid #E2E8F0; background: #F8FAFC; color: #334155; font-size: 12px; font-weight: 700; cursor: pointer; transition: all .15s ease; font-family: inherit; }
.ilmuna-pill-btn:hover { border-color: #0D7A87; color: #0D7A87; }
.ilmuna-pill-btn.is-active { background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%); border-color: #1D3B5E; color: #FFFFFF; box-shadow: 0 3px 10px rgba(29, 59, 94, 0.20); }
.ilmuna-search-wrap { position: relative; flex: 1; min-width: 200px; }
.ilmuna-search-wrap iconify-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #64748B; font-size: 16px; pointer-events: none; }
.ilmuna-search-input { width: 100%; height: 36px; border: 1.5px solid #CBD5E1; border-radius: 10px; padding: 0 12px 0 34px; font-size: 13px; color: #0F172A; background: #FFFFFF; font-family: inherit; box-sizing: border-box; }
.ilmuna-search-input:focus { outline: none; border-color: #0D7A87; box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.12); }
.ilmuna-filter-actions { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ilmuna-nav-stat-inline { display: inline-flex; align-items: center; gap: 5px; height: 34px; padding: 0 11px; border-radius: 10px; background: #F8FAFC; border: 1px solid #E2E8F0; font-size: 12px; color: #475569; white-space: nowrap; }
.ilmuna-nav-stat-inline strong { color: #1D3B5E; font-weight: 800; }
.ortu-b-ok { background: rgba(16, 185, 129, 0.14); color: #047857; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
.ortu-b-warn { background: rgba(245, 158, 11, 0.16); color: #B45309; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
.ortu-b-err { background: rgba(239, 68, 68, 0.14); color: #DC2626; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
.ortu-b-info { background: rgba(13, 122, 135, 0.14); color: #0D7A87; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
.ortu-izin-btn { display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 10px; border-radius: 8px; font-size: 11.5px; font-weight: 700; cursor: pointer; text-decoration: none; transition: all .15s ease; font-family: inherit; }
.ortu-izin-btn--bukti { border: none; background: linear-gradient(135deg, #1D3B5E, #0D7A87); color: #FFFFFF; }
.ortu-izin-btn--bukti:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(13, 122, 135, 0.28); }
.ortu-izin-btn--map { border: 1.5px solid #CBD5E1; background: #FFFFFF; color: #1D3B5E; }
.ortu-izin-btn--map:hover { border-color: #0D7A87; color: #0D7A87; background: #F0FDFA; }
</style>

<div class="ortu-mon-wrap">
    <?php if (empty($childrenList)): ?>
        <div class="ilm-data-container" style="padding:40px 24px;text-align:center;">
            <iconify-icon icon="solar:users-group-two-rounded-bold" width="44" style="color:#94A3B8;"></iconify-icon>
            <p style="margin-top:10px;color:#475569;font-weight:600;">Belum ada siswa (anak) yang terhubung ke akun Orang Tua Anda. Silakan hubungi Administrator sekolah untuk menghubungkan data anak.</p>
        </div>
    <?php else: ?>
        <!-- 1. SEGMENTED TABS RESMI (ALA CURRICULUM.PHP) -->
        <div class="curriculum-main-tabs" id="ortuMonTabs" role="tablist">
            <button type="button" class="curriculum-tab-btn is-active" data-tab="nilai">
                <iconify-icon icon="solar:star-bold"></iconify-icon>
                <span class="curriculum-tab-label">Nilai Mapel</span>
                <span class="curriculum-tab-counter" id="cntNilai">0</span>
            </button>
            <button type="button" class="curriculum-tab-btn" data-tab="tugas">
                <iconify-icon icon="solar:document-text-bold"></iconify-icon>
                <span class="curriculum-tab-label">Tugas</span>
                <span class="curriculum-tab-counter" id="cntTugas">0</span>
            </button>
            <button type="button" class="curriculum-tab-btn" data-tab="proyek">
                <iconify-icon icon="solar:rocket-2-bold"></iconify-icon>
                <span class="curriculum-tab-label">Proyek &amp; P5</span>
                <span class="curriculum-tab-counter" id="cntProyek">0</span>
            </button>
            <button type="button" class="curriculum-tab-btn" data-tab="absensi">
                <iconify-icon icon="solar:checklist-minimalistic-bold"></iconify-icon>
                <span class="curriculum-tab-label">Absensi</span>
                <span class="curriculum-tab-counter" id="cntAbsensi">0</span>
            </button>
            <button type="button" class="curriculum-tab-btn" data-tab="perizinan">
                <iconify-icon icon="solar:letter-bold"></iconify-icon>
                <span class="curriculum-tab-label">Perizinan</span>
                <span class="curriculum-tab-counter" id="cntPerizinan">0</span>
            </button>
            <button type="button" class="curriculum-tab-btn" data-tab="pelanggaran">
                <iconify-icon icon="solar:shield-warning-bold"></iconify-icon>
                <span class="curriculum-tab-label">Pelanggaran &amp; BK</span>
                <span class="curriculum-tab-counter" id="cntPelanggaran">0</span>
            </button>
        </div>

        <!-- 2. TOOLBAR PILIH ANAK + PENCARIAN + COUNTER INLINE (ALA ASSESSMENT-ACCESS.PHP) -->
        <div class="ilmuna-filter-box">
            <div class="ilmuna-filter-topline">
                <div class="ilmuna-pill-group" id="ortuChildBar">
                    <?php foreach ($childrenList as $idx => $ch): ?>
                        <button type="button" class="ilmuna-pill-btn <?= $idx === 0 ? 'is-active' : '' ?>" data-siswa-id="<?= (int)$ch['id_siswa'] ?>" onclick="ORTU_MON.selectChild(<?= (int)$ch['id_siswa'] ?>, this)">
                            <iconify-icon icon="solar:square-academic-cap-2-bold" width="15"></iconify-icon>
                            <span><?= htmlspecialchars($ch['nama_lengkap'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($ch['nama_kelas'], ENT_QUOTES, 'UTF-8') ?>)</span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="ilmuna-search-wrap">
                    <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                    <input type="text" id="ortuMonSearch" class="ilmuna-search-input" placeholder="Cari mapel, tugas, proyek, izin, pelanggaran..." autocomplete="off">
                </div>

                <div class="ilmuna-filter-actions">
                    <div class="ilmuna-nav-stat-inline" title="Persentase kehadiran ananda">
                        <iconify-icon icon="solar:checklist-minimalistic-bold" width="14" style="color:#0D7A87;"></iconify-icon>
                        <span>Hadir</span>
                        <strong id="kpiAbsPersen">-</strong>
                    </div>
                    <div class="ilmuna-nav-stat-inline" title="Indeks Disiplin BK ananda">
                        <iconify-icon icon="solar:shield-warning-bold" width="14" style="color:#10B981;"></iconify-icon>
                        <span>BK</span>
                        <strong id="kpiDisiplin">-</strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. DATA CONTAINER (UNIVERSAL TABLE TEMPLATE) -->
        <div class="ilm-data-container">
            <div class="ilm-data-gunungan" aria-hidden="true"></div>
            <div class="ilm-table-responsive" id="ortuMonTableWrap">
                <p style="text-align:center;padding:34px;color:#64748B;">Memuat data monitoring anak...</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL PREVIEW BUKTI & LOKASI PERIZINAN (UNIVERSAL MODAL TEMPLATE) -->
<div class="ilm-modal-backdrop" id="modalBuktiOrtu">
    <div class="ilm-modal-dialog" style="max-width:580px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon" style="background:linear-gradient(135deg,#1D3B5E,#0D7A87);">
                    <iconify-icon icon="solar:camera-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="ortuBuktiTitle">Bukti &amp; Lokasi Perizinan</h3>
                    <p class="ilm-modal-header__subtitle" id="ortuBuktiSub">-</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalBuktiOrtu')" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <div class="ilm-modal-body" style="max-height:calc(82vh - 140px);overflow-y:auto;">
            <div id="ortuBuktiAlasan" style="padding:10px 12px;border-radius:10px;background:#F8FAFC;border:1px solid #E2E8F0;font-size:13px;color:#334155;margin-bottom:12px;"></div>
            <div id="ortuBuktiImgWrap" style="display:none;text-align:center;margin-bottom:12px;">
                <img id="ortuBuktiImg" src="" alt="Bukti Perizinan" style="max-width:100%;max-height:360px;border-radius:12px;border:1px solid #CBD5E1;object-fit:contain;">
            </div>
            <div id="ortuBuktiGpsWrap" style="display:none;padding:10px 12px;border-radius:10px;background:#F0FDFA;border:1px solid rgba(13,122,135,0.25);font-size:12.5px;color:#0F172A;">
                <div style="font-weight:700;color:#0D7A87;margin-bottom:4px;display:flex;align-items:center;gap:5px;">
                    <iconify-icon icon="solar:map-point-wave-bold" width="16"></iconify-icon>
                    <span>Lokasi GPS Pengajuan Izin</span>
                </div>
                <div id="ortuBuktiAlamat" style="margin-bottom:8px;color:#334155;">-</div>
                <a id="ortuBuktiGmaps" href="#" target="_blank" rel="noopener" class="ortu-izin-btn ortu-izin-btn--bukti">
                    <iconify-icon icon="solar:map-arrow-up-bold" width="14"></iconify-icon>
                    <span>Buka di Google Maps</span>
                </a>
            </div>
        </div>
        <div class="ilm-modal-footer">
            <button type="button" class="ilm-modal-btn ilm-modal-btn--primary" onclick="closeIlmunaModal('modalBuktiOrtu')">Tutup</button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var API_URL = <?= json_encode(BASE_URL . '/dashboard/orang-tua/?api=1') ?>;
    var INIT_CHILD_ID = <?= !empty($childrenList) ? (int)$childrenList[0]['id_siswa'] : 0 ?>;

    var STATE = {
        activeChildId: INIT_CHILD_ID,
        activeTab: 'nilai',
        payload: null
    };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    window.ORTU_MON = {
        selectChild: function (siswaId, btnEl) {
            STATE.activeChildId = parseInt(siswaId, 10);
            document.querySelectorAll('#ortuChildBar .ilmuna-pill-btn').forEach(function (b) {
                b.classList.toggle('is-active', b === btnEl);
            });
            ORTU_MON.loadChild();
        },
        loadChild: async function () {
            if (!STATE.activeChildId) return;
            document.getElementById('ortuMonTableWrap').innerHTML = '<p style="text-align:center;padding:34px;color:#64748B;">Memuat data monitoring anak...</p>';
            try {
                var r = await fetch(API_URL + '&id_siswa=' + encodeURIComponent(STATE.activeChildId), {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                });
                var res = await r.json();
                if (res && res.success) {
                    STATE.payload = res;
                    var nLen = (res.nilai || []).length;
                    var tList = res.tugas || [];
                    var pList = res.proyek || [];
                    var absS = (res.absensi && res.absensi.summary) ? res.absensi.summary : { persen: 100, total: 0 };
                    var izS  = (res.perizinan && res.perizinan.summary) ? res.perizinan.summary : { total: 0, disetujui: 0, menunggu: 0, ditolak: 0 };
                    var pelS = (res.pelanggaran && res.pelanggaran.summary) ? res.pelanggaran.summary : { total_kasus: 0, disiplin_bk: 100 };

                    document.getElementById('cntNilai').textContent = nLen;
                    document.getElementById('cntTugas').textContent = tList.length;
                    document.getElementById('cntProyek').textContent = pList.length;
                    document.getElementById('cntAbsensi').textContent = absS.total || 0;
                    document.getElementById('cntPerizinan').textContent = izS.total || 0;
                    document.getElementById('cntPelanggaran').textContent = pelS.total_kasus || 0;

                    document.getElementById('kpiAbsPersen').textContent = absS.persen + '%';
                    document.getElementById('kpiDisiplin').textContent = pelS.disiplin_bk + '/100';
                    ORTU_MON.renderTab();
                } else {
                    Ilmuna.error('Gagal Memuat', (res && res.message) || 'Gagal memuat data anak.');
                }
            } catch (e) {
                Ilmuna.error('Gagal Memuat', 'Koneksi ke server terganggu.');
            }
        },
        renderTab: function () {
            var wrap = document.getElementById('ortuMonTableWrap');
            if (!STATE.payload) return;
            var q = ((document.getElementById('ortuMonSearch') || {}).value || '').toLowerCase().trim();
            var matchQ = function (txt) { return !q || String(txt || '').toLowerCase().indexOf(q) !== -1; };

            if (STATE.activeTab === 'nilai') {
                var rows = (STATE.payload.nilai || []).filter(function (r) { return matchQ((r.nama_mata_pelajaran || '') + ' ' + (r.predikat_akhir || '')); });
                if (!rows.length) {
                    wrap.innerHTML = '<p style="text-align:center;padding:34px;color:#64748B;">Belum ada data nilai mata pelajaran.</p>';
                    return;
                }
                wrap.innerHTML = '<table class="ilm-table"><thead><tr><th>Mata Pelajaran</th><th>Kognitif</th><th>Afektif</th><th>Psikomotorik</th><th>Nilai Akhir</th><th>Predikat</th></tr></thead><tbody>'
                    + rows.map(function (r) {
                        return '<tr>'
                            + '<td data-label="Mata Pelajaran"><strong>' + esc(r.nama_mata_pelajaran) + '</strong></td>'
                            + '<td data-label="Kognitif">' + (r.rata_kognitif != null ? esc(r.rata_kognitif) : '-') + '</td>'
                            + '<td data-label="Afektif">' + (r.rata_afektif != null ? esc(r.rata_afektif) + (r.predikat_afektif ? ' (' + esc(r.predikat_afektif) + ')' : '') : '-') + '</td>'
                            + '<td data-label="Psikomotorik">' + (r.rata_psikomotorik != null ? esc(r.rata_psikomotorik) : '-') + '</td>'
                            + '<td data-label="Nilai Akhir"><strong>' + (r.nilai_akhir != null ? esc(r.nilai_akhir) : '-') + '</strong></td>'
                            + '<td data-label="Predikat"><span class="ortu-b-info">' + esc(r.predikat_akhir || r.predikat_afektif || '-') + '</span></td>'
                            + '</tr>';
                    }).join('') + '</tbody></table>';
                return;
            }

            if (STATE.activeTab === 'tugas') {
                var tRows = (STATE.payload.tugas || []).filter(function (r) { return matchQ((r.nama_mata_pelajaran || '') + ' ' + (r.judul_tugas || '') + ' ' + (r.status_pengumpulan || '')); });
                if (!tRows.length) {
                    wrap.innerHTML = '<p style="text-align:center;padding:34px;color:#64748B;">Belum ada daftar tugas.</p>';
                    return;
                }
                wrap.innerHTML = '<table class="ilm-table"><thead><tr><th>Mata Pelajaran</th><th>Judul Tugas</th><th>Tenggat</th><th>Status</th><th>Nilai</th></tr></thead><tbody>'
                    + tRows.map(function (r) {
                        var stBadge = r.status_pengumpulan
                            ? '<span class="ortu-b-ok">' + esc(r.status_pengumpulan) + '</span>'
                            : '<span class="ortu-b-warn">Belum Mengumpulkan</span>';
                        return '<tr>'
                            + '<td data-label="Mata Pelajaran"><strong>' + esc(r.nama_mata_pelajaran) + '</strong></td>'
                            + '<td data-label="Judul Tugas">' + esc(r.judul_tugas) + '</td>'
                            + '<td data-label="Tenggat">' + esc(r.tenggat_waktu || '-') + '</td>'
                            + '<td data-label="Status">' + stBadge + '</td>'
                            + '<td data-label="Nilai"><strong>' + (r.nilai_tugas != null ? esc(r.nilai_tugas) : '-') + '</strong></td>'
                            + '</tr>';
                    }).join('') + '</tbody></table>';
                return;
            }

            if (STATE.activeTab === 'proyek') {
                var pRows = (STATE.payload.proyek || []).filter(function (r) { return matchQ((r.nama_mata_pelajaran || '') + ' ' + (r.judul_proyek || '') + ' ' + (r.nama_kelompok || '')); });
                if (!pRows.length) {
                    wrap.innerHTML = '<p style="text-align:center;padding:34px;color:#64748B;">Belum ada proyek / P5 yang diikuti.</p>';
                    return;
                }
                wrap.innerHTML = '<table class="ilm-table"><thead><tr><th>Mata Pelajaran</th><th>Judul Proyek / P5</th><th>Kelompok</th><th>Tenggat</th><th>Status</th><th>Nilai</th></tr></thead><tbody>'
                    + pRows.map(function (r) {
                        var stBadge = r.status_pengumpulan
                            ? '<span class="ortu-b-ok">' + esc(r.status_pengumpulan) + '</span>'
                            : '<span class="ortu-b-warn">Dalam Proses</span>';
                        return '<tr>'
                            + '<td data-label="Mata Pelajaran"><strong>' + esc(r.nama_mata_pelajaran) + '</strong></td>'
                            + '<td data-label="Proyek">' + esc(r.judul_proyek) + '</td>'
                            + '<td data-label="Kelompok">' + esc(r.nama_kelompok || 'Individu') + '</td>'
                            + '<td data-label="Tenggat">' + esc(r.tenggat_waktu || '-') + '</td>'
                            + '<td data-label="Status">' + stBadge + '</td>'
                            + '<td data-label="Nilai"><strong>' + (r.nilai_proyek != null ? esc(r.nilai_proyek) : '-') + '</strong></td>'
                            + '</tr>';
                    }).join('') + '</tbody></table>';
                return;
            }

            if (STATE.activeTab === 'absensi') {
                var aLogs = ((STATE.payload.absensi && STATE.payload.absensi.logs) ? STATE.payload.absensi.logs : []).filter(function (r) { return matchQ((r.nama_mapel || '') + ' ' + (r.status || '') + ' ' + (r.keterangan || '')); });
                if (!aLogs.length) {
                    wrap.innerHTML = '<p style="text-align:center;padding:34px;color:#64748B;">Belum ada riwayat presensi.</p>';
                    return;
                }
                wrap.innerHTML = '<table class="ilm-table"><thead><tr><th>Waktu</th><th>Mata Pelajaran</th><th>Pertemuan</th><th>Status</th><th>Metode &amp; Keterangan</th></tr></thead><tbody>'
                    + aLogs.map(function (r) {
                        var st = String(r.status || '').toUpperCase();
                        var cls = (st === 'H' || st === 'T') ? 'ortu-b-ok' : (st === 'A' ? 'ortu-b-err' : 'ortu-b-warn');
                        var lblMap = { H: 'Hadir', I: 'Izin', S: 'Sakit', A: 'Alpa', T: 'Terlambat' };
                        return '<tr>'
                            + '<td data-label="Waktu">' + esc(r.waktu_absen || '-') + '</td>'
                            + '<td data-label="Mata Pelajaran"><strong>' + esc(r.nama_mapel) + '</strong></td>'
                            + '<td data-label="Pertemuan">P' + esc(r.pertemuan_ke || '-') + '</td>'
                            + '<td data-label="Status"><span class="' + cls + '">' + esc(lblMap[st] || st) + '</span></td>'
                            + '<td data-label="Keterangan">' + esc(r.metode_absen || '-') + (r.keterangan ? ' · ' + esc(r.keterangan) : '') + '</td>'
                            + '</tr>';
                    }).join('') + '</tbody></table>';
                return;
            }

            if (STATE.activeTab === 'perizinan') {
                var izLogs = ((STATE.payload.perizinan && STATE.payload.perizinan.logs) ? STATE.payload.perizinan.logs : []).filter(function (r) { return matchQ((r.nama_mapel || '') + ' ' + (r.jenis_izin || '') + ' ' + (r.alasan || '') + ' ' + (r.alamat_izin || '') + ' ' + (r.status_perizinan || '')); });
                if (!izLogs.length) {
                    wrap.innerHTML = '<p style="text-align:center;padding:34px;color:#64748B;">Belum ada pengajuan perizinan (Izin / Sakit) dari ananda.</p>';
                    return;
                }
                wrap.innerHTML = '<table class="ilm-table"><thead><tr><th>Tanggal Izin</th><th>Mapel &amp; Pertemuan</th><th>Jenis</th><th>Alasan</th><th>Lokasi &amp; Bukti</th><th>Status &amp; Catatan</th></tr></thead><tbody>'
                    + izLogs.map(function (r, idx) {
                        var sp = String(r.status_perizinan || 'Menunggu');
                        var stCls = (sp.indexOf('Disetujui') !== -1 || r.approval_guru === 'Approved')
                            ? 'ortu-b-ok'
                            : ((sp.indexOf('Ditolak') !== -1 || r.approval_guru === 'Rejected') ? 'ortu-b-err' : 'ortu-b-warn');
                        var jCls = String(r.jenis_izin || '').toLowerCase() === 'sakit' ? 'ortu-b-info' : 'ortu-b-warn';
                        var hasBukti = !!(r.bukti_perizinan && String(r.bukti_perizinan).trim() !== '');
                        var hasGps = !!(r.latitude && r.longitude && String(r.latitude) !== '0' && String(r.longitude) !== '0');
                        var lokBuktiParts = [];
                        if (hasBukti) {
                            lokBuktiParts.push('<button type="button" class="ortu-izin-btn ortu-izin-btn--bukti" onclick="ORTU_MON.openBukti(' + idx + ')"><iconify-icon icon="solar:camera-bold" width="13"></iconify-icon><span>Lihat Bukti</span></button>');
                        }
                        if (hasGps) {
                            var gmapsUrl = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(r.latitude + ',' + r.longitude);
                            lokBuktiParts.push('<a href="' + gmapsUrl + '" target="_blank" rel="noopener" class="ortu-izin-btn ortu-izin-btn--map"><iconify-icon icon="solar:map-point-wave-bold" width="13" style="color:#0D7A87;"></iconify-icon><span>Lokasi GPS</span></a>');
                        }
                        var alamatLine = r.alamat_izin ? '<div style="font-size:11px;color:#64748B;margin-top:4px;max-width:210px;">' + esc(r.alamat_izin) + '</div>' : '';
                        return '<tr>'
                            + '<td data-label="Tanggal Izin"><strong>' + esc(r.tanggal_izin || '-') + '</strong></td>'
                            + '<td data-label="Mapel"><strong>' + esc(r.nama_mapel || 'Umum') + '</strong>' + (r.pertemuan_ke ? '<div style="font-size:11px;color:#64748B;">Pertemuan ' + esc(r.pertemuan_ke) + '</div>' : '') + '</td>'
                            + '<td data-label="Jenis"><span class="' + jCls + '">' + esc(r.jenis_izin || 'Izin') + '</span></td>'
                            + '<td data-label="Alasan"><div style="font-size:12.5px;color:#334155;">' + esc(r.alasan || '-') + '</div></td>'
                            + '<td data-label="Lokasi & Bukti"><div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">' + (lokBuktiParts.length ? lokBuktiParts.join('') : '<span style="font-size:12px;color:#94A3B8;">-</span>') + '</div>' + alamatLine + '</td>'
                            + '<td data-label="Status"><span class="' + stCls + '">' + esc(sp) + '</span>' + (r.catatan_guru ? '<div style="font-size:11.5px;color:#475569;margin-top:3px;">' + esc(r.catatan_guru) + '</div>' : '') + '</td>'
                            + '</tr>';
                    }).join('') + '</tbody></table>';
                STATE.filteredIzin = izLogs;
                return;
            }

            // Tab Pelanggaran & Disiplin BK
            var pelLogs = ((STATE.payload.pelanggaran && STATE.payload.pelanggaran.logs) ? STATE.payload.pelanggaran.logs : []).filter(function (r) { return matchQ((r.kategori_pelanggaran || '') + ' ' + (r.kronologi || '') + ' ' + (r.lokasi_kejadian || '') + ' ' + (r.status_penanganan || '')); });
            if (!pelLogs.length) {
                wrap.innerHTML = '<p style="text-align:center;padding:34px;color:#047857;font-weight:700;">Alhamdulillah, ananda tidak memiliki catatan pelanggaran (Disiplin BK: 100 / Bersih).</p>';
                return;
            }
            wrap.innerHTML = '<table class="ilm-table"><thead><tr><th>Tanggal</th><th>Lokasi</th><th>Pelanggaran &amp; Kronologi</th><th>Skor BK</th><th>Status &amp; Pembinaan BK</th></tr></thead><tbody>'
                + pelLogs.map(function (r) {
                    var locB = r.lokasi_kejadian === 'Grup WA' ? '<span class="ortu-b-ok">Grup WA</span>' : '<span class="ortu-b-info">Realitas</span>';
                    var stCls = r.status_penanganan === 'Selesai' ? 'ortu-b-ok' : (r.status_penanganan === 'Pembinaan' ? 'ortu-b-info' : 'ortu-b-warn');
                    var skorB = (parseInt(r.poin || 0, 10) > 0)
                        ? '<span class="ortu-b-err">' + esc(r.tingkat_pelanggaran) + ' · ' + r.poin + ' Poin</span>'
                        : '<span class="ortu-b-warn">Menunggu BK</span>';
                    return '<tr>'
                        + '<td data-label="Tanggal"><strong>' + esc(r.tanggal_kejadian) + '</strong><div style="font-size:11px;color:#64748B;">' + esc(r.nama_mapel) + '</div></td>'
                        + '<td data-label="Lokasi">' + locB + '</td>'
                        + '<td data-label="Pelanggaran"><strong>' + esc(r.kategori_pelanggaran) + '</strong><div style="font-size:12px;color:#475569;margin-top:2px;">' + esc(r.kronologi) + '</div></td>'
                        + '<td data-label="Skor BK">' + skorB + '</td>'
                        + '<td data-label="Status BK"><span class="' + stCls + '">' + esc(r.status_penanganan) + '</span>' + (r.tindak_lanjut ? '<div style="font-size:11.5px;color:#0D7A87;margin-top:3px;">' + esc(r.tindak_lanjut) + '</div>' : '') + '</td>'
                        + '</tr>';
                }).join('') + '</tbody></table>';
        },
        openBukti: function (idx) {
            var r = (STATE.filteredIzin || [])[idx];
            if (!r) return;
            document.getElementById('ortuBuktiTitle').textContent = 'Bukti ' + (r.jenis_izin || 'Izin') + ' — ' + (r.tanggal_izin || '');
            document.getElementById('ortuBuktiSub').textContent = (r.nama_mapel || 'Umum') + (r.pertemuan_ke ? ' · Pertemuan ' + r.pertemuan_ke : '') + ' · ' + (r.status_perizinan || '');
            document.getElementById('ortuBuktiAlasan').innerHTML = '<strong>Alasan:</strong> ' + esc(r.alasan || '-');

            var imgWrap = document.getElementById('ortuBuktiImgWrap');
            var imgEl = document.getElementById('ortuBuktiImg');
            if (r.bukti_perizinan && String(r.bukti_perizinan).trim() !== '') {
                imgEl.src = r.bukti_perizinan;
                imgWrap.style.display = 'block';
            } else {
                imgWrap.style.display = 'none';
            }

            var gpsWrap = document.getElementById('ortuBuktiGpsWrap');
            if (r.latitude && r.longitude && String(r.latitude) !== '0' && String(r.longitude) !== '0') {
                var alamatTxt = (r.alamat_izin ? r.alamat_izin + ' ' : '') + '(' + r.latitude + ', ' + r.longitude + ')';
                document.getElementById('ortuBuktiAlamat').textContent = alamatTxt;
                document.getElementById('ortuBuktiGmaps').href = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(r.latitude + ',' + r.longitude);
                gpsWrap.style.display = 'block';
            } else {
                gpsWrap.style.display = 'none';
            }
            openIlmunaModal('modalBuktiOrtu');
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('#ortuMonTabs .curriculum-tab-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                STATE.activeTab = btn.getAttribute('data-tab');
                document.querySelectorAll('#ortuMonTabs .curriculum-tab-btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                ORTU_MON.renderTab();
            });
        });
        var searchEl = document.getElementById('ortuMonSearch');
        if (searchEl) searchEl.addEventListener('input', ORTU_MON.renderTab);
        if (INIT_CHILD_ID > 0) {
            ORTU_MON.loadChild();
        }
    });
})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
