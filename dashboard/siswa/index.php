<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — DASHBOARD SISWA (PESERTA DIDIK)
 * ==============================================================================
 * Mengacu Ketat pada Panduan Desain Resmi README.md:
 * 1. Filosofi Desain "Data-First":
 *    - Zero Title & Zero Subtitle (Tanpa <h1> & <p> banner judul mubasir).
 *    - Dilarang Hero Banner Dekoratif.
 *    - Tanpa Statistik KPI Box Besar (Zero KPI Stat Cards).
 * 2. Segmented Tabs Konsisten (Standar curriculum.php: .curriculum-main-tabs).
 * 3. Toolbar & Action Navigation (Standar assessment-access.php: .ilmuna-filter-box).
 * 4. Tiga Modul Konten Utama:
 *    - Tugas Belajar (Urut: Belum Dikerjakan -> Dikirim -> Dinilai).
 *    - Kelompok Belajar Kolaboratif (Disertai foto siswa dari tabel siswa & NISN).
 *    - Materi Pembelajaran PAI & Jurnal Mutaba'ah.
 * 5. Standar Responsivitas Mobile (360px+, touch target >= 44px, no overflow-x).
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole(['siswa', 'admin', 'guru']);

$pageTitle = 'Dashboard Siswa';
$pageIcon  = 'solar:widget-2-bold';
$pdo = getDBConnection();
$currentUser = getCurrentUser();
$activeRole = hasRole('siswa') ? 'siswa' : (hasRole('guru') ? 'guru' : 'admin');

// 1. RESOLUSI DATA SISWA & KELAS TERDAFTAR (STRICT CLASS ENROLMENT)
$currentSiswa = null;
$overrideSiswaId = (int)($_GET['siswa_id'] ?? 0);

