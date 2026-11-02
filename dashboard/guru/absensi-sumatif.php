<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — ABSENSI ASESMEN SUMATIF (PORTAL GURU)
 * ==============================================================================
 * Aturan Waktu Absensi Asesmen:
 * - Minimal 30 menit sebelum waktu mulai (jam_mulai - 30 menit).
 *   Sebelum waktu ini -> Belum dibuka (tidak dapat absensi).
 * - Maksimal 15 menit setelah waktu mulai -> Hadir (Tepat Waktu).
 * - Lebih dari 15 menit s/d 20 menit setelah waktu mulai -> Terlambat.
 * - 20 menit ke atas setelah waktu mulai -> Ditutup (tidak dapat absensi).
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole(['guru', 'admin']);
$activeRole = hasRole('guru') ? 'guru' : 'admin';
$GLOBALS['activeRole'] = $activeRole;

$pdo = getDBConnection();
$currentUser = getCurrentUser();
$pageTitle = 'Presensi Asesmen Sumatif — Guru';

// Lookup data guru berdasarkan id_user
$currentGuru = null;
$idGuruLogged = 0;
try {
    $gStmt = $pdo->prepare("SELECT * FROM guru WHERE id_user = ? LIMIT 1");
    $gStmt->execute([$currentUser['id']]);
    $currentGuru = $gStmt->fetch();
    if ($currentGuru) {
        $idGuruLogged = (int)$currentGuru['id'];
    }
} catch (Exception $e) { }

/**
 * Validasi Jendela Waktu Absensi Asesmen Sumatif
 * Sesuai aturan:
 * - Minimal 30 menit sebelum waktu mulai (sebelum itu = belum dibuka)
 * - s/d 15 menit setelah waktu mulai = Hadir (tepat waktu)
 * - > 15 menit s/d 20 menit setelah waktu mulai = Terlambat
 * - >= 20 menit setelah waktu mulai = Ditutup (tidak dapat absensi)
 */
function getAbsensiWindowStatus($asum) {
    if (empty($asum['tanggal_pelaksanaan']) || empty($asum['jam_mulai'])) {
        return [
            'allowed'       => true,
            'status'        => 'Hadir',
            'reason'        => 'Jadwal fleksibel (tanpa pembatasan waktu).',
            'diff_minutes'  => 0,
            'window_status' => 'open',
            'jam_buka'      => '—',
            'jam_mulai'     => '—',
            'jam_toleransi' => '—',
            'jam_tutup'     => '—',
            'window_label'  => 'Jadwal Terbuka'
        ];
    }

    $tgl = trim($asum['tanggal_pelaksanaan']);
    $jamMulai = trim($asum['jam_mulai']);
    $jadwalTs = strtotime($tgl . ' ' . $jamMulai);

    if ($jadwalTs === false) {
        return [
            'allowed'       => true,
            'status'        => 'Hadir',
            'reason'        => 'Jadwal tidak valid.',
            'diff_minutes'  => 0,
            'window_status' => 'open',
            'jam_buka'      => '—',
            'jam_mulai'     => '—',
            'jam_toleransi' => '—',
            'jam_tutup'     => '—',
            'window_label'  => 'Jadwal Terbuka'
        ];
    }

    $nowTs = time();
    $diffSec = $nowTs - $jadwalTs;
    $diffMinutes = $diffSec / 60.0;

    $jamBukaStr      = date('H:i', $jadwalTs - (30 * 60));
    $jamMulaiStr     = date('H:i', $jadwalTs);
    $jamToleransiStr = date('H:i', $jadwalTs + (15 * 60));
    $jamTutupStr     = date('H:i', $jadwalTs + (20 * 60));

    // 1. Minimal 30 menit sebelum waktu mulai (sebelum itu = belum dibuka)
    if ($diffMinutes < -30) {
        return [
            'allowed'       => false,
            'status'        => null,
            'reason'        => "Absensi belum dibuka. Absensi baru dapat dilakukan mulai 30 menit sebelum waktu mulai (Pukul {$jamBukaStr} WIB). Jam mulai ujian: {$jamMulaiStr} WIB.",
            'diff_minutes'  => round($diffMinutes, 1),
            'window_status' => 'not_started',
            'jam_buka'      => $jamBukaStr,
            'jam_mulai'     => $jamMulaiStr,
            'jam_toleransi' => $jamToleransiStr,
            'jam_tutup'     => $jamTutupStr,
            'window_label'  => "Buka Pkl {$jamBukaStr}"
        ];
    }

    // 2. 20 menit keatas dari waktu mulai tidak dapat absensi (ditutup)
    if ($diffMinutes >= 20) {
        return [
            'allowed'       => false,
            'status'        => null,
            'reason'        => "Waktu absensi telah ditutup. Batas maksimal absensi adalah 20 menit setelah jam mulai ujian (Pukul {$jamTutupStr} WIB).",
            'diff_minutes'  => round($diffMinutes, 1),
            'window_status' => 'closed',
            'jam_buka'      => $jamBukaStr,
            'jam_mulai'     => $jamMulaiStr,
            'jam_toleransi' => $jamToleransiStr,
            'jam_tutup'     => $jamTutupStr,
            'window_label'  => 'Ditutup (>20 Mnt)'
        ];
    }

    // 3. Lebih dari 15 menit setelah waktu mulai = Terlambat
    if ($diffMinutes > 15) {
        return [
            'allowed'       => true,
            'status'        => 'Terlambat',
            'reason'        => "Absensi melewati toleransi 15 menit (Pukul {$jamToleransiStr} WIB). Kehadiran otomatis dicatat sebagai TERLAMBAT.",
            'diff_minutes'  => round($diffMinutes, 1),
            'window_status' => 'late',
            'jam_buka'      => $jamBukaStr,
            'jam_mulai'     => $jamMulaiStr,
            'jam_toleransi' => $jamToleransiStr,
            'jam_tutup'     => $jamTutupStr,
            'window_label'  => 'Toleransi Terlambat'
        ];
    }

    // 4. Antara 30 menit sebelum s/d 15 menit setelah waktu mulai = Hadir tepat waktu
    return [
        'allowed'       => true,
        'status'        => 'Hadir',
        'reason'        => 'Absensi tepat waktu (Hadir).',
        'diff_minutes'  => round($diffMinutes, 1),
        'window_status' => 'open',
        'jam_buka'      => $jamBukaStr,
        'jam_mulai'     => $jamMulaiStr,
        'jam_toleransi' => $jamToleransiStr,
        'jam_tutup'     => $jamTutupStr,
        'window_label'  => 'Dibuka (Tepat Waktu)'
    ];
}

