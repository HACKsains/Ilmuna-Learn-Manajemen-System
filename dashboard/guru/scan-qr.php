<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — SCANNER QR PRESENSI KBM & GEOFENCING GPS
 * ==============================================================================
 * - TEMA: Lumina Gold & Emerald (Identik dengan absensi-sumatif.php)
 *   Palette: Navy (#1D3B5E), Teal (#0D7A87), Sky (#52B2DC), Emerald (#10B981)
 *   Latar: #F0F4F8 + Canvas Floating Particles (#bgCanvas) + Ambient Glow Orbs
 *   Tipografi: Outfit & Plus Jakarta Sans
 * - LAYOUT: Mengikuti arsitektur scan-absensi.code-snippets.php (Full Screen Kiosk)
 *   Header Sticky Fullscreen + Statusbar Geofence GPS
 *   Grid 2 Kolom Sejajar:
 *     1. Mode 1 — Kamera (Viewfinder Html5Qrcode, Overlay, Camera Controls)
 *     2. Mode 2 — Mesin Scanner / Input Manual (Hardware Scanner Gun Auto-Focus,
 *        Feedback Box, dan Riwayat Scan Sesi Ini)
 * - REALTIME LAZY LOAD (SESI INI):
 *   Langsung append data baru ke riwayat sesi saat scan berhasil tanpa reload &
 *   tanpa polling interval (bebas dari refresh tabel rekap manual lama).
 * - PROTEKSI GAMBAR & PERBAIKAN SECURE CONTEXT:
 *   1. Proteksi download / drag / right click pada seluruh gambar
 *   2. Penanganan graceful GeolocationPositionError & fallback koordinat master sekolah
 *   3. Penanganan graceful Camera Access di context insecure (HTTP vs HTTPS/Localhost)
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

requireRole(['guru', 'admin']);
$activeRole = hasRole('guru') ? 'guru' : 'admin';
$GLOBALS['activeRole'] = $activeRole;

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
$currentUser = getCurrentUser();
$pageTitle = 'Pemindai QR Presensi KBM';

// Profil Guru Pengampu
$currentGuru = null;
$idGuruLogged = 0;
try {
    $gStmt = $pdo->prepare("SELECT * FROM guru WHERE id_user = ? LIMIT 1");
    $gStmt->execute([$currentUser['id']]);
    $currentGuru = $gStmt->fetch(PDO::FETCH_ASSOC);
    if ($currentGuru) {
        $idGuruLogged = (int)$currentGuru['id'];
    }
} catch (Exception $e) {}

$namaPetugas = $currentGuru['nama_guru'] ?? ($currentUser['full_name'] ?? ($currentUser['nama_lengkap'] ?? 'Dewan Guru'));
$nipPetugas  = $currentGuru['nip'] ?? '';

/* ==============================================================================
 * HELPER: HAVERSINE FORMULA JARAK GPS (METER)
 * ============================================================================== */
function calculateHaversineDistance($lat1, $lng1, $lat2, $lng2) {
    $earthRadius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

/* ==============================================================================
 * HELPER: VALIDASI GEOFENCING TERHADAP TABEL `titik_koordinat_sekolah`
 * ============================================================================== */
function checkSchoolGeofence($pdo, $studentLat, $studentLng, $extraRadius = 0) {
    if ($studentLat === null || $studentLng === null) {
        return [
            'ok'      => false,
            'reason'  => 'NO_COORDS',
            'message' => 'Koordinat GPS siswa tidak terdeteksi. Pastikan izin lokasi aktif pada smartphone siswa.',
        ];
    }

    $stmt = $pdo->query("SELECT id, nama_lokasi, latitude, longitude, radius_meter, alamat FROM titik_koordinat_sekolah WHERE is_active = 1");
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($locations)) {
        $locations = $pdo->query("SELECT id, nama_lokasi, latitude, longitude, radius_meter, alamat FROM titik_koordinat_sekolah LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($locations)) {
        return [
            'ok'       => true,
            'reason'   => 'NO_GEOFENCE_DEFINED',
            'message'  => 'Geofencing tidak aktif (belum ada titik master sekolah).',
            'location' => null
        ];
    }

    $bestMatch = null;
    $nearest = null;

    foreach ($locations as $loc) {
        $dist = calculateHaversineDistance($studentLat, $studentLng, (float)$loc['latitude'], (float)$loc['longitude']);
        $maxRadius = (int)$loc['radius_meter'] + (float)$extraRadius;

        $locData = [
            'id'          => (int)$loc['id'],
            'nama_lokasi' => (string)$loc['nama_lokasi'],
            'distance'    => round($dist, 1),
            'radius'      => $maxRadius,
            'alamat'      => (string)$loc['alamat'],
        ];

        if ($nearest === null || $dist < $nearest['distance']) {
            $nearest = $locData;
        }

        if ($dist <= $maxRadius) {
            if ($bestMatch === null || $dist < $bestMatch['distance']) {
                $bestMatch = $locData;
            }
        }
    }

    if ($bestMatch === null) {
        $msg = 'Siswa berada di luar batas area sekolah / kampus.';
        if ($nearest) {
            $msg .= ' Titik terdekat: ' . $nearest['nama_lokasi'] . ' (Jarak: ' . $nearest['distance'] . ' meter, batas radius: ' . $nearest['radius'] . ' meter).';
        }
        return [
            'ok'      => false,
            'reason'  => 'OUTSIDE_GEOFENCE',
            'message' => $msg,
            'nearest' => $nearest
        ];
    }

    return [
        'ok'       => true,
        'reason'   => 'INSIDE_GEOFENCE',
        'message'  => 'Dalam area radius: ' . $bestMatch['nama_lokasi'] . ' (' . $bestMatch['distance'] . ' meter).',
        'location' => $bestMatch,
        'nearest'  => $nearest
    ];
}

/* ==============================================================================
 * HELPER: PARSER QR RAW STRING (JSON / Presensi Token / NIS)
 * ============================================================================== */
function parseQrAttendanceData($raw) {
    $raw = trim((string)$raw);
    if ($raw === '' || strlen($raw) > 1024) return null;

    // Format JSON
    if ($raw[0] === '{') {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            $token = $data['id'] ?? $data['id_presensi'] ?? $data['token'] ?? $data['nis'] ?? $data['nisn'] ?? '';
            $lat = isset($data['lat']) && is_numeric($data['lat']) ? (float)$data['lat'] : null;
            $lng = isset($data['lng']) && is_numeric($data['lng']) ? (float)$data['lng'] : null;
            $acc = isset($data['a']) && is_numeric($data['a']) ? (float)$data['a'] : (isset($data['acc']) ? (float)$data['acc'] : null);
            $ts  = isset($data['t']) && is_numeric($data['t']) ? (int)$data['t'] : null;

            return [
                'type'        => 'json',
                'id_presensi' => preg_replace('/[^A-Za-z0-9._\-:]/', '', (string)$token),
                'id_siswa'    => isset($data['id_siswa']) ? (int)$data['id_siswa'] : 0,
                'id_kmp'      => isset($data['id_kmp']) ? (int)$data['id_kmp'] : 0,
                'lat'         => $lat,
                'lng'         => $lng,
                'accuracy'    => $acc,
                'timestamp'   => $ts,
                'raw'         => $raw
            ];
        }
    }

    // Format Raw Text / Barcode
    return [
        'type'        => 'raw',
        'id_presensi' => preg_replace('/[^A-Za-z0-9._\-:]/', '', $raw),
        'id_siswa'    => 0,
        'id_kmp'      => 0,
        'lat'         => null,
        'lng'         => null,
        'accuracy'    => null,
        'timestamp'   => null,
        'raw'         => $raw
    ];
}

/* ==============================================================================
 * BACKEND AJAX HANDLER: PROSES SCAN QR PRESENSI
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'scan_qr_process') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $rawQr = trim($_POST['id_presensi'] ?? '');
        $parsed = parseQrAttendanceData($rawQr);

        if (!$parsed || empty($parsed['id_presensi'])) {
            echo json_encode([
                'success' => false,
                'code'    => 'INVALID_FORMAT',
                'message' => 'Format kode QR kosong atau tidak valid.',
                'tts'     => 'Maaf, format kode QR tidak valid.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $tokenStr = $parsed['id_presensi'];
        $gpsLat   = $parsed['lat'];
        $gpsLng   = $parsed['lng'];
        $gpsAcc   = $parsed['accuracy'];

        if ($gpsLat === null && isset($_POST['device_lat']) && is_numeric($_POST['device_lat'])) {
            $gpsLat = (float)$_POST['device_lat'];
            $gpsLng = (float)$_POST['device_lng'];
            $gpsAcc = isset($_POST['device_acc']) ? (float)$_POST['device_acc'] : null;
        }

        // Resolusi Siswa & Presensi
        $idSiswa = 0;
        $idKmp   = 0;
        $presensiRow = null;

        // 1. Cek tabel `presensi`
        $stmtPres = $pdo->prepare("
            SELECT p.*, s.nis, s.nama_lengkap, s.id_kelas
            FROM presensi p
            JOIN siswa s ON p.id_siswa = s.id
            WHERE p.id_presensi = ? AND p.status = 'aktif'
            LIMIT 1
        ");
        $stmtPres->execute([$tokenStr]);
        $presensiRow = $stmtPres->fetch(PDO::FETCH_ASSOC);

        if ($presensiRow) {
            $idSiswa = (int)$presensiRow['id_siswa'];
            $idKmp   = (int)$presensiRow['id_kelas_mata_pelajaran'];
        } else {
            // 2. Cek tabel `siswa` via NIS / NISN / ID
            $stmtMhs = $pdo->prepare("
                SELECT id, nis, nisn, nama_lengkap, id_kelas
                FROM siswa
                WHERE nis = ? OR nisn = ? OR id = ?
                LIMIT 1
            ");
            $stmtMhs->execute([$tokenStr, $tokenStr, (int)$tokenStr]);
            $siswaRow = $stmtMhs->fetch(PDO::FETCH_ASSOC);

            if ($siswaRow) {
                $idSiswa = (int)$siswaRow['id'];
                if ($parsed['id_kmp'] > 0) {
                    $idKmp = $parsed['id_kmp'];
                } else {
                    $stmtKmpGuess = $pdo->prepare("
                        SELECT kmp.id 
                        FROM kelas_mata_pelajaran kmp
                        WHERE kmp.id_kelas = ?
                        ORDER BY kmp.id ASC LIMIT 1
                    ");
                    $stmtKmpGuess->execute([$siswaRow['id_kelas']]);
                    $idKmp = (int)$stmtKmpGuess->fetchColumn();
                }
            }
        }

        if (!$idSiswa) {
            echo json_encode([
                'success' => false,
                'code'    => 'STUDENT_NOT_FOUND',
                'message' => 'Data siswa atau token presensi tidak ditemukan pada sistem.',
                'tts'     => 'Maaf, data siswa tidak ditemukan.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Data Lengkap Siswa
        $stmtSiswaData = $pdo->prepare("SELECT id, nis, nisn, nama_lengkap, id_kelas, foto FROM siswa WHERE id = ? LIMIT 1");
        $stmtSiswaData->execute([$idSiswa]);
        $siswa = $stmtSiswaData->fetch(PDO::FETCH_ASSOC);

        // Resolusi Rombel & Mata Pelajaran
        $stmtKmDetail = $pdo->prepare("
            SELECT kmp.id AS id_kmp, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel
            FROM kelas_mata_pelajaran kmp
            JOIN kelas k ON kmp.id_kelas = k.id
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            WHERE kmp.id = ?
            LIMIT 1
        ");
        $stmtKmDetail->execute([$idKmp]);
        $kmDetail = $stmtKmDetail->fetch(PDO::FETCH_ASSOC);

        if (!$kmDetail) {
            $stmtKmDetail = $pdo->prepare("
                SELECT kmp.id AS id_kmp, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel
                FROM kelas_mata_pelajaran kmp
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE kmp.id_kelas = ?
                LIMIT 1
            ");
            $stmtKmDetail->execute([$siswa['id_kelas']]);
            $kmDetail = $stmtKmDetail->fetch(PDO::FETCH_ASSOC);
            if ($kmDetail) {
                $idKmp = (int)$kmDetail['id_kmp'];
            }
        }

        // 1. Validasi Geofencing GPS
        $geofenceResult = checkSchoolGeofence($pdo, $gpsLat, $gpsLng);

        if (!$geofenceResult['ok']) {
            echo json_encode([
                'success' => false,
                'code'    => 'OUTSIDE_GEOFENCE',
                'message' => $geofenceResult['message'],
                'tts'     => 'Maaf, ' . $siswa['nama_lengkap'] . ', Anda berada di luar area sekolah.',
                'coords'  => [
                    'lat' => $gpsLat,
                    'lng' => $gpsLng,
                    'acc' => $gpsAcc
                ],
                'nearest' => $geofenceResult['nearest'],
                'siswa'   => [
                    'id'   => $siswa['id'],
                    'nis'  => $siswa['nis'],
                    'nisn' => $siswa['nisn'] ?? '',
                    'nama' => $siswa['nama_lengkap'],
                    'foto' => $siswa['foto'] ?? ''
                ],
                'kelas_mapel' => $kmDetail ? [
                    'nama_kelas' => $kmDetail['nama_kelas'],
                    'nama_mapel' => $kmDetail['nama_mata_pelajaran']
                ] : null
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 2. Resolusi Jadwal KBM Hari Ini
        $todayStr = date('Y-m-d');
        $stmtJadwal = $pdo->prepare("
            SELECT id, pertemuan_ke, tanggal, jam_mulai, jam_selesai, ruangan, status_jadwal, materi_pembahasan
            FROM jadwal_pelajaran
            WHERE id_kelas_mata_pelajaran = ? AND tanggal = ?
            ORDER BY jam_mulai ASC LIMIT 1
        ");
        $stmtJadwal->execute([$idKmp, $todayStr]);
        $jadwalAktif = $stmtJadwal->fetch(PDO::FETCH_ASSOC);

        if (!$jadwalAktif) {
            $stmtJadwalNext = $pdo->prepare("
                SELECT id, pertemuan_ke, tanggal, jam_mulai, jam_selesai, ruangan, status_jadwal, materi_pembahasan
                FROM jadwal_pelajaran
                WHERE id_kelas_mata_pelajaran = ?
                ORDER BY pertemuan_ke ASC LIMIT 1
            ");
            $stmtJadwalNext->execute([$idKmp]);
            $jadwalAktif = $stmtJadwalNext->fetch(PDO::FETCH_ASSOC);
        }

        $idJadwal = $jadwalAktif ? (int)$jadwalAktif['id'] : null;
        $pertemuanKe = $jadwalAktif ? (int)$jadwalAktif['pertemuan_ke'] : 1;

        // 3. Cek Duplikasi Presensi
        $stmtCekDuplikat = $pdo->prepare("
            SELECT id, status, waktu_absen, metode_absen 
            FROM absensi 
            WHERE id_kelas_mata_pelajaran = ? AND id_siswa = ? AND pertemuan_ke = ?
            LIMIT 1
        ");
        $stmtCekDuplikat->execute([$idKmp, $idSiswa, $pertemuanKe]);
        $existingAbsen = $stmtCekDuplikat->fetch(PDO::FETCH_ASSOC);

        if ($existingAbsen) {
            $statusLabel = [
                'H' => 'Hadir',
                'I' => 'Izin',
                'S' => 'Sakit',
                'A' => 'Alpa',
                'T' => 'Terlambat'
            ][strtoupper($existingAbsen['status'])] ?? 'Tercatat';

            $waktuFormatted = !empty($existingAbsen['waktu_absen']) ? date('H:i', strtotime($existingAbsen['waktu_absen'])) : '-';

            echo json_encode([
                'success' => false,
                'code'    => 'ALREADY_RECORDED',
                'message' => $siswa['nama_lengkap'] . ' sudah tercatat ' . $statusLabel . ' pada Pertemuan ' . $pertemuanKe . ' (pukul ' . $waktuFormatted . ' WIB).',
                'tts'     => $siswa['nama_lengkap'] . ' sudah tercatat ' . $statusLabel . ' pada pertemuan ini.',
                'status'  => $existingAbsen['status'],
                'siswa'   => [
                    'id'   => $siswa['id'],
                    'nis'  => $siswa['nis'],
                    'nisn' => $siswa['nisn'] ?? '',
                    'nama' => $siswa['nama_lengkap'],
                    'foto' => $siswa['foto'] ?? ''
                ],
                'kelas_mapel' => [
                    'nama_kelas' => $kmDetail['nama_kelas'] ?? '-',
                    'nama_mapel' => $kmDetail['nama_mata_pelajaran'] ?? '-'
                ],
                'pertemuan_ke' => $pertemuanKe
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 4. Insert ke Tabel `absensi`
        $idTitikSekolah = !empty($geofenceResult['location']['id']) ? (int)$geofenceResult['location']['id'] : null;
        $jarakMeter     = !empty($geofenceResult['location']['distance']) ? (float)$geofenceResult['location']['distance'] : null;
        $namaLokasiSekolah = !empty($geofenceResult['location']['nama_lokasi']) ? $geofenceResult['location']['nama_lokasi'] : 'Titik Sekolah';

        $keteranganAbsen = 'Scan QR & GPS';
        if ($namaLokasiSekolah) {
            $keteranganAbsen .= ' • ' . $namaLokasiSekolah . ($jarakMeter !== null ? " ({$jarakMeter}m)" : "");
        }

        $stmtInsert = $pdo->prepare("
            INSERT INTO absensi (
                id_siswa, id_kelas_mata_pelajaran, id_jadwal, id_titik_sekolah,
                pertemuan_ke, status, latitude, longitude, jarak_meter,
                waktu_absen, metode_absen, keterangan
            ) VALUES (
                ?, ?, ?, ?,
                ?, 'H', ?, ?, ?,
                NOW(), 'Scan QR & GPS', ?
            )
        ");
        $stmtInsert->execute([
            $idSiswa,
            $idKmp,
            $idJadwal,
            $idTitikSekolah,
            $pertemuanKe,
            $gpsLat,
            $gpsLng,
            $jarakMeter,
            $keteranganAbsen
        ]);
        $newAbsenId = $pdo->lastInsertId();

        $ttsMessage = sprintf(
            "%s kelas %s telah berhasil presensi",
            $siswa['nama_lengkap'],
            $kmDetail['nama_kelas']
        );

        echo json_encode([
            'success'      => true,
            'code'         => 'SUCCESS',
            'absensi_id'   => $newAbsenId,
            'message'      => 'Presensi berhasil dicatat! Status: Hadir (H).',
            'tts'          => $ttsMessage,
            'siswa'        => [
                'id'   => $siswa['id'],
                'nis'  => $siswa['nis'],
                'nisn' => $siswa['nisn'] ?? '',
                'nama' => $siswa['nama_lengkap'],
                'foto' => $siswa['foto'] ?? ''
            ],
            'kelas_mapel'  => [
                'id_kmp'     => $idKmp,
                'nama_kelas' => $kmDetail['nama_kelas'],
                'nama_mapel' => $kmDetail['nama_mata_pelajaran']
            ],
            'pertemuan_ke' => $pertemuanKe,
            'geofence'     => [
                'lokasi'   => $namaLokasiSekolah,
                'distance' => $jarakMeter
            ],
            'waktu'        => date('H:i') . ' WIB',
            'toast_title'  => 'Presensi Berhasil!',
            'toast_msg'    => $siswa['nama_lengkap'] . ' (' . $siswa['nis'] . ') • P' . $pertemuanKe . ' • ' . ($jarakMeter ? $jarakMeter . 'm dari ' . $namaLokasiSekolah : $namaLokasiSekolah)
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'code'    => 'SERVER_ERROR',
            'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage(),
            'tts'     => 'Maaf, terjadi kesalahan pada server.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Master Titik Koordinat Aktif
$activeGeofences = $pdo->query("SELECT id, nama_lokasi, latitude, longitude, radius_meter, alamat FROM titik_koordinat_sekolah WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
if (empty($activeGeofences)) {
    $activeGeofences = $pdo->query("SELECT id, nama_lokasi, latitude, longitude, radius_meter, alamat FROM titik_koordinat_sekolah LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
}

$masterLoc = !empty($activeGeofences) ? $activeGeofences[0] : null;
$masterLocationName = $masterLoc ? $masterLoc['nama_lokasi'] : 'Area Kampus Sekolah';
$masterRadius       = $masterLoc ? (int)$masterLoc['radius_meter'] : 100;
$masterLat          = $masterLoc ? $masterLoc['latitude'] : '-7.15664000';
$masterLng          = $masterLoc ? $masterLoc['longitude'] : '111.88724000';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($pageTitle) ?> — Ilmuna Learn</title>
    <?= renderFavicon() ?>

    <!-- GOOGLE FONTS & ICONS (SAMA SEPERTI ABSENSI-SUMATIF) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?= renderIconifyScript() ?>
    <?php if (function_exists('loadCentralTemplates')) loadCentralTemplates(['modal', 'calendar', 'table']); ?>

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --navy: #1D3B5E;
            --navy-dark: #10243B;
            --teal: #0D7A87;
            --teal-hover: #095963;
            --sky: #52B2DC;
            --gold: #F59E0B;
            --gold-light: #FEF3C7;
            --emerald: #10B981;
            --rose: #F43F5E;
            --violet: #8B5CF6;
            --border: #E2E8F0;
            --font-h: 'Outfit', sans-serif;
            --font-b: 'Plus Jakarta Sans', sans-serif;
        }
        html { height: 100%; scroll-behavior: smooth; }
        body {
            font-family: var(--font-b);
            background: #F0F4F8;
            color: #334155;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        /* PROTEKSI DOWNLOAD & DRAG GAMBAR */
        img {
            -webkit-user-drag: none !important;
            -khtml-user-drag: none !important;
            -moz-user-drag: none !important;
            -o-user-drag: none !important;
            user-drag: none !important;
            user-select: none !important;
            -webkit-user-select: none !important;
            -ms-user-select: none !important;
            -webkit-touch-callout: none !important;
            pointer-events: none !important;
        }

        /* PAGE TRANSITION (SAMA SEPERTI ABSENSI-SUMATIF) */
        .page-transition {
            position: fixed; top: 50%; left: 50%;
            width: 300vmax; height: 300vmax;
            margin-top: -150vmax; margin-left: -150vmax;
            background: #E8EFF7; z-index: 9999;
            transform-origin: center;
            transform: rotate(-225deg) translateY(85%);
            transition: transform 0.6s cubic-bezier(0.4,0,0.2,1);
            pointer-events: none;
        }
        .page-transition::before { content:''; position:absolute; top:10vh; left:0; width:100%; height:100%; background:#52B2DC; }
        .page-transition::after  { content:''; position:absolute; top:20vh; left:0; width:100%; height:100%; background:#0D7A87; }
        .page-transition.active  { transform: rotate(135deg) translateY(-10%); }

        /* CANVAS PARTICLES & AMBIENT GLOW ORBS */
        #bgCanvas { position: fixed; inset: 0; pointer-events: none; z-index: 0; }
        .glow-orb { position: fixed; border-radius: 50%; filter: blur(120px); pointer-events: none; z-index: 0; }
        .glow-orb-1 { width: 500px; height: 500px; background: rgba(13, 122, 135, 0.08); top: -100px; left: -100px; }
        .glow-orb-2 { width: 400px; height: 400px; background: rgba(82, 178, 220, 0.07); bottom: -80px; right: -80px; }

        /* TOPBAR FULLSCREEN (SAMA SEPERTI ABSENSI-SUMATIF) */
        .topbar {
            position: sticky; top: 0; z-index: 100;
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(29, 59, 94, 0.10);
            padding: 12px 20px;
            display: flex; align-items: center; gap: 12px;
            box-shadow: 0 2px 16px rgba(29, 59, 94, 0.08);
        }
        .topbar-logo { display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .topbar-logo img { height: 32px; width: auto; }
        .topbar-logo .app-name { font-family: var(--font-h); font-weight: 800; font-size: 1rem; color: var(--navy); letter-spacing: -0.02em; line-height: 1; }
        .topbar-logo .app-sub  { font-size: 0.65rem; color: var(--teal); font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; }
        .topbar-sep { flex: 1; }
        .topbar-user { display: flex; align-items: center; gap: 8px; font-size: 0.78rem; color: #64748B; }
        .topbar-user strong { color: var(--navy); font-weight: 700; }
        
        .topbar-back {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: 8px;
            background: rgba(13, 122, 135, 0.10); color: var(--teal);
            font-size: 0.78rem; font-weight: 600; text-decoration: none;
            border: 1px solid rgba(13, 122, 135, 0.20); transition: all 0.2s;
            cursor: pointer;
        }
        .topbar-back:hover { background: var(--teal); color: #FFFFFF; }

        /* MAIN KIOSK WRAPPER */
        .main-wrap {
            position: relative; z-index: 1;
            max-width: 1240px; margin: 0 auto;
            padding: 20px 16px 48px;
        }
        @media (min-width: 768px) { .main-wrap { padding: 24px 20px 60px; } }

        /* STATUSBAR GEOFENCE GPS (ARSIKTETUR SNIPPET DENGAN TEMA LUMINA) */
        .kiosk-statusbar {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 20px; margin-bottom: 20px;
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.85);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(29, 59, 94, 0.08);
        }
        .kiosk-statusbar__icon {
            width: 44px; height: 44px; border-radius: 12px;
            background: linear-gradient(135deg, rgba(13, 122, 135, 0.12), rgba(82, 178, 220, 0.20));
            color: var(--teal);
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; flex-shrink: 0;
            box-shadow: 0 3px 10px rgba(13, 122, 135, 0.15);
        }
        .kiosk-statusbar__info { flex: 1; min-width: 0; }
        .kiosk-statusbar__title {
            font-family: var(--font-h);
            font-size: 14px; font-weight: 800; color: var(--navy);
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        }
        .kiosk-statusbar__sub {
            font-size: 12px; color: #64748B; margin-top: 3px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .kiosk-statusbar__dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--emerald);
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.6);
            animation: scanDotPulse 1.8s infinite;
        }
        @keyframes scanDotPulse {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.6); }
            70% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        /* 2-COLUMN SPLIT GRID (LAYOUT PERSIS SCAN-ABSENSI SNIPPET) */
        .kiosk-split {
            display: grid; grid-template-columns: 1fr;
            gap: 20px;
        }
        @media (min-width: 860px) {
            .kiosk-split { grid-template-columns: 1fr 1fr; }
        }

        /* PANEL KIRI & KANAN (TEMA GLASS LUMINA NAVY & TEAL) */
        .kiosk-panel {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.85);
            border-radius: 18px; padding: 20px;
            display: flex; flex-direction: column; gap: 14px;
            box-shadow: 0 4px 24px rgba(29, 59, 94, 0.08);
        }
        .kiosk-panel__head {
            display: flex; align-items: center; justify-content: space-between;
            font-family: var(--font-h); font-size: 13px; font-weight: 800;
            color: var(--navy); text-transform: uppercase; letter-spacing: 0.5px;
            padding-bottom: 10px; border-bottom: 1.5px dashed rgba(29, 59, 94, 0.12);
        }
        .kiosk-panel__head-left {
            display: flex; align-items: center; gap: 8px;
        }
        .kiosk-panel__head-left iconify-icon { color: var(--teal); font-size: 18px; }

        /* MODE 1: KAMERA REAL-TIME */
        .kiosk-cam-wrap {
            position: relative; background: #0F1D2F;
            border-radius: 14px; overflow: hidden;
            aspect-ratio: 1 / 1; max-width: 440px; width: 100%;
            margin: 0 auto; display: flex; align-items: center; justify-content: center;
            box-shadow: inset 0 0 24px rgba(0,0,0,0.6);
        }
        .kiosk-reader { width: 100%; height: 100%; }
        .kiosk-reader video {
            border-radius: 14px !important; object-fit: cover !important;
            width: 100% !important; height: 100% !important;
        }
        .kiosk-cam-overlay {
            position: absolute; inset: 0;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 8px; color: var(--sky); text-align: center; padding: 20px;
            background: rgba(15, 29, 47, 0.94); z-index: 5;
        }
        .kiosk-cam-overlay.hide { display: none !important; }

        .kiosk-cam-controls { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .kiosk-select {
            flex: 1; min-width: 150px; padding: 9px 12px;
            border: 1.5px solid var(--border); border-radius: 10px;
            background: #FFFFFF; font-family: var(--font-b);
            font-size: 12px; font-weight: 600; color: var(--navy);
            outline: none; transition: 0.2s; cursor: pointer;
        }
        .kiosk-select:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.12); }

        .kiosk-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 9px 16px; border-radius: 10px;
            font-family: var(--font-b); font-size: 12px; font-weight: 700;
            cursor: pointer; border: 1.5px solid transparent;
            transition: all 0.2s; white-space: nowrap;
        }
        .kiosk-btn:disabled { opacity: 0.55; cursor: not-allowed; pointer-events: none; }
        .kiosk-btn--primary {
            background: linear-gradient(135deg, var(--navy), var(--teal) 50%, var(--sky));
            color: #FFFFFF; box-shadow: 0 3px 12px rgba(13, 122, 135, 0.30);
        }
        .kiosk-btn--primary:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(13, 122, 135, 0.40); }
        .kiosk-btn--ghost {
            background: rgba(244, 63, 94, 0.10); color: #BE185D; border-color: rgba(244, 63, 94, 0.25);
        }
        .kiosk-btn--ghost:hover { background: #BE185D; color: #FFFFFF; }

        .kiosk-hint {
            display: flex; gap: 8px; align-items: flex-start;
            font-size: 11.5px; color: #475569;
            background: rgba(13, 122, 135, 0.08); border: 1px dashed rgba(13, 122, 135, 0.25);
            border-radius: 10px; padding: 10px 12px; line-height: 1.5;
        }
        .kiosk-hint iconify-icon { color: var(--teal); flex-shrink: 0; margin-top: 1px; font-size: 15px; }

        /* MODE 2: MESIN SCANNER / HARDWARE INPUT */
        .kiosk-hw-wrap {
            display: flex; flex-direction: column; align-items: center; gap: 14px;
            padding: 12px 0; min-height: 175px; justify-content: center;
        }
        .kiosk-hw-icon {
            color: var(--teal); animation: kioskHwPulse 2.2s ease-in-out infinite; font-size: 52px;
        }
        @keyframes kioskHwPulse {
            0%, 100% { transform: scale(1); opacity: 0.85; }
            50%      { transform: scale(1.08); opacity: 1; }
        }
        .kiosk-hw-input-wrap {
            width: 100%; display: flex; flex-direction: column; gap: 10px; align-items: stretch;
        }
        .kiosk-hw-input {
            width: 100%; padding: 14px 16px;
            border: 2px solid var(--border); border-radius: 12px;
            background: #FFFFFF; font-family: 'Courier New', monospace;
            font-size: 14px; font-weight: 700; color: var(--navy);
            text-align: center; outline: none; transition: 0.2s; letter-spacing: 0.6px;
        }
        .kiosk-hw-input:focus {
            border-color: var(--teal); box-shadow: 0 0 0 4px rgba(13, 122, 135, 0.12);
        }
        .kiosk-hw-input::placeholder {
            font-family: var(--font-b); font-size: 12px;
            letter-spacing: normal; font-weight: 500; color: #94A3B8;
        }
        .kiosk-hw-submit {
            justify-content: center; padding: 11px 16px; font-size: 12.5px;
        }

        /* FEEDBACK BOX (SAMA SEPERTI SNIPPET TAPI TEMA LUMINA) */
        .kiosk-feedback {
            display: none; margin-top: 8px; padding: 14px 16px;
            border-radius: 12px; font-size: 13px; line-height: 1.4;
            align-items: center; gap: 12px; animation: kioskFadeIn 0.25s ease-out;
        }
        .kiosk-feedback.show { display: flex; }
        .kiosk-feedback iconify-icon { flex-shrink: 0; font-size: 26px; }
        .kiosk-feedback--ok {
            background: rgba(16, 185, 129, 0.10); border: 1.5px solid rgba(16, 185, 129, 0.30); color: #065F46;
        }
        .kiosk-feedback--ok iconify-icon { color: #059669; }
        .kiosk-feedback--warn {
            background: rgba(245, 158, 11, 0.10); border: 1.5px solid rgba(245, 158, 11, 0.30); color: #92400E;
        }
        .kiosk-feedback--warn iconify-icon { color: var(--gold); }
        .kiosk-feedback--err {
            background: rgba(244, 63, 94, 0.10); border: 1.5px solid rgba(244, 63, 94, 0.30); color: #9F1239;
        }
        .kiosk-feedback--err iconify-icon { color: var(--rose); }
        .kiosk-feedback__body { flex: 1; min-width: 0; }
        .kiosk-feedback__name {
            font-family: var(--font-h); font-weight: 800; font-size: 14.5px; color: inherit; display: block;
        }
        .kiosk-feedback__meta {
            font-size: 11.5px; color: inherit; opacity: 0.88; margin-top: 2px;
            display: flex; gap: 8px; flex-wrap: wrap;
        }
        @keyframes kioskFadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* RIWAYAT SCAN SESI INI (LAZY LOAD DIRECT APPEND TANPA RELOAD / INTERVAL POLL) */
        .kiosk-recent {
            margin-top: 14px; background: #FFFFFF;
            border: 1px solid var(--border); border-radius: 14px; overflow: hidden;
            box-shadow: 0 4px 18px rgba(29, 59, 94, 0.06);
        }
        .kiosk-recent__head {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 16px; background: rgba(241, 245, 249, 0.85);
            border-bottom: 1px solid var(--border);
            font-family: var(--font-h); font-size: 11.5px; font-weight: 800;
            color: var(--navy); text-transform: uppercase; letter-spacing: 0.5px;
        }
        .kiosk-recent__badge {
            background: rgba(13, 122, 135, 0.12); color: var(--teal);
            padding: 1px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 800;
        }
        .kiosk-recent__clear {
            margin-left: auto; background: transparent; border: none;
            font-family: var(--font-b); font-size: 11px; font-weight: 700;
            color: var(--rose); cursor: pointer; padding: 2px 8px; border-radius: 6px;
            transition: 0.15s;
        }
        .kiosk-recent__clear:hover { background: rgba(244, 63, 94, 0.08); }
        .kiosk-recent__list { max-height: 290px; overflow-y: auto; }
        .kiosk-recent__empty { padding: 24px; text-align: center; color: #94A3B8; font-size: 12px; }
        .kiosk-recent__item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 16px; border-bottom: 1px solid rgba(226, 232, 240, 0.7);
            font-size: 12.5px; transition: 0.15s;
            animation: kioskItemIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes kioskItemIn {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .kiosk-recent__item:last-child { border-bottom: none; }
        .kiosk-recent__item:hover { background: rgba(13, 122, 135, 0.03); }
        .kiosk-recent__check {
            width: 26px; height: 26px; border-radius: 50%;
            background: rgba(16, 185, 129, 0.15); color: #059669;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
            font-size: 15px;
        }
        .kiosk-recent__info { flex: 1; min-width: 0; }
        .kiosk-recent__name {
            font-family: var(--font-h); font-weight: 800; color: var(--navy);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .kiosk-recent__sub { font-size: 11px; color: #64748B; margin-top: 2px; }
        .kiosk-recent__time {
            font-size: 11px; color: #475569; font-weight: 700;
            font-variant-numeric: tabular-nums; flex-shrink: 0; font-family: monospace;
        }

        /* TOAST NOTIFICATION STACK (TEMA GLASS LUMINA) */
        .toast-wrap {
            position: fixed; top: 78px; right: 20px;
            z-index: 999999; display: flex; flex-direction: column; gap: 10px;
            pointer-events: none; max-width: 360px; width: calc(100vw - 40px);
        }
        .toast {
            pointer-events: auto; display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 16px; background: rgba(15, 29, 47, 0.95);
            color: #FFFFFF; border-radius: 14px;
            box-shadow: 0 12px 32px rgba(0,0,0,0.25);
            animation: toastSlideIn 0.32s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative; overflow: hidden;
        }
        .toast.toast--out { animation: toastSlideOut 0.28s ease forwards; }
        .toast__icon {
            width: 34px; height: 34px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
            font-size: 18px;
        }
        .toast--ok .toast__icon   { background: rgba(16, 185, 129, 0.20); color: var(--emerald); }
        .toast--err .toast__icon  { background: rgba(244, 63, 94, 0.20); color: #F87171; }
        .toast--warn .toast__icon { background: rgba(245, 158, 11, 0.20); color: var(--gold); }
        .toast__body { flex: 1; min-width: 0; }
        .toast__title {
            font-family: var(--font-h); font-size: 13.5px; font-weight: 800; color: #FFFFFF;
            margin-bottom: 2px; line-height: 1.3;
        }
        .toast--ok .toast__title   { color: #6EE7B7; }
        .toast--err .toast__title  { color: #FCA5A5; }
        .toast--warn .toast__title { color: #FDE68A; }
        .toast__msg { font-size: 12px; color: #E2E8F0; line-height: 1.4; word-break: break-word; }
        .toast__time { font-size: 10.5px; color: #94A3B8; margin-top: 4px; font-variant-numeric: tabular-nums; }
        .toast__close {
            background: transparent; border: none; color: #94A3B8; cursor: pointer;
            padding: 2px; font-size: 14px; line-height: 1; transition: color 0.15s; flex-shrink: 0;
        }
        .toast__close:hover { color: #FFFFFF; }
        .toast__bar {
            position: absolute; bottom: 0; left: 0; height: 3px;
            background: currentColor; opacity: 0.35;
            animation: toastProgressBar 4500ms linear forwards;
        }
        .toast--ok .toast__bar   { color: var(--emerald); }
        .toast--err .toast__bar  { color: var(--rose); }
        .toast--warn .toast__bar { color: var(--gold); }

        @keyframes toastSlideIn {
            from { transform: translateX(120%); opacity: 0; }
            to   { transform: translateX(0); opacity: 1; }
        }
        @keyframes toastSlideOut {
            from { transform: translateX(0); opacity: 1; }
            to   { transform: translateX(120%); opacity: 0; }
        }
        @keyframes toastProgressBar {
            from { width: 100%; }
            to   { width: 0%; }
        }

        @media (max-width: 640px) {
            .main-wrap { padding: 10px; }
            .topbar { padding: 10px 14px; gap: 10px; }
            .topbar-user { display: none; }
            .topbar-back span { display: none; }
            .kiosk-split { gap: 12px; }
            .kiosk-panel { padding: 14px; }
            .kiosk-cam-wrap { aspect-ratio: 4 / 3; }
            .kiosk-feedback { padding: 11px 12px; font-size: 12px; }
            .kiosk-statusbar { padding: 12px 14px; gap: 10px; }
            .kiosk-statusbar__title { font-size: 13px; }
            .kiosk-statusbar__sub { font-size: 11px; }
            .toast-wrap { top: 68px; right: 10px; left: 10px; width: auto; max-width: none; }
        }
    </style>
</head>
<body>

<div id="pageTransition" class="page-transition active"></div>
<canvas id="bgCanvas"></canvas>
<div class="glow-orb glow-orb-1"></div>
<div class="glow-orb glow-orb-2"></div>

<!-- TOPBAR KIOSK FULLSCREEN (TEMA PERSIS ABSENSI-SUMATIF) -->
<header class="topbar">
    <a href="<?= BASE_URL ?>/dashboard/guru/absensi" class="topbar-logo">
        <img src="<?= htmlspecialchars(getFaviconUrl() ?: BASE_URL . '/assets/images/logo-learn.png', ENT_QUOTES, 'UTF-8') ?>" alt="Ilmuna Learn" draggable="false" oncontextmenu="return false;" ondragstart="return false;">
        <div>
            <div class="app-name">Ilmuna Learn</div>
            <div class="app-sub">Portal Guru — Pemindai QR Presensi KBM</div>
        </div>
    </a>
    <div class="topbar-sep"></div>
    <div class="topbar-user">
        <iconify-icon icon="solar:user-speak-bold" style="color:var(--teal)"></iconify-icon>
        <span>Guru: <strong><?= htmlspecialchars($namaPetugas, ENT_QUOTES, 'UTF-8') ?></strong></span>
        <?php if ($nipPetugas): ?>
            <span style="font-size:.7rem;color:#94A3B8;">(NIP. <?= htmlspecialchars($nipPetugas, ENT_QUOTES, 'UTF-8') ?>)</span>
        <?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
        <button type="button" class="topbar-back" id="btnToggleFs" onclick="toggleKioskFullscreen()" title="Beralih ke Layar Penuh">
            <iconify-icon icon="solar:maximize-square-3-bold" id="iconFs"></iconify-icon>
            <span id="textFs">Layar Penuh</span>
        </button>
        <a href="<?= BASE_URL ?>/dashboard/guru/absensi" class="topbar-back">
            <iconify-icon icon="solar:arrow-left-bold"></iconify-icon>
            <span>Kembali ke Absensi</span>
        </a>
    </div>
</header>

<!-- MAIN CONTAINER -->
<main class="main-wrap">

    <!-- STATUSBAR GEOFENCE GPS SEKOLAH (PERSIS ARSITEKTUR SNIPPET DENGAN TEMA LUMINA) -->
    <div class="kiosk-statusbar">
        <div class="kiosk-statusbar__icon">
            <iconify-icon icon="solar:map-point-wave-bold"></iconify-icon>
        </div>
        <div class="kiosk-statusbar__info">
            <div class="kiosk-statusbar__title">
                <span class="kiosk-statusbar__dot"></span>
                <span id="statusTitle">Geofencing GPS Aktif: Siap Memindai Presensi Siswa</span>
            </div>
            <div class="kiosk-statusbar__sub" id="statusSub">
                Acuan: <strong><?= htmlspecialchars($masterLocationName) ?></strong> (Radius: <?= $masterRadius ?> meter · Lat: <?= $masterLat ?>, Lng: <?= $masterLng ?>)
            </div>
        </div>
    </div>

    <!-- 2-COLUMN SPLIT: MODE 1 (KAMERA) & MODE 2 (MESIN SCANNER) PERSIS SNIPPET -->
    <div class="kiosk-split">

        <!-- PANEL KIRI: MODE 1 — KAMERA -->
        <div class="kiosk-panel">
            <div class="kiosk-panel__head">
                <div class="kiosk-panel__head-left">
                    <iconify-icon icon="solar:camera-bold"></iconify-icon>
                    <span>Mode 1 — Kamera</span>
                </div>
                <span style="font-size: 11px; color: var(--teal); font-weight: 700;">Html5Qrcode Engine</span>
            </div>

            <div class="kiosk-cam-wrap">
                <div id="scanqrReader" class="kiosk-reader"></div>
                <div class="kiosk-cam-overlay" id="camOverlay">
                    <iconify-icon icon="solar:camera-off-bold" style="font-size:42px;color:rgba(82,178,220,0.5);"></iconify-icon>
                    <p style="color:#FFFFFF;font-family:var(--font-h);font-size:14px;font-weight:800;margin-top:6px;">Kamera Belum Aktif</p>
                    <small style="color:#94A3B8;max-width:280px;line-height:1.4;">Tekan tombol <b>Mulai Kamera</b> di bawah untuk mengaktifkan pemindaian.</small>
                </div>
            </div>

            <div class="kiosk-cam-controls">
                <select class="kiosk-select" id="camSelect">
                    <option value="">— Memuat Daftar Kamera —</option>
                </select>
                <button type="button" class="kiosk-btn kiosk-btn--primary" id="btnStartCam" onclick="startCamera()">
                    <iconify-icon icon="solar:play-circle-bold" width="15"></iconify-icon>
                    Mulai Kamera
                </button>
                <button type="button" class="kiosk-btn kiosk-btn--ghost" id="btnStopCam" onclick="stopCamera()" disabled>
                    <iconify-icon icon="solar:stop-circle-bold" width="15"></iconify-icon>
                    Stop
                </button>
            </div>

            <div class="kiosk-hint">
                <iconify-icon icon="solar:info-circle-bold"></iconify-icon>
                <span>Arahkan kamera ke QR presensi digital pada smartphone atau kartu identitas siswa. Siswa wajib berada dalam radius sekolah.</span>
            </div>
        </div>

        <!-- PANEL KANAN: MODE 2 — MESIN SCANNER / INPUT MANUAL -->
        <div class="kiosk-panel">
            <div class="kiosk-panel__head">
                <div class="kiosk-panel__head-left">
                    <iconify-icon icon="solar:keyboard-bold"></iconify-icon>
                    <span>Mode 2 — Mesin Scanner / Input Manual</span>
                </div>
                <span style="font-size: 11px; color: var(--emerald); font-weight: 800;">AUTO-FOCUS ON</span>
            </div>

            <div class="kiosk-hw-wrap">
                <div class="kiosk-hw-icon">
                    <iconify-icon icon="solar:scanner-bold"></iconify-icon>
                </div>
                <div class="kiosk-hw-input-wrap">
                    <input type="text"
                           class="kiosk-hw-input"
                           id="hwInput"
                           placeholder="Arahkan mesin barcode gun ke QR / tempel kode di sini…"
                           autocomplete="off"
                           autocapitalize="off"
                           autocorrect="off"
                           spellcheck="false"
                           autofocus>
                    <button type="button" class="kiosk-btn kiosk-btn--primary kiosk-hw-submit" id="btnSubmitHw" onclick="submitHwInput()">
                        <iconify-icon icon="solar:cursor-square-bold" width="15"></iconify-icon>
                        Kirim Manual
                    </button>
                </div>
            </div>

            <!-- FEEDBACK ALERT BOX -->
            <div class="kiosk-feedback" id="feedbackBox">
                <iconify-icon icon="solar:check-circle-bold" id="feedbackIcon"></iconify-icon>
                <div class="kiosk-feedback__body">
                    <span class="kiosk-feedback__name" id="feedbackName">-</span>
                    <span class="kiosk-feedback__meta" id="feedbackMeta">-</span>
                </div>
            </div>

            <!-- RIWAYAT SCAN SESI INI (LAZY LOAD DIRECT APPEND TANPA RELOAD / INTERVAL POLL) -->
            <div class="kiosk-recent">
                <div class="kiosk-recent__head">
                    <iconify-icon icon="solar:history-bold" width="15" style="color:var(--teal);"></iconify-icon>
                    <span>Riwayat Scan Sesi Ini</span>
                    <span class="kiosk-recent__badge" id="recentBadge">0</span>
                    <button type="button" class="kiosk-recent__clear" onclick="clearRecent()">Bersihkan</button>
                </div>
                <div class="kiosk-recent__list" id="recentList">
                    <div class="kiosk-recent__empty">Belum ada scan berhasil pada sesi ini.</div>
                </div>
            </div>

        </div>

    </div>

</main>

<!-- TOAST WRAPPER -->
<div class="toast-wrap" id="toastWrap"></div>

<!-- LIBRARY HTML5 QRCODE -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/alert.js"></script>

<script>
/**
 * ==============================================================================
 * PROTEKSI DOWNLOAD & DRAG GAMBAR
 * ============================================================================== */
document.addEventListener('contextmenu', function(e) {
    if (e.target && (e.target.tagName === 'IMG' || e.target.closest('img'))) {
        e.preventDefault();
        return false;
    }
}, { capture: true });

document.addEventListener('dragstart', function(e) {
    if (e.target && (e.target.tagName === 'IMG' || e.target.closest('img'))) {
        e.preventDefault();
        return false;
    }
}, { capture: true });

/**
 * ==============================================================================
 * FULLSCREEN KIOSK TOGGLE (SAMA SEPERTI ABSENSI-SUMATIF)
 * ============================================================================== */
window.toggleKioskFullscreen = function() {
    var icon = document.getElementById('iconFs');
    var text = document.getElementById('textFs');
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().then(function() {
            if (icon) icon.setAttribute('icon', 'solar:minimize-square-3-bold');
            if (text) text.textContent = 'Keluar Layar Penuh';
        }).catch(function(err) {
            console.warn('Fullscreen error:', err);
        });
    } else {
        if (document.exitFullscreen) {
            document.exitFullscreen().then(function() {
                if (icon) icon.setAttribute('icon', 'solar:maximize-square-3-bold');
                if (text) text.textContent = 'Layar Penuh';
            });
        }
    }
};

document.addEventListener('fullscreenchange', function() {
    var icon = document.getElementById('iconFs');
    var text = document.getElementById('textFs');
    if (document.fullscreenElement) {
        if (icon) icon.setAttribute('icon', 'solar:minimize-square-3-bold');
        if (text) text.textContent = 'Keluar Layar Penuh';
    } else {
        if (icon) icon.setAttribute('icon', 'solar:maximize-square-3-bold');
        if (text) text.textContent = 'Layar Penuh';
    }
});

/* PAGE TRANSITION */
var pt = document.getElementById('pageTransition');
setTimeout(function(){ if(pt) pt.classList.remove('active'); }, 100);

/* BG CANVAS ANIMATED PARTICLES (TEMA LUMINA ABSENSI-SUMATIF) */
var bgCanvas = document.getElementById('bgCanvas');
if (bgCanvas) {
    var bc = bgCanvas.getContext('2d');
    function szBg(){ bgCanvas.width = window.innerWidth; bgCanvas.height = window.innerHeight; }
    szBg(); window.addEventListener('resize', szBg);
    var pts = [];
    for (var i = 0; i < 35; i++) {
        pts.push({
            x: Math.random() * bgCanvas.width,
            y: Math.random() * bgCanvas.height,
            vx: (Math.random() - 0.5) * 0.3,
            vy: (Math.random() - 0.5) * 0.3,
            r: Math.random() * 2 + 1,
            c: ['#1D3B5E', '#0D7A87', '#52B2DC'][Math.floor(Math.random() * 3)],
            o: Math.random() * 0.25 + 0.05
        });
    }
    function bgAnimate(){
        bc.clearRect(0, 0, bgCanvas.width, bgCanvas.height);
        pts.forEach(function(p){
            p.x += p.vx; p.y += p.vy;
            if (p.x < 0 || p.x > bgCanvas.width) p.vx *= -1;
            if (p.y < 0 || p.y > bgCanvas.height) p.vy *= -1;
            bc.beginPath(); bc.arc(p.x, p.y, p.r, 0, Math.PI * 2);
            bc.fillStyle = p.c; bc.globalAlpha = p.o; bc.fill();
        });
        bc.globalAlpha = 1; requestAnimationFrame(bgAnimate);
    }
    bgAnimate();
}

/**
 * ==============================================================================
 * AUDIO & TTS ENGINE
 * ============================================================================== */
var audioCtx = null;
function getAudioCtx() {
    if (!audioCtx) {
        try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e){}
    }
    return audioCtx;
}

function playBeep(freq, duration, type) {
    var ctx = getAudioCtx();
    if (!ctx) return;
    try {
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = type || 'sine';
        osc.frequency.value = freq;
        osc.connect(gain);
        gain.connect(ctx.destination);
        var t = ctx.currentTime;
        gain.gain.setValueAtTime(0.001, t);
        gain.gain.exponentialRampToValueAtTime(0.35, t + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.001, t + duration);
        osc.start(t);
        osc.stop(t + duration + 0.02);
    } catch(e){}
}

function soundSuccess() {
    playBeep(880, 0.08, 'sine');
    setTimeout(function() { playBeep(1200, 0.12, 'sine'); }, 100);
}

function soundError() {
    playBeep(260, 0.25, 'sawtooth');
}

function speakTTS(text) {
    if (!window.speechSynthesis || !text) return;
    try {
        window.speechSynthesis.cancel();
        var utter = new SpeechSynthesisUtterance(text);
        utter.lang = 'id-ID';
        utter.rate = 1.05;
        utter.pitch = 1.0;
        var voices = window.speechSynthesis.getVoices();
        for (var i = 0; i < voices.length; i++) {
            if (voices[i].lang && voices[i].lang.indexOf('id') !== -1) {
                utter.voice = voices[i]; break;
            }
        }
        window.speechSynthesis.speak(utter);
    } catch(e){}
}

/**
 * ==============================================================================
 * TOAST NOTIFICATIONS (TEMA PERSIS ABSENSI-SUMATIF)
 * ============================================================================== */
function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function pushToast(type, title, msg) {
    var normType = (type === 'err' || type === 'error') ? 'error' : ((type === 'warn' || type === 'warning') ? 'warning' : 'success');
    if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
        return Ilmuna.toast(normType, title, msg, 4500);
    }
    var wrap = document.getElementById('toastWrap');
    if (!wrap) return;

    var iconMap = {
        ok: 'solar:check-circle-bold',
        err: 'solar:danger-circle-bold',
        warn: 'solar:shield-warning-bold'
    };

    var el = document.createElement('div');
    el.className = 'toast toast--' + esc(type);
    el.innerHTML =
        '<div class="toast__icon">' +
            '<iconify-icon icon="' + esc(iconMap[type] || iconMap.ok) + '"></iconify-icon>' +
        '</div>' +
        '<div class="toast__body">' +
            '<div class="toast__title">' + esc(title) + '</div>' +
            '<div class="toast__msg">' + esc(msg) + '</div>' +
            '<div class="toast__time">' + (new Date()).toLocaleTimeString('id-ID') + '</div>' +
        '</div>' +
        '<button type="button" class="toast__close" aria-label="Tutup">✕</button>' +
        '<div class="toast__bar"></div>';

    var close = function() {
        if (el.classList.contains('toast--out')) return;
        el.classList.add('toast--out');
        setTimeout(function() { el.remove(); }, 300);
    };

    el.querySelector('.toast__close').addEventListener('click', close);
    wrap.appendChild(el);
    setTimeout(close, 4500);
}

/**
 * ==============================================================================
 * CLIENT ENGINE: CAMERA, HARDWARE SCANNER, LAZY LOAD RECENT (NO RELOAD / NO POLL)
 * ============================================================================== */
var qrScanner = null;
var isScanning = false;
var isProcessing = false;
var lastScannedToken = '';
var lastScannedTime = 0;
var sessionRecent = [];

// Inisialisasi Koordinat Scanner Guru dengan Default Acuan Master Sekolah (Anti-Error)
var deviceCoords = {
    lat: <?= json_encode((float)$masterLat) ?>,
    lng: <?= json_encode((float)$masterLng) ?>,
    acc: 10
};

// Coba perbarui GPS jika browser berada dalam Secure Context (HTTPS/localhost) tanpa melempar unhandled warning
if (navigator.geolocation && (window.isSecureContext || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    navigator.geolocation.getCurrentPosition(function(pos) {
        if (pos && pos.coords) {
            deviceCoords.lat = pos.coords.latitude;
            deviceCoords.lng = pos.coords.longitude;
            deviceCoords.acc = pos.coords.accuracy;
        }
    }, function(err) {
        // Fallback hening ke koordinat sekolah tanpa spam log error
    }, {
        enableHighAccuracy: false,
        timeout: 6000,
        maximumAge: 300000
    });
}

/* 1. INISIALISASI KAMERA DENGAN DETEKSI SECURE CONTEXT */
function loadCameras() {
    var sel = document.getElementById('camSelect');
    var overlay = document.getElementById('camOverlay');
    if (!sel) return;

    var isSecure = window.isSecureContext || location.hostname === 'localhost' || location.hostname === '127.0.0.1';

    if (!isSecure) {
        sel.innerHTML = '<option value="">Akses kamera butuh HTTPS / Localhost</option>';
        if (overlay) {
            var httpsUrl = 'https://' + location.host + location.pathname + location.search;
            overlay.classList.remove('hide');
            overlay.innerHTML =
                '<iconify-icon icon="solar:shield-warning-bold" style="font-size:42px;color:var(--gold);"></iconify-icon>' +
                '<p style="color:#FFFFFF;font-family:var(--font-h);font-size:14px;font-weight:800;margin-top:6px;">Akses Kamera Butuh HTTPS</p>' +
                '<small style="color:#94A3B8;max-width:280px;line-height:1.4;margin:4px 0 10px;">Browser membatasi izin kamera hanya pada HTTPS atau Localhost.</small>' +
                '<div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center;">' +
                    '<a href="' + esc(httpsUrl) + '" class="kiosk-btn kiosk-btn--primary" style="text-decoration:none;font-size:11.5px;padding:7px 14px;">' +
                        '<iconify-icon icon="solar:lock-bold" width="14"></iconify-icon> Buka Versi HTTPS' +
                    '</a>' +
                '</div>';
        }
        // Auto fokus ke Mode 2 (Mesin Barcode Gun)
        var hwInp = document.getElementById('hwInput');
        if (hwInp) hwInp.focus();
        return;
    }

    if (!window.Html5Qrcode) return;

    Html5Qrcode.getCameras().then(function(devices) {
        sel.innerHTML = '<option value="">— Pilih Kamera —</option>';
        if (!devices || devices.length === 0) {
            sel.innerHTML = '<option value="">Tidak ada kamera terdeteksi</option>';
            return;
        }

        var isMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);
        var prefer = null;

        devices.forEach(function(d, idx) {
            var opt = document.createElement('option');
            opt.value = d.id;
            opt.textContent = d.label || ('Kamera ' + (idx + 1));
            sel.appendChild(opt);

            if (isMobile && /back|rear|belakang|environment/i.test(d.label || '')) {
                prefer = d.id;
            } else if (!isMobile && /front|user|depan|facetime|integrated/i.test(d.label || '')) {
                if (!prefer) prefer = d.id;
            }
        });

        if (prefer) sel.value = prefer;
        else if (devices[0]) sel.value = devices[0].id;

        // Auto start kamera jika secure context
        startCamera();
    }).catch(function(err) {
        sel.innerHTML = '<option value="">Izin kamera diblokir / tidak tersedia</option>';
        if (overlay) {
            overlay.classList.remove('hide');
            overlay.innerHTML =
                '<iconify-icon icon="solar:camera-off-bold" style="font-size:42px;color:rgba(82,178,220,0.5);"></iconify-icon>' +
                '<p style="color:#FFFFFF;font-family:var(--font-h);font-size:14px;font-weight:800;margin-top:6px;">Kamera Tidak Tersedia</p>' +
                '<small style="color:#94A3B8;max-width:280px;line-height:1.4;">' + esc(err.message || 'Izin kamera ditolak pada browser.') + '</small>';
        }
    });
}

window.startCamera = function() {
    if (isScanning || !window.Html5Qrcode) return;

    var isSecure = window.isSecureContext || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    if (!isSecure) {
        pushToast('warn', 'Akses Butuh HTTPS', 'Silakan buka via HTTPS untuk mengizinkan akses kamera.');
        return;
    }

    var sel = document.getElementById('camSelect');
    var deviceId = sel ? sel.value : null;
    var overlay = document.getElementById('camOverlay');
    var btnStart = document.getElementById('btnStartCam');
    var btnStop = document.getElementById('btnStopCam');

    if (!qrScanner) {
        qrScanner = new Html5Qrcode('scanqrReader');
    }

    var cameraConfig = deviceId ? { deviceId: { exact: deviceId } } : { facingMode: 'environment' };

    qrScanner.start(
        cameraConfig,
        {
            fps: 15,
            qrbox: { width: 240, height: 240 },
            aspectRatio: 1.0
        },
        function(decodedText) {
            onDecoded(decodedText);
        },
        function(err) {}
    ).then(function() {
        isScanning = true;
        if (overlay) overlay.classList.add('hide');
        if (btnStart) btnStart.disabled = true;
        if (btnStop) btnStop.disabled = false;
    }).catch(function(err) {
        pushToast('err', 'Kamera Gagal', err.message || 'Izin kamera ditolak.');
        showFeedback('err', 'Gagal Mengaktifkan Kamera', err.message || 'Izin kamera tidak diberikan.');
    });
};

window.stopCamera = function() {
    if (!qrScanner || !isScanning) return;
    var overlay = document.getElementById('camOverlay');
    var btnStart = document.getElementById('btnStartCam');
    var btnStop = document.getElementById('btnStopCam');

    qrScanner.stop().then(function() {
        isScanning = false;
        if (overlay) overlay.classList.remove('hide');
        if (btnStart) btnStart.disabled = false;
        if (btnStop) btnStop.disabled = true;
    }).catch(function(err) {
        console.warn('Stop cam error:', err);
    });
};

/* 2. FEEDBACK UI BOX (PERSIS ARSITEKTUR SNIPPET DENGAN TEMA LUMINA) */
function showFeedback(type, name, meta) {
    var box = document.getElementById('feedbackBox');
    var icon = document.getElementById('feedbackIcon');
    var nameEl = document.getElementById('feedbackName');
    var metaEl = document.getElementById('feedbackMeta');
    if (!box) return;

    box.className = 'kiosk-feedback show kiosk-feedback--' + esc(type);
    if (nameEl) nameEl.textContent = name;
    if (metaEl) metaEl.textContent = meta;

    if (icon) {
        if (type === 'ok') icon.setAttribute('icon', 'solar:check-circle-bold');
        else if (type === 'warn') icon.setAttribute('icon', 'solar:shield-warning-bold');
        else icon.setAttribute('icon', 'solar:danger-circle-bold');
    }
}

function hideFeedback() {
    var box = document.getElementById('feedbackBox');
    if (box) box.classList.remove('show');
}

/* 3. SISTEM LAZY LOAD SESI INI (DIRECT APPEND TANPA RELOAD / REFRESH BERKALA) */
function renderRecent() {
    var list = document.getElementById('recentList');
    var badge = document.getElementById('recentBadge');
    if (!list) return;

    if (badge) badge.textContent = sessionRecent.length;

    if (!sessionRecent.length) {
        list.innerHTML = '<div class="kiosk-recent__empty">Belum ada scan berhasil pada sesi ini.</div>';
        return;
    }

    list.innerHTML = sessionRecent.map(function(r) {
        return '' +
            '<div class="kiosk-recent__item">' +
                '<div class="kiosk-recent__check">' +
                    '<iconify-icon icon="solar:check-circle-bold"></iconify-icon>' +
                '</div>' +
                '<div class="kiosk-recent__info">' +
                    '<div class="kiosk-recent__name">' + esc(r.nama) + '</div>' +
                    '<div class="kiosk-recent__sub">' + esc(r.nis) + ' · ' + esc(r.kelas) + ' · ' + esc(r.pertemuan) + ' · ' + esc(r.jarak) + '</div>' +
                '</div>' +
                '<div class="kiosk-recent__time">' + esc(r.time) + '</div>' +
            '</div>';
    }).join('');
}

window.clearRecent = function() {
    sessionRecent = [];
    renderRecent();
    hideFeedback();
};

/* 4. PROSES DATA KE SERVER VIA AJAX */
function onDecoded(raw) {
    raw = (raw || '').trim();
    if (!raw) return;

    var now = Date.now();
    if (raw === lastScannedToken && (now - lastScannedTime) < 3500) {
        return; // Debounce 3.5 detik untuk scan yang sama
    }

    if (isProcessing) return;
    isProcessing = true;
    lastScannedToken = raw;
    lastScannedTime = now;

    showFeedback('warn', 'Memverifikasi Presensi...', 'Mengecek koordinat GPS dan jadwal siswa...');

    var fd = new FormData();
    fd.append('action', 'scan_qr_process');
    fd.append('id_presensi', raw);
    if (deviceCoords.lat !== null) {
        fd.append('device_lat', deviceCoords.lat);
        fd.append('device_lng', deviceCoords.lng);
        fd.append('device_acc', deviceCoords.acc || 0);
    }

    fetch(window.location.pathname, {
        method: 'POST',
        body: fd
    })
    .then(function(res) { return res.json(); })
    .then(function(res) {
        isProcessing = false;
        if (res.success) {
            soundSuccess();
            speakTTS(res.tts || res.message);
            pushToast('ok', 'Presensi Berhasil', res.toast_msg || res.message);

            var s = res.siswa || {};
            var km = res.kelas_mapel || {};
            var distText = (res.geofence && res.geofence.distance) ? (res.geofence.distance + 'm dari titik sekolah') : 'Dalam radius sekolah';

            showFeedback('ok', s.nama + ' (' + s.nis + ')', (km.nama_kelas || 'Kelas') + ' · P' + (res.pertemuan_ke || 1) + ' · ' + distText);

            // Lazy Load: langsung masukkan data ke riwayat sesi ini secara instant tanpa reload/polling
            sessionRecent.unshift({
                nama: s.nama || 'Siswa',
                nis: s.nis || '—',
                kelas: km.nama_kelas || 'Kelas',
                pertemuan: 'P' + (res.pertemuan_ke || 1),
                jarak: distText,
                time: res.waktu || ((new Date()).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) + ' WIB')
            });
            renderRecent();

        } else {
            soundError();
            speakTTS(res.tts || res.message);
            pushToast('err', res.code === 'ALREADY_RECORDED' ? 'Sudah Tercatat' : 'Presensi Ditolak', res.message);
            showFeedback('err', 'Presensi Tidak Berhasil', res.message);
        }
    })
    .catch(function(err) {
        isProcessing = false;
        soundError();
        pushToast('err', 'Koneksi Gagal', err.message);
        showFeedback('err', 'Gagal Memproses Data', err.message);
    });
}

/* 5. SUBMIT HARDWARE / INPUT MANUAL */
window.submitHwInput = function() {
    var inp = document.getElementById('hwInput');
    if (!inp) return;
    var val = inp.value.trim();
    if (!val) {
        pushToast('warn', 'Input Kosong', 'Silakan tempel atau scan kode QR terlebih dahulu.');
        inp.focus();
        return;
    }
    onDecoded(val);
    inp.value = '';
    inp.focus();
};

/* 6. EVENT LISTENERS */
document.addEventListener('DOMContentLoaded', function() {
    loadCameras();
    renderRecent();

    var inp = document.getElementById('hwInput');
    if (inp) {
        inp.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitHwInput();
            }
        });
    }

    // Auto refocus ke input hardware jika tidak berinteraksi dengan select
    document.addEventListener('click', function(e) {
        if (e.target && e.target.id !== 'camSelect' && e.target.tagName !== 'BUTTON' && e.target.tagName !== 'A') {
            var inp2 = document.getElementById('hwInput');
            if (inp2 && document.activeElement !== inp2 && document.activeElement.tagName !== 'SELECT') {
                // Biarkan fokus jika diperlukan
            }
        }
    });
});
</script>
</body>
</html>
