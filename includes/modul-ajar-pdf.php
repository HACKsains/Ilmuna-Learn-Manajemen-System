<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — GENERATOR RESMI DOKUMEN PDF MODUL AJAR & LAPORAN KETERLAKSANAAN
 * ==============================================================================
 * File         : includes/modul-ajar-pdf.php
 * Format       : A4 Portrait (Kurikulum Merdeka)
 * Fitur Utama  :
 *   1. Pengaturan Cetak & Ekspor PDF Resmi Tersentralisasi di includes/
 *   2. Kop Sekolah Dinamis (`identitas_sekolah`), Logo, Stempel & TTD Digital
 *   3. QR Code SVG Presisi Tinggi (includes/qrcode_engine.php) untuk setiap
 *      berkas/tautan Lampiran Modul Ajar (mendukung banyak lampiran sekaligus)
 *   4. Dual-Engine Output:
 *      - Direct PDF Binary Download via Headless Browser (Edge / Chrome) jika tersedia
 *      - Print-Ready HTML A4 Fallback otomatis pada hosting tanpa browser headless
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

if (file_exists(BASE_PATH . '/includes/qrcode_engine.php')) {
    require_once BASE_PATH . '/includes/qrcode_engine.php';
}

/**
 * 1. Deteksi Headless Browser (Microsoft Edge / Google Chrome / Chromium)
 */
if (!function_exists('ilmuna_ma_get_headless_browser')) {
    function ilmuna_ma_get_headless_browser() {
        $candidates = [
            'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
            'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
            'C:\Program Files\Google\Chrome\Application\chrome.exe',
            'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
            getenv('LOCALAPPDATA') ? getenv('LOCALAPPDATA') . '\Microsoft\Edge\Application\msedge.exe' : null,
            getenv('LOCALAPPDATA') ? getenv('LOCALAPPDATA') . '\Google\Chrome\Application\chrome.exe' : null,
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/local/bin/chromium',
            '/snap/bin/chromium'
        ];
        foreach ($candidates as $bin) {
            if ($bin && @file_exists($bin) && @is_executable($bin)) {
                return $bin;
            }
        }
        if (function_exists('exec')) {
            $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
            if (!in_array('exec', $disabled, true)) {
                foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'msedge'] as $cmdName) {
                    $whichOut = @exec('which ' . escapeshellarg($cmdName) . ' 2>/dev/null');
                    if ($whichOut && @file_exists($whichOut) && @is_executable($whichOut)) {
                        return $whichOut;
                    }
                }
            }
        }
        return null;
    }
}

/**
 * 2. Helper Format Tanggal Indonesia & Hari
 */
if (!function_exists('ilmuna_ma_indo_date')) {
    function ilmuna_ma_indo_date($dateStr = null, $withDay = false) {
        if (!$dateStr || $dateStr === '-' || $dateStr === '0000-00-00') {
            return '-';
        }
        $ts = strtotime($dateStr);
        if (!$ts) return (string)$dateStr;
        $days = [
            'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
        ];
        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $formatted = (int)date('d', $ts) . ' ' . ($months[(int)date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
        if ($withDay) {
            return ($days[date('l', $ts)] ?? '') . ', ' . $formatted;
        }
        return $formatted;
    }
}

/**
 * 3. Helper Konversi Berkas Gambar Lokal ke Base64 Data URI
 */
if (!function_exists('ilmuna_ma_file_to_b64')) {
    function ilmuna_ma_file_to_b64($relPath) {
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
            if ($cand && @file_exists($cand) && @is_file($cand)) {
                $ext = strtolower(pathinfo($cand, PATHINFO_EXTENSION));
                $mime = 'image/png';
                if (in_array($ext, ['jpg', 'jpeg'], true)) $mime = 'image/jpeg';
                elseif ($ext === 'svg') $mime = 'image/svg+xml';
                elseif ($ext === 'webp') $mime = 'image/webp';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($cand));
            }
        }
        return '';
    }
}

/**
 * 4. Generate QR Code SVG Offline (Pure Vector, Tanpa GD, Tanpa API Eksternal)
 *    Menggunakan includes/qrcode_engine.php dengan warna Lumina Navy/Teal.
 */
if (!function_exists('ilmuna_ma_generate_qr_svg')) {
    function ilmuna_ma_generate_qr_svg($urlText, $sizePx = 108) {
        $text = trim((string)$urlText);
        if ($text === '') return '';
        if (!class_exists('QRCode')) return '';

        try {
            $qr = new QRCode($text, ['s' => 'qrm']);
            $ref = new ReflectionClass($qr);
            $method = $ref->getMethod('dispatch_encode');
            $method->setAccessible(true);
            $code = $method->invoke($qr, $text, ['s' => 'qrm']);
            if (empty($code['b']) || !is_array($code['b'])) return '';

            $matrix = $code['b'];
            $matrixSize = count($matrix);
            $margin = 2;
            $totalSize = $matrixSize + ($margin * 2);

            $path = '';
            for ($r = 0; $r < $matrixSize; $r++) {
                for ($c = 0; $c < $matrixSize; $c++) {
                    if (!empty($matrix[$r][$c])) {
                        $x = $c + $margin;
                        $y = $r + $margin;
                        $path .= "M{$x} {$y}h1v1h-1z";
                    }
                }
            }

            $w = (int)$sizePx;
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $totalSize . ' ' . $totalSize . '" width="' . $w . '" height="' . $w . '" shape-rendering="crispEdges">'
                 . '<rect width="' . $totalSize . '" height="' . $totalSize . '" fill="#FFFFFF"/>'
                 . '<path d="' . $path . '" fill="#1D3B5E"/>'
                 . '</svg>';
        } catch (Throwable $e) {
            return '';
        }
    }
}

