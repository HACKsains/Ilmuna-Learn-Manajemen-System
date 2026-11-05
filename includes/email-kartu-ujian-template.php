<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — TEMPLATE EMAIL & GENERATOR PDF KARTU PESERTA UJIAN (A5)
 * ==============================================================================
 * Lokasi: includes/email-kartu-ujian-template.php
 * Khusus: Asesmen Diagnostik & Asesmen Formatif
 * Fitur:
 *   1. Layout Responsif Email Gmail (1 Tema Resmi Ilmuna: Navy #1D3B5E, Teal #0D7A87, Sky Blue #52B2DC).
 *   2. Generator Berkas PDF Kartu Peserta Ujian Berukuran A5 (148 x 210 mm) Portrait via Headless Chrome/Edge.
 *   3. Lampiran Berkas PDF langsung terlampir pada email siswa.
 *   4. Petunjuk cetak mandiri kertas A5 yang jelas dan tegas.
 *   5. ATURAN KRITIS: TANPA MENYERTAKAN TOKEN SESI (Token rahasia diberikan di ruang ujian).
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/dashboard/admin/users-email-template.php';

/**
 * Deteksi peramban headless (Edge / Chrome) untuk ekspor PDF presisi tinggi
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
 * 1. Render Template HTML Berkas PDF Kartu Peserta Ujian (Format Presisi Kertas A5)
 *
 * @param array $cardData Data kartu peserta
 * @return string HTML lengkap untuk dicetak sebagai PDF A5
 */
function ilmuna_render_kartu_ujian_a5_pdf_html($cardData) {
    $c_navy  = '#1D3B5E'; // Biru Tua
    $c_teal  = '#0D7A87'; // Teal / Hijau Toska
    $c_sky   = '#52B2DC'; // Biru Muda (Sky Blue)

    $siteName       = defined('APP_NAME') ? APP_NAME : 'Ilmuna Learn';
    $siteTag        = defined('APP_TAGLINE') ? APP_TAGLINE : 'Media Pembelajaran Digital Pendidikan Agama Islam';
    $namaSiswa      = htmlspecialchars($cardData['nama_siswa'] ?? 'Peserta Ujian', ENT_QUOTES, 'UTF-8');
    $nomorPeserta   = htmlspecialchars($cardData['nomor_peserta'] ?? '-', ENT_QUOTES, 'UTF-8');
    $nis            = htmlspecialchars($cardData['nis'] ?? '-', ENT_QUOTES, 'UTF-8');
    $nisn           = htmlspecialchars($cardData['nisn'] ?? '-', ENT_QUOTES, 'UTF-8');
    $namaKelas      = htmlspecialchars($cardData['nama_kelas'] ?? 'Umum', ENT_QUOTES, 'UTF-8');
    $namaMapel      = htmlspecialchars($cardData['nama_mata_pelajaran'] ?? 'Pendidikan Agama Islam', ENT_QUOTES, 'UTF-8');
    $judulAsesmen   = htmlspecialchars($cardData['judul_asesmen'] ?? 'Asesmen CBT', ENT_QUOTES, 'UTF-8');
    $tipeKategori   = strtolower($cardData['tipe_kategori'] ?? 'diagnostik');
    $pertemuanKe    = (int)($cardData['pertemuan_ke'] ?? 1);
    $tahunPelajaran = htmlspecialchars($cardData['tahun_pelajaran'] ?? '2026/2027', ENT_QUOTES, 'UTF-8');
    $semester       = htmlspecialchars($cardData['semester'] ?? 'Ganjil', ENT_QUOTES, 'UTF-8');
    $usernameUjian  = htmlspecialchars($cardData['username_ujian'] ?? '-', ENT_QUOTES, 'UTF-8');
    $passwordUjian  = htmlspecialchars($cardData['password_ujian'] ?? '-', ENT_QUOTES, 'UTF-8');
    $durasiMenit    = (int)($cardData['durasi_menit'] ?? 60);
    $tglPelaksanaan = !empty($cardData['tanggal_pelaksanaan']) ? date('d/m/Y', strtotime($cardData['tanggal_pelaksanaan'])) : date('d/m/Y');
    $jamMulai       = !empty($cardData['jam_mulai']) ? substr($cardData['jam_mulai'], 0, 5) : '08:00';
    $jamSelesai     = !empty($cardData['jam_selesai']) ? substr($cardData['jam_selesai'], 0, 5) : '09:30';

    $tipeTitle = ($tipeKategori === 'diagnostik') ? 'ASESMEN DIAGNOSTIK' : 'ASESMEN FORMATIF';
    if ($pertemuanKe > 0) {
        $tipeTitle .= ' (PERTEMUAN KE-' . $pertemuanKe . ')';
    }

    // Logo Resmi Ilmuna (Base64 data URI agar mandiri & tampil sempurna di headless)
    $logoFile = BASE_PATH . '/assets/images/logo.png';
    $logoBase64 = '';
    if (file_exists($logoFile)) {
        $logoData = file_get_contents($logoFile);
        $logoBase64 = 'data:image/png;base64,' . base64_encode($logoData);
    }

    // Foto Siswa jika ada
    $fotoSrc = '';
    if (!empty($cardData['foto'])) {
        $fotoPath = BASE_PATH . '/' . ltrim($cardData['foto'], '/');
        if (file_exists($fotoPath)) {
            $mime = (mime_content_type($fotoPath) ?: 'image/jpeg');
            $fotoSrc = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fotoPath));
        }
    }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kartu Peserta Ujian A5 - {$namaSiswa}</title>
    <style>
        @page {
            size: A5 portrait;
            margin: 8mm 9mm 8mm 9mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            margin: 0;
            padding: 0;
            background: #FFFFFF;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: {$c_navy};
            font-size: 11px;
            line-height: 1.4;
        }
        .a5-card {
            width: 100%;
            height: 100%;
            border: 2.5px solid {$c_navy};
            border-radius: 12px;
            padding: 12px 14px;
            position: relative;
            background: #FFFFFF;
        }
        .a5-card::before {
            content: "";
            position: absolute;
            top: 3px; left: 3px; right: 3px; bottom: 3px;
            border: 1px solid {$c_sky};
            border-radius: 9px;
            pointer-events: none;
        }
        .kop-table {
            width: 100%;
            border-bottom: 2.5px double {$c_navy};
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .kop-logo {
            width: 62px;
            text-align: center;
            vertical-align: middle;
        }
        .kop-logo img {
            width: 52px;
            height: 52px;
            object-fit: contain;
        }
        .kop-text {
            text-align: center;
            vertical-align: middle;
            padding-right: 15px;
        }
        .kop-title {
            font-size: 14.5px;
            font-weight: 900;
            color: {$c_navy};
            letter-spacing: 0.5px;
            margin: 0 0 2px;
            text-transform: uppercase;
        }
        .kop-sub {
            font-size: 11px;
            font-weight: 700;
            color: {$c_teal};
            letter-spacing: 0.3px;
            margin: 0 0 2px;
            text-transform: uppercase;
        }
        .kop-desc {
            font-size: 9px;
            color: #64748B;
            margin: 0;
        }
        .badge-header {
            background: linear-gradient(135deg, {$c_navy} 0%, {$c_teal} 100%);
            color: #FFFFFF;
            text-align: center;
            font-size: 11px;
            font-weight: 800;
            padding: 5px 8px;
            border-radius: 6px;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        .main-info-table {
            width: 100%;
            margin-bottom: 10px;
        }
        .photo-box {
            width: 90px;
            height: 115px;
            border: 1.5px solid {$c_sky};
            border-radius: 6px;
            background: #F8FAFC;
            text-align: center;
            vertical-align: middle;
            overflow: hidden;
            padding: 2px;
        }
        .photo-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 4px;
        }
        .photo-box .placeholder {
            font-size: 9.5px;
            color: #94A3B8;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            background: #F1F5F9;
        }
        .details-col {
            vertical-align: top;
            padding-left: 12px;
        }
        .detail-row {
            display: flex;
            margin-bottom: 4.5px;
            font-size: 10.5px;
            border-bottom: 1px dotted #E2E8F0;
            padding-bottom: 2.5px;
        }
        .detail-label {
            width: 105px;
            font-weight: 700;
            color: {$c_teal};
            flex-shrink: 0;
        }
        .detail-sep {
            width: 10px;
            color: #64748B;
        }
        .detail-val {
            font-weight: 700;
            color: {$c_navy};
            flex-grow: 1;
            word-break: break-word;
        }
        .cred-box {
            background: #F0F9FF;
            border: 1.5px solid {$c_sky};
            border-top: 3px solid {$c_teal};
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 10px;
        }
        .cred-table {
            width: 100%;
        }
        .cred-cell {
            width: 50%;
            vertical-align: top;
            padding: 2px 6px;
        }
        .cred-cell:first-child {
            border-right: 1.5px dashed {$c_sky};
        }
        .cred-label {
            font-size: 9.5px;
            font-weight: 700;
            color: {$c_teal};
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        .cred-val {
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 15px;
            font-weight: 900;
            color: {$c_navy};
            letter-spacing: 0.8px;
        }
        .token-notice {
            background: #FEF3C7;
            border: 1px solid #FCD34D;
            border-radius: 5px;
            padding: 4px 8px;
            margin-top: 6px;
            font-size: 9px;
            color: #92400E;
            line-height: 1.35;
        }
        .print-notice {
            background: #F8FAFC;
            border-left: 3.5px solid {$c_sky};
            border-radius: 0 6px 6px 0;
            padding: 6px 10px;
            font-size: 9.2px;
            color: #475569;
            margin-bottom: 10px;
            line-height: 1.4;
        }
        .signature-table {
            width: 100%;
            margin-top: 6px;
        }
        .sig-col {
            width: 50%;
            text-align: center;
            font-size: 9.5px;
            vertical-align: top;
        }
        .sig-space {
            height: 38px;
        }
        .sig-name {
            font-weight: 800;
            text-decoration: underline;
            color: {$c_navy};
        }
        .card-footer-note {
            text-align: center;
            font-size: 8px;
            color: #94A3B8;
            border-top: 1px dashed #CBD5E1;
            padding-top: 5px;
            margin-top: 8px;
        }
    </style>
</head>
<body>
    <div class="a5-card">
        
        <!-- KOP SURAT RESMI -->
        <table class="kop-table" cellspacing="0" cellpadding="0">
            <tr>
                <td class="kop-logo">
                    <img src="{$logoBase64}" alt="Logo">
                </td>
                <td class="kop-text">
                    <div class="kop-title">{$siteName} — KARTU PESERTA CBT</div>
                    <div class="kop-sub">{$tipeTitle}</div>
                    <div class="kop-desc">{$siteTag} &bull; T.P. {$tahunPelajaran} ({$semester})</div>
                </td>
            </tr>
        </table>

        <!-- BADGE INDIKATOR UJIAN -->
        <div class="badge-header">
            KARTU IDENTITAS RESMI UJIAN COMPUTER BASED TEST (CBT)
        </div>

        <!-- IDENTITAS PESERTA -->
        <table class="main-info-table" cellspacing="0" cellpadding="0">
            <tr>
                <td class="photo-box">
HTML;

    if (!empty($fotoSrc)) {
        $html .= '<img src="' . $fotoSrc . '" alt="Foto ' . $namaSiswa . '">';
    } else {
        $html .= '<div class="placeholder">PASFOTO<br>3x4 CM</div>';
    }

    $html .= <<<HTML
                </td>
                <td class="details-col">
                    <div class="detail-row">
                        <span class="detail-label">Nomor Peserta</span>
                        <span class="detail-sep">:</span>
                        <span class="detail-val" style="font-family: monospace; font-size: 11.5px; color: {$c_teal};">{$nomorPeserta}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Nama Siswa</span>
                        <span class="detail-sep">:</span>
                        <span class="detail-val">{$namaSiswa}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">NIS / NISN</span>
                        <span class="detail-sep">:</span>
                        <span class="detail-val">{$nis} / {$nisn}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Rombel Kelas</span>
                        <span class="detail-sep">:</span>
                        <span class="detail-val">{$namaKelas}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Mata Pelajaran</span>
                        <span class="detail-sep">:</span>
                        <span class="detail-val">{$namaMapel}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Nama Asesmen</span>
                        <span class="detail-sep">:</span>
                        <span class="detail-val">{$judulAsesmen}</span>
                    </div>
                    <div class="detail-row" style="border-bottom:none;">
                        <span class="detail-label">Jadwal & Durasi</span>
                        <span class="detail-sep">:</span>
                        <span class="detail-val">{$tglPelaksanaan} &bull; {$jamMulai} - {$jamSelesai} ({$durasiMenit} Menit)</span>
                    </div>
                </td>
            </tr>
        </table>

        <!-- BOX KREDENSIAL LOGIN (TANPA TOKEN SESI) -->
        <div class="cred-box">
            <table class="cred-table" cellspacing="0" cellpadding="0">
                <tr>
                    <td class="cred-cell">
                        <div class="cred-label">👤 Username Login Ujian</div>
                        <div class="cred-val">{$usernameUjian}</div>
                    </td>
                    <td class="cred-cell" style="padding-left: 12px;">
                        <div class="cred-label">🔑 Password / PIN Ujian</div>
                        <div class="cred-val">{$passwordUjian}</div>
                    </td>
                </tr>
            </table>

            <!-- PEMBERITAHUAN RESMI: TOKEN SESI TIDAK DICANTUMKAN -->
            <div class="token-notice">
                <strong>🔒 Catatan Rahasia Token Sesi:</strong> Token Sesi ujian <u>TIDAK dicantumkan</u> pada kartu ini demi keamanan CBT. Token akan diumumkan secara langsung oleh Pengawas di ruang ujian saat asesmen dimulai.
            </div>
        </div>

        <!-- PETUNJUK CETAK MANDIRI KERTAS A5 -->
        <div class="print-notice">
            <strong>📌 Petunjuk Pencetakan Mandiri (Kertas A5):</strong><br>
            1. Kartu ini wajib dicetak secara mandiri oleh peserta pada <strong>kertas ukuran A5 (148 x 210 mm)</strong>.<br>
            2. Wajib dibawa saat memasuki ruang ujian dan diletakkan di atas meja peserta.<br>
            3. Dilarang mencoret, merusak, atau memindahtangankan kredensial login kepada siswa lain.
        </div>

        <!-- TITIMANGSA & TANDA TANGAN -->
        <table class="signature-table" cellspacing="0" cellpadding="0">
            <tr>
                <td class="sig-col">
                    Peserta Ujian,
                    <div class="sig-space"></div>
                    <div class="sig-name">{$namaSiswa}</div>
                    <div style="font-size: 8.5px; color: #64748B;">NIS. {$nis}</div>
                </td>
                <td class="sig-col">
                    Ditetapkan Tanggal: {$tglPelaksanaan}<br>
                    Proktor / Pengawas Ruang,
                    <div class="sig-space"></div>
                    <div class="sig-name">( Pengawas Ruang CBT )</div>
                    <div style="font-size: 8.5px; color: #64748B;">Tanda Tangan & Verifikasi</div>
                </td>
            </tr>
        </table>

        <!-- FOOTER CATATAN SISTEM -->
        <div class="card-footer-note">
            Dokumen sah dicetak otomatis oleh Sistem {$siteName} &bull; ID Tiket: {$nomorPeserta} &bull; Dicetak: {$tglPelaksanaan}
        </div>

    </div>
</body>
</html>
HTML;

    return $html;
}

/**
 * 2. Generate Berkas PDF Kartu Peserta Ujian (A5)
 * Menggunakan engine headless browser (Edge/Chrome).
 *
 * @param array $cardData Data kartu peserta
 * @param string|null $targetPdfPath Jalur file PDF tujuan (opsional)
 * @return string|false Jalur absolut berkas PDF jika berhasil, false jika gagal
 */
function ilmuna_generate_kartu_ujian_pdf($cardData, $targetPdfPath = null) {
    $bin = ilmuna_get_headless_browser_bin();
    if (!$bin) {
        return false;
    }

    $uploadDir = BASE_PATH . '/uploads/kartu_ujian';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $nomorPeserta = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $cardData['nomor_peserta'] ?? 'KARTU');
    $nis          = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $cardData['nis'] ?? 'SISWA');
    $tipe         = strtolower($cardData['tipe_kategori'] ?? 'diagnostik');

    if (empty($targetPdfPath)) {
        $unique = 'Kartu_Ujian_' . strtoupper($tipe) . '_' . $nis . '_' . $nomorPeserta . '_' . bin2hex(random_bytes(3));
        $targetPdfPath = $uploadDir . '/' . $unique . '.pdf';
    }

    $uniqueTmp = 'tmp_' . bin2hex(random_bytes(6));
    $htmlTmp = $uploadDir . '/' . $uniqueTmp . '.html';

    $htmlContent = ilmuna_render_kartu_ujian_a5_pdf_html($cardData);
    if (!file_put_contents($htmlTmp, $htmlContent)) {
        return false;
    }

    $cmd = '"' . $bin . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --no-pdf-header-footer --print-to-pdf="' . $targetPdfPath . '" "file:///' . str_replace('\\', '/', $htmlTmp) . '"';
    exec($cmd, $out, $ret);

    @unlink($htmlTmp);

    if (file_exists($targetPdfPath) && filesize($targetPdfPath) > 0) {
        return $targetPdfPath;
    }

    return false;
}

