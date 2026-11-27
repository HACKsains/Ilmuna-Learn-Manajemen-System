<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — CATATAN PELANGGARAN SISWA PER KELAS & MAPEL (PORTAL GURU)
 * ==============================================================================
 * Model Tabel Siswa ala Absensi:
 *   1. Guru wajib memilih Kelas dan Mata Pelajaran yang diampu terlebih dahulu.
 *   2. Tabel menampilkan seluruh siswa pada kelas tersebut beserta ringkasan
 *      pelanggaran (Grup WA & Realitas) dan akumulasi poin.
 *   3. Di samping setiap siswa terdapat tombol "+ Pelanggaran" untuk langsung
 *      mencatat pelanggaran siswa tersebut, dan tombol "Riwayat" untuk melihat/
 *      mengubah/menghapus catatan siswa tersebut.
 *   4. Dilengkapi Tab Log Riwayat & Ekspor Excel (.xlsx) Lumina Gold.
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/../../includes/academic-sync-engine.php';

requireRole(['guru', 'admin']);
$activeRole = hasRole('guru') ? 'guru' : 'admin';
$GLOBALS['activeRole'] = $activeRole;

if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
    require_once __DIR__ . '/../admin/catatan-pelanggaran-excel.php';
    exit;
}

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");

// Pastikan tabel catatan_pelanggaran_siswa tersedia
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `catatan_pelanggaran_siswa` (
        `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_siswa` INT(11) UNSIGNED NOT NULL,
        `id_kelas` INT(11) UNSIGNED NOT NULL,
        `id_kelas_mata_pelajaran` INT(11) UNSIGNED NULL DEFAULT NULL,
        `id_guru` INT(11) UNSIGNED NULL DEFAULT NULL,
        `recorded_by_user_id` INT(11) NULL DEFAULT NULL,
        `recorded_by_role` ENUM('admin','guru') NOT NULL DEFAULT 'guru',
        `lokasi_kejadian` ENUM('Grup WA','Realitas') NOT NULL DEFAULT 'Realitas',
        `tanggal_kejadian` DATE NOT NULL,
        `waktu_kejadian` TIME NULL DEFAULT NULL,
        `kategori_pelanggaran` VARCHAR(120) NOT NULL,
        `tingkat_pelanggaran` ENUM('Ringan','Sedang','Berat') NOT NULL DEFAULT 'Ringan',
        `poin` INT(11) NOT NULL DEFAULT 5,
        `kronologi` TEXT NOT NULL,
        `bukti_keterangan` VARCHAR(255) NULL DEFAULT NULL,
        `tindak_lanjut` TEXT NULL DEFAULT NULL,
        `status_penanganan` ENUM('Baru','Pembinaan','Selesai') NOT NULL DEFAULT 'Baru',
        `tahun_pelajaran` VARCHAR(30) NULL DEFAULT '2026/2027',
        `semester` ENUM('Ganjil','Genap') NOT NULL DEFAULT 'Ganjil',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_pel_siswa` (`id_siswa`, `tanggal_kejadian`),
        INDEX `idx_pel_kelas_kmp` (`id_kelas`, `id_kelas_mata_pelajaran`),
        INDEX `idx_pel_lokasi` (`lokasi_kejadian`, `tingkat_pelanggaran`, `status_penanganan`),
        INDEX `idx_pel_guru` (`id_guru`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$currentUser  = getCurrentUser();
$isAdmin      = hasRole('admin');
$resolvedGuru = ilmuna_resolve_logged_guru($pdo, $currentUser);
$idGuruLogged = (int)($resolvedGuru['id_guru'] ?? 0);
$idUserLogged = (int)($currentUser['id'] ?? 0);
$isStrictGuru = ($activeRole === 'guru' || !$isAdmin);

$activeTahun = '2026/2027';
$activeSem   = ((int)date('n') >= 7) ? 'Ganjil' : 'Genap';
try {
    $tpDb = trim((string)$pdo->query("SELECT tahun_pelajaran_aktif FROM identitas_sekolah WHERE is_active = 1 LIMIT 1")->fetchColumn());
    if ($tpDb !== '') $activeTahun = $tpDb;
} catch (Throwable $e) {}

// Daftar Kelas & Mapel (KMP) yang diampu Guru ini
$kmpList = [];
if ($isStrictGuru) {
    if ($idGuruLogged > 0) {
        $stKmp = $pdo->prepare("
            SELECT kmp.id AS id_kmp, k.id AS id_kelas, k.nama_kelas, mp.id AS id_mapel, mp.nama_mata_pelajaran
            FROM kelas_mata_pelajaran kmp
            JOIN kelas k ON k.id = kmp.id_kelas
            JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
            JOIN guru_mata_pelajaran gmp ON gmp.id = kmp.id_guru_mata_pelajaran
            WHERE gmp.id_guru = ?
            ORDER BY k.nama_kelas ASC, mp.nama_mata_pelajaran ASC
        ");
        $stKmp->execute([$idGuruLogged]);
        $kmpList = $stKmp->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    $kmpList = $pdo->query("
        SELECT kmp.id AS id_kmp, k.id AS id_kelas, k.nama_kelas, mp.id AS id_mapel, mp.nama_mata_pelajaran
        FROM kelas_mata_pelajaran kmp
        JOIN kelas k ON k.id = kmp.id_kelas
        JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
        ORDER BY k.nama_kelas ASC, mp.nama_mata_pelajaran ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$allowedKmpMap = [];
$kelasOptions  = [];
foreach ($kmpList as $km) {
    $allowedKmpMap[(int)$km['id_kmp']] = $km;
    $kid = (int)$km['id_kelas'];
    if (!isset($kelasOptions[$kid])) {
        $kelasOptions[$kid] = $km['nama_kelas'];
    }
}

/* ==============================================================================
 * AJAX JSON API (?api=1)
 * ============================================================================== */
if (isset($_GET['api']) && $_GET['api'] === '1') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $act = trim((string)($_POST['api_action'] ?? $_GET['api_action'] ?? 'roster'));

    try {
        // Ambil daftar siswa per KMP beserta ringkasan & log pelanggarannya
        if ($act === 'roster') {
            $idKmp = (int)($_GET['id_kmp'] ?? 0);
            if ($idKmp <= 0 || !isset($allowedKmpMap[$idKmp])) {
                echo json_encode([
                    'success'  => true,
                    'students' => [],
                    'logs'     => [],
                    'counts'   => ['students' => 0, 'logs' => 0, 'wa' => 0, 'realitas' => 0],
                ]);
                exit;
            }

            $kmpInfo = $allowedKmpMap[$idKmp];
            $idKelas = (int)$kmpInfo['id_kelas'];

            $stS = $pdo->prepare("SELECT id, nis, nisn, nama_lengkap FROM siswa WHERE id_kelas = ? ORDER BY nama_lengkap ASC");
            $stS->execute([$idKelas]);
            $students = $stS->fetchAll(PDO::FETCH_ASSOC);

            $stL = $pdo->prepare("
                SELECT p.*,
                       s.nama_lengkap AS nama_siswa, s.nis,
                       k.nama_kelas,
                       COALESCE(mp.nama_mata_pelajaran, '-') AS nama_mapel,
                       COALESCE(g.nama_guru, u.full_name, 'Guru') AS nama_pencatat
                FROM catatan_pelanggaran_siswa p
                JOIN siswa s ON s.id = p.id_siswa
                JOIN kelas k ON k.id = p.id_kelas
                LEFT JOIN kelas_mata_pelajaran kmp ON kmp.id = p.id_kelas_mata_pelajaran
                LEFT JOIN mata_pelajaran mp ON mp.id = kmp.id_mata_pelajaran
                LEFT JOIN guru g ON g.id = p.id_guru
                LEFT JOIN users u ON u.id = p.recorded_by_user_id
                WHERE p.id_kelas_mata_pelajaran = ?
                ORDER BY p.tanggal_kejadian DESC, p.id DESC
            ");
            $stL->execute([$idKmp]);
            $logs = $stL->fetchAll(PDO::FETCH_ASSOC);

            $bySiswa = [];
            $cWa = 0; $cReal = 0;
            foreach ($logs as $l) {
                $sid = (int)$l['id_siswa'];
                if ($l['lokasi_kejadian'] === 'Grup WA') $cWa++;
                else $cReal++;

                if (!isset($bySiswa[$sid])) {
                    $bySiswa[$sid] = [
                        'wa' => 0, 'realitas' => 0, 'total' => 0, 'poin' => 0,
                        'last_kategori' => $l['kategori_pelanggaran'],
                        'last_tanggal'  => $l['tanggal_kejadian'],
                        'last_lokasi'   => $l['lokasi_kejadian'],
                    ];
                }
                $bySiswa[$sid]['total']++;
                if ($l['lokasi_kejadian'] === 'Grup WA') $bySiswa[$sid]['wa']++;
                else $bySiswa[$sid]['realitas']++;
                $bySiswa[$sid]['poin'] += (int)$l['poin'];
            }

            foreach ($students as &$st) {
                $sid = (int)$st['id'];
                $agg = $bySiswa[$sid] ?? ['wa' => 0, 'realitas' => 0, 'total' => 0, 'poin' => 0, 'last_kategori' => '', 'last_tanggal' => '', 'last_lokasi' => ''];
                $st['kasus_wa']      = $agg['wa'];
                $st['kasus_real']    = $agg['realitas'];
                $st['total_kasus']   = $agg['total'];
                $st['total_poin']    = $agg['poin'];
                $st['last_kategori'] = $agg['last_kategori'];
                $st['last_tanggal']  = $agg['last_tanggal'];
                $st['last_lokasi']   = $agg['last_lokasi'];
            }
            unset($st);

            echo json_encode([
                'success'  => true,
                'kmp'      => $kmpInfo,
                'students' => $students,
                'logs'     => $logs,
                'counts'   => [
                    'students' => count($students),
                    'logs'     => count($logs),
                    'wa'       => $cWa,
                    'realitas' => $cReal,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($act === 'save') {
            $id        = (int)($_POST['id'] ?? 0);
            $idKmp     = (int)($_POST['id_kelas_mata_pelajaran'] ?? 0);
            $idSiswa   = (int)($_POST['id_siswa'] ?? 0);
            $lokasi    = in_array($_POST['lokasi_kejadian'] ?? '', ['Grup WA', 'Realitas'], true) ? $_POST['lokasi_kejadian'] : 'Realitas';
            $tanggal   = trim((string)($_POST['tanggal_kejadian'] ?? date('Y-m-d')));
            $waktu     = trim((string)($_POST['waktu_kejadian'] ?? ''));
            $kategori  = trim((string)($_POST['kategori_pelanggaran'] ?? ''));
            $tingkat   = in_array($_POST['tingkat_pelanggaran'] ?? '', ['Ringan', 'Sedang', 'Berat'], true) ? $_POST['tingkat_pelanggaran'] : 'Ringan';
            $poin      = max(0, min(100, (int)($_POST['poin'] ?? 5)));
            $kronologi = trim((string)($_POST['kronologi'] ?? ''));
            $bukti     = trim((string)($_POST['bukti_keterangan'] ?? ''));
            $tindak    = trim((string)($_POST['tindak_lanjut'] ?? ''));
            $status    = in_array($_POST['status_penanganan'] ?? '', ['Baru', 'Pembinaan', 'Selesai'], true) ? $_POST['status_penanganan'] : 'Baru';

            if (!isset($allowedKmpMap[$idKmp])) {
                echo json_encode(['success' => false, 'message' => 'Pilih Kelas & Mata Pelajaran yang Anda ampu terlebih dahulu.']);
                exit;
            }
            if ($idSiswa <= 0 || $kategori === '' || $kronologi === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
                echo json_encode(['success' => false, 'message' => 'Siswa, tanggal kejadian, kategori pelanggaran, dan kronologi wajib diisi.']);
                exit;
            }

            $idKelas = (int)$allowedKmpMap[$idKmp]['id_kelas'];
            $chkS = $pdo->prepare("SELECT id FROM siswa WHERE id = ? AND id_kelas = ? LIMIT 1");
            $chkS->execute([$idSiswa, $idKelas]);
            if (!$chkS->fetchColumn()) {
                echo json_encode(['success' => false, 'message' => 'Siswa tidak terdaftar pada kelas yang dipilih.']);
                exit;
            }

            $waktuVal = preg_match('/^\d{2}:\d{2}/', $waktu) ? (substr($waktu, 0, 5) . ':00') : null;

            if ($id > 0) {
                $stEx = $pdo->prepare("SELECT * FROM catatan_pelanggaran_siswa WHERE id = ? LIMIT 1");
                $stEx->execute([$id]);
                $ex = $stEx->fetch(PDO::FETCH_ASSOC);
                if (!$ex) {
                    echo json_encode(['success' => false, 'message' => 'Catatan tidak ditemukan.']);
                    exit;
                }
                if ($isStrictGuru && (int)$ex['recorded_by_user_id'] !== $idUserLogged && (int)$ex['id_guru'] !== $idGuruLogged) {
                    echo json_encode(['success' => false, 'message' => 'Anda hanya dapat mengubah catatan yang Anda buat sendiri.']);
                    exit;
                }
                if (($ex['status_penanganan'] ?? 'Baru') !== 'Baru') {
                    echo json_encode(['success' => false, 'message' => 'Catatan yang sudah Dalam Pembinaan atau Selesai tidak dapat diubah lagi.']);
                    exit;
                }
                $stU = $pdo->prepare("
                    UPDATE catatan_pelanggaran_siswa
                    SET lokasi_kejadian = ?, tanggal_kejadian = ?, waktu_kejadian = ?,
                        kategori_pelanggaran = ?, kronologi = ?, bukti_keterangan = ?, tindak_lanjut = ?
                    WHERE id = ?
                ");
                $stU->execute([
                    $lokasi, $tanggal, $waktuVal,
                    mb_substr($kategori, 0, 120),
                    $kronologi, $bukti !== '' ? mb_substr($bukti, 0, 255) : null,
                    $tindak !== '' ? $tindak : null,
                    $id
                ]);
                echo json_encode(['success' => true, 'message' => 'Catatan pelanggaran berhasil diperbarui.']);
                exit;
            }

            // Guru hanya melaporkan pelanggaran; skor poin & status pembinaan ditentukan oleh Guru BK
            $stI = $pdo->prepare("
                INSERT INTO catatan_pelanggaran_siswa
                    (id_siswa, id_kelas, id_kelas_mata_pelajaran, id_guru, recorded_by_user_id, recorded_by_role,
                     lokasi_kejadian, tanggal_kejadian, waktu_kejadian, kategori_pelanggaran, tingkat_pelanggaran,
                     poin, kronologi, bukti_keterangan, tindak_lanjut, status_penanganan, tahun_pelajaran, semester)
                VALUES (?, ?, ?, ?, ?, 'guru', ?, ?, ?, ?, 'Ringan', 0, ?, ?, ?, 'Baru', ?, ?)
            ");
            $stI->execute([
                $idSiswa, $idKelas, $idKmp,
                $idGuruLogged > 0 ? $idGuruLogged : null,
                $idUserLogged > 0 ? $idUserLogged : null,
                $lokasi, $tanggal, $waktuVal,
                mb_substr($kategori, 0, 120),
                $kronologi, $bukti !== '' ? mb_substr($bukti, 0, 255) : null,
                $tindak !== '' ? $tindak : null,
                $activeTahun, $activeSem
            ]);

            echo json_encode(['success' => true, 'message' => 'Laporan pelanggaran berhasil dikirim ke Guru BK (Baru Dicatat).']);
            exit;
        }

        if ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
                exit;
            }
            $stEx = $pdo->prepare("SELECT id, id_guru, recorded_by_user_id, status_penanganan FROM catatan_pelanggaran_siswa WHERE id = ? LIMIT 1");
            $stEx->execute([$id]);
            $ex = $stEx->fetch(PDO::FETCH_ASSOC);
            if (!$ex) {
                echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan.']);
                exit;
            }
            if ($isStrictGuru && (int)$ex['recorded_by_user_id'] !== $idUserLogged && (int)$ex['id_guru'] !== $idGuruLogged) {
                echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki izin menghapus catatan ini.']);
                exit;
            }
            if (($ex['status_penanganan'] ?? 'Baru') !== 'Baru') {
                echo json_encode(['success' => false, 'message' => 'Catatan yang sudah Dalam Pembinaan atau Selesai tidak dapat dihapus.']);
                exit;
            }
            $pdo->prepare("DELETE FROM catatan_pelanggaran_siswa WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Catatan pelanggaran berhasil dihapus.']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Kesalahan server: ' . $e->getMessage()]);
    }
    exit;
}

$pageTitle = 'Catatan Pelanggaran Siswa';
$pageIcon  = 'solar:shield-warning-bold';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/table-template.php';
require_once BASE_PATH . '/includes/modal-template.php';
?>
<style>
.pel-wrap { width: 100%; max-width: 100%; margin: 0 auto; }
.pel-badge-wa { background: rgba(22, 163, 74, 0.12); color: #15803D; border: 1px solid rgba(22, 163, 74, 0.25); padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
.pel-badge-real { background: rgba(29, 59, 94, 0.10); color: #1D3B5E; border: 1px solid rgba(29, 59, 94, 0.22); padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
.pel-badge-clean { background: #F1F5F9; color: #64748B; border: 1px solid #E2E8F0; padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
.pel-badge-ringan { background: rgba(245, 158, 11, 0.14); color: #B45309; }
.pel-badge-sedang { background: rgba(234, 88, 12, 0.14); color: #C2410C; }
.pel-badge-berat { background: rgba(220, 38, 38, 0.14); color: #DC2626; }
.pel-student-banner { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; border-radius: 12px; background: linear-gradient(135deg, rgba(29, 59, 94, 0.06), rgba(13, 122, 135, 0.06)); border: 1px solid rgba(13, 122, 135, 0.22); margin-bottom: 14px; }
.pel-student-banner strong { font-size: 14px; color: #1D3B5E; display: block; }
.pel-student-banner small { font-size: 12px; color: #475569; }
.pel-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.pel-field { margin-bottom: 14px; }
.pel-field label { display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 6px; }
.pel-input, .pel-textarea { width: 100%; border: 1.5px solid #CBD5E1; border-radius: 10px; padding: 10px 12px; font-size: 13.5px; color: #0F172A; background: #fff; font-family: inherit; transition: border-color .15s; box-sizing: border-box; }
.pel-input:focus, .pel-textarea:focus { outline: none; border-color: #0D7A87; box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.12); }
.pel-textarea { min-height: 78px; resize: vertical; }
.pel-loc-group { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.pel-loc-card { border: 1.5px solid #CBD5E1; border-radius: 12px; padding: 10px 12px; cursor: pointer; display: flex; align-items: center; gap: 10px; background: #fff; transition: all .15s; text-align: left; }
.pel-loc-card.is-active { border-color: #0D7A87; background: rgba(13, 122, 135, 0.06); box-shadow: 0 0 0 2px rgba(13, 122, 135, 0.14); }
.pel-loc-card strong { display: block; font-size: 13px; color: #0F172A; }
.pel-loc-card small { display: block; font-size: 11px; color: #64748B; }
.pel-chip-row { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.pel-preset-chip { font-size: 11.5px; font-weight: 600; padding: 5px 11px; border-radius: 999px; border: 1px solid #CBD5E1; background: #F8FAFC; color: #334155; cursor: pointer; transition: all .15s; }
.pel-preset-chip:hover { border-color: #0D7A87; color: #0D7A87; background: #ECFEFF; }
.pel-preset-chip.is-picked { border-color: #1D3B5E; background: linear-gradient(135deg, #1D3B5E, #0D7A87); color: #FFFFFF; }
.pel-act-cell { display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }
/* Tombol Seragam Tema Lumina Gold (Navy #1D3B5E, Teal #0D7A87, Gold #F59E0B) */
.ilmuna-nav-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 36px; padding: 0 14px; border-radius: 10px; font-size: 12.5px; font-weight: 700; cursor: pointer; border: none; transition: all .18s ease; white-space: nowrap; font-family: inherit; }
.ilmuna-nav-btn--primary { background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); color: #FFFFFF; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.28); }
.ilmuna-nav-btn--primary:hover { transform: translateY(-1px); box-shadow: 0 6px 15px rgba(245, 158, 11, 0.38); }
.ilmuna-nav-btn--ghost { background: #FFFFFF; color: #1D3B5E; border: 1.5px solid #CBD5E1; }
.ilmuna-nav-btn--ghost:hover { background: #F8FAFC; border-color: #0D7A87; color: #0D7A87; }
.ilmuna-nav-btn:disabled { opacity: 0.65; cursor: not-allowed; transform: none; }
.pel-btn-add { display: inline-flex; align-items: center; gap: 5px; height: 32px; padding: 0 12px; border-radius: 9px; border: none; background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); color: #FFFFFF; font-size: 12px; font-weight: 700; cursor: pointer; box-shadow: 0 3px 10px rgba(245, 158, 11, 0.26); transition: all .15s ease; font-family: inherit; }
.pel-btn-add:hover { transform: translateY(-1px); box-shadow: 0 5px 14px rgba(245, 158, 11, 0.36); }
.pel-btn-hist { display: inline-flex; align-items: center; gap: 5px; height: 32px; padding: 0 11px; border-radius: 9px; border: 1.5px solid #CBD5E1; background: #FFFFFF; color: #1D3B5E; font-size: 12px; font-weight: 700; cursor: pointer; transition: all .15s ease; font-family: inherit; }
.pel-btn-hist:hover { border-color: #0D7A87; color: #0D7A87; background: #F0FDFA; }
.ilm-act-btn { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 9px; border: 1.5px solid #CBD5E1; background: #FFFFFF; color: #1D3B5E; cursor: pointer; transition: all .15s ease; }
.ilm-act-btn--edit:hover { border-color: #0D7A87; color: #0D7A87; background: #F0FDFA; }
.ilm-act-btn--delete:hover { border-color: #DC2626; color: #DC2626; background: #FEF2F2; }
.pel-st-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.pel-st-baru { background: rgba(245, 158, 11, 0.14); color: #B45309; border: 1px solid rgba(245, 158, 11, 0.30); }
.pel-st-pembinaan { background: rgba(13, 122, 135, 0.12); color: #0D7A87; border: 1px solid rgba(13, 122, 135, 0.28); }
.pel-st-selesai { background: rgba(22, 163, 74, 0.12); color: #15803D; border: 1px solid rgba(22, 163, 74, 0.28); }
.pel-btn-step { display: inline-flex; align-items: center; gap: 5px; height: 30px; padding: 0 11px; border-radius: 8px; border: none; font-size: 11.5px; font-weight: 700; cursor: pointer; transition: all .15s ease; font-family: inherit; color: #FFFFFF; }
.pel-btn-step--binaan { background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%); box-shadow: 0 3px 10px rgba(29, 59, 94, 0.22); }
.pel-btn-step--binaan:hover { transform: translateY(-1px); box-shadow: 0 5px 14px rgba(13, 122, 135, 0.32); }
.pel-btn-step--selesai { background: linear-gradient(135deg, #0D7A87 0%, #15803D 100%); box-shadow: 0 3px 10px rgba(13, 122, 135, 0.24); }
.pel-btn-step--selesai:hover { transform: translateY(-1px); box-shadow: 0 5px 14px rgba(21, 128, 61, 0.32); }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.pel-hist-item { border: 1px solid #E2E8F0; border-radius: 12px; padding: 12px 14px; margin-bottom: 10px; background: #F8FAFC; }
.pel-hist-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; margin-bottom: 6px; }
@media (max-width: 768px) {
    .pel-form-grid, .pel-loc-group { grid-template-columns: 1fr; }
}
</style>

<div class="pel-wrap">
    <!-- 1. SEGMENTED TABS -->
    <div class="curriculum-tabs-wrapper" id="pelGuruTabs">
        <button type="button" class="curriculum-tab-btn is-active" data-view="roster">
            <iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon>
            <span class="curriculum-tab-label">Daftar Siswa (Input Pelanggaran)</span>
            <span class="curriculum-tab-counter" id="cntTabSiswa">0</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-view="logs">
            <iconify-icon icon="solar:clipboard-list-bold"></iconify-icon>
            <span class="curriculum-tab-label">Log Riwayat Pelanggaran</span>
            <span class="curriculum-tab-counter" id="cntTabLogs">0</span>
        </button>
    </div>

    <!-- 2. TOOLBAR PILIH KELAS & MAPEL + SEARCH + EXPORT -->
    <div class="ilmuna-filter-box">
        <div class="ilmuna-filter-bottomline" style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid #E2E8F0;">
            <select id="filterKelasGuru" class="ilmuna-select" onchange="PEL_GURU.onKelasChange()">
                <option value="0">-- 1. Pilih Kelas Ampu --</option>
                <?php foreach ($kelasOptions as $kid => $knama): ?>
                    <option value="<?= (int)$kid ?>"><?= htmlspecialchars($knama, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterMapelGuru" class="ilmuna-select" onchange="PEL_GURU.loadRoster()" disabled>
                <option value="0">-- 2. Pilih Mata Pelajaran --</option>
            </select>
        </div>

        <div class="ilmuna-filter-topline">
            <div class="ilmuna-pill-group" id="pelLocPills">
                <button type="button" class="ilmuna-pill-btn is-active" data-loc="all">Semua</button>
                <button type="button" class="ilmuna-pill-btn" data-loc="Grup WA" style="color:#15803D;">Grup WA (<span id="cntPillWa">0</span>)</button>
                <button type="button" class="ilmuna-pill-btn" data-loc="Realitas" style="color:#1D3B5E;">Realitas (<span id="cntPillReal">0</span>)</button>
            </div>

            <div class="ilmuna-search-wrap">
                <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                <input type="text" id="pelSearchInput" class="ilmuna-search-input" placeholder="Cari nama siswa, NIS, kategori pelanggaran..." autocomplete="off">
            </div>

            <div class="ilmuna-filter-actions">
                <div class="ilmuna-nav-stat-inline">
                    <iconify-icon icon="solar:document-text-bold" width="14"></iconify-icon>
                    <strong id="pelKpiCount">0</strong>
                    <span id="pelKpiLabel">Siswa</span>
                </div>
                <button type="button" id="btnExportPelGuru" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" onclick="PEL_GURU.exportExcel(this)">
                    <iconify-icon icon="solar:file-download-bold" width="16" style="color:#10B981;"></iconify-icon>
                    <span>Ekspor Excel</span>
                </button>
            </div>
        </div>
    </div>

    <!-- 3A. TABEL DAFTAR SISWA (ALA ABSENSI) -->
    <div class="ilm-data-container" id="guruRosterWrap">
        <div class="ilm-data-gunungan" aria-hidden="true"></div>
        <div class="ilm-table-responsive">
            <table class="ilm-table">
                <thead>
                    <tr>
                        <th style="width:52px;">No</th>
                        <th>Siswa</th>
                        <th>Di Grup WA</th>
                        <th>Di Realitas</th>
                        <th>Total Poin</th>
                        <th>Catatan Terakhir</th>
                        <th style="text-align:right;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="pelRosterTbody">
                    <tr><td colspan="7" style="text-align:center;padding:38px;color:#64748B;">Pilih <strong>Kelas</strong> dan <strong>Mata Pelajaran</strong> di atas terlebih dahulu untuk menampilkan tabel siswa.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 3B. TABEL LOG RIWAYAT PELANGGARAN -->
    <div class="ilm-data-container" id="guruLogsWrap" style="display:none;">
        <div class="ilm-data-gunungan" aria-hidden="true"></div>
        <div class="ilm-table-responsive">
            <table class="ilm-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Siswa</th>
                        <th>Lokasi</th>
                        <th>Kategori &amp; Kronologi</th>
                        <th>Tingkat &amp; Poin</th>
                        <th>Status</th>
                        <th style="text-align:right;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="pelLogsTbody">
                    <tr><td colspan="7" style="text-align:center;padding:38px;color:#64748B;">Pilih Kelas dan Mata Pelajaran terlebih dahulu.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL INPUT / EDIT PELANGGARAN SISWA -->
<div class="ilm-modal-backdrop" id="modalPelanggaranGuru">
    <div class="ilm-modal-dialog" style="max-width:660px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon" style="background:linear-gradient(135deg,#1D3B5E,#0D7A87);">
                    <iconify-icon icon="solar:shield-warning-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="pelModalTitle">Tambah Pelanggaran Siswa</h3>
                    <p class="ilm-modal-header__subtitle">Catat pelanggaran di Grup WA maupun di Realitas (Tatap Muka)</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalPelanggaranGuru')" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>

        <form id="formPelanggaranGuru" onsubmit="PEL_GURU.save(event)">
            <input type="hidden" id="pelId" value="0">
            <input type="hidden" id="pelKmpId" value="0">
            <input type="hidden" id="pelSiswaId" value="0">
            <input type="hidden" id="pelLokasi" value="Realitas">

            <div class="ilm-modal-body" style="max-height:calc(86vh - 160px);overflow-y:auto;">
                <div class="pel-student-banner">
                    <div>
                        <strong id="pelBannerNama">-</strong>
                        <small id="pelBannerMeta">-</small>
                    </div>
                    <iconify-icon icon="solar:user-id-bold" width="26" style="color:#0D7A87;"></iconify-icon>
                </div>

                <div class="pel-field">
                    <label>Lokasi Kejadian Pelanggaran</label>
                    <div class="pel-loc-group" id="pelLocPicker">
                        <button type="button" class="pel-loc-card" data-loc="Grup WA" onclick="PEL_GURU.pickLokasi('Grup WA')">
                            <iconify-icon icon="solar:chat-round-dots-bold" width="24" style="color:#16A34A;flex-shrink:0;"></iconify-icon>
                            <div><strong>Di Grup WA</strong><small>Chat tidak sopan, spam, etika grup WA</small></div>
                        </button>
                        <button type="button" class="pel-loc-card is-active" data-loc="Realitas" onclick="PEL_GURU.pickLokasi('Realitas')">
                            <iconify-icon icon="solar:users-group-rounded-bold" width="24" style="color:#1D3B5E;flex-shrink:0;"></iconify-icon>
                            <div><strong>Di Realitas (Tatap Muka)</strong><small>Di kelas, lingkungan sekolah, KBM</small></div>
                        </button>
                    </div>
                </div>

                <div class="pel-form-grid">
                    <div class="pel-field">
                        <label for="pelTanggal">Tanggal Kejadian</label>
                        <input type="date" id="pelTanggal" class="pel-input" required>
                    </div>
                    <div class="pel-field">
                        <label for="pelWaktu">Waktu (WIB, opsional)</label>
                        <input type="time" id="pelWaktu" class="pel-input">
                    </div>
                </div>

                <div class="pel-field">
                    <label for="pelKategori">Kategori / Jenis Pelanggaran (klik cepat atau ketik)</label>
                    <input type="text" id="pelKategori" class="pel-input" placeholder="Klik pilihan di bawah atau ketik jenis pelanggaran..." required>
                    <div class="pel-chip-row" id="pelPresetChips"></div>
                </div>

                <div class="pel-field">
                    <label for="pelKronologi">Kronologi Kejadian</label>
                    <textarea id="pelKronologi" class="pel-textarea" placeholder="Jelaskan kronologi kejadian secara objektif..." required></textarea>
                </div>

                <div class="pel-field">
                    <label for="pelBukti">Bukti / Referensi (opsional)</label>
                    <input type="text" id="pelBukti" class="pel-input" placeholder="Screenshot chat WA / Saksi di kelas">
                </div>

                <div class="pel-field" style="margin-bottom:0;">
                    <label for="pelTindak">Tindak Lanjut / Pembinaan (opsional)</label>
                    <textarea id="pelTindak" class="pel-textarea" style="min-height:58px;" placeholder="Teguran lisan, peringatan di grup, pemanggilan wali, dsb..."></textarea>
                </div>
            </div>

            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalPelanggaranGuru')">Batal</button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:disk-bold"></iconify-icon>
                    <span>Simpan Pelanggaran</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL RIWAYAT PELANGGARAN PER SISWA -->
<div class="ilm-modal-backdrop" id="modalRiwayatSiswaGuru">
    <div class="ilm-modal-dialog" style="max-width:680px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon" style="background:linear-gradient(135deg,#1D3B5E,#0D7A87);">
                    <iconify-icon icon="solar:history-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="histGuruTitle">Riwayat Pelanggaran Siswa</h3>
                    <p class="ilm-modal-header__subtitle" id="histGuruSub">-</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalRiwayatSiswaGuru')" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <div class="ilm-modal-body" id="histGuruBody" style="max-height:calc(82vh - 140px);overflow-y:auto;"></div>
        <div class="ilm-modal-footer">
            <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalRiwayatSiswaGuru')">Tutup</button>
            <button type="button" class="ilm-modal-btn ilm-modal-btn--primary" id="histGuruAddBtn">
                <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                <span>Tambah Pelanggaran</span>
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var API_URL   = <?= json_encode(BASE_URL . '/dashboard/guru/catatan-pelanggaran?api=1') ?>;
    var EXCEL_URL = <?= json_encode(BASE_URL . '/dashboard/guru/catatan-pelanggaran') ?>;
    var KMP_LIST  = <?= json_encode($kmpList, JSON_UNESCAPED_UNICODE) ?>;

    var PRESETS = {
        'Grup WA': [
            'Berkata tidak sopan di Grup WA',
            'Spam / mengirim konten di luar KBM',
            'Mengabaikan arahan guru di Grup WA',
            'Menghina / bullying teman di Grup WA',
            'Keluar grup tanpa izin'
        ],
        'Realitas': [
            'Terlambat masuk kelas',
            'Mengganggu ketertiban KBM',
            'Tidak memakai seragam / atribut lengkap',
            'Membolos jam pelajaran',
            'Tidak sopan kepada guru / teman'
        ]
    };

    var STATE = {
        view: 'roster',
        locFilter: 'all',
        activeKmp: null,
        students: [],
        logs: [],
        currentPresets: PRESETS['Realitas']
    };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    async function fetchJsonSafe(url, opts, retryCount) {
        retryCount = retryCount || 0;
        var reqOpts = Object.assign({ credentials: 'same-origin' }, opts || {});
        reqOpts.headers = Object.assign({ 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, reqOpts.headers || {});
        try {
            var r = await fetch(url, reqOpts);
            var txt = await r.text();
            var trimmed = txt.trim();
            if (trimmed.charAt(0) !== '{' && trimmed.charAt(0) !== '[') {
                if (retryCount < 1) {
                    await new Promise(function (ok) { setTimeout(ok, 450); });
                    return fetchJsonSafe(url, opts, retryCount + 1);
                }
                if (trimmed.indexOf('GUARDIAN DOME') !== -1 || trimmed.indexOf('login') !== -1) {
                    window.location.reload();
                    return null;
                }
                throw new Error('Respons server tidak valid (HTTP ' + r.status + ').');
            }
            return JSON.parse(trimmed);
        } catch (err) {
            if (retryCount < 1) {
                await new Promise(function (ok) { setTimeout(ok, 450); });
                return fetchJsonSafe(url, opts, retryCount + 1);
            }
            throw err;
        }
    }

    function statusBadgeHtml(st) {
        if (st === 'Selesai') {
            return '<span class="pel-st-badge pel-st-selesai"><iconify-icon icon="solar:check-circle-bold" width="13"></iconify-icon>Selesai</span>';
        }
        if (st === 'Pembinaan') {
            return '<span class="pel-st-badge pel-st-pembinaan"><iconify-icon icon="solar:shield-user-bold" width="13"></iconify-icon>Dalam Pembinaan</span>';
        }
        return '<span class="pel-st-badge pel-st-baru"><iconify-icon icon="solar:clock-circle-bold" width="13"></iconify-icon>Baru Dicatat</span>';
    }

    function nextStepBtnHtml() {
        return '';
    }

    function scoreBadgeGuruHtml(r) {
        var p = parseInt(r.poin || 0, 10);
        if (p <= 0) {
            return '<span class="pel-badge-clean">Menunggu Skor BK</span>';
        }
        var tCls = r.tingkat_pelanggaran === 'Berat' ? 'pel-badge-berat' : (r.tingkat_pelanggaran === 'Sedang' ? 'pel-badge-sedang' : 'pel-badge-ringan');
        return '<span class="ilmuna-badge ' + tCls + '">' + esc(r.tingkat_pelanggaran) + ' · ' + p + ' Poin</span>';
    }

    function renderPresets(loc) {
        var wrap = document.getElementById('pelPresetChips');
        if (!wrap) return;
        var arr = PRESETS[loc] || PRESETS['Realitas'];
        STATE.currentPresets = arr;
        var curVal = (document.getElementById('pelKategori').value || '').trim();
        wrap.innerHTML = arr.map(function (txt, idx) {
            var cls = 'pel-preset-chip' + (curVal === txt ? ' is-picked' : '');
            return '<button type="button" class="' + cls + '" data-preset-idx="' + idx + '" onclick="PEL_GURU.pickPreset(' + idx + ')">' + esc(txt) + '</button>';
        }).join('');
    }

    window.PEL_GURU = {
        onKelasChange: function () {
            var kid = parseInt(document.getElementById('filterKelasGuru').value || '0', 10);
            var mapelSel = document.getElementById('filterMapelGuru');
            if (!kid) {
                mapelSel.innerHTML = '<option value="0">-- 2. Pilih Mata Pelajaran --</option>';
                mapelSel.disabled = true;
                STATE.activeKmp = null;
                STATE.students = [];
                STATE.logs = [];
                PEL_GURU.render();
                return;
            }
            var filtered = KMP_LIST.filter(function (k) { return parseInt(k.id_kelas, 10) === kid; });
            var html = '<option value="0">-- 2. Pilih Mata Pelajaran (' + filtered.length + ') --</option>';
            filtered.forEach(function (k) {
                html += '<option value="' + k.id_kmp + '">' + esc(k.nama_mata_pelajaran) + '</option>';
            });
            mapelSel.innerHTML = html;
            mapelSel.disabled = false;
            if (filtered.length === 1) {
                mapelSel.value = String(filtered[0].id_kmp);
                PEL_GURU.loadRoster();
            } else {
                STATE.activeKmp = null;
                STATE.students = [];
                STATE.logs = [];
                PEL_GURU.render();
            }
        },
        loadRoster: async function () {
            var idKmp = parseInt(document.getElementById('filterMapelGuru').value || '0', 10);
            if (!idKmp) {
                STATE.activeKmp = null;
                STATE.students = [];
                STATE.logs = [];
                PEL_GURU.render();
                return;
            }
            document.getElementById('pelRosterTbody').innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#64748B;">Memuat daftar siswa...</td></tr>';
            try {
                var res = await fetchJsonSafe(API_URL + '&api_action=roster&id_kmp=' + encodeURIComponent(idKmp));
                if (!res) return;
                if (res.success) {
                    STATE.activeKmp = res.kmp || null;
                    STATE.students = res.students || [];
                    STATE.logs = res.logs || [];
                    var c = res.counts || { students: 0, logs: 0, wa: 0, realitas: 0 };
                    document.getElementById('cntTabSiswa').textContent = c.students;
                    document.getElementById('cntTabLogs').textContent = c.logs;
                    document.getElementById('cntPillWa').textContent = c.wa;
                    document.getElementById('cntPillReal').textContent = c.realitas;
                    PEL_GURU.render();
                } else {
                    Ilmuna.error('Gagal Memuat', (res && res.message) || 'Tidak dapat mengambil daftar siswa.');
                }
            } catch (e) {
                Ilmuna.error('Gagal Memuat', (e && e.message) ? e.message : 'Koneksi ke server terganggu. Silakan coba lagi.');
            }
        },
        render: function () {
            var q = (document.getElementById('pelSearchInput').value || '').toLowerCase().trim();
            var isLogs = (STATE.view === 'logs');
            document.getElementById('guruRosterWrap').style.display = isLogs ? 'none' : '';
            document.getElementById('guruLogsWrap').style.display = isLogs ? '' : 'none';

            if (!STATE.activeKmp) {
                document.getElementById('cntTabSiswa').textContent = '0';
                document.getElementById('cntTabLogs').textContent = '0';
                document.getElementById('cntPillWa').textContent = '0';
                document.getElementById('cntPillReal').textContent = '0';
                document.getElementById('pelKpiCount').textContent = '0';
                document.getElementById('pelKpiLabel').textContent = isLogs ? 'Catatan' : 'Siswa';
                var msg = '<tr><td colspan="7" style="text-align:center;padding:38px;color:#64748B;">Pilih <strong>Kelas</strong> dan <strong>Mata Pelajaran</strong> di atas terlebih dahulu untuk menampilkan tabel siswa.</td></tr>';
                document.getElementById('pelRosterTbody').innerHTML = msg;
                document.getElementById('pelLogsTbody').innerHTML = msg;
                return;
            }

            if (!isLogs) {
                var sList = STATE.students.filter(function (s) {
                    if (STATE.locFilter === 'Grup WA' && s.kasus_wa <= 0) return false;
                    if (STATE.locFilter === 'Realitas' && s.kasus_real <= 0) return false;
                    if (q) {
                        var hay = ((s.nama_lengkap || '') + ' ' + (s.nis || '') + ' ' + (s.last_kategori || '')).toLowerCase();
                        if (hay.indexOf(q) === -1) return false;
                    }
                    return true;
                });
                document.getElementById('pelKpiCount').textContent = sList.length;
                document.getElementById('pelKpiLabel').textContent = 'Siswa';

                var tb = document.getElementById('pelRosterTbody');
                if (!sList.length) {
                    tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:34px;color:#64748B;">Tidak ada siswa yang sesuai dengan filter ini.</td></tr>';
                    return;
                }
                tb.innerHTML = sList.map(function (s, idx) {
                    var waBadge = s.kasus_wa > 0
                        ? '<span class="pel-badge-wa"><iconify-icon icon="solar:chat-round-dots-bold" width="13"></iconify-icon>' + s.kasus_wa + ' Kasus</span>'
                        : '<span class="pel-badge-clean">0</span>';
                    var realBadge = s.kasus_real > 0
                        ? '<span class="pel-badge-real"><iconify-icon icon="solar:users-group-rounded-bold" width="13"></iconify-icon>' + s.kasus_real + ' Kasus</span>'
                        : '<span class="pel-badge-clean">0</span>';
                    var poinCls = s.total_poin >= 30 ? 'pel-badge-berat' : (s.total_poin >= 15 ? 'pel-badge-sedang' : (s.total_poin > 0 ? 'pel-badge-ringan' : ''));
                    var poinHtml = s.total_poin > 0
                        ? '<span class="ilmuna-badge ' + poinCls + '">' + s.total_poin + ' Poin</span>'
                        : '<span class="pel-badge-clean">0 Poin</span>';
                    var lastHtml = s.total_kasus > 0
                        ? '<strong>' + esc(s.last_kategori) + '</strong><div style="font-size:11px;color:#64748B;">' + esc(s.last_tanggal) + ' · ' + esc(s.last_lokasi) + '</div>'
                        : '<span style="font-size:12px;color:#94A3B8;">Belum ada pelanggaran</span>';
                    var histBtn = s.total_kasus > 0
                        ? '<button type="button" class="pel-btn-hist" onclick="PEL_GURU.openHistory(' + s.id + ')"><iconify-icon icon="solar:history-bold" width="14"></iconify-icon><span>Riwayat (' + s.total_kasus + ')</span></button>'
                        : '';
                    return '<tr>'
                        + '<td data-label="No">' + (idx + 1) + '</td>'
                        + '<td data-label="Siswa"><strong>' + esc(s.nama_lengkap) + '</strong><div style="font-size:11px;color:#64748B;">NIS: ' + esc(s.nis || '-') + '</div></td>'
                        + '<td data-label="Di Grup WA">' + waBadge + '</td>'
                        + '<td data-label="Di Realitas">' + realBadge + '</td>'
                        + '<td data-label="Total Poin">' + poinHtml + '</td>'
                        + '<td data-label="Catatan Terakhir">' + lastHtml + '</td>'
                        + '<td data-label="Aksi" style="text-align:right;"><div class="pel-act-cell">'
                            + histBtn
                            + '<button type="button" class="pel-btn-add" onclick="PEL_GURU.openAddForStudent(' + s.id + ')"><iconify-icon icon="solar:add-circle-bold" width="15"></iconify-icon><span>Pelanggaran</span></button>'
                        + '</div></td>'
                        + '</tr>';
                }).join('');
                return;
            }

            // Tab Log Riwayat
            var lList = STATE.logs.filter(function (x) {
                if (STATE.locFilter !== 'all' && x.lokasi_kejadian !== STATE.locFilter) return false;
                if (q) {
                    var hay = ((x.nama_siswa || '') + ' ' + (x.nis || '') + ' ' + (x.kategori_pelanggaran || '') + ' ' + (x.kronologi || '')).toLowerCase();
                    if (hay.indexOf(q) === -1) return false;
                }
                return true;
            });
            document.getElementById('pelKpiCount').textContent = lList.length;
            document.getElementById('pelKpiLabel').textContent = 'Catatan';
            var ltb = document.getElementById('pelLogsTbody');
            if (!lList.length) {
                ltb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:34px;color:#64748B;">Belum ada riwayat pelanggaran pada filter ini.</td></tr>';
                return;
            }
            ltb.innerHTML = lList.map(function (r) {
                var locBadge = r.lokasi_kejadian === 'Grup WA'
                    ? '<span class="pel-badge-wa"><iconify-icon icon="solar:chat-round-dots-bold" width="13"></iconify-icon>Grup WA</span>'
                    : '<span class="pel-badge-real"><iconify-icon icon="solar:users-group-rounded-bold" width="13"></iconify-icon>Realitas</span>';
                var tCls = r.tingkat_pelanggaran === 'Berat' ? 'pel-badge-berat' : (r.tingkat_pelanggaran === 'Sedang' ? 'pel-badge-sedang' : 'pel-badge-ringan');
                return '<tr>'
                    + '<td data-label="Tanggal"><strong>' + esc(r.tanggal_kejadian) + '</strong><div style="font-size:11px;color:#64748B;">' + esc(r.waktu_kejadian ? String(r.waktu_kejadian).slice(0, 5) + ' WIB' : '-') + '</div></td>'
                    + '<td data-label="Siswa"><strong>' + esc(r.nama_siswa) + '</strong><div style="font-size:11px;color:#64748B;">NIS: ' + esc(r.nis || '-') + '</div></td>'
                    + '<td data-label="Lokasi">' + locBadge + '</td>'
                    + '<td data-label="Kategori"><strong>' + esc(r.kategori_pelanggaran) + '</strong><div style="font-size:12px;color:#475569;margin-top:2px;">' + esc(r.kronologi) + '</div></td>'
                    + '<td data-label="Skor BK">' + scoreBadgeGuruHtml(r) + '</td>'
                    + '<td data-label="Status">' + statusBadgeHtml(r.status_penanganan) + '</td>'
                    + '<td data-label="Aksi" style="text-align:right;"><div class="pel-act-cell">'
                        + nextStepBtnHtml(r, 0)
                        + (r.status_penanganan === 'Baru'
                            ? '<button type="button" class="ilm-act-btn ilm-act-btn--edit" title="Edit" onclick="PEL_GURU.openEdit(' + r.id + ')"><iconify-icon icon="solar:pen-bold" width="15"></iconify-icon></button>'
                              + '<button type="button" class="ilm-act-btn ilm-act-btn--delete" title="Hapus" onclick="PEL_GURU.remove(' + r.id + ')"><iconify-icon icon="solar:trash-bin-trash-bold" width="15"></iconify-icon></button>'
                            : '')
                    + '</div></td>'
                    + '</tr>';
            }).join('');
        },
        pickLokasi: function (loc) {
            document.getElementById('pelLokasi').value = loc;
            document.querySelectorAll('#pelLocPicker .pel-loc-card').forEach(function (btn) {
                btn.classList.toggle('is-active', btn.getAttribute('data-loc') === loc);
            });
            renderPresets(loc);
        },
        pickPreset: function (idx) {
            var txt = STATE.currentPresets[idx];
            if (!txt) return;
            var inp = document.getElementById('pelKategori');
            inp.value = txt;
            inp.focus();
            document.querySelectorAll('#pelPresetChips .pel-preset-chip').forEach(function (b) {
                b.classList.toggle('is-picked', parseInt(b.getAttribute('data-preset-idx'), 10) === idx);
            });
        },
        onTingkatChange: function () {
            var v = document.getElementById('pelTingkat').value;
            var map = { 'Ringan': 5, 'Sedang': 15, 'Berat': 30 };
            if (map[v] !== undefined) document.getElementById('pelPoin').value = map[v];
        },
        openAddForStudent: function (siswaId) {
            if (!STATE.activeKmp) return;
            var s = STATE.students.find(function (x) { return parseInt(x.id, 10) === parseInt(siswaId, 10); });
            if (!s) return;
            document.getElementById('formPelanggaranGuru').reset();
            document.getElementById('pelModalTitle').textContent = 'Tambah Pelanggaran Siswa';
            document.getElementById('pelId').value = '0';
            document.getElementById('pelKmpId').value = String(STATE.activeKmp.id_kmp);
            document.getElementById('pelSiswaId').value = String(s.id);
            document.getElementById('pelBannerNama').textContent = s.nama_lengkap + (s.nis ? ' (NIS: ' + s.nis + ')' : '');
            document.getElementById('pelBannerMeta').textContent = STATE.activeKmp.nama_kelas + ' — ' + STATE.activeKmp.nama_mata_pelajaran;
            document.getElementById('pelTanggal').value = new Date().toISOString().slice(0, 10);
            PEL_GURU.pickLokasi(STATE.locFilter === 'Grup WA' ? 'Grup WA' : 'Realitas');
            closeIlmunaModal('modalRiwayatSiswaGuru');
            openIlmunaModal('modalPelanggaranGuru');
        },
        openEdit: function (logId) {
            var r = STATE.logs.find(function (x) { return parseInt(x.id, 10) === parseInt(logId, 10); });
            if (!r || !STATE.activeKmp) return;
            document.getElementById('formPelanggaranGuru').reset();
            document.getElementById('pelModalTitle').textContent = 'Edit Catatan Pelanggaran';
            document.getElementById('pelId').value = String(r.id);
            document.getElementById('pelKmpId').value = String(r.id_kelas_mata_pelajaran || STATE.activeKmp.id_kmp);
            document.getElementById('pelSiswaId').value = String(r.id_siswa);
            document.getElementById('pelBannerNama').textContent = r.nama_siswa + (r.nis ? ' (NIS: ' + r.nis + ')' : '');
            document.getElementById('pelBannerMeta').textContent = STATE.activeKmp.nama_kelas + ' — ' + STATE.activeKmp.nama_mata_pelajaran;
            document.getElementById('pelTanggal').value = r.tanggal_kejadian || '';
            document.getElementById('pelWaktu').value = r.waktu_kejadian ? String(r.waktu_kejadian).slice(0, 5) : '';
            document.getElementById('pelKategori').value = r.kategori_pelanggaran || '';
            document.getElementById('pelKronologi').value = r.kronologi || '';
            document.getElementById('pelBukti').value = r.bukti_keterangan || '';
            document.getElementById('pelTindak').value = r.tindak_lanjut || '';
            PEL_GURU.pickLokasi(r.lokasi_kejadian || 'Realitas');
            closeIlmunaModal('modalRiwayatSiswaGuru');
            openIlmunaModal('modalPelanggaranGuru');
        },
        openHistory: function (siswaId) {
            var s = STATE.students.find(function (x) { return parseInt(x.id, 10) === parseInt(siswaId, 10); });
            if (!s) return;
            var list = STATE.logs.filter(function (x) { return parseInt(x.id_siswa, 10) === parseInt(siswaId, 10); });
            document.getElementById('histGuruTitle').textContent = s.nama_lengkap;
            document.getElementById('histGuruSub').textContent = (STATE.activeKmp ? STATE.activeKmp.nama_kelas + ' — ' + STATE.activeKmp.nama_mata_pelajaran : '') + ' · Total ' + s.total_kasus + ' Kasus (' + s.total_poin + ' Poin)';
            var addBtn = document.getElementById('histGuruAddBtn');
            addBtn.onclick = function () { PEL_GURU.openAddForStudent(s.id); };

            var body = document.getElementById('histGuruBody');
            if (!list.length) {
                body.innerHTML = '<p style="text-align:center;color:#64748B;padding:24px 0;">Belum ada riwayat pelanggaran.</p>';
            } else {
                body.innerHTML = list.map(function (r) {
                    var locBadge = r.lokasi_kejadian === 'Grup WA'
                        ? '<span class="pel-badge-wa">Grup WA</span>'
                        : '<span class="pel-badge-real">Realitas</span>';
                    var tCls = r.tingkat_pelanggaran === 'Berat' ? 'pel-badge-berat' : (r.tingkat_pelanggaran === 'Sedang' ? 'pel-badge-sedang' : 'pel-badge-ringan');
                    return '<div class="pel-hist-item">'
                        + '<div class="pel-hist-head">'
                            + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
                                + locBadge
                                + scoreBadgeGuruHtml(r)
                                + statusBadgeHtml(r.status_penanganan)
                                + '<span style="font-size:12px;color:#64748B;">' + esc(r.tanggal_kejadian) + (r.waktu_kejadian ? ' · ' + esc(String(r.waktu_kejadian).slice(0, 5)) + ' WIB' : '') + '</span>'
                            + '</div>'
                            + '<div class="pel-act-cell">'
                                + nextStepBtnHtml(r, s.id)
                                + (r.status_penanganan === 'Baru'
                                    ? '<button type="button" class="ilm-act-btn ilm-act-btn--edit" title="Edit" onclick="PEL_GURU.openEdit(' + r.id + ')"><iconify-icon icon="solar:pen-bold" width="14"></iconify-icon></button>'
                                      + '<button type="button" class="ilm-act-btn ilm-act-btn--delete" title="Hapus" onclick="PEL_GURU.remove(' + r.id + ')"><iconify-icon icon="solar:trash-bin-trash-bold" width="14"></iconify-icon></button>'
                                    : '')
                            + '</div>'
                        + '</div>'
                        + '<div style="font-weight:700;color:#0F172A;font-size:13.5px;">' + esc(r.kategori_pelanggaran) + '</div>'
                        + '<div style="font-size:12.5px;color:#475569;margin-top:4px;">' + esc(r.kronologi) + '</div>'
                        + (r.tindak_lanjut ? '<div style="font-size:12px;color:#0D7A87;margin-top:4px;"><strong>Tindak Lanjut:</strong> ' + esc(r.tindak_lanjut) + '</div>' : '')
                        + '</div>';
                }).join('');
            }
            openIlmunaModal('modalRiwayatSiswaGuru');
        },
        save: async function (e) {
            e.preventDefault();
            var fd = new FormData();
            fd.append('api_action', 'save');
            fd.append('id', document.getElementById('pelId').value);
            fd.append('id_kelas_mata_pelajaran', document.getElementById('pelKmpId').value);
            fd.append('id_siswa', document.getElementById('pelSiswaId').value);
            fd.append('lokasi_kejadian', document.getElementById('pelLokasi').value);
            fd.append('tanggal_kejadian', document.getElementById('pelTanggal').value);
            fd.append('waktu_kejadian', document.getElementById('pelWaktu').value);
            fd.append('kategori_pelanggaran', document.getElementById('pelKategori').value.trim());
            fd.append('kronologi', document.getElementById('pelKronologi').value.trim());
            fd.append('bukti_keterangan', document.getElementById('pelBukti').value.trim());
            fd.append('tindak_lanjut', document.getElementById('pelTindak').value.trim());

            try {
                var res = await fetchJsonSafe(API_URL, { method: 'POST', body: fd });
                if (!res) return;
                if (res.success) {
                    closeIlmunaModal('modalPelanggaranGuru');
                    Ilmuna.success('Tersimpan', res.message);
                    PEL_GURU.loadRoster();
                } else {
                    Ilmuna.error('Gagal', (res && res.message) || 'Gagal menyimpan catatan.');
                }
            } catch (err) {
                Ilmuna.error('Kesalahan', (err && err.message) ? err.message : 'Gagal menghubungi server.');
            }
        },
        advanceStatus: async function (id, nextStatus, reopenSiswaId) {
            var label = nextStatus === 'Pembinaan' ? 'Dalam Pembinaan' : 'Selesai';
            var ok = await Ilmuna.confirm(
                'info',
                'Tindak Lanjut: ' + label + '?',
                'Ubah status penanganan pelanggaran ini menjadi "' + label + '"?',
                'Ya, ' + label,
                'Batal'
            );
            if (!ok) return;
            var fd = new FormData();
            fd.append('api_action', 'update_status');
            fd.append('id', id);
            fd.append('next_status', nextStatus);
            try {
                var r = await fetch(API_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
                var res = await r.json();
                if (res && res.success) {
                    Ilmuna.success('Status Diperbarui', res.message);
                    await PEL_GURU.loadRoster();
                    if (reopenSiswaId) {
                        PEL_GURU.openHistory(reopenSiswaId);
                    }
                } else {
                    Ilmuna.error('Gagal', (res && res.message) || 'Gagal memperbarui status.');
                }
            } catch (err) {
                Ilmuna.error('Kesalahan', 'Gagal menghubungi server.');
            }
        },
        remove: async function (id) {
            var ok = await Ilmuna.confirm('error', 'Hapus Catatan Pelanggaran?', 'Catatan pelanggaran ini akan dihapus permanen dari database.', 'Ya, Hapus', 'Batal');
            if (!ok) return;
            var fd = new FormData();
            fd.append('api_action', 'delete');
            fd.append('id', id);
            try {
                var res = await fetchJsonSafe(API_URL, { method: 'POST', body: fd });
                if (!res) return;
                if (res.success) {
                    closeIlmunaModal('modalRiwayatSiswaGuru');
                    Ilmuna.success('Terhapus', res.message);
                    PEL_GURU.loadRoster();
                } else {
                    Ilmuna.error('Gagal', (res && res.message) || 'Gagal menghapus catatan.');
                }
            } catch (err) {
                Ilmuna.error('Kesalahan', (err && err.message) ? err.message : 'Gagal menghubungi server.');
            }
        },
        exportExcel: async function (btnEl) {
            var params = new URLSearchParams();
            params.set('action', 'export_excel');
            var kmp = document.getElementById('filterMapelGuru') ? document.getElementById('filterMapelGuru').value : '0';
            var q = (document.getElementById('pelSearchInput').value || '').trim();
            if (kmp && kmp !== '0') params.set('id_kmp', kmp);
            if (STATE.locFilter === 'Grup WA' || STATE.locFilter === 'Realitas') params.set('lokasi', STATE.locFilter);
            if (q) params.set('search', q);

            var origHtml = btnEl ? btnEl.innerHTML : '';
            if (btnEl) {
                btnEl.disabled = true;
                btnEl.innerHTML = '<iconify-icon icon="solar:refresh-bold" width="16" style="animation:spin 1s linear infinite;"></iconify-icon><span>Mengekspor...</span>';
            }
            try {
                var resp = await fetch(EXCEL_URL + '?' + params.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!resp.ok) {
                    var errText = 'Gagal mengekspor berkas Excel.';
                    try {
                        var j = await resp.json();
                        if (j && j.data && j.data.message) errText = j.data.message;
                    } catch (_) {}
                    throw new Error(errText);
                }
                var ctype = (resp.headers.get('Content-Type') || '').toLowerCase();
                if (ctype.indexOf('spreadsheetml') === -1 && ctype.indexOf('octet-stream') === -1) {
                    throw new Error('Respons server bukan berkas Excel (.xlsx). Silakan refresh halaman lalu coba lagi.');
                }
                var blob = await resp.blob();
                var disp = resp.headers.get('Content-Disposition') || '';
                var match = disp.match(/filename="?([^"]+)"?/i);
                var fname = (match && match[1]) ? match[1] : 'catatan-pelanggaran-guru-ilmuna.xlsx';
                var dlUrl = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = dlUrl;
                a.download = fname;
                document.body.appendChild(a);
                a.click();
                setTimeout(function () {
                    URL.revokeObjectURL(dlUrl);
                    a.remove();
                }, 1500);
                if (window.Ilmuna && typeof Ilmuna.toast === 'function') {
                    Ilmuna.toast('success', 'Berhasil mengekspor ' + fname);
                } else {
                    Ilmuna.success('Berhasil', 'Berkas ' + fname + ' berhasil diunduh.');
                }
            } catch (err) {
                Ilmuna.error('Ekspor Gagal', err && err.message ? err.message : 'Gagal mengekspor Excel.');
            } finally {
                if (btnEl) {
                    btnEl.disabled = false;
                    btnEl.innerHTML = origHtml;
                }
            }
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('#pelGuruTabs .curriculum-tab-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                STATE.view = btn.getAttribute('data-view');
                document.querySelectorAll('#pelGuruTabs .curriculum-tab-btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                PEL_GURU.render();
            });
        });
        document.querySelectorAll('#pelLocPills .ilmuna-pill-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                STATE.locFilter = btn.getAttribute('data-loc');
                document.querySelectorAll('#pelLocPills .ilmuna-pill-btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                PEL_GURU.render();
            });
        });
        document.getElementById('pelSearchInput').addEventListener('input', PEL_GURU.render);
        document.getElementById('pelKategori').addEventListener('input', function () {
            renderPresets(document.getElementById('pelLokasi').value || 'Realitas');
        });
    });
})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
