<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PORTAL PENUGASAN & PROYEK BELAJAR SISWA
 * ==============================================================================
 * Fitur & Spesifikasi:
 * 1. Scope Terbatas & Ketat:
 *    - Hanya menampilkan tugas & mata pelajaran yang berasal dari KELAS SISWA.
 *    - Validasi keamanan di setiap endpoint (anti-akses silang kelas).
 * 2. Tugas Mandiri & Kolaborasi Kelompok:
 *    - Tugas Mandiri: Pengumpulan individual.
 *    - Tugas Kelompok: Info kelompok otomatis (Nama Kelompok, Ketua, Anggota).
 *      Pengumpulan berkas bersama atas nama kelompok ke Google Drive.
 * 3. Sinkronisasi Google Drive via Google Apps Script (GAS):
 *    - Menggunakan konstanta GAS_TUGAS_URL dari config.php.
 *    - Otomatis tersimpan di hirarki folder:
 *      /tugas/{Kelas}/{Mapel}/{Judul Tugas}/{Nama Siswa atau Nama Kelompok}/
 * 4. Rincian Penilaian Interaktif:
 *    - Menampilkan Nilai Akhir Siswa & Predikat.
 *    - Jika tugas kelompok: menampilkan rincian Nilai Kelompok, Nilai Bagi Kelompok,
 *      Nilai Individu Siswa, dan rumus: (Nilai Individu + Nilai Bagi) / 2 = Nilai Akhir.
 *    - Menampilkan Umpan Balik konstruktif Guru & Ketercapaian Aspek ITP.
 * 5. Strict View-Only (Anti-Download Berkas):
 *    - Lampiran guru & pengumpulan siswa dipratinjau via PDF.js Canvas / GDrive Preview.
 * 6. Tema Lumina UNUGIRI (Navy, Emerald, Teal, Gold, Rose) tanpa alert default browser.
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

// Anti-cache header
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

// Hak Akses: Siswa (juga izinkan preview oleh Admin & Guru)
requireRole(['siswa', 'admin', 'guru']);
$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
$currentUser = getCurrentUser();

$activeRole = hasRole('siswa') ? 'siswa' : (hasRole('guru') ? 'guru' : 'admin');
$GLOBALS['activeRole'] = $activeRole;

// ==============================================================================
// 1. RESOLUSI DATA SISWA & KELAS TERDAFTAR (STRICT CLASS ENROLMENT)
// ==============================================================================
$currentSiswa = null;
$overrideSiswaId = (int)($_GET['siswa_id'] ?? 0);

