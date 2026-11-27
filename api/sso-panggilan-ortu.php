<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — API NOTIFIKASI PANGGILAN ORANG TUA (UNTUK MARGO ONE SSO)
 * ==============================================================================
 * Mengembalikan daftar panggilan orang tua aktif (`perlu_panggilan_ortu = 1`)
 * berdasarkan email / username akun Orang Tua yang sedang login di Margo One SSO.
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once __DIR__ . '/../config.php';

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');

try {
    $email    = trim((string)($_GET['email'] ?? ''));
    $username = trim((string)($_GET['username'] ?? ''));
    if ($email === '' && $username === '') {
        echo json_encode(['success' => true, 'items' => []]);
        exit;
    }

    $pdo = getDBConnection();
    $pdo->exec("SET time_zone = '+07:00'");

    // Pastikan kolom tersedia
    $cols = array_column($pdo->query("SHOW COLUMNS FROM catatan_pelanggaran_siswa")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('perlu_panggilan_ortu', $cols, true)) {
        echo json_encode(['success' => true, 'items' => []]);
        exit;
    }

    // Cari orang_tua berdasarkan email_gmail atau users.email / users.username
    $ortuId = 0;
    if ($email !== '') {
        $st1 = $pdo->prepare("SELECT id FROM orang_tua WHERE LOWER(email_gmail) = LOWER(?) LIMIT 1");
        $st1->execute([$email]);
        $ortuId = (int)$st1->fetchColumn();
    }
    if ($ortuId <= 0 && ($email !== '' || $username !== '')) {
        $st2 = $pdo->prepare("
            SELECT o.id
            FROM orang_tua o
            JOIN users u ON u.id = o.id_user
            WHERE (? != '' AND LOWER(u.email) = LOWER(?))
               OR (? != '' AND LOWER(u.username) = LOWER(?))
            LIMIT 1
        ");
        $st2->execute([$email, $email, $username, $username]);
        $ortuId = (int)$st2->fetchColumn();
    }

    if ($ortuId <= 0) {
        echo json_encode(['success' => true, 'items' => []]);
        exit;
    }

    $st = $pdo->prepare("
        SELECT p.id, p.id_siswa, s.nama_lengkap AS nama_siswa, COALESCE(k.nama_kelas, '-') AS nama_kelas,
               p.tanggal_kejadian, p.kategori_pelanggaran, p.tingkat_pelanggaran, p.poin,
               p.status_penanganan, p.tindak_lanjut,
               p.perlu_panggilan_ortu, p.jadwal_panggilan_ortu, p.catatan_panggilan_ortu
        FROM orang_tua_siswa ots
        JOIN siswa s ON s.id = ots.id_siswa
        LEFT JOIN kelas k ON k.id = s.id_kelas
        JOIN catatan_pelanggaran_siswa p ON p.id_siswa = s.id
        WHERE ots.id_orang_tua = ?
          AND p.perlu_panggilan_ortu = 1
          AND p.status_penanganan != 'Selesai'
        ORDER BY p.jadwal_panggilan_ortu ASC, p.tanggal_kejadian DESC, p.id DESC
        LIMIT 20
    ");
    $st->execute([$ortuId]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'items' => [], 'message' => $e->getMessage()]);
}
