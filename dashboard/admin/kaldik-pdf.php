<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — EXPORT PDF KALENDER PENDIDIKAN & RPE (SATUAN PENDIDIKAN)
 * ==============================================================================
 * Menghasilkan Berkas PDF Kalender Pendidikan 1 Tahun Penuh untuk Diunduh Langsung:
 *   - Halaman 1: Full Kalender 1 Tahun dalam Format Matriks (12 Bulan),
 *                Header Hijau Emerald, Badge Tanggal Sesuai Warna Status,
 *                dan Legenda Warna Resmi di Bagian Bawah.
 *   - Halaman 2: Rincian Pekan Efektif (RPE) Tingkat Satuan Pendidikan
 *                (Tabel Ganjil & Genap dengan Rincian Hari Senin-Sabtu),
 *                Rekapitulasi Tahunan, Daftar Agenda/Hari Libur, dan Titimangsa Tanda Tangan.
 *
 * Menggunakan Layout Presisi A4 Landscape, Pure CSS Print Optimization,
 * High-Resolution Vector Rendering, dan Diunduh Langsung Melalui JavaScript Blob.
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

if (php_sapi_name() !== 'cli') {
    requireRole('admin');
}

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");

/* ==============================================================================
 * HELPERS: NAMA HARI, BULAN, & FORMAT HIJRIYAH
 * ============================================================================== */
if (!function_exists('getNamaHariIndo')) {
    function getNamaHariIndo($dateStr) {
        $days = [
            'Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa',
            'Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'
        ];
        return $days[date('l', strtotime($dateStr))] ?? 'Senin';
    }
}

if (!function_exists('getTanggalHijriyah')) {
    function getTanggalHijriyah($dateStr, $format = 'd MMM') {
        if (!class_exists('IntlDateFormatter')) return '';
        static $fmtShort = null;
        if ($fmtShort === null) {
            $fmtShort = new IntlDateFormatter(
                "id_ID@calendar=islamic-umalqura",
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                'Asia/Jakarta',
                IntlDateFormatter::TRADITIONAL,
                "d MMM"
            );
        }
        return $fmtShort->format(new DateTime($dateStr));
    }
}

if (!function_exists('ilmuna_get_headless_browser_bin')) {
    function ilmuna_get_headless_browser_bin() {
        $candidates = [
            'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
            'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
            'C:\Program Files\Google\Chrome\Application\chrome.exe',
            'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/snap/bin/chromium'
        ];
        foreach ($candidates as $bin) {
            if ($bin && file_exists($bin)) {
                return $bin;
            }
        }
        return null;
    }
}

$tahun = trim($_GET['tahun'] ?? '');

// Ambil Profil Identitas Sekolah Aktif
$sekolah = null;
try {
    $stmtSekolah = $pdo->query("SELECT * FROM identitas_sekolah WHERE is_active = 1 LIMIT 1");
    $sekolah = $stmtSekolah->fetch(PDO::FETCH_ASSOC);
    if (!$sekolah) {
        $stmtSekolah = $pdo->query("SELECT * FROM identitas_sekolah ORDER BY id DESC LIMIT 1");
        $sekolah = $stmtSekolah->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $sekolah = [];
}
if (!$sekolah) {
    $sekolah = [];
}

// Helper konversi file gambar ke Base64 URI
if (!function_exists('ilmuna_kaldik_file_to_b64')) {
    function ilmuna_kaldik_file_to_b64($relPath) {
        if (empty($relPath)) return '';
        if (strpos($relPath, '<svg') !== false) {
            return 'data:image/svg+xml;base64,' . base64_encode(trim($relPath));
        }
        if (strpos($relPath, 'data:image') === 0) return $relPath;
        $baseDir = dirname(__DIR__, 2);
        $candidates = [
            $baseDir . '/' . ltrim($relPath, '/\\'),
            $baseDir . '/uploads/' . ltrim($relPath, '/\\'),
            $baseDir . '/uploads/school/' . ltrim($relPath, '/\\'),
            $relPath
        ];
        foreach ($candidates as $cand) {
            if (file_exists($cand) && is_file($cand)) {
                $ext = strtolower(pathinfo($cand, PATHINFO_EXTENSION));
                $mime = 'image/png';
                if (in_array($ext, ['jpg', 'jpeg'])) $mime = 'image/jpeg';
                elseif ($ext === 'svg') $mime = 'image/svg+xml';
                elseif ($ext === 'webp') $mime = 'image/webp';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($cand));
            }
        }
        return '';
    }
}

if (!$tahun && !empty($sekolah['tahun_pelajaran_aktif'])) {
    $tahun = $sekolah['tahun_pelajaran_aktif'];
}
if (!preg_match('/^\d{4}\/\d{4}$/', $tahun)) {
    $tahun = '2026/2027';
}