if (($activeRole === 'admin' || $activeRole === 'guru') && $overrideSiswaId > 0) {
    $stmtS = $pdo->prepare("
        SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran 
        FROM siswa s 
        LEFT JOIN kelas k ON s.id_kelas = k.id 
        WHERE s.id = ? LIMIT 1
    ");
    $stmtS->execute([$overrideSiswaId]);
    $currentSiswa = $stmtS->fetch(PDO::FETCH_ASSOC);
}

if (!$currentSiswa) {
    $stmtS = $pdo->prepare("
        SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran 
        FROM siswa s 
        LEFT JOIN kelas k ON s.id_kelas = k.id 
        WHERE s.nis = ? OR s.nisn = ? OR s.gmail = ? OR s.gmail = ?
        LIMIT 1
    ");
    $stmtS->execute([
        $currentUser['username'] ?? '',
        $currentUser['nisn_nip'] ?? '',
        $currentUser['email'] ?? '',
        $currentUser['email_google'] ?? ''
    ]);
    $currentSiswa = $stmtS->fetch(PDO::FETCH_ASSOC);
}

// Fallback jika login sebagai admin/demo
if (!$currentSiswa) {
    $currentSiswa = $pdo->query("
        SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran 
        FROM siswa s 
        LEFT JOIN kelas k ON s.id_kelas = k.id 
        ORDER BY s.id ASC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
}

$idSiswa    = (int)($currentSiswa['id'] ?? 0);
$idKelas    = (int)($currentSiswa['id_kelas'] ?? 0);
$namaKelas  = $currentSiswa['nama_kelas'] ?? 'Kelas Tidak Ditemukan';
$namaSiswa  = $currentSiswa['nama_lengkap'] ?? ($currentUser['full_name'] ?: 'Siswa Ilmuna');
$nisnSiswa  = $currentSiswa['nisn'] ?? ($currentSiswa['nis'] ?? '-');
$thnAjaran  = $currentSiswa['tahun_pelajaran'] ?? '2026/2027';

// ==============================================================================
// 2. HELPER FUNCTIONS (GAS URL, PREDIKAT, UPLOAD KE GAS TUGAS)
// ==============================================================================
function getGasTugasUrl($pdo) {
    if (defined('GAS_TUGAS_URL') && !empty(GAS_TUGAS_URL)) {
        return GAS_TUGAS_URL;
    }
    if (function_exists('getGasTugasConfig')) {
        $cfg = getGasTugasConfig();
        if (!empty($cfg['url'])) return $cfg['url'];
    }
    if ($pdo) {
        $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'gas_tugas_url' LIMIT 1");
        $stmt->execute();
        $dbVal = trim((string)$stmt->fetchColumn());
        if (!empty($dbVal)) return $dbVal;
    }
    return '';
}

function hitungPredikatNilai($nilai) {
    $nilai = (float)$nilai;
    if ($nilai >= 90) return 'A';
    if ($nilai >= 80) return 'B';
    if ($nilai >= 70) return 'C';
    if ($nilai >= 60) return 'D';
    return 'E';
}

function uploadFileToGasTugas($gasUrl, $localPath, $originalName, $mimeType, $kelas, $mapel, $judulTugas, $jenisPenugasan, $namaPengumpul) {
    if (empty($gasUrl) || !file_exists($localPath)) {
        return ['success' => false, 'message' => 'GAS Tugas belum dikonfigurasi atau file lokal tidak ditemukan.'];
    }

    if (empty($mimeType)) {
        $mimeType = mime_content_type($localPath) ?: 'application/octet-stream';
    }

    $fileData = base64_encode(file_get_contents($localPath));

    $payload = [
        'fileData'       => $fileData,
        'fileName'       => $originalName,
        'mimeType'       => $mimeType,
        'namaKelas'      => $kelas,
        'namaMapel'      => $mapel,
        'judulTugas'     => $judulTugas,
        'jenisPenugasan' => $jenisPenugasan,
        'namaPengumpul'  => $namaPengumpul,
        'rootFolder'     => 'tugas'
    ];

    $ch = curl_init($gasUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 65,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'message' => 'cURL Error: ' . $curlErr];
    }

    $json = json_decode($response, true);
    if ($json && isset($json['success']) && $json['success']) {
        return [
            'success'     => true,
            'file_id'     => $json['fileId'] ?? '',
            'view_url'    => $json['viewUrl'] ?? '',
            'preview_url' => $json['previewUrl'] ?? '',
            'folder_path' => $json['folderPath'] ?? ''
        ];
    }

    return [
        'success' => false,
        'message' => $json['message'] ?? 'Respon GAS tidak valid atau gagal menyimpan ke Google Drive.'
    ];
}

// ==============================================================================
// 3. STREAM PROXY STRICT VIEW-ONLY (ANTI-DOWNLOAD DOKUMEN TUGAS & PENGUMPULAN)
// ==============================================================================
if (isset($_GET['stream']) && in_array($_GET['stream'], ['tugas', 'submission'])) {
    while (ob_get_level()) { ob_end_clean(); }
    $type = $_GET['stream'];
    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(404);
        exit('Berkas tidak ditemukan.');
    }

    $filePath = '';
    $fileName = '';
    $gdriveId = '';
    $mimeType = 'application/pdf';

    if ($type === 'tugas') {
        // Validasi: Siswa hanya boleh stream tugas dari kelasnya sendiri
        $stmtT = $pdo->prepare("
            SELECT t.*, km.id_kelas 
            FROM tugas t
            JOIN kelas_mapel km ON t.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
            WHERE t.id = ? AND km.id_kelas = ?
            LIMIT 1
        ");
        $stmtT->execute([$id, $idKelas]);
        $row = $stmtT->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(403);
            exit('Akses ditolak: Berkas tugas bukan milik kelas Anda.');
        }
        $filePath = $row['file_lampiran_tugas'] ?? '';
        $fileName = basename($filePath);
        $gdriveId = $row['gdrive_file_id'] ?? '';
    } else {
        // Validasi: Siswa hanya boleh stream pengumpulan dirinya sendiri atau kelompoknya
        $stmtSub = $pdo->prepare("
            SELECT pt.*, t.id_kelas_mata_pelajaran, km.id_kelas
            FROM pengumpulan_tugas pt
            JOIN tugas t ON pt.id_tugas = t.id
            JOIN kelas_mapel km ON t.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
            WHERE pt.id = ? AND km.id_kelas = ?
            LIMIT 1
        ");
        $stmtSub->execute([$id, $idKelas]);
        $row = $stmtSub->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(403);
            exit('Akses ditolak: Data pengumpulan tidak ditemukan.');
        }

        // Cek kepemilikan siswa: individu vs anggota kelompok
        $isOwner = false;
        if ((int)$row['id_siswa'] === $idSiswa) {
            $isOwner = true;
        } elseif (!empty($row['id_tugas_kelompok'])) {
            $stmtC = $pdo->prepare("SELECT 1 FROM tugas_kelompok_anggota WHERE id_tugas_kelompok = ? AND id_siswa = ? LIMIT 1");
            $stmtC->execute([$row['id_tugas_kelompok'], $idSiswa]);
            if ($stmtC->fetchColumn()) {
                $isOwner = true;
            }
        }

        if (!$isOwner && $activeRole === 'siswa') {
            http_response_code(403);
            exit('Akses ditolak: Anda bukan pengunggah atau anggota dari kelompok tugas ini.');
        }

        $filePath = $row['file_tugas'] ?? '';
        $fileName = $row['file_nama_asli'] ?? basename($filePath);
        $gdriveId = $row['gdrive_file_id'] ?? '';
    }

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mimes = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'mp3'  => 'audio/mpeg',
        'txt'  => 'text/plain'
    ];
    if (isset($mimes[$ext])) {
        $mimeType = $mimes[$ext];
    }

    // 1. Coba Berkas Lokal
    $localPath = '';
    if (!empty($filePath)) {
        if (strpos($filePath, BASE_URL) === 0) {
            $localPath = BASE_PATH . str_replace(BASE_URL, '', $filePath);
        } else {
            $parsed = parse_url($filePath, PHP_URL_PATH);
            if ($parsed && (strpos($parsed, '/uploads/') !== false || strpos($parsed, 'uploads/') !== false)) {
                $parsedRel = substr($parsed, strpos($parsed, 'uploads/'));
                if (file_exists(BASE_PATH . '/' . $parsedRel)) {
                    $localPath = BASE_PATH . '/' . $parsedRel;
                }
            }
            if (!$localPath && file_exists(BASE_PATH . '/' . ltrim($filePath, '/'))) {
                $localPath = BASE_PATH . '/' . ltrim($filePath, '/');
            }
        }
    }

    if ($localPath && file_exists($localPath)) {
        header("Content-Type: {$mimeType}");
        header('Content-Disposition: inline; filename="' . addslashes(basename($fileName)) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($localPath));
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: private, max-age=3600');
        readfile($localPath);
        exit;
    }

    // 2. Coba Google Drive File ID Streaming
    if (!empty($gdriveId)) {
        $cookieFile = sys_get_temp_dir() . '/gdrive_siswa_' . md5($gdriveId) . '.txt';
        $driveUrl = "https://drive.google.com/uc?export=download&id={$gdriveId}&confirm=t";
        $ch = curl_init($driveUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        $bin = curl_exec($ch);
        $fetchedMime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        @unlink($cookieFile);

        $finalMime = $mimeType;
        if (!empty($fetchedMime) && strpos($fetchedMime, 'application/octet-stream') === false && strpos($fetchedMime, 'text/html') === false) {
            $finalMime = $fetchedMime;
        }

        if (!empty($bin) && strlen($bin) > 500 && stripos($bin, '<!DOCTYPE html>') === false) {
            header("Content-Type: {$finalMime}");
            header('Content-Disposition: inline; filename="' . addslashes(basename($fileName)) . '"');
            header('Content-Length: ' . strlen($bin));
            echo $bin;
            exit;
        }

        // Fallback redirect ke viewer terproteksi
        header("Location: https://drive.google.com/file/d/{$gdriveId}/preview");
        exit;
    }

    http_response_code(404);
    exit('Berkas tidak dapat dimuat.');
}

// ==============================================================================
// 4. AJAX ENDPOINTS (PENGUMPULAN TUGAS & DETAIL TUGAS SISWA)
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';

    // A. DETAIL TUGAS LENGKAP UNTUK MODAL SISWA
    if ($action === 'get_task_detail') {
        $idTugas = (int)($_POST['id_tugas'] ?? 0);

        if ($idTugas <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID tugas tidak valid.']);
            exit;
        }

        // Pastikan tugas berasal dari kelas siswa!
        $stmtT = $pdo->prepare("
            SELECT t.*, km.nama_kelas, km.nama_mata_pelajaran, km.semester, km.id_kelas,
                   COALESCE(g.nama_guru, km.nama_guru, 'Guru Pengampu PAI') AS nama_guru,
                   g.nip AS nip_guru, g.foto AS foto_guru
            FROM tugas t
            JOIN kelas_mapel km ON t.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
            LEFT JOIN guru g ON t.id_guru = g.id
            WHERE t.id = ? AND km.id_kelas = ?
            LIMIT 1
        ");
        $stmtT->execute([$idTugas, $idKelas]);
        $task = $stmtT->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            echo json_encode(['success' => false, 'message' => 'Tugas tidak ditemukan atau bukan untuk kelas Anda.']);
            exit;
        }

        // Info kelompok (jika tugas kelompok)
        $groupInfo = null;
        $isMeKetua = false;
        if ($task['jenis_penugasan'] === 'Kelompok') {
            $stmtG = $pdo->prepare("
                SELECT tk.*, s.nama_lengkap AS nama_ketua, s.nisn AS nisn_ketua
                FROM tugas_kelompok tk
                JOIN tugas_kelompok_anggota tka ON tk.id = tka.id_tugas_kelompok
                LEFT JOIN siswa s ON tk.id_ketua_kelompok = s.id
                WHERE tk.id_tugas = ? AND tka.id_siswa = ?
                LIMIT 1
            ");
            $stmtG->execute([$idTugas, $idSiswa]);
            $groupInfo = $stmtG->fetch(PDO::FETCH_ASSOC);

            if ($groupInfo) {
                $isMeKetua = ((int)($groupInfo['id_ketua_kelompok'] ?? 0) === $idSiswa);
                $groupInfo['is_me_ketua'] = $isMeKetua;

                // Ambil seluruh anggota kelompok ini
                $stmtAng = $pdo->prepare("
                    SELECT s.id, s.nama_lengkap, s.nisn, s.foto, (s.id = ?) AS is_ketua
                    FROM tugas_kelompok_anggota tka
                    JOIN siswa s ON tka.id_siswa = s.id
                    WHERE tka.id_tugas_kelompok = ?
                    ORDER BY is_ketua DESC, s.nama_lengkap ASC
                ");
                $stmtAng->execute([$groupInfo['id_ketua_kelompok'], $groupInfo['id']]);
                $groupInfo['anggota'] = $stmtAng->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        // Cek pengumpulan tugas yang sudah ada
        $submission = null;
        if ($task['jenis_penugasan'] === 'Kelompok' && $groupInfo) {
            $stmtSub = $pdo->prepare("
                SELECT pt.*, s.nama_lengkap AS nama_pengumpul
                FROM pengumpulan_tugas pt
                LEFT JOIN siswa s ON pt.id_siswa = s.id
                WHERE pt.id_tugas = ? AND pt.id_tugas_kelompok = ?
                ORDER BY pt.waktu_pengumpulan DESC LIMIT 1
            ");
            $stmtSub->execute([$idTugas, $groupInfo['id']]);
            $submission = $stmtSub->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmtSub = $pdo->prepare("
                SELECT pt.*, s.nama_lengkap AS nama_pengumpul
                FROM pengumpulan_tugas pt
                LEFT JOIN siswa s ON pt.id_siswa = s.id
                WHERE pt.id_tugas = ? AND pt.id_siswa = ?
                ORDER BY pt.waktu_pengumpulan DESC LIMIT 1
            ");
            $stmtSub->execute([$idTugas, $idSiswa]);
            $submission = $stmtSub->fetch(PDO::FETCH_ASSOC);
        }

        // Cek nilai tugas siswa
        $nilai = null;
        $stmtNilai = $pdo->prepare("
            SELECT nt.*, g.nama_guru
            FROM nilai_tugas nt
            LEFT JOIN guru g ON nt.id_guru = g.id
            WHERE nt.id_tugas = ? AND nt.id_siswa = ?
            LIMIT 1
        ");
        $stmtNilai->execute([$idTugas, $idSiswa]);
        $nilai = $stmtNilai->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'     => true,
            'task'        => $task,
            'group'       => $groupInfo,
            'is_me_ketua' => $isMeKetua,
            'submission'  => $submission,
            'nilai'       => $nilai
        ]);
        exit;
    }

    // B. PENGUMPULAN TUGAS (UNGGAH BERKAS KE GDRIVE VIA GAS & SIMPAN KE DB)
    if ($action === 'submit_tugas') {
        $idTugas      = (int)($_POST['id_tugas'] ?? 0);
        $linkTugas    = trim($_POST['link_tugas'] ?? '');
        $catatanSiswa = trim($_POST['catatan_siswa'] ?? '');

        if ($idTugas <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID tugas tidak valid.']);
            exit;
        }

        // Validasi ketat: Tugas harus berasal dari kelas siswa!
        $stmtT = $pdo->prepare("
            SELECT t.*, km.nama_kelas, km.nama_mata_pelajaran, km.id_kelas
            FROM tugas t
            JOIN kelas_mapel km ON t.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
            WHERE t.id = ? AND km.id_kelas = ?
            LIMIT 1
        ");
        $stmtT->execute([$idTugas, $idKelas]);
        $task = $stmtT->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak: Tugas ini bukan untuk kelas Anda.']);
            exit;
        }

        $isKelompok = ($task['jenis_penugasan'] === 'Kelompok');
        $idKelompok = null;
        $namaPengumpulDrive = $namaSiswa;

        if ($isKelompok) {
            // Cari data kelompok siswa ini
            $stmtG = $pdo->prepare("
                SELECT tk.*, s.nama_lengkap AS nama_ketua
                FROM tugas_kelompok tk
                JOIN tugas_kelompok_anggota tka ON tk.id = tka.id_tugas_kelompok
                LEFT JOIN siswa s ON tk.id_ketua_kelompok = s.id
                WHERE tk.id_tugas = ? AND tka.id_siswa = ?
                LIMIT 1
            ");
            $stmtG->execute([$idTugas, $idSiswa]);
            $group = $stmtG->fetch(PDO::FETCH_ASSOC);

            if (!$group) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Anda belum dimasukkan ke dalam kelompok tugas ini oleh Guru. Silakan hubungi guru pengampu.'
                ]);
                exit;
            }

            // ATURAN RESMI: HANYA KETUA KELOMPOK YANG DAPAT MENGUMPULKAN TUGAS KELOMPOK
            $idKetua = (int)($group['id_ketua_kelompok'] ?? 0);
            if ($idKetua !== $idSiswa) {
                $namaKetua = $group['nama_ketua'] ?: 'Ketua Kelompok Anda';
                echo json_encode([
                    'success' => false,
                    'message' => "Akses Ditolak: Hanya Ketua Kelompok ({$namaKetua}) yang memiliki hak untuk mengunggah tugas kelompok ini."
                ]);
                exit;
            }

            $idKelompok = (int)$group['id'];
            $namaPengumpulDrive = $group['nama_kelompok'] . ' - ' . $namaSiswa;
        }

        // Cek tenggat waktu (Keterlambatan)
        $now = date('Y-m-d H:i:s');
        $deadline = $task['tenggat_waktu'];
        $isLate = 0;
        $statusPengumpulan = 'Diserahkan';

        if (!empty($deadline) && $now > $deadline) {
            $isLate = 1;
            $statusPengumpulan = 'Terlambat';
        }

        // Cek apakah ada pengumpulan sebelumnya
        $existingSubId = 0;
        $existingRevisiKe = 1;
        if ($isKelompok) {
            $stmtCheck = $pdo->prepare("SELECT id, status_pengumpulan, revisi_ke FROM pengumpulan_tugas WHERE id_tugas = ? AND id_tugas_kelompok = ? LIMIT 1");
            $stmtCheck->execute([$idTugas, $idKelompok]);
            $exRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if ($exRow) {
                $existingSubId = (int)$exRow['id'];
                $existingRevisiKe = (int)($exRow['revisi_ke'] ?? 1);
                if ($exRow['status_pengumpulan'] === 'Menunggu Revisi') {
                    $statusPengumpulan = $isLate ? 'Terlambat' : 'Diserahkan';
                    $existingRevisiKe++;
                }
            }
        } else {
            $stmtCheck = $pdo->prepare("SELECT id, status_pengumpulan, revisi_ke FROM pengumpulan_tugas WHERE id_tugas = ? AND id_siswa = ? LIMIT 1");
            $stmtCheck->execute([$idTugas, $idSiswa]);
            $exRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if ($exRow) {
                $existingSubId = (int)$exRow['id'];
                $existingRevisiKe = (int)($exRow['revisi_ke'] ?? 1);
                if ($exRow['status_pengumpulan'] === 'Menunggu Revisi') {
                    $statusPengumpulan = $isLate ? 'Terlambat' : 'Diserahkan';
                    $existingRevisiKe++;
                }
            }
        }

        // Upload Berkas (jika ada file diunggah)
        $hasFile = isset($_FILES['file_tugas']) && $_FILES['file_tugas']['error'] === UPLOAD_ERR_OK;

        if (!$hasFile && empty($linkTugas) && $existingSubId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Harap lampirkan berkas tugas atau masukkan tautan dokumen.']);
            exit;
        }

        $localFilePath    = '';
        $originalName     = '';
        $fileSize         = 0;
        $fileSizeKb       = 0;
        $gdriveFileId     = '';
        $gdriveViewUrl    = '';
        $gdrivePreviewUrl = '';
        $gdriveFolderPath = '';

        if ($hasFile) {
            $file = $_FILES['file_tugas'];
            $originalName = $file['name'];
            $fileSize     = (int)$file['size'];
            $fileSizeKb   = (int)ceil($fileSize / 1024);
            $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            // Validasi Ukuran File: Dibatasi maksimal 1 MB (1,048,576 bytes)
            $maxBytes = 1 * 1024 * 1024;
            if ($fileSize > $maxBytes) {
                $actualMb = round($fileSize / (1024 * 1024), 2);
                echo json_encode([
                    'success' => false,
                    'message' => "Ukuran berkas ({$actualMb} MB) melebihi batas maksimal 1 MB. Harap pilih berkas dengan ukuran di bawah 1 MB atau lakukan kompresi terlebih dahulu."
                ]);
                exit;
            }

            // Simpan Berkas Langsung di Server Lokal (uploads/tugas/YYYY/MM/)
            $uploadDirRel = 'uploads/tugas/' . date('Y/m');
            $uploadDirAbs = BASE_PATH . '/' . $uploadDirRel;
            if (!file_exists($uploadDirAbs)) {
                @mkdir($uploadDirAbs, 0777, true);
            }

            $cleanName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
            $safeFileName = 'tugas_' . time() . '_' . $idSiswa . '_' . $cleanName . '.' . $ext;
            $destination = $uploadDirAbs . '/' . $safeFileName;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                echo json_encode(['success' => false, 'message' => 'Gagal menyimpan berkas ke server lokal.']);
                exit;
            }

            $localFilePath = $uploadDirRel . '/' . $safeFileName;

            // Berkas disimpan di server lokal saja (tidak dilempar ke Google Drive)
            $gdriveFileId     = '';
            $gdriveViewUrl    = '';
            $gdrivePreviewUrl = '';
            $gdriveFolderPath = '';
        }

        // Tentukan tipe pengumpulan
        $tipePengumpulan = 'File Upload';
        if ($hasFile && !empty($linkTugas)) {
            $tipePengumpulan = 'File dan Link';
        } elseif (!$hasFile && !empty($linkTugas)) {
            $tipePengumpulan = 'Link URL';
        }

        $idKelasMapel = (int)($task['id_kelas_mata_pelajaran'] ?? 0);

        // Simpan / Perbarui Record di Database
        if ($existingSubId > 0) {
            // Update submission
            $updateSql = "
                UPDATE pengumpulan_tugas SET
                    id_siswa            = :id_siswa,
                    tipe_pengumpulan    = :tipe_p,
                    link_tugas          = :link,
                    catatan_siswa       = :catatan,
                    status_pengumpulan  = :status_p,
                    is_terlambat        = :late,
                    revisi_ke           = :revisi_ke,
                    waktu_pengumpulan   = NOW()
            ";
            $params = [
                ':id_siswa'  => $idSiswa,
                ':tipe_p'    => $tipePengumpulan,
                ':link'      => $linkTugas,
                ':catatan'   => $catatanSiswa,
                ':status_p'  => $statusPengumpulan,
                ':late'      => $isLate,
                ':revisi_ke' => $existingRevisiKe,
                ':id'        => $existingSubId
            ];

            if ($hasFile) {
                $updateSql .= ",
                    file_tugas          = :file_tugas,
                    file_nama_asli      = :nama_asli,
                    file_ukuran_kb      = :ukuran_kb,
                    gdrive_file_id      = :g_id,
                    gdrive_view_url     = :g_view,
                    gdrive_preview_url  = :g_prev,
                    gdrive_folder_path  = :g_folder
                ";
                $params[':file_tugas'] = $localFilePath;
                $params[':nama_asli']  = $originalName;
                $params[':ukuran_kb']  = $fileSizeKb;
                $params[':g_id']       = $gdriveFileId;
                $params[':g_view']     = $gdriveViewUrl;
                $params[':g_prev']     = $gdrivePreviewUrl;
                $params[':g_folder']   = $gdriveFolderPath;
            }

            $updateSql .= " WHERE id = :id";
            $pdo->prepare($updateSql)->execute($params);

            echo json_encode([
                'success' => true,
                'message' => 'Alhamdulillah! Jawaban dan berkas tugas Anda berhasil diperbarui.'
            ]);
            exit;

        } else {
            // Insert baru
            $stmtIns = $pdo->prepare("
                INSERT INTO pengumpulan_tugas (
                    id_tugas, id_siswa, id_kelas_mata_pelajaran, id_tugas_kelompok,
                    tipe_pengumpulan, file_tugas, file_nama_asli, file_ukuran_kb,
                    gdrive_file_id, gdrive_view_url, gdrive_preview_url, gdrive_folder_path,
                    link_tugas, catatan_siswa, status_pengumpulan, is_terlambat,
                    revisi_ke, waktu_pengumpulan
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, NOW()
                )
            ");
            $stmtIns->execute([
                $idTugas, $idSiswa, $idKelasMapel, $idKelompok,
                $tipePengumpulan, $localFilePath, $originalName, $fileSizeKb,
                $gdriveFileId, $gdriveViewUrl, $gdrivePreviewUrl, $gdriveFolderPath,
                $linkTugas, $catatanSiswa, $statusPengumpulan, $isLate,
                $existingRevisiKe
            ]);

            $subBaruId = $pdo->lastInsertId();

            // Beri feedback notifikasi ke user
            $kelompokMsg = $isKelompok ? " untuk kelompok Anda" : "";
            echo json_encode([
                'success' => true,
                'message' => "Alhamdulillah! Tugas{$kelompokMsg} berhasil dikumpulkan dan disimpan di server.",
                'id_pengumpulan' => $subBaruId
            ]);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.']);
    exit;
}

// ==============================================================================
// 5. QUERY DATA MATA PELAJARAN KELAS SISWA (HANYA KELAS YANG DIENROL SISWA)
// ==============================================================================
$myMapelList = [];
if ($idKelas > 0) {
    $stmtM = $pdo->prepare("
        SELECT km.id_kelas_mata_pelajaran AS id, km.nama_mata_pelajaran, km.semester,
               COALESCE(g.nama_guru, km.nama_guru, 'Guru Pengampu') AS nama_guru
        FROM kelas_mapel km
        LEFT JOIN guru g ON km.id_guru = g.id
        WHERE km.id_kelas = ?
        ORDER BY km.nama_mata_pelajaran ASC
    ");
    $stmtM->execute([$idKelas]);
    $myMapelList = $stmtM->fetchAll(PDO::FETCH_ASSOC);
}

// ==============================================================================
// 6. FUNCTION FETCH TUGAS SISWA (REUSABLE UNTUK SSR & AJAX LAZY LOAD)
// ==============================================================================
function fetchStudentTasks($pdo, $idKelas, $idSiswa, $filterMapel = 0, $filterJenis = 'all', $filterStatus = 'all', $filterSearch = '') {
    $sqlTugas = "
        SELECT t.*, 
               km.nama_kelas, km.nama_mata_pelajaran, km.semester, km.id_kelas,
               COALESCE(g.nama_guru, km.nama_guru, 'Guru Pengampu PAI') AS nama_guru,
               g.nip AS nip_guru, g.foto AS foto_guru
        FROM tugas t
        JOIN kelas_mapel km ON t.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
        LEFT JOIN guru g ON t.id_guru = g.id
        WHERE km.id_kelas = :id_kelas
          AND (t.status_tugas != 'Draft' OR t.status_tugas IS NULL)
    ";
    $params = [':id_kelas' => $idKelas];

    if ($filterMapel > 0) {
        $sqlTugas .= " AND t.id_kelas_mata_pelajaran = :id_kmp";
        $params[':id_kmp'] = $filterMapel;
    }

    if ($filterJenis === 'Individu' || $filterJenis === 'Kelompok') {
        $sqlTugas .= " AND t.jenis_penugasan = :jenis";
        $params[':jenis'] = $filterJenis;
    }

    if ($filterSearch !== '') {
        $sqlTugas .= " AND (t.judul_tugas LIKE :kw OR t.deskripsi_instruksi LIKE :kw OR km.nama_mata_pelajaran LIKE :kw)";
        $params[':kw'] = '%' . $filterSearch . '%';
    }

    $sqlTugas .= " ORDER BY t.tenggat_waktu ASC, t.id DESC";

    $stmtTugas = $pdo->prepare($sqlTugas);
    $stmtTugas->execute($params);
    $rawTasks = $stmtTugas->fetchAll(PDO::FETCH_ASSOC);

    $tasks = [];
    $counts = [
        'all'       => 0,
        'pending'   => 0, // Belum dikerjakan
        'submitted' => 0, // Menunggu koreksi
        'graded'    => 0, // Tuntas & Dinilai
        'revision'  => 0  // Perlu Revisi
    ];

    foreach ($rawTasks as $t) {
        $tId = (int)$t['id'];
        $isKelompok = ($t['jenis_penugasan'] === 'Kelompok');

        // 1. Cek Kelompok Siswa
        $myGroup = null;
        $isMeKetua = false;
        if ($isKelompok) {
            $stmtG = $pdo->prepare("
                SELECT tk.id, tk.nomor_kelompok, tk.nama_kelompok, tk.id_ketua_kelompok,
                       s.nama_lengkap AS nama_ketua, s.nisn AS nisn_ketua
                FROM tugas_kelompok tk
                JOIN tugas_kelompok_anggota tka ON tk.id = tka.id_tugas_kelompok
                LEFT JOIN siswa s ON tk.id_ketua_kelompok = s.id
                WHERE tk.id_tugas = ? AND tka.id_siswa = ?
                LIMIT 1
            ");
            $stmtG->execute([$tId, $idSiswa]);
            $myGroup = $stmtG->fetch(PDO::FETCH_ASSOC);

            if ($myGroup) {
                $isMeKetua = ((int)($myGroup['id_ketua_kelompok'] ?? 0) === $idSiswa);
                $myGroup['is_me_ketua'] = $isMeKetua;

                $stmtAng = $pdo->prepare("
                    SELECT s.id, s.nama_lengkap, s.nisn, (s.id = ?) AS is_ketua
                    FROM tugas_kelompok_anggota tka
                    JOIN siswa s ON tka.id_siswa = s.id
                    WHERE tka.id_tugas_kelompok = ?
                    ORDER BY is_ketua DESC, s.nama_lengkap ASC
                ");
                $stmtAng->execute([$myGroup['id_ketua_kelompok'], $myGroup['id']]);
                $myGroup['anggota'] = $stmtAng->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        $t['my_group'] = $myGroup;
        $t['is_me_ketua'] = $isMeKetua;

        // 2. Cek Pengumpulan
        $submission = null;
        if ($isKelompok && $myGroup) {
            $stmtSub = $pdo->prepare("
                SELECT pt.*, s.nama_lengkap AS nama_pengumpul
                FROM pengumpulan_tugas pt
                LEFT JOIN siswa s ON pt.id_siswa = s.id
                WHERE pt.id_tugas = ? AND pt.id_tugas_kelompok = ?
                ORDER BY pt.waktu_pengumpulan DESC LIMIT 1
            ");
            $stmtSub->execute([$tId, $myGroup['id']]);
            $submission = $stmtSub->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmtSub = $pdo->prepare("
                SELECT pt.*, s.nama_lengkap AS nama_pengumpul
                FROM pengumpulan_tugas pt
                LEFT JOIN siswa s ON pt.id_siswa = s.id
                WHERE pt.id_tugas = ? AND pt.id_siswa = ?
                ORDER BY pt.waktu_pengumpulan DESC LIMIT 1
            ");
            $stmtSub->execute([$tId, $idSiswa]);
            $submission = $stmtSub->fetch(PDO::FETCH_ASSOC);
        }
        $t['submission'] = $submission;

        // 3. Cek Nilai
        $nilai = null;
        $stmtNilai = $pdo->prepare("
            SELECT nt.*, g.nama_guru
            FROM nilai_tugas nt
            LEFT JOIN guru g ON nt.id_guru = g.id
            WHERE nt.id_tugas = ? AND nt.id_siswa = ?
            LIMIT 1
        ");
        $stmtNilai->execute([$tId, $idSiswa]);
        $nilai = $stmtNilai->fetch(PDO::FETCH_ASSOC);
        $t['nilai'] = $nilai;

        // 4. Hitung Status Utama Siswa
        $statusKey = 'pending';
        if ($submission && ($submission['status_pengumpulan'] === 'Menunggu Revisi' || ($nilai && $nilai['status_penilaian'] === 'Perlu Revisi'))) {
            $statusKey = 'revision';
        } elseif ($nilai && in_array($nilai['status_penilaian'], ['Dinilai', 'Tuntas'])) {
            $statusKey = 'graded';
        } elseif ($submission) {
            $statusKey = 'submitted';
        } else {
            $statusKey = 'pending';
        }
        $t['student_status'] = $statusKey;

        // Update counts
        $counts['all']++;
        if (isset($counts[$statusKey])) {
            $counts[$statusKey]++;
        }

        // Filter status jika dipilih
        if ($filterStatus === 'all' || $filterStatus === $statusKey) {
            $tasks[] = $t;
        }
    }

    // PENGURUTAN TUGAS SISWA (SESUAI PRIORITAS STATUS & TENGGAT WAKTU):
    // 1. Belum dikerjakan (pending / revision) -> Diurutkan berdasarkan tenggat waktu (deadline) yang paling awal (paling mendesak)
    // 2. Yang sudah dikerjakan & dikirim (submitted)
    // 3. Yang sudah dinilai oleh guru (graded)
    usort($tasks, function($a, $b) {
        $getTier = function($status) {
            if ($status === 'pending' || $status === 'revision') return 1;
            if ($status === 'submitted') return 2;
            if ($status === 'graded') return 3;
            return 4;
        };

        $tierA = $getTier($a['student_status'] ?? '');
        $tierB = $getTier($b['student_status'] ?? '');

        if ($tierA !== $tierB) {
            return $tierA <=> $tierB;
        }

        // TIER 1: Belum dikerjakan -> Urutkan tanggal tenggat (deadline) yang paling awal
        if ($tierA === 1) {
            $timeA = !empty($a['tenggat_waktu']) ? strtotime($a['tenggat_waktu']) : PHP_INT_MAX;
            $timeB = !empty($b['tenggat_waktu']) ? strtotime($b['tenggat_waktu']) : PHP_INT_MAX;
            if ($timeA !== $timeB) {
                return $timeA <=> $timeB;
            }
            return (int)$b['id'] <=> (int)$a['id'];
        }

        // TIER 2: Sudah dikerjakan & dikirim -> Urutkan berdasarkan waktu pengumpulan terbaru
        if ($tierA === 2) {
            $subA = !empty($a['submission']['waktu_pengumpulan']) ? strtotime($a['submission']['waktu_pengumpulan']) : 0;
            $subB = !empty($b['submission']['waktu_pengumpulan']) ? strtotime($b['submission']['waktu_pengumpulan']) : 0;
            if ($subA !== $subB) {
                return $subB <=> $subA;
            }
            return (int)$b['id'] <=> (int)$a['id'];
        }

        // TIER 3: Sudah dinilai -> Urutkan berdasarkan waktu penilaian atau id terbaru
        if ($tierA === 3) {
            $timeA = !empty($a['nilai']['updated_at']) ? strtotime($a['nilai']['updated_at']) : (!empty($a['nilai']['created_at']) ? strtotime($a['nilai']['created_at']) : 0);
            $timeB = !empty($b['nilai']['updated_at']) ? strtotime($b['nilai']['updated_at']) : (!empty($b['nilai']['created_at']) ? strtotime($b['nilai']['created_at']) : 0);
            if ($timeA !== $timeB) {
                return $timeB <=> $timeA;
            }
            return (int)$b['id'] <=> (int)$a['id'];
        }

        return (int)$b['id'] <=> (int)$a['id'];
    });

    return ['tasks' => $tasks, 'counts' => $counts];
}

// ==============================================================================
// 7. HELPER RENDER KARTU TUGAS (REUSABLE UNTUK SSR & AJAX LAZY LOAD)
// ==============================================================================
function renderTaskCardsHtml($tasks, $namaKelas, $idSiswa) {
    if (empty($tasks)): ?>
        <div style="background: #FFFFFF; border: 1px dashed var(--lumina-border); border-radius: 16px; padding: 60px 20px; text-align: center; animation: tgsFadeInUp 0.35s ease forwards;">
            <iconify-icon icon="solar:notebook-bookmark-bold" style="font-size: 54px; color: #CBD5E1; margin-bottom: 12px;"></iconify-icon>
            <h3 style="font-size: 18px; font-weight: 800; color: var(--lumina-navy); margin: 0 0 6px;">Tidak Ada Penugasan Ditemukan</h3>
            <p style="font-size: 13.5px; color: #64748B; max-width: 440px; margin: 0 auto;">
                Belum ada tugas aktif untuk kriteria filter yang Anda pilih di kelas <strong><?= htmlspecialchars($namaKelas) ?></strong>.
            </p>
        </div>
    <?php else: ?>
        <div class="tgs-cards-grid">
            <?php foreach ($tasks as $t): 
                $sub = $t['submission'];
                $nil = $t['nilai'];
                $isGroup = ($t['jenis_penugasan'] === 'Kelompok');
                $group = $t['my_group'];
                $tId = (int)$t['id'];

                // Status Badge & Text
                $badgeClass = 'tgs-badge--pending';
                $badgeLabel = 'Belum Dikerjakan';
                $badgeIcon  = 'solar:clock-circle-bold';

                if ($t['student_status'] === 'revision') {
                    $badgeClass = 'tgs-badge--revision';
                    $badgeLabel = 'Perlu Revisi';
                    $badgeIcon  = 'solar:danger-triangle-bold';
                } elseif ($t['student_status'] === 'graded') {
                    $badgeClass = 'tgs-badge--graded';
                    $badgeLabel = 'Tuntas & Dinilai';
                    $badgeIcon  = 'solar:check-circle-bold';
                } elseif ($t['student_status'] === 'submitted') {
                    $badgeClass = 'tgs-badge--submitted';
                    $badgeLabel = 'Sudah Dikumpulkan';
                    $badgeIcon  = 'solar:file-check-bold';
                }

                // Deadline formatting
                $dlTime = !empty($t['tenggat_waktu']) ? strtotime($t['tenggat_waktu']) : 0;
                $isOverdue = ($dlTime > 0 && time() > $dlTime);
                $dlFormatted = ($dlTime > 0) ? date('d M Y, H:i', $dlTime) . ' WIB' : 'Tidak Terbatas';
            ?>
                <div class="tgs-card" id="taskCard_<?= $tId ?>" data-task-id="<?= $tId ?>" data-task-title="<?= htmlspecialchars($t['judul_tugas']) ?>" data-mapel="<?= htmlspecialchars($t['nama_mata_pelajaran']) ?>" data-jenis="<?= $isGroup ? 'Kelompok' : 'Mandiri' ?>" data-deadline="<?= htmlspecialchars($dlFormatted) ?>" data-status="<?= $badgeLabel ?>" data-score="<?= ($nil && in_array($nil['status_penilaian'], ['Dinilai', 'Tuntas'])) ? number_format((float)($nil['nilai'] ?? 0), 1) : '-' ?>">
                    <div>
                        <!-- TOP BADGES -->
                        <div class="tgs-card-top">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" class="tgs-chk-student" value="<?= $tId ?>" onchange="onStudentCheckboxChange()" style="width: 18px; height: 18px; accent-color: var(--lumina-teal); cursor: pointer; flex-shrink: 0;" title="Pilih tugas ini">
                                <span class="tgs-badge tgs-badge--mapel">
                                    <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>
                                    <span><?= htmlspecialchars($t['nama_mata_pelajaran']) ?></span>
                                </span>
                            </div>
                            <div style="display: flex; gap: 6px; align-items: center;">
                                <span class="tgs-priority-ribbon" id="priorityBadge_<?= $tId ?>" style="display:none; color: #F59E0B; font-size: 17px; margin-right: 4px;" title="Tugas Prioritas">
                                    <iconify-icon icon="solar:star-bold"></iconify-icon>
                                </span>
                                <?php if ($isGroup): ?>
                                    <span class="tgs-badge tgs-badge--kelompok">
                                        <iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon>
                                        <span>Kelompok</span>
                                    </span>
                                <?php else: ?>
                                    <span class="tgs-badge tgs-badge--mandiri">
                                        <iconify-icon icon="solar:user-bold"></iconify-icon>
                                        <span>Mandiri</span>
                                    </span>
                                <?php endif; ?>
                                <span class="tgs-badge <?= $badgeClass ?>">
                                    <iconify-icon icon="<?= $badgeIcon ?>"></iconify-icon>
                                    <span><?= $badgeLabel ?></span>
                                </span>
                            </div>
                        </div>

                        <!-- TITLE & DESCRIPTION -->
                        <h2 class="tgs-card-title"><?= htmlspecialchars($t['judul_tugas']) ?></h2>
                        <div class="tgs-card-desc"><?= nl2br(htmlspecialchars(($t['deskripsi_instruksi'] ?? $t['deskripsi_tugas'] ?? '') ?: 'Tidak ada instruksi khusus.')) ?></div>

                        <!-- TAUTAN SUMBER BELAJAR DARI GURU (JIKA ADA) -->
                        <?php if (!empty($t['link_sumber_tugas'])): 
                            $rawSource = trim($t['link_sumber_tugas']);
                            $urlSource = preg_match('~^https?://~i', $rawSource) ? $rawSource : 'https://' . $rawSource;
                        ?>
                            <div class="tgs-card-source-link">
                                <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
                                    <div style="width: 28px; height: 28px; border-radius: 8px; background: #E0F2FE; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: #0284C7;">
                                        <iconify-icon icon="solar:link-circle-bold" style="font-size: 18px;"></iconify-icon>
                                    </div>
                                    <div style="min-width: 0;">
                                        <div style="font-size: 10px; font-weight: 800; color: #0369A1; text-transform: uppercase; letter-spacing: 0.5px; line-height: 1.2;">Sumber Belajar:</div>
                                        <a href="<?= htmlspecialchars($urlSource) ?>" target="_blank" rel="noopener noreferrer" style="font-size: 11.5px; font-weight: 600; color: #0284C7; text-decoration: none; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($rawSource) ?>" onclick="event.stopPropagation();">
                                            <?= htmlspecialchars($rawSource) ?>
                                        </a>
                                    </div>
                                </div>
                                <a href="<?= htmlspecialchars($urlSource) ?>" target="_blank" rel="noopener noreferrer" class="btn-tgs btn-tgs--sm" style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: #0284C7; color: #ffffff; border-radius: 6px; font-size: 11px; font-weight: 700; text-decoration: none; flex-shrink: 0;" onclick="event.stopPropagation();">
                                    <span>Buka</span>
                                    <iconify-icon icon="solar:arrow-right-up-bold" style="font-size: 12px;"></iconify-icon>
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- INFO KELOMPOK (JIKA TUGAS KELOMPOK) -->
                        <?php if ($isGroup): ?>
                            <?php if ($group): 
                                $isMeKetua = !empty($t['is_me_ketua']);
                                $namaKetua = $group['nama_ketua'] ?: 'Belum ditentukan';
                            ?>
                                <div style="background: #F8FAFC; border: 1.5px solid #E2E8F0; border-radius: 12px; padding: 12px 14px; margin-bottom: 14px;">
                                    <!-- Header Kelompok & Role Badge -->
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                        <div style="display: flex; align-items: center; gap: 6px; font-weight: 800; font-size: 13px; color: #6B21A8;">
                                            <iconify-icon icon="solar:users-group-two-rounded-bold" style="font-size: 18px;"></iconify-icon>
                                            <span><?= htmlspecialchars($group['nama_kelompok']) ?></span>
                                        </div>
                                        <?php if ($isMeKetua): ?>
                                            <span class="tgs-badge" style="background: #FEF3C7; color: #B45309; font-weight: 800; font-size: 11px;">
                                                👑 Anda: Ketua Kelompok
                                            </span>
                                        <?php else: ?>
                                            <span class="tgs-badge" style="background: #E0E7FF; color: #4338CA; font-weight: 700; font-size: 11px;">
                                                👤 Anda: Anggota
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Ketua Kelompok -->
                                    <div style="font-size: 12px; color: #1E293B; background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 8px; padding: 8px 10px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <span style="font-size: 14px;">👑</span>
                                            <span>Ketua Kelompok:</span>
                                            <strong style="color: var(--lumina-navy);"><?= htmlspecialchars($namaKetua) ?></strong>
                                        </div>
                                        <?php if ($isMeKetua): ?>
                                            <span style="font-size: 10px; color: #059669; font-weight: 800; background: #DCFCE7; padding: 2px 6px; border-radius: 4px;">Wewenang Unggah</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Anggota Kelompok -->
                                    <div>
                                        <div style="font-size: 11px; font-weight: 700; color: #64748B; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
                                            Anggota Kelompok (<?= count($group['anggota'] ?? []) ?> Santri):
                                        </div>
                                        <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                            <?php foreach ($group['anggota'] as $ang): 
                                                $isAngKetua = ((int)$ang['id'] === (int)($group['id_ketua_kelompok'] ?? 0));
                                                $isAngMe = ((int)$ang['id'] === $idSiswa);
                                                $chipBg = $isAngKetua ? '#FEF3C7' : ($isAngMe ? '#E0F2FE' : '#FFFFFF');
                                                $chipBorder = $isAngKetua ? '#FDE68A' : ($isAngMe ? '#BAE6FD' : '#E2E8F0');
                                                $chipColor = $isAngKetua ? '#92400E' : ($isAngMe ? '#0369A1' : '#334155');
                                            ?>
                                                <span style="display: inline-flex; align-items: center; gap: 4px; background: <?= $chipBg ?>; border: 1px solid <?= $chipBorder ?>; color: <?= $chipColor ?>; padding: 3px 8px; border-radius: 6px; font-size: 11.5px; font-weight: <?= ($isAngKetua || $isAngMe) ? '700' : '500' ?>;">
                                                    <?php if ($isAngKetua): ?><span>👑</span><?php endif; ?>
                                                    <span><?= htmlspecialchars($ang['nama_lengkap']) ?></span>
                                                    <?php if ($isAngMe): ?><small style="font-weight: 800; opacity: 0.9;">(Anda)</small><?php endif; ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div style="background: #FEF2F2; border: 1px dashed #FCA5A5; border-radius: 10px; padding: 10px 14px; font-size: 12px; color: #DC2626; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                                    <iconify-icon icon="solar:danger-triangle-bold" style="font-size: 18px;"></iconify-icon>
                                    <span>Anda belum dimasukkan ke kelompok manapun pada tugas ini oleh guru.</span>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- JIKA SUDAH DINILAI GURU: KOTAK NILAI RESMI & RINCIAN RUMUS -->
                        <?php if ($nil && in_array($nil['status_penilaian'], ['Dinilai', 'Tuntas'])): 
                            $finalNilai = (float)($nil['nilai'] ?? 0);
                            $pred = $nil['predikat'] ?: hitungPredikatNilai($finalNilai);
                        ?>
                            <div class="tgs-score-result-box" style="margin-bottom: 12px;">
                                <div class="tgs-score-top">
                                    <div>
                                        <div style="font-size: 11px; font-weight: 700; color: #166534; text-transform: uppercase; letter-spacing: 0.5px;">Nilai Akhir Siswa:</div>
                                        <div class="tgs-score-number"><?= number_format($finalNilai, 1) ?></div>
                                    </div>
                                    <div class="tgs-score-predikat"><?= $pred ?></div>
                                </div>

                                <!-- RINCIAN NILAI JIKA TUGAS KELOMPOK -->
                                <?php if ($isGroup && isset($nil['nilai_kelompok']) && $nil['nilai_kelompok'] !== null): 
                                    $nk = (float)$nil['nilai_kelompok'];
                                    $ni = (float)($nil['nilai_individu'] ?? $nk);
                                ?>
                                    <div class="tgs-score-breakdown">
                                        <div style="display: flex; justify-content: space-between; font-weight: 700;">
                                            <span>Nilai Kelompok: <?= number_format($nk, 1) ?></span>
                                            <span>Kontribusi Individu: <strong><?= number_format($ni, 1) ?></strong></span>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- CATATAN & UMPAN BALIK GURU -->
                                <?php if (!empty($nil['catatan_guru'])): ?>
                                    <div style="margin-top: 8px; font-size: 11.5px; color: #166534; background: #FFFFFF; padding: 8px 10px; border-radius: 6px; border: 1px dashed #86EFAC;">
                                        <div style="font-weight: 700; display: flex; align-items: center; gap: 4px; margin-bottom: 2px;">
                                            <iconify-icon icon="solar:chat-round-dots-bold"></iconify-icon>
                                            <span>Umpan Balik Guru:</span>
                                        </div>
                                        <div style="font-style: italic;">"<?= htmlspecialchars($nil['catatan_guru']) ?>"</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- META LIST (GURU, TENGGAT, DLL) -->
                        <div class="tgs-card-meta-list">
                            <div class="tgs-meta-item">
                                <iconify-icon icon="solar:user-circle-bold" style="color: var(--lumina-teal); font-size: 15px;"></iconify-icon>
                                <span>Guru Pengampu: <strong><?= htmlspecialchars($t['nama_guru']) ?></strong></span>
                            </div>
                            <div class="tgs-meta-item">
                                <iconify-icon icon="solar:calendar-date-bold" style="color: <?= $isOverdue ? '#E11D48' : '#F59E0B' ?>; font-size: 15px;"></iconify-icon>
                                <span>Tenggat: <strong style="color: <?= $isOverdue ? '#E11D48' : 'inherit' ?>;"><?= $dlFormatted ?></strong></span>
                            </div>
                            <?php if ($sub): ?>
                                <div class="tgs-meta-item" style="color: #0284C7;">
                                    <iconify-icon icon="solar:cloud-check-bold" style="font-size: 15px;"></iconify-icon>
                                    <span>Dikumpulkan: <?= date('d M Y, H:i', strtotime($sub['waktu_pengumpulan'])) ?> (<?= $sub['status_pengumpulan'] ?>)</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- TOMBOL AKSI -->
                    <div class="tgs-card-actions">
                        <button type="button" class="btn-tgs btn-tgs--outline" onclick="openDetailModal(<?= $tId ?>)">
                            <iconify-icon icon="solar:info-circle-bold"></iconify-icon>
                            <span>Petunjuk</span>
                        </button>

                        <?php if ($t['student_status'] === 'graded'): ?>
                            <button type="button" class="btn-tgs btn-tgs--primary" onclick="openDetailModal(<?= $tId ?>)">
                                <iconify-icon icon="solar:diploma-verified-bold"></iconify-icon>
                                <span>Lihat Evaluasi</span>
                            </button>
                        <?php elseif ($isGroup && empty($t['is_me_ketua'])): ?>
                            <?php if ($sub): ?>
                                <button type="button" class="btn-tgs btn-tgs--primary" onclick="openDetailModal(<?= $tId ?>)" style="background: #0284C7;" title="Tugas telah dikumpulkan oleh Ketua Kelompok">
                                    <iconify-icon icon="solar:eye-bold"></iconify-icon>
                                    <span>Lihat Berkas Kelompok</span>
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn-tgs btn-tgs--outline" style="cursor: not-allowed; opacity: 0.85; background: #FEF3C7; color: #92400E; border-color: #FDE68A; flex: 1;" title="Hanya Ketua Kelompok (<?= htmlspecialchars($group['nama_ketua'] ?? '') ?>) yang memiliki hak mengunggah tugas kelompok" disabled>
                                    <iconify-icon icon="solar:lock-bold"></iconify-icon>
                                    <span>Hanya Ketua yang Dapat Mengumpulkan</span>
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <button type="button" class="btn-tgs btn-tgs--primary" onclick="openSubmitModal(<?= $tId ?>)">
                                <iconify-icon icon="solar:upload-track-bold"></iconify-icon>
                                <span><?= $sub ? 'Perbarui Tugas' : ($isGroup ? 'Kumpulkan (Ketua)' : 'Kumpulkan Tugas') ?></span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- CONTAINER PAGINASI SISWA -->
        <div class="tgs-pagination-bar" id="studentPaginationBar" style="display: none;"></div>
    <?php endif;
}

// ==============================================================================
// 8. HANDLER AJAX LAZY LOAD GET REQUEST (TANPA RELOAD HALAMAN)
// ==============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $fMapel  = (int)($_GET['mapel'] ?? 0);
    $fJenis  = trim($_GET['jenis'] ?? 'all');
    $fStatus = trim($_GET['status'] ?? 'all');
    $fSearch = trim($_GET['q'] ?? '');

    $result = fetchStudentTasks($pdo, $idKelas, $idSiswa, $fMapel, $fJenis, $fStatus, $fSearch);

    ob_start();
    renderTaskCardsHtml($result['tasks'], $namaKelas, $idSiswa);
    $htmlOutput = ob_get_clean();

    echo json_encode([
        'success' => true,
        'counts'  => $result['counts'],
        'html'    => $htmlOutput
    ]);
    exit;
}

// ==============================================================================
// 9. QUERY AWAL SERVER-SIDE RENDERING (SSR FIRST PAINT)
// ==============================================================================
$filterMapel = (int)($_GET['mapel'] ?? 0);
$filterJenis = trim($_GET['jenis'] ?? 'all');
$filterStatus= trim($_GET['status'] ?? 'all');
$filterSearch= trim($_GET['q'] ?? '');

$initialData = fetchStudentTasks($pdo, $idKelas, $idSiswa, $filterMapel, $filterJenis, $filterStatus, $filterSearch);
$tasks = $initialData['tasks'];
$counts = $initialData['counts'];

$pageTitle = 'Penugasan & Proyek Belajar';
require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<!-- LOCAL ASSETS PDF.JS UNTUK PRATINJAU AMAN BEBAS DOWNLOAD -->
<script src="<?= BASE_URL ?>/assets/js/pdf.min.js"></script>

<style>
/* ==============================================================================
 * STILISTIK KHUSUS PENUGASAN SISWA — LUMINA ISLAMIC THEME
 * ============================================================================== */
:root {
    --lumina-navy: #0A2540;
    --lumina-teal: #0D7A87;
    --lumina-emerald: #059669;
    --lumina-gold: #F59E0B;
    --lumina-rose: #E11D48;
    --lumina-purple: #7C3AED;
    --lumina-border: #E2E8F0;
    --lumina-bg-soft: #F8FAFC;
}

.tgs-student-wrap {
    padding: 20px 0 60px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

/* COMPACT TOP HEADER */
.tgs-top-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
    padding: 2px 0;
}
.tgs-top-header__info {
    display: flex;
    align-items: center;
    gap: 12px;
}
.tgs-top-header__icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: rgba(13, 122, 135, 0.12);
    color: var(--lumina-teal);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}
.tgs-top-header__title {
    font-size: 20px;
    font-weight: 800;
    color: var(--lumina-navy);
    font-family: 'Outfit', sans-serif;
    margin: 0;
    line-height: 1.2;
    letter-spacing: -0.3px;
}
.tgs-top-header__meta {
    font-size: 12.5px;
    color: #64748B;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 2px;
}
.tgs-top-header__class {
    font-weight: 700;
    color: var(--lumina-teal);
}
.tgs-dot {
    color: #CBD5E1;
    font-size: 10px;
}
.tgs-top-header__badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    padding: 7px 14px;
    border-radius: 9999px;
    font-size: 12.5px;
    font-weight: 700;
    color: var(--lumina-navy);
    box-shadow: 0 2px 6px rgba(0,0,0,0.03);
}
.tgs-active-indicator {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #10B981;
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25);
}

/* FILTER BAR */
.tgs-filter-card {
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    border-radius: 18px;
    padding: 16px 18px;
    margin-bottom: 20px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    box-shadow: 0 2px 10px rgba(10, 37, 64, 0.03);
}
.tgs-status-tabs {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 4px;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}
.tgs-status-tabs::-webkit-scrollbar {
    display: none;
}
.tgs-tab-pill {
    padding: 8px 16px;
    border-radius: 9999px;
    font-size: 13px;
    font-weight: 700;
    color: #475569;
    background: #F1F5F9;
    border: 1px solid transparent;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.tgs-tab-pill:hover {
    background: #E2E8F0;
    color: var(--lumina-navy);
}
.tgs-tab-pill.active {
    background: linear-gradient(135deg, #0D7A87 0%, #09636E 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(13, 122, 135, 0.28);
}
.tgs-tab-pill .tgs-count {
    background: rgba(0, 0, 0, 0.08);
    padding: 2px 8px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 800;
}
.tgs-tab-pill.active .tgs-count {
    background: rgba(255, 255, 255, 0.25);
    color: #FFFFFF;
}

.tgs-filter-inputs {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.tgs-filter-select, .tgs-filter-search {
    padding: 10px 14px;
    border-radius: 12px;
    border: 1.5px solid var(--lumina-border);
    font-size: 13px;
    color: #334155;
    background: #FFFFFF;
    outline: none;
    transition: all 0.2s;
}
.tgs-filter-select:focus, .tgs-filter-search:focus {
    border-color: var(--lumina-teal);
    box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.12);
}
.tgs-filter-search {
    flex: 1;
    min-width: 220px;
}

/* GRID TUGAS */
.tgs-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 20px;
}
@media (max-width: 640px) {
    .tgs-cards-grid {
        grid-template-columns: 1fr;
    }
}

.tgs-card {
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    border-radius: 16px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 16px;
    transition: transform 0.2s, box-shadow 0.2s;
    position: relative;
    overflow: hidden;
}
.tgs-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.06);
    border-color: #CBD5E1;
}

.tgs-card-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}
.tgs-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 11.5px;
    font-weight: 700;
}
.tgs-badge--mapel { background: #F1F5F9; color: var(--lumina-navy); }
.tgs-badge--mandiri { background: #E0F2FE; color: #0369A1; }
.tgs-badge--kelompok { background: #F3E8FF; color: #7E22CE; }
.tgs-badge--pending { background: #FEF3C7; color: #B45309; }
.tgs-badge--submitted { background: #E0F2FE; color: #0284C7; }
.tgs-badge--graded { background: #DCFCE7; color: #15803D; }
.tgs-badge--revision { background: #FFE4E6; color: #E11D48; }

.tgs-card-title {
    font-size: 16.5px;
    font-weight: 800;
    color: var(--lumina-navy);
    line-height: 1.35;
    margin: 8px 0 6px;
}
.tgs-card-desc {
    font-size: 12.5px;
    color: #64748B;
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    margin-bottom: 12px;
}

.tgs-card-source-link {
    background: #F0F9FF;
    border: 1.5px solid #BAE6FD;
    border-radius: 10px;
    padding: 8px 12px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    box-shadow: 0 1px 3px rgba(2, 132, 199, 0.05);
    transition: all 0.2s ease;
}
.tgs-card-source-link:hover {
    background: #E0F2FE;
    border-color: #7DD3FC;
}

.tgs-card-meta-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 12px;
    color: #475569;
    background: #F8FAFC;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid #EDF2F7;
}
.tgs-meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

/* KOTAK KELOMPOK SISWA */
.tgs-group-card {
    background: #FAF5FF;
    border: 1px solid #E9D5FF;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 12px;
    color: #6B21A8;
}
.tgs-group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-weight: 700;
    margin-bottom: 4px;
}
.tgs-group-members {
    font-size: 11px;
    color: #7E22CE;
}

/* KOTAK NILAI SISWA */
.tgs-score-result-box {
    background: #F0FDF4;
    border: 1.5px solid #86EFAC;
    border-radius: 12px;
    padding: 12px 14px;
}
.tgs-score-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
}
.tgs-score-number {
    font-size: 26px;
    font-weight: 900;
    font-family: 'Outfit', sans-serif;
    color: #15803D;
    line-height: 1;
}
.tgs-score-predikat {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: #16A34A;
    color: #FFFFFF;
    font-size: 18px;
    font-weight: 900;
    display: flex;
    align-items: center;
    justify-content: center;
}
.tgs-score-breakdown {
    background: rgba(255,255,255,0.7);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 11px;
    color: #166534;
    margin-top: 6px;
    line-height: 1.4;
}

/* TOMBOL AKSI */
.tgs-card-actions {
    display: flex;
    gap: 8px;
    margin-top: 6px;
}
.btn-tgs {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 9px 14px;
    border-radius: 10px;
    font-size: 12.5px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}
.btn-tgs--primary {
    background: var(--lumina-teal);
    color: #FFFFFF;
    flex: 1;
}
.btn-tgs--primary:hover {
    background: #09636e;
    color: #FFFFFF;
}
.btn-tgs--outline {
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    color: #334155;
}
.btn-tgs--outline:hover {
    background: #F8FAFC;
    color: var(--lumina-navy);
}

/* MODAL SYSTEM */
.tgs-modal-backdrop {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.7);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.tgs-modal-backdrop.active {
    display: flex;
}
.tgs-modal-card {
    background: #FFFFFF;
    border-radius: 20px;
    width: 100%;
    max-width: 680px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    animation: tgsScaleUp 0.2s ease-out;
}
.tgs-modal-card--lg {
    max-width: 1100px;
    height: 92vh;
}
@keyframes tgsScaleUp {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
.tgs-modal-header {
    padding: 18px 24px;
    border-bottom: 1px solid var(--lumina-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #F8FAFC;
}
.tgs-modal-title {
    font-size: 17px;
    font-weight: 800;
    color: var(--lumina-navy);
    margin: 0;
}
.tgs-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    color: #94A3B8;
    cursor: pointer;
    line-height: 1;
}
.tgs-modal-close:hover { color: #334155; }
.tgs-modal-body {
    padding: 24px;
    overflow-y: auto;
    flex: 1;
}

/* FORM ELEMENTS */
.tgs-form-group {
    margin-bottom: 18px;
}
.tgs-form-label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: #334155;
    margin-bottom: 6px;
}
.tgs-form-control {
    width: 100%;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid var(--lumina-border);
    font-size: 13.5px;
    color: #334155;
    background: #FFFFFF;
    outline: none;
}
.tgs-form-control:focus {
    border-color: var(--lumina-teal);
    box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.15);
}

.tgs-dropzone {
    border: 2px dashed #CBD5E1;
    border-radius: 14px;
    padding: 28px 20px;
    text-align: center;
    background: #F8FAFC;
    cursor: pointer;
    transition: all 0.2s;
}
.tgs-dropzone:hover, .tgs-dropzone.dragover {
    border-color: var(--lumina-teal);
    background: #F0FDF4;
}

/* TOAST & DIALOG SYSTEM */
.tgs-toast-container {
    position: fixed;
    top: 24px; right: 24px;
    z-index: 100000;
    display: flex;
    flex-direction: column;
    gap: 10px;
    pointer-events: none;
}
.tgs-toast-item {
    background: #FFFFFF;
    border-radius: 12px;
    padding: 14px 18px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    display: flex;
    align-items: center;
    gap: 12px;
    pointer-events: auto;
    min-width: 300px;
    max-width: 440px;
    border-left: 5px solid var(--lumina-teal);
    animation: tgsSlideIn 0.25s ease-out;
}
.tgs-toast-item.toast-success { border-left-color: #059669; }
.tgs-toast-item.toast-warn { border-left-color: #F59E0B; }
.tgs-toast-item.toast-error { border-left-color: #E11D48; }
@keyframes tgsSlideIn {
    from { opacity: 0; transform: translateX(40px); }
    to { opacity: 1; transform: translateX(0); }
}

/* VIEWER STAGE VIEW-ONLY */
.tgs-viewer-container {
    background: #0F172A;
    border-radius: 12px;
    overflow: hidden;
    height: 75vh;
    display: flex;
    flex-direction: column;
    position: relative;
    user-select: none;
    -webkit-user-select: none;
}
.tgs-viewer-header {
    background: #1E293B;
    padding: 10px 16px;
    color: #F8FAFC;
    font-size: 13px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #334155;
}
.tgs-viewer-canvas-wrap {
    flex: 1;
    overflow: auto;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #090D16;
    padding: 20px;
}
.tgs-viewer-canvas {
    max-width: 100%;
    border-radius: 8px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.5);
}

/* FLOATING BULK BAR UNTUK SISWA */
.tgs-bulk-floating-bar {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%) translateY(100px);
    background: rgba(10, 37, 64, 0.95);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 50px;
    padding: 10px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    z-index: 1050;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35);
    opacity: 0;
    pointer-events: none;
    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    color: #FFFFFF;
}
.tgs-bulk-floating-bar.is-visible {
    transform: translateX(-50%) translateY(0);
    opacity: 1;
    pointer-events: auto;
}
.tgs-bulk-info {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 700;
}
.tgs-bulk-count {
    background: var(--lumina-teal);
    color: #FFFFFF;
    font-size: 12px;
    font-weight: 800;
    padding: 3px 10px;
    border-radius: 20px;
}
.tgs-bulk-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}
.btn-bulk-grade {
    background: var(--lumina-teal) !important;
    color: #FFFFFF !important;
    border: none !important;
    box-shadow: 0 4px 12px rgba(13, 122, 135, 0.3);
}
.btn-bulk-status {
    background: rgba(255, 255, 255, 0.12) !important;
    color: #FFFFFF !important;
    border: 1px solid rgba(255, 255, 255, 0.2) !important;
}
.btn-bulk-status:hover {
    background: rgba(255, 255, 255, 0.22) !important;
}
.btn-bulk-cancel {
    background: transparent !important;
    color: #94A3B8 !important;
    border: none !important;
}
.btn-bulk-cancel:hover {
    color: #FFFFFF !important;
}

/* PRIORITY CARD HIGHLIGHT */
.tgs-card.is-priority {
    border-color: #F59E0B !important;
    box-shadow: 0 6px 20px rgba(245, 158, 11, 0.15) !important;
}
.tgs-priority-ribbon {
    animation: tgsScaleUp 0.2s ease-out;
}

/* PAGINASI SISWA */
.tgs-pagination-bar {
    margin-top: 24px;
    padding: 14px 18px;
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    box-shadow: 0 2px 8px rgba(10, 37, 64, 0.03);
}
.tgs-pag-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 700;
    color: var(--lumina-navy);
    cursor: pointer;
    transition: all 0.2s ease;
}
.tgs-pag-btn:hover:not(:disabled) {
    background: #E0F2FE;
    border-color: #BAE6FD;
    color: #0284C7;
}
.tgs-pag-num {
    min-width: 34px;
    height: 34px;
    padding: 0 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 700;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s ease;
}
.tgs-pag-num:hover {
    background: #F1F5F9;
    color: var(--lumina-navy);
}
.tgs-pag-num.active {
    background: var(--lumina-teal);
    border-color: var(--lumina-teal);
    color: #FFFFFF;
    box-shadow: 0 2px 6px rgba(13, 122, 135, 0.3);
}

/* RESPONSIVE DETAIL MODAL GRID */
.tgs-detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

@media (max-width: 860px) {
    .tgs-detail-grid {
        grid-template-columns: 1fr;
    }
    .tgs-modal-card--lg {
        height: auto;
        max-height: 92vh;
    }
}

@media (max-width: 768px) {
    .tgs-student-wrap {
        padding: 12px 0 84px;
    }
    .container-fluid {
        padding: 0 12px !important;
    }
    .tgs-top-header {
        flex-direction: row;
        align-items: center;
        gap: 10px;
        margin-bottom: 12px;
    }
    .tgs-top-header__icon {
        width: 38px;
        height: 38px;
        font-size: 20px;
        border-radius: 10px;
    }
    .tgs-top-header__title {
        font-size: 17px;
    }
    .tgs-top-header__meta {
        font-size: 11.5px;
    }
    .tgs-top-header__badge {
        display: none;
    }
    .tgs-filter-card {
        padding: 12px 14px;
        border-radius: 16px;
        margin-bottom: 14px;
        gap: 10px;
    }
    .tgs-status-tabs {
        gap: 6px;
        padding-bottom: 2px;
    }
    .tgs-tab-pill {
        padding: 7px 12px;
        font-size: 12px;
        gap: 6px;
    }
    .tgs-filter-inputs {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
    }
    .tgs-filter-select, .tgs-filter-search {
        width: 100%;
        min-width: 0;
        height: 42px;
        font-size: 13px;
        border-radius: 12px;
        padding: 0 14px;
    }
    .tgs-cards-grid {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .tgs-card {
        padding: 16px;
        border-radius: 18px;
        box-shadow: 0 4px 16px rgba(10, 37, 64, 0.05);
    }
    .tgs-card-top {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 6px;
    }
    .tgs-card-title {
        font-size: 16px;
        margin: 8px 0 4px;
        line-height: 1.35;
    }
    .tgs-card-desc {
        font-size: 12px;
        line-height: 1.45;
        margin-bottom: 10px;
    }
    .tgs-card-meta-list {
        padding: 10px 12px;
        border-radius: 12px;
        font-size: 11.5px;
        gap: 5px;
    }
    .tgs-card-actions {
        display: flex;
        gap: 8px;
        margin-top: 10px;
    }
    .tgs-card-actions .btn-tgs {
        min-height: 42px;
        font-size: 12.5px;
        border-radius: 12px;
    }
    .tgs-bulk-floating-bar {
        left: 12px;
        right: 12px;
        width: auto;
        max-width: none;
        transform: translateY(120px);
        bottom: 12px;
        border-radius: 18px;
        padding: 12px 14px;
        flex-direction: column;
        gap: 10px;
    }
    .tgs-bulk-floating-bar.is-visible {
        transform: translateY(0);
    }
    .tgs-bulk-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px;
        width: 100%;
    }
    .tgs-bulk-actions .btn-bulk-cancel {
        grid-column: 1 / -1;
    }
    .tgs-pagination-bar {
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 12px;
        gap: 10px;
    }
    .tgs-pag-controls {
        width: 100%;
        justify-content: center;
        flex-wrap: wrap;
    }
    .tgs-pag-btn {
        padding: 6px 10px;
        font-size: 11.5px;
    }
    /* Mobile Modal Bottom Sheet for Native App Feel */
    .tgs-modal-backdrop {
        padding: 0 !important;
        align-items: flex-end !important;
    }
    .tgs-modal-card {
        border-radius: 20px 20px 0 0 !important;
        margin-top: auto !important;
        max-height: 94vh !important;
        width: 100% !important;
        max-width: 100% !important;
    }
    .tgs-modal-card--lg {
        height: auto !important;
        max-height: 94vh !important;
    }
}

/* PRINT MEDIA UNTUK REKAP TUGAS */
@media print {
    body * {
        visibility: hidden;
    }
    #modalRekapTugas, #modalRekapTugas * {
        visibility: visible;
    }
    #modalRekapTugas {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        background: #FFFFFF !important;
    }
    .tgs-modal-card {
        box-shadow: none !important;
        border: none !important;
        max-width: 100% !important;
        width: 100% !important;
    }
    .tgs-modal-header button, .tgs-modal-card > div:last-child {
        display: none !important;
    }
}
</style>

<div class="tgs-student-wrap">
    <div class="container-fluid" style="max-width: 1320px; margin: 0 auto; padding: 0 16px;">

        <!-- FILTER CARD (INTERAKTIF & LAZY LOADED TANPA RELOAD HALAMAN) -->
        <div class="tgs-filter-card">
            <!-- TABS STATUS PENUGASAN -->
            <div class="tgs-status-tabs">
                <button type="button" class="tgs-tab-pill <?= $filterStatus === 'all' ? 'active' : '' ?>" data-status="all" onclick="setTabFilter('all', this)">
                    <iconify-icon icon="solar:widget-2-bold"></iconify-icon>
                    <span>Semua Tugas</span>
                    <span class="tgs-count" id="count_all"><?= $counts['all'] ?></span>
                </button>
                <button type="button" class="tgs-tab-pill <?= $filterStatus === 'pending' ? 'active' : '' ?>" data-status="pending" onclick="setTabFilter('pending', this)">
                    <iconify-icon icon="solar:clock-circle-bold"></iconify-icon>
                    <span>Belum Dikerjakan</span>
                    <span class="tgs-count" id="count_pending"><?= $counts['pending'] ?></span>
                </button>
                <button type="button" class="tgs-tab-pill <?= $filterStatus === 'submitted' ? 'active' : '' ?>" data-status="submitted" onclick="setTabFilter('submitted', this)">
                    <iconify-icon icon="solar:file-check-bold"></iconify-icon>
                    <span>Sudah Dikumpulkan</span>
                    <span class="tgs-count" id="count_submitted"><?= $counts['submitted'] ?></span>
                </button>
                <button type="button" class="tgs-tab-pill <?= $filterStatus === 'graded' ? 'active' : '' ?>" data-status="graded" onclick="setTabFilter('graded', this)">
                    <iconify-icon icon="solar:medal-star-bold"></iconify-icon>
                    <span>Tuntas & Dinilai</span>
                    <span class="tgs-count" id="count_graded"><?= $counts['graded'] ?></span>
                </button>
                <button type="button" class="tgs-tab-pill <?= $filterStatus === 'revision' ? 'active' : '' ?>" data-status="revision" id="tabPillRevision" style="color: #E11D48; <?= $counts['revision'] > 0 ? '' : 'display:none;' ?>" onclick="setTabFilter('revision', this)">
                    <iconify-icon icon="solar:danger-triangle-bold"></iconify-icon>
                    <span>Perlu Revisi</span>
                    <span class="tgs-count" id="count_revision" style="background: rgba(225, 29, 72, 0.2);"><?= $counts['revision'] ?></span>
                </button>
            </div>

            <!-- FILTER CONTROLS (HANYA MAPEL KELAS SISWA) -->
            <div class="tgs-filter-inputs">
                <select id="filterMapel" class="tgs-filter-select" onchange="onFilterChange()">
                    <option value="0">Semua Mata Pelajaran Kelas Anda</option>
                    <?php foreach ($myMapelList as $mp): ?>
                        <option value="<?= $mp['id'] ?>" <?= $filterMapel === (int)$mp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($mp['nama_mata_pelajaran']) ?> (<?= htmlspecialchars($mp['nama_guru']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="filterJenis" class="tgs-filter-select" onchange="onFilterChange()">
                    <option value="all" <?= $filterJenis === 'all' ? 'selected' : '' ?>>Semua Jenis Tugas</option>
                    <option value="Individu" <?= $filterJenis === 'Individu' ? 'selected' : '' ?>>Tugas Mandiri (Individu)</option>
                    <option value="Kelompok" <?= $filterJenis === 'Kelompok' ? 'selected' : '' ?>>Tugas Kelompok Kolaboratif</option>
                </select>

                <div style="position: relative; flex: 1; min-width: 180px;">
                    <input type="text" id="filterSearch" class="tgs-filter-search" placeholder="Cari judul tugas atau materi..." value="<?= htmlspecialchars($filterSearch) ?>" oninput="onSearchInput()" style="width: 100%;">
                </div>

                <button type="button" class="btn-tgs btn-tgs--outline" onclick="onFilterChange()" style="padding: 9px 16px;">
                    <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                    <span>Cari</span>
                </button>
                <button type="button" id="btnResetFilter" class="btn-tgs btn-tgs--outline" onclick="resetFilters()" title="Reset Filter" style="<?= ($filterMapel > 0 || $filterJenis !== 'all' || !empty($filterSearch)) ? '' : 'display:none;' ?>">
                    <iconify-icon icon="solar:refresh-bold"></iconify-icon>
                </button>
            </div>
        </div>

        <!-- TOOLBAR AKSI MASSAL SISWA -->
        <div style="background: #FFFFFF; border: 1px solid var(--lumina-border); border-radius: 14px; padding: 10px 14px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.02);">
            <div style="display: flex; align-items: center; gap: 8px;">
                <input type="checkbox" id="chkSelectAllStudent" onchange="toggleSelectAllStudent(this)" style="width: 18px; height: 18px; accent-color: var(--lumina-teal); cursor: pointer;">
                <label for="chkSelectAllStudent" style="font-size: 13px; font-weight: 700; color: #475569; cursor: pointer; user-select: none;">Pilih Semua Tugas</label>
            </div>
            <div style="font-size: 12px; color: #64748B; font-weight: 600;">
                <span id="selectedStudentSummary" style="background: #F1F5F9; padding: 3px 10px; border-radius: 9999px;">0 tugas dipilih</span>
            </div>
        </div>

        <!-- DAFTAR KARTU TUGAS SISWA (CONTAINER UNTUK LAZY LOAD & DYNAMIC IN-PLACE PATCH) -->
        <div id="tasksCardsContainer">
            <?php renderTaskCardsHtml($tasks, $namaKelas, $idSiswa); ?>
        </div>

    </div>
</div>

<!-- FLOATING BULK BAR UNTUK SISWA -->
<div class="tgs-bulk-floating-bar" id="bulkBarStudent">
    <div class="tgs-bulk-info">
        <span class="tgs-bulk-count" id="bulkStudentCount">0</span>
        <span class="tgs-bulk-text">Tugas Terpilih</span>
    </div>
    <div class="tgs-bulk-actions">
        <button type="button" class="btn-tgs btn-tgs--sm btn-bulk-grade" onclick="openRekapModal()" title="Lihat Rekap & Cetak Ringkasan Penugasan">
            <iconify-icon icon="solar:printer-bold"></iconify-icon>
            <span>Rekap & Cetak Ringkasan</span>
        </button>
        <button type="button" class="btn-tgs btn-tgs--sm btn-bulk-status" onclick="toggleBookmarkSelected()" title="Tandai Prioritas / Bintang pada tugas terpilih">
            <iconify-icon icon="solar:star-bold" style="color: #F59E0B;"></iconify-icon>
            <span>Tandai Prioritas</span>
        </button>
        <button type="button" class="btn-tgs btn-tgs--sm btn-bulk-cancel" onclick="clearStudentSelection()" title="Batalkan Pilihan">
            <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            <span>Batal</span>
        </button>
    </div>
</div>

<!-- ==============================================================================
 * MODAL 1: DETAIL PETUNJUK TUGAS & PRATINJAU LAMPIRAN (STRICT VIEW-ONLY)
 * ============================================================================== -->
<div class="tgs-modal-backdrop" id="modalDetailTugas">
    <div class="tgs-modal-card tgs-modal-card--lg">
        <div class="tgs-modal-header">
            <div>
                <h3 class="tgs-modal-title" id="detailTaskTitle">Petunjuk & Rincian Tugas</h3>
                <div style="font-size: 12px; color: #64748B;" id="detailTaskSub">Memuat informasi tugas...</div>
            </div>
            <button type="button" class="tgs-modal-close" onclick="closeModal('modalDetailTugas')">&times;</button>
        </div>
        <div class="tgs-modal-body" style="padding: 20px;">
            <div class="tgs-detail-grid" id="detailLayoutGrid">
                
                <!-- KOLOM KIRI: PETUNJUK LENGKAP -->
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div style="background: #F8FAFC; border: 1px solid var(--lumina-border); border-radius: 12px; padding: 16px;">
                        <h4 style="font-size: 14px; font-weight: 800; color: var(--lumina-navy); margin: 0 0 8px; display: flex; align-items: center; gap: 6px;">
                            <iconify-icon icon="solar:document-text-bold" style="color: var(--lumina-teal); font-size: 18px;"></iconify-icon>
                            <span>Instruksi Tugas Guru:</span>
                        </h4>
                        <div id="detailTaskInstructions" style="font-size: 13.5px; color: #334155; line-height: 1.6; white-space: pre-line;">-</div>
                    </div>

                    <!-- KOTAK TAUTAN SUMBER BELAJAR DARI GURU (JIKA ADA) -->
                    <div id="detailSourceLinkWrap" style="display: none; background: #F0F9FF; border: 1.5px solid #BAE6FD; border-radius: 12px; padding: 14px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px;">
                            <div style="font-size: 12px; font-weight: 800; color: #0369A1; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 6px;">
                                <iconify-icon icon="solar:link-circle-bold" style="font-size: 18px; color: #0284C7;"></iconify-icon>
                                <span>Tautan Sumber Belajar Tambahan:</span>
                            </div>
                            <span class="tgs-badge" style="background: #E0F2FE; color: #0369A1; font-size: 11px; font-weight: 700;">Referensi Guru</span>
                        </div>
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; background: #FFFFFF; border: 1px solid #BAE6FD; border-radius: 8px; padding: 10px 12px;">
                            <div style="min-width: 0; display: flex; align-items: center; gap: 8px;">
                                <iconify-icon icon="solar:global-bold" style="font-size: 18px; color: #0284C7; flex-shrink: 0;"></iconify-icon>
                                <a id="detailSourceLinkAnchor" href="#" target="_blank" rel="noopener noreferrer" style="font-size: 12.5px; font-weight: 600; color: #0284C7; text-decoration: underline; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block;" title="">-</a>
                            </div>
                            <a id="detailSourceLinkBtn" href="#" target="_blank" rel="noopener noreferrer" class="btn-tgs btn-tgs--sm" style="display: inline-flex; align-items: center; gap: 5px; padding: 6px 14px; background: #0284C7; color: #ffffff; border-radius: 6px; font-size: 12px; font-weight: 700; text-decoration: none; flex-shrink: 0; box-shadow: 0 1px 3px rgba(2, 132, 199, 0.25);">
                                <span>Buka Link</span>
                                <iconify-icon icon="solar:arrow-right-up-bold" style="font-size: 14px;"></iconify-icon>
                            </a>
                        </div>
                    </div>

                    <!-- KOTAK FORMAT & ATURAN PENGUMPULAN -->
                    <div style="background: #FFFFFF; border: 1px solid var(--lumina-border); border-radius: 12px; padding: 16px; font-size: 12.5px;">
                        <div style="font-weight: 700; color: var(--lumina-navy); margin-bottom: 8px;">Aturan & Format Tugas:</div>
                        <div style="display: flex; flex-direction: column; gap: 6px; color: #475569;">
                            <div>• Tipe Pengumpulan: <strong id="detailTipePengumpulan">-</strong></div>
                            <div>• Format File Dibolehkan: <strong id="detailFormatFile">-</strong></div>
                            <div>• Batas Maksimal Ukuran: <strong id="detailMaxMb">- MB</strong></div>
                            <div>• Batas Waktu (Deadline): <strong id="detailDeadline" style="color: #E11D48;">-</strong></div>
                        </div>
                    </div>

                    <!-- KOTAK KELOMPOK (JIKA KELOMPOK) -->
                    <div id="detailGroupBox" style="display: none; background: #FAF5FF; border: 1px solid #E9D5FF; border-radius: 12px; padding: 14px;">
                        <div style="font-weight: 800; color: #6B21A8; font-size: 13px; margin-bottom: 6px;">Penugasan Kelompok:</div>
                        <div id="detailGroupMembersList" style="font-size: 12px; color: #7E22CE; display: flex; flex-direction: column; gap: 4px;"></div>
                    </div>

                    <button type="button" class="btn-tgs btn-tgs--primary" id="btnGoToSubmit" style="padding: 12px; justify-content: center; margin-top: auto;">
                        <iconify-icon icon="solar:upload-track-bold" style="font-size: 18px;"></iconify-icon>
                        <span id="labelGoToSubmit">Kumpulkan Tugas Sekarang</span>
                    </button>
                </div>

                <!-- KOLOM KANAN: PRATINJAU LAMPIRAN BERKAS GURU (VIEW-ONLY) -->
                <div>
                    <div style="font-size: 13px; font-weight: 700; color: var(--lumina-navy); margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between;">
                        <span>Berkas Lampiran / Materi Rujukan Guru:</span>
                        <span class="tgs-badge" style="background: #DCFCE7; color: #15803D; font-size: 11px;">View-Only</span>
                    </div>
                    <div class="tgs-viewer-container" id="detailViewerContainer">
                        <div class="tgs-viewer-header">
                            <span id="detailViewerFileName">Lampiran Tugas</span>
                            <span style="font-size: 11px; opacity: 0.8;">Anti-Download Dilindungi</span>
                        </div>
                        <div class="tgs-viewer-canvas-wrap" id="detailViewerStage">
                            <div style="color: #94A3B8; font-size: 13px;">Tidak ada berkas lampiran.</div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ==============================================================================
 * MODAL 2: FORM PENGUMPULAN TUGAS SISWA (SIMPAN LANGSUNG KE SERVER LOKAL)
 * ============================================================================== -->
<div class="tgs-modal-backdrop" id="modalSubmitTugas">
    <div class="tgs-modal-card">
        <div class="tgs-modal-header">
            <div>
                <h3 class="tgs-modal-title" id="submitModalTitle">Kumpulkan Tugas Siswa</h3>
                <div style="font-size: 12px; color: #64748B;" id="submitModalSub">Penyimpanan Aman di Server Lokal (Maksimal 1 MB)</div>
            </div>
            <button type="button" class="tgs-modal-close" onclick="closeModal('modalSubmitTugas')">&times;</button>
        </div>
        <div class="tgs-modal-body">
            
            <!-- ALERT INFO KHUSUS TUGAS KELOMPOK -->
            <div id="submitGroupNotice" style="display: none; background: #FAF5FF; border: 1.5px solid #D8B4FE; border-radius: 12px; padding: 12px 16px; margin-bottom: 18px;">
                <div style="font-size: 12.5px; font-weight: 800; color: #6B21A8; display: flex; align-items: center; gap: 6px;">
                    <iconify-icon icon="solar:users-group-two-rounded-bold" style="font-size: 18px;"></iconify-icon>
                    <span>Pengumpulan Tugas Kelompok Kolaboratif</span>
                </div>
                <div style="font-size: 11.5px; color: #7E22CE; margin-top: 4px;" id="submitGroupNoticeText">
                    Pengumpulan ini mewakili seluruh anggota kelompok Anda. Cukup satu perwakilan yang mengunggah tugas.
                </div>
            </div>

            <!-- BANNER TAUTAN SUMBER BELAJAR GURU (OPSIONAL) -->
            <div id="submitSourceLinkNotice" style="display: none; background: #F0F9FF; border: 1.5px solid #BAE6FD; border-radius: 12px; padding: 10px 14px; margin-bottom: 16px;">
                <div style="font-size: 12px; font-weight: 700; color: #0369A1; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                    <div style="display: flex; align-items: center; gap: 6px; min-width: 0;">
                        <iconify-icon icon="solar:link-circle-bold" style="font-size: 17px; color: #0284C7; flex-shrink: 0;"></iconify-icon>
                        <span style="white-space: nowrap;">Sumber Belajar:</span>
                        <a id="submitSourceLinkAnchor" href="#" target="_blank" rel="noopener noreferrer" style="color: #0284C7; text-decoration: underline; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600;"></a>
                    </div>
                    <a id="submitSourceLinkBtn" href="#" target="_blank" rel="noopener noreferrer" style="font-size: 11.5px; font-weight: 700; color: #0284C7; white-space: nowrap; text-decoration: none; display: inline-flex; align-items: center; gap: 3px;">
                        <span>Kunjungi</span>
                        <iconify-icon icon="solar:arrow-right-up-bold"></iconify-icon>
                    </a>
                </div>
            </div>

            <form id="formSubmitTugas" onsubmit="handleFormSubmission(event)">
                <input type="hidden" id="submitTaskId" name="id_tugas" value="0">

                <!-- UPLOAD FILE -->
                <div class="tgs-form-group">
                    <label class="tgs-form-label">Unggah Berkas Tugas (Dokumen / PDF / Gambar / Arsip):</label>
                    <div class="tgs-dropzone" id="fileDropzone" onclick="document.getElementById('fileInputTugas').click()">
                        <iconify-icon icon="solar:cloud-upload-bold" style="font-size: 38px; color: var(--lumina-teal); margin-bottom: 8px;"></iconify-icon>
                        <div style="font-size: 14px; font-weight: 700; color: var(--lumina-navy);" id="dropzoneLabel">
                            Klik atau Seret Berkas ke Area Ini
                        </div>
                        <div style="font-size: 12px; color: #64748B; margin-top: 4px;" id="dropzoneInfo">
                            Format diizinkan: PDF, DOCX, PPTX, JPG, PNG, ZIP (Maksimal <span id="labelMaxMbSubmit">1</span> MB - Server Lokal)
                        </div>
                    </div>
                    <input type="file" id="fileInputTugas" name="file_tugas" style="display: none;" onchange="onFileSelected(this)">
                </div>

                <!-- LINK DOKUMEN EKSTERNAL (OPSIONAL / GOOGLE DOCS / CANVA) -->
                <div class="tgs-form-group">
                    <label class="tgs-form-label">Tautan Dokumen Eksternal (Opsional / Google Docs / Canva):</label>
                    <input type="url" id="inputLinkTugas" name="link_tugas" class="tgs-form-control" placeholder="https://docs.google.com/document/d/... atau tautan Canva / Drive">
                </div>

                <!-- CATATAN SISWA -->
                <div class="tgs-form-group">
                    <label class="tgs-form-label">Catatan Pengantar untuk Guru (Opsional):</label>
                    <textarea id="inputCatatanSiswa" name="catatan_siswa" class="tgs-form-control" rows="3" placeholder="Tuliskan catatan singkat mengenai karya Anda kepada guru pengampu..."></textarea>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 10px;">
                    <button type="button" class="btn-tgs btn-tgs--outline" onclick="closeModal('modalSubmitTugas')">
                        <span>Batal</span>
                    </button>
                    <button type="submit" class="btn-tgs btn-tgs--primary" id="btnSubmitWork" style="flex: 1; padding: 12px;">
                        <iconify-icon icon="solar:check-circle-bold" style="font-size: 18px;"></iconify-icon>
                        <span id="btnSubmitWorkText">Kirim & Simpan Berkas Tugas</span>
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

<!-- ==============================================================================
 * MODAL REKAPITULASI TUGAS SISWA (PRINTABLE & SUMMARY VIEW)
 * ============================================================================== -->
<div class="tgs-modal-backdrop" id="modalRekapTugas">
    <div class="tgs-modal-card tgs-modal-card--lg" style="max-height: 90vh;">
        <div class="tgs-modal-header">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:38px; height:38px; border-radius:10px; background:rgba(13,122,135,0.12); color:var(--lumina-teal); display:flex; align-items:center; justify-content:center; font-size:20px;">
                    <iconify-icon icon="solar:printer-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="tgs-modal-title">Ringkasan & Rekapitulasi Tugas Terpilih</h3>
                    <div style="font-size:12px; color:#64748B;">Daftar ringkasan penugasan untuk portofolio & arsip belajar siswa</div>
                </div>
            </div>
            <button type="button" class="tgs-modal-close" onclick="closeModal('modalRekapTugas')">&times;</button>
        </div>
        <div class="tgs-modal-body" id="rekapPrintArea" style="padding: 24px; background: #FFFFFF;">
            <!-- Printable Header -->
            <div style="border-bottom: 2px solid var(--lumina-navy); padding-bottom: 12px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h2 style="font-size: 18px; font-weight: 800; color: var(--lumina-navy); margin: 0 0 4px; font-family: 'Outfit', sans-serif;">LEMBAR REKAPITULASI PENUGASAN SANTRI</h2>
                    <div style="font-size: 13px; color: #475569;">
                        Nama: <strong><?= htmlspecialchars($namaSiswa) ?></strong> | NISN: <strong><?= htmlspecialchars($nisnSiswa) ?></strong> | Kelas: <strong><?= htmlspecialchars($namaKelas) ?></strong>
                    </div>
                </div>
                <div style="font-size: 12px; color: #64748B; text-align: right;">
                    Dicetak pada: <strong><?= date('d M Y, H:i') ?> WIB</strong>
                </div>
            </div>

            <!-- Table of selected tasks -->
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 12.5px;" id="rekapTable">
                    <thead>
                        <tr style="background: #F1F5F9; border-bottom: 2px solid #CBD5E1;">
                            <th style="padding: 10px 8px; text-align: center; width: 40px;">No</th>
                            <th style="padding: 10px 8px; text-align: left;">Judul Tugas</th>
                            <th style="padding: 10px 8px; text-align: left;">Mata Pelajaran</th>
                            <th style="padding: 10px 8px; text-align: center; width: 90px;">Jenis</th>
                            <th style="padding: 10px 8px; text-align: left; width: 150px;">Tenggat</th>
                            <th style="padding: 10px 8px; text-align: center; width: 120px;">Status</th>
                            <th style="padding: 10px 8px; text-align: center; width: 80px;">Nilai</th>
                        </tr>
                    </thead>
                    <tbody id="rekapTableBody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 20px; padding: 12px 16px; background: #F8FAFC; border: 1px dashed #CBD5E1; border-radius: 8px; font-size: 12px; color: #64748B;">
                <span>Catatan: Rekap ini mencakup tugas-tugas aktif yang dipilih secara mandiri oleh siswa untuk keperluan portofolio atau monitoring mandiri.</span>
            </div>
        </div>
        <div style="padding: 16px 24px; border-top: 1px solid var(--lumina-border); background: #F8FAFC; display: flex; justify-content: flex-end; gap: 10px;">
            <button type="button" class="btn-tgs btn-tgs--outline" onclick="closeModal('modalRekapTugas')">Tutup</button>
            <button type="button" class="btn-tgs btn-tgs--primary" onclick="printRekapArea()">
                <iconify-icon icon="solar:printer-bold"></iconify-icon>
                <span>Cetak / Simpan PDF</span>
            </button>
        </div>
    </div>
</div>

<!-- TOAST WRAPPER -->
<div class="tgs-toast-container" id="tgsToastWrap"></div>

<!-- LUMINA DIALOG MODAL BACKDROP -->
<div class="tgs-modal-backdrop" id="luminaDialogBackdrop" style="z-index: 100001;">
    <div class="tgs-modal-card" style="max-width: 420px; text-align: center; padding: 24px;">
        <div id="luminaDialogIcon" style="font-size: 48px; margin-bottom: 12px;"></div>
        <h4 id="luminaDialogTitle" style="font-size: 18px; font-weight: 800; color: var(--lumina-navy); margin: 0 0 8px;"></h4>
        <p id="luminaDialogMessage" style="font-size: 13.5px; color: #64748B; margin-bottom: 20px; line-height: 1.5;"></p>
        <div id="luminaDialogActions" style="display: flex; gap: 10px; justify-content: center;"></div>
    </div>
</div>

<script>
// ==============================================================================
// JAVASCRIPT LOGIC — PENUGASAN SISWA & GDRIVE VIEWER TANPA DOWNLOAD
// ==============================================================================

// 1. LUMINA TOAST & DIALOG SYSTEM
function luminaToast(title, msg, type = 'info') {
    const _ilmType = (type === 'warn') ? 'warning' : (type || 'info');
    if (window.Ilmuna) return Ilmuna.toast(_ilmType, title, msg, 4500);
    const wrap = document.getElementById('tgsToastWrap');
    if (!wrap) return;
    const el = document.createElement('div');
    el.className = `tgs-toast-item toast-${type}`;
    let icon = 'solar:info-circle-bold';
    if (type === 'success') icon = 'solar:check-circle-bold';
    if (type === 'warn') icon = 'solar:danger-triangle-bold';
    if (type === 'error') icon = 'solar:close-circle-bold';
    
    el.innerHTML = `
        <iconify-icon icon="${icon}" style="font-size:24px; flex-shrink:0;"></iconify-icon>
        <div style="flex:1;">
            <div style="font-weight:700; font-size:13.5px; color:var(--lumina-navy);">${escapeHtml(title)}</div>
            <div style="font-size:12px; color:#64748B; margin-top:2px;">${escapeHtml(msg)}</div>
        </div>
    `;
    wrap.appendChild(el);
    setTimeout(() => {
        el.style.opacity = '0';
        el.style.transform = 'translateX(40px)';
        el.style.transition = 'all 0.3s ease';
        setTimeout(() => el.remove(), 300);
    }, 4500);
}

function luminaAlert(title, message, type = 'info') {
    return new Promise(resolve => {
        const _ilmType = (type === 'warn') ? 'warning' : (type || 'info');
        if (window.Ilmuna) {
            return Ilmuna.alert(_ilmType, title, message, 'OK', false, () => resolve(true));
        }
        const bd = document.getElementById('luminaDialogBackdrop');
        const iconEl = document.getElementById('luminaDialogIcon');
        const titEl = document.getElementById('luminaDialogTitle');
        const msgEl = document.getElementById('luminaDialogMessage');
        const actEl = document.getElementById('luminaDialogActions');

        let iconName = 'solar:info-circle-bold';
        let iconColor = 'var(--lumina-teal)';
        if (type === 'success') { iconName = 'solar:check-circle-bold'; iconColor = '#059669'; }
        if (type === 'warn')    { iconName = 'solar:danger-triangle-bold'; iconColor = '#F59E0B'; }
        if (type === 'error')   { iconName = 'solar:close-circle-bold'; iconColor = '#E11D48'; }

        iconEl.innerHTML = `<iconify-icon icon="${iconName}" style="color:${iconColor};"></iconify-icon>`;
        titEl.textContent = title;
        msgEl.textContent = message;
        actEl.innerHTML = `<button type="button" class="btn-tgs btn-tgs--primary" id="btnDialogOk" style="min-width:110px;">OK</button>`;

        bd.classList.add('active');
        document.getElementById('btnDialogOk').onclick = () => {
            bd.classList.remove('active');
            resolve(true);
        };
    });
}

function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('active');
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[m]);
}

// 2. MODAL PETUNJUK & DETAIL TUGAS
let currentDetailData = null;

async function openDetailModal(idTugas) {
    openModal('modalDetailTugas');
    document.getElementById('detailTaskTitle').textContent = 'Memuat Petunjuk Tugas...';
    document.getElementById('detailTaskSub').textContent = 'Harap tunggu...';
    document.getElementById('detailTaskInstructions').textContent = 'Memuat...';
    document.getElementById('detailViewerStage').innerHTML = `<div style="color:#94A3B8; font-size:13px;"><iconify-icon icon="line-md:loading-loop" style="font-size:24px;"></iconify-icon><div style="margin-top:6px;">Menyiapkan pratinjau aman...</div></div>`;

    try {
        const fd = new FormData();
        fd.append('action', 'get_task_detail');
        fd.append('id_tugas', idTugas);

        const res = await fetch('tugas', { method: 'POST', body: fd });
        const json = await res.json();

        if (!json.success || !json.task) {
            luminaAlert('Peringatan', json.message || 'Gagal memuat detail tugas.', 'warn');
            closeModal('modalDetailTugas');
            return;
        }

        const t = json.task;
        currentDetailData = json;

        document.getElementById('detailTaskTitle').textContent = t.judul_tugas;
        document.getElementById('detailTaskSub').textContent = `${t.nama_mata_pelajaran} • Guru: ${t.nama_guru}`;
        document.getElementById('detailTaskInstructions').textContent = t.deskripsi_instruksi || t.deskripsi_tugas || '- Tidak ada instruksi tertulis -';

        // Tautan Sumber Belajar Guru
        const srcWrap = document.getElementById('detailSourceLinkWrap');
        const srcAnchor = document.getElementById('detailSourceLinkAnchor');
        const srcBtn = document.getElementById('detailSourceLinkBtn');
        if (t.link_sumber_tugas && t.link_sumber_tugas.trim() !== '') {
            const rawLink = t.link_sumber_tugas.trim();
            const urlLink = /^https?:\/\//i.test(rawLink) ? rawLink : 'https://' + rawLink;
            if (srcAnchor) {
                srcAnchor.href = urlLink;
                srcAnchor.textContent = rawLink;
                srcAnchor.title = rawLink;
            }
            if (srcBtn) {
                srcBtn.href = urlLink;
            }
            if (srcWrap) {
                srcWrap.style.display = 'block';
            }
        } else {
            if (srcWrap) {
                srcWrap.style.display = 'none';
            }
        }

        document.getElementById('detailTipePengumpulan').textContent = t.tipe_pengumpulan || 'Dokumen / Berkas';
        document.getElementById('detailFormatFile').textContent = t.format_file_diizinkan || t.format_file_dibolehkan || 'PDF, DOCX, ZIP, JPG';
        document.getElementById('detailMaxMb').textContent = '1 MB (Penyimpanan Server Lokal)';
        document.getElementById('detailDeadline').textContent = t.tenggat_waktu ? new Date(t.tenggat_waktu).toLocaleString('id-ID') : 'Tidak Terbatas';

        // Kelompok Info
        const grpBox = document.getElementById('detailGroupBox');
        const grpList = document.getElementById('detailGroupMembersList');
        if (t.jenis_penugasan === 'Kelompok' && json.group) {
            grpBox.style.display = 'block';
            const grp = json.group;
            const namaKetua = grp.nama_ketua || 'Belum ditentukan';
            let roleBadge = json.is_me_ketua 
                ? `<span class="tgs-badge" style="background:#FEF3C7; color:#B45309; font-weight:800;">👑 Anda: Ketua Kelompok</span>`
                : `<span class="tgs-badge" style="background:#E0E7FF; color:#4338CA; font-weight:700;">👤 Anda: Anggota</span>`;
            
            let anggotaChips = (grp.anggota || []).map(a => {
                const isKet = (parseInt(a.id) === parseInt(grp.id_ketua_kelompok));
                const isMe = (parseInt(a.id) === <?= (int)$idSiswa ?>);
                const bg = isKet ? '#FEF3C7' : (isMe ? '#E0F2FE' : '#FFFFFF');
                const border = isKet ? '#FDE68A' : (isMe ? '#BAE6FD' : '#E2E8F0');
                const col = isKet ? '#92400E' : (isMe ? '#0369A1' : '#334155');
                return `<span style="background:${bg}; border:1px solid ${border}; color:${col}; padding:3px 8px; border-radius:6px; font-size:11.5px; font-weight:${(isKet||isMe)?'700':'500'};">
                    ${isKet ? '👑 ' : ''}${escapeHtml(a.nama_lengkap)}${isMe ? ' <small>(Anda)</small>' : ''}
                </span>`;
            }).join(' ');

            grpList.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <div style="font-size:13.5px; font-weight:800; color:#6B21A8;">${escapeHtml(grp.nama_kelompok)}</div>
                    ${roleBadge}
                </div>
                <div style="background:#FFFFFF; border:1px solid #E9D5FF; border-radius:8px; padding:8px 10px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center;">
                    <div>👑 Ketua Kelompok: <strong>${escapeHtml(namaKetua)}</strong></div>
                    ${json.is_me_ketua ? '<span style="font-size:10px; color:#059669; font-weight:800; background:#DCFCE7; padding:2px 6px; border-radius:4px;">Hak Unggah</span>' : ''}
                </div>
                <div style="font-size:11px; font-weight:700; color:#7E22CE; margin-bottom:4px; text-transform:uppercase;">Daftar Anggota Kelompok:</div>
                <div style="display:flex; flex-wrap:wrap; gap:6px;">${anggotaChips}</div>
            `;
        } else {
            grpBox.style.display = 'none';
        }

        // Tombol Submit
        const btnSubmit = document.getElementById('btnGoToSubmit');
        const lblSubmit = document.getElementById('labelGoToSubmit');
        
        if (t.jenis_penugasan === 'Kelompok' && !json.is_me_ketua) {
            if (json.submission) {
                lblSubmit.textContent = 'Lihat Berkas yang Dikumpulkan Ketua';
                btnSubmit.disabled = false;
                btnSubmit.style.opacity = '1';
                btnSubmit.style.cursor = 'pointer';
                btnSubmit.onclick = () => {
                    closeModal('modalDetailTugas');
                    openSubmissionViewer(json.submission.id, t.judul_tugas);
                };
            } else {
                lblSubmit.textContent = '🔒 Hanya Ketua Kelompok yang Dapat Mengumpulkan';
                btnSubmit.disabled = true;
                btnSubmit.style.opacity = '0.7';
                btnSubmit.style.cursor = 'not-allowed';
                btnSubmit.onclick = null;
            }
        } else {
            btnSubmit.disabled = false;
            btnSubmit.style.opacity = '1';
            btnSubmit.style.cursor = 'pointer';
            btnSubmit.onclick = () => {
                closeModal('modalDetailTugas');
                openSubmitModal(idTugas);
            };
            lblSubmit.textContent = json.submission ? 'Perbarui Jawaban Tugas' : (t.jenis_penugasan === 'Kelompok' ? 'Kumpulkan Tugas (Ketua Kelompok)' : 'Kumpulkan Tugas Sekarang');
        }

        // Render Viewer Dokumen Lampiran Guru (Strict View-Only)
        renderTeacherAttachmentViewer(t);

    } catch (err) {
        console.error('Gagal mengambil rincian tugas:', err);
        luminaAlert('Kesalahan', 'Gagal memuat rincian tugas: ' + err.message, 'error');
        closeModal('modalDetailTugas');
    }
}

function renderTeacherAttachmentViewer(t) {
    const stage = document.getElementById('detailViewerStage');
    const label = document.getElementById('detailViewerFileName');

    if (!t.file_lampiran_tugas && !t.gdrive_file_id) {
        if (t.link_sumber_tugas && t.link_sumber_tugas.trim() !== '') {
            const rawLink = t.link_sumber_tugas.trim();
            const urlLink = /^https?:\/\//i.test(rawLink) ? rawLink : 'https://' + rawLink;
            label.textContent = 'Tautan Sumber Belajar Guru';
            stage.innerHTML = `
                <div style="padding: 30px 20px; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; min-height: 280px;">
                    <div style="width: 58px; height: 58px; border-radius: 16px; background: #E0F2FE; color: #0284C7; display: flex; align-items: center; justify-content: center; margin-bottom: 14px; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.15);">
                        <iconify-icon icon="solar:link-circle-bold" style="font-size: 32px;"></iconify-icon>
                    </div>
                    <h4 style="font-size: 15.5px; font-weight: 800; color: #0F172A; margin: 0 0 6px;">Tautan Sumber Belajar Tersedia</h4>
                    <p style="font-size: 12.5px; color: #64748B; max-width: 360px; margin: 0 0 16px; line-height: 1.5;">Guru menyematkan rujukan materi pembelajaran online sebagai panduan pengerjaan tugas ini.</p>
                    <div style="max-width: 90%; background: #FFFFFF; border: 1px solid #BAE6FD; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; word-break: break-all; font-size: 12.5px; font-weight: 600; color: #0284C7;">
                        ${escapeHtml(rawLink)}
                    </div>
                    <a href="${escapeHtml(urlLink)}" target="_blank" rel="noopener noreferrer" class="btn-tgs btn-tgs--primary" style="padding: 10px 20px; font-size: 13px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);">
                        <span>Buka Tautan Sumber Belajar</span>
                        <iconify-icon icon="solar:arrow-right-up-bold" style="font-size: 16px;"></iconify-icon>
                    </a>
                </div>
            `;
            return;
        }
        label.textContent = 'Tidak Ada Berkas Lampiran';
        stage.innerHTML = `<div style="color:#94A3B8; font-size:13px; text-align:center;">Guru tidak menyertakan berkas lampiran materi untuk tugas ini.</div>`;
        return;
    }

    const fileName = t.file_lampiran_tugas ? t.file_lampiran_tugas.split('/').pop() : 'Materi_Lampiran_Tugas';
    label.textContent = fileName;
    const ext = fileName.split('.').pop().toLowerCase();
    const streamUrl = `tugas?stream=tugas&id=${t.id}`;

    // PDF Canvas View-Only
    if (ext === 'pdf') {
        stage.innerHTML = `<canvas id="canvasPdfViewerStudent" class="tgs-viewer-canvas"></canvas>`;
        loadPdfCanvasView(streamUrl, 'canvasPdfViewerStudent');
        return;
    }

    // Google Drive Iframe Terproteksi
    if (t.gdrive_file_id) {
        stage.innerHTML = `
            <iframe src="https://drive.google.com/file/d/${t.gdrive_file_id}/preview?rm=minimal" 
                    style="width:100%; height:100%; border:none; border-radius:8px;" 
                    sandbox="allow-scripts allow-same-origin">
            </iframe>
        `;
        return;
    }

    // Gambar
    if (['jpg', 'jpeg', 'png', 'webp'].includes(ext)) {
        stage.innerHTML = `<img src="${streamUrl}" alt="Lampiran Tugas" class="tgs-viewer-canvas" style="max-height:65vh; pointer-events:none;" oncontextmenu="return false;">`;
        return;
    }

    // Fallback
    stage.innerHTML = `<div style="color:#94A3B8; font-size:13px; text-align:center;">Berkas berformat .${ext.toUpperCase()} disiapkan untuk ditinjau.</div>`;
}

// 3. PDF.JS CANVAS RENDERER TANPA DOWNLOAD
async function loadPdfCanvasView(url, canvasId) {
    if (!window.pdfjsLib) return;
    try {
        const loadingTask = pdfjsLib.getDocument({ url: url, withCredentials: true });
        const pdf = await loadingTask.promise;
        const page = await pdf.getPage(1);
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        const context = canvas.getContext('2d');
        const viewport = page.getViewport({ scale: 1.25 });

        canvas.height = viewport.height;
        canvas.width = viewport.width;

        await page.render({ canvasContext: context, viewport: viewport }).promise;
    } catch (e) {
        console.error('Gagal render PDF canvas:', e);
    }
}

// 4. MODAL PENGUMPULAN TUGAS SISWA
async function openSubmitModal(idTugas) {
    document.getElementById('submitTaskId').value = idTugas;
    document.getElementById('fileInputTugas').value = '';
    document.getElementById('dropzoneLabel').textContent = 'Klik atau Seret Berkas ke Area Ini';
    document.getElementById('inputLinkTugas').value = '';
    document.getElementById('inputCatatanSiswa').value = '';

    openModal('modalSubmitTugas');

    try {
        const fd = new FormData();
        fd.append('action', 'get_task_detail');
        fd.append('id_tugas', idTugas);

        const res = await fetch('tugas', { method: 'POST', body: fd });
        const json = await res.json();

        if (json.success && json.task) {
            const t = json.task;
            document.getElementById('submitModalTitle').textContent = `Pengumpulan: ${t.judul_tugas}`;
            document.getElementById('submitModalSub').textContent = `${t.nama_mata_pelajaran} • ${t.nama_kelas}`;
            document.getElementById('labelMaxMbSubmit').textContent = '1';

            const grpNotice = document.getElementById('submitGroupNotice');
            const grpNoticeText = document.getElementById('submitGroupNoticeText');
            if (t.jenis_penugasan === 'Kelompok') {
                if (!json.is_me_ketua) {
                    closeModal('modalSubmitTugas');
                    const namaKetua = (json.group && json.group.nama_ketua) ? json.group.nama_ketua : 'Ketua Kelompok Anda';
                    luminaAlert('Akses Dibatasi', `Hanya Ketua Kelompok (${namaKetua}) yang memiliki hak untuk mengunggah tugas kelompok ini. Anggota kelompok dapat meninjau berkas setelah dikumpulkan oleh ketua.`, 'warn');
                    return;
                }
                grpNotice.style.display = 'block';
                grpNoticeText.innerHTML = `👑 Anda mengunggah tugas sebagai <strong>Ketua Kelompok</strong> mewakili "<strong>${escapeHtml(json.group ? json.group.nama_kelompok : '')}</strong>". Seluruh anggota kelompok otomatis tersinkronisasi.`;
            } else {
                grpNotice.style.display = 'none';
            }

            // Tautan Sumber Belajar Guru Notice
            const srcNotice = document.getElementById('submitSourceLinkNotice');
            const srcNoticeAnchor = document.getElementById('submitSourceLinkAnchor');
            const srcNoticeBtn = document.getElementById('submitSourceLinkBtn');
            if (t.link_sumber_tugas && t.link_sumber_tugas.trim() !== '') {
                const rawLink = t.link_sumber_tugas.trim();
                const urlLink = /^https?:\/\//i.test(rawLink) ? rawLink : 'https://' + rawLink;
                if (srcNoticeAnchor) {
                    srcNoticeAnchor.href = urlLink;
                    srcNoticeAnchor.textContent = rawLink;
                    srcNoticeAnchor.title = rawLink;
                }
                if (srcNoticeBtn) {
                    srcNoticeBtn.href = urlLink;
                }
                if (srcNotice) {
                    srcNotice.style.display = 'block';
                }
            } else {
                if (srcNotice) {
                    srcNotice.style.display = 'none';
                }
            }

            // Jika sudah pernah mengumpulkan, isi field lama
            if (json.submission) {
                const s = json.submission;
                document.getElementById('inputLinkTugas').value = s.link_tugas || '';
                document.getElementById('inputCatatanSiswa').value = s.catatan_siswa || '';
                if (s.file_nama_asli) {
                    document.getElementById('dropzoneLabel').textContent = `Berkas Tersimpan: ${s.file_nama_asli} (Klik untuk mengganti)`;
                }
                document.getElementById('btnSubmitWorkText').textContent = 'Perbarui Jawaban & Berkas Tugas';
            } else {
                document.getElementById('btnSubmitWorkText').textContent = 'Kirim & Simpan Berkas Tugas';
            }
        }
    } catch (e) {
        console.error('Gagal memuat form pengumpulan:', e);
    }
}

function onFileSelected(inp) {
    if (inp.files && inp.files[0]) {
        const f = inp.files[0];
        const sizeMb = (f.size / (1024 * 1024)).toFixed(2);
        if (f.size > 1024 * 1024) {
            luminaAlert('Ukuran Berkas Terlalu Besar', `Berkas "${escapeHtml(f.name)}" berukuran ${sizeMb} MB. Maksimal ukuran berkas yang diizinkan adalah 1 MB untuk penyimpanan server lokal.`, 'warn');
            inp.value = '';
            document.getElementById('dropzoneLabel').textContent = 'Klik atau Seret Berkas ke Area Ini';
            return;
        }
        const sizeKb = (f.size / 1024).toFixed(0);
        document.getElementById('dropzoneLabel').innerHTML = `<strong>${escapeHtml(f.name)}</strong> (${sizeKb} KB) — <span style="color:#059669; font-weight:700;">Siap Disimpan</span>`;
    }
}

// Drag & drop support
const dropzone = document.getElementById('fileDropzone');
if (dropzone) {
    ['dragenter', 'dragover'].forEach(name => {
        dropzone.addEventListener(name, (e) => { e.preventDefault(); dropzone.classList.add('dragover'); }, false);
    });
    ['dragleave', 'drop'].forEach(name => {
        dropzone.addEventListener(name, (e) => { e.preventDefault(); dropzone.classList.remove('dragover'); }, false);
    });
    dropzone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files && files.length > 0) {
            document.getElementById('fileInputTugas').files = files;
            onFileSelected(document.getElementById('fileInputTugas'));
        }
    }, false);
}

window.openSubmissionViewer = async function(idSub, title) {
    openModal('modalDetailTugas');
    document.getElementById('detailTaskTitle').textContent = `Berkas Kelompok: ${title}`;
    document.getElementById('detailTaskSub').textContent = 'Pratinjau Berkas Kelompok (View-Only)';
    const stage = document.getElementById('detailViewerStage');
    const label = document.getElementById('detailViewerFileName');
    label.textContent = 'Berkas_Tugas_Kelompok';
    stage.innerHTML = `<div style="color:#94A3B8; font-size:13px; text-align:center;"><iconify-icon icon="line-md:loading-loop" style="font-size:26px;"></iconify-icon><div style="margin-top:6px;">Menyiapkan pratinjau berkas kelompok...</div></div>`;

    const streamUrl = `tugas?stream=submission&id=${idSub}`;
    stage.innerHTML = `<canvas id="canvasSubViewerDetail" class="tgs-viewer-canvas"></canvas>`;
    loadPdfCanvasView(streamUrl, 'canvasSubViewerDetail');
};

// 5. FILTERING & LAZY LOAD LOGIC (SMOOTH & ZERO FULL PAGE RELOAD)
let currentActiveStatus = '<?= htmlspecialchars($filterStatus) ?>';
let searchDebounceTimer = null;

function setTabFilter(status, btn) {
    currentActiveStatus = status;
    document.querySelectorAll('.tgs-tab-pill').forEach(p => p.classList.remove('active'));
    if (btn) btn.classList.add('active');
    loadTasksAjax(false);
}

function onFilterChange() {
    loadTasksAjax(false);
}

function onSearchInput() {
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(() => {
        loadTasksAjax(false);
    }, 350);
}

function resetFilters() {
    const selMapel = document.getElementById('filterMapel');
    const selJenis = document.getElementById('filterJenis');
    const inpSearch = document.getElementById('filterSearch');
    if (selMapel) selMapel.value = '0';
    if (selJenis) selJenis.value = 'all';
    if (inpSearch) inpSearch.value = '';
    currentActiveStatus = 'all';

    document.querySelectorAll('.tgs-tab-pill').forEach(p => {
        if (p.getAttribute('data-status') === 'all') p.classList.add('active');
        else p.classList.remove('active');
    });

    loadTasksAjax(false);
}

async function loadTasksAjax(isQuiet = false, targetHighlightId = null) {
    const container = document.getElementById('tasksCardsContainer');
    if (!container) return;

    const mapel = document.getElementById('filterMapel') ? document.getElementById('filterMapel').value : '0';
    const jenis = document.getElementById('filterJenis') ? document.getElementById('filterJenis').value : 'all';
    const q = document.getElementById('filterSearch') ? document.getElementById('filterSearch').value.trim() : '';
    const status = currentActiveStatus || 'all';

    // Update reset button visibility
    const btnReset = document.getElementById('btnResetFilter');
    if (btnReset) {
        btnReset.style.display = (mapel !== '0' || jenis !== 'all' || q !== '') ? 'inline-flex' : 'none';
    }

    if (!isQuiet) {
        container.style.opacity = '0.45';
        container.style.transition = 'opacity 0.2s ease';
    }

    try {
        const queryParams = new URLSearchParams({
            ajax: '1',
            status: status,
            mapel: mapel,
            jenis: jenis,
            q: q
        });

        const res = await fetch(`tugas?${queryParams.toString()}`);
        const data = await res.json();

        if (data.success) {
            container.innerHTML = data.html;
            container.style.opacity = '1';

            // Refresh status checkbox dan prioritas
            applyPriorityBookmarks();
            onStudentCheckboxChange();
            initStudentPagination();

            // Update counter badges
            if (data.counts) {
                if (document.getElementById('count_all')) document.getElementById('count_all').textContent = data.counts.all || 0;
                if (document.getElementById('count_pending')) document.getElementById('count_pending').textContent = data.counts.pending || 0;
                if (document.getElementById('count_submitted')) document.getElementById('count_submitted').textContent = data.counts.submitted || 0;
                if (document.getElementById('count_graded')) document.getElementById('count_graded').textContent = data.counts.graded || 0;

                const revCount = data.counts.revision || 0;
                const revTab = document.getElementById('tabPillRevision');
                if (revTab) {
                    revTab.style.display = revCount > 0 ? 'inline-flex' : 'none';
                    if (document.getElementById('count_revision')) document.getElementById('count_revision').textContent = revCount;
                }
            }

            // Highlight target card smoothly (e.g. newly submitted task)
            if (targetHighlightId) {
                const targetCard = document.getElementById(`taskCard_${targetHighlightId}`);
                if (targetCard) {
                    targetCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    targetCard.style.transition = 'all 0.5s ease';
                    targetCard.style.boxShadow = '0 0 0 3px #10B981, 0 10px 25px -5px rgba(16, 185, 129, 0.25)';
                    setTimeout(() => {
                        targetCard.style.boxShadow = '';
                    }, 3000);
                }
            }

            // Sync URL without refreshing
            const newUrl = new URL(window.location);
            if (status !== 'all') newUrl.searchParams.set('status', status); else newUrl.searchParams.delete('status');
            if (mapel !== '0') newUrl.searchParams.set('mapel', mapel); else newUrl.searchParams.delete('mapel');
            if (jenis !== 'all') newUrl.searchParams.set('jenis', jenis); else newUrl.searchParams.delete('jenis');
            if (q) newUrl.searchParams.set('q', q); else newUrl.searchParams.delete('q');
            window.history.replaceState({}, '', newUrl);
        }
    } catch (err) {
        console.error('Gagal memuat tugas:', err);
        container.style.opacity = '1';
    }
}

// 6. SUBMIT PENGUMPULAN TUGAS VIA AJAX (TANPA RELOAD HALAMAN!)
async function handleFormSubmission(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitWork');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Menyimpan berkas ke server...`;

    try {
        const form = document.getElementById('formSubmitTugas');
        const fd = new FormData(form);
        fd.append('action', 'submit_tugas');

        const submittedTaskId = document.getElementById('submitIdTugas') ? document.getElementById('submitIdTugas').value : null;

        const res = await fetch('tugas', { method: 'POST', body: fd });
        const json = await res.json();

        if (json.success) {
            closeModal('modalSubmitTugas');
            luminaToast('Alhamdulillah!', json.message, 'success');
            // Lazy update tugas tanpa render ulang ataupun reload halaman
            await loadTasksAjax(true, submittedTaskId);
        } else {
            luminaAlert('Gagal Mengumpulkan Tugas', json.message || 'Terjadi kesalahan sistem.', 'error');
        }
    } catch (err) {
        console.error('Kesalahan pengumpulan tugas:', err);
        luminaAlert('Kesalahan Jaringan', 'Gagal mengirim berkas: ' + err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}

// --------------------------------------------------------------------------
// 7. AKSI MASSAL & PRIORITAS TUGAS SISWA
// --------------------------------------------------------------------------
function getPriorityBookmarks() {
    try {
        const stored = localStorage.getItem('lumina_student_priority_tasks');
        return stored ? JSON.parse(stored) : [];
    } catch(e) {
        return [];
    }
}

function savePriorityBookmarks(arr) {
    try {
        localStorage.setItem('lumina_student_priority_tasks', JSON.stringify(arr));
    } catch(e) {}
}

function applyPriorityBookmarks() {
    const list = getPriorityBookmarks();
    document.querySelectorAll('.tgs-card').forEach(card => {
        const id = parseInt(card.getAttribute('data-task-id'));
        const badge = document.getElementById(`priorityBadge_${id}`);
        if (list.includes(id)) {
            card.classList.add('is-priority');
            if (badge) badge.style.display = 'inline-flex';
        } else {
            card.classList.remove('is-priority');
            if (badge) badge.style.display = 'none';
        }
    });
}

function onStudentCheckboxChange() {
    const checked = document.querySelectorAll('.tgs-chk-student:checked');
    const all = document.querySelectorAll('.tgs-chk-student');
    const count = checked.length;
    const bar = document.getElementById('bulkBarStudent');
    const countBadge = document.getElementById('bulkStudentCount');
    const summaryText = document.getElementById('selectedStudentSummary');
    const masterChk = document.getElementById('chkSelectAllStudent');

    if (countBadge) countBadge.textContent = count;
    if (summaryText) summaryText.textContent = `${count} tugas dipilih`;
    if (bar) {
        if (count > 0) {
            bar.classList.add('is-visible');
        } else {
            bar.classList.remove('is-visible');
        }
    }
    if (masterChk) {
        masterChk.checked = (all.length > 0 && checked.length === all.length);
        masterChk.indeterminate = (checked.length > 0 && checked.length < all.length);
    }
}

function toggleSelectAllStudent(master) {
    const checkboxes = document.querySelectorAll('.tgs-chk-student');
    checkboxes.forEach(cb => { cb.checked = master.checked; });
    onStudentCheckboxChange();
}

function clearStudentSelection() {
    const checkboxes = document.querySelectorAll('.tgs-chk-student');
    checkboxes.forEach(cb => { cb.checked = false; });
    const master = document.getElementById('chkSelectAllStudent');
    if (master) { master.checked = false; master.indeterminate = false; }
    onStudentCheckboxChange();
}

function toggleBookmarkSelected() {
    const checked = Array.from(document.querySelectorAll('.tgs-chk-student:checked'));
    if (checked.length === 0) {
        luminaAlert('Pilih Tugas', 'Pilih minimal satu penugasan untuk ditandai prioritas.', 'warn');
        return;
    }
    let list = getPriorityBookmarks();
    const ids = checked.map(c => parseInt(c.value));

    const allPrioritized = ids.every(id => list.includes(id));
    if (allPrioritized) {
        list = list.filter(id => !ids.includes(id));
        luminaToast('Prioritas Dihapus', `${ids.length} tugas dihapus dari daftar prioritas.`, 'info');
    } else {
        ids.forEach(id => {
            if (!list.includes(id)) list.push(id);
        });
        luminaToast('Ditandai Prioritas', `${ids.length} tugas berhasil ditandai sebagai prioritas belajar.`, 'success');
    }
    savePriorityBookmarks(list);
    applyPriorityBookmarks();
}

function openRekapModal() {
    const checked = Array.from(document.querySelectorAll('.tgs-chk-student:checked'));
    if (checked.length === 0) {
        luminaAlert('Pilih Tugas', 'Pilih minimal satu penugasan untuk dicetak ringkasannya.', 'warn');
        return;
    }

    const tbody = document.getElementById('rekapTableBody');
    if (!tbody) return;
    tbody.innerHTML = '';

    checked.forEach((chk, index) => {
        const card = chk.closest('.tgs-card');
        if (!card) return;

        const title = card.getAttribute('data-task-title') || '-';
        const mapel = card.getAttribute('data-mapel') || '-';
        const jenis = card.getAttribute('data-jenis') || '-';
        const deadline = card.getAttribute('data-deadline') || '-';
        const status = card.getAttribute('data-status') || '-';
        const score = card.getAttribute('data-score') || '-';

        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #E2E8F0';
        tr.innerHTML = `
            <td style="padding: 10px 8px; text-align: center; color: #64748B;">${index + 1}</td>
            <td style="padding: 10px 8px; font-weight: 700; color: var(--lumina-navy);">${escapeHtml(title)}</td>
            <td style="padding: 10px 8px; color: #475569;">${escapeHtml(mapel)}</td>
            <td style="padding: 10px 8px; text-align: center;"><span class="tgs-badge" style="font-size: 11px;">${escapeHtml(jenis)}</span></td>
            <td style="padding: 10px 8px; color: #334155;">${escapeHtml(deadline)}</td>
            <td style="padding: 10px 8px; text-align: center;"><span class="tgs-badge" style="font-size: 11px;">${escapeHtml(status)}</span></td>
            <td style="padding: 10px 8px; text-align: center; font-weight: 800; color: #15803D;">${escapeHtml(score)}</td>
        `;
        tbody.appendChild(tr);
    });

    openModal('modalRekapTugas');
}

function printRekapArea() {
    window.print();
}

// --------------------------------------------------------------------------
// 9. PAGINASI KARTU TUGAS SISWA
// --------------------------------------------------------------------------
let studentCurrentPage = 1;
const studentPageSize = 6;

function initStudentPagination() {
    studentCurrentPage = 1;
    applyStudentPagination();
}

function applyStudentPagination() {
    const container = document.getElementById('tasksCardsContainer');
    if (!container) return;
    const cards = Array.from(container.querySelectorAll('.tgs-card'));
    const totalItems = cards.length;
    const pagBar = document.getElementById('studentPaginationBar');
    if (!pagBar) return;

    if (totalItems <= studentPageSize) {
        cards.forEach(c => c.style.display = '');
        pagBar.innerHTML = '';
        pagBar.style.display = 'none';
        return;
    }

    const totalPages = Math.ceil(totalItems / studentPageSize);
    if (studentCurrentPage > totalPages) studentCurrentPage = totalPages;
    if (studentCurrentPage < 1) studentCurrentPage = 1;

    const startIdx = (studentCurrentPage - 1) * studentPageSize;
    const endIdx = startIdx + studentPageSize;

    cards.forEach((c, idx) => {
        if (idx >= startIdx && idx < endIdx) {
            c.style.display = '';
        } else {
            c.style.display = 'none';
        }
    });

    // Render kontrol paginasi
    let pagHtml = `
        <div style="font-size: 12.5px; color: #64748B; font-weight: 600;">
            Menampilkan <strong>${startIdx + 1} - ${Math.min(endIdx, totalItems)}</strong> dari <strong>${totalItems}</strong> Tugas
        </div>
        <div class="tgs-pag-controls" style="display: flex; align-items: center; gap: 6px;">
            <button type="button" class="tgs-pag-btn" onclick="goToStudentPage(${studentCurrentPage - 1})" ${studentCurrentPage === 1 ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : ''}>
                <iconify-icon icon="solar:alt-arrow-left-linear"></iconify-icon>
                <span>Sebelumnya</span>
            </button>
    `;

    for (let p = 1; p <= totalPages; p++) {
        if (p === 1 || p === totalPages || (p >= studentCurrentPage - 1 && p <= studentCurrentPage + 1)) {
            const isActive = (p === studentCurrentPage);
            pagHtml += `
                <button type="button" class="tgs-pag-num ${isActive ? 'active' : ''}" onclick="goToStudentPage(${p})">
                    ${p}
                </button>
            `;
        } else if (p === studentCurrentPage - 2 || p === studentCurrentPage + 2) {
            pagHtml += `<span style="padding: 0 4px; color: #94A3B8;">...</span>`;
        }
    }

    pagHtml += `
            <button type="button" class="tgs-pag-btn" onclick="goToStudentPage(${studentCurrentPage + 1})" ${studentCurrentPage === totalPages ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : ''}>
                <span>Berikutnya</span>
                <iconify-icon icon="solar:alt-arrow-right-linear"></iconify-icon>
            </button>
        </div>
    `;

    pagBar.innerHTML = pagHtml;
    pagBar.style.display = 'flex';
}

function goToStudentPage(page) {
    studentCurrentPage = page;
    applyStudentPagination();
    const container = document.getElementById('tasksCardsContainer');
    if (container) {
        container.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// Inisialisasi saat dokumen siap
document.addEventListener('DOMContentLoaded', () => {
    applyPriorityBookmarks();
    onStudentCheckboxChange();
    initStudentPagination();
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
