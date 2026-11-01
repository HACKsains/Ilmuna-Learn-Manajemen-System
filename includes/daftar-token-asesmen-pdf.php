<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — GENERATOR PDF DAFTAR TOKEN SESI UJIAN (CBT)
 * ==============================================================================
 * File Terpisah Khusus Pencetakan / Ekspor Berkas PDF Daftar Token Asesmen.
 * Mendukung Kategori:
 *   - Asesmen Sumatif (Lingkup Materi, STS, SAS)
 *   - Asesmen Formatif
 *   - Asesmen Diagnostik
 *   - Remedial / Pengayaan
 * Format Kertas: A4 Portrait (210 x 297 mm)
 * Engine: Headless Browser (Edge / Chrome) dengan rendering presisi tinggi.
 * Metode Ekspor: Direct Blob Download ke peramban tanpa dialog window.print().
 * Desain: Lumina Gold Professional (Navy #1D3B5E, Teal #0D7A87, Sky #52B2DC, Gold #D97706)
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/includes/qrcode_engine.php';
if (file_exists(BASE_PATH . '/database.php')) {
    require_once BASE_PATH . '/database.php';
}

if (!function_exists('ilmuna_token_file_to_b64')) {
    function ilmuna_token_file_to_b64($relPath) {
        if (empty($relPath)) return '';
        if (strpos($relPath, '<svg') !== false) {
            return 'data:image/svg+xml;base64,' . base64_encode(trim($relPath));
        }
        if (strpos($relPath, 'data:image') === 0) return $relPath;
        $candidates = [
            BASE_PATH . '/' . ltrim($relPath, '/\\'),
            BASE_PATH . '/uploads/' . ltrim($relPath, '/\\'),
            BASE_PATH . '/uploads/school/' . ltrim($relPath, '/\\'),
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

if (!function_exists('ilmuna_token_indo_day')) {
    function ilmuna_token_indo_day($dateStr) {
        if (!$dateStr || $dateStr === '-' || $dateStr === '0000-00-00') return '-';
        $days = [
            'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
        ];
        $ts = strtotime($dateStr);
        if (!$ts) return '-';
        return $days[date('l', $ts)] ?? '-';
    }
}

if (!function_exists('ilmuna_token_indo_date_full')) {
    function ilmuna_token_indo_date_full($dateStr) {
        if (!$dateStr || $dateStr === '-' || $dateStr === '0000-00-00') return '-';
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

if (!function_exists('ilmuna_get_token_logo_b64')) {
    function ilmuna_get_token_logo_b64() {
        static $cachedLogo = null;
        if ($cachedLogo !== null) {
            return $cachedLogo;
        }

        $candidates = [
            BASE_PATH . '/assets/images/landscape-logo-learn.png',
            BASE_PATH . '/assets/images/logo-learn.png',
            BASE_PATH . '/assets/images/landscape-logo.png',
            BASE_PATH . '/assets/images/logo.png'
        ];
        foreach ($candidates as $f) {
            if (file_exists($f)) {
                $ext = pathinfo($f, PATHINFO_EXTENSION);
                $cachedLogo = 'data:image/' . ($ext === 'jpg' ? 'jpeg' : 'png') . ';base64,' . base64_encode(file_get_contents($f));
                return $cachedLogo;
            }
        }
        return '';
    }
}

/**
 * Generate QR Code SVG untuk Token Asesmen
 */
if (!function_exists('ilmuna_generate_token_qr_svg')) {
    function ilmuna_generate_token_qr_svg($tokenStr, $judulAsm = '') {
        $tokenStr = trim((string)$tokenStr);
        if ($tokenStr === '' || $tokenStr === '-') {
            $tokenStr = 'NO-TOKEN';
        }
        $qrPayload = 'ILMUNA-TOKEN:' . $tokenStr . ($judulAsm ? '|ASM:' . $judulAsm : '');
        try {
            $qr = new QRCode($qrPayload, ['s' => 'qrh']);
            return $qr->svg(['w' => 120, 'h' => 120, 'mode' => 'svg']);
        } catch (Throwable $e) {
            return '';
        }
    }
}

/**
 * Render Dokumen HTML Siap Cetak untuk Daftar Token Sesi Asesmen
 *
 * @param array $tokensData Daftar array data token asesmen
 * @param array $metaInfo Informasi pelengkap (kategori, tahun pelajaran, semester, tgl cetak, dll.)
 * @return string HTML lengkap
 */
function ilmuna_render_daftar_token_pdf_html(array $tokensData, array $metaInfo = []) {
    // Ambil Profil Identitas Sekolah
    $sekolah = [];
    if (function_exists('getDBConnection')) {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->query("SELECT * FROM identitas_sekolah WHERE is_active = 1 LIMIT 1");
            $sekolah = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!$sekolah) {
                $stmt = $pdo->query("SELECT * FROM identitas_sekolah ORDER BY id DESC LIMIT 1");
                $sekolah = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {}
    }

    $logoB64 = '';
    if (!empty($sekolah['logo_sekolah'])) {
        $logoB64 = ilmuna_token_file_to_b64($sekolah['logo_sekolah']);
    }
    if (!$logoB64) {
        $logoB64 = ilmuna_get_token_logo_b64();
    }

    $tglCetak = date('d/m/Y, H:i') . ' WIB';
    $tglCetakFull = ilmuna_token_indo_day(date('Y-m-d')) . ', ' . ilmuna_token_indo_date_full(date('Y-m-d'));

    $tipeJudul = strtoupper($metaInfo['tipe'] ?? 'ASESMEN');
    if ($tipeJudul === 'SUMATIF')      $kategoriLabel = 'ASESMEN SUMATIF (CBT)';
    elseif ($tipeJudul === 'FORMATIF') $kategoriLabel = 'ASESMEN FORMATIF';
    elseif ($tipeJudul === 'DIAGNOSTIK')$kategoriLabel = 'ASESMEN DIAGNOSTIK';
    elseif ($tipeJudul === 'REMEDIAL')  $kategoriLabel = 'REMEDIAL / PENGAYAAN';
    else                                $kategoriLabel = 'SELURUH ASESMEN TERPADU (CBT)';

    $tahunPelajaran = $metaInfo['tahun_pelajaran'] ?? ($sekolah['tahun_pelajaran_aktif'] ?? '2026/2027');
    $semester       = $metaInfo['semester'] ?? ($sekolah['semester_aktif'] ?? 'Ganjil');
    $jenisSumatif   = $metaInfo['jenis_sumatif'] ?? '';
    $namaKelas      = $metaInfo['nama_kelas'] ?? 'Semua Rombel';

    $isSingleAssessment = (count($tokensData) === 1);
    $singleItem = $isSingleAssessment ? $tokensData[0] : null;

    ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Daftar Token Sesi Ujian CBT - <?= htmlspecialchars($kategoriLabel) ?></title>
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
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 11.5px;
            line-height: 1.4;
            color: #1E293B;
            background: #FFFFFF;
            margin: 0;
            padding: 0;
        }

        /* HEADER KOP RESMI */
        .kop-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2.5px solid #1D3B5E;
            padding-bottom: 10px;
            margin-bottom: 12px;
            position: relative;
        }
        .kop-wrapper::after {
            content: "";
            position: absolute;
            bottom: -5px;
            left: 0;
            right: 0;
            height: 1px;
            background: #0D7A87;
        }
        .kop-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .kop-logo img {
            max-height: 48px;
            max-width: 170px;
            object-fit: contain;
        }
        .kop-title {
            text-align: right;
        }
        .kop-title h1 {
            margin: 0;
            font-size: 15px;
            font-weight: 900;
            color: #1D3B5E;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .kop-title h2 {
            margin: 2px 0 0;
            font-size: 12px;
            font-weight: 700;
            color: #0D7A87;
            text-transform: uppercase;
        }
        .kop-title .confidential-badge {
            display: inline-block;
            margin-top: 4px;
            background: #FEF2F2;
            border: 1px solid #F87171;
            color: #DC2626;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: 0.8px;
            padding: 2px 8px;
            border-radius: 4px;
            text-transform: uppercase;
        }

        /* META SUMMARY BOX */
        .meta-box {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 14px;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 11px;
        }
        .meta-col {
            flex: 1;
        }
        .meta-row {
            display: flex;
            margin-bottom: 4px;
        }
        .meta-row:last-child {
            margin-bottom: 0;
        }
        .meta-label {
            width: 125px;
            color: #64748B;
            font-weight: 600;
            flex-shrink: 0;
        }
        .meta-val {
            color: #1E293B;
            font-weight: 700;
        }
        .meta-val strong {
            color: #0D7A87;
        }

        /* SINGLE ASSESSMENT SPOTLIGHT BANNER */
        .single-token-banner {
            background: linear-gradient(135deg, #F0FDFA 0%, #FFFFFF 100%);
            border: 2px solid #0D7A87;
            border-radius: 12px;
            padding: 18px 22px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            box-shadow: 0 4px 14px rgba(13, 122, 135, 0.08);
        }
        .single-token-details {
            flex: 1;
        }
        .single-token-title {
            font-size: 16px;
            font-weight: 900;
            color: #1D3B5E;
            margin: 0 0 4px;
        }
        .single-token-sub {
            font-size: 12px;
            color: #64748B;
            margin-bottom: 10px;
        }
        .single-token-meta-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .st-badge {
            display: inline-block;
            background: #E2E8F0;
            color: #334155;
            font-size: 10.5px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 4px;
        }
        .st-badge--teal {
            background: #CCFBF1;
            color: #0F766E;
        }
        .st-badge--navy {
            background: #E0E7FF;
            color: #3730A3;
        }
        .st-badge--gold {
            background: #FEF3C7;
            color: #B45309;
        }

        .single-token-code-card {
            text-align: center;
            background: #FFFFFF;
            border: 2px dashed #0D7A87;
            border-radius: 10px;
            padding: 12px 20px;
            min-width: 200px;
        }
        .st-code-label {
            font-size: 10px;
            font-weight: 800;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }
        .st-code-val {
            font-family: "Courier New", Courier, monospace;
            font-size: 32px;
            font-weight: 900;
            color: #0D7A87;
            letter-spacing: 4px;
            line-height: 1.1;
        }
        .st-code-note {
            font-size: 9.5px;
            color: #DC2626;
            font-weight: 700;
            margin-top: 6px;
        }

        /* DATA TABLE */
        table.token-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            font-size: 10.5px;
        }
        table.token-table th {
            background: #1D3B5E;
            color: #FFFFFF;
            padding: 8px 8px;
            font-weight: 800;
            font-size: 10.5px;
            text-align: left;
            border: 1px solid #1D3B5E;
            vertical-align: middle;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        table.token-table th.text-center {
            text-align: center;
        }
        table.token-table td {
            padding: 7px 8px;
            border: 1px solid #CBD5E1;
            vertical-align: middle;
            color: #1E293B;
        }
        table.token-table tr:nth-child(even) td {
            background: #F8FAFC;
        }
        table.token-table tr.highlight td {
            background: #F0FDFA;
        }

        /* TOKEN DISPLAY BADGE */
        .token-badge {
            display: inline-block;
            font-family: "Courier New", Courier, monospace;
            font-size: 14px;
            font-weight: 900;
            color: #0D7A87;
            background: #F0FDFA;
            border: 1.5px solid #0D7A87;
            padding: 4px 10px;
            border-radius: 6px;
            letter-spacing: 2px;
            white-space: nowrap;
        }
        .token-badge--empty {
            font-family: inherit;
            font-size: 10px;
            font-weight: 600;
            color: #94A3B8;
            background: #F1F5F9;
            border: 1px dashed #CBD5E1;
            padding: 3px 6px;
            letter-spacing: normal;
        }

        /* SECURITY DIRECTIVES BOX */
        .security-box {
            background: #FFFBEB;
            border: 1px solid #FDE68A;
            border-left: 4px solid #D97706;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 16px;
            font-size: 10px;
            color: #78350F;
            line-height: 1.45;
        }
        .security-box strong {
            color: #92400E;
            font-size: 10.5px;
        }
        .security-box ol {
            margin: 4px 0 0 16px;
            padding: 0;
        }
        .security-box li {
            margin-bottom: 2px;
        }

        /* SIGNATURE SECTION */
        .sign-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-top: 14px;
            font-size: 11px;
            page-break-inside: avoid;
        }
        .sign-box {
            width: 220px;
            text-align: center;
        }
        .sign-date {
            margin-bottom: 4px;
            color: #475569;
        }
        .sign-role {
            font-weight: 700;
            color: #1D3B5E;
            margin-bottom: 2px;
        }
        .sign-space {
            height: 54px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .sign-line {
            border-bottom: 1px solid #1E293B;
            margin-bottom: 4px;
        }
        .sign-name {
            font-weight: 800;
            color: #1E293B;
        }
        .sign-nip {
            font-size: 10px;
            color: #64748B;
        }

        /* FOOTER BRANDING */
        .doc-footer {
            margin-top: 18px;
            border-top: 1px solid #E2E8F0;
            padding-top: 6px;
            display: flex;
            justify-content: space-between;
            font-size: 9.5px;
            color: #94A3B8;
        }
    </style>
</head>
<body>

    <!-- KOP RESMI -->
    <div class="kop-wrapper">
        <div class="kop-logo">
            <?php if (!empty($logoB64)): ?>
                <img src="<?= $logoB64 ?>" alt="Logo Ilmuna Learn">
            <?php else: ?>
                <div style="font-weight: 900; font-size: 18px; color: #1D3B5E; letter-spacing: 1px;">ILMUNA LEARN</div>
            <?php endif; ?>
        </div>
        <div class="kop-title">
            <h1>DAFTAR TOKEN SESI UJIAN (CBT)</h1>
            <h2><?= htmlspecialchars($kategoriLabel) ?><?= !empty($sekolah['nama_sekolah']) ? ' &bull; ' . htmlspecialchars($sekolah['nama_sekolah']) : '' ?></h2>
            <div class="confidential-badge">&#128274; DOKUMEN RAHASIA PROKTOR & PENGAWAS RUANG</div>
        </div>
    </div>

    <!-- META SUMMARY BOX -->
    <div class="meta-box">
        <div class="meta-col">
            <div class="meta-row">
                <span class="meta-label">Tahun Pelajaran:</span>
                <span class="meta-val"><?= htmlspecialchars($tahunPelajaran) ?> &bull; Semester <?= htmlspecialchars($semester) ?></span>
            </div>
            <div class="meta-row">
                <span class="meta-label">Kategori Ujian:</span>
                <span class="meta-val"><strong><?= htmlspecialchars($kategoriLabel) ?></strong></span>
            </div>
            <?php if (!empty($jenisSumatif)): ?>
            <div class="meta-row">
                <span class="meta-label">Jenis Sumatif:</span>
                <span class="meta-val"><?= htmlspecialchars($jenisSumatif) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <div class="meta-col">
            <div class="meta-row">
                <span class="meta-label">Rombel Sasaran:</span>
                <span class="meta-val"><?= htmlspecialchars($namaKelas) ?></span>
            </div>
            <div class="meta-row">
                <span class="meta-label">Total Paket Asesmen:</span>
                <span class="meta-val"><?= count($tokensData) ?> Paket Asesmen</span>
            </div>
            <div class="meta-row">
                <span class="meta-label">Waktu Cetak Dokumen:</span>
                <span class="meta-val"><?= $tglCetak ?></span>
            </div>
        </div>
    </div>

    <!-- JIKA HANYA 1 ASESMEN: TAMPILKAN BANNER PROMINENT TOKEN -->
    <?php if ($isSingleAssessment && $singleItem): ?>
    <div class="single-token-banner">
        <div class="single-token-details">
            <h3 class="single-token-title">#<?= (int)$singleItem['id_asesmen'] ?> - <?= htmlspecialchars($singleItem['judul_asesmen']) ?></h3>
            <div class="single-token-sub">
                Mata Pelajaran: <strong><?= htmlspecialchars($singleItem['nama_mata_pelajaran'] ?: 'Umum') ?></strong> &bull; Rombel: <strong><?= htmlspecialchars($singleItem['nama_kelas'] ?: 'Semua Kelas') ?></strong>
            </div>
            <div class="single-token-meta-badges">
                <span class="st-badge st-badge--teal">
                    Jadwal: <?= htmlspecialchars(ilmuna_token_indo_day($singleItem['tanggal_pelaksanaan'] ?? '')) ?>, <?= htmlspecialchars(ilmuna_token_indo_date_full($singleItem['tanggal_pelaksanaan'] ?? '')) ?>
                </span>
                <span class="st-badge st-badge--navy">
                    Jam: <?= htmlspecialchars($singleItem['jam_mulai'] ? substr($singleItem['jam_mulai'], 0, 5) : '07:30') ?> – <?= htmlspecialchars($singleItem['jam_selesai'] ? substr($singleItem['jam_selesai'], 0, 5) : '09:00') ?> WIB
                </span>
                <span class="st-badge st-badge--gold">
                    Durasi: <?= (int)($singleItem['durasi_menit'] ?: 60) ?> Menit
                </span>
                <span class="st-badge">
                    Peserta: <?= (int)($singleItem['total_peserta'] ?? 0) ?> Siswa Terdaftar
                </span>
            </div>
        </div>
        <div class="single-token-code-card">
            <div class="st-code-label">TOKEN SESI CBT RESMI</div>
            <?php if (!empty($singleItem['token_sesi']) && $singleItem['token_sesi'] !== '-'): ?>
                <div class="st-code-val"><?= htmlspecialchars($singleItem['token_sesi']) ?></div>
                <div class="st-code-note">* Diumumkan saat jam ujian dimulai</div>
            <?php else: ?>
                <div class="st-code-val" style="font-size: 16px; color: #94A3B8;">BEBAS TOKEN</div>
                <div class="st-code-note">Ujian dapat diakses tanpa token sesi</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- TABEL UTAMA DAFTAR TOKEN ASESMEN -->
    <table class="token-table">
        <thead>
            <tr>
                <th style="width: 30px;" class="text-center">No</th>
                <th style="width: 55px;" class="text-center">ID</th>
                <th style="min-width: 170px;">Paket Asesmen & Mapel</th>
                <th style="width: 125px;">Rombel / Kelas</th>
                <th style="width: 145px;">Hari, Tanggal & Jam</th>
                <th style="width: 55px;" class="text-center">Durasi</th>
                <th style="width: 55px;" class="text-center">Peserta</th>
                <th style="width: 120px;" class="text-center">Token CBT</th>
                <th style="width: 80px;" class="text-center">Paraf Pengawas</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tokensData)): ?>
                <tr>
                    <td colspan="9" style="text-align: center; padding: 24px; color: #64748B;">
                        Tidak ada data asesmen atau token yang sesuai dengan filter yang dipilih.
                    </td>
                </tr>
            <?php else: ?>
                <?php $no = 1; foreach ($tokensData as $row): ?>
                <?php 
                    $hasToken = !empty($row['token_sesi']) && $row['token_sesi'] !== '-';
                    $jamMulai = !empty($row['jam_mulai']) ? substr($row['jam_mulai'], 0, 5) : '-';
                    $jamSelesai = !empty($row['jam_selesai']) ? substr($row['jam_selesai'], 0, 5) : '-';
                    $tglHari = !empty($row['tanggal_pelaksanaan']) ? ilmuna_token_indo_day($row['tanggal_pelaksanaan']) . ', ' . ilmuna_token_indo_date_full($row['tanggal_pelaksanaan']) : 'Sesuai Jadwal';
                ?>
                <tr class="<?= $hasToken ? 'highlight' : '' ?>">
                    <td class="text-center" style="font-weight: 700; color: #64748B;"><?= $no++ ?></td>
                    <td class="text-center" style="font-family: monospace; font-weight: 700; color: #0D7A87;">#<?= (int)$row['id_asesmen'] ?></td>
                    <td>
                        <div style="font-weight: 800; color: #1D3B5E; font-size: 11px; margin-bottom: 2px;">
                            <?= htmlspecialchars($row['judul_asesmen']) ?>
                        </div>
                        <div style="font-size: 10px; color: #475569;">
                            Mapel: <strong><?= htmlspecialchars($row['nama_mata_pelajaran'] ?: 'Umum') ?></strong>
                            <?php if (!empty($row['jenis_sumatif'])): ?>
                                &bull; <span style="color: #0D7A87;"><?= htmlspecialchars($row['jenis_sumatif']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($row['pertemuan_ke']) && (int)$row['pertemuan_ke'] > 0): ?>
                                &bull; Ke-<?= (int)$row['pertemuan_ke'] ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <span style="font-weight: 700; color: #334155;">
                            <?= htmlspecialchars($row['nama_kelas'] ?: 'Semua Kelas') ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: #1E293B; font-size: 10px;"><?= htmlspecialchars($tglHari) ?></div>
                        <div style="font-size: 9.5px; color: #64748B;">Pukul <?= htmlspecialchars($jamMulai) ?> – <?= htmlspecialchars($jamSelesai) ?> WIB</div>
                    </td>
                    <td class="text-center" style="font-weight: 700; color: #475569;">
                        <?= (int)($row['durasi_menit'] ?: 60) ?>'
                    </td>
                    <td class="text-center" style="font-weight: 700; color: #1D3B5E;">
                        <?= (int)($row['total_peserta'] ?? 0) ?>
                    </td>
                    <td class="text-center">
                        <?php if ($hasToken): ?>
                            <span class="token-badge"><?= htmlspecialchars($row['token_sesi']) ?></span>
                        <?php else: ?>
                            <span class="token-badge--empty">Bebas Token</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center" style="color: #CBD5E1; font-size: 14px;">
                        <div style="width: 50px; height: 26px; border: 1px dashed #CBD5E1; border-radius: 4px; margin: 0 auto;"></div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- SECURITY & OPERATIONAL DIRECTIVES -->
    <div class="security-box">
        <strong>TATA TERTIB & KEBIJAKAN PENGGUNAAN TOKEN SESI CBT:</strong>
        <ol>
            <li>Token sesi bersifat <strong>RAHASIA NEGARA / LEMBAGA</strong> dan hanya dipegang oleh Proktor Utama dan Pengawas Ruang yang bertugas.</li>
            <li>Pengawas Ruang dilarang mengumumkan atau membagikan token kepada peserta sebelum waktu pelaksanaan ujian resmi dimulai.</li>
            <li>Token diumumkan secara lisan atau dituliskan pada papan tulis ruang ujian tepat saat peserta dipersilakan memulai pengerjaan.</li>
            <li>Apabila terjadi indikasi kebocoran token atau kendala sesi, Proktor berhak melakukan <em>Reset / Acak Token Baru</em> secara real-time melalui dashboard Ilmuna Learn.</li>
        </ol>
    </div>

    <!-- SIGNATURE SECTION -->
    <?php
    $ttdKepsekB64 = ilmuna_token_file_to_b64($sekolah['ttd_kepala_sekolah'] ?? '');
    $stempelB64   = ilmuna_token_file_to_b64($sekolah['cap_stempel_path'] ?? '');
    $kotaSekolah  = $sekolah['titimangsa_tempat'] ?: 'Bojonegoro';
    ?>
    <div class="sign-section">
        <div class="sign-box">
            <div class="sign-date">&nbsp;</div>
            <div class="sign-role">Pengawas Ruang / Proktor,</div>
            <div class="sign-space"></div>
            <div class="sign-line"></div>
            <div class="sign-name">( .................................................... )</div>
            <div class="sign-nip">NIP/NUPTK: -</div>
        </div>

        <div class="sign-box">
            <div class="sign-date"><?= htmlspecialchars($kotaSekolah) ?>, <?= htmlspecialchars($tglCetakFull) ?></div>
            <div class="sign-role">Penanggung Jawab CBT / Kepala Madrasah,</div>
            <div class="sign-space">
                <?php if ($stempelB64): ?>
                    <img src="<?= $stempelB64 ?>" alt="Stempel" style="position: absolute; left: 15px; top: 0; height: 52px; opacity: 0.85; pointer-events: none;">
                <?php endif; ?>
                <?php if ($ttdKepsekB64): ?>
                    <img src="<?= $ttdKepsekB64 ?>" alt="TTD Kepala Sekolah" style="max-height: 50px; max-width: 140px; position: relative; z-index: 2;">
                <?php endif; ?>
            </div>
            <div class="sign-line"></div>
            <div class="sign-name"><?= htmlspecialchars($sekolah['nama_kepala_sekolah'] ?: 'Dr. H. Muhammad Arifin, M.Pd.') ?></div>
            <div class="sign-nip"><?= !empty($sekolah['nip_kepala_sekolah']) ? 'NIP. ' . htmlspecialchars($sekolah['nip_kepala_sekolah']) : 'NIP/NUPTK: -' ?></div>
        </div>
    </div>

    <!-- DOC FOOTER BRANDING -->
    <div class="doc-footer">
        <div>Ilmuna Learn &bull; Sistem Asesmen Terpadu & CBT Engine v2.0</div>
        <div>Dicetak Otomatis dari Dashboard Admin &bull; Keamanan Tingkat Tinggi (Exam Browser Kiosk)</div>
        <div>Halaman 1 dari 1</div>
    </div>

</body>
</html>
<?php
    return ob_get_clean();
}

/**
 * Ekspor Berkas PDF Daftar Token Asesmen Menggunakan Headless Chrome / Edge
 *
 * @param array $tokensData Daftar array data token asesmen
 * @param array $metaInfo Informasi pelengkap
 * @param string|null $targetPdfPath Jalur target berkas PDF opsional
 * @return string|false Jalur absolut berkas PDF jika berhasil, false jika gagal
 */
function ilmuna_generate_daftar_token_pdf(array $tokensData, array $metaInfo = [], $targetPdfPath = null) {
    if (empty($tokensData)) {
        return ['ok' => false, 'error' => 'Data token kosong.'];
    }

    $bin = ilmuna_get_headless_browser_bin();
    if (!$bin) {
        return ['ok' => false, 'error' => 'Headless browser (Edge/Chrome) tidak ditemukan di server.'];
    }

    $uploadDir = BASE_PATH . '/uploads/kartu_ujian';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $tipeTag = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $metaInfo['tipe'] ?? 'ASESMEN'));
    $count = count($tokensData);

    if (empty($targetPdfPath)) {
        $unique = 'Daftar_Token_' . $tipeTag . '_' . $count . 'Paket_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $targetPdfPath = $uploadDir . '/' . $unique . '.pdf';
    }

    $uniqueTmp = 'tmp_token_' . bin2hex(random_bytes(6));
    $htmlTmp = $uploadDir . '/' . $uniqueTmp . '.html';

    $htmlContent = ilmuna_render_daftar_token_pdf_html($tokensData, $metaInfo);
    if (!file_put_contents($htmlTmp, $htmlContent)) {
        return ['ok' => false, 'error' => 'Gagal menulis file HTML sementara ke: ' . $htmlTmp];
    }

    $fileUri = 'file:///' . str_replace('\\', '/', $htmlTmp);
    $userDataDir = sys_get_temp_dir() . '/edge_tok_' . uniqid();
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

    $outStr = implode(' | ', array_slice($out, -5));
    return [
        'ok'    => false,
        'error' => 'Headless browser gagal membuat PDF token. Exit code: ' . $ret . '. Output: ' . mb_substr($outStr, 0, 300),
    ];
}