$parts = explode('/', $tahun);
$yearGanjil = (int)($parts[0] ?? date('Y'));
$yearGenap  = (int)($parts[1] ?? ($yearGanjil + 1));

// 1. Ambil seluruh data kalender pendidikan 1 tahun
$stmt = $pdo->prepare("SELECT * FROM kalender_pendidikan WHERE tahun_pelajaran = ? ORDER BY tanggal ASC");
$stmt->execute([$tahun]);
$kaldikRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$kaldikMap = [];
$agendaList = [];
foreach ($kaldikRows as $r) {
    $kaldikMap[$r['tanggal']] = $r;
    if (!empty($r['keterangan']) && $r['keterangan'] !== 'KBM Reguler') {
        $agendaList[] = $r;
    }
}

// 2. Kalkulasi RPE untuk seluruh 12 bulan (Ganjil: 7..12, Genap: 1..6)
$bulanListGanjil = [
    1 => ['Juli', 7, $yearGanjil],
    2 => ['Agustus', 8, $yearGanjil],
    3 => ['September', 9, $yearGanjil],
    4 => ['Oktober', 10, $yearGanjil],
    5 => ['November', 11, $yearGanjil],
    6 => ['Desember', 12, $yearGanjil]
];

$bulanListGenap = [
    7  => ['Januari', 1, $yearGenap],
    8  => ['Februari', 2, $yearGenap],
    9  => ['Maret', 3, $yearGenap],
    10 => ['April', 4, $yearGenap],
    11 => ['Mei', 5, $yearGenap],
    12 => ['Juni', 6, $yearGenap]
];

