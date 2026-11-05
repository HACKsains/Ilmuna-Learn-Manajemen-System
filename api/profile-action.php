<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PROFILE ACTIONS & DATA SYNCHRONIZATION API
 * ==============================================================================
 * Handles AJAX requests for:
 * 1. update_account: Update user's credentials & profile in `users` table
 * 2. update_personal: Role-based update in `siswa` or `guru` table
 * 3. upload_photo: Sync avatar to `users` AND `siswa` / `guru`
 * 4. delete_photo: Remove avatar from both tables
 * 5. link_google / unlink_google: Google SSO linkage via users table
 * 6. change_password: Secure bcrypt password update
 * ==============================================================================
 */

header('Content-Type: application/json; charset=utf-8');

if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
if (function_exists('opcache_reset')) {
    @opcache_reset();
}
require_once BASE_PATH . '/auth.php';

// Authentication check
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi Anda telah berakhir. Silakan login kembali.']);
    exit;
}

$currentUser = getCurrentUser();
$userId      = (int)$currentUser['id'];
$activeRole  = getActiveRole() ?? 'siswa';
$pdo         = getDBConnection();

// Get action from POST, GET, or JSON body
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (empty($action)) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $jsonInput = json_decode($rawInput, true);
        if (is_array($jsonInput) && isset($jsonInput['action'])) {
            $action = $jsonInput['action'];
            $_POST = array_merge($_POST, $jsonInput);
        }
    }
}

/**
 * Helper: Detect existing columns in a table
 */
