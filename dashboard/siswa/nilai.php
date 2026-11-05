<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PORTAL NILAI KOGNITIF SISWA TERPADU (SINGLE TABLE)
 * ==============================================================================
 * Filosofi Desain "Data-First" (Zero Title) Sesuai Standar README.md:
 * 1. Seluruh penilaian kognitif disajikan langsung dalam 1 Tabel Komprehensif:
 *    - Baris: Mata Pelajaran pada rombel kelas siswa.
 *    - Kolom: Mapel & Pengampu | Tugas | Proyek | Formatif | Diagnostik | Sumatif LM
 *    - Setiap sel memuat rincian ke berapa (T1, T2, P1, F1, D1, S1) beserta nilai & keterangan.
 * 2. Murni 5 Aspek Kognitif: TANPA Aspek Afektif (Sikap) & TANPA Aspek Psikomotorik (Praktik).
 * 3. Sumatif khusus Lingkup Materi (Non-STS & Non-SAS).
 * 4. Dual-View: Desktop Table Berdensitas Tinggi + Mobile Card Stack Ultra-Responsif (360px+).
 * 5. Interactive Touch/Click: Setiap chip nilai di desktop & mobile dapat diklik/ditekan
 *    untuk membuka modal rincian nilai, remedial, dan catatan evaluasi guru.
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

// Hak Akses: Siswa (Izinkan juga preview oleh Admin & Guru untuk pendampingan belajar)
requireRole(['siswa', 'admin', 'guru']);
$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
$currentUser = getCurrentUser();

$activeRole = hasRole('siswa') ? 'siswa' : (hasRole('guru') ? 'guru' : 'admin');

/* ==============================================================================
 * 1. RESOLUSI DATA SISWA & KELAS TERDAFTAR (STRICT CLASS ENROLMENT)
 * ============================================================================== */
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
        WHERE s.nis = ? OR s.nisn = ? OR s.gmail = ?
        LIMIT 1
    ");
    $stmtS->execute([
        $currentUser['username'],
        $currentUser['username'],
        $currentUser['email'] ?? ''
    ]);
    $currentSiswa = $stmtS->fetch(PDO::FETCH_ASSOC);
}

