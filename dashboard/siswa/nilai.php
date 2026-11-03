<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PORTAL TRANSKRIP NILAI KOGNITIF SISWA TERPADU (SINGLE TABLE)
 * ==============================================================================
 * Filosofi Desain "Data-First" (Zero Title) Sesuai Standar README.md:
 * 1. Seluruh penilaian kognitif disatukan ke dalam 1 Tabel Komprehensif:
 *    - Baris: Mata Pelajaran pada kelas siswa.
 *    - Kolom: Mapel & Guru | Tugas | Proyek | Formatif | Diagnostik | Sumatif LM | Rata-rata Akhir & KKTP
 *    - Setiap sel memuat rincian ke berapa (T1, T2, P1, F1, D1, S1) beserta nilai & keterangan.
 * 2. Murni 5 Aspek Kognitif: TANPA Aspek Afektif (Sikap) & TANPA Aspek Psikomotorik (Praktik).
 * 3. Sumatif khusus Lingkup Materi (Non-STS & Non-SAS).
 * 4. Dual-View: Desktop Table Berdensitas Tinggi + Mobile Card Stack Ultra-Responsif (360px+).
 * 5. Interactive Modal Detail: Klik sel chip untuk melihat catatan guru dan breakdown nilai.
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
        'avg_kognitif'      => null,
        'predikat'          => '-',
        'status_kktp'       => 'Belum Ada Nilai',
        'is_tuntas'         => null,
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

// Hitung Rata-rata Sub-Aspek & Rata-rata Kognitif Gabungan per Mapel
$grandTotalPenilaian = 0;
$grandSumKognitif = 0;
$grandCountMapelScored = 0;
$totalMapelTuntas = 0;

foreach ($mapelTable as $mid => &$row) {
    $kktp = (float)$row['info']['kktp'];
    if ($kktp <= 0) $kktp = 75.00;

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

    // Gabungkan rata-rata kognitif dari semua komponen kognitif yang sudah dinilai
    $allScores = [];
    foreach (['tugas', 'proyek', 'formatif', 'diagnostik', 'sumatif_lm'] as $asp) {
        foreach ($row[$asp] as $item) {
            if ($item['nilai_efektif'] !== null) {
                $allScores[] = (float)$item['nilai_efektif'];
            }
        }
    }

    if (count($allScores) > 0) {
        $finalScore = round(array_sum($allScores) / count($allScores), 2);
        $row['avg_kognitif'] = $finalScore;
        $grandSumKognitif += $finalScore;
        $grandCountMapelScored++;
        $grandTotalPenilaian += count($allScores);

        // Predikat & Status KKTP
        if ($finalScore >= 88.0) {
            $row['predikat'] = 'A';
        } elseif ($finalScore >= $kktp) {
            $row['predikat'] = 'B';
        } elseif ($finalScore >= 60.0) {
            $row['predikat'] = 'C';
        } else {
            $row['predikat'] = 'D';
        }

        if ($finalScore >= $kktp) {
            $row['status_kktp'] = 'Tercapai';
            $row['is_tuntas'] = true;
            $totalMapelTuntas++;
        } else {
            $row['status_kktp'] = 'Belum Tercapai';
            $row['is_tuntas'] = false;
        }
    } else {
        $row['avg_kognitif'] = null;
        $row['predikat'] = '-';
        $row['status_kktp'] = 'Belum Ada Nilai';
        $row['is_tuntas'] = null;
    }
}
unset($row);

$totalMapel = count($mapelTable);
$overallAverage = ($grandCountMapelScored > 0) ? round($grandSumKognitif / $grandCountMapelScored, 2) : 0;
$persenTuntas = ($grandCountMapelScored > 0) ? round(($totalMapelTuntas / $grandCountMapelScored) * 100, 1) : 0;

// Kalkulasi rata-rata per kolom aspek
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

/* 1. TOP METRIC STRIP (Zero Title Standard) */
.grade-metric-strip {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 14px;
}

