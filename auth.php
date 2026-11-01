<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — AUTHENTICATION & MULTI-ROLE RBAC SYSTEM
 * ==============================================================================
 * Handles multi-role sessions, role switching, permissions, and security
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';

/**
 * Check if current user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current logged in user data
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['user_username'] ?? '',
        'full_name' => $_SESSION['user_name'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'avatar' => $_SESSION['user_avatar'] ?? null,
        'roles' => $_SESSION['user_roles'] ?? [],
        'roles_detail' => $_SESSION['user_roles_detail'] ?? [],
        'active_role' => $_SESSION['active_role'] ?? null,
    ];
}

/**
 * Get all role codes for current user
 */
function getUserRoles() {
    return $_SESSION['user_roles'] ?? [];
}

/**
 * Get currently active role code
 * URL-Aware: Mengutamakan deteksi role dari konteks URL dashboard yang sedang dibuka.
 * Menjamin multi-tab dengan role berbeda tetap terisolasi dan tidak saling menimpa
 * (misal Tab 1: Ketua Kelas tetap Ketua Kelas, Tab 2: Guru tetap Guru, Tab 3: Siswa tetap Siswa).
 */
function getActiveRole() {
    // 1. Variabel global eksplisit jika diset di level page controller
    if (!empty($GLOBALS['activeRole'])) {
        return $GLOBALS['activeRole'];
    }

    // 2. Deteksi otomatis dari URL request (Tab Isolation)
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $uri    = str_replace('\\', '/', $_SERVER['REQUEST_URI'] ?? '');
    $path   = $script ?: $uri;

    if (strpos($path, '/dashboard/ketua-kelas') !== false) {
        if (hasRole('ketua_kelas')) {
            return 'ketua_kelas';
        }
    } elseif (strpos($path, '/dashboard/guru') !== false) {
        if (hasRole('guru')) {
            return 'guru';
        }
    } elseif (strpos($path, '/dashboard/siswa') !== false) {
        if (hasRole('siswa')) {
            return 'siswa';
        }
    } elseif (strpos($path, '/dashboard/admin') !== false) {
        // Di folder /dashboard/admin/:
        // Jika user memiliki role admin, utamakan admin kecuali jika session role eksplisit guru
        if (hasRole('admin') && (!hasRole('guru') || ($_SESSION['active_role'] ?? '') !== 'guru')) {
            return 'admin';
        }
        if (hasRole('guru')) {
            return 'guru';
        }
        if (hasRole('admin')) {
            return 'admin';
        }
    }

    // 3. Fallback jika di luar folder dashboard spesifik
    return $_SESSION['active_role'] ?? $_SESSION['primary_role'] ?? (getUserRoles()[0] ?? null);
}

/**
 * Check if user possesses a specific role
 */
function hasRole($roleCode) {
    $roles = getUserRoles();
    return in_array($roleCode, $roles, true);
}

/**
 * Set active role for the current session
 */
function setActiveRole($roleCode) {
    if (!hasRole($roleCode)) {
        return false;
    }
    $_SESSION['active_role'] = $roleCode;
    return true;
}

/**
 * Login user with username/email and password
 */
function loginUser($identifier, $password) {
    $pdo = getDBConnection();
    
    $cleanId = trim($identifier);

    // Detect users table column structure dynamically
    $userCols = [];
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM users");
        $userCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $userCols = ['id', 'username', 'email', 'password_hash', 'status', 'full_name'];
    }

    $emailConditions = [];
    if (in_array('email', $userCols, true)) {
        $emailConditions[] = "email = :uemail";
    }
    if (in_array('email_google', $userCols, true)) {
        $emailConditions[] = "email_google = :uemail";
    }

    $userWhere = "username = :uname";
    if (!empty($emailConditions)) {
        $userWhere .= " OR " . implode(" OR ", $emailConditions);
    }

    $statusConditions = [];
    if (in_array('status', $userCols, true)) {
        $statusConditions[] = "(status = 'active' OR status = '1' OR status IS NULL)";
    }
    if (in_array('is_active', $userCols, true)) {
        $statusConditions[] = "(is_active = 1 OR is_active IS NULL)";
    }

    $statusSql = !empty($statusConditions) ? " AND (" . implode(" AND ", $statusConditions) . ")" : "";

    $stmt = $pdo->prepare("SELECT * FROM users WHERE ({$userWhere}){$statusSql} LIMIT 1");
    $stmt->execute(['uname' => $cleanId, 'uemail' => $cleanId]);
    $user = $stmt->fetch();

    $passHash = $user['password_hash'] ?? $user['password'] ?? '';
    if (!$user || !password_verify($password, $passHash)) {
        return ['success' => false, 'message' => 'Username, email, atau kata sandi yang Anda masukkan salah.'];
    }

    return establishUserSession($pdo, $user);
}