function getTableColumns(PDO $pdo, $table) {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $cache[$table] = $cols;
        return $cols;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Helper: Find Siswa row for current user
 */
function findSiswaRecord(PDO $pdo, $userId, $username, $email) {
    $siswaCols = getTableColumns($pdo, 'siswa');
    if (empty($siswaCols)) return null;

    // 1. Try id_user if column exists
    if (in_array('id_user', $siswaCols, true)) {
        $stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM siswa s LEFT JOIN kelas k ON s.id_kelas = k.id WHERE s.id_user = ? LIMIT 1");
        $stmt->execute([$userId]);
        $res = $stmt->fetch();
        if ($res) return $res;
    }

    // 2. Try by nisn or nis matching username
    if (in_array('nisn', $siswaCols, true)) {
        $stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM siswa s LEFT JOIN kelas k ON s.id_kelas = k.id WHERE s.nisn = ? OR s.nis = ? LIMIT 1");
        $stmt->execute([$username, $username]);
        $res = $stmt->fetch();
        if ($res) return $res;
    }

    // 3. Try by gmail matching user email
    if (in_array('gmail', $siswaCols, true) && !empty($email)) {
        $stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM siswa s LEFT JOIN kelas k ON s.id_kelas = k.id WHERE s.gmail = ? LIMIT 1");
        $stmt->execute([$email]);
        $res = $stmt->fetch();
        if ($res) return $res;
    }

    return null;
}

/**
 * Helper: Find Guru row for current user
 */
function findGuruRecord(PDO $pdo, $userId, $username, $email) {
    $guruCols = getTableColumns($pdo, 'guru');
    if (empty($guruCols)) return null;

    // 1. Try id_user
    if (in_array('id_user', $guruCols, true)) {
        $stmt = $pdo->prepare("SELECT * FROM guru WHERE id_user = ? LIMIT 1");
        $stmt->execute([$userId]);
        $res = $stmt->fetch();
        if ($res) return $res;
    }

    // 2. Try by nip
    if (in_array('nip', $guruCols, true)) {
        $stmt = $pdo->prepare("SELECT * FROM guru WHERE nip = ? OR nuptk = ? LIMIT 1");
        $stmt->execute([$username, $username]);
        $res = $stmt->fetch();
        if ($res) return $res;
    }

    // 3. Try by email
    if (in_array('email', $guruCols, true) && !empty($email)) {
        $stmt = $pdo->prepare("SELECT * FROM guru WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $res = $stmt->fetch();
        if ($res) return $res;
    }

    return null;
}

// -----------------------------------------------------------------------------
// ACTION ROUTER
// -----------------------------------------------------------------------------
try {
    switch ($action) {

        // =====================================================================
        // 0. AMBIL DATA PRIBADI (LAZY LOAD ON-DEMAND)
        // =====================================================================
        case 'get_personal_data':
            $targetRole = $_GET['target_role'] ?? $_POST['target_role'] ?? $activeRole;
            if ($targetRole === 'siswa') {
                $siswa = findSiswaRecord($pdo, $userId, $currentUser['username'], $currentUser['email']);
                echo json_encode([
                    'success' => true,
                    'role'    => 'siswa',
                    'data'    => $siswa ?: null
                ]);
                exit;
            } else {
                $guru = findGuruRecord($pdo, $userId, $currentUser['username'], $currentUser['email']);
                echo json_encode([
                    'success' => true,
                    'role'    => 'guru',
                    'data'    => $guru ?: null
                ]);
                exit;
            }

        // =====================================================================
        // 1. UPDATE DATA AKUN (users table)
        // =====================================================================
        case 'update_account':
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $emailGoogle = trim($_POST['email_google'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if (empty($fullName)) {
                echo json_encode(['success' => false, 'message' => 'Nama lengkap tidak boleh kosong.']);
                exit;
            }

            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Format email utama tidak valid.']);
                exit;
            }

            if (!empty($emailGoogle) && !filter_var($emailGoogle, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Format email Google tidak valid.']);
                exit;
            }

            $userCols = getTableColumns($pdo, 'users');

            // Cek apakah email sudah dipakai user lain
            if (!empty($email) && in_array('email', $userCols, true)) {
                $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                $checkStmt->execute([$email, $userId]);
                if ($checkStmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Email tersebut sudah digunakan oleh akun lain.']);
                    exit;
                }
            }

            $updateSets = [];
            $updateParams = [];

            if (in_array('full_name', $userCols, true)) {
                $updateSets[] = "`full_name` = ?";
                $updateParams[] = $fullName;
            }
            if (in_array('nama_lengkap', $userCols, true)) {
                $updateSets[] = "`nama_lengkap` = ?";
                $updateParams[] = $fullName;
            }
            if (in_array('email', $userCols, true) && !empty($email)) {
                $updateSets[] = "`email` = ?";
                $updateParams[] = $email;
            }
            if (array_key_exists('email_google', $_POST) && in_array('email_google', $userCols, true)) {
                $updateSets[] = "`email_google` = ?";
                $updateParams[] = !empty($emailGoogle) ? $emailGoogle : null;
            }
            if (in_array('phone', $userCols, true)) {
                $updateSets[] = "`phone` = ?";
                $updateParams[] = !empty($phone) ? $phone : null;
            }
            if (in_array('no_hp', $userCols, true)) {
                $updateSets[] = "`no_hp` = ?";
                $updateParams[] = !empty($phone) ? $phone : null;
            }

            if (!empty($updateSets)) {
                $updateParams[] = $userId;
                $sql = "UPDATE `users` SET " . implode(", ", $updateSets) . " WHERE `id` = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($updateParams);

                // Update session
                $_SESSION['user_name'] = $fullName;
                if (!empty($email)) {
                    $_SESSION['user_email'] = $email;
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'Data akun berhasil diperbarui.',
                'full_name' => $fullName,
                'email' => $email,
                'email_google' => $emailGoogle
            ]);
            exit;

        // =====================================================================
        // 2. UPDATE DATA PRIBADI (siswa / guru table)
        // =====================================================================
        case 'update_personal':
            $targetRole = $_POST['target_role'] ?? $activeRole;

            if ($targetRole === 'siswa') {
                $namaLengkap = trim($_POST['nama_lengkap'] ?? '');
                $alamat      = trim($_POST['alamat'] ?? '');
                $noHp        = trim($_POST['no_hp'] ?? '');
                $gmail       = trim($_POST['gmail'] ?? '');
                $idKelas     = isset($_POST['id_kelas']) && is_numeric($_POST['id_kelas']) ? (int)$_POST['id_kelas'] : null;

                if (empty($namaLengkap)) {
                    echo json_encode(['success' => false, 'message' => 'Nama lengkap siswa wajib diisi.']);
                    exit;
                }

                $siswaCols = getTableColumns($pdo, 'siswa');
                $siswa = findSiswaRecord($pdo, $userId, $currentUser['username'], $currentUser['email']);

                if ($siswa) {
                    $sets = [];
                    $params = [];

                    if (in_array('nama_lengkap', $siswaCols, true)) {
                        $sets[] = "`nama_lengkap` = ?";
                        $params[] = $namaLengkap;
                    }
                    if (in_array('alamat', $siswaCols, true)) {
                        $sets[] = "`alamat` = ?";
                        $params[] = $alamat;
                    }
                    if (in_array('no_hp', $siswaCols, true)) {
                        $sets[] = "`no_hp` = ?";
                        $params[] = $noHp;
                    }
                    if (in_array('gmail', $siswaCols, true)) {
                        $sets[] = "`gmail` = ?";
                        $params[] = $gmail;
                    }
                    if ($idKelas !== null && in_array('id_kelas', $siswaCols, true)) {
                        $sets[] = "`id_kelas` = ?";
                        $params[] = $idKelas;
                    }

                    if (!empty($sets)) {
                        $params[] = $siswa['id'];
                        $stmt = $pdo->prepare("UPDATE `siswa` SET " . implode(", ", $sets) . " WHERE `id` = ?");
                        $stmt->execute($params);
                    }

                    // Sinkronisasi balik ke `users`
                    $userCols = getTableColumns($pdo, 'users');
                    $syncSets = [];
                    $syncParams = [];
                    if (in_array('full_name', $userCols, true)) {
                        $syncSets[] = "`full_name` = ?";
                        $syncParams[] = $namaLengkap;
                    }
                    // Catatan: `users.email_google` TIDAK ikut diubah di sini karena khusus untuk tautan Google SSO (link_google)
                    if (!empty($noHp) && in_array('phone', $userCols, true)) {
                        $syncSets[] = "`phone` = ?";
                        $syncParams[] = $noHp;
                    }
                    if (!empty($syncSets)) {
                        $syncParams[] = $userId;
                        $pdo->prepare("UPDATE `users` SET " . implode(", ", $syncSets) . " WHERE `id` = ?")->execute($syncParams);
                        $_SESSION['user_name'] = $namaLengkap;
                    }

                    $namaKelas = '';
                    if (!empty($idKelas)) {
                        try {
                            $kStmt = $pdo->prepare("SELECT nama_kelas FROM kelas WHERE id = ? LIMIT 1");
                            $kStmt->execute([$idKelas]);
                            $namaKelas = (string)($kStmt->fetchColumn() ?: '');
                        } catch (Exception $e) {}
                    }

                    echo json_encode([
                        'success' => true,
                        'message' => 'Data pribadi siswa berhasil disimpan dan disinkronkan ke akun!',
                        'data'    => [
                            'nama_lengkap' => $namaLengkap,
                            'alamat'       => $alamat,
                            'no_hp'        => $noHp,
                            'gmail'        => $gmail,
                            'id_kelas'     => $idKelas,
                            'nama_kelas'   => $namaKelas
                        ]
                    ]);
                    exit;
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Data master siswa Anda belum terhubung. Hubungi administrator.'
                    ]);
                    exit;
                }

            } else {
                // Guru / Admin role
                $namaGuru          = trim($_POST['nama_guru'] ?? '');
                $nip               = trim($_POST['nip'] ?? '');
                $nuptk             = trim($_POST['nuptk'] ?? '');
                $jenisKelamin      = trim($_POST['jenis_kelamin'] ?? 'L');
                $noHp              = trim($_POST['no_hp'] ?? '');
                $email             = trim($_POST['email'] ?? '');
                $alamat            = trim($_POST['alamat'] ?? '');
                $statusKepegawaian = trim($_POST['status_kepegawaian'] ?? 'Tetap');

                if (empty($namaGuru)) {
                    echo json_encode(['success' => false, 'message' => 'Nama lengkap guru wajib diisi.']);
                    exit;
                }

                $guruCols = getTableColumns($pdo, 'guru');
                $guru = findGuruRecord($pdo, $userId, $currentUser['username'], $currentUser['email']);

                if ($guru) {
                    $sets = [];
                    $params = [];

                    if (in_array('nama_guru', $guruCols, true)) {
                        $sets[] = "`nama_guru` = ?";
                        $params[] = $namaGuru;
                    }
                    if (in_array('nip', $guruCols, true)) {
                        $sets[] = "`nip` = ?";
                        $params[] = $nip;
                    }
                    if (in_array('nuptk', $guruCols, true)) {
                        $sets[] = "`nuptk` = ?";
                        $params[] = $nuptk;
                    }
                    if (in_array('jenis_kelamin', $guruCols, true)) {
                        $sets[] = "`jenis_kelamin` = ?";
                        $params[] = in_array($jenisKelamin, ['L', 'P'], true) ? $jenisKelamin : 'L';
                    }
                    if (in_array('no_hp', $guruCols, true)) {
                        $sets[] = "`no_hp` = ?";
                        $params[] = $noHp;
                    }
                    if (in_array('email', $guruCols, true)) {
                        $sets[] = "`email` = ?";
                        $params[] = $email;
                    }
                    if (in_array('alamat', $guruCols, true)) {
                        $sets[] = "`alamat` = ?";
                        $params[] = $alamat;
                    }
                    if (in_array('status_kepegawaian', $guruCols, true)) {
                        $sets[] = "`status_kepegawaian` = ?";
                        $params[] = $statusKepegawaian;
                    }

                    if (!empty($sets)) {
                        $params[] = $guru['id'];
                        $stmt = $pdo->prepare("UPDATE `guru` SET " . implode(", ", $sets) . " WHERE `id` = ?");
                        $stmt->execute($params);
                    }

                    // Sinkronisasi balik ke `users`
                    $userCols = getTableColumns($pdo, 'users');
                    $syncSets = [];
                    $syncParams = [];
                    if (in_array('full_name', $userCols, true)) {
                        $syncSets[] = "`full_name` = ?";
                        $syncParams[] = $namaGuru;
                    }
                    if (!empty($email) && in_array('email', $userCols, true)) {
                        $syncSets[] = "`email` = ?";
                        $syncParams[] = $email;
                    }
                    if (!empty($noHp) && in_array('phone', $userCols, true)) {
                        $syncSets[] = "`phone` = ?";
                        $syncParams[] = $noHp;
                    }
                    if (!empty($syncSets)) {
                        $syncParams[] = $userId;
                        $pdo->prepare("UPDATE `users` SET " . implode(", ", $syncSets) . " WHERE `id` = ?")->execute($syncParams);
                        $_SESSION['user_name'] = $namaGuru;
                    }

                    echo json_encode([
                        'success' => true,
                        'message' => 'Data pribadi guru berhasil disimpan dan disinkronkan ke akun!',
                        'data'    => [
                            'nama_guru'          => $namaGuru,
                            'nip'                => $nip,
                            'nuptk'              => $nuptk,
                            'jenis_kelamin'      => $jenisKelamin,
                            'status_kepegawaian' => $statusKepegawaian,
                            'no_hp'              => $noHp,
                            'email'              => $email,
                            'alamat'             => $alamat
                        ]
                    ]);
                    exit;
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Data master guru Anda belum terdaftar. Hubungi administrator.'
                    ]);
                    exit;
                }
            }

        // =====================================================================
        // 3. UPLOAD FOTO PROFIL (Sync to users & siswa/guru)
        // =====================================================================
        case 'upload_photo':
            if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'Tidak ada file foto yang diunggah atau terjadi kesalahan server.']);
                exit;
            }

            $file = $_FILES['photo'];
            $maxSize = 3 * 1024 * 1024; // 3MB

            if ($file['size'] > $maxSize) {
                echo json_encode(['success' => false, 'message' => 'Ukuran foto melebihi batas maksimal 3MB.']);
                exit;
            }

            // Validasi tipe berkas
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

            if (!in_array($mime, $allowedMimes, true)) {
                echo json_encode(['success' => false, 'message' => 'Format file tidak didukung. Harap unggah format JPG, PNG, atau WebP.']);
                exit;
            }

            $extMap = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/gif'  => 'gif'
            ];
            $ext = $extMap[$mime] ?? 'jpg';

            // Target direktori
            $targetDir = BASE_PATH . '/uploads/profiles';
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }

            $fileName = 'profile_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destPath = $targetDir . '/' . $fileName;

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                echo json_encode(['success' => false, 'message' => 'Gagal memindahkan file yang diunggah.']);
                exit;
            }

            $photoRelativeUrl = 'uploads/profiles/' . $fileName;
            $photoFullUrl = BASE_URL . '/' . $photoRelativeUrl;

            // 1. Update tabel `users`
            $userCols = getTableColumns($pdo, 'users');
            $userPhotoSets = [];
            $userPhotoParams = [];

            if (in_array('foto_profil', $userCols, true)) {
                $userPhotoSets[] = "`foto_profil` = ?";
                $userPhotoParams[] = $photoRelativeUrl;
            }
            if (in_array('avatar', $userCols, true)) {
                $userPhotoSets[] = "`avatar` = ?";
                $userPhotoParams[] = $photoRelativeUrl;
            }

            if (!empty($userPhotoSets)) {
                $userPhotoParams[] = $userId;
                $pdo->prepare("UPDATE `users` SET " . implode(", ", $userPhotoSets) . " WHERE `id` = ?")->execute($userPhotoParams);
            }

            // 2. Sinkronkan ke tabel `siswa` jika role siswa
            $syncedTo = ['users'];
            if ($activeRole === 'siswa' || hasRole('siswa')) {
                $siswaCols = getTableColumns($pdo, 'siswa');
                if (in_array('foto', $siswaCols, true)) {
                    $siswa = findSiswaRecord($pdo, $userId, $currentUser['username'], $currentUser['email']);
                    if ($siswa) {
                        $pdo->prepare("UPDATE `siswa` SET `foto` = ? WHERE `id` = ?")->execute([$photoRelativeUrl, $siswa['id']]);
                        $syncedTo[] = 'siswa';
                    }
                }
            }

            // 3. Sinkronkan ke tabel `guru` jika role guru / admin
            if ($activeRole === 'admin' || $activeRole === 'guru' || hasRole('admin') || hasRole('guru')) {
                $guruCols = getTableColumns($pdo, 'guru');
                if (in_array('foto', $guruCols, true)) {
                    $guru = findGuruRecord($pdo, $userId, $currentUser['username'], $currentUser['email']);
                    if ($guru) {
                        $pdo->prepare("UPDATE `guru` SET `foto` = ? WHERE `id` = ?")->execute([$photoRelativeUrl, $guru['id']]);
                        $syncedTo[] = 'guru';
                    }
                }
            }

            // Update session avatar
            $_SESSION['user_avatar'] = $photoFullUrl;

            echo json_encode([
                'success' => true,
                'message' => 'Foto profil berhasil diperbarui dan disinkronkan ke ' . implode(' & ', $syncedTo) . '!',
                'photo_url' => $photoFullUrl,
                'synced_to' => $syncedTo
            ]);
            exit;

        // =====================================================================
        // 4. HAPUS FOTO PROFIL
        // =====================================================================
        case 'delete_photo':
            $userCols = getTableColumns($pdo, 'users');
            $userPhotoSets = [];
            $userPhotoParams = [];

            if (in_array('foto_profil', $userCols, true)) {
                $userPhotoSets[] = "`foto_profil` = NULL";
            }
            if (in_array('avatar', $userCols, true)) {
                $userPhotoSets[] = "`avatar` = NULL";
            }
            if (!empty($userPhotoSets)) {
                $userPhotoParams[] = $userId;
                $pdo->prepare("UPDATE `users` SET " . implode(", ", $userPhotoSets) . " WHERE `id` = ?")->execute($userPhotoParams);
            }

            // Hapus juga di siswa
            $siswa = findSiswaRecord($pdo, $userId, $currentUser['username'], $currentUser['email']);
            if ($siswa) {
                $pdo->prepare("UPDATE `siswa` SET `foto` = NULL WHERE `id` = ?")->execute([$siswa['id']]);
            }

            // Hapus juga di guru
            $guru = findGuruRecord($pdo, $userId, $currentUser['username'], $currentUser['email']);
            if ($guru) {
                $pdo->prepare("UPDATE `guru` SET `foto` = NULL WHERE `id` = ?")->execute([$guru['id']]);
            }

            $_SESSION['user_avatar'] = null;

            echo json_encode([
                'success' => true,
                'message' => 'Foto profil berhasil dihapus dari seluruh tabel sistem.'
            ]);
            exit;

        // =====================================================================
        // 5. PERMINTAAN TAUTKAN AKUN GOOGLE DENGAN VERIFIKASI GMAIL
        // =====================================================================
        case 'request_link_google':
        case 'link_google':
            require_once BASE_PATH . '/includes/email-verify-google-template.php';
            ilmuna_ensure_google_verification_table($pdo);

            $accessToken = trim($_POST['google_access_token'] ?? '');
            $emailGoogle = '';
            $googleId    = '';

            if (!empty($accessToken)) {
                // Verifikasi access_token secara aman ke Google UserInfo API
                $uInfo = fetchGoogleApi('https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . urlencode($accessToken));
                if (!empty($uInfo['email'])) {
                    $emailGoogle = strtolower(trim($uInfo['email']));
                    $googleId    = trim((string)($uInfo['sub'] ?? ''));
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Gagal mengambil informasi profil dari Google. Pastikan izin akun Google diberikan.'
                    ]);
                    exit;
                }
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Token otentikasi Google tidak ditemukan. Silakan klik tombol Google SSO kembali.'
                ]);
                exit;
            }

            if (empty($emailGoogle) || !filter_var($emailGoogle, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Alamat Gmail Google tidak valid.']);
                exit;
            }

            if (empty($googleId)) {
                $googleId = 'goog_' . md5(strtolower($emailGoogle) . '_ilmuna_sso');
            }

            $userCols = getTableColumns($pdo, 'users');

            // Cek apakah akun Google sudah ditautkan ke pengguna lain
            if (in_array('google_id', $userCols, true) || in_array('email_google', $userCols, true)) {
                $chk = $pdo->prepare("SELECT id, username FROM `users` WHERE (`google_id` = ? OR `email_google` = ?) AND `id` != ? LIMIT 1");
                $chk->execute([$googleId, $emailGoogle, $userId]);
                $exists = $chk->fetch(PDO::FETCH_ASSOC);
                if ($exists) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Akun Google tersebut (' . $emailGoogle . ') sudah tertaut pada pengguna lain (@' . $exists['username'] . ').'
                    ]);
                    exit;
                }
            }

            // Cek apakah akun Google ini sudah ditautkan ke pengguna ini sendiri
            $chkSelf = $pdo->prepare("SELECT id FROM `users` WHERE `id` = ? AND (`email_google` = ? OR `google_id` = ?) LIMIT 1");
            $chkSelf->execute([$userId, $emailGoogle, $googleId]);
            if ($chkSelf->fetch()) {
                echo json_encode([
                    'success'      => true,
                    'linked'       => true,
                    'email_google' => $emailGoogle,
                    'message'      => 'Akun Google ini (' . $emailGoogle . ') sudah berhasil tertaut dengan akun Anda.'
                ]);
                exit;
            }

            // Hapus permintaan verifikasi lama yang belum selesai untuk user ini
            $pdo->prepare("DELETE FROM `google_link_verifications` WHERE `user_id` = ? AND `verified_at` IS NULL")->execute([$userId]);

            // Buat token verifikasi kriptografis baru
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // Berlaku 60 menit
            $createdAt = date('Y-m-d H:i:s');
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';

            $ins = $pdo->prepare("INSERT INTO `google_link_verifications` (`user_id`, `email_google`, `google_id`, `token`, `expires_at`, `created_at`, `ip_address`) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([$userId, $emailGoogle, $googleId, $token, $expiresAt, $createdAt, $ipAddress]);

            $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost';
            $verifyUrl = $baseUrl . '/verify-google.php?token=' . $token;

            $userName = $currentUser['full_name'] ?? ($currentUser['username'] ?? 'Pengguna');
            $mailRes = ilmuna_send_google_verify_email($emailGoogle, $userName, $verifyUrl, 60);

            if (!$mailRes['success']) {
                echo json_encode([
                    'success'      => true,
                    'pending'      => true,
                    'email_google' => $emailGoogle,
                    'verify_url'   => $verifyUrl,
                    'mail_failed'  => true,
                    'message'      => 'Tautan verifikasi penautan Google berhasil dibuat! Karena proteksi SMTP membatasi pengiriman dari IP hosting, silakan klik tombol "Buka Link Verifikasi" di bawah ini untuk memverifikasi secara langsung.',
                    'action_url'   => $verifyUrl
                ]);
                exit;
            }

            echo json_encode([
                'success'      => true,
                'pending'      => true,
                'email_google' => $emailGoogle,
                'message'      => 'Tautan verifikasi telah dikirim ke ' . $emailGoogle . '. Silakan periksa kotak masuk atau spam di Gmail Anda dan klik tautan untuk menyelesaikan penautan.'
            ]);
            exit;

        // =====================================================================
        // 5A. CEK STATUS PENAUTAN GOOGLE (LAZY CHECK ON-DEMAND)
        // =====================================================================
        case 'check_google_link_status':
            $userStmt = $pdo->prepare("SELECT email_google, google_id FROM users WHERE id = ? LIMIT 1");
            $userStmt->execute([$userId]);
            $uRow = $userStmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($uRow['email_google'])) {
                echo json_encode([
                    'success'      => true,
                    'connected'    => true,
                    'email_google' => $uRow['email_google']
                ]);
                exit;
            }

            $pStmt = $pdo->prepare("SELECT email_google, expires_at FROM google_link_verifications WHERE user_id = ? AND verified_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
            $pStmt->execute([$userId]);
            $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($pRow) {
                echo json_encode([
                    'success'      => true,
                    'connected'    => false,
                    'pending'      => true,
                    'email_google' => $pRow['email_google']
                ]);
                exit;
            }

            echo json_encode([
                'success'   => true,
                'connected' => false,
                'pending'   => false
            ]);
            exit;

        // =====================================================================
        // 5B. BATALKAN PERMINTAAN TAUTKAN GOOGLE
        // =====================================================================
        case 'cancel_link_google':
            require_once BASE_PATH . '/includes/email-verify-google-template.php';
            ilmuna_ensure_google_verification_table($pdo);
            $pdo->prepare("DELETE FROM `google_link_verifications` WHERE `user_id` = ? AND `verified_at` IS NULL")->execute([$userId]);

            echo json_encode([
                'success' => true,
                'message' => 'Permintaan penautan akun Google berhasil dibatalkan.'
            ]);
            exit;

        // =====================================================================
        // 6. PUTUSKAN TAUTAN GOOGLE (Unlink Google Account)
        // =====================================================================
        case 'unlink_google':
            $userCols = getTableColumns($pdo, 'users');
            $sets = [];
            if (in_array('google_id', $userCols, true)) {
                $sets[] = "`google_id` = NULL";
            }
            if (in_array('email_google', $userCols, true)) {
                $sets[] = "`email_google` = NULL";
            }

            if (!empty($sets)) {
                $pdo->prepare("UPDATE `users` SET " . implode(", ", $sets) . " WHERE `id` = ?")->execute([$userId]);
            }

            // Hapus juga verifikasi tertunda jika ada
            try {
                $pdo->prepare("DELETE FROM `google_link_verifications` WHERE `user_id` = ?")->execute([$userId]);
            } catch (Exception $e) {}

            echo json_encode([
                'success' => true,
                'message' => 'Tautan akun Google berhasil diputuskan.'
            ]);
            exit;

        // =====================================================================
        // 7. GANTI KATA SANDI (Change Password)
        // =====================================================================
        case 'change_password':
            $oldPassword     = $_POST['old_password'] ?? '';
            $newPassword     = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($oldPassword)) {
                echo json_encode(['success' => false, 'message' => 'Kata sandi saat ini wajib diisi.']);
                exit;
            }

            if (strlen($newPassword) < 6) {
                echo json_encode(['success' => false, 'message' => 'Kata sandi baru minimal harus 6 karakter.']);
                exit;
            }

            if ($newPassword !== $confirmPassword) {
                echo json_encode(['success' => false, 'message' => 'Konfirmasi kata sandi baru tidak cocok.']);
                exit;
            }

            // Ambil hash password lama dari database
            $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `id` = ? LIMIT 1");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch();

            if (!$userData) {
                echo json_encode(['success' => false, 'message' => 'Pengguna tidak ditemukan.']);
                exit;
            }

            $currentHash = $userData['password_hash'] ?? $userData['password'] ?? '';
            if (!password_verify($oldPassword, $currentHash)) {
                echo json_encode(['success' => false, 'message' => 'Kata sandi lama yang Anda masukkan salah.']);
                exit;
            }

            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $userCols = getTableColumns($pdo, 'users');
            $sets = [];
            $params = [];

            if (in_array('password_hash', $userCols, true)) {
                $sets[] = "`password_hash` = ?";
                $params[] = $newHash;
            }
            if (in_array('password', $userCols, true)) {
                $sets[] = "`password` = ?";
                $params[] = $newHash;
            }

            if (!empty($sets)) {
                $params[] = $userId;
                $pdo->prepare("UPDATE `users` SET " . implode(", ", $sets) . " WHERE `id` = ?")->execute($params);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Kata sandi berhasil diperbarui! Gunakan kata sandi baru untuk login selanjutnya.'
            ]);
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal (' . htmlspecialchars($action) . ').']);
            exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
    exit;
}