if (($activeRole === 'admin' || $activeRole === 'guru') && $overrideSiswaId > 0) {
    $stmtS = $pdo->prepare("
        SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran 
        FROM siswa s 
        LEFT JOIN kelas k ON s.id_kelas = k.id 
        WHERE s.id = ? LIMIT 1
    ");
    $stmtS->execute([$overrideSiswaId]);
    $currentSiswa = $stmtS->fetch(PDO::FETCH_ASSOC);
}

if (!$currentSiswa) {
    $stmtS = $pdo->prepare("
        SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran 
        FROM siswa s 
        LEFT JOIN kelas k ON s.id_kelas = k.id 
        WHERE s.nis = ? OR s.nisn = ? OR s.gmail = ? OR s.gmail = ?
        LIMIT 1
    ");
    $stmtS->execute([
        $currentUser['username'] ?? '',
        $currentUser['nisn_nip'] ?? '',
        $currentUser['email'] ?? '',
        $currentUser['email_google'] ?? ''
    ]);
    $currentSiswa = $stmtS->fetch(PDO::FETCH_ASSOC);
}

// Fallback jika admin/guru preview
if (!$currentSiswa) {
    $currentSiswa = $pdo->query("
        SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran 
        FROM siswa s 
        LEFT JOIN kelas k ON s.id_kelas = k.id 
        ORDER BY s.id ASC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
}

$idSiswa    = (int)($currentSiswa['id'] ?? 0);
$idKelas    = (int)($currentSiswa['id_kelas'] ?? 0);
$namaKelas  = $currentSiswa['nama_kelas'] ?? 'Kelas PAI';
$namaSiswa  = $currentSiswa['nama_lengkap'] ?? ($currentUser['full_name'] ?: 'Siswa Ilmuna');
$nisnSiswa  = $currentSiswa['nisn'] ?? ($currentSiswa['nis'] ?? '-');

function hitungPredikatNilai($skor) {
    $s = (float)$skor;
    if ($s >= 90) return 'A';
    if ($s >= 80) return 'B';
    if ($s >= 70) return 'C';
    if ($s >= 60) return 'D';
    return 'E';
}

function getStudentPhotoPath($foto) {
    if (empty($foto)) return '';
    if (strpos($foto, 'http://') === 0 || strpos($foto, 'https://') === 0) return $foto;
    if (strpos($foto, 'uploads/') === 0) return BASE_URL . '/' . $foto;
    return BASE_URL . '/uploads/profiles/' . $foto;
}

// 2. QUERY TUGAS KELAS SISWA (SINKRON DENGAN KELAS_MAPEL & PENGUMPULAN)
$assignments = [];
$pendingCount = 0;
$submittedCount = 0;
$gradedCount = 0;

if ($idKelas > 0) {
    $stmtTugas = $pdo->prepare("
        SELECT t.*, km.nama_mata_pelajaran, km.nama_kelas,
               COALESCE(g.nama_guru, km.nama_guru, 'Guru Pengampu PAI') AS nama_guru
        FROM tugas t
        JOIN kelas_mapel km ON t.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
        LEFT JOIN guru g ON t.id_guru = g.id
        WHERE km.id_kelas = ?
          AND (t.status_tugas != 'Draft' OR t.status_tugas IS NULL)
        ORDER BY t.tenggat_waktu ASC, t.id DESC
    ");
    $stmtTugas->execute([$idKelas]);
    $rawTasks = $stmtTugas->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawTasks as $t) {
        $tId = (int)$t['id'];
        $isGroup = ($t['jenis_penugasan'] === 'Kelompok');

        // Cek Kelompok Belajar Siswa
        $myGroup = null;
        if ($isGroup) {
            $stmtG = $pdo->prepare("
                SELECT tk.id, tk.nama_kelompok, tk.id_ketua_kelompok
                FROM tugas_kelompok tk
                JOIN tugas_kelompok_anggota tka ON tk.id = tka.id_tugas_kelompok
                WHERE tk.id_tugas = ? AND tka.id_siswa = ?
                LIMIT 1
            ");
            $stmtG->execute([$tId, $idSiswa]);
            $myGroup = $stmtG->fetch(PDO::FETCH_ASSOC);
        }

        // Cek Pengumpulan Tugas
        $submission = null;
        if ($isGroup && $myGroup) {
            $stmtSub = $pdo->prepare("
                SELECT pt.*, s.nama_lengkap AS nama_pengumpul
                FROM pengumpulan_tugas pt
                LEFT JOIN siswa s ON pt.id_siswa = s.id
                WHERE pt.id_tugas = ? AND pt.id_tugas_kelompok = ?
                ORDER BY pt.waktu_pengumpulan DESC LIMIT 1
            ");
            $stmtSub->execute([$tId, $myGroup['id']]);
            $submission = $stmtSub->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmtSub = $pdo->prepare("
                SELECT pt.*, s.nama_lengkap AS nama_pengumpul
                FROM pengumpulan_tugas pt
                LEFT JOIN siswa s ON pt.id_siswa = s.id
                WHERE pt.id_tugas = ? AND pt.id_siswa = ?
                ORDER BY pt.waktu_pengumpulan DESC LIMIT 1
            ");
            $stmtSub->execute([$tId, $idSiswa]);
            $submission = $stmtSub->fetch(PDO::FETCH_ASSOC);
        }

        // Cek Nilai Tugas
        $stmtNilai = $pdo->prepare("
            SELECT * FROM nilai_tugas
            WHERE id_tugas = ? AND id_siswa = ?
            LIMIT 1
        ");
        $stmtNilai->execute([$tId, $idSiswa]);
        $nilai = $stmtNilai->fetch(PDO::FETCH_ASSOC);

        // Kategori Status Pengerjaan
        if ($submission && ($submission['status_pengumpulan'] === 'Menunggu Revisi' || ($nilai && $nilai['status_penilaian'] === 'Perlu Revisi'))) {
            $statusKey = 'revision';
            $pendingCount++;
        } elseif ($nilai && in_array($nilai['status_penilaian'], ['Dinilai', 'Tuntas'])) {
            $statusKey = 'graded';
            $gradedCount++;
        } elseif ($submission) {
            $statusKey = 'submitted';
            $submittedCount++;
        } else {
            $statusKey = 'pending';
            $pendingCount++;
        }

        $t['my_group'] = $myGroup;
        $t['submission'] = $submission;
        $t['nilai'] = $nilai;
        $t['student_status'] = $statusKey;
        $assignments[] = $t;
    }

    // URUTKAN SESUAI ATURAN RESMI:
    // 1. Belum Dikerjakan / Perlu Revisi (Urut deadline paling awal)
    // 2. Sudah Dikerjakan & Dikirim (Menunggu Penilaian)
    // 3. Sudah Dinilai
    usort($assignments, function($a, $b) {
        $orderMap = [
            'revision'  => 1,
            'pending'   => 1,
            'submitted' => 2,
            'graded'    => 3,
        ];
        $wA = $orderMap[$a['student_status']] ?? 9;
        $wB = $orderMap[$b['student_status']] ?? 9;

        if ($wA !== $wB) {
            return $wA <=> $wB;
        }

        if ($wA === 1) {
            $dlA = !empty($a['tenggat_waktu']) ? strtotime($a['tenggat_waktu']) : PHP_INT_MAX;
            $dlB = !empty($b['tenggat_waktu']) ? strtotime($b['tenggat_waktu']) : PHP_INT_MAX;
            return $dlA <=> $dlB;
        }

        return $b['id'] <=> $a['id'];
    });
}

// 3. QUERY KELOMPOK BELAJAR KOLABORATIF SISWA BESERTA SELURUH ANGGOTANYA
$myGroups = [];
if ($idSiswa > 0) {
    $stmtGroups = $pdo->prepare("
        SELECT tk.*, t.judul_tugas, km.nama_mata_pelajaran,
               s_ketua.nama_lengkap AS nama_ketua, s_ketua.nisn AS nisn_ketua, s_ketua.foto AS foto_ketua
        FROM tugas_kelompok tk
        JOIN tugas_kelompok_anggota tka ON tk.id = tka.id_tugas_kelompok
        JOIN tugas t ON tk.id_tugas = t.id
        JOIN kelas_mapel km ON t.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
        LEFT JOIN siswa s_ketua ON tk.id_ketua_kelompok = s_ketua.id
        WHERE tka.id_siswa = ?
        ORDER BY tk.id DESC
    ");
    $stmtGroups->execute([$idSiswa]);
    $myGroups = $stmtGroups->fetchAll(PDO::FETCH_ASSOC);

    foreach ($myGroups as &$g) {
        $stmtA = $pdo->prepare("
            SELECT s.id, s.nama_lengkap, s.nisn, s.nis, s.foto, 
                   (s.id = ?) AS is_me, 
                   (s.id = ?) AS is_ketua
            FROM tugas_kelompok_anggota tka
            JOIN siswa s ON tka.id_siswa = s.id
            WHERE tka.id_tugas_kelompok = ?
            ORDER BY is_ketua DESC, s.nama_lengkap ASC
        ");
        $stmtA->execute([$idSiswa, (int)$g['id_ketua_kelompok'], (int)$g['id']]);
        $g['anggota'] = $stmtA->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($g);
}

// 4. QUERY MATERI & MODUL PAI TERBARU KELAS SISWA
$recentMateri = [];
if ($idKelas > 0) {
    $stmtMat = $pdo->prepare("
        SELECT m.*, km.nama_mata_pelajaran, km.nama_kelas,
               COALESCE(g.nama_guru, km.nama_guru, 'Guru Pengampu PAI') AS nama_guru
        FROM materi m
        JOIN kelas_mapel km ON m.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
        LEFT JOIN guru g ON m.id_guru = g.id
        WHERE km.id_kelas = ?
        ORDER BY m.id DESC
        LIMIT 6
    ");
    $stmtMat->execute([$idKelas]);
    $recentMateri = $stmtMat->fetchAll(PDO::FETCH_ASSOC);
}

// 5. STATUS MUTABA'AH HARI INI
$stmtM = $pdo->prepare("SELECT * FROM mutabaah_logs WHERE student_id = ? AND log_date = CURRENT_DATE LIMIT 1");
$stmtM->execute([$currentUser['id']]);
$todayMutabaah = $stmtM->fetch();

$fotoSiswaUrl = getStudentPhotoPath($currentSiswa['foto'] ?? '');
$inisialSiswa = mb_substr($namaSiswa, 0, 1, 'UTF-8');

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<style>
/* ==============================================================================
 * ILMUNA DATA-FIRST DASHBOARD SISWA (ZERO TITLE, ZERO SUBTITLE, ZERO KPI STATS)
 * Mengikuti Panduan Desain Resmi README.md Bab 4 & Bab 5
 * ============================================================================== */
:root {
    --lumina-navy: #1D3B5E;
    --lumina-teal: #0D7A87;
    --lumina-sky: #52B2DC;
    --lumina-gold: #F59E0B;
    --lumina-emerald: #10B981;
    --lumina-amber: #D97706;
    --lumina-rose: #EF4444;
    --lumina-border: #E2E8F0;
    --lumina-bg-canvas: #F8FAFC;
    --lumina-bg-card: #FFFFFF;
}

.ilmuna-student-dashboard {
    width: 100%;
    margin: 0 auto;
    padding-bottom: 30px;
    box-sizing: border-box;
}

/* 1. STANDAR TABS SEGMENTED (ALA CURRICULUM.PHP) */
.curriculum-main-tabs {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    background: #F1F5F9 !important;
    border: 1px solid #E2E8F0 !important;
    border-radius: 14px !important;
    padding: 6px !important;
    margin-bottom: 14px !important;
    overflow-x: auto !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
    box-sizing: border-box !important;
    width: 100% !important;
}
.curriculum-main-tabs::-webkit-scrollbar {
    display: none !important;
}
.curriculum-tab-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    padding: 10px 18px !important;
    border-radius: 10px !important;
    border: 1px solid transparent !important;
    background: transparent !important;
    color: #475569 !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    text-decoration: none !important;
    min-height: 42px !important;
}
.curriculum-tab-btn:hover {
    background: #E2E8F0 !important;
    color: var(--lumina-navy) !important;
}
.curriculum-tab-btn.is-active {
    background: #FFFFFF !important;
    color: var(--lumina-teal) !important;
    border-color: #CBD5E1 !important;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08) !important;
}
.curriculum-tab-counter {
    background: #CCFBF1 !important;
    color: #0F766E !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    border-radius: 10px !important;
    padding: 0 7px !important;
    height: 20px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
}
.curriculum-tab-btn.is-active .curriculum-tab-counter {
    background: var(--lumina-teal) !important;
    color: #FFFFFF !important;
}

/* 2. STANDAR TOOLBAR AKSI (ALA ASSESSMENT-ACCESS.PHP) */
.ilmuna-filter-box {
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    border-radius: 14px;
    padding: 10px 14px;
    margin-bottom: 18px;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03);
}
.ilmuna-filter-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

/* User Identity Chip Compact (Data-First) */
.sd-user-chip {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    padding: 6px 12px;
    border-radius: 10px;
}
.sd-user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
    background: #E2E8F0;
    flex-shrink: 0;
}
.sd-user-avatar-fallback {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #0D7A87 0%, #1D3B5E 100%);
    color: #FFFFFF;
    font-size: 13px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.sd-user-name {
    font-weight: 800;
    font-size: 13px;
    color: var(--lumina-navy);
    line-height: 1.2;
}
.sd-user-meta {
    font-size: 11px;
    color: #64748B;
    display: flex;
    align-items: center;
    gap: 6px;
    line-height: 1.2;
    margin-top: 2px;
}

/* Action Navigation Buttons */
.ilmuna-nav-actions-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.ilmuna-nav-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 9px;
    font-size: 12.5px;
    font-weight: 700;
    text-decoration: none;
    transition: all 0.2s ease;
    min-height: 38px;
    box-sizing: border-box;
}
.ilmuna-nav-link--emerald {
    background: #DCFCE7;
    color: #15803D !important;
    border: 1px solid #BBF7D0;
}
.ilmuna-nav-link--gold {
    background: #FEF3C7;
    color: #B45309 !important;
    border: 1px solid #FDE68A;
}
.ilmuna-nav-link--sky {
    background: #E0F2FE;
    color: #0369A1 !important;
    border: 1px solid #BAE6FD;
}
.ilmuna-nav-link--teal {
    background: #CCFBF1;
    color: #0F766E !important;
    border: 1px solid #99F6E4;
}
.ilmuna-nav-link:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.06);
}

/* Kolom Pencarian Cepat Inline */
.ilmuna-search-wrap {
    position: relative;
    min-width: 200px;
    flex-grow: 1;
    max-width: 320px;
}
.ilmuna-search-wrap iconify-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #94A3B8;
    font-size: 16px;
}
.ilmuna-search-input {
    width: 100%;
    padding: 8px 12px 8px 36px;
    border: 1px solid #CBD5E1;
    border-radius: 9px;
    font-size: 13px;
    background: #FFFFFF;
    color: var(--lumina-navy);
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
    box-sizing: border-box;
    min-height: 38px;
}
.ilmuna-search-input:focus {
    border-color: var(--lumina-teal);
    box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.15);
}

/* 3. GRID LAYOUT DUA KOLOM DATA-FIRST */
.sd-data-grid {
    display: grid;
    grid-template-columns: 1.8fr 1.2fr;
    gap: 18px;
}
.sd-data-pane {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

/* Kartu Konten Bersih & Elegan */
.sd-content-card {
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    border-radius: 16px;
    box-shadow: 0 3px 12px rgba(15, 23, 42, 0.03);
    overflow: hidden;
}
.sd-content-header {
    padding: 14px 18px;
    border-bottom: 1px solid #F1F5F9;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    background: #FAF5FF;
}
.sd-content-header--tugas {
    background: linear-gradient(135deg, #F0FDF4 0%, #FFFFFF 100%);
}
.sd-content-header--kelompok {
    background: linear-gradient(135deg, #FAF5FF 0%, #FFFFFF 100%);
}
.sd-content-header--materi {
    background: linear-gradient(135deg, #F0F9FF 0%, #FFFFFF 100%);
}
.sd-content-title {
    font-family: 'Outfit', sans-serif;
    font-size: 15px;
    font-weight: 800;
    color: var(--lumina-navy);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.sd-content-link {
    font-size: 12px;
    font-weight: 700;
    color: var(--lumina-teal);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.sd-content-link:hover {
    text-decoration: underline;
}
.sd-content-body {
    padding: 16px 18px;
}

/* TUGAS ITEMS */
.sd-task-item {
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    transition: all 0.2s ease;
}
.sd-task-item:last-child {
    margin-bottom: 0;
}
.sd-task-item:hover {
    background: #FFFFFF;
    border-color: #CBD5E1;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
}
.sd-task-title {
    font-weight: 700;
    font-size: 13.5px;
    color: var(--lumina-navy);
    margin: 0 0 4px;
    line-height: 1.35;
}
.sd-task-meta {
    font-size: 11.5px;
    color: #64748B;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

/* KELOMPOK ITEMS */
.sd-group-box {
    background: #FAF5FF;
    border: 1.5px solid #E9D5FF;
    border-radius: 12px;
    padding: 14px;
    margin-bottom: 12px;
}
.sd-group-box:last-child {
    margin-bottom: 0;
}
.sd-group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
}
.sd-group-name {
    font-weight: 800;
    font-size: 13.5px;
    color: #6B21A8;
    display: flex;
    align-items: center;
    gap: 6px;
}
.sd-members-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}
.sd-member-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    padding: 4px 8px;
    border-radius: 8px;
    font-size: 11.5px;
    font-weight: 600;
    color: #334155;
}
.sd-member-chip.is-ketua {
    background: #FEF3C7;
    border-color: #FDE68A;
    color: #92400E;
    font-weight: 700;
}
.sd-member-chip.is-me {
    border-color: #BAE6FD;
    background: #F0F9FF;
    color: #0369A1;
    font-weight: 700;
}
.sd-member-avatar {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    object-fit: cover;
    background: #E2E8F0;
    flex-shrink: 0;
}
.sd-member-avatar-fallback {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #0D7A87;
    color: #FFFFFF;
    font-size: 10px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

/* MATERI ITEMS */
.sd-materi-item {
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    padding: 11px 13px;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    transition: all 0.2s ease;
}
.sd-materi-item:last-child {
    margin-bottom: 0;
}
.sd-materi-item:hover {
    background: #FFFFFF;
    border-color: #BAE6FD;
    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.05);
}

/* BADGES RESMI */
.sd-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
}
.sd-badge--emerald { background: #DCFCE7; color: #15803D; }
.sd-badge--sky     { background: #E0F2FE; color: #0369A1; }
.sd-badge--amber   { background: #FEF3C7; color: #B45309; }
.sd-badge--purple  { background: #F3E8FF; color: #7E22CE; }
.sd-badge--rose    { background: #FFE4E6; color: #E11D48; }

/* TAB PANE TOGGLING */
.sd-tab-content-pane {
    display: none;
}
.sd-tab-content-pane.is-active {
    display: block;
}

/* ==============================================================================
 * 5. STANDAR RESPONSIVITAS MOBILE (360px – 768px)
 * ============================================================================== */
@media (max-width: 900px) {
    .sd-data-grid {
        grid-template-columns: 1fr;
        gap: 14px;
    }
}

@media (max-width: 768px) {
    .ilmuna-filter-topline {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }
    .sd-user-chip {
        width: 100%;
        box-sizing: border-box;
    }
    .ilmuna-nav-actions-wrap {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        width: 100%;
    }
    .ilmuna-nav-link {
        justify-content: center;
        min-height: 44px; /* Touch target standar mobile */
    }
    .ilmuna-search-wrap {
        max-width: 100%;
        width: 100%;
    }
    .ilmuna-search-input {
        min-height: 44px;
        font-size: 14px;
    }
    .sd-task-item {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }
    .sd-task-action-wrap {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        width: 100%;
    }
    .sd-task-action-wrap .btn {
        flex: 1;
        justify-content: center;
        min-height: 42px;
    }
    .sd-member-chip {
        width: 100%;
        box-sizing: border-box;
    }
    .sd-content-header {
        padding: 12px 14px;
    }
    .sd-content-body {
        padding: 14px;
    }
}
</style>

<div class="ilmuna-student-dashboard">

    <!-- ==============================================================================
     * 1. STANDAR TABS SEGMENTED (ALA CURRICULUM.PHP)
     * ============================================================================== -->
    <div class="curriculum-main-tabs" id="studentMainTabs" role="tablist">
        <button type="button" class="curriculum-tab-btn is-active" data-tab="semua">
            <iconify-icon icon="solar:layers-minimalistic-bold"></iconify-icon>
            <span class="curriculum-tab-label">Semua Aktivitas</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="tugas">
            <iconify-icon icon="solar:clipboard-check-bold"></iconify-icon>
            <span class="curriculum-tab-label">Tugas Belajar</span>
            <span class="curriculum-tab-counter" id="badgeTabTugas"><?= count($assignments) ?></span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="kelompok">
            <iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon>
            <span class="curriculum-tab-label">Kelompok Kolaboratif</span>
            <span class="curriculum-tab-counter" id="badgeTabKelompok"><?= count($myGroups) ?></span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="materi">
            <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>
            <span class="curriculum-tab-label">Materi & Modul PAI</span>
            <span class="curriculum-tab-counter" id="badgeTabMateri"><?= count($recentMateri) ?></span>
        </button>
    </div>

    <!-- ==============================================================================
     * 2. STANDAR TOOLBAR AKSI (ALA ASSESSMENT-ACCESS.PHP) - ZERO TITLE, ZERO HERO
     * ============================================================================== -->
    <div class="ilmuna-filter-box">
        <div class="ilmuna-filter-topline">
            <!-- Identitas Ringkas Siswa (Direct Data Chip) -->
            <div class="sd-user-chip">
                <?php if (!empty($fotoSiswaUrl)): ?>
                    <img src="<?= htmlspecialchars($fotoSiswaUrl) ?>" alt="Avatar" class="sd-user-avatar" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="sd-user-avatar-fallback" style="display: none;"><?= $inisialSiswa ?></div>
                <?php else: ?>
                    <div class="sd-user-avatar-fallback"><?= $inisialSiswa ?></div>
                <?php endif; ?>
                <div>
                    <div class="sd-user-name"><?= htmlspecialchars($namaSiswa) ?></div>
                    <div class="sd-user-meta">
                        <span>NISN: <strong><?= htmlspecialchars($nisnSiswa) ?></strong></span>
                        <span>&bull;</span>
                        <span style="color: var(--lumina-teal); font-weight: 700;"><?= htmlspecialchars($namaKelas) ?></span>
                    </div>
                </div>
            </div>

            <!-- Pintasan Navigasi Aksi Siswa -->
            <div class="ilmuna-nav-actions-wrap">
                <a href="<?= BASE_URL ?>/dashboard/siswa/absensi.php" class="ilmuna-nav-link ilmuna-nav-link--emerald" title="Presensi KBM & Live QR">
                    <iconify-icon icon="solar:qr-code-bold"></iconify-icon>
                    <span>Presensi KBM</span>
                </a>
                <a href="<?= BASE_URL ?>/dashboard/siswa/mutabaah.php" class="ilmuna-nav-link ilmuna-nav-link--gold" title="Isi Jurnal Mutaba'ah Ibadah Yaumiyah">
                    <iconify-icon icon="solar:hand-heart-bold"></iconify-icon>
                    <span>Mutaba'ah</span>
                </a>
                <a href="<?= BASE_URL ?>/dashboard/siswa/tugas.php" class="ilmuna-nav-link ilmuna-nav-link--teal" title="Tugas Belajar Siswa">
                    <iconify-icon icon="solar:pen-new-square-bold"></iconify-icon>
                    <span>Tugas (<?= $pendingCount ?>)</span>
                </a>
                <a href="<?= BASE_URL ?>/dashboard/siswa/materi.php" class="ilmuna-nav-link ilmuna-nav-link--sky" title="Buka Modul Materi Digital">
                    <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>
                    <span>Materi PAI</span>
                </a>
            </div>

            <!-- Pencarian Cepat Konten Inline -->
            <div class="ilmuna-search-wrap">
                <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                <input type="text" id="liveSearchInput" class="ilmuna-search-input" placeholder="Cari tugas, kelompok, materi..." autocomplete="off">
            </div>
        </div>
    </div>

    <!-- ==============================================================================
     * 3. PANE 1: TAB SEMUA AKTIVITAS (DUAL-COLUMN DEFAULT VIEW)
     * ============================================================================== -->
    <div class="sd-tab-content-pane is-active" id="paneSemua">
        <div class="sd-data-grid">
            
            <!-- KOLOM KIRI: TUGAS & KELOMPOK -->
            <div class="sd-data-pane">
                
                <!-- KARTU A: DAFTAR TUGAS BELAJAR TERBARU -->
                <div class="sd-content-card" id="cardTugasWrap">
                    <div class="sd-content-header sd-content-header--tugas">
                        <h3 class="sd-content-title">
                            <iconify-icon icon="solar:clipboard-check-bold" style="color: var(--lumina-teal); font-size: 19px;"></iconify-icon>
                            <span>Daftar Tugas & Evaluasi Belajar Terbaru</span>
                        </h3>
                        <a href="<?= BASE_URL ?>/dashboard/siswa/tugas.php" class="sd-content-link">
                            <span>Buka Portal Tugas</span>
                            <iconify-icon icon="solar:alt-arrow-right-linear"></iconify-icon>
                        </a>
                    </div>
                    <div class="sd-content-body">
                        <?php if (empty($assignments)): ?>
                            <div style="text-align: center; padding: 28px 14px; color: #64748B;">
                                <iconify-icon icon="solar:checklist-minimalistic-bold" style="font-size: 40px; color: #CBD5E1; margin-bottom: 6px;"></iconify-icon>
                                <div style="font-weight: 700; font-size: 13.5px; color: var(--lumina-navy);">Alhamdulillah, Belum Ada Tugas Aktif</div>
                                <p style="font-size: 12px; margin: 4px 0 0;">Semua tugas untuk rombel <?= htmlspecialchars($namaKelas) ?> telah selesai.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($assignments as $a): 
                                $isKel = ($a['jenis_penugasan'] === 'Kelompok');
                                $dlText = !empty($a['tenggat_waktu']) ? date('d M Y, H:i', strtotime($a['tenggat_waktu'])) . ' WIB' : 'Tidak Terbatas';
                                $statusKey = $a['student_status'] ?? 'pending';
                            ?>
                                <div class="sd-task-item searchable-item" data-search="<?= strtolower(htmlspecialchars($a['judul_tugas'] . ' ' . $a['nama_mata_pelajaran'] . ' ' . $a['nama_guru'])) ?>">
                                    <div style="min-width: 0;">
                                        <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                                            <span class="sd-badge <?= $isKel ? 'sd-badge--purple' : 'sd-badge--sky' ?>">
                                                <?= $isKel ? '👥 Kelompok' : '👤 Individu' ?>
                                            </span>
                                            <span class="sd-badge sd-badge--sky" style="background:#F1F5F9; color:#475569;">
                                                <?= htmlspecialchars($a['nama_mata_pelajaran']) ?>
                                            </span>
                                        </div>
                                        <h4 class="sd-task-title"><?= htmlspecialchars($a['judul_tugas']) ?></h4>
                                        <div class="sd-task-meta">
                                            <span><iconify-icon icon="solar:user-bold"></iconify-icon> <?= htmlspecialchars($a['nama_guru']) ?></span>
                                            <span>&bull;</span>
                                            <span><iconify-icon icon="solar:calendar-date-bold"></iconify-icon> Tenggat: <strong><?= $dlText ?></strong></span>
                                        </div>
                                    </div>

                                    <div class="sd-task-action-wrap" style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px; flex-shrink: 0;">
                                        <?php if ($statusKey === 'graded' && $a['nilai']): 
                                            $scoreVal = (float)$a['nilai']['nilai'];
                                            $pred = $a['nilai']['predikat'] ?: hitungPredikatNilai($scoreVal);
                                        ?>
                                            <span class="sd-badge sd-badge--emerald" style="font-size: 11.5px; padding: 4px 8px;">
                                                <iconify-icon icon="solar:diploma-verified-bold"></iconify-icon>
                                                <span>Nilai: <?= number_format($scoreVal, 1) ?> (<?= $pred ?>)</span>
                                            </span>
                                            <a href="<?= BASE_URL ?>/dashboard/siswa/tugas.php" class="btn btn-sm btn-secondary" style="font-size: 11px; padding: 4px 10px;">
                                                Evaluasi
                                            </a>
                                        <?php elseif ($statusKey === 'submitted'): ?>
                                            <span class="sd-badge sd-badge--sky" style="font-size: 11px; padding: 4px 8px;">
                                                <iconify-icon icon="solar:clock-circle-bold"></iconify-icon>
                                                <span>Menunggu Penilaian</span>
                                            </span>
                                            <a href="<?= BASE_URL ?>/dashboard/siswa/tugas.php" class="btn btn-sm btn-secondary" style="font-size: 11px; padding: 4px 10px;">
                                                Detail Berkas
                                            </a>
                                        <?php elseif ($statusKey === 'revision'): ?>
                                            <span class="sd-badge sd-badge--rose" style="font-size: 11px; padding: 4px 8px;">
                                                <iconify-icon icon="solar:danger-triangle-bold"></iconify-icon>
                                                <span>Perlu Revisi</span>
                                            </span>
                                            <a href="<?= BASE_URL ?>/dashboard/siswa/tugas.php" class="btn btn-sm btn-primary" style="background:#E11D48; border-color:#BE123C; font-size: 11px; padding: 4px 12px;">
                                                Revisi
                                            </a>
                                        <?php else: ?>
                                            <span class="sd-badge sd-badge--amber" style="font-size: 11px; padding: 4px 8px;">
                                                <iconify-icon icon="solar:hourglass-line-bold"></iconify-icon>
                                                <span>Belum Dikerjakan</span>
                                            </span>
                                            <a href="<?= BASE_URL ?>/dashboard/siswa/tugas.php" class="btn btn-sm btn-primary" style="background:var(--lumina-teal); border-color:var(--lumina-teal); font-size: 11px; padding: 4px 12px;">
                                                Kumpulkan
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- KARTU B: KELOMPOK BELAJAR KOLABORATIF SISWA -->
                <div class="sd-content-card" id="cardKelompokWrap">
                    <div class="sd-content-header sd-content-header--kelompok">
                        <h3 class="sd-content-title">
                            <iconify-icon icon="solar:users-group-two-rounded-bold" style="color: #7C3AED; font-size: 19px;"></iconify-icon>
                            <span>Kelompok Belajar Kolaboratif Siswa</span>
                        </h3>
                        <span class="sd-badge sd-badge--purple"><?= count($myGroups) ?> Terdaftar</span>
                    </div>
                    <div class="sd-content-body">
                        <?php if (empty($myGroups)): ?>
                            <div style="text-align: center; padding: 26px 14px; color: #64748B;">
                                <iconify-icon icon="solar:users-group-rounded-linear" style="font-size: 38px; color: #CBD5E1; margin-bottom: 6px;"></iconify-icon>
                                <div style="font-weight: 700; font-size: 13px; color: var(--lumina-navy);">Belum Masuk Kelompok</div>
                                <p style="font-size: 11.5px; margin: 4px 0 0;">Guru belum menugaskan kelompok pembelajaran untuk akun Anda.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($myGroups as $grp): 
                                $isMeKetua = ((int)$grp['id_ketua_kelompok'] === $idSiswa);
                                $namaKetua = $grp['nama_ketua'] ?: 'Belum ditentukan';
                                $fotoKetuaUrl = getStudentPhotoPath($grp['foto_ketua'] ?? '');
                            ?>
                                <div class="sd-group-box searchable-item" data-search="<?= strtolower(htmlspecialchars($grp['nama_kelompok'] . ' ' . $grp['judul_tugas'] . ' ' . $namaKetua)) ?>">
                                    <div class="sd-group-header">
                                        <div>
                                            <div class="sd-group-name">
                                                <iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon>
                                                <span><?= htmlspecialchars($grp['nama_kelompok']) ?></span>
                                            </div>
                                            <div style="font-size: 11.5px; color: #64748B; margin-top: 2px;">
                                                Tugas: <strong><?= htmlspecialchars($grp['judul_tugas']) ?></strong> (<?= htmlspecialchars($grp['nama_mata_pelajaran']) ?>)
                                            </div>
                                        </div>
                                        <?php if ($isMeKetua): ?>
                                            <span class="sd-badge sd-badge--amber" style="font-weight: 800;">
                                                👑 Anda: Ketua Kelompok
                                            </span>
                                        <?php else: ?>
                                            <span class="sd-badge sd-badge--purple" style="font-weight: 700;">
                                                👤 Anda: Anggota
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Baris Info Ketua dengan Foto & NISN -->
                                    <div style="font-size: 12px; color: #334155; background: #FFFFFF; border: 1px solid #E9D5FF; border-radius: 8px; padding: 7px 10px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <?php if (!empty($fotoKetuaUrl)): ?>
                                                <img src="<?= htmlspecialchars($fotoKetuaUrl) ?>" alt="Ketua" class="sd-member-avatar" onerror="this.style.display='none';">
                                            <?php endif; ?>
                                            <div>
                                                <span>👑 Ketua: <strong><?= htmlspecialchars($namaKetua) ?></strong></span>
                                                <?php if (!empty($grp['nisn_ketua'])): ?>
                                                    <span style="font-size: 10.5px; color: #64748B; margin-left: 4px;">(NISN: <?= htmlspecialchars($grp['nisn_ketua']) ?>)</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($isMeKetua): ?>
                                            <span style="font-size: 10px; color: #059669; font-weight: 800; background: #DCFCE7; padding: 2px 6px; border-radius: 4px;">Hak Unggah</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Daftar Anggota Lengkap Disertai Foto Siswa & NISN -->
                                    <div style="font-size: 11px; font-weight: 700; color: #7E22CE; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
                                        Anggota Kelompok (<?= count($grp['anggota'] ?? []) ?> Siswa):
                                    </div>
                                    <div class="sd-members-wrap">
                                        <?php foreach ($grp['anggota'] as $ang): 
                                            $chipClass = $ang['is_ketua'] ? 'is-ketua' : ($ang['is_me'] ? 'is-me' : '');
                                            $photoUrl = getStudentPhotoPath($ang['foto']);
                                            $initial = mb_substr($ang['nama_lengkap'], 0, 1, 'UTF-8');
                                            $nisnAng = $ang['nisn'] ?: $ang['nis'];
                                        ?>
                                            <span class="sd-member-chip <?= $chipClass ?>" title="NISN: <?= htmlspecialchars($nisnAng) ?>">
                                                <?php if (!empty($photoUrl)): ?>
                                                    <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Foto" class="sd-member-avatar" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                    <div class="sd-member-avatar-fallback" style="display: none;"><?= $initial ?></div>
                                                <?php else: ?>
                                                    <div class="sd-member-avatar-fallback"><?= $initial ?></div>
                                                <?php endif; ?>
                                                <span>
                                                    <?php if ($ang['is_ketua']): ?>👑 <?php endif; ?>
                                                    <?= htmlspecialchars($ang['nama_lengkap']) ?>
                                                    <?php if ($ang['is_me']): ?><small style="font-weight: 800; opacity: 0.9;">(Anda)</small><?php endif; ?>
                                                </span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- KOLOM KANAN: MATERI & PENGINGAT MUTABA'AH -->
            <div class="sd-data-pane">
                
                <!-- KARTU C: MATERI PEMBELAJARAN PAI TERBARU -->
                <div class="sd-content-card" id="cardMateriWrap">
                    <div class="sd-content-header sd-content-header--materi">
                        <h3 class="sd-content-title">
                            <iconify-icon icon="solar:book-bookmark-bold" style="color: var(--lumina-teal); font-size: 19px;"></iconify-icon>
                            <span>Materi & Modul Pembelajaran</span>
                        </h3>
                        <a href="<?= BASE_URL ?>/dashboard/siswa/materi.php" class="sd-content-link">
                            <span>Buka Modul</span>
                            <iconify-icon icon="solar:alt-arrow-right-linear"></iconify-icon>
                        </a>
                    </div>
                    <div class="sd-content-body">
                        <?php if (empty($recentMateri)): ?>
                            <div style="text-align: center; padding: 26px 12px; color: #64748B;">
                                <iconify-icon icon="solar:documents-minimalistic-linear" style="font-size: 38px; color: #CBD5E1; margin-bottom: 6px;"></iconify-icon>
                                <div style="font-weight: 700; font-size: 13px; color: var(--lumina-navy);">Belum Ada Materi Rilis</div>
                                <p style="font-size: 11.5px; margin: 4px 0 0;">Modul materi pembelajaran akan diunggah oleh guru pengampu.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentMateri as $m): 
                                $tglMateri = !empty($m['created_at']) ? date('d M Y', strtotime($m['created_at'])) : '-';
                            ?>
                                <div class="sd-materi-item searchable-item" data-search="<?= strtolower(htmlspecialchars($m['judul_materi'] . ' ' . $m['nama_mata_pelajaran'] . ' ' . $m['nama_guru'])) ?>">
                                    <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                                        <div style="width: 34px; height: 34px; border-radius: 9px; background: #E0F2FE; color: #0284C7; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0;">
                                            <iconify-icon icon="solar:document-text-bold"></iconify-icon>
                                        </div>
                                        <div style="min-width: 0;">
                                            <a href="<?= BASE_URL ?>/dashboard/siswa/materi.php" style="font-size: 12.5px; font-weight: 700; color: var(--lumina-navy); text-decoration: none; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?= htmlspecialchars($m['judul_materi']) ?>
                                            </a>
                                            <div style="font-size: 11px; color: #64748B;">
                                                <?= htmlspecialchars($m['nama_mata_pelajaran']) ?> &bull; <?= $tglMateri ?>
                                            </div>
                                        </div>
                                    </div>
                                    <a href="<?= BASE_URL ?>/dashboard/siswa/materi.php" class="btn btn-sm btn-secondary" style="font-size: 11px; padding: 4px 9px; flex-shrink: 0;">
                                        Buka
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- KARTU D: PENGINGAT JURNAL MUTABA'AH YAUMIYAH -->
                <div class="sd-content-card" style="background: linear-gradient(135deg, #FFFBEB 0%, #FFFFFF 100%); border-color: #FDE68A;">
                    <div class="sd-content-header" style="background: #FEF3C7; border-bottom-color: #FDE68A;">
                        <h3 class="sd-content-title" style="color: #92400E;">
                            <iconify-icon icon="solar:hand-heart-bold" style="color: #D97706; font-size: 19px;"></iconify-icon>
                            <span>Jurnal Mutaba'ah Harian</span>
                        </h3>
                        <span class="sd-badge sd-badge--amber" style="background: #FFFFFF; color: #92400E; border: 1px solid #FDE68A;">
                            <?= $todayMutabaah ? 'Sudah Diisi' : 'Belum Diisi' ?>
                        </span>
                    </div>
                    <div class="sd-content-body" style="font-size: 12.5px; color: #78350F; line-height: 1.5;">
                        <p style="margin: 0 0 12px;">
                            Istiqomahkan sholat berjama'ah 5 waktu, tilawah Al-Qur'an, dan amalan sunnah harian Anda.
                        </p>
                        <a href="<?= BASE_URL ?>/dashboard/siswa/mutabaah.php" class="btn btn-primary btn-sm" style="background: #D97706; border-color: #B45309; width: 100%; justify-content: center; min-height: 38px; font-weight: 700;">
                            <iconify-icon icon="solar:check-read-bold" style="font-size: 16px;"></iconify-icon>
                            <span><?= $todayMutabaah ? 'Perbarui Jurnal Hari Ini' : 'Isi Jurnal Sekarang' ?></span>
                        </a>
                    </div>
                </div>

            </div>

        </div>
    </div>

    <!-- ==============================================================================
     * 4. PANE 2: TAB TUGAS BELAJAR SAJA
     * ============================================================================== -->
    <div class="sd-tab-content-pane" id="paneTugas">
        <div class="sd-content-card">
            <div class="sd-content-header sd-content-header--tugas">
                <h3 class="sd-content-title">
                    <iconify-icon icon="solar:clipboard-check-bold" style="color: var(--lumina-teal); font-size: 19px;"></iconify-icon>
                    <span>Seluruh Tugas Belajar & Evaluasi (<?= count($assignments) ?>)</span>
                </h3>
                <a href="<?= BASE_URL ?>/dashboard/siswa/tugas.php" class="sd-content-link">
                    <span>Lihat Halaman Penuh</span>
                    <iconify-icon icon="solar:alt-arrow-right-linear"></iconify-icon>
                </a>
            </div>
            <div class="sd-content-body">
                <?php if (empty($assignments)): ?>
                    <div style="text-align: center; padding: 36px 14px; color: #64748B;">
                        <iconify-icon icon="solar:checklist-minimalistic-bold" style="font-size: 44px; color: #CBD5E1; margin-bottom: 6px;"></iconify-icon>
                        <div style="font-weight: 700; font-size: 14px; color: var(--lumina-navy);">Tidak Ada Tugas</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($assignments as $a): 
                        $isKel = ($a['jenis_penugasan'] === 'Kelompok');
                        $dlText = !empty($a['tenggat_waktu']) ? date('d M Y, H:i', strtotime($a['tenggat_waktu'])) . ' WIB' : 'Tidak Terbatas';
                        $statusKey = $a['student_status'] ?? 'pending';
                    ?>
                        <div class="sd-task-item searchable-item" data-search="<?= strtolower(htmlspecialchars($a['judul_tugas'] . ' ' . $a['nama_mata_pelajaran'] . ' ' . $a['nama_guru'])) ?>">
                            <div style="min-width: 0;">
                                <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                                    <span class="sd-badge <?= $isKel ? 'sd-badge--purple' : 'sd-badge--sky' ?>">
                                        <?= $isKel ? '👥 Kelompok' : '👤 Individu' ?>
                                    </span>
                                    <span class="sd-badge sd-badge--sky" style="background:#F1F5F9; color:#475569;">
                                        <?= htmlspecialchars($a['nama_mata_pelajaran']) ?>
                                    </span>
                                </div>
                                <h4 class="sd-task-title"><?= htmlspecialchars($a['judul_tugas']) ?></h4>
                                <div class="sd-task-meta">
                                    <span><iconify-icon icon="solar:user-bold"></iconify-icon> <?= htmlspecialchars($a['nama_guru']) ?></span>
                                    <span>&bull;</span>
                                    <span><iconify-icon icon="solar:calendar-date-bold"></iconify-icon> Tenggat: <strong><?= $dlText ?></strong></span>
                                </div>
                            </div>

                            <div class="sd-task-action-wrap" style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px; flex-shrink: 0;">
                                <?php if ($statusKey === 'graded' && $a['nilai']): 
                                    $scoreVal = (float)$a['nilai']['nilai'];
                                    $pred = $a['nilai']['predikat'] ?: hitungPredikatNilai($scoreVal);
                                ?>
                                    <span class="sd-badge sd-badge--emerald" style="font-size: 11.5px; padding: 4px 8px;">
                                        <iconify-icon icon="solar:diploma-verified-bold"></iconify-icon>
                                        <span>Nilai: <?= number_format($scoreVal, 1) ?> (<?= $pred ?>)</span>
                                    </span>
                                    <a href="<?= BASE_URL ?>/dashboard/siswa/tugas.php" class="btn btn-sm btn-secondary" style="font-size: 11px; padding: 4px 10px;">
                                        Evaluasi
                                    </a>
                                <?php elseif ($statusKey === 'submitted'): ?>
                                    <span class="sd-badge sd-badge--sky" style="font-size: 11px; padding: 4px 8px;">
                                        <iconify-icon icon="solar:clock-circle-bold"></iconify-icon>
                                        <span>Menunggu Penilaian</span>
                                    </span>
                                    <a href="<?= BASE_URL ?>/dashboard/siswa/tugas.php" class="btn btn-sm btn-secondary" style="font-size: 11px; padding: 4px 10px;">
                                        Detail Berkas
                                    </a>
                                <?php elseif ($statusKey === 'revision'): ?>
                                    <span class="sd-badge sd-badge--rose" style="font-size: 11px; padding: 4px 8px;">
                                        <iconify-icon icon="solar:danger-triangle-bold"></iconify-icon>
                                        <span>Perlu Revisi</span>
                                    </span>
                                    <a href="<?= BASE_URL ?>/dashboard/siswa/tugas.php" class="btn btn-sm btn-primary" style="background:#E11D48; border-color:#BE123C; font-size: 11px; padding: 4px 12px;">
                                        Revisi
                                    </a>
                                <?php else: ?>
                                    <span class="sd-badge sd-badge--amber" style="font-size: 11px; padding: 4px 8px;">
                                        <iconify-icon icon="solar:hourglass-line-bold"></iconify-icon>
                                        <span>Belum Dikerjakan</span>
                                    </span>
                                    <a href="<?= BASE_URL ?>/dashboard/siswa/tugas.php" class="btn btn-sm btn-primary" style="background:var(--lumina-teal); border-color:var(--lumina-teal); font-size: 11px; padding: 4px 12px;">
                                        Kumpulkan
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ==============================================================================
     * 5. PANE 3: TAB KELOMPOK KOLABORATIF SAJA
     * ============================================================================== -->
    <div class="sd-tab-content-pane" id="paneKelompok">
        <div class="sd-content-card">
            <div class="sd-content-header sd-content-header--kelompok">
                <h3 class="sd-content-title">
                    <iconify-icon icon="solar:users-group-two-rounded-bold" style="color: #7C3AED; font-size: 19px;"></iconify-icon>
                    <span>Kelompok Belajar Kolaboratif Siswa (<?= count($myGroups) ?>)</span>
                </h3>
            </div>
            <div class="sd-content-body">
                <?php if (empty($myGroups)): ?>
                    <div style="text-align: center; padding: 36px 14px; color: #64748B;">
                        <iconify-icon icon="solar:users-group-rounded-linear" style="font-size: 42px; color: #CBD5E1; margin-bottom: 6px;"></iconify-icon>
                        <div style="font-weight: 700; font-size: 13.5px; color: var(--lumina-navy);">Belum Ada Kelompok</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($myGroups as $grp): 
                        $isMeKetua = ((int)$grp['id_ketua_kelompok'] === $idSiswa);
                        $namaKetua = $grp['nama_ketua'] ?: 'Belum ditentukan';
                        $fotoKetuaUrl = getStudentPhotoPath($grp['foto_ketua'] ?? '');
                    ?>
                        <div class="sd-group-box searchable-item" data-search="<?= strtolower(htmlspecialchars($grp['nama_kelompok'] . ' ' . $grp['judul_tugas'] . ' ' . $namaKetua)) ?>">
                            <div class="sd-group-header">
                                <div>
                                    <div class="sd-group-name">
                                        <iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon>
                                        <span><?= htmlspecialchars($grp['nama_kelompok']) ?></span>
                                    </div>
                                    <div style="font-size: 11.5px; color: #64748B; margin-top: 2px;">
                                        Tugas: <strong><?= htmlspecialchars($grp['judul_tugas']) ?></strong> (<?= htmlspecialchars($grp['nama_mata_pelajaran']) ?>)
                                    </div>
                                </div>
                                <?php if ($isMeKetua): ?>
                                    <span class="sd-badge sd-badge--amber" style="font-weight: 800;">👑 Anda: Ketua</span>
                                <?php else: ?>
                                    <span class="sd-badge sd-badge--purple" style="font-weight: 700;">👤 Anda: Anggota</span>
                                <?php endif; ?>
                            </div>

                            <div style="font-size: 12px; color: #334155; background: #FFFFFF; border: 1px solid #E9D5FF; border-radius: 8px; padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <?php if (!empty($fotoKetuaUrl)): ?>
                                        <img src="<?= htmlspecialchars($fotoKetuaUrl) ?>" alt="Ketua" class="sd-member-avatar" onerror="this.style.display='none';">
                                    <?php endif; ?>
                                    <div>
                                        <span>👑 Ketua Kelompok: <strong><?= htmlspecialchars($namaKetua) ?></strong></span>
                                        <?php if (!empty($grp['nisn_ketua'])): ?>
                                            <span style="font-size: 11px; color: #64748B;">(NISN: <?= htmlspecialchars($grp['nisn_ketua']) ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($isMeKetua): ?>
                                    <span style="font-size: 10px; color: #059669; font-weight: 800; background: #DCFCE7; padding: 2px 6px; border-radius: 4px;">Hak Unggah</span>
                                <?php endif; ?>
                            </div>

                            <div style="font-size: 11px; font-weight: 700; color: #7E22CE; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                Anggota Kelompok (<?= count($grp['anggota'] ?? []) ?> Siswa):
                            </div>
                            <div class="sd-members-wrap">
                                <?php foreach ($grp['anggota'] as $ang): 
                                    $chipClass = $ang['is_ketua'] ? 'is-ketua' : ($ang['is_me'] ? 'is-me' : '');
                                    $photoUrl = getStudentPhotoPath($ang['foto']);
                                    $initial = mb_substr($ang['nama_lengkap'], 0, 1, 'UTF-8');
                                    $nisnAng = $ang['nisn'] ?: $ang['nis'];
                                ?>
                                    <span class="sd-member-chip <?= $chipClass ?>" title="NISN: <?= htmlspecialchars($nisnAng) ?>">
                                        <?php if (!empty($photoUrl)): ?>
                                            <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Foto" class="sd-member-avatar" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <div class="sd-member-avatar-fallback" style="display: none;"><?= $initial ?></div>
                                        <?php else: ?>
                                            <div class="sd-member-avatar-fallback"><?= $initial ?></div>
                                        <?php endif; ?>
                                        <span>
                                            <?php if ($ang['is_ketua']): ?>👑 <?php endif; ?>
                                            <?= htmlspecialchars($ang['nama_lengkap']) ?>
                                            <?php if ($ang['is_me']): ?><small style="font-weight: 800; opacity: 0.9;">(Anda)</small><?php endif; ?>
                                        </span>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ==============================================================================
     * 6. PANE 4: TAB MATERI & MODUL PAI SAJA
     * ============================================================================== -->
    <div class="sd-tab-content-pane" id="paneMateri">
        <div class="sd-content-card">
            <div class="sd-content-header sd-content-header--materi">
                <h3 class="sd-content-title">
                    <iconify-icon icon="solar:book-bookmark-bold" style="color: var(--lumina-teal); font-size: 19px;"></iconify-icon>
                    <span>Materi & Modul Pembelajaran PAI (<?= count($recentMateri) ?>)</span>
                </h3>
                <a href="<?= BASE_URL ?>/dashboard/siswa/materi.php" class="sd-content-link">
                    <span>Lihat Halaman Materi Penuh</span>
                    <iconify-icon icon="solar:alt-arrow-right-linear"></iconify-icon>
                </a>
            </div>
            <div class="sd-content-body">
                <?php if (empty($recentMateri)): ?>
                    <div style="text-align: center; padding: 36px 14px; color: #64748B;">
                        <iconify-icon icon="solar:documents-minimalistic-linear" style="font-size: 42px; color: #CBD5E1; margin-bottom: 6px;"></iconify-icon>
                        <div style="font-weight: 700; font-size: 13.5px; color: var(--lumina-navy);">Belum Ada Materi</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($recentMateri as $m): 
                        $tglMateri = !empty($m['created_at']) ? date('d M Y', strtotime($m['created_at'])) : '-';
                    ?>
                        <div class="sd-materi-item searchable-item" data-search="<?= strtolower(htmlspecialchars($m['judul_materi'] . ' ' . $m['nama_mata_pelajaran'] . ' ' . $m['nama_guru'])) ?>">
                            <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                                <div style="width: 36px; height: 36px; border-radius: 9px; background: #E0F2FE; color: #0284C7; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;">
                                    <iconify-icon icon="solar:document-text-bold"></iconify-icon>
                                </div>
                                <div style="min-width: 0;">
                                    <a href="<?= BASE_URL ?>/dashboard/siswa/materi.php" style="font-size: 13px; font-weight: 700; color: var(--lumina-navy); text-decoration: none; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <?= htmlspecialchars($m['judul_materi']) ?>
                                    </a>
                                    <div style="font-size: 11.5px; color: #64748B;">
                                        <?= htmlspecialchars($m['nama_mata_pelajaran']) ?> &bull; <?= $tglMateri ?> &bull; <iconify-icon icon="solar:user-bold"></iconify-icon> <?= htmlspecialchars($m['nama_guru']) ?>
                                    </div>
                                </div>
                            </div>
                            <a href="<?= BASE_URL ?>/dashboard/siswa/materi.php" class="btn btn-sm btn-secondary" style="font-size: 11.5px; padding: 5px 12px; flex-shrink: 0;">
                                Buka
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- SCRIPT NAVIGASI TAB & LIVE SEARCH IN-PLACE (ZERO RELOAD POLICY) -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Segmented Control Switcher
    const tabButtons = document.querySelectorAll('#studentMainTabs .curriculum-tab-btn');
    const panes = {
        'semua': document.getElementById('paneSemua'),
        'tugas': document.getElementById('paneTugas'),
        'kelompok': document.getElementById('paneKelompok'),
        'materi': document.getElementById('paneMateri')
    };

    tabButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            tabButtons.forEach(b => b.classList.remove('is-active'));
            this.classList.add('is-active');

            const targetTab = this.getAttribute('data-tab');
            for (const key in panes) {
                if (panes[key]) {
                    if (key === targetTab) {
                        panes[key].classList.add('is-active');
                    } else {
                        panes[key].classList.remove('is-active');
                    }
                }
            }
        });
    });

    // 2. Live Search In-Place (Zero Page Reload)
    const searchInput = document.getElementById('liveSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            const items = document.querySelectorAll('.searchable-item');

            items.forEach(item => {
                const text = (item.getAttribute('data-search') || '').toLowerCase();
                if (!query || text.includes(query)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    }
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