.metric-pill-box {
    background: var(--g-bg-card);
    border: 1px solid var(--g-border);
    border-radius: 12px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.metric-pill-box:hover {
    box-shadow: 0 4px 10px rgba(0,0,0,0.05);
}

.metric-icon-circle {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
}

.metric-pill-box.theme-emerald .metric-icon-circle { background: #ecfdf5; color: #059669; }
.metric-pill-box.theme-indigo .metric-icon-circle { background: #eef2ff; color: #4f46e5; }
.metric-pill-box.theme-amber .metric-icon-circle { background: #fffbeb; color: #d97706; }
.metric-pill-box.theme-sky .metric-icon-circle { background: #f0f9ff; color: #0284c7; }

.metric-info-text {
    line-height: 1.25;
}

.metric-info-text .m-label {
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--g-text-muted);
}

.metric-info-text .m-val {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--g-text-main);
}

.metric-info-text .m-sub {
    font-size: 0.72rem;
    color: var(--g-text-muted);
}

/* 2. TOOLBAR BAR & SEARCH */
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
    padding: 5px 12px;
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--g-primary-dark);
}

.grade-search-input-wrap {
    position: relative;
    min-width: 260px;
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
    padding: 7px 12px 7px 32px;
    border-radius: 8px;
    border: 1px solid var(--g-border);
    background: var(--g-bg-card);
    color: var(--g-text-main);
    font-size: 0.82rem;
    outline: none;
    transition: border-color 0.15s ease;
}

.grade-search-input:focus {
    border-color: #10b981;
}

.grade-filter-select {
    padding: 7px 12px;
    border-radius: 8px;
    border: 1px solid var(--g-border);
    background: var(--g-bg-card);
    color: var(--g-text-main);
    font-size: 0.82rem;
    cursor: pointer;
    outline: none;
}

.btn-action-print {
    padding: 7px 14px;
    border-radius: 8px;
    background: #0f172a;
    color: #ffffff;
    font-size: 0.82rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: background 0.15s ease;
}

.btn-action-print:hover {
    background: #1e293b;
    color: #ffffff;
}

/* 3. MASTER SINGLE TABLE (DESKTOP) */
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
    background: #f1f5f9;
}

body.dark-mode .master-grade-table tbody tr:hover td {
    background: rgba(255,255,255,0.02);
}

/* Header Columns */
.col-num { width: 36px; text-align: center; }
.col-mapel { min-width: 190px; max-width: 230px; }
.col-aspect { min-width: 165px; }
.col-score-final { width: 140px; text-align: center; }

/* Mapel Info Cell */
.mapel-info-box {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.mapel-name-title {
    font-size: 0.86rem;
    font-weight: 800;
    color: var(--g-text-main);
    line-height: 1.25;
}

.mapel-teacher-sub {
    font-size: 0.73rem;
    color: var(--g-text-muted);
    display: flex;
    align-items: center;
    gap: 4px;
}

.mapel-kktp-tag {
    display: inline-flex;
    align-items: center;
    width: fit-content;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 2px 6px;
    font-size: 0.69rem;
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
    min-height: 48px;
}

.cell-chip-flow {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}

.grade-badge-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 7px;
    border-radius: 7px;
    font-size: 0.72rem;
    border: 1px solid var(--g-border);
    cursor: pointer;
    user-select: none;
    transition: transform 0.12s ease, filter 0.12s ease;
    background: #f8fafc;
    text-decoration: none;
}

.grade-badge-chip:hover {
    transform: translateY(-1px);
    filter: brightness(0.96);
}

.grade-badge-chip .chip-code {
    font-weight: 800;
    color: #334155;
    font-size: 0.70rem;
    padding-right: 3px;
    border-right: 1px solid rgba(0,0,0,0.1);
}

.grade-badge-chip .chip-score {
    font-weight: 800;
    font-size: 0.76rem;
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
    padding-top: 4px;
    border-top: 1px dashed var(--g-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.69rem;
    color: var(--g-text-muted);
}

.cell-subtotal-bar strong {
    font-weight: 800;
    color: var(--g-text-main);
    font-size: 0.74rem;
}

.cell-empty-text {
    font-size: 0.73rem;
    color: #94a3b8;
    font-style: italic;
    padding: 6px 0;
}

/* Final Score Cell */
.final-score-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    height: 100%;
    min-height: 52px;
}

