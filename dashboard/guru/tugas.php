<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — MANAJEMEN PENUGASAN & PENILAIAN SISWA (PORTAL GURU & ADMIN)
 * ==============================================================================
 * Fitur Utama:
 * 1. CRUD Penugasan Lengkap (Individu & Kelompok).
 * 2. Manajemen Kelompok Pembelajaran (tugas_kelompok & tugas_kelompok_anggota).
 * 3. Integrasi Google Apps Script (GAS) Terpisah untuk Tugas:
 *    - Otomatis membuat struktur folder di Google Drive:
 *      root "tugas" -> "/{nama_kelas}" -> "/{nama_mapel}" -> "/{nama_tugas}" -> "/{nama_pengumpul}" -> "{nama_file}"
 *    - Menyimpan ID & Link Google Drive (Hanya Lihat / View-Only) di database.
 * 4. Penilaian Tugas Siswa Terpadu (nilai_tugas & pengumpulan_tugas):
 *    - Nilai angka, predikat otomatis (A, B, C, D, E), remedial, aspek ITP ketercapaian, umpan balik.
 *    - Sinkronisasi otomatis nilai untuk seluruh anggota kelompok dalam tugas kolaboratif.
 * 5. VIEW LANGSUNG TANPA DOWNLOAD (STRICT VIEW-ONLY):
 *    - In-page PDF.js Canvas viewer (bebas tombol unduh).
 *    - In-page Google Drive Preview iframe dengan pelindung overlay anti-download.
 *    - In-page Gambar & Video player.
 *    - Proteksi klik kanan (contextmenu), shortcut Ctrl+S, Ctrl+P, dan watermark keamanan.
 * 6. Tema Lumina Gold & Emerald serasi dengan seluruh portal Ilmuna Learn.
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

// Anti-cache header agar browser selalu memuat kode terbaru
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

// Hak Akses: Guru & Admin
requireRole(['guru', 'admin']);
$activeRole = hasRole('guru') ? 'guru' : 'admin';
$GLOBALS['activeRole'] = $activeRole;

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
$currentUser = getCurrentUser();
$isAdmin = hasRole('admin');

// Pastikan direktori uploads/tugas tersedia
$localUploadDir = BASE_PATH . '/uploads/tugas';
if (!is_dir($localUploadDir)) {
    @mkdir($localUploadDir, 0777, true);
}

