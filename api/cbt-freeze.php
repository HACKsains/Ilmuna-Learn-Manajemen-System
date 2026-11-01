<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — CBT EXAM BROWSER CONTROLLER & FREEZE ENGINE (API)
 * ==============================================================================
 * Mendukung Ujian Diagnostik, Formatif, dan Sumatif:
 * 1. Menerima sinyal Freeze saat siswa keluar aplikasi (Home, Recent Apps, Split Screen).
 * 2. Mengubah status sesi menjadi 'Terkunci / Freeze' & 'is_locked_admin = 1'.
 * 3. Siswa otomatis dilogout dan TIDAK BISA login sendiri sebelum dibuka manual oleh Admin/Proktor.
 * 4. Jawaban siswa dan paket butir soal TETAP UTUH di database (tidak di-reset/dibuat baru).
 * 5. Menyediakan API pembukaan kunci manual (Unfreeze) oleh Admin di dashboard.
 * ==============================================================================
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Koneksi database gagal: ' . $e->getMessage()
    ]);
    exit;
}

$action = $_REQUEST['action'] ?? 'freeze';

// ------------------------------------------------------------------------------
// 1. BEKUKAN SESI UJIAN (FREEZE) SAAT SISWA KELUAR DARI EXAM BROWSER
// ------------------------------------------------------------------------------
if ($action === 'freeze') {
    $rawTipe     = $_REQUEST['tipe'] ?? $_REQUEST['tipe_asesmen'] ?? 'sumatif';
    $tipeAsesmen = in_array($rawTipe, ['diagnostik', 'formatif', 'sumatif', 'remedial'], true) ? $rawTipe : 'sumatif';
    $idSiswa     = (int)($_REQUEST['id_siswa'] ?? 0);
    $idAsesmen   = (int)($_REQUEST['id_asesmen'] ?? 0);
    $sisaDetik   = isset($_REQUEST['sisa_waktu_detik']) ? max(0, (int)$_REQUEST['sisa_waktu_detik']) : null;
    $alasan      = trim($_REQUEST['alasan'] ?? 'Keluar Aplikasi / Home (Peralihan Layar)');
    $deviceModel = trim($_REQUEST['device_model'] ?? 'Android Device');
    $ipAddress   = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent   = $_SERVER['HTTP_USER_AGENT'] ?? null;

    if (!$idSiswa || !$idAsesmen) {
        echo json_encode(['success' => false, 'message' => 'ID Siswa dan ID Asesmen wajib disertakan.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1.1 Update / Insert ke Tabel Master Sesi CBT: cbt_sesi_ujian
        $stmtCek = $pdo->prepare("SELECT id, status_sesi, sisa_waktu_detik, total_pelanggaran FROM cbt_sesi_ujian WHERE tipe_asesmen = ? AND id_asesmen = ? AND id_siswa = ? LIMIT 1");
        $stmtCek->execute([$tipeAsesmen, $idAsesmen, $idSiswa]);
        $sesi = $stmtCek->fetch(PDO::FETCH_ASSOC);

        $idSesi = null;
        $pelanggaranKe = 1;

        if ($sesi) {
            $idSesi = $sesi['id'];
            $pelanggaranKe = ((int)$sesi['total_pelanggaran']) + 1;

            // Jika sudah selesai secara sah, jangan bekukan lagi
            if ($sesi['status_sesi'] === 'Selesai') {
                $pdo->rollBack();
                echo json_encode(['success' => true, 'message' => 'Ujian telah selesai.', 'status' => 'Selesai']);
                exit;
            }

            $updSql = "UPDATE cbt_sesi_ujian SET 
                        status_sesi = 'Terkunci / Freeze',
                        is_frozen = 1,
                        is_locked_admin = 1,
                        waktu_freeze = NOW(),
                        alasan_freeze = ?,
                        total_pelanggaran = total_pelanggaran + 1,
                        sisa_waktu_detik = COALESCE(?, sisa_waktu_detik),
                        device_model = COALESCE(?, device_model),
                        ip_address = ?,
                        user_agent = ?
                       WHERE id = ?";
            $updStmt = $pdo->prepare($updSql);
            $updStmt->execute([$alasan, $sisaDetik, $deviceModel, $ipAddress, $userAgent, $idSesi]);
        } else {
            $idKmp = (int)($_REQUEST['id_kelas_mata_pelajaran'] ?? 0);
            if (!$idKmp) {
                if ($tipeAsesmen === 'sumatif') {
                    $stmtKmp = $pdo->prepare("SELECT id_kelas_mata_pelajaran FROM akses_asesmen_sumatif WHERE id_asesmen_sumatif = ? AND id_siswa = ? LIMIT 1");
                    $stmtKmp->execute([$idAsesmen, $idSiswa]);
                    $idKmp = (int)$stmtKmp->fetchColumn();
                } elseif ($tipeAsesmen === 'formatif') {
                    $stmtKmp = $pdo->prepare("SELECT id_kelas_mata_pelajaran FROM akses_asesmen_formatif WHERE id_asesmen_formatif = ? AND id_siswa = ? LIMIT 1");
                    $stmtKmp->execute([$idAsesmen, $idSiswa]);
                    $idKmp = (int)$stmtKmp->fetchColumn();
                } elseif ($tipeAsesmen === 'diagnostik') {
                    $stmtKmp = $pdo->prepare("SELECT id_kelas_mata_pelajaran FROM akses_asesmen_diagnostik WHERE id_asesmen_diagnostik = ? AND id_siswa = ? LIMIT 1");
                    $stmtKmp->execute([$idAsesmen, $idSiswa]);
                    $idKmp = (int)$stmtKmp->fetchColumn();
                } elseif ($tipeAsesmen === 'remedial') {
                    $stmtKmp = $pdo->prepare("SELECT id_kelas_mata_pelajaran FROM akses_pengayaan_remedial WHERE id_pengayaan_remedial = ? AND id_siswa = ? LIMIT 1");
                    $stmtKmp->execute([$idAsesmen, $idSiswa]);
                    $idKmp = (int)$stmtKmp->fetchColumn();
                }
                if (!$idKmp) {
                    $idKelasSiswa = (int)($pdo->query("SELECT id_kelas FROM siswa WHERE id = $idSiswa")->fetchColumn() ?: 1);
                    $idKmp = (int)($pdo->query("SELECT id FROM kelas_mata_pelajaran WHERE id_kelas = $idKelasSiswa LIMIT 1")->fetchColumn() ?: 1);
                }
            }

            $insSql = "INSERT INTO cbt_sesi_ujian 
                        (tipe_asesmen, id_asesmen, id_siswa, id_kelas_mata_pelajaran, status_sesi, sisa_waktu_detik, is_frozen, waktu_freeze, alasan_freeze, total_pelanggaran, is_locked_admin, device_model, ip_address, user_agent)
                       VALUES (?, ?, ?, ?, 'Terkunci / Freeze', ?, 1, NOW(), ?, 1, 1, ?, ?, ?)";
            $insStmt = $pdo->prepare($insSql);
            $insStmt->execute([$tipeAsesmen, $idAsesmen, $idSiswa, $idKmp, (int)($sisaDetik ?? 0), $alasan, $deviceModel, $ipAddress, $userAgent]);
            $idSesi = (int)$pdo->lastInsertId();
        }

        // 1.2 Catat ke Tabel Audit Pelanggaran: cbt_log_pelanggaran_exambro
        $jenisPelanggaran = 'Keluar Aplikasi / Home';
        if (stripos($alasan, 'Recent') !== false) $jenisPelanggaran = 'Recent Apps / App Switcher';
        elseif (stripos($alasan, 'Split') !== false) $jenisPelanggaran = 'Split Screen / Layar Ganda';
        elseif (stripos($alasan, 'Screenshot') !== false) $jenisPelanggaran = 'Tangkapan Layar / Screenshot';
        elseif (stripos($alasan, 'Copy') !== false) $jenisPelanggaran = 'Salin / Tempel Teks (Clipboard)';

        $logStmt = $pdo->prepare("INSERT INTO cbt_log_pelanggaran_exambro 
            (id_sesi_ujian, tipe_asesmen, id_asesmen, id_siswa, jenis_pelanggaran, keterangan, sisa_waktu_detik, pelanggaran_ke, status_tindakan, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Freeze & Logout Otomatis', ?, ?)");
        $logStmt->execute([$idSesi, $tipeAsesmen, $idAsesmen, $idSiswa, $jenisPelanggaran, $alasan, $sisaDetik, $pelanggaranKe, $ipAddress, $userAgent]);

        // 1.3 Update tabel akses master (sumatif, formatif, diagnostik, atau remedial)
        $tabelAkses = ($tipeAsesmen === 'sumatif') ? 'akses_asesmen_sumatif' : (($tipeAsesmen === 'diagnostik') ? 'akses_asesmen_diagnostik' : (($tipeAsesmen === 'remedial') ? 'akses_pengayaan_remedial' : 'akses_asesmen_formatif'));
        $fkCol      = ($tipeAsesmen === 'sumatif') ? 'id_asesmen_sumatif' : (($tipeAsesmen === 'diagnostik') ? 'id_asesmen_diagnostik' : (($tipeAsesmen === 'remedial') ? 'id_pengayaan_remedial' : 'id_asesmen_formatif'));
        $stmtAkses = $pdo->prepare("UPDATE `$tabelAkses` SET status_akses = 'Terkunci', sisa_waktu_detik = COALESCE(?, sisa_waktu_detik) WHERE id_siswa = ? AND `$fkCol` = ?");
        $stmtAkses->execute([$sisaDetik, $idSiswa, $idAsesmen]);

        recordCbtEvent();
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Sesi ujian berhasil dibekukan (Freeze). Status diubah menjadi Terkunci dan butuh verifikasi admin.',
            'data' => [
                'id_sesi'          => $idSesi,
                'status_sesi'      => 'Terkunci / Freeze',
                'is_locked_admin'  => true,
                'sisa_waktu_detik' => $sisaDetik,
                'pelanggaran_ke'   => $pelanggaranKe
            ]
        ]);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Gagal membekukan sesi: ' . $e->getMessage()]);
        exit;
    }
}

// ------------------------------------------------------------------------------
// 2. BUKA KUNCI MANUAL OLEH ADMIN / PROKTOR (UNFREEZE & RESUME)
// ------------------------------------------------------------------------------
if ($action === 'unfreeze') {
    $idSesi      = (int)($_POST['id_sesi'] ?? 0);
    $idSiswa     = (int)($_POST['id_siswa'] ?? 0);
    $idAsesmen   = (int)($_POST['id_asesmen'] ?? 0);
    $rawTipe     = $_POST['tipe'] ?? $_POST['tipe_asesmen'] ?? 'sumatif';
    $tipeAsesmen = in_array($rawTipe, ['diagnostik', 'formatif', 'sumatif', 'remedial'], true) ? $rawTipe : 'sumatif';
    $idAdmin     = (int)($_POST['id_admin'] ?? 1); // ID User Admin
    $catatan     = trim($_POST['catatan'] ?? 'Dibuka kembali oleh pengawas/proktor ujian');

    try {
        $pdo->beginTransaction();

        if ($idSesi > 0 && (!$idSiswa || !$idAsesmen)) {
            $stmtInfo = $pdo->prepare("SELECT tipe_asesmen, id_asesmen, id_siswa FROM cbt_sesi_ujian WHERE id = ? LIMIT 1");
            $stmtInfo->execute([$idSesi]);
            $sRow = $stmtInfo->fetch(PDO::FETCH_ASSOC);
            if ($sRow) {
                $tipeAsesmen = $sRow['tipe_asesmen'];
                $idAsesmen   = (int)$sRow['id_asesmen'];
                $idSiswa     = (int)$sRow['id_siswa'];
            }
        }

        $where = $idSesi > 0 ? "id = $idSesi" : "tipe_asesmen = '$tipeAsesmen' AND id_asesmen = $idAsesmen AND id_siswa = $idSiswa";
        
        $upd = $pdo->prepare("UPDATE cbt_sesi_ujian SET 
                                sisa_waktu_detik = CASE
                                    WHEN sisa_waktu_detik > 0 AND COALESCE(updated_at, waktu_freeze, waktu_mulai) IS NOT NULL THEN
                                        GREATEST(0, CAST(sisa_waktu_detik AS SIGNED) - TIMESTAMPDIFF(SECOND, COALESCE(updated_at, waktu_freeze, waktu_mulai), NOW()))
                                    ELSE sisa_waktu_detik
                                END,
                                status_sesi = 'Sedang Mengerjakan',
                                is_frozen = 0,
                                is_locked_admin = 0,
                                dibuka_oleh = ?,
                                waktu_buka_kunci = NOW(),
                                updated_at = NOW(),
                                catatan_proktor = ?
                              WHERE $where");
        $upd->execute([$idAdmin, $catatan]);

        // Update juga tabel akses master beserta sisa_waktu_detik terbaru
        if ($idSiswa > 0 && $idAsesmen > 0) {
            $tabelAkses = ($tipeAsesmen === 'sumatif') ? 'akses_asesmen_sumatif' : (($tipeAsesmen === 'diagnostik') ? 'akses_asesmen_diagnostik' : (($tipeAsesmen === 'remedial') ? 'akses_pengayaan_remedial' : 'akses_asesmen_formatif'));
            $fkCol      = ($tipeAsesmen === 'sumatif') ? 'id_asesmen_sumatif' : (($tipeAsesmen === 'diagnostik') ? 'id_asesmen_diagnostik' : (($tipeAsesmen === 'remedial') ? 'id_pengayaan_remedial' : 'id_asesmen_formatif'));
            $stmtNewSec = $pdo->prepare("SELECT sisa_waktu_detik FROM cbt_sesi_ujian WHERE $where LIMIT 1");
            $stmtNewSec->execute();
            $newSec = $stmtNewSec->fetchColumn();
            if ($newSec !== false) {
                $updLegacy = $pdo->prepare("UPDATE `$tabelAkses` SET status_akses = 'Sedang Mengerjakan', sisa_waktu_detik = ?, updated_at = NOW() WHERE id_siswa = ? AND `$fkCol` = ?");
                $updLegacy->execute([(int)$newSec, $idSiswa, $idAsesmen]);
            } else {
                $updLegacy = $pdo->prepare("UPDATE `$tabelAkses` SET status_akses = 'Sedang Mengerjakan', updated_at = NOW() WHERE id_siswa = ? AND `$fkCol` = ?");
                $updLegacy->execute([$idSiswa, $idAsesmen]);
            }
        }

        recordCbtEvent();
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Kunci ujian berhasil dibuka oleh admin. Siswa sekarang dapat login kembali dan melanjutkan pengerjaan.'
        ]);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Gagal membuka kunci: ' . $e->getMessage()]);
        exit;
    }
}

// ------------------------------------------------------------------------------
// 3. AUTOSAVE JAWABAN SISWA KE TABEL CBT_JAWABAN_SISWA
// ------------------------------------------------------------------------------
if ($action === 'save_answer') {
    $rawTipe       = $_POST['tipe'] ?? $_POST['tipe_asesmen'] ?? 'sumatif';
    $tipeAsesmen   = in_array($rawTipe, ['diagnostik', 'formatif', 'sumatif', 'remedial'], true) ? $rawTipe : 'sumatif';
    $idAsesmen     = (int)($_POST['id_asesmen'] ?? 0);
    $idSoal        = (int)($_POST['id_soal'] ?? 0);
    $idSiswa       = (int)($_POST['id_siswa'] ?? 0);
    $nomorTampil   = (int)($_POST['nomor_urut'] ?? 1);
    $tipeSoal      = $_POST['tipe_soal'] ?? 'Pilihan Ganda';
    $idOpsi        = !empty($_POST['id_opsi']) ? (int)$_POST['id_opsi'] : null;
    $kodeOpsi      = !empty($_POST['kode_opsi']) ? trim($_POST['kode_opsi']) : null;
    $jawabanTeks   = $_POST['jawaban_teks'] ?? null;
    $jawabanJson   = !empty($_POST['jawaban_kompleks']) ? $_POST['jawaban_kompleks'] : null;
    $isRagu        = !empty($_POST['is_ragu']) ? 1 : 0;

    if (!$idAsesmen || !$idSoal || !$idSiswa) {
        echo json_encode(['success' => false, 'message' => 'Data pengerjaan butir soal tidak lengkap.']);
        exit;
    }

    try {
        // Ambil ID sesi jika ada
        $idSesi = $pdo->query("SELECT id FROM cbt_sesi_ujian WHERE tipe_asesmen = '$tipeAsesmen' AND id_asesmen = $idAsesmen AND id_siswa = $idSiswa LIMIT 1")->fetchColumn() ?: null;

        $sql = "INSERT INTO cbt_jawaban_siswa 
                (id_sesi_ujian, tipe_asesmen, id_asesmen, id_soal, id_siswa, nomor_urut_tampil, tipe_soal, id_opsi_dipilih, kode_opsi_dipilih, jawaban_teks, jawaban_kompleks, is_ragu, waktu_simpan)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    id_opsi_dipilih = VALUES(id_opsi_dipilih),
                    kode_opsi_dipilih = VALUES(kode_opsi_dipilih),
                    jawaban_teks = VALUES(jawaban_teks),
                    jawaban_kompleks = VALUES(jawaban_kompleks),
                    is_ragu = VALUES(is_ragu),
                    waktu_simpan = NOW()";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $idSesi, $tipeAsesmen, $idAsesmen, $idSoal, $idSiswa, $nomorTampil, $tipeSoal,
            $idOpsi, $kodeOpsi, $jawabanTeks, $jawabanJson, $isRagu
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Jawaban berhasil disimpan secara otomatis.'
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan jawaban: ' . $e->getMessage()]);
        exit;
    }
}

// ------------------------------------------------------------------------------
// 4. CEK STATUS KUNCI SISWA (SEBELUM LOGIN KEMBALI)
// ------------------------------------------------------------------------------
if ($action === 'check_access') {
    $idSiswa     = (int)($_REQUEST['id_siswa'] ?? 0);
    $idAsesmen   = (int)($_REQUEST['id_asesmen'] ?? 0);
    $rawTipe     = $_REQUEST['tipe'] ?? $_REQUEST['tipe_asesmen'] ?? 'sumatif';
    $tipeAsesmen = in_array($rawTipe, ['diagnostik', 'formatif', 'sumatif', 'remedial'], true) ? $rawTipe : 'sumatif';

    $stmt = $pdo->prepare("SELECT id, status_sesi, is_frozen, is_locked_admin, sisa_waktu_detik, total_pelanggaran, alasan_freeze 
                           FROM cbt_sesi_ujian 
                           WHERE tipe_asesmen = ? AND id_asesmen = ? AND id_siswa = ? LIMIT 1");
    $stmt->execute([$tipeAsesmen, $idAsesmen, $idSiswa]);
    $sesi = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sesi) {
        echo json_encode(['success' => true, 'data' => ['can_enter' => true, 'is_locked' => false]]);
        exit;
    }

    $isLocked = ($sesi['is_locked_admin'] == 1 || $sesi['status_sesi'] === 'Terkunci / Freeze');

    echo json_encode([
        'success' => true,
        'data' => [
            'can_enter'         => !$isLocked,
            'is_locked'         => $isLocked,
            'status_sesi'       => $sesi['status_sesi'],
            'sisa_waktu_detik'  => (int)$sesi['sisa_waktu_detik'],
            'total_pelanggaran' => (int)$sesi['total_pelanggaran'],
            'alasan_freeze'     => $sesi['alasan_freeze'],
            'message'           => $isLocked 
                ? 'Sesi ujian Anda terkunci karena terdeteksi meninggalkan aplikasi. Silakan hubungi Pengawas / Admin untuk membuka kunci ujian Anda.' 
                : 'Sesi diizinkan.'
        ]
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action tidak dikenali.']);