/**
 * 3. Render HTML Email Pengiriman Kartu Ujian Resmi Ilmuna
 *
 * @param array $cardData Data kartu peserta
 * @param string $pdfFileName Nama berkas PDF yang dilampirkan
 * @return array ['subject' => string, 'body' => string, 'attachments' => array]
 */
function ilmuna_get_kartu_ujian_email_template($cardData, $pdfFileName = 'Kartu_Peserta_Ujian_A5.pdf') {
    $c_navy  = '#1D3B5E'; // Biru Tua
    $c_teal  = '#0D7A87'; // Teal / Hijau Toska
    $c_sky   = '#52B2DC'; // Biru Muda (Sky Blue)

    $namaSiswa      = htmlspecialchars($cardData['nama_siswa'] ?? 'Mahasiswa / Siswa', ENT_QUOTES, 'UTF-8');
    $nomorPeserta   = htmlspecialchars($cardData['nomor_peserta'] ?? '-', ENT_QUOTES, 'UTF-8');
    $nis            = htmlspecialchars($cardData['nis'] ?? '-', ENT_QUOTES, 'UTF-8');
    $nisn           = htmlspecialchars($cardData['nisn'] ?? '-', ENT_QUOTES, 'UTF-8');
    $namaKelas      = htmlspecialchars($cardData['nama_kelas'] ?? 'Umum', ENT_QUOTES, 'UTF-8');
    $namaMapel      = htmlspecialchars($cardData['nama_mata_pelajaran'] ?? 'Pendidikan Agama Islam', ENT_QUOTES, 'UTF-8');
    $judulAsesmen   = htmlspecialchars($cardData['judul_asesmen'] ?? 'Asesmen CBT', ENT_QUOTES, 'UTF-8');
    $tipeKategori   = strtolower($cardData['tipe_kategori'] ?? 'diagnostik');
    $pertemuanKe    = (int)($cardData['pertemuan_ke'] ?? 1);
    $tahunPelajaran = htmlspecialchars($cardData['tahun_pelajaran'] ?? '2026/2027', ENT_QUOTES, 'UTF-8');
    $usernameUjian  = htmlspecialchars($cardData['username_ujian'] ?? '-', ENT_QUOTES, 'UTF-8');
    $passwordUjian  = htmlspecialchars($cardData['password_ujian'] ?? '-', ENT_QUOTES, 'UTF-8');
    $durasiMenit    = (int)($cardData['durasi_menit'] ?? 60);
    $tglPelaksanaan = !empty($cardData['tanggal_pelaksanaan']) ? date('d F Y', strtotime($cardData['tanggal_pelaksanaan'])) : date('d F Y');
    $jamMulai       = !empty($cardData['jam_mulai']) ? substr($cardData['jam_mulai'], 0, 5) : '08:00';
    $jamSelesai     = !empty($cardData['jam_selesai']) ? substr($cardData['jam_selesai'], 0, 5) : '09:30';

    $siteName  = defined('APP_NAME') ? APP_NAME : 'Ilmuna Learn';
    $siteTag   = defined('APP_TAGLINE') ? APP_TAGLINE : 'Media Pembelajaran Digital Pendidikan Agama Islam';
    $baseUrl   = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://learn.ilmuna.my.id';
    $tahun     = date('Y');

    $tipeNama = ($tipeKategori === 'diagnostik') ? 'Asesmen Diagnostik' : 'Asesmen Formatif';
    if ($pertemuanKe > 0) {
        $tipeNama .= ' (Pertemuan Ke-' . $pertemuanKe . ')';
    }

    $logoFile = BASE_PATH . '/assets/images/logo.png';
    $attachments = [];
    $logoSrc = $baseUrl . '/assets/images/logo.png';

    if (file_exists($logoFile)) {
        $attachments[] = [
            'path'        => $logoFile,
            'name'        => 'logo.png',
            'mime'        => 'image/png',
            'cid'         => 'ilmuna_logo',
            'disposition' => 'inline'
        ];
        $logoSrc = 'cid:ilmuna_logo';
    }

    $subject = "Kartu Peserta Ujian {$tipeNama} — {$namaSiswa} ({$nomorPeserta})";

    $body = <<<HTML
<!DOCTYPE html>
<html lang="id" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>{$subject}</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        html, body {
            margin: 0 auto !important;
            padding: 0 !important;
            height: 100% !important;
            width: 100% !important;
            background-color: #F8FAFC;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: {$c_navy};
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        table, td {
            mso-table-lspace: 0pt !important;
            mso-table-rspace: 0pt !important;
        }
        table {
            border-spacing: 0 !important;
            border-collapse: collapse !important;
            table-layout: fixed !important;
            margin: 0 auto !important;
        }
        img {
            -ms-interpolation-mode: bicubic;
            border: 0;
            outline: none;
            text-decoration: none;
        }
        @media only screen and (max-width: 600px) {
            .ilmuna-container {
                width: 100% !important;
                max-width: 100% !important;
                border-radius: 0 !important;
                border-left: none !important;
                border-right: none !important;
            }
            .ilmuna-hdr-pad {
                padding: 26px 18px 22px !important;
            }
            .ilmuna-bdy-pad {
                padding: 24px 18px 20px !important;
            }
            .ilmuna-cred-val {
                font-size: 16px !important;
                word-break: break-all !important;
            }
            .ilmuna-btn-cta {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
                text-align: center !important;
                padding: 14px 16px !important;
            }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#F8FAFC;-webkit-font-smoothing:antialiased;">

    <!-- Wrapper -->
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#F8FAFC;padding:26px 8px;">
        <tr>
            <td align="center" style="vertical-align:top;">

                <!-- Preheader Text -->
                <div style="display:none;font-size:1px;color:#F8FAFC;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;">
                    Kartu Peserta Ujian {$tipeNama} Anda telah diterbitkan. Unduh dan cetak lampiran PDF dengan kertas A5 secara mandiri.
                </div>

                <!-- CARD CONTAINER -->
                <table role="presentation" class="ilmuna-container" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width:600px;background-color:#FFFFFF;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(29,59,94,0.08);border:1px solid #CBD5E1;">
                    
                    <!-- ============================================================== -->
                    <!-- HEADER RESMI: NAVY #1D3B5E -> TEAL #0D7A87 + BORDER #52B2DC    -->
                    <!-- ============================================================== -->
                    <tr>
                        <td class="ilmuna-hdr-pad" style="background: linear-gradient(135deg, {$c_navy} 0%, {$c_teal} 100%); padding: 32px 30px 26px; text-align: center; border-bottom: 3.5px solid {$c_sky};">
                            
                            <!-- Logo Resmi Ilmuna Badge -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 0 auto 12px;">
                                <tr>
                                    <td align="center" style="width: 76px; height: 76px; background-color: #FFFFFF; border-radius: 18px; box-shadow: 0 6px 20px rgba(0,0,0,0.18); border: 2px solid {$c_sky}; text-align: center; vertical-align: middle;">
                                        <img src="{$logoSrc}" alt="{$siteName}" width="54" height="54" style="width:54px;height:54px;max-width:54px;display:block;margin:0 auto;object-fit:contain;" />
                                    </td>
                                </tr>
                            </table>

                            <!-- Header Typography -->
                            <h1 style="margin: 0 0 6px; color: #FFFFFF; font-size: 21px; font-weight: 800; letter-spacing: -0.2px; line-height: 1.3;">
                                Kartu Peserta Ujian Digital
                            </h1>
                            <p style="margin: 0; color: {$c_sky}; font-size: 13px; font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase;">
                                {$tipeNama} &bull; T.P. {$tahunPelajaran}
                            </p>
                            <p style="margin: 4px 0 0; color: #E2E8F0; font-size: 11.5px; font-weight: 400;">
                                {$siteName} &bull; {$siteTag}
                            </p>
                        </td>
                    </tr>

                    <!-- ============================================================== -->
                    <!-- BODY SECTION                                                   -->
                    <!-- ============================================================== -->
                    <tr>
                        <td class="ilmuna-bdy-pad" style="padding: 30px 32px 20px;">
                            
                            <p style="margin: 0 0 12px; font-size: 15px; color: {$c_navy}; line-height: 1.6;">
                                <strong>Assalamu'alaikum Warahmatullahi Wabarakatuh,</strong><br>
                                Yth. <strong style="color: {$c_teal};">{$namaSiswa}</strong>
                            </p>

                            <p style="margin: 0 0 18px; font-size: 13.5px; color: #475569; line-height: 1.7;">
                                Akun tiket akses Computer Based Test (CBT) Anda untuk mata pelajaran <strong>{$namaMapel}</strong> dengan asesmen <strong>{$judulAsesmen}</strong> telah berhasil diterbitkan oleh panitia/guru pengampu.
                            </p>

                            <!-- ============================================================== -->
                            <!-- INSTRUKSI CETAK MANDIRI KERTAS A5 (SKY BLUE & TEAL HIGHLIGHT)  -->
                            <!-- ============================================================== -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F0F9FF; border: 1.5px solid {$c_sky}; border-left: 4.5px solid {$c_teal}; border-radius: 10px; margin-bottom: 22px;">
                                <tr>
                                    <td style="padding: 16px 18px;">
                                        <div style="font-size: 13.5px; font-weight: 800; color: {$c_navy}; margin-bottom: 6px;">
                                            📄 Berkas Lampiran: Cetak Mandiri Kertas A5
                                        </div>
                                        <div style="font-size: 12.5px; color: #334155; line-height: 1.65;">
                                            Berkas resmi Kartu Peserta Ujian telah kami lampirkan pada email ini dalam format <strong>PDF ({$pdfFileName})</strong>.<br>
                                            <span style="display: inline-block; margin-top: 4px; padding: 4px 10px; background: #FFFFFF; border: 1px solid {$c_sky}; border-radius: 6px; font-weight: 700; color: {$c_teal};">
                                                🖨️ Wajib Dicetak Mandiri Menggunakan Kertas Ukuran A5 (148 x 210 mm)
                                            </span><br>
                                            Harap unduh lampiran PDF tersebut, cetak dengan jelas, dan <strong>wajib dibawa</strong> saat pelaksanaan ujian untuk diverifikasi oleh Pengawas Ruang.
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <!-- ============================================================== -->
                            <!-- DETAIL IDENTITAS ASESMEN                                        -->
                            <!-- ============================================================== -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; margin-bottom: 20px;">
                                <tr>
                                    <td style="padding: 14px 16px;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                            <tr>
                                                <td style="width: 38%; font-size: 12px; color: #64748B; padding: 4px 0; font-weight: 600;">Nomor Peserta:</td>
                                                <td style="font-size: 12.5px; color: {$c_teal}; padding: 4px 0; font-weight: 800; font-family: monospace;">{$nomorPeserta}</td>
                                            </tr>
                                            <tr>
                                                <td style="font-size: 12px; color: #64748B; padding: 4px 0; font-weight: 600;">NIS / NISN:</td>
                                                <td style="font-size: 12px; color: {$c_navy}; padding: 4px 0; font-weight: 700;">{$nis} / {$nisn}</td>
                                            </tr>
                                            <tr>
                                                <td style="font-size: 12px; color: #64748B; padding: 4px 0; font-weight: 600;">Rombel Kelas:</td>
                                                <td style="font-size: 12px; color: {$c_navy}; padding: 4px 0; font-weight: 700;">{$namaKelas}</td>
                                            </tr>
                                            <tr>
                                                <td style="font-size: 12px; color: #64748B; padding: 4px 0; font-weight: 600;">Mata Pelajaran:</td>
                                                <td style="font-size: 12px; color: {$c_navy}; padding: 4px 0; font-weight: 700;">{$namaMapel}</td>
                                            </tr>
                                            <tr>
                                                <td style="font-size: 12px; color: #64748B; padding: 4px 0; font-weight: 600;">Asesmen / Jadwal:</td>
                                                <td style="font-size: 12px; color: {$c_navy}; padding: 4px 0; font-weight: 700;">{$judulAsesmen} &bull; {$tglPelaksanaan} ({$durasiMenit} Menit)</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- ============================================================== -->
                            <!-- BOX KREDENSIAL LOGIN (TANPA TOKEN SESI)                        -->
                            <!-- ============================================================== -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F8FAFC; border: 1.5px solid #CBD5E1; border-top: 3.5px solid {$c_teal}; border-radius: 12px; margin-bottom: 22px;">
                                <tr>
                                    <td style="padding: 18px 20px;">
                                        
                                        <!-- Field Username -->
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                            <tr>
                                                <td style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: {$c_teal}; padding-bottom: 3px;">
                                                    👤 Username Login CBT
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="ilmuna-cred-val" style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 18px; font-weight: 800; color: {$c_navy}; letter-spacing: 0.8px; word-break: break-all;">
                                                    {$usernameUjian}
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Divider Sky Blue -->
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 12px 0;">
                                            <tr>
                                                <td style="border-top: 1px dashed #CBD5E1; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                            </tr>
                                        </table>

                                        <!-- Field Password -->
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                            <tr>
                                                <td style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: {$c_teal}; padding-bottom: 3px;">
                                                    🔑 Password / PIN Ujian
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="ilmuna-cred-val" style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 18px; font-weight: 800; color: {$c_navy}; letter-spacing: 0.8px; word-break: break-all;">
                                                    {$passwordUjian}
                                                </td>
                                            </tr>
                                        </table>

                                    </td>
                                </tr>
                            </table>

                            <!-- ============================================================== -->
                            <!-- CATATAN TOKEN SESI (SANGAT JELAS TANPA MENYERTAKAN TOKEN)      -->
                            <!-- ============================================================== -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F8FAFC; border-left: 4px solid {$c_sky}; border-radius: 8px; margin-bottom: 22px;">
                                <tr>
                                    <td style="padding: 13px 16px; font-size: 12px; color: {$c_navy}; line-height: 1.6;">
                                        <strong>🔒 Informasi Token Sesi Ujian:</strong><br>
                                        Sesuai Prosedur Operasional Standar (POS) CBT, <strong>Token Sesi Ujian tidak dicantumkan</strong> pada email maupun kartu ini demi menjamin ketertiban dan integritas pelaksanaan asesmen.<br>
                                        Token Sesi akan diumumkan secara langsung oleh Pengawas Ruang sesaat sebelum ujian dimulai.
                                    </td>
                                </tr>
                            </table>


                        </td>
                    </tr>

                    <!-- ============================================================== -->
                    <!-- FOOTER SECTION (TEMA NAVY & SLATE)                             -->
                    <!-- ============================================================== -->
                    <tr>
                        <td style="padding: 22px 30px 24px; background-color: #F8FAFC; border-top: 1px solid #E2E8F0; text-align: center;">
                            <p style="margin: 0 0 4px; font-size: 13px; font-weight: 700; color: {$c_navy};">
                                {$siteName}
                            </p>
                            <p style="margin: 0 0 8px; font-size: 11.5px; color: #64748B;">
                                {$siteTag} &bull; Portal Pembelajaran & CBT Terpadu
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #94A3B8; line-height: 1.6;">
                                Email notifikasi kartu ujian ini dikirimkan otomatis oleh server sistem.<br>
                                &copy; {$tahun} {$siteName}. Hak Cipta Dilindungi.
                            </p>
                        </td>
                    </tr>

                </table>
                <!-- /CARD CONTAINER -->

            </td>
        </tr>
    </table>

</body>
</html>
HTML;

    return [
        'subject'     => $subject,
        'body'        => $body,
        'attachments' => $attachments
    ];
}

/**
 * 4. Kirim Email Kartu Ujian Beserta Lampiran Berkas PDF (A5) via Socket SMTP
 *
 * @param string $toEmail Alamat email tujuan
 * @param array $cardData Data lengkap kartu ujian
 * @return array ['success' => bool, 'message' => string, 'pdf_path' => string|null]
 */
function ilmuna_send_kartu_ujian_email($toEmail, $cardData) {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => 'Format email mahasiswa tidak valid: ' . $toEmail
        ];
    }

    $cfg = ilmuna_smtp_config();
    if (empty($cfg['is_enabled'])) {
        return [
            'success' => false,
            'message' => 'Pengiriman email dinonaktifkan di konfigurasi SMTP.'
        ];
    }

    // 1. Generate Berkas PDF Kartu Peserta Ujian (A5)
    $nomorPeserta = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $cardData['nomor_peserta'] ?? 'KARTU');
    $nis          = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $cardData['nis'] ?? 'SISWA');
    $tipeClean    = strtoupper($cardData['tipe_kategori'] ?? 'DIAGNOSTIK');

    $pdfFileName = "Kartu_Ujian_{$tipeClean}_{$nis}_{$nomorPeserta}.pdf";
    $pdfPath = ilmuna_generate_kartu_ujian_pdf($cardData);

    if (!$pdfPath || !file_exists($pdfPath)) {
        return [
            'success' => false,
            'message' => 'Gagal menghasilkan berkas PDF Kartu Peserta Ujian A5.'
        ];
    }

    // 2. Ambil Template Email
    $tpl = ilmuna_get_kartu_ujian_email_template($cardData, $pdfFileName);
    $subject     = $tpl['subject'];
    $bodyHtml    = $tpl['body'];
    $attachments = $tpl['attachments'] ?? [];

    // Tambahkan PDF sebagai lampiran download (attachment)
    $attachments[] = [
        'path'        => $pdfPath,
        'name'        => $pdfFileName,
        'mime'        => 'application/pdf',
        'disposition' => 'attachment'
    ];

    $host     = $cfg['host'] ?: 'smtp.gmail.com';
    $port     = (int)($cfg['port'] ?: 587);
    $secure   = strtolower($cfg['secure'] ?: 'tls');
    $user     = $cfg['user'] ?: '';
    $pass     = $cfg['pass'] ?: '';
    $from     = $cfg['from'] ?: $user;
    $fromName = $cfg['from_name'] ?: 'Ilmuna System';

    // 3. Kirim Email via Socket SMTP dengan dukungan Multipart Attachment
    $res = ilmuna_send_multipart_smtp([
        'host'        => $host,
        'port'        => $port,
        'secure'      => $secure,
        'user'        => $user,
        'pass'        => $pass,
        'from'        => $from,
        'from_name'   => $fromName,
        'to'          => $toEmail,
        'subject'     => $subject,
        'body'        => $bodyHtml,
        'attachments' => $attachments
    ]);

    // Catat log pengiriman email
    if (function_exists('ilmuna_log_email_send')) {
        ilmuna_log_email_send([
            'timestamp' => date('Y-m-d H:i:s'),
            'to'        => $toEmail,
            'nama'      => $cardData['nama_siswa'] ?? 'Siswa',
            'username'  => $cardData['username_ujian'] ?? '',
            'success'   => $res['success'],
            'detail'    => $res['message'] . " (PDF: $pdfFileName)"
        ]);
    }

    $res['pdf_path'] = $pdfPath;
    $res['pdf_name'] = $pdfFileName;
    return $res;
}