/**
 * Login user menggunakan Akun Google.
 * ATURAN KETAT:
 * - HANYA mengizinkan login jika akun sudah secara eksplisit ditautkan (linked)
 *   pada kolom `users.email_google` melalui halaman /profile.
 * - Walaupun email ada di `users.email`, `siswa.gmail`, atau `guru.email`, jika
 *   belum di-link di /profile (`email_google` kosong), maka TIDAK BISA login via Google.
 * - TIDAK PERNAH menambahkan/mendaftarkan akun baru dari sisi login.
 */
function loginUserByGoogle($emailGoogle, $googleId = '') {
    $pdo = getDBConnection();

    $cleanEmail = strtolower(trim((string)$emailGoogle));
    $cleanGid   = trim((string)$googleId);

    if ($cleanEmail === '' || !filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Format alamat email Google tidak valid.'];
    }

    // Deteksi kolom tabel users secara dinamis (MySQL & SQLite)
    $userCols = [];
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $colStmt = $pdo->query("PRAGMA table_info(users)");
            $userCols = array_column($colStmt->fetchAll(PDO::FETCH_ASSOC), 'name');
        } else {
            $colStmt = $pdo->query("SHOW COLUMNS FROM users");
            $userCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (Exception $e) {
        $userCols = ['id', 'username', 'email', 'email_google', 'google_id', 'password_hash', 'status', 'full_name'];
    }

    if (!in_array('email_google', $userCols, true)) {
        return ['success' => false, 'message' => 'Fitur tautan Google SSO belum tersedia pada tabel pengguna.'];
    }

    $statusConditions = [];
    if (in_array('status', $userCols, true)) {
        $statusConditions[] = "(status = 'active' OR status = '1' OR status IS NULL)";
    }
    if (in_array('is_active', $userCols, true)) {
        $statusConditions[] = "(is_active = 1 OR is_active IS NULL)";
    }
    $statusSql = !empty($statusConditions) ? " AND (" . implode(" AND ", $statusConditions) . ")" : "";

    // HANYA cek kolom `email_google` (dan harus sudah terisi / linked di /profile)
    $sql = "SELECT * FROM users WHERE email_google IS NOT NULL AND TRIM(email_google) != '' AND LOWER(email_google) = :email_g{$statusSql} LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['email_g' => $cleanEmail]);
    $user = $stmt->fetch();

    // Jika belum linked di `users.email_google`, cek apakah email sebenarnya ada di `users.email` / `siswa.gmail` / `guru.email`
    // untuk memberikan pesan yang jelas bahwa akun harus di-link dulu di /profile
    if (!$user) {
        $hasUnlinkedEmail = false;
        try {
            if (in_array('email', $userCols, true)) {
                $stCheck = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1");
                $stCheck->execute([$cleanEmail]);
                if ($stCheck->fetchColumn()) {
                    $hasUnlinkedEmail = true;
                }
            }
            if (!$hasUnlinkedEmail) {
                $stSw = $pdo->prepare("SELECT id FROM siswa WHERE LOWER(gmail) = ? LIMIT 1");
                $stSw->execute([$cleanEmail]);
                if ($stSw->fetchColumn()) {
                    $hasUnlinkedEmail = true;
                }
            }
            if (!$hasUnlinkedEmail) {
                $stGr = $pdo->prepare("SELECT id FROM guru WHERE LOWER(email) = ? LIMIT 1");
                $stGr->execute([$cleanEmail]);
                if ($stGr->fetchColumn()) {
                    $hasUnlinkedEmail = true;
                }
            }
        } catch (Exception $e) {}

        if ($hasUnlinkedEmail) {
            return [
                'success' => false,
                'message' => 'Email (' . $cleanEmail . ') belum ditautkan ke Google SSO. Silakan masuk dengan Username & Password terlebih dahulu, lalu hubungkan akun Google Anda di menu Profil Saya.'
            ];
        }

        return [
            'success' => false,
            'message' => 'Akun Google (' . $cleanEmail . ') belum terdaftar atau belum ditautkan di menu Profil. Silakan masuk dengan Username & Password terlebih dahulu.'
        ];
    }

    return establishUserSession($pdo, $user);
}

/**
 * Helper internal: memuat role dari database & menginisialisasi session login untuk user yang valid
 */