.final-score-val {
    font-size: 1.12rem;
    font-weight: 900;
    color: var(--g-text-main);
}

.kktp-status-pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.kktp-status-pill.pass {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #bbf7d0;
}

.kktp-status-pill.fail {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

.kktp-status-pill.empty {
    background: #f1f5f9;
    color: #64748b;
    border: 1px solid #e2e8f0;
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

/* 4. MOBILE CARD STACK (Dual-View Responsive) */
.grade-mobile-stack {
    display: none;
    flex-direction: column;
    gap: 12px;
}

.mobile-mapel-card {
    background: var(--g-bg-card);
    border: 1px solid var(--g-border);
    border-radius: 14px;
    padding: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}

.mmc-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--g-border);
    margin-bottom: 10px;
}

.mmc-title {
    font-size: 0.95rem;
    font-weight: 800;
    color: var(--g-text-main);
}

.mmc-teacher {
    font-size: 0.76rem;
    color: var(--g-text-muted);
}

.mmc-aspect-row {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px 0;
    border-bottom: 1px dashed var(--g-border);
}

.mmc-aspect-row:last-child {
    border-bottom: none;
}

.mmc-aspect-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.74rem;
    font-weight: 700;
    color: var(--g-text-muted);
}

.mmc-final-footer {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid var(--g-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8fafc;
    margin: 10px -14px -14px;
    padding: 12px 14px;
    border-bottom-left-radius: 14px;
    border-bottom-right-radius: 14px;
}

body.dark-mode .mmc-final-footer {
    background: #0f172a;
}

/* 5. INTERACTIVE DETAIL MODAL */
.grade-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.6);
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
    border-radius: 16px;
    width: 100%;
    max-width: 440px;
    border: 1px solid var(--g-border);
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
    overflow: hidden;
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
}

