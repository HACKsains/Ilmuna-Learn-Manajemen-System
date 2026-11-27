<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — NON-BLOCKING WHATSAPP BOT NOTIFIER HELPER
 * ==============================================================================
 * Mengirim push event real-time bertanda tangan HMAC-SHA256 dari Ilmuna Learn
 * ke Ilmuna WA Bot (`POST /api/v1/notify.php`) dengan timeout ketat 2 detik
 * agar tidak pernah memperlambat atau menggagalkan halaman utama Ilmuna Learn.
 *
 * Kompatibel penuh dengan PHP 7.4+ & PHP 8.2/8.3.
 * ==============================================================================
 */

if (!defined('WA_BOT_API_URL')) {
    $isLocalLearn = (PHP_SAPI === 'cli')
        || in_array($_SERVER['HTTP_HOST'] ?? 'localhost', ['localhost', '127.0.0.1', 'learn.test'], true);
    define('WA_BOT_API_URL', $isLocalLearn ? 'http://localhost/wa%20bot' : 'https://wa.ilmuna.my.id');
}
if (!defined('WA_BRIDGE_PUBLIC_KEY')) {
    define('WA_BRIDGE_PUBLIC_KEY', 'pk_wa_ilmuna_2026_bridge');
}
if (!defined('WA_BRIDGE_HMAC_SECRET')) {
    define('WA_BRIDGE_HMAC_SECRET', 'sk_wa_ilmuna_2026_hmac_shared_secret');
}
if (!defined('WA_BRIDGE_ENABLED')) {
    define('WA_BRIDGE_ENABLED', true);
}

/**
 * Normalisasi nomor HP ke format E.164 tanpa '+' (628xxxxxxxxxx)
 * Sesuai API_INTEGRATION.md §4
 */
if (!function_exists('ilmuna_wa_normalize_phone')) {
    function ilmuna_wa_normalize_phone($raw)
    {
        $digits = preg_replace('/\D+/', '', (string)$raw);
        if ($digits === '' || $digits === null) {
            return '';
        }
        if (strpos($digits, '0') === 0) {
            $digits = '62' . substr($digits, 1);
        } elseif (strpos($digits, '8') === 0) {
            $digits = '62' . $digits;
        }
        $len = strlen($digits);
        return ($len >= 10 && $len <= 15) ? $digits : '';
    }
}

/**
 * Ambil seluruh nomor WA orang tua/wali yang sah untuk seorang siswa
 */
function ilmuna_wa_get_parent_phones(PDO $pdo, $siswaId)
{
    $phones = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT ot.no_hp_ayah, ot.no_hp_ibu, ot.no_hp_wali
             FROM orang_tua_siswa ots
             JOIN orang_tua ot ON ot.id = ots.id_orang_tua
             WHERE ots.id_siswa = :sid AND ot.status = 'aktif'"
        );
        $stmt->execute([':sid' => (int)$siswaId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (['no_hp_ayah', 'no_hp_ibu', 'no_hp_wali'] as $col) {
                $p = ilmuna_wa_normalize_phone($row[$col] ?? '');
                if ($p !== '') {
                    $phones[$p] = $p;
                }
            }
        }
    } catch (Throwable $e) {
        // Non-blocking
    }
    return array_values($phones);
}

/**
 * Pengirim HTTP POST HMAC-SHA256 timeout 2 detik ke WA Bot `/api/v1/notify.php`
 */
function wa_push_event(array $payload, $httpOverride = null)
{
    if (!WA_BRIDGE_ENABLED) {
        return ['success' => false, 'skipped' => true, 'message' => 'WA Bridge nonaktif'];
    }

    $url     = rtrim(WA_BOT_API_URL, '/') . '/api/v1/notify.php';
    $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($rawBody === false) {
        return ['success' => false, 'message' => 'Gagal encode JSON'];
    }

    $ts  = (string)time();
    $sig = hash_hmac('sha256', $ts . '.' . $rawBody, WA_BRIDGE_HMAC_SECRET);

    $headers = [
        'Content-Type: application/json; charset=utf-8',
        'Accept: application/json',
        'X-Ilmuna-Key: ' . WA_BRIDGE_PUBLIC_KEY,
        'X-Ilmuna-Timestamp: ' . $ts,
        'X-Ilmuna-Signature: ' . $sig,
    ];

    if (is_callable($httpOverride)) {
        return $httpOverride($url, $headers, $rawBody);
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'Ekstensi cURL tidak tersedia'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $rawBody,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT        => 2, // Strict non-blocking 2 detik per IMPLEMENTATION_PLAN Fase 6
    ]);

    $body = @curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['success' => false, 'http_status' => 0, 'message' => 'WA Bot unreachable: ' . $err];
    }

    $decoded = json_decode((string)$body, true);
    return [
        'success'     => ($code >= 200 && $code < 300) && !empty($decoded['success']),
        'http_status' => $code,
        'response'    => is_array($decoded) ? $decoded : $body,
    ];
}