/**
 * 5. Resolusi URL Publik Penuh untuk Lampiran (File Lokal maupun Tautan Eksternal)
 */
if (!function_exists('ilmuna_ma_attachment_url')) {
    function ilmuna_ma_attachment_url(array $att) {
        if (!empty($att['url']) && preg_match('#^https?://#i', (string)$att['url'])) {
            return trim((string)$att['url']);
        }
        $fp = trim((string)($att['file_path'] ?? ''));
        if ($fp === '') return '';
        if (preg_match('#^https?://#i', $fp)) {
            return $fp;
        }
        $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
        return $base . '/' . ltrim($fp, '/');
    }
}

/**
 * 6. Helper Format Ukuran File
 */
if (!function_exists('ilmuna_ma_format_bytes')) {
    function ilmuna_ma_format_bytes($bytes) {
        $b = (int)$bytes;
        if ($b <= 0) return 'Tautan Digital';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int)floor(log($b, 1024));
        if (!isset($units[$i])) $i = count($units) - 1;
        return round($b / pow(1024, $i), 1) . ' ' . $units[$i];
    }
}

/**
 * 7. Helper Bagian Header Kop Sekolah & Blok Tanda Tangan
 */
function ilmuna_ma_extract_school_meta(array $data) {
    $sekolah = $data['sekolah'] ?? [];
    $modul   = $data['modul'] ?? [];

    $namaSekolah = htmlspecialchars($sekolah['nama_sekolah'] ?? 'ILMUNA LEARN ACADEMY', ENT_QUOTES, 'UTF-8');
    $alamatParts = array_filter([
        $sekolah['alamat_jalan'] ?? '',
        $sekolah['desa_kelurahan'] ?? '',
        $sekolah['kecamatan'] ?? '',
        $sekolah['kabupaten_kota'] ?? '',
        $sekolah['provinsi'] ?? ''
    ]);
    $alamatSekolah = !empty($alamatParts)
        ? htmlspecialchars(implode(', ', $alamatParts), ENT_QUOTES, 'UTF-8')
        : htmlspecialchars($sekolah['alamat'] ?? '', ENT_QUOTES, 'UTF-8');
    $kontakParts = array_filter([
        !empty($sekolah['npsn']) ? 'NPSN: ' . $sekolah['npsn'] : '',
        !empty($sekolah['telepon']) ? 'Telp. ' . $sekolah['telepon'] : '',
        !empty($sekolah['email']) ? 'Email: ' . $sekolah['email'] : '',
        !empty($sekolah['website']) ? $sekolah['website'] : '',
    ]);
    $kontakSekolah = htmlspecialchars(implode(' | ', $kontakParts), ENT_QUOTES, 'UTF-8');

    $logoB64 = !empty($sekolah['logo_sekolah']) ? ilmuna_ma_file_to_b64($sekolah['logo_sekolah']) : '';
    if ($logoB64 === '' && file_exists(BASE_PATH . '/assets/images/logo.png')) {
        $logoB64 = 'data:image/png;base64,' . base64_encode(file_get_contents(BASE_PATH . '/assets/images/logo.png'));
    }

    $ttdKepsekB64 = !empty($sekolah['ttd_kepala_sekolah']) ? ilmuna_ma_file_to_b64($sekolah['ttd_kepala_sekolah']) : '';
    $stempelB64   = !empty($sekolah['cap_stempel_path']) ? ilmuna_ma_file_to_b64($sekolah['cap_stempel_path']) : '';
    $titimangsa   = htmlspecialchars(!empty($sekolah['titimangsa_tempat']) ? $sekolah['titimangsa_tempat'] : 'Bojonegoro', ENT_QUOTES, 'UTF-8');
    $namaKepsek   = htmlspecialchars(trim(($sekolah['nama_kepala_sekolah'] ?? 'Kepala Sekolah') . ' ' . ($sekolah['gelar_kepala_sekolah'] ?? '')), ENT_QUOTES, 'UTF-8');
    $nipKepsek    = !empty($sekolah['nip_kepala_sekolah']) ? 'NIP. ' . htmlspecialchars($sekolah['nip_kepala_sekolah'], ENT_QUOTES, 'UTF-8') : 'NIP. -';

    $guruNamaRaw = !empty($data['guru_nama']) ? $data['guru_nama'] : ($modul['nama_guru_pengampu'] ?? 'Dewan Guru');
    $guruNipRaw  = !empty($data['guru_nip']) ? $data['guru_nip'] : ($modul['nip_guru_pengampu'] ?? '');
    $namaGuru    = htmlspecialchars($guruNamaRaw, ENT_QUOTES, 'UTF-8');
    $nipGuru     = ($guruNipRaw !== '' && $guruNipRaw !== '-') ? 'NIP. ' . htmlspecialchars($guruNipRaw, ENT_QUOTES, 'UTF-8') : 'NIP. -';

    return [
        'nama_sekolah'   => $namaSekolah,
        'alamat_sekolah' => $alamatSekolah,
        'kontak_sekolah' => $kontakSekolah,
        'logo_b64'       => $logoB64,
        'ttd_kepsek_b64' => $ttdKepsekB64,
        'stempel_b64'    => $stempelB64,
        'titimangsa'     => $titimangsa,
        'nama_kepsek'    => $namaKepsek,
        'nip_kepsek'     => $nipKepsek,
        'nama_guru'      => $namaGuru,
        'nip_guru'       => $nipGuru,
    ];
}

