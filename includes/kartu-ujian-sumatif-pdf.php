<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — GENERATOR PDF KARTU PESERTA & JADWAL ASESMEN SUMATIF
 * ==============================================================================
 * Format Kertas: A4 Portrait (210 x 297 mm).
 * Kapasitas Kertas: 1 Lembar A4 memuat tepat 3 buah Kartu Kompak (Atas, Tengah, Bawah)
 *                  dengan tinggi yang pas tanpa membuang space kosong.
 * Desain Kompak Sesuai Permintaan:
 *   - Tinggi kartu berakhir pas di bagian bawah foto, QR Code ("SCAN ABSENSI"),
 *     dan kolom Tanda Tangan Pengawas yang diletakkan langsung di samping kanan QR Code.
 *   - Logo Resmi Ilmuna diletakkan di celah pemisah bagian atas antara kolom Detail
 *     dan kolom Jadwal.
 *   - Kolom Kanan: Tabel Jadwal Asesmen Sumatif sesuai kelas (Hari/Tgl grouped rowspan,
 *     Jam Sesi, Mata Pelajaran & Judul Asesmen, Paraf Pengawas).
 * QR Code: 3-Color Gradient Lumina (Navy #1D3B5E, Teal #0D7A87, Sky Blue #52B2DC)
 *          dengan White Logo Ilmuna di tengah (Format data: Nomor Peserta Ujian).
 * Engine: Headless Browser (Edge / Chrome) dengan rendering presisi tinggi.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/includes/qrcode_engine.php';
if (file_exists(BASE_PATH . '/database.php')) {
    require_once BASE_PATH . '/database.php';
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
 * 2. Helper Nama Hari dan Format Tanggal Bahasa Indonesia
 */
if (!function_exists('ilmuna_indo_day')) {
    function ilmuna_indo_day($dateStr) {
        if (!$dateStr || $dateStr === '-') return 'Senin';
        $days = [
            'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
        ];
        $ts = strtotime($dateStr);
        if (!$ts) return 'Senin';
        return $days[date('l', $ts)] ?? 'Senin';
    }
}

if (!function_exists('ilmuna_indo_date_short')) {
    function ilmuna_indo_date_short($dateStr) {
        if (!$dateStr || $dateStr === '-') return '-';
        $months = [1=>'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
        $ts = strtotime($dateStr);
        if (!$ts) return $dateStr;
        $m = (int)date('m', $ts);
        return date('d', $ts) . ' ' . ($months[$m] ?? date('M', $ts)) . ' ' . date('Y', $ts);
    }
}

/**
 * 3. Dapatkan thumbnail logo putih transparan (180x180 px) untuk tengah QR Code
 *
 * @return string Data URI (data:image/png;base64,...)
 */
function ilmuna_get_sumatif_qr_white_logo_b64() {
    static $cachedB64 = null;
    if ($cachedB64 !== null) {
        return $cachedB64;
    }

    $srcPath = BASE_PATH . '/assets/images/white logo.png';
    $thumbPath = BASE_PATH . '/assets/images/white_logo_qr_thumb.png';

    if (!file_exists($srcPath)) {
        $fallback = BASE_PATH . '/assets/images/logo.png';
        if (file_exists($fallback)) {
            $cachedB64 = 'data:image/png;base64,' . base64_encode(file_get_contents($fallback));
            return $cachedB64;
        }
        return '';
    }

    if (!file_exists($thumbPath) || filemtime($thumbPath) < filemtime($srcPath)) {
        if (extension_loaded('gd')) {
            $src = @imagecreatefrompng($srcPath);
            if ($src) {
                $sw = imagesx($src);
                $sh = imagesy($src);
                $dim = 180;
                $dst = imagecreatetruecolor($dim, $dim);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                imagefill($dst, 0, 0, $trans);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $dim, $dim, $sw, $sh);
                @imagepng($dst, $thumbPath, 8);
                imagedestroy($src);
                imagedestroy($dst);
            }
        }
    }

    $fileToRead = file_exists($thumbPath) ? $thumbPath : $srcPath;
    $cachedB64 = 'data:image/png;base64,' . base64_encode(file_get_contents($fileToRead));
    return $cachedB64;
}

/**
 * 4. Generate QR Code SVG dengan Tema Gradient 3 Warna (Navy, Teal, Sky Blue) + Logo Putih di Tengah
 *
 * @param string $nomorPeserta Nomor Peserta Ujian
 * @return string SVG QR Code siap pakai
 */
function ilmuna_generate_sumatif_qr_svg($nomorPeserta) {
    $text = trim((string)$nomorPeserta);
    if ($text === '') {
        $text = 'KARTU-UJIAN';
    }

    $qr = new QRCode($text, ['s' => 'qrh']);
    $ref = new ReflectionClass($qr);
    $method = $ref->getMethod('dispatch_encode');
    $method->setAccessible(true);
    $code = $method->invoke($qr, $text, ['s' => 'qrh']);

    $matrix = $code['b'];
    $matrixSize = count($matrix);
    $margin = 3;
    $totalSize = $matrixSize + ($margin * 2);

    $center = $matrixSize / 2;
    $logoRadius = ceil($matrixSize * 0.12);
    $logoMin = $center - $logoRadius;
    $logoMax = $center + $logoRadius;

    $uniqId = 'sumqr_' . substr(md5($text . microtime()), 0, 8);

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $totalSize . ' ' . $totalSize . '" width="100%" height="100%" shape-rendering="geometricPrecision">' . "\n";
    $svg .= '<defs>' . "\n";
    $svg .= '  <linearGradient id="' . $uniqId . '" x1="0%" y1="0%" x2="100%" y2="100%">' . "\n";
    $svg .= '    <stop offset="0%" stop-color="#1D3B5E" />' . "\n";   // Biru Tua
    $svg .= '    <stop offset="50%" stop-color="#0D7A87" />' . "\n";  // Teal
    $svg .= '    <stop offset="100%" stop-color="#52B2DC" />' . "\n"; // Sky Blue
    $svg .= '  </linearGradient>' . "\n";
    $svg .= '</defs>' . "\n";

    $svg .= '<rect width="' . $totalSize . '" height="' . $totalSize . '" fill="#FFFFFF" rx="2" />' . "\n";

    $path = '';
    for ($r = 0; $r < $matrixSize; $r++) {
        for ($c = 0; $c < $matrixSize; $c++) {
            if (!empty($matrix[$r][$c])) {
                if ($r >= $logoMin && $r <= $logoMax && $c >= $logoMin && $c <= $logoMax) {
                    continue;
                }
                $x = $c + $margin;
                $y = $r + $margin;
                $path .= "M {$x} {$y} h 1 v 1 h -1 Z ";
            }
        }
    }
    $svg .= '<path d="' . trim($path) . '" fill="url(#' . $uniqId . ')" />' . "\n";

    $logoB64 = ilmuna_get_sumatif_qr_white_logo_b64();
    if (!empty($logoB64)) {
        $cx = $totalSize / 2;
        $cy = $totalSize / 2;
        $badgeSize = ($logoRadius * 2 + 1);
        $badgeX = $cx - ($badgeSize / 2);
        $badgeY = $cy - ($badgeSize / 2);
        $badgeR = $badgeSize * 0.22;

        $svg .= '<rect x="' . $badgeX . '" y="' . $badgeY . '" width="' . $badgeSize . '" height="' . $badgeSize . '" rx="' . $badgeR . '" fill="#1D3B5E" stroke="#52B2DC" stroke-width="0.35" />' . "\n";

        $pad = $badgeSize * 0.15;
        $imgX = $badgeX + $pad;
        $imgY = $badgeY + $pad;
        $imgSize = $badgeSize - ($pad * 2);
        $svg .= '<image href="' . $logoB64 . '" x="' . $imgX . '" y="' . $imgY . '" width="' . $imgSize . '" height="' . $imgSize . '" preserveAspectRatio="xMidYMid meet" />' . "\n";
    }

    $svg .= '</svg>';
    return $svg;
}

/**
 * 5. Dapatkan Pas Foto 3x4 Siswa dari data siswa
 *
 * Mengambil foto dari data siswa (kolom foto), memeriksa direktori penyimpanan,
 * dan apabila berkas fisik belum diunggah, menghasilkan Pas Foto 3x4 Resmi Pelajar
 * berlatar belakang biru formal (standar madrasah / ujian nasional) dengan inisial nama siswa.
 *
 * @param array $student Data siswa
 * @return string Data URI (image/jpeg, image/png, atau image/svg+xml)
 */
function ilmuna_get_student_photo_b64(array $student) {
    $rawFoto = trim((string)($student['foto'] ?? ''));
    if ($rawFoto !== '') {
        if (strpos($rawFoto, 'data:image') === 0) {
            return $rawFoto;
        }
        $candidates = [
            BASE_PATH . '/' . ltrim($rawFoto, '/'),
            BASE_PATH . '/uploads/' . ltrim($rawFoto, '/'),
            BASE_PATH . '/uploads/students/' . ltrim($rawFoto, '/'),
            BASE_PATH . '/uploads/profiles/' . ltrim($rawFoto, '/'),
            BASE_PATH . '/assets/images/' . ltrim($rawFoto, '/')
        ];
        foreach ($candidates as $cand) {
            if (file_exists($cand) && is_file($cand)) {
                $ext = strtolower(pathinfo($cand, PATHINFO_EXTENSION));
                $mime = 'image/jpeg';
                if ($ext === 'png') $mime = 'image/png';
                elseif ($ext === 'webp') $mime = 'image/webp';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($cand));
            }
        }
    }

    // Default: Pas Foto Resmi Pelajar berlatar biru formal (standar ujian nasional/madrasah)
    $nama = trim((string)($student['nama_siswa'] ?? ($student['nama_lengkap'] ?? 'Siswa')));
    $words = preg_split('/\s+/', $nama);
    $initials = '';
    if (count($words) >= 2) {
        $initials = mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1);
    } else {
        $initials = mb_substr($nama, 0, 2);
    }
    $initials = strtoupper($initials);

    // SVG Pas Foto 3x4 (proporsi 3:4, e.g. 150x200 px)
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 150 200" width="100%" height="100%">'
         . '<defs>'
         . '  <linearGradient id="bgBlue" x1="0%" y1="0%" x2="0%" y2="100%">'
         . '    <stop offset="0%" stop-color="#1E40AF" />'
         . '    <stop offset="100%" stop-color="#172554" />'
         . '  </linearGradient>'
         . '</defs>'
         . '<rect width="150" height="200" fill="url(#bgBlue)" />'
         . '<ellipse cx="75" cy="205" rx="55" ry="42" fill="#F8FAFC" />'
         . '<path d="M 60 165 L 75 192 L 90 165 Z" fill="#94A3B8" />'
         . '<path d="M 67 165 L 75 180 L 83 165 Z" fill="#1E3A8A" />'
         . '<rect x="67" y="144" width="16" height="22" rx="3" fill="#FBBF24" opacity="0.85" />'
         . '<circle cx="75" cy="116" r="28" fill="#FDE68A" />'
         . '<path d="M 47 116 C 47 82, 103 82, 103 116 C 94 102, 56 102, 47 116 Z" fill="#1E293B" />'
         . '<circle cx="75" cy="116" r="20" fill="#1E3A8A" opacity="0.92" />'
         . '<text x="75" y="123" font-family="-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif" font-size="16" font-weight="900" fill="#FFFFFF" text-anchor="middle">' . htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') . '</text>'
         . '<rect x="0" y="180" width="150" height="20" fill="rgba(15, 23, 42, 0.78)" />'
         . '<text x="75" y="194" font-family="-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif" font-size="8.5" font-weight="800" fill="#FFFFFF" letter-spacing="0.8" text-anchor="middle">PAS FOTO 3X4</text>'
         . '</svg>';

    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

/**
 * 5b. Pengelompokan Data Kartu Berdasarkan Siswa (1 Nama = 1 Kartu Ujian Saja)
 *
 * Mengelompokkan data kartu agar setiap peserta didik hanya memiliki tepat 1 lembar kartu ujian.
 * Apabila peserta didik memiliki lebih dari 1 paket asesmen sumatif (misal STS, SAS, atau asesmen beberapa mapel),
 * seluruh asesmen tersebut secara otomatis digabungkan dan ditata di tabel jadwal ujian pada kartu siswa tersebut.
 *
 * @param array $cardsData Daftar baris data kartu asesmen
 * @return array Daftar kartu unik per siswa dengan jadwal lengkap teragregasi
 */
function ilmuna_group_sumatif_cards_by_student(array $cardsData) {
    if (empty($cardsData)) {
        return [];
    }

    $grouped = [];

    foreach ($cardsData as $item) {
        $sId  = !empty($item['id_siswa']) ? (int)$item['id_siswa'] : 0;
        $nis  = trim((string)($item['nis'] ?? ''));
        $np   = trim((string)($item['nomor_peserta'] ?? ''));
        $nama = trim((string)($item['nama_siswa'] ?? ''));
        $kls  = trim((string)($item['nama_kelas'] ?? ''));

        // Prioritas penentuan identitas unik siswa
        if ($sId > 0) {
            $key = 's_' . $sId;
        } elseif ($nis !== '' && $nis !== '-') {
            $key = 'nis_' . $nis;
        } elseif ($np !== '' && $np !== '-') {
            $key = 'np_' . $np;
        } else {
            $key = 'nama_' . mb_strtolower($nama) . '_' . mb_strtolower($kls);
        }

        if (!isset($grouped[$key])) {
            $grouped[$key] = $item;
            $grouped[$key]['jadwal'] = [];
            $grouped[$key]['_jadwal_keys'] = [];
        } else {
            // Lengkapi data identitas / kredensial jika data di baris lain lebih lengkap
            if ((empty($grouped[$key]['nomor_peserta']) || $grouped[$key]['nomor_peserta'] === '-') && !empty($item['nomor_peserta']) && $item['nomor_peserta'] !== '-') {
                $grouped[$key]['nomor_peserta'] = $item['nomor_peserta'];
            }
            if ((empty($grouped[$key]['username_ujian']) || $grouped[$key]['username_ujian'] === '-') && !empty($item['username_ujian']) && $item['username_ujian'] !== '-') {
                $grouped[$key]['username_ujian'] = $item['username_ujian'];
            }
            if ((empty($grouped[$key]['password_ujian']) || $grouped[$key]['password_ujian'] === '-') && !empty($item['password_ujian']) && $item['password_ujian'] !== '-') {
                $grouped[$key]['password_ujian'] = $item['password_ujian'];
            }
            if (empty($grouped[$key]['foto']) && !empty($item['foto'])) {
                $grouped[$key]['foto'] = $item['foto'];
            }
            if ((empty($grouped[$key]['nis']) || $grouped[$key]['nis'] === '-') && !empty($item['nis']) && $item['nis'] !== '-') {
                $grouped[$key]['nis'] = $item['nis'];
            }
            if ((empty($grouped[$key]['nisn']) || $grouped[$key]['nisn'] === '-') && !empty($item['nisn']) && $item['nisn'] !== '-') {
                $grouped[$key]['nisn'] = $item['nisn'];
            }
        }

        // 1. Masukkan jadwal dari array 'jadwal' jika sudah terlampir
        if (!empty($item['jadwal']) && is_array($item['jadwal'])) {
            foreach ($item['jadwal'] as $j) {
                $jTgl   = !empty($j['tanggal_pelaksanaan']) ? $j['tanggal_pelaksanaan'] : date('Y-m-d');
                $jJamM  = !empty($j['jam_mulai']) ? substr($j['jam_mulai'], 0, 5) : '07:30';
                $jJamS  = !empty($j['jam_selesai']) ? substr($j['jam_selesai'], 0, 5) : '09:00';
                $jJudul = trim((string)($j['judul_asesmen'] ?? 'Asesmen Sumatif'));
                $jMapel = trim((string)($j['nama_mata_pelajaran'] ?? 'Mata Pelajaran'));
                $jId    = (string)($j['id'] ?? '');

                $jKey = ($jId !== '' ? $jId . '_' : '') . $jTgl . '_' . $jJamM . '_' . mb_strtolower($jJudul) . '_' . mb_strtolower($jMapel);

                if (!isset($grouped[$key]['_jadwal_keys'][$jKey])) {
                    $grouped[$key]['_jadwal_keys'][$jKey] = true;
                    $grouped[$key]['jadwal'][] = [
                        'id'                  => $j['id'] ?? 0,
                        'tanggal_pelaksanaan' => $jTgl,
                        'jam_mulai'           => $jJamM,
                        'jam_selesai'         => $jJamS,
                        'durasi_menit'        => $j['durasi_menit'] ?? 60,
                        'nama_mata_pelajaran' => $jMapel,
                        'judul_asesmen'       => $jJudul
                    ];
                }
            }
        }

        // 2. Masukkan juga asesmen spesifik dari baris item saat ini ke dalam jadwal
        if (!empty($item['judul_asesmen'])) {
            $itemTgl   = !empty($item['tanggal_pelaksanaan']) ? $item['tanggal_pelaksanaan'] : date('Y-m-d');
            $itemJamM  = !empty($item['jam_mulai']) ? substr($item['jam_mulai'], 0, 5) : '07:30';
            $itemJamS  = !empty($item['jam_selesai']) ? substr($item['jam_selesai'], 0, 5) : '09:00';
            $itemJudul = trim((string)$item['judul_asesmen']);
            $itemMapel = trim((string)($item['nama_mata_pelajaran'] ?? 'Mata Pelajaran'));
            $itemId    = (string)($item['id_asesmen'] ?? ($item['id_asesmen_sumatif'] ?? ''));

            $itemKey = ($itemId !== '' ? $itemId . '_' : '') . $itemTgl . '_' . $itemJamM . '_' . mb_strtolower($itemJudul) . '_' . mb_strtolower($itemMapel);

            if (!isset($grouped[$key]['_jadwal_keys'][$itemKey])) {
                $grouped[$key]['_jadwal_keys'][$itemKey] = true;
                $grouped[$key]['jadwal'][] = [
                    'id'                  => $item['id_asesmen'] ?? ($item['id_asesmen_sumatif'] ?? 0),
                    'tanggal_pelaksanaan' => $itemTgl,
                    'jam_mulai'           => $itemJamM,
                    'jam_selesai'         => $itemJamS,
                    'durasi_menit'        => $item['durasi_menit'] ?? 60,
                    'nama_mata_pelajaran' => $itemMapel,
                    'judul_asesmen'       => $itemJudul
                ];
            }
        }
    }

    // Urutkan jadwal masing-masing siswa secara kronologis (tanggal & jam mulai)
    $finalCards = [];
    foreach ($grouped as $c) {
        unset($c['_jadwal_keys']);
        if (!empty($c['jadwal'])) {
            usort($c['jadwal'], function($a, $b) {
                $tglA = $a['tanggal_pelaksanaan'] ?? '';
                $tglB = $b['tanggal_pelaksanaan'] ?? '';
                if ($tglA !== $tglB) {
                    return strcmp($tglA, $tglB);
                }
                $jmA = $a['jam_mulai'] ?? '';
                $jmB = $b['jam_mulai'] ?? '';
                return strcmp($jmA, $jmB);
            });
        }
        $finalCards[] = $c;
    }

    return $finalCards;
}

/**
 * 6. Render HTML Kartu Ujian Sumatif Kompak (Muat Tepat 4 Kartu per Lembar Kertas A4)
 *
 * @param array $cardsData Daftar peserta ujian sumatif
 * @return string HTML lengkap siap dicetak via Headless Browser
 */
function ilmuna_render_kartu_ujian_sumatif_a4_pdf_html(array $cardsData) {
    // 1 Nama Siswa HANYA memiliki 1 Kartu Ujian Sumatif.
    // Jika siswa memiliki lebih dari 1 asesmen sumatif, seluruh asesmen dirangkum ke dalam tabel jadwal.
    $cardsData = ilmuna_group_sumatif_cards_by_student($cardsData);

    $c_navy  = '#1D3B5E';
    $c_teal  = '#0D7A87';
    $c_sky   = '#52B2DC';
    $c_gold  = '#F59E0B';

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

    $siteName = !empty($sekolah['nama_singkat']) ? $sekolah['nama_singkat'] : (!empty($sekolah['nama_sekolah']) ? $sekolah['nama_sekolah'] : (defined('APP_NAME') ? APP_NAME : 'Ilmuna Learn'));
    
    // Cari logo sekolah resmi
    $logoSrc = '';
    if (!empty($sekolah['logo_sekolah'])) {
        $candFiles = [
            BASE_PATH . '/' . ltrim($sekolah['logo_sekolah'], '/\\'),
            BASE_PATH . '/uploads/' . ltrim($sekolah['logo_sekolah'], '/\\'),
            BASE_PATH . '/uploads/school/' . ltrim($sekolah['logo_sekolah'], '/\\'),
            $sekolah['logo_sekolah']
        ];
        foreach ($candFiles as $cf) {
            if (file_exists($cf) && is_file($cf)) {
                $ext = strtolower(pathinfo($cf, PATHINFO_EXTENSION));
                $mime = 'image/png';
                if (in_array($ext, ['jpg', 'jpeg'])) $mime = 'image/jpeg';
                elseif ($ext === 'svg') $mime = 'image/svg+xml';
                $logoSrc = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($cf));
                break;
            }
        }
    }
    if (!$logoSrc) {
        $logoFile = BASE_PATH . '/assets/images/logo.png';
        if (file_exists($logoFile)) {
            $logoSrc = 'data:image/png;base64,' . base64_encode(file_get_contents($logoFile));
        }
    }

    // 1 Lembar Kertas A4 (297 mm) memuat tepat 4 buah Kartu Kompak (tinggi ~67.5mm)
    $chunks = array_chunk($cardsData, 4);

    ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kartu Peserta & Jadwal Ujian Sumatif — <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 0;
        }
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        html, body {
            width: 210mm;
            background-color: #FFFFFF;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #1E293B;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* LEMBAR A4 (MEMUAT TEPAT 4 KARTU KOMPAK DENGAN TINGGI PRESISI) */
        .a4-sheet {
            width: 210mm;
            height: 297mm;
            max-height: 297mm;
            padding: 4.5mm 6mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            page-break-after: always;
            position: relative;
            overflow: hidden;
            background: #FFFFFF;
        }
        .a4-sheet:last-child {
            page-break-after: avoid;
        }

        /* GARIS PEMOTONGAN ANTAR KARTU */
        .sheet-divider {
            height: 3.2mm;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            margin: 0;
        }
        .sheet-divider__line {
            position: absolute;
            left: 0;
            right: 0;
            top: 50%;
            height: 1px;
            border-top: 1.1px dashed #94A3B8;
        }
        .sheet-divider__badge {
            position: relative;
            background: #FFFFFF;
            padding: 0.5px 8px;
            font-size: 5.6pt;
            font-weight: 800;
            color: #64748B;
            border: 1px solid #CBD5E1;
            border-radius: 999px;
            letter-spacing: 0.6px;
            text-transform: uppercase;
        }

        /* KARTU KOMPAK SUMATIF (LEBAR PENUH A4, TINGGI TEPAT ~67.5mm) */
        .card-compact {
            width: 100%;
            height: 67.5mm;
            max-height: 67.5mm;
            border: 1.2px solid #CBD5E1;
            border-top: 3.2px solid <?= $c_teal ?>;
            border-radius: 7px;
            background: #FFFFFF;
            padding: 1.8mm 3.2mm 1.6mm 3.2mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        /* HEADER KARTU DENGAN LOGO DI CELAH TENGAH */
        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            padding-bottom: 1.2mm;
            border-bottom: 1px solid #E2E8F0;
            min-height: 8mm;
        }
        .card-head__left {
            width: 46%;
            min-width: 0;
        }
        .card-head__right {
            width: 48%;
            min-width: 0;
            text-align: right;
        }
        .card-head__badge-row {
            display: flex;
            align-items: center;
            gap: 1.5mm;
        }
        .card-head__badge {
            font-size: 5.2pt;
            font-weight: 800;
            color: <?= $c_teal ?>;
            background: #F0FDFA;
            border: 1px solid #99F6E4;
            padding: 0.5px 3.5px;
            border-radius: 3px;
            letter-spacing: 0.2px;
            text-transform: uppercase;
        }
        .card-head__title {
            font-size: 7.6pt;
            font-weight: 800;
            color: <?= $c_navy ?>;
            letter-spacing: -0.2px;
            line-height: 1.1;
        }
        .card-head__sub {
            font-size: 5.5pt;
            color: #475569;
            font-weight: 600;
        }

        /* LOGO TEPAT DI CELAH ANTARA KOLOM DETAIL DAN JADWAL */
        .card-head__center-logo {
            position: absolute;
            left: 47%;
            top: 50%;
            transform: translate(-50%, -50%);
            width: 8.5mm;
            height: 8.5mm;
            background: #FFFFFF;
            border-radius: 50%;
            border: 1.5px solid <?= $c_sky ?>;
            box-shadow: 0 1px 4px rgba(29, 59, 94, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }
        .card-head__center-logo img {
            width: 6mm;
            height: 6mm;
            object-fit: contain;
            display: block;
        }

        .card-head__app-name {
            font-size: 6.8pt;
            font-weight: 800;
            color: <?= $c_teal ?>;
            letter-spacing: 0.2px;
            text-transform: uppercase;
        }
        .card-head__class-badge {
            display: inline-block;
            font-size: 6pt;
            font-weight: 800;
            color: <?= $c_navy ?>;
            background: #EFF6FF;
            border: 1px solid #BFDBFE;
            padding: 0.5px 4.5px;
            border-radius: 3px;
        }

        /* BODY KARTU: DIBAGI 2 KOLOM (KIRI: DETAIL KARTU, KANAN: TABEL JADWAL) */
        .card-body {
            display: flex;
            gap: 2.5mm;
            flex: 1;
            min-height: 0;
            padding-top: 1.4mm;
        }

        /* ============================================================== */
        /* KOLOM KIRI: DETAIL KARTU (TINGGI BERAKHIR PAS DI SCAN ABSENSI)  */
        /* ============================================================== */
        .col-detail {
            width: 46%;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        /* BAGIAN ATAS: IDENTITAS & KREDENSIAL LOGIN */
        .col-detail__top {
            display: flex;
            flex-direction: column;
            gap: 1mm;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 5.7pt;
            line-height: 1.2;
        }
        .info-table td {
            padding: 0.3px 0;
            vertical-align: top;
        }
        .info-table__lbl {
            width: 17mm;
            color: #64748B;
            font-weight: 600;
        }
        .info-table__sep {
            width: 1.5mm;
            color: #94A3B8;
        }
        .info-table__val {
            color: <?= $c_navy ?>;
            font-weight: 700;
            word-break: break-word;
        }
        .info-table__val--nomor {
            color: <?= $c_teal ?>;
            font-family: 'SFMono-Regular', Consolas, monospace;
            font-size: 6.5pt;
            font-weight: 800;
            letter-spacing: 0.2px;
        }

        /* BOX KREDENSIAL CBT */
        .cred-box {
            background: #F0FDF4;
            border: 1px solid #86EFAC;
            border-left: 2.5px solid <?= $c_teal ?>;
            border-radius: 3.5px;
            padding: 0.8mm 1.8mm;
            display: flex;
            gap: 1.8mm;
        }
        .cred-box__item {
            flex: 1;
            min-width: 0;
        }
        .cred-box__lbl {
            font-size: 4.6pt;
            font-weight: 800;
            color: <?= $c_teal ?>;
            text-transform: uppercase;
            letter-spacing: 0.2px;
            display: block;
        }
        .cred-box__val {
            font-family: 'SFMono-Regular', Consolas, monospace;
            font-size: 6.2pt;
            font-weight: 800;
            color: <?= $c_navy ?>;
            letter-spacing: 0.2px;
            word-break: break-all;
            display: block;
        }

        /* BAGIAN BAWAH: FOTO 3x4 + QR CODE (SCAN ABSENSI) + TTD DI SAMPING KANANNYA */
        .col-detail__bottom {
            display: flex;
            align-items: flex-end;
            gap: 2mm;
            padding-top: 0.8mm;
        }

        /* FOTO 3x4 */
        .photo-box {
            width: 16.5mm;
            height: 22mm;
            border: 1px solid #CBD5E1;
            border-radius: 3.5px;
            background: #F8FAFC;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .photo-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        /* QR CODE WRAPPER */
        .qr-col {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex-shrink: 0;
            width: 18.5mm;
        }
        .qr-box {
            width: 18mm;
            height: 18mm;
            border: 1px solid #CBD5E1;
            border-radius: 3.5px;
            background: #FFFFFF;
            padding: 1px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .qr-caption {
            font-size: 4.8pt;
            font-weight: 800;
            color: <?= $c_teal ?>;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            text-align: center;
            margin-top: 0.6mm;
            line-height: 1;
        }

        /* TTD PENGAWAS LANGSUNG DI SAMPING KANAN QR CODE */
        .sign-col {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 22mm;
            padding-bottom: 0.3mm;
        }
        .sign-notice {
            font-size: 4.5pt;
            color: #64748B;
            line-height: 1.15;
        }
        .sign-notice strong {
            color: <?= $c_navy ?>;
        }
        .sign-box {
            text-align: center;
            width: 100%;
        }
        .sign-box__title {
            font-size: 4.9pt;
            font-weight: 700;
            color: #334155;
            margin-bottom: 4.5mm;
            line-height: 1.1;
        }
        .sign-box__line {
            border-bottom: 1px solid #475569;
            width: 100%;
        }

        /* GARIS PEMBATAS VERTIKAL TENGAH */
        .col-divider {
            width: 1px;
            border-left: 1px dashed #CBD5E1;
            margin: 0 0.5mm;
        }

        /* ============================================================== */
        /* KOLOM KANAN: TABEL JADWAL SESUAI KELAS (AUTO-ADAPTIVE DENSITY)  */
        /* ============================================================== */
        .col-jadwal {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
            max-height: 55mm;
            overflow: hidden;
        }
        .col-jadwal__main {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .jadwal-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(135deg, <?= $c_navy ?> 0%, <?= $c_teal ?> 100%);
            color: #FFFFFF;
            padding: 0.7mm 1.8mm;
            border-radius: 3px;
            margin-bottom: 0.8mm;
            flex-shrink: 0;
        }
        .jadwal-bar__title {
            font-size: 5.6pt;
            font-weight: 800;
            letter-spacing: 0.2px;
            text-transform: uppercase;
        }
        .jadwal-bar__sub {
            font-size: 5.1pt;
            color: <?= $c_sky ?>;
            font-weight: 700;
        }

        /* TABEL JADWAL DENGAN TINGGI MENYESUAIKAN PENUH (TABLE-LAYOUT FIXED) */
        .jadwal-table-wrap {
            flex: 1;
            min-height: 0;
            overflow: hidden;
            display: flex;
        }
        .jadwal-table {
            width: 100%;
            height: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            border: 1px solid #CBD5E1;
        }
        .jadwal-table th {
            background-color: #F1F5F9;
            color: <?= $c_navy ?>;
            font-weight: 800;
            text-align: left;
            border: 1px solid #CBD5E1;
            text-transform: uppercase;
            letter-spacing: 0.2px;
            overflow: hidden;
            white-space: nowrap;
        }
        .jadwal-table td {
            border: 1px solid #E2E8F0;
            vertical-align: middle;
            color: #1E293B;
            overflow: hidden;
        }
        .jadwal-table tr:nth-child(even) td {
            background-color: #F8FAFC;
        }
        .jadwal-day {
            background-color: #F8FAFC !important;
            font-weight: 800;
            color: <?= $c_navy ?>;
            text-align: center;
            vertical-align: middle !important;
            border-right: 1px solid #CBD5E1 !important;
            overflow: hidden;
        }
        .jadwal-time {
            font-family: 'SFMono-Regular', Consolas, monospace;
            font-weight: 700;
            color: <?= $c_navy ?>;
            white-space: nowrap;
            overflow: hidden;
        }
        .jadwal-mapel {
            font-weight: 700;
            color: #0F172A;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .jadwal-mapel__sub {
            color: <?= $c_teal ?>;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .jadwal-paraf {
            text-align: center;
            vertical-align: middle !important;
        }

        /* TIER 1: COMFORT DENSITY (1 - 3 JADWAL) */
        .jadwal--comfort th { font-size: 5pt; padding: 1.2px 2.5px; }
        .jadwal--comfort td { font-size: 5.4pt; padding: 1.2px 2.5px; line-height: 1.2; }
        .jadwal--comfort .jadwal-day strong { font-size: 5.6pt; display: block; color: <?= $c_teal ?>; }
        .jadwal--comfort .jadwal-day small { font-size: 4.6pt; color: #64748B; font-weight: 600; display: block; }
        .jadwal--comfort .jadwal-time { font-size: 5.2pt; }
        .jadwal--comfort .jadwal-mapel { font-size: 5.4pt; }
        .jadwal--comfort .jadwal-mapel__sub { font-size: 4.6pt; display: block; }
        .jadwal--comfort .jadwal-paraf__box { display: inline-block; width: 6mm; height: 4mm; border: 1px dashed #CBD5E1; border-radius: 2px; }

        /* TIER 2: MEDIUM DENSITY (4 - 5 JADWAL) */
        .jadwal--medium th { font-size: 4.6pt; padding: 0.9px 2px; }
        .jadwal--medium td { font-size: 4.8pt; padding: 0.9px 2px; line-height: 1.15; }
        .jadwal--medium .jadwal-day strong { font-size: 5.1pt; display: block; color: <?= $c_teal ?>; }
        .jadwal--medium .jadwal-day small { font-size: 4.2pt; color: #64748B; font-weight: 600; display: block; }
        .jadwal--medium .jadwal-time { font-size: 4.7pt; }
        .jadwal--medium .jadwal-mapel { font-size: 4.9pt; }
        .jadwal--medium .jadwal-mapel__sub { font-size: 4.1pt; display: block; }
        .jadwal--medium .jadwal-paraf__box { display: inline-block; width: 5.5mm; height: 3.5mm; border: 1px dashed #CBD5E1; border-radius: 2px; }

        /* TIER 3: COMPACT DENSITY (6 - 8 JADWAL) */
        .jadwal--compact th { font-size: 4.2pt; padding: 0.5px 1.5px; }
        .jadwal--compact td { font-size: 4.3pt; padding: 0.5px 1.5px; line-height: 1.05; }
        .jadwal--compact .jadwal-day strong { font-size: 4.6pt; display: block; color: <?= $c_teal ?>; }
        .jadwal--compact .jadwal-day small { font-size: 3.8pt; color: #64748B; font-weight: 600; display: block; }
        .jadwal--compact .jadwal-time { font-size: 4.2pt; }
        .jadwal--compact .jadwal-mapel { font-size: 4.5pt; }
        .jadwal--compact .jadwal-mapel__sub { font-size: 3.8pt; display: block; }
        .jadwal--compact .jadwal-paraf__box { display: inline-block; width: 5mm; height: 3mm; border: 1px dashed #CBD5E1; border-radius: 1.5px; }

        /* TIER 4: ULTRA DENSITY (9+ JADWAL) */
        .jadwal--ultra th { font-size: 3.8pt; padding: 0.2px 1.2px; }
        .jadwal--ultra td { font-size: 3.9pt; padding: 0.2px 1.2px; line-height: 1.0; }
        .jadwal--ultra .jadwal-day strong { font-size: 4.1pt; display: block; color: <?= $c_teal ?>; }
        .jadwal--ultra .jadwal-day small { font-size: 3.4pt; color: #64748B; font-weight: 600; display: block; }
        .jadwal--ultra .jadwal-time { font-size: 3.8pt; }
        .jadwal--ultra .jadwal-mapel { font-size: 4.1pt; }
        .jadwal--ultra .jadwal-mapel__sub { display: none; }
        .jadwal--ultra .jadwal-paraf__box { display: inline-block; width: 4.2mm; height: 2.5mm; border: 1px dashed #CBD5E1; border-radius: 1.5px; }

        .col-jadwal__foot {
            border-top: 1px solid #E2E8F0;
            padding-top: 0.6mm;
            margin-top: 0.6mm;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 4.4pt;
            color: #64748B;
            font-style: italic;
            flex-shrink: 0;
        }

        /* PLACEHOLDER KARTU KOSONG */
        .card-compact--empty {
            border: 1.2px dashed #E2E8F0;
            border-radius: 7px;
            background: #FAFAFA;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #CBD5E1;
            font-size: 8.5pt;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
    </style>
</head>
<body>

<?php foreach ($chunks as $pageIndex => $sheetCards): ?>
    <div class="a4-sheet">

        <?php for ($i = 0; $i < 4; $i++): ?>
            <?php if (isset($sheetCards[$i])): 
                $c = $sheetCards[$i];
                $nomorPeserta   = htmlspecialchars($c['nomor_peserta'] ?? '-', ENT_QUOTES, 'UTF-8');
                $namaSiswa      = htmlspecialchars($c['nama_siswa'] ?? 'Peserta Ujian', ENT_QUOTES, 'UTF-8');
                $nis            = htmlspecialchars($c['nis'] ?? '-', ENT_QUOTES, 'UTF-8');
                $nisn           = htmlspecialchars($c['nisn'] ?? '-', ENT_QUOTES, 'UTF-8');
                $namaKelas      = htmlspecialchars($c['nama_kelas'] ?? 'Umum', ENT_QUOTES, 'UTF-8');
                $namaMapel      = htmlspecialchars($c['nama_mata_pelajaran'] ?? '-', ENT_QUOTES, 'UTF-8');
                $judulAsesmen   = htmlspecialchars($c['judul_asesmen'] ?? 'Asesmen Sumatif (CBT)', ENT_QUOTES, 'UTF-8');
                $tahunPelajaran = htmlspecialchars($c['tahun_pelajaran'] ?? ($sekolah['tahun_pelajaran_aktif'] ?? '2026/2027'), ENT_QUOTES, 'UTF-8');
                $semester       = htmlspecialchars($c['semester'] ?? ($sekolah['semester_aktif'] ?? 'Ganjil'), ENT_QUOTES, 'UTF-8');
                $usernameUjian  = htmlspecialchars($c['username_ujian'] ?? '-', ENT_QUOTES, 'UTF-8');
                $passwordUjian  = htmlspecialchars($c['password_ujian'] ?? '-', ENT_QUOTES, 'UTF-8');

                // Foto siswa: diambil dari data siswa (file server atau Pas Foto Resmi Pelajar Latar Biru)
                $photoSrc = ilmuna_get_student_photo_b64($c);

                // QR Code dengan 3-Color Gradient + White Logo Ilmuna di Tengah
                $qrSvg = ilmuna_generate_sumatif_qr_svg($c['nomor_peserta'] ?? '');

                // Susun dan Kelompokkan Jadwal Asesmen Berdasarkan Tanggal/Hari
                $jadwalRaw = $c['jadwal'] ?? [];
                if (empty($jadwalRaw)) {
                    $jadwalRaw = [[
                        'tanggal_pelaksanaan' => $c['tanggal_pelaksanaan'] ?? date('Y-m-d'),
                        'jam_mulai'           => $c['jam_mulai'] ?? '07:30',
                        'jam_selesai'         => $c['jam_selesai'] ?? '09:00',
                        'nama_mata_pelajaran' => $c['nama_mata_pelajaran'] ?? 'Mata Pelajaran',
                        'judul_asesmen'       => $c['judul_asesmen'] ?? 'Asesmen Sumatif (CBT)'
                    ]];
                }

                // Hitung total sesi untuk menentukan tier kepadatan (auto-scaling)
                $totalSesi = count($jadwalRaw);
                $tierClass = 'jadwal--comfort';
                if ($totalSesi >= 9) {
                    $tierClass = 'jadwal--ultra';
                } elseif ($totalSesi >= 6) {
                    $tierClass = 'jadwal--compact';
                } elseif ($totalSesi >= 4) {
                    $tierClass = 'jadwal--medium';
                }

                $groupedJadwal = [];
                foreach ($jadwalRaw as $jItem) {
                    $tglKey = !empty($jItem['tanggal_pelaksanaan']) ? $jItem['tanggal_pelaksanaan'] : date('Y-m-d');
                    if (!isset($groupedJadwal[$tglKey])) {
                        $groupedJadwal[$tglKey] = [];
                    }
                    $groupedJadwal[$tglKey][] = $jItem;
                }
            ?>
                <!-- KARTU KOMPAK SUMATIF #<?= ($i + 1) ?> (TINGGI MAKSIMAL SAMPAI SCAN ABSENSI & TTD DI SAMPING QR) -->
                <div class="card-compact">
                    
                    <!-- HEADER KARTU (DENGAN LOGO DI CELAH TENGAH) -->
                    <div class="card-head">
                        <div class="card-head__left">
                            <div class="card-head__badge-row">
                                <span class="card-head__badge">KARTU PESERTA CBT</span>
                                <span style="font-size: 5.2pt; color: #64748B; font-weight: 700;">T.P. <?= $tahunPelajaran ?></span>
                            </div>
                            <div class="card-head__title">ASESMEN SUMATIF</div>
                            <div class="card-head__sub">Semester <?= $semester ?> &bull; CBT Terpadu</div>
                        </div>

                        <!-- LOGO RESMI DI CELAH PEMISAH TENGAH -->
                        <div class="card-head__center-logo">
                            <?php if ($logoSrc): ?>
                                <img src="<?= $logoSrc ?>" alt="Logo">
                            <?php endif; ?>
                        </div>

                        <div class="card-head__right">
                            <div class="card-head__app-name"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="card-head__class-badge">Rombel: <?= $namaKelas ?></div>
                            <div style="font-size: 4.8pt; color: #64748B; margin-top: 0.5px;">Token Diumumkan Pengawas</div>
                        </div>
                    </div>

                    <!-- BODY KARTU: DIBAGI 2 (KIRI: DETAIL KARTU, KANAN: JADWAL) -->
                    <div class="card-body">

                        <!-- ========================================== -->
                        <!-- KOLOM KIRI: DETAIL KARTU PESERTA           -->
                        <!-- ========================================== -->
                        <div class="col-detail">
                            
                            <!-- BAGIAN ATAS: IDENTITAS & KREDENSIAL -->
                            <div class="col-detail__top">
                                <table class="info-table">
                                    <tr>
                                        <td class="info-table__lbl">No. Peserta</td>
                                        <td class="info-table__sep">:</td>
                                        <td class="info-table__val info-table__val--nomor"><?= $nomorPeserta ?></td>
                                    </tr>
                                    <tr>
                                        <td class="info-table__lbl">Nama Siswa</td>
                                        <td class="info-table__sep">:</td>
                                        <td class="info-table__val"><?= $namaSiswa ?></td>
                                    </tr>
                                    <tr>
                                        <td class="info-table__lbl">NIS / NISN</td>
                                        <td class="info-table__sep">:</td>
                                        <td class="info-table__val"><?= $nis ?> / <?= $nisn ?></td>
                                    </tr>
                                </table>

                                <!-- KREDENSIAL CBT -->
                                <div class="cred-box">
                                    <div class="cred-box__item">
                                        <span class="cred-box__lbl">👤 Username CBT</span>
                                        <span class="cred-box__val">@<?= $usernameUjian ?></span>
                                    </div>
                                    <div class="cred-box__item" style="border-left: 1px dashed #86EFAC; padding-left: 1.8mm;">
                                        <span class="cred-box__lbl">🔑 Password CBT</span>
                                        <span class="cred-box__val" style="color: <?= $c_teal ?>;"><?= $passwordUjian ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- BAGIAN BAWAH: FOTO 3x4 + QR (SCAN ABSENSI) + TTD DI SAMPING KANANNYA -->
                            <div class="col-detail__bottom">
                                <!-- FOTO 3x4 (DARI DATA SISWA) -->
                                <div class="photo-box">
                                    <img src="<?= $photoSrc ?>" alt="Foto <?= $namaSiswa ?>">
                                </div>

                                <!-- QR CODE + CAPTION SCAN ABSENSI -->
                                <div class="qr-col">
                                    <div class="qr-box">
                                        <?= $qrSvg ?>
                                    </div>
                                    <div class="qr-caption">SCAN ABSENSI</div>
                                </div>

                                <!-- TTD PENGAWAS LANGSUNG DI SAMPING KANAN QR CODE -->
                                <div class="sign-col">
                                    <div class="sign-notice">
                                        * <strong>Token Sesi</strong> diumumkan saat ujian. Wajib bawa kartu.
                                    </div>
                                    <div class="sign-box">
                                        <div class="sign-box__title"><?= htmlspecialchars($sekolah['titimangsa_tempat'] ?: 'Bojonegoro') ?>, Proktor / Pengawas</div>
                                        <div class="sign-box__line"></div>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <!-- PEMISAH VERTIKAL TENGAH -->
                        <div class="col-divider"></div>

                        <!-- ========================================== -->
                        <!-- KOLOM KANAN: TABEL JADWAL SESUAI KELAS     -->
                        <!-- ========================================== -->
                        <div class="col-jadwal">
                            <div class="col-jadwal__main">
                                <div class="jadwal-bar">
                                    <span class="jadwal-bar__title">🗓️ Jadwal Asesmen Sumatif</span>
                                    <span class="jadwal-bar__sub">Kelas: <?= $namaKelas ?></span>
                                </div>

                                <div class="jadwal-table-wrap">
                                    <table class="jadwal-table <?= $tierClass ?>">
                                        <thead>
                                            <tr>
                                                <th style="width: 22%; text-align: center;">Hari / Tgl</th>
                                                <th style="width: 22%;">Waktu / Jam</th>
                                                <th style="width: 46%;">Mata Pelajaran & Asesmen</th>
                                                <th style="width: 10%; text-align: center;">Paraf</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($groupedJadwal as $tglIso => $sesiList): 
                                                $rowCount = count($sesiList);
                                                $dayName = ilmuna_indo_day($tglIso);
                                                $dateFmt = ilmuna_indo_date_short($tglIso);
                                            ?>
                                                <?php foreach ($sesiList as $sIdx => $sesi): 
                                                    $jamM = !empty($sesi['jam_mulai']) ? substr($sesi['jam_mulai'], 0, 5) : '07:30';
                                                    $jamS = !empty($sesi['jam_selesai']) ? substr($sesi['jam_selesai'], 0, 5) : '09:00';
                                                    $mapelSesi = htmlspecialchars($sesi['nama_mata_pelajaran'] ?? 'Mata Pelajaran', ENT_QUOTES, 'UTF-8');
                                                    $judulSesi = htmlspecialchars($sesi['judul_asesmen'] ?? 'Asesmen Sumatif', ENT_QUOTES, 'UTF-8');
                                                ?>
                                                    <tr>
                                                        <?php if ($sIdx === 0): ?>
                                                            <td rowspan="<?= $rowCount ?>" class="jadwal-day">
                                                                <strong><?= $dayName ?></strong>
                                                                <small><?= $dateFmt ?></small>
                                                            </td>
                                                        <?php endif; ?>
                                                        <td class="jadwal-time">
                                                            <?= $jamM ?> &ndash; <?= $jamS ?>
                                                        </td>
                                                        <td>
                                                            <div class="jadwal-mapel"><?= $mapelSesi ?></div>
                                                            <div class="jadwal-mapel__sub"><?= $judulSesi ?></div>
                                                        </td>
                                                        <td class="jadwal-paraf">
                                                            <span class="jadwal-paraf__box"></span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="col-jadwal__foot">
                                <span>* Hadir 15 menit sebelum ujian dimulai.</span>
                                <span>Tertib &bull; Jujur &bull; Mandiri</span>
                            </div>
                        </div>

                    </div>
                </div>

            <?php else: ?>
                <!-- Kotak kosong jika lembar terakhir tidak genap 4 kartu -->
                <div class="card-compact card-compact--empty">
                    (Ruang Kosong)
                </div>
            <?php endif; ?>

            <?php if ($i < 3): ?>
                <!-- GARIS PEMOTONGAN HORIZONTAL ANTARKARTU -->
                <div class="sheet-divider">
                    <div class="sheet-divider__line"></div>
                    <div class="sheet-divider__badge">✂ POTONG DI SINI (KARTU <?= ($i + 1) ?>) ✂</div>
                </div>
            <?php endif; ?>

        <?php endfor; ?>

    </div>
<?php endforeach; ?>

</body>
</html>
<?php
    return ob_get_clean();
}

/**
 * 7. Ekspor Berkas PDF Kartu Peserta Ujian Sumatif Kompak (Muat Tepat 4 Kartu per Lembar A4)
 *
 * @param array $cardsData Daftar array data peserta sumatif
 * @param string|null $targetPdfPath Jalur target PDF opsional
 * @return string|false Jalur absolut file PDF jika sukses, false jika gagal
 */
function ilmuna_generate_kartu_ujian_sumatif_pdf(array $cardsData, $targetPdfPath = null) {
    if (empty($cardsData)) {
        return ['ok' => false, 'error' => 'Data kartu kosong.'];
    }

    $bin = ilmuna_get_headless_browser_bin();
    if (!$bin) {
        return ['ok' => false, 'error' => 'Headless browser (Edge/Chrome) tidak ditemukan di server. Install Microsoft Edge atau Google Chrome.'];
    }

    $uploadDir = BASE_PATH . '/uploads/kartu_ujian';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    if (!is_writable($uploadDir)) {
        return ['ok' => false, 'error' => 'Direktori penyimpanan tidak dapat ditulis: ' . $uploadDir];
    }

    $count = count($cardsData);
    $firstNum = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $cardsData[0]['nomor_peserta'] ?? 'KARTU');

    if (empty($targetPdfPath)) {
        $unique = 'Kartu_Ujian_SUMATIF_4in1_' . $firstNum . '_' . $count . 'Siswa_' . bin2hex(random_bytes(3));
        $targetPdfPath = $uploadDir . '/' . $unique . '.pdf';
    }

    $uniqueTmp = 'tmp_sumatif_4in1_' . bin2hex(random_bytes(6));
    $htmlTmp = $uploadDir . '/' . $uniqueTmp . '.html';

    $htmlContent = ilmuna_render_kartu_ujian_sumatif_a4_pdf_html($cardsData);
    if (!file_put_contents($htmlTmp, $htmlContent)) {
        return ['ok' => false, 'error' => 'Gagal menulis file HTML sementara ke: ' . $htmlTmp];
    }

    $fileUri = 'file:///' . str_replace('\\', '/', $htmlTmp);
    $userDataDir = sys_get_temp_dir() . '/edge_pdf_' . uniqid();
    @mkdir($userDataDir, 0777, true);

    // Gunakan modern Chromium headless flags yang super cepat & stabil di Windows/Apache
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

    // PDF tidak terbentuk — kumpulkan info diagnostik
    $outStr = implode(' | ', array_slice($out, -5));
    return [
        'ok'    => false,
        'error' => 'Headless browser gagal membuat PDF. Exit code: ' . $ret . '. Output: ' . mb_substr($outStr, 0, 300),
    ];
}