/**
 * 1. Push Notifikasi Absensi Siswa (Alpa / Terlambat / Sakit / Izin) ke Orang Tua
 */
function wa_notify_absensi(PDO $pdo, $siswaId, $statusCode, $tanggal = null, $httpOverride = null)
{
    $statusMap = [
        'A' => 'Alpa',
        'T' => 'Terlambat',
        'S' => 'Sakit',
        'I' => 'Izin',
        'H' => 'Hadir',
    ];
    $upper = strtoupper(trim((string)$statusCode));
    $statusLabel = $statusMap[$upper] ?? (string)$statusCode;

    $stmt = $pdo->prepare(
        'SELECT s.nama_lengkap, k.nama_kelas
         FROM siswa s LEFT JOIN kelas k ON k.id = s.id_kelas
         WHERE s.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => (int)$siswaId]);
    $st = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$st) {
        return ['success' => false, 'message' => 'Siswa tidak ditemukan'];
    }

    $recipients = ilmuna_wa_get_parent_phones($pdo, (int)$siswaId);
    if (empty($recipients)) {
        return ['success' => false, 'message' => 'Tidak ada nomor WA orang tua terdaftar'];
    }

    $portalBase = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://learn.ilmuna.my.id';
    return wa_push_event([
        'event'        => 'absensi_alert',
        'student_name' => (string)$st['nama_lengkap'],
        'kelas'        => (string)($st['nama_kelas'] ?? '-'),
        'status'       => $statusLabel,
        'tanggal'      => $tanggal ? (string)$tanggal : date('Y-m-d'),
        'recipients'   => $recipients,
        'portal_url'   => $portalBase . '/dashboard/orang-tua',
    ], $httpOverride);
}

/**
 * 2. Push Notifikasi Catatan Pelanggaran BK ke Orang Tua
 */
function wa_notify_pelanggaran_bk(PDO $pdo, $siswaId, $jenisPelanggaran, $poin, $httpOverride = null)
{
    $stmt = $pdo->prepare(
        'SELECT s.nama_lengkap, k.nama_kelas
         FROM siswa s LEFT JOIN kelas k ON k.id = s.id_kelas
         WHERE s.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => (int)$siswaId]);
    $st = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$st) {
        return ['success' => false, 'message' => 'Siswa tidak ditemukan'];
    }

    $recipients = ilmuna_wa_get_parent_phones($pdo, (int)$siswaId);
    if (empty($recipients)) {
        return ['success' => false, 'message' => 'Tidak ada nomor WA orang tua terdaftar'];
    }

    $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(poin), 0) FROM catatan_pelanggaran_siswa WHERE id_siswa = :sid');
    $sumStmt->execute([':sid' => (int)$siswaId]);
    $totalPoin = (int)$sumStmt->fetchColumn();

    return wa_push_event([
        'event'                => 'pelanggaran_bk',
        'student_name'         => (string)$st['nama_lengkap'],
        'kelas'                => (string)($st['nama_kelas'] ?? '-'),
        'jenis_pelanggaran'    => (string)$jenisPelanggaran,
        'poin'                 => (int)$poin,
        'indeks_disiplin_sisa' => max(0, 100 - $totalPoin),
        'recipients'           => $recipients,
    ], $httpOverride);
}

/**
 * 3. Push Notifikasi Pengajuan Perizinan Baru (GPS + Bukti + Tombol Setujui/Tolak) ke Guru
 */
