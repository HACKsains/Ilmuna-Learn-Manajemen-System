<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — DATA GURU & PENDIDIK MANAGEMENT
 * ==============================================================================
 * Sesuai Skema Database: skema-tabel-kelas.sql (Tabel: guru, users, kelas_mata_pelajaran, kelas, mata_pelajaran)
 * Fitur: Lazy Load AJAX, Pencarian, Filter Status Kepegawaian & Gender, Detail Jadwal/Penugasan Mengajar,
 *        Tambah/Edit Manual, Unduh Template, Import/Export Excel (.xlsx) Native
 * Tema Warna: Lumina Gold (Navy #1D3B5E, Teal #0D7A87, Sky #52B2DC, Gold #F59E0B)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole('admin');

// Delegasi langsung untuk aksi Excel ke backend terpisah (teachers-excel.php)
if (isset($_GET['action']) && in_array($_GET['action'], ['download_template', 'export_excel'], true)) {
    require_once __DIR__ . '/teachers-excel.php';
    exit;
}

$pdo = getDBConnection();
$pageTitle = 'Data Guru & Tenaga Pendidik';
$pageIcon = 'solar:user-speak-bold';


if (!function_exists('getGuruStats')) {
    function getGuruStats($pdo) {
        return [
            'total' => (int)$pdo->query("SELECT COUNT(*) FROM guru")->fetchColumn(),
            'pns'   => (int)$pdo->query("SELECT COUNT(*) FROM guru WHERE status_kepegawaian = 'PNS'")->fetchColumn(),
            'pppk'  => (int)$pdo->query("SELECT COUNT(*) FROM guru WHERE status_kepegawaian = 'PPPK'")->fetchColumn(),
            'gtt'   => (int)$pdo->query("SELECT COUNT(*) FROM guru WHERE status_kepegawaian IN ('GTT', 'Tetap Yayasan', 'Honorer', 'Tetap')")->fetchColumn(),
            'akun'  => (int)$pdo->query("SELECT COUNT(*) FROM guru WHERE id_user IS NOT NULL")->fetchColumn()
        ];
    }
}

/**
 * Singkatan Resmi Guru & Tenaga Pendidik
 */
function ilmuna_abbreviate_teacher(string $nama): string {
    // 1. Bersihkan tanda dalam kurung, e.g. (Super Admin), (Akun Demo)
    $namaClean = trim(preg_replace('/\(.*?\)/', '', $nama));

    // 2. Bersihkan gelar belakang setelah koma, e.g. , M.Pd.I, , S.Ag
    $namaClean = preg_replace('/,.*$/', '', $namaClean);

    // 3. Bersihkan gelar depan & belakang umum
    $prefixes = [
        '/\b(ustadzah|ustadz|ustz|ust|k\.h\.|kh\.|kh|kyai|habib|drs\.|drs|dra\.|dra|prof\.|prof|dr\.|dr|ir\.|ir|h\.|hj\.)\b/i',
        '/\b(s\.pd|s\.pd\.i|m\.pd|m\.pd\.i|s\.ag|m\.a|s\.kom|m\.kom|s\.t|m\.t|s\.e|m\.m|s\.sos|s\.si|m\.si|s\.h|lc)\b/i'
    ];
    $namaClean = preg_replace($prefixes, '', $namaClean);
    $namaClean = trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $namaClean));

    $words = preg_split('/\s+/', $namaClean, -1, PREG_SPLIT_NO_EMPTY);
    $stopwords = ['dan', 'bin', 'binti', '&', 'al', 'ibn'];
    $filtered = [];
    foreach ($words as $w) {
        if (!in_array(strtolower($w), $stopwords, true)) {
            $filtered[] = $w;
        }
    }

    if (count($filtered) >= 3) {
        return strtoupper(substr($filtered[0], 0, 1) . substr($filtered[1], 0, 1) . substr($filtered[2], 0, 1));
    } elseif (count($filtered) === 2) {
        $w1 = strtoupper($filtered[0]);
        $w2 = strtoupper($filtered[1]);
        $c2 = preg_replace('/[AEIOU]/', '', substr($w2, 1));
        if (strlen($c2) >= 1) {
            return substr($w1, 0, 1) . substr($w2, 0, 1) . substr($c2, 0, 1);
        }
        return substr($w1, 0, 1) . substr($w2, 0, 2);
    } elseif (count($filtered) === 1) {
        $w = strtoupper($filtered[0]);
        if (strlen($w) <= 3) return $w;
        $vowelsRemoved = $w[0] . preg_replace('/[AEIOU]/', '', substr($w, 1));
        if (strlen($vowelsRemoved) >= 3) {
            return substr($vowelsRemoved, 0, 3);
        }
        return substr($w, 0, 3);
    }

    return 'GRU';
}

/**
 * Generator Kode Guru Otomatis
 * Format: (singkatan)-(2angkarandom)(idguru)(3angkarandom)
 */
function ilmuna_generate_teacher_code(PDO $pdo, string $namaGuru, int $idGuru = 0): string {
    $singkatan = ilmuna_abbreviate_teacher($namaGuru);

    if ($idGuru <= 0) {
        try {
            $st = $pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guru'");
            $nextId = (int)$st->fetchColumn();
            if ($nextId <= 0) {
                $st = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM guru");
                $nextId = (int)$st->fetchColumn();
            }
            $idGuru = $nextId > 0 ? $nextId : 1;
        } catch (Throwable $e) {
            $idGuru = 1;
        }
    }

    $chk = $pdo->prepare("SELECT id FROM guru WHERE kode_guru = ? AND id != ? LIMIT 1");

    for ($i = 0; $i < 10; $i++) {
        $rand2 = str_pad((string)random_int(10, 99), 2, '0', STR_PAD_LEFT);
        $rand3 = str_pad((string)random_int(100, 999), 3, '0', STR_PAD_LEFT);
        $kode = "{$singkatan}-{$rand2}{$idGuru}{$rand3}";

        $chk->execute([$kode, $idGuru]);
        if (!$chk->fetchColumn()) {
            return $kode;
        }
    }

    return "{$singkatan}-" . random_int(10, 99) . $idGuru . random_int(100, 999);
}

