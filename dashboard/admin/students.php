<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — DATA SISWA & PESERTA DIDIK MANAGEMENT
 * ==============================================================================
 * Sesuai Skema Database: skema-tabel-kelas.sql (Tabel: siswa, kelas, kelas_mata_pelajaran, presensi, users)
 * Mekanisme & Tampilan: Mengikuti referensi data-mahasiswa (Filter wajib pilih kelas,
 *                       Lazy Load AJAX, Paginated, Bulk Action, Export/Import Excel,
 *                       dan Generator Kode Presensi QR Unik 1 Semester).
 * Format Kode Presensi: (3 digit acak)NIS(4 digit acak)NISN(5 digit acak) per id_kelas_mata_pelajaran
 * Masa Berlaku: 1 Semester (6 Bulan) sebagai landasan QR Code absensi
 * Tema Warna: Lumina Gold (Navy #1D3B5E, Teal #0D7A87, Sky #52B2DC, Gold #F59E0B)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole('admin');

// Delegasi langsung untuk aksi Excel ke backend terpisah (students-excel.php)
if (isset($_GET['action']) && in_array($_GET['action'], ['download_template', 'export_excel', 'import_excel'], true)) {
    require_once __DIR__ . '/students-excel.php';
    exit;
}

$pdo = getDBConnection();
$pageTitle = 'Data Siswa & Peserta Didik';
$pageIcon = 'solar:square-academic-cap-2-bold';

/* ==============================================================================
 * 1. STATS & KPI HELPER
 * ============================================================================== */
if (!function_exists('getSiswaStats')) {
    function getSiswaStats($pdo, $idKelas = 0) {
        $totalSiswa = (int)$pdo->query("SELECT COUNT(*) FROM siswa")->fetchColumn();
        $totalKelas = (int)$pdo->query("SELECT COUNT(*) FROM kelas")->fetchColumn();
        $totalPresensi = (int)$pdo->query("SELECT COUNT(*) FROM presensi WHERE status = 'aktif'")->fetchColumn();
        
        $siswaKelasIni = 0;
        $mapelKelasIni = 0;
        if ($idKelas > 0) {
            $siswaKelasIni = (int)$pdo->query("SELECT COUNT(*) FROM siswa WHERE id_kelas = " . (int)$idKelas)->fetchColumn();
            $mapelKelasIni = (int)$pdo->query("SELECT COUNT(*) FROM kelas_mata_pelajaran WHERE id_kelas = " . (int)$idKelas)->fetchColumn();
        }

        return [
            'total_siswa'     => $totalSiswa,
            'total_kelas'     => $totalKelas,
            'total_presensi'  => $totalPresensi,
            'siswa_kelas_ini' => $siswaKelasIni,
            'mapel_kelas_ini' => $mapelKelasIni
        ];
    }
}

/* ==============================================================================
 * 2. AJAX API ENDPOINTS
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['action'];

    // 2.1 LIST PILIHAN KELAS UNTUK DROPDOWN & FILTER
    if ($act === 'list_kelas_options') {
        $sql = "SELECT k.id, k.nama_kelas, k.tahun_pelajaran,
                       (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = k.id) AS total_siswa,
                       (SELECT COUNT(*) FROM kelas_mata_pelajaran kmp WHERE kmp.id_kelas = k.id) AS total_mapel
                FROM kelas k
                ORDER BY k.tahun_pelajaran DESC, k.nama_kelas ASC";
        $kelas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => [
                'kelas' => $kelas ?: [],
                'stats' => getSiswaStats($pdo)
            ]
        ]);
        exit;
    }

    // 2.2 LIST SISWA PER KELAS (LAZY LOAD + SEARCH + PAGINASI)
    if ($act === 'list') {
        $id_kelas = (int)($_POST['id_kelas'] ?? 0);
        $search   = trim($_POST['search'] ?? '');
        $page     = max(1, (int)($_POST['page'] ?? 1));
        $per      = max(5, min(200, (int)($_POST['per_page'] ?? 20)));
        $offset   = ($page - 1) * $per;

        // Jika belum memilih kelas dan tidak ada kata kunci pencarian, kembalikan state kosong
        if ($id_kelas <= 0 && $search === '') {
            echo json_encode([
                'success' => true,
                'data' => [
                    'rows'       => [],
                    'total'      => 0,
                    'page'       => 1,
                    'per'        => $per,
                    'pages'      => 1,
                    'kelas_info' => null,
                    'stats'      => getSiswaStats($pdo, 0)
                ]
            ]);
            exit;
        }

        $where = '1=1';
        $params = [];

        if ($id_kelas > 0) {
            $where .= ' AND s.id_kelas = ?';
            $params[] = $id_kelas;
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= ' AND (s.nama_lengkap LIKE ? OR s.nisn LIKE ? OR s.nis LIKE ? OR s.gmail LIKE ? OR s.no_hp LIKE ?)';
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
        }

        // Total data
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM siswa s WHERE {$where}");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        // Ambil data siswa beserta status kode presensi per mata pelajaran
        $orderBy = ($id_kelas > 0) ? "s.nama_lengkap ASC" : "k.nama_kelas ASC, s.nama_lengkap ASC";
        $sql = "SELECT s.id, s.id_kelas, s.nisn, s.nis, s.nama_lengkap, s.no_hp, s.gmail, s.alamat, s.foto, s.created_at,
                       k.nama_kelas, k.tahun_pelajaran,
                       (SELECT COUNT(*) FROM presensi p WHERE p.id_siswa = s.id AND p.status = 'aktif') AS total_presensi_aktif,
                       (SELECT COUNT(*) FROM kelas_mata_pelajaran kmp WHERE kmp.id_kelas = s.id_kelas) AS total_mapel_kelas,
                       u.id AS user_id, u.username AS user_username, u.status AS user_status
                FROM siswa s
                JOIN kelas k ON s.id_kelas = k.id
                LEFT JOIN users u ON (s.nisn = u.nisn_nip OR s.nisn = u.username OR s.gmail = u.email)
                WHERE {$where}
                ORDER BY {$orderBy}
                LIMIT {$per} OFFSET {$offset}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ambil info lengkap kelas aktif
        $stmtK = $pdo->prepare("
            SELECT k.id, k.nama_kelas, k.tahun_pelajaran,
                   (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = k.id) AS total_siswa,
                   (SELECT COUNT(*) FROM kelas_mata_pelajaran kmp WHERE kmp.id_kelas = k.id) AS total_mapel
            FROM kelas k
            WHERE k.id = ? LIMIT 1
        ");
        $stmtK->execute([$id_kelas]);
        $kelas_info = $stmtK->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => [
                'rows'       => $rows ?: [],
                'total'      => $total,
                'page'       => $page,
                'per'        => $per,
                'pages'      => max(1, (int)ceil($total / $per)),
                'kelas_info' => $kelas_info,
                'stats'      => getSiswaStats($pdo, $id_kelas)
            ]
        ]);
        exit;
    }

    // 2.3 GET SINGLE SISWA (UNTUK EDIT)
    if ($act === 'get_single') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID siswa tidak valid.']]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT s.*, k.nama_kelas,
                   u.id as user_id, u.username as user_username, u.status as user_status
            FROM siswa s
            JOIN kelas k ON s.id_kelas = k.id
            LEFT JOIN users u ON (s.nisn = u.nisn_nip OR s.nisn = u.username OR s.gmail = u.email)
            WHERE s.id = ? LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Data siswa tidak ditemukan.']]);
            exit;
        }

        echo json_encode(['success' => true, 'data' => ['row' => $row]]);
        exit;
    }

    // 2.4 SIMPAN / EDIT DATA SISWA
    if ($act === 'save') {
        $id           = (int)($_POST['id'] ?? 0);
        $id_kelas     = (int)($_POST['id_kelas'] ?? 0);
        $nisn         = trim($_POST['nisn'] ?? '');
        $nis          = trim($_POST['nis'] ?? '');
        $nama_lengkap = trim($_POST['nama_lengkap'] ?? '');
        $no_hp        = trim($_POST['no_hp'] ?? '');
        $gmail        = trim($_POST['gmail'] ?? '');
        $alamat       = trim($_POST['alamat'] ?? '');
        $create_user  = !empty($_POST['create_account']) && $_POST['create_account'] == '1';
        $user_pass    = trim($_POST['password'] ?? 'siswa123');

        if ($id_kelas <= 0) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Rombel kelas wajib dipilih.']]);
            exit;
        }
        if ($nama_lengkap === '') {
            echo json_encode(['success' => false, 'data' => ['message' => 'Nama lengkap siswa wajib diisi.']]);
            exit;
        }
        if ($nisn === '' && $nis === '') {
            echo json_encode(['success' => false, 'data' => ['message' => 'NISN atau NIS wajib diisi.']]);
            exit;
        }
        if ($nisn === '') $nisn = $nis;
        if ($nis === '')  $nis = $nisn;

        // Cek duplikasi NISN pada siswa lain
        $checkDup = $pdo->prepare("SELECT id FROM siswa WHERE (nisn = ? OR nis = ?) AND id != ? LIMIT 1");
        $checkDup->execute([$nisn, $nis, $id]);
        if ($checkDup->fetch()) {
            echo json_encode(['success' => false, 'data' => ['message' => 'NISN atau NIS sudah digunakan oleh siswa lain.']]);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Upload Foto Siswa jika ada
            $uploadedFoto = null;
            $deleteFoto   = !empty($_POST['delete_foto']) && $_POST['delete_foto'] == '1';

            if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $tmpName  = $_FILES['foto']['tmp_name'];
                $fileSize = $_FILES['foto']['size'];
                if ($fileSize <= 3 * 1024 * 1024) { // max 3MB
                    $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
                    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
                    if (in_array($ext, $allowedExts)) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime  = finfo_file($finfo, $tmpName);
                        finfo_close($finfo);
                        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
                            $targetDir = __DIR__ . '/../../uploads/students/';
                            if (!is_dir($targetDir)) {
                                @mkdir($targetDir, 0755, true);
                            }
                            $safeName = 'siswa_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
                            if (move_uploaded_file($tmpName, $targetDir . $safeName)) {
                                $uploadedFoto = 'uploads/students/' . $safeName;
                            }
                        }
                    }
                }
            }

            if ($id > 0) {
                $oldFoto = $pdo->query("SELECT foto FROM siswa WHERE id = " . $id)->fetchColumn();
                $finalFoto = $oldFoto;
                if ($uploadedFoto !== null) {
                    $finalFoto = $uploadedFoto;
                    if ($oldFoto && file_exists(__DIR__ . '/../../' . $oldFoto) && strpos($oldFoto, 'uploads/students/') === 0) {
                        @unlink(__DIR__ . '/../../' . $oldFoto);
                    }
                } elseif ($deleteFoto) {
                    $finalFoto = null;
                    if ($oldFoto && file_exists(__DIR__ . '/../../' . $oldFoto) && strpos($oldFoto, 'uploads/students/') === 0) {
                        @unlink(__DIR__ . '/../../' . $oldFoto);
                    }
                }

                $stmt = $pdo->prepare("
                    UPDATE siswa 
                    SET id_kelas = ?, nisn = ?, nis = ?, nama_lengkap = ?, no_hp = ?, gmail = ?, alamat = ?, foto = ?
                    WHERE id = ?
                ");
                $stmt->execute([$id_kelas, $nisn, $nis, $nama_lengkap, $no_hp ?: null, $gmail ?: null, $alamat ?: null, $finalFoto, $id]);
                $studentId = $id;
                $msg = "Data siswa '{$nama_lengkap}' berhasil diperbarui.";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO siswa (id_kelas, nisn, nis, nama_lengkap, no_hp, gmail, alamat, foto)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$id_kelas, $nisn, $nis, $nama_lengkap, $no_hp ?: null, $gmail ?: null, $alamat ?: null, $uploadedFoto]);
                $studentId = (int)$pdo->lastInsertId();
                $msg = "Siswa '{$nama_lengkap}' berhasil ditambahkan ke rombel.";
            }

            // Opsi Pembuatan Akun Login Siswa (Tabel: users & user_roles)
            if ($create_user && $studentId > 0) {
                $uName = 'siswa_' . $nisn;
                $uEmail = !empty($gmail) ? $gmail : ($nisn . '@sekolah.id');

                $stmtCheckU = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
                $stmtCheckU->execute([$uName, $uEmail]);
                $existingUser = $stmtCheckU->fetch(PDO::FETCH_ASSOC);

                if (!$existingUser) {
                    $passHash = password_hash($user_pass ?: 'siswa123', PASSWORD_BCRYPT);
                    $stmtInsU = $pdo->prepare("
                        INSERT INTO users (username, password_hash, email, full_name, nisn_nip, phone, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'active')
                    ");
                    $stmtInsU->execute([$uName, $passHash, $uEmail, $nama_lengkap, $nisn, $no_hp ?: null]);
                    $newUserId = (int)$pdo->lastInsertId();

                    // Assign role siswa (role_id = 3)
                    $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id, is_primary) VALUES (?, 3, 1)")
                        ->execute([$newUserId]);
                }
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => $msg,
                    'id'      => $studentId
                ]
            ]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal menyimpan: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 2.5 HAPUS SISWA (SINGLE)
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID siswa tidak valid.']]);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Ambil info foto sebelum dihapus
            $stmtFoto = $pdo->prepare("SELECT foto FROM siswa WHERE id = ? LIMIT 1");
            $stmtFoto->execute([$id]);
            $oldFoto = $stmtFoto->fetchColumn();

            // Hapus presensi terkait
            $pdo->prepare("DELETE FROM presensi WHERE id_siswa = ?")->execute([$id]);
            // Hapus siswa
            $pdo->prepare("DELETE FROM siswa WHERE id = ?")->execute([$id]);
            $pdo->commit();

            if (!empty($oldFoto)) {
                $photoPath = __DIR__ . '/../../' . $oldFoto;
                if (file_exists($photoPath)) @unlink($photoPath);
            }

            echo json_encode(['success' => true, 'data' => ['message' => 'Data siswa dan histori kartu presensi berhasil dihapus.']]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal menghapus siswa: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 2.6 BULK DELETE SISWA
    if ($act === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_unique(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada siswa yang dipilih.']]);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $inClause = implode(',', array_fill(0, count($ids), '?'));

            // Ambil daftar file foto sebelum dihapus
            $stmtFotos = $pdo->prepare("SELECT foto FROM siswa WHERE id IN ($inClause) AND foto IS NOT NULL AND foto != ''");
            $stmtFotos->execute($ids);
            $fotosToDelete = $stmtFotos->fetchAll(PDO::FETCH_COLUMN);

            $pdo->prepare("DELETE FROM presensi WHERE id_siswa IN ($inClause)")->execute($ids);
            $pdo->prepare("DELETE FROM siswa WHERE id IN ($inClause)")->execute($ids);
            $pdo->commit();

            // Hapus file fisik setelah commit
            foreach ($fotosToDelete as $oldFoto) {
                if (!empty($oldFoto)) {
                    $p = __DIR__ . '/../../' . $oldFoto;
                    if (file_exists($p)) @unlink($p);
                }
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => count($ids) . ' siswa berhasil dihapus secara massal.',
                    'count'   => count($ids)
                ]
            ]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal hapus massal: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 2.7 GENERATE KODE PRESENSI SISWA (SINGLE ATAU BULK)
    // Format: (3 digit acak) + NIS + (4 digit acak) + NISN + (5 digit acak), expired 1 semester
    if ($act === 'generate_presensi') {
        $id_kelas   = (int)($_POST['id_kelas'] ?? 0);
        $student_id = (int)($_POST['id_siswa'] ?? 0);
        $bulk_ids   = $_POST['student_ids'] ?? [];

        if (!is_array($bulk_ids)) $bulk_ids = [];
        $bulk_ids = array_unique(array_filter(array_map('intval', $bulk_ids)));

        if ($student_id > 0) {
            $targetStudentIds = [$student_id];
        } elseif (!empty($bulk_ids)) {
            $targetStudentIds = $bulk_ids;
        } elseif ($id_kelas > 0) {
            $stmtAllSiswa = $pdo->prepare("SELECT id FROM siswa WHERE id_kelas = ?");
            $stmtAllSiswa->execute([$id_kelas]);
            $targetStudentIds = $stmtAllSiswa->fetchAll(PDO::FETCH_COLUMN);
        } else {
            echo json_encode(['success' => false, 'data' => ['message' => 'Pilih siswa atau rombel kelas terlebih dahulu.']]);
            exit;
        }

        if (empty($targetStudentIds)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada siswa yang ditemukan untuk digenerate.']]);
            exit;
        }

        // Ambil data siswa (NIS & NISN & id_kelas)
        $inSiswa = implode(',', array_fill(0, count($targetStudentIds), '?'));
        $stmtSiswa = $pdo->prepare("SELECT id, id_kelas, nisn, nis, nama_lengkap FROM siswa WHERE id IN ($inSiswa)");
        $stmtSiswa->execute($targetStudentIds);
        $students = $stmtSiswa->fetchAll(PDO::FETCH_ASSOC);

        // Siapkan statement query mata pelajaran kelas
        $stmtMapel = $pdo->prepare("SELECT id, id_mata_pelajaran FROM kelas_mata_pelajaran WHERE id_kelas = ?");

        // Statement Insert or Update Presensi
        $stmtInsertPresensi = $pdo->prepare("
            INSERT INTO presensi (id_presensi, id_siswa, id_kelas_mata_pelajaran, tanggal_created, expired_at, status)
            VALUES (?, ?, ?, NOW(), ?, 'aktif')
            ON DUPLICATE KEY UPDATE
                id_presensi = VALUES(id_presensi),
                tanggal_created = NOW(),
                expired_at = VALUES(expired_at),
                status = 'aktif'
        ");

        // Expired 1 Semester (6 Bulan ke depan dari sekarang)
        $expiredAt = date('Y-m-d 23:59:59', strtotime('+6 months'));

        $totalGenerated = 0;
        $mapelCountMap = [];

        try {
            $pdo->beginTransaction();

            foreach ($students as $st) {
                $sId = (int)$st['id'];
                $sKelas = (int)$st['id_kelas'];
                $nisClean  = preg_replace('/[^a-zA-Z0-9]/', '', (string)$st['nis']);
                $nisnClean = preg_replace('/[^a-zA-Z0-9]/', '', (string)$st['nisn']);

                // Ambil daftar id_kelas_mata_pelajaran untuk kelas siswa
                $stmtMapel->execute([$sKelas]);
                $kmps = $stmtMapel->fetchAll(PDO::FETCH_ASSOC);

                if (empty($kmps)) {
                    // Jika rombel belum punya relasi kelas_mata_pelajaran, otomatis hubungkan ke mapel pertama
                    $firstMapel = $pdo->query("SELECT id FROM mata_pelajaran LIMIT 1")->fetchColumn();
                    if ($firstMapel) {
                        $pdo->prepare("INSERT IGNORE INTO kelas_mata_pelajaran (id_kelas, id_mata_pelajaran) VALUES (?, ?)")
                            ->execute([$sKelas, $firstMapel]);
                        $stmtMapel->execute([$sKelas]);
                        $kmps = $stmtMapel->fetchAll(PDO::FETCH_ASSOC);
                    }
                }

                foreach ($kmps as $kmp) {
                    $kmpId = (int)$kmp['id'];
                    $mapelCountMap[$kmpId] = true;

                    // POLA KODE PRESENSI: (3 digit acak) + NIS + (4 digit acak) + NISN + (5 digit acak)
                    $rand3 = mt_rand(100, 999);
                    $rand4 = mt_rand(1000, 9999);
                    $rand5 = mt_rand(10000, 99999);
                    $kodePresensi = sprintf("%03d%s%04d%s%05d", $rand3, $nisClean, $rand4, $nisnClean, $rand5);

                    // Insert or update
                    $stmtInsertPresensi->execute([$kodePresensi, $sId, $kmpId, $expiredAt]);
                    $totalGenerated++;
                }
            }

            $pdo->commit();

            $msg = "Berhasil membuat {$totalGenerated} kode presensi unik untuk " . count($students) . " siswa (" . count($mapelCountMap) . " mata pelajaran terhubung). Berlaku selama 1 semester untuk QR Code absensi.";

            echo json_encode([
                'success' => true,
                'data' => [
                    'message'         => $msg,
                    'total_generated' => $totalGenerated,
                    'total_students'  => count($students),
                    'total_mapel'     => count($mapelCountMap),
                    'expired_at'      => $expiredAt
                ]
            ]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal generate kode presensi: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 2.8 GET DETAIL KODE PRESENSI SISWA (UNTUK MODAL KARTU & QR CODE)
    if ($act === 'get_student_presensi') {
        $student_id = (int)($_POST['id_siswa'] ?? 0);
        if (!$student_id) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID siswa tidak valid.']]);
            exit;
        }

        // Ambil info siswa & kelas
        $stmtS = $pdo->prepare("
            SELECT s.*, k.nama_kelas, k.tahun_pelajaran
            FROM siswa s
            JOIN kelas k ON s.id_kelas = k.id
            WHERE s.id = ? LIMIT 1
        ");
        $stmtS->execute([$student_id]);
        $student = $stmtS->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Data siswa tidak ditemukan.']]);
            exit;
        }

        // Ambil seluruh mapel yang ada di kelas ini beserta kode presensi yang sudah digenerate
        $sqlPresensi = "
            SELECT kmp.id as id_kelas_mata_pelajaran, mp.nama_mata_pelajaran, mp.semester,
                   g.nama_guru,
                   p.id as presensi_id, p.id_presensi, p.tanggal_created, p.expired_at, p.status as presensi_status
            FROM kelas_mata_pelajaran kmp
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
            LEFT JOIN guru g ON gmp.id_guru = g.id
            LEFT JOIN presensi p ON (p.id_kelas_mata_pelajaran = kmp.id AND p.id_siswa = ?)
            WHERE kmp.id_kelas = ?
            ORDER BY mp.nama_mata_pelajaran ASC
        ";
        $stmtP = $pdo->prepare($sqlPresensi);
        $stmtP->execute([$student_id, (int)$student['id_kelas']]);
        $items = $stmtP->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => [
                'student' => $student,
                'items'   => $items ?: []
            ]
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'data' => ['message' => 'Aksi tidak dikenali.']]);
    exit;
}

$cssVersion = file_exists(BASE_PATH . '/assets/css/admin-students.css') ? filemtime(BASE_PATH . '/assets/css/admin-students.css') : '1.0';
$extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-students.css?v=' . $cssVersion . '">';

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<!-- ==========================================================================
 * LUMINA GOLD THEMED CRUD DATA SISWA (KURIKULUM MERDEKA 69 TABEL)
 * ========================================================================== -->
<div class="ilmuna-siswa-app" id="dosenCrudSiswa" 
     data-ajax="<?= BASE_URL ?>/dashboard/admin/students" 
     data-excel="<?= BASE_URL ?>/dashboard/admin/students-excel">

    <!-- TOOLBAR & PRIMARY CLASS SELECTOR -->
    <div class="ilmuna-crud-toolbar">
        <div class="ilmuna-crud-toolbar__row">
            <!-- Filter Utama: Dropdown Kelas (Wajib Dipilih Terlebih Dahulu) -->
            <div class="ilmuna-filter-primary">
                <label for="filterKelasSiswa">
                    <iconify-icon icon="solar:notebook-bookmark-bold" width="18"></iconify-icon>
                    Rombel / Kelas:
                </label>
                <select id="filterKelasSiswa" class="ilmuna-select-primary">
                    <option value="0">— Pilih Rombel Kelas Terlebih Dahulu —</option>
                </select>
            </div>

            <!-- Search Box (WhatsApp Live Style) -->
            <div class="ilmuna-search-box">
                <iconify-icon icon="solar:magnifer-bold" class="ilmuna-search-box__icon"></iconify-icon>
                <input type="text" id="searchSiswa" placeholder="Cari nama siswa, NISN, NIS, gmail, no WhatsApp (ketik langsung)..." autocomplete="off">
                <button type="button" class="ilmuna-search-clear" id="btnClearSearch" style="display:none;" title="Hapus pencarian">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                </button>
            </div>

            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnSearchSiswa">
                <iconify-icon icon="solar:magnifer-bold" width="16"></iconify-icon>
                Cari
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="btnResetSiswa">
                <iconify-icon icon="solar:refresh-bold" width="16"></iconify-icon>
                Reset
            </button>
        </div>

        <div class="ilmuna-crud-toolbar__row ilmuna-crud-toolbar__row--filters">
            <!-- Per Halaman -->
            <div class="ilmuna-filter-group">
                <label for="perPageSiswa">
                    <iconify-icon icon="solar:list-bold" width="14"></iconify-icon>
                    Tampilkan:
                </label>
                <select id="perPageSiswa" class="ilmuna-select-sm">
                    <option value="20">20 Siswa</option>
                    <option value="50">50 Siswa</option>
                    <option value="100">100 Siswa</option>
                    <option value="200">200 Siswa</option>
                </select>
            </div>

            <!-- Action Buttons (Tambah Siswa di paling kiri) -->
            <div class="ilmuna-crud-toolbar__row--actions" style="margin-left: auto;">
                <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="btnOpenAddStudent" disabled title="Pilih rombel kelas terlebih dahulu">
                    <iconify-icon icon="solar:user-plus-bold" width="16"></iconify-icon>
                    Tambah Siswa
                </button>
                <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="btnDownloadTemplate">
                    <iconify-icon icon="solar:file-download-bold" width="16"></iconify-icon>
                    Unduh Format Excel
                </button>
                <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="btnOpenImport">
                    <iconify-icon icon="solar:cloud-upload-bold" width="16"></iconify-icon>
                    Import Excel
                </button>
                <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="btnExportExcel" disabled title="Pilih rombel kelas terlebih dahulu untuk export">
                    <iconify-icon icon="solar:file-send-bold" width="16"></iconify-icon>
                    Export Excel
                </button>
                <button type="button" class="ilmuna-btn ilmuna-btn--emerald" id="btnGenerateClassPresensi" disabled title="Pilih rombel kelas terlebih dahulu untuk generate kode presensi">
                    <iconify-icon icon="solar:qr-code-bold" width="17"></iconify-icon>
                    Generate Presensi Rombel
                </button>
            </div>
        </div>
    </div>

    <!-- ACTIVE CLASS INFO BANNER CARD (DYNAMICALLY SHOWN) -->
    <div id="classBannerWrap" style="display: none;"></div>

    <!-- TABEL DATA SISWA -->
    <div class="ilmuna-crud-tablewrap">
        <table class="ilmuna-crud-table" id="tableSiswa">
            <thead>
                <tr>
                    <th style="width: 40px; text-align: center;">
                        <input type="checkbox" id="checkAllStudents" class="ilmuna-bulk-cb" title="Pilih Semua Siswa">
                    </th>
                    <th style="width: 46px; text-align: center;">#</th>
                    <th style="width: 65px; text-align: center;">Foto</th>
                    <th style="width: 125px;">NISN</th>
                    <th style="width: 115px;">NIS</th>
                    <th>Nama Lengkap Siswa</th>
                    <th style="width: 140px;">No WhatsApp / HP</th>
                    <th style="width: 170px;">Status Kode Presensi</th>
                    <th>Alamat Tinggal</th>
                    <th style="width: 130px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody id="tableSiswaBody">
                <tr>
                    <td colspan="10">
                        <div class="ilmuna-empty-row">
                            <div class="ilmuna-empty-row__icon">
                                <iconify-icon icon="solar:notebook-bookmark-bold"></iconify-icon>
                            </div>
                            <h4>Pilih Rombel / Kelas Terlebih Dahulu</h4>
                            <p>Data siswa beserta kartu QR Code presensi akan ditampilkan secara otomatis setelah Anda memilih salah satu rombel kelas di atas.</p>
                            <div class="ilmuna-empty-row__chips" id="quickClassChips"></div>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- PAGINATION -->
    <div class="ilmuna-pagination" id="paginationWrap">
        <div class="ilmuna-pagination__info" id="paginationInfo">Pilih rombel kelas di atas</div>
        <div class="ilmuna-pagination__btns" id="paginationBtns"></div>
    </div>
</div>

<!-- ==========================================================================
 * FLOATING BULK ACTIONS BOTTOM BAR
 * ========================================================================== -->
<div class="ilmuna-bulk-bar" id="bulkActionBar">
    <div class="ilmuna-bulk-bar__count">
        Terpilih: <strong id="bulkSelectedCount">0</strong> siswa
    </div>
    <div class="ilmuna-bulk-bar__divider"></div>
    <div class="ilmuna-bulk-bar__actions">
        <button type="button" class="ilmuna-bulk-bar__btn ilmuna-bulk-bar__btn--presensi" id="btnBulkPresensi">
            <iconify-icon icon="solar:qr-code-bold" width="16"></iconify-icon>
            Generate Kode Presensi
        </button>
        <button type="button" class="ilmuna-bulk-bar__btn ilmuna-bulk-bar__btn--danger" id="btnBulkDelete">
            <iconify-icon icon="solar:trash-bin-trash-bold" width="16"></iconify-icon>
            Hapus Terpilih
        </button>
        <button type="button" class="ilmuna-bulk-bar__btn ilmuna-bulk-bar__btn--ghost" id="btnBulkCancel">
            Batal
        </button>
    </div>
</div>

<!-- ==========================================================================
 * MODAL: FORM TAMBAH / EDIT SISWA
 * ========================================================================== -->
<div class="ilmuna-modal" id="modalStudentForm">
    <div class="ilmuna-modal__overlay" data-close="1"></div>
    <div class="ilmuna-modal__box">
        <div class="ilmuna-modal__head">
            <h3 id="modalStudentTitle">
                <iconify-icon icon="solar:user-plus-bold" width="20"></iconify-icon>
                <span>Tambah Siswa Baru</span>
            </h3>
            <button type="button" class="ilmuna-modal__close" data-close="1">&times;</button>
        </div>
        <form id="formStudent" class="ilmuna-modal__body" onsubmit="return false;" enctype="multipart/form-data">
            <input type="hidden" name="id" id="formStudentId" value="0">
            <input type="hidden" id="inputDeleteFotoSiswa" name="delete_foto" value="0">

            <!-- FOTO SISWA UPLOAD & PREVIEW WIDGET -->
            <div class="siswa-photo-upload-widget">
                <input type="file" id="inputFotoSiswa" name="foto" accept="image/jpeg,image/png,image/webp,image/jpg" style="display: none;">
                <div class="siswa-photo-upload-preview" id="previewFotoSiswa">
                    <iconify-icon icon="solar:user-bold" width="34" style="color: #94A3B8;"></iconify-icon>
                </div>
                <div class="siswa-photo-upload-info">
                    <h5>Foto Profil Siswa</h5>
                    <p>Format JPG, PNG, atau WEBP (Maksimal 3MB). Rasio persegi 1:1 disarankan.</p>
                    <div class="siswa-photo-upload-actions">
                        <button type="button" class="ilmuna-btn ilmuna-btn--primary ilmuna-btn--xs" id="btnBrowseFotoSiswa">
                            <iconify-icon icon="solar:camera-add-bold" width="13"></iconify-icon> Pilih Foto
                        </button>
                        <button type="button" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--xs" id="btnRemoveFotoSiswa" style="display: none; color: #DC2626; border-color: #FCA5A5;">
                            <iconify-icon icon="solar:trash-bin-trash-bold" width="13"></iconify-icon> Hapus Foto
                        </button>
                    </div>
                </div>
            </div>

            <div class="ilmuna-form-field">
                <label>Rombel / Kelas <span class="req">*</span></label>
                <select name="id_kelas" id="formStudentKelas" class="ilmuna-input" required>
                    <option value="">— Pilih Kelas —</option>
                </select>
            </div>

            <div class="ilmuna-form-row">
                <div class="ilmuna-form-field">
                    <label>NISN <span class="req">*</span></label>
                    <input type="text" name="nisn" id="formStudentNisn" class="ilmuna-input" placeholder="contoh: 0081234567" required>
                </div>
                <div class="ilmuna-form-field">
                    <label>NIS (Nomor Induk Sekolah) <span class="req">*</span></label>
                    <input type="text" name="nis" id="formStudentNis" class="ilmuna-input" placeholder="contoh: 26271001" required>
                </div>
            </div>

            <div class="ilmuna-form-field">
                <label>Nama Lengkap Siswa <span class="req">*</span></label>
                <input type="text" name="nama_lengkap" id="formStudentNama" class="ilmuna-input" placeholder="contoh: Muhammad Farhan Al-Fatih" required>
            </div>

            <div class="ilmuna-form-row">
                <div class="ilmuna-form-field">
                    <label>No WhatsApp / Telepon</label>
                    <input type="text" name="no_hp" id="formStudentHp" class="ilmuna-input" placeholder="contoh: 081234567890">
                </div>
                <div class="ilmuna-form-field">
                    <label>Gmail / Email</label>
                    <input type="email" name="gmail" id="formStudentGmail" class="ilmuna-input" placeholder="contoh: farhan@gmail.com">
                </div>
            </div>

            <div class="ilmuna-form-field">
                <label>Alamat Tinggal</label>
                <textarea name="alamat" id="formStudentAlamat" class="ilmuna-input" rows="2" placeholder="Alamat lengkap tempat tinggal siswa"></textarea>
            </div>

            <div class="ilmuna-checkbox-group" id="wrapCreateAccount">
                <input type="checkbox" name="create_account" id="formStudentCreateAccount" value="1">
                <div class="ilmuna-checkbox-group__label">
                    <span class="ilmuna-checkbox-group__title">Buat Akun Portal Login Siswa Otomatis</span>
                    <span class="ilmuna-checkbox-group__desc">Username akan diset ke <code>siswa_{NISN}</code> dengan hak akses Siswa (role_id 3).</span>
                </div>
            </div>
        </form>
        <div class="ilmuna-modal__foot">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnSaveStudent">
                <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                Simpan Siswa
            </button>
        </div>
    </div>
</div>

<!-- ==========================================================================
 * MODAL: DETAIL KODE PRESENSI & QR CODE SISWA
 * ========================================================================== -->
<div class="ilmuna-modal" id="modalPresensiDetail">
    <div class="ilmuna-modal__overlay" data-close="1"></div>
    <div class="ilmuna-modal__box" style="max-width: 680px;">
        <div class="ilmuna-modal__head" style="background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%);">
            <h3>
                <iconify-icon icon="solar:qr-code-bold" width="22"></iconify-icon>
                <span>Kartu QR Code Presensi Siswa</span>
            </h3>
            <button type="button" class="ilmuna-modal__close" data-close="1">&times;</button>
        </div>
        <div class="ilmuna-modal__body" id="modalPresensiBody">
            <div style="text-align:center; padding: 30px;"><iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="30"></iconify-icon><p>Memuat kode presensi...</p></div>
        </div>
        <div class="ilmuna-modal__foot">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Tutup</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--emerald" id="btnRegenerateStudentPresensi">
                <iconify-icon icon="solar:refresh-bold" width="16"></iconify-icon>
                Regenerate Kode Presensi Siswa Ini
            </button>
        </div>
    </div>
</div>

<!-- ==========================================================================
 * MODAL: IMPORT DATA SISWA DARI EXCEL
 * ========================================================================== -->
<div class="ilmuna-modal" id="modalImportExcel">
    <div class="ilmuna-modal__overlay" data-close="1"></div>
    <div class="ilmuna-modal__box" style="max-width: 580px;">
        <div class="ilmuna-modal__head">
            <h3>
                <iconify-icon icon="solar:cloud-upload-bold" width="22"></iconify-icon>
                <span>Import Data Siswa dari Excel (.xlsx)</span>
            </h3>
            <button type="button" class="ilmuna-modal__close" data-close="1">&times;</button>
        </div>
        <form id="formImportExcel" class="ilmuna-modal__body" onsubmit="return false;">
            <div class="ilmuna-form-field">
                <label>Target Rombel / Kelas</label>
                <select name="id_kelas" id="importTargetKelas" class="ilmuna-input">
                    <option value="0">— Gunakan Kolom nama_kelas di File Excel —</option>
                </select>
                <small style="color:#64748b; font-size:11.5px;">Jika Anda memilih rombel di sini, seluruh siswa di file akan dimasukkan ke rombel ini (mengabaikan kolom nama_kelas di Excel).</small>
            </div>

            <div class="ilmuna-form-field">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label style="margin:0;">Pilih File Excel (.xlsx) <span class="req">*</span></label>
                    <button type="button" class="ilmuna-btn-link" id="btnModalDownloadTemplate" style="font-size:12px; color:#0D7A87; background:none; border:none; cursor:pointer; font-weight:600; display:inline-flex; align-items:center; gap:4px; padding:0;">
                        <iconify-icon icon="solar:file-download-bold" width="14"></iconify-icon> Unduh Format Excel
                    </button>
                </div>
                <input type="file" name="file" id="importFileInput" class="ilmuna-input" accept=".xlsx" required>
            </div>

            <div class="ilmuna-checkbox-group">
                <input type="checkbox" name="auto_presensi" id="importAutoPresensi" value="1" checked>
                <div class="ilmuna-checkbox-group__label">
                    <span class="ilmuna-checkbox-group__title">Otomatis Generate Kode Presensi Unik (1 Semester)</span>
                    <span class="ilmuna-checkbox-group__desc">Format kode: <code>(3 digit acak)NIS(4 digit acak)NISN(5 digit acak)</code> langsung dibuat untuk seluruh mapel kelas.</span>
                </div>
            </div>

            <div id="importLogBox" style="display:none; max-height:160px; overflow-y:auto; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px 14px; font-size:12px;"></div>
        </form>
        <div class="ilmuna-modal__foot">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnSubmitImport">
                <iconify-icon icon="solar:cloud-upload-bold" width="16"></iconify-icon>
                Mulai Import Data
            </button>
        </div>
    </div>
</div>

<!-- ==========================================================================
 * MODAL: PREVIEW FOTO SISWA (LIGHTBOX)
 * ========================================================================== -->
<div class="ilmuna-modal" id="modalPreviewFotoSiswa">
    <div class="ilmuna-modal__overlay" data-close="1"></div>
    <div class="ilmuna-modal__box" style="max-width: 440px; padding: 22px; text-align: center;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <iconify-icon icon="solar:user-bold" width="20" style="color: #0D7A87;"></iconify-icon>
                <h4 id="previewFotoSiswaTitle" style="margin: 0; font-size: 15px; font-weight: 700; color: #1D3B5E;">Foto Siswa</h4>
            </div>
            <button type="button" class="ilmuna-modal__close" data-close="1">&times;</button>
        </div>
        <div style="border-radius: 16px; overflow: hidden; background: #0F172A; border: 1.5px solid #E2E8F0; margin-bottom: 16px; box-shadow: 0 8px 24px rgba(15,29,47,0.12);">
            <img id="previewFotoSiswaImg" src="" alt="Foto Siswa" style="max-width: 100%; max-height: 420px; object-fit: contain; display: block; margin: 0 auto;">
        </div>
        <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1" style="width: 100%; justify-content: center;">
            Tutup Preview
        </button>
    </div>
</div>

<!-- ==========================================================================
 * CUSTOM CONFIRMATION OVERLAY
 * ========================================================================== -->
<div class="ilmuna-confirm-overlay" id="confirmOverlay">
    <div class="ilmuna-confirm-modal">
        <div class="ilmuna-confirm-icon" id="confirmIcon">
            <iconify-icon icon="solar:danger-triangle-bold"></iconify-icon>
        </div>
        <h4 class="ilmuna-confirm-title" id="confirmTitle">Konfirmasi</h4>
        <p class="ilmuna-confirm-message" id="confirmMessage">Apakah Anda yakin ingin melanjutkan tindakan ini?</p>
        <div class="ilmuna-confirm-actions">
            <button type="button" class="ilmuna-confirm-btn ilmuna-confirm-btn--cancel" id="btnConfirmCancel">Batal</button>
            <button type="button" class="ilmuna-confirm-btn ilmuna-confirm-btn--ok" id="btnConfirmOk">Lanjutkan</button>
        </div>
    </div>
</div>

<!-- ==========================================================================
 * TOAST NOTIFICATIONS WRAPPER
 * ========================================================================== -->
<div class="ilmuna-toast-wrap" id="toastWrap"></div>

<!-- ==========================================================================
 * CLIENT-SIDE JAVASCRIPT APPLICATION ENGINE
 * ========================================================================== -->
<script>
(function() {
    'use strict';

    var root = document.getElementById('dosenCrudSiswa');
    if (!root) return;

    var AJAX_URL  = root.dataset.ajax;
    var EXCEL_URL = root.dataset.excel;

    // Pindahkan elemen overlay dan modal ke document.body (mencegah clipping z-index)
    ['modalStudentForm', 'modalPresensiDetail', 'modalImportExcel', 'modalPreviewFotoSiswa', 'confirmOverlay', 'toastWrap', 'bulkActionBar'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode && el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    });

    // Application State
    var state = {
        id_kelas: 0,
        page: 1,
        per_page: 20,
        search: '',
        total: 0,
        pages: 1,
        loading: false,
        activeStudentId: 0
    };

    var selectedStudents = new Set();
    var cachedKelas = [];

    /* ==========================================================================
     * 1. TOAST NOTIFICATION HELPER
     * ========================================================================== */
    function toast(type, message) {
        var normType = (type === 'danger' || type === 'err' || type === 'error') ? 'error' : ((type === 'warn' || type === 'warning') ? 'warning' : (type === 'info' ? 'info' : 'success'));
        var titleMap = { success: 'Berhasil', error: 'Gagal', warning: 'Peringatan', info: 'Informasi' };
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            return Ilmuna.toast(normType, titleMap[normType] || 'Pemberitahuan', message, 4500);
        }
        var wrap = document.getElementById('toastWrap');
        if (!wrap) return;

        var icons = {
            success: 'solar:check-circle-bold',
            error:   'solar:danger-circle-bold',
            warning: 'solar:danger-triangle-bold',
            info:    'solar:info-circle-bold'
        };

        var el = document.createElement('div');
        el.className = 'ilmuna-toast ilmuna-toast--' + type;
        el.innerHTML = '<iconify-icon icon="' + (icons[type] || icons.info) + '" class="ilmuna-toast__icon"></iconify-icon>' +
                       '<div class="ilmuna-toast__msg">' + escapeHtml(message) + '</div>' +
                       '<button type="button" class="ilmuna-toast__close">&times;</button>';

        wrap.appendChild(el);
        requestAnimationFrame(function(){ el.classList.add('show'); });

        var t = setTimeout(function(){ closeToast(el); }, 4500);
        el.querySelector('.ilmuna-toast__close').addEventListener('click', function(){
            clearTimeout(t);
            closeToast(el);
        });
    }

    function closeToast(el) {
        el.classList.remove('show');
        setTimeout(function(){ el.remove(); }, 350);
    }

    /* ==========================================================================
     * 2. CUSTOM CONFIRM DIALOG PROMISE
     * ========================================================================== */
    function confirmDialog(message, options) {
        options = options || {};
        var type = (options.type === 'danger' || options.type === 'delete' || options.type === 'error') ? 'error' : (options.type || 'warning');
        if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
            return Ilmuna.confirm(type, options.title || 'Konfirmasi Tindakan', message || 'Apakah Anda yakin?', options.okText || 'Ya, Lanjutkan', options.cancelText || 'Batal');
        }
        return new Promise(function(resolve) {
            options = options || {};
            var overlay = document.getElementById('confirmOverlay');
            var titleEl = document.getElementById('confirmTitle');
            var msgEl   = document.getElementById('confirmMessage');
            var iconEl  = document.getElementById('confirmIcon');
            var okBtn   = document.getElementById('btnConfirmOk');
            var ccBtn   = document.getElementById('btnConfirmCancel');

            titleEl.textContent = options.title || 'Konfirmasi Tindakan';
            msgEl.textContent   = message || 'Apakah Anda yakin?';
            okBtn.textContent   = options.okText || 'Ya, Lanjutkan';

            // Reset icon class
            iconEl.className = 'ilmuna-confirm-icon';
            if (options.type === 'danger') {
                iconEl.classList.add('ilmuna-confirm-icon--danger');
                iconEl.innerHTML = '<iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon>';
                okBtn.className = 'ilmuna-confirm-btn ilmuna-confirm-btn--danger';
            } else if (options.type === 'emerald') {
                iconEl.classList.add('ilmuna-confirm-icon--emerald');
                iconEl.innerHTML = '<iconify-icon icon="solar:qr-code-bold"></iconify-icon>';
                okBtn.className = 'ilmuna-confirm-btn ilmuna-confirm-btn--emerald';
            } else {
                iconEl.innerHTML = '<iconify-icon icon="solar:danger-triangle-bold"></iconify-icon>';
                okBtn.className = 'ilmuna-confirm-btn ilmuna-confirm-btn--ok';
            }

            overlay.classList.add('show');

            function cleanup(result) {
                overlay.classList.remove('show');
                okBtn.removeEventListener('click', onOk);
                ccBtn.removeEventListener('click', onCancel);
                resolve(result);
            }

            function onOk()     { cleanup(true); }
            function onCancel() { cleanup(false); }

            okBtn.addEventListener('click', onOk);
            ccBtn.addEventListener('click', onCancel);
        });
    }

    /* ==========================================================================
     * 3. STRING & HTML SANITIZERS
     * ========================================================================== */
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /* ==========================================================================
     * 4. LOAD PILIHAN KELAS UNTUK FILTER & DROPDOWN MODAL
     * ========================================================================== */
    function loadKelasOptions() {
        var fd = new FormData();
        fd.append('action', 'list_kelas_options');

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (!res || !res.success) return;

                cachedKelas = res.data.kelas || [];
                var filterSelect = document.getElementById('filterKelasSiswa');
                var modalSelect  = document.getElementById('formStudentKelas');
                var importSelect = document.getElementById('importTargetKelas');
                var chipWrap     = document.getElementById('quickClassChips');

                // Update filter options
                var filterHtml = '<option value="0">— Pilih Rombel Kelas Terlebih Dahulu —</option>';
                var modalHtml  = '<option value="">— Pilih Kelas —</option>';
                var importHtml = '<option value="0">— Gunakan Kolom nama_kelas di File Excel —</option>';
                var chipsHtml  = '';

                cachedKelas.forEach(function(k) {
                    var label = k.nama_kelas + ' (' + k.tahun_pelajaran + ') — ' + k.total_siswa + ' Siswa';
                    filterHtml += '<option value="' + k.id + '">' + escapeHtml(label) + '</option>';
                    modalHtml  += '<option value="' + k.id + '">' + escapeHtml(k.nama_kelas + ' (' + k.tahun_pelajaran + ')') + '</option>';
                    importHtml += '<option value="' + k.id + '">' + escapeHtml(k.nama_kelas + ' (' + k.tahun_pelajaran + ')') + '</option>';

                    chipsHtml += '<button type="button" class="ilmuna-empty-chip" data-id="' + k.id + '">' +
                                 '<iconify-icon icon="solar:notebook-bookmark-bold" width="13"></iconify-icon> ' +
                                 escapeHtml(k.nama_kelas) + ' (' + k.total_siswa + ')</button>';
                });

                filterSelect.innerHTML = filterHtml;
                modalSelect.innerHTML  = modalHtml;
                importSelect.innerHTML = importHtml;
                if (chipWrap) chipWrap.innerHTML = chipsHtml;

                // Bind click quick chip
                if (chipWrap) {
                    chipWrap.querySelectorAll('.ilmuna-empty-chip').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            var cid = parseInt(btn.dataset.id, 10);
                            filterSelect.value = cid;
                            selectClass(cid);
                        });
                    });
                }

                // Update KPI stats (null-safe — KPI cards sudah dihapus dari view)
                if (res.data.stats) {
                    updateKpi(res.data.stats);
                }
            })
            .catch(function(err){
                console.error('Error load kelas options:', err);
            });
    }

    // Null-safe: aman dipanggil meskipun elemen KPI tidak ada di DOM
    function updateKpi(stats) {
        if (!stats) return;
        var elTotal   = document.getElementById('kpiTotalSiswa');
        var elKelasIni = document.getElementById('kpiSiswaKelasIni');
        var elPresensi = document.getElementById('kpiTotalPresensi');
        var elMapel   = document.getElementById('kpiMapelKelasIni');
        if (elTotal)    elTotal.textContent    = stats.total_siswa !== undefined ? stats.total_siswa : '-';
        if (elKelasIni) elKelasIni.textContent = stats.siswa_kelas_ini !== undefined ? stats.siswa_kelas_ini : '-';
        if (elPresensi) elPresensi.textContent = stats.total_presensi !== undefined ? stats.total_presensi : '-';
        if (elMapel)    elMapel.textContent    = stats.mapel_kelas_ini !== undefined ? stats.mapel_kelas_ini : '-';
    }

    /* ==========================================================================
     * 5. SELECT CLASS LOGIC & TOOLBAR TOGGLE
     * ========================================================================== */
    function selectClass(classId) {
        state.id_kelas = parseInt(classId, 10) || 0;
        state.page = 1;
        selectedStudents.clear();
        updateBulkBar();

        var btnAdd     = document.getElementById('btnOpenAddStudent');
        var btnExport  = document.getElementById('btnExportExcel');
        var btnPresCls = document.getElementById('btnGenerateClassPresensi');

        if (state.id_kelas > 0) {
            btnAdd.removeAttribute('disabled');
            btnAdd.title = 'Tambah siswa baru ke rombel ini';
            btnExport.removeAttribute('disabled');
            btnExport.title = 'Export data siswa rombel ini ke file Excel';
            btnPresCls.removeAttribute('disabled');
            btnPresCls.title = 'Generate kode presensi unik untuk seluruh siswa di rombel ini';
        } else {
            btnAdd.setAttribute('disabled', 'disabled');
            btnAdd.title = 'Pilih rombel kelas terlebih dahulu';
            btnExport.setAttribute('disabled', 'disabled');
            btnExport.title = 'Pilih rombel kelas terlebih dahulu untuk export';
            btnPresCls.setAttribute('disabled', 'disabled');
            btnPresCls.title = 'Pilih rombel kelas terlebih dahulu untuk generate presensi';
        }

        fetchList();
    }

    /* ==========================================================================
     * 6. FETCH & RENDER LIST SISWA
     * ========================================================================== */
    function fetchList() {
        var tbody = document.getElementById('tableSiswaBody');
        var bannerWrap = document.getElementById('classBannerWrap');

        // Jika belum ada kelas yang dipilih, tampilkan empty state yang ramah
        if (state.id_kelas <= 0) {
            bannerWrap.style.display = 'none';
            tbody.innerHTML = '<tr><td colspan="10">' +
                '<div class="ilmuna-empty-row">' +
                    '<div class="ilmuna-empty-row__icon"><iconify-icon icon="solar:notebook-bookmark-bold"></iconify-icon></div>' +
                    '<h4>Pilih Rombel / Kelas Terlebih Dahulu</h4>' +
                    '<p>Data siswa beserta kartu QR Code presensi akan ditampilkan secara otomatis setelah Anda memilih salah satu rombel kelas di atas.</p>' +
                    '<div class="ilmuna-empty-row__chips" id="quickClassChips"></div>' +
                '</div>' +
            '</td></tr>';

            // Re-render quick class chips jika cached
            if (cachedKelas.length > 0) {
                var cWrap = document.getElementById('quickClassChips');
                var cHtml = '';
                cachedKelas.forEach(function(k) {
                    cHtml += '<button type="button" class="ilmuna-empty-chip" data-id="' + k.id + '">' +
                             '<iconify-icon icon="solar:notebook-bookmark-bold" width="13"></iconify-icon> ' +
                             escapeHtml(k.nama_kelas) + ' (' + k.total_siswa + ')</button>';
                });
                cWrap.innerHTML = cHtml;
                cWrap.querySelectorAll('.ilmuna-empty-chip').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var cid = parseInt(btn.dataset.id, 10);
                        document.getElementById('filterKelasSiswa').value = cid;
                        selectClass(cid);
                    });
                });
            }

            document.getElementById('paginationInfo').textContent = 'Pilih rombel kelas di atas';
            document.getElementById('paginationBtns').innerHTML   = '';
            return;
        }

        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding: 40px 20px; color:#64748b;">' +
            '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="28"></iconify-icon>' +
            '<p style="margin-top:8px; font-weight:600;">Memuat data siswa rombel...</p>' +
        '</td></tr>';

        var fd = new FormData();
        fd.append('action', 'list');
        fd.append('id_kelas', state.id_kelas);
        fd.append('page', state.page);
        fd.append('per_page', state.per_page);
        fd.append('search', state.search);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (!res || !res.success) {
                    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding: 30px; color:#ef4444;">Gagal memuat data siswa.</td></tr>';
                    return;
                }

                var data = res.data;
                state.total = data.total;
                state.pages = data.pages;

                // Render Active Class Banner
                if (data.kelas_info) {
                    renderClassBanner(data.kelas_info);
                } else {
                    bannerWrap.style.display = 'none';
                }

                // Render Table Rows
                renderTableRows(data.rows);

                // Render Pagination
                renderPagination(data.total, data.page, data.per, data.pages);

                // Update KPI (null-safe)
                if (data.stats) updateKpi(data.stats);
            })
            .catch(function(err) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding: 30px; color:#ef4444;">Terjadi kesalahan jaringan. Silakan refresh halaman.</td></tr>';
            });
    }

    /* Render Class Banner Card */
    function renderClassBanner(k) {
        var wrap = document.getElementById('classBannerWrap');
        wrap.style.display = 'block';
        wrap.innerHTML = '<div class="ilmuna-class-banner">' +
            '<div class="ilmuna-class-banner__left">' +
                '<div class="ilmuna-class-banner__icon"><iconify-icon icon="solar:notebook-bookmark-bold"></iconify-icon></div>' +
                '<div class="ilmuna-class-banner__title">' +
                    '<h3>' + escapeHtml(k.nama_kelas) + '</h3>' +
                    '<p>Tahun Pelajaran: ' + escapeHtml(k.tahun_pelajaran) + '</p>' +
                '</div>' +
            '</div>' +
            '<div class="ilmuna-class-banner__meta">' +
                '<span class="ilmuna-meta-pill ilmuna-meta-pill--students"><iconify-icon icon="solar:users-group-rounded-bold" width="14"></iconify-icon> ' + k.total_siswa + ' Siswa Terdaftar</span>' +
                '<span class="ilmuna-meta-pill ilmuna-meta-pill--mapel"><iconify-icon icon="solar:book-bookmark-bold" width="14"></iconify-icon> ' + k.total_mapel + ' Mata Pelajaran</span>' +
            '</div>' +
        '</div>';
    }

    /* Render Table Rows */
    function renderTableRows(rows) {
        var tbody = document.getElementById('tableSiswaBody');
        var checkAll = document.getElementById('checkAllStudents');
        checkAll.checked = false;

        if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10">' +
                '<div class="ilmuna-empty-row">' +
                    '<div class="ilmuna-empty-row__icon"><iconify-icon icon="solar:user-id-bold"></iconify-icon></div>' +
                    '<h4>Belum Ada Siswa di Rombel Ini</h4>' +
                    '<p>Belum ada data siswa yang terdaftar pada rombel kelas ini. Anda dapat menambahkan siswa secara manual atau melalui Import Excel.</p>' +
                '</div>' +
            '</td></tr>';
            return;
        }

        var avatars = ['navy', 'teal', 'sky', 'gold'];
        var html = '';

        rows.forEach(function(s, idx) {
            var rowNum = (state.page - 1) * state.per_page + idx + 1;
            var isChecked = selectedStudents.has(s.id);
            var avColor = avatars[s.id % avatars.length];
            var inisial = getInitials(s.nama_lengkap);

            // Presensi Badge
            var presBadge = '';
            var totalPres = parseInt(s.total_presensi_aktif, 10) || 0;
            var totalMpl  = parseInt(s.total_mapel_kelas, 10) || 0;

            if (totalPres > 0 && totalPres >= totalMpl && totalMpl > 0) {
                presBadge = '<span class="ilmuna-badge ilmuna-badge--emerald" title="Kode presensi aktif untuk seluruh mapel"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> ' + totalPres + '/' + totalMpl + ' Mapel Aktif</span>';
            } else if (totalPres > 0) {
                presBadge = '<span class="ilmuna-badge ilmuna-badge--amber" title="Sebagian mapel belum memiliki kode presensi"><iconify-icon icon="solar:shield-warning-bold"></iconify-icon> ' + totalPres + '/' + totalMpl + ' Mapel</span>';
            } else {
                presBadge = '<span class="ilmuna-badge ilmuna-badge--gray" title="Belum pernah digenerate kode presensi"><iconify-icon icon="solar:close-circle-bold"></iconify-icon> Belum Ada</span>';
            }

            // WhatsApp link
            var waHtml = '-';
            if (s.no_hp) {
                var cleanPhone = s.no_hp.replace(/[^0-9]/g, '');
                if (cleanPhone.startsWith('0')) cleanPhone = '62' + cleanPhone.substr(1);
                waHtml = '<a href="https://wa.me/' + cleanPhone + '" target="_blank" style="color:#0D7A87; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:4px;">' +
                         '<iconify-icon icon="solar:chat-round-dots-bold" width="14"></iconify-icon> ' + escapeHtml(s.no_hp) + '</a>';
            }

            // Foto Siswa Cell
            var fotoCell = '';
            if (s.foto) {
                fotoCell = '<div class="guru-photo-wrap" onclick="previewFotoSiswa(\'../../' + escapeHtml(s.foto) + '\', \'' + escapeHtml(s.nama_lengkap.replace(/'/g, "\\'")) + '\')" title="Klik untuk perbesar foto">' +
                           '<img class="guru-photo-img" src="../../' + escapeHtml(s.foto) + '" alt="' + escapeHtml(s.nama_lengkap) + '" loading="lazy">' +
                           '</div>';
            } else {
                fotoCell = '<div class="guru-photo-wrap" style="cursor:default;" title="Belum ada foto">' +
                           '<div class="ilmuna-avatar ilmuna-avatar--' + avColor + '" style="width:100%;height:100%;border-radius:10px;font-size:13px;box-shadow:none;">' + escapeHtml(inisial) + '</div>' +
                           '</div>';
            }

            html += '<tr class="' + (isChecked ? 'is-selected' : '') + '" data-id="' + s.id + '">' +
                '<td style="text-align: center;">' +
                    '<input type="checkbox" class="ilmuna-bulk-cb row-cb" value="' + s.id + '" ' + (isChecked ? 'checked' : '') + '>' +
                '</td>' +
                '<td style="text-align: center; color: #64748b; font-weight: 700;">' + rowNum + '</td>' +
                '<td style="text-align: center;">' + fotoCell + '</td>' +
                '<td><span class="ilmuna-code-tag">' + escapeHtml(s.nisn) + '</span></td>' +
                '<td><span class="ilmuna-code-tag">' + escapeHtml(s.nis) + '</span></td>' +
                '<td>' +
                    '<div>' +
                        '<div class="ilmuna-user-cell__name">' + escapeHtml(s.nama_lengkap) + '</div>' +
                        '<div class="ilmuna-user-cell__sub">' +
                            (s.gmail ? '<iconify-icon icon="solar:letter-bold" width="12"></iconify-icon> ' + escapeHtml(s.gmail) : '<span style="color:#94a3b8;">Email belum diatur</span>') +
                        '</div>' +
                    '</div>' +
                '</td>' +
                '<td>' + waHtml + '</td>' +
                '<td>' + presBadge + '</td>' +
                '<td><span style="font-size:12px; color:#64748b;" title="' + escapeHtml(s.alamat || '-') + '">' + escapeHtml(truncate(s.alamat || '-', 25)) + '</span></td>' +
                '<td style="text-align: center;">' +
                    '<div class="ilmuna-action-btns" style="justify-content: center;">' +
                        '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--qr btn-action-qr" data-id="' + s.id + '" title="Lihat & Buat Kartu QR Presensi">' +
                            '<iconify-icon icon="solar:qr-code-bold"></iconify-icon>' +
                        '</button>' +
                        '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--edit btn-action-edit" data-id="' + s.id + '" title="Edit Siswa">' +
                            '<iconify-icon icon="solar:pen-new-square-bold"></iconify-icon>' +
                        '</button>' +
                        '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--delete btn-action-delete" data-id="' + s.id + '" data-name="' + escapeHtml(s.nama_lengkap) + '" title="Hapus Siswa">' +
                            '<iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon>' +
                        '</button>' +
                    '</div>' +
                '</td>' +
            '</tr>';
        });

        tbody.innerHTML = html;
        bindTableRowEvents();
    }

    function getInitials(name) {
        if (!name) return 'S';
        var parts = name.trim().split(/\s+/);
        var ini = parts[0] ? parts[0].charAt(0) : 'S';
        if (parts[1]) ini += parts[1].charAt(0);
        return ini.toUpperCase();
    }

    function truncate(str, len) {
        if (!str) return '';
        return str.length > len ? str.substr(0, len) + '...' : str;
    }

    /* Bind Checkbox & Action Click Events inside Table */
    function bindTableRowEvents() {
        var tbody = document.getElementById('tableSiswaBody');

        // Checkbox row toggle
        tbody.querySelectorAll('.row-cb').forEach(function(cb) {
            cb.addEventListener('change', function(e) {
                var sid = parseInt(cb.value, 10);
                var tr  = cb.closest('tr');
                if (cb.checked) {
                    selectedStudents.add(sid);
                    if (tr) tr.classList.add('is-selected');
                } else {
                    selectedStudents.delete(sid);
                    if (tr) tr.classList.remove('is-selected');
                }
                updateBulkBar();
            });
        });

        // Button QR Presensi
        tbody.querySelectorAll('.btn-action-qr').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var sid = parseInt(btn.dataset.id, 10);
                openPresensiModal(sid);
            });
        });

        // Button Edit
        tbody.querySelectorAll('.btn-action-edit').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var sid = parseInt(btn.dataset.id, 10);
                openEditStudentModal(sid);
            });
        });

        // Button Delete Single
        tbody.querySelectorAll('.btn-action-delete').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var sid = parseInt(btn.dataset.id, 10);
                var sname = btn.dataset.name || 'siswa ini';

                confirmDialog('Apakah Anda yakin ingin menghapus data siswa "' + sname + '"? Seluruh riwayat kode presensi juga akan dihapus.', {
                    title: 'Hapus Siswa',
                    type: 'danger',
                    okText: 'Hapus Sekarang'
                }).then(function(confirmed) {
                    if (!confirmed) return;

                    var fd = new FormData();
                    fd.append('action', 'delete');
                    fd.append('id', sid);

                    fetch(AJAX_URL, { method: 'POST', body: fd })
                        .then(function(r){ return r.json(); })
                        .then(function(res) {
                            if (!res || !res.success) {
                                toast('error', (res && res.data && res.data.message) || 'Gagal menghapus siswa.');
                                return;
                            }
                            toast('success', res.data.message);
                            selectedStudents.delete(sid);
                            updateBulkBar();
                            fetchList();
                        })
                        .catch(function(){
                            toast('error', 'Koneksi bermasalah.');
                        });
                });
            });
        });
    }

    /* Check All in Header */
    document.getElementById('checkAllStudents').addEventListener('change', function(e) {
        var checked = e.target.checked;
        document.querySelectorAll('#tableSiswaBody .row-cb').forEach(function(cb) {
            cb.checked = checked;
            var sid = parseInt(cb.value, 10);
            var tr = cb.closest('tr');
            if (checked) {
                selectedStudents.add(sid);
                if (tr) tr.classList.add('is-selected');
            } else {
                selectedStudents.delete(sid);
                if (tr) tr.classList.remove('is-selected');
            }
        });
        updateBulkBar();
    });

    function updateBulkBar() {
        var bar = document.getElementById('bulkActionBar');
        var countEl = document.getElementById('bulkSelectedCount');
        countEl.textContent = selectedStudents.size;

        if (selectedStudents.size > 0) {
            bar.classList.add('is-open');
        } else {
            bar.classList.remove('is-open');
        }
    }

    /* Bulk Cancel */
    document.getElementById('btnBulkCancel').addEventListener('click', function() {
        selectedStudents.clear();
        document.getElementById('checkAllStudents').checked = false;
        document.querySelectorAll('#tableSiswaBody .row-cb').forEach(function(cb) {
            cb.checked = false;
            var tr = cb.closest('tr');
            if (tr) tr.classList.remove('is-selected');
        });
        updateBulkBar();
    });

    /* Bulk Delete Action */
    document.getElementById('btnBulkDelete').addEventListener('click', function() {
        if (selectedStudents.size === 0) return;

        confirmDialog('Apakah Anda yakin ingin menghapus ' + selectedStudents.size + ' siswa terpilih beserta seluruh data kartu presensinya?', {
            title: 'Hapus Massal Siswa',
            type: 'danger',
            okText: 'Hapus ' + selectedStudents.size + ' Siswa'
        }).then(function(confirmed) {
            if (!confirmed) return;

            var fd = new FormData();
            fd.append('action', 'bulk_delete');
            Array.from(selectedStudents).forEach(function(id) {
                fd.append('ids[]', id);
            });

            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    if (!res || !res.success) {
                        toast('error', (res && res.data && res.data.message) || 'Gagal hapus massal.');
                        return;
                    }
                    toast('success', res.data.message);
                    selectedStudents.clear();
                    updateBulkBar();
                    fetchList();
                })
                .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
        });
    });

    /* Bulk Generate Presensi Action */
    document.getElementById('btnBulkPresensi').addEventListener('click', function() {
        if (selectedStudents.size === 0) return;

        confirmDialog('Generate kode presensi unik untuk ' + selectedStudents.size + ' siswa terpilih? Setiap siswa akan dibuatkan kode presensi (3 digit acak + NIS + 4 digit acak + NISN + 5 digit acak) berlaku 1 semester untuk setiap mata pelajaran.', {
            title: 'Generate Kode Presensi Massal',
            type: 'emerald',
            okText: 'Generate Sekarang'
        }).then(function(confirmed) {
            if (!confirmed) return;

            var fd = new FormData();
            fd.append('action', 'generate_presensi');
            Array.from(selectedStudents).forEach(function(id) {
                fd.append('student_ids[]', id);
            });

            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    if (!res || !res.success) {
                        toast('error', (res && res.data && res.data.message) || 'Gagal generate kode presensi.');
                        return;
                    }
                    toast('success', res.data.message);
                    selectedStudents.clear();
                    updateBulkBar();
                    fetchList();
                })
                .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
        });
    });

    /* ==========================================================================
     * 7. PAGINATION RENDERER
     * ========================================================================== */
    function renderPagination(total, page, per, pages) {
        var infoEl = document.getElementById('paginationInfo');
        var btnsEl = document.getElementById('paginationBtns');

        if (total === 0) {
            infoEl.textContent = 'Tidak ada siswa yang sesuai pencarian';
            btnsEl.innerHTML   = '';
            return;
        }

        var start = (page - 1) * per + 1;
        var end   = Math.min(page * per, total);
        infoEl.textContent = 'Menampilkan ' + start + ' - ' + end + ' dari ' + total + ' Siswa';

        if (pages <= 1) {
            btnsEl.innerHTML = '';
            return;
        }

        var html = '';
        // Previous Button
        html += '<button type="button" class="ilmuna-page-btn" data-page="' + (page - 1) + '" ' + (page <= 1 ? 'disabled' : '') + '>' +
                '<iconify-icon icon="solar:alt-arrow-left-bold"></iconify-icon></button>';

        // Pages numbers
        var range = [];
        var maxButtons = 5;
        var startPage = Math.max(1, page - 2);
        var endPage   = Math.min(pages, startPage + maxButtons - 1);
        if (endPage - startPage < maxButtons - 1) {
            startPage = Math.max(1, endPage - maxButtons + 1);
        }

        for (var p = startPage; p <= endPage; p++) {
            range.push(p);
        }

        if (startPage > 1) {
            html += '<button type="button" class="ilmuna-page-btn" data-page="1">1</button>';
            if (startPage > 2) html += '<span style="color:#94a3b8; padding:0 4px;">...</span>';
        }

        range.forEach(function(p) {
            html += '<button type="button" class="ilmuna-page-btn ' + (p === page ? 'is-active' : '') + '" data-page="' + p + '">' + p + '</button>';
        });

        if (endPage < pages) {
            if (endPage < pages - 1) html += '<span style="color:#94a3b8; padding:0 4px;">...</span>';
            html += '<button type="button" class="ilmuna-page-btn" data-page="' + pages + '">' + pages + '</button>';
        }

        // Next Button
        html += '<button type="button" class="ilmuna-page-btn" data-page="' + (page + 1) + '" ' + (page >= pages ? 'disabled' : '') + '>' +
                '<iconify-icon icon="solar:alt-arrow-right-bold"></iconify-icon></button>';

        btnsEl.innerHTML = html;

        btnsEl.querySelectorAll('.ilmuna-page-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var p = parseInt(btn.dataset.page, 10);
                if (p && p !== state.page && p >= 1 && p <= pages) {
                    state.page = p;
                    fetchList();
                }
            });
        });
    }

    /* ==========================================================================
     * 8. MODAL DETAIL KODE PRESENSI & LIVE QR CODE
     * ========================================================================== */
    function openPresensiModal(studentId) {
        state.activeStudentId = studentId;
        var body = document.getElementById('modalPresensiBody');
        openModal('modalPresensiDetail');
        body.innerHTML = '<div style="text-align:center; padding: 40px;"><iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="32"></iconify-icon><p style="margin-top:8px;">Memuat detail kode presensi siswa...</p></div>';

        var fd = new FormData();
        fd.append('action', 'get_student_presensi');
        fd.append('id_siswa', studentId);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (!res || !res.success) {
                    body.innerHTML = '<div style="color:#ef4444; padding:20px;">' + ((res && res.data && res.data.message) || 'Gagal memuat kartu presensi.') + '</div>';
                    return;
                }

                var st    = res.data.student;
                var items = res.data.items || [];

                var html = '';
                // Student Profile Header Card
                html += '<div class="ilmuna-presensi-student-card">' +
                    '<div class="ilmuna-avatar ilmuna-avatar--navy" style="width:48px; height:48px; font-size:16px;">' + escapeHtml(getInitials(st.nama_lengkap)) + '</div>' +
                    '<div style="flex:1;">' +
                        '<h4 style="margin:0 0 2px; font-size:16px; font-weight:800; color:#0f172a;">' + escapeHtml(st.nama_lengkap) + '</h4>' +
                        '<div style="font-size:12px; color:#475569; display:flex; gap:12px; flex-wrap:wrap;">' +
                            '<span>NISN: <strong>' + escapeHtml(st.nisn) + '</strong></span>' +
                            '<span>NIS: <strong>' + escapeHtml(st.nis) + '</strong></span>' +
                            '<span>Rombel: <strong>' + escapeHtml(st.nama_kelas) + '</strong></span>' +
                        '</div>' +
                    '</div>' +
                '</div>';

                html += '<div style="margin-top:14px; margin-bottom:4px; font-size:13px; font-weight:800; color:#1D3B5E; display:flex; justify-content:space-between; align-items:center;">' +
                    '<span>Daftar Kode Presensi Mata Pelajaran (Valid 1 Semester)</span>' +
                    '<span style="font-size:12px; font-weight:600; color:#64748b;">Total: ' + items.length + ' Mapel</span>' +
                '</div>';

                if (items.length === 0) {
                    html += '<div style="padding:20px; text-align:center; color:#64748b; background:#f8fafc; border-radius:12px;">' +
                        '<p>Rombel kelas ini belum memiliki pemetaan mata pelajaran di tabel <code>kelas_mata_pelajaran</code>.</p>' +
                    '</div>';
                } else {
                    html += '<div class="ilmuna-presensi-list">';
                    items.forEach(function(item) {
                        var code = item.id_presensi || '';
                        var hasCode = (code !== '');
                        var qrUrl = hasCode ? ('https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + encodeURIComponent(code)) : '';

                        html += '<div class="ilmuna-presensi-item">' +
                            '<div class="ilmuna-presensi-item__info">' +
                                '<div class="ilmuna-presensi-item__mapel">' + escapeHtml(item.nama_mata_pelajaran) + '</div>' +
                                '<div class="ilmuna-presensi-item__guru"><iconify-icon icon="solar:user-speak-bold" width="13"></iconify-icon> Guru Pengampu: ' + escapeHtml(item.nama_guru || 'Belum Ditetapkan') + '</div>' +
                                (hasCode ?
                                    ('<div>' +
                                        '<div class="ilmuna-code-box">' +
                                            '<span>' + escapeHtml(code) + '</span>' +
                                            '<button type="button" class="ilmuna-code-box__btn btn-copy-code" data-code="' + escapeHtml(code) + '" title="Salin Kode">' +
                                                '<iconify-icon icon="solar:copy-bold"></iconify-icon> Salin' +
                                            '</button>' +
                                        '</div>' +
                                        '<div style="font-size:11.5px; color:#64748b; margin-top:6px;">' +
                                            'Berlaku s.d: <strong style="color:#047857;">' + escapeHtml(item.expired_at || '1 Semester') + '</strong>' +
                                        '</div>' +
                                    '</div>') :
                                    ('<div style="font-size:12px; color:#b45309; font-weight:600;"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon> Kode presensi belum dibuat untuk mapel ini.</div>')
                                ) +
                            '</div>' +
                            '<div class="ilmuna-presensi-item__qr">' +
                                (hasCode ?
                                    ('<img src="' + qrUrl + '" alt="QR Code" class="ilmuna-qr-canvas" loading="lazy">' +
                                     '<span style="font-size:10.5px; color:#64748b; font-weight:700;">QR Absensi</span>') :
                                    ('<div style="width:80px; height:80px; border:1px dashed #cbd5e1; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#94a3b8; font-size:11px; text-align:center; padding:4px;">No QR</div>')
                                ) +
                            '</div>' +
                        '</div>';
                    });
                    html += '</div>';
                }

                body.innerHTML = html;

                // Bind copy code button
                body.querySelectorAll('.btn-copy-code').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var cd = btn.dataset.code;
                        if (navigator.clipboard) {
                            navigator.clipboard.writeText(cd).then(function() {
                                toast('success', 'Kode presensi berhasil disalin: ' + cd);
                            });
                        } else {
                            toast('info', 'Kode: ' + cd);
                        }
                    });
                });
            })
            .catch(function() {
                body.innerHTML = '<div style="color:#ef4444; padding:20px;">Gagal mengambil kartu presensi.</div>';
            });
    }

    // Regenerate Kode Presensi Siswa Ini
    document.getElementById('btnRegenerateStudentPresensi').addEventListener('click', function() {
        if (!state.activeStudentId) return;

        confirmDialog('Regenerate seluruh kode presensi siswa ini untuk setiap mata pelajaran? Kode lama akan diganti dengan format baru (3 digit acak + NIS + 4 digit acak + NISN + 5 digit acak) berlaku 1 semester.', {
            title: 'Regenerate Kode Presensi Siswa',
            type: 'emerald',
            okText: 'Regenerate Sekarang'
        }).then(function(confirmed) {
            if (!confirmed) return;

            var fd = new FormData();
            fd.append('action', 'generate_presensi');
            fd.append('id_siswa', state.activeStudentId);

            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    if (!res || !res.success) {
                        toast('error', (res && res.data && res.data.message) || 'Gagal regenerate.');
                        return;
                    }
                    toast('success', res.data.message);
                    openPresensiModal(state.activeStudentId);
                    fetchList();
                })
                .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
        });
    });

    /* ==========================================================================
     * 9. GENERATE PRESENSI SELURUH KELAS (TOOLBAR BUTTON)
     * ========================================================================== */
    document.getElementById('btnGenerateClassPresensi').addEventListener('click', function() {
        if (state.id_kelas <= 0) {
            toast('warning', 'Pilih rombel kelas terlebih dahulu.');
            return;
        }

        var kelasNama = document.getElementById('filterKelasSiswa').selectedOptions[0] ? document.getElementById('filterKelasSiswa').selectedOptions[0].text : 'kelas ini';

        confirmDialog('Generate kode presensi unik untuk SELURUH SISWA di ' + kelasNama + '? Kode presensi akan dibuat otomatis dengan format (3 digit acak)NIS(4 digit acak)NISN(5 digit acak) untuk setiap mata pelajaran dan berlaku 1 semester untuk absensi QR Code.', {
            title: 'Generate Kode Presensi Rombel',
            type: 'emerald',
            okText: 'Generate Seluruh Siswa'
        }).then(function(confirmed) {
            if (!confirmed) return;

            var fd = new FormData();
            fd.append('action', 'generate_presensi');
            fd.append('id_kelas', state.id_kelas);

            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    if (!res || !res.success) {
                        toast('error', (res && res.data && res.data.message) || 'Gagal generate kode presensi kelas.');
                        return;
                    }
                    toast('success', res.data.message);
                    fetchList();
                })
                .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
        });
    });

    /* ==========================================================================
     * 10. MODAL TAMBAH & EDIT SISWA & FOTO PROFILE
     * ========================================================================== */
    // Lightbox Preview Foto Siswa
    window.previewFotoSiswa = function(src, name) {
        var titleEl = document.getElementById('previewFotoSiswaTitle');
        var imgEl   = document.getElementById('previewFotoSiswaImg');
        if (titleEl) titleEl.textContent = 'Foto: ' + (name || 'Siswa');
        if (imgEl)   imgEl.src = src;
        openModal('modalPreviewFotoSiswa');
    };

    // Foto Siswa Upload Widget Listeners
    var btnBrowseFotoSiswa   = document.getElementById('btnBrowseFotoSiswa');
    var inputFotoSiswa       = document.getElementById('inputFotoSiswa');
    var btnRemoveFotoSiswa   = document.getElementById('btnRemoveFotoSiswa');
    var previewFotoSiswaEl   = document.getElementById('previewFotoSiswa');
    var inputDeleteFotoSiswa = document.getElementById('inputDeleteFotoSiswa');

    if (btnBrowseFotoSiswa && inputFotoSiswa) {
        btnBrowseFotoSiswa.addEventListener('click', function(){
            inputFotoSiswa.click();
        });
    }

    if (inputFotoSiswa) {
        inputFotoSiswa.addEventListener('change', function(e){
            var file = e.target.files && e.target.files[0];
            if (!file) return;

            // Validate file size (max 3MB)
            if (file.size > 3 * 1024 * 1024) {
                toast('warning', 'Ukuran file foto maksimal 3MB.');
                inputFotoSiswa.value = '';
                return;
            }

            // Validate format
            var validTypes = ['image/jpeg', 'image/png', 'image/webp'];
            if (validTypes.indexOf(file.type) === -1) {
                toast('warning', 'Format file harus JPG, PNG, atau WEBP.');
                inputFotoSiswa.value = '';
                return;
            }

            var reader = new FileReader();
            reader.onload = function(evt) {
                if (previewFotoSiswaEl) {
                    previewFotoSiswaEl.innerHTML = '<img src="' + evt.target.result + '" alt="Preview Foto">';
                }
                if (btnRemoveFotoSiswa)   btnRemoveFotoSiswa.style.display = 'inline-flex';
                if (inputDeleteFotoSiswa) inputDeleteFotoSiswa.value = '0';
            };
            reader.readAsDataURL(file);
        });
    }

    if (btnRemoveFotoSiswa) {
        btnRemoveFotoSiswa.addEventListener('click', function(){
            if (inputFotoSiswa) inputFotoSiswa.value = '';
            if (inputDeleteFotoSiswa) inputDeleteFotoSiswa.value = '1';
            if (previewFotoSiswaEl) {
                previewFotoSiswaEl.innerHTML = '<iconify-icon icon="solar:user-bold" width="34" style="color: #94A3B8;"></iconify-icon>';
            }
            btnRemoveFotoSiswa.style.display = 'none';
        });
    }

    document.getElementById('btnOpenAddStudent').addEventListener('click', function() {
        if (state.id_kelas <= 0) {
            toast('warning', 'Pilih rombel kelas terlebih dahulu.');
            return;
        }

        var form = document.getElementById('formStudent');
        form.reset();
        document.getElementById('formStudentId').value = 0;
        document.getElementById('formStudentKelas').value = state.id_kelas;

        // Reset Foto Upload State
        if (inputDeleteFotoSiswa) inputDeleteFotoSiswa.value = '0';
        if (inputFotoSiswa)       inputFotoSiswa.value = '';
        if (previewFotoSiswaEl)   previewFotoSiswaEl.innerHTML = '<iconify-icon icon="solar:user-bold" width="34" style="color: #94A3B8;"></iconify-icon>';
        if (btnRemoveFotoSiswa)   btnRemoveFotoSiswa.style.display = 'none';

        document.getElementById('modalStudentTitle').innerHTML = '<iconify-icon icon="solar:user-plus-bold" width="20"></iconify-icon> <span>Tambah Siswa Baru</span>';
        document.getElementById('wrapCreateAccount').style.display = 'flex';
        openModal('modalStudentForm');
    });

    function openEditStudentModal(studentId) {
        var fd = new FormData();
        fd.append('action', 'get_single');
        fd.append('id', studentId);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal mengambil data siswa.');
                    return;
                }

                var row = res.data.row;
                document.getElementById('formStudentId').value    = row.id;
                document.getElementById('formStudentKelas').value = row.id_kelas;
                document.getElementById('formStudentNisn').value  = row.nisn;
                document.getElementById('formStudentNis').value   = row.nis;
                document.getElementById('formStudentNama').value  = row.nama_lengkap;
                document.getElementById('formStudentHp').value    = row.no_hp || '';
                document.getElementById('formStudentGmail').value = row.gmail || '';
                document.getElementById('formStudentAlamat').value= row.alamat || '';

                // Populate Foto Siswa
                if (inputDeleteFotoSiswa) inputDeleteFotoSiswa.value = '0';
                if (inputFotoSiswa)       inputFotoSiswa.value = '';
                if (row.foto) {
                    if (previewFotoSiswaEl) previewFotoSiswaEl.innerHTML = '<img src="../../' + escapeHtml(row.foto) + '" alt="Foto Siswa">';
                    if (btnRemoveFotoSiswa) btnRemoveFotoSiswa.style.display = 'inline-flex';
                } else {
                    if (previewFotoSiswaEl) previewFotoSiswaEl.innerHTML = '<iconify-icon icon="solar:user-bold" width="34" style="color: #94A3B8;"></iconify-icon>';
                    if (btnRemoveFotoSiswa) btnRemoveFotoSiswa.style.display = 'none';
                }

                document.getElementById('modalStudentTitle').innerHTML = '<iconify-icon icon="solar:pen-new-square-bold" width="20"></iconify-icon> <span>Edit Data Siswa</span>';
                document.getElementById('wrapCreateAccount').style.display = 'none'; // Sembunyikan buat akun saat edit
                openModal('modalStudentForm');
            })
            .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
    }

    // Save Student Submit
    document.getElementById('btnSaveStudent').addEventListener('click', function() {
        var form = document.getElementById('formStudent');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        var fd = new FormData(form);
        fd.append('action', 'save');

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal menyimpan siswa.');
                    return;
                }
                toast('success', res.data.message);
                closeModal('modalStudentForm');
                fetchList();
            })
            .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
    });

    /* ==========================================================================
     * 11. MODAL IMPORT EXCEL
     * ========================================================================== */
    document.getElementById('btnOpenImport').addEventListener('click', function() {
        var form = document.getElementById('formImportExcel');
        form.reset();
        document.getElementById('importLogBox').style.display = 'none';
        document.getElementById('importLogBox').innerHTML = '';
        if (state.id_kelas > 0) {
            document.getElementById('importTargetKelas').value = state.id_kelas;
        }
        openModal('modalImportExcel');
    });

    document.getElementById('btnSubmitImport').addEventListener('click', function() {
        var fileInput = document.getElementById('importFileInput');
        if (!fileInput.files || fileInput.files.length === 0) {
            toast('warning', 'Pilih berkas Excel (.xlsx) terlebih dahulu.');
            return;
        }

        var form = document.getElementById('formImportExcel');
        var fd = new FormData(form);
        fd.append('action', 'import_excel');

        var submitBtn = document.getElementById('btnSubmitImport');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin"></iconify-icon> Memproses Import...';

        fetch(EXCEL_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<iconify-icon icon="solar:cloud-upload-bold" width="16"></iconify-icon> Mulai Import Data';

                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal import berkas Excel.');
                    return;
                }

                toast('success', res.data.message);

                // Tampilkan log jika ada
                if (res.data.log && res.data.log.length > 0) {
                    var logBox = document.getElementById('importLogBox');
                    logBox.style.display = 'block';
                    var logHtml = '';
                    res.data.log.forEach(function(l) {
                        var col = l[1] === 'ok' ? '#047857' : (l[1] === 'skip' ? '#b45309' : '#dc2626');
                        logHtml += '<div style="color:' + col + '; margin-bottom:3px;"><strong>Baris ' + l[0] + ':</strong> ' + escapeHtml(l[2]) + '</div>';
                    });
                    logBox.innerHTML = logHtml;
                }

                loadKelasOptions();
                fetchList();
            })
            .catch(function() {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<iconify-icon icon="solar:cloud-upload-bold" width="16"></iconify-icon> Mulai Import Data';
                toast('error', 'Koneksi bermasalah saat import.');
            });
    });

    /* ==========================================================================
     * 12. EXPORT EXCEL & DOWNLOAD TEMPLATE (JAVASCRIPT BLOB METHOD)
     * ========================================================================== */
    function downloadBlob(url, fallbackFilename, btnElement, loadingText) {
        var origHtml = btnElement ? btnElement.innerHTML : '';
        if (btnElement) {
            btnElement.disabled = true;
            btnElement.innerHTML = '<iconify-icon icon="solar:spinner-bold" class="ilmuna-spin" width="16"></iconify-icon> ' + (loadingText || 'Mengunduh...');
        }
        toast('info', 'Mempersiapkan unduhan file Excel...');

        return fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res) {
            if (!res.ok) {
                return res.text().then(function(t) {
                    var msg = 'Gagal mengunduh berkas (HTTP ' + res.status + ')';
                    try {
                        var j = JSON.parse(t);
                        if (j && j.data && j.data.message) msg = j.data.message;
                    } catch(e) {}
                    throw new Error(msg);
                });
            }
            var disposition = res.headers.get('Content-Disposition') || '';
            var filename = fallbackFilename;
            if (disposition && disposition.indexOf('filename=') !== -1) {
                var matches = disposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
                if (matches && matches[1]) {
                    filename = matches[1].replace(/['"]/g, '').trim();
                }
            }
            return res.blob().then(function(blob) {
                return { blob: blob, filename: filename };
            });
        })
        .then(function(data) {
            var blobUrl = window.URL.createObjectURL(data.blob);
            var a = document.createElement('a');
            a.style.display = 'none';
            a.href = blobUrl;
            a.download = data.filename;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(blobUrl);
            document.body.removeChild(a);

            toast('success', 'Berkas Excel berhasil diunduh: ' + data.filename);
        })
        .catch(function(err) {
            toast('error', err.message || 'Gagal mengunduh file Excel.');
        })
        .finally(function() {
            if (btnElement) {
                btnElement.disabled = false;
                btnElement.innerHTML = origHtml;
            }
        });
    }

    var btnDownloadTpl = document.getElementById('btnDownloadTemplate');
    if (btnDownloadTpl) {
        btnDownloadTpl.addEventListener('click', function(e) {
            e.preventDefault();
            downloadBlob(EXCEL_URL + '?action=download_template', 'template-import-siswa-ilmuna.xlsx', this, 'Mengunduh Format...');
        });
    }

    var btnModalDownloadTpl = document.getElementById('btnModalDownloadTemplate');
    if (btnModalDownloadTpl) {
        btnModalDownloadTpl.addEventListener('click', function(e) {
            e.preventDefault();
            downloadBlob(EXCEL_URL + '?action=download_template', 'template-import-siswa-ilmuna.xlsx', this, 'Mengunduh...');
        });
    }

    var btnExport = document.getElementById('btnExportExcel');
    if (btnExport) {
        btnExport.addEventListener('click', function(e) {
            e.preventDefault();
            if (state.id_kelas <= 0) {
                toast('warning', 'Pilih rombel kelas terlebih dahulu untuk export data.');
                return;
            }
            var url = EXCEL_URL + '?action=export_excel&id_kelas=' + state.id_kelas;
            if (state.search) url += '&search=' + encodeURIComponent(state.search);
            downloadBlob(url, 'data-siswa-ilmuna.xlsx', this, 'Mengekspor...');
        });
    }

    /* ==========================================================================
     * 13. WHATSAPP-STYLE REACTIVE LIVE SEARCH & FILTERS
     * ========================================================================== */
    var searchInputSiswa = document.getElementById('searchSiswa');
    var searchClearBtn   = document.getElementById('btnClearSearch');
    var searchBoxSiswa   = searchInputSiswa ? searchInputSiswa.closest('.ilmuna-search-box') : null;
    var searchDebounceTimer = null;

    if (searchInputSiswa) {
        // Setiap 1 huruf diketik langsung mencari (WhatsApp live search)
        searchInputSiswa.addEventListener('input', function() {
            var val = this.value;

            // Tampilkan tombol X untuk clear jika ada teks
            if (searchClearBtn) {
                searchClearBtn.style.display = val.length > 0 ? 'flex' : 'none';
            }

            if (searchBoxSiswa) searchBoxSiswa.classList.add('is-searching');
            clearTimeout(searchDebounceTimer);

            // Debounce super cepat 130ms persis WA
            searchDebounceTimer = setTimeout(function() {
                state.search = val.trim();
                state.page = 1;
                fetchList();
                if (searchBoxSiswa) searchBoxSiswa.classList.remove('is-searching');
            }, 130);
        });

        // Tekan Enter langsung cari seketika
        searchInputSiswa.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(searchDebounceTimer);
                state.search = this.value.trim();
                state.page = 1;
                fetchList();
                if (searchBoxSiswa) searchBoxSiswa.classList.remove('is-searching');
            }
        });
    }

    // Tombol X Clear Search
    if (searchClearBtn) {
        searchClearBtn.addEventListener('click', function(e) {
            e.preventDefault();
            searchInputSiswa.value = '';
            searchClearBtn.style.display = 'none';
            state.search = '';
            state.page = 1;
            fetchList();
            searchInputSiswa.focus();
        });
    }

    // Filter Kelas: langsung ganti seketika
    document.getElementById('filterKelasSiswa').addEventListener('change', function(e) {
        selectClass(this.value);
    });

    // Filter Per Halaman: langsung ganti seketika
    document.getElementById('perPageSiswa').addEventListener('change', function(e) {
        state.per_page = parseInt(this.value, 10) || 20;
        state.page = 1;
        fetchList();
    });

    document.getElementById('btnSearchSiswa').addEventListener('click', function() {
        clearTimeout(searchDebounceTimer);
        state.search = searchInputSiswa.value.trim();
        state.page = 1;
        fetchList();
    });

    document.getElementById('btnResetSiswa').addEventListener('click', function() {
        clearTimeout(searchDebounceTimer);
        if (searchInputSiswa) searchInputSiswa.value = '';
        if (searchClearBtn) searchClearBtn.style.display = 'none';
        state.search = '';
        state.page = 1;
        fetchList();
    });

    /* ==========================================================================
     * 14. UNIVERSAL MODAL OPEN & CLOSE ENGINE (GUARANTEED CLICKABLE)
     * ========================================================================== */
    function openModal(idOrEl) {
        var m = (typeof idOrEl === 'string') ? document.getElementById(idOrEl) : idOrEl;
        if (!m) return;
        m.style.display = 'flex';
        void m.offsetWidth; // Force reflow
        m.classList.add('show');
        m.classList.add('is-open');
    }

    function closeModal(idOrEl) {
        var m = (typeof idOrEl === 'string') ? document.getElementById(idOrEl) : idOrEl;
        if (!m) return;
        m.classList.remove('show');
        m.classList.remove('is-open');
        setTimeout(function() {
            if (!m.classList.contains('show') && !m.classList.contains('is-open')) {
                m.style.display = 'none';
            }
        }, 220);
    }

    // Global Event Delegation for ALL Close Triggers (X button, Batal, Tutup, Overlay)
    document.addEventListener('click', function(e) {
        var closeTrigger = e.target.closest('[data-close="1"], .ilmuna-modal__close, .ilmuna-confirm-btn--cancel');
        if (closeTrigger) {
            e.preventDefault();
            e.stopPropagation();
            var modal = closeTrigger.closest('.ilmuna-modal, .ilmuna-confirm-overlay');
            if (modal) {
                closeModal(modal);
            } else {
                document.querySelectorAll('.ilmuna-modal.show, .ilmuna-modal.is-open, .ilmuna-confirm-overlay.show, .ilmuna-confirm-overlay.is-open').forEach(function(m) {
                    closeModal(m);
                });
            }
            return;
        }

        if (e.target.classList.contains('ilmuna-modal__overlay') || e.target.classList.contains('ilmuna-modal')) {
            var m = e.target.closest('.ilmuna-modal');
            if (m) closeModal(m);
        }
    });

    // Escape key closes open modals
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.ilmuna-modal.show, .ilmuna-modal.is-open').forEach(function(m) {
                closeModal(m);
            });
            var confirmOv = document.getElementById('confirmOverlay');
            if (confirmOv && (confirmOv.classList.contains('show') || confirmOv.classList.contains('is-open'))) {
                var cancelBtn = document.getElementById('btnConfirmCancel');
                if (cancelBtn) cancelBtn.click();
            }
        }
    });

    // Make openModal and closeModal globally accessible if needed
    window.ilmunaOpenModal  = openModal;
    window.ilmunaCloseModal = closeModal;

    // Initial Load: Pilihan Kelas
    loadKelasOptions();
})();
</script>

<?php
require_once BASE_PATH . '/includes/footer.php';