/* ==============================================================================
 * AJAX HANDLERS
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    function sendJson($success, $data = [], $message = '') {
        ob_end_clean();
        echo json_encode(['success' => $success, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $act = $_POST['action'] ?? '';

    /* ---------- GET DAFTAR ASESMEN ---------- */
    if ($act === 'get_asesmen_list') {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `absensi_asesmen_sumatif` (
                `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_asesmen_sumatif` INT(11) UNSIGNED NOT NULL,
                `nomor_peserta` VARCHAR(50) NOT NULL,
                `id_siswa` INT(11) UNSIGNED NOT NULL,
                `id_kelas_mata_pelajaran` INT(11) UNSIGNED NOT NULL,
                `status_kehadiran` ENUM('Hadir','Izin','Sakit','Alpha','Terlambat') NOT NULL DEFAULT 'Hadir',
                `metode_absen` ENUM('Scan QR','Manual') NOT NULL DEFAULT 'Scan QR',
                `waktu_absen` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `diabsen_oleh` INT(11) DEFAULT NULL,
                `keterangan` TEXT DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_aasum_ujian_siswa` (`id_asesmen_sumatif`, `id_siswa`),
                KEY `idx_aasum_as` (`id_asesmen_sumatif`),
                KEY `idx_aasum_siswa` (`id_siswa`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Exception $eCreate) { }

        $scope = $_POST['scope'] ?? 'all'; // 'mine' atau 'all'
        $params = [];
        $whereScope = "";

        if ($scope === 'mine' && $idGuruLogged > 0) {
            $whereScope = " AND (asum.id_guru = :id_guru_owner OR kmp.id_guru_mata_pelajaran IN (SELECT id FROM guru_mata_pelajaran WHERE id_guru = :id_guru_gmp))";
            $params[':id_guru_owner'] = $idGuruLogged;
            $params[':id_guru_gmp']   = $idGuruLogged;
        }

        try {
            $sql = "
                SELECT asum.id, asum.judul_asesmen, asum.jenis_sumatif,
                       asum.tanggal_pelaksanaan, asum.jam_mulai, asum.jam_selesai,
                       asum.durasi_menit, asum.status, asum.id_guru,
                       mp.nama_mata_pelajaran, mp.kode_mapel,
                       k.nama_kelas,
                       COUNT(DISTINCT aas.id) AS total_peserta,
                       COUNT(DISTINCT absensi.id) AS total_hadir
                FROM asesmen_sumatif asum
                JOIN kelas_mata_pelajaran kmp ON asum.id_kelas_mata_pelajaran = kmp.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                LEFT JOIN akses_asesmen_sumatif aas ON aas.id_asesmen_sumatif = asum.id
                LEFT JOIN absensi_asesmen_sumatif absensi ON absensi.id_asesmen_sumatif = asum.id
                WHERE asum.status IN ('Aktif', 'Draft') {$whereScope}
                GROUP BY asum.id, asum.judul_asesmen, asum.jenis_sumatif,
                         asum.tanggal_pelaksanaan, asum.jam_mulai, asum.jam_selesai,
                         asum.durasi_menit, asum.status, asum.id_guru,
                         mp.nama_mata_pelajaran, mp.kode_mapel, k.nama_kelas
                ORDER BY asum.tanggal_pelaksanaan DESC, asum.jam_mulai DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Perkaya setiap asesmen dengan kalkulasi jendela waktu
            foreach ($rows as &$r) {
                $win = getAbsensiWindowStatus($r);
                $r['window_status']   = $win['window_status'];
                $r['window_allowed']  = $win['allowed'];
                $r['window_reason']   = $win['reason'];
                $r['window_label']    = $win['window_label'];
                $r['jam_buka']        = $win['jam_buka'];
                $r['jam_mulai_fmt']   = $win['jam_mulai'];
                $r['jam_toleransi']   = $win['jam_toleransi'];
                $r['jam_tutup']       = $win['jam_tutup'];
                $r['diff_minutes']    = $win['diff_minutes'];
                $r['is_mine']         = ($idGuruLogged > 0 && (int)($r['id_guru'] ?? 0) === $idGuruLogged);
            }
            unset($r);

            sendJson(true, $rows);
        } catch (Exception $e) {
            sendJson(false, [], 'Gagal memuat daftar asesmen: ' . $e->getMessage());
        }
    }

    /* ---------- LOOKUP SISWA (DENGAN CEK JENDELA WAKTU) ---------- */
    if ($act === 'lookup_siswa') {
        $nomorPeserta = trim($_POST['nomor_peserta'] ?? '');
        $idAsesmen    = (int)($_POST['id_asesmen'] ?? 0);
        if (!$nomorPeserta || !$idAsesmen) sendJson(false, [], 'Data tidak valid.');

        try {
            // Cek jadwal asesmen terlebih dahulu
            $stmtAsum = $pdo->prepare("SELECT id, judul_asesmen, tanggal_pelaksanaan, jam_mulai, jam_selesai FROM asesmen_sumatif WHERE id = ? LIMIT 1");
            $stmtAsum->execute([$idAsesmen]);
            $asumInfo = $stmtAsum->fetch();
            if (!$asumInfo) sendJson(false, [], 'Asesmen tidak ditemukan.');

            $win = getAbsensiWindowStatus($asumInfo);
            if (!$win['allowed']) {
                sendJson(false, [
                    'window_status' => $win['window_status'],
                    'jam_buka'      => $win['jam_buka'],
                    'jam_tutup'     => $win['jam_tutup']
                ], $win['reason']);
            }

            $stmt = $pdo->prepare("
                SELECT aas.id AS id_akses, aas.nomor_peserta, aas.id_siswa,
                       aas.id_kelas_mata_pelajaran, aas.status_akses,
                       s.nisn, s.nis, s.nama_lengkap, s.foto,
                       k.nama_kelas,
                       mp.nama_mata_pelajaran,
                       absensi.id AS id_absensi,
                       absensi.status_kehadiran, absensi.waktu_absen, absensi.metode_absen
                FROM akses_asesmen_sumatif aas
                JOIN siswa s ON aas.id_siswa = s.id
                LEFT JOIN kelas k ON s.id_kelas = k.id
                JOIN kelas_mata_pelajaran kmp ON aas.id_kelas_mata_pelajaran = kmp.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN absensi_asesmen_sumatif absensi ON (
                    absensi.id_asesmen_sumatif = aas.id_asesmen_sumatif
                    AND absensi.id_siswa = aas.id_siswa
                )
                WHERE aas.nomor_peserta = :nomor AND aas.id_asesmen_sumatif = :id_asesmen
                LIMIT 1
            ");
            $stmt->execute([':nomor' => $nomorPeserta, ':id_asesmen' => $idAsesmen]);
            $siswa = $stmt->fetch();
            if (!$siswa) sendJson(false, [], 'Nomor peserta tidak ditemukan dalam daftar peserta asesmen ini.');

            $siswa['window_status'] = $win['window_status'];
            $siswa['window_reason'] = $win['reason'];
            sendJson(true, $siswa);
        } catch (Exception $e) {
            sendJson(false, [], 'Gagal mencari data siswa: ' . $e->getMessage());
        }
    }

    /* ---------- SIMPAN ABSENSI (ATURAN WAKTU & TOLAK GANDA) ---------- */
    if ($act === 'simpan_absensi') {
        $nomorPeserta    = trim($_POST['nomor_peserta'] ?? '');
        $idAsesmen       = (int)($_POST['id_asesmen'] ?? 0);
        $idSiswa         = (int)($_POST['id_siswa'] ?? 0);
        $idKmp           = (int)($_POST['id_kelas_mata_pelajaran'] ?? 0);
        $statusKehadiran = $_POST['status_kehadiran'] ?? 'Hadir';
        $metodeAbsen     = $_POST['metode_absen'] ?? 'Scan QR';
        $keterangan      = trim($_POST['keterangan'] ?? '');
        $allowedStatus   = ['Hadir','Izin','Sakit','Alpha','Terlambat'];
        $allowedMetode   = ['Scan QR','Manual'];

        if (!$nomorPeserta || !$idAsesmen || !$idSiswa || !$idKmp) sendJson(false, [], 'Data absensi tidak lengkap.');
        if (!in_array($statusKehadiran, $allowedStatus, true)) sendJson(false, [], 'Status kehadiran tidak valid.');
        if (!in_array($metodeAbsen, $allowedMetode, true)) sendJson(false, [], 'Metode absensi tidak valid.');

        try {
            // 1. Validasi Jadwal Waktu Asesmen
            $stmtAsum = $pdo->prepare("SELECT id, judul_asesmen, tanggal_pelaksanaan, jam_mulai, jam_selesai FROM asesmen_sumatif WHERE id = ? LIMIT 1");
            $stmtAsum->execute([$idAsesmen]);
            $asumInfo = $stmtAsum->fetch();
            if (!$asumInfo) sendJson(false, [], 'Data asesmen tidak ditemukan.');

            $win = getAbsensiWindowStatus($asumInfo);

            // Tolak jika belum dibuka (< -30 mnt) atau sudah ditutup (>= +20 mnt)
            if (!$win['allowed']) {
                sendJson(false, [
                    'window_status' => $win['window_status'],
                    'jam_buka'      => $win['jam_buka'],
                    'jam_tutup'     => $win['jam_tutup']
                ], $win['reason']);
            }

            // Jika berada dalam periode keterlambatan (15 < diff < 20 mnt), paksa status 'Terlambat'
            if ($win['window_status'] === 'late') {
                $statusKehadiran = 'Terlambat';
            }

            // 2. Cek apakah sudah pernah absen sebelumnya (tolak ganda)
            $cek = $pdo->prepare("SELECT id, status_kehadiran, waktu_absen FROM absensi_asesmen_sumatif WHERE id_asesmen_sumatif=:a AND id_siswa=:s LIMIT 1");
            $cek->execute([':a' => $idAsesmen, ':s' => $idSiswa]);
            $existing = $cek->fetch();

            if ($existing) {
                $waktuInfo = !empty($existing['waktu_absen']) ? ' pada ' . date('H:i:s', strtotime($existing['waktu_absen'])) . ' WIB' : '';
                sendJson(false, [
                    'already_absen'    => true,
                    'status_kehadiran' => $existing['status_kehadiran'],
                    'waktu_absen'      => $existing['waktu_absen']
                ], 'Siswa sudah absen (' . $existing['status_kehadiran'] . $waktuInfo . '). Absensi tidak dapat diulang.');
            }

            // 3. Simpan ke database
            $ins = $pdo->prepare("
                INSERT INTO absensi_asesmen_sumatif(
                    id_asesmen_sumatif, nomor_peserta, id_siswa, id_kelas_mata_pelajaran,
                    status_kehadiran, metode_absen, waktu_absen, diabsen_oleh, keterangan
                ) VALUES (
                    :a, :n, :s, :k2, :st, :mt, NOW(), :p, :ket
                )
            ");
            $ins->execute([
                ':a'   => $idAsesmen,
                ':n'   => $nomorPeserta,
                ':s'   => $idSiswa,
                ':k2'  => $idKmp,
                ':st'  => $statusKehadiran,
                ':mt'  => $metodeAbsen,
                ':p'   => $currentUser['id'],
                ':ket' => $keterangan ?: null
            ]);

            $successMsg = ($statusKehadiran === 'Terlambat')
                ? 'Absensi berhasil dicatat sebagai TERLAMBAT (lewat 15 menit dari jam mulai ujian).'
                : 'Absensi berhasil! Status: ' . $statusKehadiran . ' (Tepat Waktu).';

            sendJson(true, [
                'status'        => $statusKehadiran,
                'window_status' => $win['window_status'],
                'waktu_absen'   => date('Y-m-d H:i:s')
            ], $successMsg);

        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                sendJson(false, [], 'Siswa sudah absen pada asesmen ini.');
            }
            sendJson(false, [], 'Gagal menyimpan absensi: ' . $e->getMessage());
        }
    }

    /* ---------- MONITORING (REALTIME / POLL) ---------- */
    if ($act === 'get_monitoring') {
        $idAsesmen = (int)($_POST['id_asesmen'] ?? 0);
        if (!$idAsesmen) sendJson(false, [], 'ID asesmen tidak valid.');

        try {
            $cekAkses = $pdo->prepare("SELECT COUNT(*) FROM akses_asesmen_sumatif WHERE id_asesmen_sumatif = :id");
            $cekAkses->execute([':id' => $idAsesmen]);
            if ((int)$cekAkses->fetchColumn() === 0) sendJson(true, [], '');

            $stmt = $pdo->prepare("
                SELECT aas.nomor_peserta, aas.status_akses,
                       s.nama_lengkap, s.nisn, s.nis, s.foto,
                       k.nama_kelas,
                       absensi.id AS id_absensi,
                       absensi.status_kehadiran,
                       absensi.metode_absen,
                       absensi.waktu_absen,
                       u.full_name AS nama_petugas
                FROM akses_asesmen_sumatif aas
                JOIN siswa s ON aas.id_siswa = s.id
                LEFT JOIN kelas k ON s.id_kelas = k.id
                LEFT JOIN absensi_asesmen_sumatif absensi ON (
                    absensi.id_asesmen_sumatif = aas.id_asesmen_sumatif
                    AND absensi.id_siswa = aas.id_siswa
                )
                LEFT JOIN users u ON absensi.diabsen_oleh = u.id
                WHERE aas.id_asesmen_sumatif = :id_asum
                ORDER BY aas.nomor_peserta ASC
            ");
            $stmt->execute([':id_asum' => $idAsesmen]);
            sendJson(true, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            sendJson(false, [], 'Gagal memuat monitoring: ' . $e->getMessage());
        }
    }

    sendJson(false, [], 'Action tidak dikenali.');
}

$namaPetugas = $currentGuru['nama_guru'] ?? ($currentUser['full_name'] ?? 'Dewan Guru');
$nipPetugas  = $currentGuru['nip'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($pageTitle) ?> — Ilmuna Learn</title>
    <?= renderFavicon() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?= renderIconifyScript() ?>
    <?php if (function_exists('loadCentralTemplates')) loadCentralTemplates(['modal', 'calendar', 'table']); ?>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --navy:#1D3B5E; --teal:#0D7A87; --sky:#52B2DC;
            --emerald:#10B981; --amber:#F59E0B; --rose:#F43F5E; --violet:#8B5CF6;
            --font-h:'Outfit',sans-serif; --font-b:'Plus Jakarta Sans',sans-serif;
        }
        html { height: 100%; scroll-behavior: smooth; }
        body { font-family: var(--font-b); background: #F0F4F8; color: #334155; min-height: 100vh; -webkit-font-smoothing: antialiased; }

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

        #bgCanvas { position:fixed; inset:0; pointer-events:none; z-index:0; }
        .glow-orb { position:fixed; border-radius:50%; filter:blur(120px); pointer-events:none; z-index:0; }
        .glow-orb-1 { width:500px; height:500px; background:rgba(13,122,135,.08); top:-100px; left:-100px; }
        .glow-orb-2 { width:400px; height:400px; background:rgba(82,178,220,.07); bottom:-80px; right:-80px; }

        .topbar {
            position:sticky; top:0; z-index:100;
            background:rgba(255,255,255,.92); backdrop-filter:blur(16px);
            border-bottom:1px solid rgba(29,59,94,.10);
            padding:12px 20px;
            display:flex; align-items:center; gap:12px;
            box-shadow:0 2px 16px rgba(29,59,94,.08);
        }
        .topbar-logo { display:flex; align-items:center; gap:10px; text-decoration:none; }
        .topbar-logo img { height:32px; width:auto; }
        .topbar-logo .app-name { font-family:var(--font-h); font-weight:800; font-size:1rem; color:var(--navy); letter-spacing:-.02em; line-height:1; }
        .topbar-logo .app-sub  { font-size:.65rem; color:var(--teal); font-weight:600; letter-spacing:.05em; text-transform:uppercase; }
        .topbar-sep { flex:1; }
        .topbar-user { display:flex; align-items:center; gap:8px; font-size:.78rem; color:#64748B; }
        .topbar-user strong { color:var(--navy); font-weight:700; }
        .topbar-back { display:inline-flex; align-items:center; gap:6px; padding:6px 14px; border-radius:8px; background:rgba(13,122,135,.10); color:var(--teal); font-size:.78rem; font-weight:600; text-decoration:none; border:1px solid rgba(13,122,135,.20); transition:all .2s; }
        .topbar-back:hover { background:var(--teal); color:#fff; }

        .main-wrap { position:relative; z-index:1; max-width:1380px; margin:0 auto; padding:24px 16px 48px; }
        @media(min-width:768px){ .main-wrap{ padding:32px 24px 64px; } }

        .page-header { display:flex; flex-wrap:wrap; align-items:flex-end; gap:16px; margin-bottom:24px; }
        .page-header-left { flex:1; min-width:200px; }
        .page-eyebrow { display:inline-flex; align-items:center; gap:6px; padding:4px 12px; border-radius:999px; background:rgba(13,122,135,.10); color:var(--teal); font-size:.72rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; margin-bottom:8px; }
        .page-eyebrow .dot { width:6px; height:6px; border-radius:50%; background:var(--teal); animation:pulseDot 2s ease-in-out infinite; box-shadow:0 0 6px var(--teal); }
        @keyframes pulseDot { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(.85)} }
        .page-title { font-family:var(--font-h); font-size:1.7rem; font-weight:900; color:var(--navy); letter-spacing:-.03em; line-height:1.1; margin-bottom:4px; }
        .page-title span { color:var(--teal); }
        .page-subtitle { color:#64748B; font-size:.82rem; font-weight:500; }
        .page-subtitle strong { color: var(--emerald); font-weight: 700; }

        /* TIME RULES NOTICE CARD */
        .rules-card {
            background: linear-gradient(135deg, rgba(29,59,94,.04) 0%, rgba(13,122,135,.07) 100%);
            border: 1px solid rgba(13,122,135,.18);
            border-radius: 16px;
            padding: 14px 18px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .rules-icon {
            width: 42px; height: 42px; border-radius: 12px;
            background: linear-gradient(135deg, var(--navy), var(--teal));
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; flex-shrink: 0; box-shadow: 0 4px 12px rgba(13,122,135,.25);
        }
        .rules-content { flex: 1; min-width: 240px; }
        .rules-title { font-family: var(--font-h); font-size: .88rem; font-weight: 800; color: var(--navy); margin-bottom: 2px; }
        .rules-desc { font-size: .75rem; color: #475569; line-height: 1.45; }
        .rules-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
        .r-chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px; font-size: .68rem; font-weight: 700; }
        .r-chip-open { background: rgba(16,185,129,.15); color: #059669; }
        .r-chip-late { background: rgba(245,158,11,.15); color: #B45309; }
        .r-chip-closed { background: rgba(244,63,94,.15); color: #BE185D; }

        @keyframes fadeUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

        .selector-card { background:rgba(255,255,255,.90); backdrop-filter:blur(12px); border-radius:20px; border:1px solid rgba(255,255,255,.80); box-shadow:0 4px 24px rgba(29,59,94,.10); padding:24px; margin-bottom:24px; animation:fadeUp .5s cubic-bezier(.22,1,.36,1) both; }
        .selector-header-wrap { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
        .selector-title { font-family:var(--font-h); font-size:1rem; font-weight:700; color:var(--navy); display:flex; align-items:center; gap:8px; }
        .selector-title i { color:var(--teal); font-size:.9rem; }

        /* Filter Switch (Asesmen Saya vs Semua) */
        .filter-pills { display: flex; background: rgba(241,245,249,.9); border-radius: 10px; padding: 3px; gap: 4px; }
        .filter-pill { padding: 6px 14px; border-radius: 8px; border: none; background: transparent; font-family: var(--font-b); font-size: .75rem; font-weight: 600; color: #64748B; cursor: pointer; transition: all .2s; display: inline-flex; align-items: center; gap: 5px; }
        .filter-pill.active { background: #fff; color: var(--navy); box-shadow: 0 2px 6px rgba(29,59,94,.10); font-weight: 700; }

        .asesmen-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(290px,1fr)); gap:14px; }

        .asesmen-card { border-radius:14px; border:2px solid transparent; padding:16px; cursor:pointer; background:rgba(248,250,252,.92); transition:all .25s cubic-bezier(.4,0,.2,1); position:relative; overflow:hidden; }
        .asesmen-card:hover { border-color:var(--teal); transform:translateY(-2px); box-shadow:0 8px 24px rgba(13,122,135,.15); }
        .asesmen-card.selected { border-color:var(--teal); background:rgba(13,122,135,.06); box-shadow:0 6px 20px rgba(13,122,135,.18); }
        .ac-top-meta { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 4px; }
        .ac-mapel { font-size:.68rem; font-weight:700; color:var(--teal); letter-spacing:.06em; text-transform:uppercase; }
        .ac-judul { font-family:var(--font-h); font-size:.92rem; font-weight:800; color:var(--navy); line-height:1.3; margin-bottom:6px; }
        .ac-tags { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; flex-wrap: wrap; }
        .ac-jenis { display:inline-block; padding:2px 8px; border-radius:6px; font-size:.65rem; font-weight:700; background:rgba(13,122,135,.12); color:var(--teal); }
        
        /* Window status badges on card */
        .ac-window-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px; font-size: .65rem; font-weight: 700; letter-spacing: .02em; }
        .badge-window-open    { background: rgba(16,185,129,.15); color: #059669; }
        .badge-window-late    { background: rgba(245,158,11,.15); color: #B45309; }
        .badge-window-pending { background: rgba(82,178,220,.15); color: #0284C7; }
        .badge-window-closed  { background: rgba(244,63,94,.12); color: #BE185D; }

        .ac-meta { display:flex; flex-wrap:wrap; gap:6px; margin-bottom: 10px; }
        .ac-chip { display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:6px; font-size:.68rem; font-weight:600; color:#475569; background:rgba(226,232,240,.75); }
        .ac-chip i { font-size:.58rem; color:#94A3B8; }
        .ac-progress { margin-top:8px; padding-top:10px; border-top:1px solid rgba(226,232,240,.8); }
        .ac-progress-label { display:flex; justify-content:space-between; font-size:.68rem; color:#64748B; margin-bottom:4px; font-weight:600; }
        .ac-progress-bar { height:5px; border-radius:999px; background:#E2E8F0; overflow:hidden; }
        .ac-progress-fill { height:100%; border-radius:999px; background:linear-gradient(90deg,var(--teal),var(--sky)); transition:width .6s cubic-bezier(.4,0,.2,1); }

        /* LIVE WINDOW STATUS BANNER (DI ATAS SCANNER) */
        .window-banner {
            border-radius: 12px;
            padding: 12px 14px;
            font-size: .78rem;
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.45;
        }
        .window-banner i { font-size: 1rem; flex-shrink: 0; margin-top: 1px; }
        .window-banner strong { display: block; font-weight: 800; font-family: var(--font-h); font-size: .84rem; margin-bottom: 1px; }
        .window-banner .wb-desc { font-size: .72rem; }
        .banner-open    { background: rgba(16,185,129,.12); border: 1px solid rgba(16,185,129,.25); color: #065F46; }
        .banner-open i  { color: #059669; }
        .banner-late    { background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.30); color: #92400E; }
        .banner-late i  { color: var(--amber); }
        .banner-pending { background: rgba(82,178,220,.12); border: 1px solid rgba(82,178,220,.30); color: #075985; }
        .banner-pending i { color: var(--sky); }
        .banner-closed  { background: rgba(244,63,94,.12); border: 1px solid rgba(244,63,94,.28); color: #9F1239; }
        .banner-closed i { color: var(--rose); }

        .absensi-layout { display:grid; grid-template-columns:1fr; gap:20px; opacity:0; animation:fadeUp .5s .1s cubic-bezier(.22,1,.36,1) forwards; }
        @media(min-width:1024px){ .absensi-layout{ grid-template-columns:420px 1fr; } }

        .glass-card { background:rgba(255,255,255,.90); backdrop-filter:blur(12px); border-radius:20px; border:1px solid rgba(255,255,255,.80); box-shadow:0 4px 24px rgba(29,59,94,.10); overflow:hidden; }
        .card-head { padding:18px 20px; background:linear-gradient(135deg,#1D3B5E,#0D7A87); display:flex; align-items:center; gap:10px; }
        .card-head-icon { width:38px; height:38px; border-radius:10px; background:rgba(255,255,255,.15); display:flex; align-items:center; justify-content:center; color:#fff; font-size:.9rem; }
        .card-head-title { font-family:var(--font-h); font-size:.95rem; font-weight:800; color:#fff; }
        .card-head-sub { font-size:.68rem; color:rgba(255,255,255,.75); margin-top:1px; }
        .card-body { padding:20px; }

        .tab-row { display:flex; gap:6px; background:rgba(241,245,249,.8); border-radius:12px; padding:4px; margin-bottom:18px; }
        .tab-btn { flex:1; padding:9px 6px; border-radius:9px; border:none; background:transparent; font-family:var(--font-b); font-size:.78rem; font-weight:600; color:#64748B; cursor:pointer; transition:all .22s; display:flex; align-items:center; justify-content:center; gap:6px; }
        .tab-btn.active { background:#fff; color:var(--navy); box-shadow:0 2px 8px rgba(29,59,94,.12); }
        .tab-panel { display:none; }
        .tab-panel.active { display:block; }

        .qr-viewport { position:relative; border-radius:16px; overflow:hidden; background:#0F1D2F; aspect-ratio:1; margin-bottom:14px; }
        #qrVideo { width:100%; height:100%; object-fit:cover; display:block; }
        .qr-overlay { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; pointer-events:none; }
        .qr-frame { width:55%; aspect-ratio:1; border:2.5px solid rgba(82,178,220,.8); border-radius:12px; box-shadow:0 0 0 9999px rgba(0,0,0,.45); position:relative; animation:qrPulse 2s ease-in-out infinite; }
        @keyframes qrPulse { 0%,100%{border-color:rgba(82,178,220,.8)} 50%{border-color:rgba(13,122,135,1)} }
        .qr-frame::before,.qr-frame::after,.qr-frame > span::before,.qr-frame > span::after { content:''; position:absolute; width:16px; height:16px; border-color:var(--sky); border-style:solid; }
        .qr-frame::before { top:-2px; left:-2px; border-width:3px 0 0 3px; border-radius:6px 0 0 0; }
        .qr-frame::after  { top:-2px; right:-2px; border-width:3px 3px 0 0; border-radius:0 6px 0 0; }
        .qr-frame > span::before { bottom:-2px; left:-2px; border-width:0 0 3px 3px; border-radius:0 0 0 6px; }
        .qr-frame > span::after  { bottom:-2px; right:-2px; border-width:0 3px 3px 0; border-radius:0 0 6px 0; }
        .qr-scan-line { position:absolute; left:4px; right:4px; height:2px; background:linear-gradient(90deg,transparent,var(--sky),transparent); border-radius:999px; animation:scanLine 2s linear infinite; }
        @keyframes scanLine { 0%{top:10%} 100%{top:90%} }
        .qr-status-text { position:absolute; bottom:12px; left:0; right:0; text-align:center; color:rgba(255,255,255,.9); font-size:.72rem; font-weight:600; letter-spacing:.04em; z-index: 4; padding: 0 12px; }

        .qr-cam-error,
        .qr-cam-off {
            display: none;
            position: absolute;
            inset: 0;
            z-index: 5;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 24px;
            text-align: center;
            color: rgba(255,255,255,.75);
        }
        .qr-cam-error { background: #0F1D2F; }
        .qr-cam-off   { background: linear-gradient(135deg, #0F1D2F 0%, #152C47 100%); }
        .qr-cam-error > i,
        .qr-cam-off > i { font-size:2.4rem; color:rgba(255,255,255,.3); }
        .qr-cam-off > i { color: rgba(82,178,220,.35); }
        .qr-cam-error p,
        .qr-cam-off p { font-size:.8rem; font-weight: 600; line-height:1.5; max-width: 230px; }
        .btn-cam { padding:8px 16px; border-radius:10px; background:rgba(82,178,220,.2); border:1px solid rgba(82,178,220,.3); color:var(--sky); font-size:.78rem; font-weight:700; cursor:pointer; transition:all .2s; display: inline-flex; align-items: center; gap: 6px; }
        .btn-cam:hover { background:var(--sky); color:#fff; }

        .btn-toggle-cam {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 20;
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(15,29,47,.80);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,.22);
            color: #fff;
            font-family: var(--font-b);
            font-size: .72rem;
            font-weight: 700;
            cursor: pointer;
            transition: all .2s;
            box-shadow: 0 4px 12px rgba(0,0,0,.2);
        }
        .btn-toggle-cam:hover { background: rgba(13,122,135,.9); border-color: rgba(82,178,220,.6); transform: translateY(-1px); }
        .btn-toggle-cam i { font-size: .7rem; }
        .btn-toggle-cam.cam-active { background: rgba(244,63,94,.85); border-color: rgba(244,63,94,.5); }
        .btn-toggle-cam.cam-active:hover { background: rgba(244,63,94,1); }

        .mesin-hint { background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.25); border-radius:12px; padding:12px 14px; font-size:.75rem; color:#92400E; margin-bottom:14px; display:flex; gap:10px; align-items:flex-start; }
        .mesin-hint i { color:var(--amber); flex-shrink:0; margin-top:1px; }

        .field-label { display:block; font-size:.75rem; font-weight:700; color:#334155; margin-bottom:5px; }
        .field { margin-bottom:14px; }
        .input-wrap { position:relative; display:flex; align-items:center; }
        .input-wrap > i { position:absolute; left:13px; color:#94A3B8; font-size:.82rem; pointer-events:none; }
        .form-input { width:100%; padding:11px 12px 11px 38px; border-radius:12px; border:1.5px solid #E2E8F0; background:rgba(255,255,255,.9); color:#0F1D2F; font-family:var(--font-b); font-size:.88rem; font-weight:500; transition:all .22s; }
        .form-input:focus { outline:none; border-color:var(--teal); background:#fff; box-shadow:0 0 0 4px rgba(13,122,135,.10); }
        .form-input::placeholder { color:#94A3B8; font-weight:400; }

        .btn-primary { width:100%; padding:12px 16px; border-radius:12px; border:none; background:linear-gradient(135deg,#1D3B5E,#0D7A87 50%,#52B2DC); color:#fff; font-family:var(--font-h); font-size:.95rem; font-weight:700; letter-spacing:.02em; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 8px 20px rgba(13,122,135,.30); transition:all .28s cubic-bezier(.4,0,.2,1); position:relative; overflow:hidden; }
        .btn-primary:disabled { opacity: .6; cursor: not-allowed; box-shadow: none; }
        .btn-primary::after { content:''; position:absolute; top:0; left:-100%; width:50%; height:100%; background:linear-gradient(to right,transparent,rgba(255,255,255,.5),transparent); transform:skewX(-20deg); animation:btnShine 3s cubic-bezier(.4,0,.2,1) infinite; }
        @keyframes btnShine { 0%{left:-100%} 20%{left:200%} 100%{left:200%} }

        .alert-box { display:none; padding:12px 14px; border-radius:12px; margin-bottom:14px; font-size:.78rem; font-weight:600; align-items:center; gap:8px; }
        .alert-box.show { display:flex; }
        .alert-error   { background:rgba(244,63,94,.12); color:#BE185D; border:1px solid rgba(244,63,94,.25); }
        .alert-success { background:rgba(16,185,129,.12); color:#059669; border:1px solid rgba(16,185,129,.25); }
        .alert-warning { background:rgba(245,158,11,.12); color:#B45309; border:1px solid rgba(245,158,11,.25); }
        .alert-close { margin-left:auto; background:none; border:none; color:inherit; cursor:pointer; font-size:.85rem; }

        /* SISWA FEEDBACK CARD */
        .siswa-card {
            display:none; border-radius:14px; padding:14px; margin-top:14px;
            background:#F8FAFC; border:2px solid transparent;
            transition:all .3s cubic-bezier(.4,0,.2,1);
            align-items:center; gap:12px;
        }
        .siswa-card.show { display:flex; }
        .siswa-card.state-processing { border-color:var(--sky); background:rgba(82,178,220,.08); }
        .siswa-card.state-success    { border-color:var(--emerald); background:rgba(16,185,129,.08); }
        .siswa-card.state-terlambat  { border-color:var(--amber); background:rgba(245,158,11,.10); }
        .siswa-card.state-rejected   { border-color:var(--rose); background:rgba(244,63,94,.08); }

        .siswa-avatar { width:52px; height:52px; border-radius:12px; object-fit:cover; flex-shrink:0; border:2px solid #fff; box-shadow:0 2px 8px rgba(0,0,0,.1); }
        .siswa-avatar-ph { width:52px; height:52px; border-radius:12px; background:linear-gradient(135deg,var(--teal),var(--sky)); color:#fff; display:flex; align-items:center; justify-content:center; font-family:var(--font-h); font-size:1.3rem; font-weight:800; flex-shrink:0; }
        .siswa-info { flex:1; min-width:0; }
        .siswa-nama { font-family:var(--font-h); font-size:.95rem; font-weight:800; color:var(--navy); margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .siswa-meta { font-size:.72rem; color:#64748B; margin-bottom:6px; }
        .siswa-chips { display:flex; flex-wrap:wrap; gap:4px; }
        .siswa-chip { display:inline-flex; align-items:center; gap:3px; padding:2px 7px; border-radius:5px; font-size:.65rem; font-weight:700; }
        .chip-nomor { background:rgba(29,59,94,.10); color:var(--navy); }
        .chip-kelas { background:rgba(13,122,135,.10); color:var(--teal); }
        .chip-mapel { background:rgba(139,92,246,.10); color:var(--violet); }
        .chip-hadir     { background:rgba(16,185,129,.18); color:#059669; }
        .chip-terlambat { background:rgba(245,158,11,.22); color:#B45309; }
        .chip-proses    { background:rgba(82,178,220,.15); color:#0369A1; }
        .chip-tolak     { background:rgba(244,63,94,.18); color:#BE185D; }

        /* MONITORING STATS & TABLE */
        .mon-stats { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
        .mon-stat { flex:1; min-width:70px; border-radius:12px; padding:10px 12px; text-align:center; }
        .mon-stat .stat-num { font-family:var(--font-h); font-size:1.4rem; font-weight:900; line-height:1; }
        .mon-stat .stat-lbl { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; margin-top:2px; }
        .ms-total { background:rgba(29,59,94,.07); color:var(--navy); }
        .ms-hadir { background:rgba(16,185,129,.10); color:#059669; }
        .ms-late  { background:rgba(245,158,11,.10); color:#B45309; }
        .ms-belum { background:rgba(244,63,94,.08); color:#BE185D; }
        .ms-lain  { background:rgba(139,92,246,.10); color:#6D28D9; }

        .mon-header { display:flex; flex-wrap:wrap; align-items:center; gap:12px; margin-bottom:14px; }
        .mon-search-wrap { position:relative; flex:1; display:flex; align-items:center; }
        .mon-search-icon { position:absolute; left:10px; color:#94A3B8; font-size:.78rem; pointer-events:none; }
        .mon-search { width:100%; padding:9px 12px 9px 34px; border-radius:10px; border:1.5px solid #E2E8F0; background:rgba(255,255,255,.9); font-size:.82rem; font-family:var(--font-b); color:#0F1D2F; transition:all .22s; }
        .mon-search:focus { outline:none; border-color:var(--teal); box-shadow:0 0 0 3px rgba(13,122,135,.10); }

        .btn-refresh { padding:8px 14px; border-radius:9px; background:rgba(13,122,135,.10); border:1px solid rgba(13,122,135,.20); color:var(--teal); font-size:.78rem; font-weight:700; cursor:pointer; transition:all .2s; display:flex; align-items:center; gap:6px; font-family:var(--font-b); }
        .btn-refresh:hover { background:var(--teal); color:#fff; }
        .btn-refresh.spinning i { animation:spin .7s linear infinite; }
        @keyframes spin { to{transform:rotate(360deg)} }

        .rt-indicator {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 999px;
            background: rgba(16,185,129,.10); color: #059669;
            font-size: .65rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
            margin-left: auto;
        }
        .rt-indicator .rt-dot { width: 6px; height: 6px; border-radius: 50%; background: #10B981; box-shadow: 0 0 6px rgba(16,185,129,.8); animation: rtPulse 1.6s ease-in-out infinite; }
        .rt-indicator.paused { background: rgba(148,163,184,.15); color: #64748B; }
        .rt-indicator.paused .rt-dot { background: #94A3B8; box-shadow: none; animation: none; }
        @keyframes rtPulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.75)} }

        .mon-table-wrap { overflow-x:auto; border-radius:12px; border:1px solid #E2E8F0; }
        .mon-table { width:100%; border-collapse:collapse; font-size:.78rem; }
        .mon-table th { padding:10px 12px; text-align:left; background:rgba(241,245,249,.9); color:#475569; font-size:.65rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; border-bottom:1px solid #E2E8F0; white-space:nowrap; }
        .mon-table td { padding:10px 12px; border-bottom:1px solid rgba(226,232,240,.6); vertical-align:middle; }
        .mon-table tbody tr:last-child td { border-bottom:none; }
        .mon-table tbody tr { transition: background-color .3s ease; }
        .mon-table tbody tr:hover { background:rgba(13,122,135,.04); }
        .mon-table tbody tr.row-flash { animation: rowFlash 1.4s cubic-bezier(.4,0,.2,1); }
        @keyframes rowFlash {
            0%   { background-color: rgba(16,185,129,.30); }
            40%  { background-color: rgba(16,185,129,.18); }
            100% { background-color: transparent; }
        }

        .status-pill { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:999px; font-size:.65rem; font-weight:700; white-space:nowrap; }
        .pill-hadir     { background:rgba(16,185,129,.12); color:#059669; }
        .pill-terlambat { background:rgba(245,158,11,.15); color:#B45309; }
        .pill-izin      { background:rgba(82,178,220,.12); color:#0369A1; }
        .pill-sakit     { background:rgba(139,92,246,.12); color:#6D28D9; }
        .pill-alpha     { background:rgba(244,63,94,.12); color:#BE185D; }
        .pill-belum     { background:rgba(226,232,240,.6); color:#94A3B8; }
        .pill-dot { width:5px; height:5px; border-radius:50%; background:currentColor; }

        .mon-avatar { width:32px; height:32px; border-radius:8px; object-fit:cover; }
        .mon-avatar-ph { width:32px; height:32px; border-radius:8px; background:linear-gradient(135deg,var(--teal),var(--sky)); display:flex; align-items:center; justify-content:center; color:#fff; font-size:.75rem; font-weight:800; }
        .mon-name { font-weight:700; color:var(--navy); }
        .mon-sub  { font-size:.65rem; color:#94A3B8; }
        .empty-state { text-align:center; padding:48px 24px; color:#94A3B8; }
        .empty-state i { font-size:2.5rem; margin-bottom:12px; display:block; }
        .empty-state p { font-size:.82rem; }
        #noAsesmenMsg { text-align:center; padding:60px 24px; color:#94A3B8; }
        #noAsesmenMsg i { font-size:3rem; display:block; margin-bottom:14px; }
        #noAsesmenMsg p { font-size:.88rem; }
        .skeleton { background:linear-gradient(90deg,#F1F5F9 25%,#E2E8F0 50%,#F1F5F9 75%); background-size:200% 100%; animation:shimmer 1.5s infinite; border-radius:8px; }
        @keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }

        /* TOAST NOTIFICATION */
        .toast-wrap { position:fixed; top:24px; right:24px; z-index:9998; display:flex; flex-direction:column; gap:8px; pointer-events:none; }
        .toast { min-width:260px; max-width:360px; padding:12px 16px; border-radius:14px; background:rgba(15,29,47,.95); color:#fff; font-size:.8rem; font-weight:600; display:flex; align-items:flex-start; gap:10px; box-shadow:0 8px 24px rgba(0,0,0,.25); pointer-events:auto; animation:toastIn .4s cubic-bezier(.22,1,.36,1) both; }
        .toast.out { animation:toastOut .3s ease-in both; }
        @keyframes toastIn  { from{opacity:0;transform:translateX(40px)} to{opacity:1;transform:translateX(0)} }
        @keyframes toastOut { from{opacity:1;transform:translateX(0)} to{opacity:0;transform:translateX(40px)} }
        .toast i { flex-shrink:0; margin-top:1px; }
        .toast-success i { color:var(--emerald); }
        .toast-error   i { color:#F87171; }
        .toast-warning i { color:var(--amber); }

        @media(max-width:480px){ .page-title{font-size:1.35rem;} .card-body{padding:14px;} }
    
        @keyframes fa-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .fa-spin { display: inline-block; animation: fa-spin 1s infinite linear; }
    
    </style>
</head>
<body>
<div id="pageTransition" class="page-transition active"></div>
<canvas id="bgCanvas"></canvas>
<div class="glow-orb glow-orb-1"></div>
<div class="glow-orb glow-orb-2"></div>

<header class="topbar">
    <a href="<?= htmlspecialchars(BASE_URL . '/dashboard/guru', ENT_QUOTES, 'UTF-8') ?>" class="topbar-logo">
        <img src="<?= htmlspecialchars(BASE_URL . '/assets/images/logo-learn.png', ENT_QUOTES, 'UTF-8') ?>" alt="Ilmuna Learn" draggable="false">
        <div>
            <div class="app-name">Ilmuna Learn</div>
            <div class="app-sub">Portal Guru — Presensi Sumatif</div>
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
    <a href="<?= htmlspecialchars(BASE_URL . '/dashboard/guru', ENT_QUOTES, 'UTF-8') ?>" class="topbar-back">
        <iconify-icon icon="solar:arrow-left-linear"></iconify-icon> Dashboard Guru
    </a>
</header>

<div class="main-wrap">
    

    <!-- ATURAN WAKTU ABSENSI CARD -->
    <div class="rules-card">
        <div class="rules-icon"><iconify-icon icon="solar:clock-circle-bold"></iconify-icon></div>
        <div class="rules-content">
            <div class="rules-title">Ketentuan Jadwal Absensi Asesmen Sumatif</div>
            <div class="rules-desc">Absensi hanya dapat dilakukan pada jam asesmen sesuai jadwal pelaksanaan dengan aturan toleransi berikut:</div>
            <div class="rules-chips">
                <span class="r-chip r-chip-open"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> Buka: 30 Mnt Sebelum Mulai s/d 15 Mnt Setelah Mulai = Hadir (Tepat Waktu)</span>
                <span class="r-chip r-chip-late"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon> Lewat 15 Mnt s/d 20 Mnt = Terlambat</span>
                <span class="r-chip r-chip-closed"><iconify-icon icon="solar:close-circle-bold"></iconify-icon> 20 Mnt Ke Atas = Ditutup (Tidak Dapat Absensi)</span>
            </div>
        </div>
    </div>

    <!-- SELECTOR ASESMEN -->
    <div class="selector-card">
        <div class="selector-header-wrap">
            <div class="selector-title"><iconify-icon icon="solar:clipboard-list-bold"></iconify-icon> Pilih Asesmen Sumatif</div>
            <div class="filter-pills">
                <button class="filter-pill active" id="btnFilterAll" type="button" onclick="setAsesmenFilter('all')">
                    <iconify-icon icon="solar:global-bold"></iconify-icon> Semua Asesmen
                </button>
                <button class="filter-pill" id="btnFilterMine" type="button" onclick="setAsesmenFilter('mine')">
                    <iconify-icon icon="solar:user-check-bold"></iconify-icon> Asesmen Saya
                </button>
            </div>
        </div>
        <div id="asesmenGrid" class="asesmen-grid">
            <div style="border-radius:14px;padding:16px;background:#F8FAFC;"><div class="skeleton" style="height:10px;width:60%;margin-bottom:8px;"></div><div class="skeleton" style="height:14px;width:90%;margin-bottom:6px;"></div><div class="skeleton" style="height:5px;width:100%;margin-top:14px;"></div></div>
            <div style="border-radius:14px;padding:16px;background:#F8FAFC;"><div class="skeleton" style="height:10px;width:55%;margin-bottom:8px;"></div><div class="skeleton" style="height:14px;width:85%;margin-bottom:6px;"></div><div class="skeleton" style="height:5px;width:100%;margin-top:14px;"></div></div>
        </div>
        <p id="noAsesmenAvailable" style="display:none;color:#94A3B8;font-size:.82rem;padding:24px;text-align:center;">
            <iconify-icon icon="solar:folder-open-bold" style="font-size:2rem;display:block;margin:0 auto 8px;"></iconify-icon>
            Tidak ada asesmen sumatif yang cocok dengan filter saat ini.
        </p>
    </div>

    <!-- MAIN ABSENSI LAYOUT -->
    <div id="absensiLayout" class="absensi-layout" style="display:none;">

        <!-- KIRI: SCAN & AUTO-ABSENSI -->
        <div class="glass-card">
            <div class="card-head">
                <div class="card-head-icon"><iconify-icon icon="solar:qr-code-bold"></iconify-icon></div>
                <div>
                    <div class="card-head-title">Scan &amp; Auto-Absensi</div>
                    <div class="card-head-sub" id="selectedAsesmenLabel">Belum dipilih</div>
                </div>
            </div>
            <div class="card-body">

                <!-- LIVE WINDOW STATUS BANNER -->
                <div id="windowBanner" class="window-banner banner-open" style="display:none;">
                    <iconify-icon icon="solar:info-circle-bold"></iconify-icon>
                    <div>
                        <strong id="wbTitle">Status Sesi Absensi</strong>
                        <div class="wb-desc" id="wbDesc">Memeriksa jadwal...</div>
                    </div>
                </div>

                <div class="alert-box" id="scanAlert">
                    <iconify-icon icon="solar:info-circle-bold"></iconify-icon>
                    <span id="scanAlertMsg"></span>
                    <button class="alert-close" onclick="closeAlert('scanAlert')" type="button"><iconify-icon icon="solar:close-circle-bold"></iconify-icon></button>
                </div>

                <div class="tab-row">
                    <button class="tab-btn active" id="tabCamBtn" onclick="switchTab('camera')" type="button">
                        <iconify-icon icon="solar:camera-bold"></iconify-icon> Kamera QR
                    </button>
                    <button class="tab-btn" id="tabMesinBtn" onclick="switchTab('mesin')" type="button">
                        <iconify-icon icon="solar:scanner-bold"></iconify-icon> Mesin / Manual
                    </button>
                </div>

                <!-- KAMERA QR TAB -->
                <div class="tab-panel active" id="tabCamera">
                    <div class="qr-viewport" id="qrViewport">
                        <video id="qrVideo" autoplay playsinline muted></video>
                        <div class="qr-overlay" id="qrOverlay">
                            <div class="qr-frame">
                                <span></span>
                                <div class="qr-scan-line"></div>
                            </div>
                        </div>
                        <div class="qr-status-text" id="qrStatusText">Arahkan kamera ke QR Code nomor peserta</div>

                        <div class="qr-cam-off" id="qrCamOff">
                            <iconify-icon icon="solar:camera-linear"></iconify-icon>
                            <p>Kamera sedang dimatikan</p>
                            <button class="btn-cam" type="button" onclick="toggleCamera()">
                                <iconify-icon icon="solar:videocamera-record-bold"></iconify-icon> Aktifkan Kamera
                            </button>
                        </div>

                        <div class="qr-cam-error" id="qrCamError">
                            <iconify-icon icon="solar:camera-linear"></iconify-icon>
                            <p>Kamera tidak dapat diakses.<br>Pastikan izin kamera diberikan browser.</p>
                            <button class="btn-cam" type="button" onclick="startCamera()"><iconify-icon icon="solar:restart-bold"></iconify-icon> Coba Lagi</button>
                        </div>

                        <button class="btn-toggle-cam" id="btnToggleCam" onclick="toggleCamera()" type="button" title="Buka/Tutup Kamera">
                            <iconify-icon icon="solar:camera-linear" id="toggleCamIcon"></iconify-icon>
                            <span id="toggleCamText">Tutup</span>
                        </button>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label class="field-label">Nomor Peserta (hasil scan QR)</label>
                        <div class="input-wrap">
                            <iconify-icon icon="solar:card-2-bold"></iconify-icon>
                            <input type="text" id="qrResult" class="form-input" placeholder="Menunggu scan QR..." readonly>
                        </div>
                    </div>
                </div>

                <!-- MESIN / MANUAL TAB -->
                <div class="tab-panel" id="tabMesin">
                    <div class="mesin-hint">
                        <iconify-icon icon="solar:bolt-bold"></iconify-icon>
                        <div>Ketik nomor peserta lalu tekan <strong>Enter</strong> atau tembakkan scanner barcode. Absensi otomatis tersimpan sesuai aturan jam asesmen.</div>
                    </div>
                    <div class="field" style="margin-bottom:12px;">
                        <label class="field-label" for="manualInput">Nomor Peserta</label>
                        <div class="input-wrap">
                            <iconify-icon icon="solar:scanner-bold"></iconify-icon>
                            <input type="text" id="manualInput" class="form-input" placeholder="Scan barcode / ketik nomor peserta..." autocomplete="off">
                        </div>
                    </div>
                    <button class="btn-primary" id="btnCariSiswa" onclick="lookupSiswa('manual')" type="button">
                        <iconify-icon icon="solar:bolt-bold"></iconify-icon> Absen Sekarang
                    </button>
                </div>

                <!-- FEEDBACK SISWA CARD -->
                <div class="siswa-card" id="siswaCard">
                    <div id="siswaAvatarWrap"></div>
                    <div class="siswa-info">
                        <div class="siswa-nama" id="siswaNama">—</div>
                        <div class="siswa-meta">NISN: <span id="siswaNISN">—</span> &nbsp;|&nbsp; NIS: <span id="siswaNIS">—</span></div>
                        <div class="siswa-chips" id="siswaChips"></div>
                    </div>
                </div>

            </div>
        </div>

        <!-- KANAN: MONITORING KEHADIRAN -->
        <div class="glass-card">
            <div class="card-head">
                <div class="card-head-icon"><iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon></div>
                <div style="flex:1;">
                    <div class="card-head-title">Monitoring Kehadiran</div>
                    <div class="card-head-sub" id="monitoringLabel">Pilih asesmen terlebih dahulu</div>
                </div>
                <div class="rt-indicator" id="rtIndicator" style="display:none;">
                    <span class="rt-dot"></span>
                    <span id="rtText">Live</span>
                </div>
            </div>
            <div class="card-body">
                <div id="noAsesmenMsg">
                    <iconify-icon icon="solar:clipboard-list-bold"></iconify-icon>
                    <p>Pilih salah satu asesmen sumatif di atas untuk memantau kehadiran peserta.</p>
                </div>

                <div id="monitoringContent" style="display:none;">
                    <div class="mon-stats">
                        <div class="mon-stat ms-total">
                            <div class="stat-num" id="statTotal">0</div>
                            <div class="stat-lbl">Peserta</div>
                        </div>
                        <div class="mon-stat ms-hadir">
                            <div class="stat-num" id="statHadir">0</div>
                            <div class="stat-lbl">Hadir</div>
                        </div>
                        <div class="mon-stat ms-late">
                            <div class="stat-num" id="statLate">0</div>
                            <div class="stat-lbl">Terlambat</div>
                        </div>
                        <div class="mon-stat ms-belum">
                            <div class="stat-num" id="statBelum">0</div>
                            <div class="stat-lbl">Belum</div>
                        </div>
                        <div class="mon-stat ms-lain">
                            <div class="stat-num" id="statLain">0</div>
                            <div class="stat-lbl">Izin/Sakit</div>
                        </div>
                    </div>

                    <div class="mon-header">
                        <div class="mon-search-wrap">
                            <iconify-icon class="mon-search-icon" icon="solar:magnifer-linear"></iconify-icon>
                            <input type="text" id="monSearch" class="mon-search" placeholder="Cari nama, nomor, atau kelas..." oninput="filterMonitoringTable()">
                        </div>
                        <button class="btn-refresh" id="btnRefreshMon" onclick="refreshMonitoringClick()" type="button" title="Muat ulang data monitoring">
                            <iconify-icon icon="solar:restart-bold"></iconify-icon> Refresh
                        </button>
                    </div>

                    <div class="mon-table-wrap">
                        <table class="mon-table" id="monTable">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Siswa</th>
                                    <th>Nomor Peserta</th>
                                    <th>Kelas</th>
                                    <th>Status Absen</th>
                                    <th>Waktu</th>
                                    <th>Metode</th>
                                </tr>
                            </thead>
                            <tbody id="monTbody">
                                <tr><td colspan="7" class="empty-state"><iconify-icon icon="solar:restart-circle-bold" class="fa-spin"></iconify-icon><p>Memuat data peserta...</p></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/alert.js"></script>
<script>
/* ===========================
 * STATE & KONFIGURASI
 * =========================== */
var selectedAsesmen   = null;
var currentSiswa      = null;
var currentTab        = 'camera';
var cameraStream      = null;
var qrAnimFrame       = null;
var scanning          = false;
var cameraEnabled     = true;
var savingInProgress  = false;
var resetTimeoutId    = null;
var currentFilterScope = 'all';

/* Monitoring state */
var monData           = [];
var monSignature      = '';
var monTableRendered  = false;
var pollTimer         = null;
var POLL_INTERVAL_MS  = 3000;

/* PAGE TRANSITION */
var pt = document.getElementById('pageTransition');
setTimeout(function(){ if(pt) pt.classList.remove('active'); }, 100);

/* BG CANVAS */
var bgCanvas = document.getElementById('bgCanvas');
if(bgCanvas){
    var bc = bgCanvas.getContext('2d');
    function szBg(){ bgCanvas.width=window.innerWidth; bgCanvas.height=window.innerHeight; }
    szBg(); window.addEventListener('resize', szBg);
    var pts=[];
    for(var i=0;i<35;i++) pts.push({x:Math.random()*bgCanvas.width,y:Math.random()*bgCanvas.height,vx:(Math.random()-.5)*.3,vy:(Math.random()-.5)*.3,r:Math.random()*2+1,c:['#1D3B5E','#0D7A87','#52B2DC'][Math.floor(Math.random()*3)],o:Math.random()*.25+.05});
    function bgAnimate(){
        bc.clearRect(0,0,bgCanvas.width,bgCanvas.height);
        pts.forEach(function(p){
            p.x+=p.vx; p.y+=p.vy;
            if(p.x<0||p.x>bgCanvas.width) p.vx*=-1;
            if(p.y<0||p.y>bgCanvas.height) p.vy*=-1;
            bc.beginPath(); bc.arc(p.x,p.y,p.r,0,Math.PI*2);
            bc.fillStyle=p.c; bc.globalAlpha=p.o; bc.fill();
        });
        bc.globalAlpha=1; requestAnimationFrame(bgAnimate);
    }
    bgAnimate();
}

/* ===========================
 * HELPERS
 * =========================== */
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function showToast(msg, type){
    type = (type === 'danger' || type === 'err') ? 'error' : (type || 'success');
    var titleMap = { success: 'Berhasil', error: 'Gagal', warning: 'Peringatan', info: 'Informasi' };
    if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
        return Ilmuna.toast(type, titleMap[type] || 'Pemberitahuan', msg, 4000);
    }
    var icons={success:'fa-check-circle',error:'fa-exclamation-circle',warning:'fa-triangle-exclamation'};
    var t=document.createElement('div');
    t.className='toast toast-'+type;
    t.innerHTML='<i class="fas '+(icons[type]||'fa-info')+'"></i><span>'+esc(msg)+'</span>';
    document.getElementById('toastWrap').appendChild(t);
    setTimeout(function(){ t.classList.add('out'); setTimeout(function(){ if(t.parentNode) t.parentNode.removeChild(t); },350); },3800);
}

function showAlert(id, msg, type){
    type = (type === 'danger' || type === 'err') ? 'error' : (type || 'error');
    var titleMap = { success: 'Berhasil', error: 'Gagal', warning: 'Peringatan', info: 'Informasi' };
    if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
        Ilmuna.toast(type, titleMap[type] || 'Pemberitahuan', msg, 4500);
    }
    var el=document.getElementById(id), msgEl=document.getElementById(id+'Msg');
    if(!el||!msgEl) return;
    var iconMap={success:'fa-check-circle',error:'fa-exclamation-circle',warning:'fa-triangle-exclamation'};
    el.className='alert-box show alert-'+(type||'error');
    var iEl=el.querySelector('i'); if(iEl) iEl.className='fas '+(iconMap[type]||'fa-exclamation-circle');
    msgEl.textContent=msg;
}
window.closeAlert=function(id){ var el=document.getElementById(id); if(el) el.classList.remove('show'); };

function fmtWaktu(dt){
    if(!dt) return '—';
    try{ var d=new Date(dt.replace(' ','T')); return d.toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit',second:'2-digit'}); }
    catch(e){ return dt; }
}

/* ===========================
 * FILTER ASESMEN (SAYA vs SEMUA)
 * =========================== */
window.setAsesmenFilter = function(scope){
    currentFilterScope = scope;
    document.getElementById('btnFilterAll').classList.toggle('active', scope==='all');
    document.getElementById('btnFilterMine').classList.toggle('active', scope==='mine');
    loadAsesmenList();
};

/* ===========================
 * LOAD DAFTAR ASESMEN
 * =========================== */
function loadAsesmenList(){
    fetch('',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=get_asesmen_list&scope=' + encodeURIComponent(currentFilterScope)
    })
    .then(function(r){return r.json();})
    .then(function(res){
        var grid=document.getElementById('asesmenGrid');
        if(!res.success||!res.data||res.data.length===0){
            grid.innerHTML='';
            document.getElementById('noAsesmenAvailable').style.display='block';
            return;
        }
        document.getElementById('noAsesmenAvailable').style.display='none';
        grid.innerHTML='';

        res.data.forEach(function(a){
            var total=parseInt(a.total_peserta)||0, hadir=parseInt(a.total_hadir)||0;
            var pct=total>0?Math.round(hadir/total*100):0;
            var card=document.createElement('div');
            card.className='asesmen-card'; card.dataset.id=a.id;

            // Window Status Badge
            var winBadge = '';
            if(a.window_status === 'open'){
                winBadge = '<span class="ac-window-badge badge-window-open"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> Dibuka (Tepat Waktu)</span>';
            } else if(a.window_status === 'late'){
                winBadge = '<span class="ac-window-badge badge-window-late"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon> Toleransi Terlambat</span>';
            } else if(a.window_status === 'not_started'){
                winBadge = '<span class="ac-window-badge badge-window-pending"><iconify-icon icon="solar:hourglass-bold"></iconify-icon> Buka ' + (a.jam_buka||'') + '</span>';
            } else if(a.window_status === 'closed'){
                winBadge = '<span class="ac-window-badge badge-window-closed"><iconify-icon icon="solar:lock-bold"></iconify-icon> Ditutup (>20 Mnt)</span>';
            }

            var mineBadge = a.is_mine ? '<span style="font-size:.65rem;padding:2px 6px;border-radius:4px;background:rgba(29,59,94,.1);color:var(--navy);font-weight:700;"><iconify-icon icon="solar:user-id-bold"></iconify-icon> Asesmen Saya</span>' : '';

            card.innerHTML =
                '<div class="ac-top-meta">' +
                    '<div class="ac-mapel">' + esc(a.kode_mapel) + ' — ' + esc(a.nama_kelas) + '</div>' +
                    winBadge +
                '</div>' +
                '<div class="ac-judul">' + esc(a.judul_asesmen) + '</div>' +
                '<div class="ac-tags">' +
                    '<span class="ac-jenis">' + esc(a.jenis_sumatif) + '</span>' +
                    mineBadge +
                '</div>' +
                '<div class="ac-meta">' +
                    '<div class="ac-chip"><iconify-icon icon="solar:calendar-bold"></iconify-icon> ' + esc(a.tanggal_pelaksanaan||'—') + '</div>' +
                    '<div class="ac-chip"><iconify-icon icon="solar:clock-circle-bold"></iconify-icon> ' + esc(a.jam_mulai||'') + '–' + esc(a.jam_selesai||'') + '</div>' +
                    '<div class="ac-chip"><iconify-icon icon="solar:hourglass-line-bold"></iconify-icon> ' + esc(a.durasi_menit||0) + ' mnt</div>' +
                '</div>' +
                '<div class="ac-progress">' +
                    '<div class="ac-progress-label"><span>Kehadiran</span><span>' + hadir + ' / ' + total + ' (' + pct + '%)</span></div>' +
                    '<div class="ac-progress-bar"><div class="ac-progress-fill" style="width:' + pct + '%"></div></div>' +
                '</div>';

            card.addEventListener('click', function(){ selectAsesmen(a, card); });
            grid.appendChild(card);
        });
    })
    .catch(function(e){ console.warn('Load asesmen:', e); });
}

function updateWindowBanner(a){
    var wb = document.getElementById('windowBanner');
    var title = document.getElementById('wbTitle');
    var desc = document.getElementById('wbDesc');
    if(!wb || !title || !desc) return;

    wb.style.display = 'flex';
    wb.className = 'window-banner banner-' + a.window_status;

    if(a.window_status === 'open'){
        wb.querySelector('iconify-icon').setAttribute('icon', 'solar:check-circle-bold');
        title.textContent = 'Sesi Absensi Dibuka — Tepat Waktu';
        desc.innerHTML = 'Absensi berstatus <strong>Hadir</strong>. Batas toleransi tepat waktu sampai pukul <strong>' + (a.jam_toleransi||'') + ' WIB</strong> (15 menit setelah jam mulai ' + (a.jam_mulai||'') + ').';
    } else if(a.window_status === 'late'){
        wb.querySelector('iconify-icon').setAttribute('icon', 'solar:danger-triangle-bold');
        title.textContent = 'Periode Keterlambatan Asesmen';
        desc.innerHTML = 'Waktu telah lewat 15 menit dari jam mulai. Siswa yang diabsen saat ini otomatis berstatus <strong>TERLAMBAT</strong>. Absensi ditutup pada pukul <strong>' + (a.jam_tutup||'') + ' WIB</strong>.';
    } else if(a.window_status === 'not_started'){
        wb.querySelector('iconify-icon').setAttribute('icon', 'solar:hourglass-bold');
        title.textContent = 'Absensi Belum Dibuka';
        desc.innerHTML = 'Absensi baru dibuka <strong>30 menit sebelum jam mulai</strong> (Pukul <strong>' + (a.jam_buka||'') + ' WIB</strong>). Jam mulai ujian: <strong>' + (a.jam_mulai||'') + ' WIB</strong>.';
    } else if(a.window_status === 'closed'){
        wb.querySelector('iconify-icon').setAttribute('icon', 'solar:lock-bold');
        title.textContent = 'Waktu Absensi Telah Ditutup';
        desc.innerHTML = 'Batas toleransi maksimal (20 menit setelah jam mulai ujian / pukul <strong>' + (a.jam_tutup||'') + ' WIB</strong>) telah berakhir. Presensi ditutup, Anda tetap dapat melihat pemantauan kehadiran di samping.';
    }
}

function selectAsesmen(a, cardEl){
    document.querySelectorAll('.asesmen-card').forEach(function(c){ c.classList.remove('selected'); });
    cardEl.classList.add('selected');
    selectedAsesmen = a; currentSiswa = null;

    document.getElementById('selectedAsesmenLabel').textContent = a.judul_asesmen;
    document.getElementById('monitoringLabel').textContent = a.judul_asesmen + ' — ' + (a.nama_kelas||'');
    var layout = document.getElementById('absensiLayout');
    layout.style.display = 'grid';
    document.getElementById('noAsesmenMsg').style.display = 'none';
    document.getElementById('monitoringContent').style.display = 'block';
    document.getElementById('rtIndicator').style.display = 'inline-flex';

    updateWindowBanner(a);

    // Disable input / scan if closed or not started
    var manualBtn = document.getElementById('btnCariSiswa');
    var manualInp = document.getElementById('manualInput');
    if(!a.window_allowed){
        if(manualBtn) manualBtn.disabled = true;
        if(manualInp) manualInp.placeholder = a.window_reason || 'Absensi ditutup/belum dibuka.';
    } else {
        if(manualBtn) manualBtn.disabled = false;
        if(manualInp) manualInp.placeholder = 'Scan barcode / ketik nomor peserta...';
    }

    // Reset monitoring state
    monData = [];
    monSignature = '';
    monTableRendered = false;
    stopPolling();

    resetForm();
    loadMonitoring();
    startPolling();

    if(currentTab==='camera'){
        if(cameraEnabled && a.window_allowed) startCamera();
        else updateCameraUI('off');
    }
}

/* ===========================
 * TABS
 * =========================== */
window.switchTab=function(tab){
    currentTab=tab;
    document.getElementById('tabCamBtn').classList.toggle('active',tab==='camera');
    document.getElementById('tabMesinBtn').classList.toggle('active',tab==='mesin');
    document.getElementById('tabCamera').classList.toggle('active',tab==='camera');
    document.getElementById('tabMesin').classList.toggle('active',tab==='mesin');
    if(tab==='camera'){
        if(cameraEnabled && selectedAsesmen && selectedAsesmen.window_allowed) startCamera();
        else updateCameraUI('off');
    } else {
        stopCameraStream();
        setTimeout(function(){ var el=document.getElementById('manualInput'); if(el) el.focus(); },100);
    }
    resetForm();
};

/* ===========================
 * CAMERA
 * =========================== */
function updateCameraUI(state){
    var v = document.getElementById('qrVideo');
    var off = document.getElementById('qrCamOff');
    var err = document.getElementById('qrCamError');
    var overlay = document.getElementById('qrOverlay');
    var statusText = document.getElementById('qrStatusText');
    var btn = document.getElementById('btnToggleCam');
    var icon = document.getElementById('toggleCamIcon');
    var txt = document.getElementById('toggleCamText');

    if(v) v.style.display = (state === 'active') ? 'block' : 'none';
    if(off) off.style.display = (state === 'off') ? 'flex' : 'none';
    if(err) err.style.display = (state === 'error') ? 'flex' : 'none';
    if(overlay) overlay.style.display = (state === 'active') ? 'flex' : 'none';
    if(statusText) statusText.style.display = (state === 'active') ? 'block' : 'none';

    if(btn && icon && txt){
        if(state === 'active'){
            icon.setAttribute('icon', 'solar:camera-linear'); txt.textContent = 'Tutup';
            btn.classList.add('cam-active'); btn.title = 'Tutup Kamera';
        } else if(state === 'off'){
            icon.setAttribute('icon', 'solar:videocamera-record-bold'); txt.textContent = 'Buka';
            btn.classList.remove('cam-active'); btn.title = 'Buka Kamera';
        } else {
            icon.setAttribute('icon', 'solar:restart-bold'); txt.textContent = 'Coba';
            btn.classList.remove('cam-active'); btn.title = 'Coba Lagi';
        }
    }
}

window.toggleCamera = function(){
    if(cameraEnabled){ cameraEnabled = false; stopCamera(); }
    else { cameraEnabled = true; startCamera(); }
};

window.startCamera = function(){
    cameraEnabled = true;
    if(!window.jsQR){ cameraEnabled=false; updateCameraUI('error'); showCamError('Library jsQR tidak termuat. Refresh halaman.'); return; }
    if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){ cameraEnabled=false; updateCameraUI('error'); showCamError('Browser tidak mendukung kamera.<br>Gunakan tab Mesin / Manual.'); return; }
    stopCameraStream();
    navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'},width:{ideal:640},height:{ideal:640}}})
    .then(function(stream){
        cameraStream = stream;
        var v = document.getElementById('qrVideo');
        if(!v) return;
        v.srcObject = stream; v.play();
        updateCameraUI('active');
        var st = document.getElementById('qrStatusText');
        if(st) st.textContent = 'Arahkan kamera ke QR Code nomor peserta';
        scanning = true; scanFrame();
    })
    .catch(function(err){
        console.warn('Camera err:', err);
        cameraEnabled = false; updateCameraUI('error');
        showCamError('Akses kamera ditolak.<br>Gunakan tab Mesin / Manual.');
    });
};

function stopCameraStream(){
    if(cameraStream){ cameraStream.getTracks().forEach(function(t){ t.stop(); }); cameraStream = null; }
    if(qrAnimFrame){ cancelAnimationFrame(qrAnimFrame); qrAnimFrame = null; }
    scanning = false;
}

function stopCamera(){ stopCameraStream(); updateCameraUI('off'); }

function showCamError(msg){
    var err = document.getElementById('qrCamError');
    if(err){ err.style.display = 'flex'; var p = err.querySelector('p'); if(p) p.innerHTML = msg; }
}

function resumeScan(){
    if(currentTab !== 'camera') return;
    if(!cameraStream || !cameraEnabled) return;
    if(qrAnimFrame){ cancelAnimationFrame(qrAnimFrame); qrAnimFrame = null; }
    scanning = true; scanFrame();
}

function scanFrame(){
    if(!scanning || !cameraStream){ qrAnimFrame = null; return; }
    if(savingInProgress){ qrAnimFrame = requestAnimationFrame(scanFrame); return; }
    var v = document.getElementById('qrVideo');
    if(!v || v.readyState < v.HAVE_ENOUGH_DATA){ qrAnimFrame = requestAnimationFrame(scanFrame); return; }
    var tc = document.createElement('canvas');
    tc.width = v.videoWidth; tc.height = v.videoHeight;
    var tx = tc.getContext('2d');
    tx.drawImage(v, 0, 0);
    var id = tx.getImageData(0, 0, tc.width, tc.height);
    var code = window.jsQR(id.data, id.width, id.height, {inversionAttempts:'dontInvert'});
    if(code && code.data){
        var nomor = code.data.trim();
        document.getElementById('qrResult').value = nomor;
        document.getElementById('qrStatusText').textContent = '✅ QR terdeteksi: ' + nomor;
        scanning = false; qrAnimFrame = null;
        lookupSiswa('qr');
    } else {
        qrAnimFrame = requestAnimationFrame(scanFrame);
    }
}

/* ===========================
 * LOOKUP + AUTO-SAVE (DENGAN ATURAN WAKTU)
 * =========================== */
window.lookupSiswa = function(src){
    if(savingInProgress) return;
    if(!selectedAsesmen){ showAlert('scanAlert','Pilih asesmen terlebih dahulu.','warning'); return; }

    var nomor = src==='qr'
        ? (document.getElementById('qrResult').value||'').trim()
        : (document.getElementById('manualInput').value||'').trim();
    if(!nomor){ showAlert('scanAlert','Nomor peserta tidak boleh kosong.','warning'); return; }

    closeAlert('scanAlert');
    document.getElementById('siswaCard').classList.remove('show');
    savingInProgress = true;

    fetch('',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=lookup_siswa&nomor_peserta='+encodeURIComponent(nomor)+'&id_asesmen='+encodeURIComponent(selectedAsesmen.id)
    })
    .then(function(r){ return r.json(); })
    .then(function(res){
        if(!res.success){
            savingInProgress = false;
            showAlert('scanAlert', res.message || 'Nomor peserta tidak ditemukan.', 'error');
            showToast(res.message || 'Tidak ditemukan', 'error');
            setTimeout(resetForm, 2200);
            return;
        }
        currentSiswa = res.data;

        // Cek sudah pernah absen
        if(res.data.id_absensi){
            renderSiswaCard(res.data, 'rejected');
            var waktu = res.data.waktu_absen ? ' pada ' + res.data.waktu_absen : '';
            var msg = res.data.nama_lengkap + ' sudah absen ('+res.data.status_kehadiran+waktu+'). Absensi tidak dapat diulang.';
            showAlert('scanAlert', msg, 'warning');
            showToast('⚠ '+res.data.nama_lengkap+' sudah absen', 'warning');
            savingInProgress = false;
            setTimeout(resetForm, 2600);
            return;
        }

        // Belum absen -> eksekusi auto-save
        renderSiswaCard(res.data, 'processing');
        doAutoSave(res.data);
    })
    .catch(function(){
        savingInProgress = false;
        showAlert('scanAlert','Gagal menghubungi server.','error');
        setTimeout(resetForm, 2000);
    });
};

function doAutoSave(siswa){
    var metode = currentTab === 'camera' ? 'Scan QR' : 'Manual';
    fetch('',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:[
            'action=simpan_absensi',
            'id_asesmen='+encodeURIComponent(selectedAsesmen.id),
            'nomor_peserta='+encodeURIComponent(siswa.nomor_peserta),
            'id_siswa='+encodeURIComponent(siswa.id_siswa),
            'id_kelas_mata_pelajaran='+encodeURIComponent(siswa.id_kelas_mata_pelajaran),
            'status_kehadiran=Hadir',
            'metode_absen='+encodeURIComponent(metode),
            'keterangan='
        ].join('&')
    })
    .then(function(r){ return r.json(); })
    .then(function(res){
        savingInProgress = false;
        if(res.success){
            var statusSaved = (res.data && res.data.status) ? res.data.status : 'Hadir';
            var stateKey = (statusSaved === 'Terlambat') ? 'terlambat' : 'success';

            renderSiswaCard(siswa, stateKey);

            if(statusSaved === 'Terlambat'){
                showToast('⚠ TERLAMBAT — ' + siswa.nama_lengkap, 'warning');
            } else {
                showToast('✓ HADIR — ' + siswa.nama_lengkap, 'success');
            }

            loadMonitoring({silent:true});
            setTimeout(resetForm, 2000);
        } else {
            renderSiswaCard(siswa, 'rejected');
            showAlert('scanAlert', res.message || 'Gagal menyimpan absensi.', 'error');
            showToast('✗ ' + (res.message || 'Gagal'), 'error');
            setTimeout(resetForm, 2800);
        }
    })
    .catch(function(){
        savingInProgress = false;
        showAlert('scanAlert','Gagal menghubungi server.','error');
        setTimeout(resetForm, 2800);
    });
}

/* ===========================
 * RENDER SISWA CARD
 * =========================== */
function renderSiswaCard(s, state){
    state = state || 'processing';
    document.getElementById('siswaNama').textContent = s.nama_lengkap || '—';
    document.getElementById('siswaNISN').textContent = s.nisn || '—';
    document.getElementById('siswaNIS').textContent  = s.nis || '—';

    var aw = document.getElementById('siswaAvatarWrap');
    if(s.foto){
        aw.innerHTML = '<img class="siswa-avatar" src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/uploads/'+esc(s.foto)+'" alt="foto" onerror="this.style.display=\'none\'">';
    } else {
        aw.innerHTML = '<div class="siswa-avatar-ph">'+esc((s.nama_lengkap||'S').charAt(0).toUpperCase())+'</div>';
    }

    var chips = '<span class="siswa-chip chip-nomor"><iconify-icon icon="solar:hashtag-square-bold"></iconify-icon>'+esc(s.nomor_peserta||'—')+'</span>'+
                '<span class="siswa-chip chip-kelas"><iconify-icon icon="solar:buildings-2-bold"></iconify-icon>'+esc(s.nama_kelas||'—')+'</span>'+
                '<span class="siswa-chip chip-mapel"><iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>'+esc(s.nama_mata_pelajaran||'—')+'</span>';

    if(state === 'success'){
        chips += '<span class="siswa-chip chip-hadir"><iconify-icon icon="solar:check-circle-bold"></iconify-icon>BERHASIL — HADIR</span>';
    } else if(state === 'terlambat'){
        chips += '<span class="siswa-chip chip-terlambat"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon>BERHASIL — TERLAMBAT</span>';
    } else if(state === 'rejected'){
        chips += '<span class="siswa-chip chip-tolak"><iconify-icon icon="solar:close-circle-bold"></iconify-icon>SUDAH ABSEN / DITOLAK</span>';
    } else {
        chips += '<span class="siswa-chip chip-proses"><iconify-icon icon="solar:restart-circle-bold" class="fa-spin"></iconify-icon>Menyimpan...</span>';
    }

    document.getElementById('siswaChips').innerHTML = chips;
    var card = document.getElementById('siswaCard');
    card.className = 'siswa-card show state-' + state;
}

/* ===========================
 * RESET FORM
 * =========================== */
window.resetForm = function(){
    if(resetTimeoutId){ clearTimeout(resetTimeoutId); resetTimeoutId = null; }
    currentSiswa = null;
    document.getElementById('siswaCard').classList.remove('show', 'state-processing', 'state-success', 'state-terlambat', 'state-rejected');
    document.getElementById('qrResult').value = '';
    document.getElementById('manualInput').value = '';
    document.getElementById('qrStatusText').textContent = 'Arahkan kamera ke QR Code nomor peserta';
    closeAlert('scanAlert');
    savingInProgress = false;
    resumeScan();
};

/* =========================================================
 * MONITORING — REALTIME INCREMENTAL
 * ========================================================= */
function computeSignature(rows){
    var parts = [];
    for(var i=0;i<rows.length;i++){
        var r = rows[i];
        parts.push((r.nomor_peserta||'') + '|' + (r.status_kehadiran||'') + '|' + (r.waktu_absen||'') + '|' + (r.metode_absen||''));
    }
    return parts.join('~');
}

function startPolling(){
    stopPolling();
    if(!selectedAsesmen) return;
    pollTimer = setInterval(function(){
        if(document.hidden) return;
        if(savingInProgress) return;
        loadMonitoring({silent:true});
    }, POLL_INTERVAL_MS);
    updateRtIndicator('live');
}
function stopPolling(){
    if(pollTimer){ clearInterval(pollTimer); pollTimer = null; }
}

function updateRtIndicator(state){
    var ind = document.getElementById('rtIndicator');
    var txt = document.getElementById('rtText');
    if(!ind) return;
    if(state === 'live'){ ind.classList.remove('paused'); if(txt) txt.textContent = 'Live'; }
    else { ind.classList.add('paused'); if(txt) txt.textContent = 'Paused'; }
}

window.loadMonitoring = function(opts){
    opts = opts || {};
    var silent = !!opts.silent;
    if(!selectedAsesmen) return;

    fetch('',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=get_monitoring&id_asesmen=' + encodeURIComponent(selectedAsesmen.id)
    })
    .then(function(r){ return r.json(); })
    .then(function(res){
        if(!res.success) return;
        var rows = res.data || [];
        var newSig = computeSignature(rows);

        if(!silent || !monTableRendered || newSig !== monSignature){
            monSignature = newSig;
            monData = rows;
            renderMonitoringStats(rows);
            renderMonitoringTable(rows, silent);
            monTableRendered = true;
        }
    })
    .catch(function(e){ console.warn('Monitoring err:', e); });
};

function renderMonitoringStats(rows){
    var total = rows.length;
    var hadir = 0, late = 0, belum = 0, lain = 0;
    for(var i=0;i<rows.length;i++){
        var s = rows[i].status_kehadiran;
        if(s === 'Hadir') hadir++;
        else if(s === 'Terlambat') late++;
        else if(s === 'Izin' || s === 'Sakit' || s === 'Alpha') lain++;
        else belum++;
    }
    document.getElementById('statTotal').textContent = total;
    document.getElementById('statHadir').textContent = hadir;
    document.getElementById('statLate').textContent  = late;
    document.getElementById('statBelum').textContent = belum;
    document.getElementById('statLain').textContent  = lain;
}

function renderMonitoringTable(rows, isSilent){
    var tbody = document.getElementById('monTbody');
    if(!rows || rows.length === 0){
        tbody.innerHTML = '<tr><td colspan="7" class="empty-state"><iconify-icon icon="solar:user-cross-bold"></iconify-icon><p>Belum ada data peserta untuk asesmen ini.</p></td></tr>';
        return;
    }

    var query = (document.getElementById('monSearch').value||'').toLowerCase().trim();
    var html = '';

    rows.forEach(function(r, idx){
        if(query){
            var searchStr = ((r.nama_lengkap||'') + ' ' + (r.nomor_peserta||'') + ' ' + (r.nama_kelas||'')).toLowerCase();
            if(searchStr.indexOf(query) === -1) return;
        }

        var pillClass = 'pill-belum', statusText = 'Belum Absen';
        if(r.status_kehadiran === 'Hadir') { pillClass = 'pill-hadir'; statusText = 'Hadir'; }
        else if(r.status_kehadiran === 'Terlambat') { pillClass = 'pill-terlambat'; statusText = 'Terlambat'; }
        else if(r.status_kehadiran === 'Izin') { pillClass = 'pill-izin'; statusText = 'Izin'; }
        else if(r.status_kehadiran === 'Sakit') { pillClass = 'pill-sakit'; statusText = 'Sakit'; }
        else if(r.status_kehadiran === 'Alpha') { pillClass = 'pill-alpha'; statusText = 'Alpha'; }

        var avHtml = r.foto
            ? '<img class="mon-avatar" src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/uploads/' + esc(r.foto) + '" onerror="this.style.display=\'none\'">'
            : '<div class="mon-avatar-ph">' + esc((r.nama_lengkap||'S').charAt(0).toUpperCase()) + '</div>';

        html += '<tr data-nomor="' + esc(r.nomor_peserta) + '">' +
            '<td style="color:#94A3B8;font-weight:700;">' + (idx + 1) + '</td>' +
            '<td><div style="display:flex;align-items:center;gap:8px;">' + avHtml + '<div><div class="mon-name">' + esc(r.nama_lengkap) + '</div><div class="mon-sub">NISN: ' + esc(r.nisn||'—') + '</div></div></div></td>' +
            '<td><strong style="color:var(--navy);font-family:var(--font-h);">' + esc(r.nomor_peserta) + '</strong></td>' +
            '<td>' + esc(r.nama_kelas||'—') + '</td>' +
            '<td><span class="status-pill ' + pillClass + '"><span class="pill-dot"></span>' + statusText + '</span></td>' +
            '<td style="font-size:.72rem;color:#64748B;">' + fmtWaktu(r.waktu_absen) + '</td>' +
            '<td>' + (r.metode_absen ? '<span style="font-size:.7rem;padding:2px 6px;border-radius:4px;background:#E2E8F0;font-weight:600;">' + esc(r.metode_absen) + '</span>' : '—') + '</td>' +
        '</tr>';
    });

    tbody.innerHTML = html || '<tr><td colspan="7" class="empty-state"><iconify-icon icon="solar:magnifer-linear"></iconify-icon><p>Tidak ada hasil yang sesuai dengan kata kunci.</p></td></tr>';
}

window.filterMonitoringTable = function(){
    renderMonitoringTable(monData, false);
};

window.refreshMonitoringClick = function(){
    var btn = document.getElementById('btnRefreshMon');
    if(btn) btn.classList.add('spinning');
    loadMonitoring();
    setTimeout(function(){ if(btn) btn.classList.remove('spinning'); }, 800);
};

// Enter key support di input manual
document.addEventListener('DOMContentLoaded', function(){
    var mi = document.getElementById('manualInput');
    if(mi){
        mi.addEventListener('keydown', function(e){
            if(e.key === 'Enter'){
                e.preventDefault();
                lookupSiswa('manual');
            }
        });
    }
    loadAsesmenList();
});
</script>
</body>
</html>
