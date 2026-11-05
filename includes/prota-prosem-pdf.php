<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — GENERATOR RESMI DOKUMEN PDF PROTA & PROSEM
 * ==============================================================================
 * File         : includes/prota-prosem-pdf.php
 * Format       : PROTA (A4 Portrait), PROSEM (A4 Landscape)
 * Output       : Berkas PDF resmi Kurikulum Merdeka (Kaldik & RPE Terintegrasi)
 * Engine       : Headless Browser (Microsoft Edge / Google Chrome)
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * 1. Deteksi executable peramban headless (Edge / Chrome)
 */
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

/**
 * 2. Format Tanggal Bahasa Indonesia
 */
if (!function_exists('ilmuna_indo_date_full')) {
    function ilmuna_indo_date_full($dateStr = null) {
        if (!$dateStr || $dateStr === '-' || $dateStr === '0000-00-00') {
            $dateStr = date('Y-m-d');
        }
        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $ts = strtotime($dateStr);
        if (!$ts) return $dateStr;
        $d = (int)date('d', $ts);
        $m = (int)date('m', $ts);
        $y = date('Y', $ts);
        return $d . ' ' . ($months[$m] ?? date('F', $ts)) . ' ' . $y;
    }
}

/**
 * 3. Helper Konversi Berkas Gambar ke Base64 URI Presisi Tinggi
 */