$calcRpeForSemester = function($bulanDef, $semName) use ($pdo, $tahun) {
    $rows = [];
    $tSen = 0; $tSel = 0; $tRab = 0; $tKam = 0; $tJum = 0; $tSab = 0;
    $tHe = 0; $tPekan = 0; $tPe = 0; $tPte = 0;

    foreach ($bulanDef as $urutan => $bInfo) {
        $namaBulan = $bInfo[0];
        $mDigit    = $bInfo[1];
        $calYear   = $bInfo[2];

        $stmtCount = $pdo->prepare("
            SELECT hari, COUNT(*) as jml FROM kalender_pendidikan
            WHERE tahun_pelajaran = ? AND semester = ?
              AND status_hari = 'Efektif KBM' AND MONTH(tanggal) = ?
            GROUP BY hari
        ");
        $stmtCount->execute([$tahun, $semName, $mDigit]);
        $counts = $stmtCount->fetchAll(PDO::FETCH_KEY_PAIR);

        $sen  = (int)($counts['Senin'] ?? 0);
        $sel  = (int)($counts['Selasa'] ?? 0);
        $rab  = (int)($counts['Rabu'] ?? 0);
        $kam  = (int)($counts['Kamis'] ?? 0);
        $jum  = (int)($counts['Jumat'] ?? 0);
        $sab  = (int)($counts['Sabtu'] ?? 0);

        $totalHe = $sen + $sel + $rab + $kam + $jum + $sab;
        $pekanTotal = in_array($mDigit, [7,8,10,12,1,3,5], true) ? 5 : 4;
        $pekanEfektif = $totalHe === 0 ? 0 : min($pekanTotal, max(1, (int)round($totalHe / 5)));
        $pekanTidakEfektif = max(0, $pekanTotal - $pekanEfektif);

        $stmtKet = $pdo->prepare("
            SELECT GROUP_CONCAT(DISTINCT keterangan SEPARATOR '; ')
            FROM kalender_pendidikan
            WHERE tahun_pelajaran = ? AND semester = ?
              AND status_hari != 'Efektif KBM' AND MONTH(tanggal) = ?
        ");
        $stmtKet->execute([$tahun, $semName, $mDigit]);
        $ketAgenda = $stmtKet->fetchColumn() ?: ($pekanTidakEfektif > 0 ? 'Kegiatan Non-KBM / Libur Semester' : 'KBM Efektif Penuh');

        $rows[] = [
            'urutan' => $urutan,
            'bulan'  => $namaBulan,
            'tahun'  => $calYear,
            'mDigit' => $mDigit,
            'senin'  => $sen,
            'selasa' => $sel,
            'rabu'   => $rab,
            'kamis'  => $kam,
            'jumat'  => $jum,
            'sabtu'  => $sab,
            'total_he' => $totalHe,
            'pekan_total' => $pekanTotal,
            'pekan_efektif' => $pekanEfektif,
            'pekan_tidak_efektif' => $pekanTidakEfektif,
            'keterangan' => $ketAgenda
        ];

        $tSen += $sen; $tSel += $sel; $tRab += $rab;
        $tKam += $kam; $tJum += $jum; $tSab += $sab;
        $tHe += $totalHe; $tPekan += $pekanTotal;
        $tPe += $pekanEfektif; $tPte += $pekanTidakEfektif;
    }

    return [
        'rows'   => $rows,
        'totals' => [
            'senin' => $tSen, 'selasa' => $tSel, 'rabu' => $tRab,
            'kamis' => $tKam, 'jumat' => $tJum, 'sabtu' => $tSab,
            'total_he' => $tHe, 'pekan_total' => $tPekan,
            'pekan_efektif' => $tPe, 'pekan_tidak_efektif' => $tPte
        ]
    ];
};

$rpeGanjil = $calcRpeForSemester($bulanListGanjil, 'Ganjil');
$rpeGenap  = $calcRpeForSemester($bulanListGenap, 'Genap');

$hePerMonth = [];
foreach ($rpeGanjil['rows'] as $r) {
    $hePerMonth[$r['mDigit']] = $r['total_he'];
}
foreach ($rpeGenap['rows'] as $r) {
    $hePerMonth[$r['mDigit']] = $r['total_he'];
}

$grandHe = $rpeGanjil['totals']['total_he'] + $rpeGenap['totals']['total_he'];
$grandPekan = $rpeGanjil['totals']['pekan_total'] + $rpeGenap['totals']['pekan_total'];
$grandPe = $rpeGanjil['totals']['pekan_efektif'] + $rpeGenap['totals']['pekan_efektif'];
$grandPte = $rpeGanjil['totals']['pekan_tidak_efektif'] + $rpeGenap['totals']['pekan_tidak_efektif'];

$all12Months = array_merge(array_values($bulanListGanjil), array_values($bulanListGenap));

// Render HTML ke Output Buffer
ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kalender Pendidikan &amp; RPE Tahun Pelajaran <?= htmlspecialchars($tahun) ?> — Ilmuna Learn</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 6mm 8mm;
        }
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, sans-serif;
            font-size: 11px;
            color: #0F172A;
            background: #FFFFFF;
            line-height: 1.3;
        }

        .pdf-page {
            width: 100%;
            page-break-after: always;
        }
        .pdf-page:last-child {
            page-break-after: auto;
        }

        /* ===== KOP SURAT RESMI ===== */
        .kop-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2.5px solid #059669;
            padding-bottom: 6px;
            margin-bottom: 8px;
        }
        .kop-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .kop-logo {
            width: 36px;
            height: 36px;
            background: #059669;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFFFFF;
            font-weight: 900;
            font-size: 18px;
            letter-spacing: -0.5px;
        }
        .kop-title h1 {
            font-size: 14px;
            font-weight: 800;
            color: #065F46;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }
        .kop-title p {
            font-size: 10px;
            font-weight: 600;
            color: #0D7A87;
        }
        .kop-right {
            text-align: right;
            font-size: 9.5px;
            color: #64748B;
        }
        .kop-right strong {
            color: #0F172A;
            font-size: 10.5px;
        }

        /* ===== 12-MONTH CALENDAR MATRIX GRID ===== */
        .cal-grid-12 {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 6px;
            margin-bottom: 8px;
        }

        .cal-month-card {
            border: 1px solid #CBD5E1;
            border-radius: 6px;
            overflow: hidden;
            background: #FFFFFF;
            display: flex;
            flex-direction: column;
        }

        /* HEADER BULAN HIJAU (Permintaan Pengguna: Diatas diwarnai hijau) */
        .cal-month-header {
            background: #059669 !important;
            color: #FFFFFF !important;
            padding: 4px 6px;
            text-align: center;
            border-bottom: 1px solid #047857;
        }
        .cal-month-name {
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            line-height: 1.1;
        }
        .cal-month-sub {
            font-size: 8px;
            font-weight: 600;
            opacity: 0.92;
            margin-top: 1px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .cal-month-he {
            background: rgba(255, 255, 255, 0.25);
            padding: 1px 4px;
            border-radius: 3px;
            font-weight: 800;
        }

        /* TABLE GRID KALENDER BULAN */
        .cal-month-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 8.5px;
        }
        .cal-month-table th {
            padding: 3px 1px;
            font-size: 8px;
            font-weight: 700;
            text-align: center;
            background: #F1F5F9;
            color: #475569;
            border-bottom: 1px solid #E2E8F0;
        }
        .cal-month-table th.th-sun {
            color: #DC2626;
            background: #FEE2E2;
        }
        .cal-month-table td {
            height: 17px;
            text-align: center;
            vertical-align: middle;
            border: 0.5px solid #F1F5F9;
            font-size: 8px;
            font-weight: 700;
            padding: 1px;
        }
        .cal-month-table td.empty-cell {
            color: #CBD5E1;
            background: #FAFAFA;
        }

        /* WARNA STATUS KALDIK SESUAI LEGENDA RESMI */
        .day-kbm {
            background: #D1FAE5 !important;
            color: #065F46 !important;
            font-weight: 800 !important;
        }
        .day-libur {
            background: #FEE2E2 !important;
            color: #DC2626 !important;
            font-weight: 800 !important;
        }
        .day-semester {
            background: #F1F5F9 !important;
            color: #475569 !important;
            font-weight: 700 !important;
        }
        .day-kegiatan {
            background: #FEF3C7 !important;
            color: #B45309 !important;
            font-weight: 800 !important;
        }
        .day-keagamaan {
            background: #EDE9FE !important;
            color: #7C3AED !important;
            font-weight: 800 !important;
        }
        .day-p5 {
            background: #E0F2FE !important;
            color: #0284C7 !important;
            font-weight: 800 !important;
        }

        /* ===== LEGENDA WARNA ===== */
        .legend-card {
            border: 1px solid #CBD5E1;
            border-radius: 6px;
            background: #F8FAFC;
            padding: 5px 8px;
            margin-bottom: 6px;
        }
        .legend-title {
            font-size: 9px;
            font-weight: 800;
            color: #065F46;
            text-transform: uppercase;
            margin-bottom: 4px;
            display: flex;
            justify-content: space-between;
        }
        .legend-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 6px;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 8px;
            line-height: 1.15;
            color: #334155;
        }
        .legend-badge {
            width: 14px;
            height: 14px;
            border-radius: 3px;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 7.5px;
            font-weight: 800;
            border: 0.5px solid rgba(0,0,0,0.1);
        }

        /* ===== HALAMAN 2: TABEL RPE & AGENDA ===== */
        .rpe-section-title {
            background: #059669;
            color: #FFFFFF;
            font-size: 10px;
            font-weight: 800;
            padding: 4px 8px;
            border-radius: 4px;
            margin-bottom: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .rpe-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5px;
            margin-bottom: 6px;
        }
        .rpe-table th {
            background: #065F46;
            color: #FFFFFF;
            padding: 4px 5px;
            font-size: 8.5px;
            font-weight: 700;
            border: 1px solid #047857;
            text-align: center;
        }
        .rpe-table th.th-left {
            text-align: left;
        }
        .rpe-table td {
            padding: 3.5px 5px;
            border: 1px solid #CBD5E1;
            text-align: center;
            color: #1E293B;
        }
        .rpe-table td.td-left {
            text-align: left;
        }
        .rpe-table tr:nth-child(even) td {
            background: #F8FAFC;
        }
        .rpe-table tr.tr-sum td {
            background: #E2E8F0;
            font-weight: 800;
            border-top: 1.5px solid #065F46;
            border-bottom: 2px solid #0F172A;
        }

        /* REKAPITULASI TAHUNAN BOX */
        .rekap-summary-box {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            background: #F1F5F9;
            border: 1.5px solid #CBD5E1;
            border-radius: 6px;
            padding: 6px 10px;
            margin-bottom: 6px;
        }
        .rekap-col {
            text-align: center;
        }
        .rekap-label {
            font-size: 8.5px;
            color: #475569;
            font-weight: 600;
        }
        .rekap-val {
            font-size: 13px;
            font-weight: 800;
            color: #065F46;
            margin-top: 1px;
        }

        /* AGENDA LIST TABLE */
        .agenda-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8px;
            margin-bottom: 8px;
        }
        .agenda-table th {
            background: #0D7A87;
            color: #FFFFFF;
            padding: 3px 5px;
            font-size: 8px;
            font-weight: 700;
            border: 1px solid #0F766E;
            text-align: center;
        }
        .agenda-table td {
            padding: 2.5px 5px;
            border: 1px solid #E2E8F0;
            color: #334155;
        }
        .agenda-table tr:nth-child(even) td {
            background: #F8FAFC;
        }

        /* SIGN-OFF BLOCK */
        .sign-grid {
            display: flex;
            justify-content: space-between;
            margin-top: 6px;
            padding: 0 40px;
            font-size: 9px;
        }
        .sign-col {
            text-align: center;
            width: 200px;
        }
        .sign-space {
            height: 38px;
        }
        .sign-name {
            font-weight: 800;
            text-decoration: underline;
            color: #0F172A;
        }
    </style>