/**
 * 5. Socket SMTP Engine Khusus Berkas Lampiran (Multipart/Mixed + Multipart/Related)
 * Mendukung dual-port fallback otomatis (587 TLS <-> 465 SSL)
 */
function ilmuna_send_multipart_smtp($params) {
    $res = _ilmuna_single_multipart_smtp($params);
    if ($res['success']) {
        return $res;
    }

    $origPort   = (int)($params['port'] ?? 587);
    $origSecure = strtolower($params['secure'] ?? 'tls');

    if ($origPort === 587 || $origSecure === 'tls') {
        $alt = $params;
        $alt['port'] = 465;
        $alt['secure'] = 'ssl';
        $altRes = _ilmuna_single_multipart_smtp($alt);
        if ($altRes['success']) return $altRes;
    } elseif ($origPort === 465 || $origSecure === 'ssl') {
        $alt = $params;
        $alt['port'] = 587;
        $alt['secure'] = 'tls';
        $altRes = _ilmuna_single_multipart_smtp($alt);
        if ($altRes['success']) return $altRes;
    }

    return $res;
}

function _ilmuna_single_multipart_smtp($params) {
    $host      = $params['host'];
    $port      = $params['port'];
    $secure    = $params['secure'];
    $user      = trim($params['user'] ?? '');
    $pass      = trim(str_replace(' ', '', $params['pass'] ?? ''));
    $from      = trim($params['from'] ?? '');
    $fromName  = $params['from_name'] ?? 'Ilmuna Learn';
    $to        = $params['to'] ?? '';
    $subject   = $params['subject'] ?? '';
    $body      = $params['body'] ?? '';

    $timeout   = 20;
    $errno     = 0;
    $errstr    = '';

    $targetHost = ($secure === 'ssl') ? ('ssl://' . $host) : ('tcp://' . $host);

    $context = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true
        ]
    ]);

    $socket = @stream_socket_client($targetHost . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        return ['success' => false, 'message' => "Gagal terhubung ke host SMTP ($host:$port): $errstr ($errno)"];
    }

    stream_set_timeout($socket, $timeout);

    $readResponse = function() use ($socket) {
        $data = '';
        while (!feof($socket)) {
            $line = fgets($socket, 1024);
            $data .= $line;
            if (substr($line, 3, 1) === ' ' || empty($line)) break;
        }
        return $data;
    };

    $sendCommand = function($cmd) use ($socket, $readResponse) {
        fputs($socket, $cmd . "\r\n");
        return $readResponse();
    };

    $initial = $readResponse();
    if (substr($initial, 0, 3) !== '220') {
        fclose($socket);
        return ['success' => false, 'message' => 'Respon handshake awal SMTP tidak valid: ' . trim($initial)];
    }

    $ehlo = $sendCommand('EHLO ' . (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost'));

    if ($secure === 'tls') {
        $tlsRes = $sendCommand('STARTTLS');
        if (substr($tlsRes, 0, 3) !== '220') {
            fclose($socket);
            return ['success' => false, 'message' => 'STARTTLS ditolak server SMTP: ' . trim($tlsRes)];
        }

        $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        $cryptoOk = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
        if (!$cryptoOk) {
            fclose($socket);
            return ['success' => false, 'message' => 'Gagal negosiasi TLS dengan server SMTP.'];
        }

        $sendCommand('EHLO ' . (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost'));
    }

    if (!empty($user) && !empty($pass)) {
        $authRes = $sendCommand('AUTH LOGIN');
        if (substr($authRes, 0, 3) !== '334') {
            fclose($socket);
            return ['success' => false, 'message' => 'Server tidak mendukung AUTH LOGIN: ' . trim($authRes)];
        }

        $userRes = $sendCommand(base64_encode($user));
        if (substr($userRes, 0, 3) !== '334') {
            fclose($socket);
            return ['success' => false, 'message' => 'Username SMTP ditolak: ' . trim($userRes)];
        }

        $passRes = $sendCommand(base64_encode($pass));
        if (substr($passRes, 0, 3) !== '235') {
            fclose($socket);
            return ['success' => false, 'message' => 'Password/App Password SMTP ditolak: ' . trim($passRes)];
        }
    }

    $mailFromRes = $sendCommand('MAIL FROM:<' . $from . '>');
    if (substr($mailFromRes, 0, 3) !== '250') {
        fclose($socket);
        return ['success' => false, 'message' => 'MAIL FROM ditolak: ' . trim($mailFromRes)];
    }

    $rcptRes = $sendCommand('RCPT TO:<' . $to . '>');
    if (substr($rcptRes, 0, 3) !== '250' && substr($rcptRes, 0, 3) !== '251') {
        fclose($socket);
        return ['success' => false, 'message' => 'Alamat email penerima ditolak oleh SMTP: ' . trim($rcptRes)];
    }

    $dataRes = $sendCommand('DATA');
    if (substr($dataRes, 0, 3) !== '354') {
        fclose($socket);
        return ['success' => false, 'message' => 'Perintah DATA ditolak: ' . trim($dataRes)];
    }

    // Pembuatan MIME Multipart/Mixed (Top level) + Multipart/Related (Body + CID Logo) + Attachments (PDF)
    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $encodedSubject  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $attachments     = $params['attachments'] ?? [];

    $mixedBoundary   = "----=_Ilmuna_Mixed_" . md5(uniqid((string)rand(), true));
    $relatedBoundary = "----=_Ilmuna_Related_" . md5(uniqid((string)rand(), true));

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"',
        'From: ' . $encodedFromName . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Reply-To: ' . $encodedFromName . ' <' . $from . '>',
        'Subject: ' . $encodedSubject,
        'Date: ' . date('r'),
        'X-Mailer: Ilmuna-Learn-SMTP/1.0',
        'Precedence: bulk'
    ];

    $payloadLines = [];

    // Part 1: multipart/related (HTML Body + Inline CID images)
    $payloadLines[] = "--" . $mixedBoundary;
    $payloadLines[] = 'Content-Type: multipart/related; boundary="' . $relatedBoundary . '"' . "\r\n";
    
    // 1a: HTML Body
    $payloadLines[] = "--" . $relatedBoundary;
    $payloadLines[] = 'Content-Type: text/html; charset=UTF-8';
    $payloadLines[] = "Content-Transfer-Encoding: 8bit\r\n";
    $payloadLines[] = $body;

    // Pisahkan attachment inline (CID) dan attachment biasa (PDF)
    $fileAttachments = [];
    foreach ($attachments as $att) {
        if (empty($att['path']) || !file_exists($att['path'])) continue;
        $disp = strtolower($att['disposition'] ?? 'attachment');
        if ($disp === 'inline' && !empty($att['cid'])) {
            $attData = @file_get_contents($att['path']);
            if ($attData !== false) {
                $payloadLines[] = "--" . $relatedBoundary;
                $payloadLines[] = 'Content-Type: ' . ($att['mime'] ?? 'image/png') . '; name="' . ($att['name'] ?? basename($att['path'])) . '"';
                $payloadLines[] = 'Content-Transfer-Encoding: base64';
                $payloadLines[] = 'Content-ID: <' . $att['cid'] . '>';
                $payloadLines[] = 'Content-Disposition: inline; filename="' . ($att['name'] ?? basename($att['path'])) . "\"\r\n";
                $payloadLines[] = chunk_split(base64_encode($attData));
            }
        } else {
            $fileAttachments[] = $att;
        }
    }
    $payloadLines[] = "--" . $relatedBoundary . "--\r\n";

    // Part 2: Berkas Lampiran PDF (attachment)
    foreach ($fileAttachments as $fatt) {
        $fdata = @file_get_contents($fatt['path']);
        if ($fdata !== false) {
            $fname = $fatt['name'] ?? basename($fatt['path']);
            $fmime = $fatt['mime'] ?? 'application/pdf';
            $payloadLines[] = "--" . $mixedBoundary;
            $payloadLines[] = 'Content-Type: ' . $fmime . '; name="' . $fname . '"';
            $payloadLines[] = 'Content-Transfer-Encoding: base64';
            $payloadLines[] = 'Content-Disposition: attachment; filename="' . $fname . "\"\r\n";
            $payloadLines[] = chunk_split(base64_encode($fdata));
        }
    }
    $payloadLines[] = "--" . $mixedBoundary . "--";

    $fullMessage = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $payloadLines) . "\r\n.";
    $sendRes = $sendCommand($fullMessage);

    $sendCommand('QUIT');
    fclose($socket);

    if (substr($sendRes, 0, 3) === '250') {
        return ['success' => true, 'message' => 'Kartu peserta ujian PDF A5 berhasil dikirimkan ke Gmail: ' . $to];
    }

    return ['success' => false, 'message' => 'Gagal mengirimkan data email: ' . trim($sendRes)];
}