/**
 * 8. Render Grid QR Code Lampiran untuk PDF
 */
if (!function_exists('ilmuna_ma_truncate')) {
    function ilmuna_ma_truncate($str, $maxLen = 58) {
        $s = (string)$str;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($s, 'UTF-8') > $maxLen ? (mb_substr($s, 0, max(1, $maxLen - 3), 'UTF-8') . '...') : $s;
        }
        return strlen($s) > $maxLen ? (substr($s, 0, max(1, $maxLen - 3)) . '...') : $s;
    }
}

if (!function_exists('ilmuna_ma_val')) {
    function ilmuna_ma_val(array $arr, $key, $fallback = '-') {
        if (!isset($arr[$key])) return $fallback;
        $v = trim((string)$arr[$key]);
        return $v !== '' ? (string)$arr[$key] : $fallback;
    }
}

function ilmuna_ma_render_qr_attachments_html(array $lampiranList) {
    if (empty($lampiranList)) {
        return '<div style="font-size:10.5px;color:#64748B;">Tidak ada lampiran digital terunggah.</div>';
    }
    $html = '<div class="qr-grid">';
    foreach ($lampiranList as $idx => $att) {
        $url = ilmuna_ma_attachment_url($att);
        $name = htmlspecialchars($att['file_name'] ?? ('Lampiran ' . ($idx + 1)), ENT_QUOTES, 'UTF-8');
        $sizeLabel = htmlspecialchars(ilmuna_ma_format_bytes($att['file_size'] ?? 0), ENT_QUOTES, 'UTF-8');
        $qrSvg = ilmuna_ma_generate_qr_svg($url, 96);
        $shortUrl = htmlspecialchars(ilmuna_ma_truncate($url, 58), ENT_QUOTES, 'UTF-8');

        $html .= '<div class="qr-card">'
            . '<div class="qr-box">' . ($qrSvg ?: '<div style="font-size:9px;color:#94A3B8;padding:12px;">QR</div>') . '</div>'
            . '<div class="qr-info">'
                . '<div class="qr-idx">Lampiran #' . ($idx + 1) . ' &bull; ' . $sizeLabel . '</div>'
                . '<div class="qr-name">' . $name . '</div>'
                . '<div class="qr-hint">Pindai QR Code untuk membuka / mengunduh berkas lampiran:</div>'
                . '<div class="qr-url">' . $shortUrl . '</div>'
            . '</div>'
        . '</div>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * 9. Shared Base CSS untuk PDF A4 Modul Ajar & Laporan
 */
function ilmuna_ma_pdf_base_css() {
    return '
        @page { size: A4 portrait; margin: 13mm 14mm 14mm 14mm; }
        * { box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        body { font-family: "Segoe UI", Tahoma, Arial, sans-serif; font-size: 10.5px; color: #0F172A; background: #FFFFFF; margin: 0; padding: 0; line-height: 1.48; }
        .sheet { width: 100%; }
        .header-kop { display: flex; align-items: center; justify-content: space-between; border-bottom: 3px double #1D3B5E; padding-bottom: 8px; margin-bottom: 12px; }
        .kop-logo { width: 62px; height: 62px; object-fit: contain; flex-shrink: 0; }
        .kop-text { text-align: center; flex: 1; padding: 0 10px; }
        .kop-text h2 { margin: 0 0 2px; font-size: 14.5px; font-weight: 800; color: #1D3B5E; text-transform: uppercase; letter-spacing: 0.4px; }
        .kop-text p { margin: 1px 0 0; font-size: 9.5px; color: #475569; }
        .doc-title-box { text-align: center; margin: 8px 0 12px; }
        .doc-title { margin: 0; font-size: 14px; font-weight: 800; color: #1D3B5E; text-transform: uppercase; letter-spacing: 0.5px; }
        .doc-subtitle { margin: 3px 0 0; font-size: 10.5px; font-weight: 600; color: #0D7A87; }
        .sec-head { background: linear-gradient(90deg, #1D3B5E 0%, #0D7A87 100%); color: #FFFFFF; font-weight: 700; font-size: 10.5px; padding: 5px 10px; border-radius: 5px; text-transform: uppercase; letter-spacing: 0.4px; margin: 12px 0 7px; page-break-after: avoid; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 10.2px; }
        table.meta td { border: 1px solid #CBD5E1; padding: 5px 8px; vertical-align: top; }
        table.meta td.lbl { width: 20%; font-weight: 700; background: #F1F5F9; color: #1E293B; }
        table.grid { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 10.2px; }
        table.grid th, table.grid td { border: 1px solid #CBD5E1; padding: 6px 8px; vertical-align: top; }
        table.grid th { background: #F1F5F9; color: #1D3B5E; font-weight: 700; text-align: left; }
        .text-block { white-space: pre-line; word-break: break-word; }
        .badge { display: inline-block; padding: 2px 7px; border-radius: 4px; font-size: 9.5px; font-weight: 700; }
        .qr-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-top: 4px; }
        .qr-card { display: flex; align-items: center; gap: 10px; border: 1px solid #CBD5E1; border-radius: 8px; padding: 8px; background: #F8FAFC; page-break-inside: avoid; }
        .qr-box { width: 96px; height: 96px; flex-shrink: 0; background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 6px; padding: 2px; display: flex; align-items: center; justify-content: center; }
        .qr-info { flex: 1; min-width: 0; }
        .qr-idx { font-size: 9px; font-weight: 700; color: #0D7A87; text-transform: uppercase; margin-bottom: 2px; }
        .qr-name { font-size: 10.5px; font-weight: 800; color: #0F172A; word-break: break-word; margin-bottom: 3px; }
        .qr-hint { font-size: 8.8px; color: #475569; margin-bottom: 2px; }
        .qr-url { font-size: 8px; color: #0284C7; word-break: break-all; font-family: Consolas, monospace; }
        table.sig { width: 100%; margin-top: 22px; border-collapse: collapse; font-size: 10.5px; page-break-inside: avoid; }
        table.sig td { width: 50%; text-align: center; vertical-align: top; padding: 4px 10px; }
        .sig-space { height: 58px; position: relative; }
        .stempel-img { position: absolute; left: 22%; top: -8px; width: 76px; opacity: 0.82; pointer-events: none; }
        .ttd-img { position: absolute; left: 36%; top: 0; height: 54px; pointer-events: none; }
    ';
}

/**
 * 10. RENDER HTML DOKUMEN MODUL AJAR (A4 PORTRAIT + QR CODE LAMPIRAN)
 */
function ilmuna_render_modul_ajar_pdf_html(array $data) {
    $modul       = $data['modul'] ?? [];
    $cpRows      = $data['cp_rows'] ?? [];
    $tpRows      = $data['tp_rows'] ?? [];
    $modelNames  = $data['model_names'] ?? [];
    $metodeNames = $data['metode_names'] ?? [];
    $meta        = ilmuna_ma_extract_school_meta($data);

    $jamStr = '';
    if (!empty($modul['jam_mulai']) || !empty($modul['jam_selesai'])) {
        $jamStr = ' (' . htmlspecialchars(trim(($modul['jam_mulai'] ?? '') . ' - ' . ($modul['jam_selesai'] ?? ''), ' -'), ENT_QUOTES, 'UTF-8') . ' WIB)';
    }
    $lampiranHtml = ilmuna_ma_render_qr_attachments_html($modul['lampiran'] ?? []);

    ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Modul Ajar - <?= htmlspecialchars($modul['judul_modul'] ?? 'Modul Ajar', ENT_QUOTES, 'UTF-8') ?></title>
    <style><?= ilmuna_ma_pdf_base_css() ?></style>
</head>
<body>
<div class="sheet">
    <div class="header-kop">
        <?php if ($meta['logo_b64'] !== ''): ?>
            <img src="<?= $meta['logo_b64'] ?>" class="kop-logo" alt="Logo">
        <?php else: ?>
            <div style="width:62px;"></div>
        <?php endif; ?>
        <div class="kop-text">
            <h2><?= $meta['nama_sekolah'] ?></h2>
            <?php if ($meta['alamat_sekolah'] !== ''): ?><p><?= $meta['alamat_sekolah'] ?></p><?php endif; ?>
            <?php if ($meta['kontak_sekolah'] !== ''): ?><p><?= $meta['kontak_sekolah'] ?></p><?php endif; ?>
        </div>
        <div style="width:62px;"></div>
    </div>

    <div class="doc-title-box">
        <div class="doc-title">MODUL AJAR KURIKULUM MERDEKA</div>
        <div class="doc-subtitle"><?= htmlspecialchars($modul['nama_mata_pelajaran'] ?? '', ENT_QUOTES, 'UTF-8') ?> &bull; <?= htmlspecialchars($modul['nama_kelas'] ?? '', ENT_QUOTES, 'UTF-8') ?> &bull; Pertemuan ke-<?= (int)($modul['pertemuan_ke'] ?? 1) ?></div>
    </div>

    <div class="sec-head">I. Informasi Umum &amp; Identitas Modul</div>
    <table class="meta">
        <tr>
            <td class="lbl">Satuan Pendidikan</td><td><?= $meta['nama_sekolah'] ?></td>
            <td class="lbl">Tahun / Semester</td><td><?= htmlspecialchars($modul['tahun_pelajaran'] ?? '2026/2027', ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars($modul['semester'] ?? 'Ganjil', ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
            <td class="lbl">Mata Pelajaran</td><td><?= htmlspecialchars($modul['nama_mata_pelajaran'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
            <td class="lbl">Kelas / Fase</td><td><?= htmlspecialchars($modul['nama_kelas'] ?? '-', ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars($modul['fase'] ?? 'Fase E', ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
            <td class="lbl">Guru Penyusun</td><td><?= $meta['nama_guru'] ?></td>
            <td class="lbl">Pertemuan Ke-</td><td><strong>Pertemuan ke-<?= (int)($modul['pertemuan_ke'] ?? 1) ?></strong></td>
        </tr>
        <tr>
            <td class="lbl">Nama Modul Ajar</td><td colspan="3"><strong><?= htmlspecialchars($modul['judul_modul'] ?? '-', ENT_QUOTES, 'UTF-8') ?></strong></td>
        </tr>
        <tr>
            <td class="lbl">Tanggal &amp; Jam</td><td><?= ilmuna_ma_indo_date($modul['tanggal'] ?? null, true) . $jamStr ?></td>
            <td class="lbl">Alokasi Waktu</td><td><?= (int)($modul['jumlah_jp'] ?? 2) ?> JP &times; <?= (int)($modul['alokasi_per_jp'] ?? 45) ?> Menit (<?= (int)($modul['total_menit'] ?? 90) ?> Menit)</td>
        </tr>
        <tr>
            <td class="lbl">Target Peserta Didik</td><td><?= htmlspecialchars(ilmuna_ma_val($modul, 'target_peserta_didik', 'Peserta Didik Reguler / Tipikal'), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="lbl">Model Pembelajaran</td><td><?= htmlspecialchars(!empty($modelNames) ? implode(', ', $modelNames) : '-', ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
            <td class="lbl">Metode Pembelajaran</td><td><?= htmlspecialchars(!empty($metodeNames) ? implode(', ', $metodeNames) : '-', ENT_QUOTES, 'UTF-8') ?></td>
            <td class="lbl">Status Modul</td><td><?= htmlspecialchars(($modul['status'] ?? 'Draft') . (($modul['meeting_status'] ?? '') === 'final' ? ' (Final / Terkunci)' : ''), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
            <td class="lbl">Profil Pelajar Pancasila</td><td colspan="3" class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'profil_pelajar_pancasila'), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
            <td class="lbl">Media &amp; Sarana</td><td colspan="3" class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'media_pembelajaran'), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
            <td class="lbl">Sumber Belajar</td><td colspan="3" class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'sumber_belajar'), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
    </table>

    <div class="sec-head">II. Kompetensi Inti (CP, TP, ATP &amp; ITP)</div>
    <table class="grid">
        <tr>
            <th style="width:24%;">Capaian Pembelajaran (CP)</th>
            <td>
                <?php if (!empty($cpRows)): ?>
                    <?php foreach ($cpRows as $c): ?>
                        <div style="margin-bottom:5px;">
                            <?php if (!empty($c['nama_elemen'])): ?><strong>[<?= htmlspecialchars($c['nama_elemen'], ENT_QUOTES, 'UTF-8') ?>]</strong> <?php endif; ?>
                            <?= nl2br(htmlspecialchars((string)($c['cp'] ?? ''), ENT_QUOTES, 'UTF-8')) ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>-<?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Tujuan Pembelajaran (TP)</th>
            <td>
                <?php if (!empty($tpRows)): ?>
                    <?php foreach ($tpRows as $t): ?>
                        <div style="margin-bottom:4px;">
                            <?php if (!empty($t['kode_tp'])): ?><strong>(<?= htmlspecialchars($t['kode_tp'], ENT_QUOTES, 'UTF-8') ?>)</strong> <?php endif; ?>
                            <?= nl2br(htmlspecialchars((string)($t['tp'] ?? ''), ENT_QUOTES, 'UTF-8')) ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!empty($modul['tp_custom']) && empty($tpRows)): ?>
                    <div class="text-block"><?= htmlspecialchars((string)$modul['tp_custom'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php elseif (empty($tpRows)): ?>-<?php endif; ?>
            </td>
        </tr>
        <tr><th>Alur Tujuan Pembelajaran (ATP)</th><td class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'atp_custom'), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><th>Indikator Ketercapaian (ITP)</th><td class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'itp_custom'), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><th>Pemahaman Bermakna</th><td class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'pemahaman_bermakna'), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><th>Pertanyaan Pemantik</th><td class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'pertanyaan_pemantik'), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <?php if (!empty($modul['persiapan_pembelajaran'])): ?>
        <tr><th>Persiapan Pembelajaran</th><td class="text-block"><?= htmlspecialchars((string)$modul['persiapan_pembelajaran'], ENT_QUOTES, 'UTF-8') ?></td></tr>
        <?php endif; ?>
    </table>

    <div class="sec-head">III. Skenario Kegiatan Pembelajaran</div>
    <table class="grid">
        <thead>
            <tr>
                <th style="width:22%;">Tahap Kegiatan</th>
                <th>Uraian Aktivitas Pembelajaran</th>
                <th style="width:13%;text-align:center;">Alokasi</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>1. Pendahuluan / Pembukaan</strong></td>
                <td class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'kegiatan_pendahuluan'), ENT_QUOTES, 'UTF-8') ?></td>
                <td style="text-align:center;font-weight:700;"><?= (int)($modul['durasi_pembukaan'] ?? 15) ?> Menit</td>
            </tr>
            <tr>
                <td><strong>2. Kegiatan Inti</strong></td>
                <td class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'kegiatan_inti'), ENT_QUOTES, 'UTF-8') ?></td>
                <td style="text-align:center;font-weight:700;"><?= (int)($modul['durasi_inti'] ?? 60) ?> Menit</td>
            </tr>
            <tr>
                <td><strong>3. Penutup</strong></td>
                <td class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'kegiatan_penutup'), ENT_QUOTES, 'UTF-8') ?></td>
                <td style="text-align:center;font-weight:700;"><?= (int)($modul['durasi_penutup'] ?? 15) ?> Menit</td>
            </tr>
        </tbody>
    </table>

    <div class="sec-head">IV. Asesmen, Pengayaan &amp; Refleksi</div>
    <table class="grid">
        <?php if (!empty($modul['asesmen_diagnostik'])): ?><tr><th style="width:24%;">Asesmen Diagnostik</th><td class="text-block"><?= htmlspecialchars((string)$modul['asesmen_diagnostik'], ENT_QUOTES, 'UTF-8') ?></td></tr><?php endif; ?>
        <?php if (!empty($modul['asesmen_formatif'])): ?><tr><th style="width:24%;">Asesmen Formatif</th><td class="text-block"><?= htmlspecialchars((string)$modul['asesmen_formatif'], ENT_QUOTES, 'UTF-8') ?></td></tr><?php endif; ?>
        <?php if (!empty($modul['asesmen_sumatif'])): ?><tr><th style="width:24%;">Asesmen Sumatif</th><td class="text-block"><?= htmlspecialchars((string)$modul['asesmen_sumatif'], ENT_QUOTES, 'UTF-8') ?></td></tr><?php endif; ?>
        <?php if (!empty($modul['pengayaan_remedial'])): ?><tr><th style="width:24%;">Pengayaan &amp; Remedial</th><td class="text-block"><?= htmlspecialchars((string)$modul['pengayaan_remedial'], ENT_QUOTES, 'UTF-8') ?></td></tr><?php endif; ?>
        <tr><th style="width:24%;">Refleksi Guru</th><td class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'refleksi_guru'), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><th style="width:24%;">Refleksi Peserta Didik</th><td class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'refleksi_siswa'), ENT_QUOTES, 'UTF-8') ?></td></tr>
    </table>

    <div class="sec-head">V. LKPD, Bahan Bacaan, Glosarium &amp; Lampiran Digital (QR Code)</div>
    <table class="grid">
        <?php if (!empty($modul['lkpd'])): ?><tr><th style="width:24%;">Panduan LKPD</th><td class="text-block"><?= htmlspecialchars((string)$modul['lkpd'], ENT_QUOTES, 'UTF-8') ?></td></tr><?php endif; ?>
        <?php if (!empty($modul['bahan_bacaan'])): ?><tr><th style="width:24%;">Bahan Bacaan</th><td class="text-block"><?= htmlspecialchars((string)$modul['bahan_bacaan'], ENT_QUOTES, 'UTF-8') ?></td></tr><?php endif; ?>
        <tr><th style="width:24%;">Glosarium</th><td class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'glosarium'), ENT_QUOTES, 'UTF-8') ?></td></tr>
    </table>

    <div style="margin-top:6px;">
        <div style="font-size:10.5px;font-weight:700;color:#1D3B5E;margin-bottom:4px;">Lampiran Digital (Pindai QR Code untuk Mengakses File / Tautan):</div>
        <?= $lampiranHtml ?>
    </div>

    <table class="sig">
        <tr>
            <td>
                Mengetahui,<br>Kepala Sekolah
                <div class="sig-space">
                    <?php if ($meta['stempel_b64'] !== ''): ?><img src="<?= $meta['stempel_b64'] ?>" class="stempel-img" alt="Stempel"><?php endif; ?>
                    <?php if ($meta['ttd_kepsek_b64'] !== ''): ?><img src="<?= $meta['ttd_kepsek_b64'] ?>" class="ttd-img" alt="TTD"><?php endif; ?>
                </div>
                <strong><?= $meta['nama_kepsek'] ?></strong><br>
                <?= $meta['nip_kepsek'] ?>
            </td>
            <td>
                <?= $meta['titimangsa'] ?>, <?= ilmuna_ma_indo_date(date('Y-m-d')) ?><br>
                Guru Mata Pelajaran
                <div class="sig-space"></div>
                <strong><?= $meta['nama_guru'] ?></strong><br>
                <?= $meta['nip_guru'] ?>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
<?php
    return ob_get_clean();
}

/**
 * 11. RENDER HTML LAPORAN KETERLAKSANAAN PEMBELAJARAN (A4 PORTRAIT)
 */
function ilmuna_render_laporan_modul_pdf_html(array $data) {
    $modul = $data['modul'] ?? [];
    $meta  = ilmuna_ma_extract_school_meta($data);

    $statusMap = [
        'terlaksana' => ['label' => 'Terlaksana Penuh', 'bg' => '#DCFCE7', 'color' => '#15803D', 'score' => 100],
        'sebagian'   => ['label' => 'Terlaksana Sebagian', 'bg' => '#FEF3C7', 'color' => '#B45309', 'score' => 50],
        'belum'      => ['label' => 'Belum Terlaksana', 'bg' => '#F1F5F9', 'color' => '#475569', 'score' => 0],
    ];
    $stP = $statusMap[$modul['status_pembukaan'] ?? 'belum'] ?? $statusMap['belum'];
    $stI = $statusMap[$modul['status_inti'] ?? 'belum'] ?? $statusMap['belum'];
    $stN = $statusMap[$modul['status_penutup'] ?? 'belum'] ?? $statusMap['belum'];
    $avgPct = (int)round(($stP['score'] + $stI['score'] + $stN['score']) / 3);
    $lampiranHtml = ilmuna_ma_render_qr_attachments_html($modul['lampiran'] ?? []);

    ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Keterlaksanaan - <?= htmlspecialchars($modul['judul_modul'] ?? 'Modul Ajar', ENT_QUOTES, 'UTF-8') ?></title>
    <style><?= ilmuna_ma_pdf_base_css() ?></style>
</head>
<body>
<div class="sheet">
    <div class="header-kop">
        <?php if ($meta['logo_b64'] !== ''): ?>
            <img src="<?= $meta['logo_b64'] ?>" class="kop-logo" alt="Logo">
        <?php else: ?>
            <div style="width:62px;"></div>
        <?php endif; ?>
        <div class="kop-text">
            <h2><?= $meta['nama_sekolah'] ?></h2>
            <?php if ($meta['alamat_sekolah'] !== ''): ?><p><?= $meta['alamat_sekolah'] ?></p><?php endif; ?>
            <?php if ($meta['kontak_sekolah'] !== ''): ?><p><?= $meta['kontak_sekolah'] ?></p><?php endif; ?>
        </div>
        <div style="width:62px;"></div>
    </div>

    <div class="doc-title-box">
        <div class="doc-title">LAPORAN KETERLAKSANAAN PEMBELAJARAN</div>
        <div class="doc-subtitle"><?= htmlspecialchars($modul['nama_mata_pelajaran'] ?? '', ENT_QUOTES, 'UTF-8') ?> &bull; <?= htmlspecialchars($modul['nama_kelas'] ?? '', ENT_QUOTES, 'UTF-8') ?> &bull; Pertemuan ke-<?= (int)($modul['pertemuan_ke'] ?? 1) ?></div>
    </div>

    <div class="sec-head">I. Identitas Pelaksanaan KBM</div>
    <table class="meta">
        <tr>
            <td class="lbl">Mata Pelajaran</td><td><?= htmlspecialchars($modul['nama_mata_pelajaran'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
            <td class="lbl">Kelas / Fase</td><td><?= htmlspecialchars($modul['nama_kelas'] ?? '-', ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars($modul['fase'] ?? 'Fase E', ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <tr>
            <td class="lbl">Tahun / Semester</td><td><?= htmlspecialchars($modul['tahun_pelajaran'] ?? '2026/2027', ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars($modul['semester'] ?? 'Ganjil', ENT_QUOTES, 'UTF-8') ?></td>
            <td class="lbl">Tanggal Pelaksanaan</td><td><?= ilmuna_ma_indo_date($modul['tanggal'] ?? null, true) ?></td>
        </tr>
        <tr>
            <td class="lbl">Nama Modul Ajar</td><td colspan="3"><strong><?= htmlspecialchars($modul['judul_modul'] ?? '-', ENT_QUOTES, 'UTF-8') ?></strong></td>
        </tr>
        <tr>
            <td class="lbl">Status Pertemuan</td><td><strong><?= (($modul['meeting_status'] ?? '') === 'final') ? 'FINAL (TERKUNCI)' : 'BERJALAN' ?></strong></td>
            <td class="lbl">Capaian Keterlaksanaan</td><td><strong style="color:#0D7A87;font-size:12px;"><?= $avgPct ?>%</strong></td>
        </tr>
    </table>

    <div class="sec-head">II. Rincian Keterlaksanaan Tahapan Kegiatan</div>
    <table class="grid">
        <thead>
            <tr>
                <th style="width:22%;">Tahapan Kegiatan</th>
                <th style="width:12%;text-align:center;">Durasi</th>
                <th style="width:22%;text-align:center;">Status</th>
                <th>Catatan Evaluasi / Kendala Guru</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>1. Pembukaan / Pendahuluan</strong></td>
                <td style="text-align:center;"><?= (int)($modul['durasi_pembukaan'] ?? 15) ?> Menit</td>
                <td style="text-align:center;"><span class="badge" style="background:<?= $stP['bg'] ?>;color:<?= $stP['color'] ?>;"><?= $stP['label'] ?></span></td>
                <td class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'catatan_pembukaan'), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <tr>
                <td><strong>2. Kegiatan Inti</strong></td>
                <td style="text-align:center;"><?= (int)($modul['durasi_inti'] ?? 60) ?> Menit</td>
                <td style="text-align:center;"><span class="badge" style="background:<?= $stI['bg'] ?>;color:<?= $stI['color'] ?>;"><?= $stI['label'] ?></span></td>
                <td class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'catatan_inti'), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <tr>
                <td><strong>3. Penutup</strong></td>
                <td style="text-align:center;"><?= (int)($modul['durasi_penutup'] ?? 15) ?> Menit</td>
                <td style="text-align:center;"><span class="badge" style="background:<?= $stN['bg'] ?>;color:<?= $stN['color'] ?>;"><?= $stN['label'] ?></span></td>
                <td class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'catatan_penutup'), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        </tbody>
    </table>

    <div class="sec-head">III. Refleksi Pembelajaran</div>
    <table class="grid">
        <tr><th style="width:24%;">Refleksi Guru</th><td class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'refleksi_guru'), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><th style="width:24%;">Refleksi Peserta Didik</th><td class="text-block"><?= htmlspecialchars(ilmuna_ma_val($modul, 'refleksi_siswa'), ENT_QUOTES, 'UTF-8') ?></td></tr>
    </table>

    <?php if (!empty($modul['lampiran'])): ?>
    <div class="sec-head">IV. Bukti &amp; Lampiran Digital (QR Code)</div>
    <?= $lampiranHtml ?>
    <?php endif; ?>

    <table class="sig">
        <tr>
            <td>
                Mengetahui,<br>Kepala Sekolah
                <div class="sig-space">
                    <?php if ($meta['stempel_b64'] !== ''): ?><img src="<?= $meta['stempel_b64'] ?>" class="stempel-img" alt="Stempel"><?php endif; ?>
                    <?php if ($meta['ttd_kepsek_b64'] !== ''): ?><img src="<?= $meta['ttd_kepsek_b64'] ?>" class="ttd-img" alt="TTD"><?php endif; ?>
                </div>
                <strong><?= $meta['nama_kepsek'] ?></strong><br>
                <?= $meta['nip_kepsek'] ?>
            </td>
            <td>
                <?= $meta['titimangsa'] ?>, <?= ilmuna_ma_indo_date(date('Y-m-d')) ?><br>
                Guru Mata Pelajaran
                <div class="sig-space"></div>
                <strong><?= $meta['nama_guru'] ?></strong><br>
                <?= $meta['nip_guru'] ?>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
<?php
    return ob_get_clean();
}

/**
 * 12. Generate PDF Binary via Headless Browser (Edge / Chrome)
 */
function ilmuna_generate_modul_ajar_pdf(array $data, $mode = 'modul') {
    $bin = ilmuna_ma_get_headless_browser();
    if (!$bin) {
        return ['ok' => false, 'headless_missing' => true, 'error' => 'Headless browser tidak tersedia di server.'];
    }

    $uploadDir = BASE_PATH . '/uploads/modul_ajar_pdf';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    if (!is_writable($uploadDir)) {
        return ['ok' => false, 'error' => 'Direktori PDF tidak dapat ditulis.'];
    }

    $prefix = ($mode === 'laporan') ? 'Laporan_Keterlaksanaan' : 'Modul_Ajar';
    $uniqueTmp = 'tmp_' . $mode . '_' . bin2hex(random_bytes(5));
    $htmlTmp = $uploadDir . '/' . $uniqueTmp . '.html';
    $pdfPath = $uploadDir . '/' . $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';

    $htmlContent = ($mode === 'laporan')
        ? ilmuna_render_laporan_modul_pdf_html($data)
        : ilmuna_render_modul_ajar_pdf_html($data);

    if (!@file_put_contents($htmlTmp, $htmlContent)) {
        return ['ok' => false, 'error' => 'Gagal menulis HTML sementara.'];
    }

    $fileUri = 'file:///' . str_replace('\\', '/', $htmlTmp);
    $userDataDir = sys_get_temp_dir() . '/ilmuna_ma_pdf_' . uniqid();
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
         . ' --print-to-pdf="' . $pdfPath . '"'
         . ' "' . $fileUri . '" 2>&1';

    @set_time_limit(180);
    @exec($cmd, $out, $ret);

    @unlink($htmlTmp);
    @rmdir($userDataDir);

    if (file_exists($pdfPath) && filesize($pdfPath) > 0) {
        return ['ok' => true, 'path' => $pdfPath];
    }

    return ['ok' => false, 'error' => 'Gagal mengenerate berkas PDF via headless browser.'];
}

/**
 * 13. Output Langsung (Direct PDF Download bila Headless Tersedia, atau Print-to-PDF HTML Fallback)
 */
function ilmuna_output_modul_ajar_pdf(array $data, $mode = 'modul', $forceHtmlPrint = false) {
    $modul = $data['modul'] ?? [];
    $cleanTitle = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)($modul['judul_modul'] ?? 'Modul_Ajar'));
    $cleanTitle = trim(preg_replace('/_+/', '_', $cleanTitle), '_');
    $cleanTitle = substr($cleanTitle, 0, 80) ?: 'Modul_Ajar';
    $filename = (($mode === 'laporan') ? 'Laporan_Keterlaksanaan_' : '') . $cleanTitle . '.pdf';

    if (!$forceHtmlPrint) {
        $gen = ilmuna_generate_modul_ajar_pdf($data, $mode);
        if (!empty($gen['ok']) && !empty($gen['path']) && file_exists($gen['path'])) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($gen['path']));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            readfile($gen['path']);
            @unlink($gen['path']);
            exit;
        }
    }

    // Fallback Print-to-PDF HTML A4 lengkap dengan toolbar cetak rapi
    $html = ($mode === 'laporan')
        ? ilmuna_render_laporan_modul_pdf_html($data)
        : ilmuna_render_modul_ajar_pdf_html($data);

    $toolbar = '
    <style>
        .ilmuna-no-print-bar { max-width: 820px; margin: 14px auto; padding: 10px 14px; background: #F8FAFC; border: 1px solid #CBD5E1; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; gap: 10px; font-family: "Segoe UI", Arial, sans-serif; font-size: 12.5px; }
        .ilmuna-no-print-bar button { padding: 7px 14px; border-radius: 7px; font-weight: 700; cursor: pointer; font-size: 12px; }
        .ilmuna-btn-p { background: #0D7A87; color: #fff; border: 1px solid #0D7A87; }
        .ilmuna-btn-c { background: #fff; color: #334155; border: 1px solid #CBD5E1; }
        @media screen { body { padding: 0 18px 28px; background: #F1F5F9; } .sheet { max-width: 820px; margin: 0 auto; background: #fff; padding: 26px 28px; border-radius: 10px; box-shadow: 0 8px 24px rgba(15,23,42,0.08); } }
        @media print { .ilmuna-no-print-bar { display: none !important; } body { padding: 0; background: #fff; } .sheet { max-width: 100%; padding: 0; box-shadow: none; } }
    </style>
    <div class="ilmuna-no-print-bar">
        <div><strong>Dokumen Siap Cetak / Simpan PDF</strong> &bull; Pilih <em>Save as PDF</em> pada dialog cetak.</div>
        <div style="display:flex;gap:8px;">
            <button type="button" class="ilmuna-btn-p" onclick="window.print()">Cetak / Unduh PDF</button>
            <button type="button" class="ilmuna-btn-c" onclick="window.close()">Tutup</button>
        </div>
    </div>
    <script>window.addEventListener("load", function(){ setTimeout(function(){ try { window.print(); } catch(e){} }, 350); });</script>';

    $html = str_ireplace('<body>', '<body>' . $toolbar, $html);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}