body.dark-mode .g-modal-header { background: #0f172a; }

.g-modal-header h4 {
    margin: 0;
    font-size: 0.92rem;
    font-weight: 800;
    color: var(--g-text-main);
    display: flex;
    align-items: center;
    gap: 8px;
}

.g-modal-close-btn {
    border: none;
    background: none;
    font-size: 1.15rem;
    color: var(--g-text-muted);
    cursor: pointer;
    padding: 4px;
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
    font-size: 0.8rem;
    border-bottom: 1px dashed var(--g-border);
    padding-bottom: 6px;
}

.modal-item-row .lbl { color: var(--g-text-muted); }
.modal-item-row .val { font-weight: 700; color: var(--g-text-main); }

.modal-note-box {
    background: #f8fafc;
    border: 1px solid var(--g-border);
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 0.78rem;
    color: var(--g-text-main);
    line-height: 1.4;
}

body.dark-mode .modal-note-box { background: #0f172a; }

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
    font-size: 0.73rem;
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
}

@media print {
    body { background: #fff !important; color: #000 !important; }
    .sidebar, .navbar, .grade-toolbar-bar, .grade-metric-strip, .grade-legend-bar, .g-modal-close-btn { display: none !important; }
    .grade-container-fluid { max-width: 100% !important; padding: 0 !important; }
    .grade-table-card { display: block !important; border: 1px solid #000 !important; box-shadow: none !important; }
    .grade-mobile-stack { display: none !important; }
    .master-grade-table th, .master-grade-table td { border: 1px solid #333 !important; padding: 6px !important; }
    .grade-badge-chip { border: 1px solid #666 !important; background: transparent !important; color: #000 !important; }
}
</style>

<div class="grade-container-fluid">

    <!-- ========================================================================
         1. TOP METRIC STRIP (Zero Title Standard - README.md)
         ======================================================================== -->
    <div class="grade-metric-strip">
        <div class="metric-pill-box theme-emerald">
            <div class="metric-icon-circle">
                <i class="fas fa-book-open"></i>
            </div>
            <div class="metric-info-text">
                <div class="m-label">Mata Pelajaran</div>
                <div class="m-val"><?= $totalMapel ?> Mapel</div>
                <div class="m-sub">Kelas: <?= htmlspecialchars($currentSiswa['nama_kelas'] ?? 'Rombel Siswa') ?></div>
            </div>
        </div>

        <div class="metric-pill-box theme-indigo">
            <div class="metric-icon-circle">
                <i class="fas fa-tasks"></i>
            </div>
            <div class="metric-info-text">
                <div class="m-label">Total Penilaian</div>
                <div class="m-val"><?= $grandTotalPenilaian ?> Item</div>
                <div class="m-sub">Tugas, Proyek, Formatif, Diag & Sumatif LM</div>
            </div>
        </div>

        <div class="metric-pill-box theme-amber">
            <div class="metric-icon-circle">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="metric-info-text">
                <div class="m-label">Rata-Rata Kognitif</div>
                <div class="m-val"><?= ($overallAverage > 0) ? number_format($overallAverage, 2) : '—' ?></div>
                <div class="m-sub">Capaian Gabungan 5 Aspek</div>
            </div>
        </div>

        <div class="metric-pill-box theme-sky">
            <div class="metric-icon-circle">
                <i class="fas fa-check-double"></i>
            </div>
            <div class="metric-info-text">
                <div class="m-label">Ketuntasan KKTP</div>
                <div class="m-val"><?= $totalMapelTuntas ?> / <?= $totalMapel ?></div>
                <div class="m-sub"><?= $persenTuntas ?>% Mapel Mencapai Target</div>
            </div>
        </div>
    </div>

    <!-- ========================================================================
         2. TOOLBAR BAR & SEARCH
         ======================================================================== -->
    <div class="grade-toolbar-bar">
        <div class="toolbar-left-group">
            <span class="student-identity-chip">
                <i class="fas fa-user-graduate"></i>
                <?= htmlspecialchars($currentSiswa['nama_lengkap'] ?? $currentUser['full_name'] ?? 'Siswa') ?>
                <span style="opacity: 0.65; font-weight: 500;">(NIS: <?= htmlspecialchars($currentSiswa['nis'] ?? '-') ?>)</span>
            </span>

            <select class="grade-filter-select" id="filterStatus" onchange="applyGradeFilters()">
                <option value="all">Semua Status Ketuntasan</option>
                <option value="Tercapai">Tercapai KKTP</option>
                <option value="Belum Tercapai">Belum Tercapai KKTP</option>
            </select>
        </div>

        <div class="toolbar-right-group">
            <div class="grade-search-input-wrap">
                <i class="fas fa-search"></i>
                <input type="text" class="grade-search-input" id="searchMapelInput" 
                       placeholder="Cari mata pelajaran atau guru..." oninput="applyGradeFilters()">
            </div>

            <button type="button" class="btn-action-print" onclick="window.print()">
                <i class="fas fa-print"></i> Cetak Rekap
            </button>
        </div>
    </div>

    <!-- ========================================================================
         3. MASTER SINGLE TABLE (DESKTOP VIEW)
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
                        <th class="col-score-final">Nilai Akhir & KKTP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mapelTable)): ?>
                        <tr>
                            <td colspan="8" class="text-center" style="padding: 30px; color: var(--g-text-muted);">
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
                        <tr class="mapel-grade-row" 
                            data-search="<?= htmlspecialchars($searchHaystack) ?>"
                            data-status="<?= $row['status_kktp'] ?>">
                            
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

                            <!-- 7. NILAI AKHIR & STATUS KKTP -->
                            <td class="col-score-final">
                                <div class="final-score-box">
                                    <?php if ($row['avg_kognitif'] !== null): ?>
                                        <div class="final-score-val">
                                            <?= number_format($row['avg_kognitif'], 2) ?>
                                            <span style="font-size: 0.76rem; font-weight: 700; color: var(--g-text-muted);">
                                                (<?= $row['predikat'] ?>)
                                            </span>
                                        </div>
                                        <span class="kktp-status-pill <?= $row['is_tuntas'] ? 'pass' : 'fail' ?>">
                                            <?= $row['status_kktp'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="kktp-status-pill empty">Belum Ada</span>
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
                        <td class="text-center" style="font-size: 0.98rem; font-weight: 900; color: #10b981;">
                            <?= ($overallAverage > 0) ? number_format($overallAverage, 2) : '—' ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ========================================================================
         4. MOBILE CARD STACK (DUAL-VIEW RESPONSIVE)
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
            <div class="mobile-mapel-card mapel-grade-row"
                 data-search="<?= htmlspecialchars($searchHaystack) ?>"
                 data-status="<?= $row['status_kktp'] ?>">
                 
                <div class="mmc-head">
                    <div>
                        <div class="mmc-title"><?= htmlspecialchars($mInfo['nama_mata_pelajaran']) ?></div>
                        <div class="mmc-teacher">
                            <?= htmlspecialchars($mInfo['nama_guru'] ?? 'Guru Pengampu') ?>
                        </div>
                    </div>
                    <span class="mapel-kktp-tag">KKTP: <?= number_format($kktp, 1) ?></span>
                </div>

                <!-- Tugas -->
                <div class="mmc-aspect-row">
                    <div class="mmc-aspect-header">
                        <span>Tugas Pembelajaran</span>
                        <span><?= ($row['avg_tugas'] !== null) ? 'Rata: ' . number_format($row['avg_tugas'], 1) : '-' ?></span>
                    </div>
                    <div class="cell-chip-flow">
                        <?php if (empty($row['tugas'])): ?>
                            <span class="cell-empty-text">— Belum ada nilai —</span>
                        <?php else: foreach ($row['tugas'] as $t): 
                            $eff = $t['nilai_efektif'];
                            $isRem = ($t['nilai_akhir_remedial'] !== null || $t['nilai_remedial'] !== null);
                            $tierClass = ($eff >= $kktp) ? ($isRem ? 'is-remedial' : 'is-pass') : 'is-fail';
                        ?>
                            <div class="grade-badge-chip <?= $tierClass ?>">
                                <span class="chip-code"><?= $t['label_code'] ?></span>
                                <span class="chip-score"><?= number_format($eff, 1) ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <!-- Proyek -->
                <div class="mmc-aspect-row">
                    <div class="mmc-aspect-header">
                        <span>Proyek Belajar</span>
                        <span><?= ($row['avg_proyek'] !== null) ? 'Rata: ' . number_format($row['avg_proyek'], 1) : '-' ?></span>
                    </div>
                    <div class="cell-chip-flow">
                        <?php if (empty($row['proyek'])): ?>
                            <span class="cell-empty-text">— Belum ada nilai —</span>
                        <?php else: foreach ($row['proyek'] as $p): 
                            $eff = $p['nilai_efektif'];
                            $isRem = ($p['nilai_akhir_remedial'] !== null || $p['nilai_remedial'] !== null);
                            $tierClass = ($eff >= $kktp) ? ($isRem ? 'is-remedial' : 'is-pass') : 'is-fail';
                        ?>
                            <div class="grade-badge-chip <?= $tierClass ?>">
                                <span class="chip-code"><?= $p['label_code'] ?></span>
                                <span class="chip-score"><?= number_format($eff, 1) ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <!-- Formatif -->
                <div class="mmc-aspect-row">
                    <div class="mmc-aspect-header">
                        <span>Asesmen Formatif</span>
                        <span><?= ($row['avg_formatif'] !== null) ? 'Rata: ' . number_format($row['avg_formatif'], 1) : '-' ?></span>
                    </div>
                    <div class="cell-chip-flow">
                        <?php if (empty($row['formatif'])): ?>
                            <span class="cell-empty-text">— Belum ada nilai —</span>
                        <?php else: foreach ($row['formatif'] as $f): 
                            $eff = $f['nilai_efektif'];
                            $isRem = ($f['nilai_akhir_remedial'] !== null || $f['nilai_remedial'] !== null);
                            $tierClass = ($eff >= $kktp) ? ($isRem ? 'is-remedial' : 'is-pass') : 'is-fail';
                        ?>
                            <div class="grade-badge-chip <?= $tierClass ?>">
                                <span class="chip-code"><?= $f['label_code'] ?></span>
                                <span class="chip-score"><?= number_format($eff, 1) ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <!-- Diagnostik -->
                <div class="mmc-aspect-row">
                    <div class="mmc-aspect-header">
                        <span>Asesmen Diagnostik</span>
                        <span><?= ($row['avg_diagnostik'] !== null) ? 'Rata: ' . number_format($row['avg_diagnostik'], 1) : '-' ?></span>
                    </div>
                    <div class="cell-chip-flow">
                        <?php if (empty($row['diagnostik'])): ?>
                            <span class="cell-empty-text">— Belum ada nilai —</span>
                        <?php else: foreach ($row['diagnostik'] as $d): 
                            $eff = $d['nilai_efektif'];
                            $tierClass = ($eff >= $kktp) ? 'is-pass' : 'is-fail';
                        ?>
                            <div class="grade-badge-chip <?= $tierClass ?>">
                                <span class="chip-code"><?= $d['label_code'] ?></span>
                                <span class="chip-score"><?= number_format($eff, 1) ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <!-- Sumatif LM -->
                <div class="mmc-aspect-row">
                    <div class="mmc-aspect-header">
                        <span>Sumatif Lingkup Materi</span>
                        <span><?= ($row['avg_sumatif_lm'] !== null) ? 'Rata: ' . number_format($row['avg_sumatif_lm'], 1) : '-' ?></span>
                    </div>
                    <div class="cell-chip-flow">
                        <?php if (empty($row['sumatif_lm'])): ?>
                            <span class="cell-empty-text">— Belum ada nilai —</span>
                        <?php else: foreach ($row['sumatif_lm'] as $s): 
                            $eff = $s['nilai_efektif'];
                            $isRem = ($s['nilai_akhir_remedial'] !== null || $s['nilai_remedial'] !== null);
                            $tierClass = ($eff >= $kktp) ? ($isRem ? 'is-remedial' : 'is-pass') : 'is-fail';
                        ?>
                            <div class="grade-badge-chip <?= $tierClass ?>">
                                <span class="chip-code"><?= $s['label_code'] ?></span>
                                <span class="chip-score"><?= number_format($eff, 1) ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <!-- Footer Nilai Akhir -->
                <div class="mmc-final-footer">
                    <div>
                        <div style="font-size: 0.72rem; color: var(--g-text-muted);">Nilai Rata-rata:</div>
                        <div style="font-size: 1.15rem; font-weight: 900; color: var(--g-text-main);">
                            <?= ($row['avg_kognitif'] !== null) ? number_format($row['avg_kognitif'], 2) . ' (' . $row['predikat'] . ')' : '—' ?>
                        </div>
                    </div>
                    <?php if ($row['avg_kognitif'] !== null): ?>
                        <span class="kktp-status-pill <?= $row['is_tuntas'] ? 'pass' : 'fail' ?>">
                            <?= $row['status_kktp'] ?>
                        </span>
                    <?php else: ?>
                        <span class="kktp-status-pill empty">Belum Ada Nilai</span>
                    <?php endif; ?>
                </div>

            </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- ========================================================================
         5. LEGENDA KETERANGAN SKOR & KODE
         ======================================================================== -->
    <div class="grade-legend-bar">
        <span style="font-weight: 700; color: var(--g-text-main);">Keterangan Kode:</span>
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
                <span class="val" id="mJudulAsesmen" style="text-align: right; max-width: 60%;">—</span>
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
    </div>
</div>

<script>
// Filter & Search Master Table
function applyGradeFilters() {
    const query = document.getElementById('searchMapelInput').value.toLowerCase().trim();
    const status = document.getElementById('filterStatus').value;

    const rows = document.querySelectorAll('.mapel-grade-row');
    rows.forEach(r => {
        const hay = r.getAttribute('data-search') || '';
        const rStatus = r.getAttribute('data-status') || '';

        const matchQuery = (query === '' || hay.includes(query));
        const matchStatus = (status === 'all' || rStatus === status);

        if (matchQuery && matchStatus) {
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
}

function closeGradeDetailModal() {
    document.getElementById('gradeDetailModal').classList.remove('is-open');
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