// Fallback jika belum ditautkan
if (!$currentSiswa) {
    $stmtFallback = $pdo->query("
        SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran 
        FROM siswa s 
        LEFT JOIN kelas k ON s.id_kelas = k.id 
        ORDER BY s.id ASC LIMIT 1
    ");
    $currentSiswa = $stmtFallback->fetch(PDO::FETCH_ASSOC);
}

$idSiswa = (int)($currentSiswa['id'] ?? 0);
$idKelas = (int)($currentSiswa['id_kelas'] ?? 0);

/* ==============================================================================
 * 2. MENGAMBIL SELURUH MATA PELAJARAN DI KELAS SISWA (KMP)
 * ============================================================================== */
$kmpList = [];
if ($idKelas > 0) {
    $stmtKmp = $pdo->prepare("
        SELECT kmp.id AS id_kmp, kmp.id_kelas, kmp.id_mata_pelajaran,
               mp.nama_mata_pelajaran, mp.kode_mapel, COALESCE(mp.kktp, 75.00) AS kktp,
               g.nama_guru, g.nip
        FROM kelas_mata_pelajaran kmp
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
        LEFT JOIN guru g ON gmp.id_guru = g.id
        WHERE kmp.id_kelas = ?
        ORDER BY mp.nama_mata_pelajaran ASC
    ");
    $stmtKmp->execute([$idKelas]);
    $kmpList = $stmtKmp->fetchAll(PDO::FETCH_ASSOC);
}

$kmpMap = [];
foreach ($kmpList as $m) {
    $kmpMap[(int)$m['id_kmp']] = $m;
}

/* ==============================================================================
 * 3. QUERY 5 ASPEK KOGNITIF (MURNI TANPA AFEKTIF & PSIKOMOTORIK)
 * ============================================================================== */

// A. TUGAS PEMBELAJARAN (NON-PROYEK)
$stmtTugas = $pdo->prepare("
    SELECT nt.id, nt.id_tugas, nt.id_kelas_mata_pelajaran,
           nt.nilai AS nilai_asli, nt.nilai_remedial, nt.nilai_akhir_remedial,
           nt.status_penilaian, nt.catatan_guru, nt.waktu_penilaian,
           t.judul_tugas, t.jenis_penugasan, t.pertemuan_ke, t.nilai_maksimal,
           mp.nama_mata_pelajaran, mp.kode_mapel, COALESCE(mp.kktp, 75.00) AS kktp_mapel
    FROM nilai_tugas nt
    JOIN tugas t ON nt.id_tugas = t.id
    JOIN kelas_mata_pelajaran kmp ON nt.id_kelas_mata_pelajaran = kmp.id
    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    WHERE nt.id_siswa = :id_siswa AND kmp.id_kelas = :id_kelas
          AND LOWER(t.jenis_penugasan) NOT LIKE '%proyek%'
          AND LOWER(t.judul_tugas) NOT LIKE '%proyek%'
    ORDER BY t.pertemuan_ke ASC, t.id ASC
");
$stmtTugas->execute([':id_siswa' => $idSiswa, ':id_kelas' => $idKelas]);
$tugasList = $stmtTugas->fetchAll(PDO::FETCH_ASSOC);

// B. PROYEK BELAJAR
$stmtProyek = $pdo->prepare("
    SELECT nt.id, nt.id_tugas, nt.id_kelas_mata_pelajaran,
           nt.nilai AS nilai_asli, nt.nilai_remedial, nt.nilai_akhir_remedial,
           nt.status_penilaian, nt.catatan_guru, nt.waktu_penilaian,
           t.judul_tugas, t.jenis_penugasan, t.pertemuan_ke, t.nilai_maksimal,
           mp.nama_mata_pelajaran, mp.kode_mapel, COALESCE(mp.kktp, 75.00) AS kktp_mapel
    FROM nilai_tugas nt
    JOIN tugas t ON nt.id_tugas = t.id
    JOIN kelas_mata_pelajaran kmp ON nt.id_kelas_mata_pelajaran = kmp.id
    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    WHERE nt.id_siswa = :id_siswa AND kmp.id_kelas = :id_kelas
          AND (LOWER(t.jenis_penugasan) LIKE '%proyek%' OR LOWER(t.judul_tugas) LIKE '%proyek%')
    ORDER BY t.pertemuan_ke ASC, t.id ASC
");
$stmtProyek->execute([':id_siswa' => $idSiswa, ':id_kelas' => $idKelas]);
$proyekList = $stmtProyek->fetchAll(PDO::FETCH_ASSOC);

// C. ASESMEN FORMATIF
$stmtFmt = $pdo->prepare("
    SELECT nf.id, nf.id_asesmen_formatif, nf.id_kelas_mata_pelajaran,
           nf.nilai_angka AS nilai_asli, nf.nilai_remedial, nf.nilai_akhir_remedial,
           nf.nilai_kktp, nf.predikat, nf.status_tuntas,
           nf.catatan_evaluasi AS catatan_guru, nf.waktu_penilaian,
           asf.judul_asesmen, asf.teknik_asesmen, asf.pertemuan_ke,
           mp.nama_mata_pelajaran, mp.kode_mapel
    FROM nilai_asesmen_formatif nf
    JOIN asesmen_formatif asf ON nf.id_asesmen_formatif = asf.id
    JOIN kelas_mata_pelajaran kmp ON nf.id_kelas_mata_pelajaran = kmp.id
    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    WHERE nf.id_siswa = :id_siswa AND kmp.id_kelas = :id_kelas
    ORDER BY asf.pertemuan_ke ASC, asf.id ASC
");
$stmtFmt->execute([':id_siswa' => $idSiswa, ':id_kelas' => $idKelas]);
$formatifList = $stmtFmt->fetchAll(PDO::FETCH_ASSOC);

// D. ASESMEN DIAGNOSTIK
$stmtDiag = $pdo->prepare("
    SELECT nd.id, nd.id_asesmen_diagnostik, nd.id_kelas_mata_pelajaran,
           nd.nilai_angka AS nilai_asli, nd.nilai_kktp, nd.predikat, nd.status_tuntas,
           nd.catatan_evaluasi AS catatan_guru, nd.waktu_penilaian,
           asd.judul_asesmen, asd.jenis_diagnostik, asd.pertemuan_ke,
           mp.nama_mata_pelajaran, mp.kode_mapel
    FROM nilai_asesmen_diagnostik nd
    JOIN asesmen_diagnostik asd ON nd.id_asesmen_diagnostik = asd.id
    JOIN kelas_mata_pelajaran kmp ON nd.id_kelas_mata_pelajaran = kmp.id
    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    WHERE nd.id_siswa = :id_siswa AND kmp.id_kelas = :id_kelas
    ORDER BY asd.pertemuan_ke ASC, asd.id ASC
");
$stmtDiag->execute([':id_siswa' => $idSiswa, ':id_kelas' => $idKelas]);
$diagnostikList = $stmtDiag->fetchAll(PDO::FETCH_ASSOC);

// E. ASESMEN SUMATIF (LINGKUP MATERI SAJA — NON-STS & NON-SAS)
$stmtSm = $pdo->prepare("
    SELECT ns.id, ns.id_asesmen_sumatif, ns.id_kelas_mata_pelajaran,
           ns.nilai_angka AS nilai_asli, ns.nilai_remedial, ns.nilai_akhir_remedial,
           ns.status_remedial, ns.catatan_remedial, ns.nilai_kktp,
           ns.predikat, ns.status_tuntas,
           ns.catatan_evaluasi AS catatan_guru, ns.waktu_penilaian,
           asum.judul_asesmen, asum.jenis_sumatif, asum.pertemuan_ke,
           mp.nama_mata_pelajaran, mp.kode_mapel
    FROM nilai_asesmen_sumatif ns
    JOIN asesmen_sumatif asum ON ns.id_asesmen_sumatif = asum.id
    JOIN kelas_mata_pelajaran kmp ON ns.id_kelas_mata_pelajaran = kmp.id
    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    WHERE ns.id_siswa = :id_siswa AND kmp.id_kelas = :id_kelas
          AND asum.jenis_sumatif NOT IN ('Sumatif Tengah Semester', 'Sumatif Akhir Semester')
    ORDER BY asum.pertemuan_ke ASC, asum.id ASC
");
$stmtSm->execute([':id_siswa' => $idSiswa, ':id_kelas' => $idKelas]);
$sumatifLmList = $stmtSm->fetchAll(PDO::FETCH_ASSOC);

/* ==============================================================================
 * 4. PENGELOMPOKKAN NILAI KE DALAM SETIAP MATA PELAJARAN (1 MAPEL = 1 ROW)
 * ============================================================================== */
function calculateEffectiveGrade($asli, $rem, $akhirRem) {
    if ($akhirRem !== null && $akhirRem !== '') return (float)$akhirRem;
    if ($rem !== null && $rem !== '') return (float)$rem;
    if ($asli !== null && $asli !== '') return (float)$asli;
    return null;
}

$mapelTable = [];
foreach ($kmpList as $m) {
    $mid = (int)$m['id_kmp'];
    $mapelTable[$mid] = [
        'info'              => $m,
        'tugas'             => [],
        'proyek'            => [],
        'formatif'          => [],
        'diagnostik'        => [],
        'sumatif_lm'        => [],
        'avg_tugas'         => null,
        'avg_proyek'        => null,
        'avg_formatif'      => null,
        'avg_diagnostik'    => null,
        'avg_sumatif_lm'    => null,
        'total_asesmen_mapel' => 0
    ];
}

// Map Tugas
$seqT = [];
foreach ($tugasList as $t) {
    $mid = (int)$t['id_kelas_mata_pelajaran'];
    if (isset($mapelTable[$mid])) {
        $seqT[$mid] = ($seqT[$mid] ?? 0) + 1;
        $eff = calculateEffectiveGrade($t['nilai_asli'], $t['nilai_remedial'], $t['nilai_akhir_remedial']);
        $t['label_code'] = 'T' . $seqT[$mid];
        $t['nilai_efektif'] = $eff;
        $t['aspek_nama'] = 'Tugas Pembelajaran';
        $mapelTable[$mid]['tugas'][] = $t;
        $mapelTable[$mid]['total_asesmen_mapel']++;
    }
}

// Map Proyek
$seqP = [];
foreach ($proyekList as $p) {
    $mid = (int)$p['id_kelas_mata_pelajaran'];
    if (isset($mapelTable[$mid])) {
        $seqP[$mid] = ($seqP[$mid] ?? 0) + 1;
        $eff = calculateEffectiveGrade($p['nilai_asli'], $p['nilai_remedial'], $p['nilai_akhir_remedial']);
        $p['label_code'] = 'P' . $seqP[$mid];
        $p['nilai_efektif'] = $eff;
        $p['aspek_nama'] = 'Proyek Belajar';
        $mapelTable[$mid]['proyek'][] = $p;
        $mapelTable[$mid]['total_asesmen_mapel']++;
    }
}

// Map Formatif
$seqF = [];
foreach ($formatifList as $f) {
    $mid = (int)$f['id_kelas_mata_pelajaran'];
    if (isset($mapelTable[$mid])) {
        $seqF[$mid] = ($seqF[$mid] ?? 0) + 1;
        $eff = calculateEffectiveGrade($f['nilai_asli'], $f['nilai_remedial'], $f['nilai_akhir_remedial']);
        $f['label_code'] = 'F' . $seqF[$mid];
        $f['nilai_efektif'] = $eff;
        $f['aspek_nama'] = 'Asesmen Formatif';
        $mapelTable[$mid]['formatif'][] = $f;
        $mapelTable[$mid]['total_asesmen_mapel']++;
    }
}

// Map Diagnostik
$seqD = [];
foreach ($diagnostikList as $d) {
    $mid = (int)$d['id_kelas_mata_pelajaran'];
    if (isset($mapelTable[$mid])) {
        $seqD[$mid] = ($seqD[$mid] ?? 0) + 1;
        $eff = ($d['nilai_asli'] !== null) ? (float)$d['nilai_asli'] : null;
        $d['label_code'] = 'D' . $seqD[$mid];
        $d['nilai_efektif'] = $eff;
        $d['aspek_nama'] = 'Asesmen Diagnostik';
        $mapelTable[$mid]['diagnostik'][] = $d;
        $mapelTable[$mid]['total_asesmen_mapel']++;
    }
}

// Map Sumatif LM
$seqS = [];
foreach ($sumatifLmList as $s) {
    $mid = (int)$s['id_kelas_mata_pelajaran'];
    if (isset($mapelTable[$mid])) {
        $seqS[$mid] = ($seqS[$mid] ?? 0) + 1;
        $eff = calculateEffectiveGrade($s['nilai_asli'], $s['nilai_remedial'], $s['nilai_akhir_remedial']);
        $s['label_code'] = 'S' . $seqS[$mid];
        $s['nilai_efektif'] = $eff;
        $s['aspek_nama'] = 'Sumatif Lingkup Materi';
        $mapelTable[$mid]['sumatif_lm'][] = $s;
        $mapelTable[$mid]['total_asesmen_mapel']++;
    }
}

// Hitung Rata-rata Sub-Aspek per Mapel
foreach ($mapelTable as $mid => &$row) {
    $calcAvg = function($items) {
        $valid = [];
        foreach ($items as $item) {
            if ($item['nilai_efektif'] !== null) {
                $valid[] = (float)$item['nilai_efektif'];
            }
        }
        return count($valid) > 0 ? (array_sum($valid) / count($valid)) : null;
    };

    $row['avg_tugas']      = $calcAvg($row['tugas']);
    $row['avg_proyek']     = $calcAvg($row['proyek']);
    $row['avg_formatif']   = $calcAvg($row['formatif']);
    $row['avg_diagnostik'] = $calcAvg($row['diagnostik']);
    $row['avg_sumatif_lm'] = $calcAvg($row['sumatif_lm']);
}
unset($row);

// Kalkulasi rata-rata per kolom aspek untuk tabel footer
$allTugasScores = [];
$allProyekScores = [];
$allFormatifScores = [];
$allDiagScores = [];
$allSumatifScores = [];

foreach ($mapelTable as $r) {
    if ($r['avg_tugas'] !== null) $allTugasScores[] = $r['avg_tugas'];
    if ($r['avg_proyek'] !== null) $allProyekScores[] = $r['avg_proyek'];
    if ($r['avg_formatif'] !== null) $allFormatifScores[] = $r['avg_formatif'];
    if ($r['avg_diagnostik'] !== null) $allDiagScores[] = $r['avg_diagnostik'];
    if ($r['avg_sumatif_lm'] !== null) $allSumatifScores[] = $r['avg_sumatif_lm'];
}

$colAvgTugas = count($allTugasScores) > 0 ? round(array_sum($allTugasScores) / count($allTugasScores), 2) : null;
$colAvgProyek = count($allProyekScores) > 0 ? round(array_sum($allProyekScores) / count($allProyekScores), 2) : null;
$colAvgFormatif = count($allFormatifScores) > 0 ? round(array_sum($allFormatifScores) / count($allFormatifScores), 2) : null;
$colAvgDiag = count($allDiagScores) > 0 ? round(array_sum($allDiagScores) / count($allDiagScores), 2) : null;
$colAvgSumatif = count($allSumatifScores) > 0 ? round(array_sum($allSumatifScores) / count($allSumatifScores), 2) : null;

$pageTitle = "Transkrip Nilai Siswa";
include __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/admin-assessment-access.css">

<style>
/* ==============================================================================
 * ILMUNA DATA-FIRST SINGLE-TABLE GRADES STYLES
 * ============================================================================== */
:root {
    --g-bg-card: #ffffff;
    --g-border: #e2e8f0;
    --g-text-main: #0f172a;
    --g-text-muted: #64748b;
    --g-primary: #10b981;
    --g-primary-dark: #047857;
    --g-primary-light: #ecfdf5;
    --g-primary-border: #a7f3d0;
}

body.dark-mode {
    --g-bg-card: #1e293b;
    --g-border: #334155;
    --g-text-main: #f8fafc;
    --g-text-muted: #94a3b8;
    --g-primary: #10b981;
    --g-primary-dark: #059669;
    --g-primary-light: rgba(16, 185, 129, 0.12);
    --g-primary-border: #065f46;
}

.grade-container-fluid {
    width: 100%;
    max-width: 1560px;
    margin: 0 auto;
    padding: 12px 16px 40px;
    box-sizing: border-box;
}

/* 1. COMPACT TOOLBAR (Identity & Instant Search) */
.grade-toolbar-bar {
    background: var(--g-bg-card);
    border: 1px solid var(--g-border);
    border-radius: 12px;
    padding: 10px 14px;
    margin-bottom: 14px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.toolbar-left-group, .toolbar-right-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.student-identity-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--g-primary-light);
    border: 1px solid var(--g-primary-border);
    border-radius: 8px;
    padding: 6px 14px;
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--g-primary-dark);
}

.grade-class-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f1f5f9;
    border: 1px solid var(--g-border);
    border-radius: 8px;
    padding: 6px 12px;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--g-text-muted);
}

body.dark-mode .grade-class-chip {
    background: #334155;
    color: #cbd5e1;
}

.grade-search-input-wrap {
    position: relative;
    min-width: 280px;
}

.grade-search-input-wrap i {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--g-text-muted);
    font-size: 0.85rem;
}

.grade-search-input {
    width: 100%;
    padding: 8px 12px 8px 34px;
    border-radius: 8px;
    border: 1px solid var(--g-border);
    background: var(--g-bg-card);
    color: var(--g-text-main);
    font-size: 0.84rem;
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.grade-search-input:focus {
    border-color: #10b981;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
}

/* 2. MASTER SINGLE TABLE (DESKTOP) */
.grade-table-card {
    background: var(--g-bg-card);
    border: 1px solid var(--g-border);
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,0.03);
}

.table-responsive-box {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.master-grade-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 0.82rem;
    color: var(--g-text-main);
}

.master-grade-table thead th {
    background: #f8fafc;
    color: #334155;
    font-weight: 700;
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    padding: 12px 10px;
    border-bottom: 2px solid var(--g-border);
    border-right: 1px solid var(--g-border);
    vertical-align: middle;
    white-space: nowrap;
}

body.dark-mode .master-grade-table thead th {
    background: #0f172a;
    color: #94a3b8;
}

.master-grade-table tbody td {
    padding: 10px;
    border-bottom: 1px solid var(--g-border);
    border-right: 1px solid var(--g-border);
    vertical-align: top;
    background: var(--g-bg-card);
}

.master-grade-table tbody tr:hover td {
    background: #f8fafc;
}

body.dark-mode .master-grade-table tbody tr:hover td {
    background: rgba(255,255,255,0.02);
}

/* Header Columns */
.col-num { width: 36px; text-align: center; }
.col-mapel { min-width: 200px; max-width: 240px; }
.col-aspect { min-width: 170px; }

/* Mapel Info Cell */
.mapel-info-box {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.mapel-name-title {
    font-size: 0.88rem;
    font-weight: 800;
    color: var(--g-text-main);
    line-height: 1.25;
}

.mapel-teacher-sub {
    font-size: 0.74rem;
    color: var(--g-text-muted);
    display: flex;
    align-items: center;
    gap: 5px;
}

.mapel-kktp-tag {
    display: inline-flex;
    align-items: center;
    width: fit-content;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 2px 7px;
    font-size: 0.70rem;
    font-weight: 700;
    color: #475569;
    margin-top: 4px;
}

body.dark-mode .mapel-kktp-tag {
    background: #334155;
    border-color: #475569;
    color: #e2e8f0;
}

/* Cell Inside Items (T1, T2, P1, dsb dalam 1 sel) */
.cell-grade-stack {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-height: 50px;
}

.cell-chip-flow {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.grade-badge-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 8px;
    border-radius: 8px;
    font-size: 0.74rem;
    border: 1px solid var(--g-border);
    cursor: pointer;
    user-select: none;
    transition: transform 0.12s ease, filter 0.12s ease, box-shadow 0.12s ease;
    background: #f8fafc;
    text-decoration: none;
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
}

.grade-badge-chip:hover, .grade-badge-chip:active {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}

.grade-badge-chip .chip-code {
    font-weight: 800;
    font-size: 0.72rem;
    padding-right: 4px;
    border-right: 1px solid rgba(0,0,0,0.12);
}

.grade-badge-chip .chip-score {
    font-weight: 800;
    font-size: 0.78rem;
}

/* Status Color Tiers */
.grade-badge-chip.is-pass {
    background: #ecfdf5;
    border-color: #a7f3d0;
    color: #065f46;
}
.grade-badge-chip.is-pass .chip-code { color: #047857; }
.grade-badge-chip.is-pass .chip-score { color: #065f46; }

.grade-badge-chip.is-remedial {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}
.grade-badge-chip.is-remedial .chip-code { color: #b45309; }
.grade-badge-chip.is-remedial .chip-score { color: #92400e; }

.grade-badge-chip.is-fail {
    background: #fef2f2;
    border-color: #fecaca;
    color: #991b1b;
}
.grade-badge-chip.is-fail .chip-code { color: #b91c1c; }
.grade-badge-chip.is-fail .chip-score { color: #991b1b; }

.cell-subtotal-bar {
    margin-top: auto;
    padding-top: 5px;
    border-top: 1px dashed var(--g-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.70rem;
    color: var(--g-text-muted);
}

.cell-subtotal-bar strong {
    font-weight: 800;
    color: var(--g-text-main);
    font-size: 0.76rem;
}

.cell-empty-text {
    font-size: 0.73rem;
    color: #94a3b8;
    font-style: italic;
    padding: 6px 0;
}

/* Table Footer Summary */
.master-grade-table tfoot td {
    background: #f8fafc;
    font-weight: 800;
    border-top: 2px solid var(--g-border);
    padding: 10px;
    color: var(--g-text-main);
}

body.dark-mode .master-grade-table tfoot td {
    background: #0f172a;
}

/* 3. MOBILE CARD STACK (HIGH-ELEGANCE DUAL-VIEW) */
.grade-mobile-stack {
    display: none;
    flex-direction: column;
    gap: 14px;
}

.mobile-mapel-card {
    background: var(--g-bg-card);
    border: 1px solid var(--g-border);
    border-radius: 16px;
    padding: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.mmc-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--g-border);
    margin-bottom: 12px;
    gap: 10px;
}

.mmc-title {
    font-size: 1rem;
    font-weight: 800;
    color: var(--g-text-main);
    line-height: 1.3;
}

.mmc-teacher {
    font-size: 0.78rem;
    color: var(--g-text-muted);
    margin-top: 3px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.mmc-section-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.mmc-aspect-row {
    background: #f8fafc;
    border: 1px solid var(--g-border);
    border-radius: 12px;
    padding: 10px 12px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

body.dark-mode .mmc-aspect-row {
    background: #0f172a;
}

.mmc-aspect-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--g-text-muted);
}

.mmc-aspect-header .aspect-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--g-text-main);
}

.mmc-aspect-header .aspect-avg {
    font-size: 0.74rem;
    color: var(--g-text-muted);
    background: rgba(0,0,0,0.04);
    padding: 2px 6px;
    border-radius: 6px;
}

body.dark-mode .mmc-aspect-header .aspect-avg {
    background: rgba(255,255,255,0.08);
}

/* Mobile Touch-Friendly Chip */
.mobile-chip-flow {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 2px;
}

.mobile-chip-flow .grade-badge-chip {
    padding: 6px 10px;
    font-size: 0.80rem;
    border-radius: 9px;
    cursor: pointer;
}

.mobile-chip-flow .grade-badge-chip .chip-code {
    font-size: 0.76rem;
    padding-right: 5px;
}

.mobile-chip-flow .grade-badge-chip .chip-score {
    font-size: 0.84rem;
}

.mobile-tap-hint {
    font-size: 0.70rem;
    color: var(--g-text-muted);
    text-align: center;
    margin-top: 8px;
    font-style: italic;
}

/* 4. INTERACTIVE DETAIL MODAL */
.grade-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
}

.grade-modal-backdrop.is-open {
    display: flex;
}

.grade-modal-dialog {
    background: var(--g-bg-card);
    border-radius: 18px;
    width: 100%;
    max-width: 440px;
    max-height: 90vh;
    border: 1px solid var(--g-border);
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.15), 0 8px 10px -6px rgba(0,0,0,0.1);
    overflow-y: auto;
    animation: modalPop 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes modalPop {
    from { opacity: 0; transform: scale(0.96) translateY(8px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}

.g-modal-header {
    padding: 14px 18px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--g-border);
    background: #f8fafc;
    position: sticky;
    top: 0;
    z-index: 10;
}

body.dark-mode .g-modal-header { background: #0f172a; }

.g-modal-header h4 {
    margin: 0;
    font-size: 0.94rem;
    font-weight: 800;
    color: var(--g-text-main);
    display: flex;
    align-items: center;
    gap: 8px;
}

.g-modal-close-btn {
    border: none;
    background: none;
    font-size: 1.4rem;
    line-height: 1;
    color: var(--g-text-muted);
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 8px;
}

.g-modal-close-btn:hover {
    background: rgba(0,0,0,0.06);
}

.g-modal-body {
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.modal-item-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.82rem;
    border-bottom: 1px dashed var(--g-border);
    padding-bottom: 7px;
}

.modal-item-row .lbl { color: var(--g-text-muted); }
.modal-item-row .val { font-weight: 700; color: var(--g-text-main); }

.modal-note-box {
    background: #f8fafc;
    border: 1px solid var(--g-border);
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 0.80rem;
    color: var(--g-text-main);
    line-height: 1.45;
}

body.dark-mode .modal-note-box { background: #0f172a; }

.g-modal-footer {
    padding: 12px 18px;
    border-top: 1px solid var(--g-border);
    background: #f8fafc;
    display: flex;
    justify-content: flex-end;
}

body.dark-mode .g-modal-footer { background: #0f172a; }

.btn-modal-close-action {
    padding: 8px 18px;
    border-radius: 8px;
    background: #0f172a;
    color: #ffffff;
    font-size: 0.82rem;
    font-weight: 600;
    border: none;
    cursor: pointer;
}

body.dark-mode .btn-modal-close-action {
    background: #334155;
    color: #f8fafc;
}

/* Legenda Keterangan */
.grade-legend-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 16px;
    margin-top: 14px;
    padding: 10px 14px;
    background: var(--g-bg-card);
    border: 1px solid var(--g-border);
    border-radius: 10px;
    font-size: 0.74rem;
    color: var(--g-text-muted);
}

.legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.legend-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

/* RESPONSIVE SWITCH */
@media (max-width: 900px) {
    .grade-table-card { display: none; }
    .grade-mobile-stack { display: flex; }
    .grade-search-input-wrap { width: 100%; min-width: 0; }
    .toolbar-left-group, .toolbar-right-group { width: 100%; justify-content: space-between; }
    .grade-container-fluid { padding: 10px 12px 30px; }
}
</style>

<div class="grade-container-fluid">

    <!-- ========================================================================
         1. COMPACT TOOLBAR (Zero Title Standard - README.md)
         ======================================================================== -->
    <div class="grade-toolbar-bar">
        <div class="toolbar-left-group">
            <span class="student-identity-chip">
                <i class="fas fa-user-graduate"></i>
                <?= htmlspecialchars($currentSiswa['nama_lengkap'] ?? $currentUser['full_name'] ?? 'Siswa') ?>
                <span style="opacity: 0.7; font-weight: 500;">(NIS: <?= htmlspecialchars($currentSiswa['nis'] ?? '-') ?>)</span>
            </span>

            <span class="grade-class-chip">
                <i class="fas fa-users"></i>
                <?= htmlspecialchars($currentSiswa['nama_kelas'] ?? 'Rombel Siswa') ?>
            </span>
        </div>

        <div class="toolbar-right-group">
            <div class="grade-search-input-wrap">
                <i class="fas fa-search"></i>
                <input type="text" class="grade-search-input" id="searchMapelInput" 
                       placeholder="Cari mata pelajaran atau guru..." oninput="applyGradeFilters()">
            </div>
        </div>
    </div>

    <!-- ========================================================================
         2. MASTER SINGLE TABLE (DESKTOP VIEW)
         ======================================================================== -->
    <div class="grade-table-card">
        <div class="table-responsive-box">
            <table class="master-grade-table" id="tableMasterGrades">
                <thead>
                    <tr>
                        <th class="col-num">#</th>
                        <th class="col-mapel">Mata Pelajaran & Pengampu</th>
                        <th class="col-aspect">Tugas Pembelajaran</th>
                        <th class="col-aspect">Proyek Belajar</th>
                        <th class="col-aspect">Asesmen Formatif</th>
                        <th class="col-aspect">Asesmen Diagnostik</th>
                        <th class="col-aspect">Sumatif Lingkup Materi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mapelTable)): ?>
                        <tr>
                            <td colspan="7" class="text-center" style="padding: 30px; color: var(--g-text-muted);">
                                <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 8px; display: block; opacity: 0.4;"></i>
                                Belum ada mata pelajaran terdaftar untuk rombel kelas Anda.
                            </td>
                        </tr>
                    <?php else: 
                        $no = 1;
                        foreach ($mapelTable as $mid => $row):
                            $mInfo = $row['info'];
                            $kktp = (float)$mInfo['kktp'];
                            if ($kktp <= 0) $kktp = 75.00;
                            $searchHaystack = strtolower($mInfo['nama_mata_pelajaran'] . ' ' . $mInfo['kode_mapel'] . ' ' . $mInfo['nama_guru']);
                    ?>
                        <tr class="mapel-grade-row" data-search="<?= htmlspecialchars($searchHaystack) ?>">
                            
                            <td class="col-num text-center" style="font-weight: 700; color: var(--g-text-muted);">
                                <?= $no++ ?>
                            </td>

                            <!-- 1. MAPEL & GURU -->
                            <td class="col-mapel">
                                <div class="mapel-info-box">
                                    <div class="mapel-name-title"><?= htmlspecialchars($mInfo['nama_mata_pelajaran']) ?></div>
                                    <div class="mapel-teacher-sub">
                                        <i class="fas fa-chalkboard-teacher"></i>
                                        <?= htmlspecialchars($mInfo['nama_guru'] ?? 'Guru Pengampu') ?>
                                    </div>
                                    <span class="mapel-kktp-tag">
                                        KKTP: <?= number_format($kktp, 1) ?>
                                    </span>
                                </div>
                            </td>

                            <!-- 2. TUGAS PEMBELAJARAN (1 SEL TERPADU) -->
                            <td class="col-aspect">
                                <div class="cell-grade-stack">
                                    <?php if (empty($row['tugas'])): ?>
                                        <span class="cell-empty-text">— Belum ada —</span>
                                    <?php else: ?>
                                        <div class="cell-chip-flow">
                                            <?php foreach ($row['tugas'] as $t): 
                                                $eff = $t['nilai_efektif'];
                                                $isRem = ($t['nilai_akhir_remedial'] !== null || $t['nilai_remedial'] !== null);
                                                $tierClass = ($eff >= $kktp) ? ($isRem ? 'is-remedial' : 'is-pass') : 'is-fail';
                                                $jsonPayload = htmlspecialchars(json_encode([
                                                    'aspek' => 'Tugas Pembelajaran (' . $t['label_code'] . ')',
                                                    'judul' => $t['judul_tugas'],
                                                    'mapel' => $mInfo['nama_mata_pelajaran'],
                                                    'pertemuan' => $t['pertemuan_ke'] ? 'Pertemuan ke-' . $t['pertemuan_ke'] : '-',
                                                    'nilai_asli' => $t['nilai_asli'],
                                                    'nilai_remedial' => $t['nilai_remedial'],
                                                    'nilai_akhir' => $t['nilai_akhir_remedial'],
                                                    'nilai_efektif' => $eff,
                                                    'kktp' => $kktp,
                                                    'catatan' => $t['catatan_guru'] ?? '-'
                                                ]), ENT_QUOTES, 'UTF-8');
                                            ?>
                                                <div class="grade-badge-chip <?= $tierClass ?>" 
                                                     role="button"
                                                     tabindex="0"
                                                     onclick="openGradeDetailModal(<?= $jsonPayload ?>)"
                                                     title="<?= htmlspecialchars($t['judul_tugas']) ?> (Klik rincian)">
                                                    <span class="chip-code"><?= $t['label_code'] ?></span>
                                                    <span class="chip-score"><?= number_format($eff, 1) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="cell-subtotal-bar">
                                            <span>Rata-rata:</span>
                                            <strong><?= number_format($row['avg_tugas'], 2) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- 3. PROYEK BELAJAR (1 SEL TERPADU) -->
                            <td class="col-aspect">
                                <div class="cell-grade-stack">
                                    <?php if (empty($row['proyek'])): ?>
                                        <span class="cell-empty-text">— Belum ada —</span>
                                    <?php else: ?>
                                        <div class="cell-chip-flow">
                                            <?php foreach ($row['proyek'] as $p): 
                                                $eff = $p['nilai_efektif'];
                                                $isRem = ($p['nilai_akhir_remedial'] !== null || $p['nilai_remedial'] !== null);
                                                $tierClass = ($eff >= $kktp) ? ($isRem ? 'is-remedial' : 'is-pass') : 'is-fail';
                                                $jsonPayload = htmlspecialchars(json_encode([
                                                    'aspek' => 'Proyek Belajar (' . $p['label_code'] . ')',
                                                    'judul' => $p['judul_tugas'],
                                                    'mapel' => $mInfo['nama_mata_pelajaran'],
                                                    'pertemuan' => $p['pertemuan_ke'] ? 'Pertemuan ke-' . $p['pertemuan_ke'] : '-',
                                                    'nilai_asli' => $p['nilai_asli'],
                                                    'nilai_remedial' => $p['nilai_remedial'],
                                                    'nilai_akhir' => $p['nilai_akhir_remedial'],
                                                    'nilai_efektif' => $eff,
                                                    'kktp' => $kktp,
                                                    'catatan' => $p['catatan_guru'] ?? '-'
                                                ]), ENT_QUOTES, 'UTF-8');
                                            ?>
                                                <div class="grade-badge-chip <?= $tierClass ?>" 
                                                     role="button"
                                                     tabindex="0"
                                                     onclick="openGradeDetailModal(<?= $jsonPayload ?>)"
                                                     title="<?= htmlspecialchars($p['judul_tugas']) ?> (Klik rincian)">
                                                    <span class="chip-code"><?= $p['label_code'] ?></span>
                                                    <span class="chip-score"><?= number_format($eff, 1) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="cell-subtotal-bar">
                                            <span>Rata-rata:</span>
                                            <strong><?= number_format($row['avg_proyek'], 2) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- 4. ASESMEN FORMATIF (1 SEL TERPADU) -->
                            <td class="col-aspect">
                                <div class="cell-grade-stack">
                                    <?php if (empty($row['formatif'])): ?>
                                        <span class="cell-empty-text">— Belum ada —</span>
                                    <?php else: ?>
                                        <div class="cell-chip-flow">
                                            <?php foreach ($row['formatif'] as $f): 
                                                $eff = $f['nilai_efektif'];
                                                $isRem = ($f['nilai_akhir_remedial'] !== null || $f['nilai_remedial'] !== null);
                                                $tierClass = ($eff >= $kktp) ? ($isRem ? 'is-remedial' : 'is-pass') : 'is-fail';
                                                $jsonPayload = htmlspecialchars(json_encode([
                                                    'aspek' => 'Asesmen Formatif (' . $f['label_code'] . ')',
                                                    'judul' => $f['judul_asesmen'],
                                                    'mapel' => $mInfo['nama_mata_pelajaran'],
                                                    'pertemuan' => $f['pertemuan_ke'] ? 'Pertemuan ke-' . $f['pertemuan_ke'] : '-',
                                                    'nilai_asli' => $f['nilai_asli'],
                                                    'nilai_remedial' => $f['nilai_remedial'],
                                                    'nilai_akhir' => $f['nilai_akhir_remedial'],
                                                    'nilai_efektif' => $eff,
                                                    'kktp' => $kktp,
                                                    'catatan' => $f['catatan_guru'] ?? '-'
                                                ]), ENT_QUOTES, 'UTF-8');
                                            ?>
                                                <div class="grade-badge-chip <?= $tierClass ?>" 
                                                     role="button"
                                                     tabindex="0"
                                                     onclick="openGradeDetailModal(<?= $jsonPayload ?>)"
                                                     title="<?= htmlspecialchars($f['judul_asesmen']) ?> (Klik rincian)">
                                                    <span class="chip-code"><?= $f['label_code'] ?></span>
                                                    <span class="chip-score"><?= number_format($eff, 1) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="cell-subtotal-bar">
                                            <span>Rata-rata:</span>
                                            <strong><?= number_format($row['avg_formatif'], 2) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- 5. ASESMEN DIAGNOSTIK (1 SEL TERPADU) -->
                            <td class="col-aspect">
                                <div class="cell-grade-stack">
                                    <?php if (empty($row['diagnostik'])): ?>
                                        <span class="cell-empty-text">— Belum ada —</span>
                                    <?php else: ?>
                                        <div class="cell-chip-flow">
                                            <?php foreach ($row['diagnostik'] as $d): 
                                                $eff = $d['nilai_efektif'];
                                                $tierClass = ($eff >= $kktp) ? 'is-pass' : 'is-fail';
                                                $jsonPayload = htmlspecialchars(json_encode([
                                                    'aspek' => 'Asesmen Diagnostik (' . $d['label_code'] . ')',
                                                    'judul' => $d['judul_asesmen'],
                                                    'mapel' => $mInfo['nama_mata_pelajaran'],
                                                    'pertemuan' => $d['pertemuan_ke'] ? 'Pertemuan ke-' . $d['pertemuan_ke'] : '-',
                                                    'nilai_asli' => $d['nilai_asli'],
                                                    'nilai_remedial' => null,
                                                    'nilai_akhir' => null,
                                                    'nilai_efektif' => $eff,
                                                    'kktp' => $kktp,
                                                    'catatan' => $d['catatan_guru'] ?? '-'
                                                ]), ENT_QUOTES, 'UTF-8');
                                            ?>
                                                <div class="grade-badge-chip <?= $tierClass ?>" 
                                                     role="button"
                                                     tabindex="0"
                                                     onclick="openGradeDetailModal(<?= $jsonPayload ?>)"
                                                     title="<?= htmlspecialchars($d['judul_asesmen']) ?> (Klik rincian)">
                                                    <span class="chip-code"><?= $d['label_code'] ?></span>
                                                    <span class="chip-score"><?= number_format($eff, 1) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="cell-subtotal-bar">
                                            <span>Rata-rata:</span>
                                            <strong><?= number_format($row['avg_diagnostik'], 2) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- 6. SUMATIF LINGKUP MATERI (1 SEL TERPADU) -->
                            <td class="col-aspect">
                                <div class="cell-grade-stack">
                                    <?php if (empty($row['sumatif_lm'])): ?>
                                        <span class="cell-empty-text">— Belum ada —</span>
                                    <?php else: ?>
                                        <div class="cell-chip-flow">
                                            <?php foreach ($row['sumatif_lm'] as $s): 
                                                $eff = $s['nilai_efektif'];
                                                $isRem = ($s['nilai_akhir_remedial'] !== null || $s['nilai_remedial'] !== null);
                                                $tierClass = ($eff >= $kktp) ? ($isRem ? 'is-remedial' : 'is-pass') : 'is-fail';
                                                $jsonPayload = htmlspecialchars(json_encode([
                                                    'aspek' => 'Sumatif Lingkup Materi (' . $s['label_code'] . ')',
                                                    'judul' => $s['judul_asesmen'],
                                                    'mapel' => $mInfo['nama_mata_pelajaran'],
                                                    'pertemuan' => $s['pertemuan_ke'] ? 'Pertemuan ke-' . $s['pertemuan_ke'] : '-',
                                                    'nilai_asli' => $s['nilai_asli'],
                                                    'nilai_remedial' => $s['nilai_remedial'],
                                                    'nilai_akhir' => $s['nilai_akhir_remedial'],
                                                    'nilai_efektif' => $eff,
                                                    'kktp' => $kktp,
                                                    'catatan' => $s['catatan_guru'] ?? '-'
                                                ]), ENT_QUOTES, 'UTF-8');
                                            ?>
                                                <div class="grade-badge-chip <?= $tierClass ?>" 
                                                     role="button"
                                                     tabindex="0"
                                                     onclick="openGradeDetailModal(<?= $jsonPayload ?>)"
                                                     title="<?= htmlspecialchars($s['judul_asesmen']) ?> (Klik rincian)">
                                                    <span class="chip-code"><?= $s['label_code'] ?></span>
                                                    <span class="chip-score"><?= number_format($eff, 1) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="cell-subtotal-bar">
                                            <span>Rata-rata:</span>
                                            <strong><?= number_format($row['avg_sumatif_lm'], 2) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>

                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" class="text-right" style="font-weight: 800; font-size: 0.8rem;">Rata-Rata Seluruh Capaian:</td>
                        <td class="text-center" style="font-weight: 800;"><?= ($colAvgTugas !== null) ? number_format($colAvgTugas, 2) : '—' ?></td>
                        <td class="text-center" style="font-weight: 800;"><?= ($colAvgProyek !== null) ? number_format($colAvgProyek, 2) : '—' ?></td>
                        <td class="text-center" style="font-weight: 800;"><?= ($colAvgFormatif !== null) ? number_format($colAvgFormatif, 2) : '—' ?></td>
                        <td class="text-center" style="font-weight: 800;"><?= ($colAvgDiag !== null) ? number_format($colAvgDiag, 2) : '—' ?></td>
                        <td class="text-center" style="font-weight: 800;"><?= ($colAvgSumatif !== null) ? number_format($colAvgSumatif, 2) : '—' ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ========================================================================
         3. MOBILE CARD STACK (HIGH-ELEGANCE DUAL-VIEW)
         ======================================================================== -->
    <div class="grade-mobile-stack" id="mobileGradeStack">
        <?php if (empty($mapelTable)): ?>
            <div class="mobile-mapel-card text-center" style="color: var(--g-text-muted);">
                Belum ada data nilai mata pelajaran untuk rombel Anda.
            </div>
        <?php else:
            foreach ($mapelTable as $mid => $row):
                $mInfo = $row['info'];
                $kktp = (float)$mInfo['kktp'];
                if ($kktp <= 0) $kktp = 75.00;
                $searchHaystack = strtolower($mInfo['nama_mata_pelajaran'] . ' ' . $mInfo['kode_mapel'] . ' ' . $mInfo['nama_guru']);
        ?>
            <div class="mobile-mapel-card mapel-grade-row" data-search="<?= htmlspecialchars($searchHaystack) ?>">
                 
                <div class="mmc-head">
                    <div>
                        <div class="mmc-title"><?= htmlspecialchars($mInfo['nama_mata_pelajaran']) ?></div>
                        <div class="mmc-teacher">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <?= htmlspecialchars($mInfo['nama_guru'] ?? 'Guru Pengampu') ?>
                        </div>
                    </div>
                    <span class="mapel-kktp-tag">KKTP: <?= number_format($kktp, 1) ?></span>
                </div>

                <div class="mmc-section-list">
                    <!-- Tugas -->
                    <div class="mmc-aspect-row">
                        <div class="mmc-aspect-header">
                            <span class="aspect-badge">
                                <i class="fas fa-pencil-alt text-sky-500"></i> Tugas Pembelajaran
                            </span>
                            <span class="aspect-avg">
                                <?= ($row['avg_tugas'] !== null) ? 'Rata: ' . number_format($row['avg_tugas'], 1) : '-' ?>
                            </span>
                        </div>
                        <div class="mobile-chip-flow">
                            <?php if (empty($row['tugas'])): ?>
                                <span class="cell-empty-text">— Belum ada nilai —</span>
                            <?php else: foreach ($row['tugas'] as $t): 
                                $eff = $t['nilai_efektif'];
                                $isRem = ($t['nilai_akhir_remedial'] !== null || $t['nilai_remedial'] !== null);
                                $tierClass = ($eff >= $kktp) ? ($isRem ? 'is-remedial' : 'is-pass') : 'is-fail';
                                $jsonPayload = htmlspecialchars(json_encode([
                                    'aspek' => 'Tugas Pembelajaran (' . $t['label_code'] . ')',
                                    'judul' => $t['judul_tugas'],
                                    'mapel' => $mInfo['nama_mata_pelajaran'],
                                    'pertemuan' => $t['pertemuan_ke'] ? 'Pertemuan ke-' . $t['pertemuan_ke'] : '-',
                                    'nilai_asli' => $t['nilai_asli'],
                                    'nilai_remedial' => $t['nilai_remedial'],
                                    'nilai_akhir' => $t['nilai_akhir_remedial'],
                                    'nilai_efektif' => $eff,
                                    'kktp' => $kktp,
                                    'catatan' => $t['catatan_guru'] ?? '-'
                                ]), ENT_QUOTES, 'UTF-8');
                            ?>
                                <div class="grade-badge-chip <?= $tierClass ?>"
                                     role="button"
                                     tabindex="0"
                                     onclick="openGradeDetailModal(<?= $jsonPayload ?>)">
                                    <span class="chip-code"><?= $t['label_code'] ?></span>
                                    <span class="chip-score"><?= number_format($eff, 1) ?></span>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <!-- Proyek -->
                    <div class="mmc-aspect-row">
                        <div class="mmc-aspect-header">
                            <span class="aspect-badge">
                                <i class="fas fa-project-diagram text-indigo-500"></i> Proyek Belajar
                            </span>
                            <span class="aspect-avg">
                                <?= ($row['avg_proyek'] !== null) ? 'Rata: ' . number_format($row['avg_proyek'], 1) : '-' ?>
                            </span>
                        </div>
                        <div class="mobile-chip-flow">
                            <?php if (empty($row['proyek'])): ?>
                                <span class="cell-empty-text">— Belum ada nilai —</span>
                            <?php else: foreach ($row['proyek'] as $p): 
                                $eff = $p['nilai_efektif'];
                                $isRem = ($p['nilai_akhir_remedial'] !== null || $p['nilai_remedial'] !== null);
                                $tierClass = ($eff >= $kktp) ? ($isRem ? 'is-remedial' : 'is-pass') : 'is-fail';
                                $jsonPayload = htmlspecialchars(json_encode([
                                    'aspek' => 'Proyek Belajar (' . $p['label_code'] . ')',
                                    'judul' => $p['judul_tugas'],
                                    'mapel' => $mInfo['nama_mata_pelajaran'],
                                    'pertemuan' => $p['pertemuan_ke'] ? 'Pertemuan ke-' . $p['pertemuan_ke'] : '-',
                                    'nilai_asli' => $p['nilai_asli'],
                                    'nilai_remedial' => $p['nilai_remedial'],
                                    'nilai_akhir' => $p['nilai_akhir_remedial'],
                                    'nilai_efektif' => $eff,
                                    'kktp' => $kktp,
                                    'catatan' => $p['catatan_guru'] ?? '-'
                                ]), ENT_QUOTES, 'UTF-8');
                            ?>
                                <div class="grade-badge-chip <?= $tierClass ?>"
                                     role="button"
                                     tabindex="0"
                                     onclick="openGradeDetailModal(<?= $jsonPayload ?>)">
                                    <span class="chip-code"><?= $p['label_code'] ?></span>
                                    <span class="chip-score"><?= number_format($eff, 1) ?></span>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <!-- Formatif -->
                    <div class="mmc-aspect-row">
                        <div class="mmc-aspect-header">
                            <span class="aspect-badge">
                                <i class="fas fa-clipboard-check text-emerald-500"></i> Asesmen Formatif
                            </span>
                            <span class="aspect-avg">
                                <?= ($row['avg_formatif'] !== null) ? 'Rata: ' . number_format($row['avg_formatif'], 1) : '-' ?>
                            </span>
                        </div>
                        <div class="mobile-chip-flow">
                            <?php if (empty($row['formatif'])): ?>
                                <span class="cell-empty-text">— Belum ada nilai —</span>
                            <?php else: foreach ($row['formatif'] as $f): 
                                $eff = $f['nilai_efektif'];
                                $isRem = ($f['nilai_akhir_remedial'] !== null || $f['nilai_remedial'] !== null);
                                $tierClass = ($eff >= $kktp) ? ($isRem ? 'is-remedial' : 'is-pass') : 'is-fail';
                                $jsonPayload = htmlspecialchars(json_encode([
                                    'aspek' => 'Asesmen Formatif (' . $f['label_code'] . ')',
                                    'judul' => $f['judul_asesmen'],
                                    'mapel' => $mInfo['nama_mata_pelajaran'],
                                    'pertemuan' => $f['pertemuan_ke'] ? 'Pertemuan ke-' . $f['pertemuan_ke'] : '-',
                                    'nilai_asli' => $f['nilai_asli'],
                                    'nilai_remedial' => $f['nilai_remedial'],
                                    'nilai_akhir' => $f['nilai_akhir_remedial'],
                                    'nilai_efektif' => $eff,
                                    'kktp' => $kktp,
                                    'catatan' => $f['catatan_guru'] ?? '-'
                                ]), ENT_QUOTES, 'UTF-8');
                            ?>
                                <div class="grade-badge-chip <?= $tierClass ?>"
                                     role="button"
                                     tabindex="0"
                                     onclick="openGradeDetailModal(<?= $jsonPayload ?>)">
                                    <span class="chip-code"><?= $f['label_code'] ?></span>
                                    <span class="chip-score"><?= number_format($eff, 1) ?></span>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <!-- Diagnostik -->
                    <div class="mmc-aspect-row">
                        <div class="mmc-aspect-header">
                            <span class="aspect-badge">
                                <i class="fas fa-stethoscope text-amber-500"></i> Asesmen Diagnostik
                            </span>
                            <span class="aspect-avg">
                                <?= ($row['avg_diagnostik'] !== null) ? 'Rata: ' . number_format($row['avg_diagnostik'], 1) : '-' ?>
                            </span>
                        </div>
                        <div class="mobile-chip-flow">
                            <?php if (empty($row['diagnostik'])): ?>
                                <span class="cell-empty-text">— Belum ada nilai —</span>
                            <?php else: foreach ($row['diagnostik'] as $d): 
                                $eff = $d['nilai_efektif'];
                                $tierClass = ($eff >= $kktp) ? 'is-pass' : 'is-fail';
                                $jsonPayload = htmlspecialchars(json_encode([
                                    'aspek' => 'Asesmen Diagnostik (' . $d['label_code'] . ')',
                                    'judul' => $d['judul_asesmen'],
                                    'mapel' => $mInfo['nama_mata_pelajaran'],
                                    'pertemuan' => $d['pertemuan_ke'] ? 'Pertemuan ke-' . $d['pertemuan_ke'] : '-',
                                    'nilai_asli' => $d['nilai_asli'],
                                    'nilai_remedial' => null,
                                    'nilai_akhir' => null,
                                    'nilai_efektif' => $eff,
                                    'kktp' => $kktp,
                                    'catatan' => $d['catatan_guru'] ?? '-'
                                ]), ENT_QUOTES, 'UTF-8');
                            ?>
                                <div class="grade-badge-chip <?= $tierClass ?>"
                                     role="button"
                                     tabindex="0"
                                     onclick="openGradeDetailModal(<?= $jsonPayload ?>)">
                                    <span class="chip-code"><?= $d['label_code'] ?></span>
                                    <span class="chip-score"><?= number_format($eff, 1) ?></span>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <!-- Sumatif LM -->
                    <div class="mmc-aspect-row">
                        <div class="mmc-aspect-header">
                            <span class="aspect-badge">
                                <i class="fas fa-file-alt text-rose-500"></i> Sumatif Lingkup Materi
                            </span>
                            <span class="aspect-avg">
                                <?= ($row['avg_sumatif_lm'] !== null) ? 'Rata: ' . number_format($row['avg_sumatif_lm'], 1) : '-' ?>
                            </span>
                        </div>
                        <div class="mobile-chip-flow">
                            <?php if (empty($row['sumatif_lm'])): ?>
                                <span class="cell-empty-text">— Belum ada nilai —</span>
                            <?php else: foreach ($row['sumatif_lm'] as $s): 
                                $eff = $s['nilai_efektif'];
                                $isRem = ($s['nilai_akhir_remedial'] !== null || $s['nilai_remedial'] !== null);
                                $tierClass = ($eff >= $kktp) ? ($isRem ? 'is-remedial' : 'is-pass') : 'is-fail';
                                $jsonPayload = htmlspecialchars(json_encode([
                                    'aspek' => 'Sumatif Lingkup Materi (' . $s['label_code'] . ')',
                                    'judul' => $s['judul_asesmen'],
                                    'mapel' => $mInfo['nama_mata_pelajaran'],
                                    'pertemuan' => $s['pertemuan_ke'] ? 'Pertemuan ke-' . $s['pertemuan_ke'] : '-',
                                    'nilai_asli' => $s['nilai_asli'],
                                    'nilai_remedial' => $s['nilai_remedial'],
                                    'nilai_akhir' => $s['nilai_akhir_remedial'],
                                    'nilai_efektif' => $eff,
                                    'kktp' => $kktp,
                                    'catatan' => $s['catatan_guru'] ?? '-'
                                ]), ENT_QUOTES, 'UTF-8');
                            ?>
                                <div class="grade-badge-chip <?= $tierClass ?>"
                                     role="button"
                                     tabindex="0"
                                     onclick="openGradeDetailModal(<?= $jsonPayload ?>)">
                                    <span class="chip-code"><?= $s['label_code'] ?></span>
                                    <span class="chip-score"><?= number_format($eff, 1) ?></span>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>

                <div class="mobile-tap-hint">
                    <i class="fas fa-hand-pointer"></i> Ketuk chip nilai untuk melihat detail & catatan guru
                </div>

            </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- ========================================================================
         4. LEGENDA KETERANGAN SKOR & KODE
         ======================================================================== -->
    <div class="grade-legend-bar">
        <span style="font-weight: 700; color: var(--g-text-main);">Keterangan:</span>
        <span class="legend-item"><strong style="color: #0284c7;">T</strong> = Tugas</span>
        <span class="legend-item"><strong style="color: #4f46e5;">P</strong> = Proyek</span>
        <span class="legend-item"><strong style="color: #059669;">F</strong> = Formatif</span>
        <span class="legend-item"><strong style="color: #d97706;">D</strong> = Diagnostik</span>
        <span class="legend-item"><strong style="color: #dc2626;">S</strong> = Sumatif Lingkup Materi</span>
        <span style="margin-left: auto;"></span>
        <span class="legend-item">
            <span class="legend-dot" style="background: #10b981;"></span> Tuntas KKTP
        </span>
        <span class="legend-item">
            <span class="legend-dot" style="background: #f59e0b;"></span> Terbantu Remedial
        </span>
        <span class="legend-item">
            <span class="legend-dot" style="background: #ef4444;"></span> Belum Tuntas
        </span>
    </div>

</div>

<!-- ============================================================================
     INTERACTIVE DETAIL MODAL DIALOG
     ============================================================================ -->
<div class="grade-modal-backdrop" id="gradeDetailModal" onclick="closeGradeModalOnOutside(event)">
    <div class="grade-modal-dialog" id="gradeModalContent">
        <div class="g-modal-header">
            <h4><i class="fas fa-info-circle text-emerald-500"></i> <span id="mAspekTitle">Rincian Penilaian</span></h4>
            <button type="button" class="g-modal-close-btn" onclick="closeGradeDetailModal()">&times;</button>
        </div>
        <div class="g-modal-body">
            <div class="modal-item-row">
                <span class="lbl">Mata Pelajaran</span>
                <span class="val" id="mMapelName">—</span>
            </div>
            <div class="modal-item-row">
                <span class="lbl">Judul / Materi</span>
                <span class="val" id="mJudulAsesmen" style="text-align: right; max-width: 65%;">—</span>
            </div>
            <div class="modal-item-row">
                <span class="lbl">Pertemuan / Sesi</span>
                <span class="val" id="mPertemuan">—</span>
            </div>
            <div class="modal-item-row">
                <span class="lbl">Nilai Efektif</span>
                <span class="val" id="mNilaiEfektif" style="font-size: 1.15rem; color: #10b981;">—</span>
            </div>
            <div class="modal-item-row" id="rowNilaiAsli">
                <span class="lbl">Nilai Asli Sebelum Remedial</span>
                <span class="val" id="mNilaiAsli">—</span>
            </div>
            <div class="modal-item-row" id="rowNilaiRemedial">
                <span class="lbl">Nilai Hasil Remedial</span>
                <span class="val" id="mNilaiRemedial">—</span>
            </div>
            <div class="modal-item-row">
                <span class="lbl">Batas KKTP Mapel</span>
                <span class="val" id="mKktpValue">—</span>
            </div>

            <div>
                <div style="font-size: 0.74rem; font-weight: 700; color: var(--g-text-muted); margin-bottom: 4px;">
                    Catatan Guru / Evaluasi:
                </div>
                <div class="modal-note-box" id="mCatatanGuru">
                    Tidak ada catatan khusus dari guru pengampu.
                </div>
            </div>
        </div>
        <div class="g-modal-footer">
            <button type="button" class="btn-modal-close-action" onclick="closeGradeDetailModal()">Tutup</button>
        </div>
    </div>
</div>

<script>
// Filter Live Search
function applyGradeFilters() {
    const query = document.getElementById('searchMapelInput').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.mapel-grade-row');
    rows.forEach(r => {
        const hay = r.getAttribute('data-search') || '';
        if (query === '' || hay.includes(query)) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
}

// Modal Interaction
function openGradeDetailModal(data) {
    if (!data) return;

    document.getElementById('mAspekTitle').innerText = data.aspek || 'Rincian Penilaian';
    document.getElementById('mMapelName').innerText = data.mapel || '-';
    document.getElementById('mJudulAsesmen').innerText = data.judul || '-';
    document.getElementById('mPertemuan').innerText = data.pertemuan || '-';
    document.getElementById('mNilaiEfektif').innerText = (data.nilai_efektif !== null) ? Number(data.nilai_efektif).toFixed(2) : '-';
    document.getElementById('mKktpValue').innerText = (data.kktp) ? Number(data.kktp).toFixed(2) : '-';

    const rowAsli = document.getElementById('rowNilaiAsli');
    const rowRem = document.getElementById('rowNilaiRemedial');

    if (data.nilai_remedial !== null || data.nilai_akhir !== null) {
        rowAsli.style.display = 'flex';
        rowRem.style.display = 'flex';
        document.getElementById('mNilaiAsli').innerText = (data.nilai_asli !== null) ? Number(data.nilai_asli).toFixed(2) : '-';
        document.getElementById('mNilaiRemedial').innerText = (data.nilai_akhir !== null) ? Number(data.nilai_akhir).toFixed(2) : ((data.nilai_remedial !== null) ? Number(data.nilai_remedial).toFixed(2) : '-');
    } else {
        rowAsli.style.display = 'none';
        rowRem.style.display = 'none';
    }

    const noteBox = document.getElementById('mCatatanGuru');
    if (data.catatan && data.catatan.trim() !== '' && data.catatan !== '-') {
        noteBox.innerText = data.catatan;
    } else {
        noteBox.innerText = 'Tidak ada catatan khusus dari guru pengampu.';
    }

    document.getElementById('gradeDetailModal').classList.add('is-open');
    document.body.style.overflow = 'hidden';
}

function closeGradeDetailModal() {
    document.getElementById('gradeDetailModal').classList.remove('is-open');
    document.body.style.overflow = '';
}

function closeGradeModalOnOutside(e) {
    if (e.target.id === 'gradeDetailModal') {
        closeGradeDetailModal();
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeGradeDetailModal();
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>