</head>
<body>

    <!-- =========================================================================
     * HALAMAN 1: FULL KALENDER 1 TAHUN (MATRIKS 12 BULAN) + LEGENDA WARNA
     * ========================================================================= -->
    <div class="pdf-page">
        <!-- KOP RESMI -->
        <?php 
        $logoSekolahB64 = ilmuna_kaldik_file_to_b64($sekolah['logo_sekolah'] ?? '');
        $namaSekolahKaldik = $sekolah['nama_sekolah'] ?: 'ILMUNA LEARN';
        $namaSingkatKaldik = $sekolah['nama_singkat'] ?: $namaSekolahKaldik;
        ?>
        <div class="kop-box">
            <div class="kop-left">
                <?php if ($logoSekolahB64): ?>
                    <img src="<?= $logoSekolahB64 ?>" alt="Logo" style="width: 36px; height: 36px; object-fit: contain;">
                <?php else: ?>
                    <div class="kop-logo">IL</div>
                <?php endif; ?>
                <div class="kop-title">
                    <h1>KALENDER PENDIDIKAN TAHUN PELAJARAN <?= htmlspecialchars($tahun) ?></h1>
                    <p><?= htmlspecialchars($namaSekolahKaldik) ?> — SEMESTER GANJIL &amp; SEMESTER GENAP</p>
                </div>
            </div>
            <div class="kop-right">
                <strong>SKB 3 Menteri &amp; Falakiyah NU Online</strong><br>
                Total Hari Efektif (HE): <strong><?= $grandHe ?> Hari</strong> | Pekan Efektif: <strong><?= $grandPe ?> Pekan</strong>
            </div>
        </div>

        <!-- MATRIKS 12 BULAN (6 BULAN GANJIL DI ATAS, 6 BULAN GENAP DI BAWAH) -->
        <div class="cal-grid-12">
            <?php foreach ($all12Months as $mInfo):
                $mName   = $mInfo[0];
                $mDigit  = $mInfo[1];
                $calYear = $mInfo[2];
                $heBulan = $hePerMonth[$mDigit] ?? 0;

                $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $mDigit, $calYear);
                $firstDayOfWeek = (int)date('w', strtotime("$calYear-$mDigit-01"));
                $firstCol = ($firstDayOfWeek + 6) % 7; // 0=Senin ... 6=Minggu

                $dFirst = sprintf('%04d-%02d-01', $calYear, $mDigit);
                $dLast  = sprintf('%04d-%02d-%02d', $calYear, $mDigit, $daysInMonth);
                $hijriSpan = "🌙 " . getTanggalHijriyah($dFirst, 'd MMM') . " – " . getTanggalHijriyah($dLast, 'd MMM');
            ?>
                <div class="cal-month-card">
                    <!-- HEADER BULAN WARNA HIJAU -->
                    <div class="cal-month-header">
                        <div class="cal-month-name"><?= $mName ?> <?= $calYear ?></div>
                        <div class="cal-month-sub">
                            <span><?= $hijriSpan ?></span>
                            <span class="cal-month-he"><?= $heBulan ?> HE</span>
                        </div>
                    </div>
                    <table class="cal-month-table">
                        <thead>
                            <tr>
                                <th>Sn</th>
                                <th>Sl</th>
                                <th>Rb</th>
                                <th>Km</th>
                                <th>Jm</th>
                                <th>Sb</th>
                                <th class="th-sun">Mg</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $dayCounter = 1;
                            for ($row = 0; $row < 6; $row++):
                                if ($dayCounter > $daysInMonth) break;
                            ?>
                                <tr>
                                    <?php for ($col = 0; $col < 7; $col++): ?>
                                        <?php if ($row === 0 && $col < $firstCol): ?>
                                            <td class="empty-cell">&bull;</td>
                                        <?php elseif ($dayCounter <= $daysInMonth): ?>
                                            <?php
                                            $dateStr = sprintf('%04d-%02d-%02d', $calYear, $mDigit, $dayCounter);
                                            $kItem = $kaldikMap[$dateStr] ?? null;
                                            $isSun = ($col === 6);

                                            $badgeClass = '';
                                            if ($kItem) {
                                                $st = $kItem['status_hari'] ?? '';
                                                $ket = strtolower($kItem['keterangan'] ?? '');
                                                if ($st === 'Efektif KBM') $badgeClass = 'day-kbm';
                                                elseif ($st === 'Libur Nasional') $badgeClass = 'day-libur';
                                                elseif ($st === 'Libur Semester') $badgeClass = 'day-semester';
                                                elseif ($st === 'Kegiatan Sekolah/Ujian') {
                                                    if (strpos($ket, 'p5') !== false || strpos($ket, 'class meeting') !== false) {
                                                        $badgeClass = 'day-p5';
                                                    } else {
                                                        $badgeClass = 'day-kegiatan';
                                                    }
                                                }
                                                elseif ($st === 'Libur Khusus Keagamaan') $badgeClass = 'day-keagamaan';
                                            } else {
                                                if ($isSun) $badgeClass = 'day-libur';
                                            }
                                            ?>
                                            <td class="<?= $badgeClass ?>"><?= $dayCounter ?></td>
                                            <?php $dayCounter++; ?>
                                        <?php else: ?>
                                            <td class="empty-cell">&bull;</td>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- LEGENDA WARNA RESMI KALDIK -->
        <div class="legend-card">
            <div class="legend-title">
                <span>Legenda Warna Status Kalender Pendidikan:</span>
                <span>Standar Operasional Kaldik Satuan Pendidikan</span>
            </div>
            <div class="legend-grid">
                <div class="legend-item">
                    <span class="legend-badge day-kbm">HE</span>
                    <span><strong>Efektif KBM (Hijau):</strong> Pembelajaran Aktif &amp; Tatap Muka</span>
                </div>
                <div class="legend-item">
                    <span class="legend-badge day-libur">LN</span>
                    <span><strong>Libur Nasional (Merah):</strong> Keputusan SKB 3 Menteri</span>
                </div>
                <div class="legend-item">
                    <span class="legend-badge day-semester">LS</span>
                    <span><strong>Libur Semester (Abu-abu):</strong> Libur Akhir Pekan (Minggu) &amp; Jeda</span>
                </div>
                <div class="legend-item">
                    <span class="legend-badge day-kegiatan">UK</span>
                    <span><strong>Ujian &amp; Asesmen (Emas):</strong> Formatif, Sumatif, PTS, &amp; MPLS</span>
                </div>
                <div class="legend-item">
                    <span class="legend-badge day-keagamaan">LK</span>
                    <span><strong>Keagamaan (Ungu):</strong> Libur Ramadhan &amp; Kalender NU</span>
                </div>
                <div class="legend-item">
                    <span class="legend-badge day-p5">P5</span>
                    <span><strong>Class Meeting / P5 (Biru):</strong> Projek Penguatan Karakter</span>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================================
     * HALAMAN 2: RINCIAN PEKAN EFEKTIF (RPE) & DAFTAR AGENDA TAHUNAN
     * ========================================================================= -->
    <div class="pdf-page">
        <!-- KOP RPE -->
        <div class="kop-box">
            <div class="kop-left">
                <?php if ($logoSekolahB64): ?>
                    <img src="<?= $logoSekolahB64 ?>" alt="Logo" style="width: 36px; height: 36px; object-fit: contain;">
                <?php else: ?>
                    <div class="kop-logo">RPE</div>
                <?php endif; ?>
                <div class="kop-title">
                    <h1>RINCIAN PEKAN EFEKTIF (RPE) TINGKAT SATUAN PENDIDIKAN</h1>
                    <p>ANALISIS HARI &amp; PEKAN EFEKTIF BELAJAR TAHUN PELAJARAN <?= htmlspecialchars($tahun) ?></p>
                </div>
            </div>
            <div class="kop-right">
                Satuan Pendidikan: <strong><?= htmlspecialchars($namaSekolahKaldik) ?></strong><br>
                Kurikulum: <strong>Kurikulum Merdeka</strong>
            </div>
        </div>

        <!-- REKAPITULASI TAHUNAN CARDS -->
        <div class="rekap-summary-box">
            <div class="rekap-col">
                <div class="rekap-label">Total Hari Efektif (HE) 1 Tahun</div>
                <div class="rekap-val"><?= $grandHe ?> Hari</div>
            </div>
            <div class="rekap-col">
                <div class="rekap-label">Total Pekan Kalender</div>
                <div class="rekap-val"><?= $grandPekan ?> Pekan</div>
            </div>
            <div class="rekap-col">
                <div class="rekap-label">Total Pekan Efektif KBM</div>
                <div class="rekap-val"><?= $grandPe ?> Pekan</div>
            </div>
            <div class="rekap-col">
                <div class="rekap-label">Total Pekan Tidak Efektif</div>
                <div class="rekap-val"><?= $grandPte ?> Pekan</div>
            </div>
        </div>

        <!-- TABEL RPE SEMESTER GANJIL -->
        <div class="rpe-section-title">
            <span>I. SEMESTER GANJIL (JULI — DESEMBER <?= $yearGanjil ?>)</span>
            <span>Total HE: <?= $rpeGanjil['totals']['total_he'] ?> Hari | Pekan Efektif: <?= $rpeGanjil['totals']['pekan_efektif'] ?> Pekan</span>
        </div>
        <table class="rpe-table">
            <thead>
                <tr>
                    <th style="width: 25px;">No</th>
                    <th class="th-left" style="width: 80px;">Bulan</th>
                    <th style="width: 32px;">Sen</th>
                    <th style="width: 32px;">Sel</th>
                    <th style="width: 32px;">Rab</th>
                    <th style="width: 32px;">Kam</th>
                    <th style="width: 32px;">Jum</th>
                    <th style="width: 32px;">Sab</th>
                    <th style="width: 55px;">Total HE</th>
                    <th style="width: 55px;">Jml Pekan</th>
                    <th style="width: 65px;">Pekan Efektif</th>
                    <th style="width: 75px;">Non-Efektif</th>
                    <th class="th-left">Keterangan Kegiatan / Non-Efektif</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($rpeGanjil['rows'] as $r): ?>
                    <tr>
                        <td><?= $no++ ?></td>
                        <td class="td-left"><strong><?= $r['bulan'] ?></strong></td>
                        <td><?= $r['senin'] ?></td>
                        <td><?= $r['selasa'] ?></td>
                        <td><?= $r['rabu'] ?></td>
                        <td><?= $r['kamis'] ?></td>
                        <td><?= $r['jumat'] ?></td>
                        <td><?= $r['sabtu'] ?></td>
                        <td><strong><?= $r['total_he'] ?></strong></td>
                        <td><?= $r['pekan_total'] ?></td>
                        <td><strong><?= $r['pekan_efektif'] ?></strong></td>
                        <td><?= $r['pekan_tidak_efektif'] ?></td>
                        <td class="td-left"><?= htmlspecialchars($r['keterangan']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="tr-sum">
                    <td colspan="2">JUMLAH GANJIL</td>
                    <td><?= $rpeGanjil['totals']['senin'] ?></td>
                    <td><?= $rpeGanjil['totals']['selasa'] ?></td>
                    <td><?= $rpeGanjil['totals']['rabu'] ?></td>
                    <td><?= $rpeGanjil['totals']['kamis'] ?></td>
                    <td><?= $rpeGanjil['totals']['jumat'] ?></td>
                    <td><?= $rpeGanjil['totals']['sabtu'] ?></td>
                    <td><?= $rpeGanjil['totals']['total_he'] ?></td>
                    <td><?= $rpeGanjil['totals']['pekan_total'] ?></td>
                    <td><?= $rpeGanjil['totals']['pekan_efektif'] ?></td>
                    <td><?= $rpeGanjil['totals']['pekan_tidak_efektif'] ?></td>
                    <td class="td-left">-</td>
                </tr>
            </tbody>
        </table>

        <!-- TABEL RPE SEMESTER GENAP -->
        <div class="rpe-section-title">
            <span>II. SEMESTER GENAP (JANUARI — JUNI <?= $yearGenap ?>)</span>
            <span>Total HE: <?= $rpeGenap['totals']['total_he'] ?> Hari | Pekan Efektif: <?= $rpeGenap['totals']['pekan_efektif'] ?> Pekan</span>
        </div>
        <table class="rpe-table">
            <thead>
                <tr>
                    <th style="width: 25px;">No</th>
                    <th class="th-left" style="width: 80px;">Bulan</th>
                    <th style="width: 32px;">Sen</th>
                    <th style="width: 32px;">Sel</th>
                    <th style="width: 32px;">Rab</th>
                    <th style="width: 32px;">Kam</th>
                    <th style="width: 32px;">Jum</th>
                    <th style="width: 32px;">Sab</th>
                    <th style="width: 55px;">Total HE</th>
                    <th style="width: 55px;">Jml Pekan</th>
                    <th style="width: 65px;">Pekan Efektif</th>
                    <th style="width: 75px;">Non-Efektif</th>
                    <th class="th-left">Keterangan Kegiatan / Non-Efektif</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($rpeGenap['rows'] as $r): ?>
                    <tr>
                        <td><?= $no++ ?></td>
                        <td class="td-left"><strong><?= $r['bulan'] ?></strong></td>
                        <td><?= $r['senin'] ?></td>
                        <td><?= $r['selasa'] ?></td>
                        <td><?= $r['rabu'] ?></td>
                        <td><?= $r['kamis'] ?></td>
                        <td><?= $r['jumat'] ?></td>
                        <td><?= $r['sabtu'] ?></td>
                        <td><strong><?= $r['total_he'] ?></strong></td>
                        <td><?= $r['pekan_total'] ?></td>
                        <td><strong><?= $r['pekan_efektif'] ?></strong></td>
                        <td><?= $r['pekan_tidak_efektif'] ?></td>
                        <td class="td-left"><?= htmlspecialchars($r['keterangan']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="tr-sum">
                    <td colspan="2">JUMLAH GENAP</td>
                    <td><?= $rpeGenap['totals']['senin'] ?></td>
                    <td><?= $rpeGenap['totals']['selasa'] ?></td>
                    <td><?= $rpeGenap['totals']['rabu'] ?></td>
                    <td><?= $rpeGenap['totals']['kamis'] ?></td>
                    <td><?= $rpeGenap['totals']['jumat'] ?></td>
                    <td><?= $rpeGenap['totals']['sabtu'] ?></td>
                    <td><?= $rpeGenap['totals']['total_he'] ?></td>
                    <td><?= $rpeGenap['totals']['pekan_total'] ?></td>
                    <td><?= $rpeGenap['totals']['pekan_efektif'] ?></td>
                    <td><?= $rpeGenap['totals']['pekan_tidak_efektif'] ?></td>
                    <td class="td-left">-</td>
                </tr>
            </tbody>
        </table>

        <!-- TITIMANGSA & TANDA TANGAN RESMI -->
        <?php
        $ttdWakaB64   = ilmuna_kaldik_file_to_b64($sekolah['ttd_waka_kurikulum'] ?? '');
        $ttdKepsekB64 = ilmuna_kaldik_file_to_b64($sekolah['ttd_kepala_sekolah'] ?? '');
        $stempelB64   = ilmuna_kaldik_file_to_b64($sekolah['cap_stempel_path'] ?? '');
        $kotaSekolah  = $sekolah['titimangsa_tempat'] ?: 'Bojonegoro';
        ?>
        <div class="sign-grid">
            <div class="sign-col">
                <p>Mengetahui,<br><strong>Waka. Bidang Kurikulum</strong></p>
                <div class="sign-space" style="position: relative; display: flex; align-items: center; justify-content: center; height: 46px;">
                    <?php if ($ttdWakaB64): ?>
                        <img src="<?= $ttdWakaB64 ?>" alt="TTD Waka" style="max-height: 44px; max-width: 120px; position: relative; z-index: 2;">
                    <?php endif; ?>
                </div>
                <p class="sign-name"><?= htmlspecialchars($sekolah['nama_waka_kurikulum'] ?: 'Ustadz Rahmat Hidayat, M.Pd.I') ?></p>
                <p style="font-size: 8px; color: #64748B;"><?= !empty($sekolah['nip_waka_kurikulum']) ? 'NIP. ' . htmlspecialchars($sekolah['nip_waka_kurikulum']) : 'NIP. -' ?></p>
            </div>
            <div class="sign-col">
                <p>Ditetapkan di: <?= htmlspecialchars($kotaSekolah) ?><br>Pada Tanggal: <?= date('d F Y') ?></p>
                <p style="margin-top: 3px;"><strong>Kepala Satuan Pendidikan</strong></p>
                <div class="sign-space" style="position: relative; display: flex; align-items: center; justify-content: center; height: 46px;">
                    <?php if ($stempelB64): ?>
                        <img src="<?= $stempelB64 ?>" alt="Stempel" style="position: absolute; left: 10px; top: 0; height: 46px; opacity: 0.85; pointer-events: none;">
                    <?php endif; ?>
                    <?php if ($ttdKepsekB64): ?>
                        <img src="<?= $ttdKepsekB64 ?>" alt="TTD Kepala Sekolah" style="max-height: 44px; max-width: 120px; position: relative; z-index: 2;">
                    <?php endif; ?>
                </div>
                <p class="sign-name"><?= htmlspecialchars($sekolah['nama_kepala_sekolah'] ?: 'Dr. H. Muhammad Arifin, M.Pd.') ?></p>
                <p style="font-size: 8px; color: #64748B;"><?= !empty($sekolah['nip_kepala_sekolah']) ? 'NIP. ' . htmlspecialchars($sekolah['nip_kepala_sekolah']) : 'NIP. -' ?></p>
            </div>
        </div>
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

// Mode preview HTML murni (jika diminta via query preview=1)
if (isset($_GET['preview']) && $_GET['preview'] === '1') {
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

// ==============================================================================
// MODE DOWNLOAD LANGSUNG SEBAGAI BERKAS .PDF (VIA HEADLESS RENDERING)
// ==============================================================================
$bin = ilmuna_get_headless_browser_bin();
if ($bin) {
    $tempDir = __DIR__ . '/../../uploads';
    if (!is_dir($tempDir)) @mkdir($tempDir, 0777, true);
    $unique = 'kaldik_pdf_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $htmlTmp = $tempDir . '/' . $unique . '.html';
    $pdfTmp  = $tempDir . '/' . $unique . '.pdf';

    file_put_contents($htmlTmp, $html);

    $cmd = '"' . $bin . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --no-pdf-header-footer --print-to-pdf="' . $pdfTmp . '" "file:///' . str_replace('\\', '/', $htmlTmp) . '"';
    exec($cmd, $o, $ret);

    if (file_exists($pdfTmp) && filesize($pdfTmp) > 0) {
        $filename = 'kalender-pendidikan-rpe-' . str_replace('/', '-', $tahun) . '-' . date('Ymd-His') . '.pdf';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Access-Control-Expose-Headers: Content-Disposition');
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($pdfTmp));

        readfile($pdfTmp);
        @unlink($pdfTmp);
        @unlink($htmlTmp);
        exit;
    }
    @unlink($htmlTmp);
}

// Fallback jika headless tidak ditemukan: kirim berkas HTML sebagai attachment
$filename = 'kalender-pendidikan-rpe-' . str_replace('/', '-', $tahun) . '.html';
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $html;
exit;