/* ==============================================================================
 * 4. AJAX API ENDPOINTS
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['action'];

    // 4.0 GENERATE KODE GURU OTOMATIS: (singkatan)-(2random)(id)(3random)
    if ($act === 'generate_code') {
        $nama_guru = trim($_POST['nama_guru'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $kode = ilmuna_generate_teacher_code($pdo, $nama_guru, $id);
        echo json_encode(['success' => true, 'data' => ['kode_guru' => $kode]]);
        exit;
    }

    // 4.1 LIST GURU (LAZY LOAD + SEARCH + FILTERS + PAGINATION)
    if ($act === 'list') {
        $search   = trim($_POST['search'] ?? '');
        $f_status = trim($_POST['f_status'] ?? '');
        $f_gender = trim($_POST['f_gender'] ?? '');
        $page     = max(1, (int)($_POST['page'] ?? 1));
        $per      = max(5, min(100, (int)($_POST['per_page'] ?? 20)));
        $offset   = ($page - 1) * $per;

        $where = '1=1';
        $params = [];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= ' AND (g.nama_guru LIKE ? OR g.nip LIKE ? OR g.nuptk LIKE ? OR g.email LIKE ?)';
            $params = array_merge($params, [$like, $like, $like, $like]);
        }
        if ($f_status !== '') {
            $where .= ' AND g.status_kepegawaian = ?';
            $params[] = $f_status;
        }
        if ($f_gender !== '') {
            $where .= ' AND g.jenis_kelamin = ?';
            $params[] = $f_gender;
        }

        // Count total
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM guru g WHERE {$where}");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        $sql = "SELECT g.id, g.id_user, g.nama_guru, g.kode_guru, g.nip, g.nuptk, g.jenis_kelamin, g.no_hp, g.email, g.status_kepegawaian, g.alamat, g.foto,
                    (SELECT COUNT(*) FROM kelas_mata_pelajaran kmp JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id WHERE gmp.id_guru = g.id) AS total_mengajar,
                    (SELECT GROUP_CONCAT(mp.nama_mata_pelajaran SEPARATOR '||') 
                     FROM guru_mata_pelajaran gmp 
                     JOIN mata_pelajaran mp ON gmp.id_mata_pelajaran = mp.id 
                     WHERE gmp.id_guru = g.id) AS mapel_diampu,
                    (SELECT COUNT(*) FROM guru_mata_pelajaran gmp WHERE gmp.id_guru = g.id) AS total_mapel_diampu,
                    u.username AS akun_username, u.status AS akun_status
                FROM guru g
                LEFT JOIN users u ON g.id_user = u.id
                WHERE {$where}
                ORDER BY g.nama_guru ASC
                LIMIT {$per} OFFSET {$offset}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => [
                'rows'  => $rows ?: [],
                'total' => $total,
                'page'  => $page,
                'per'   => $per,
                'pages' => max(1, (int)ceil($total / $per)),
                'stats' => getGuruStats($pdo)
            ]
        ]);
        exit;
    }

    // 4.2 GET SINGLE GURU (FOR EDIT)
    if ($act === 'get_single') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID guru tidak valid.']]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT g.*, u.username as user_username, u.email as user_email
            FROM guru g 
            LEFT JOIN users u ON g.id_user = u.id 
            WHERE g.id = ? LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Data guru tidak ditemukan.']]);
            exit;
        }

        echo json_encode(['success' => true, 'data' => ['row' => $row]]);
        exit;
    }

    // 4.3 SAVE / ADD MANUAL / EDIT GURU
    if ($act === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $nama_guru  = trim($_POST['nama_guru'] ?? '');
        $kode_guru  = strtoupper(trim($_POST['kode_guru'] ?? ''));
        $nip        = trim($_POST['nip'] ?? '');
        $nuptk      = trim($_POST['nuptk'] ?? '');
        $jk         = in_array($_POST['jenis_kelamin'] ?? 'L', ['L', 'P']) ? $_POST['jenis_kelamin'] : 'L';
        $no_hp      = trim($_POST['no_hp'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $status_peg = trim($_POST['status_kepegawaian'] ?? 'Tetap');
        $alamat     = trim($_POST['alamat'] ?? '');
        $createAcc  = !empty($_POST['create_account']) && $_POST['create_account'] == '1';

        if ($nama_guru === '') {
            echo json_encode(['success' => false, 'data' => ['message' => 'Nama lengkap dan gelar guru wajib diisi.']]);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $id_user = null;
            if ($id > 0) {
                $id_user = $pdo->query("SELECT id_user FROM guru WHERE id = " . $id)->fetchColumn();
            }

            // Buat akun login jika diminta dan belum ada
            if ($createAcc && empty($id_user)) {
                $baseUser = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode(' ', $nama_guru)[0] ?? 'guru'));
                if (strlen($baseUser) < 3) $baseUser = 'guru_' . time();
                $finalUser = $baseUser;
                $chkUser = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $chkUser->execute([$finalUser]);
                $suffix = 1;
                while ($chkUser->fetch()) {
                    $finalUser = $baseUser . $suffix++;
                    $chkUser->execute([$finalUser]);
                }

                $finalEmail = $email ?: ($finalUser . '@sekolah.id');
                $chkEmail = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $chkEmail->execute([$finalEmail]);
                if ($chkEmail->fetch()) {
                    $finalEmail = $finalUser . '_' . time() . '@sekolah.id';
                }

                $passHash = password_hash('guru123', PASSWORD_BCRYPT);
                $stmtInsUser = $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, full_name, nisn_nip, gender, phone, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $stmtInsUser->execute([$finalUser, $finalEmail, $passHash, $nama_guru, $nip, $jk, $no_hp]);
                $id_user = (int)$pdo->lastInsertId();

                // Role guru / admin guru
                $stmtRole = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id, is_primary) VALUES (?, 1, 1)");
                $stmtRole->execute([$id_user]);
            }

            // Upload Foto Guru jika ada
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
                            $targetDir = __DIR__ . '/../../uploads/teachers/';
                            if (!is_dir($targetDir)) {
                                @mkdir($targetDir, 0755, true);
                            }
                            $safeName = 'guru_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
                            if (move_uploaded_file($tmpName, $targetDir . $safeName)) {
                                $uploadedFoto = 'uploads/teachers/' . $safeName;
                            }
                        }
                    }
                }
            }

            if ($id > 0) {
                $oldFoto = $pdo->query("SELECT foto FROM guru WHERE id = " . $id)->fetchColumn();
                $finalFoto = $oldFoto;
                if ($uploadedFoto !== null) {
                    $finalFoto = $uploadedFoto;
                    if ($oldFoto && file_exists(__DIR__ . '/../../' . $oldFoto) && strpos($oldFoto, 'uploads/teachers/') === 0) {
                        @unlink(__DIR__ . '/../../' . $oldFoto);
                    }
                } elseif ($deleteFoto) {
                    $finalFoto = null;
                    if ($oldFoto && file_exists(__DIR__ . '/../../' . $oldFoto) && strpos($oldFoto, 'uploads/teachers/') === 0) {
                        @unlink(__DIR__ . '/../../' . $oldFoto);
                    }
                }

                if ($kode_guru === '' || strpos($kode_guru, 'OTOMATIS') !== false) {
                    $oldKode = $pdo->query("SELECT kode_guru FROM guru WHERE id = " . $id)->fetchColumn();
                    $kode_guru = $oldKode ?: ilmuna_generate_teacher_code($pdo, $nama_guru, $id);
                }

                $stmt = $pdo->prepare("
                    UPDATE guru 
                    SET nama_guru = ?, kode_guru = ?, nip = ?, nuptk = ?, jenis_kelamin = ?, no_hp = ?, email = ?, status_kepegawaian = ?, alamat = ?, foto = ?, id_user = COALESCE(?, id_user)
                    WHERE id = ?
                ");
                $stmt->execute([$nama_guru, $kode_guru, $nip ?: null, $nuptk ?: null, $jk, $no_hp ?: null, $email ?: null, $status_peg, $alamat ?: null, $finalFoto, $id_user, $id]);
                $msg = 'Data guru berhasil diperbarui.';
                $mode = 'update';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO guru (nama_guru, nip, nuptk, jenis_kelamin, no_hp, email, status_kepegawaian, alamat, foto, id_user)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$nama_guru, $nip ?: null, $nuptk ?: null, $jk, $no_hp ?: null, $email ?: null, $status_peg, $alamat ?: null, $uploadedFoto, $id_user]);
                $id = (int)$pdo->lastInsertId();

                // Generate dan simpan kode_guru permanen format: (singkatan)-(2acak)(id)(3acak)
                try {
                    $finalKodeGuru = ilmuna_generate_teacher_code($pdo, $nama_guru, $id);
                    $pdo->prepare("UPDATE guru SET kode_guru = ? WHERE id = ?")->execute([$finalKodeGuru, $id]);
                } catch (Exception $eKg) {}

                $msg = 'Data guru baru berhasil ditambahkan.';
                $mode = 'insert';
            }

            $pdo->commit();

            // Ambil row lengkap terbaru untuk update in-place DOM
            $rowStmt = $pdo->prepare("
                SELECT g.id, g.id_user, g.nama_guru, g.kode_guru, g.nip, g.nuptk, g.jenis_kelamin, g.no_hp, g.email, g.status_kepegawaian, g.alamat, g.foto,
                    (SELECT COUNT(*) FROM kelas_mata_pelajaran kmp JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id WHERE gmp.id_guru = g.id) AS total_mengajar,
                    (SELECT GROUP_CONCAT(mp.nama_mata_pelajaran SEPARATOR '||') 
                     FROM guru_mata_pelajaran gmp 
                     JOIN mata_pelajaran mp ON gmp.id_mata_pelajaran = mp.id 
                     WHERE gmp.id_guru = g.id) AS mapel_diampu,
                    (SELECT COUNT(*) FROM guru_mata_pelajaran gmp WHERE gmp.id_guru = g.id) AS total_mapel_diampu,
                    u.username AS akun_username, u.status AS akun_status
                FROM guru g
                LEFT JOIN users u ON g.id_user = u.id
                WHERE g.id = ?
            ");
            $rowStmt->execute([$id]);
            $savedRow = $rowStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => $msg,
                    'id'      => $id,
                    'mode'    => $mode,
                    'row'     => $savedRow,
                    'stats'   => getGuruStats($pdo)
                ]
            ]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal menyimpan: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 4.4 DELETE GURU
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID guru tidak valid.']]);
            exit;
        }

        try {
            // Lepaskan relasi di kelas_mata_pelajaran (set id_guru_mata_pelajaran = NULL)
            $pdo->prepare("
                UPDATE kelas_mata_pelajaran 
                SET id_guru_mata_pelajaran = NULL 
                WHERE id_guru_mata_pelajaran IN (SELECT id FROM guru_mata_pelajaran WHERE id_guru = ?)
            ")->execute([$id]);
            // Lepaskan relasi di guru_mata_pelajaran
            $pdo->prepare("DELETE FROM guru_mata_pelajaran WHERE id_guru = ?")->execute([$id]);
            // Hapus data guru
            $pdo->prepare("DELETE FROM guru WHERE id = ?")->execute([$id]);

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => 'Data guru berhasil dihapus.',
                    'id'      => $id,
                    'stats'   => getGuruStats($pdo)
                ]
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal menghapus: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 4.4.1 BULK DELETE GURU
    if ($act === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_unique(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada data guru yang dipilih.']]);
            exit;
        }

        try {
            $in = implode(',', $ids);
            $pdo->query("
                UPDATE kelas_mata_pelajaran 
                SET id_guru_mata_pelajaran = NULL 
                WHERE id_guru_mata_pelajaran IN (SELECT id FROM guru_mata_pelajaran WHERE id_guru IN ($in))
            ");
            $pdo->query("DELETE FROM guru_mata_pelajaran WHERE id_guru IN ($in)");
            $pdo->query("DELETE FROM guru WHERE id IN ($in)");

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => 'Berhasil menghapus ' . count($ids) . ' data guru terpilih.',
                    'deleted' => count($ids),
                    'stats'   => getGuruStats($pdo)
                ]
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal menghapus masal: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 4.4.2 BULK UPDATE STATUS KEPEGAWAIAN
    if ($act === 'bulk_update_status') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_unique(array_filter(array_map('intval', $ids)));
        $status = trim($_POST['status_kepegawaian'] ?? '');
        $allowed = ['PNS', 'PPPK', 'GTT', 'Tetap Yayasan', 'Tetap'];

        if (empty($ids)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada guru yang dipilih.']]);
            exit;
        }
        if (!in_array($status, $allowed, true)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Status kepegawaian baru tidak valid.']]);
            exit;
        }

        try {
            $in = implode(',', $ids);
            $stmt = $pdo->prepare("UPDATE guru SET status_kepegawaian = ? WHERE id IN ($in)");
            $stmt->execute([$status]);

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => "Berhasil memperbarui status kepegawaian menjadi '{$status}' pada " . count($ids) . " guru terpilih.",
                    'updated' => count($ids),
                    'stats'   => getGuruStats($pdo)
                ]
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal memperbarui status masal: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 4.4.3 BULK CREATE PORTAL ACCOUNTS
    if ($act === 'bulk_create_accounts') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_unique(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada guru yang dipilih.']]);
            exit;
        }

        try {
            $in = implode(',', $ids);
            $guruList = $pdo->query("SELECT id, nama_guru, nip, email, jenis_kelamin, no_hp, id_user FROM guru WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);

            $created = 0;
            $alreadyHas = 0;
            $passHash = password_hash('guru123', PASSWORD_BCRYPT);

            foreach ($guruList as $g) {
                if (!empty($g['id_user'])) {
                    $alreadyHas++;
                    continue;
                }

                $baseUser = '';
                if (!empty($g['nip'])) {
                    $baseUser = preg_replace('/[^0-9]/', '', $g['nip']);
                }
                if (!$baseUser) {
                    $baseUser = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode(' ', $g['nama_guru'])[0] ?? 'guru'));
                    if (strlen($baseUser) < 3) $baseUser = 'guru' . $g['id'];
                }

                $finalUser = $baseUser;
                $chkUser = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $chkUser->execute([$finalUser]);
                $suffix = 1;
                while ($chkUser->fetch()) {
                    $finalUser = $baseUser . $suffix++;
                    $chkUser->execute([$finalUser]);
                }

                $finalEmail = $g['email'] ?: ($finalUser . '@sekolah.id');
                $chkEmail = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $chkEmail->execute([$finalEmail]);
                if ($chkEmail->fetch()) {
                    $finalEmail = $finalUser . '_' . time() . '@sekolah.id';
                }

                $stmtIns = $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, full_name, nisn_nip, gender, phone, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $stmtIns->execute([$finalUser, $finalEmail, $passHash, $g['nama_guru'], $g['nip'] ?: null, $g['jenis_kelamin'] ?: 'L', $g['no_hp'] ?: null]);
                $newUserId = (int)$pdo->lastInsertId();

                $stmtRole = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id, is_primary) VALUES (?, 1, 1)");
                $stmtRole->execute([$newUserId]);

                $pdo->prepare("UPDATE guru SET id_user = ? WHERE id = ?")->execute([$newUserId, $g['id']]);
                $created++;
            }

            $msg = "Berhasil membuat {$created} akun login portal guru (Password default: guru123).";
            if ($alreadyHas > 0) {
                $msg .= " ({$alreadyHas} guru dilewati karena sudah memiliki akun).";
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => $msg,
                    'created' => $created,
                    'skipped' => $alreadyHas
                ]
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal membuat akun masal: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 4.4.4 GET MAPPING MATA PELAJARAN GURU
    if ($act === 'get_guru_mapel') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID guru tidak valid.']]);
            exit;
        }

        $guru = $pdo->query("SELECT id, nama_guru, nip, status_kepegawaian FROM guru WHERE id = " . $id)->fetch(PDO::FETCH_ASSOC);
        if (!$guru) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Guru tidak ditemukan.']]);
            exit;
        }

        $all_mapel = $pdo->query("SELECT id, nama_mata_pelajaran, kode_mapel, semester, tahun_pelajaran FROM mata_pelajaran ORDER BY nama_mata_pelajaran ASC")->fetchAll(PDO::FETCH_ASSOC);

        $assigned = $pdo->query("SELECT id_mata_pelajaran FROM guru_mata_pelajaran WHERE id_guru = " . $id)->fetchAll(PDO::FETCH_COLUMN);
        $assigned = array_map('intval', $assigned ?: []);

        echo json_encode([
            'success' => true,
            'data' => [
                'guru'      => $guru,
                'all_mapel' => $all_mapel ?: [],
                'assigned'  => $assigned
            ]
        ]);
        exit;
    }

    // 4.4.5 SAVE MAPPING MATA PELAJARAN GURU
    if ($act === 'save_guru_mapel') {
        $id_guru = (int)($_POST['id_guru'] ?? 0);
        if (!$id_guru) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID guru tidak valid.']]);
            exit;
        }

        $mapel_ids = $_POST['mapel_ids'] ?? [];
        if (!is_array($mapel_ids)) $mapel_ids = [];
        $mapel_ids = array_unique(array_filter(array_map('intval', $mapel_ids)));

        try {
            $pdo->beginTransaction();

            $existing = $pdo->query("SELECT id_mata_pelajaran FROM guru_mata_pelajaran WHERE id_guru = " . $id_guru)->fetchAll(PDO::FETCH_COLUMN);
            $existing = array_map('intval', $existing ?: []);

            $to_del = array_diff($existing, $mapel_ids);
            if (!empty($to_del)) {
                $del = $pdo->prepare("DELETE FROM guru_mata_pelajaran WHERE id_guru = ? AND id_mata_pelajaran = ?");
                foreach ($to_del as $m) $del->execute([$id_guru, $m]);
            }

            $to_add = array_diff($mapel_ids, $existing);
            if (!empty($to_add)) {
                $ins = $pdo->prepare("INSERT IGNORE INTO guru_mata_pelajaran (id_guru, id_mata_pelajaran) VALUES (?, ?)");
                foreach ($to_add as $m) $ins->execute([$id_guru, $m]);
            }

            $pdo->commit();

            $rowStmt = $pdo->prepare("
                SELECT g.id, g.id_user, g.nama_guru, g.nip, g.nuptk, g.jenis_kelamin, g.no_hp, g.email, g.status_kepegawaian, g.alamat, g.foto,
                    (SELECT COUNT(*) FROM kelas_mata_pelajaran kmp JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id WHERE gmp.id_guru = g.id) AS total_mengajar,
                    (SELECT GROUP_CONCAT(mp.nama_mata_pelajaran SEPARATOR '||') 
                     FROM guru_mata_pelajaran gmp 
                     JOIN mata_pelajaran mp ON gmp.id_mata_pelajaran = mp.id 
                     WHERE gmp.id_guru = g.id) AS mapel_diampu,
                    (SELECT COUNT(*) FROM guru_mata_pelajaran gmp WHERE gmp.id_guru = g.id) AS total_mapel_diampu,
                    u.username AS akun_username, u.status AS akun_status
                FROM guru g
                LEFT JOIN users u ON g.id_user = u.id
                WHERE g.id = ?
            ");
            $rowStmt->execute([$id_guru]);
            $updatedRow = $rowStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => 'Mapping mata pelajaran berhasil disimpan (' . count($mapel_ids) . ' mata pelajaran diampu).',
                    'total'   => count($mapel_ids),
                    'row'     => $updatedRow
                ]
            ]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal menyimpan mapping: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 4.4.6 BULK ASSIGN MATA PELAJARAN GURU
    if ($act === 'bulk_assign_mapel') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_unique(array_filter(array_map('intval', $ids)));

        $mapel_ids = $_POST['mapel_ids'] ?? [];
        if (!is_array($mapel_ids)) $mapel_ids = [];
        $mapel_ids = array_unique(array_filter(array_map('intval', $mapel_ids)));

        if (empty($ids)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada guru yang dipilih.']]);
            exit;
        }
        if (empty($mapel_ids)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Pilih setidaknya 1 mata pelajaran.']]);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT IGNORE INTO guru_mata_pelajaran (id_guru, id_mata_pelajaran) VALUES (?, ?)");
            foreach ($ids as $gid) {
                foreach ($mapel_ids as $mid) {
                    $ins->execute([$gid, $mid]);
                }
            }
            $pdo->commit();

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => 'Berhasil menetapkan ' . count($mapel_ids) . ' mata pelajaran ke ' . count($ids) . ' guru terpilih.',
                    'total_guru'  => count($ids),
                    'total_mapel' => count($mapel_ids)
                ]
            ]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 4.5 GET DETAIL PENUGASAN MENGAJAR GURU
    if ($act === 'get_mengajar') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID guru tidak valid.']]);
            exit;
        }

        $guru = $pdo->query("SELECT id, nama_guru, nip, status_kepegawaian, email, no_hp FROM guru WHERE id = " . $id)->fetch(PDO::FETCH_ASSOC);
        if (!$guru) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Guru tidak ditemukan.']]);
            exit;
        }

        $sql = "SELECT kmp.id, kmp.id_kelas, k.nama_kelas, k.tahun_pelajaran AS tahun_kelas,
                    kmp.id_mata_pelajaran, mp.nama_mata_pelajaran, mp.semester,
                    (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = k.id) AS total_siswa
                FROM kelas_mata_pelajaran kmp
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                WHERE gmp.id_guru = ?
                ORDER BY k.nama_kelas ASC, mp.nama_mata_pelajaran ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => [
                'guru'     => $guru,
                'mengajar' => $rows ?: []
            ]
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'data' => ['message' => 'Aksi tidak dikenali.']]);
    exit;
}

$cssVersion = file_exists(BASE_PATH . '/assets/css/admin-teachers.css') ? filemtime(BASE_PATH . '/assets/css/admin-teachers.css') : '1.0';
$extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-teachers.css?v=' . $cssVersion . '">';

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<!-- ==========================================================================
 * LUMINA GOLD THEMED CRUD DATA GURU (MASTER DATA PENDIDIK)
 * ========================================================================== -->
<div class="ilmuna-guru-app" id="adminCrudGuru" data-ajax="<?= BASE_URL ?>/dashboard/admin/teachers" data-excel="<?= BASE_URL ?>/dashboard/admin/teachers-excel">

    <!-- TOOLBAR & FILTERS -->
    <div class="ilmuna-crud-toolbar">
        <div class="ilmuna-crud-toolbar__row">
            <div class="ilmuna-search-box">
                <iconify-icon icon="solar:magnifer-bold" width="18"></iconify-icon>
                <input type="text" id="inputSearchGuru" placeholder="Cari nama guru, NIP, NUPTK, atau email...">
            </div>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnSearchGuru">
                <iconify-icon icon="solar:magnifer-bold" width="16"></iconify-icon>
                Cari
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="btnResetGuru">
                <iconify-icon icon="solar:refresh-bold" width="16"></iconify-icon>
                Reset
            </button>
        </div>
        <div class="ilmuna-crud-toolbar__row ilmuna-crud-toolbar__row--filters">
            <div class="ilmuna-filter-group">
                <label for="filterStatus">
                    <iconify-icon icon="solar:tag-bold" width="15"></iconify-icon>
                    Status Kepegawaian:
                </label>
                <select id="filterStatus" class="ilmuna-select-sm">
                    <option value="">Semua Status</option>
                    <option value="PNS">PNS</option>
                    <option value="PPPK">PPPK</option>
                    <option value="GTT">GTT (Guru Tidak Tetap)</option>
                    <option value="Tetap Yayasan">Tetap Yayasan</option>
                    <option value="Tetap">Tetap</option>
                </select>
            </div>
            <div class="ilmuna-filter-group">
                <label for="filterGender">
                    <iconify-icon icon="solar:user-bold" width="15"></iconify-icon>
                    Gender:
                </label>
                <select id="filterGender" class="ilmuna-select-sm">
                    <option value="">Semua (L/P)</option>
                    <option value="L">Laki-laki (L)</option>
                    <option value="P">Perempuan (P)</option>
                </select>
            </div>
            <div class="ilmuna-filter-group">
                <label for="filterPerPage">
                    <iconify-icon icon="solar:list-bold" width="15"></iconify-icon>
                    Tampilkan:
                </label>
                <select id="filterPerPage" class="ilmuna-select-sm">
                    <option value="20">20 Data</option>
                    <option value="50">50 Data</option>
                    <option value="100">100 Data</option>
                </select>
            </div>
        </div>
        <div class="ilmuna-crud-toolbar__row ilmuna-crud-toolbar__row--actions">
            <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="btnTambahGuru">
                <iconify-icon icon="solar:user-plus-bold" width="16"></iconify-icon>
                Tambah Data Guru
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="btnDownloadTemplateGuru">
                <iconify-icon icon="solar:file-download-bold" width="16"></iconify-icon>
                Unduh Format Excel
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="btnImportGuru">
                <iconify-icon icon="solar:cloud-upload-bold" width="16"></iconify-icon>
                Import Excel (.xlsx)
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--emerald" id="btnExportGuru">
                <iconify-icon icon="solar:file-send-bold" width="16"></iconify-icon>
                Export Excel (.xlsx)
            </button>
        </div>
    </div>

    <!-- CHIPS COUNTER -->
    <div class="ilmuna-crud-info" id="guruCrudInfo">
        <span class="ilmuna-info-chip"><iconify-icon icon="solar:user-speak-bold" width="14"></iconify-icon> Memuat data guru...</span>
    </div>

    <!-- TABEL DATA GURU -->
    <div class="ilmuna-crud-tablewrap">
        <table class="ilmuna-crud-table" id="tableGuru">
            <thead>
                <tr>
                    <th style="width: 44px; text-align: center;">
                        <input type="checkbox" id="checkAllGuru" class="ilmuna-bulk-cb" title="Pilih Semua di Halaman Ini">
                    </th>
                    <th style="width: 45px; text-align: center;">#</th>
                    <th style="width: 65px; text-align: center;">Foto</th>
                    <th>Nama Lengkap Guru & Kontak</th>
                    <th style="width: 160px;">NIP / NUPTK</th>
                    <th style="width: 60px; text-align: center;">L/P</th>
                    <th style="width: 130px;">Status Kepegawaian</th>
                    <th style="width: 120px; text-align: center;">Akun Portal</th>
                    <th style="width: 210px;">Mata Pelajaran Diampu</th>
                    <th style="width: 110px; text-align: center;">Penugasan Rombel</th>
                    <th style="width: 190px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody id="tableGuruBody">
                <tr>
                    <td colspan="11" class="ilmuna-table-loading">
                        <iconify-icon icon="svg-spinners:ring-resize" width="28"></iconify-icon>
                        <span>Memuat pangkalan data guru...</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- PAGINATION -->
    <div class="ilmuna-pagination" id="guruPagination">
        <div class="ilmuna-pagination__info" id="guruPagInfo">Menampilkan data...</div>
        <div class="ilmuna-pagination__btns" id="guruPagBtns"></div>
    </div>

</div>

<!-- ==========================================================================
 * MODALS & DIALOGS
 * ========================================================================== -->

<!-- MODAL 1: FORM TAMBAH / EDIT GURU -->
<div class="ilmuna-custom-modal" id="modalGuru">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 600px;">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:user-speak-bold" width="22"></iconify-icon>
                <h3 id="modalGuruTitle">Tambah Data Guru</h3>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <form id="formGuru" class="ilmuna-modal-body" onsubmit="return false;" enctype="multipart/form-data">
            <input type="hidden" name="id" value="0">
            <input type="hidden" id="inputDeleteFotoGuru" name="delete_foto" value="0">

            <div class="guru-photo-upload-widget">
                <input type="file" id="inputFotoGuru" name="foto" accept="image/jpeg,image/png,image/webp,image/jpg" style="display: none;">
                <div class="guru-photo-upload-preview" id="previewFotoGuru">
                    <iconify-icon icon="solar:user-bold" width="34" style="color: #94A3B8;"></iconify-icon>
                </div>
                <div class="guru-photo-upload-info">
                    <h5>Foto Profil Guru</h5>
                    <p>Format JPG, PNG, atau WEBP (Maksimal 3MB). Rasio persegi 1:1 disarankan.</p>
                    <div class="guru-photo-upload-actions">
                        <button type="button" class="ilmuna-btn ilmuna-btn--primary ilmuna-btn--xs" id="btnBrowseFotoGuru">
                            <iconify-icon icon="solar:camera-add-bold" width="13"></iconify-icon> Pilih Foto
                        </button>
                        <button type="button" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--xs" id="btnRemoveFotoGuru" style="display: none; color: #DC2626; border-color: #FCA5A5;">
                            <iconify-icon icon="solar:trash-bin-trash-bold" width="13"></iconify-icon> Hapus Foto
                        </button>
                    </div>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1.8fr 1.2fr; gap: 14px;">
                <div class="ilmuna-form-field">
                    <label>Nama Lengkap Guru & Gelar <span class="req">*</span></label>
                    <input type="text" name="nama_guru" id="inputNamaGuru" required placeholder="Contoh: Ustadz Rahmat Hidayat, M.Pd.I" class="ilmuna-input">
                    <small>Nama resmi pendidik beserta gelar akademik</small>
                </div>
                <div class="ilmuna-form-field">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                        <label style="margin-bottom: 0;">Kode Guru</label>
                        <span class="ilmuna-badge ilmuna-badge--teal" style="font-size: 10px; padding: 2px 7px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                            <iconify-icon icon="solar:magic-stick-3-bold" width="12"></iconify-icon> Otomatis
                        </span>
                    </div>
                    <div style="display: flex; gap: 6px;">
                        <input type="text" name="kode_guru" id="inputKodeGuru" readonly placeholder="[Otomatis dari Sistem]" class="ilmuna-input" style="background-color: #F8FAFC; color: #0D7A87; font-weight: 700; letter-spacing: 0.5px; cursor: default;">
                        <button type="button" class="ilmuna-btn ilmuna-btn--sm ilmuna-btn--outline" id="btnRegenerateKodeGuru" title="Generate ulang kode acak baru" style="padding: 0 10px; height: 38px; border-color: #CBD5E1; flex-shrink: 0;">
                            <iconify-icon icon="solar:restart-bold" width="16" style="color: #0D7A87;"></iconify-icon>
                        </button>
                    </div>
                    <small style="color: #64748B;">Format: (singkatan)-(2acak)(id)(3acak)</small>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                <div class="ilmuna-form-field">
                    <label>Nomor Induk Pegawai (NIP)</label>
                    <input type="text" name="nip" placeholder="Contoh: 198804202014021003" class="ilmuna-input">
                </div>
                <div class="ilmuna-form-field">
                    <label>Nomor Unik Pendidik (NUPTK)</label>
                    <input type="text" name="nuptk" placeholder="Contoh: 92837492817263" class="ilmuna-input">
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                <div class="ilmuna-form-field">
                    <label>Jenis Kelamin <span class="req">*</span></label>
                    <select name="jenis_kelamin" class="ilmuna-input">
                        <option value="L">Laki-laki (L)</option>
                        <option value="P">Perempuan (P)</option>
                    </select>
                </div>
                <div class="ilmuna-form-field">
                    <label>Status Kepegawaian <span class="req">*</span></label>
                    <select name="status_kepegawaian" class="ilmuna-input">
                        <option value="PNS">PNS (Pegawai Negeri Sipil)</option>
                        <option value="PPPK">PPPK</option>
                        <option value="GTT">GTT (Guru Tidak Tetap)</option>
                        <option value="Tetap Yayasan">Tetap Yayasan</option>
                        <option value="Tetap">Tetap</option>
                    </select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                <div class="ilmuna-form-field">
                    <label>Nomor WhatsApp / Telepon</label>
                    <input type="text" name="no_hp" placeholder="Contoh: 081234567890" class="ilmuna-input">
                </div>
                <div class="ilmuna-form-field">
                    <label>Alamat Email</label>
                    <input type="email" name="email" placeholder="Contoh: guru@sekolah.id" class="ilmuna-input">
                </div>
            </div>
            <div class="ilmuna-form-field">
                <label>Alamat Tempat Tinggal</label>
                <textarea name="alamat" rows="2" placeholder="Alamat lengkap domisili guru..." class="ilmuna-input" style="resize: vertical;"></textarea>
            </div>
            <div class="ilmuna-form-field" id="wrapCreateAccount" style="background: rgba(13, 122, 135, 0.05); padding: 12px 14px; border-radius: 12px; border: 1px dashed rgba(13, 122, 135, 0.3);">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #1D3B5E; margin: 0;">
                    <input type="checkbox" name="create_account" value="1" style="width: 17px; height: 17px; accent-color: #0D7A87;">
                    <span><strong>Otomatis Buat Akun Login Portal</strong> (Password default: <code>guru123</code>)</span>
                </label>
            </div>
        </form>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnSaveGuru">
                <iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon>
                Simpan Data Guru
            </button>
        </div>
    </div>
</div>

<!-- MODAL 2: DETAIL PENUGASAN MENGAJAR GURU -->
<div class="ilmuna-custom-modal" id="modalPenugasan">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 660px;">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:notebook-bookmark-bold" width="22"></iconify-icon>
                <h3>Rombel & Mapel Mengajar — <span id="penugasanGuruName" style="color: var(--lumina-teal);">—</span></h3>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-modal-body">
            <div id="penugasanGuruInfo" style="display: flex; gap: 16px; align-items: center; padding: 12px 16px; background: #F8FAFC; border-radius: 12px; border: 1px solid #E2E8F0;">
                <div class="guru-avatar" id="penugasanAvatar">GR</div>
                <div style="flex: 1;">
                    <strong id="penugasanNama" style="font-size: 15px; color: #1D3B5E; display: block;">—</strong>
                    <span id="penugasanMeta" style="font-size: 12px; color: #64748B;">NIP: - | Status: -</span>
                </div>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                <h4 style="margin: 0; font-size: 14px; font-weight: 700; color: #1D3B5E;">Daftar Mata Pelajaran & Kelas yang Diampu:</h4>
                <a href="<?= BASE_URL ?>/dashboard/admin/classes" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--sm">
                    <iconify-icon icon="solar:pen-new-square-bold" width="14"></iconify-icon>
                    Kelola di Halaman Kelas
                </a>
            </div>
            <div class="ilmuna-crud-tablewrap" style="max-height: 280px; overflow-y: auto;">
                <table class="ilmuna-crud-table" style="font-size: 12.5px;">
                    <thead>
                        <tr>
                            <th style="width: 40px;">#</th>
                            <th>Mata Pelajaran</th>
                            <th>Rombel / Kelas</th>
                            <th>Semester</th>
                            <th style="text-align: center;">Siswa</th>
                        </tr>
                    </thead>
                    <tbody id="penugasanTableBody">
                        <tr><td colspan="5" class="ilmuna-table-loading"><span>Memuat penugasan...</span></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Tutup</button>
        </div>
    </div>
</div>

<!-- MODAL 3: IMPORT EXCEL (.xlsx) -->
<div class="ilmuna-custom-modal" id="modalImportGuru">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 580px;">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:cloud-upload-bold" width="22"></iconify-icon>
                <h3>Import Data Guru dari Excel (.xlsx)</h3>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-modal-body">
            <div style="margin-bottom: 16px;">
                <button type="button" id="btnModalDownloadTemplateGuru" class="ilmuna-btn ilmuna-btn--outline" style="font-size: 12.5px;">
                    <iconify-icon icon="solar:file-download-bold" width="14"></iconify-icon>
                    Unduh Format Template Excel (.xlsx)
                </button>
            </div>
            <div class="ilmuna-file-dropzone" id="guruDropzone">
                <iconify-icon icon="solar:cloud-upload-bold" width="38"></iconify-icon>
                <label for="inputImportFile" style="cursor: pointer; font-weight: 700; color: #1D3B5E; margin-top: 8px; display: block;">
                    Pilih File Excel (.xlsx) atau Tarik Berkas ke Sini
                </label>
                <div id="guruSelectedFileName" style="font-size: 12.5px; color: #0D7A87; margin-top: 6px; font-weight: 700; display: none;"></div>
                <input type="file" id="inputImportFile" accept=".xlsx" class="ilmuna-file-input">
            </div>
            <div id="guruImportLog" class="ilmuna-import-log" style="display: none;"></div>
        </div>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Tutup</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnDoImportGuru">
                <iconify-icon icon="solar:cloud-upload-bold" width="16"></iconify-icon>
                Mulai Import
            </button>
        </div>
    </div>
</div>

<!-- MODAL 4: UBAH STATUS KEPEGAWAIAN MASAL -->
<div class="ilmuna-custom-modal" id="modalBulkUpdateStatus">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 460px;">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:tag-bold" width="22"></iconify-icon>
                <h3>Ubah Status Kepegawaian Masal</h3>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-modal-body">
            <p style="margin: 0 0 16px; font-size: 13px; color: #64748B;">
                Anda akan memperbarui status kepegawaian untuk <strong id="bulkStatusCountText" style="color: #1D3B5E;">0</strong> guru terpilih.
            </p>
            <div class="ilmuna-form-field">
                <label>Pilih Status Kepegawaian Baru <span class="req">*</span></label>
                <select id="selectBulkStatus" class="ilmuna-input">
                    <option value="PNS">PNS (Pegawai Negeri Sipil)</option>
                    <option value="PPPK">PPPK (Pegawai Pemerintah dg Perjanjian Kerja)</option>
                    <option value="GTT">GTT (Guru Tidak Tetap / Honorer)</option>
                    <option value="Tetap Yayasan">Tetap Yayasan</option>
                    <option value="Tetap">Tetap</option>
                </select>
            </div>
        </div>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnSubmitBulkStatus">
                <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                Terapkan Status Baru
            </button>
        </div>
    </div>
</div>

<!-- MODAL 5: MAPPING MATA PELAJARAN GURU (SINGLE) -->
<div class="ilmuna-custom-modal" id="modalMappingMapelGuru">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 620px;">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:book-bookmark-bold" width="22" style="color: #0D7A87;"></iconify-icon>
                <div>
                    <h3 id="modalMappingGuruTitle" style="margin: 0; font-size: 16px;">Mapping Mata Pelajaran yang Diampu</h3>
                    <small id="modalMappingGuruSubtitle" style="color: #64748B; font-weight: 500;">Pilih mata pelajaran yang menjadi bidang ajar guru ini</small>
                </div>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-modal-body">
            <input type="hidden" id="mappingGuruId" value="0">
            <div style="display: flex; gap: 8px; margin-bottom: 12px; align-items: center; flex-wrap: wrap;">
                <div class="ilmuna-search-box" style="flex: 1; min-width: 180px;">
                    <iconify-icon icon="solar:magnifer-bold" width="16"></iconify-icon>
                    <input type="text" id="inputFilterMapelModal" placeholder="Cari nama mata pelajaran...">
                </div>
                <button type="button" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--sm" id="btnSelectAllMapel">Pilih Semua</button>
                <button type="button" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--sm" id="btnClearAllMapel">Hapus Centang</button>
            </div>

            <div class="ilmuna-mapel-picker-list" id="listMapelGuruPicker">
                <div style="text-align: center; padding: 24px; color: #64748B;">
                    <iconify-icon icon="svg-spinners:ring-resize" width="26"></iconify-icon>
                    <p style="margin: 6px 0 0;">Memuat daftar mata pelajaran...</p>
                </div>
            </div>
        </div>
        <div class="ilmuna-modal-footer">
            <span style="font-size: 12.5px; color: #64748B; font-weight: 600; margin-right: auto;" id="mappingSelectedCountInfo">0 mata pelajaran dipilih</span>
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnSaveMappingMapel">
                <iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon>
                Simpan Relasi Mapel
            </button>
        </div>
    </div>
</div>

<!-- MODAL 6: BULK ASSIGN MATA PELAJARAN -->
<div class="ilmuna-custom-modal" id="modalBulkAssignMapel">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 580px;">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:book-bookmark-bold" width="22" style="color: #0D7A87;"></iconify-icon>
                <div>
                    <h3 style="margin: 0; font-size: 16px;">Tetapkan Mata Pelajaran Masal</h3>
                    <small style="color: #64748B;">Tambahkan relasi mapel untuk <strong id="bulkMapelCountText" style="color:#1D3B5E;">0</strong> guru terpilih</small>
                </div>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-modal-body">
            <p style="margin: 0 0 12px; font-size: 13px; color: #64748B;">
                Pilih mata pelajaran yang akan ditetapkan sebagai mata pelajaran yang diampu oleh seluruh guru terpilih:
            </p>
            <div class="ilmuna-mapel-picker-list" id="listBulkMapelPicker">
                <!-- Rendered dynamically -->
            </div>
        </div>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnSubmitBulkMapel">
                <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                Tetapkan ke Guru Terpilih
            </button>
        </div>
    </div>
</div>

<!-- MODAL PREVIEW FOTO GURU (LIGHTBOX) -->
<div class="ilmuna-custom-modal" id="modalPreviewFotoGuru">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 440px; padding: 22px; text-align: center;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <iconify-icon icon="solar:user-bold" width="20" style="color: #0D7A87;"></iconify-icon>
                <h4 id="previewFotoTitle" style="margin: 0; font-size: 15px; font-weight: 700; color: #1D3B5E;">Foto Guru</h4>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <div style="border-radius: 16px; overflow: hidden; background: #0F172A; border: 1.5px solid #E2E8F0; margin-bottom: 16px; box-shadow: 0 8px 24px rgba(15,29,47,0.12);">
            <img id="previewFotoImg" src="" alt="Foto Guru" style="max-width: 100%; max-height: 420px; object-fit: contain; display: block; margin: 0 auto;">
        </div>
        <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1" style="width: 100%; justify-content: center;">
            Tutup Preview
        </button>
    </div>
</div>

<!-- FLOATING BULK ACTION BAR -->
<div class="ilmuna-bulk-bar" id="bulkBarGuru">
    <div class="ilmuna-bulk-bar__counter">
        <span class="ilmuna-bulk-pill" id="bulkCountGuru">0</span>
        <span class="ilmuna-bulk-label">Guru Terpilih</span>
    </div>
    <div class="ilmuna-bulk-bar__divider"></div>
    <div class="ilmuna-bulk-bar__actions">
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--teal" id="btnBulkOpenMapel">
            <iconify-icon icon="solar:book-bookmark-bold" width="16"></iconify-icon>
            <span>Petakan Mapel</span>
        </button>
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--gold" id="btnBulkOpenStatus">
            <iconify-icon icon="solar:tag-bold" width="16"></iconify-icon>
            <span>Ubah Status</span>
        </button>
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--sky" id="btnBulkCreateAccounts">
            <iconify-icon icon="solar:user-check-bold" width="16"></iconify-icon>
            <span>Buat Akun Portal</span>
        </button>
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--emerald" id="btnBulkExportGuruSelected">
            <iconify-icon icon="solar:file-send-bold" width="16"></iconify-icon>
            <span>Ekspor Excel (.xlsx)</span>
        </button>
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--danger" id="btnBulkDeleteGuru">
            <iconify-icon icon="solar:trash-bin-trash-bold" width="16"></iconify-icon>
            <span>Hapus Masal</span>
        </button>
    </div>
    <button type="button" class="ilmuna-bulk-bar__close" id="btnBulkCloseGuru" title="Batalkan Pilihan">
        <iconify-icon icon="solar:close-circle-bold" width="20"></iconify-icon>
    </button>
</div>

<!-- TOAST & CONFIRM DIALOGS -->
<div id="guruCrudToast" class="ilmuna-toast-stack"></div>
<div id="guruCrudConfirm" class="ilmuna-confirm-overlay">
    <div class="ilmuna-confirm-dialog">
        <div class="ilmuna-confirm-icon" id="guruConfirmIcon">
            <iconify-icon icon="solar:danger-triangle-bold" width="30"></iconify-icon>
        </div>
        <h3 class="ilmuna-confirm-title" id="guruConfirmTitle">Konfirmasi</h3>
        <p class="ilmuna-confirm-message" id="guruConfirmMessage"></p>
        <div class="ilmuna-confirm-actions">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="guruConfirmCancel" style="flex: 1;">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--danger" id="guruConfirmOk" style="flex: 1;">Ya, Lanjutkan</button>
        </div>
    </div>
</div>

<!-- ==========================================================================
 * JAVASCRIPT APP (Pure Vanilla ES6)
 * ========================================================================== -->
<script>
(function(){
    var rootEl = document.getElementById('adminCrudGuru');
    if (!rootEl) return;
    var AJAX_URL = rootEl.getAttribute('data-ajax') || window.location.href.split('?')[0];
    var EXCEL_URL = rootEl.getAttribute('data-excel') || (AJAX_URL + '-excel');

    if (window.location.search && window.location.search.indexOf('action=') !== -1) {
        try {
            window.history.replaceState({}, document.title, window.location.pathname);
        } catch(e) {}
    }

    function downloadBlob(url, fallbackFilename, btnElement, loadingText) {
        var origHtml = btnElement ? btnElement.innerHTML : '';
        if (btnElement) {
            btnElement.disabled = true;
            btnElement.innerHTML = '<iconify-icon icon="svg-spinners:ring-resize" width="16"></iconify-icon> ' + (loadingText || 'Mengunduh...');
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
            setTimeout(function() {
                if (a.parentNode) document.body.removeChild(a);
                window.URL.revokeObjectURL(blobUrl);
            }, 500);
            toast('success', 'File Excel berhasil diunduh: ' + data.filename);
        })
        .catch(function(err) {
            console.error('Download blob error:', err);
            toast('error', err.message || 'Gagal mengunduh file Excel.');
        })
        .finally(function() {
            if (btnElement) {
                btnElement.disabled = false;
                btnElement.innerHTML = origHtml;
            }
        });
    }

    var state = {
        search: '',
        f_status: '',
        f_gender: '',
        page: 1,
        per_page: 20,
        total: 0,
        pages: 1,
        loading: false
    };

    ['modalGuru', 'modalPreviewFotoGuru', 'modalPenugasan', 'modalImportGuru', 'modalBulkUpdateStatus', 'modalMappingMapelGuru', 'modalBulkAssignMapel', 'bulkBarGuru', 'guruCrudToast', 'guruCrudConfirm'].forEach(function(id){
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    });

    var selectedGuru = new Set();

    function updateBulkBar() {
        var bar = document.getElementById('bulkBarGuru');
        var countEl = document.getElementById('bulkCountGuru');
        var checkAll = document.getElementById('checkAllGuru');
        if (!bar || !countEl) return;

        var count = selectedGuru.size;
        countEl.textContent = count;
        if (count > 0) {
            bar.classList.add('is-active');
        } else {
            bar.classList.remove('is-active');
        }

        var pageCheckboxes = document.querySelectorAll('.ilmuna-guru-cb');
        if (pageCheckboxes.length > 0) {
            var allChecked = true;
            var someChecked = false;
            pageCheckboxes.forEach(function(cb){
                if (cb.checked) someChecked = true;
                else allChecked = false;
            });
            if (checkAll) {
                checkAll.checked = allChecked;
                checkAll.indeterminate = !allChecked && someChecked;
            }
        } else if (checkAll) {
            checkAll.checked = false;
            checkAll.indeterminate = false;
        }
    }

    function toast(type, msg) {
        var normType = (type === 'danger' || type === 'err' || type === 'error') ? 'error' : ((type === 'warn' || type === 'warning') ? 'warning' : (type === 'info' ? 'info' : 'success'));
        var titleMap = { success: 'Berhasil', error: 'Gagal', warning: 'Peringatan', info: 'Informasi' };
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            return Ilmuna.toast(normType, titleMap[normType] || 'Pemberitahuan', msg, 4500);
        }
        var stack = document.getElementById('guruCrudToast');
        if (!stack) return;
        var icon = 'solar:check-circle-bold';
        if (type === 'error')   icon = 'solar:close-circle-bold';
        if (type === 'warning') icon = 'solar:danger-triangle-bold';

        var t = document.createElement('div');
        t.className = 'ilmuna-toast ilmuna-toast--' + type;
        t.innerHTML = '<iconify-icon icon="' + icon + '" width="20"></iconify-icon><span>' + escHtml(msg) + '</span>';
        stack.appendChild(t);
        setTimeout(function(){
            t.style.opacity = '0';
            t.style.transform = 'translateY(-10px)';
            t.style.transition = 'all 0.25s ease';
            setTimeout(function(){ if (t.parentNode) t.parentNode.removeChild(t); }, 250);
        }, 3200);
    }

    function confirmDialog(msg, opts) {
        opts = opts || {};
        var type = (opts.type === 'danger' || opts.type === 'delete' || opts.type === 'error') ? 'error' : (opts.type || 'warning');
        if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
            return Ilmuna.confirm(type, opts.title || 'Konfirmasi Tindakan', msg || 'Apakah Anda yakin ingin melanjutkan?', opts.okText || 'Ya, Lanjutkan', opts.cancelText || 'Batal');
        }
        return new Promise(function(resolve){
            var ov = document.getElementById('guruCrudConfirm');
            var titleEl = document.getElementById('guruConfirmTitle');
            var msgEl = document.getElementById('guruConfirmMessage');
            var btnOk = document.getElementById('guruConfirmOk');
            var btnCancel = document.getElementById('guruConfirmCancel');

            titleEl.textContent = opts.title || 'Konfirmasi Tindakan';
            msgEl.textContent = msg || 'Apakah Anda yakin ingin melanjutkan?';
            btnOk.textContent = opts.okText || 'Ya, Lanjutkan';

            ov.classList.add('is-open');

            function cleanup(val) {
                ov.classList.remove('is-open');
                btnOk.removeEventListener('click', onOk);
                btnCancel.removeEventListener('click', onCancel);
                resolve(val);
            }
            function onOk(){ cleanup(true); }
            function onCancel(){ cleanup(false); }

            btnOk.addEventListener('click', onOk);
            btnCancel.addEventListener('click', onCancel);
        });
    }

    function openModal(id) {
        var m = document.getElementById(id);
        if (m) m.classList.add('is-open');
    }
    function closeModal(id) {
        var m = document.getElementById(id);
        if (m) m.classList.remove('is-open');
    }
    document.querySelectorAll('.ilmuna-custom-modal').forEach(function(m){
        m.addEventListener('click', function(e){
            if (e.target.dataset.close === '1' || e.target.closest('[data-close="1"]') || e.target.closest('.ilmuna-modal-close')) {
                m.classList.remove('is-open');
            }
        });
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') {
            document.querySelectorAll('.ilmuna-custom-modal.is-open').forEach(function(m){
                m.classList.remove('is-open');
            });
        }
    });

    function escHtml(s) {
        return String(s || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function getInitials(name) {
        var p = (name || '').trim().split(/\s+/);
        var inisial = '';
        if (p[0]) inisial += p[0].charAt(0);
        if (p[1]) inisial += p[1].charAt(0);
        return inisial.toUpperCase() || 'GR';
    }

    /* =========================================================================
     * FIX: 11 <td> must match 11 <th> in thead.
     * Foto (avatar) and Nama+Kontak are now TWO SEPARATE cells.
     * ========================================================================= */
    function buildGuruRowCellsHtml(r, no, isChecked) {
        var initials = getInitials(r.nama_guru);
        var badgeClass = 'cell-badge--gtt';
        if (r.status_kepegawaian === 'PNS')  badgeClass = 'cell-badge--pns';
        if (r.status_kepegawaian === 'PPPK') badgeClass = 'cell-badge--pppk';

        var akunBadge = r.id_user
            ? '<span class="cell-badge cell-badge--active"><iconify-icon icon="solar:check-circle-bold" width="12"></iconify-icon> ' + escHtml(r.akun_username || 'Aktif') + '</span>'
            : '<span class="cell-badge cell-badge--none"><iconify-icon icon="solar:close-circle-bold" width="12"></iconify-icon> Belum Ada</span>';

        var mapelList = (r.mapel_diampu || '').split('||').filter(Boolean);
        var mapelHtml = '<div class="guru-mapel-cell">';
        if (mapelList.length > 0) {
            mapelHtml += '<div class="guru-mapel-pills">';
            mapelList.slice(0, 2).forEach(function(m){
                var shortName = m.length > 20 ? m.substring(0, 19) + '…' : m;
                mapelHtml += '<span class="guru-mapel-pill" title="' + escHtml(m) + '">' + escHtml(shortName) + '</span>';
            });
            if (mapelList.length > 2) {
                mapelHtml += '<span class="guru-mapel-pill guru-mapel-pill--more" title="' + escHtml(mapelList.slice(2).join(', ')) + '">+' + (mapelList.length - 2) + '</span>';
            }
            mapelHtml += '</div>';
            mapelHtml += '<button type="button" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--xs" data-act="mapping_mapel" data-id="' + r.id + '" title="Kelola Mapping Mata Pelajaran"><iconify-icon icon="solar:pen-new-square-bold" width="12"></iconify-icon> Atur Mapel (' + mapelList.length + ')</button>';
        } else {
            mapelHtml += '<span class="guru-mapel-empty">Belum dipetakan</span>';
            mapelHtml += '<button type="button" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--xs" data-act="mapping_mapel" data-id="' + r.id + '" style="color:#D97706;border-color:#FDE68A;"><iconify-icon icon="solar:add-circle-bold" width="12"></iconify-icon> + Petakan Mapel</button>';
        }
        mapelHtml += '</div>';

        var kontak = '';
        if (r.email) kontak += '<iconify-icon icon="solar:letter-bold" width="11"></iconify-icon> ' + escHtml(r.email);
        if (r.no_hp) kontak += (kontak ? ' &nbsp;|&nbsp; ' : '') + '<iconify-icon icon="solar:phone-bold" width="11"></iconify-icon> ' + escHtml(r.no_hp);

        var html = '';

        // 1. Checkbox
        html += '<td style="text-align:center;"><input type="checkbox" class="ilmuna-guru-cb" value="' + r.id + '" ' + (isChecked ? 'checked' : '') + '></td>';
        // 2. Nomor
        html += '<td class="guru-cell-num" style="font-family:monospace;font-weight:700;color:#64748B;text-align:center;">' + no + '</td>';
        // 3. Foto (avatar only)
        html += '<td style="text-align:center;"><div class="guru-avatar">' + initials + '</div></td>';
        // 4. Nama & Kontak
        html += '<td>';
        html += '  <div class="teacher-info">';
        html += '    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">';
        html += '      <strong style="color:#1D3B5E;">' + escHtml(r.nama_guru || '-') + '</strong>';
        if (r.kode_guru) {
            html += '      <span class="ilmuna-badge ilmuna-badge--teal" style="font-family:monospace;font-size:11px;font-weight:700;padding:1px 6px;letter-spacing:0.5px;">' + escHtml(r.kode_guru) + '</span>';
        }
        html += '    </div>';
        if (kontak) html += '    <small>' + kontak + '</small>';
        html += '  </div>';
        html += '</td>';
        // 5. NIP / NUPTK
        html += '<td>';
        html += '  <div style="font-size:12px;"><strong>NIP:</strong> ' + escHtml(r.nip || '-') + '</div>';
        html += '  <div style="font-size:11px;color:#64748B;"><strong>NUPTK:</strong> ' + escHtml(r.nuptk || '-') + '</div>';
        html += '</td>';
        // 6. L/P
        html += '<td style="text-align:center;"><span class="cell-badge" style="background:#F1F5F9;color:#334155;">' + (r.jenis_kelamin === 'P' ? 'P' : 'L') + '</span></td>';
        // 7. Status
        html += '<td class="guru-cell-status"><span class="cell-badge ' + badgeClass + '">' + escHtml(r.status_kepegawaian || 'Tetap') + '</span></td>';
        // 8. Akun Portal
        html += '<td style="text-align:center;">' + akunBadge + '</td>';
        // 9. Mapel
        html += '<td class="guru-cell-mapel">' + mapelHtml + '</td>';
        // 10. Rombel
        html += '<td style="text-align:center;" class="guru-cell-mengajar">';
        html += '  <button type="button" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--sm" data-act="penugasan" data-id="' + r.id + '" title="Lihat Rombel & Mapel yang Diampu">';
        html += '    <iconify-icon icon="solar:notebook-bold" width="13"></iconify-icon> ' + (r.total_mengajar || 0) + ' Rombel';
        html += '  </button>';
        html += '</td>';
        // 11. Aksi
        html += '<td style="text-align:center;">';
        html += '  <div class="row-actions" style="display:flex;gap:6px;justify-content:center;">';
        html += '    <button type="button" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--sm" data-act="edit" data-id="' + r.id + '"><iconify-icon icon="solar:pen-bold" width="13"></iconify-icon> Edit</button>';
        html += '    <button type="button" class="ilmuna-btn ilmuna-btn--danger ilmuna-btn--sm" data-act="delete" data-id="' + r.id + '"><iconify-icon icon="solar:trash-bin-trash-bold" width="13"></iconify-icon> Hapus</button>';
        html += '  </div>';
        html += '</td>';

        return html;
    }

    function insertRowInPlace(r) {
        var tbody = document.getElementById('tableGuruBody');
        var emptyRow = tbody.querySelector('.ilmuna-empty-row');
        if (emptyRow) tbody.innerHTML = '';

        var tr = document.createElement('tr');
        tr.setAttribute('data-id', r.id);
        tr.className = 'ilmuna-row-slide-in ilmuna-row-highlight-new';
        tr.innerHTML = buildGuruRowCellsHtml(r, 1, false);

        var firstTr = tbody.querySelector('tr[data-id]');
        if (firstTr) {
            tbody.insertBefore(tr, firstTr);
        } else {
            var sentinel = document.getElementById('lazySentinelGuru');
            if (sentinel) tbody.insertBefore(tr, sentinel);
            else tbody.appendChild(tr);
        }

        renumberGuruRows();
        state.total++;
        renderInfo();
        renderPagination();
        try { tr.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } catch(e) {}
    }

    function updateRowInPlace(r) {
        var tbody = document.getElementById('tableGuruBody');
        var tr = tbody.querySelector('tr[data-id="' + r.id + '"]');
        if (tr) {
            var curNum = tr.querySelector('.guru-cell-num') ? tr.querySelector('.guru-cell-num').textContent : '1';
            var isChecked = selectedGuru.has(parseInt(r.id, 10));
            tr.innerHTML = buildGuruRowCellsHtml(r, curNum, isChecked);
            tr.classList.remove('ilmuna-row-highlight-updated');
            void tr.offsetWidth;
            tr.classList.add('ilmuna-row-highlight-updated');
            updateBulkBar();
        } else {
            insertRowInPlace(r);
        }
    }

    function removeRowInPlace(id) {
        var tbody = document.getElementById('tableGuruBody');
        var tr = tbody.querySelector('tr[data-id="' + id + '"]');
        if (tr) {
            tr.style.transition = 'all 0.35s ease';
            tr.style.opacity = '0';
            tr.style.transform = 'translateX(35px)';
            setTimeout(function(){
                if (tr.parentNode) tr.parentNode.removeChild(tr);
                renumberGuruRows();
                var remaining = tbody.querySelectorAll('tr[data-id]');
                if (remaining.length === 0) renderRows([]);
            }, 350);
            selectedGuru.delete(parseInt(id, 10));
            updateBulkBar();
            state.total = Math.max(0, state.total - 1);
            renderInfo();
            renderPagination();
        }
    }

    function renumberGuruRows() {
        var tbody = document.getElementById('tableGuruBody');
        var rows = tbody.querySelectorAll('tr[data-id]');
        rows.forEach(function(row, idx){
            var numCell = row.querySelector('.guru-cell-num');
            if (numCell) numCell.textContent = idx + 1;
        });
    }

    var lazyObserver = null;
    function initLazyLoad() {
        if (!('IntersectionObserver' in window)) return;
        var sentinel = document.getElementById('lazySentinelGuru');
        if (!sentinel) return;
        if (lazyObserver) lazyObserver.disconnect();
        lazyObserver = new IntersectionObserver(function(entries){
            entries.forEach(function(entry){
                if (entry.isIntersecting && !state.loading && state.page < state.pages) {
                    state.page++;
                    fetchList({ append: true });
                }
            });
        }, { rootMargin: '250px' });
        lazyObserver.observe(sentinel);
    }

    function fetchList(opts) {
        opts = opts || {};
        var tbody = document.getElementById('tableGuruBody');
        if (state.loading) return;
        state.loading = true;

        var sentinel = document.getElementById('lazySentinelGuru');

        if (opts.append) {
            if (sentinel) sentinel.style.display = '';
        } else if (opts.isLiveSearch) {
            tbody.style.opacity = '0.7';
            tbody.style.transition = 'opacity 0.15s ease';
        } else if (!opts.silent) {
            tbody.innerHTML =
                '<tr><td colspan="11" class="ilmuna-table-loading">' +
                '<iconify-icon icon="svg-spinners:ring-resize" width="28"></iconify-icon>' +
                '<span>Memuat data guru...</span></td></tr>';
        }

        var fd = new FormData();
        fd.append('action', 'list');
        fd.append('search', state.search);
        fd.append('f_status', state.f_status);
        fd.append('f_gender', state.f_gender);
        fd.append('page', state.page);
        fd.append('per_page', state.per_page);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                state.loading = false;
                tbody.style.opacity = '1';
                if (!res || !res.success) {
                    if (!opts.append) {
                        tbody.innerHTML = '<tr><td colspan="11" class="ilmuna-table-loading" style="color:#DC2626;">Gagal memuat data guru.</td></tr>';
                    }
                    toast('error', (res && res.data && res.data.message) || 'Gagal memuat data.');
                    return;
                }
                var d = res.data;
                state.total = d.total;
                state.pages = d.pages;
                renderRows(d.rows, opts);
                renderPagination();
                renderInfo();
                if (d.stats) updateStats(d.stats);
            })
            .catch(function(){
                state.loading = false;
                tbody.style.opacity = '1';
                if (!opts.append) {
                    tbody.innerHTML = '<tr><td colspan="11" class="ilmuna-table-loading" style="color:#DC2626;">Koneksi ke server terputus.</td></tr>';
                }
                toast('error', 'Koneksi ke server terputus.');
            });
    }

    function updateStats(s) {
        if (!s) return;
        var elTotal = document.getElementById('statValTotal');
        var elPNS   = document.getElementById('statValPNS');
        var elPPPK  = document.getElementById('statValPPPK');
        var elAkun  = document.getElementById('statValAkun');
        if (elTotal) elTotal.textContent = s.total || 0;
        if (elPNS)   elPNS.textContent   = s.pns || 0;
        if (elPPPK)  elPPPK.textContent  = (s.pppk + s.gtt) || 0;
        if (elAkun)  elAkun.textContent  = s.akun || 0;
    }

    function renderRows(rows, opts) {
        opts = opts || {};
        var tbody = document.getElementById('tableGuruBody');

        if (!opts.append && (!rows || !rows.length)) {
            tbody.innerHTML =
                '<tr><td colspan="11"><div class="ilmuna-empty-row" style="text-align:center;padding:36px;color:#64748B;">' +
                '<iconify-icon icon="solar:user-speak-bold" width="46" style="color:#CBD5E1;margin-bottom:8px;"></iconify-icon>' +
                '<p style="font-weight:700;color:#1E293B;margin:0 0 4px;">Belum ada data guru ditemukan</p>' +
                '<small>Tambahkan data guru baru atau sesuaikan kata kunci pencarian.</small>' +
                '</div></td></tr>';
            updateBulkBar();
            return;
        }

        var html = '';
        var startNum = opts.append ? tbody.querySelectorAll('tr[data-id]').length : (state.page - 1) * state.per_page;
        rows.forEach(function(r, i){
            var no = startNum + i + 1;
            var idNum = parseInt(r.id, 10);
            var isChecked = selectedGuru.has(idNum);

            html += '<tr data-id="' + r.id + '" class="ilmuna-row-slide-in ' + (isChecked ? 'is-selected' : '') + '">';
            html += buildGuruRowCellsHtml(r, no, isChecked);
            html += '</tr>';
        });

        var sentinelHtml = '<tr id="lazySentinelGuru" class="ilmuna-table-sentinel" style="display:' + (state.page < state.pages ? '' : 'none') + ';"><td colspan="11"><iconify-icon icon="svg-spinners:ring-resize" width="18" style="vertical-align: middle; margin-right: 6px;"></iconify-icon> Memuat data guru berikutnya...</td></tr>';

        if (opts.append) {
            var oldSentinel = document.getElementById('lazySentinelGuru');
            if (oldSentinel) oldSentinel.remove();
            tbody.insertAdjacentHTML('beforeend', html + sentinelHtml);
        } else {
            tbody.innerHTML = html + sentinelHtml;
        }

        updateBulkBar();
        initLazyLoad();
    }

    function renderPagination() {
        var infoEl = document.getElementById('guruPagInfo');
        var btnsEl = document.getElementById('guruPagBtns');
        var currentLoaded = document.querySelectorAll('#tableGuruBody tr[data-id]').length;
        var end = Math.min(Math.max(currentLoaded, state.page * state.per_page), state.total);
        var start = state.total === 0 ? 0 : 1;

        infoEl.innerHTML = 'Menampilkan <strong>' + start + '</strong>–<strong>' + end + '</strong> dari <strong>' + state.total + '</strong> guru';

        var html = '';
        html += '<button type="button" class="ilmuna-pag-btn" data-page="1" ' + (state.page <= 1 ? 'disabled' : '') + ' title="Halaman Pertama">«</button>';
        html += '<button type="button" class="ilmuna-pag-btn" data-page="' + (state.page - 1) + '" ' + (state.page <= 1 ? 'disabled' : '') + ' title="Sebelumnya">‹</button>';

        var totalPages = state.pages;
        var from = Math.max(1, state.page - 2);
        var to   = Math.min(totalPages, state.page + 2);

        if (from > 1) html += '<span style="padding:0 4px;color:#94A3B8;">…</span>';
        for (var p = from; p <= to; p++) {
            html += '<button type="button" class="ilmuna-pag-btn ' + (p === state.page ? 'is-active' : '') + '" data-page="' + p + '">' + p + '</button>';
        }
        if (to < totalPages) html += '<span style="padding:0 4px;color:#94A3B8;">…</span>';

        html += '<button type="button" class="ilmuna-pag-btn" data-page="' + (state.page + 1) + '" ' + (state.page >= totalPages ? 'disabled' : '') + ' title="Berikutnya">›</button>';
        html += '<button type="button" class="ilmuna-pag-btn" data-page="' + totalPages + '" ' + (state.page >= totalPages ? 'disabled' : '') + ' title="Halaman Terakhir">»</button>';
        btnsEl.innerHTML = html;
    }

    function renderInfo() {
        var info = document.getElementById('guruCrudInfo');
        var chips = '';
        chips += '<span class="ilmuna-info-chip"><iconify-icon icon="solar:user-speak-bold" width="14"></iconify-icon> Total: ' + state.total + ' Guru</span>';
        if (state.f_status) chips += '<span class="ilmuna-info-chip"><iconify-icon icon="solar:tag-bold" width="14"></iconify-icon> Status: ' + escHtml(state.f_status) + '</span>';
        if (state.f_gender) chips += '<span class="ilmuna-info-chip"><iconify-icon icon="solar:user-bold" width="14"></iconify-icon> Gender: ' + (state.f_gender === 'P' ? 'Perempuan' : 'Laki-laki') + '</span>';
        if (state.search)   chips += '<span class="ilmuna-info-chip"><iconify-icon icon="solar:magnifer-bold" width="14"></iconify-icon> Cari: "' + escHtml(state.search) + '"</span>';
        info.innerHTML = chips;
    }

    var searchInputGuru = document.getElementById('inputSearchGuru');
    var searchTimerGuru = null;
    var searchBoxGuru = searchInputGuru ? searchInputGuru.closest('.ilmuna-search-box') : null;

    if (searchInputGuru) {
        searchInputGuru.addEventListener('input', function(){
            var val = this.value;
            if (searchBoxGuru) searchBoxGuru.classList.add('is-searching');
            clearTimeout(searchTimerGuru);
            searchTimerGuru = setTimeout(function(){
                state.search = val.trim();
                state.page = 1;
                fetchList({ isLiveSearch: true, reset: true });
                if (searchBoxGuru) searchBoxGuru.classList.remove('is-searching');
            }, 180);
        });

        searchInputGuru.addEventListener('keydown', function(e){
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(searchTimerGuru);
                state.search = this.value.trim();
                state.page = 1;
                fetchList({ isLiveSearch: true, reset: true });
                if (searchBoxGuru) searchBoxGuru.classList.remove('is-searching');
            }
        });
    }

    document.getElementById('btnSearchGuru').addEventListener('click', function(){
        clearTimeout(searchTimerGuru);
        state.search = document.getElementById('inputSearchGuru').value.trim();
        state.page = 1;
        fetchList({ isLiveSearch: true, reset: true });
    });

    document.getElementById('btnResetGuru').addEventListener('click', function(){
        clearTimeout(searchTimerGuru);
        document.getElementById('inputSearchGuru').value = '';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterGender').value = '';
        state.search = ''; state.f_status = ''; state.f_gender = '';
        state.page = 1;
        fetchList({ isLiveSearch: true, reset: true });
    });
    document.getElementById('filterStatus').addEventListener('change', function(){
        state.f_status = this.value; state.page = 1; fetchList({ isLiveSearch: true, reset: true });
    });
    document.getElementById('filterGender').addEventListener('change', function(){
        state.f_gender = this.value; state.page = 1; fetchList({ isLiveSearch: true, reset: true });
    });
    document.getElementById('filterPerPage').addEventListener('change', function(){
        state.per_page = parseInt(this.value) || 20; state.page = 1; fetchList({ isLiveSearch: true, reset: true });
    });
    document.getElementById('guruPagBtns').addEventListener('click', function(e){
        var btn = e.target.closest('.ilmuna-pag-btn');
        if (!btn || btn.disabled) return;
        var p = parseInt(btn.dataset.page) || 1;
        if (p === state.page) return;
        state.page = p;
        fetchList();
    });

    document.getElementById('btnTambahGuru').addEventListener('click', function(){
        document.getElementById('modalGuruTitle').textContent = 'Tambah Data Guru Baru';
        var form = document.getElementById('formGuru');
        form.reset();
        form.querySelector('[name="id"]').value = '0';
        var kodeInput = document.getElementById('inputKodeGuru');
        if (kodeInput) kodeInput.value = '';
        document.getElementById('inputDeleteFotoGuru').value = '0';
        document.getElementById('inputFotoGuru').value = '';
        document.getElementById('previewFotoGuru').innerHTML = '<iconify-icon icon="solar:user-bold" width="34" style="color: #94A3B8;"></iconify-icon>';
        document.getElementById('btnRemoveFotoGuru').style.display = 'none';
        document.getElementById('wrapCreateAccount').style.display = 'block';
        openModal('modalGuru');
    });

    window.previewFotoGuru = function(src, name) {
        document.getElementById('previewFotoTitle').textContent = 'Foto: ' + (name || 'Guru');
        document.getElementById('previewFotoImg').src = src;
        openModal('modalPreviewFotoGuru');
    };

    var btnBrowseFoto = document.getElementById('btnBrowseFotoGuru');
    var inputFoto = document.getElementById('inputFotoGuru');
    var btnRemoveFoto = document.getElementById('btnRemoveFotoGuru');
    var previewFoto = document.getElementById('previewFotoGuru');
    var inputDeleteFoto = document.getElementById('inputDeleteFotoGuru');

    if (btnBrowseFoto && inputFoto) {
        btnBrowseFoto.addEventListener('click', function(){
            inputFoto.click();
        });
    }

    if (inputFoto) {
        inputFoto.addEventListener('change', function(e){
            var file = e.target.files && e.target.files[0];
            if (!file) return;

            if (file.size > 3 * 1024 * 1024) {
                toast('warning', 'Ukuran file foto maksimal 3MB.');
                inputFoto.value = '';
                return;
            }

            var validTypes = ['image/jpeg', 'image/png', 'image/webp'];
            if (validTypes.indexOf(file.type) === -1) {
                toast('warning', 'Format file harus JPG, PNG, atau WEBP.');
                inputFoto.value = '';
                return;
            }

            var reader = new FileReader();
            reader.onload = function(evt) {
                previewFoto.innerHTML = '<img src="' + evt.target.result + '" alt="Preview">';
                btnRemoveFoto.style.display = 'inline-flex';
                inputDeleteFoto.value = '0';
            };
            reader.readAsDataURL(file);
        });
    }

    if (btnRemoveFoto) {
        btnRemoveFoto.addEventListener('click', function(){
            inputFoto.value = '';
            inputDeleteFoto.value = '1';
            previewFoto.innerHTML = '<iconify-icon icon="solar:user-bold" width="34" style="color: #94A3B8;"></iconify-icon>';
            btnRemoveFoto.style.display = 'none';
        });
    }

    document.getElementById('tableGuruBody').addEventListener('click', function(e){
        var btn = e.target.closest('[data-act]');
        if (!btn) return;
        var act = btn.dataset.act;
        var id  = parseInt(btn.dataset.id) || 0;
        if (!id) return;
        if (act === 'edit')          openEdit(id);
        if (act === 'delete')        doDelete(id);
        if (act === 'penugasan')     openPenugasan(id);
        if (act === 'mapping_mapel') openMappingMapel(id);
    });

    function openEdit(id) {
        var fd = new FormData();
        fd.append('action', 'get_single');
        fd.append('id', id);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal memuat data guru.');
                    return;
                }
                var row = res.data.row;
                document.getElementById('modalGuruTitle').textContent = 'Edit Data Guru & Pendidik';
                var form = document.getElementById('formGuru');
                form.querySelector('[name="id"]').value = row.id;
                form.querySelector('[name="kode_guru"]').value = row.kode_guru || '';
                form.querySelector('[name="nama_guru"]').value = row.nama_guru || '';
                form.querySelector('[name="nip"]').value = row.nip || '';
                form.querySelector('[name="nuptk"]').value = row.nuptk || '';
                form.querySelector('[name="jenis_kelamin"]').value = row.jenis_kelamin || 'L';
                form.querySelector('[name="status_kepegawaian"]').value = row.status_kepegawaian || 'Tetap';
                form.querySelector('[name="no_hp"]').value = row.no_hp || '';
                form.querySelector('[name="email"]').value = row.email || '';
                form.querySelector('[name="alamat"]').value = row.alamat || '';
                document.getElementById('inputDeleteFotoGuru').value = '0';
                document.getElementById('inputFotoGuru').value = '';
                if (row.foto) {
                    document.getElementById('previewFotoGuru').innerHTML = '<img src="../../' + escHtml(row.foto) + '" alt="Foto Guru">';
                    document.getElementById('btnRemoveFotoGuru').style.display = 'inline-flex';
                } else {
                    document.getElementById('previewFotoGuru').innerHTML = '<iconify-icon icon="solar:user-bold" width="34" style="color: #94A3B8;"></iconify-icon>';
                    document.getElementById('btnRemoveFotoGuru').style.display = 'none';
                }
                document.getElementById('wrapCreateAccount').style.display = row.id_user ? 'none' : 'block';
                openModal('modalGuru');
            })
            .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
    }

    // Auto-Generate Kode Guru
    var codeTimerGuru = null;
    function triggerAutoGenerateTeacherCode(force) {
        var nameInput = document.getElementById('inputNamaGuru');
        var kodeInput = document.getElementById('inputKodeGuru');
        var idInput   = document.querySelector('#formGuru [name="id"]');
        if (!nameInput || !kodeInput) return;

        var nama = nameInput.value.trim();
        var id   = parseInt(idInput ? idInput.value : 0) || 0;

        if (!nama) {
            if (id === 0) kodeInput.value = '';
            return;
        }

        if (id === 0 || force || !kodeInput.value.trim()) {
            var fd = new FormData();
            fd.append('action', 'generate_code');
            fd.append('nama_guru', nama);
            fd.append('id', id);
            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (res && res.success && res.data && res.data.kode_guru) {
                        kodeInput.value = res.data.kode_guru;
                    }
                })
                .catch(function(){});
        }
    }

    var inputNamaGuruEl = document.getElementById('inputNamaGuru');
    if (inputNamaGuruEl) {
        inputNamaGuruEl.addEventListener('input', function(){
            clearTimeout(codeTimerGuru);
            codeTimerGuru = setTimeout(function(){
                triggerAutoGenerateTeacherCode(false);
            }, 300);
        });
    }

    var btnRegenKodeGuruEl = document.getElementById('btnRegenerateKodeGuru');
    if (btnRegenKodeGuruEl) {
        btnRegenKodeGuruEl.addEventListener('click', function(){
            var btn = this;
            var icon = btn.querySelector('iconify-icon');
            if (icon) {
                icon.style.transform = 'rotate(180deg)';
                icon.style.transition = 'transform 0.3s ease';
                setTimeout(function(){ icon.style.transform = 'none'; }, 300);
            }
            triggerAutoGenerateTeacherCode(true);
        });
    }

    document.getElementById('btnSaveGuru').addEventListener('click', function(){
        var btn = this;
        var form = document.getElementById('formGuru');
        var nama = form.querySelector('[name="nama_guru"]').value.trim();

        if (!nama) { toast('warning', 'Nama lengkap guru wajib diisi.'); return; }

        btn.disabled = true;
        var fd = new FormData(form);
        fd.append('action', 'save');

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal menyimpan.');
                    return;
                }
                toast('success', res.data.message);
                closeModal('modalGuru');

                if (res.data.row) {
                    if (res.data.mode === 'insert') {
                        insertRowInPlace(res.data.row);
                    } else {
                        updateRowInPlace(res.data.row);
                    }
                } else {
                    fetchList({ silent: true });
                }
                if (res.data.stats) updateStats(res.data.stats);
            })
            .catch(function(){
                btn.disabled = false;
                toast('error', 'Koneksi bermasalah.');
            });
    });

    function doDelete(id) {
        confirmDialog('Yakin ingin menghapus guru ini? Penugasan mengajar di kelas mapel akan dilepaskan secara aman.', {
            title: 'Hapus Data Guru?',
            okText: 'Ya, Hapus Data'
        }).then(function(ok){
            if (!ok) return;
            var fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', id);

            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (!res || !res.success) {
                        toast('error', (res && res.data && res.data.message) || 'Gagal menghapus guru.');
                        return;
                    }
                    toast('success', res.data.message);
                    removeRowInPlace(id);
                    if (res.data.stats) updateStats(res.data.stats);
                })
                .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
        });
    }

    function openPenugasan(id) {
        document.getElementById('penugasanGuruName').textContent = '—';
        document.getElementById('penugasanNama').textContent = 'Memuat...';
        document.getElementById('penugasanMeta').textContent = '';
        document.getElementById('penugasanTableBody').innerHTML =
            '<tr><td colspan="5" class="ilmuna-table-loading">' +
            '<iconify-icon icon="svg-spinners:ring-resize" width="24"></iconify-icon>' +
            '<span>Memuat penugasan mengajar...</span></td></tr>';
        openModal('modalPenugasan');

        var fd = new FormData();
        fd.append('action', 'get_mengajar');
        fd.append('id', id);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal memuat penugasan.');
                    return;
                }
                var g = res.data.guru || {};
                var list = res.data.mengajar || [];

                document.getElementById('penugasanGuruName').textContent = g.nama_guru || '—';
                document.getElementById('penugasanNama').textContent = g.nama_guru || '-';
                document.getElementById('penugasanMeta').textContent = 'NIP: ' + (g.nip || '-') + ' | Status: ' + (g.status_kepegawaian || 'Tetap') + (g.no_hp ? ' | WA: ' + g.no_hp : '');
                document.getElementById('penugasanAvatar').textContent = getInitials(g.nama_guru);

                var tbody = document.getElementById('penugasanTableBody');
                if (!list.length) {
                    tbody.innerHTML =
                        '<tr><td colspan="5" style="text-align:center;padding:24px;color:#64748B;">' +
                        '<iconify-icon icon="solar:notebook-bookmark-bold" width="36" style="color:#CBD5E1;margin-bottom:6px;"></iconify-icon>' +
                        '<p style="margin:0;font-weight:700;">Guru ini belum ditugaskan mengajar di rombel kelas manapun.</p>' +
                        '<small>Gunakan tombol "Kelola di Halaman Kelas" untuk menetapkan guru ini pada mata pelajaran kelas.</small>' +
                        '</td></tr>';
                    return;
                }

                var html = '';
                list.forEach(function(item, idx){
                    html += '<tr>';
                    html += '<td style="font-family:monospace;font-weight:700;color:#64748B;">' + (idx + 1) + '</td>';
                    html += '<td><strong>' + escHtml(item.nama_mata_pelajaran || '-') + '</strong></td>';
                    html += '<td><span class="cell-badge cell-badge--pns">' + escHtml(item.nama_kelas || '-') + '</span></td>';
                    html += '<td>Semester ' + escHtml(item.semester || '-') + '</td>';
                    html += '<td style="text-align:center;"><span class="cell-badge cell-badge--pppk">' + (item.total_siswa || 0) + ' Siswa</span></td>';
                    html += '</tr>';
                });
                tbody.innerHTML = html;
            })
            .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
    }

    var currentMappingMapelData = [];
    var currentAssignedMapelSet = new Set();

    function renderMappingMapelPicker(filterText) {
        var container = document.getElementById('listMapelGuruPicker');
        var q = (filterText || '').toLowerCase().trim();
        var filtered = currentMappingMapelData.filter(function(m){
            if (!q) return true;
            return (m.nama_mata_pelajaran || '').toLowerCase().indexOf(q) !== -1;
        });

        if (!filtered.length) {
            container.innerHTML = '<div style="text-align:center;padding:24px;color:#94A3B8;">Tidak ada mata pelajaran yang cocok dengan filter "' + escHtml(q) + '".</div>';
            return;
        }

        var html = '';
        filtered.forEach(function(m){
            var isChecked = currentAssignedMapelSet.has(parseInt(m.id, 10));
            html += '<label class="ilmuna-mapel-card ' + (isChecked ? 'is-checked' : '') + '" data-id="' + m.id + '">';
            html += '  <input type="checkbox" class="cb-mapel-item" value="' + m.id + '" ' + (isChecked ? 'checked' : '') + '>';
            html += '  <div class="ilmuna-mapel-card__info">';
            html += '    <span class="ilmuna-mapel-card__title">' + escHtml(m.nama_mata_pelajaran) + (m.kode_mapel ? ' <span style="font-size:11.5px;color:#0D7A87;font-weight:700;margin-left:4px;">(' + escHtml(m.kode_mapel) + ')</span>' : '') + '</span>';
            html += '    <div class="ilmuna-mapel-card__meta">';
            html += '      <span><iconify-icon icon="solar:calendar-bold" width="12"></iconify-icon> ' + escHtml(m.tahun_pelajaran || '-') + '</span>';
            html += '      <span>• Semester ' + escHtml(m.semester || '-') + '</span>';
            html += '    </div>';
            html += '  </div>';
            html += '</label>';
        });
        container.innerHTML = html;
        updateMappingCountInfo();
    }

    function updateMappingCountInfo() {
        var info = document.getElementById('mappingSelectedCountInfo');
        if (info) info.textContent = currentAssignedMapelSet.size + ' mata pelajaran dipilih';
    }

    function openMappingMapel(id) {
        document.getElementById('mappingGuruId').value = id;
        document.getElementById('inputFilterMapelModal').value = '';
        document.getElementById('listMapelGuruPicker').innerHTML =
            '<div style="text-align:center;padding:24px;color:#64748B;">' +
            '<iconify-icon icon="svg-spinners:ring-resize" width="26"></iconify-icon>' +
            '<p style="margin:6px 0 0;">Memuat daftar mata pelajaran...</p></div>';
        openModal('modalMappingMapelGuru');

        var fd = new FormData();
        fd.append('action', 'get_guru_mapel');
        fd.append('id', id);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal memuat relasi mapel.');
                    return;
                }
                var g = res.data.guru || {};
                document.getElementById('modalMappingGuruTitle').textContent = 'Mapping Mapel: ' + (g.nama_guru || 'Guru');
                document.getElementById('modalMappingGuruSubtitle').textContent = 'NIP: ' + (g.nip || '-') + ' | Status: ' + (g.status_kepegawaian || 'Tetap');

                currentMappingMapelData = res.data.all_mapel || [];
                currentAssignedMapelSet = new Set(res.data.assigned || []);
                renderMappingMapelPicker('');
            })
            .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
    }

    document.getElementById('listMapelGuruPicker').addEventListener('change', function(e){
        if (e.target && e.target.classList.contains('cb-mapel-item')) {
            var mid = parseInt(e.target.value, 10);
            var card = e.target.closest('.ilmuna-mapel-card');
            if (e.target.checked) {
                currentAssignedMapelSet.add(mid);
                if (card) card.classList.add('is-checked');
            } else {
                currentAssignedMapelSet.delete(mid);
                if (card) card.classList.remove('is-checked');
            }
            updateMappingCountInfo();
        }
    });

    document.getElementById('inputFilterMapelModal').addEventListener('input', function(){
        renderMappingMapelPicker(this.value);
    });

    document.getElementById('btnSelectAllMapel').addEventListener('click', function(){
        currentMappingMapelData.forEach(function(m){
            currentAssignedMapelSet.add(parseInt(m.id, 10));
        });
        renderMappingMapelPicker(document.getElementById('inputFilterMapelModal').value);
    });
    document.getElementById('btnClearAllMapel').addEventListener('click', function(){
        currentAssignedMapelSet.clear();
        renderMappingMapelPicker(document.getElementById('inputFilterMapelModal').value);
    });

    document.getElementById('btnSaveMappingMapel').addEventListener('click', function(){
        var btn = this;
        var guruId = parseInt(document.getElementById('mappingGuruId').value, 10);
        if (!guruId) return;

        btn.disabled = true;
        btn.innerHTML = '<iconify-icon icon="svg-spinners:ring-resize" width="16"></iconify-icon> Menyimpan...';

        var fd = new FormData();
        fd.append('action', 'save_guru_mapel');
        fd.append('id_guru', guruId);
        currentAssignedMapelSet.forEach(function(mid){
            fd.append('mapel_ids[]', mid);
        });

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                btn.innerHTML = '<iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon> Simpan Relasi Mapel';
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal menyimpan mapping.');
                    return;
                }
                toast('success', res.data.message);
                closeModal('modalMappingMapelGuru');
                if (res.data && res.data.row) {
                    updateRowInPlace(res.data.row);
                } else {
                    fetchList({ silent: true });
                }
                if (res.data && res.data.stats) updateStats(res.data.stats);
            })
            .catch(function(){
                btn.disabled = false;
                btn.innerHTML = '<iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon> Simpan Relasi Mapel';
                toast('error', 'Koneksi bermasalah.');
            });
    });

    var currentBulkSelectedMapel = new Set();

    document.getElementById('btnBulkOpenMapel').addEventListener('click', function(){
        if (!selectedGuru.size) return;
        document.getElementById('bulkMapelCountText').textContent = selectedGuru.size;
        currentBulkSelectedMapel.clear();

        var container = document.getElementById('listBulkMapelPicker');
        container.innerHTML =
            '<div style="text-align:center;padding:24px;color:#64748B;">' +
            '<iconify-icon icon="svg-spinners:ring-resize" width="26"></iconify-icon>' +
            '<p style="margin:6px 0 0;">Memuat mata pelajaran...</p></div>';
        openModal('modalBulkAssignMapel');

        var fd = new FormData();
        fd.append('action', 'get_guru_mapel');
        fd.append('id', Array.from(selectedGuru)[0]);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res || !res.success) {
                    container.innerHTML = '<p style="color:#DC2626;text-align:center;">Gagal memuat mata pelajaran.</p>';
                    return;
                }
                var list = res.data.all_mapel || [];
                var html = '';
                list.forEach(function(m){
                    html += '<label class="ilmuna-mapel-card" data-id="' + m.id + '">';
                    html += '  <input type="checkbox" class="cb-bulk-mapel" value="' + m.id + '">';
                    html += '  <div class="ilmuna-mapel-card__info">';
                    html += '    <span class="ilmuna-mapel-card__title">' + escHtml(m.nama_mata_pelajaran) + '</span>';
                    html += '    <div class="ilmuna-mapel-card__meta">Semester ' + escHtml(m.semester || '-') + ' • ' + escHtml(m.tahun_pelajaran || '-') + '</div>';
                    html += '  </div>';
                    html += '</label>';
                });
                container.innerHTML = html;
            })
            .catch(function(){ container.innerHTML = '<p style="color:#DC2626;text-align:center;">Koneksi bermasalah.</p>'; });
    });

    document.getElementById('listBulkMapelPicker').addEventListener('change', function(e){
        if (e.target && e.target.classList.contains('cb-bulk-mapel')) {
            var mid = parseInt(e.target.value, 10);
            var card = e.target.closest('.ilmuna-mapel-card');
            if (e.target.checked) {
                currentBulkSelectedMapel.add(mid);
                if (card) card.classList.add('is-checked');
            } else {
                currentBulkSelectedMapel.delete(mid);
                if (card) card.classList.remove('is-checked');
            }
        }
    });

    document.getElementById('btnSubmitBulkMapel').addEventListener('click', function(){
        if (!currentBulkSelectedMapel.size) {
            toast('warning', 'Pilih minimal satu mata pelajaran.');
            return;
        }

        var btn = this;
        btn.disabled = true;

        var fd = new FormData();
        fd.append('action', 'bulk_assign_mapel');
        selectedGuru.forEach(function(gid){
            fd.append('ids[]', gid);
        });
        currentBulkSelectedMapel.forEach(function(mid){
            fd.append('mapel_ids[]', mid);
        });

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal menetapkan mapel.');
                    return;
                }
                toast('success', res.data.message);
                closeModal('modalBulkAssignMapel');
                selectedGuru.clear();
                updateBulkBar();
                fetchList({ silent: true });
            })
            .catch(function(){
                btn.disabled = false;
                toast('error', 'Koneksi bermasalah.');
            });
    });

    function triggerDownloadTemplate(btn) {
        var url = EXCEL_URL + '?action=download_template';
        downloadBlob(url, 'template-data-guru-ilmuna.xlsx', btn, 'Mengunduh...');
    }

    var btnDownloadTpl = document.getElementById('btnDownloadTemplateGuru');
    if (btnDownloadTpl) {
        btnDownloadTpl.addEventListener('click', function(e){
            e.preventDefault();
            triggerDownloadTemplate(this);
        });
    }

    var btnModalDownloadTpl = document.getElementById('btnModalDownloadTemplateGuru');
    if (btnModalDownloadTpl) {
        btnModalDownloadTpl.addEventListener('click', function(e){
            e.preventDefault();
            triggerDownloadTemplate(this);
        });
    }

    document.getElementById('btnExportGuru').addEventListener('click', function(e){
        e.preventDefault();
        var url = EXCEL_URL + '?action=export_excel';
        if (state.search)   url += '&search=' + encodeURIComponent(state.search);
        if (state.f_status) url += '&f_status=' + encodeURIComponent(state.f_status);
        if (state.f_gender) url += '&f_gender=' + encodeURIComponent(state.f_gender);
        downloadBlob(url, 'data-guru-ilmuna.xlsx', this, 'Mengekspor...');
    });

    var dropzone = document.getElementById('guruDropzone');
    var fileInput = document.getElementById('inputImportFile');
    var selectedFileName = document.getElementById('guruSelectedFileName');

    function updateFileDisplay() {
        if (!selectedFileName || !fileInput) return;
        if (fileInput.files && fileInput.files[0]) {
            selectedFileName.textContent = 'Berkas terpilih: ' + fileInput.files[0].name + ' (' + (fileInput.files[0].size / 1024).toFixed(1) + ' KB)';
            selectedFileName.style.display = 'block';
        } else {
            selectedFileName.textContent = '';
            selectedFileName.style.display = 'none';
        }
    }

    if (dropzone && fileInput) {
        ['dragenter', 'dragover'].forEach(function(evtName){
            dropzone.addEventListener(evtName, function(e){
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function(evtName){
            dropzone.addEventListener(evtName, function(e){
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('is-dragover');
            });
        });
        dropzone.addEventListener('drop', function(e){
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                updateFileDisplay();
            }
        });
        fileInput.addEventListener('change', updateFileDisplay);
    }

    document.getElementById('btnImportGuru').addEventListener('click', function(){
        document.getElementById('inputImportFile').value = '';
        updateFileDisplay();
        var log = document.getElementById('guruImportLog');
        log.style.display = 'none';
        log.innerHTML = '';
        openModal('modalImportGuru');
    });

    document.getElementById('btnDoImportGuru').addEventListener('click', function(){
        var btn = this;
        var fileInp = document.getElementById('inputImportFile');
        if (!fileInp.files || !fileInp.files[0]) {
            toast('warning', 'Pilih file berkas Excel (.xlsx) terlebih dahulu.');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<iconify-icon icon="svg-spinners:ring-resize" width="16"></iconify-icon> Mengunggah & Memproses...';

        var fd = new FormData();
        fd.append('action', 'import_excel');
        fd.append('file', fileInp.files[0]);

        fetch(EXCEL_URL, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r){ return r.json(); })
        .then(function(res){
            btn.disabled = false;
            btn.innerHTML = '<iconify-icon icon="solar:cloud-upload-bold" width="16"></iconify-icon> Mulai Import';
            if (!res || !res.success) {
                toast('error', (res && res.data && res.data.message) || 'Gagal memproses import.');
                return;
            }

            toast('success', res.data.message);
            var logBox = document.getElementById('guruImportLog');
            logBox.style.display = 'block';

            var st = res.data.stat || {};
            var html = '<div class="ilmuna-import-stat">';
            html += '<span style="color:#059669;font-weight:700;">✓ Berhasil: ' + (st.ok||0) + '</span>';
            html += '<span style="color:#D97706;font-weight:700;">⚠ Dilewati: ' + (st.skip||0) + '</span>';
            html += '<span style="color:#DC2626;font-weight:700;">✕ Gagal: ' + (st.error||0) + '</span>';
            html += '</div>';

            (res.data.log || []).forEach(function(l){
                html += '<div class="ilmuna-import-row">';
                html += '<span style="font-family:monospace;color:#94A3B8;">Brs ' + l[0] + '</span>';
                html += '<span class="badge ' + l[1] + '">' + l[1].toUpperCase() + '</span>';
                html += '<span style="flex:1;">' + escHtml(l[2]) + '</span>';
                html += '</div>';
            });
            logBox.innerHTML = html;

            fetchList({ isLiveSearch: true, reset: true });
        })
        .catch(function(err){
            btn.disabled = false;
            btn.innerHTML = '<iconify-icon icon="solar:cloud-upload-bold" width="16"></iconify-icon> Mulai Import';
            toast('error', 'Koneksi bermasalah saat import: ' + (err.message || 'Error'));
        });
    });

    document.getElementById('checkAllGuru').addEventListener('change', function(){
        var checked = this.checked;
        document.querySelectorAll('.ilmuna-guru-cb').forEach(function(cb){
            cb.checked = checked;
            var id = parseInt(cb.value, 10);
            var row = cb.closest('tr');
            if (checked) {
                selectedGuru.add(id);
                if (row) row.classList.add('is-selected');
            } else {
                selectedGuru.delete(id);
                if (row) row.classList.remove('is-selected');
            }
        });
        updateBulkBar();
    });

    document.getElementById('tableGuruBody').addEventListener('change', function(e){
        if (e.target && e.target.classList.contains('ilmuna-guru-cb')) {
            var id = parseInt(e.target.value, 10);
            var row = e.target.closest('tr');
            if (e.target.checked) {
                selectedGuru.add(id);
                if (row) row.classList.add('is-selected');
            } else {
                selectedGuru.delete(id);
                if (row) row.classList.remove('is-selected');
            }
            updateBulkBar();
        }
    });

    document.getElementById('btnBulkCloseGuru').addEventListener('click', function(){
        selectedGuru.clear();
        document.querySelectorAll('.ilmuna-guru-cb').forEach(function(cb){
            cb.checked = false;
            var row = cb.closest('tr');
            if (row) row.classList.remove('is-selected');
        });
        updateBulkBar();
    });

    document.getElementById('btnBulkExportGuruSelected').addEventListener('click', function(e){
        e.preventDefault();
        if (!selectedGuru.size) return;
        var ids = Array.from(selectedGuru).join(',');
        var url = EXCEL_URL + '?action=export_excel&ids=' + encodeURIComponent(ids);
        downloadBlob(url, 'data-guru-terpilih-' + selectedGuru.size + '.xlsx', this, 'Mengekspor (' + selectedGuru.size + ')...');
    });

    document.getElementById('btnBulkDeleteGuru').addEventListener('click', function(){
        if (!selectedGuru.size) return;
        var count = selectedGuru.size;
        confirmDialog('Yakin ingin menghapus ' + count + ' data guru terpilih? Relasi pengampu di kelas akan dilepaskan secara otomatis.', {
            title: 'Hapus Masal (' + count + ' Guru)?',
            okText: 'Ya, Hapus Terpilih'
        }).then(function(ok){
            if (!ok) return;
            var fd = new FormData();
            fd.append('action', 'bulk_delete');
            selectedGuru.forEach(function(id){
                fd.append('ids[]', id);
            });

            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (!res || !res.success) {
                        toast('error', (res && res.data && res.data.message) || 'Gagal menghapus masal.');
                        return;
                    }
                    toast('success', res.data.message);
                    var toDelete = Array.from(selectedGuru);
                    toDelete.forEach(function(gid){
                        removeRowInPlace(gid);
                    });
                    selectedGuru.clear();
                    updateBulkBar();
                    if (res.data && res.data.stats) updateStats(res.data.stats);
                })
                .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
        });
    });

    document.getElementById('btnBulkOpenStatus').addEventListener('click', function(){
        if (!selectedGuru.size) return;
        document.getElementById('bulkStatusCountText').textContent = selectedGuru.size;
        openModal('modalBulkUpdateStatus');
    });

    document.getElementById('btnSubmitBulkStatus').addEventListener('click', function(){
        var btn = this;
        var status = document.getElementById('selectBulkStatus').value;
        if (!status) {
            toast('warning', 'Pilih status kepegawaian baru.');
            return;
        }

        btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'bulk_update_status');
        fd.append('status_kepegawaian', status);
        selectedGuru.forEach(function(id){
            fd.append('ids[]', id);
        });

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal memperbarui status kepegawaian.');
                    return;
                }
                toast('success', res.data.message);
                closeModal('modalBulkUpdateStatus');
                var badgeClass = 'cell-badge--gtt';
                if (status === 'PNS')  badgeClass = 'cell-badge--pns';
                if (status === 'PPPK') badgeClass = 'cell-badge--pppk';
                selectedGuru.forEach(function(gid){
                    var tr = document.querySelector('#tableGuruBody tr[data-id="' + gid + '"]');
                    if (tr) {
                        var statusCell = tr.querySelector('.guru-cell-status');
                        if (statusCell) {
                            statusCell.innerHTML = '<span class="cell-badge ' + badgeClass + '">' + escHtml(status) + '</span>';
                        }
                        tr.classList.remove('ilmuna-row-highlight-updated');
                        void tr.offsetWidth;
                        tr.classList.add('ilmuna-row-highlight-updated');
                    }
                });
                selectedGuru.clear();
                updateBulkBar();
                if (res.data && res.data.stats) updateStats(res.data.stats);
            })
            .catch(function(){
                btn.disabled = false;
                toast('error', 'Koneksi bermasalah.');
            });
    });

    document.getElementById('btnBulkCreateAccounts').addEventListener('click', function(){
        if (!selectedGuru.size) return;
        var count = selectedGuru.size;
        confirmDialog('Buat akun login portal secara otomatis untuk ' + count + ' guru terpilih? Guru yang belum memiliki akun akan dibuatkan akun aktif dengan kata sandi bawaan: guru123.', {
            title: 'Buat Akun Portal (' + count + ' Guru)?',
            okText: 'Ya, Buat Akun'
        }).then(function(ok){
            if (!ok) return;
            var fd = new FormData();
            fd.append('action', 'bulk_create_accounts');
            selectedGuru.forEach(function(id){
                fd.append('ids[]', id);
            });

            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (!res || !res.success) {
                        toast('error', (res && res.data && res.data.message) || 'Gagal membuat akun masal.');
                        return;
                    }
                    toast('success', res.data.message);
                    selectedGuru.clear();
                    fetchList();
                })
                .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
        });
    });

    fetchList();
})();
</script>

<?php
require_once BASE_PATH . '/includes/footer.php';