// Pastikan tabel app_settings tersedia
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `app_settings` (
        `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
        `setting_value` TEXT DEFAULT NULL,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Pastikan kolom Google Drive di tabel tugas & pengumpulan_tugas tersedia
try {
    $colsTugas = $pdo->query("SHOW COLUMNS FROM tugas")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('gdrive_file_id', $colsTugas)) {
        $pdo->exec("ALTER TABLE tugas ADD COLUMN `gdrive_file_id` VARCHAR(150) NULL AFTER `link_sumber_tugas`");
    }
    if (!in_array('gdrive_view_url', $colsTugas)) {
        $pdo->exec("ALTER TABLE tugas ADD COLUMN `gdrive_view_url` TEXT NULL AFTER `gdrive_file_id`");
    }
    if (!in_array('gdrive_preview_url', $colsTugas)) {
        $pdo->exec("ALTER TABLE tugas ADD COLUMN `gdrive_preview_url` TEXT NULL AFTER `gdrive_view_url`");
    }

    $colsPeng = $pdo->query("SHOW COLUMNS FROM pengumpulan_tugas")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('gdrive_file_id', $colsPeng)) {
        $pdo->exec("ALTER TABLE pengumpulan_tugas ADD COLUMN `gdrive_file_id` VARCHAR(150) NULL AFTER `link_tugas`");
    }
    if (!in_array('gdrive_view_url', $colsPeng)) {
        $pdo->exec("ALTER TABLE pengumpulan_tugas ADD COLUMN `gdrive_view_url` TEXT NULL AFTER `gdrive_file_id`");
    }
    if (!in_array('gdrive_preview_url', $colsPeng)) {
        $pdo->exec("ALTER TABLE pengumpulan_tugas ADD COLUMN `gdrive_preview_url` TEXT NULL AFTER `gdrive_view_url`");
    }
    if (!in_array('gdrive_folder_path', $colsPeng)) {
        $pdo->exec("ALTER TABLE pengumpulan_tugas ADD COLUMN `gdrive_folder_path` TEXT NULL AFTER `gdrive_preview_url`");
    }
} catch (Exception $e) {}

// Profil Guru Logged-in
$currentGuru = null;
$idGuruLogged = 0;
try {
    $gStmt = $pdo->prepare("SELECT * FROM guru WHERE id_user = ? LIMIT 1");
    $gStmt->execute([$currentUser['id']]);
    $currentGuru = $gStmt->fetch(PDO::FETCH_ASSOC);
    if ($currentGuru) {
        $idGuruLogged = (int)$currentGuru['id'];
    }
    // Fallback jika tidak cocok via id_user, cari via email
    if (!$idGuruLogged && !empty($currentUser['email'])) {
        $gStmt2 = $pdo->prepare("SELECT * FROM guru WHERE email = ? LIMIT 1");
        $gStmt2->execute([$currentUser['email']]);
        $cg2 = $gStmt2->fetch(PDO::FETCH_ASSOC);
        if ($cg2) {
            $currentGuru = $cg2;
            $idGuruLogged = (int)$cg2['id'];
        }
    }
} catch (Exception $e) {}

// ATURAN HAK AKSES KETAT:
// Jika peran aktif adalah guru (atau bukan admin murni), batasi 100% hanya kelas & mapel yang diampu
$isStrictGuru = ($activeRole === 'guru' || !$isAdmin);

/**
 * Validasi KMP (id_kelas_mata_pelajaran): Harus diampu oleh guru
 */
function isKmpDiampuGuru($pdo, $idKmp, $idGuruLogged, $isBypass = false) {
    if ($isBypass) return true;
    if ($idKmp <= 0 || $idGuruLogged <= 0) return false;
    $stmt = $pdo->prepare("SELECT 1 FROM kelas_mapel WHERE id_kelas_mata_pelajaran = ? AND id_guru = ? LIMIT 1");
    $stmt->execute([$idKmp, $idGuruLogged]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Validasi Tugas: Harus berada di kelas/mapel yang diampu oleh guru
 */
function isTugasDiampuGuru($pdo, $idTugas, $idGuruLogged, $isBypass = false) {
    if ($isBypass) return true;
    if ($idTugas <= 0 || $idGuruLogged <= 0) return false;
    $stmt = $pdo->prepare("
        SELECT 1 
        FROM tugas t
        JOIN kelas_mapel km ON t.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
        WHERE t.id = ? AND km.id_guru = ?
        LIMIT 1
    ");
    $stmt->execute([$idTugas, $idGuruLogged]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Validasi Pengumpulan: Harus dari tugas yang kelas/mapelnya diampu oleh guru
 */
function isPengumpulanDiampuGuru($pdo, $idPengumpulan, $idGuruLogged, $isBypass = false) {
    if ($isBypass) return true;
    if ($idPengumpulan <= 0 || $idGuruLogged <= 0) return false;
    $stmt = $pdo->prepare("
        SELECT 1 
        FROM pengumpulan_tugas pt
        JOIN tugas t ON pt.id_tugas = t.id
        JOIN kelas_mapel km ON t.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
        WHERE pt.id = ? AND km.id_guru = ?
        LIMIT 1
    ");
    $stmt->execute([$idPengumpulan, $idGuruLogged]);
    return (bool)$stmt->fetchColumn();
}

// Helper: Ambil URL GAS Tugas (Terpusat di config.php)
function getGasTugasUrl($pdo = null) {
    if (defined('GAS_TUGAS_URL') && !empty(GAS_TUGAS_URL)) {
        return trim(GAS_TUGAS_URL);
    }
    if ($pdo) {
        $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'gas_tugas_url' LIMIT 1");
        $stmt->execute();
        $dbVal = trim((string)$stmt->fetchColumn());
        if (!empty($dbVal)) {
            return $dbVal;
        }
    }
    return '';
}

// Helper: Hitung Predikat Nilai Otomatis
function hitungPredikatNilai($nilai) {
    $nilai = (float)$nilai;
    if ($nilai >= 90) return 'A';
    if ($nilai >= 80) return 'B';
    if ($nilai >= 70) return 'C';
    if ($nilai >= 60) return 'D';
    return 'E';
}

// Helper: Kirim Berkas ke Google Apps Script (TugasUploader.gs)
function uploadBerkasKeGasTugas($pdo, $localPath, $originalName, $kelas, $mapel, $judulTugas, $jenisPenugasan, $namaPengumpul, $mimeType = '') {
    $gasUrl = getGasTugasUrl($pdo);
    if (empty($gasUrl) || !file_exists($localPath)) {
        return [
            'success' => false,
            'message' => 'GAS Tugas belum dikonfigurasi atau file lokal tidak ditemukan.'
        ];
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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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

/* ==============================================================================
 * STREAMING PROXY UNTUK VIEW LANGSUNG (STRICT VIEW-ONLY TANPA DOWNLOAD)
 * ============================================================================== */
if (isset($_GET['stream']) && in_array($_GET['stream'], ['submission', 'tugas'])) {
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

    if ($type === 'submission') {
        if (!isPengumpulanDiampuGuru($pdo, $id, $idGuruLogged, !$isStrictGuru)) {
            http_response_code(403);
            exit('Akses ditolak: Berkas pengumpulan bukan dari kelas yang Anda ampu.');
        }
        $stmt = $pdo->prepare("SELECT * FROM pengumpulan_tugas WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            exit('Data pengumpulan tidak ditemukan.');
        }
        $filePath = $row['file_tugas'] ?? '';
        $fileName = $row['file_nama_asli'] ?? basename($filePath);
        $gdriveId = $row['gdrive_file_id'] ?? '';
    } else {
        if (!isTugasDiampuGuru($pdo, $id, $idGuruLogged, !$isStrictGuru)) {
            http_response_code(403);
            exit('Akses ditolak: Berkas tugas bukan dari kelas yang Anda ampu.');
        }
        $stmt = $pdo->prepare("SELECT * FROM tugas WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            exit('Data tugas tidak ditemukan.');
        }
        $filePath = $row['file_lampiran_tugas'] ?? '';
        $fileName = basename($filePath);
        $gdriveId = $row['gdrive_file_id'] ?? '';
    }

    // Ekstensi & MIME
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

    // 1. Cek Berkas Lokal
    $absLocal = BASE_PATH . '/' . ltrim($filePath, '/');
    if (!empty($filePath) && file_exists($absLocal) && is_file($absLocal)) {
        header("Content-Type: {$mimeType}");
        header('Content-Disposition: inline; filename="' . rawurlencode($fileName) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($absLocal));
        header('Accept-Ranges: bytes');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=86400');
        readfile($absLocal);
        exit;
    }

    // 2. Cek Google Drive ID
    if (!empty($gdriveId)) {
        $cookieFile = sys_get_temp_dir() . '/gdrive_cookie_' . md5($gdriveId) . '.txt';
        $driveUrl = "https://drive.google.com/uc?export=download&id={$gdriveId}&confirm=t";
        $ch = curl_init($driveUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
        ]);
        $bin = curl_exec($ch);
        $fetchedMime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        @unlink($cookieFile);

        $finalMime = $mimeType;
        if (!empty($fetchedMime) && strpos($fetchedMime, 'application/octet-stream') === false && strpos($fetchedMime, 'text/html') === false) {
            $finalMime = $fetchedMime;
        }

        $isHtmlResponse = (stripos($bin, '<!DOCTYPE') !== false || stripos($bin, '<html') !== false);
        if ($bin && strlen($bin) > 0 && !$isHtmlResponse) {
            header("Content-Type: {$finalMime}");
            header('Content-Disposition: inline; filename="' . rawurlencode($fileName) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . strlen($bin));
            header('Accept-Ranges: bytes');
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: public, max-age=86400');
            echo $bin;
            exit;
        }
    }

    // Fallback: Kembalikan JSON bila gagal streaming
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(422);
    echo json_encode([
        'success'   => false,
        'fallback'  => true,
        'gdrive_id' => $gdriveId,
        'message'   => 'Berkas binary dialihkan ke mode preview iframe proteksi.'
    ]);
    exit;
}

/* ==============================================================================
 * BACKEND AJAX HANDLERS
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    try {
        // 1. DAFTAR PILIHAN KELAS
        if ($action === 'list_kelas') {
            if ($isAdmin && !$isStrictGuru) {
                $stmt = $pdo->query("SELECT id, nama_kelas, tahun_pelajaran FROM kelas ORDER BY nama_kelas ASC");
            } else {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT k.id, k.nama_kelas, k.tahun_pelajaran 
                    FROM kelas_mapel km
                    JOIN kelas k ON km.id_kelas = k.id
                    WHERE km.id_guru = ?
                    ORDER BY k.nama_kelas ASC
                ");
                $stmt->execute([$idGuruLogged]);
            }
            $kelas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $kelas]);
            exit;
        }

        // 2. DAFTAR MATA PELAJARAN PER KELAS
        if ($action === 'list_mapel') {
            $idKelas = (int)($_POST['id_kelas'] ?? 0);
            if ($idKelas <= 0) {
                echo json_encode(['success' => true, 'data' => []]);
                exit;
            }

            if ($isAdmin && !$isStrictGuru) {
                $stmt = $pdo->prepare("
                    SELECT km.id_kelas_mata_pelajaran AS id, km.nama_mata_pelajaran, km.semester, km.nama_guru
                    FROM kelas_mapel km
                    WHERE km.id_kelas = ?
                    ORDER BY km.nama_mata_pelajaran ASC
                ");
                $stmt->execute([$idKelas]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT km.id_kelas_mata_pelajaran AS id, km.nama_mata_pelajaran, km.semester, km.nama_guru
                    FROM kelas_mapel km
                    WHERE km.id_kelas = ? AND km.id_guru = ?
                    ORDER BY km.nama_mata_pelajaran ASC
                ");
                $stmt->execute([$idKelas, $idGuruLogged]);
            }
            $mapel = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $mapel]);
            exit;
        }

        // 3. STATISTIK TUGAS & PENILAIAN
        if ($action === 'get_stats') {
            $idKmp = (int)($_POST['id_kelas_mata_pelajaran'] ?? 0);
            $whereTugas = "WHERE 1=1";
            $params = [];

            if ($idKmp > 0) {
                if ($isStrictGuru && !isKmpDiampuGuru($pdo, $idKmp, $idGuruLogged, false)) {
                    $whereTugas .= " AND 1=0";
                } else {
                    $whereTugas .= " AND t.id_kelas_mata_pelajaran = ?";
                    $params[] = $idKmp;
                }
            } elseif ($isStrictGuru) {
                $whereTugas .= " AND t.id_kelas_mata_pelajaran IN (SELECT id_kelas_mata_pelajaran FROM kelas_mapel WHERE id_guru = ?)";
                $params[] = $idGuruLogged;
            }

            $stmtTot = $pdo->prepare("SELECT COUNT(*) FROM tugas t {$whereTugas}");
            $stmtTot->execute($params);
            $totalTugas = (int)$stmtTot->fetchColumn();

            $stmtIndiv = $pdo->prepare("SELECT COUNT(*) FROM tugas t {$whereTugas} AND t.jenis_penugasan = 'Individu'");
            $stmtIndiv->execute($params);
            $totalIndividu = (int)$stmtIndiv->fetchColumn();

            $stmtKel = $pdo->prepare("SELECT COUNT(*) FROM tugas t {$whereTugas} AND t.jenis_penugasan = 'Kelompok'");
            $stmtKel->execute($params);
            $totalKelompok = (int)$stmtKel->fetchColumn();

            // Total Kelompok Terbentuk di Database
            $stmtTotKelompok = $pdo->prepare("SELECT COUNT(*) FROM tugas_kelompok tk JOIN tugas t ON tk.id_tugas = t.id {$whereTugas}");
            $stmtTotKelompok->execute($params);
            $totalKelompokTerbentuk = (int)$stmtTotKelompok->fetchColumn();

            // Total Pengumpulan & Status Penilaian
            $whereSub = "WHERE 1=1";
            $subParams = [];
            if ($idKmp > 0) {
                if ($isStrictGuru && !isKmpDiampuGuru($pdo, $idKmp, $idGuruLogged, false)) {
                    $whereSub .= " AND 1=0";
                } else {
                    $whereSub .= " AND p.id_kelas_mata_pelajaran = ?";
                    $subParams[] = $idKmp;
                }
            } elseif ($isStrictGuru) {
                $whereSub .= " AND p.id_kelas_mata_pelajaran IN (SELECT id_kelas_mata_pelajaran FROM kelas_mapel WHERE id_guru = ?)";
                $subParams[] = $idGuruLogged;
            }

            $stmtSub = $pdo->prepare("SELECT COUNT(*) FROM pengumpulan_tugas p {$whereSub}");
            $stmtSub->execute($subParams);
            $totalPengumpulan = (int)$stmtSub->fetchColumn();

            // Menunggu Penilaian (Belum ada di tabel nilai_tugas)
            $stmtWait = $pdo->prepare("
                SELECT COUNT(*) FROM pengumpulan_tugas p 
                {$whereSub}
                AND p.id NOT IN (SELECT id_pengumpulan_tugas FROM nilai_tugas WHERE status_penilaian IN ('Dinilai', 'Tuntas'))
            ");
            $stmtWait->execute($subParams);
            $menungguPenilaian = (int)$stmtWait->fetchColumn();

            // Tuntas Dinilai
            $stmtTuntas = $pdo->prepare("
                SELECT COUNT(*) FROM pengumpulan_tugas p 
                {$whereSub}
                AND p.id IN (SELECT id_pengumpulan_tugas FROM nilai_tugas WHERE status_penilaian IN ('Dinilai', 'Tuntas'))
            ");
            $stmtTuntas->execute($subParams);
            $tuntasDinilai = (int)$stmtTuntas->fetchColumn();

            echo json_encode([
                'success' => true,
                'data' => [
                    'total_tugas'              => $totalTugas,
                    'total_individu'           => $totalIndividu,
                    'total_kelompok'           => $totalKelompok,
                    'total_kelompok_terbentuk' => $totalKelompokTerbentuk,
                    'total_pengumpulan'        => $totalPengumpulan,
                    'menunggu_penilaian'       => $menungguPenilaian,
                    'tuntas_dinilai'           => $tuntasDinilai
                ]
            ]);
            exit;
        }

        // 4. DAFTAR TUGAS (CRUD TAB 1)
        if ($action === 'list_tugas') {
            $idKmp  = (int)($_POST['id_kelas_mata_pelajaran'] ?? 0);
            $status = trim($_POST['status'] ?? 'all');
            $jenis  = trim($_POST['jenis'] ?? 'all');
            $search = trim($_POST['search'] ?? '');
            $page   = max(1, (int)($_POST['page'] ?? 1));
            $per    = max(5, min(100, (int)($_POST['per'] ?? 10)));
            $offset = ($page - 1) * $per;

            $where = "WHERE 1=1";
            $params = [];

            if ($idKmp > 0) {
                if ($isStrictGuru && !isKmpDiampuGuru($pdo, $idKmp, $idGuruLogged, false)) {
                    $where .= " AND 1=0";
                } else {
                    $where .= " AND t.id_kelas_mata_pelajaran = :id_kmp";
                    $params[':id_kmp'] = $idKmp;
                }
            } elseif ($isStrictGuru) {
                $where .= " AND t.id_kelas_mata_pelajaran IN (SELECT id_kelas_mata_pelajaran FROM kelas_mapel WHERE id_guru = :id_guru)";
                $params[':id_guru'] = $idGuruLogged;
            }

            if ($status !== 'all' && in_array($status, ['Draft', 'Dibuka', 'Ditutup'])) {
                $where .= " AND t.status_tugas = :status";
                $params[':status'] = $status;
            }

            if ($jenis !== 'all' && in_array($jenis, ['Individu', 'Kelompok'])) {
                $where .= " AND t.jenis_penugasan = :jenis";
                $params[':jenis'] = $jenis;
            }

            if (!empty($search)) {
                $where .= " AND (t.judul_tugas LIKE :q OR t.deskripsi_instruksi LIKE :q)";
                $params[':q'] = "%{$search}%";
            }

            // Total count
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM tugas t {$where}");
            foreach ($params as $k => $v) { $stmtCount->bindValue($k, $v); }
            $stmtCount->execute();
            $totalRows = (int)$stmtCount->fetchColumn();

            // Fetch data
            $stmtList = $pdo->prepare("
                SELECT 
                    t.*,
                    km.nama_kelas,
                    km.nama_mata_pelajaran,
                    km.semester,
                    (SELECT COUNT(*) FROM pengumpulan_tugas pt WHERE pt.id_tugas = t.id) AS total_kumpul,
                    (SELECT COUNT(*) FROM nilai_tugas nt WHERE nt.id_tugas = t.id AND nt.status_penilaian IN ('Dinilai', 'Tuntas')) AS total_dinilai,
                    (SELECT AVG(nt.nilai) FROM nilai_tugas nt WHERE nt.id_tugas = t.id) AS rata_nilai,
                    (SELECT COUNT(*) FROM tugas_kelompok tk WHERE tk.id_tugas = t.id) AS total_kelompok_dibuat
                FROM tugas t
                LEFT JOIN kelas_mapel km ON t.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
                {$where}
                ORDER BY t.id DESC
                LIMIT :offset, :per
            ");
            foreach ($params as $k => $v) { $stmtList->bindValue($k, $v); }
            $stmtList->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmtList->bindValue(':per', $per, PDO::PARAM_INT);
            $stmtList->execute();
            $tugas = $stmtList->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data'    => $tugas,
                'page'    => $page,
                'per'     => $per,
                'total'   => $totalRows,
                'pages'   => ceil($totalRows / $per)
            ]);
            exit;
        }

        // 5. DETAIL TUGAS
        if ($action === 'get_tugas') {
            $id = (int)($_POST['id'] ?? 0);
            if ($isStrictGuru && !isTugasDiampuGuru($pdo, $id, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat mengakses tugas di kelas yang Anda ampu.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT t.*, km.id_kelas, km.nama_kelas, km.nama_mata_pelajaran
                FROM tugas t
                LEFT JOIN kelas_mapel km ON t.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
                WHERE t.id = ? LIMIT 1
            ");
            $stmt->execute([$id]);
            $tugas = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tugas) {
                echo json_encode(['success' => false, 'message' => 'Tugas tidak ditemukan.']);
                exit;
            }

            echo json_encode(['success' => true, 'data' => $tugas]);
            exit;
        }

        // 6. SIMPAN TUGAS (INSERT / UPDATE)
        if ($action === 'save_tugas') {
            $id               = (int)($_POST['id'] ?? 0);
            $idKmp            = (int)($_POST['id_kelas_mata_pelajaran'] ?? 0);
            $judul            = trim($_POST['judul_tugas'] ?? '');
            $deskripsi        = trim($_POST['deskripsi_instruksi'] ?? '');
            $jenisPenugasan   = in_array($_POST['jenis_penugasan'] ?? '', ['Individu', 'Kelompok']) ? $_POST['jenis_penugasan'] : 'Individu';
            $tipePengumpulan  = $_POST['tipe_pengumpulan'] ?? 'File atau Link';
            $formatFile       = trim($_POST['format_file_diizinkan'] ?? 'pdf,docx,zip');
            $linkSumber       = trim($_POST['link_sumber_tugas'] ?? '');
            $nilaiMaksimal    = max(1, min(100, (float)($_POST['nilai_maksimal'] ?? 100)));
            $bobotNilai       = max(1, min(100, (int)($_POST['bobot_nilai'] ?? 10)));
            $pertemuanKe      = max(1, (int)($_POST['pertemuan_ke'] ?? 1));
            $tanggalMulai     = !empty($_POST['tanggal_mulai']) ? date('Y-m-d H:i:s', strtotime($_POST['tanggal_mulai'])) : date('Y-m-d H:i:s');
            $tenggatWaktu     = !empty($_POST['tenggat_waktu']) ? date('Y-m-d H:i:s', strtotime($_POST['tenggat_waktu'])) : date('Y-m-d 23:59:59', strtotime('+7 days'));
            $statusTugas      = in_array($_POST['status_tugas'] ?? '', ['Draft', 'Dibuka', 'Ditutup']) ? $_POST['status_tugas'] : 'Dibuka';

            if ($idKmp <= 0 || empty($judul)) {
                echo json_encode(['success' => false, 'message' => 'Kelas, Mata Pelajaran, dan Judul Tugas wajib diisi.']);
                exit;
            }

            // Validasi Hak Mengajar Guru: Hanya bisa buat / ubah tugas di kelas yang diampu
            if ($isStrictGuru && !isKmpDiampuGuru($pdo, $idKmp, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat membuat atau mengedit tugas di kelas dan mata pelajaran yang Anda ampu.']);
                exit;
            }

            if ($id > 0 && $isStrictGuru && !isTugasDiampuGuru($pdo, $id, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Tugas ini bukan dari kelas yang Anda ampu.']);
                exit;
            }

            // Dapatkan Info Kelas & Mapel untuk Sinkronisasi Google Drive
            $stmtKm = $pdo->prepare("SELECT nama_kelas, nama_mata_pelajaran FROM kelas_mapel WHERE id_kelas_mata_pelajaran = ? LIMIT 1");
            $stmtKm->execute([$idKmp]);
            $kmInfo = $stmtKm->fetch(PDO::FETCH_ASSOC);
            $namaKelas = $kmInfo['nama_kelas'] ?? 'Kelas';
            $namaMapel = $kmInfo['nama_mata_pelajaran'] ?? 'Mapel';

            // Ambil data lama jika Update
            $oldTugas = null;
            if ($id > 0) {
                $stmtOld = $pdo->prepare("SELECT * FROM tugas WHERE id = ? LIMIT 1");
                $stmtOld->execute([$id]);
                $oldTugas = $stmtOld->fetch(PDO::FETCH_ASSOC);
            }

            $finalFile = $oldTugas['file_lampiran_tugas'] ?? '';
            $finalGdriveId = $oldTugas['gdrive_file_id'] ?? '';
            $finalGdriveView = $oldTugas['gdrive_view_url'] ?? '';
            $finalGdrivePreview = $oldTugas['gdrive_preview_url'] ?? '';
            $uploadNotice = '';

            // Handle Berkas Lampiran Baru
            if (isset($_FILES['file_lampiran']) && $_FILES['file_lampiran']['error'] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['file_lampiran']['tmp_name'];
                $origName = $_FILES['file_lampiran']['name'];
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $safeName = 'tugas_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $destPath = $localUploadDir . '/' . $safeName;

                if (move_uploaded_file($tmpName, $destPath)) {
                    $finalFile = 'uploads/tugas/' . $safeName;

                    // Kirim ke Google Apps Script (TugasUploader.gs)
                    $gasResult = uploadBerkasKeGasTugas(
                        $pdo,
                        $destPath,
                        $origName,
                        $namaKelas,
                        $namaMapel,
                        $judul,
                        $jenisPenugasan,
                        'Lampiran Guru',
                        mime_content_type($destPath)
                    );

                    if ($gasResult['success']) {
                        $finalGdriveId = $gasResult['file_id'];
                        $finalGdriveView = $gasResult['view_url'];
                        $finalGdrivePreview = $gasResult['preview_url'];
                        $uploadNotice = ' (Tersimpan & Tersinkronisasi ke Google Drive)';
                    } else {
                        $uploadNotice = ' (Tersimpan Lokal: ' . $gasResult['message'] . ')';
                    }
                }
            }

            if ($id > 0) {
                // UPDATE
                $stmtUpdate = $pdo->prepare("
                    UPDATE tugas SET
                        id_kelas_mata_pelajaran = :id_kmp,
                        id_guru                 = :id_guru,
                        pertemuan_ke            = :pertemuan,
                        judul_tugas             = :judul,
                        deskripsi_instruksi     = :deskripsi,
                        jenis_penugasan         = :jenis,
                        tipe_pengumpulan        = :tipe,
                        format_file_diizinkan   = :format,
                        file_lampiran_tugas     = :file,
                        link_sumber_tugas       = :link,
                        gdrive_file_id          = :gdrive_id,
                        gdrive_view_url         = :gdrive_view,
                        gdrive_preview_url      = :gdrive_preview,
                        nilai_maksimal          = :nilai_max,
                        bobot_nilai             = :bobot,
                        tanggal_mulai           = :tgl_mulai,
                        tenggat_waktu           = :tenggat,
                        status_tugas            = :status,
                        updated_at              = NOW()
                    WHERE id = :id
                ");
                $stmtUpdate->execute([
                    ':id_kmp'         => $idKmp,
                    ':id_guru'        => $idGuruLogged ?: $currentUser['id'],
                    ':pertemuan'      => $pertemuanKe,
                    ':judul'          => $judul,
                    ':deskripsi'      => $deskripsi,
                    ':jenis'          => $jenisPenugasan,
                    ':tipe'           => $tipePengumpulan,
                    ':format'         => $formatFile,
                    ':file'           => $finalFile,
                    ':link'           => $linkSumber,
                    ':gdrive_id'      => $finalGdriveId,
                    ':gdrive_view'    => $finalGdriveView,
                    ':gdrive_preview' => $finalGdrivePreview,
                    ':nilai_max'      => $nilaiMaksimal,
                    ':bobot'          => $bobotNilai,
                    ':tgl_mulai'      => $tanggalMulai,
                    ':tenggat'        => $tenggatWaktu,
                    ':status'         => $statusTugas,
                    ':id'             => $id
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Penugasan berhasil diperbarui!' . $uploadNotice,
                    'id'      => $id
                ]);
                exit;
            } else {
                // INSERT
                $stmtInsert = $pdo->prepare("
                    INSERT INTO tugas (
                        id_kelas_mata_pelajaran, id_guru, pertemuan_ke, judul_tugas,
                        deskripsi_instruksi, jenis_penugasan, tipe_pengumpulan, format_file_diizinkan,
                        file_lampiran_tugas, link_sumber_tugas, gdrive_file_id, gdrive_view_url, gdrive_preview_url,
                        nilai_maksimal, bobot_nilai, tanggal_mulai, tenggat_waktu, status_tugas, created_at, updated_at
                    ) VALUES (
                        :id_kmp, :id_guru, :pertemuan, :judul,
                        :deskripsi, :jenis, :tipe, :format,
                        :file, :link, :gdrive_id, :gdrive_view, :gdrive_preview,
                        :nilai_max, :bobot, :tgl_mulai, :tenggat, :status, NOW(), NOW()
                    )
                ");
                $stmtInsert->execute([
                    ':id_kmp'         => $idKmp,
                    ':id_guru'        => $idGuruLogged ?: $currentUser['id'],
                    ':pertemuan'      => $pertemuanKe,
                    ':judul'          => $judul,
                    ':deskripsi'      => $deskripsi,
                    ':jenis'          => $jenisPenugasan,
                    ':tipe'           => $tipePengumpulan,
                    ':format'         => $formatFile,
                    ':file'           => $finalFile,
                    ':link'           => $linkSumber,
                    ':gdrive_id'      => $finalGdriveId,
                    ':gdrive_view'    => $finalGdriveView,
                    ':gdrive_preview' => $finalGdrivePreview,
                    ':nilai_max'      => $nilaiMaksimal,
                    ':bobot'          => $bobotNilai,
                    ':tgl_mulai'      => $tanggalMulai,
                    ':tenggat'        => $tenggatWaktu,
                    ':status'         => $statusTugas
                ]);

                $newId = (int)$pdo->lastInsertId();
                echo json_encode([
                    'success' => true,
                    'message' => 'Alhamdulillah! Penugasan baru berhasil dibuat!' . $uploadNotice,
                    'id'      => $newId
                ]);
                exit;
            }
        }

        // 7. HAPUS TUGAS
        if ($action === 'delete_tugas') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID tugas tidak valid.']);
                exit;
            }

            if ($isStrictGuru && !isTugasDiampuGuru($pdo, $id, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat menghapus tugas di kelas yang Anda ampu.']);
                exit;
            }

            // Hapus nilai, pengumpulan, anggota kelompok, kelompok, lalu tugas
            $pdo->prepare("DELETE FROM nilai_tugas WHERE id_tugas = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM pengumpulan_tugas WHERE id_tugas = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM tugas_kelompok_anggota WHERE id_tugas_kelompok IN (SELECT id FROM tugas_kelompok WHERE id_tugas = ?)")->execute([$id]);
            $pdo->prepare("DELETE FROM tugas_kelompok WHERE id_tugas = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM tugas WHERE id = ?")->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Tugas dan data terkait berhasil dihapus.']);
            exit;
        }

        // 8. TOGGLE STATUS TUGAS (Dibuka / Ditutup)
        if ($action === 'toggle_status_tugas') {
            $id = (int)($_POST['id'] ?? 0);
            if ($isStrictGuru && !isTugasDiampuGuru($pdo, $id, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat mengubah status tugas di kelas yang Anda ampu.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT status_tugas FROM tugas WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $curr = $stmt->fetchColumn();

            if (!$curr) {
                echo json_encode(['success' => false, 'message' => 'Tugas tidak ditemukan.']);
                exit;
            }

            $next = ($curr === 'Dibuka') ? 'Ditutup' : 'Dibuka';
            $pdo->prepare("UPDATE tugas SET status_tugas = ?, updated_at = NOW() WHERE id = ?")->execute([$next, $id]);

            echo json_encode(['success' => true, 'new_status' => $next]);
            exit;
        }

        // ======================================================================
        // MANAJEMEN KELOMPOK (TUGAS KELOMPOK)
        // ======================================================================

        // 9. DAFTAR KELOMPOK UNTUK SEBUAH TUGAS
        if ($action === 'list_kelompok') {
            $idTugas = (int)($_POST['id_tugas'] ?? 0);
            if ($idTugas <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID tugas tidak valid.']);
                exit;
            }

            if ($isStrictGuru && !isTugasDiampuGuru($pdo, $idTugas, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda tidak mengampu tugas pada kelas ini.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT tk.*, s.nama_lengkap AS nama_ketua, s.nisn AS nisn_ketua
                FROM tugas_kelompok tk
                LEFT JOIN siswa s ON tk.id_ketua_kelompok = s.id
                WHERE tk.id_tugas = ?
                ORDER BY tk.nomor_kelompok ASC
            ");
            $stmt->execute([$idTugas]);
            $kelompoks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Ambil anggota untuk tiap kelompok
            foreach ($kelompoks as &$kel) {
                $stmtAng = $pdo->prepare("
                    SELECT tka.*, s.nama_lengkap, s.nisn, s.nis, s.foto
                    FROM tugas_kelompok_anggota tka
                    JOIN siswa s ON tka.id_siswa = s.id
                    WHERE tka.id_tugas_kelompok = ?
                    ORDER BY s.nama_lengkap ASC
                ");
                $stmtAng->execute([$kel['id']]);
                $kel['anggota'] = $stmtAng->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success' => true, 'data' => $kelompoks]);
            exit;
        }

        // 10. DAFTAR SISWA SATU KELAS UNTUK DIBAGI KELOMPOK
        if ($action === 'get_siswa_kelas') {
            $idTugas = (int)($_POST['id_tugas'] ?? 0);
            if ($isStrictGuru && !isTugasDiampuGuru($pdo, $idTugas, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda tidak mengampu tugas pada kelas ini.']);
                exit;
            }

            $stmtT = $pdo->prepare("
                SELECT t.id, t.id_kelas_mata_pelajaran, km.id_kelas
                FROM tugas t
                JOIN kelas_mapel km ON t.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
                WHERE t.id = ? LIMIT 1
            ");
            $stmtT->execute([$idTugas]);
            $tInfo = $stmtT->fetch(PDO::FETCH_ASSOC);

            if (!$tInfo) {
                echo json_encode(['success' => false, 'message' => 'Tugas tidak ditemukan.']);
                exit;
            }

            $idKelas = (int)$tInfo['id_kelas'];
            $stmtS = $pdo->prepare("
                SELECT s.id, s.nisn, s.nis, s.nama_lengkap, s.foto
                FROM siswa s
                WHERE s.id_kelas = ?
                ORDER BY s.nama_lengkap ASC
            ");
            $stmtS->execute([$idKelas]);
            $siswaList = $stmtS->fetchAll(PDO::FETCH_ASSOC);

            // Cek siapa yang sudah terdaftar di kelompok pada tugas ini
            $stmtAssigned = $pdo->prepare("
                SELECT tka.id_siswa, tk.nama_kelompok, tk.id AS id_kelompok
                FROM tugas_kelompok_anggota tka
                JOIN tugas_kelompok tk ON tka.id_tugas_kelompok = tk.id
                WHERE tk.id_tugas = ?
            ");
            $stmtAssigned->execute([$idTugas]);
            $assigned = $stmtAssigned->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_UNIQUE);

            foreach ($siswaList as &$sw) {
                $sw['assigned_kelompok'] = $assigned[$sw['id']] ?? null;
            }

            echo json_encode(['success' => true, 'data' => $siswaList]);
            exit;
        }

        // 11. SIMPAN KELOMPOK BARU / UPDATE
        if ($action === 'save_kelompok') {
            $idKelompok   = (int)($_POST['id_kelompok'] ?? 0);
            $idTugas      = (int)($_POST['id_tugas'] ?? 0);
            $namaKelompok = trim($_POST['nama_kelompok'] ?? '');
            $nomor        = max(1, (int)($_POST['nomor_kelompok'] ?? 1));
            $idKetua      = (int)($_POST['id_ketua_kelompok'] ?? 0) ?: null;
            $anggotaIds   = isset($_POST['anggota_ids']) && is_array($_POST['anggota_ids']) ? array_map('intval', $_POST['anggota_ids']) : [];

            if ($idTugas <= 0 || empty($namaKelompok)) {
                echo json_encode(['success' => false, 'message' => 'ID tugas dan nama kelompok wajib diisi.']);
                exit;
            }

            if ($isStrictGuru && !isTugasDiampuGuru($pdo, $idTugas, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat mengelola kelompok di kelas yang Anda ampu.']);
                exit;
            }

            $stmtT = $pdo->prepare("SELECT id_kelas_mata_pelajaran FROM tugas WHERE id = ? LIMIT 1");
            $stmtT->execute([$idTugas]);
            $idKmp = (int)$stmtT->fetchColumn();

            if ($idKelompok > 0) {
                // Update
                $stmtUp = $pdo->prepare("
                    UPDATE tugas_kelompok SET
                        nama_kelompok     = :nama,
                        nomor_kelompok    = :nomor,
                        id_ketua_kelompok = :ketua
                    WHERE id = :id AND id_tugas = :id_tugas
                ");
                $stmtUp->execute([
                    ':nama'     => $namaKelompok,
                    ':nomor'    => $nomor,
                    ':ketua'    => $idKetua,
                    ':id'       => $idKelompok,
                    ':id_tugas' => $idTugas
                ]);
            } else {
                // Insert
                $stmtIns = $pdo->prepare("
                    INSERT INTO tugas_kelompok (
                        id_tugas, id_kelas_mata_pelajaran, nomor_kelompok, nama_kelompok, id_ketua_kelompok, created_at
                    ) VALUES (
                        :id_tugas, :id_kmp, :nomor, :nama, :ketua, NOW()
                    )
                ");
                $stmtIns->execute([
                    ':id_tugas' => $idTugas,
                    ':id_kmp'   => $idKmp,
                    ':nomor'    => $nomor,
                    ':nama'     => $namaKelompok,
                    ':ketua'    => $idKetua
                ]);
                $idKelompok = (int)$pdo->lastInsertId();
            }

            // Sync anggota kelompok
            if ($idKetua && !in_array($idKetua, $anggotaIds)) {
                $anggotaIds[] = $idKetua;
            }

            // Hapus anggota lama
            $pdo->prepare("DELETE FROM tugas_kelompok_anggota WHERE id_tugas_kelompok = ?")->execute([$idKelompok]);

            // Masukkan anggota baru
            if (!empty($anggotaIds)) {
                $insAng = $pdo->prepare("
                    INSERT INTO tugas_kelompok_anggota (id_tugas_kelompok, id_siswa, id_kelas_mata_pelajaran, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                foreach ($anggotaIds as $sid) {
                    if ($sid > 0) {
                        $insAng->execute([$idKelompok, $sid, $idKmp]);
                    }
                }
            }

            echo json_encode(['success' => true, 'message' => 'Kelompok berhasil disimpan!']);
            exit;
        }

        // 12. HAPUS KELOMPOK
        if ($action === 'delete_kelompok') {
            $idKelompok = (int)($_POST['id_kelompok'] ?? 0);
            if ($idKelompok <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID kelompok tidak valid.']);
                exit;
            }

            $stmtKel = $pdo->prepare("SELECT id_tugas FROM tugas_kelompok WHERE id = ? LIMIT 1");
            $stmtKel->execute([$idKelompok]);
            $tugasId = (int)$stmtKel->fetchColumn();
            if ($isStrictGuru && !isTugasDiampuGuru($pdo, $tugasId, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda tidak memiliki wewenang pada kelompok ini.']);
                exit;
            }

            $pdo->prepare("DELETE FROM tugas_kelompok_anggota WHERE id_tugas_kelompok = ?")->execute([$idKelompok]);
            $pdo->prepare("DELETE FROM tugas_kelompok WHERE id = ?")->execute([$idKelompok]);

            echo json_encode(['success' => true, 'message' => 'Kelompok berhasil dihapus.']);
            exit;
        }

        // 13. GENERATE OTOMATIS KELOMPOK DARI SISWA KELAS
        if ($action === 'auto_generate_kelompok') {
            $idTugas = (int)($_POST['id_tugas'] ?? 0);
            $jumlahKelompok = max(2, min(15, (int)($_POST['jumlah_kelompok'] ?? 4)));

            if ($isStrictGuru && !isTugasDiampuGuru($pdo, $idTugas, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda tidak mengampu tugas pada kelas ini.']);
                exit;
            }

            $stmtT = $pdo->prepare("
                SELECT t.id, t.id_kelas_mata_pelajaran, km.id_kelas
                FROM tugas t
                JOIN kelas_mapel km ON t.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
                WHERE t.id = ? LIMIT 1
            ");
            $stmtT->execute([$idTugas]);
            $tInfo = $stmtT->fetch(PDO::FETCH_ASSOC);

            if (!$tInfo) {
                echo json_encode(['success' => false, 'message' => 'Tugas tidak ditemukan.']);
                exit;
            }

            $idKmp = (int)$tInfo['id_kelas_mata_pelajaran'];
            $idKelas = (int)$tInfo['id_kelas'];

            $stmtS = $pdo->prepare("SELECT id, nama_lengkap FROM siswa WHERE id_kelas = ? ORDER BY RAND()");
            $stmtS->execute([$idKelas]);
            $siswaList = $stmtS->fetchAll(PDO::FETCH_ASSOC);

            if (empty($siswaList)) {
                echo json_encode(['success' => false, 'message' => 'Tidak ada siswa di rombel kelas ini.']);
                exit;
            }

            // Hapus kelompok lama untuk tugas ini
            $pdo->prepare("DELETE FROM tugas_kelompok_anggota WHERE id_tugas_kelompok IN (SELECT id FROM tugas_kelompok WHERE id_tugas = ?)")->execute([$idTugas]);
            $pdo->prepare("DELETE FROM tugas_kelompok WHERE id_tugas = ?")->execute([$idTugas]);

            $namaTokoh = [
                'Abu Bakar Ash-Shiddiq',
                'Umar bin Khattab',
                'Utsman bin Affan',
                'Ali bin Abi Thalib',
                'Thalhah bin Ubaidillah',
                'Zubair bin Awwam',
                'Abdurrahman bin Auf',
                "Sa'ad bin Abi Waqqash",
                'Said bin Zaid',
                'Abu Ubaidah bin al-Jarrah',
                'Salman Al-Farisi',
                'Bilal bin Rabah'
            ];

            // Buat kelompok
            $kelompokIds = [];
            for ($k = 1; $k <= $jumlahKelompok; $k++) {
                $subNama = $namaTokoh[($k - 1) % count($namaTokoh)];
                $namaKel = "Kelompok {$k} - {$subNama}";
                $stmtIns = $pdo->prepare("
                    INSERT INTO tugas_kelompok (id_tugas, id_kelas_mata_pelajaran, nomor_kelompok, nama_kelompok, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmtIns->execute([$idTugas, $idKmp, $k, $namaKel]);
                $kelompokIds[$k] = (int)$pdo->lastInsertId();
            }

            // Distribusi siswa
            $insAng = $pdo->prepare("
                INSERT INTO tugas_kelompok_anggota (id_tugas_kelompok, id_siswa, id_kelas_mata_pelajaran, created_at)
                VALUES (?, ?, ?, NOW())
            ");

            $kCursor = 1;
            foreach ($siswaList as $idx => $sw) {
                $targetKelompokId = $kelompokIds[$kCursor];
                $insAng->execute([$targetKelompokId, $sw['id'], $idKmp]);

                // Jadikan siswa pertama di kelompok sebagai ketua
                if ($idx < $jumlahKelompok) {
                    $pdo->prepare("UPDATE tugas_kelompok SET id_ketua_kelompok = ? WHERE id = ?")->execute([$sw['id'], $targetKelompokId]);
                }

                $kCursor++;
                if ($kCursor > $jumlahKelompok) {
                    $kCursor = 1;
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "Alhamdulillah! Berhasil membentuk {$jumlahKelompok} kelompok secara merata untuk " . count($siswaList) . " siswa."
            ]);
            exit;
        }

        // 13B. AMBIL DATA LENGKAP UNTUK TAB KELOMPOK
        if ($action === 'get_kelompok_tab_data') {
            $idTugas = (int)($_POST['id_tugas'] ?? 0);
            if ($idTugas <= 0) {
                echo json_encode(['success' => false, 'message' => 'Pilih tugas kelompok terlebih dahulu.']);
                exit;
            }

            if ($isStrictGuru && !isTugasDiampuGuru($pdo, $idTugas, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda tidak mengampu tugas pada kelas ini.']);
                exit;
            }

            $stmtT = $pdo->prepare("
                SELECT t.*, km.id_kelas, km.nama_kelas, km.nama_mata_pelajaran
                FROM tugas t
                JOIN kelas_mapel km ON t.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
                WHERE t.id = ? LIMIT 1
            ");
            $stmtT->execute([$idTugas]);
            $tugas = $stmtT->fetch(PDO::FETCH_ASSOC);

            if (!$tugas) {
                echo json_encode(['success' => false, 'message' => 'Tugas tidak ditemukan.']);
                exit;
            }

            $idKelas = (int)$tugas['id_kelas'];

            // Seluruh siswa terdaftar di rombel kelas
            $stmtAll = $pdo->prepare("SELECT id, nisn, nis, nama_lengkap, foto FROM siswa WHERE id_kelas = ? ORDER BY nama_lengkap ASC");
            $stmtAll->execute([$idKelas]);
            $allSiswa = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
            $totalSiswaKelas = count($allSiswa);

            // Daftar kelompok untuk tugas ini
            $stmtKel = $pdo->prepare("
                SELECT tk.*, s.nama_lengkap AS nama_ketua, s.nisn AS nisn_ketua, s.foto AS foto_ketua
                FROM tugas_kelompok tk
                LEFT JOIN siswa s ON tk.id_ketua_kelompok = s.id
                WHERE tk.id_tugas = ?
                ORDER BY tk.nomor_kelompok ASC
            ");
            $stmtKel->execute([$idTugas]);
            $kelompoks = $stmtKel->fetchAll(PDO::FETCH_ASSOC);

            $assignedSiswaIds = [];
            foreach ($kelompoks as &$kel) {
                $stmtAng = $pdo->prepare("
                    SELECT tka.*, s.nama_lengkap, s.nisn, s.nis, s.foto
                    FROM tugas_kelompok_anggota tka
                    JOIN siswa s ON tka.id_siswa = s.id
                    WHERE tka.id_tugas_kelompok = ?
                    ORDER BY s.nama_lengkap ASC
                ");
                $stmtAng->execute([$kel['id']]);
                $kel['anggota'] = $stmtAng->fetchAll(PDO::FETCH_ASSOC);

                foreach ($kel['anggota'] as $a) {
                    $assignedSiswaIds[] = (int)$a['id_siswa'];
                }
            }

            // Siswa yang belum masuk ke kelompok manapun pada tugas ini
            $unassignedSiswa = [];
            foreach ($allSiswa as $s) {
                if (!in_array((int)$s['id'], $assignedSiswaIds)) {
                    $unassignedSiswa[] = $s;
                }
            }

            echo json_encode([
                'success' => true,
                'data'    => [
                    'tugas'             => $tugas,
                    'total_siswa_kelas' => $totalSiswaKelas,
                    'kelompoks'         => $kelompoks,
                    'unassigned_siswa'  => $unassignedSiswa
                ]
            ]);
            exit;
        }

        // 13C. TENTUKAN KETUA KELOMPOK
        if ($action === 'set_ketua_kelompok') {
            $idKelompok = (int)($_POST['id_kelompok'] ?? 0);
            $idKetua    = (int)($_POST['id_ketua'] ?? 0);

            if ($idKelompok <= 0 || $idKetua <= 0) {
                echo json_encode(['success' => false, 'message' => 'Data kelompok atau calon ketua tidak valid.']);
                exit;
            }

            $stmtKel = $pdo->prepare("SELECT id_tugas, id_kelas_mata_pelajaran FROM tugas_kelompok WHERE id = ? LIMIT 1");
            $stmtKel->execute([$idKelompok]);
            $kelInfo = $stmtKel->fetch(PDO::FETCH_ASSOC);

            if (!$kelInfo) {
                echo json_encode(['success' => false, 'message' => 'Kelompok tidak ditemukan.']);
                exit;
            }

            if ($isStrictGuru && !isTugasDiampuGuru($pdo, $kelInfo['id_tugas'], $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda tidak memiliki wewenang pada kelompok ini.']);
                exit;
            }

            // Pastikan calon ketua juga terdaftar dalam anggota kelompok
            $stmtCheckAng = $pdo->prepare("SELECT 1 FROM tugas_kelompok_anggota WHERE id_tugas_kelompok = ? AND id_siswa = ? LIMIT 1");
            $stmtCheckAng->execute([$idKelompok, $idKetua]);
            if (!$stmtCheckAng->fetchColumn()) {
                $pdo->prepare("INSERT INTO tugas_kelompok_anggota (id_tugas_kelompok, id_siswa, id_kelas_mata_pelajaran, created_at) VALUES (?, ?, ?, NOW())")
                    ->execute([$idKelompok, $idKetua, $kelInfo['id_kelas_mata_pelajaran']]);
            }

            $pdo->prepare("UPDATE tugas_kelompok SET id_ketua_kelompok = ? WHERE id = ?")->execute([$idKetua, $idKelompok]);

            echo json_encode(['success' => true, 'message' => 'Alhamdulillah! Ketua kelompok berhasil ditentukan.']);
            exit;
        }

        // 13D. PINDAHKAN ANGGOTA KE KELOMPOK LAIN
        if ($action === 'move_anggota_kelompok') {
            $idSiswa    = (int)($_POST['id_siswa'] ?? 0);
            $idTujuan   = (int)($_POST['id_kelompok_tujuan'] ?? 0);
            $idTugas    = (int)($_POST['id_tugas'] ?? 0);

            if ($idSiswa <= 0 || $idTujuan <= 0 || $idTugas <= 0) {
                echo json_encode(['success' => false, 'message' => 'Data pemindahan anggota tidak lengkap.']);
                exit;
            }

            if ($isStrictGuru && !isTugasDiampuGuru($pdo, $idTugas, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
                exit;
            }

            $stmtKel = $pdo->prepare("SELECT id_kelas_mata_pelajaran FROM tugas_kelompok WHERE id = ? AND id_tugas = ? LIMIT 1");
            $stmtKel->execute([$idTujuan, $idTugas]);
            $kmpId = (int)$stmtKel->fetchColumn();

            if (!$kmpId) {
                echo json_encode(['success' => false, 'message' => 'Kelompok tujuan tidak valid.']);
                exit;
            }

            // Hapus dari kelompok lama pada tugas ini
            $stmtOldKel = $pdo->prepare("
                SELECT tk.id FROM tugas_kelompok tk
                JOIN tugas_kelompok_anggota tka ON tk.id = tka.id_tugas_kelompok
                WHERE tk.id_tugas = ? AND tka.id_siswa = ? LIMIT 1
            ");
            $stmtOldKel->execute([$idTugas, $idSiswa]);
            $oldKelId = (int)$stmtOldKel->fetchColumn();

            if ($oldKelId > 0) {
                $pdo->prepare("DELETE FROM tugas_kelompok_anggota WHERE id_tugas_kelompok = ? AND id_siswa = ?")->execute([$oldKelId, $idSiswa]);
                $pdo->prepare("UPDATE tugas_kelompok SET id_ketua_kelompok = NULL WHERE id = ? AND id_ketua_kelompok = ?")->execute([$oldKelId, $idSiswa]);
            }

            // Masukkan ke kelompok baru
            $pdo->prepare("INSERT INTO tugas_kelompok_anggota (id_tugas_kelompok, id_siswa, id_kelas_mata_pelajaran, created_at) VALUES (?, ?, ?, NOW())")
                ->execute([$idTujuan, $idSiswa, $kmpId]);

            echo json_encode(['success' => true, 'message' => 'Siswa berhasil dipindahkan ke kelompok baru!']);
            exit;
        }

        // 13E. KELUARKAN ANGGOTA DARI KELOMPOK
        if ($action === 'remove_anggota_kelompok') {
            $idSiswa    = (int)($_POST['id_siswa'] ?? 0);
            $idKelompok = (int)($_POST['id_kelompok'] ?? 0);

            $stmtKel = $pdo->prepare("SELECT id_tugas FROM tugas_kelompok WHERE id = ? LIMIT 1");
            $stmtKel->execute([$idKelompok]);
            $tId = (int)$stmtKel->fetchColumn();

            if ($isStrictGuru && !isTugasDiampuGuru($pdo, $tId, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
                exit;
            }

            $pdo->prepare("DELETE FROM tugas_kelompok_anggota WHERE id_tugas_kelompok = ? AND id_siswa = ?")->execute([$idKelompok, $idSiswa]);
            $pdo->prepare("UPDATE tugas_kelompok SET id_ketua_kelompok = NULL WHERE id = ? AND id_ketua_kelompok = ?")->execute([$idKelompok, $idSiswa]);

            echo json_encode(['success' => true, 'message' => 'Siswa dikeluarkan dari kelompok.']);
            exit;
        }

        // 13F. MASUKKAN SISWA KE KELOMPOK
        if ($action === 'add_anggota_kelompok') {
            $idSiswa    = (int)($_POST['id_siswa'] ?? 0);
            $idKelompok = (int)($_POST['id_kelompok'] ?? 0);

            if ($idSiswa <= 0 || $idKelompok <= 0) {
                echo json_encode(['success' => false, 'message' => 'Data tidak lengkap.']);
                exit;
            }

            $stmtKel = $pdo->prepare("SELECT id_tugas, id_kelas_mata_pelajaran FROM tugas_kelompok WHERE id = ? LIMIT 1");
            $stmtKel->execute([$idKelompok]);
            $kelInfo = $stmtKel->fetch(PDO::FETCH_ASSOC);

            if (!$kelInfo) {
                echo json_encode(['success' => false, 'message' => 'Kelompok tidak ditemukan.']);
                exit;
            }

            if ($isStrictGuru && !isTugasDiampuGuru($pdo, $kelInfo['id_tugas'], $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
                exit;
            }

            // Hapus dari keanggotaan kelompok lain di tugas ini jika ada
            $stmtOld = $pdo->prepare("
                SELECT tk.id FROM tugas_kelompok tk
                JOIN tugas_kelompok_anggota tka ON tk.id = tka.id_tugas_kelompok
                WHERE tk.id_tugas = ? AND tka.id_siswa = ? LIMIT 1
            ");
            $stmtOld->execute([$kelInfo['id_tugas'], $idSiswa]);
            $oldKId = (int)$stmtOld->fetchColumn();
            if ($oldKId > 0) {
                $pdo->prepare("DELETE FROM tugas_kelompok_anggota WHERE id_tugas_kelompok = ? AND id_siswa = ?")->execute([$oldKId, $idSiswa]);
            }

            $pdo->prepare("INSERT INTO tugas_kelompok_anggota (id_tugas_kelompok, id_siswa, id_kelas_mata_pelajaran, created_at) VALUES (?, ?, ?, NOW())")
                ->execute([$idKelompok, $idSiswa, $kelInfo['id_kelas_mata_pelajaran']]);

            echo json_encode(['success' => true, 'message' => 'Siswa berhasil ditambahkan ke kelompok!']);
            exit;
        }

        // 13G. UBAH NAMA KELOMPOK
        if ($action === 'rename_kelompok') {
            $idKelompok = (int)($_POST['id_kelompok'] ?? 0);
            $namaBaru   = trim($_POST['nama_kelompok'] ?? '');

            if ($idKelompok <= 0 || empty($namaBaru)) {
                echo json_encode(['success' => false, 'message' => 'Nama kelompok tidak boleh kosong.']);
                exit;
            }

            $stmtKel = $pdo->prepare("SELECT id_tugas FROM tugas_kelompok WHERE id = ? LIMIT 1");
            $stmtKel->execute([$idKelompok]);
            $tId = (int)$stmtKel->fetchColumn();

            if ($isStrictGuru && !isTugasDiampuGuru($pdo, $tId, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
                exit;
            }

            $pdo->prepare("UPDATE tugas_kelompok SET nama_kelompok = ? WHERE id = ?")->execute([$namaBaru, $idKelompok]);

            echo json_encode(['success' => true, 'message' => 'Nama kelompok berhasil diperbarui!']);
            exit;
        }

        // 13H. RESET SELURUH KELOMPOK UNTUK SEBUAH TUGAS
        if ($action === 'reset_semua_kelompok') {
            $idTugas = (int)($_POST['id_tugas'] ?? 0);
            if ($idTugas <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID tugas tidak valid.']);
                exit;
            }

            if ($isStrictGuru && !isTugasDiampuGuru($pdo, $idTugas, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
                exit;
            }

            $pdo->prepare("DELETE FROM tugas_kelompok_anggota WHERE id_tugas_kelompok IN (SELECT id FROM tugas_kelompok WHERE id_tugas = ?)")->execute([$idTugas]);
            $pdo->prepare("DELETE FROM tugas_kelompok WHERE id_tugas = ?")->execute([$idTugas]);

            echo json_encode(['success' => true, 'message' => 'Seluruh kelompok untuk tugas ini berhasil di-reset.']);
            exit;
        }

        // ======================================================================
        // PEMERIKSAAN & PENILAIAN SISWA (TAB 2)
        // ======================================================================

        // 14. DAFTAR PENGUMPULAN SISWA
        if ($action === 'list_pengumpulan') {
            $idTugas   = (int)($_POST['id_tugas'] ?? 0);
            $filter    = trim($_POST['filter_status'] ?? 'all');
            $search    = trim($_POST['search'] ?? '');

            if ($idTugas <= 0) {
                echo json_encode(['success' => false, 'message' => 'Pilih tugas terlebih dahulu.']);
                exit;
            }

            // Validasi Hak Mengajar Guru: Hanya bisa melihat tugas di kelas yang diampu
            if ($isStrictGuru && !isTugasDiampuGuru($pdo, $idTugas, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat melihat dan menilai tugas dari kelas yang Anda ampu.']);
                exit;
            }

            // Info Tugas & Kelas
            $stmtT = $pdo->prepare("
                SELECT t.*, km.id_kelas, km.nama_kelas, km.nama_mata_pelajaran
                FROM tugas t
                JOIN kelas_mapel km ON t.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
                WHERE t.id = ? LIMIT 1
            ");
            $stmtT->execute([$idTugas]);
            $tugasInfo = $stmtT->fetch(PDO::FETCH_ASSOC);

            if (!$tugasInfo) {
                echo json_encode(['success' => false, 'message' => 'Tugas tidak ditemukan.']);
                exit;
            }

            $isKelompok = ($tugasInfo['jenis_penugasan'] === 'Kelompok');

            // Ambil semua pengumpulan tugas untuk tugas ini (tanpa duplikasi baris kelompok)
            $stmtPeng = $pdo->prepare("
                SELECT 
                    pt.*,
                    s.nama_lengkap AS nama_siswa,
                    s.nisn,
                    s.nis,
                    s.foto AS foto_siswa,
                    tk.nama_kelompok,
                    tk.nomor_kelompok,
                    (SELECT nt.id FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND (nt.id_pengumpulan_tugas = pt.id OR nt.id_siswa = pt.id_siswa) LIMIT 1) AS id_nilai,
                    (SELECT nt.nilai_kelompok FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND (nt.id_pengumpulan_tugas = pt.id OR nt.id_siswa = pt.id_siswa) LIMIT 1) AS nilai_kelompok,
                    (SELECT nt.nilai_bagi_kelompok FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND (nt.id_pengumpulan_tugas = pt.id OR nt.id_siswa = pt.id_siswa) LIMIT 1) AS nilai_bagi_kelompok,
                    (SELECT nt.nilai FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND nt.id_siswa = pt.id_siswa LIMIT 1) AS nilai,
                    (SELECT nt.nilai_individu FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND nt.id_siswa = pt.id_siswa LIMIT 1) AS nilai_individu,
                    (SELECT nt.nilai_remedial FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND nt.id_siswa = pt.id_siswa LIMIT 1) AS nilai_remedial,
                    (SELECT nt.predikat FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND nt.id_siswa = pt.id_siswa LIMIT 1) AS predikat,
                    (SELECT nt.aspek_itp_ketercapaian FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND (nt.id_pengumpulan_tugas = pt.id OR nt.id_siswa = pt.id_siswa) LIMIT 1) AS aspek_itp_ketercapaian,
                    (SELECT nt.catatan_guru FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND (nt.id_pengumpulan_tugas = pt.id OR nt.id_siswa = pt.id_siswa) LIMIT 1) AS catatan_guru,
                    (SELECT nt.status_penilaian FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND (nt.id_pengumpulan_tugas = pt.id OR nt.id_siswa = pt.id_siswa) LIMIT 1) AS status_penilaian,
                    (SELECT nt.waktu_penilaian FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND (nt.id_pengumpulan_tugas = pt.id OR nt.id_siswa = pt.id_siswa) LIMIT 1) AS waktu_penilaian
                FROM pengumpulan_tugas pt
                LEFT JOIN siswa s ON pt.id_siswa = s.id
                LEFT JOIN tugas_kelompok tk ON pt.id_tugas_kelompok = tk.id
                WHERE pt.id_tugas = ?
                GROUP BY pt.id
                ORDER BY pt.waktu_pengumpulan DESC
            ");
            $stmtPeng->execute([$idTugas]);
            $submissions = $stmtPeng->fetchAll(PDO::FETCH_ASSOC);

            // Filter status jika diminta
            if ($filter === 'belum_dinilai') {
                $submissions = array_filter($submissions, function($r) {
                    return empty($r['status_penilaian']) || $r['status_penilaian'] === 'Draft';
                });
            } elseif ($filter === 'sudah_dinilai') {
                $submissions = array_filter($submissions, function($r) {
                    return in_array($r['status_penilaian'], ['Dinilai', 'Tuntas']);
                });
            } elseif ($filter === 'perlu_revisi') {
                $submissions = array_filter($submissions, function($r) {
                    return ($r['status_penilaian'] === 'Perlu Revisi');
                });
            } elseif ($filter === 'terlambat') {
                $submissions = array_filter($submissions, function($r) {
                    return ((int)$r['is_terlambat'] === 1 || $r['status_pengumpulan'] === 'Terlambat');
                });
            }

            if (!empty($search)) {
                $q = strtolower($search);
                $submissions = array_filter($submissions, function($r) use ($q) {
                    return strpos(strtolower($r['nama_siswa'] ?? ''), $q) !== false ||
                           strpos(strtolower($r['nama_kelompok'] ?? ''), $q) !== false ||
                           strpos(strtolower($r['nisn'] ?? ''), $q) !== false;
                });
            }

            // Re-index array
            $submissions = array_values($submissions);

            // Jika kelompok, ambilkan juga anggota kelompok beserta nilai masing-masing untuk tiap pengumpulan
            if ($isKelompok) {
                foreach ($submissions as &$sub) {
                    if (!empty($sub['id_tugas_kelompok'])) {
                        $stmtAng = $pdo->prepare("
                            SELECT s.id, s.nama_lengkap, s.nisn, s.nis, s.foto,
                                   nt.nilai, nt.nilai_individu, nt.nilai_kelompok, nt.nilai_bagi_kelompok, nt.predikat
                            FROM tugas_kelompok_anggota tka
                            JOIN siswa s ON tka.id_siswa = s.id
                            LEFT JOIN nilai_tugas nt ON nt.id_tugas = ? AND nt.id_siswa = s.id
                            WHERE tka.id_tugas_kelompok = ?
                            ORDER BY s.nama_lengkap ASC
                        ");
                        $stmtAng->execute([$idTugas, $sub['id_tugas_kelompok']]);
                        $sub['anggota_kelompok'] = $stmtAng->fetchAll(PDO::FETCH_ASSOC);
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'data'    => $submissions,
                'tugas'   => $tugasInfo
            ]);
            exit;
        }

        // 15. DETAIL PENGUMPULAN UNTUK MODAL PENILAIAN & VIEW IN-PAGE
        if ($action === 'get_submission_detail') {
            $idSub = (int)($_POST['id_pengumpulan'] ?? 0);
            if ($idSub <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID pengumpulan tidak valid.']);
                exit;
            }

            // Validasi Hak Mengajar Guru: Hanya bisa mengakses pengumpulan dari kelas yang diampu
            if ($isStrictGuru && !isPengumpulanDiampuGuru($pdo, $idSub, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Berkas pengumpulan bukan dari kelas yang Anda ampu.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT 
                    pt.*,
                    s.nama_lengkap AS nama_siswa,
                    s.nisn,
                    s.nis,
                    s.foto AS foto_siswa,
                    t.judul_tugas,
                    t.jenis_penugasan,
                    t.nilai_maksimal,
                    t.bobot_nilai,
                    t.tenggat_waktu,
                    km.nama_kelas,
                    km.nama_mata_pelajaran,
                    tk.nama_kelompok,
                    tk.nomor_kelompok,
                    (SELECT nt.id FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND (nt.id_pengumpulan_tugas = pt.id OR nt.id_siswa = pt.id_siswa) LIMIT 1) AS id_nilai,
                    (SELECT nt.nilai FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND nt.id_siswa = pt.id_siswa LIMIT 1) AS nilai,
                    (SELECT nt.nilai_individu FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND nt.id_siswa = pt.id_siswa LIMIT 1) AS nilai_individu,
                    (SELECT nt.nilai_kelompok FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND (nt.id_pengumpulan_tugas = pt.id OR nt.id_siswa = pt.id_siswa) LIMIT 1) AS nilai_kelompok,
                    (SELECT nt.nilai_bagi_kelompok FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND (nt.id_pengumpulan_tugas = pt.id OR nt.id_siswa = pt.id_siswa) LIMIT 1) AS nilai_bagi_kelompok,
                    (SELECT nt.jumlah_anggota_kelompok FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND (nt.id_pengumpulan_tugas = pt.id OR nt.id_siswa = pt.id_siswa) LIMIT 1) AS jumlah_anggota_kelompok,
                    (SELECT nt.nilai_remedial FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND nt.id_siswa = pt.id_siswa LIMIT 1) AS nilai_remedial,
                    (SELECT nt.predikat FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND nt.id_siswa = pt.id_siswa LIMIT 1) AS predikat,
                    (SELECT nt.aspek_itp_ketercapaian FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND (nt.id_pengumpulan_tugas = pt.id OR nt.id_siswa = pt.id_siswa) LIMIT 1) AS aspek_itp_ketercapaian,
                    (SELECT nt.catatan_guru FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND (nt.id_pengumpulan_tugas = pt.id OR nt.id_siswa = pt.id_siswa) LIMIT 1) AS catatan_guru,
                    (SELECT nt.status_penilaian FROM nilai_tugas nt WHERE nt.id_tugas = pt.id_tugas AND (nt.id_pengumpulan_tugas = pt.id OR nt.id_siswa = pt.id_siswa) LIMIT 1) AS status_penilaian
                FROM pengumpulan_tugas pt
                JOIN tugas t ON pt.id_tugas = t.id
                JOIN kelas_mapel km ON t.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
                LEFT JOIN siswa s ON pt.id_siswa = s.id
                LEFT JOIN tugas_kelompok tk ON pt.id_tugas_kelompok = tk.id
                WHERE pt.id = ?
                LIMIT 1
            ");
            $stmt->execute([$idSub]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sub) {
                echo json_encode(['success' => false, 'message' => 'Data pengumpulan tidak ditemukan.']);
                exit;
            }

            // Jika tugas kelompok, sertakan daftar anggota beserta rincian nilai masing-masing
            $sub['anggota_kelompok'] = [];
            if (!empty($sub['id_tugas_kelompok'])) {
                $stmtAng = $pdo->prepare("
                    SELECT s.id, s.nama_lengkap, s.nisn, s.nis, s.foto,
                           nt.id AS id_nilai, nt.nilai, nt.nilai_individu, nt.nilai_kelompok, nt.nilai_bagi_kelompok, nt.predikat
                    FROM tugas_kelompok_anggota tka
                    JOIN siswa s ON tka.id_siswa = s.id
                    LEFT JOIN nilai_tugas nt ON nt.id_tugas = ? AND nt.id_siswa = s.id
                    WHERE tka.id_tugas_kelompok = ?
                    ORDER BY s.nama_lengkap ASC
                ");
                $stmtAng->execute([$sub['id_tugas'], $sub['id_tugas_kelompok']]);
                $sub['anggota_kelompok'] = $stmtAng->fetchAll(PDO::FETCH_ASSOC);

                // Sinkronisasi nilai_kelompok jika belum ada di $sub
                foreach ($sub['anggota_kelompok'] as $ak) {
                    if ($ak['nilai_kelompok'] !== null) {
                        $sub['nilai_kelompok'] = $ak['nilai_kelompok'];
                        $sub['nilai_bagi_kelompok'] = $ak['nilai_bagi_kelompok'];
                        break;
                    }
                }
            }

            echo json_encode(['success' => true, 'data' => $sub]);
            exit;
        }

        // 16. SIMPAN PENILAIAN TUGAS (NILAI KELOMPOK, NILAI INDIVIDU, PREDIKAT, FEEDBACK)
        if ($action === 'save_penilaian') {
            $idSub         = (int)($_POST['id_pengumpulan'] ?? 0);
            $catatan       = trim($_POST['catatan_guru'] ?? '');
            $aspekItp      = in_array($_POST['aspek_itp'] ?? '', ['Tercapai Sangat Baik', 'Tercapai', 'Cukup Tercapai', 'Perlu Bimbingan']) ? $_POST['aspek_itp'] : 'Tercapai';
            $statusNilai   = in_array($_POST['status_penilaian'] ?? '', ['Dinilai', 'Perlu Revisi', 'Tuntas']) ? $_POST['status_penilaian'] : 'Dinilai';

            if ($idSub <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID pengumpulan tidak valid.']);
                exit;
            }

            // Validasi Hak Mengajar Guru: Hanya bisa menilai pengumpulan dari kelas yang diampu
            if ($isStrictGuru && !isPengumpulanDiampuGuru($pdo, $idSub, $idGuruLogged, false)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat menilai tugas siswa di kelas yang Anda ampu.']);
                exit;
            }

            // Dapatkan detail pengumpulan & tugas
            $stmtSub = $pdo->prepare("
                SELECT pt.*, t.nilai_maksimal, t.jenis_penugasan
                FROM pengumpulan_tugas pt
                JOIN tugas t ON pt.id_tugas = t.id
                WHERE pt.id = ? LIMIT 1
            ");
            $stmtSub->execute([$idSub]);
            $sub = $stmtSub->fetch(PDO::FETCH_ASSOC);

            if (!$sub) {
                echo json_encode(['success' => false, 'message' => 'Pengumpulan tidak ditemukan.']);
                exit;
            }

            $idTugas   = (int)$sub['id_tugas'];
            $idKmp     = (int)$sub['id_kelas_mata_pelajaran'];
            $idKel     = !empty($sub['id_tugas_kelompok']) ? (int)$sub['id_tugas_kelompok'] : null;
            $idGuruVal = $idGuruLogged ?: $currentUser['id'];
            $nilaiMax  = (float)$sub['nilai_maksimal'];

            $isKelompok = ($sub['jenis_penugasan'] === 'Kelompok' || !empty($idKel));

            if ($isKelompok && $idKel) {
                // ==============================================================
                // PENILAIAN TUGAS KELOMPOK (RUMUS ADIL & SEIMBANG):
                // 1. Nilai Kelompok (Karya Bersama): Berlaku setara bagi seluruh anggota kelompok (Skala 0-100)
                // 2. Nilai Individu (Kontribusi Pribadi): Dinilai spesifik per santri (Skala 0-100)
                // 3. Nilai Akhir Santri = (Nilai Kelompok + Nilai Individu) / 2
                // Contoh: Kelompok 100 & Individu 100 -> (100 + 100) / 2 = 100 (Adil, tidak memberatkan)
                // ==============================================================
                $nilaiKelompok = isset($_POST['nilai_kelompok']) ? (float)$_POST['nilai_kelompok'] : (float)($_POST['nilai'] ?? 0);
                $nilaiKelompok = min($nilaiMax, max(0, $nilaiKelompok));

                // Ambil daftar anggota kelompok terdaftar
                $stmtAng = $pdo->prepare("SELECT id_siswa FROM tugas_kelompok_anggota WHERE id_tugas_kelompok = ?");
                $stmtAng->execute([$idKel]);
                $dbAnggotaIds = $stmtAng->fetchAll(PDO::FETCH_COLUMN);

                // Tambahkan siswa submitter jika belum ada
                if (!empty($sub['id_siswa']) && !in_array($sub['id_siswa'], $dbAnggotaIds)) {
                    $dbAnggotaIds[] = (int)$sub['id_siswa'];
                }

                $totalAnggota = count($dbAnggotaIds);
                if ($totalAnggota <= 0) $totalAnggota = 1;

                // Nilai kelompok utuh per anggota (skala penuh 0-100)
                $nilaiBagiKelompok = $nilaiKelompok;

                // Ambil input nilai individu per siswa (jika dikirim via form JSON atau array)
                $anggotaScoresRaw = $_POST['anggota_scores'] ?? '';
                $scoreMap = [];
                if (is_string($anggotaScoresRaw) && !empty($anggotaScoresRaw)) {
                    $decoded = json_decode($anggotaScoresRaw, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $as) {
                            $sid = (int)($as['id_siswa'] ?? 0);
                            if ($sid > 0) $scoreMap[$sid] = (float)($as['nilai_individu'] ?? 0);
                        }
                    }
                } elseif (is_array($anggotaScoresRaw)) {
                    foreach ($anggotaScoresRaw as $sid => $val) {
                        $scoreMap[(int)$sid] = (float)$val;
                    }
                }

                // Default fallback nilai individu jika tidak diisi spesifik
                $defaultNilaiIndividu = isset($_POST['nilai']) ? (float)$_POST['nilai'] : $nilaiKelompok;

                foreach ($dbAnggotaIds as $targetSid) {
                    $targetSid = (int)$targetSid;
                    $nilaiIndividu = isset($scoreMap[$targetSid]) ? (float)$scoreMap[$targetSid] : $defaultNilaiIndividu;
                    $nilaiIndividu = min($nilaiMax, max(0, $nilaiIndividu));

                    // RUMUS ADIL & SEIMBANG: (Nilai Kelompok + Nilai Individu) / 2
                    $nilaiAkhir = round(($nilaiKelompok + $nilaiIndividu) / 2, 2);
                    $nilaiAkhir = min($nilaiMax, max(0, $nilaiAkhir));
                    $predikat   = hitungPredikatNilai($nilaiAkhir);

                    // Simpan / update ke database nilai_tugas
                    $stmtCheck = $pdo->prepare("SELECT id FROM nilai_tugas WHERE id_tugas = ? AND id_siswa = ? LIMIT 1");
                    $stmtCheck->execute([$idTugas, $targetSid]);
                    $existingNilaiId = $stmtCheck->fetchColumn();

                    if ($existingNilaiId) {
                        $pdo->prepare("
                            UPDATE nilai_tugas SET
                                id_pengumpulan_tugas    = ?,
                                id_guru                 = ?,
                                nilai                   = ?,
                                nilai_kelompok          = ?,
                                nilai_bagi_kelompok     = ?,
                                nilai_individu          = ?,
                                jumlah_anggota_kelompok = ?,
                                predikat                = ?,
                                aspek_itp_ketercapaian  = ?,
                                catatan_guru            = ?,
                                status_penilaian        = ?,
                                waktu_penilaian         = NOW(),
                                updated_at              = NOW()
                            WHERE id = ?
                        ")->execute([
                            $idSub, $idGuruVal, $nilaiAkhir, $nilaiKelompok, $nilaiBagiKelompok, $nilaiIndividu, $totalAnggota,
                            $predikat, $aspekItp, $catatan, $statusNilai, $existingNilaiId
                        ]);
                    } else {
                        $pdo->prepare("
                            INSERT INTO nilai_tugas (
                                id_tugas, id_pengumpulan_tugas, id_siswa, id_kelas_mata_pelajaran, id_guru,
                                nilai, nilai_kelompok, nilai_bagi_kelompok, nilai_individu, jumlah_anggota_kelompok,
                                predikat, aspek_itp_ketercapaian, catatan_guru, status_penilaian, waktu_penilaian,
                                created_at, updated_at
                            ) VALUES (
                                ?, ?, ?, ?, ?,
                                ?, ?, ?, ?, ?,
                                ?, ?, ?, ?, NOW(),
                                NOW(), NOW()
                            )
                        ")->execute([
                            $idTugas, $idSub, $targetSid, $idKmp, $idGuruVal,
                            $nilaiAkhir, $nilaiKelompok, $nilaiBagiKelompok, $nilaiIndividu, $totalAnggota,
                            $predikat, $aspekItp, $catatan, $statusNilai
                        ]);
                    }
                }

                // Update status pengumpulan jika perlu revisi
                if ($statusNilai === 'Perlu Revisi') {
                    $pdo->prepare("UPDATE pengumpulan_tugas SET status_pengumpulan = 'Menunggu Revisi' WHERE id = ?")->execute([$idSub]);
                }

                echo json_encode([
                    'success' => true,
                    'message' => "Alhamdulillah! Nilai tugas kelompok tersimpan untuk {$totalAnggota} santri (Nilai Kelompok: {$nilaiKelompok}, Nilai Akhir seimbang)."
                ]);
                exit;

            } else {
                // ==============================================================
                // PENILAIAN TUGAS INDIVIDU / MANDIRI
                // ==============================================================
                $nilai         = (float)($_POST['nilai'] ?? 0);
                $nilaiRemedial = !empty($_POST['nilai_remedial']) ? (float)$_POST['nilai_remedial'] : null;
                $effectiveScore = ($nilaiRemedial !== null && $nilaiRemedial > $nilai) ? $nilaiRemedial : $nilai;
                $predikat = hitungPredikatNilai($effectiveScore);
                $idSiswa = (int)$sub['id_siswa'];

                $stmtCheck = $pdo->prepare("SELECT id FROM nilai_tugas WHERE id_tugas = ? AND id_siswa = ? LIMIT 1");
                $stmtCheck->execute([$idTugas, $idSiswa]);
                $existingNilaiId = $stmtCheck->fetchColumn();

                if ($existingNilaiId) {
                    $pdo->prepare("
                        UPDATE nilai_tugas SET
                            id_pengumpulan_tugas   = ?,
                            id_guru                = ?,
                            nilai                  = ?,
                            nilai_individu         = ?,
                            nilai_remedial         = ?,
                            nilai_akhir_remedial   = ?,
                            predikat               = ?,
                            aspek_itp_ketercapaian = ?,
                            catatan_guru           = ?,
                            status_penilaian       = ?,
                            waktu_penilaian        = NOW(),
                            updated_at             = NOW()
                        WHERE id = ?
                    ")->execute([
                        $idSub, $idGuruVal, $nilai, $nilai, $nilaiRemedial,
                        ($nilaiRemedial !== null) ? max($nilai, $nilaiRemedial) : null,
                        $predikat, $aspekItp, $catatan, $statusNilai, $existingNilaiId
                    ]);
                } else {
                    $pdo->prepare("
                        INSERT INTO nilai_tugas (
                            id_tugas, id_pengumpulan_tugas, id_siswa, id_kelas_mata_pelajaran, id_guru,
                            nilai, nilai_individu, nilai_remedial, nilai_akhir_remedial, predikat,
                            aspek_itp_ketercapaian, catatan_guru, status_penilaian, waktu_penilaian,
                            created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?,
                            ?, ?, ?, NOW(),
                            NOW(), NOW()
                        )
                    ")->execute([
                        $idTugas, $idSub, $idSiswa, $idKmp, $idGuruVal,
                        $nilai, $nilai, $nilaiRemedial,
                        ($nilaiRemedial !== null) ? max($nilai, $nilaiRemedial) : null,
                        $predikat, $aspekItp, $catatan, $statusNilai
                    ]);
                }

                if ($statusNilai === 'Perlu Revisi') {
                    $pdo->prepare("UPDATE pengumpulan_tugas SET status_pengumpulan = 'Menunggu Revisi' WHERE id = ?")->execute([$idSub]);
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Alhamdulillah! Nilai tugas siswa berhasil disimpan.'
                ]);
                exit;
            }
        }

        // ======================================================================
        // GOOGLE APPS SCRIPT SETTINGS (GAS TUGAS)
        // ======================================================================

        // 17. SIMPAN KONFIGURASI GAS TUGAS
        if ($action === 'save_gas_setting') {
            $url = trim($_POST['gas_tugas_url'] ?? '');
            $stmt = $pdo->prepare("
                INSERT INTO app_settings (setting_key, setting_value, updated_at)
                VALUES ('gas_tugas_url', :val, NOW())
                ON DUPLICATE KEY UPDATE setting_value = :val, updated_at = NOW()
            ");
            $stmt->execute([':val' => $url]);

            echo json_encode(['success' => true, 'message' => 'Pengaturan GAS Tugas berhasil disimpan.']);
            exit;
        }

        // 18. TEST KONEKSI GAS TUGAS
        if ($action === 'test_gas_connection') {
            $gasUrl = getGasTugasUrl($pdo);
            if (empty($gasUrl)) {
                echo json_encode(['success' => false, 'message' => 'URL GAS Tugas belum diatur.']);
                exit;
            }

            $ch = curl_init($gasUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($err) {
                echo json_encode(['success' => false, 'message' => 'Koneksi gagal: ' . $err]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Alhamdulillah! Koneksi ke Web App Google Apps Script berhasil (HTTP ' . $code . ').'
            ]);
            exit;
        }

        // ======================================================================
        // BULK ACTION 1: UBAH STATUS PENUGASAN MASSAL (BUKA / TUTUP / DRAFT)
        // ======================================================================
        if ($action === 'bulk_status_tugas') {
            $ids = $_POST['ids'] ?? [];
            if (is_string($ids)) $ids = json_decode($ids, true) ?: explode(',', $ids);
            $newStatus = in_array($_POST['status'] ?? '', ['Dibuka', 'Ditutup', 'Draft']) ? $_POST['status'] : 'Dibuka';

            if (empty($ids) || !is_array($ids)) {
                echo json_encode(['success' => false, 'message' => 'Tidak ada tugas yang dipilih.']);
                exit;
            }

            $successCount = 0;
            $stmtUp = $pdo->prepare("UPDATE tugas SET status_tugas = ? WHERE id = ?");

            foreach ($ids as $tId) {
                $tId = (int)$tId;
                if ($tId <= 0) continue;
                if ($isStrictGuru && !isTugasDiampuGuru($pdo, $tId, $idGuruLogged, false)) {
                    continue;
                }
                $stmtUp->execute([$newStatus, $tId]);
                $successCount++;
            }

            echo json_encode([
                'success' => true,
                'message' => "Alhamdulillah! Status {$successCount} tugas berhasil diubah menjadi '{$newStatus}'."
            ]);
            exit;
        }

        // ======================================================================
        // BULK ACTION 2: HAPUS PENUGASAN MASSAL
        // ======================================================================
        if ($action === 'bulk_delete_tugas') {
            $ids = $_POST['ids'] ?? [];
            if (is_string($ids)) $ids = json_decode($ids, true) ?: explode(',', $ids);

            if (empty($ids) || !is_array($ids)) {
                echo json_encode(['success' => false, 'message' => 'Tidak ada tugas yang dipilih untuk dihapus.']);
                exit;
            }

            $delCount = 0;
            $stmtDelAng = $pdo->prepare("DELETE FROM tugas_kelompok_anggota WHERE id_tugas_kelompok IN (SELECT id FROM tugas_kelompok WHERE id_tugas = ?)");
            $stmtDelKel = $pdo->prepare("DELETE FROM tugas_kelompok WHERE id_tugas = ?");
            $stmtDelNil = $pdo->prepare("DELETE FROM nilai_tugas WHERE id_tugas = ?");
            $stmtDelSub = $pdo->prepare("DELETE FROM pengumpulan_tugas WHERE id_tugas = ?");
            $stmtDelTug = $pdo->prepare("DELETE FROM tugas WHERE id = ?");

            foreach ($ids as $tId) {
                $tId = (int)$tId;
                if ($tId <= 0) continue;
                if ($isStrictGuru && !isTugasDiampuGuru($pdo, $tId, $idGuruLogged, false)) {
                    continue;
                }
                $stmtDelAng->execute([$tId]);
                $stmtDelKel->execute([$tId]);
                $stmtDelNil->execute([$tId]);
                $stmtDelSub->execute([$tId]);
                $stmtDelTug->execute([$tId]);
                $delCount++;
            }

            echo json_encode([
                'success' => true,
                'message' => "Alhamdulillah! Sebanyak {$delCount} penugasan berhasil dihapus."
            ]);
            exit;
        }

        // ======================================================================
        // BULK ACTION 3: PENILAIAN PENGUMPULAN MASSAL (BULK GRADE)
        // ======================================================================
        if ($action === 'bulk_grade_submissions') {
            $ids = $_POST['ids'] ?? [];
            if (is_string($ids)) $ids = json_decode($ids, true) ?: explode(',', $ids);
            $scoreVal = (float)($_POST['nilai'] ?? 100);
            $catatan = trim($_POST['catatan_guru'] ?? '');
            $aspekItp = in_array($_POST['aspek_itp'] ?? '', ['Tercapai Sangat Baik', 'Tercapai', 'Cukup Tercapai', 'Perlu Bimbingan']) ? $_POST['aspek_itp'] : 'Tercapai';
            $statusPenilaian = in_array($_POST['status_penilaian'] ?? '', ['Dinilai', 'Tuntas', 'Perlu Revisi']) ? $_POST['status_penilaian'] : 'Dinilai';
            $idGuruVal = $idGuruLogged ?: $currentUser['id'];

            if (empty($ids) || !is_array($ids)) {
                echo json_encode(['success' => false, 'message' => 'Tidak ada berkas pengumpulan yang dipilih.']);
                exit;
            }

            $gradedCount = 0;
            foreach ($ids as $subId) {
                $subId = (int)$subId;
                if ($subId <= 0) continue;
                if ($isStrictGuru && !isPengumpulanDiampuGuru($pdo, $subId, $idGuruLogged, false)) {
                    continue;
                }

                $stmtS = $pdo->prepare("SELECT pt.*, t.nilai_maksimal, t.jenis_penugasan FROM pengumpulan_tugas pt JOIN tugas t ON pt.id_tugas = t.id WHERE pt.id = ? LIMIT 1");
                $stmtS->execute([$subId]);
                $subRow = $stmtS->fetch(PDO::FETCH_ASSOC);
                if (!$subRow) continue;

                $tId = (int)$subRow['id_tugas'];
                $kmpId = (int)$subRow['id_kelas_mata_pelajaran'];
                $idKel = !empty($subRow['id_tugas_kelompok']) ? (int)$subRow['id_tugas_kelompok'] : null;
                $effectiveScore = min((float)$subRow['nilai_maksimal'], max(0, $scoreVal));
                $pred = hitungPredikatNilai($effectiveScore);

                if (!empty($idKel)) {
                    // Kelompok: Seluruh anggota kelompok menerima nilai
                    $stmtAng = $pdo->prepare("SELECT id_siswa FROM tugas_kelompok_anggota WHERE id_tugas_kelompok = ?");
                    $stmtAng->execute([$idKel]);
                    $angIds = $stmtAng->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($subRow['id_siswa']) && !in_array($subRow['id_siswa'], $angIds)) {
                        $angIds[] = (int)$subRow['id_siswa'];
                    }
                    $totAng = count($angIds) ?: 1;

                    foreach ($angIds as $sid) {
                        $sid = (int)$sid;
                        $stmtCheck = $pdo->prepare("SELECT id FROM nilai_tugas WHERE id_tugas = ? AND id_siswa = ? LIMIT 1");
                        $stmtCheck->execute([$tId, $sid]);
                        $exId = $stmtCheck->fetchColumn();

                        if ($exId) {
                            $pdo->prepare("UPDATE nilai_tugas SET id_pengumpulan_tugas=?, id_guru=?, nilai=?, nilai_kelompok=?, nilai_bagi_kelompok=?, nilai_individu=?, jumlah_anggota_kelompok=?, predikat=?, aspek_itp_ketercapaian=?, catatan_guru=?, status_penilaian=?, waktu_penilaian=NOW(), updated_at=NOW() WHERE id=?")->execute([
                                $subId, $idGuruVal, $effectiveScore, $effectiveScore, $effectiveScore, $effectiveScore, $totAng, $pred, $aspekItp, $catatan, $statusPenilaian, $exId
                            ]);
                        } else {
                            $pdo->prepare("INSERT INTO nilai_tugas (id_tugas, id_pengumpulan_tugas, id_siswa, id_kelas_mata_pelajaran, id_guru, nilai, nilai_kelompok, nilai_bagi_kelompok, nilai_individu, jumlah_anggota_kelompok, predikat, aspek_itp_ketercapaian, catatan_guru, status_penilaian, waktu_penilaian, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())")->execute([
                                $tId, $subId, $sid, $kmpId, $idGuruVal, $effectiveScore, $effectiveScore, $effectiveScore, $effectiveScore, $totAng, $pred, $aspekItp, $catatan, $statusPenilaian
                            ]);
                        }
                    }
                } else {
                    // Individu
                    $sid = (int)$subRow['id_siswa'];
                    $stmtCheck = $pdo->prepare("SELECT id FROM nilai_tugas WHERE id_tugas = ? AND id_siswa = ? LIMIT 1");
                    $stmtCheck->execute([$tId, $sid]);
                    $exId = $stmtCheck->fetchColumn();

                    if ($exId) {
                        $pdo->prepare("UPDATE nilai_tugas SET id_pengumpulan_tugas=?, id_guru=?, nilai=?, nilai_individu=?, predikat=?, aspek_itp_ketercapaian=?, catatan_guru=?, status_penilaian=?, waktu_penilaian=NOW(), updated_at=NOW() WHERE id=?")->execute([
                            $subId, $idGuruVal, $effectiveScore, $effectiveScore, $pred, $aspekItp, $catatan, $statusPenilaian, $exId
                        ]);
                    } else {
                        $pdo->prepare("INSERT INTO nilai_tugas (id_tugas, id_pengumpulan_tugas, id_siswa, id_kelas_mata_pelajaran, id_guru, nilai, nilai_individu, predikat, aspek_itp_ketercapaian, catatan_guru, status_penilaian, waktu_penilaian, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())")->execute([
                            $tId, $subId, $sid, $kmpId, $idGuruVal, $effectiveScore, $effectiveScore, $pred, $aspekItp, $catatan, $statusPenilaian
                        ]);
                    }
                }

                if ($statusPenilaian === 'Perlu Revisi') {
                    $pdo->prepare("UPDATE pengumpulan_tugas SET status_pengumpulan = 'Menunggu Revisi' WHERE id = ?")->execute([$subId]);
                }

                $gradedCount++;
            }

            echo json_encode([
                'success' => true,
                'message' => "Alhamdulillah! Berhasil menilai massal {$gradedCount} pengumpulan dengan nilai {$scoreVal}."
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.']);
        exit;

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan server: ' . $e->getMessage()]);
        exit;
    }
}

// ------------------------------------------------------------------------------
// RENDER VIEW HALAMAN
// ------------------------------------------------------------------------------
$pageTitle = 'Tugas & Penilaian Siswa';
$activeGasTugasUrl = getGasTugasUrl($pdo);

require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/modal-template.php';
require_once BASE_PATH . '/includes/calendar-template.php';
require_once BASE_PATH . '/includes/table-template.php';
?>

<!-- LOCAL ASSETS PDF.JS (HTML5 CANVAS VIEWER - BEBAS TOMBOL UNDUH / DOWNLOAD) -->
<script src="<?= BASE_URL ?>/assets/js/pdf.min.js"></script>

<style>
/* ==============================================================================
 * LUMINA GOLD & EMERALD — STYLE MANAJEMEN PENUGASAN GURU
 * ============================================================================== */
:root {
    --lumina-navy: #1D3B5E;
    --lumina-navy-dark: #0F172A;
    --lumina-teal: #0D7A87;
    --lumina-sky: #52B2DC;
    --lumina-emerald: #10B981;
    --lumina-emerald-dark: #0F6B38;
    --lumina-gold: #F59E0B;
    --lumina-rose: #F43F5E;
    --lumina-border: #E2E8F0;
    --lumina-bg-card: #FFFFFF;
}

.tgs-container {
    padding: 6px 0 40px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    color: #334155;
}

/* TOP HEADER & TITLE */
.tgs-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 24px;
}
.tgs-header__title {
    display: flex;
    align-items: center;
    gap: 14px;
}
.tgs-header__icon {
    width: 52px;
    height: 52px;
    border-radius: 16px;
    background: linear-gradient(135deg, #0D7A87 0%, #0F6B38 100%);
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    box-shadow: 0 10px 25px -8px rgba(13, 122, 135, 0.45);
}
.tgs-header h2 {
    font-family: 'Outfit', sans-serif;
    font-size: 24px;
    font-weight: 800;
    color: var(--lumina-navy);
    margin: 0;
    line-height: 1.2;
}
.tgs-header p {
    font-size: 13.5px;
    color: #64748B;
    margin: 4px 0 0;
}
.tgs-header__actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

/* BUTTONS */
.btn-tgs {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 12px;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    border: none;
    text-decoration: none;
    line-height: 1;
    white-space: nowrap;
}
.btn-tgs:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px -6px rgba(0, 0, 0, 0.15);
}
.btn-tgs--primary {
    background: linear-gradient(135deg, #0D7A87 0%, #0F6B38 100%);
    color: #FFFFFF;
}
.btn-tgs--gold {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: #FFFFFF;
}
.btn-tgs--secondary {
    background: #FFFFFF;
    color: var(--lumina-navy);
    border: 1px solid var(--lumina-border);
}
.btn-tgs--secondary:hover {
    background: #F8FAFC;
    border-color: #CBD5E1;
}
.btn-tgs--rose {
    background: linear-gradient(135deg, #E11D48 0%, #BE123C 100%);
    color: #FFFFFF;
}
.btn-tgs--amber {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: #FFFFFF;
}
.btn-tgs--purple {
    background: linear-gradient(135deg, #7C3AED 0%, #4F46E5 100%);
    color: #FFFFFF;
}
.btn-tgs--sm {
    padding: 6px 12px;
    font-size: 12px;
    border-radius: 8px;
}

/* STATS ROW */
.tgs-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}
.tgs-stat-card {
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    border-radius: 16px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 4px 15px -4px rgba(0, 0, 0, 0.03);
    transition: transform 0.2s, box-shadow 0.2s;
}
.tgs-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px -6px rgba(0, 0, 0, 0.08);
}
.tgs-stat-card__icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}
.tgs-stat-card__icon--blue { background: rgba(82, 178, 220, 0.15); color: #0284C7; }
.tgs-stat-card__icon--green { background: rgba(16, 185, 129, 0.15); color: #059669; }
.tgs-stat-card__icon--purple { background: rgba(139, 92, 246, 0.15); color: #7C3AED; }
.tgs-stat-card__icon--amber { background: rgba(245, 158, 11, 0.15); color: #D97706; }
.tgs-stat-card__icon--red { background: rgba(244, 63, 94, 0.15); color: #E11D48; }

.tgs-stat-card__val {
    font-family: 'Outfit', sans-serif;
    font-size: 22px;
    font-weight: 800;
    color: var(--lumina-navy);
    line-height: 1;
}
.tgs-stat-card__lbl {
    font-size: 12px;
    color: #64748B;
    font-weight: 600;
    margin-top: 4px;
}

/* FILTER BAR */
.tgs-filter-bar {
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    border-radius: 16px;
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    box-shadow: 0 4px 15px -4px rgba(0, 0, 0, 0.03);
}
.tgs-filter-group {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    flex: 1;
}
.tgs-select {
    padding: 9px 14px;
    border-radius: 10px;
    border: 1px solid var(--lumina-border);
    font-size: 13.5px;
    color: #334155;
    background: #FFFFFF;
    font-weight: 600;
    outline: none;
    transition: all 0.2s;
    min-width: 180px;
}
.tgs-select:focus {
    border-color: var(--lumina-teal);
    box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.15);
}
.tgs-search-input {
    padding: 9px 14px 9px 38px;
    border-radius: 10px;
    border: 1px solid var(--lumina-border);
    font-size: 13.5px;
    color: #334155;
    background: #FFFFFF;
    outline: none;
    transition: all 0.2s;
    width: 240px;
}
.tgs-search-input:focus {
    border-color: var(--lumina-teal);
    box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.15);
}
.tgs-search-box {
    position: relative;
    display: inline-block;
}
.tgs-search-box iconify-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 16px;
    color: #94A3B8;
    pointer-events: none;
}

/* NAVIGATION TABS */
.tgs-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid var(--lumina-border);
    margin-bottom: 24px;
}
.tgs-tab {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    font-size: 14px;
    font-weight: 700;
    color: #64748B;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: -2px;
}
.tgs-tab:hover {
    color: var(--lumina-navy);
}
.tgs-tab.active {
    color: var(--lumina-teal);
    border-bottom-color: var(--lumina-teal);
}
.tgs-tab__badge {
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 800;
    background: #F1F5F9;
    color: #475569;
}
.tgs-tab.active .tgs-tab__badge {
    background: rgba(13, 122, 135, 0.15);
    color: var(--lumina-teal);
}

/* CARD LIST & TABLE */
.tgs-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 18px;
}
.tgs-item-card {
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    border-radius: 18px;
    padding: 20px;
    box-shadow: 0 4px 15px -4px rgba(0, 0, 0, 0.04);
    transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.tgs-item-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 30px -8px rgba(0, 0, 0, 0.1);
    border-color: #CBD5E1;
}
.tgs-item-card__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}
.tgs-item-card__title {
    font-family: 'Outfit', sans-serif;
    font-size: 16.5px;
    font-weight: 700;
    color: var(--lumina-navy);
    line-height: 1.35;
    margin: 0;
}
.tgs-item-card__meta {
    font-size: 12px;
    color: #64748B;
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.tgs-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 700;
    line-height: 1;
}
.tgs-badge--individ { background: rgba(82, 178, 220, 0.15); color: #0284C7; }
.tgs-badge--kelompok { background: rgba(139, 92, 246, 0.15); color: #7C3AED; }
.tgs-badge--open { background: rgba(16, 185, 129, 0.15); color: #059669; }
.tgs-badge--closed { background: rgba(244, 63, 94, 0.15); color: #E11D48; }
.tgs-badge--draft { background: rgba(100, 116, 139, 0.15); color: #475569; }
.tgs-badge--gdrive { background: rgba(15, 107, 56, 0.12); color: #0F6B38; }

.tgs-item-card__desc {
    font-size: 13px;
    color: #475569;
    line-height: 1.55;
    margin: 10px 0 16px;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.tgs-item-card__progress {
    background: #F8FAFC;
    border-radius: 12px;
    padding: 10px 14px;
    margin-bottom: 16px;
    border: 1px solid #F1F5F9;
}
.tgs-item-card__progress-label {
    display: flex;
    justify-content: space-between;
    font-size: 11.5px;
    font-weight: 700;
    color: #475569;
    margin-bottom: 6px;
}
.tgs-progress-bar {
    height: 7px;
    border-radius: 8px;
    background: #E2E8F0;
    overflow: hidden;
}
.tgs-progress-fill {
    height: 100%;
    border-radius: 8px;
    background: linear-gradient(90deg, #10B981, #0D7A87);
    transition: width 0.4s ease;
}

.tgs-item-card__actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding-top: 12px;
    border-top: 1px solid #F1F5F9;
}

/* TABLE SUBMISSION */
.tgs-table-wrap {
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    border-radius: 16px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    box-shadow: 0 4px 15px -4px rgba(0, 0, 0, 0.03);
}
.tgs-table {
    width: 100%;
    min-width: 980px;
    border-collapse: collapse;
    font-size: 13.5px;
}
.tgs-table th {
    background: #F8FAFC;
    color: #475569;
    font-weight: 700;
    padding: 14px 18px;
    text-align: left;
    border-bottom: 1px solid var(--lumina-border);
    white-space: nowrap;
}
.tgs-table td {
    padding: 14px 18px;
    border-bottom: 1px solid #F1F5F9;
    vertical-align: middle;
}
.tgs-table tr:hover td {
    background: #F8FAFC;
}

/* ==============================================================================
 * KELOMPOK BELAJAR TAB STYLES
 * ============================================================================== */
.tgs-kelompok-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}
.tgs-kelompok-card {
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    border-radius: 18px;
    padding: 18px 20px;
    box-shadow: 0 4px 15px -4px rgba(0, 0, 0, 0.03);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
}
.tgs-kelompok-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px -6px rgba(124, 58, 237, 0.12);
    border-color: #DDD6FE;
}
.tgs-kelompok-card__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid #F1F5F9;
    margin-bottom: 14px;
}
.tgs-kelompok-card__title {
    font-family: 'Outfit', sans-serif;
    font-size: 16px;
    font-weight: 800;
    color: var(--lumina-navy);
    display: flex;
    align-items: center;
    gap: 8px;
}
.tgs-ketua-spotlight {
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border: 1px solid #F59E0B;
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.tgs-ketua-label {
    font-size: 11px;
    font-weight: 800;
    color: #92400E;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.tgs-anggota-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 14px;
    max-height: 320px;
    overflow-y: auto;
    padding-right: 4px;
}
.tgs-anggota-item {
    background: #F8FAFC;
    border: 1px solid #F1F5F9;
    border-radius: 10px;
    padding: 8px 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    transition: background 0.15s;
}
.tgs-anggota-item:hover {
    background: #F1F5F9;
}
.tgs-anggota-item.is-ketua {
    background: #FFFBEB;
    border-color: #FDE68A;
}
.tgs-unassigned-box {
    background: #FFFFFF;
    border: 1px solid var(--lumina-border);
    border-radius: 18px;
    padding: 20px;
    margin-top: 24px;
    box-shadow: 0 4px 15px -4px rgba(0,0,0,0.03);
}

/* ==============================================================================
 * LUMINA THEMED TOAST & DIALOG SYSTEM (ZERO BROWSER DEFAULT POPUP)
 * ============================================================================== */
.tgs-toast-container {
    position: fixed;
    top: 84px;
    right: 22px;
    z-index: 100005;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 390px;
    pointer-events: none;
}
.tgs-toast-item {
    background: #FFFFFF;
    border-left: 4.5px solid #10B981;
    border-radius: 14px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
    padding: 14px 18px;
    pointer-events: auto;
    animation: tgsToastIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    display: flex;
    align-items: flex-start;
    gap: 12px;
    border-top: 1px solid rgba(226, 232, 240, 0.7);
    border-right: 1px solid rgba(226, 232, 240, 0.7);
    border-bottom: 1px solid rgba(226, 232, 240, 0.7);
}
.tgs-toast-item.err { border-left-color: #F43F5E; }
.tgs-toast-item.warn { border-left-color: #F59E0B; }
.tgs-toast-item.info { border-left-color: #7C3AED; }
.tgs-toast-icon { font-size: 22px; line-height: 1; flex-shrink: 0; margin-top: 1px; }
.tgs-toast-content { flex: 1; min-width: 0; }
.tgs-toast-title { font-size: 13.5px; font-weight: 800; color: #1D3B5E; margin-bottom: 2px; }
.tgs-toast-msg { font-size: 12.5px; color: #475569; line-height: 1.45; word-break: break-word; }
@keyframes tgsToastIn {
    from { transform: translateX(120%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

.tgs-dialog-backdrop {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.72);
    backdrop-filter: blur(6px);
    z-index: 100010;
    display: none !important;
    pointer-events: none !important;
    align-items: center;
    justify-content: center;
    padding: 20px;
    opacity: 0;
    visibility: hidden;
    transition: all 0.25s ease;
}
.tgs-dialog-backdrop.show {
    display: flex !important;
    pointer-events: auto !important;
    opacity: 1;
    visibility: visible;
}
.tgs-dialog-card {
    background: #FFFFFF;
    border-radius: 20px;
    box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.35);
    width: 100%;
    max-width: 460px;
    padding: 28px 24px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    animation: modalIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    position: relative;
    border: 1px solid rgba(226, 232, 240, 0.8);
}
.tgs-dialog-icon-circle {
    width: 58px;
    height: 58px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    margin-bottom: 16px;
    flex-shrink: 0;
}
.tgs-dialog-icon-circle.success { background: #ECFDF5; color: #059669; }
.tgs-dialog-icon-circle.warning { background: #FFFBEB; color: #D97706; }
.tgs-dialog-icon-circle.error { background: #FFF1F2; color: #E11D48; }
.tgs-dialog-icon-circle.info { background: #EDE9FE; color: #7C3AED; }
.tgs-dialog-title {
    font-family: 'Outfit', sans-serif;
    font-size: 19px;
    font-weight: 800;
    color: var(--lumina-navy);
    margin: 0 0 8px;
}
.tgs-dialog-message {
    font-size: 13.5px;
    color: #64748B;
    line-height: 1.55;
    margin: 0 0 20px;
    white-space: pre-line;
    max-height: 240px;
    overflow-y: auto;
    padding: 0 4px;
}
.tgs-dialog-input {
    width: 100%;
    border: 1.5px solid #CBD5E1;
    border-radius: 12px;
    padding: 10px 14px;
    font-size: 14px;
    outline: none;
    font-family: inherit;
    color: var(--lumina-navy);
    margin-bottom: 20px;
    transition: border-color 0.2s, box-shadow 0.2s;
    background: #F8FAFC;
    box-sizing: border-box;
}
.tgs-dialog-input:focus {
    border-color: #7C3AED;
    background: #FFFFFF;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);
}
.tgs-dialog-actions {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    width: 100%;
}
.tgs-dialog-actions .btn-tgs {
    flex: 1;
    padding: 10px 18px;
    font-size: 13.5px;
    justify-content: center;
}

/* ==============================================================================
 * MODAL INSPEKTOR & VIEWER TANPA DOWNLOAD (CORE REQUIREMENT)
 * ============================================================================== */
.tgs-modal-backdrop {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.7);
    backdrop-filter: blur(6px);
    z-index: 9999;
    display: none !important;
    pointer-events: none !important;
    align-items: center;
    justify-content: center;
    padding: 20px;
    opacity: 0;
    visibility: hidden;
    transition: all 0.25s ease;
}
.tgs-modal-backdrop.show {
    display: flex !important;
    pointer-events: auto !important;
    opacity: 1;
    visibility: visible;
}
.tgs-modal {
    background: #FFFFFF;
    border-radius: 20px;
    box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.3);
    width: 100%;
    max-width: 1350px;
    max-height: 94vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: modalIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalIn {
    from { opacity: 0; transform: scale(0.96) translateY(10px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}

.tgs-modal__header {
    padding: 16px 24px;
    background: #FFFFFF;
    border-bottom: 1px solid var(--lumina-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-shrink: 0;
}
.tgs-modal__title-box {
    display: flex;
    align-items: center;
    gap: 12px;
}
.tgs-modal__title {
    font-family: 'Outfit', sans-serif;
    font-size: 18px;
    font-weight: 800;
    color: var(--lumina-navy);
    margin: 0;
}
.tgs-modal__close {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: none;
    background: #F1F5F9;
    color: #64748B;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    cursor: pointer;
    transition: all 0.2s;
}
.tgs-modal__close:hover {
    background: #E2E8F0;
    color: var(--lumina-rose);
}

.tgs-modal__body {
    padding: 24px;
    overflow-y: auto;
    flex: 1;
}

/* SPLIT SCREEN GRADING & VIEWER */
.tgs-inspector-split {
    display: grid;
    grid-template-columns: 420px 1fr;
    gap: 20px;
    height: calc(94vh - 120px);
}
@media (max-width: 1024px) {
    .tgs-inspector-split {
        grid-template-columns: 1fr;
        height: auto;
    }
}

.tgs-grade-pane {
    background: #F8FAFC;
    border: 1px solid var(--lumina-border);
    border-radius: 16px;
    padding: 20px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

/* DOCUMENT STAGE (HANYA LIHAT - ZERO DOWNLOAD) */
.tgs-viewer-pane {
    background: #0F172A;
    border: 1px solid #1E293B;
    border-radius: 16px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    position: relative;
    user-select: none;
    -webkit-user-select: none;
}
.tgs-viewer-toolbar {
    background: #1E293B;
    padding: 10px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    color: #E2E8F0;
    font-size: 12.5px;
    flex-shrink: 0;
    border-bottom: 1px solid #334155;
}
.tgs-viewer-title {
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: flex;
    align-items: center;
    gap: 8px;
}
.tgs-viewer-controls {
    display: flex;
    align-items: center;
    gap: 8px;
}
.tgs-btn-viewtool {
    background: rgba(255, 255, 255, 0.1);
    color: #F8FAFC;
    border: none;
    border-radius: 6px;
    padding: 6px 10px;
    font-size: 12px;
    cursor: pointer;
    transition: background 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.tgs-btn-viewtool:hover {
    background: rgba(255, 255, 255, 0.2);
}

.tgs-stage-content {
    flex: 1;
    overflow: auto;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #090D16;
}

/* WATERMARK KEAMANAN DOKUMEN */
.tgs-watermark-overlay {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    pointer-events: none;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    z-index: 20;
    opacity: 0.12;
}
.tgs-watermark-text {
    transform: rotate(-30deg);
    font-size: 26px;
    font-weight: 900;
    color: #FFFFFF;
    text-align: center;
    line-height: 2.2;
    letter-spacing: 2px;
    white-space: pre-wrap;
}

/* PDF CANVAS CONTAINER */
.tgs-pdf-stage {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 20px;
    gap: 20px;
    width: 100%;
}
.tgs-pdf-canvas {
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
    background: #FFFFFF;
    border-radius: 4px;
    max-width: 100%;
}

/* IFRAME DRIVE PREVIEW TANPA DOWNLOAD */
.tgs-iframe-container {
    width: 100%;
    height: 100%;
    position: relative;
}
.tgs-drive-iframe {
    width: 100%;
    height: 100%;
    border: none;
}
/* Pelindung klik menu download di header iframe Google Drive */
.tgs-drive-shield-top {
    position: absolute;
    top: 0; right: 0;
    width: 140px;
    height: 56px;
    background: transparent;
    z-index: 15;
    cursor: default;
}

/* FORM ELEMENTS INSIDE MODAL */
.tgs-form-group {
    margin-bottom: 16px;
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
    transition: all 0.2s;
}
.tgs-form-control:focus {
    border-color: var(--lumina-teal);
    box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.15);
}
textarea.tgs-form-control {
    resize: vertical;
    min-height: 85px;
}

.tgs-score-input-box {
    display: flex;
    align-items: center;
    gap: 12px;
}
.tgs-score-input {
    width: 100px;
    font-size: 24px;
    font-weight: 800;
    text-align: center;
    font-family: 'Outfit', sans-serif;
    color: var(--lumina-teal);
}
.tgs-grade-badge-live {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: #F1F5F9;
    color: #475569;
    font-family: 'Outfit', sans-serif;
    font-size: 24px;
    font-weight: 900;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #E2E8F0;
    transition: all 0.2s;
}
.tgs-grade-badge-live.grade-A { background: rgba(16, 185, 129, 0.15); color: #059669; border-color: #10B981; }
.tgs-grade-badge-live.grade-B { background: rgba(82, 178, 220, 0.15); color: #0284C7; border-color: #52B2DC; }
.tgs-grade-badge-live.grade-C { background: rgba(245, 158, 11, 0.15); color: #D97706; border-color: #F59E0B; }
.tgs-grade-badge-live.grade-D { background: rgba(244, 63, 94, 0.15); color: #E11D48; border-color: #F43F5E; }
.tgs-grade-badge-live.grade-E { background: rgba(15, 23, 42, 0.15); color: #0F172A; border-color: #64748B; }

/* REKOMENDASI CATATAN / PRESET FEEDBACK */
.tgs-preset-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}
.tgs-preset-chip {
    padding: 4px 10px;
    border-radius: 14px;
    background: #FFFFFF;
    border: 1px solid #CBD5E1;
    font-size: 11.5px;
    font-weight: 600;
    color: #475569;
    cursor: pointer;
    transition: all 0.15s;
}
.tgs-preset-chip:hover {
    background: #F1F5F9;
    border-color: var(--lumina-teal);
    color: var(--lumina-teal);
}

/* SKELETON / LOADING */
.tgs-loading-state {
    padding: 60px 20px;
    text-align: center;
    color: #94A3B8;
}
.tgs-spinner {
    width: 38px;
    height: 38px;
    border: 3px solid rgba(13, 122, 135, 0.2);
    border-top-color: var(--lumina-teal);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin: 0 auto 12px;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ==============================================================================
 * FLOATING BULK ACTION BAR & RESPONSIVE RULES (MOBILE & DESKTOP)
 * ============================================================================== */
.tgs-bulk-floating-bar {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(15, 23, 42, 0.95);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 9999px;
    padding: 10px 22px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.3);
    z-index: 1050;
    max-width: 94vw;
    animation: tgsSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

@keyframes tgsSlideUp {
    from { opacity: 0; transform: translate(-50%, 20px); }
    to { opacity: 1; transform: translate(-50%, 0); }
}

.tgs-bulk-info {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #FFFFFF;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
}

.tgs-bulk-count-badge {
    background: var(--lumina-teal, #0D7A87);
    color: #FFFFFF;
    border-radius: 9999px;
    padding: 2px 10px;
    font-size: 12px;
    font-weight: 800;
}

.tgs-bulk-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

/* RESPONSIVE DESIGN (DESKTOP, TABLET & MOBILE) */
@media (max-width: 1024px) {
    .tgs-inspector-split {
        grid-template-columns: 1fr;
        height: auto;
    }
    .tgs-filter-bar {
        gap: 12px;
    }
}

@media (max-width: 768px) {
    .tgs-container {
        padding: 12px 6px 90px;
    }
    .tgs-filter-bar {
        flex-direction: column;
        align-items: stretch;
        padding: 14px;
        gap: 12px;
    }
    .tgs-filter-group {
        flex-direction: column;
        align-items: stretch;
        width: 100%;
    }
    .tgs-select {
        width: 100% !important;
        min-width: 0 !important;
    }
    .tgs-search-box {
        width: 100%;
    }
    .tgs-search-input {
        width: 100% !important;
        min-width: 0 !important;
    }
    .tgs-tabs {
        overflow-x: auto;
        white-space: nowrap;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 4px;
        gap: 4px;
        width: 100%;
    }
    .tgs-tab {
        padding: 10px 14px;
        font-size: 13px;
    }
    .tgs-card-grid {
        grid-template-columns: 1fr;
    }
    .tgs-bulk-floating-bar {
        border-radius: 18px;
        padding: 12px 14px;
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
        bottom: 14px;
        width: calc(100vw - 24px);
        max-width: 100vw;
    }
    .tgs-bulk-info {
        justify-content: center;
    }
    .tgs-bulk-actions {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 6px;
    }
    .tgs-bulk-actions .btn-tgs {
        padding: 8px 10px;
        font-size: 12px;
        justify-content: center;
    }
    .tgs-modal {
        max-width: 95vw !important;
        margin: 10px auto;
        padding: 16px;
    }
    .tgs-item-card__actions {
        flex-direction: column;
        gap: 8px;
    }
    .tgs-item-card__actions > div {
        width: 100%;
        justify-content: space-between;
    }
    .tgs-item-card__actions .btn-tgs--primary {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="tgs-container">

    <!-- BANNER PERINGATAN BILA BELUM MEMILIKI KELAS AMPUAN -->
    <div id="noClassAlert" style="display: none; background: #FFFBEB; border-left: 4px solid #F59E0B; padding: 14px 18px; border-radius: 14px; margin-bottom: 20px; font-size: 13px; color: #92400E; align-items: center; gap: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
        <iconify-icon icon="solar:danger-triangle-bold" style="font-size: 24px; color: #F59E0B; flex-shrink: 0;"></iconify-icon>
        <div>
            <strong>Perhatian Hak Akses:</strong> Anda belum terdaftar mengampu kelas atau mata pelajaran apapun. Seluruh pembuatan tugas dan penilaian siswa dibatasi khusus pada rombel kelas ampunan Anda. Hubungi Administrator untuk verifikasi jam mengajar (KBM).
        </div>
    </div>

    <!-- FILTER BAR -->
    <div class="tgs-filter-bar">
        <div class="tgs-filter-group">
            <select id="filterKelas" class="tgs-select" onchange="onKelasFilterChange()">
                <option value="">-- Pilih Rombel Kelas --</option>
            </select>
            <select id="filterMapel" class="tgs-select" onchange="onMapelFilterChange()" disabled>
                <option value="">-- Semua Mata Pelajaran --</option>
            </select>
            <select id="filterStatusTugas" class="tgs-select" onchange="reloadActiveTab()" style="min-width: 140px;">
                <option value="all">Semua Status</option>
                <option value="Dibuka">Dibuka</option>
                <option value="Ditutup">Ditutup</option>
                <option value="Draft">Draft</option>
            </select>
        </div>
        <div class="tgs-search-box">
            <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
            <input type="text" id="filterSearch" class="tgs-search-input" placeholder="Cari judul tugas..." onkeyup="debounceSearch()">
        </div>
    </div>

    <!-- NAVIGATION TABS & ACTION BAR -->
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; margin-bottom: 24px;">
        <div class="tgs-tabs" style="margin-bottom: 0;">
            <button type="button" class="tgs-tab active" id="tabBtnTugas" onclick="switchMainTab('tugas')">
                <iconify-icon icon="solar:clipboard-list-bold"></iconify-icon>
                <span>Manajemen Penugasan</span>
                <span class="tgs-tab__badge" id="tabCountTugas">0</span>
            </button>
            <button type="button" class="tgs-tab" id="tabBtnKelompok" onclick="switchMainTab('kelompok')">
                <iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon>
                <span>Bagi & Kelola Kelompok</span>
                <span class="tgs-tab__badge" id="tabCountKelompok">0</span>
            </button>
            <button type="button" class="tgs-tab" id="tabBtnPenilaian" onclick="switchMainTab('penilaian')">
                <iconify-icon icon="solar:diploma-bold"></iconify-icon>
                <span>Pemeriksaan & Penilaian Siswa</span>
                <span class="tgs-tab__badge" id="tabCountPenilaian">0</span>
            </button>
        </div>
        <button type="button" class="btn-tgs btn-tgs--primary" id="btnOpenCreateTugas" onclick="openCreateTugasModal(event)" style="box-shadow: 0 4px 14px rgba(13, 122, 135, 0.25); position: relative; z-index: 50; cursor: pointer;">
            <iconify-icon icon="solar:add-circle-bold" style="font-size: 19px; pointer-events: none;"></iconify-icon>
            <span style="pointer-events: none;">Buat Tugas Baru</span>
        </button>
    </div>

    <!-- TAB 1: MANAJEMEN PENUGASAN (DUAL VIEW & PAGINASI KONSISTEN) -->
    <div id="tabContentTugas">
        <div class="ilm-data-container" id="tugasDataWrapper">
            <!-- HIASAN GUNUNGAN WAYANG (PERSIS SEPERTI ALERT SISTEM) -->
            <div class="ilm-data-gunungan" aria-hidden="true"></div>

            <!-- TOOLBAR ATAS: SELEKSI MASSAL & VIEW SWITCHER (CARD VS ROW TABLE) -->
            <div class="ilm-data-toolbar">
                <div class="ilm-data-toolbar__left">
                    <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 700; color: #475569; margin: 0;">
                        <input type="checkbox" id="chkSelectAllTasks" onchange="toggleSelectAllTasks(this)" style="width: 17px; height: 17px; accent-color: var(--lumina-teal); cursor: pointer;">
                        <span>Pilih Semua di Halaman Ini</span>
                    </label>
                    <span id="bulkTaskSelectedInfo" style="font-size: 11.5px; font-weight: 700; color: var(--lumina-teal); background: rgba(13, 122, 135, 0.08); border: 1px solid rgba(13, 122, 135, 0.18); padding: 3px 8px; border-radius: 6px;">0 dipilih</span>
                </div>

                <div class="ilm-data-toolbar__right">
                    <!-- VIEW SWITCHER BUTTONS (DUAL MODE KARTU / TABEL BARIS) -->
                    <div class="ilm-view-switcher" role="group" aria-label="Tampilan Tugas">
                        <button type="button" class="ilm-view-btn is-active" id="btnViewCard" onclick="switchTugasViewMode('card')" title="Tampilan Kartu (Grid View)">
                            <iconify-icon icon="solar:widget-2-bold"></iconify-icon>
                            <span>Kartu</span>
                        </button>
                        <button type="button" class="ilm-view-btn" id="btnViewTable" onclick="switchTugasViewMode('table')" title="Tampilan Tabel (Row View)">
                            <iconify-icon icon="solar:list-bold"></iconify-icon>
                            <span>Tabel Baris</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- CONTAINER KONTEN DATA DINAMIS -->
            <div id="tugasDataContainer">
                <div class="tgs-loading-state" style="padding: 50px 20px; text-align: center;">
                    <div class="tgs-spinner"></div>
                    <div style="font-weight: 600; color: #64748B; margin-top: 10px;">Memuat daftar penugasan...</div>
                </div>
            </div>

            <!-- BARIS PAGINASI INTERAKTIF -->
            <div class="ilm-pagination-bar" id="tugasPaginationBar"></div>
        </div>
    </div>

    <!-- TAB 2: BAGI & KELOLA KELOMPOK BELAJAR -->
    <div id="tabContentKelompok" style="display: none;">
        <!-- Control Header -->
        <div style="background: #FFFFFF; border: 1px solid var(--lumina-border); border-radius: 18px; padding: 20px; margin-bottom: 22px; box-shadow: 0 4px 15px -4px rgba(0,0,0,0.03);">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap; flex: 1;">
                    <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(124, 58, 237, 0.12); color: #7C3AED; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;">
                        <iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon>
                    </div>
                    <div>
                        <div style="font-size: 11.5px; font-weight: 700; color: #7C3AED; text-transform: uppercase; letter-spacing: 0.5px;">Pilih Penugasan Kelompok</div>
                        <select id="selectKelompokTugas" class="tgs-select" style="min-width: 320px; font-weight: 700; color: var(--lumina-navy);" onchange="onSelectKelompokTugasChange()">
                            <option value="">-- Pilih Tugas Kelompok --</option>
                        </select>
                    </div>
                </div>

                <div id="kelompokTugasQuickActions" style="display: none; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="btn-tgs btn-tgs--secondary btn-tgs--sm" onclick="openAddSingleKelompokInTab()">
                        <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                        <span>Tambah Kelompok Manual</span>
                    </button>
                    <button type="button" class="btn-tgs btn-tgs--secondary btn-tgs--sm" style="color: var(--lumina-rose);" onclick="resetSemuaKelompokTab()">
                        <iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon>
                        <span>Reset Kelompok</span>
                    </button>
                </div>
            </div>

            <!-- Panel Generator Acak & Adil (Muncul jika tugas dipilih) -->
            <div id="panelAutoGenerateBox" style="display: none; margin-top: 18px; padding-top: 18px; border-top: 1px dashed var(--lumina-border);">
                <div style="background: linear-gradient(135deg, rgba(124, 58, 237, 0.05) 0%, rgba(79, 70, 229, 0.08) 100%); border: 1px solid rgba(124, 58, 237, 0.2); border-radius: 14px; padding: 18px 20px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <iconify-icon icon="solar:magic-stick-3-bold" style="font-size: 20px; color: #7C3AED;"></iconify-icon>
                                <h4 style="margin: 0; font-family: 'Outfit', sans-serif; font-size: 16px; font-weight: 800; color: #4C1D95;">Bagi Siswa Kelas Secara Acak & Adil</h4>
                            </div>
                            <p style="margin: 4px 0 0; font-size: 12.5px; color: #6D28D9;" id="kelompokInfoKelasText">
                                Tentukan berapa kelompok yang diinginkan, sistem akan membagi rata 1 kelas santri beserta penamaan kelompok.
                            </p>
                        </div>

                        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; background: #FFFFFF; border: 1px solid #CBD5E1; border-radius: 10px; padding: 4px 8px;">
                                <label style="font-size: 12px; font-weight: 700; color: #475569; margin-right: 8px;">Jumlah Kelompok:</label>
                                <button type="button" class="btn-tgs btn-tgs--secondary" style="padding: 4px 10px; min-width: 28px; height: 28px;" onclick="adjustJmlKelompok(-1)">-</button>
                                <input type="number" id="inputJumlahKelompokTab" value="4" min="2" max="15" style="width: 48px; border: none; text-align: center; font-weight: 800; font-size: 16px; outline: none; color: #4C1D95;" oninput="updateJmlKelompokEstimate()">
                                <button type="button" class="btn-tgs btn-tgs--secondary" style="padding: 4px 10px; min-width: 28px; height: 28px;" onclick="adjustJmlKelompok(1)">+</button>
                            </div>

                            <button type="button" class="btn-tgs" style="background: linear-gradient(135deg, #7C3AED 0%, #4F46E5 100%); color: #FFFFFF;" onclick="executeAutoGenerateTab()" id="btnAutoGenerateTab">
                                <iconify-icon icon="solar:dice-bold" style="font-size: 18px;"></iconify-icon>
                                <span>Acak & Bagi Kelompok Sekarang</span>
                            </button>
                        </div>
                    </div>

                    <div style="margin-top: 10px; font-size: 12px; color: #7C3AED; font-weight: 600;" id="kelompokEstimateText">
                        Estimasi: Membagi siswa secara seimbang.
                    </div>
                </div>
            </div>
        </div>

        <!-- Grid Kartu Kelompok Belajar -->
        <div id="kelompokTabCardsContainer">
            <div style="background: #FFFFFF; border: 1px dashed var(--lumina-border); border-radius: 16px; padding: 60px 20px; text-align: center; color: #64748B;">
                <iconify-icon icon="solar:users-group-two-rounded-bold" style="font-size: 48px; color: #CBD5E1; margin-bottom: 12px;"></iconify-icon>
                <h4 style="font-family: 'Outfit', sans-serif; font-size: 18px; color: var(--lumina-navy); margin: 0 0 6px;">Pilih Tugas Kelompok di Atas</h4>
                <p style="font-size: 13px; color: #64748B; margin: 0;">Pilih salah satu penugasan kelompok untuk menentukan jumlah kelompok, membagi siswa acak, dan memilih ketua kelompok.</p>
            </div>
        </div>
    </div>

    <!-- TAB 3: PEMERIKSAAN & PENILAIAN SISWA -->
    <div id="tabContentPenilaian" style="display: none;">
        <div style="background: #FFFFFF; border: 1px solid var(--lumina-border); border-radius: 16px; padding: 18px 20px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; flex: 1;">
                <label style="font-weight: 700; font-size: 13.5px; color: var(--lumina-navy);">Pilih Tugas:</label>
                <select id="selectPenilaianTugas" class="tgs-select" style="min-width: 280px;" onchange="loadSubmissions()">
                    <option value="">-- Pilih Tugas Terlebih Dahulu --</option>
                </select>
                <select id="filterSubStatus" class="tgs-select" onchange="loadSubmissions()" style="min-width: 170px;">
                    <option value="all">Semua Pengumpulan</option>
                    <option value="belum_dinilai">Menunggu Penilaian</option>
                    <option value="sudah_dinilai">Sudah Dinilai</option>
                    <option value="perlu_revisi">Perlu Revisi</option>
                    <option value="terlambat">Terlambat</option>
                </select>
            </div>
            <div style="font-size: 13px; color: #64748B;" id="submissionSummaryText">
                Silakan pilih tugas untuk mulai menilai.
            </div>
        </div>

        <div class="ilm-data-container" id="submissionDataWrapper">
            <!-- HIASAN GUNUNGAN WAYANG (PERSIS SEPERTI ALERT SISTEM) -->
            <div class="ilm-data-gunungan" aria-hidden="true"></div>

            <div class="ilm-table-responsive">
                <table class="ilm-table">
                    <thead>
                        <tr>
                            <th style="width: 44px; text-align: center;">
                                <input type="checkbox" id="chkSelectAllSubmissions" onchange="toggleSelectAllSubmissions(this)" style="width: 17px; height: 17px; accent-color: var(--lumina-teal); cursor: pointer;">
                            </th>
                            <th style="width: 45px; text-align: center;">No</th>
                            <th>Siswa / Kelompok</th>
                            <th>Waktu Kumpul</th>
                            <th>Berkas & Tautan</th>
                            <th style="text-align: center;">Nilai & Predikat</th>
                            <th style="text-align: center;">Status</th>
                            <th style="text-align: right; width: 170px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="submissionTableBody">
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 50px 20px; color: #94A3B8;">
                                Pilih tugas di atas untuk menampilkan pengumpulan siswa.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- PAGINASI PENILAIAN SISWA -->
            <div class="ilm-pagination-bar" id="submissionPaginationBar" style="display: none;"></div>
        </div>
    </div>

</div>

<!-- ============================================================================
 * FLOATING BULK BARS & BULK GRADE MODAL (GURU)
 * ============================================================================ -->
<!-- FLOATING BULK BAR: TUGAS GURU -->
<div class="tgs-bulk-floating-bar" id="bulkBarTasks" style="display: none;">
    <div class="tgs-bulk-info">
        <span class="tgs-bulk-count-badge" id="bulkTaskCount">0</span>
        <span>Tugas Terpilih</span>
    </div>
    <div class="tgs-bulk-actions">
        <button type="button" class="btn-tgs btn-tgs--sm btn-tgs--primary" onclick="bulkUpdateTaskStatus('Dibuka')" title="Buka Tugas Terpilih">
            <iconify-icon icon="solar:lock-unlocked-bold"></iconify-icon>
            <span>Buka Tugas</span>
        </button>
        <button type="button" class="btn-tgs btn-tgs--sm" style="background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A;" onclick="bulkUpdateTaskStatus('Ditutup')" title="Tutup Tugas Terpilih">
            <iconify-icon icon="solar:lock-bold"></iconify-icon>
            <span>Tutup Tugas</span>
        </button>
        <button type="button" class="btn-tgs btn-tgs--sm btn-tgs--rose" onclick="bulkDeleteTasks()" title="Hapus Tugas Terpilih">
            <iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon>
            <span>Hapus Terpilih</span>
        </button>
        <button type="button" class="btn-tgs btn-tgs--sm btn-tgs--secondary" onclick="clearTaskSelection()">
            <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            <span>Batal</span>
        </button>
    </div>
</div>

<!-- FLOATING BULK BAR: PENILAIAN GURU -->
<div class="tgs-bulk-floating-bar" id="bulkBarSubmissions" style="display: none;">
    <div class="tgs-bulk-info">
        <span class="tgs-bulk-count-badge" id="bulkSubCount">0</span>
        <span>Pengumpulan Terpilih</span>
    </div>
    <div class="tgs-bulk-actions">
        <button type="button" class="btn-tgs btn-tgs--sm btn-tgs--primary" onclick="openBulkGradeModal()">
            <iconify-icon icon="solar:star-fall-bold"></iconify-icon>
            <span>Beri Nilai Massal</span>
        </button>
        <button type="button" class="btn-tgs btn-tgs--sm" style="background: #DCFCE7; color: #15803D; border: 1px solid #86EFAC;" onclick="bulkMarkTuntas()">
            <iconify-icon icon="solar:check-circle-bold"></iconify-icon>
            <span>Tandai Tuntas</span>
        </button>
        <button type="button" class="btn-tgs btn-tgs--sm btn-tgs--rose" onclick="bulkMarkRevision()">
            <iconify-icon icon="solar:danger-triangle-bold"></iconify-icon>
            <span>Minta Revisi</span>
        </button>
        <button type="button" class="btn-tgs btn-tgs--sm btn-tgs--secondary" onclick="clearSubmissionSelection()">
            <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            <span>Batal</span>
        </button>
    </div>
</div>

<!-- MODAL BULK GRADE GURU -->
<div class="ilm-modal-backdrop" id="modalBulkGrade">
    <div class="ilm-modal-dialog" style="max-width: 520px;">
        <!-- HIASAN ORNAMEN NUSANTARA (PERSIS SEPERTI ALERT SISTEM) -->
        <div class="ilm-modal-gunungan" aria-hidden="true"></div>
        <div class="ilm-modal-ornament-tl" aria-hidden="true"></div>
        <div class="ilm-modal-ornament-tr" aria-hidden="true"></div>
        <div class="ilm-modal-ornament-bl" aria-hidden="true"></div>

        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon" style="background: linear-gradient(135deg, rgba(245,158,11,0.12) 0%, rgba(217,119,6,0.18) 100%); border-color: rgba(245,158,11,0.25); color: #D97706;">
                    <iconify-icon icon="solar:star-fall-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title">Penilaian Massal Siswa</h3>
                    <div class="ilm-modal-divider" aria-hidden="true"></div>
                    <p class="ilm-modal-header__subtitle">Terapkan nilai ke seluruh pengumpulan terpilih</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeModal('modalBulkGrade')" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formBulkGrade" onsubmit="submitBulkGrade(event)">
            <div class="ilm-modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <div style="background: rgba(240, 253, 244, 0.85); border: 1px solid #86EFAC; border-radius: 12px; padding: 12px 14px; font-size: 13px; color: #166534; backdrop-filter: blur(2px);">
                    <strong id="bulkGradeCountLabel">0 pengumpulan terpilih</strong> akan dinilai secara serentak dengan parameter berikut:
                </div>

                <div>
                    <label class="tgs-form-label">Nilai yang Diberikan (0 - 100) *</label>
                    <input type="number" id="inputBulkScore" class="tgs-form-control" min="0" max="100" step="0.5" value="85" required style="font-size: 18px; font-weight: 800; color: var(--lumina-teal); text-align: center; background: rgba(255, 255, 255, 0.9);">
                </div>

                <div>
                    <label class="tgs-form-label">Ketercapaian Aspek ITP</label>
                    <select id="selectBulkAspekItp" class="tgs-form-control" style="background: rgba(255, 255, 255, 0.9);">
                        <option value="Tercapai Sangat Baik">Tercapai Sangat Baik</option>
                        <option value="Tercapai" selected>Tercapai</option>
                        <option value="Cukup Tercapai">Cukup Tercapai</option>
                        <option value="Perlu Bimbingan">Perlu Bimbingan</option>
                    </select>
                </div>

                <div>
                    <label class="tgs-form-label">Status Penilaian</label>
                    <select id="selectBulkStatusPenilaian" class="tgs-form-control" style="background: rgba(255, 255, 255, 0.9);">
                        <option value="Dinilai" selected>Dinilai (Selesai)</option>
                        <option value="Tuntas">Tuntas</option>
                        <option value="Perlu Revisi">Perlu Revisi</option>
                    </select>
                </div>

                <div>
                    <label class="tgs-form-label">Catatan & Umpan Balik Guru (Opsional)</label>
                    <textarea id="inputBulkCatatanGuru" class="tgs-form-control" rows="2" placeholder="Catatan evaluasi untuk seluruh siswa terpilih..." style="background: rgba(255, 255, 255, 0.9);"></textarea>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeModal('modalBulkGrade')">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                    <span>Batal</span>
                </button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--gold" id="btnSubmitBulkGrade">
                    <iconify-icon icon="solar:check-circle-bold"></iconify-icon>
                    <span>Terapkan Nilai Massal</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================================
 * MODAL BUAT / EDIT TUGAS
 * ============================================================================ -->
<div class="ilm-modal-backdrop" id="modalTugas">
    <div class="ilm-modal-dialog" style="max-width: 820px;">
        <!-- HIASAN ORNAMEN NUSANTARA (PERSIS SEPERTI ALERT SISTEM) -->
        <div class="ilm-modal-gunungan" aria-hidden="true"></div>
        <div class="ilm-modal-ornament-tl" aria-hidden="true"></div>
        <div class="ilm-modal-ornament-tr" aria-hidden="true"></div>
        <div class="ilm-modal-ornament-bl" aria-hidden="true"></div>

        <!-- HEADER ILMUNA NUSANTARA TOUCH -->
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:document-add-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="modalTugasTitle">Buat Penugasan Baru</h3>
                    <div class="ilm-modal-divider" aria-hidden="true"></div>
                    <p class="ilm-modal-header__subtitle">Isi seluruh detail penugasan dengan benar</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeModal('modalTugas')" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formTugas" onsubmit="submitFormTugas(event)">
            <input type="hidden" name="action" value="save_tugas">
            <input type="hidden" name="id" id="tugasFormId" value="0">

            <div class="ilm-modal-body" style="max-height: calc(90vh - 160px);">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="tgs-form-group">
                        <label class="tgs-form-label">
                            <iconify-icon icon="solar:users-group-two-rounded-bold" style="font-size:15px; color:var(--lumina-teal);"></iconify-icon>
                            Rombel Kelas <span style="color:red;">*</span>
                        </label>
                        <select name="form_kelas_id" id="formKelasId" class="tgs-form-control ilm-form-select" onchange="onFormKelasChange()" required>
                            <option value="">-- Pilih Kelas --</option>
                        </select>
                    </div>
                    <div class="tgs-form-group">
                        <label class="tgs-form-label">
                            <iconify-icon icon="solar:book-bookmark-bold" style="font-size:15px; color:var(--lumina-teal);"></iconify-icon>
                            Mata Pelajaran <span style="color:red;">*</span>
                        </label>
                        <select name="id_kelas_mata_pelajaran" id="formKmpId" class="tgs-form-control ilm-form-select" required disabled>
                            <option value="">-- Pilih Mata Pelajaran --</option>
                        </select>
                    </div>
                </div>

                <div class="tgs-form-group">
                    <label class="tgs-form-label">
                        <iconify-icon icon="solar:pen-new-square-bold" style="font-size:15px; color:var(--lumina-teal);"></iconify-icon>
                        Judul Tugas <span style="color:red;">*</span>
                    </label>
                    <input type="text" name="judul_tugas" id="formJudulTugas" class="tgs-form-control" placeholder="Contoh: Analisis Karakter Ulil Albab & Berpikir Kritis" required>
                </div>

                <div class="tgs-form-group">
                    <label class="tgs-form-label">
                        <iconify-icon icon="solar:notes-bold" style="font-size:15px; color:var(--lumina-teal);"></iconify-icon>
                        Instruksi & Deskripsi Tugas
                    </label>
                    <textarea name="deskripsi_instruksi" id="formDeskripsi" class="tgs-form-control" rows="4" placeholder="Tuliskan petunjuk pengerjaan tugas secara rinci dan terstruktur..."></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px;">
                    <div class="tgs-form-group">
                        <label class="tgs-form-label">Jenis Penugasan</label>
                        <select name="jenis_penugasan" id="formJenisPenugasan" class="tgs-form-control ilm-form-select">
                            <option value="Individu">Individu (Mandiri)</option>
                            <option value="Kelompok">Kelompok (Kolaboratif)</option>
                        </select>
                    </div>
                    <div class="tgs-form-group">
                        <label class="tgs-form-label">Tipe Pengumpulan</label>
                        <select name="tipe_pengumpulan" id="formTipePengumpulan" class="tgs-form-control ilm-form-select">
                            <option value="File atau Link">File atau Link URL</option>
                            <option value="File Upload">Khusus File Upload</option>
                            <option value="Link URL">Khusus Link URL</option>
                            <option value="Teks / Essay">Teks Langsung / Essay</option>
                        </select>
                    </div>
                    <div class="tgs-form-group">
                        <label class="tgs-form-label">Format File Diizinkan</label>
                        <input type="text" name="format_file_diizinkan" id="formFormatFile" class="tgs-form-control" value="pdf,docx,zip,png,jpg" placeholder="pdf,docx,zip">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px;">
                    <div class="tgs-form-group">
                        <label class="tgs-form-label">Nilai Maksimal</label>
                        <input type="number" name="nilai_maksimal" id="formNilaiMax" class="tgs-form-control" value="100" min="1" max="100" required>
                    </div>
                    <div class="tgs-form-group">
                        <label class="tgs-form-label">Bobot Nilai (%)</label>
                        <input type="number" name="bobot_nilai" id="formBobot" class="tgs-form-control" value="15" min="1" max="100">
                    </div>
                    <div class="tgs-form-group">
                        <label class="tgs-form-label">Pertemuan Ke</label>
                        <input type="number" name="pertemuan_ke" id="formPertemuan" class="tgs-form-control" value="1" min="1">
                    </div>
                </div>

                <!-- INPUT TANGGAL — KALENDER NUSANTARA ILMUNA -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="tgs-form-group">
                        <label class="tgs-form-label">
                            <iconify-icon icon="solar:calendar-date-bold" style="font-size:15px; color:var(--lumina-teal);"></iconify-icon>
                            Tanggal Mulai Pengerjaan
                        </label>
                        <div style="position: relative;">
                            <input type="text" name="tanggal_mulai" id="formTglMulai" class="tgs-form-control" data-ilm-calendar="datetime" readonly style="cursor:pointer; background:#FFFFFF; padding-right: 42px;" placeholder="Pilih tanggal mulai..." title="Klik untuk membuka kalender">
                            <iconify-icon icon="solar:calendar-date-bold" onclick="document.getElementById('formTglMulai').click()" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); font-size: 20px; color: var(--lumina-teal); cursor: pointer;"></iconify-icon>
                        </div>
                    </div>
                    <div class="tgs-form-group">
                        <label class="tgs-form-label">
                            <iconify-icon icon="solar:alarm-bold" style="font-size:15px; color:var(--lumina-rose);"></iconify-icon>
                            Tenggat Waktu (Deadline) <span style="color:red;">*</span>
                        </label>
                        <div style="position: relative;">
                            <input type="text" name="tenggat_waktu" id="formTenggat" class="tgs-form-control" data-ilm-calendar="datetime" readonly required style="cursor:pointer; background:#FFFFFF; padding-right: 42px;" placeholder="Pilih tenggat waktu..." title="Klik untuk membuka kalender">
                            <iconify-icon icon="solar:clock-circle-bold" onclick="document.getElementById('formTenggat').click()" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); font-size: 20px; color: #F59E0B; cursor: pointer;"></iconify-icon>
                        </div>
                    </div>
                </div>

                <!-- CLOUD UPLOAD DROPZONE ULTRA ESTETIK -->
                <div class="tgs-form-group">
                    <label class="tgs-form-label">
                        <iconify-icon icon="solar:cloud-upload-bold" style="font-size:15px; color:var(--lumina-teal);"></iconify-icon>
                        Lampiran Berkas Soal / Lembar Kerja
                    </label>
                    <div class="ilm-upload-dropzone" id="dropzoneTugasLampiran">
                        <input type="file" name="file_lampiran" id="formFileLampiran" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.png,.jpg,.jpeg">
                        
                        <!-- State Standar (Belum Memilih File) -->
                        <div class="ilm-upload-content" id="dropzoneEmptyState">
                            <div class="ilm-upload-icon-circle">
                                <iconify-icon icon="solar:cloud-upload-bold-duotone"></iconify-icon>
                            </div>
                            <div class="ilm-upload-title">
                                Tarik & lepas berkas ke sini, atau <span class="ilm-upload-highlight">Pilih Berkas</span>
                            </div>
                            <div class="ilm-upload-hint">
                                Format didukung: PDF, Word, Excel, PPT, ZIP, Gambar (Maks. 50MB)
                            </div>
                            <div class="ilm-upload-badge-drive">
                                <iconify-icon icon="solar:shield-check-bold"></iconify-icon>
                                <span>Tersinkronisasi Aman ke Google Drive Sekolah</span>
                            </div>
                        </div>

                        <!-- State Terpilih (Preview File Card) -->
                        <div class="ilm-upload-preview" id="dropzonePreviewState" style="display: none;">
                            <div class="ilm-upload-preview__info">
                                <div class="ilm-upload-preview__icon" id="previewFileIcon">
                                    <iconify-icon icon="solar:file-text-bold"></iconify-icon>
                                </div>
                                <div>
                                    <div class="ilm-upload-preview__name" id="previewFileName">nama_file.pdf</div>
                                    <div class="ilm-upload-preview__size" id="previewFileSize">1.2 MB</div>
                                </div>
                            </div>
                            <button type="button" class="ilm-upload-preview__remove" id="btnRemoveSelectedFile" title="Ganti File">
                                <iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon>
                                <span>Ganti</span>
                            </button>
                        </div>

                        <!-- State Berkas Aktif (Saat Edit dari Database) -->
                        <div id="formCurrentFileBox" class="ilm-upload-existing" style="display: none;"></div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 14px;">
                    <div class="tgs-form-group">
                        <label class="tgs-form-label">
                            <iconify-icon icon="solar:link-bold" style="font-size:15px; color:var(--lumina-teal);"></iconify-icon>
                            Tautan Sumber Belajar Tambahan (Opsional)
                        </label>
                        <input type="url" name="link_sumber_tugas" id="formLinkSumber" class="tgs-form-control" placeholder="https://...">
                    </div>
                    <div class="tgs-form-group">
                        <label class="tgs-form-label">
                            <iconify-icon icon="solar:check-circle-bold" style="font-size:15px; color:var(--lumina-teal);"></iconify-icon>
                            Status Tugas
                        </label>
                        <select name="status_tugas" id="formStatusTugas" class="tgs-form-control ilm-form-select">
                            <option value="Dibuka">Dibuka</option>
                            <option value="Draft">Draft</option>
                            <option value="Ditutup">Ditutup</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- FOOTER ILMUNA -->
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeModal('modalTugas')">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                    <span>Batal</span>
                </button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary" id="btnSubmitTugas">
                    <iconify-icon icon="solar:disk-bold"></iconify-icon>
                    <span>Simpan Penugasan</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================================
 * MODAL KELOLA KELOMPOK (TUGAS KELOMPOK)
 * ============================================================================ -->
<div class="tgs-modal-backdrop" id="modalKelompok">
    <div class="tgs-modal" style="max-width: 900px;">
        <div class="tgs-modal__header">
            <div class="tgs-modal__title-box">
                <iconify-icon icon="solar:users-group-two-rounded-bold" style="font-size: 24px; color: #7C3AED;"></iconify-icon>
                <h3 class="tgs-modal__title" id="modalKelompokTitle">Manajemen Kelompok Belajar</h3>
            </div>
            <button type="button" class="tgs-modal__close" onclick="closeModal('modalKelompok')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <div class="tgs-modal__body" style="max-height: calc(90vh - 140px);">
            <div style="background: rgba(124, 58, 237, 0.08); border-left: 4px solid #7C3AED; padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 13px; color: #4C1D95; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                <div>
                    <strong>Tugas Kolaboratif:</strong> Ketika siswa mengumpulkan tugas kelompok, berkas diunggah ke Google Drive di folder nama kelompok dan nilai akan otomatis tersinkron ke semua anggota kelompok.
                </div>
                <button type="button" class="btn-tgs btn-tgs--sm" style="background:#7C3AED; color:#fff;" onclick="promptAutoGenerateKelompok()">
                    <iconify-icon icon="solar:magic-stick-3-bold"></iconify-icon>
                    <span>Bagi Kelompok Otomatis</span>
                </button>
            </div>

            <div id="kelompokListContainer" style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Diisi via AJAX -->
            </div>
        </div>
        <div style="padding: 14px 24px; background: #F8FAFC; border-top: 1px solid var(--lumina-border); display: flex; align-items: center; justify-content: space-between;">
            <button type="button" class="btn-tgs btn-tgs--secondary" onclick="openAddSingleKelompok()">
                <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                <span>Tambah Kelompok Manual</span>
            </button>
            <button type="button" class="btn-tgs btn-tgs--primary" onclick="closeModal('modalKelompok')">Selesai</button>
        </div>
    </div>
</div>

<!-- ============================================================================
 * MODAL INSPEKTOR & VIEWER PENILAIAN (STRICT VIEW-ONLY TANPA DOWNLOAD)
 * ============================================================================ -->
<div class="tgs-modal-backdrop" id="modalInspector">
    <div class="tgs-modal" style="max-width: 1440px; height: 94vh;">
        <div class="tgs-modal__header">
            <div class="tgs-modal__title-box">
                <iconify-icon icon="solar:diploma-verified-bold" style="font-size: 24px; color: var(--lumina-teal);"></iconify-icon>
                <div>
                    <h3 class="tgs-modal__title" id="inspectorHeaderTitle">Pemeriksaan & Penilaian Tugas</h3>
                    <div style="font-size: 12px; color: #64748B;" id="inspectorHeaderSub">Memuat berkas siswa...</div>
                </div>
            </div>
            <button type="button" class="tgs-modal__close" onclick="closeModal('modalInspector')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>

        <div class="tgs-modal__body" style="padding: 16px;">
            <div class="tgs-inspector-split">

                <!-- PANEL FORM PENILAIAN (KIRI) -->
                <div class="tgs-grade-pane">
                    <!-- Info Pengumpul -->
                    <div style="background: #FFFFFF; border: 1px solid var(--lumina-border); border-radius: 12px; padding: 14px; display: flex; align-items: center; gap: 12px;">
                        <div id="inspectorStudentAvatar" style="width: 44px; height: 44px; border-radius: 10px; background: #0D7A87; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 16px;">
                            S
                        </div>
                        <div style="overflow: hidden;">
                            <div id="inspectorStudentName" style="font-weight: 800; font-size: 14px; color: var(--lumina-navy); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Nama Siswa</div>
                            <div id="inspectorStudentMeta" style="font-size: 11.5px; color: #64748B;">NISN / Kelas</div>
                        </div>
                    </div>

                    <!-- Anggota Kelompok (Jika ada) -->
                    <div id="inspectorGroupMembersBox" style="display: none; background: #FFFFFF; border: 1px solid var(--lumina-border); border-radius: 12px; padding: 12px;">
                        <div style="font-size: 11.5px; font-weight: 700; color: #7C3AED; margin-bottom: 6px;">Anggota Kelompok:</div>
                        <div id="inspectorGroupMembersList" style="font-size: 12px; color: #475569; display: flex; flex-direction: column; gap: 4px;"></div>
                    </div>

                    <!-- Catatan Siswa -->
                    <div style="background: #FFFFFF; border: 1px solid var(--lumina-border); border-radius: 12px; padding: 12px;">
                        <div style="font-size: 11.5px; font-weight: 700; color: #64748B; margin-bottom: 4px;">Catatan Pengantar Siswa:</div>
                        <div id="inspectorStudentNotes" style="font-size: 12.5px; color: #334155; font-style: italic; max-height: 80px; overflow-y: auto;">- Tidak ada catatan -</div>
                    </div>

                    <!-- Form Nilai -->
                    <form id="formPenilaianDirect" onsubmit="submitPenilaianDirect(event)" style="display: flex; flex-direction: column; gap: 14px;">
                        <input type="hidden" id="gradeSubmissionId" value="0">
                        <input type="hidden" id="gradeIsGroup" value="0">

                        <!-- SECTION 1: PENILAIAN MANDIRI / INDIVIDU -->
                        <div id="boxGradeIndividual" style="display: flex; flex-direction: column; gap: 14px;">
                            <div class="tgs-form-group" style="margin-bottom: 0;">
                                <label class="tgs-form-label">Input Nilai Tugas (Maksimal <span id="labelMaxScore">100</span>)</label>
                                <div class="tgs-score-input-box">
                                    <input type="number" id="inputScore" class="tgs-form-control tgs-score-input" min="0" max="100" step="0.5" required oninput="onScoreInputChange()">
                                    <div class="tgs-grade-badge-live" id="badgeGradePredikat">-</div>
                                    <div style="font-size: 12px; color: #64748B;">
                                        <div>Predikat Otomatis</div>
                                        <div style="font-weight: 700; color: var(--lumina-navy);" id="labelPredikatDesc">Belum Dinilai</div>
                                    </div>
                                </div>
                            </div>

                            <div class="tgs-form-group" style="margin-bottom: 0;">
                                <label class="tgs-form-label">Nilai Remedial (Jika Ada / Opsional)</label>
                                <input type="number" id="inputRemedial" class="tgs-form-control" placeholder="Nilai setelah perbaikan" min="0" max="100" step="0.5" oninput="onScoreInputChange()">
                            </div>
                        </div>

                        <!-- SECTION 2: PENILAIAN KELOMPOK & INDIVIDU (RUMUS KHUSUS) -->
                        <div id="boxGradeGroup" style="display: none; flex-direction: column; gap: 14px;">
                            <!-- Box Nilai Kelompok Bersama -->
                            <div style="background: #F0FDF4; border: 1.5px solid #86EFAC; border-radius: 12px; padding: 14px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                    <label class="tgs-form-label" style="margin-bottom: 0; color: #166534; display: flex; align-items: center; gap: 6px; font-weight: 800;">
                                        <iconify-icon icon="solar:users-group-two-rounded-bold" style="font-size: 18px; color: #16A34A;"></iconify-icon>
                                        <span>Nilai Kelompok (Karya Bersama)</span>
                                    </label>
                                    <span class="tgs-badge" style="background: #DCFCE7; color: #15803D; font-weight: 800;" id="badgeGroupMemberCount">0 Anggota</span>
                                </div>
                                
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <input type="number" id="inputGroupScore" class="tgs-form-control tgs-score-input" min="0" max="100" step="0.5" placeholder="0-100" oninput="onGroupScoreInputChange()" style="border-color: #86EFAC; background: #FFFFFF;">
                                    <div style="font-size: 12px; color: #166534; line-height: 1.4;">
                                        <div>Basis Penilaian: <strong>Karya Bersama (Skala 0-100)</strong></div>
                                        <div style="font-size: 13.5px; font-weight: 800; color: #15803D; margin-top: 2px;">
                                            Diberikan setara ke <span id="labelGroupMemberCount">1</span> santri
                                        </div>
                                    </div>
                                </div>
                                <div style="font-size: 11px; color: #15803D; margin-top: 8px; font-style: italic; background: rgba(255,255,255,0.7); padding: 6px 10px; border-radius: 6px;">
                                    📌 <strong>Rumus Adil & Seimbang:</strong> (Nilai Kelompok + Nilai Individu) / 2 &nbsp;•&nbsp; <em>Jika Kelompok 100 & Individu 100 = <strong>100</strong></em>
                                </div>
                            </div>

                            <!-- Rincian Nilai Individu Tiap Anggota -->
                            <div style="background: #FFFFFF; border: 1px solid var(--lumina-border); border-radius: 12px; padding: 14px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                    <div style="font-weight: 800; font-size: 13px; color: var(--lumina-navy); display: flex; align-items: center; gap: 6px;">
                                        <iconify-icon icon="solar:user-bold" style="color: var(--lumina-teal); font-size: 16px;"></iconify-icon>
                                        <span>Penilaian Individu Tiap Anggota:</span>
                                    </div>
                                    <button type="button" class="btn-tgs btn-tgs--sm" onclick="setSameIndividualScore()" style="font-size: 11px; padding: 4px 8px;" title="Terapkan nilai yang sama ke semua anggota kelompok">
                                        <iconify-icon icon="solar:copy-bold"></iconify-icon>
                                        <span>Samakan Nilai</span>
                                    </button>
                                </div>
                                <div id="inspectorGroupMembersGradeList" style="display: flex; flex-direction: column; gap: 10px; max-height: 260px; overflow-y: auto;">
                                    <!-- Diisi secara dinamis oleh JavaScript -->
                                </div>
                            </div>
                        </div>

                        <div class="tgs-form-group" style="margin-bottom: 0;">
                            <label class="tgs-form-label">Ketercapaian Aspek ITP</label>
                            <select id="selectAspekItp" class="tgs-form-control">
                                <option value="Tercapai Sangat Baik">Tercapai Sangat Baik</option>
                                <option value="Tercapai" selected>Tercapai</option>
                                <option value="Cukup Tercapai">Cukup Tercapai</option>
                                <option value="Perlu Bimbingan">Perlu Bimbingan</option>
                            </select>
                        </div>

                        <div class="tgs-form-group" style="margin-bottom: 0;">
                            <label class="tgs-form-label">Catatan & Umpan Balik Guru</label>
                            <textarea id="inputCatatanGuru" class="tgs-form-control" rows="3" placeholder="Berikan evaluasi konstruktif bagi karya siswa..."></textarea>
                            <div class="tgs-preset-chips">
                                <span class="tgs-preset-chip" onclick="addFeedbackPreset('Alhamdulillah, analisis sangat mendalam dan terstruktur rapi!')">🌟 Sangat Bagus</span>
                                <span class="tgs-preset-chip" onclick="addFeedbackPreset('Karya baik, namun perlu pendalaman pada argumen dan dalil rujukan.')">💡 Tambah Dalil</span>
                                <span class="tgs-preset-chip" onclick="addFeedbackPreset('Silakan lengkapi format laporan dan perbaiki sistematika penulisan.')">✍️ Rapikan Format</span>
                            </div>
                        </div>

                        <div class="tgs-form-group" style="margin-bottom: 0;">
                            <label class="tgs-form-label">Status Penilaian</label>
                            <select id="selectStatusPenilaian" class="tgs-form-control">
                                <option value="Dinilai">Dinilai & Tuntas</option>
                                <option value="Perlu Revisi">Perlu Revisi Siswa</option>
                                <option value="Tuntas">Tuntas Sempurna</option>
                            </select>
                        </div>

                        <button type="submit" class="btn-tgs btn-tgs--primary" id="btnSaveGrade" style="justify-content: center; padding: 12px; margin-top: 6px;">
                            <iconify-icon icon="solar:check-circle-bold" style="font-size: 18px;"></iconify-icon>
                            <span>Simpan Nilai & Umpan Balik</span>
                        </button>
                    </form>
                </div>

                <!-- STAGE DOKUMEN VIEWER TANPA DOWNLOAD (KANAN) -->
                <div class="tgs-viewer-pane" id="viewerStagePane">
                    <div class="tgs-viewer-toolbar">
                        <div class="tgs-viewer-title">
                            <iconify-icon icon="solar:shield-check-bold" style="color: #10B981; font-size: 16px;"></iconify-icon>
                            <span id="viewerFileNameLabel">Pratinjau Berkas Tugas</span>
                        </div>
                        <div class="tgs-viewer-controls" id="viewerToolbarControls">
                            <!-- Tombol kontrol PDF Canvas (Page Prev/Next, Zoom) -->
                            <button type="button" class="tgs-btn-viewtool" id="btnPdfPrev" onclick="changePdfPage(-1)">
                                <iconify-icon icon="solar:alt-arrow-left-bold"></iconify-icon>
                            </button>
                            <span id="pdfPageNumLabel" style="font-weight: 700; min-width: 50px; text-align: center;">- / -</span>
                            <button type="button" class="tgs-btn-viewtool" id="btnPdfNext" onclick="changePdfPage(1)">
                                <iconify-icon icon="solar:alt-arrow-right-bold"></iconify-icon>
                            </button>
                            <button type="button" class="tgs-btn-viewtool" onclick="zoomPdf(0.15)">
                                <iconify-icon icon="solar:magnifer-zoom-in-bold"></iconify-icon>
                            </button>
                            <button type="button" class="tgs-btn-viewtool" onclick="zoomPdf(-0.15)">
                                <iconify-icon icon="solar:magnifer-zoom-out-bold"></iconify-icon>
                            </button>
                            <span style="background: rgba(244, 63, 94, 0.2); color: #FDA4AF; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 800;">
                                VIEW-ONLY (ANTI-DOWNLOAD)
                            </span>
                        </div>
                    </div>

                    <!-- AREA CANVAS DOKUMEN -->
                    <div class="tgs-stage-content" id="stageContentArea" oncontextmenu="return preventRightClick(event)">
                        <!-- Watermark Overlay Keamanan -->
                        <div class="tgs-watermark-overlay">
                            <div class="tgs-watermark-text">
                                ILMUNA LEARN • TINJAUAN AKADEMIK RESMI
                                DILARANG MENGUNDUH / MEMPERBANYAK BERKAS
                            </div>
                        </div>

                        <!-- Konten Dinamis diinjeksi via JS: PDF Canvas, Iframe Preview, Gambar, Video -->
                        <div id="dynamicStageRender" style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;">
                            <div style="color: #64748B;">Pilih berkas untuk ditampilkan.</div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>


<!-- TOAST CONTAINER -->
<div class="tgs-toast-container" id="tgsToastWrap"></div>

<!-- LUMINA UNIFIED DIALOG MODAL (ALERT, CONFIRM, PROMPT) -->
<div class="tgs-dialog-backdrop" id="luminaDialogBackdrop">
    <div class="tgs-dialog-card" id="luminaDialogCard">
        <div class="tgs-dialog-icon-circle info" id="luminaDialogIconWrap">
            <iconify-icon icon="solar:info-circle-bold" id="luminaDialogIcon"></iconify-icon>
        </div>
        <h3 class="tgs-dialog-title" id="luminaDialogTitle">Pemberitahuan</h3>
        <div class="tgs-dialog-message" id="luminaDialogMsg">Pesan informasi sistem.</div>
        <input type="text" id="luminaDialogInput" class="tgs-dialog-input" style="display: none;" placeholder="">
        <div class="tgs-dialog-actions" id="luminaDialogActions">
            <button type="button" class="btn-tgs btn-tgs--secondary" id="luminaDialogBtnCancel" style="display: none;">Batal</button>
            <button type="button" class="btn-tgs btn-tgs--primary" id="luminaDialogBtnConfirm">Lanjutkan</button>
        </div>
    </div>
</div>

<!-- ============================================================================
 * JAVASCRIPT LOGIC & VIEW-ONLY CONTROLLERS
 * ============================================================================ -->
<script>
(function() {
    'use strict';

    // State Variables
    let currentKelasId = 0;
    let currentKmpId = 0;
    let currentTugasList = [];
    let currentSubmissions = [];
    let activeMainTab = 'tugas';
    let currentPdfDoc = null;
    let currentPdfPage = 1;
    let currentPdfScale = 1.25;
    let debounceTimer = null;
    window.currentKelompokTabData = null;

    // Inisialisasi PDF.js Worker
    if (window.pdfjsLib) {
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = '<?= BASE_URL ?>/assets/js/pdf.worker.min.js';
    }

    // --------------------------------------------------------------------------
    // LUMINA THEMED TOAST & DIALOG SYSTEM (ZERO BROWSER DEFAULT POPUP)
    // --------------------------------------------------------------------------
    let dialogResolve = null;

    function normDlgType(type) {
        if (type === 'warn' || type === 'warning') return 'warning';
        if (type === 'error' || type === 'err' || type === 'danger') return 'error';
        if (type === 'success' || type === 'ok') return 'success';
        return type || 'info';
    }

    window.luminaToast = function(title, message, type = 'success') {
        const t = normDlgType(type);
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            return Ilmuna.toast(t, title, message, 4000);
        }
        const wrap = document.getElementById('tgsToastWrap');
        if (!wrap) return;

        const icons = {
            success: 'solar:check-circle-bold',
            error: 'solar:close-circle-bold',
            warn: 'solar:danger-triangle-bold',
            warning: 'solar:danger-triangle-bold',
            info: 'solar:info-circle-bold'
        };
        const iconColors = {
            success: '#059669',
            error: '#E11D48',
            warn: '#D97706',
            warning: '#D97706',
            info: '#7C3AED'
        };

        const item = document.createElement('div');
        const normType = (type === 'warning') ? 'warn' : type;
        item.className = 'tgs-toast-item ' + (normType === 'error' ? 'err' : (normType === 'warn' ? 'warn' : (normType === 'info' ? 'info' : '')));
        item.innerHTML = `
            <iconify-icon icon="${icons[type] || icons.info}" class="tgs-toast-icon" style="color:${iconColors[type] || iconColors.info};"></iconify-icon>
            <div class="tgs-toast-content">
                <div class="tgs-toast-title">${escapeHtml(title)}</div>
                <div class="tgs-toast-msg">${escapeHtml(message)}</div>
            </div>
        `;
        wrap.appendChild(item);

        setTimeout(() => {
            item.style.transition = 'all 0.3s ease';
            item.style.opacity = '0';
            item.style.transform = 'translateX(120%)';
            setTimeout(() => item.remove(), 300);
        }, 3600);
    };

    window.luminaAlert = function(title, message, type = 'info') {
        const t = normDlgType(type);
        if (window.Ilmuna && typeof window.Ilmuna.alert === 'function') {
            return new Promise((resolve) => {
                Ilmuna.alert(t, title || 'Pemberitahuan', message || '', 'Tutup', false, () => resolve(true));
            });
        }
        return new Promise((resolve) => {
            const backdrop = document.getElementById('luminaDialogBackdrop');
            const iconWrap = document.getElementById('luminaDialogIconWrap');
            const iconEl = document.getElementById('luminaDialogIcon');
            const titleEl = document.getElementById('luminaDialogTitle');
            const msgEl = document.getElementById('luminaDialogMsg');
            const inputEl = document.getElementById('luminaDialogInput');
            const btnCancel = document.getElementById('luminaDialogBtnCancel');
            const btnConfirm = document.getElementById('luminaDialogBtnConfirm');

            const typeIcons = {
                success: 'solar:check-circle-bold',
                error: 'solar:close-circle-bold',
                warning: 'solar:danger-triangle-bold',
                warn: 'solar:danger-triangle-bold',
                info: 'solar:info-circle-bold'
            };

            const tNorm = (type === 'warn') ? 'warning' : type;
            iconWrap.className = 'tgs-dialog-icon-circle ' + (tNorm || 'info');
            iconEl.setAttribute('icon', typeIcons[type] || typeIcons.info);

            titleEl.textContent = title || 'Pemberitahuan';
            msgEl.textContent = message || '';
            inputEl.style.display = 'none';
            btnCancel.style.display = 'none';

            btnConfirm.className = 'btn-tgs ' + (type === 'error' ? 'btn-tgs--rose' : (type === 'warning' ? 'btn-tgs--amber' : 'btn-tgs--primary'));
            btnConfirm.innerHTML = `<span>Tutup</span>`;

            btnConfirm.onclick = () => {
                backdrop.classList.remove('show');
                resolve(true);
            };

            backdrop.classList.add('show');
            setTimeout(() => btnConfirm.focus(), 100);
        });
    };

    window.luminaConfirm = function(title, message, confirmText = 'Lanjutkan', cancelText = 'Batal', type = 'warning') {
        const t = normDlgType(type);
        if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
            return Ilmuna.confirm(t, title || 'Konfirmasi', message || '', confirmText, cancelText);
        }
        return new Promise((resolve) => {
            const backdrop = document.getElementById('luminaDialogBackdrop');
            const iconWrap = document.getElementById('luminaDialogIconWrap');
            const iconEl = document.getElementById('luminaDialogIcon');
            const titleEl = document.getElementById('luminaDialogTitle');
            const msgEl = document.getElementById('luminaDialogMsg');
            const inputEl = document.getElementById('luminaDialogInput');
            const btnCancel = document.getElementById('luminaDialogBtnCancel');
            const btnConfirm = document.getElementById('luminaDialogBtnConfirm');

            const typeIcons = {
                success: 'solar:check-circle-bold',
                error: 'solar:close-circle-bold',
                warning: 'solar:danger-triangle-bold',
                warn: 'solar:danger-triangle-bold',
                info: 'solar:question-circle-bold'
            };

            const tNorm = (type === 'warn') ? 'warning' : type;
            iconWrap.className = 'tgs-dialog-icon-circle ' + (tNorm || 'warning');
            iconEl.setAttribute('icon', typeIcons[type] || typeIcons.warning);

            titleEl.textContent = title || 'Konfirmasi';
            msgEl.textContent = message || '';
            inputEl.style.display = 'none';

            btnCancel.style.display = 'inline-flex';
            btnCancel.innerHTML = `<span>${escapeHtml(cancelText)}</span>`;

            btnConfirm.className = 'btn-tgs ' + (type === 'error' ? 'btn-tgs--rose' : (type === 'warning' ? 'btn-tgs--amber' : 'btn-tgs--primary'));
            btnConfirm.innerHTML = `<span>${escapeHtml(confirmText)}</span>`;

            btnCancel.onclick = () => {
                backdrop.classList.remove('show');
                resolve(false);
            };

            btnConfirm.onclick = () => {
                backdrop.classList.remove('show');
                resolve(true);
            };

            backdrop.classList.add('show');
            setTimeout(() => btnConfirm.focus(), 100);
        });
    };

    window.luminaPrompt = function(title, message, defaultValue = '', placeholder = '', confirmText = 'Simpan', cancelText = 'Batal') {
        return new Promise((resolve) => {
            const backdrop = document.getElementById('luminaDialogBackdrop');
            const iconWrap = document.getElementById('luminaDialogIconWrap');
            const iconEl = document.getElementById('luminaDialogIcon');
            const titleEl = document.getElementById('luminaDialogTitle');
            const msgEl = document.getElementById('luminaDialogMsg');
            const inputEl = document.getElementById('luminaDialogInput');
            const btnCancel = document.getElementById('luminaDialogBtnCancel');
            const btnConfirm = document.getElementById('luminaDialogBtnConfirm');

            iconWrap.className = 'tgs-dialog-icon-circle info';
            iconEl.setAttribute('icon', 'solar:pen-bold');

            titleEl.textContent = title || 'Input Data';
            msgEl.textContent = message || '';

            inputEl.style.display = 'block';
            inputEl.value = defaultValue || '';
            inputEl.placeholder = placeholder || '';

            btnCancel.style.display = 'inline-flex';
            btnCancel.innerHTML = `<span>${escapeHtml(cancelText)}</span>`;

            btnConfirm.className = 'btn-tgs btn-tgs--primary';
            btnConfirm.innerHTML = `<span>${escapeHtml(confirmText)}</span>`;

            const onDone = () => {
                const val = inputEl.value.trim();
                backdrop.classList.remove('show');
                resolve(val.length > 0 ? val : null);
            };

            btnCancel.onclick = () => {
                backdrop.classList.remove('show');
                resolve(null);
            };

            btnConfirm.onclick = onDone;
            inputEl.onkeydown = (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    onDone();
                } else if (e.key === 'Escape') {
                    backdrop.classList.remove('show');
                    resolve(null);
                }
            };

            backdrop.classList.add('show');
            setTimeout(() => inputEl.focus(), 150);
        });
    };

    // Override browser default alert ke Lumina Themed Alert
    window.alert = function(msg) {
        luminaAlert('Pemberitahuan', msg, 'info');
    };

    // --------------------------------------------------------------------------
    // PROTEKSI VIEW-ONLY & ANTI-DOWNLOAD
    // --------------------------------------------------------------------------
    window.preventRightClick = function(e) {
        e.preventDefault();
        luminaToast('Mode Tinjauan Aman', 'Pengunduhan dan penyalinan berkas dinonaktifkan.', 'warn');
        return false;
    };

    // Cegah pintasan tombol cetak dan simpan (Ctrl+S, Ctrl+P)
    window.addEventListener('keydown', function(e) {
        const isInspectorOpen = document.getElementById('modalInspector').classList.contains('show');
        if (isInspectorOpen) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'p' || e.key === 'S' || e.key === 'P')) {
                e.preventDefault();
                luminaToast('Berkas Terproteksi', 'Berkas dilindungi dalam mode pratinjau langsung (Anti-Download).', 'warn');
                return false;
            }
        }
    });

    // --------------------------------------------------------------------------
    // MODAL HELPERS — Support dual class: tgs-modal-backdrop (.show) & ilm-modal-backdrop (.is-open)
    // --------------------------------------------------------------------------
    window.openModal = function(id) {
        const el = document.getElementById(id);
        if (!el) {
            console.error('Modal element tidak ditemukan:', id);
            return;
        }

        // Tampilkan modal secara pasti dan instan
        el.style.display = 'flex';
        el.classList.add('is-open');
        el.classList.add('show');
        document.body.style.overflow = 'hidden';

        // Auto-focus elemen input pertama yang dapat diisi
        const firstInput = el.querySelector('input:not([type="hidden"]):not([readonly]), select, textarea');
        if (firstInput) setTimeout(() => firstInput.focus(), 150);

        // Bind IlmunaCalendar ke input [data-ilm-calendar] di dalam modal
        if (window.IlmunaCalendar) {
            el.querySelectorAll('[data-ilm-calendar]').forEach(inp => {
                inp.style.cursor = 'pointer';
                if (!inp._ilmCalBound) {
                    inp._ilmCalBound = true;
                    inp.addEventListener('click', function(e) {
                        e.preventDefault();
                        const withTime = inp.getAttribute('data-ilm-calendar') !== 'date';
                        window.IlmunaCalendar.open(inp, withTime);
                    });
                }
            });
        }
    };

    window.closeModal = function(id) {
        const el = document.getElementById(id);
        if (!el) return;

        el.classList.remove('is-open');
        el.classList.remove('show');

        setTimeout(() => {
            if (!el.classList.contains('is-open') && !el.classList.contains('show')) {
                el.style.display = 'none';
            }
        }, 220);

        if (!document.querySelector('.ilm-modal-backdrop.is-open, .tgs-modal-backdrop.show')) {
            document.body.style.overflow = '';
        }
    };

    // Auto-bind tombol Buat Tugas Baru saat DOM siap
    document.addEventListener('DOMContentLoaded', () => {
        const btnCreate = document.getElementById('btnOpenCreateTugas');
        if (btnCreate) {
            btnCreate.addEventListener('click', function(e) {
                e.preventDefault();
                openCreateTugasModal(e);
            });
        }
    });

    window.openGasModal = function() {
        openModal('modalGas');
    };

    // --------------------------------------------------------------------------
    // CASCADING FILTER ROMBEL & MAPEL
    // --------------------------------------------------------------------------
    async function loadKelasOptions() {
        try {
            const fd = new FormData();
            fd.append('action', 'list_kelas');
            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();

            const selectFilter = document.getElementById('filterKelas');
            const selectForm = document.getElementById('formKelasId');
            selectFilter.innerHTML = '<option value="">-- Semua Rombel Kelas --</option>';
            selectForm.innerHTML = '<option value="">-- Pilih Kelas --</option>';

            if (json.success && json.data) {
                if (json.data.length === 0) {
                    selectFilter.innerHTML = '<option value="">(Tidak ada kelas yang diampu)</option>';
                    selectForm.innerHTML = '<option value="">(Tidak ada kelas yang diampu)</option>';
                    const alertEl = document.getElementById('noClassAlert');
                    if (alertEl) alertEl.style.display = 'flex';
                } else {
                    const alertEl = document.getElementById('noClassAlert');
                    if (alertEl) alertEl.style.display = 'none';

                    json.data.forEach(k => {
                        const opt1 = document.createElement('option');
                        opt1.value = k.id;
                        opt1.textContent = `${k.nama_kelas} (${k.tahun_pelajaran || '2026/2027'})`;
                        selectFilter.appendChild(opt1);

                        const opt2 = document.createElement('option');
                        opt2.value = k.id;
                        opt2.textContent = `${k.nama_kelas} (${k.tahun_pelajaran || '2026/2027'})`;
                        selectForm.appendChild(opt2);
                    });
                }
            }
        } catch (e) {
            console.error('Gagal memuat kelas:', e);
        }
    }

    window.onKelasFilterChange = async function() {
        currentKelasId = parseInt(document.getElementById('filterKelas').value) || 0;
        const selectMapel = document.getElementById('filterMapel');
        selectMapel.innerHTML = '<option value="">-- Semua Mata Pelajaran --</option>';
        currentKmpId = 0;

        if (currentKelasId > 0) {
            selectMapel.disabled = false;
            try {
                const fd = new FormData();
                fd.append('action', 'list_mapel');
                fd.append('id_kelas', currentKelasId);
                const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.success && json.data) {
                    json.data.forEach(m => {
                        const opt = document.createElement('option');
                        opt.value = m.id;
                        opt.textContent = `${m.nama_mata_pelajaran} (${m.semester})`;
                        selectMapel.appendChild(opt);
                    });
                }
            } catch (e) {
                console.error('Gagal memuat mapel filter:', e);
            }
        } else {
            selectMapel.disabled = true;
        }

        reloadActiveTab();
        loadStats();
    };

    window.onMapelFilterChange = function() {
        currentKmpId = parseInt(document.getElementById('filterMapel').value) || 0;
        reloadActiveTab();
        loadStats();
    };

    window.onFormKelasChange = async function() {
        const idK = parseInt(document.getElementById('formKelasId').value) || 0;
        const selectKmp = document.getElementById('formKmpId');
        selectKmp.innerHTML = '<option value="">-- Pilih Mata Pelajaran --</option>';

        if (idK > 0) {
            selectKmp.disabled = false;
            try {
                const fd = new FormData();
                fd.append('action', 'list_mapel');
                fd.append('id_kelas', idK);
                const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.success && json.data) {
                    json.data.forEach(m => {
                        const opt = document.createElement('option');
                        opt.value = m.id;
                        opt.textContent = `${m.nama_mata_pelajaran} (${m.semester})`;
                        selectKmp.appendChild(opt);
                    });
                }
            } catch (e) {
                console.error('Gagal memuat mapel form:', e);
            }
        } else {
            selectKmp.disabled = true;
        }
    };

    // --------------------------------------------------------------------------
    // LOAD STATS
    // --------------------------------------------------------------------------
    async function loadStats() {
        try {
            const fd = new FormData();
            fd.append('action', 'get_stats');
            if (currentKmpId > 0) fd.append('id_kelas_mata_pelajaran', currentKmpId);

            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success && json.data) {
                const elStatTugas = document.getElementById('statTotalTugas');
                if (elStatTugas) elStatTugas.textContent = json.data.total_tugas;
                const elStatInd = document.getElementById('statTotalIndividu');
                if (elStatInd) elStatInd.textContent = json.data.total_individu;
                const elStatKel = document.getElementById('statTotalKelompok');
                if (elStatKel) elStatKel.textContent = json.data.total_kelompok;
                const elStatPeng = document.getElementById('statTotalPengumpulan');
                if (elStatPeng) elStatPeng.textContent = json.data.total_pengumpulan;
                const elStatTunggu = document.getElementById('statMenungguPenilaian');
                if (elStatTunggu) elStatTunggu.textContent = json.data.menunggu_penilaian;

                const tabTugas = document.getElementById('tabCountTugas');
                if (tabTugas) tabTugas.textContent = json.data.total_tugas;
                const tabKel = document.getElementById('tabCountKelompok');
                if (tabKel) tabKel.textContent = json.data.total_kelompok_terbentuk || json.data.total_kelompok || 0;
                const tabPen = document.getElementById('tabCountPenilaian');
                if (tabPen) tabPen.textContent = json.data.total_pengumpulan;
            }
        } catch (e) {
            console.error('Gagal memuat stats:', e);
        }
    }

    // --------------------------------------------------------------------------
    // MAIN TABS CONTROLLER (TUGAS | KELOMPOK | PENILAIAN)
    // --------------------------------------------------------------------------
    window.switchMainTab = function(tab) {
        activeMainTab = tab;
        document.getElementById('tabBtnTugas').classList.toggle('active', tab === 'tugas');
        const tabBtnKel = document.getElementById('tabBtnKelompok');
        if (tabBtnKel) tabBtnKel.classList.toggle('active', tab === 'kelompok');
        document.getElementById('tabBtnPenilaian').classList.toggle('active', tab === 'penilaian');

        document.getElementById('tabContentTugas').style.display = (tab === 'tugas') ? 'block' : 'none';
        const tabContentKel = document.getElementById('tabContentKelompok');
        if (tabContentKel) tabContentKel.style.display = (tab === 'kelompok') ? 'block' : 'none';
        document.getElementById('tabContentPenilaian').style.display = (tab === 'penilaian') ? 'block' : 'none';

        if (tab === 'tugas') {
            loadTugasList();
        } else if (tab === 'kelompok') {
            populateKelompokTugasDropdown();
        } else {
            populatePenilaianTugasDropdown();
        }
    };

    window.reloadActiveTab = function() {
        if (activeMainTab === 'tugas') {
            loadTugasList();
        } else if (activeMainTab === 'kelompok') {
            const sel = document.getElementById('selectKelompokTugas');
            const idT = parseInt(sel ? sel.value : 0) || 0;
            if (idT > 0) loadKelompokTabData(idT);
            else populateKelompokTugasDropdown();
        } else {
            populatePenilaianTugasDropdown();
        }
    };

    window.debounceSearch = function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            reloadActiveTab();
        }, 350);
    };

    // --------------------------------------------------------------------------
    // TAB 1: MANAJEMEN PENUGASAN (DUAL VIEW: CARD VS ROW TABLE + PAGINASI)
    // --------------------------------------------------------------------------
    let tugasPaginator = null;
    let currentTugasViewMode = localStorage.getItem('ilm_tugas_view_mode') || 'card';

    window.switchTugasViewMode = function(mode) {
        currentTugasViewMode = mode;
        localStorage.setItem('ilm_tugas_view_mode', mode);

        const btnCard = document.getElementById('btnViewCard');
        const btnTable = document.getElementById('btnViewTable');
        if (btnCard) btnCard.classList.toggle('is-active', mode === 'card');
        if (btnTable) btnTable.classList.toggle('is-active', mode === 'table');

        if (tugasPaginator) {
            tugasPaginator.setViewMode(mode);
            updateTaskBulkBar();
        }
    };

    async function loadTugasList(isQuiet = false) {
        const container = document.getElementById('tugasDataContainer');
        const pagBar = document.getElementById('tugasPaginationBar');
        if (!container) return;

        if (!isQuiet) {
            container.innerHTML = `
                <div class="tgs-loading-state" style="padding: 50px 20px; text-align: center;">
                    <div class="tgs-spinner"></div>
                    <div style="font-weight: 600; color: #64748B; margin-top: 10px;">Memuat daftar penugasan...</div>
                </div>
            `;
            if (pagBar) pagBar.innerHTML = '';
        } else {
            container.style.opacity = '0.5';
            container.style.transition = 'opacity 0.2s ease';
        }

        try {
            const fd = new FormData();
            fd.append('action', 'list_tugas');
            if (currentKmpId > 0) fd.append('id_kelas_mata_pelajaran', currentKmpId);
            fd.append('status', document.getElementById('filterStatusTugas').value);
            fd.append('search', document.getElementById('filterSearch').value);

            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();

            if (!json.success || !json.data || json.data.length === 0) {
                container.innerHTML = `
                    <div style="background: rgba(255, 255, 255, 0.95); border: 1px dashed var(--lumina-border); border-radius: 16px; padding: 50px 20px; text-align: center; margin: 16px;">
                        <iconify-icon icon="solar:clipboard-remove-bold" style="font-size: 48px; color: #CBD5E1; margin-bottom: 12px;"></iconify-icon>
                        <h4 style="font-family: 'Outfit', sans-serif; font-size: 17px; color: var(--lumina-navy); margin: 0 0 6px;">Belum Ada Tugas Ditemukan</h4>
                        <p style="font-size: 13px; color: #64748B; margin: 0 0 16px;">Silakan buat tugas baru atau ubah kriteria filter kelas & mapel.</p>
                        <button type="button" class="btn-tgs btn-tgs--primary" onclick="openCreateTugasModal()">
                            <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                            <span>Buat Tugas Sekarang</span>
                        </button>
                    </div>
                `;
                if (pagBar) pagBar.innerHTML = '';
                updateTaskBulkBar();
                return;
            }

            currentTugasList = json.data;

            // Inisialisasi Paginator Universal
            tugasPaginator = new IlmunaDataPaginator({
                items: currentTugasList,
                pageSize: 12,
                containerId: 'tugasDataContainer',
                paginationId: 'tugasPaginationBar',
                viewMode: currentTugasViewMode,
                viewModeKey: 'ilm_tugas_view_mode',
                onPageChange: () => {
                    updateTaskBulkBar();
                },

                // 1. RENDER KARTU (COMPACT CARD VIEW)
                renderCard: (items, gridEl) => {
                    gridEl.innerHTML = '';
                    items.forEach(t => {
                        const isKelompok = (t.jenis_penugasan === 'Kelompok');
                        const badgeJenis = isKelompok
                            ? `<span class="tgs-badge tgs-badge--kelompok"><iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon> Kelompok</span>`
                            : `<span class="tgs-badge tgs-badge--individ"><iconify-icon icon="solar:user-bold"></iconify-icon> Individu</span>`;

                        let badgeStatus = `<span class="tgs-badge tgs-badge--open">Dibuka</span>`;
                        if (t.status_tugas === 'Ditutup') badgeStatus = `<span class="tgs-badge tgs-badge--closed">Ditutup</span>`;
                        if (t.status_tugas === 'Draft') badgeStatus = `<span class="tgs-badge tgs-badge--draft">Draft</span>`;

                        const totalKumpul = parseInt(t.total_kumpul) || 0;
                        const totalDinilai = parseInt(t.total_dinilai) || 0;
                        const rataNilai = t.rata_nilai ? parseFloat(t.rata_nilai).toFixed(1) : '-';

                        const card = document.createElement('div');
                        card.className = 'ilm-compact-card';
                        card.id = `tgs-card-${t.id}`;
                        card.innerHTML = `
                            <div>
                                <div class="ilm-compact-card__header">
                                    <div style="display:flex; align-items:flex-start; gap:10px;">
                                        <input type="checkbox" class="tgs-chk-task" value="${t.id}" onchange="updateTaskBulkBar()" style="width: 17px; height: 17px; accent-color: var(--lumina-teal); cursor: pointer; margin-top: 3px; flex-shrink: 0;" title="Pilih tugas ini">
                                        <div>
                                            <h3 class="ilm-compact-card__title">${escapeHtml(t.judul_tugas)}</h3>
                                            <div class="ilm-compact-card__meta">
                                                <span>${escapeHtml(t.nama_kelas || 'Kelas')}</span> • 
                                                <span>${escapeHtml(t.nama_mata_pelajaran || 'Mapel')}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="display:flex; flex-direction:column; align-items:flex-end; gap:4px;">
                                        ${badgeStatus}
                                        ${badgeJenis}
                                    </div>
                                </div>

                                <div class="ilm-compact-card__desc">${escapeHtml(t.deskripsi_instruksi || 'Tidak ada deskripsi instruksi tambahan.')}</div>

                                <div class="tgs-item-card__progress" style="margin-bottom: 12px; padding: 8px 12px; background: rgba(248, 250, 252, 0.85); border-radius: 10px;">
                                    <div class="tgs-item-card__progress-label" style="font-size: 11px;">
                                        <span>Pengumpulan</span>
                                        <span><strong>${totalKumpul}</strong> Kumpul • <strong>${totalDinilai}</strong> Dinilai</span>
                                    </div>
                                    <div class="tgs-progress-bar" style="height: 6px;">
                                        <div class="tgs-progress-fill" style="width: ${totalKumpul > 0 ? Math.min(100, (totalDinilai / totalKumpul) * 100) : 0}%;"></div>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; font-size:10.5px; color:#64748B; margin-top:4px;">
                                        <span>Batas: ${formatDateTime(t.tenggat_waktu)}</span>
                                        <span>Rata-rata: <strong>${rataNilai}</strong></span>
                                    </div>
                                </div>
                            </div>

                            <div class="ilm-compact-card__footer">
                                <div style="display:flex; gap:5px;">
                                    <button type="button" class="ilm-table-btn" onclick="editTugas(${t.id})" title="Edit Detail Tugas">
                                        <iconify-icon icon="solar:pen-bold"></iconify-icon>
                                    </button>
                                    ${isKelompok ? `
                                        <button type="button" class="btn-tgs btn-tgs--sm" style="background:rgba(124,58,237,0.12); color:#7C3AED; padding:0 8px; height:32px; font-size:11px;" onclick="jumpToKelompokTab(${t.id})" title="Bagi & Kelola Kelompok">
                                            <iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon>
                                            <span>(${t.total_kelompok_dibuat || 0})</span>
                                        </button>
                                    ` : ''}
                                    <button type="button" class="ilm-table-btn" onclick="toggleStatusTugas(${t.id})" title="${t.status_tugas === 'Dibuka' ? 'Tutup Tugas' : 'Buka Tugas'}">
                                        <iconify-icon icon="${t.status_tugas === 'Dibuka' ? 'solar:lock-bold' : 'solar:lock-unlocked-bold'}"></iconify-icon>
                                    </button>
                                    <button type="button" class="ilm-table-btn ilm-table-btn--rose" onclick="deleteTugas(${t.id})" title="Hapus Tugas">
                                        <iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon>
                                    </button>
                                </div>

                                <button type="button" class="btn-tgs btn-tgs--primary btn-tgs--sm" style="height:32px; padding:0 12px; font-size:11.5px;" onclick="jumpToPenilaian(${t.id})">
                                    <iconify-icon icon="solar:diploma-bold"></iconify-icon>
                                    <span>Periksa (${totalKumpul})</span>
                                </button>
                            </div>
                        `;
                        gridEl.appendChild(card);
                    });
                },

                // 2. RENDER BARIS TABEL (COMPACT ROW TABLE VIEW)
                renderRow: (items, tbody, thead, paginator) => {
                    thead.innerHTML = `
                        <tr>
                            <th style="width: 44px; text-align: center;">
                                <input type="checkbox" id="chkSelectAllTableTasks" onchange="toggleSelectAllTasks(this)" style="width: 17px; height: 17px; accent-color: var(--lumina-teal); cursor: pointer;">
                            </th>
                            <th style="width: 45px; text-align: center;">No</th>
                            <th>Judul & Detail Penugasan</th>
                            <th>Rombel & Mapel</th>
                            <th style="text-align: center;">Jenis</th>
                            <th style="text-align: center;">Status</th>
                            <th>Tenggat Waktu</th>
                            <th style="text-align: center;">Pengumpulan</th>
                            <th style="text-align: right; width: 160px;">Aksi</th>
                        </tr>
                    `;

                    tbody.innerHTML = '';
                    const p = paginator || tugasPaginator || { currentPage: 1, pageSize: 12 };
                    const startNo = (p.currentPage - 1) * p.pageSize;

                    items.forEach((t, idx) => {
                        const isKelompok = (t.jenis_penugasan === 'Kelompok');
                        const badgeJenis = isKelompok
                            ? `<span class="tgs-badge tgs-badge--kelompok" style="font-size:10.5px; padding:3px 8px;"><iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon> Kelompok</span>`
                            : `<span class="tgs-badge tgs-badge--individ" style="font-size:10.5px; padding:3px 8px;"><iconify-icon icon="solar:user-bold"></iconify-icon> Individu</span>`;

                        let badgeStatus = `<span class="tgs-badge tgs-badge--open" style="font-size:10.5px; padding:3px 8px;">Dibuka</span>`;
                        if (t.status_tugas === 'Ditutup') badgeStatus = `<span class="tgs-badge tgs-badge--closed" style="font-size:10.5px; padding:3px 8px;">Ditutup</span>`;
                        if (t.status_tugas === 'Draft') badgeStatus = `<span class="tgs-badge tgs-badge--draft" style="font-size:10.5px; padding:3px 8px;">Draft</span>`;

                        const totalKumpul = parseInt(t.total_kumpul) || 0;
                        const totalDinilai = parseInt(t.total_dinilai) || 0;

                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td style="text-align: center;">
                                <input type="checkbox" class="tgs-chk-task" value="${t.id}" onchange="updateTaskBulkBar()" style="width: 17px; height: 17px; accent-color: var(--lumina-teal); cursor: pointer;">
                            </td>
                            <td style="text-align: center; font-weight: 700; color: #94A3B8;">${startNo + idx + 1}</td>
                            <td>
                                <strong style="color: var(--lumina-navy); font-size: 13.5px;">${escapeHtml(t.judul_tugas)}</strong>
                                <div style="font-size: 11.5px; color: #64748B; margin-top: 2px; max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    ${escapeHtml(t.deskripsi_instruksi || 'Tidak ada deskripsi tambahan.')}
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 700; color: #334155;">${escapeHtml(t.nama_kelas || 'Kelas')}</div>
                                <div style="font-size: 11px; color: #64748B;">${escapeHtml(t.nama_mata_pelajaran || 'Mapel')}</div>
                            </td>
                            <td style="text-align: center;">${badgeJenis}</td>
                            <td style="text-align: center;">${badgeStatus}</td>
                            <td>
                                <div style="font-size: 12px; font-weight: 600; color: #334155;">${formatDateTime(t.tenggat_waktu)}</div>
                                <div style="font-size: 10.5px; color: #94A3B8;">Bobot: ${t.bobot_nilai}% • Max: ${t.nilai_maksimal}</div>
                            </td>
                            <td style="text-align: center;">
                                <span class="tgs-badge" style="background: rgba(13,122,135,0.1); color: #0D7A87; font-weight: 800;">
                                    ${totalKumpul} Kumpul • ${totalDinilai} Dinilai
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; align-items: center; gap: 4px;">
                                    <button type="button" class="ilm-table-btn" onclick="editTugas(${t.id})" title="Edit Tugas">
                                        <iconify-icon icon="solar:pen-bold"></iconify-icon>
                                    </button>
                                    <button type="button" class="ilm-table-btn" onclick="toggleStatusTugas(${t.id})" title="Ubah Status">
                                        <iconify-icon icon="${t.status_tugas === 'Dibuka' ? 'solar:lock-bold' : 'solar:lock-unlocked-bold'}"></iconify-icon>
                                    </button>
                                    <button type="button" class="ilm-table-btn ilm-table-btn--rose" onclick="deleteTugas(${t.id})" title="Hapus Tugas">
                                        <iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon>
                                    </button>
                                    <button type="button" class="ilm-table-btn ilm-table-btn--primary" onclick="jumpToPenilaian(${t.id})" title="Periksa Pengumpulan">
                                        <span>Nilai</span>
                                    </button>
                                </div>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                }
            });

            // Sinkronisasi tombol view mode
            const btnCard = document.getElementById('btnViewCard');
            const btnTable = document.getElementById('btnViewTable');
            if (btnCard) btnCard.classList.toggle('is-active', currentTugasViewMode === 'card');
            if (btnTable) btnTable.classList.toggle('is-active', currentTugasViewMode === 'table');

            updateTaskBulkBar();

        } catch (e) {
            console.error('Gagal memuat tugas:', e);
            container.innerHTML = `<div style="color:red; text-align:center; padding:30px;">Gagal memuat tugas: ${e.message}</div>`;
        } finally {
            container.style.opacity = '1';
        }
    }

    // --------------------------------------------------------------------------
    // BUKA MODAL BUAT TUGAS BARU
    // --------------------------------------------------------------------------
    window.openCreateTugasModal = async function() {
        const formKelas = document.getElementById('formKelasId');
        // Jika opsi kelas belum selesai dimuat, muat ulang
        if (formKelas && formKelas.options.length <= 1 && formKelas.options[0].value === '') {
            if (typeof loadKelasOptions === 'function') {
                try { await loadKelasOptions(); } catch(e) {}
            }
        }

        const form = document.getElementById('formTugas');
        if (form) form.reset();
        document.getElementById('tugasFormId').value = '0';
        document.getElementById('modalTugasTitle').textContent = 'Buat Penugasan Baru';
        const curBox = document.getElementById('formCurrentFileBox');
        if (curBox) curBox.style.display = 'none';
        const fileInput = document.getElementById('formFileLampiran');
        if (fileInput) fileInput.value = '';
        const emptyState = document.getElementById('dropzoneEmptyState');
        const previewState = document.getElementById('dropzonePreviewState');
        if (emptyState) emptyState.style.display = 'flex';
        if (previewState) previewState.style.display = 'none';

        // Pre-fill jika filter aktif
        if (currentKelasId > 0 && formKelas) {
            formKelas.value = currentKelasId;
            if (typeof onFormKelasChange === 'function') {
                onFormKelasChange().then(() => {
                    if (currentKmpId > 0) {
                        const formKmp = document.getElementById('formKmpId');
                        if (formKmp) formKmp.value = currentKmpId;
                    }
                });
            }
        }

        // Format tanggal visual standar (YYYY-MM-DD HH:mm:00)
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        const dt = String(now.getDate()).padStart(2, '0');
        const hh = String(now.getHours()).padStart(2, '0');
        const mm = String(now.getMinutes()).padStart(2, '0');
        document.getElementById('formTglMulai').value = `${y}-${m}-${dt} ${hh}:${mm}:00`;

        const d7 = new Date();
        d7.setDate(d7.getDate() + 7);
        const y7 = d7.getFullYear();
        const m7 = String(d7.getMonth() + 1).padStart(2, '0');
        const dt7 = String(d7.getDate()).padStart(2, '0');
        document.getElementById('formTenggat').value = `${y7}-${m7}-${dt7} 23:59:00`;

        openModal('modalTugas');
    };

    // EDIT TUGAS
    window.editTugas = async function(id) {
        try {
            const fd = new FormData();
            fd.append('action', 'get_tugas');
            fd.append('id', id);
            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();

            if (!json.success || !json.data) {
                luminaToast('Peringatan', 'Tugas tidak ditemukan.', 'warn');
                return;
            }

            const t = json.data;
            document.getElementById('tugasFormId').value = t.id;
            document.getElementById('modalTugasTitle').textContent = 'Edit Penugasan: ' + t.judul_tugas;

            document.getElementById('formKelasId').value = t.id_kelas;
            await onFormKelasChange();
            document.getElementById('formKmpId').value = t.id_kelas_mata_pelajaran;

            document.getElementById('formJudulTugas').value = t.judul_tugas;
            document.getElementById('formDeskripsi').value = t.deskripsi_instruksi || '';
            document.getElementById('formJenisPenugasan').value = t.jenis_penugasan;
            document.getElementById('formTipePengumpulan').value = t.tipe_pengumpulan;
            document.getElementById('formFormatFile').value = t.format_file_diizinkan || '';
            document.getElementById('formNilaiMax').value = t.nilai_maksimal;
            document.getElementById('formBobot').value = t.bobot_nilai;
            document.getElementById('formPertemuan').value = t.pertemuan_ke;

            if (t.tanggal_mulai) {
                document.getElementById('formTglMulai').value = t.tanggal_mulai;
            }
            if (t.tenggat_waktu) {
                document.getElementById('formTenggat').value = t.tenggat_waktu;
            }
            document.getElementById('formLinkSumber').value = t.link_sumber_tugas || '';
            document.getElementById('formStatusTugas').value = t.status_tugas;

            const fileInput = document.getElementById('formFileLampiran');
            if (fileInput) fileInput.value = '';
            const emptyState = document.getElementById('dropzoneEmptyState');
            const previewState = document.getElementById('dropzonePreviewState');
            if (emptyState) emptyState.style.display = 'flex';
            if (previewState) previewState.style.display = 'none';

            const curBox = document.getElementById('formCurrentFileBox');
            if (t.file_lampiran_tugas) {
                curBox.style.display = 'flex';
                const fileName = escapeHtml(t.file_lampiran_tugas.split('/').pop());
                curBox.innerHTML = `
                    <div style="display:flex; align-items:center; gap:8px;">
                        <iconify-icon icon="solar:document-bold" style="color:var(--lumina-teal); font-size:18px;"></iconify-icon>
                        <div>
                            <span style="font-size:11px; color:#64748B;">Berkas Lampiran di Google Drive:</span>
                            <div style="font-weight:700; color:var(--lumina-navy);">${fileName}</div>
                        </div>
                    </div>
                    ${t.gdrive_view_url ? `<a href="${escapeHtml(t.gdrive_view_url)}" target="_blank" class="ilm-modal-btn ilm-modal-btn--ghost" style="height:32px; padding:0 12px; font-size:11.5px; border-radius:8px; text-decoration:none;">Pratinjau</a>` : ''}
                `;
            } else {
                curBox.style.display = 'none';
            }

            openModal('modalTugas');

        } catch (e) {
            console.error('Gagal mengambil data tugas:', e);
            luminaAlert('Kesalahan', 'Gagal mengambil data tugas: ' + e.message, 'error');
        }
    };

    // SUBMIT SIMPAN TUGAS
    window.submitFormTugas = async function(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSubmitTugas');
        const origText = btn.innerHTML;

        // Validasi manual untuk readonly input (browser skip required validation pada readonly field)
        const tenggatVal = document.getElementById('formTenggat').value;
        if (!tenggatVal) {
            luminaAlert('Perhatian', 'Tenggat Waktu (Deadline) wajib diisi. Klik ikon kalender di kolom Tenggat Waktu.', 'warning');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = `<span class="tgs-spinner" style="width:16px;height:16px;margin:0;border-width:2px;display:inline-block;"></span> Menyimpan...`;

        try {
            const form = document.getElementById('formTugas');
            const fd = new FormData(form);
            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();

            if (json.success) {
                luminaToast('Alhamdulillah!', json.message, 'success');
                closeModal('modalTugas');
                loadTugasList(true);
                loadStats();
            } else {
                luminaAlert('Gagal Menyimpan', json.message, 'error');
            }
        } catch (err) {
            console.error('Gagal menyimpan tugas:', err);
            luminaAlert('Kesalahan Jaringan', 'Terjadi kesalahan koneksi saat menyimpan tugas.', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    };

    // HAPUS TUGAS
    window.deleteTugas = async function(id) {
        const ok = await luminaConfirm(
            'Hapus Penugasan?',
            'Apakah Anda yakin ingin menghapus tugas ini? Seluruh data pengumpulan siswa, kelompok, dan nilai terkait akan ikut terhapus.',
            'Ya, Hapus Tugas',
            'Batal',
            'error'
        );
        if (!ok) return;

        try {
            const fd = new FormData();
            fd.append('action', 'delete_tugas');
            fd.append('id', id);
            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();

            if (json.success) {
                luminaToast('Tugas Dihapus', json.message, 'success');
                loadTugasList(true);
                loadStats();
            } else {
                luminaAlert('Gagal Menghapus', json.message, 'error');
            }
        } catch (e) {
            console.error('Gagal menghapus tugas:', e);
            luminaToast('Kesalahan Jaringan', 'Gagal menghapus tugas.', 'error');
        }
    };

    // TOGGLE STATUS TUGAS
    window.toggleStatusTugas = async function(id) {
        try {
            const fd = new FormData();
            fd.append('action', 'toggle_status_tugas');
            fd.append('id', id);
            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                loadTugasList(true);
            }
        } catch (e) {
            console.error('Gagal toggle status:', e);
        }
    };

    // --------------------------------------------------------------------------
    // TAB 2: BAGI & KELOLA KELOMPOK BELAJAR (INTERAKTIF, ACAK & ADIL)
    // --------------------------------------------------------------------------
    window.jumpToKelompokTab = function(idTugas) {
        switchMainTab('kelompok');
        setTimeout(() => {
            populateKelompokTugasDropdown(idTugas);
        }, 120);
    };

    window.openKelompokModal = function(idTugas) {
        jumpToKelompokTab(idTugas);
    };

    async function populateKelompokTugasDropdown(preselectId = null) {
        const sel = document.getElementById('selectKelompokTugas');
        if (!sel) return;
        const currentVal = preselectId || sel.value;
        sel.innerHTML = '<option value="">-- Memuat Tugas Kelompok... --</option>';

        try {
            const fd = new FormData();
            fd.append('action', 'list_tugas');
            if (currentKmpId > 0) fd.append('id_kelas_mata_pelajaran', currentKmpId);
            fd.append('per', 100);

            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();

            sel.innerHTML = '<option value="">-- Pilih Penugasan Kelompok --</option>';
            if (json.success && json.data) {
                const kelompokTasks = json.data.filter(t => t.jenis_penugasan === 'Kelompok');

                if (kelompokTasks.length === 0) {
                    sel.innerHTML = '<option value="">(Belum ada penugasan jenis Kelompok)</option>';
                    document.getElementById('kelompokTabCardsContainer').innerHTML = `
                        <div style="background: #FFFFFF; border: 1px dashed var(--lumina-border); border-radius: 16px; padding: 60px 20px; text-align: center; color: #64748B;">
                            <iconify-icon icon="solar:users-group-two-rounded-bold" style="font-size: 48px; color: #CBD5E1; margin-bottom: 12px;"></iconify-icon>
                            <h4 style="font-family: 'Outfit', sans-serif; font-size: 18px; color: var(--lumina-navy); margin: 0 0 6px;">Belum Ada Tugas Berjenis Kelompok</h4>
                            <p style="font-size: 13px; color: #64748B; margin: 0 0 16px;">Buat tugas baru dengan opsi 'Jenis Penugasan: Kelompok' untuk mulai membagi kelompok siswa.</p>
                            <button type="button" class="btn-tgs btn-tgs--primary" onclick="switchMainTab('tugas'); openCreateTugasModal();">
                                <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                                <span>Buat Tugas Kelompok Baru</span>
                            </button>
                        </div>
                    `;
                    document.getElementById('panelAutoGenerateBox').style.display = 'none';
                    document.getElementById('kelompokTugasQuickActions').style.display = 'none';
                    return;
                }

                kelompokTasks.forEach(t => {
                    const opt = document.createElement('option');
                    opt.value = t.id;
                    opt.textContent = `[${t.nama_kelas || 'Kelas'}] ${t.judul_tugas} - ${t.nama_mata_pelajaran || ''} (${t.total_kelompok_dibuat || 0} kelompok)`;
                    sel.appendChild(opt);
                });

                if (currentVal && kelompokTasks.some(t => t.id == currentVal)) {
                    sel.value = currentVal;
                } else if (kelompokTasks.length === 1) {
                    sel.value = kelompokTasks[0].id;
                }
            }

            onSelectKelompokTugasChange();
        } catch (e) {
            console.error('Gagal dropdown kelompok tugas:', e);
        }
    }

    window.onSelectKelompokTugasChange = function() {
        const sel = document.getElementById('selectKelompokTugas');
        const idTugas = parseInt(sel ? sel.value : 0) || 0;
        const panelBox = document.getElementById('panelAutoGenerateBox');
        const quickActions = document.getElementById('kelompokTugasQuickActions');

        if (idTugas <= 0) {
            if (panelBox) panelBox.style.display = 'none';
            if (quickActions) quickActions.style.display = 'none';
            document.getElementById('kelompokTabCardsContainer').innerHTML = `
                <div style="background: #FFFFFF; border: 1px dashed var(--lumina-border); border-radius: 16px; padding: 60px 20px; text-align: center; color: #64748B;">
                    <iconify-icon icon="solar:users-group-two-rounded-bold" style="font-size: 48px; color: #CBD5E1; margin-bottom: 12px;"></iconify-icon>
                    <h4 style="font-family: 'Outfit', sans-serif; font-size: 18px; color: var(--lumina-navy); margin: 0 0 6px;">Pilih Tugas Kelompok di Atas</h4>
                    <p style="font-size: 13px; color: #64748B; margin: 0;">Pilih salah satu penugasan kelompok untuk menentukan jumlah kelompok, membagi siswa secara acak, dan menentukan ketua kelompok.</p>
                </div>
            `;
            return;
        }

        if (panelBox) panelBox.style.display = 'block';
        if (quickActions) quickActions.style.display = 'flex';
        loadKelompokTabData(idTugas);
    };

    window.adjustJmlKelompok = function(delta) {
        const inp = document.getElementById('inputJumlahKelompokTab');
        if (!inp) return;
        let val = parseInt(inp.value) || 2;
        val = Math.max(2, Math.min(15, val + delta));
        inp.value = val;
        updateJmlKelompokEstimate();
    };

    window.updateJmlKelompokEstimate = function() {
        const inp = document.getElementById('inputJumlahKelompokTab');
        const estText = document.getElementById('kelompokEstimateText');
        if (!inp || !estText) return;
        const jml = parseInt(inp.value) || 2;
        const total = (window.currentKelompokTabData && window.currentKelompokTabData.total_siswa_kelas) ? window.currentKelompokTabData.total_siswa_kelas : 0;
        if (total > 0) {
            const minPer = Math.floor(total / jml);
            const maxPer = Math.ceil(total / jml);
            estText.innerHTML = `✨ <strong>Estimasi Pembagian Adil:</strong> Total <strong>${total} Siswa</strong> akan dibagi ke <strong>${jml} Kelompok</strong> (~${minPer === maxPer ? minPer : `${minPer} s/d ${maxPer}`} siswa per kelompok).`;
        } else {
            estText.innerHTML = `✨ <strong>Estimasi:</strong> Membagi siswa ke dalam <strong>${jml} Kelompok</strong> secara merata.`;
        }
    };

    window.executeAutoGenerateTab = async function() {
        const sel = document.getElementById('selectKelompokTugas');
        const idTugas = parseInt(sel ? sel.value : 0) || 0;
        if (idTugas <= 0) {
            luminaToast('Peringatan', 'Silakan pilih tugas kelompok terlebih dahulu.', 'warn');
            return;
        }

        const inp = document.getElementById('inputJumlahKelompokTab');
        const jml = parseInt(inp ? inp.value : 4) || 4;
        const total = (window.currentKelompokTabData && window.currentKelompokTabData.total_siswa_kelas) || 0;

        const confirmMsg = `Bagi ${total > 0 ? total + ' santri kelas ini' : 'seluruh siswa'} menjadi ${jml} kelompok secara acak dan seimbang?\n\nCatatan: Pembagian baru ini akan mengatur ulang susunan kelompok pada tugas ini.`;
        const ok = await luminaConfirm('Bagi Kelompok Acak & Adil?', confirmMsg, 'Bagi Kelompok Sekarang', 'Batal', 'warning');
        if (!ok) return;

        const btn = document.getElementById('btnAutoGenerateTab');
        const origHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = `<span class="tgs-spinner" style="width:16px;height:16px;margin:0;border-width:2px;display:inline-block;"></span> Mengacak Siswa...`;
        }

        try {
            const fd = new FormData();
            fd.append('action', 'auto_generate_kelompok');
            fd.append('id_tugas', idTugas);
            fd.append('jumlah_kelompok', jml);

            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();

            if (json.success) {
                luminaToast('Alhamdulillah!', json.message, 'success');
                loadKelompokTabData(idTugas);
                loadStats();
            } else {
                luminaAlert('Gagal Membagi Kelompok', json.message, 'error');
            }
        } catch (e) {
            console.error('Gagal generate kelompok:', e);
            luminaAlert('Kesalahan Jaringan', 'Terjadi kesalahan koneksi saat membagi kelompok.', 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        }
    };

    async function loadKelompokTabData(idTugas) {
        const container = document.getElementById('kelompokTabCardsContainer');
        if (!container) return;

        if (!idTugas || idTugas <= 0) {
            container.innerHTML = `
                <div style="background: #FFFFFF; border: 1px dashed var(--lumina-border); border-radius: 16px; padding: 60px 20px; text-align: center; color: #64748B;">
                    <iconify-icon icon="solar:users-group-two-rounded-bold" style="font-size: 48px; color: #CBD5E1; margin-bottom: 12px;"></iconify-icon>
                    <h4 style="font-family: 'Outfit', sans-serif; font-size: 18px; color: var(--lumina-navy); margin: 0 0 6px;">Pilih Tugas Kelompok di Atas</h4>
                    <p style="font-size: 13px; color: #64748B; margin: 0;">Pilih salah satu penugasan kelompok untuk menentukan jumlah kelompok, membagi siswa acak, dan memilih ketua kelompok.</p>
                </div>
            `;
            return;
        }

        container.innerHTML = `
            <div class="tgs-loading-state" style="padding: 50px 20px;">
                <div class="tgs-spinner"></div>
                <div>Memuat data kelompok pembelajaran...</div>
            </div>
        `;

        try {
            const fd = new FormData();
            fd.append('action', 'get_kelompok_tab_data');
            fd.append('id_tugas', idTugas);

            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();

            const payload = json.data || json;
            if (!json.success || !payload || (!payload.kelompoks && !payload.tugas)) {
                container.innerHTML = `
                    <div style="background: #FFF1F2; border: 1px solid #FECDD3; border-radius: 14px; padding: 24px; text-align: center; color: #BE123C;">
                        <iconify-icon icon="solar:danger-triangle-bold" style="font-size: 36px; margin-bottom: 8px;"></iconify-icon>
                        <div style="font-weight: 700; font-size: 15px;">Gagal Memuat Data Kelompok</div>
                        <p style="font-size: 13px; margin: 4px 0 0;">${escapeHtml(json.message || 'Terjadi kesalahan sistem.')}</p>
                    </div>
                `;
                return;
            }

            window.currentKelompokTabData = payload;
            const data = payload;

            // Perbarui teks info rombel kelas & estimasi
            const infoText = document.getElementById('kelompokInfoKelasText');
            if (infoText && data.tugas) {
                infoText.innerHTML = `Kelas: <strong>${escapeHtml(data.tugas.nama_kelas || '')}</strong> • Mapel: <strong>${escapeHtml(data.tugas.nama_mata_pelajaran || '')}</strong> • Total Siswa: <strong>${data.total_siswa_kelas || 0} Santri</strong>. Tentukan berapa kelompok yang diinginkan, sistem akan membagi secara acak dan adil.`;
            }
            updateJmlKelompokEstimate();

            // Cek kelompok
            const kelompoks = data.kelompoks || [];
            const unassigned = data.unassigned_siswa || [];

            if (kelompoks.length === 0) {
                container.innerHTML = `
                    <div style="background: #FFFFFF; border: 1px dashed var(--lumina-border); border-radius: 18px; padding: 60px 20px; text-align: center;">
                        <div style="width: 64px; height: 64px; border-radius: 50%; background: rgba(124, 58, 237, 0.1); color: #7C3AED; display: inline-flex; align-items: center; justify-content: center; font-size: 32px; margin-bottom: 14px;">
                            <iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon>
                        </div>
                        <h4 style="font-family: 'Outfit', sans-serif; font-size: 19px; font-weight: 800; color: var(--lumina-navy); margin: 0 0 8px;">Belum Ada Kelompok Terbentuk</h4>
                        <p style="font-size: 13.5px; color: #64748B; max-width: 520px; margin: 0 auto 20px; line-height: 1.6;">
                            Tugas ini belum memiliki susunan kelompok. Gunakan tombol generator <strong>"Acak & Bagi Kelompok Sekarang"</strong> di atas untuk membagi <strong>${data.total_siswa_kelas || 0} santri</strong> secara merata dan adil.
                        </p>
                        <button type="button" class="btn-tgs" style="background: linear-gradient(135deg, #7C3AED 0%, #4F46E5 100%); color: #FFFFFF; padding: 10px 22px; font-size: 14px;" onclick="executeAutoGenerateTab()">
                            <iconify-icon icon="solar:magic-stick-3-bold" style="font-size: 20px;"></iconify-icon>
                            <span>Bagi Kelompok Otomatis Sekarang</span>
                        </button>
                    </div>
                `;
                return;
            }

            // Render Kelompok Grid
            let html = `
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                    <div style="font-family: 'Outfit', sans-serif; font-size: 17px; font-weight: 800; color: var(--lumina-navy); display: flex; align-items: center; gap: 8px;">
                        <span>Daftar Susunan Kelompok</span>
                        <span class="tgs-badge" style="background: #EDE9FE; color: #6D28D9; font-weight: 800;">${kelompoks.length} Kelompok</span>
                    </div>
                    <div style="font-size: 12.5px; color: #64748B;">
                        Total Anggota Terbagi: <strong>${data.total_siswa_kelas - unassigned.length}</strong> dari <strong>${data.total_siswa_kelas}</strong> Siswa
                    </div>
                </div>
                <div class="tgs-kelompok-grid">
            `;

            kelompoks.forEach(kel => {
                const anggota = kel.anggota || [];
                const jmlAnggota = anggota.length;
                const hasKetua = (kel.id_ketua_kelompok && kel.nama_ketua);

                // Options untuk kelompok lain (untuk fitur pindah kelompok)
                let moveOptionsHtml = '<option value="">Pindah ke...</option>';
                kelompoks.forEach(otherK => {
                    if (otherK.id != kel.id) {
                        moveOptionsHtml += `<option value="${otherK.id}">Kelompok ${otherK.nomor_kelompok}</option>`;
                    }
                });

                // Options ketua dropdown
                let ketuaSelectOptions = '<option value="">-- Pilih Ketua Kelompok --</option>';
                anggota.forEach(a => {
                    const selAttr = (a.id_siswa == kel.id_ketua_kelompok) ? 'selected' : '';
                    ketuaSelectOptions += `<option value="${a.id_siswa}" ${selAttr}>${escapeHtml(a.nama_lengkap)}</option>`;
                });

                // Header Card
                html += `
                    <div class="tgs-kelompok-card">
                        <div>
                            <div class="tgs-kelompok-card__header">
                                <div>
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <span class="tgs-badge" style="background: rgba(124, 58, 237, 0.12); color: #7C3AED; font-weight: 800; font-size: 11px;">#${kel.nomor_kelompok}</span>
                                        <div class="tgs-kelompok-card__title" title="${escapeHtml(kel.nama_kelompok)}">${escapeHtml(kel.nama_kelompok)}</div>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 5px;">
                                    <button type="button" class="btn-tgs btn-tgs--secondary" style="padding: 4px 8px; font-size: 12px; height: 30px;" onclick="renameKelompokTab(${kel.id}, '${escapeHtml(kel.nama_kelompok)}')" title="Ubah Nama Kelompok">
                                        <iconify-icon icon="solar:pen-bold"></iconify-icon>
                                    </button>
                                    <button type="button" class="btn-tgs btn-tgs--secondary" style="padding: 4px 8px; font-size: 12px; height: 30px; color: var(--lumina-rose);" onclick="deleteKelompokInTab(${kel.id})" title="Hapus Kelompok Ini">
                                        <iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon>
                                    </button>
                                </div>
                            </div>

                            <!-- Ketua Kelompok Spotlight -->
                            <div class="tgs-ketua-spotlight" style="${hasKetua ? '' : 'background: #FFFBEB; border-color: #FDE68A;'}">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <div class="tgs-ketua-label" style="color: ${hasKetua ? '#92400E' : '#B45309'};">
                                        <iconify-icon icon="solar:crown-star-bold" style="font-size: 14px; color: #F59E0B;"></iconify-icon>
                                        <span>Ketua Kelompok</span>
                                    </div>
                                    ${hasKetua ? `
                                        <span class="tgs-badge" style="background: #FFFFFF; color: #B45309; font-size: 10px; font-weight: 800; border: 1px solid #FDE68A;">TERPILIH</span>
                                    ` : `
                                        <span class="tgs-badge" style="background: #FEF2F2; color: #DC2626; font-size: 10px; font-weight: 800;">BELUM DITENTUKAN</span>
                                    `}
                                </div>

                                ${hasKetua ? `
                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                                        <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                                            <div style="width: 34px; height: 34px; border-radius: 50%; background: #FDE68A; color: #78350F; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; flex-shrink: 0; border: 2px solid #FFFFFF;">
                                                ${escapeHtml(kel.nama_ketua.charAt(0).toUpperCase())}
                                            </div>
                                            <div style="min-width: 0;">
                                                <div style="font-weight: 800; font-size: 13.5px; color: #78350F; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                    ${escapeHtml(kel.nama_ketua)}
                                                </div>
                                                <div style="font-size: 11px; color: #92400E;">NISN: ${escapeHtml(kel.nisn_ketua || '-')}</div>
                                            </div>
                                        </div>
                                        <select class="tgs-select" style="font-size: 11.5px; padding: 3px 6px; width: auto; max-width: 125px; height: 28px; background: #FFFFFF; border-color: #FCD34D;" onchange="setKetuaKelompokTab(${kel.id}, this.value)" title="Ganti Ketua Kelompok">
                                            ${ketuaSelectOptions}
                                        </select>
                                    </div>
                                ` : `
                                    <div>
                                        <div style="font-size: 12px; color: #92400E; margin-bottom: 6px;">Pilih salah satu anggota untuk menjadi ketua:</div>
                                        <select class="tgs-select" style="font-size: 12px; padding: 4px 8px; width: 100%; height: 32px; background: #FFFFFF; border-color: #FCD34D; font-weight: 700; color: #78350F;" onchange="setKetuaKelompokTab(${kel.id}, this.value)">
                                            ${ketuaSelectOptions}
                                        </select>
                                    </div>
                                `}
                            </div>

                            <!-- Anggota List -->
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                <div style="font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">
                                    Anggota Kelompok (${jmlAnggota})
                                </div>
                            </div>

                            <div class="tgs-anggota-list">
                `;

                if (anggota.length === 0) {
                    html += `
                        <div style="text-align: center; padding: 20px; color: #94A3B8; font-size: 12px; font-style: italic; background: #F8FAFC; border-radius: 8px;">
                            Belum ada anggota di kelompok ini.
                        </div>
                    `;
                } else {
                    anggota.forEach(a => {
                        const isKetua = (a.id_siswa == kel.id_ketua_kelompok);
                        html += `
                            <div class="tgs-anggota-item ${isKetua ? 'is-ketua' : ''}">
                                <div style="display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1;">
                                    <div style="width: 28px; height: 28px; border-radius: 50%; background: ${isKetua ? '#FDE68A' : '#E2E8F0'}; color: ${isKetua ? '#92400E' : '#334155'}; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; flex-shrink: 0;">
                                        ${escapeHtml(a.nama_lengkap.charAt(0).toUpperCase())}
                                    </div>
                                    <div style="min-width: 0; flex: 1;">
                                        <div style="font-weight: ${isKetua ? '800' : '600'}; font-size: 12.5px; color: ${isKetua ? '#92400E' : '#1E293B'}; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            ${isKetua ? '👑 ' : ''}${escapeHtml(a.nama_lengkap)}
                                        </div>
                                        <div style="font-size: 10.5px; color: #64748B;">NISN: ${escapeHtml(a.nisn || '-')}</div>
                                    </div>
                                </div>

                                <div style="display: flex; align-items: center; gap: 4px; flex-shrink: 0;">
                                    ${!isKetua ? `
                                        <button type="button" class="btn-tgs btn-tgs--secondary" style="padding: 3px 6px; font-size: 11px; height: 26px; color: #B45309;" onclick="setKetuaKelompokTab(${kel.id}, ${a.id_siswa})" title="Jadikan Ketua Kelompok">
                                            <iconify-icon icon="solar:crown-star-bold"></iconify-icon>
                                        </button>
                                    ` : ''}

                                    <select class="tgs-select" style="font-size: 11px; padding: 2px 4px; height: 26px; width: 85px;" onchange="moveAnggotaTab(${a.id_siswa}, this.value)" title="Pindahkan ke kelompok lain">
                                        ${moveOptionsHtml}
                                    </select>

                                    <button type="button" class="btn-tgs btn-tgs--secondary" style="padding: 3px 6px; font-size: 11px; height: 26px; color: var(--lumina-rose);" onclick="removeAnggotaTab(${a.id_siswa}, ${kel.id})" title="Keluarkan dari Kelompok">
                                        <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                                    </button>
                                </div>
                            </div>
                        `;
                    });
                }

                html += `
                            </div>
                        </div>

                        <div style="padding-top: 10px; border-top: 1px solid #F1F5F9; display: flex; align-items: center; justify-content: space-between;">
                            <span style="font-size: 11.5px; color: #64748B;">Kapasitas: <strong>${jmlAnggota} Siswa</strong></span>
                            <span class="tgs-badge" style="background: rgba(16, 185, 129, 0.12); color: #059669; font-size: 11px;">Aktif</span>
                        </div>
                    </div>
                `;
            });

            html += `</div>`; // Tutup .tgs-kelompok-grid

            // Bagian Siswa Belum Masuk Kelompok (Unassigned)
            if (unassigned.length > 0) {
                let addToKelOptions = '<option value="">+ Masukkan ke Kelompok...</option>';
                kelompoks.forEach(k => {
                    addToKelOptions += `<option value="${k.id}">Kelompok ${k.nomor_kelompok} (${escapeHtml(k.nama_kelompok)})</option>`;
                });

                html += `
                    <div class="tgs-unassigned-box">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <iconify-icon icon="solar:user-cross-bold" style="font-size: 20px; color: #E11D48;"></iconify-icon>
                                <h4 style="margin: 0; font-family: 'Outfit', sans-serif; font-size: 16px; font-weight: 800; color: #9F1239;">
                                    Santri Belum Berkelompok (${unassigned.length} Siswa)
                                </h4>
                            </div>
                            <div style="font-size: 12.5px; color: #64748B;">
                                Siswa berikut belum terdaftar ke kelompok manapun pada tugas ini.
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px;">
                `;

                unassigned.forEach(sw => {
                    html += `
                        <div style="background: #FFF1F2; border: 1px solid #FECDD3; border-radius: 12px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                            <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
                                <div style="width: 30px; height: 30px; border-radius: 50%; background: #FFE4E6; color: #BE123C; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; flex-shrink: 0;">
                                    ${escapeHtml(sw.nama_lengkap.charAt(0).toUpperCase())}
                                </div>
                                <div style="min-width: 0;">
                                    <div style="font-weight: 700; font-size: 12.5px; color: #881337; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        ${escapeHtml(sw.nama_lengkap)}
                                    </div>
                                    <div style="font-size: 10.5px; color: #9F1239;">NISN: ${escapeHtml(sw.nisn || '-')}</div>
                                </div>
                            </div>

                            <select class="tgs-select" style="font-size: 11px; padding: 2px 6px; height: 28px; width: 140px; background: #FFFFFF; border-color: #FDA4AF; color: #881337; font-weight: 600;" onchange="addAnggotaToGroupTab(${sw.id}, this.value)">
                                ${addToKelOptions}
                            </select>
                        </div>
                    `;
                });

                html += `
                        </div>
                    </div>
                `;
            }

            container.innerHTML = html;

        } catch (e) {
            console.error('Gagal memuat detail tab kelompok:', e);
            container.innerHTML = `<div style="color:red; padding:30px; text-align:center;">Gagal memuat data kelompok: ${e.message}</div>`;
        }
    }

    window.setKetuaKelompokTab = async function(idKelompok, idKetua) {
        if (!idKetua) return;
        const sel = document.getElementById('selectKelompokTugas');
        const idTugas = parseInt(sel ? sel.value : 0) || 0;
        try {
            const fd = new FormData();
            fd.append('action', 'set_ketua_kelompok');
            fd.append('id_kelompok', idKelompok);
            fd.append('id_ketua', idKetua);
            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                luminaToast('Ketua Ditetapkan', json.message, 'success');
                loadKelompokTabData(idTugas);
            } else {
                luminaAlert('Gagal', json.message, 'error');
            }
        } catch (e) {
            console.error('Gagal set ketua:', e);
            luminaToast('Kesalahan Jaringan', 'Gagal menetapkan ketua kelompok.', 'error');
        }
    };

    window.moveAnggotaTab = async function(idSiswa, idKelompokTujuan) {
        if (!idKelompokTujuan) return;
        const sel = document.getElementById('selectKelompokTugas');
        const idTugas = parseInt(sel ? sel.value : 0) || 0;
        try {
            const fd = new FormData();
            fd.append('action', 'move_anggota_kelompok');
            fd.append('id_siswa', idSiswa);
            fd.append('id_kelompok_tujuan', idKelompokTujuan);
            fd.append('id_tugas', idTugas);
            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                luminaToast('Anggota Dipindahkan', json.message, 'success');
                loadKelompokTabData(idTugas);
            } else {
                luminaAlert('Gagal', json.message, 'error');
            }
        } catch (e) {
            console.error('Gagal memindahkan anggota:', e);
            luminaToast('Kesalahan Jaringan', 'Gagal memindahkan anggota kelompok.', 'error');
        }
    };

    window.removeAnggotaTab = async function(idSiswa, idKelompok) {
        const ok = await luminaConfirm(
            'Keluarkan Anggota?',
            'Keluarkan siswa ini dari kelompok? Siswa akan dipindahkan ke daftar santri belum berkelompok.',
            'Ya, Keluarkan',
            'Batal',
            'warning'
        );
        if (!ok) return;

        const sel = document.getElementById('selectKelompokTugas');
        const idTugas = parseInt(sel ? sel.value : 0) || 0;
        try {
            const fd = new FormData();
            fd.append('action', 'remove_anggota_kelompok');
            fd.append('id_siswa', idSiswa);
            fd.append('id_kelompok', idKelompok);
            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                luminaToast('Berhasil', json.message, 'success');
                loadKelompokTabData(idTugas);
            } else {
                luminaAlert('Gagal', json.message, 'error');
            }
        } catch (e) {
            console.error('Gagal mengeluarkan anggota:', e);
            luminaToast('Kesalahan Jaringan', 'Gagal mengeluarkan anggota.', 'error');
        }
    };

    window.addAnggotaToGroupTab = async function(idSiswa, idKelompok) {
        if (!idKelompok) return;
        const sel = document.getElementById('selectKelompokTugas');
        const idTugas = parseInt(sel ? sel.value : 0) || 0;
        try {
            const fd = new FormData();
            fd.append('action', 'add_anggota_kelompok');
            fd.append('id_siswa', idSiswa);
            fd.append('id_kelompok', idKelompok);
            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                luminaToast('Berhasil', json.message, 'success');
                loadKelompokTabData(idTugas);
            } else {
                luminaAlert('Gagal', json.message, 'error');
            }
        } catch (e) {
            console.error('Gagal menambahkan anggota:', e);
            luminaToast('Kesalahan Jaringan', 'Gagal menambahkan anggota.', 'error');
        }
    };

    window.renameKelompokTab = async function(idKelompok, currentName) {
        const newName = await luminaPrompt('Ubah Nama Kelompok', 'Masukkan nama kelompok baru di bawah ini:', currentName, 'Nama Kelompok...', 'Perbarui Nama', 'Batal');
        if (!newName || newName.trim() === '' || newName === currentName) return;
        const sel = document.getElementById('selectKelompokTugas');
        const idTugas = parseInt(sel ? sel.value : 0) || 0;
        try {
            const fd = new FormData();
            fd.append('action', 'rename_kelompok');
            fd.append('id_kelompok', idKelompok);
            fd.append('nama_kelompok', newName.trim());
            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                luminaToast('Nama Diperbarui', json.message, 'success');
                loadKelompokTabData(idTugas);
            } else {
                luminaAlert('Gagal', json.message, 'error');
            }
        } catch (e) {
            console.error('Gagal mengubah nama kelompok:', e);
            luminaToast('Kesalahan Jaringan', 'Gagal memperbarui nama kelompok.', 'error');
        }
    };

    window.deleteKelompokInTab = async function(idKelompok) {
        const ok = await luminaConfirm(
            'Hapus Kelompok?',
            'Apakah Anda yakin ingin menghapus kelompok ini? Seluruh anggota kelompok akan dipindahkan ke daftar santri belum berkelompok.',
            'Ya, Hapus Kelompok',
            'Batal',
            'error'
        );
        if (!ok) return;

        const sel = document.getElementById('selectKelompokTugas');
        const idTugas = parseInt(sel ? sel.value : 0) || 0;
        try {
            const fd = new FormData();
            fd.append('action', 'delete_kelompok');
            fd.append('id_kelompok', idKelompok);
            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                luminaToast('Kelompok Dihapus', json.message, 'success');
                loadKelompokTabData(idTugas);
                loadStats();
            } else {
                luminaAlert('Gagal', json.message, 'error');
            }
        } catch (e) {
            console.error('Gagal menghapus kelompok:', e);
            luminaToast('Kesalahan Jaringan', 'Gagal menghapus kelompok.', 'error');
        }
    };

    window.resetSemuaKelompokTab = async function() {
        const sel = document.getElementById('selectKelompokTugas');
        const idTugas = parseInt(sel ? sel.value : 0) || 0;
        if (idTugas <= 0) return;

        const ok = await luminaConfirm(
            'Reset Seluruh Kelompok?',
            'Apakah Anda yakin ingin me-reset (menghapus) seluruh kelompok untuk tugas ini? Seluruh siswa akan dikembalikan ke status belum berkelompok.',
            'Ya, Reset Semua',
            'Batal',
            'error'
        );
        if (!ok) return;

        try {
            const fd = new FormData();
            fd.append('action', 'reset_semua_kelompok');
            fd.append('id_tugas', idTugas);
            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                luminaToast('Kelompok Direset', json.message, 'success');
                loadKelompokTabData(idTugas);
                loadStats();
            } else {
                luminaAlert('Gagal', json.message, 'error');
            }
        } catch (e) {
            console.error('Gagal me-reset kelompok:', e);
            luminaToast('Kesalahan Jaringan', 'Gagal me-reset kelompok.', 'error');
        }
    };

    window.openAddSingleKelompokInTab = async function() {
        const sel = document.getElementById('selectKelompokTugas');
        const idTugas = parseInt(sel ? sel.value : 0) || 0;
        if (idTugas <= 0) {
            luminaToast('Peringatan', 'Pilih tugas kelompok terlebih dahulu.', 'warn');
            return;
        }

        const nama = await luminaPrompt(
            'Tambah Kelompok Manual',
            'Masukkan nama kelompok baru yang ingin dibuat:',
            '',
            'Contoh: Kelompok 5 - Bilal bin Rabah',
            'Tambah Kelompok',
            'Batal'
        );
        if (!nama || !nama.trim()) return;

        const nextNomor = (window.currentKelompokTabData && window.currentKelompokTabData.kelompoks)
            ? window.currentKelompokTabData.kelompoks.length + 1
            : 1;

        try {
            const fd = new FormData();
            fd.append('action', 'save_kelompok');
            fd.append('id_tugas', idTugas);
            fd.append('nama_kelompok', nama.trim());
            fd.append('nomor_kelompok', nextNomor);
            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                luminaToast('Kelompok Dibuat', json.message, 'success');
                loadKelompokTabData(idTugas);
                loadStats();
            } else {
                luminaAlert('Gagal', json.message, 'error');
            }
        } catch (e) {
            console.error('Gagal menambah kelompok:', e);
            luminaToast('Kesalahan Jaringan', 'Gagal menambah kelompok.', 'error');
        }
    };

    // --------------------------------------------------------------------------
    // TAB 2: PEMERIKSAAN & PENILAIAN SISWA
    // --------------------------------------------------------------------------
    window.jumpToPenilaian = function(idTugas) {
        switchMainTab('penilaian');
        setTimeout(() => {
            const sel = document.getElementById('selectPenilaianTugas');
            sel.value = idTugas;
            loadSubmissions();
        }, 150);
    };

    async function populatePenilaianTugasDropdown() {
        const sel = document.getElementById('selectPenilaianTugas');
        sel.innerHTML = '<option value="">-- Memuat Tugas... --</option>';

        try {
            const fd = new FormData();
            fd.append('action', 'list_tugas');
            if (currentKmpId > 0) fd.append('id_kelas_mata_pelajaran', currentKmpId);
            fd.append('per', 100);

            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();

            sel.innerHTML = '<option value="">-- Pilih Tugas Terlebih Dahulu --</option>';
            if (json.success && json.data) {
                json.data.forEach(t => {
                    const opt = document.createElement('option');
                    opt.value = t.id;
                    opt.textContent = `[${t.jenis_penugasan}] ${t.judul_tugas} (${t.nama_kelas || ''})`;
                    sel.appendChild(opt);
                });
            }
        } catch (e) {
            console.error('Gagal dropdown penilaian:', e);
        }
    }

    let submissionPaginator = null;

    window.loadSubmissions = async function(isQuiet = false) {
        const idTugas = parseInt(document.getElementById('selectPenilaianTugas').value) || 0;
        const tbody = document.getElementById('submissionTableBody');
        const summary = document.getElementById('submissionSummaryText');
        const pagBar = document.getElementById('submissionPaginationBar');

        if (idTugas <= 0) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:50px 20px; color:#94A3B8;">Pilih tugas di atas untuk menampilkan pengumpulan siswa.</td></tr>`;
            summary.textContent = 'Silakan pilih tugas untuk mulai menilai.';
            if (pagBar) pagBar.style.display = 'none';
            updateSubmissionBulkBar();
            return;
        }

        if (!isQuiet) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:40px;"><div class="tgs-spinner"></div><div style="margin-top:10px; font-weight:600; color:#64748B;">Memuat berkas pengumpulan...</div></td></tr>`;
            if (pagBar) pagBar.style.display = 'none';
        } else {
            tbody.style.opacity = '0.5';
            tbody.style.transition = 'opacity 0.2s ease';
        }

        try {
            const fd = new FormData();
            fd.append('action', 'list_pengumpulan');
            fd.append('id_tugas', idTugas);
            fd.append('filter_status', document.getElementById('filterSubStatus').value);
            fd.append('search', document.getElementById('filterSearch').value);

            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();

            if (!json.success || !json.data || json.data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:50px 20px; color:#94A3B8;">Belum ada siswa yang mengumpulkan tugas ini.</td></tr>`;
                summary.textContent = 'Total 0 berkas pengumpulan masuk.';
                if (pagBar) pagBar.style.display = 'none';
                updateSubmissionBulkBar();
                return;
            }

            currentSubmissions = json.data;
            summary.innerHTML = `Total <strong>${json.data.length}</strong> pengumpulan tugas siswa.`;
            if (pagBar) pagBar.style.display = 'flex';

            // Paginator Universal untuk Tabel Penilaian Siswa
            submissionPaginator = new IlmunaDataPaginator({
                items: currentSubmissions,
                pageSize: 12,
                containerId: 'submissionTableBody',
                paginationId: 'submissionPaginationBar',
                viewMode: 'table',
                onPageChange: () => {
                    updateSubmissionBulkBar();
                },
                renderRow: (items, tbodyEl, theadEl, paginator) => {
                    tbodyEl.innerHTML = '';
                    const p = paginator || submissionPaginator || { currentPage: 1, pageSize: 12 };
                    const startNo = (p.currentPage - 1) * p.pageSize;

                    items.forEach((s, idx) => {
                        const tr = document.createElement('tr');

                        // Info pengumpul
                        let pengumpulHtml = `<strong>${escapeHtml(s.nama_siswa || 'Siswa')}</strong>`;
                        if (s.nama_kelompok) {
                            let anggotaList = '';
                            if (s.anggota_kelompok && s.anggota_kelompok.length > 0) {
                                anggotaList = `<div style="font-size:11px; color:#64748B; margin-top:2px;">Anggota: ${s.anggota_kelompok.map(a => escapeHtml(a.nama_lengkap)).join(', ')}</div>`;
                            }
                            pengumpulHtml = `
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <span class="tgs-badge tgs-badge--kelompok">${escapeHtml(s.nama_kelompok)}</span>
                                </div>
                                <div style="font-size:11.5px; color:#475569; margin-top:2px;">Oleh: ${escapeHtml(s.nama_siswa || '')}</div>
                                ${anggotaList}
                            `;
                        } else {
                            pengumpulHtml += `<div style="font-size:11px; color:#64748B;">NISN: ${escapeHtml(s.nisn || '-')}</div>`;
                        }

                        // Waktu & Keterlambatan
                        const isLate = (parseInt(s.is_terlambat) === 1 || s.status_pengumpulan === 'Terlambat');
                        const waktuHtml = `
                            <div style="font-size:12px; font-weight:600; color:#334155;">${formatDateTime(s.waktu_pengumpulan)}</div>
                            ${isLate ? '<span class="tgs-badge" style="background:rgba(244,63,94,0.12); color:#E11D48; margin-top:3px; font-size:10.5px;">Terlambat</span>' : '<span class="tgs-badge" style="background:rgba(16,185,129,0.12); color:#059669; margin-top:3px; font-size:10.5px;">Tepat Waktu</span>'}
                        `;

                        // Berkas
                        const fileHtml = `
                            <div style="display:flex; align-items:center; gap:6px;">
                                <iconify-icon icon="solar:document-bold" style="color:var(--lumina-teal); font-size:16px;"></iconify-icon>
                                <span style="font-weight:600; font-size:12.5px;">${escapeHtml(s.file_nama_asli || 'Berkas Siswa')}</span>
                            </div>
                            ${s.gdrive_file_id ? '<span class="tgs-badge tgs-badge--gdrive" style="margin-top:3px; font-size:10.5px;"><iconify-icon icon="solar:cloud-check-bold"></iconify-icon> Google Drive</span>' : ''}
                        `;

                        // Nilai & Predikat
                        let nilaiHtml = `<span style="color:#94A3B8; font-style:italic; font-size:12px;">Belum Dinilai</span>`;
                        if (s.nama_kelompok && s.anggota_kelompok && s.anggota_kelompok.length > 0) {
                            if (s.nilai_kelompok !== null && s.nilai_kelompok !== undefined && s.nilai_kelompok !== '') {
                                const nk = parseFloat(s.nilai_kelompok).toFixed(1);
                                const anggotaList = s.anggota_kelompok.map(a => {
                                    const an = (a.nilai !== null && a.nilai !== undefined) ? parseFloat(a.nilai).toFixed(1) : '-';
                                    const ap = a.predikat || (an !== '-' ? hitungPredikatNilai(an) : '-');
                                    return `<div style="display:flex; justify-content:space-between; gap:8px; font-size:10.5px; padding:2px 0; border-bottom:1px dashed #F1F5F9;">
                                        <span style="color:#334155; max-width:110px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${escapeHtml(a.nama_lengkap)}</span>
                                        <span style="font-weight:700; color:var(--lumina-navy);">${an} <span class="tgs-badge" style="padding:1px 4px; font-size:9px;">${ap}</span></span>
                                    </div>`;
                                }).join('');

                                nilaiHtml = `
                                    <div style="text-align:left; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:8px; padding:5px 8px; min-width:160px;">
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:3px; font-size:11px;">
                                            <span style="color:#166534; font-weight:800;">Kelompok: ${nk}</span>
                                            <span style="color:#0D7A87; font-weight:700; font-size:9.5px;">Bobot 50%+50%</span>
                                        </div>
                                        <div>
                                            ${anggotaList}
                                        </div>
                                    </div>
                                `;
                            } else if (s.nilai !== null && s.nilai !== undefined && s.nilai !== '') {
                                const sc = parseFloat(s.nilai).toFixed(1);
                                const pred = s.predikat || hitungPredikatNilai(sc);
                                nilaiHtml = `
                                    <div style="display:inline-flex; align-items:center; gap:6px;">
                                        <strong style="font-size:15px; font-family:'Outfit',sans-serif; color:var(--lumina-navy);">${sc}</strong>
                                        <span class="tgs-badge tgs-badge--kelompok" style="font-weight:800; font-size:10.5px;">${pred}</span>
                                    </div>
                                `;
                            }
                        } else {
                            if (s.nilai !== null && s.nilai !== undefined && s.nilai !== '') {
                                const sc = parseFloat(s.nilai).toFixed(1);
                                const pred = s.predikat || hitungPredikatNilai(sc);
                                nilaiHtml = `
                                    <div style="display:inline-flex; align-items:center; gap:6px;">
                                        <strong style="font-size:15px; font-family:'Outfit',sans-serif; color:var(--lumina-navy);">${sc}</strong>
                                        <span class="tgs-badge tgs-badge--individ" style="font-weight:800; font-size:10.5px;">${pred}</span>
                                    </div>
                                `;
                            }
                        }

                        // Status
                        let statusBadge = `<span class="tgs-badge tgs-badge--draft" style="font-size:10.5px;">Menunggu</span>`;
                        if (s.status_penilaian === 'Dinilai' || s.status_penilaian === 'Tuntas') {
                            statusBadge = `<span class="tgs-badge tgs-badge--open" style="font-size:10.5px;">Tuntas</span>`;
                        } else if (s.status_penilaian === 'Perlu Revisi') {
                            statusBadge = `<span class="tgs-badge tgs-badge--closed" style="font-size:10.5px;">Perlu Revisi</span>`;
                        }

                        tr.innerHTML = `
                            <td style="text-align:center;">
                                <input type="checkbox" class="tgs-chk-sub" value="${s.id}" data-is-group="${s.nama_kelompok ? '1' : '0'}" onchange="updateSubmissionBulkBar()" style="width:17px; height:17px; accent-color:var(--lumina-teal); cursor:pointer;" title="Pilih pengumpulan">
                            </td>
                            <td style="text-align:center; font-weight:700; color:#94A3B8;">${startNo + idx + 1}</td>
                            <td>${pengumpulHtml}</td>
                            <td>${waktuHtml}</td>
                            <td>${fileHtml}</td>
                            <td style="text-align:center;">${nilaiHtml}</td>
                            <td style="text-align:center;">${statusBadge}</td>
                            <td style="text-align:right; white-space:nowrap; width:150px;">
                                <button type="button" class="ilm-table-btn ilm-table-btn--primary" onclick="openInspectorModal(${s.id})" style="white-space:nowrap;">
                                    <iconify-icon icon="solar:eye-bold"></iconify-icon>
                                    <span>Periksa</span>
                                </button>
                            </td>
                        `;
                        tbodyEl.appendChild(tr);
                    });
                }
            });

            updateSubmissionBulkBar();

        } catch (e) {
            console.error('Gagal memuat pengumpulan:', e);
            tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; color:red; padding:30px;">Gagal memuat pengumpulan: ${e.message}</td></tr>`;
            if (pagBar) pagBar.style.display = 'none';
            updateSubmissionBulkBar();
        } finally {
            tbody.style.opacity = '1';
        }
    };

    // --------------------------------------------------------------------------
    // MODAL INSPEKTOR & VIEWER TANPA DOWNLOAD (CORE REQUIREMENT)
    // --------------------------------------------------------------------------
    window.openInspectorModal = async function(idSub) {
        openModal('modalInspector');
        document.getElementById('gradeSubmissionId').value = idSub;
        document.getElementById('dynamicStageRender').innerHTML = `<div class="tgs-loading-state"><div class="tgs-spinner"></div><div>Menyiapkan pratinjau aman...</div></div>`;

        try {
            const fd = new FormData();
            fd.append('action', 'get_submission_detail');
            fd.append('id_pengumpulan', idSub);
            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();

            if (!json.success || !json.data) {
                luminaToast('Peringatan', 'Gagal mengambil data pengumpulan.', 'warn');
                closeModal('modalInspector');
                return;
            }

            const s = json.data;

            // Render Header & Info Pengumpul
            document.getElementById('inspectorHeaderTitle').textContent = s.judul_tugas;
            document.getElementById('inspectorHeaderSub').textContent = `${s.nama_kelas} • ${s.nama_mata_pelajaran}`;

            document.getElementById('inspectorStudentName').textContent = s.nama_kelompok ? `${s.nama_kelompok} (${s.nama_siswa})` : s.nama_siswa;
            document.getElementById('inspectorStudentMeta').textContent = s.nama_kelompok ? `Tugas Kelompok Kolaboratif` : `NISN: ${s.nisn || '-'} • NIS: ${s.nis || '-'}`;
            document.getElementById('inspectorStudentNotes').textContent = s.catatan_siswa ? `"${s.catatan_siswa}"` : '- Tidak ada catatan pengantar dari siswa -';

            // Kelompok members box
            const memBox = document.getElementById('inspectorGroupMembersBox');
            const memList = document.getElementById('inspectorGroupMembersList');
            if (s.anggota_kelompok && s.anggota_kelompok.length > 0) {
                memBox.style.display = 'block';
                memList.innerHTML = s.anggota_kelompok.map(m => `<div>• <strong>${escapeHtml(m.nama_lengkap)}</strong> (NISN: ${m.nisn || '-'})</div>`).join('');
            } else {
                memBox.style.display = 'none';
            }

            // Cek apakah jenis penugasan kelompok
            const isGroup = (s.jenis_penugasan === 'Kelompok' || (s.anggota_kelompok && s.anggota_kelompok.length > 0) || !!s.id_tugas_kelompok);
            document.getElementById('gradeIsGroup').value = isGroup ? '1' : '0';

            const boxInd = document.getElementById('boxGradeIndividual');
            const boxGrp = document.getElementById('boxGradeGroup');
            const inputScore = document.getElementById('inputScore');
            const inputGroupScore = document.getElementById('inputGroupScore');

            document.getElementById('labelMaxScore').textContent = s.nilai_maksimal || '100';

            if (isGroup) {
                boxInd.style.display = 'none';
                boxGrp.style.display = 'flex';
                inputScore.removeAttribute('required');
                inputGroupScore.setAttribute('required', 'required');

                const members = s.anggota_kelompok || [];
                document.getElementById('badgeGroupMemberCount').textContent = `${members.length} Santri`;
                document.getElementById('labelGroupMemberCount').textContent = members.length || 1;

                const grpVal = (s.nilai_kelompok !== null && s.nilai_kelompok !== undefined) ? s.nilai_kelompok : ((s.nilai !== null && s.nilai !== undefined) ? s.nilai : 100);
                inputGroupScore.value = grpVal;

                const grpList = document.getElementById('inspectorGroupMembersGradeList');
                grpList.innerHTML = '';

                const count = members.length || 1;
                const grpValNum = parseFloat(grpVal) || 0;
                document.getElementById('labelGroupMemberCount').textContent = count;

                members.forEach(m => {
                    const indVal = (m.nilai_individu !== null && m.nilai_individu !== undefined) ? parseFloat(m.nilai_individu) : grpValNum;
                    let finalVal = (m.nilai !== null && m.nilai !== undefined) ? parseFloat(m.nilai) : parseFloat(((grpValNum + indVal) / 2).toFixed(2));
                    const pred = m.predikat || hitungPredikatNilai(finalVal);

                    const row = document.createElement('div');
                    row.className = 'tgs-member-grade-row';
                    row.setAttribute('data-siswa-id', m.id);
                    row.style.cssText = 'background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; padding: 10px; display: flex; flex-direction: column; gap: 6px;';
                    row.innerHTML = `
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <div style="display: flex; align-items: center; gap: 8px; overflow: hidden;">
                                <div style="width: 28px; height: 28px; border-radius: 6px; background: #0D7A87; color: #fff; font-size: 12px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    ${(m.nama_lengkap || 'S').charAt(0).toUpperCase()}
                                </div>
                                <div style="overflow: hidden;">
                                    <div style="font-size: 12.5px; font-weight: 700; color: #1E293B; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        ${escapeHtml(m.nama_lengkap)}
                                    </div>
                                    <div style="font-size: 10.5px; color: #64748B;">NISN: ${escapeHtml(m.nisn || '-')}</div>
                                </div>
                            </div>
                            <div class="tgs-member-pred-badge" id="memberPredBadge_${m.id}" style="font-weight: 800; font-size: 12px; padding: 2px 8px; border-radius: 6px; background: #E2E8F0; color: #475569;">
                                ${pred}
                            </div>
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 8px; margin-top: 2px;">
                            <div style="flex: 1;">
                                <div style="font-size: 10.5px; color: #64748B; margin-bottom: 2px;">Nilai Individu:</div>
                                <input type="number" class="tgs-form-control input-member-score" data-siswa-id="${m.id}" min="0" max="${s.nilai_maksimal || 100}" step="0.5" value="${indVal}" placeholder="0-100" oninput="onGroupScoreInputChange()" style="padding: 6px 10px; font-size: 13px; font-weight: 700;">
                            </div>
                            <div style="flex: 1.2; background: #FFFFFF; border: 1px dashed #CBD5E1; border-radius: 8px; padding: 6px 8px; text-align: center;">
                                <div style="font-size: 10px; color: #64748B;">Nilai Akhir Santri:</div>
                                <div style="font-size: 14px; font-weight: 800; color: #0D7A87;" id="memberFinalScoreLabel_${m.id}">
                                    ${finalVal.toFixed(2)}
                                </div>
                            </div>
                        </div>
                        
                        <div style="font-size: 10.5px; color: #64748B; text-align: right;" id="memberFormulaCalc_${m.id}">
                            (${grpValNum} + ${indVal}) / 2 = <strong>${finalVal.toFixed(2)}</strong>
                        </div>
                    `;
                    grpList.appendChild(row);
                });

                onGroupScoreInputChange();
                memBox.style.display = 'none';

            } else {
                boxInd.style.display = 'flex';
                boxGrp.style.display = 'none';
                inputScore.setAttribute('required', 'required');
                inputGroupScore.removeAttribute('required');

                inputScore.max = s.nilai_maksimal || '100';
                inputScore.value = (s.nilai !== null && s.nilai !== undefined) ? s.nilai : '';
                document.getElementById('inputRemedial').value = s.nilai_remedial || '';

                onScoreInputChange();
                memBox.style.display = 'none';
            }

            document.getElementById('inputCatatanGuru').value = s.catatan_guru || '';
            document.getElementById('selectAspekItp').value = s.aspek_itp_ketercapaian || 'Tercapai';
            document.getElementById('selectStatusPenilaian').value = s.status_penilaian || 'Dinilai';

            onScoreInputChange();

            // Render Document Viewer (STRICT VIEW-ONLY - ZERO DOWNLOAD)
            renderSecureViewer(s);

        } catch (e) {
            console.error('Gagal membuka inspektor:', e);
            luminaAlert('Kesalahan', 'Gagal membuka pratinjau: ' + e.message, 'error');
            closeModal('modalInspector');
        }
    };

    // RENDER SECURE VIEWER
    function renderSecureViewer(s) {
        const stage = document.getElementById('dynamicStageRender');
        const fileNameLabel = document.getElementById('viewerFileNameLabel');
        const toolbarControls = document.getElementById('viewerToolbarControls');

        const fileName = s.file_nama_asli || (s.file_tugas ? s.file_tugas.split('/').pop() : 'Berkas Tugas');
        fileNameLabel.textContent = fileName;

        const ext = fileName.split('.').pop().toLowerCase();
        const streamUrl = `<?= BASE_URL ?>/dashboard/guru/tugas?stream=submission&id=${s.id}`;

        // 1. DOKUMEN PDF -> RENDER DENGAN PDF.JS CANVAS (BEBAS TOMBOL UNDUH)
        if (ext === 'pdf') {
            toolbarControls.style.display = 'flex';
            stage.innerHTML = `
                <div class="tgs-pdf-stage">
                    <canvas id="pdfCanvasViewer" class="tgs-pdf-canvas"></canvas>
                </div>
            `;
            loadPdfDocument(streamUrl, s.gdrive_file_id);
            return;
        }

        // 2. GAMBAR (JPG, PNG, WEBP, GIF)
        if (['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(ext)) {
            toolbarControls.style.display = 'none';
            stage.innerHTML = `
                <div style="padding:20px; display:flex; align-items:center; justify-content:center; width:100%; height:100%;">
                    <img src="${streamUrl}" alt="Berkas Tugas Siswa" style="max-width:100%; max-height:85vh; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.5); user-select:none; pointer-events:none;" oncontextmenu="return false;">
                </div>
            `;
            return;
        }

        // 3. VIDEO (MP4, WEBM)
        if (['mp4', 'webm', 'mov'].includes(ext)) {
            toolbarControls.style.display = 'none';
            stage.innerHTML = `
                <div style="width:100%; max-width:800px; padding:20px;">
                    <video controls controlsList="nodownload nofullscreen" style="width:100%; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,0.5);">
                        <source src="${streamUrl}" type="video/mp4">
                        Browser Anda tidak mendukung pemutar video.
                    </video>
                </div>
            `;
            return;
        }

        // 4. GOOGLE DRIVE PREVIEW (OFFICE DOCX, PPTX, XLSX, ATAU DOKUMEN LAIN DI DRIVE)
        if (s.gdrive_file_id) {
            toolbarControls.style.display = 'none';
            stage.innerHTML = `
                <div class="tgs-iframe-container">
                    <div class="tgs-drive-shield-top" title="Tinjauan Aman Dilindungi"></div>
                    <iframe src="https://drive.google.com/file/d/${s.gdrive_file_id}/preview?rm=minimal" class="tgs-drive-iframe" sandbox="allow-scripts allow-same-origin"></iframe>
                </div>
            `;
            return;
        }

        // 5. TAUTAN EKSTERNAL / GOOGLE DOCS / CANVA
        if (s.link_tugas) {
            toolbarControls.style.display = 'none';
            stage.innerHTML = `
                <div style="padding:40px; text-align:center; color:#E2E8F0; max-width:600px;">
                    <iconify-icon icon="solar:link-circle-bold" style="font-size:48px; color:var(--lumina-teal); margin-bottom:12px;"></iconify-icon>
                    <h4 style="font-size:18px; color:#FFFFFF; margin:0 0 10px;">Tautan Eksternal Dikumpulkan Siswa</h4>
                    <p style="font-size:13px; color:#94A3B8; margin-bottom:20px;">Siswa mengumpulkan tugas berupa link dokumen eksternal:</p>
                    <div style="background:#1E293B; padding:12px 16px; border-radius:8px; font-family:monospace; font-size:13px; word-break:break-all; color:#38BDF8; margin-bottom:20px;">
                        ${escapeHtml(s.link_tugas)}
                    </div>
                    <a href="${escapeHtml(s.link_tugas)}" target="_blank" rel="noopener noreferrer" class="btn-tgs btn-tgs--primary" style="display:inline-flex;">
                        <iconify-icon icon="solar:square-top-down-bold"></iconify-icon>
                        <span>Buka Tautan di Tab Baru</span>
                    </a>
                </div>
            `;
            return;
        }

        // Fallback Umum
        toolbarControls.style.display = 'none';
        stage.innerHTML = `
            <div style="padding:40px; text-align:center; color:#94A3B8;">
                <iconify-icon icon="solar:document-text-bold" style="font-size:44px; margin-bottom:10px;"></iconify-icon>
                <div style="font-size:15px; color:#E2E8F0; font-weight:700;">Format Berkas: .${ext.toUpperCase()}</div>
                <p style="font-size:12.5px; margin-top:6px;">Pratinjau dokumen sedang disiapkan.</p>
            </div>
        `;
    }

    // PDF.JS RENDERER LOGIC
    async function loadPdfDocument(streamUrl, gdriveId) {
        if (!window.pdfjsLib) {
            renderPdfFallback(gdriveId);
            return;
        }

        try {
            const loadingTask = window.pdfjsLib.getDocument({
                url: streamUrl,
                withCredentials: false
            });
            currentPdfDoc = await loadingTask.promise;
            currentPdfPage = 1;
            renderPdfPage(currentPdfPage);
        } catch (err) {
            console.warn('PDF.js binary stream gagal, beralih ke Drive preview fallback:', err);
            renderPdfFallback(gdriveId);
        }
    }

    function renderPdfFallback(gdriveId) {
        const stage = document.getElementById('dynamicStageRender');
        document.getElementById('viewerToolbarControls').style.display = 'none';
        if (gdriveId) {
            stage.innerHTML = `
                <div class="tgs-iframe-container">
                    <div class="tgs-drive-shield-top"></div>
                    <iframe src="https://drive.google.com/file/d/${gdriveId}/preview?rm=minimal" class="tgs-drive-iframe" sandbox="allow-scripts allow-same-origin"></iframe>
                </div>
            `;
        } else {
            stage.innerHTML = `<div style="color:#FDA4AF; padding:30px;">Berkas tidak dapat dialirkan ke viewer langsung.</div>`;
        }
    }

    async function renderPdfPage(num) {
        if (!currentPdfDoc) return;
        try {
            const page = await currentPdfDoc.getPage(num);
            const canvas = document.getElementById('pdfCanvasViewer');
            if (!canvas) return;

            const viewport = page.getViewport({ scale: currentPdfScale });
            const ctx = canvas.getContext('2d');
            canvas.height = viewport.height;
            canvas.width = viewport.width;

            await page.render({ canvasContext: ctx, viewport: viewport }).promise;

            document.getElementById('pdfPageNumLabel').textContent = `${num} / ${currentPdfDoc.numPages}`;
            document.getElementById('btnPdfPrev').disabled = (num <= 1);
            document.getElementById('btnPdfNext').disabled = (num >= currentPdfDoc.numPages);
        } catch (e) {
            console.error('Gagal render halaman PDF:', e);
        }
    }

    window.changePdfPage = function(delta) {
        if (!currentPdfDoc) return;
        const next = currentPdfPage + delta;
        if (next >= 1 && next <= currentPdfDoc.numPages) {
            currentPdfPage = next;
            renderPdfPage(currentPdfPage);
        }
    };

    window.zoomPdf = function(delta) {
        currentPdfScale = Math.max(0.6, Math.min(2.5, currentPdfScale + delta));
        renderPdfPage(currentPdfPage);
    };

    // FORM SCORE REAL-TIME CALCULATION (MANDIRI / INDIVIDU)
    window.onScoreInputChange = function() {
        const sc = parseFloat(document.getElementById('inputScore').value);
        const rem = parseFloat(document.getElementById('inputRemedial').value);
        const badge = document.getElementById('badgeGradePredikat');
        const desc = document.getElementById('labelPredikatDesc');

        if (isNaN(sc)) {
            badge.textContent = '-';
            badge.className = 'tgs-grade-badge-live';
            desc.textContent = 'Belum Dinilai';
            return;
        }

        const effective = (!isNaN(rem) && rem > sc) ? rem : sc;
        const pred = hitungPredikatNilai(effective);
        badge.textContent = pred;
        badge.className = `tgs-grade-badge-live grade-${pred}`;

        const descs = {
            'A': 'Sangat Baik (Istimewa)',
            'B': 'Baik (Memenuhi KKTP)',
            'C': 'Cukup (Perlu Pemantapan)',
            'D': 'Kurang (Perlu Bimbingan)',
            'E': 'Sangat Kurang'
        };
        desc.textContent = descs[pred] || 'Predikat ' + pred;
    };

    // FORM SCORE REAL-TIME CALCULATION (KELOMPOK & INDIVIDU)
    // Rumus Adil & Seimbang: Nilai Akhir = (Nilai Kelompok + Nilai Individu) / 2
    // Jika Kelompok 100 dan Individu 100 -> Nilai Akhir = 100
    window.onGroupScoreInputChange = function() {
        const groupScoreInput = document.getElementById('inputGroupScore');
        let groupScore = parseFloat(groupScoreInput.value);
        if (isNaN(groupScore)) groupScore = 0;
        const maxScore = parseFloat(document.getElementById('labelMaxScore').textContent) || 100;
        if (groupScore > maxScore) {
            groupScore = maxScore;
            groupScoreInput.value = maxScore;
        }

        const memberRows = document.querySelectorAll('.tgs-member-grade-row');
        const memberCount = memberRows.length || 1;

        const lblCount = document.getElementById('labelGroupMemberCount');
        if (lblCount) lblCount.textContent = memberCount;

        memberRows.forEach(row => {
            const sid = row.getAttribute('data-siswa-id');
            const inputInd = row.querySelector('.input-member-score');
            let indVal = parseFloat(inputInd.value);
            if (isNaN(indVal)) indVal = 0;
            if (indVal > maxScore) {
                indVal = maxScore;
                inputInd.value = maxScore;
            }

            // RUMUS ADIL & SEIMBANG: (Nilai Kelompok + Nilai Individu) / 2
            let finalScore = parseFloat(((groupScore + indVal) / 2).toFixed(2));
            if (finalScore > maxScore) finalScore = maxScore;

            const pred = hitungPredikatNilai(finalScore);

            const labelFinal = document.getElementById(`memberFinalScoreLabel_${sid}`);
            if (labelFinal) labelFinal.textContent = finalScore.toFixed(2);

            const predBadge = document.getElementById(`memberPredBadge_${sid}`);
            if (predBadge) {
                predBadge.textContent = pred;
                if (pred === 'A') {
                    predBadge.style.background = 'rgba(16, 185, 129, 0.15)';
                    predBadge.style.color = '#059669';
                } else if (pred === 'B') {
                    predBadge.style.background = 'rgba(82, 178, 220, 0.15)';
                    predBadge.style.color = '#0284C7';
                } else if (pred === 'C') {
                    predBadge.style.background = 'rgba(245, 158, 11, 0.15)';
                    predBadge.style.color = '#D97706';
                } else {
                    predBadge.style.background = 'rgba(239, 68, 68, 0.15)';
                    predBadge.style.color = '#DC2626';
                }
            }

            const formulaLabel = document.getElementById(`memberFormulaCalc_${sid}`);
            if (formulaLabel) {
                formulaLabel.innerHTML = `(${groupScore} + ${indVal}) / 2 = <strong>${finalScore.toFixed(2)}</strong>`;
            }
        });
    };

    window.setSameIndividualScore = async function() {
        const valStr = await luminaPrompt('Samakan Nilai Individu', 'Masukkan nilai individu yang akan diterapkan ke seluruh anggota kelompok (0 - 100):', '85');
        if (valStr === null) return;
        const val = parseFloat(valStr);
        if (isNaN(val) || val < 0 || val > 100) {
            luminaToast('Peringatan', 'Masukkan angka nilai valid antara 0 hingga 100.', 'warn');
            return;
        }
        document.querySelectorAll('.input-member-score').forEach(inp => {
            inp.value = val;
        });
        onGroupScoreInputChange();
    };

    function hitungPredikatNilai(val) {
        val = parseFloat(val) || 0;
        if (val >= 90) return 'A';
        if (val >= 80) return 'B';
        if (val >= 70) return 'C';
        if (val >= 60) return 'D';
        return 'E';
    }

    window.addFeedbackPreset = function(text) {
        const ta = document.getElementById('inputCatatanGuru');
        if (ta.value.trim().length > 0) {
            ta.value += '\n' + text;
        } else {
            ta.value = text;
        }
    };

    // SUBMIT PENILAIAN
    window.submitPenilaianDirect = async function(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSaveGrade');
        const origText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<span class="tgs-spinner" style="width:16px;height:16px;margin:0;border-width:2px;display:inline-block;"></span> Menyimpan...`;

        try {
            const isGroup = document.getElementById('gradeIsGroup').value === '1';
            const fd = new FormData();
            fd.append('action', 'save_penilaian');
            fd.append('id_pengumpulan', document.getElementById('gradeSubmissionId').value);
            fd.append('nilai_remedial', document.getElementById('inputRemedial').value);
            fd.append('aspek_itp', document.getElementById('selectAspekItp').value);
            fd.append('catatan_guru', document.getElementById('inputCatatanGuru').value);
            fd.append('status_penilaian', document.getElementById('selectStatusPenilaian').value);

            if (isGroup) {
                const groupScore = document.getElementById('inputGroupScore').value;
                fd.append('nilai_kelompok', groupScore);
                fd.append('nilai', groupScore); // fallback

                const scores = [];
                document.querySelectorAll('.tgs-member-grade-row').forEach(row => {
                    const sid = parseInt(row.getAttribute('data-siswa-id'), 10);
                    const inp = row.querySelector('.input-member-score');
                    const indVal = parseFloat(inp.value) || 0;
                    scores.push({ id_siswa: sid, nilai_individu: indVal });
                });
                fd.append('anggota_scores', JSON.stringify(scores));
            } else {
                fd.append('nilai', document.getElementById('inputScore').value);
            }

            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();

            if (json.success) {
                luminaToast('Alhamdulillah!', json.message, 'success');
                loadSubmissions(true);
                loadStats();
            } else {
                luminaAlert('Gagal Menyimpan Nilai', json.message, 'error');
            }
        } catch (err) {
            console.error('Gagal simpan nilai:', err);
            luminaAlert('Kesalahan Jaringan', 'Kesalahan jaringan saat menyimpan nilai: ' + err.message, 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    };

    // --------------------------------------------------------------------------
    // PENGATURAN GAS TUGAS
    // --------------------------------------------------------------------------
    window.saveGasSetting = async function() {
        const url = document.getElementById('settingGasTugasUrl').value.trim();
        try {
            const fd = new FormData();
            fd.append('action', 'save_gas_setting');
            fd.append('gas_tugas_url', url);
            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                luminaToast('Pengaturan Disimpan', json.message, 'success');
                closeModal('modalGas');
            } else {
                luminaAlert('Gagal', json.message, 'error');
            }
        } catch (e) {
            console.error('Gagal simpan GAS:', e);
            luminaToast('Kesalahan Jaringan', 'Gagal menyimpan pengaturan GAS.', 'error');
        }
    };

    window.testGasConnection = async function() {
        const box = document.getElementById('gasTestStatus');
        box.innerHTML = `<span style="color:#0D7A87;"><iconify-icon icon="solar:refresh-circle-bold" class="tgs-spinner" style="width:14px;height:14px;"></iconify-icon> Menguji koneksi GAS...</span>`;

        try {
            const fd = new FormData();
            fd.append('action', 'test_gas_connection');
            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();

            if (json.success) {
                box.innerHTML = `<span style="color:#059669; font-weight:700;"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> ${escapeHtml(json.message)}</span>`;
            } else {
                box.innerHTML = `<span style="color:#E11D48; font-weight:700;"><iconify-icon icon="solar:close-circle-bold"></iconify-icon> ${escapeHtml(json.message)}</span>`;
            }
        } catch (e) {
            box.innerHTML = `<span style="color:#E11D48;">Gagal uji koneksi.</span>`;
        }
    };

    // --------------------------------------------------------------------------
    // UTILITY HELPERS
    // --------------------------------------------------------------------------
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDateTime(dtStr) {
        if (!dtStr) return '-';
        const d = new Date(dtStr);
        if (isNaN(d.getTime())) return dtStr;
        const opts = { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' };
        return d.toLocaleDateString('id-ID', opts).replace(/\./g, ':');
    }

    // --------------------------------------------------------------------------
    // BULK ACTIONS (GURU)
    // --------------------------------------------------------------------------
    window.updateTaskBulkBar = function() {
        const checked = document.querySelectorAll('.tgs-chk-task:checked');
        const all = document.querySelectorAll('.tgs-chk-task');
        const count = checked.length;
        const countBadge = document.getElementById('bulkTaskCount');
        const bar = document.getElementById('bulkBarTasks');
        const masterChk = document.getElementById('chkSelectAllTasks');

        if (countBadge) countBadge.textContent = count;
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
    };

    window.toggleSelectAllTasks = function(master) {
        const checkboxes = document.querySelectorAll('.tgs-chk-task');
        checkboxes.forEach(cb => { cb.checked = master.checked; });
        updateTaskBulkBar();
    };

    window.clearTaskSelection = function() {
        const checkboxes = document.querySelectorAll('.tgs-chk-task');
        checkboxes.forEach(cb => { cb.checked = false; });
        const master = document.getElementById('chkSelectAllTasks');
        if (master) { master.checked = false; master.indeterminate = false; }
        updateTaskBulkBar();
    };

    window.bulkUpdateTaskStatus = async function(newStatus) {
        const checked = Array.from(document.querySelectorAll('.tgs-chk-task:checked'));
        if (checked.length === 0) {
            luminaAlert('Pilih Tugas', 'Pilih minimal satu penugasan untuk melakukan aksi massal.', 'warning');
            return;
        }
        const ids = checked.map(c => parseInt(c.value));
        const ok = await luminaConfirm(
            'Ubah Status Tugas Massal?',
            `Apakah Anda yakin ingin mengubah status ${ids.length} tugas terpilih menjadi "${newStatus}"?`,
            'Ya, Ubah Status',
            'Batal',
            'info'
        );
        if (!ok) return;

        try {
            const fd = new FormData();
            fd.append('action', 'bulk_status_tugas');
            fd.append('ids', JSON.stringify(ids));
            fd.append('status', newStatus);

            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                luminaToast('Status Diperbarui', json.message, 'success');
                clearTaskSelection();
                loadTugasList(true);
                loadStats(true);
            } else {
                luminaAlert('Gagal', json.message || 'Gagal mengubah status tugas.', 'error');
            }
        } catch (err) {
            console.error('Bulk status error:', err);
            luminaAlert('Kesalahan Jaringan', 'Terjadi kesalahan saat memproses perubahan status tugas.', 'error');
        }
    };

    window.bulkDeleteTasks = async function() {
        const checked = Array.from(document.querySelectorAll('.tgs-chk-task:checked'));
        if (checked.length === 0) {
            luminaAlert('Pilih Tugas', 'Pilih minimal satu penugasan untuk dihapus.', 'warning');
            return;
        }
        const ids = checked.map(c => parseInt(c.value));
        const ok = await luminaConfirm(
            'Hapus Tugas Terpilih?',
            `PERINGATAN: Anda akan menghapus ${ids.length} tugas secara permanen. Semua berkas pengumpulan, riwayat kelompok, dan nilai terkait akan ikut terhapus. Lanjutkan?`,
            'Ya, Hapus Semua',
            'Batal',
            'error'
        );
        if (!ok) return;

        try {
            const fd = new FormData();
            fd.append('action', 'bulk_delete_tugas');
            fd.append('ids', JSON.stringify(ids));

            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                luminaToast('Tugas Dihapus', json.message, 'success');
                clearTaskSelection();
                loadTugasList(true);
                loadStats(true);
            } else {
                luminaAlert('Gagal Menghapus', json.message || 'Gagal menghapus tugas terpilih.', 'error');
            }
        } catch (err) {
            console.error('Bulk delete error:', err);
            luminaAlert('Kesalahan Jaringan', 'Terjadi kesalahan saat menghapus penugasan.', 'error');
        }
    };

    // Submissions Bulk Actions
    window.updateSubmissionBulkBar = function() {
        const checked = document.querySelectorAll('.tgs-chk-sub:checked');
        const all = document.querySelectorAll('.tgs-chk-sub');
        const count = checked.length;
        const countBadge = document.getElementById('bulkSubCount');
        const bar = document.getElementById('bulkBarSubmissions');
        const masterChk = document.getElementById('chkSelectAllSubmissions');

        if (countBadge) countBadge.textContent = count;
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
    };

    window.toggleSelectAllSubmissions = function(master) {
        const checkboxes = document.querySelectorAll('.tgs-chk-sub');
        checkboxes.forEach(cb => { cb.checked = master.checked; });
        updateSubmissionBulkBar();
    };

    window.clearSubmissionSelection = function() {
        const checkboxes = document.querySelectorAll('.tgs-chk-sub');
        checkboxes.forEach(cb => { cb.checked = false; });
        const master = document.getElementById('chkSelectAllSubmissions');
        if (master) { master.checked = false; master.indeterminate = false; }
        updateSubmissionBulkBar();
    };

    window.openBulkGradeModal = function() {
        const checked = Array.from(document.querySelectorAll('.tgs-chk-sub:checked'));
        if (checked.length === 0) {
            luminaAlert('Pilih Pengumpulan', 'Pilih minimal satu berkas pengumpulan untuk dinilai massal.', 'warning');
            return;
        }
        const badge = document.getElementById('bulkGradeCountBadge');
        if (badge) badge.textContent = `${checked.length} Pengumpulan Terpilih`;
        document.getElementById('bulkNilaiInput').value = '100';
        document.getElementById('bulkStatusSelect').value = 'Tuntas';
        document.getElementById('bulkCatatanInput').value = '';
        openModal('modalBulkGrade');
    };

    window.submitBulkGrade = async function() {
        const checked = Array.from(document.querySelectorAll('.tgs-chk-sub:checked'));
        if (checked.length === 0) return;
        const ids = checked.map(c => parseInt(c.value));
        const nilaiVal = document.getElementById('bulkNilaiInput').value.trim();
        const statusVal = document.getElementById('bulkStatusSelect').value;
        const catatanVal = document.getElementById('bulkCatatanInput').value.trim();

        if (nilaiVal === '' || isNaN(nilaiVal) || parseFloat(nilaiVal) < 0 || parseFloat(nilaiVal) > 100) {
            luminaAlert('Nilai Tidak Valid', 'Masukkan angka nilai antara 0 sampai 100.', 'warning');
            return;
        }

        const btn = document.getElementById('btnSubmitBulkGrade');
        const origText = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '<div class="tgs-spinner"></div> Menyimpan...'; }

        try {
            const fd = new FormData();
            fd.append('action', 'bulk_grade_submissions');
            fd.append('ids', JSON.stringify(ids));
            fd.append('nilai', nilaiVal);
            fd.append('status_penilaian', statusVal);
            fd.append('catatan', catatanVal);

            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                closeModal('modalBulkGrade');
                luminaToast('Penilaian Berhasil', json.message, 'success');
                clearSubmissionSelection();
                loadSubmissions(true);
                loadStats(true);
            } else {
                luminaAlert('Gagal Menilai', json.message || 'Gagal menyimpan penilaian massal.', 'error');
            }
        } catch (err) {
            console.error('Bulk grade error:', err);
            luminaAlert('Kesalahan Jaringan', 'Terjadi kesalahan saat memproses penilaian massal.', 'error');
        } finally {
            if (btn) { btn.disabled = false; btn.innerHTML = origText; }
        }
    };

    window.bulkMarkTuntas = async function() {
        const checked = Array.from(document.querySelectorAll('.tgs-chk-sub:checked'));
        if (checked.length === 0) {
            luminaAlert('Pilih Pengumpulan', 'Pilih minimal satu berkas pengumpulan.', 'warning');
            return;
        }
        const ids = checked.map(c => parseInt(c.value));
        const ok = await luminaConfirm(
            'Tandai Tuntas Massal?',
            `Tandai ${ids.length} pengumpulan terpilih sebagai Tuntas (Nilai 100)?`,
            'Ya, Tandai Tuntas',
            'Batal',
            'info'
        );
        if (!ok) return;

        try {
            const fd = new FormData();
            fd.append('action', 'bulk_grade_submissions');
            fd.append('ids', JSON.stringify(ids));
            fd.append('nilai', 100);
            fd.append('status_penilaian', 'Tuntas');
            fd.append('catatan', 'Tuntas dan memenuhi kriteria penugasan.');

            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                luminaToast('Ditandai Tuntas', json.message, 'success');
                clearSubmissionSelection();
                loadSubmissions(true);
                loadStats(true);
            } else {
                luminaAlert('Gagal', json.message || 'Gagal menandai tuntas.', 'error');
            }
        } catch (err) {
            console.error('Bulk tuntas error:', err);
            luminaAlert('Kesalahan Jaringan', 'Gagal memproses aksi massal.', 'error');
        }
    };

    window.bulkMarkRevision = async function() {
        const checked = Array.from(document.querySelectorAll('.tgs-chk-sub:checked'));
        if (checked.length === 0) {
            luminaAlert('Pilih Pengumpulan', 'Pilih minimal satu berkas pengumpulan.', 'warning');
            return;
        }
        const ids = checked.map(c => parseInt(c.value));
        const ok = await luminaConfirm(
            'Minta Revisi Massal?',
            `Tandai ${ids.length} pengumpulan terpilih sebagai "Perlu Revisi"? Siswa dapat mengunggah kembali perbaikan berkas.`,
            'Ya, Minta Revisi',
            'Batal',
            'warning'
        );
        if (!ok) return;

        try {
            const fd = new FormData();
            fd.append('action', 'bulk_grade_submissions');
            fd.append('ids', JSON.stringify(ids));
            fd.append('nilai', 0);
            fd.append('status_penilaian', 'Perlu Revisi');
            fd.append('catatan', 'Perlu perbaikan berkas sesuai instruksi penugasan.');

            const res = await fetch('<?= BASE_URL ?>/dashboard/guru/tugas', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                luminaToast('Status Revisi', json.message, 'success');
                clearSubmissionSelection();
                loadSubmissions(true);
                loadStats(true);
            } else {
                luminaAlert('Gagal', json.message || 'Gagal menandai revisi.', 'error');
            }
        } catch (err) {
            console.error('Bulk revisi error:', err);
            luminaAlert('Kesalahan Jaringan', 'Gagal memproses aksi revisi massal.', 'error');
        }
    };

    // --------------------------------------------------------------------------
    // INITIALIZATION
    // --------------------------------------------------------------------------
    loadKelasOptions();
    loadTugasList();
    loadStats();

})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