if (!function_exists('ilmuna_file_to_b64')) {
    function ilmuna_file_to_b64($relPath) {
        if (empty($relPath)) return '';
        if (strpos($relPath, '<svg') !== false) {
            return 'data:image/svg+xml;base64,' . base64_encode(trim($relPath));
        }
        if (strpos($relPath, 'data:image') === 0) return $relPath;
        $baseDir = dirname(__DIR__);
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

/**
 * ==============================================================================
 * 4. RENDER HTML TEMPLATE DOKUMEN PROTA (A4 PORTRAIT)
 * ==============================================================================
 */
function ilmuna_render_prota_pdf_html(array $data): string {
    $kmp      = $data['kmp'] ?? [];
    $prota    = $data['prota'] ?? [];
    $details  = $data['details'] ?? [];
    $sekolah  = $data['sekolah'] ?? [];
    $guru     = $data['guru'] ?? [];
    $tahun    = htmlspecialchars($data['tahun_pelajaran'] ?? '2026/2027', ENT_QUOTES, 'UTF-8');

    // Identitas Sekolah
    $namaSekolah   = htmlspecialchars($sekolah['nama_sekolah'] ?? 'ILMUNA LEARN ACADEMY', ENT_QUOTES, 'UTF-8');
    $alamatSekolah = htmlspecialchars($sekolah['alamat_sekolah'] ?? 'Jl. Raya Pendidikan No. 1, Bojonegoro', ENT_QUOTES, 'UTF-8');
    $npsnSekolah   = !empty($sekolah['npsn']) ? 'NPSN: ' . htmlspecialchars($sekolah['npsn'], ENT_QUOTES, 'UTF-8') : '';
    $titimangsa    = !empty($sekolah['titimangsa_tempat']) ? htmlspecialchars($sekolah['titimangsa_tempat'], ENT_QUOTES, 'UTF-8') : 'Bojonegoro';

    // Logo & TTD
    $logoBase64 = '';
    if (!empty($sekolah['logo_sekolah'])) {
        $logoBase64 = ilmuna_file_to_b64($sekolah['logo_sekolah']);
    }
    if (empty($logoBase64)) {
        $logoPath = BASE_PATH . '/assets/images/logo.png';
        if (file_exists($logoPath)) {
            $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }
    }

    $ttdKepsekB64 = !empty($sekolah['ttd_kepala_sekolah']) ? ilmuna_file_to_b64($sekolah['ttd_kepala_sekolah']) : '';
    $stempelB64   = !empty($sekolah['cap_stempel_path']) ? ilmuna_file_to_b64($sekolah['cap_stempel_path']) : '';
    $namaKepsek   = htmlspecialchars($sekolah['nama_kepala_sekolah'] ?? 'Kepala Sekolah', ENT_QUOTES, 'UTF-8');
    $nipKepsek    = !empty($sekolah['nip_kepala_sekolah']) ? 'NIP. ' . htmlspecialchars($sekolah['nip_kepala_sekolah'], ENT_QUOTES, 'UTF-8') : '';

    $namaGuru     = htmlspecialchars($guru['nama_guru'] ?? ($kmp['nama_guru'] ?? 'Guru Pengampu'), ENT_QUOTES, 'UTF-8');
    $nipGuru      = !empty($guru['nip']) && $guru['nip'] !== '-' ? 'NIP. ' . htmlspecialchars($guru['nip'], ENT_QUOTES, 'UTF-8') : (!empty($kmp['nip_guru']) && $kmp['nip_guru'] !== '-' ? 'NIP. ' . htmlspecialchars($kmp['nip_guru'], ENT_QUOTES, 'UTF-8') : '');

    $namaKelas    = htmlspecialchars($kmp['nama_kelas'] ?? 'Semua Kelas', ENT_QUOTES, 'UTF-8');
    $namaMapel    = htmlspecialchars($kmp['nama_mata_pelajaran'] ?? 'Mata Pelajaran', ENT_QUOTES, 'UTF-8');
    $fase         = htmlspecialchars($prota['fase'] ?? 'Fase F', ENT_QUOTES, 'UTF-8');

    $totGanjil = 0;
    $totGenap  = 0;
    foreach ($details as $d) {
        if (($d['semester'] ?? '') === 'Ganjil') $totGanjil += (int)($d['alokasi_waktu'] ?? 0);
        else $totGenap += (int)($d['alokasi_waktu'] ?? 0);
    }
    $totTahunan = (int)($prota['total_jtm_tahunan'] ?? ($totGanjil + $totGenap));

    ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>PROTA - <?= $namaMapel ?> - <?= $namaKelas ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 14mm 14mm 14mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            font-size: 11px;
            color: #0F172A;
            background: #FFFFFF;
            margin: 0;
            padding: 0;
            line-height: 1.4;
        }
        .header-kop {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 3px double #1E293B;
            padding-bottom: 8px;
            margin-bottom: 14px;
        }
        .kop-logo {
            width: 65px;
            height: 65px;
            object-fit: contain;
        }
        .kop-text {
            text-align: center;
            flex: 1;
            padding: 0 12px;
        }
        .kop-text h2 {
            margin: 0 0 2px 0;
            font-size: 15px;
            font-weight: 800;
            color: #0F172A;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .kop-text h3 {
            margin: 0 0 3px 0;
            font-size: 12px;
            font-weight: 700;
            color: #0D7A87;
        }
        .kop-text p {
            margin: 0;
            font-size: 10px;
            color: #64748B;
        }
        .doc-title-box {
            text-align: center;
            margin-bottom: 14px;
        }
        .doc-title {
            margin: 0;
            font-size: 15px;
            font-weight: 800;
            color: #1E3A8A;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .doc-subtitle {
            margin: 2px 0 0 0;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-bottom: 14px;
            background: #F8FAFC;
            border: 1px solid #CBD5E1;
            border-radius: 6px;
        }
        .meta-table td {
            padding: 5px 10px;
            vertical-align: top;
        }
        .meta-label {
            font-weight: 700;
            color: #334155;
            width: 140px;
        }
        .table-data {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
            margin-bottom: 16px;
        }
        .table-data th, .table-data td {
            border: 1px solid #CBD5E1;
            padding: 6px 8px;
            vertical-align: top;
        }
        .table-data th {
            background: #0D7A87;
            color: #FFFFFF;
            font-weight: 700;
            text-align: center;
            font-size: 11px;
        }
        .table-data tr:nth-child(even) {
            background: #F8FAFC;
        }
        .badge-tp {
            display: inline-block;
            background: #EFF6FF;
            color: #1D4ED8;
            font-weight: 700;
            padding: 2px 5px;
            border-radius: 4px;
            border: 1px solid #BFDBFE;
            margin-right: 4px;
        }
        .tfoot-rekap {
            background: #F1F5F9;
            font-weight: 700;
        }
        .signature-table {
            width: 100%;
            margin-top: 24px;
            font-size: 11px;
            page-break-inside: avoid;
        }
        .signature-box {
            width: 48%;
            text-align: center;
            vertical-align: top;
        }
        .signature-space {
            height: 60px;
            position: relative;
        }
        .stempel-img {
            position: absolute;
            left: 20%;
            top: -10px;
            width: 80px;
            opacity: 0.85;
            pointer-events: none;
        }
        .ttd-img {
            position: absolute;
            left: 35%;
            top: 0;
            height: 55px;
            pointer-events: none;
        }
    </style>
</head>
<body>

    <!-- KOP RESMI SEKOLAH -->
    <div class="header-kop">
        <?php if (!empty($logoBase64)): ?>
            <img src="<?= $logoBase64 ?>" class="kop-logo" alt="Logo">
        <?php else: ?>
            <div style="width:65px;"></div>
        <?php endif; ?>
        <div class="kop-text">
            <h2><?= $namaSekolah ?></h2>
            <h3>KURIKULUM MERDEKA — PROGRAM TAHUNAN (PROTA)</h3>
            <p><?= $alamatSekolah ?> <?= !empty($npsnSekolah) ? ' | ' . $npsnSekolah : '' ?></p>
        </div>
        <div style="width:65px;"></div>
    </div>

    <!-- JUDUL DOKUMEN -->
    <div class="doc-title-box">
        <h1 class="doc-title">PROGRAM TAHUNAN (PROTA)</h1>
        <p class="doc-subtitle">TAHUN PELAJARAN <?= $tahun ?></p>
    </div>

    <!-- METADATA MAPEL & KELAS -->
    <table class="meta-table">
        <tr>
            <td class="meta-label">Satuan Pendidikan</td>
            <td>: <?= $namaSekolah ?></td>
            <td class="meta-label">Mata Pelajaran</td>
            <td>: <strong><?= $namaMapel ?></strong></td>
        </tr>
        <tr>
            <td class="meta-label">Kelas / Fase</td>
            <td>: <?= $namaKelas ?> / <strong><?= $fase ?></strong></td>
            <td class="meta-label">Tahun Pelajaran</td>
            <td>: <?= $tahun ?></td>
        </tr>
    </table>

    <!-- TABEL UTAMA PROTA -->
    <table class="table-data">
        <thead>
            <tr>
                <th style="width: 35px;">No</th>
                <th style="width: 70px;">Semester</th>
                <th style="width: 140px;">Elemen Kurikulum</th>
                <th>Alur & Tujuan Pembelajaran (TP) / Lingkup Materi</th>
                <th style="width: 70px;">Alokasi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($details)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 25px; color: #94A3B8;">
                        Belum ada Tujuan Pembelajaran (TP) yang dipetakan pada Program Tahunan.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($details as $idx => $row): ?>
                    <tr>
                        <td style="text-align: center; font-weight: 600;"><?= $idx + 1 ?></td>
                        <td style="text-align: center;">
                            <span style="font-weight: 600; color: <?= ($row['semester'] === 'Ganjil' ? '#0D7A87' : '#D97706') ?>;">
                                <?= htmlspecialchars($row['semester'] ?? 'Ganjil', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($row['nama_elemen'] ?? 'Materi Inti', ENT_QUOTES, 'UTF-8') ?></strong>
                        </td>
                        <td>
                            <?php if (!empty($row['kode_tp'])): ?>
                                <span class="badge-tp"><?= htmlspecialchars($row['kode_tp'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                            <span style="font-weight: 600; color: #1E293B;">
                                <?= htmlspecialchars($row['materi_pokok'] ?? $row['uraian_tp'] ?? 'Pembelajaran KBM', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php if (!empty($row['uraian_tp']) && $row['uraian_tp'] !== ($row['materi_pokok'] ?? '')): ?>
                                <div style="font-size: 9.5px; color: #64748B; margin-top: 3px; line-height: 1.35;">
                                    <?= htmlspecialchars($row['uraian_tp'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center; font-weight: 700; color: #0F172A;">
                            <?= (int)($row['alokasi_waktu'] ?? 0) ?> JP
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="tfoot-rekap">
                <td colspan="4" style="text-align: right; padding-right: 12px;">SUBTOTAL JAM TATAP MUKA SEMESTER GANJIL:</td>
                <td style="text-align: center; color: #0D7A87;"><?= $totGanjil ?> JP</td>
            </tr>
            <tr class="tfoot-rekap">
                <td colspan="4" style="text-align: right; padding-right: 12px;">SUBTOTAL JAM TATAP MUKA SEMESTER GENAP:</td>
                <td style="text-align: center; color: #D97706;"><?= $totGenap ?> JP</td>
            </tr>
            <tr class="tfoot-rekap" style="background: #E2E8F0; font-size: 11px;">
                <td colspan="4" style="text-align: right; padding-right: 12px; font-weight: 800;">TOTAL ALOKASI JAM TATAP MUKA (JTM) TAHUNAN:</td>
                <td style="text-align: center; font-weight: 800; color: #0D7A87;"><?= $totTahunan ?> JP</td>
            </tr>
        </tfoot>
    </table>

    <!-- TANDA TANGAN LEGALITAS -->
    <table class="signature-table">
        <tr>
            <td class="signature-box">
                Mengetahui,<br>
                Kepala Sekolah
                <div class="signature-space">
                    <?php if (!empty($stempelB64)): ?>
                        <img src="<?= $stempelB64 ?>" class="stempel-img" alt="Stempel">
                    <?php endif; ?>
                    <?php if (!empty($ttdKepsekB64)): ?>
                        <img src="<?= $ttdKepsekB64 ?>" class="ttd-img" alt="TTD">
                    <?php endif; ?>
                </div>
                <strong><?= $namaKepsek ?></strong><br>
                <?= $nipKepsek ?>
            </td>
            <td class="signature-box">
                <?= $titimangsa ?>, <?= ilmuna_indo_date_full() ?><br>
                Guru Mata Pelajaran
                <div class="signature-space"></div>
                <strong><?= $namaGuru ?></strong><br>
                <?= $nipGuru ?>
            </td>
        </tr>
    </table>

</body>
</html>
<?php
    return ob_get_clean();
}

/**
 * ==============================================================================
 * 5. RENDER HTML TEMPLATE DOKUMEN PROSEM (A4 LANDSCAPE)
 * ==============================================================================
 */
function ilmuna_render_prosem_pdf_html(array $data): string {
    $kmp            = $data['kmp'] ?? [];
    $prosem         = $data['prosem'] ?? [];
    $details        = $data['details'] ?? [];
    $mingguan       = $data['mingguan'] ?? [];
    $sekolah        = $data['sekolah'] ?? [];
    $guru           = $data['guru'] ?? [];
    $tahun          = htmlspecialchars($data['tahun_pelajaran'] ?? '2026/2027', ENT_QUOTES, 'UTF-8');
    $semester       = htmlspecialchars($data['semester'] ?? 'Ganjil', ENT_QUOTES, 'UTF-8');

    // Daftar Bulan sesuai semester
    $bulanList = ($semester === 'Ganjil')
        ? ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']
        : ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];

    // Identitas Sekolah
    $namaSekolah   = htmlspecialchars($sekolah['nama_sekolah'] ?? 'ILMUNA LEARN ACADEMY', ENT_QUOTES, 'UTF-8');
    $alamatSekolah = htmlspecialchars($sekolah['alamat_sekolah'] ?? 'Jl. Raya Pendidikan No. 1, Bojonegoro', ENT_QUOTES, 'UTF-8');
    $npsnSekolah   = !empty($sekolah['npsn']) ? 'NPSN: ' . htmlspecialchars($sekolah['npsn'], ENT_QUOTES, 'UTF-8') : '';
    $titimangsa    = !empty($sekolah['titimangsa_tempat']) ? htmlspecialchars($sekolah['titimangsa_tempat'], ENT_QUOTES, 'UTF-8') : 'Bojonegoro';

    // Logo & TTD
    $logoBase64 = '';
    if (!empty($sekolah['logo_sekolah'])) {
        $logoBase64 = ilmuna_file_to_b64($sekolah['logo_sekolah']);
    }
    if (empty($logoBase64)) {
        $logoPath = BASE_PATH . '/assets/images/logo.png';
        if (file_exists($logoPath)) {
            $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }
    }

    $ttdKepsekB64 = !empty($sekolah['ttd_kepala_sekolah']) ? ilmuna_file_to_b64($sekolah['ttd_kepala_sekolah']) : '';
    $stempelB64   = !empty($sekolah['cap_stempel_path']) ? ilmuna_file_to_b64($sekolah['cap_stempel_path']) : '';
    $namaKepsek   = htmlspecialchars($sekolah['nama_kepala_sekolah'] ?? 'Kepala Sekolah', ENT_QUOTES, 'UTF-8');
    $nipKepsek    = !empty($sekolah['nip_kepala_sekolah']) ? 'NIP. ' . htmlspecialchars($sekolah['nip_kepala_sekolah'], ENT_QUOTES, 'UTF-8') : '';

    $namaGuru     = htmlspecialchars($guru['nama_guru'] ?? ($kmp['nama_guru'] ?? 'Guru Pengampu'), ENT_QUOTES, 'UTF-8');
    $nipGuru      = !empty($guru['nip']) && $guru['nip'] !== '-' ? 'NIP. ' . htmlspecialchars($guru['nip'], ENT_QUOTES, 'UTF-8') : (!empty($kmp['nip_guru']) && $kmp['nip_guru'] !== '-' ? 'NIP. ' . htmlspecialchars($kmp['nip_guru'], ENT_QUOTES, 'UTF-8') : '');

    $namaKelas    = htmlspecialchars($kmp['nama_kelas'] ?? 'Semua Kelas', ENT_QUOTES, 'UTF-8');
    $namaMapel    = htmlspecialchars($kmp['nama_mata_pelajaran'] ?? 'Mata Pelajaran', ENT_QUOTES, 'UTF-8');

    // Hitung Kolom Sum
    $colSums = [];
    foreach ($bulanList as $b) {
        for ($w = 1; $w <= 5; $w++) {
            $colSums[$b][$w] = 0;
        }
    }
    $totalSemJp = 0;
    foreach ($details as $d) {
        $dId = (int)($d['id'] ?? 0);
        $totalSemJp += (int)($d['alokasi_waktu'] ?? 0);
        foreach ($bulanList as $b) {
            for ($w = 1; $w <= 5; $w++) {
                $cVal = (int)($mingguan[$dId][$b][$w] ?? 0);
                $colSums[$b][$w] += $cVal;
            }
        }
    }

    ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>PROSEM - <?= $namaMapel ?> - <?= $namaKelas ?></title>
    <style>
        @page {
            size: A4 landscape;
            margin: 10mm 12mm 12mm 12mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            font-size: 10px;
            color: #0F172A;
            background: #FFFFFF;
            margin: 0;
            padding: 0;
            line-height: 1.35;
        }
        .header-kop {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2.5px double #1E293B;
            padding-bottom: 6px;
            margin-bottom: 10px;
        }
        .kop-logo {
            width: 55px;
            height: 55px;
            object-fit: contain;
        }
        .kop-text {
            text-align: center;
            flex: 1;
            padding: 0 10px;
        }
        .kop-text h2 {
            margin: 0 0 2px 0;
            font-size: 14px;
            font-weight: 800;
            color: #0F172A;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .kop-text h3 {
            margin: 0 0 2px 0;
            font-size: 11.5px;
            font-weight: 700;
            color: #0D7A87;
        }
        .kop-text p {
            margin: 0;
            font-size: 9.5px;
            color: #64748B;
        }
        .doc-title-box {
            text-align: center;
            margin-bottom: 10px;
        }
        .doc-title {
            margin: 0;
            font-size: 14px;
            font-weight: 800;
            color: #1E3A8A;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .doc-subtitle {
            margin: 2px 0 0 0;
            font-size: 11px;
            font-weight: 600;
            color: #475569;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
            margin-bottom: 10px;
            background: #F8FAFC;
            border: 1px solid #CBD5E1;
            border-radius: 4px;
        }
        .meta-table td {
            padding: 4px 8px;
            vertical-align: top;
        }
        .meta-label {
            font-weight: 700;
            color: #334155;
            width: 120px;
        }
        .table-data {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            margin-bottom: 14px;
        }
        .table-data th, .table-data td {
            border: 1px solid #CBD5E1;
            padding: 4px 5px;
            vertical-align: middle;
        }
        .table-data th {
            background: #0D7A87;
            color: #FFFFFF;
            font-weight: 700;
            text-align: center;
            font-size: 9.5px;
        }
        .table-data tr:nth-child(even) {
            background: #F8FAFC;
        }
        .badge-tp {
            display: inline-block;
            background: #EFF6FF;
            color: #1D4ED8;
            font-weight: 700;
            padding: 1px 4px;
            border-radius: 3px;
            border: 1px solid #BFDBFE;
            margin-right: 3px;
            font-size: 9px;
        }
        .tfoot-rekap {
            background: #F1F5F9;
            font-weight: 700;
        }
        .signature-table {
            width: 100%;
            margin-top: 16px;
            font-size: 10px;
            page-break-inside: avoid;
        }
        .signature-box {
            width: 48%;
            text-align: center;
            vertical-align: top;
        }
        .signature-space {
            height: 50px;
            position: relative;
        }
        .stempel-img {
            position: absolute;
            left: 20%;
            top: -8px;
            width: 70px;
            opacity: 0.85;
            pointer-events: none;
        }
        .ttd-img {
            position: absolute;
            left: 35%;
            top: 0;
            height: 48px;
            pointer-events: none;
        }
    </style>
</head>
<body>

    <!-- KOP RESMI SEKOLAH -->
    <div class="header-kop">
        <?php if (!empty($logoBase64)): ?>
            <img src="<?= $logoBase64 ?>" class="kop-logo" alt="Logo">
        <?php else: ?>
            <div style="width:55px;"></div>
        <?php endif; ?>
        <div class="kop-text">
            <h2><?= $namaSekolah ?></h2>
            <h3>KURIKULUM MERDEKA — PROGRAM SEMESTER (PROSEM)</h3>
            <p><?= $alamatSekolah ?> <?= !empty($npsnSekolah) ? ' | ' . $npsnSekolah : '' ?></p>
        </div>
        <div style="width:55px;"></div>
    </div>

    <!-- JUDUL DOKUMEN -->
    <div class="doc-title-box">
        <h1 class="doc-title">PROGRAM SEMESTER (PROSEM)</h1>
        <p class="doc-subtitle">SEMESTER <?= strtoupper($semester) ?> — TAHUN PELAJARAN <?= $tahun ?></p>
    </div>

    <!-- METADATA MAPEL & KELAS -->
    <table class="meta-table">
        <tr>
            <td class="meta-label">Satuan Pendidikan</td>
            <td>: <?= $namaSekolah ?></td>
            <td class="meta-label">Mata Pelajaran</td>
            <td>: <strong><?= $namaMapel ?></strong></td>
        </tr>
        <tr>
            <td class="meta-label">Kelas / Semester</td>
            <td>: <?= $namaKelas ?> / <strong><?= $semester ?></strong></td>
            <td class="meta-label">Tahun Pelajaran</td>
            <td>: <?= $tahun ?></td>
        </tr>
    </table>

    <!-- TABEL UTAMA MATRIKS PROSEM -->
    <table class="table-data">
        <thead>
            <tr>
                <th rowspan="2" style="width: 25px;">No</th>
                <th rowspan="2" style="width: 250px;">Alur & Tujuan Pembelajaran (TP) / Agenda Pembelajaran</th>
                <th rowspan="2" style="width: 35px;">JML JP</th>
                <?php foreach ($bulanList as $b): ?>
                    <th colspan="5" style="text-align: center; font-size: 10px;"><?= $b ?></th>
                <?php endforeach; ?>
            </tr>
            <tr>
                <?php foreach ($bulanList as $b): ?>
                    <?php for ($w = 1; $w <= 5; $w++): ?>
                        <th style="width: 17px; text-align: center; font-size: 8.5px;"><?= $w ?></th>
                    <?php endfor; ?>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($details)): ?>
                <tr>
                    <td colspan="<?= 3 + (count($bulanList) * 5) ?>" style="text-align: center; padding: 25px; color: #94A3B8;">
                        Belum ada data agenda KBM di Program Semester ini.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($details as $idx => $row): ?>
                    <?php
                        $dId = (int)$row['id'];
                        $label = $row['nama_agenda'] ?? $row['materi_pokok'] ?? ($row['uraian_tp'] ?? 'Agenda KBM');
                        $isAsesmen = in_array($row['kategori'] ?? '', ['Asesmen Sumatif', 'Sumatif Tengah Semester', 'Proyek', 'Asesmen Sumatif Akhir Semester']);
                    ?>
                    <tr style="<?= $isAsesmen ? 'background: #FEF3C7; font-weight: 600;' : '' ?>">
                        <td style="text-align: center; font-weight: 600;"><?= $idx + 1 ?></td>
                        <td>
                            <?php if (!empty($row['kode_tp'])): ?>
                                <span class="badge-tp"><?= htmlspecialchars($row['kode_tp'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                            <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td style="text-align: center; font-weight: 700;"><?= (int)($row['alokasi_waktu'] ?? 0) ?></td>

                        <?php foreach ($bulanList as $b): ?>
                            <?php for ($w = 1; $w <= 5; $w++): ?>
                                <?php
                                    $cellVal = (int)($mingguan[$dId][$b][$w] ?? 0);
                                ?>
                                <td style="text-align: center; font-weight: <?= $cellVal > 0 ? '700' : 'normal' ?>; color: <?= $cellVal > 0 ? '#0D7A87' : '#94A3B8' ?>;">
                                    <?= $cellVal > 0 ? $cellVal : '' ?>
                                </td>
                            <?php endfor; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="tfoot-rekap">
                <td colspan="2" style="text-align: right; padding-right: 8px;">TOTAL JAM PELAJARAN (JP):</td>
                <td style="text-align: center; color: #0D7A87; font-weight: 800;"><?= $totalSemJp ?></td>
                <?php foreach ($bulanList as $b): ?>
                    <?php for ($w = 1; $w <= 5; $w++): ?>
                        <?php $s = $colSums[$b][$w]; ?>
                        <td style="text-align: center; font-weight: 700; color: <?= $s > 0 ? '#0F172A' : '#94A3B8' ?>;">
                            <?= $s > 0 ? $s : '' ?>
                        </td>
                    <?php endfor; ?>
                <?php endforeach; ?>
            </tr>
        </tfoot>
    </table>

    <!-- TANDA TANGAN LEGALITAS -->
    <table class="signature-table">
        <tr>
            <td class="signature-box">
                Mengetahui,<br>
                Kepala Sekolah
                <div class="signature-space">
                    <?php if (!empty($stempelB64)): ?>
                        <img src="<?= $stempelB64 ?>" class="stempel-img" alt="Stempel">
                    <?php endif; ?>
                    <?php if (!empty($ttdKepsekB64)): ?>
                        <img src="<?= $ttdKepsekB64 ?>" class="ttd-img" alt="TTD">
                    <?php endif; ?>
                </div>
                <strong><?= $namaKepsek ?></strong><br>
                <?= $nipKepsek ?>
            </td>
            <td class="signature-box">
                <?= $titimangsa ?>, <?= ilmuna_indo_date_full() ?><br>
                Guru Mata Pelajaran
                <div class="signature-space"></div>
                <strong><?= $namaGuru ?></strong><br>
                <?= $nipGuru ?>
            </td>
        </tr>
    </table>

</body>
</html>
<?php
    return ob_get_clean();
}

/**
 * ==============================================================================
 * 6. GENERATE BERKAS PDF MENGGUNAKAN HEADLESS BROWSER
 * ==============================================================================
 */
function ilmuna_generate_prota_pdf(array $data, ?string $targetPdfPath = null): array {
    $bin = ilmuna_get_headless_browser_bin();
    if (!$bin) {
        return ['ok' => false, 'error' => 'Headless browser (Microsoft Edge / Google Chrome) tidak ditemukan di server.'];
    }

    $uploadDir = BASE_PATH . '/uploads/kurikulum_pdf';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    if (!is_writable($uploadDir)) {
        return ['ok' => false, 'error' => 'Direktori penyimpanan PDF tidak dapat ditulis: ' . $uploadDir];
    }

    $namaKelas = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $data['kmp']['nama_kelas'] ?? 'Kelas');
    $namaMapel = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $data['kmp']['nama_mata_pelajaran'] ?? 'Mapel');

    if (empty($targetPdfPath)) {
        $unique = 'PROTA_' . $namaKelas . '_' . $namaMapel . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $targetPdfPath = $uploadDir . '/' . $unique . '.pdf';
    }

    $uniqueTmp = 'tmp_prota_' . bin2hex(random_bytes(6));
    $htmlTmp = $uploadDir . '/' . $uniqueTmp . '.html';

    $htmlContent = ilmuna_render_prota_pdf_html($data);
    if (!file_put_contents($htmlTmp, $htmlContent)) {
        return ['ok' => false, 'error' => 'Gagal menulis berkas HTML sementara ke: ' . $htmlTmp];
    }

    $fileUri = 'file:///' . str_replace('\\', '/', $htmlTmp);
    $userDataDir = sys_get_temp_dir() . '/edge_prota_pdf_' . uniqid();
    @mkdir($userDataDir, 0777, true);

    $cmd = '"' . $bin . '"'
         . ' --headless=new'
         . ' --disable-gpu'
         . ' --no-sandbox'
         . ' --disable-dev-shm-usage'
         . ' --disable-software-rasterizer'
         . ' --disable-extensions'
         . ' --user-data-dir="' . $userDataDir . '"'
         . ' --no-pdf-header-footer'
         . ' --print-to-pdf="' . $targetPdfPath . '"'
         . ' "' . $fileUri . '" 2>&1';

    @set_time_limit(180);
    exec($cmd, $out, $ret);

    @unlink($htmlTmp);
    @rmdir($userDataDir);

    if (file_exists($targetPdfPath) && filesize($targetPdfPath) > 0) {
        return ['ok' => true, 'path' => $targetPdfPath];
    }

    $outStr = implode(' | ', array_slice($out ?? [], -5));
    return [
        'ok'    => false,
        'error' => 'Headless browser gagal mengenerate PDF PROTA. Exit code: ' . $ret . '. Log: ' . mb_substr($outStr, 0, 300),
    ];
}

function ilmuna_generate_prosem_pdf(array $data, ?string $targetPdfPath = null): array {
    $bin = ilmuna_get_headless_browser_bin();
    if (!$bin) {
        return ['ok' => false, 'error' => 'Headless browser (Microsoft Edge / Google Chrome) tidak ditemukan di server.'];
    }

    $uploadDir = BASE_PATH . '/uploads/kurikulum_pdf';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    if (!is_writable($uploadDir)) {
        return ['ok' => false, 'error' => 'Direktori penyimpanan PDF tidak dapat ditulis: ' . $uploadDir];
    }

    $namaKelas = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $data['kmp']['nama_kelas'] ?? 'Kelas');
    $namaMapel = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $data['kmp']['nama_mata_pelajaran'] ?? 'Mapel');
    $sem       = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $data['semester'] ?? 'Ganjil');

    if (empty($targetPdfPath)) {
        $unique = 'PROSEM_' . strtoupper($sem) . '_' . $namaKelas . '_' . $namaMapel . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $targetPdfPath = $uploadDir . '/' . $unique . '.pdf';
    }

    $uniqueTmp = 'tmp_prosem_' . bin2hex(random_bytes(6));
    $htmlTmp = $uploadDir . '/' . $uniqueTmp . '.html';

    $htmlContent = ilmuna_render_prosem_pdf_html($data);
    if (!file_put_contents($htmlTmp, $htmlContent)) {
        return ['ok' => false, 'error' => 'Gagal menulis berkas HTML sementara ke: ' . $htmlTmp];
    }

    $fileUri = 'file:///' . str_replace('\\', '/', $htmlTmp);
    $userDataDir = sys_get_temp_dir() . '/edge_prosem_pdf_' . uniqid();
    @mkdir($userDataDir, 0777, true);

    $cmd = '"' . $bin . '"'
         . ' --headless=new'
         . ' --disable-gpu'
         . ' --no-sandbox'
         . ' --disable-dev-shm-usage'
         . ' --disable-software-rasterizer'
         . ' --disable-extensions'
         . ' --user-data-dir="' . $userDataDir . '"'
         . ' --no-pdf-header-footer'
         . ' --print-to-pdf="' . $targetPdfPath . '"'
         . ' "' . $fileUri . '" 2>&1';

    @set_time_limit(180);
    exec($cmd, $out, $ret);

    @unlink($htmlTmp);
    @rmdir($userDataDir);

    if (file_exists($targetPdfPath) && filesize($targetPdfPath) > 0) {
        return ['ok' => true, 'path' => $targetPdfPath];
    }

    $outStr = implode(' | ', array_slice($out ?? [], -5));
    return [
        'ok'    => false,
        'error' => 'Headless browser gagal mengenerate PDF PROSEM. Exit code: ' . $ret . '. Log: ' . mb_substr($outStr, 0, 300),
    ];
}

