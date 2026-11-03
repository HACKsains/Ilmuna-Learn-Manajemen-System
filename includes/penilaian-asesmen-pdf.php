<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — GENERATOR PDF LEMBAR PENILAIAN AFEKTIF & PSIKOMOTORIK
 * ==============================================================================
 * Format Kertas: A4 Landscape (297 x 210 mm)
 * Engine       : Headless Browser (Microsoft Edge / Google Chrome)
 * Output       : Berkas PDF resmi daftar nilai rubrik observasi dan unjuk kerja siswa
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
    function ilmuna_indo_date_full($dateStr) {
        if (!$dateStr || $dateStr === '-' || $dateStr === '0000-00-00') return date('d F Y');
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
 * 2b. Helper Konversi Berkas Gambar ke Base64 URI Presisi Tinggi
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
 * 3. Render HTML Template Lembar Penilaian Siswa Resmi (A4 Landscape)
 */
function ilmuna_render_penilaian_asesmen_pdf_html(array $data) {
    $asesmen    = $data['asesmen'] ?? [];
    $items      = $data['items'] ?? [];
    $students   = $data['students'] ?? [];
    $sekolah    = $data['sekolah'] ?? [];
    $tipe       = ($data['tipe'] ?? 'afektif') === 'psikomotorik' ? 'psikomotorik' : 'afektif';

    // Ambil Data Profil Sekolah Resmi dari Database
    $namaSekolah   = htmlspecialchars($sekolah['nama_sekolah'] ?? 'SMA PLUS AL-HIKMAH BOJONEGORO', ENT_QUOTES, 'UTF-8');
    $alamatSekolah = htmlspecialchars($sekolah['alamat_sekolah'] ?? 'Jl. Raya Bojonegoro, Jawa Timur', ENT_QUOTES, 'UTF-8');
    $npsnSekolah   = !empty($sekolah['npsn']) ? 'NPSN: ' . htmlspecialchars($sekolah['npsn'], ENT_QUOTES, 'UTF-8') : '';
    $titimangsa    = !empty($sekolah['titimangsa_tempat']) ? htmlspecialchars($sekolah['titimangsa_tempat'], ENT_QUOTES, 'UTF-8') : 'Bojonegoro';

    // Logo Sekolah
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

    // TTD & Stempel Resmi Kepala Sekolah dari Database
    $ttdKepsekB64 = !empty($sekolah['ttd_kepala_sekolah']) ? ilmuna_file_to_b64($sekolah['ttd_kepala_sekolah']) : '';
    $stempelB64   = !empty($sekolah['cap_stempel_path']) ? ilmuna_file_to_b64($sekolah['cap_stempel_path']) : '';
    $namaKepsek   = htmlspecialchars($sekolah['nama_kepala_sekolah'] ?? 'Dr. H. Muhammad Arifin, M.Pd.', ENT_QUOTES, 'UTF-8');
    $nipKepsek    = htmlspecialchars($sekolah['nip_kepala_sekolah'] ?? '197503122000031002', ENT_QUOTES, 'UTF-8');

    $judulRanah = ($tipe === 'afektif') ? 'LEMBAR OBSERVASI SIKAP & KARAKTER (AFEKTIF)' : 'LEMBAR PENILAIAN UNJUK KERJA & PRAKTIK (PSIKOMOTORIK)';
    $judulAsesmen = htmlspecialchars($asesmen['judul_asesmen'] ?? 'Instrumen Penilaian', ENT_QUOTES, 'UTF-8');
    $namaKelas = htmlspecialchars($asesmen['nama_kelas'] ?? '-', ENT_QUOTES, 'UTF-8');
    $namaMapel = htmlspecialchars($asesmen['nama_mata_pelajaran'] ?? '-', ENT_QUOTES, 'UTF-8');

    // Guru Pengampu Resmi yang Sah dari Database
    $guruPengampu = htmlspecialchars($asesmen['nama_guru'] ?? ($data['guru_nama'] ?? 'Guru Pengampu'), ENT_QUOTES, 'UTF-8');
    $nipGuru      = htmlspecialchars($asesmen['nip_guru'] ?? ($data['guru_nip'] ?? '-'), ENT_QUOTES, 'UTF-8');

    $pertemuanKe = (int)($asesmen['pertemuan_ke'] ?? 1);
    $teknik = htmlspecialchars($asesmen['teknik_asesmen'] ?? ($tipe === 'afektif' ? 'Observasi' : 'Praktik'), ENT_QUOTES, 'UTF-8');
    $kktp = (float)($asesmen['kktp'] ?? 75.00);
    $tglPelaksanaan = ilmuna_indo_date_full($asesmen['tanggal_pelaksanaan'] ?? date('Y-m-d'));

    ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Lembar Penilaian - <?= $namaKelas ?> - <?= $namaMapel ?></title>
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
            font-family: 'Segoe UI', Tahoma, Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #1E293B;
            background: #FFFFFF;
            margin: 0;
            padding: 0;
            line-height: 1.35;
        }
        .header-kop {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2.5px solid #0D7A87;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .kop-logo {
            width: 60px;
            height: 60px;
            object-fit: contain;
        }
        .kop-text {
            text-align: center;
            flex: 1;
            padding: 0 15px;
        }
        .kop-text h2 {
            margin: 0 0 2px 0;
            font-size: 16px;
            font-weight: 800;
            color: #0F172A;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .kop-text h3 {
            margin: 0 0 3px 0;
            font-size: 13px;
            font-weight: 700;
            color: #0D7A87;
            letter-spacing: 0.3px;
        }
        .kop-text p {
            margin: 0;
            font-size: 10px;
            color: #64748B;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 20px;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 6px;
            padding: 8px 14px;
            margin-bottom: 12px;
            font-size: 10.5px;
        }
        .meta-row {
            display: flex;
        }
        .meta-label {
            width: 140px;
            font-weight: 600;
            color: #475569;
        }
        .meta-val {
            flex: 1;
            font-weight: 700;
            color: #0F172A;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            margin-bottom: 15px;
        }
        table.data-table th, table.data-table td {
            border: 1px solid #CBD5E1;
            padding: 5px 6px;
            vertical-align: middle;
        }
        table.data-table thead th {
            background-color: #0D7A87;
            color: #FFFFFF;
            font-weight: 700;
            text-align: center;
            font-size: 10px;
        }
        table.data-table thead th.sub-th {
            background-color: #14B8A6;
            color: #FFFFFF;
            font-size: 9px;
            padding: 4px;
        }
        table.data-table tbody tr:nth-child(even) {
            background-color: #F8FAFC;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 700;
            text-align: center;
        }
        .badge-sb { background: #DCFCE7; color: #15803D; }
        .badge-b  { background: #E0F2FE; color: #0369A1; }
        .badge-c  { background: #FEF3C7; color: #B45309; }
        .badge-k  { background: #FEE2E2; color: #B91C1C; }
        .badge-tuntas { background: #D1FAE5; color: #065F46; }
        .badge-belum  { background: #FEE2E2; color: #991B1B; }

        .signature-wrap {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            font-size: 10.5px;
            page-break-inside: avoid;
        }
        .signature-box {
            width: 250px;
            text-align: center;
        }
        .signature-space {
            height: 50px;
        }
        .signature-name {
            font-weight: 700;
            text-decoration: underline;
            color: #0F172A;
        }
    </style>
</head>
<body>

    <!-- KOP RESMI LEMBAR PENILAIAN -->
    <div class="header-kop">
        <?php if ($logoBase64): ?>
            <img src="<?= $logoBase64 ?>" class="kop-logo" alt="Logo">
        <?php else: ?>
            <div style="width: 60px;"></div>
        <?php endif; ?>
        <div class="kop-text">
            <h2><?= $namaSekolah ?></h2>
            <h3><?= $judulRanah ?></h3>
            <p><?= $alamatSekolah ?><?= $npsnSekolah ? ' &bull; ' . $npsnSekolah : '' ?></p>
        </div>
        <div style="width: 60px; text-align: right; font-size: 9px; color: #64748B;">
            <strong>ILMUNA</strong><br>Assessment
        </div>
    </div>

    <!-- METADATA ASESMEN -->
    <div class="meta-grid">
        <div class="meta-row">
            <span class="meta-label">Rombel / Kelas:</span>
            <span class="meta-val"><?= $namaKelas ?></span>
        </div>
        <div class="meta-row">
            <span class="meta-label">Judul Asesmen:</span>
            <span class="meta-val"><?= $judulAsesmen ?></span>
        </div>
        <div class="meta-row">
            <span class="meta-label">Mata Pelajaran:</span>
            <span class="meta-val"><?= $namaMapel ?></span>
        </div>
        <div class="meta-row">
            <span class="meta-label">Pertemuan Ke / Teknik:</span>
            <span class="meta-val">Pertemuan Ke-<?= $pertemuanKe ?> (<?= $teknik ?>)</span>
        </div>
        <div class="meta-row">
            <span class="meta-label">Guru Mata Pelajaran:</span>
            <span class="meta-val"><?= $guruPengampu ?><?= ($nipGuru !== '-' && !empty($nipGuru)) ? ' (NIP. ' . $nipGuru . ')' : '' ?></span>
        </div>
        <div class="meta-row">
            <span class="meta-label">Tanggal / KKTP:</span>
            <span class="meta-val"><?= $tglPelaksanaan ?> &bull; KKTP: <?= number_format($kktp, 0) ?></span>
        </div>
    </div>

    <!-- TABEL REKAPITULASI HASIL OBSERVASI / KINERJA SISWA -->
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 28px;" rowspan="2">No</th>
                <th style="width: 75px;" rowspan="2">NIS / NISN</th>
                <th style="width: 170px;" rowspan="2">Nama Peserta Didik</th>
                <th colspan="<?= max(1, count($items)) ?>">
                    <?= ($tipe === 'afektif') ? 'Indikator Observasi Sikap & Karakter (Skor 1-4)' : 'Rubrik Kinerja Unjuk Kerja / Praktik (Skor 1-4)' ?>
                </th>
                <th style="width: 65px;" rowspan="2">Nilai Akhir</th>
                <th style="width: 50px;" rowspan="2">Predikat</th>
                <th style="width: 75px;" rowspan="2">Status</th>
                <th rowspan="2">Catatan Perkembangan Karakter / Kinerja</th>
            </tr>
            <tr>
                <?php if (!empty($items)): ?>
                    <?php foreach ($items as $idx => $it): 
                        $aspekName = htmlspecialchars($it['aspek_sikap'] ?? $it['tahap_keterampilan'] ?? ('Butir ' . ($idx + 1)), ENT_QUOTES, 'UTF-8');
                    ?>
                        <th class="sub-th" title="<?= $aspekName ?>">
                            <?= (mb_strlen($aspekName) > 20) ? mb_substr($aspekName, 0, 18) . '...' : $aspekName ?>
                        </th>
                    <?php endforeach; ?>
                <?php else: ?>
                    <th class="sub-th">-</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($students)): ?>
                <?php foreach ($students as $no => $s): 
                    $nilaiAngka = isset($s['nilai_angka']) && $s['nilai_angka'] !== null ? (float)$s['nilai_angka'] : null;
                    $pred = $s['predikat'] ?? '-';
                    $status = $s['status_tuntas'] ?? 'Belum Dinilai';
                    $catatan = htmlspecialchars($s['catatan'] ?? '-', ENT_QUOTES, 'UTF-8');

                    $predClass = 'badge-b';
                    if ($pred === 'SB' || $pred === 'A') $predClass = 'badge-sb';
                    elseif ($pred === 'C') $predClass = 'badge-c';
                    elseif ($pred === 'K' || $pred === 'D' || $pred === 'E') $predClass = 'badge-k';

                    $statusClass = ($status === 'Tuntas') ? 'badge-tuntas' : 'badge-belum';
                ?>
                    <tr>
                        <td class="text-center"><?= $no + 1 ?></td>
                        <td class="text-center"><?= htmlspecialchars($s['nis'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><strong><?= htmlspecialchars($s['nama_lengkap'] ?? '-', ENT_QUOTES, 'UTF-8') ?></strong></td>
                        
                        <!-- Skor Per Butir Rubrik Observasi / Praktik -->
                        <?php if (!empty($items)): ?>
                            <?php foreach ($items as $it): 
                                if ($nilaiAngka !== null && $nilaiAngka > 0) {
                                    $skorVal = ($s['skor_rata_skala'] !== null && (float)$s['skor_rata_skala'] > 0)
                                        ? (float)$s['skor_rata_skala']
                                        : round($nilaiAngka / 25, 1);
                                    $displaySkala = number_format($skorVal, 1);
                                    if (substr($displaySkala, -2) === '.0') {
                                        $displaySkala = substr($displaySkala, 0, -2);
                                    }
                                } else {
                                    $displaySkala = '-';
                                }
                            ?>
                                <td class="text-center" style="font-weight: 600;"><?= $displaySkala ?></td>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <td class="text-center">-</td>
                        <?php endif; ?>

                        <td class="text-center" style="font-weight: 800; font-size: 11px;">
                            <?= ($nilaiAngka !== null && $nilaiAngka > 0) ? number_format($nilaiAngka, 1) : '<span style="color:#CBD5E1;">-</span>' ?>
                        </td>
                        <td class="text-center">
                            <?php if ($pred && $pred !== '-' && $nilaiAngka !== null && $nilaiAngka > 0): ?>
                                <span class="badge <?= $predClass ?>"><?= $pred ?></span>
                            <?php else: ?>
                                <span style="color:#CBD5E1;">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($status && $status !== 'Belum Dinilai' && $nilaiAngka !== null && $nilaiAngka > 0): ?>
                                <span class="badge <?= $statusClass ?>"><?= $status ?></span>
                            <?php else: ?>
                                <span style="color: #94A3B8; font-size: 9px; font-weight: 600;">Belum Dinilai</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size: 9.5px;"><?= $catatan ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="<?= 7 + max(1, count($items)) ?>" class="text-center" style="padding: 15px; color: #94A3B8;">
                        Tidak ada data siswa untuk rombel kelas ini.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- LEMBAR TANDA TANGAN RESMI -->
    <div class="signature-wrap">
        <div class="signature-box">
            <span>Mengetahui,</span><br>
            <strong>Kepala Satuan Pendidikan</strong>
            <div class="signature-space" style="position: relative; display: flex; align-items: center; justify-content: center;">
                <?php if ($stempelB64): ?>
                    <img src="<?= $stempelB64 ?>" alt="Stempel" style="position: absolute; left: 15px; top: 0; height: 50px; opacity: 0.85; pointer-events: none;">
                <?php endif; ?>
                <?php if ($ttdKepsekB64): ?>
                    <img src="<?= $ttdKepsekB64 ?>" alt="TTD Kepsek" style="max-height: 48px; max-width: 140px; position: relative; z-index: 2;">
                <?php endif; ?>
            </div>
            <div class="signature-name"><?= $namaKepsek ?></div>
            <span style="font-size: 9.5px; color: #64748B;">NIP. <?= $nipKepsek ?></span>
        </div>
        <div class="signature-box">
            <span><?= $titimangsa ?>, <?= $tglPelaksanaan ?></span><br>
            <strong>Guru Mata Pelajaran</strong>
            <div class="signature-space"></div>
            <div class="signature-name"><?= $guruPengampu ?></div>
            <span style="font-size: 9.5px; color: #64748B;"><?= ($nipGuru !== '-' && !empty($nipGuru)) ? 'NIP. ' . $nipGuru : 'Guru Pengampu Mata Pelajaran' ?></span>
        </div>
    </div>

</body>
</html>
<?php
    return ob_get_clean();
}

/**
 * 4. Generate Berkas PDF Menggunakan Headless Chromium (Edge / Chrome)
 *
 * @param array $data Data Asesmen, Siswa, dan Nilai
 * @param string|null $targetPdfPath Jalur target berkas PDF
 * @return array ['ok' => bool, 'path' => string, 'error' => string|null]
 */
function ilmuna_generate_penilaian_asesmen_pdf(array $data, $targetPdfPath = null) {
    if (empty($data['asesmen'])) {
        return ['ok' => false, 'error' => 'Data asesmen tidak ditemukan.'];
    }

    $bin = ilmuna_get_headless_browser_bin();
    if (!$bin) {
        return ['ok' => false, 'error' => 'Headless browser (Microsoft Edge / Google Chrome) tidak ditemukan di server.'];
    }

    $uploadDir = BASE_PATH . '/uploads/penilaian_pdf';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    if (!is_writable($uploadDir)) {
        return ['ok' => false, 'error' => 'Direktori penyimpanan PDF tidak dapat ditulis: ' . $uploadDir];
    }

    $namaKelas = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $data['asesmen']['nama_kelas'] ?? 'Kelas');
    $namaMapel = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $data['asesmen']['nama_mata_pelajaran'] ?? 'Mapel');
    $tipe = ($data['tipe'] ?? 'afektif') === 'psikomotorik' ? 'PSIKOMOTORIK' : 'AFEKTIF';

    if (empty($targetPdfPath)) {
        $unique = 'Lembar_Penilaian_' . $tipe . '_' . $namaKelas . '_' . $namaMapel . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $targetPdfPath = $uploadDir . '/' . $unique . '.pdf';
    }

    $uniqueTmp = 'tmp_penilaian_' . bin2hex(random_bytes(6));
    $htmlTmp = $uploadDir . '/' . $uniqueTmp . '.html';

    $htmlContent = ilmuna_render_penilaian_asesmen_pdf_html($data);
    if (!file_put_contents($htmlTmp, $htmlContent)) {
        return ['ok' => false, 'error' => 'Gagal menulis berkas HTML sementara ke: ' . $htmlTmp];
    }

    $fileUri = 'file:///' . str_replace('\\', '/', $htmlTmp);
    $userDataDir = sys_get_temp_dir() . '/edge_penilaian_pdf_' . uniqid();
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
        'error' => 'Headless browser gagal mengenerate PDF. Exit code: ' . $ret . '. Log: ' . mb_substr($outStr, 0, 300),
    ];
}