function wa_notify_perizinan_baru(PDO $pdo, $izinId, $httpOverride = null)
{
    $stmt = $pdo->prepare(
        'SELECT p.*, s.nama_lengkap AS student_name, k.nama_kelas,
                g.no_hp AS guru_phone
         FROM perizinan p
         JOIN siswa s ON s.id = p.id_siswa
         LEFT JOIN kelas k ON k.id = s.id_kelas
         LEFT JOIN guru g ON g.id = p.id_guru
         WHERE p.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => (int)$izinId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['success' => false, 'message' => 'Data perizinan tidak ditemukan'];
    }

    $guruPhone = ilmuna_wa_normalize_phone($row['guru_phone'] ?? '');
    if ($guruPhone === '' && !empty($row['id_kelas_mata_pelajaran'])) {
        // Cari guru pengampu dari kelas_mata_pelajaran -> guru_mata_pelajaran -> guru
        $gStmt = $pdo->prepare(
            'SELECT g.no_hp
             FROM kelas_mata_pelajaran kmp
             JOIN guru_mata_pelajaran gmp ON gmp.id = kmp.id_guru_mata_pelajaran
             JOIN guru g ON g.id = gmp.id_guru
             WHERE kmp.id = :kmp LIMIT 1'
        );
        $gStmt->execute([':kmp' => (int)$row['id_kelas_mata_pelajaran']]);
        $guruPhone = ilmuna_wa_normalize_phone((string)$gStmt->fetchColumn());
    }

    if ($guruPhone === '') {
        return ['success' => false, 'message' => 'Nomor WA guru pengampu belum tersedia'];
    }

    $gpsLink = '';
    if (!empty($row['latitude']) && !empty($row['longitude'])) {
        $gpsLink = 'https://maps.google.com/?q=' . $row['latitude'] . ',' . $row['longitude'];
    }

    $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://learn.ilmuna.my.id';
    $buktiUrl = '';
    if (!empty($row['bukti_perizinan'])) {
        $b = (string)$row['bukti_perizinan'];
        $buktiUrl = (strpos($b, 'http') === 0) ? $b : ($baseUrl . '/' . ltrim($b, '/'));
    }

    return wa_push_event([
        'event'        => 'perizinan_baru',
        'izin_id'      => (int)$row['id'],
        'student_name' => (string)$row['student_name'],
        'kelas'        => (string)($row['nama_kelas'] ?? '-'),
        'jenis_izin'   => (string)$row['jenis_izin'],
        'alasan'       => (string)$row['alasan'],
        'gps_link'     => $gpsLink,
        'bukti_url'    => $buktiUrl,
        'recipients'   => [$guruPhone],
    ], $httpOverride);
}

/**
 * 4. Push Konfirmasi Status Perizinan (Disetujui / Ditolak) kembali ke Siswa & Orang Tua
 */
function wa_notify_perizinan_status(PDO $pdo, $izinId, $statusLabel, $guruName, $httpOverride = null)
{
    $stmt = $pdo->prepare(
        'SELECT p.id_siswa, p.tanggal_izin, s.nama_lengkap, s.no_hp AS siswa_phone
         FROM perizinan p
         JOIN siswa s ON s.id = p.id_siswa
         WHERE p.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => (int)$izinId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['success' => false, 'message' => 'Perizinan tidak ditemukan'];
    }

    $recipients = ilmuna_wa_get_parent_phones($pdo, (int)$row['id_siswa']);
    $sp = ilmuna_wa_normalize_phone($row['siswa_phone'] ?? '');
    if ($sp !== '' && !in_array($sp, $recipients, true)) {
        $recipients[] = $sp;
    }

    if (empty($recipients)) {
        return ['success' => false, 'message' => 'Tidak ada nomor penerima siswa/ortu'];
    }

    return wa_push_event([
        'event'        => 'perizinan_status',
        'student_name' => (string)$row['nama_lengkap'],
        'status'       => (string)$statusLabel,
        'guru_name'    => (string)$guruName,
        'tanggal'      => (string)$row['tanggal_izin'],
        'recipients'   => $recipients,
    ], $httpOverride);
}

/**
 * 5. Push Auto-Broadcast Akademik (Tugas, Proyek, Agenda, Asesmen, LKPD, Materi, Mutaba'ah)
 */
function wa_notify_academic_broadcast(
    $sourceModule,
    $sourceRefId,
    $title,
    array $kelasIds,
    $deadlineAt = null,
    $summary = '',
    $actionUrl = '',
    array $targetRoles = ['siswa', 'orang_tua', 'group_kelas'],
    $httpOverride = null
) {
    $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://learn.ilmuna.my.id';
    return wa_push_event([
        'event'         => 'academic_broadcast',
        'source_module' => (string)$sourceModule,
        'source_ref_id' => (int)$sourceRefId,
        'title'         => (string)$title,
        'kelas_ids'     => array_values(array_map('intval', $kelasIds)),
        'target_roles'  => $targetRoles,
        'deadline_at'   => $deadlineAt,
        'summary'       => (string)$summary,
        'action_url'    => $actionUrl !== '' ? (string)$actionUrl : ($baseUrl . '/dashboard/siswa'),
    ], $httpOverride);
}