function establishUserSession($pdo, array $user) {

    // Detect user_roles and roles table column structure dynamically
    $urCols = [];
    try {
        $urStmt = $pdo->query("SHOW COLUMNS FROM user_roles");
        $urCols = $urStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $urCols = ['id', 'user_id', 'role_id', 'is_primary'];
    }

    $userIdCol = in_array('user_id', $urCols, true) ? 'user_id' : (in_array('id_user', $urCols, true) ? 'id_user' : 'user_id');
    $roleIdCol = in_array('role_id', $urCols, true) ? 'role_id' : (in_array('id_roles', $urCols, true) ? 'id_roles' : 'role_id');
    $hasIsPrimary = in_array('is_primary', $urCols, true);

    $rolesCols = [];
    try {
        $rStmt = $pdo->query("SHOW COLUMNS FROM roles");
        $rolesCols = $rStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $rolesCols = ['id', 'code', 'name', 'description'];
    }

    $roleCodeCol = in_array('code', $rolesCols, true) ? 'code' : (in_array('slug', $rolesCols, true) ? 'slug' : 'code');
    $roleNameCol = in_array('name', $rolesCols, true) ? 'name' : (in_array('nama_roles', $rolesCols, true) ? 'nama_roles' : 'name');
    $roleDescCol = in_array('description', $rolesCols, true) ? 'description' : (in_array('deskripsi', $rolesCols, true) ? 'deskripsi' : 'description');

    $selectPrimary = $hasIsPrimary ? 'ur.is_primary' : '1 AS is_primary';
    $orderClause = $hasIsPrimary ? 'ur.is_primary DESC, r.id ASC' : 'r.id ASC';

    $stmtRoles = $pdo->prepare("
        SELECT r.id, r.{$roleCodeCol} AS code, r.{$roleNameCol} AS name, r.{$roleDescCol} AS description, {$selectPrimary}
        FROM user_roles ur
        JOIN roles r ON ur.{$roleIdCol} = r.id
        WHERE ur.{$userIdCol} = :user_id
        ORDER BY {$orderClause}
    ");
    $stmtRoles->execute(['user_id' => $user['id']]);
    $rolesDetail = $stmtRoles->fetchAll();

    if (empty($rolesDetail)) {
        return ['success' => false, 'message' => 'Akun Anda belum memiliki role aktif. Hubungi Administrator.'];
    }

    $roleCodes = array_column($rolesDetail, 'code');

    // Regenerate session id for security
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }

    $fullName = !empty($user['full_name']) ? $user['full_name'] : (!empty($user['nama_lengkap']) ? $user['nama_lengkap'] : $user['username']);
    
    // Cek nama dari tabel siswa jika ada
    try {
        $stmtNama = $pdo->prepare("SELECT nama_lengkap FROM siswa WHERE nisn = ? LIMIT 1");
        $stmtNama->execute([$user['username']]);
        $namaSiswa = $stmtNama->fetchColumn();
        if ($namaSiswa) {
            $fullName = $namaSiswa;
        }
    } catch (Exception $e) {}

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_username'] = $user['username'];
    $_SESSION['user_name'] = $fullName;
    $_SESSION['user_email'] = $user['email'] ?? $user['email_google'] ?? '';
    $_SESSION['user_avatar'] = $user['avatar'] ?? $user['foto_profil'] ?? null;
    $_SESSION['user_roles'] = $roleCodes;
    $_SESSION['user_roles_detail'] = $rolesDetail;

    $primaryRole = $roleCodes[0];
    $_SESSION['primary_role'] = $primaryRole;
    $_SESSION['active_role'] = $primaryRole;

    return [
        'success' => true,
        'roles_count' => count($roleCodes),
        'roles' => $roleCodes,
        'primary_role' => $primaryRole
    ];
}

/**
 * Logout user
 */
function logoutUser() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Route / Gateway Helper: Get Dashboard URL for role code
 */
function getRoleDashboardUrl($roleCode) {
    switch ($roleCode) {
        case 'admin':
            return BASE_URL . '/dashboard/admin';
        case 'guru':
            return BASE_URL . '/dashboard/guru';
        case 'ketua_kelas':
            return BASE_URL . '/dashboard/ketua-kelas';
        case 'siswa':
            return BASE_URL . '/dashboard/siswa';
        default:
            return BASE_URL . '/gateway';
    }
}

/**
 * Enforce Authentication
 */
function requireAuth() {
    if (!isLoggedIn()) {
        header("Location: " . BASE_URL . "/login?msg=auth_required");
        exit;
    }
}

/**
 * Enforce Specific Role (Mendukung peran tunggal maupun array peran yang diizinkan)
 * Strict Access Control:
 * - Admin hanya bisa diakses akun yang memiliki role 'admin'
 * - Guru hanya bisa diakses akun yang memiliki role 'guru'
 * - Halaman bersama Guru & Admin hanya bisa diakses role 'admin' atau 'guru'
 * - Ketua Kelas hanya bisa diakses akun yang memiliki role 'ketua_kelas'
 * - Siswa hanya bisa diakses akun yang memiliki role 'siswa'
 */
function requireRole($requiredRole) {
    requireAuth();

    $allowedRoles = is_array($requiredRole) ? $requiredRole : [$requiredRole];

    $hasAccess = false;
    $matchedRole = null;
    foreach ($allowedRoles as $role) {
        if (hasRole($role)) {
            $hasAccess = true;
            $matchedRole = $role;
            break;
        }
    }

    if (!$hasAccess) {
        // Akses Ditolak: Pengguna tidak memiliki hak peran ini!
        header("Location: " . BASE_URL . "/gateway.php?error=forbidden");
        exit;
    }

    // Set context role aktif lokal di global variabel untuk tab saat ini
    $GLOBALS['activeRole'] = $matchedRole;
    if (!is_array($requiredRole)) {
        $_SESSION['active_role'] = $requiredRole;
    }
}
