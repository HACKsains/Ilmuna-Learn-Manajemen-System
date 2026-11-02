<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — USER MANAGEMENT & AUTOMATIC ACCOUNT GENERATOR
 * ==============================================================================
 * Sesuai Request User:
 * 1. Desain 1 Tema dengan Classes, Teachers, dan Students (Lumina Gold #1D3B5E / #0D7A87 / #D97706)
 * 2. Menampilkan data yang belum ada akun maupun yang sudah ada dari Guru dan Siswa
 * 3. Otomatisasi pembuatan akun dengan 1-Klik Button (Single & Autogen Massal):
 *    - Siswa : Username = NIS
 *              Password = (2 digit acak) . NIS . (1 digit acak) . NISN . (4 digit acak)
 *    - Guru  : Username = NIP / Nomor Identitas
 *              Password = (4 digit acak) . (NIP / Nomor Identitas) . (2 digit acak)
 * 4. Pengiriman kredensial akun langsung ke Gmail masing-masing
 * 5. Pengaturan & template Gmail terpisah di users-email-template.php
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole('admin');

require_once __DIR__ . '/users-email-template.php';

$pdo = getDBConnection();
$pageTitle = 'Manajemen Pengguna & Generator Akun';
$pageIcon = 'solar:users-group-two-rounded-bold';

/* ==============================================================================
 * 1. AJAX API BACKEND CONTROLLER
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = trim($_POST['action']);

    // 1.1 KELAS OPTIONS DROPDOWN
    if ($act === 'get_kelas_options') {
        $rows = $pdo->query("SELECT id, nama_kelas, tahun_pelajaran FROM kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => ['kelas' => $rows ?: []]]);
        exit;
    }

    // 1.2 FETCH UNIFIED LIST (GURU + SISWA DENGAN STATUS AKUN)
    if ($act === 'list') {
        $type     = trim($_POST['type'] ?? 'all');        // all, guru, siswa
        $status   = trim($_POST['status'] ?? 'all');      // all, no_account, has_account, active, inactive
        $idKelas  = (int)($_POST['id_kelas'] ?? 0);
        $search   = trim($_POST['search'] ?? '');
        $page     = max(1, (int)($_POST['page'] ?? 1));
        $perPage  = max(5, min(100, (int)($_POST['per_page'] ?? 20)));
        $offset   = ($page - 1) * $perPage;

        // Subquery Siswa
        $siswaSql = "
            SELECT 
                'siswa' AS source_type,
                s.id AS source_id,
                s.nama_lengkap,
                s.nis AS identifier_primary,
                s.nisn AS identifier_secondary,
                s.gmail AS email,
                s.no_hp,
                s.foto,
                s.id_kelas,
                k.nama_kelas,
                u.id AS user_id,
                u.username,
                u.status AS user_status,
                u.created_at AS user_created_at,
                (SELECT GROUP_CONCAT(r.code) FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = u.id) AS role_codes
            FROM siswa s
            LEFT JOIN kelas k ON s.id_kelas = k.id
            LEFT JOIN users u ON (u.username = s.nis OR u.nisn_nip = s.nisn OR (s.gmail IS NOT NULL AND s.gmail != '' AND u.email = s.gmail))
        ";

        // Subquery Guru
        $guruSql = "
            SELECT 
                'guru' AS source_type,
                g.id AS source_id,
                g.nama_guru AS nama_lengkap,
                g.nip AS identifier_primary,
                COALESCE(NULLIF(g.nuptk, ''), g.kode_guru, '-') AS identifier_secondary,
                g.email,
                g.no_hp,
                g.foto,
                0 AS id_kelas,
                'Dewan Guru' AS nama_kelas,
                u.id AS user_id,
                u.username,
                u.status AS user_status,
                u.created_at AS user_created_at,
                (SELECT GROUP_CONCAT(r.code) FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = u.id) AS role_codes
            FROM guru g
            LEFT JOIN users u ON (g.id_user = u.id OR u.nisn_nip = g.nip OR (g.email IS NOT NULL AND g.email != '' AND u.email = g.email))
        ";

        // Satukan tergantung filter type
        if ($type === 'guru') {
            $baseSql = "($guruSql)";
        } elseif ($type === 'siswa') {
            $baseSql = "($siswaSql)";
        } else {
            $baseSql = "(($guruSql) UNION ALL ($siswaSql))";
        }

        $where = " WHERE 1=1 ";
        $params = [];

        // Filter Status Akun
        if ($status === 'no_account') {
            $where .= " AND t.user_id IS NULL ";
        } elseif ($status === 'has_account') {
            $where .= " AND t.user_id IS NOT NULL ";
        } elseif ($status === 'active') {
            $where .= " AND t.user_id IS NOT NULL AND t.user_status = 'active' ";
        } elseif ($status === 'inactive') {
            $where .= " AND t.user_id IS NOT NULL AND t.user_status != 'active' ";
        }

        // Filter Kelas jika ada
        if ($idKelas > 0 && $type !== 'guru') {
            $where .= " AND t.id_kelas = ? ";
            $params[] = $idKelas;
        }

        // Search Filter
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= " AND (t.nama_lengkap LIKE ? OR t.identifier_primary LIKE ? OR t.identifier_secondary LIKE ? OR t.username LIKE ? OR t.email LIKE ? OR t.nama_kelas LIKE ?) ";
            $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
        }

        // Count Total
        $countQuery = "SELECT COUNT(*) FROM $baseSql t $where";
        $cStmt = $pdo->prepare($countQuery);
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        // Data Query
        $dataQuery = "SELECT t.* FROM $baseSql t $where ORDER BY (t.user_id IS NULL) DESC, t.nama_lengkap ASC LIMIT $perPage OFFSET $offset";
        $dStmt = $pdo->prepare($dataQuery);
        $dStmt->execute($params);
        $rows = $dStmt->fetchAll(PDO::FETCH_ASSOC);

        // Hitung Statistik Global untuk KPI Cards
        $totalGuru        = (int)$pdo->query("SELECT COUNT(*) FROM guru")->fetchColumn();
        $totalSiswa       = (int)$pdo->query("SELECT COUNT(*) FROM siswa")->fetchColumn();
        $totalUsersActive = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();

        // Total Belum Punya Akun (Guru tanpa user_id & Siswa tanpa matching akun)
        $guruNoAcc  = (int)$pdo->query("SELECT COUNT(*) FROM guru g LEFT JOIN users u ON g.id_user = u.id WHERE u.id IS NULL")->fetchColumn();
        $siswaNoAcc = (int)$pdo->query("SELECT COUNT(*) FROM siswa s LEFT JOIN users u ON (u.username = s.nis OR u.nisn_nip = s.nisn OR (s.gmail IS NOT NULL AND s.gmail != '' AND u.email = s.gmail)) WHERE u.id IS NULL")->fetchColumn();

        $stats = [
            'total_semua'        => $totalGuru + $totalSiswa,
            'total_guru'         => $totalGuru,
            'total_siswa'        => $totalSiswa,
            'total_users_active' => $totalUsersActive,
            'total_no_account'   => $guruNoAcc + $siswaNoAcc,
            'guru_no_account'    => $guruNoAcc,
            'siswa_no_account'   => $siswaNoAcc,
        ];

        echo json_encode([
            'success' => true,
            'data' => [
                'rows'    => $rows ?: [],
                'total'   => $total,
                'page'    => $page,
                'per'     => $perPage,
                'pages'   => max(1, (int)ceil($total / $perPage)),
                'stats'   => $stats
            ]
        ]);
        exit;
    }

    // 1.3 GENERATE AKUN TUNGGAL (1-BUTTON GENERATOR)
    if ($act === 'generate_single') {
        $sourceType = trim($_POST['source_type'] ?? ''); // 'guru' / 'siswa'
        $sourceId   = (int)($_POST['source_id'] ?? 0);
        $sendEmail  = !empty($_POST['send_email']) ? 1 : 0;

        if (!$sourceId || !in_array($sourceType, ['guru', 'siswa'], true)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Parameter generate tidak valid.']]);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $username = '';
            $password = '';
            $fullName = '';
            $email    = '';
            $phone    = '';
            $nisnNip  = '';
            $gender   = 'L';
            $roleCode = ($sourceType === 'guru') ? 'admin' : 'siswa';
            $roleName = ($sourceType === 'guru') ? 'Guru Pengampu / Tenaga Pendidik' : 'Peserta Didik (Siswa)';

            if ($sourceType === 'siswa') {
                $stmtS = $pdo->prepare("SELECT * FROM siswa WHERE id = ? LIMIT 1");
                $stmtS->execute([$sourceId]);
                $siswa = $stmtS->fetch(PDO::FETCH_ASSOC);

                if (!$siswa) {
                    throw new Exception('Data siswa tidak ditemukan.');
                }

                $fullName = $siswa['nama_lengkap'];
                $cleanNis = preg_replace('/[^0-9A-Za-z]/', '', $siswa['nis'] ?: $siswa['nisn']);
                $cleanNisn = preg_replace('/[^0-9A-Za-z]/', '', $siswa['nisn'] ?: $siswa['nis']);
                $email    = !empty($siswa['gmail']) ? trim($siswa['gmail']) : '';
                $phone    = $siswa['no_hp'] ?: '';
                $nisnNip  = $siswa['nisn'] ?: $cleanNis;

                // Rumus Password Siswa: (2 angka random) . NIS . (1 angka random) . NISN . (4 angka random)
                $r2 = str_pad(mt_rand(10, 99), 2, '0', STR_PAD_LEFT);
                $r1 = (string)mt_rand(1, 9);
                $r4 = str_pad(mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);

                // Username Siswa = NIS
                $username = !empty($cleanNis) ? $cleanNis : ('siswa_' . $r2);
                $password = $r2 . $cleanNis . $r1 . $cleanNisn . $r4;

            } else {
                // Guru
                $stmtG = $pdo->prepare("SELECT * FROM guru WHERE id = ? LIMIT 1");
                $stmtG->execute([$sourceId]);
                $guru = $stmtG->fetch(PDO::FETCH_ASSOC);

                if (!$guru) {
                    throw new Exception('Data guru tidak ditemukan.');
                }

                $fullName = $guru['nama_guru'];
                $cleanNip = preg_replace('/[^0-9A-Za-z]/', '', $guru['nip'] ?: ($guru['nuptk'] ?: $guru['kode_guru']));
                $email    = !empty($guru['email']) ? trim($guru['email']) : '';
                $phone    = $guru['no_hp'] ?: '';
                $nisnNip  = $guru['nip'] ?: $cleanNip;
                $gender   = ($guru['jenis_kelamin'] === 'P') ? 'P' : 'L';

                // Rumus Password Guru: (4 angka random) . (nomor identitas/NIP) . (2 angka random)
                $r4 = str_pad(mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                $r2 = str_pad(mt_rand(10, 99), 2, '0', STR_PAD_LEFT);

                // Username Guru = NIP / Nomor Identitas
                $username = !empty($cleanNip) ? $cleanNip : ('guru_' . $r4);
                $password = $r4 . $cleanNip . $r2;
            }

            // Cek apakah user sudah ada di database (berdasarkan username atau nisn_nip)
            $stmtCheck = $pdo->prepare("SELECT id, email FROM users WHERE username = ? OR nisn_nip = ? LIMIT 1");
            $stmtCheck->execute([$username, $nisnNip]);
            $existingUser = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            // Jika email dari sumber kosong, gunakan email user yang sudah ada
            if (empty($email) && !empty($existingUser['email']) && !str_ends_with($existingUser['email'], '@ilmuna.sch.id')) {
                $email = trim($existingUser['email']);
            }

            $realEmail = (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL) && !str_ends_with($email, '@ilmuna.sch.id')) ? $email : '';

            // Fallback Email jika kosong
            if (empty($email)) {
                $email = $username . '@ilmuna.sch.id';
            }

            $passHash = password_hash($password, PASSWORD_BCRYPT);
            $userId = 0;

            if ($existingUser) {
                $userId = (int)$existingUser['id'];
                $stmtUpd = $pdo->prepare("
                    UPDATE users 
                    SET password_hash = ?, full_name = ?, email = ?, phone = ?, status = 'active'
                    WHERE id = ?
                ");
                $stmtUpd->execute([$passHash, $fullName, $email, $phone, $userId]);
            } else {
                $stmtIns = $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, full_name, nisn_nip, gender, phone, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())
                ");
                $stmtIns->execute([$username, $email, $passHash, $fullName, $nisnNip, $gender, $phone]);
                $userId = (int)$pdo->lastInsertId();
            }

            // Hubungkan relasi pada tabel Guru jika guru
            if ($sourceType === 'guru') {
                $pdo->prepare("UPDATE guru SET id_user = ? WHERE id = ?")->execute([$userId, $sourceId]);
            }

            // Tetapkan Role
            $roleId = (int)$pdo->query("SELECT id FROM roles WHERE code = '$roleCode' LIMIT 1")->fetchColumn();
            if ($roleId > 0) {
                $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id, is_primary) VALUES (?, ?, 1)")
                    ->execute([$userId, $roleId]);
            }

            $pdo->commit();

            // Pengiriman Email Kredensial Otomatis via Gmail / SMTP
            $emailStatus = ['sent' => false, 'message' => 'Email tidak dikirim (alamat email/Gmail pengguna belum tersedia).'];
            $targetMail = !empty($realEmail) ? $realEmail : ((!empty($email) && !str_ends_with($email, '@ilmuna.sch.id')) ? $email : '');
            
            if (!empty($targetMail) && filter_var($targetMail, FILTER_VALIDATE_EMAIL)) {
                $resMail = ilmuna_send_credential_email($targetMail, $fullName, $username, $password, $roleName);
                $emailStatus = [
                    'sent'    => $resMail['success'],
                    'message' => $resMail['message']
                ];
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'message'      => "Akun untuk $fullName berhasil digenerate!",
                    'username'     => $username,
                    'password'     => $password,
                    'email'        => $email,
                    'email_status' => $emailStatus,
                    'user_id'      => $userId
                ]
            ]);
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal generate akun: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 1.4 GENERATE MASSAL / AUTOGEN BATCH (WIZARD DENGAN REALTIME LOG & TIMER)
    if ($act === 'autogen_batch') {
        @set_time_limit(60);
        $batchSize  = max(1, min(20, (int)($_POST['batch_size'] ?? 5)));
        $targetType = trim($_POST['target_type'] ?? 'all'); // 'all', 'guru', 'siswa'
        $onlyEmail  = !empty($_POST['only_with_email']) ? 1 : 0;
        $sendEmail  = !empty($_POST['send_email']) ? 1 : 0;

        // Ambil data tanpa akun
        $candidates = [];

        // Candidates Siswa
        if ($targetType === 'all' || $targetType === 'siswa') {
            $sql = "
                SELECT 'siswa' AS source_type, s.id, s.nama_lengkap, s.nis, s.nisn, s.gmail AS email
                FROM siswa s
                LEFT JOIN users u ON (u.username = s.nis OR u.nisn_nip = s.nisn OR (s.gmail IS NOT NULL AND s.gmail != '' AND u.email = s.gmail))
                WHERE u.id IS NULL
            ";
            if ($onlyEmail) {
                $sql .= " AND s.gmail IS NOT NULL AND s.gmail != '' ";
            }
            $stmt = $pdo->query($sql);
            $candidates = array_merge($candidates, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        // Candidates Guru
        if ($targetType === 'all' || $targetType === 'guru') {
            $sql = "
                SELECT 'guru' AS source_type, g.id, g.nama_guru AS nama_lengkap, g.nip, g.nuptk, g.kode_guru, g.email
                FROM guru g
                LEFT JOIN users u ON (g.id_user = u.id OR u.nisn_nip = g.nip OR (g.email IS NOT NULL AND g.email != '' AND u.email = g.email))
                WHERE u.id IS NULL
            ";
            if ($onlyEmail) {
                $sql .= " AND g.email IS NOT NULL AND g.email != '' ";
            }
            $stmt = $pdo->query($sql);
            $candidates = array_merge($candidates, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        $totalRemaining = count($candidates);

        if ($totalRemaining === 0) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'remaining'  => 0,
                    'created'    => 0,
                    'failed'     => 0,
                    'email_sent' => 0,
                    'items'      => [],
                    'done'       => true
                ]
            ]);
            exit;
        }

        // Ambil subset sesuai batchSize
        $batch = array_slice($candidates, 0, $batchSize);
        $createdCount   = 0;
        $failedCount    = 0;
        $emailSentCount = 0;
        $itemsLog       = [];

        $roleSiswaId = (int)$pdo->query("SELECT id FROM roles WHERE code = 'siswa' LIMIT 1")->fetchColumn();
        $roleAdminId = (int)$pdo->query("SELECT id FROM roles WHERE code = 'admin' LIMIT 1")->fetchColumn();

        foreach ($batch as $row) {
            try {
                $pdo->beginTransaction();

                $sourceType = $row['source_type'];
                $sourceId   = (int)$row['id'];
                $fullName   = $row['nama_lengkap'];
                $email      = !empty($row['email']) ? trim($row['email']) : '';
                $username   = '';
                $password   = '';
                $nisnNip    = '';
                $gender     = 'L';
                $roleId     = ($sourceType === 'guru') ? $roleAdminId : $roleSiswaId;
                $roleName   = ($sourceType === 'guru') ? 'Guru Pengampu' : 'Siswa';

                if ($sourceType === 'siswa') {
                    $cleanNis  = preg_replace('/[^0-9A-Za-z]/', '', $row['nis'] ?: $row['nisn']);
                    $cleanNisn = preg_replace('/[^0-9A-Za-z]/', '', $row['nisn'] ?: $row['nis']);
                    $r2 = str_pad(mt_rand(10, 99), 2, '0', STR_PAD_LEFT);
                    $r1 = (string)mt_rand(1, 9);
                    $r4 = str_pad(mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);

                    $username = !empty($cleanNis) ? $cleanNis : ('siswa_' . $r2);
                    $password = $r2 . $cleanNis . $r1 . $cleanNisn . $r4;
                    $nisnNip  = $row['nisn'] ?: $cleanNis;
                } else {
                    $cleanNip = preg_replace('/[^0-9A-Za-z]/', '', $row['nip'] ?: ($row['nuptk'] ?: $row['kode_guru']));
                    $r4 = str_pad(mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                    $r2 = str_pad(mt_rand(10, 99), 2, '0', STR_PAD_LEFT);

                    $username = !empty($cleanNip) ? $cleanNip : ('guru_' . $r4);
                    $password = $r4 . $cleanNip . $r2;
                    $nisnNip  = $row['nip'] ?: $cleanNip;
                }

                if (empty($email)) {
                    $email = $username . '@ilmuna.sch.id';
                }

                $passHash = password_hash($password, PASSWORD_BCRYPT);
                $stmtIns = $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, full_name, nisn_nip, gender, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())
                ");
                $stmtIns->execute([$username, $email, $passHash, $fullName, $nisnNip, $gender]);
                $newUserId = (int)$pdo->lastInsertId();

                if ($roleId > 0) {
                    $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id, is_primary) VALUES (?, ?, 1)")
                        ->execute([$newUserId, $roleId]);
                }

                if ($sourceType === 'guru') {
                    $pdo->prepare("UPDATE guru SET id_user = ? WHERE id = ?")->execute([$newUserId, $sourceId]);
                }

                $pdo->commit();
                $createdCount++;

                // Pengiriman Email Kredensial
                $mailSent = false;
                if ($sendEmail && !empty($row['email']) && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $mRes = ilmuna_send_credential_email($row['email'], $fullName, $username, $password, $roleName);
                    if ($mRes['success']) {
                        $mailSent = true;
                        $emailSentCount++;
                    }
                }

                $itemsLog[] = [
                    'status'    => 'ok',
                    'nama'      => $fullName,
                    'tipe'      => strtoupper($sourceType),
                    'username'  => $username,
                    'mail_sent' => $mailSent
                ];

            } catch (Exception $err) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $failedCount++;
                $itemsLog[] = [
                    'status' => 'fail',
                    'nama'   => $row['nama_lengkap'] ?? 'Unknown',
                    'error'  => $err->getMessage()
                ];
            }
        }

        $remainingAfter = max(0, $totalRemaining - count($batch));
        echo json_encode([
            'success' => true,
            'data' => [
                'remaining'  => $remainingAfter,
                'created'    => $createdCount,
                'failed'     => $failedCount,
                'email_sent' => $emailSentCount,
                'items'      => $itemsLog,
                'done'       => ($remainingAfter === 0)
            ]
        ]);
        exit;
    }

    // 1.5 RESET PASSWORD USER
    if ($act === 'reset_password') {
        $userId    = (int)($_POST['user_id'] ?? 0);
        $sendEmail = !empty($_POST['send_email']) ? 1 : 0;

        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['success' => false, 'data' => ['message' => 'User tidak ditemukan.']]);
            exit;
        }

        // Cek apakah guru atau siswa untuk menentukan rumus password
        $stmtG = $pdo->prepare("SELECT id, nip, email FROM guru WHERE id_user = ? OR nip = ? LIMIT 1");
        $stmtG->execute([$userId, $user['nisn_nip']]);
        $guru = $stmtG->fetch(PDO::FETCH_ASSOC);

        $newPassword = '';
        if ($guru) {
            $r4 = str_pad(mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);
            $r2 = str_pad(mt_rand(10, 99), 2, '0', STR_PAD_LEFT);
            $newPassword = $r4 . ($guru['nip'] ?: $user['username']) . $r2;
        } else {
            $stmtS = $pdo->prepare("SELECT id, nis, nisn, gmail FROM siswa WHERE nisn = ? OR nis = ? LIMIT 1");
            $stmtS->execute([$user['nisn_nip'], $user['username']]);
            $siswa = $stmtS->fetch(PDO::FETCH_ASSOC);

            if ($siswa) {
                $r2 = str_pad(mt_rand(10, 99), 2, '0', STR_PAD_LEFT);
                $r1 = (string)mt_rand(1, 9);
                $r4 = str_pad(mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                $newPassword = $r2 . ($siswa['nis'] ?: $user['username']) . $r1 . ($siswa['nisn'] ?: '008') . $r4;
            } else {
                $newPassword = 'ilmuna' . mt_rand(100000, 999999);
            }
        }

        $passHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$passHash, $userId]);

        $mailMsg = '';
        $userMail = (!empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL) && !str_ends_with($user['email'], '@ilmuna.sch.id')) ? trim($user['email']) : '';
        if (empty($userMail)) {
            if (!empty($siswa['gmail']) && filter_var($siswa['gmail'], FILTER_VALIDATE_EMAIL)) {
                $userMail = trim($siswa['gmail']);
            } elseif (!empty($guru['email']) && filter_var($guru['email'], FILTER_VALIDATE_EMAIL)) {
                $userMail = trim($guru['email']);
            }
        }

        $resMessage = 'Password berhasil direset.';
        if (!empty($userMail)) {
            $roleLabel = !empty($guru) ? 'Guru Pengampu' : (!empty($siswa) ? 'Peserta Didik' : 'Pengguna Portal');
            $mRes = ilmuna_send_credential_email($userMail, $user['full_name'], $user['username'], $newPassword, $roleLabel);
            $mailMsg = $mRes['success'] ? 'Terkirim ke ' . $userMail : 'Gagal kirim: ' . $mRes['message'];
        } else {
            $mailMsg = 'Alamat email pengguna belum tersedia.';
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'message'   => $resMessage,
                'email_msg' => $mailMsg
            ]
        ]);
        exit;
    }

    // 1.6 TOGGLE STATUS AKUN
    if ($act === 'toggle_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, status, username FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Pengguna tidak ditemukan.']]);
            exit;
        }

        $currUser = getCurrentUser();
        if ($userId === (int)($currUser['id'] ?? 0)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Anda tidak dapat menonaktifkan akun yang sedang aktif digunakan.']]);
            exit;
        }

        $newStatus = ($user['status'] === 'active') ? 'inactive' : 'active';
        $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $userId]);

        echo json_encode([
            'success' => true,
            'data' => [
                'message'    => "Status akun berhasil diubah menjadi " . ($newStatus === 'active' ? 'Aktif' : 'Nonaktif') . ".",
                'new_status' => $newStatus
            ]
        ]);
        exit;
    }

    // 1.7 HAPUS PENGGUNA (HANYA BISA JIKA AKUN SUDAH NONAKTIF)
    if ($act === 'delete_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $currUser = getCurrentUser();

        if ($userId === (int)($currUser['id'] ?? 0)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Anda tidak dapat menghapus akun Anda sendiri saat sedang masuk!']]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, status, username FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Pengguna tidak ditemukan.']]);
            exit;
        }

        if ($user['status'] === 'active') {
            echo json_encode(['success' => false, 'data' => ['message' => 'Akun masih berstatus aktif! Akun wajib dinonaktifkan terlebih dahulu sebelum dapat dihapus.']]);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE guru SET id_user = NULL WHERE id_user = ?")->execute([$userId]);
            $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$userId]);
            $pdo->prepare("DELETE FROM class_members WHERE user_id = ?")->execute([$userId]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
            $pdo->commit();

            echo json_encode(['success' => true, 'data' => ['message' => 'Akun pengguna berhasil dihapus.']]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal menghapus pengguna: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 1.8 KELOLA ROLE PENGGUNA (GET ALL ROLES & CURRENT USER ROLES)
    if ($act === 'get_user_roles') {
        $userId = (int)($_POST['user_id'] ?? 0);

        // Cek apakah user adalah siswa
        $isSiswa = false;
        if ($userId > 0) {
            $uStmt = $pdo->prepare("SELECT id, username, nisn_nip FROM users WHERE id = ? LIMIT 1");
            $uStmt->execute([$userId]);
            $targetUser = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($targetUser) {
                $stmtCheckS = $pdo->prepare("SELECT id FROM siswa WHERE nisn = ? OR nis = ? LIMIT 1");
                $stmtCheckS->execute([$targetUser['nisn_nip'], $targetUser['username']]);
                $isSiswa = (bool)$stmtCheckS->fetch();
            }
        }

        if ($isSiswa) {
            // Siswa hanya boleh memiliki role 'siswa' dan 'ketua_kelas'
            $allRoles = $pdo->query("SELECT id, code, name, description, badge_color FROM roles WHERE code IN ('siswa', 'ketua_kelas') ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $allRoles = $pdo->query("SELECT id, code, name, description, badge_color FROM roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        }

        $assigned = [];
        $primaryRoleId = 0;
        if ($userId > 0) {
            $stmtUR = $pdo->prepare("SELECT role_id, is_primary FROM user_roles WHERE user_id = ?");
            $stmtUR->execute([$userId]);
            $uRoles = $stmtUR->fetchAll(PDO::FETCH_ASSOC);
            foreach ($uRoles as $ur) {
                $assigned[] = (int)$ur['role_id'];
                if (!empty($ur['is_primary'])) {
                    $primaryRoleId = (int)$ur['role_id'];
                }
            }
            if (empty($primaryRoleId) && !empty($assigned)) {
                $primaryRoleId = $assigned[0];
            }
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'all_roles'       => $allRoles,
                'assigned_roles'  => $assigned,
                'primary_role_id' => $primaryRoleId,
                'is_siswa'        => $isSiswa
            ]
        ]);
        exit;
    }

    // 1.9 SIMPAN ROLE PENGGUNA
    if ($act === 'save_user_roles') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $roleIds = $_POST['role_ids'] ?? [];
        $primaryRoleId = (int)($_POST['primary_role_id'] ?? 0);

        if (!is_array($roleIds)) {
            $roleIds = !empty($roleIds) ? explode(',', (string)$roleIds) : [];
        }
        $roleIds = array_values(array_unique(array_map('intval', array_filter($roleIds))));

        if ($userId <= 0) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID pengguna tidak valid.']]);
            exit;
        }

        // Cek data user
        $uStmt = $pdo->prepare("SELECT id, username, nisn_nip FROM users WHERE id = ? LIMIT 1");
        $uStmt->execute([$userId]);
        $targetUser = $uStmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser) {
            echo json_encode(['success' => false, 'data' => ['message' => 'User tidak ditemukan.']]);
            exit;
        }

        // Cek apakah user adalah siswa
        $stmtCheckS = $pdo->prepare("SELECT id FROM siswa WHERE nisn = ? OR nis = ? LIMIT 1");
        $stmtCheckS->execute([$targetUser['nisn_nip'], $targetUser['username']]);
        $isSiswa = (bool)$stmtCheckS->fetch();

        if ($isSiswa) {
            // Siswa HANYA boleh ditambahkan role 'ketua_kelas' (selain role dasar 'siswa')
            $allowedStmt = $pdo->query("SELECT id FROM roles WHERE code IN ('siswa', 'ketua_kelas')");
            $allowedRoleIds = $allowedStmt->fetchAll(PDO::FETCH_COLUMN);
            $allowedRoleIds = array_map('intval', $allowedRoleIds);

            // Filter role_ids agar hanya memuat role yang diizinkan untuk siswa
            $roleIds = array_values(array_intersect($roleIds, $allowedRoleIds));

            // Pastikan role 'siswa' selalu ada untuk siswa
            $siswaRoleStmt = $pdo->query("SELECT id FROM roles WHERE code = 'siswa' LIMIT 1");
            $siswaRoleId = (int)$siswaRoleStmt->fetchColumn();
            if ($siswaRoleId && !in_array($siswaRoleId, $roleIds, true)) {
                $roleIds[] = $siswaRoleId;
            }
        }

        if (empty($roleIds)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Akun wajib memiliki minimal satu role aktif!']]);
            exit;
        }

        if (!in_array($primaryRoleId, $roleIds, true)) {
            $primaryRoleId = $roleIds[0];
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$userId]);

            $stmtIns = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, is_primary, assigned_at) VALUES (?, ?, ?, NOW())");
            foreach ($roleIds as $rId) {
                $isPrim = ($rId === $primaryRoleId) ? 1 : 0;
                $stmtIns->execute([$userId, $rId, $isPrim]);
            }
            $pdo->commit();

            echo json_encode(['success' => true, 'data' => ['message' => 'Role pengguna berhasil diperbarui.']]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal menyimpan role: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 1.10 TAMBAH ROLE BARU KE SISTEM
    if ($act === 'add_new_role') {
        $code = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['code'] ?? '')));
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $badge = trim($_POST['badge_color'] ?? 'teal');

        if (empty($code) || empty($name)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Kode role dan nama role wajib diisi.']]);
            exit;
        }

        $check = $pdo->prepare("SELECT id FROM roles WHERE code = ? LIMIT 1");
        $check->execute([$code]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'data' => ['message' => "Kode role '{$code}' sudah ada dalam sistem."]]);
            exit;
        }

        $pdo->prepare("INSERT INTO roles (code, name, description, badge_color, icon, created_at) VALUES (?, ?, ?, ?, 'fas fa-user-tag', NOW())")
            ->execute([$code, $name, $desc, $badge]);
        $newId = (int)$pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'data' => [
                'message' => "Role baru '{$name}' ({$code}) berhasil ditambahkan ke sistem.",
                'role' => [
                    'id' => $newId,
                    'code' => $code,
                    'name' => $name,
                    'description' => $desc,
                    'badge_color' => $badge
                ]
            ]
        ]);
        exit;
    }

    // 1.8 GET & SAVE SMTP CONFIG
    if ($act === 'get_smtp_settings') {
        $cfg = ilmuna_smtp_config();
        // Sembunyikan sebagian password demi keamanan tampilan
        $masked = $cfg;
        if (!empty($masked['pass'])) {
            $masked['pass_masked'] = substr($masked['pass'], 0, 3) . '********' . substr($masked['pass'], -2);
        }
        echo json_encode(['success' => true, 'data' => ['config' => $masked]]);
        exit;
    }

    if ($act === 'save_smtp_settings') {
        $host     = trim($_POST['host'] ?? 'smtp.gmail.com');
        $port     = (int)($_POST['port'] ?? 587);
        $secure   = trim($_POST['secure'] ?? 'tls');
        $user     = trim($_POST['user'] ?? '');
        $pass     = trim($_POST['pass'] ?? '');
        $from     = trim($_POST['from'] ?? '');
        $fromName = trim($_POST['from_name'] ?? 'Ilmuna Learn');
        $enabled  = !empty($_POST['is_enabled']) ? 1 : 0;

        $current = ilmuna_smtp_config();
        if (empty($pass) && !empty($current['pass'])) {
            $pass = $current['pass']; // Pertahankan pass lama jika input dikosongkan
        }

        $ok = ilmuna_save_smtp_config([
            'host'       => $host,
            'port'       => $port,
            'secure'     => $secure,
            'user'       => $user,
            'pass'       => $pass,
            'from'       => $from,
            'from_name'  => $fromName,
            'is_enabled' => $enabled
        ]);

        if ($ok) {
            echo json_encode(['success' => true, 'data' => ['message' => 'Pengaturan SMTP & Gmail berhasil disimpan.']]);
        } else {
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal menyimpan pengaturan SMTP ke berkas konfigurasi.']]);
        }
        exit;
    }

    // 1.9 TEST SMTP CONNECTION
    if ($act === 'test_smtp') {
        $testEmail = trim($_POST['test_email'] ?? '');
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Masukkan alamat email tujuan uji coba yang valid.']]);
            exit;
        }

        $res = ilmuna_send_credential_email($testEmail, 'Administrator Penguji', 'uji_coba_user', 'DemoPass12345!', 'Super Administrator');
        if ($res['success']) {
            echo json_encode(['success' => true, 'data' => ['message' => 'Uji coba sukses! ' . $res['message']]]);
        } else {
            echo json_encode(['success' => false, 'data' => ['message' => 'Uji coba gagal: ' . $res['message']]]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'data' => ['message' => 'Aksi tidak dikenali.']]);
    exit;
}

// Versioning CSS
$cssVersion = file_exists(BASE_PATH . '/assets/css/admin-users.css') ? filemtime(BASE_PATH . '/assets/css/admin-users.css') : '1.0';
$extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-users.css?v=' . $cssVersion . '">';

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<!-- ==============================================================================
 * LUMINA GOLD THEMED USER MANAGEMENT & CREDENTIAL ENGINE
 * ============================================================================== -->
<div class="ilmuna-users-app" id="appUsers" data-ajax="<?= BASE_URL ?>/dashboard/admin/users">

    <!-- FILTER & SEARCH CONTROL BOX (Tombol Aksi di Baris Pertama) -->
    <div class="ilmuna-filter-box">

        <!-- Action Buttons Row -->
        <div class="ilmuna-filter-row" style="justify-content: flex-end; gap: 8px; padding-bottom: 12px; border-bottom: 1px dashed #E2E8F0;">
            <!-- Tombol Pengaturan Gmail & SMTP -->
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="btnOpenSmtpSettings" onclick="window.openSmtpSettingsModal && window.openSmtpSettingsModal()" title="Atur Akun Pengirim Gmail & SMTP">
                <iconify-icon icon="solar:letter-bold" width="18"></iconify-icon>
                Pengaturan Gmail
            </button>
            <!-- Tombol Generate Massal -->
            <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="btnOpenAutogenModal" onclick="window.openAutogenModal && window.openAutogenModal()" title="Buat Akun Otomatis Sekaligus untuk Seluruh Siswa & Guru">
                <iconify-icon icon="solar:bolt-circle-bold" width="20"></iconify-icon>
                Generate Akun Massal
            </button>
        </div>

        <div class="ilmuna-filter-row">
            <!-- Filter Tipe Entitas -->
            <div class="ilmuna-pill-group" id="filterTypePills">
                <button type="button" class="ilmuna-pill-btn is-active" data-type="all">
                    <iconify-icon icon="solar:users-group-rounded-bold" width="16"></iconify-icon>
                    Semua
                </button>
                <button type="button" class="ilmuna-pill-btn" data-type="guru">
                    <iconify-icon icon="solar:user-speak-bold" width="16"></iconify-icon>
                    Dewan Guru
                </button>
                <button type="button" class="ilmuna-pill-btn" data-type="siswa">
                    <iconify-icon icon="solar:backpack-bold" width="16"></iconify-icon>
                    Siswa
                </button>
            </div>

            <!-- Filter Status Akun -->
            <div class="ilmuna-pill-group" id="filterStatusPills">
                <button type="button" class="ilmuna-pill-btn is-active" data-status="all">Semua Status</button>
                <button type="button" class="ilmuna-pill-btn" data-status="no_account" style="color: #D97706;">
                    <iconify-icon icon="solar:close-circle-bold" width="14"></iconify-icon>
                    Belum Ada Akun
                </button>
                <button type="button" class="ilmuna-pill-btn" data-status="has_account" style="color: #059669;">
                    <iconify-icon icon="solar:check-circle-bold" width="14"></iconify-icon>
                    Sudah Punya Akun
                </button>
            </div>
        </div>

        <div class="ilmuna-filter-row">
            <!-- Dropdown Filter Kelas (Hanya untuk Siswa) -->
            <div style="display: flex; align-items: center; gap: 8px;">
                <select id="filterKelas" class="ilmuna-select">
                    <option value="0">— Seluruh Rombel / Kelas —</option>
                </select>
            </div>

            <!-- Input Pencarian Realtime Instant -->
            <div class="ilmuna-filter-controls">
                <div class="ilmuna-search-wrap">
                    <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
                    <input type="text" id="filterSearch" class="ilmuna-search-input" placeholder="Cari nama, NIS, NISN, NIP, username, atau email...">
                </div>
                <select id="filterPerPage" class="ilmuna-select" style="width: auto;">
                    <option value="10">10 Baris</option>
                    <option value="20" selected>20 Baris</option>
                    <option value="50">50 Baris</option>
                    <option value="100">100 Baris</option>
                </select>
            </div>
        </div>
    </div>

    <!-- DATA TABLE CONTAINER -->
    <div class="ilmuna-table-container">
        <div class="ilmuna-table-scroll">
            <table class="ilmuna-table" id="tableUsers">
                <thead>
                    <tr>
                        <th style="width: 44px; text-align: center;">
                            <input type="checkbox" id="checkAllUsers" class="ilmuna-bulk-cb" title="Pilih Semua">
                        </th>
                        <th style="width: 45px; text-align: center;">#</th>
                        <th style="width: 60px; text-align: center;">Foto</th>
                        <th>Nama Lengkap & Kontak</th>
                        <th style="width: 140px;">Jenis & Rombel</th>
                        <th style="width: 170px;">Nomor Identitas</th>
                        <th style="width: 190px;">Akun Portal (Login)</th>
                        <th style="width: 130px; text-align: center;">Status Akun</th>
                        <th style="width: 170px; text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tableUsersBody">
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: #64748B;">
                            <iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="28"></iconify-icon>
                            <p style="margin-top: 8px; font-weight: 600;">Memuat data pengguna...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination Bar -->
        <div class="ilmuna-pagination-bar">
            <div class="ilmuna-pagination-info" id="paginationInfo">Memuat info halaman...</div>
            <div class="ilmuna-pagination-btns" id="paginationBtns"></div>
        </div>
    </div>

    <!-- FLOATING BULK ACTIONS BAR -->
    <div class="ilmuna-bulk-bar" id="bulkActionBar">
        <div class="ilmuna-bulk-bar__count">
            <span id="bulkSelectedCount">0</span> Pengguna Terpilih
        </div>
        <div class="ilmuna-bulk-bar__actions">
            <button type="button" class="ilmuna-btn ilmuna-btn--gold ilmuna-btn--sm" id="btnBulkGenerate">
                <iconify-icon icon="solar:bolt-circle-bold" width="16"></iconify-icon>
                Generate Akun Terpilih
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--sm" id="btnBulkCancel">
                Batal
            </button>
        </div>
    </div>

</div>

<!-- ==============================================================================
 * MODAL 1: AUTOGEN MASSAL (WIZARD & PROGRESS BAR)
 * ============================================================================== -->
<div class="ilmuna-user-modal" id="modalAutogen">
    <div class="ilmuna-user-modal__overlay" data-close="1"></div>
    <div class="ilmuna-user-modal__box">
        <div class="ilmuna-user-modal__head">
            <h3>
                <iconify-icon icon="solar:bolt-circle-bold" width="22" style="color: #D97706;"></iconify-icon>
                <span>Generate Akun Massal Otomatis</span>
            </h3>
            <button type="button" class="ilmuna-user-modal__close" data-close="1">&times;</button>
        </div>
        <div class="ilmuna-user-modal__body">
            <!-- Form Opsi Autogen -->
            <div id="autogenSetupBox">
                <p style="margin: 0 0 16px; font-size: 13.5px; color: #475569; line-height: 1.6;">
                    Fitur ini akan secara otomatis membuatkan akun login resmi bagi seluruh Siswa dan Guru yang saat ini <strong>belum memiliki akun</strong>.
                </p>

                <div style="background: #FFFBEB; border: 1.5px solid #FCD34D; border-radius: 12px; padding: 14px 16px; margin-bottom: 18px;">
                    <h5 style="margin: 0 0 6px; font-size: 12.5px; font-weight: 800; color: #92400E; text-transform: uppercase; letter-spacing: 0.5px;">
                        📌 Rumus Kredensial Resmi:
                    </h5>
                    <ul style="margin: 0; padding-left: 18px; font-size: 12px; color: #78350F; line-height: 1.6;">
                        <li><strong>Siswa:</strong> Username = <code>NIS</code> | Password = <code>[2 angka acak][NIS][1 angka acak][NISN][4 angka acak]</code></li>
                        <li><strong>Guru:</strong> Username = <code>NIP</code> | Password = <code>[4 angka acak][NIP][2 angka acak]</code></li>
                    </ul>
                </div>

                <div class="ilmuna-form-field">
                    <label>Sasaran Pengguna</label>
                    <select id="autogenTargetType" class="ilmuna-input">
                        <option value="all">Seluruh Data (Guru & Siswa)</option>
                        <option value="siswa" selected>Khusus Siswa Saja</option>
                        <option value="guru">Khusus Guru Saja</option>
                    </select>
                </div>

                <div class="ilmuna-form-row">
                    <div class="ilmuna-form-field">
                        <label>Ukuran Batch Per Request</label>
                        <select id="autogenBatchSize" class="ilmuna-input">
                            <option value="5" selected>5 Akun / Batch (Direkomendasikan)</option>
                            <option value="10">10 Akun / Batch (Cepat)</option>
                            <option value="20">20 Akun / Batch (Sangat Cepat)</option>
                        </select>
                    </div>
                    <div class="ilmuna-form-field" style="display: flex; flex-direction: column; justify-content: flex-end; padding-bottom: 6px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600;">
                            <input type="checkbox" id="autogenSendEmail" class="ilmuna-bulk-cb" checked>
                            Kirim Notifikasi Kredensial via Gmail
                        </label>
                    </div>
                </div>

                <div class="ilmuna-form-field">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600;">
                        <input type="checkbox" id="autogenOnlyWithEmail" class="ilmuna-bulk-cb">
                        Hanya proses data yang sudah memiliki alamat Gmail / Email
                    </label>
                </div>
            </div>

            <!-- Progress Realtime View (Hidden at first) -->
            <div id="autogenProgressView" style="display: none;">
                <div class="ilmuna-autogen-progress">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span id="autogenProgressLabel" style="font-size: 13px; font-weight: 700;">Memulai proses...</span>
                        <span id="autogenTimer" style="font-size: 12px; font-family: monospace; color: #FCD34D;">00:00</span>
                    </div>
                    <div class="ilmuna-progress-bar-wrap">
                        <div class="ilmuna-progress-bar-fill" id="autogenProgressBar"></div>
                    </div>
                    <div class="ilmuna-autogen-stats">
                        <span id="autogenStatCreated">Berhasil: 0</span>
                        <span id="autogenStatFailed">Gagal: 0</span>
                        <span id="autogenStatMails">Email Terkirim: 0</span>
                        <span id="autogenStatRemaining">Sisa: -</span>
                    </div>
                </div>

                <div class="ilmuna-autogen-log" id="autogenLogBox">
                    <div class="log-info">> Sistem siap melakukan eksekusi pembuatan akun...</div>
                </div>
            </div>
        </div>
        <div class="ilmuna-user-modal__foot">
            <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" data-close="1" id="btnCancelAutogen">Tutup</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="btnStartAutogen">
                <iconify-icon icon="solar:play-bold" width="16"></iconify-icon>
                Mulai Eksekusi Pembuatan Akun
            </button>
        </div>
    </div>
</div>

<!-- ==============================================================================
 * MODAL 2: PENGATURAN GMAIL & SMTP (TERPISAH & DIUBAH REALTIME)
 * ============================================================================== -->
<div class="ilmuna-user-modal" id="modalSmtpSettings">
    <div class="ilmuna-user-modal__overlay" data-close="1"></div>
    <div class="ilmuna-user-modal__box" style="max-width: 580px;">
        <div class="ilmuna-user-modal__head">
            <h3>
                <iconify-icon icon="solar:letter-bold" width="22" style="color: #0D7A87;"></iconify-icon>
                <span>Pengaturan Server Gmail & SMTP</span>
            </h3>
            <button type="button" class="ilmuna-user-modal__close" data-close="1">&times;</button>
        </div>
        <form id="formSmtpSettings" class="ilmuna-user-modal__body" onsubmit="return false;">
            <p style="margin: 0 0 16px; font-size: 13px; color: #64748B;">
                Konfigurasi ini digunakan server untuk mengirimkan kredensial (username & password) otomatis kepada guru dan siswa melalui Gmail.
            </p>

            <div class="ilmuna-form-field">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" id="smtpIsEnabled" name="is_enabled" class="ilmuna-bulk-cb" value="1" checked>
                    <strong>Aktifkan Fitur Pengiriman Email Otomatis</strong>
                </label>
            </div>

            <div class="ilmuna-form-row">
                <div class="ilmuna-form-field">
                    <label>SMTP Host <span class="req">*</span></label>
                    <input type="text" id="smtpHost" name="host" class="ilmuna-input" value="smtp.gmail.com" required>
                </div>
                <div class="ilmuna-form-field">
                    <label>Port SMTP <span class="req">*</span></label>
                    <input type="number" id="smtpPort" name="port" class="ilmuna-input" value="587" required>
                </div>
            </div>

            <div class="ilmuna-form-row">
                <div class="ilmuna-form-field">
                    <label>Enkripsi Keamanan <span class="req">*</span></label>
                    <select id="smtpSecure" name="secure" class="ilmuna-input">
                        <option value="tls" selected>TLS (Port 587)</option>
                        <option value="ssl">SSL (Port 465)</option>
                    </select>
                </div>
                <div class="ilmuna-form-field">
                    <label>Nama Pengirim (From Name) <span class="req">*</span></label>
                    <input type="text" id="smtpFromName" name="from_name" class="ilmuna-input" value="Ilmuna Learn" required>
                </div>
            </div>

            <div class="ilmuna-form-row">
                <div class="ilmuna-form-field">
                    <label>Alamat Email Akun Pengirim <span class="req">*</span></label>
                    <input type="email" id="smtpUser" name="user" class="ilmuna-input" placeholder="contoh: roinusman@gmail.com" required>
                </div>
                <div class="ilmuna-form-field">
                    <label>Sandi Aplikasi (App Password) <span class="req">*</span></label>
                    <input type="password" id="smtpPass" name="pass" class="ilmuna-input" placeholder="16 digit sandi aplikasi Google" autocomplete="new-password">
                    <small style="color: #94A3B8; font-size: 11px;">Gunakan Sandi Aplikasi (App Password) 2-Langkah Google.</small>
                </div>
            </div>

            <hr style="border: none; border-top: 1px dashed #E2E8F0; margin: 18px 0;">

            <!-- Test Kirim Email Box -->
            <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 14px;">
                <label style="font-size: 12px; font-weight: 700; color: #1D3B5E; margin-bottom: 6px; display: block;">
                    🧪 Uji Coba Pengiriman Email
                </label>
                <div style="display: flex; gap: 8px;">
                    <input type="email" id="testEmailTarget" class="ilmuna-input" placeholder="Masukkan email penerima uji coba..." style="flex: 1;">
                    <button type="button" class="ilmuna-btn ilmuna-btn--teal" id="btnTestSmtp">
                        <iconify-icon icon="solar:plain-bold" width="16"></iconify-icon>
                        Kirim Uji Coba
                    </button>
                </div>
            </div>
        </form>
        <div class="ilmuna-user-modal__foot">
            <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--teal" id="btnSaveSmtp">
                <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                Simpan Pengaturan
            </button>
        </div>
    </div>
</div>

<!-- ==============================================================================
 * MODAL 3: KREDENSIAL HASIL GENERATE (MODAL SUKSES 1-KLIK)
 * ============================================================================== -->
<div class="ilmuna-user-modal" id="modalResultSingle">
    <div class="ilmuna-user-modal__overlay" data-close="1"></div>
    <div class="ilmuna-user-modal__box" style="max-width: 500px; text-align: center;">
        <div class="ilmuna-user-modal__head" style="justify-content: center; background: #ECFDF5; border-color: #A7F3D0;">
            <h3 style="color: #065F46;">
                <iconify-icon icon="solar:check-circle-bold" width="24" style="color: #10B981;"></iconify-icon>
                <span>Akun Berhasil Dibuat!</span>
            </h3>
        </div>
        <div class="ilmuna-user-modal__body" style="padding: 26px 24px;">
            <p id="resFullName" style="font-size: 16px; font-weight: 800; color: #1D3B5E; margin: 0 0 16px;"></p>

            <div style="background: #FFFBEB; border: 1.5px solid #FCD34D; border-radius: 14px; padding: 18px; margin-bottom: 18px; text-align: left;">
                <div style="margin-bottom: 12px;">
                    <span style="font-size: 11px; font-weight: 700; color: #B45309; text-transform: uppercase;">Username Login:</span>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 3px;">
                        <span id="resUsername" style="font-family: monospace; font-size: 17px; font-weight: 800; color: #1D3B5E;"></span>
                        <button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" id="btnCopyUsername">Salin</button>
                    </div>
                </div>
                <div style="border-top: 1px dashed #FCD34D; padding-top: 12px;">
                    <span style="font-size: 11px; font-weight: 700; color: #B45309; text-transform: uppercase;">Password Login:</span>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 3px;">
                        <span id="resPassword" style="font-family: monospace; font-size: 17px; font-weight: 800; color: #B45309;"></span>
                        <button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" id="btnCopyPassword">Salin</button>
                    </div>
                </div>
            </div>

            <div id="resMailNotice" style="font-size: 12.5px; color: #64748B; padding: 10px; background: #F1F5F9; border-radius: 10px;"></div>
        </div>
        <div class="ilmuna-user-modal__foot" style="justify-content: center;">
            <button type="button" class="ilmuna-btn ilmuna-btn--teal" data-close="1" style="width: 100%; justify-content: center;">
                Selesai & Tutup
            </button>
        </div>
    </div>
</div>

<!-- ==============================================================================
 * MODAL 5: KELOLA ROLE PENGGUNA (ROLE MANAGEMENT & TAMBAH ROLE SISWA/GURU)
 * ============================================================================== -->
<div class="ilmuna-user-modal" id="modalManageRoles">
    <div class="ilmuna-user-modal__overlay" data-close="1"></div>
    <div class="ilmuna-user-modal__box" style="max-width: 560px;">
        <div class="ilmuna-user-modal__head">
            <h3>
                <iconify-icon icon="solar:shield-user-bold" width="22" style="color: #0D7A87;"></iconify-icon>
                <span>Kelola Role Akun Pengguna</span>
            </h3>
            <button type="button" class="ilmuna-user-modal__close" data-close="1">&times;</button>
        </div>
        <div class="ilmuna-user-modal__body" style="padding: 22px 24px;">
            <input type="hidden" id="roleModalUserId" value="0">

            <!-- User Header Card -->
            <div style="display: flex; align-items: center; gap: 14px; background: #F8FAFC; border: 1.5px solid #E2E8F0; border-radius: 14px; padding: 14px 16px; margin-bottom: 16px;">
                <div id="roleModalAvatar" class="ilmuna-avatar ilmuna-avatar--teal" style="width: 44px; height: 44px; font-size: 15px;">U</div>
                <div style="flex: 1; min-width: 0;">
                    <h4 id="roleModalUserName" style="margin: 0 0 3px; font-size: 15px; font-weight: 800; color: #1D3B5E; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Nama Pengguna</h4>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span id="roleModalUserHandle" class="ilmuna-code-pill" style="font-size: 11.5px;">@username</span>
                        <span id="roleModalUserType" class="ilmuna-badge ilmuna-badge--teal" style="font-size: 10.5px;">Siswa</span>
                    </div>
                </div>
            </div>

            <!-- Notice Khusus Siswa -->
            <div id="roleModalSiswaInfo" style="display: none; background: #F0FDFA; border: 1.5px solid #99F6E4; border-radius: 12px; padding: 10px 14px; margin-bottom: 14px; font-size: 12px; color: #0F766E; line-height: 1.5;">
                <iconify-icon icon="solar:info-circle-bold" width="16" style="vertical-align: -2px; color: #0D7A87; margin-right: 4px;"></iconify-icon>
                Akun Siswa memiliki peran dasar <strong>Siswa</strong> dan hanya dapat ditugaskan peran tambahan sebagai <strong>Ketua Kelas</strong>.
            </div>

            <!-- Role Selection Box -->
            <div style="margin-bottom: 18px;">
                <label style="display: block; font-size: 12.5px; font-weight: 700; color: #1D3B5E; margin-bottom: 4px;">
                    Daftar Role Akun Ini
                </label>
                <p id="roleModalHelpText" style="font-size: 12px; color: #64748B; margin: 0 0 12px; line-height: 1.5;">
                    Centang role yang ingin diberikan kepada akun ini. Pilih <strong>Utama</strong> untuk menetapkan primary role saat login.
                </p>
                <div id="roleCheckboxList" style="display: flex; flex-direction: column; gap: 10px; max-height: 240px; overflow-y: auto; padding-right: 4px;">
                    <div style="text-align: center; padding: 20px; color: #94A3B8;">Memuat data role...</div>
                </div>
            </div>

            <!-- Accordion / Toggle Box Tambah Role Baru (Hanya untuk Non-Siswa) -->
            <div id="roleModalNewRoleSection" style="border-top: 1px dashed #CBD5E1; padding-top: 14px;">
                <button type="button" id="btnToggleNewRoleForm" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" style="width: 100%; justify-content: space-between;">
                    <span style="display: flex; align-items: center; gap: 6px;">
                        <iconify-icon icon="solar:add-circle-bold" width="16" style="color: #0D7A87;"></iconify-icon>
                        <strong>Tambah Role Baru ke Sistem</strong>
                    </span>
                    <iconify-icon icon="solar:alt-arrow-down-bold" id="iconToggleNewRole"></iconify-icon>
                </button>

                <div id="boxAddNewRole" style="display: none; background: #F0FDFA; border: 1.5px solid #99F6E4; border-radius: 12px; padding: 14px; margin-top: 12px;">
                    <h5 style="margin: 0 0 10px; font-size: 12.5px; font-weight: 800; color: #0F766E;">Buat Definisi Role Baru (Contoh: Ketua Kelas, Bendahara, OSIS)</h5>
                    <div class="ilmuna-form-row" style="margin-bottom: 10px;">
                        <div class="ilmuna-form-field" style="flex: 1;">
                            <label style="font-size: 11.5px; color: #0F766E;">Kode Role (Huruf kecil & underscore)</label>
                            <input type="text" id="newRoleCode" class="ilmuna-input" placeholder="misal: ketua_kelas, sekretaris" style="font-size: 12px; padding: 7px 10px;">
                        </div>
                        <div class="ilmuna-form-field" style="flex: 1;">
                            <label style="font-size: 11.5px; color: #0F766E;">Nama Tampilan Role</label>
                            <input type="text" id="newRoleName" class="ilmuna-input" placeholder="misal: Ketua Kelas, Sekretaris" style="font-size: 12px; padding: 7px 10px;">
                        </div>
                    </div>
                    <div class="ilmuna-form-field" style="margin-bottom: 10px;">
                        <label style="font-size: 11.5px; color: #0F766E;">Deskripsi Hak Akses / Keterangan Singkat</label>
                        <input type="text" id="newRoleDesc" class="ilmuna-input" placeholder="Keterangan singkat fungsi role ini..." style="font-size: 12px; padding: 7px 10px;">
                    </div>
                    <div style="display: flex; justify-content: flex-end;">
                        <button type="button" id="btnSubmitNewRole" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm">
                            <iconify-icon icon="solar:check-circle-bold" width="15"></iconify-icon>
                            Simpan Role Baru
                        </button>
                    </div>
                </div>
            </div>

        </div>
        <div class="ilmuna-user-modal__foot" style="justify-content: flex-end; gap: 8px;">
            <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--teal" id="btnSaveUserRoles">
                <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                Simpan Perubahan Role
            </button>
        </div>
    </div>
</div>

<!-- ==============================================================================
 * CONFIRM OVERLAY & TOAST NOTIFICATION CONTAINER
 * ============================================================================== -->
<div class="ilmuna-user-modal" id="confirmModal">
    <div class="ilmuna-user-modal__overlay" data-close="1"></div>
    <div class="ilmuna-user-modal__box" style="max-width: 440px; text-align: center;">
        <div class="ilmuna-user-modal__body" style="padding: 28px 24px 20px;">
            <div id="confirmIconWrap" style="width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; background: #FEF3C7; color: #D97706; font-size: 32px;">
                <iconify-icon icon="solar:danger-triangle-bold"></iconify-icon>
            </div>
            <h4 id="confirmTitle" style="font-size: 17px; font-weight: 800; color: #1D3B5E; margin: 0 0 8px;">Konfirmasi Tindakan</h4>
            <p id="confirmMsg" style="font-size: 13.5px; color: #64748B; margin: 0 0 20px; line-height: 1.5;"></p>
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" id="btnConfirmCancel" style="flex: 1; justify-content: center;">Batal</button>
                <button type="button" class="ilmuna-btn ilmuna-btn--teal" id="btnConfirmOk" style="flex: 1; justify-content: center;">Ya, Lanjutkan</button>
            </div>
        </div>
    </div>
</div>

<div class="ilmuna-toast-wrap" id="toastWrap"></div>

<!-- ==============================================================================
 * JAVASCRIPT APPLICATION ENGINE
 * ============================================================================== -->
<script>
(function() {
    'use strict';

    var app = document.getElementById('appUsers');
    if (!app) return;

    var AJAX_URL = app.dataset.ajax;

    // Pindahkan modals ke document.body agar stacking context sempurna
    ['modalAutogen', 'modalSmtpSettings', 'modalResultSingle', 'modalManageRoles', 'confirmModal', 'toastWrap', 'bulkActionBar'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode && el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    });

    // State
    var state = {
        type: 'all',
        status: 'all',
        id_kelas: 0,
        search: '',
        page: 1,
        per_page: 20,
        total: 0,
        pages: 1
    };

    var selectedUsers = new Set();
    var searchTimer = null;

    /* 1. TOAST NOTIFICATION */
    function toast(type, msg) {
        var normType = (type === 'danger' || type === 'err' || type === 'error') ? 'error' : ((type === 'warn' || type === 'warning') ? 'warning' : (type === 'info' ? 'info' : 'success'));
        var titleMap = { success: 'Berhasil', error: 'Gagal', warning: 'Peringatan', info: 'Informasi' };
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            return Ilmuna.toast(normType, titleMap[normType] || 'Pemberitahuan', msg, 4500);
        }
        var wrap = document.getElementById('toastWrap');
        if (!wrap) return;

        var el = document.createElement('div');
        el.className = 'ilmuna-toast ilmuna-toast--' + type;
        var icon = (type === 'success') ? 'solar:check-circle-bold' : ((type === 'error') ? 'solar:danger-circle-bold' : 'solar:info-circle-bold');
        el.innerHTML = '<iconify-icon icon="' + icon + '" class="ilmuna-toast__icon" width="22"></iconify-icon>' +
                       '<div class="ilmuna-toast__msg">' + escapeHtml(msg) + '</div>' +
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
        setTimeout(function(){ el.remove(); }, 300);
    }

    /* 2. CONFIRM PROMISE */
    function confirmDialog(msg, opts) {
        opts = opts || {};
        var type = (opts.type === 'danger' || opts.type === 'delete' || opts.type === 'error') ? 'error' : (opts.type || 'warning');
        if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
            return Ilmuna.confirm(type, opts.title || 'Konfirmasi', msg, opts.okText || 'Ya, Lanjutkan', opts.cancelText || 'Batal');
        }
        return new Promise(function(resolve) {
            var m = document.getElementById('confirmModal');
            var titleEl = document.getElementById('confirmTitle');
            var msgEl   = document.getElementById('confirmMsg');
            var okBtn   = document.getElementById('btnConfirmOk');
            var ccBtn   = document.getElementById('btnConfirmCancel');
            var iconWrap = document.getElementById('confirmIconWrap');

            titleEl.textContent = opts.title || 'Konfirmasi';
            msgEl.textContent   = msg;
            okBtn.textContent   = opts.okText || 'Ya, Lanjutkan';

            if (opts.type === 'danger') {
                iconWrap.style.background = '#FEE2E2';
                iconWrap.style.color = '#EF4444';
                okBtn.className = 'ilmuna-btn ilmuna-btn--danger';
            } else {
                iconWrap.style.background = '#E0F2FE';
                iconWrap.style.color = '#0D7A87';
                okBtn.className = 'ilmuna-btn ilmuna-btn--teal';
            }

            openModal('confirmModal');

            function cleanup(result) {
                closeModal('confirmModal');
                okBtn.removeEventListener('click', onOk);
                ccBtn.removeEventListener('click', onCc);
                resolve(result);
            }
            function onOk() { cleanup(true); }
            function onCc() { cleanup(false); }

            okBtn.addEventListener('click', onOk);
            ccBtn.addEventListener('click', onCc);
        });
    }

    /* 3. MODAL HELPERS */
    function openModal(id) {
        var m = document.getElementById(id);
        if (m) {
            m.classList.add('is-open');
            document.body.classList.add('ilmuna-modal-open');
        }
    }
    function closeModal(id) {
        var m = document.getElementById(id);
        if (m) {
            m.classList.remove('is-open');
            if (!document.querySelector('.ilmuna-user-modal.is-open')) {
                document.body.classList.remove('ilmuna-modal-open');
            }
        }
    }
    window.openModal = openModal;
    window.closeModal = closeModal;

    // Global listener untuk tombol close atau klik backdrop pada ilmuna-user-modal
    document.addEventListener('click', function(e) {
        var closeTrigger = e.target.closest('[data-close="1"]');
        if (closeTrigger) {
            var modal = closeTrigger.closest('.ilmuna-user-modal');
            if (modal) {
                closeModal(modal.id);
            }
        }
    });

    function openAutogenModal() {
        document.getElementById('autogenSetupBox').style.display = 'block';
        document.getElementById('autogenProgressView').style.display = 'none';
        document.getElementById('btnStartAutogen').style.display = 'inline-flex';
        document.getElementById('btnStartAutogen').disabled = false;
        document.getElementById('btnCancelAutogen').textContent = 'Batal';
        openModal('modalAutogen');
    }
    window.openAutogenModal = openAutogenModal;

    function openSmtpSettingsModal() {
        var fd = new FormData();
        fd.append('action', 'get_smtp_settings');
        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (res && res.success && res.data.config) {
                    var c = res.data.config;
                    document.getElementById('smtpHost').value = c.host || 'smtp.gmail.com';
                    document.getElementById('smtpPort').value = c.port || 587;
                    document.getElementById('smtpSecure').value = c.secure || 'tls';
                    document.getElementById('smtpFromName').value = c.from_name || 'Ilmuna Learn';
                    document.getElementById('smtpUser').value = c.user || '';
                    document.getElementById('smtpPass').value = '';
                    document.getElementById('smtpIsEnabled').checked = (c.is_enabled == 1);
                }
                openModal('modalSmtpSettings');
            })
            .catch(function(){ openModal('modalSmtpSettings'); });
    }
    window.openSmtpSettingsModal = openSmtpSettingsModal;

    /* 4. FETCH KELAS OPTIONS */
    function loadKelasOptions() {
        var fd = new FormData();
        fd.append('action', 'get_kelas_options');
        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (res && res.success && res.data.kelas) {
                    var s = document.getElementById('filterKelas');
                    var html = '<option value="0">— Seluruh Rombel / Kelas —</option>';
                    res.data.kelas.forEach(function(k) {
                        html += '<option value="' + k.id + '">' + escapeHtml(k.nama_kelas) + ' (' + escapeHtml(k.tahun_pelajaran) + ')</option>';
                    });
                    s.innerHTML = html;
                }
            })
            .catch(function(err){ console.error(err); });
    }

    /* 5. FETCH & RENDER USERS LIST */
    function fetchList() {
        var tbody = document.getElementById('tableUsersBody');
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 40px; color: #64748B;">' +
            '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="28"></iconify-icon>' +
            '<p style="margin-top: 8px; font-weight: 600;">Memuat data pengguna...</p>' +
        '</td></tr>';

        var fd = new FormData();
        fd.append('action', 'list');
        fd.append('type', state.type);
        fd.append('status', state.status);
        fd.append('id_kelas', state.id_kelas);
        fd.append('search', state.search);
        fd.append('page', state.page);
        fd.append('per_page', state.per_page);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (!res || !res.success) {
                    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:30px; color:#ef4444;">Gagal memuat data.</td></tr>';
                    return;
                }

                var data = res.data;
                state.total = data.total;
                state.pages = data.pages;

                // Update KPI (null-safe — KPI cards sudah dihapus dari view)
                if (data.stats) updateKpis(data.stats);

                // Render Rows
                renderTableRows(data.rows);

                // Render Pagination
                renderPagination(data.total, data.page, data.per, data.pages);
            })
            .catch(function() {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:30px; color:#ef4444;">Terjadi kendala jaringan.</td></tr>';
            });
    }

    // Null-safe KPI updater (aman dipanggil meski elemen tidak ada di DOM)
    function updateKpis(s) {
        if (!s) return;
        var el1 = document.getElementById('kpiTotalSemua');
        var el2 = document.getElementById('kpiSubSemua');
        var el3 = document.getElementById('kpiUsersActive');
        var el4 = document.getElementById('kpiNoAccount');
        var el5 = document.getElementById('kpiSubNoAccount');
        var el6 = document.getElementById('kpiSiswaCount');
        var el7 = document.getElementById('kpiGuruCount');
        if (el1) el1.textContent = s.total_semua;
        if (el2) el2.textContent = s.total_guru + ' Guru & ' + s.total_siswa + ' Siswa';
        if (el3) el3.textContent = s.total_users_active;
        if (el4) el4.textContent = s.total_no_account;
        if (el5) el5.textContent = s.siswa_no_account + ' Siswa & ' + s.guru_no_account + ' Guru';
        if (el6) el6.textContent = s.total_siswa;
        if (el7) el7.textContent = s.total_guru + ' Guru Pengampu';
    }

    function renderTableRows(rows) {
        var tbody = document.getElementById('tableUsersBody');
        var checkAll = document.getElementById('checkAllUsers');
        checkAll.checked = false;

        if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 40px 20px; color: #64748B;">' +
                '<iconify-icon icon="solar:user-block-rounded-bold" width="40" style="color:#CBD5E1; margin-bottom: 8px;"></iconify-icon>' +
                '<h4 style="margin: 0 0 4px; color:#1D3B5E;">Tidak Ada Data Pengguna</h4>' +
                '<p style="margin:0; font-size:12.5px;">Coba ubah filter pencarian atau buat akun baru.</p>' +
            '</td></tr>';
            return;
        }

        var html = '';
        var colors = ['navy', 'teal', 'gold', 'sky'];

        rows.forEach(function(r, idx) {
            var rowNum = (state.page - 1) * state.per_page + idx + 1;
            var isChecked = selectedUsers.has(r.source_type + '_' + r.source_id);
            var avColor = colors[(r.source_id || idx) % colors.length];
            var initials = getInitials(r.nama_lengkap);

            // Foto / Avatar
            var avatarHtml = '';
            if (r.foto) {
                avatarHtml = '<div class="ilmuna-avatar"><img src="../../' + escapeHtml(r.foto) + '" alt="' + escapeHtml(r.nama_lengkap) + '" loading="lazy"></div>';
            } else {
                avatarHtml = '<div class="ilmuna-avatar ilmuna-avatar--' + avColor + '">' + escapeHtml(initials) + '</div>';
            }

            // Jenis & Rombel Badge
            var typeBadge = '';
            if (r.source_type === 'guru') {
                typeBadge = '<span class="ilmuna-badge ilmuna-badge--navy"><iconify-icon icon="solar:user-speak-bold" width="13"></iconify-icon> Guru</span>';
            } else {
                typeBadge = '<span class="ilmuna-badge ilmuna-badge--teal"><iconify-icon icon="solar:backpack-bold" width="13"></iconify-icon> ' + escapeHtml(r.nama_kelas || 'Siswa') + '</span>';
            }

            // Identitas (NIS/NISN untuk Siswa, NIP/NUPTK untuk Guru)
            var idHtml = '';
            if (r.source_type === 'guru') {
                idHtml = '<div style="font-size:12px;"><strong>NIP:</strong> ' + escapeHtml(r.identifier_primary || '-') + '</div>' +
                         '<div style="font-size:11px; color:#64748B;"><strong>NUPTK:</strong> ' + escapeHtml(r.identifier_secondary || '-') + '</div>';
            } else {
                idHtml = '<div style="font-size:12px;"><strong>NIS:</strong> ' + escapeHtml(r.identifier_primary || '-') + '</div>' +
                         '<div style="font-size:11px; color:#64748B;"><strong>NISN:</strong> ' + escapeHtml(r.identifier_secondary || '-') + '</div>';
            }

            // Akun Portal & Status
            var accHtml = '';
            var statusBadge = '';
            var actionBtns = '';

            if (r.user_id) {
                // Sudah ada akun
                var rCodes = (r.role_codes || (r.source_type === 'guru' ? 'admin' : 'siswa')).split(',');
                var rolesHtml = rCodes.map(function(c) {
                    c = c.trim();
                    var bColor = (c === 'admin' ? 'navy' : (c === 'guru' ? 'navy' : (c === 'ketua_kelas' ? 'teal' : 'sky')));
                    var label = c === 'admin' ? 'Admin' : (c === 'ketua_kelas' ? 'Ketua Kelas' : (c === 'guru' ? 'Guru' : (c === 'siswa' ? 'Siswa' : c)));
                    return '<span class="ilmuna-badge ilmuna-badge--' + bColor + '" style="font-size:10px; padding:2px 6px; margin: 1px 2px 1px 0;">' + escapeHtml(label) + '</span>';
                }).join('');

                accHtml = '<div>' +
                    '<span class="ilmuna-code-pill">@' + escapeHtml(r.username) + '</span>' +
                    '<div style="margin-top:4px; display:flex; flex-wrap:wrap; align-items:center; gap:2px;">' + rolesHtml + '</div>' +
                '</div>';

                if (r.user_status === 'active') {
                    statusBadge = '<span class="ilmuna-badge ilmuna-badge--emerald"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> Aktif</span>';
                } else {
                    statusBadge = '<span class="ilmuna-badge ilmuna-badge--gray"><iconify-icon icon="solar:close-circle-bold"></iconify-icon> Nonaktif</span>';
                }

                // Tombol Hapus HANYA ditampilkan jika akun berstatus NONAKTIF (sembunyi/hide saat aktif)
                var deleteBtnHtml = '';
                if (r.user_status !== 'active') {
                    deleteBtnHtml = '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--del btn-delete-user" data-id="' + r.user_id + '" data-name="' + escapeHtml(r.nama_lengkap) + '" title="Hapus Akun Pengguna (Hanya Tersedia Saat Nonaktif)">' +
                        '<iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon>' +
                    '</button>';
                }

                actionBtns = '<div style="display: flex; gap: 5px; justify-content: center;">' +
                    '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--role btn-manage-role" data-id="' + r.user_id + '" data-name="' + escapeHtml(r.nama_lengkap) + '" data-username="' + escapeHtml(r.username) + '" data-type="' + r.source_type + '" title="Kelola / Tambah Role Pengguna">' +
                        '<iconify-icon icon="solar:shield-user-bold"></iconify-icon>' +
                    '</button>' +
                    '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--reset btn-reset-pw" data-id="' + r.user_id + '" data-name="' + escapeHtml(r.nama_lengkap) + '" title="Reset Password & Kirim ke Email">' +
                        '<iconify-icon icon="solar:key-minimalistic-square-bold"></iconify-icon>' +
                    '</button>' +
                    '<button type="button" class="ilmuna-btn-icon btn-toggle-status" data-id="' + r.user_id + '" title="' + (r.user_status === 'active' ? 'Nonaktifkan Akun' : 'Aktifkan Akun') + '">' +
                        '<iconify-icon icon="' + (r.user_status === 'active' ? 'solar:eye-bold' : 'solar:eye-closed-bold') + '"></iconify-icon>' +
                    '</button>' +
                    deleteBtnHtml +
                '</div>';

            } else {
                // Belum ada akun
                accHtml = '<span style="color:#94A3B8; font-size:12px; font-style:italic;">Belum Dibuatkan Akun</span>';
                statusBadge = '<span class="ilmuna-badge ilmuna-badge--amber"><iconify-icon icon="solar:shield-warning-bold"></iconify-icon> Belum Ada</span>';

                // 1-Button Generator Kilat!
                actionBtns = '<div style="display: flex; justify-content: center;">' +
                    '<button type="button" class="ilmuna-btn ilmuna-btn--gold ilmuna-btn--sm btn-generate-single" data-type="' + r.source_type + '" data-id="' + r.source_id + '" data-name="' + escapeHtml(r.nama_lengkap) + '" title="1-Klik Buat Akun Langsung">' +
                        '<iconify-icon icon="solar:bolt-circle-bold" width="15"></iconify-icon> Generate Akun' +
                    '</button>' +
                '</div>';
            }

            // Email & WhatsApp Link
            var emailPhoneHtml = '';
            if (r.email) {
                emailPhoneHtml += '<div style="font-size:12px; color:#475569;"><iconify-icon icon="solar:letter-bold" width="12"></iconify-icon> ' + escapeHtml(r.email) + '</div>';
            }
            if (r.no_hp) {
                var cleanPhone = r.no_hp.replace(/[^0-9]/g, '');
                if (cleanPhone.startsWith('0')) cleanPhone = '62' + cleanPhone.substr(1);
                emailPhoneHtml += '<div style="font-size:11.5px; margin-top:2px;">' +
                    '<a href="https://wa.me/' + cleanPhone + '" target="_blank" style="color:#0D7A87; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:3px;">' +
                    '<iconify-icon icon="solar:chat-round-dots-bold" width="13"></iconify-icon> ' + escapeHtml(r.no_hp) + '</a></div>';
            }

            html += '<tr class="' + (isChecked ? 'is-selected' : '') + '">' +
                '<td style="text-align: center;">' +
                    '<input type="checkbox" class="ilmuna-bulk-cb row-cb" value="' + r.source_type + '_' + r.source_id + '" ' + (isChecked ? 'checked' : '') + '>' +
                '</td>' +
                '<td style="text-align: center; color: #64748B; font-weight: 700;">' + rowNum + '</td>' +
                '<td style="text-align: center;">' + avatarHtml + '</td>' +
                '<td>' +
                    '<div class="ilmuna-user-meta">' +
                        '<strong>' + escapeHtml(r.nama_lengkap) + '</strong>' +
                        emailPhoneHtml +
                    '</div>' +
                '</td>' +
                '<td>' + typeBadge + '</td>' +
                '<td>' + idHtml + '</td>' +
                '<td>' + accHtml + '</td>' +
                '<td style="text-align: center;">' + statusBadge + '</td>' +
                '<td style="text-align: center;">' + actionBtns + '</td>' +
            '</tr>';
        });

        tbody.innerHTML = html;
        bindRowEvents();
    }

    function bindRowEvents() {
        var tbody = document.getElementById('tableUsersBody');

        // Checkbox row
        tbody.querySelectorAll('.row-cb').forEach(function(cb) {
            cb.addEventListener('change', function() {
                var tr = cb.closest('tr');
                if (cb.checked) {
                    selectedUsers.add(cb.value);
                    if (tr) tr.classList.add('is-selected');
                } else {
                    selectedUsers.delete(cb.value);
                    if (tr) tr.classList.remove('is-selected');
                }
                updateBulkBar();
            });
        });

        // 1-Klik Generate Akun Satuan
        tbody.querySelectorAll('.btn-generate-single').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var sType = btn.dataset.type;
                var sId   = btn.dataset.id;
                var sName = btn.dataset.name;

                confirmDialog('Generate akun portal otomatis untuk "' + sName + '"? Kredensial (Username & Password) akan dibuatkan sesuai rumus resmi dan dikirim via Gmail jika tersedia.', {
                    title: 'Generate Akun Portal',
                    type: 'gold',
                    okText: 'Generate Sekarang'
                }).then(function(confirmed) {
                    if (!confirmed) return;

                    btn.disabled = true;
                    btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="15"></iconify-icon> Memproses...';

                    var fd = new FormData();
                    fd.append('action', 'generate_single');
                    fd.append('source_type', sType);
                    fd.append('source_id', sId);
                    fd.append('send_email', 1);

                    fetch(AJAX_URL, { method: 'POST', body: fd })
                        .then(function(r){ return r.json(); })
                        .then(function(res) {
                            btn.disabled = false;
                            btn.innerHTML = '<iconify-icon icon="solar:bolt-circle-bold" width="15"></iconify-icon> Generate Akun';

                            if (!res || !res.success) {
                                toast('error', (res && res.data && res.data.message) || 'Gagal generate akun.');
                                return;
                            }

                            // Tampilkan modal hasil
                            document.getElementById('resFullName').textContent = sName;
                            document.getElementById('resUsername').textContent = res.data.username;
                            document.getElementById('resPassword').textContent = res.data.password;
                            document.getElementById('resMailNotice').textContent = res.data.email_status ? res.data.email_status.message : '';
                            openModal('modalResultSingle');

                            toast('success', 'Generate akun berhasil.');
                            fetchList();
                        })
                        .catch(function() {
                            btn.disabled = false;
                            btn.innerHTML = '<iconify-icon icon="solar:bolt-circle-bold" width="15"></iconify-icon> Generate Akun';
                            toast('error', 'Koneksi bermasalah.');
                        });
                });
            });
        });

        // Reset Password
        tbody.querySelectorAll('.btn-reset-pw').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var uid = btn.dataset.id;
                var sName = btn.dataset.name;

                confirmDialog('Reset password untuk akun "' + sName + '"? Password baru akan dibuatkan dan dikirimkan ke email terdaftar.', {
                    title: 'Reset Password',
                    type: 'teal',
                    okText: 'Reset Password'
                }).then(function(confirmed) {
                    if (!confirmed) return;

                    var fd = new FormData();
                    fd.append('action', 'reset_password');
                    fd.append('user_id', uid);
                    fd.append('send_email', 1);

                    fetch(AJAX_URL, { method: 'POST', body: fd })
                        .then(function(r){ return r.json(); })
                        .then(function(res) {
                            if (!res || !res.success) {
                                toast('error', (res && res.data && res.data.message) || 'Gagal reset password.');
                                return;
                            }
                            toast('success', 'Password berhasil direset.');
                        })
                        .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
                });
            });
        });

        // Toggle Aktif/Nonaktif
        tbody.querySelectorAll('.btn-toggle-status').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var uid = btn.dataset.id;
                var fd = new FormData();
                fd.append('action', 'toggle_status');
                fd.append('user_id', uid);

                fetch(AJAX_URL, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        if (!res || !res.success) {
                            toast('error', (res && res.data && res.data.message) || 'Gagal ubah status.');
                            return;
                        }
                        toast('success', res.data.message);
                        fetchList();
                    })
                    .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
            });
        });

        // Kelola Role Pengguna
        tbody.querySelectorAll('.btn-manage-role').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var uid = btn.dataset.id;
                var sName = btn.dataset.name;
                var uName = btn.dataset.username;
                var sType = btn.dataset.type;

                openRoleModal(uid, sName, uName, sType);
            });
        });

        // Hapus Pengguna (Wajib Nonaktif Terlebih Dahulu)
        tbody.querySelectorAll('.btn-delete-user').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var uid = btn.dataset.id;
                var sName = btn.dataset.name;

                confirmDialog('Apakah Anda yakin ingin menghapus akun nonaktif "' + sName + '"? Akses login dan penugasan peran akun ini akan dihapus secara permanen.', {
                    title: 'Hapus Akun Pengguna',
                    type: 'danger',
                    okText: 'Hapus Sekarang'
                }).then(function(confirmed) {
                    if (!confirmed) return;

                    var fd = new FormData();
                    fd.append('action', 'delete_user');
                    fd.append('user_id', uid);

                    fetch(AJAX_URL, { method: 'POST', body: fd })
                        .then(function(r){ return r.json(); })
                        .then(function(res) {
                            if (!res || !res.success) {
                                toast('error', (res && res.data && res.data.message) || 'Gagal menghapus user.');
                                return;
                            }
                            toast('success', res.data.message);
                            fetchList();
                        })
                        .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
                });
            });
        });
    }

    /* 6. BULK ACTIONS BAR */
    document.getElementById('checkAllUsers').addEventListener('change', function(e) {
        var checked = e.target.checked;
        document.querySelectorAll('#tableUsersBody .row-cb').forEach(function(cb) {
            cb.checked = checked;
            var tr = cb.closest('tr');
            if (checked) {
                selectedUsers.add(cb.value);
                if (tr) tr.classList.add('is-selected');
            } else {
                selectedUsers.delete(cb.value);
                if (tr) tr.classList.remove('is-selected');
            }
        });
        updateBulkBar();
    });

    function updateBulkBar() {
        var bar = document.getElementById('bulkActionBar');
        var cnt = document.getElementById('bulkSelectedCount');
        cnt.textContent = selectedUsers.size;
        if (selectedUsers.size > 0) bar.classList.add('is-open');
        else bar.classList.remove('is-open');
    }

    document.getElementById('btnBulkCancel').addEventListener('click', function() {
        selectedUsers.clear();
        document.getElementById('checkAllUsers').checked = false;
        document.querySelectorAll('#tableUsersBody .row-cb').forEach(function(cb) {
            cb.checked = false;
            var tr = cb.closest('tr');
            if (tr) tr.classList.remove('is-selected');
        });
        updateBulkBar();
    });

    document.getElementById('btnBulkGenerate').addEventListener('click', function() {
        if (selectedUsers.size === 0) return;
        confirmDialog('Generate akun untuk ' + selectedUsers.size + ' entitas terpilih? Rumus username & password resmi akan diterapkan otomatis.', {
            title: 'Generate Akun Terpilih',
            type: 'teal',
            okText: 'Generate Sekarang'
        }).then(function(confirmed) {
            if (!confirmed) return;
            // Buka modal autogen dengan setup otomatis
            openModal('modalAutogen');
            document.getElementById('autogenTargetType').value = 'all';
            document.getElementById('btnStartAutogen').click();
        });
    });

    /* 7. PAGINATION */
    function renderPagination(total, page, per, pages) {
        var info = document.getElementById('paginationInfo');
        var btns = document.getElementById('paginationBtns');

        if (total === 0) {
            info.textContent = 'Menampilkan 0 data';
            btns.innerHTML   = '';
            return;
        }

        var start = (page - 1) * per + 1;
        var end   = Math.min(total, page * per);
        info.textContent = 'Menampilkan ' + start + ' – ' + end + ' dari total ' + total + ' pengguna';

        var bHtml = '';
        bHtml += '<button type="button" class="ilmuna-page-btn" data-page="' + (page - 1) + '" ' + (page <= 1 ? 'disabled' : '') + '><iconify-icon icon="solar:alt-arrow-left-bold"></iconify-icon></button>';

        for (var p = 1; p <= pages; p++) {
            if (p === 1 || p === pages || (p >= page - 2 && p <= page + 2)) {
                bHtml += '<button type="button" class="ilmuna-page-btn ' + (p === page ? 'is-active' : '') + '" data-page="' + p + '">' + p + '</button>';
            } else if (p === page - 3 || p === page + 3) {
                bHtml += '<span style="padding: 0 4px; color:#94A3B8;">…</span>';
            }
        }

        bHtml += '<button type="button" class="ilmuna-page-btn" data-page="' + (page + 1) + '" ' + (page >= pages ? 'disabled' : '') + '><iconify-icon icon="solar:alt-arrow-right-bold"></iconify-icon></button>';
        btns.innerHTML = bHtml;

        btns.querySelectorAll('.ilmuna-page-btn').forEach(function(b) {
            b.addEventListener('click', function() {
                var targetP = parseInt(b.dataset.page, 10);
                if (targetP && targetP !== state.page && targetP >= 1 && targetP <= state.pages) {
                    state.page = targetP;
                    fetchList();
                }
            });
        });
    }

    /* 8. FILTER PILL SWITCHERS & REALTIME SEARCH */
    document.querySelectorAll('#filterTypePills .ilmuna-pill-btn').forEach(function(b) {
        b.addEventListener('click', function() {
            document.querySelectorAll('#filterTypePills .ilmuna-pill-btn').forEach(function(x){ x.classList.remove('is-active'); });
            b.classList.add('is-active');
            state.type = b.dataset.type;
            state.page = 1;
            fetchList();
        });
    });

    document.querySelectorAll('#filterStatusPills .ilmuna-pill-btn').forEach(function(b) {
        b.addEventListener('click', function() {
            document.querySelectorAll('#filterStatusPills .ilmuna-pill-btn').forEach(function(x){ x.classList.remove('is-active'); });
            b.classList.add('is-active');
            state.status = b.dataset.status;
            state.page = 1;
            fetchList();
        });
    });

    document.getElementById('filterKelas').addEventListener('change', function(e) {
        state.id_kelas = parseInt(e.target.value, 10) || 0;
        state.page = 1;
        fetchList();
    });

    document.getElementById('filterPerPage').addEventListener('change', function(e) {
        state.per_page = parseInt(e.target.value, 10) || 20;
        state.page = 1;
        fetchList();
    });

    // Realtime Search (Tiap ketik 1 huruf langsung filter)
    document.getElementById('filterSearch').addEventListener('input', function(e) {
        clearTimeout(searchTimer);
        var q = e.target.value.trim();
        searchTimer = setTimeout(function() {
            state.search = q;
            state.page = 1;
            fetchList();
        }, 250);
    });

    /* 9. WIZARD GENERATE MASSAL (AUTOGEN ENGINE DENGAN BATCHING) */
    var btnOpenAutogen = document.getElementById('btnOpenAutogenModal');
    if (btnOpenAutogen) {
        btnOpenAutogen.addEventListener('click', openAutogenModal);
    }

    var autogenRunning = false;
    var autogenTimerInterval = null;
    var autogenStartTime = 0;
    var autogenTotalCreated = 0;
    var autogenTotalFailed = 0;
    var autogenTotalMails = 0;

    document.getElementById('btnStartAutogen').addEventListener('click', function() {
        if (autogenRunning) return;

        var targetType = document.getElementById('autogenTargetType').value;
        var batchSize  = document.getElementById('autogenBatchSize').value;
        var sendEmail  = document.getElementById('autogenSendEmail').checked ? 1 : 0;
        var onlyEmail  = document.getElementById('autogenOnlyWithEmail').checked ? 1 : 0;

        document.getElementById('autogenSetupBox').style.display = 'none';
        document.getElementById('autogenProgressView').style.display = 'block';
        document.getElementById('btnStartAutogen').style.display = 'none';
        document.getElementById('btnCancelAutogen').textContent = 'Hentikan / Tutup';

        var logBox = document.getElementById('autogenLogBox');
        logBox.innerHTML = '<div class="log-info">> Memulai proses pembuatan akun batch...</div>';

        autogenRunning = true;
        autogenStartTime = Date.now();
        autogenTotalCreated = 0;
        autogenTotalFailed  = 0;
        autogenTotalMails   = 0;

        clearInterval(autogenTimerInterval);
        autogenTimerInterval = setInterval(updateAutogenTimer, 1000);

        runAutogenStep(targetType, batchSize, sendEmail, onlyEmail);
    });

    function updateAutogenTimer() {
        var elapsedSec = Math.floor((Date.now() - autogenStartTime) / 1000);
        var m = String(Math.floor(elapsedSec / 60)).padStart(2, '0');
        var s = String(elapsedSec % 60).padStart(2, '0');
        document.getElementById('autogenTimer').textContent = m + ':' + s;
    }

    function runAutogenStep(targetType, batchSize, sendEmail, onlyEmail) {
        if (!autogenRunning) return;

        var fd = new FormData();
        fd.append('action', 'autogen_batch');
        fd.append('target_type', targetType);
        fd.append('batch_size', batchSize);
        fd.append('send_email', sendEmail);
        fd.append('only_with_email', onlyEmail);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (!res || !res.success) {
                    appendAutogenLog('danger', '> Gagal batch: ' + ((res && res.data && res.data.message) || 'Koneksi error'));
                    stopAutogen();
                    return;
                }

                var d = res.data;
                autogenTotalCreated += d.created;
                autogenTotalFailed  += d.failed;
                autogenTotalMails   += d.email_sent;

                document.getElementById('autogenStatCreated').textContent   = 'Berhasil: ' + autogenTotalCreated;
                document.getElementById('autogenStatFailed').textContent    = 'Gagal: ' + autogenTotalFailed;
                document.getElementById('autogenStatMails').textContent     = 'Email Terkirim: ' + autogenTotalMails;
                document.getElementById('autogenStatRemaining').textContent = 'Sisa: ' + d.remaining;

                // Tampilkan Log
                if (d.items && d.items.length > 0) {
                    d.items.forEach(function(it) {
                        if (it.status === 'ok') {
                            appendAutogenLog('success', '✓ [' + it.tipe + '] ' + it.nama + ' -> @' + it.username + (it.mail_sent ? ' (Email Terkirim)' : ''));
                        } else {
                            appendAutogenLog('danger', '✗ ' + it.nama + ': ' + it.error);
                        }
                    });
                }

                if (d.done || d.remaining === 0) {
                    appendAutogenLog('info', '🎉 Seluruh akun selesai diproses secara menyeluruh!');
                    document.getElementById('autogenProgressBar').style.width = '100%';
                    document.getElementById('autogenProgressLabel').textContent = 'Proses Selesai!';
                    stopAutogen();
                    fetchList();
                    toast('success', 'Generate akun massal berhasil.');
                } else {
                    // Update progress bar estimasi
                    document.getElementById('autogenProgressLabel').textContent = 'Sedang memproses... (Sisa: ' + d.remaining + ')';
                    document.getElementById('autogenProgressBar').style.width = '65%';
                    // Lanjut batch berikutnya
                    setTimeout(function(){
                        runAutogenStep(targetType, batchSize, sendEmail, onlyEmail);
                    }, 400);
                }
            })
            .catch(function(err) {
                appendAutogenLog('danger', '> Error koneksi ke server.');
                stopAutogen();
            });
    }

    function appendAutogenLog(type, text) {
        var box = document.getElementById('autogenLogBox');
        var line = document.createElement('div');
        line.className = 'log-' + type;
        line.textContent = text;
        box.appendChild(line);
        box.scrollTop = box.scrollHeight;
    }

    function stopAutogen() {
        autogenRunning = false;
        clearInterval(autogenTimerInterval);
        document.getElementById('btnCancelAutogen').textContent = 'Tutup';
    }

    /* 10. PENGATURAN GMAIL & SMTP (REALTIME MODAL) */
    var btnOpenSmtp = document.getElementById('btnOpenSmtpSettings');
    if (btnOpenSmtp) {
        btnOpenSmtp.addEventListener('click', openSmtpSettingsModal);
    }

    document.getElementById('btnSaveSmtp').addEventListener('click', function() {
        var form = document.getElementById('formSmtpSettings');
        var fd = new FormData(form);
        fd.append('action', 'save_smtp_settings');

        var btn = document.getElementById('btnSaveSmtp');
        btn.disabled = true;

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                btn.disabled = false;
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal menyimpan konfigurasi.');
                    return;
                }
                toast('success', res.data.message);
                closeModal('modalSmtpSettings');
            })
            .catch(function() {
                btn.disabled = false;
                toast('error', 'Koneksi bermasalah.');
            });
    });

    document.getElementById('btnTestSmtp').addEventListener('click', function() {
        var email = document.getElementById('testEmailTarget').value.trim();
        if (!email) {
            toast('warning', 'Tuliskan alamat email penerima uji coba.');
            return;
        }

        var btn = document.getElementById('btnTestSmtp');
        btn.disabled = true;
        btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="16"></iconify-icon> Mengirim...';

        var fd = new FormData();
        fd.append('action', 'test_smtp');
        fd.append('test_email', email);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                btn.disabled = false;
                btn.innerHTML = '<iconify-icon icon="solar:plain-bold" width="16"></iconify-icon> Kirim Uji Coba';
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Uji coba gagal.');
                    return;
                }
                toast('success', res.data.message);
            })
            .catch(function() {
                btn.disabled = false;
                btn.innerHTML = '<iconify-icon icon="solar:plain-bold" width="16"></iconify-icon> Kirim Uji Coba';
                toast('error', 'Koneksi bermasalah.');
            });
    });

    // Copy Kredensial di Modal Hasil
    document.getElementById('btnCopyUsername').addEventListener('click', function() {
        var u = document.getElementById('resUsername').textContent;
        navigator.clipboard.writeText(u);
        toast('info', 'Username @' + u + ' disalin.');
    });
    document.getElementById('btnCopyPassword').addEventListener('click', function() {
        var p = document.getElementById('resPassword').textContent;
        navigator.clipboard.writeText(p);
        toast('info', 'Password disalin ke clipboard.');
    });

    /* 10. MODAL KELOLA ROLE PENGGUNA (ROLE MANAGER & ADD ROLE) */
    function openRoleModal(uid, sName, uName, sType) {
        document.getElementById('roleModalUserId').value = uid;
        document.getElementById('roleModalUserName').textContent = sName;
        document.getElementById('roleModalUserHandle').textContent = '@' + uName;

        var isStudent = (sType === 'siswa');
        var typeBadge = document.getElementById('roleModalUserType');
        var siswaInfo = document.getElementById('roleModalSiswaInfo');
        var newRoleSec = document.getElementById('roleModalNewRoleSection');
        var helpText = document.getElementById('roleModalHelpText');

        if (isStudent) {
            typeBadge.className = 'ilmuna-badge ilmuna-badge--teal';
            typeBadge.textContent = 'Siswa';
            if (siswaInfo) siswaInfo.style.display = 'block';
            if (newRoleSec) newRoleSec.style.display = 'none';
            if (helpText) helpText.textContent = 'Pilih apakah siswa ini juga ditugaskan sebagai Ketua Kelas.';
        } else {
            typeBadge.className = 'ilmuna-badge ilmuna-badge--navy';
            typeBadge.textContent = 'Dewan Guru';
            if (siswaInfo) siswaInfo.style.display = 'none';
            if (newRoleSec) newRoleSec.style.display = 'block';
            if (helpText) helpText.textContent = 'Centang role yang ingin diberikan kepada akun dewan guru ini.';
        }

        var listEl = document.getElementById('roleCheckboxList');
        listEl.innerHTML = '<div style="text-align: center; padding: 20px; color: #94A3B8;"><iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="20"></iconify-icon> Memuat daftar role...</div>';

        // Reset form tambah role baru
        document.getElementById('boxAddNewRole').style.display = 'none';
        var iconToggle = document.getElementById('iconToggleNewRole');
        if (iconToggle) iconToggle.setAttribute('icon', 'solar:alt-arrow-down-bold');
        document.getElementById('newRoleCode').value = '';
        document.getElementById('newRoleName').value = '';
        document.getElementById('newRoleDesc').value = '';

        openModal('modalManageRoles');
        loadUserRoles(uid, sType);
    }

    function loadUserRoles(uid, sType) {
        var fd = new FormData();
        fd.append('action', 'get_user_roles');
        fd.append('user_id', uid);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                var listEl = document.getElementById('roleCheckboxList');
                if (!res || !res.success || !res.data || !res.data.all_roles) {
                    listEl.innerHTML = '<div style="color:#EF4444; padding:10px;">Gagal memuat daftar role dari server.</div>';
                    return;
                }

                var roles = res.data.all_roles;
                var assigned = (res.data.assigned_roles || []).map(function(x){ return parseInt(x, 10); });
                var primaryId = parseInt(res.data.primary_role_id || 0, 10);
                var isStudent = (sType === 'siswa' || res.data.is_siswa);

                // Siswa HANYA boleh role 'siswa' dan 'ketua_kelas'
                if (isStudent) {
                    roles = roles.filter(function(r) {
                        return r.code === 'siswa' || r.code === 'ketua_kelas';
                    });
                }

                var html = '';
                roles.forEach(function(role) {
                    var rId = parseInt(role.id, 10);
                    var isChecked = assigned.indexOf(rId) !== -1;
                    var isPrimary = rId === primaryId;
                    var bColor = role.badge_color || (role.code === 'admin' ? 'navy' : (role.code === 'ketua_kelas' ? 'teal' : 'sky'));

                    // Untuk siswa: role 'siswa' adalah role dasar yang selalu aktif
                    var isRequiredSiswa = (isStudent && role.code === 'siswa');
                    if (isRequiredSiswa) isChecked = true;

                    var cbAttr = isChecked ? 'checked' : '';
                    var cbDisabled = isRequiredSiswa ? 'disabled' : '';

                    html += '<div class="ilmuna-role-item ' + (isChecked ? 'is-selected' : '') + '" data-role-id="' + rId + '">' +
                        '<div style="display: flex; align-items: center; gap: 10px; flex: 1;">' +
                            '<input type="checkbox" class="role-cb" value="' + rId + '" ' + cbAttr + ' ' + cbDisabled + ' style="width: 17px; height: 17px; accent-color: #0D7A87; cursor: ' + (isRequiredSiswa ? 'default' : 'pointer') + ';" title="' + (isRequiredSiswa ? 'Role dasar siswa wajib aktif' : 'Tugaskan role ini') + '">' +
                            '<div>' +
                                '<div style="display: flex; align-items: center; gap: 6px;">' +
                                    '<span class="ilmuna-badge ilmuna-badge--' + bColor + '" style="font-size: 11px;">' + escapeHtml(role.name) + '</span>' +
                                    '<code style="font-size: 11px; color: #64748B;">(' + escapeHtml(role.code) + ')</code>' +
                                    (isRequiredSiswa ? '<span style="font-size:10.5px; color:#0D7A87; font-weight:600;">(Role Dasar)</span>' : '') +
                                '</div>' +
                                '<div style="font-size: 11.5px; color: #64748B; margin-top: 2px;">' + escapeHtml(role.description || 'Hak akses untuk peran ini.') + '</div>' +
                            '</div>' +
                        '</div>' +
                        '<div style="display: flex; align-items: center; gap: 6px;" title="Tandai sebagai Role Utama (Primary)">' +
                            '<label style="display: flex; align-items: center; gap: 4px; font-size: 11.5px; cursor: pointer; color: ' + (isPrimary ? '#0D7A87' : '#94A3B8') + '; font-weight: ' + (isPrimary ? '700' : '500') + ';">' +
                                '<input type="radio" name="primary_role_rb" value="' + rId + '" ' + (isPrimary ? 'checked' : '') + ' style="accent-color: #0D7A87;">' +
                                '<span>Utama</span>' +
                            '</label>' +
                        '</div>' +
                    '</div>';
                });

                listEl.innerHTML = html;

                // Event listener interaktif checkbox
                listEl.querySelectorAll('.ilmuna-role-item').forEach(function(item) {
                    var cb = item.querySelector('.role-cb');
                    var rb = item.querySelector('input[type="radio"]');

                    cb.addEventListener('change', function() {
                        if (cb.checked) {
                            item.classList.add('is-selected');
                            var checkedRb = listEl.querySelector('input[name="primary_role_rb"]:checked');
                            if (!checkedRb) rb.checked = true;
                        } else {
                            item.classList.remove('is-selected');
                            if (rb.checked) {
                                rb.checked = false;
                                var otherCb = listEl.querySelector('.role-cb:checked');
                                if (otherCb) {
                                    var otherItem = otherCb.closest('.ilmuna-role-item');
                                    if (otherItem) otherItem.querySelector('input[type="radio"]').checked = true;
                                }
                            }
                        }
                    });
                });
            })
            .catch(function() {
                document.getElementById('roleCheckboxList').innerHTML = '<div style="color:#EF4444; padding:10px;">Koneksi bermasalah saat memuat role.</div>';
            });
    }

    // Toggle Box Tambah Role Baru
    var btnToggleNewRole = document.getElementById('btnToggleNewRoleForm');
    if (btnToggleNewRole) {
        btnToggleNewRole.addEventListener('click', function() {
            var box = document.getElementById('boxAddNewRole');
            var isHidden = box.style.display === 'none';
            box.style.display = isHidden ? 'block' : 'none';
            var icon = document.getElementById('iconToggleNewRole');
            if (icon) icon.setAttribute('icon', isHidden ? 'solar:alt-arrow-up-bold' : 'solar:alt-arrow-down-bold');
        });
    }

    // Submit Tambah Role Baru
    var btnSubmitNewRole = document.getElementById('btnSubmitNewRole');
    if (btnSubmitNewRole) {
        btnSubmitNewRole.addEventListener('click', function() {
            var code = document.getElementById('newRoleCode').value.trim();
            var name = document.getElementById('newRoleName').value.trim();
            var desc = document.getElementById('newRoleDesc').value.trim();

            if (!code || !name) {
                toast('warning', 'Kode role dan nama role wajib diisi.');
                return;
            }

            btnSubmitNewRole.disabled = true;
            btnSubmitNewRole.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="15"></iconify-icon> Menambahkan...';

            var fd = new FormData();
            fd.append('action', 'add_new_role');
            fd.append('code', code);
            fd.append('name', name);
            fd.append('description', desc);

            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    btnSubmitNewRole.disabled = false;
                    btnSubmitNewRole.innerHTML = '<iconify-icon icon="solar:check-circle-bold" width="15"></iconify-icon> Simpan Role Baru';

                    if (!res || !res.success) {
                        toast('error', (res && res.data && res.data.message) || 'Gagal menambah role baru.');
                        return;
                    }
                    toast('success', res.data.message);
                    document.getElementById('newRoleCode').value = '';
                    document.getElementById('newRoleName').value = '';
                    document.getElementById('newRoleDesc').value = '';
                    document.getElementById('boxAddNewRole').style.display = 'none';
                    var iconToggle = document.getElementById('iconToggleNewRole');
                    if (iconToggle) iconToggle.setAttribute('icon', 'solar:alt-arrow-down-bold');

                    var uid = document.getElementById('roleModalUserId').value;
                    loadUserRoles(uid);
                })
                .catch(function() {
                    btnSubmitNewRole.disabled = false;
                    btnSubmitNewRole.innerHTML = '<iconify-icon icon="solar:check-circle-bold" width="15"></iconify-icon> Simpan Role Baru';
                    toast('error', 'Koneksi bermasalah.');
                });
        });
    }

    // Submit Simpan Role Pengguna
    var btnSaveUserRoles = document.getElementById('btnSaveUserRoles');
    if (btnSaveUserRoles) {
        btnSaveUserRoles.addEventListener('click', function() {
            var uid = document.getElementById('roleModalUserId').value;
            var checkedCbs = document.querySelectorAll('#roleCheckboxList .role-cb:checked');
            if (checkedCbs.length === 0) {
                toast('warning', 'Pilih minimal satu role untuk akun ini.');
                return;
            }

            var roleIds = [];
            checkedCbs.forEach(function(cb) { roleIds.push(cb.value); });

            var primaryRb = document.querySelector('#roleCheckboxList input[name="primary_role_rb"]:checked');
            var primaryId = primaryRb ? primaryRb.value : roleIds[0];

            btnSaveUserRoles.disabled = true;
            btnSaveUserRoles.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="16"></iconify-icon> Menyimpan...';

            var fd = new FormData();
            fd.append('action', 'save_user_roles');
            fd.append('user_id', uid);
            roleIds.forEach(function(rId) { fd.append('role_ids[]', rId); });
            fd.append('primary_role_id', primaryId);

            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    btnSaveUserRoles.disabled = false;
                    btnSaveUserRoles.innerHTML = '<iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon> Simpan Perubahan Role';

                    if (!res || !res.success) {
                        toast('error', (res && res.data && res.data.message) || 'Gagal menyimpan role.');
                        return;
                    }
                    closeModal('modalManageRoles');
                    toast('success', res.data.message);
                    fetchList();
                })
                .catch(function() {
                    btnSaveUserRoles.disabled = false;
                    btnSaveUserRoles.innerHTML = '<iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon> Simpan Perubahan Role';
                    toast('error', 'Koneksi bermasalah.');
                });
        });
    }

    /* Helpers */
    function escapeHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function getInitials(name) {
        if (!name) return 'U';
        var p = name.trim().split(/\s+/);
        var ini = p[0] ? p[0].charAt(0) : 'U';
        if (p[1]) ini += p[1].charAt(0);
        return ini.toUpperCase();
    }

    // Initialize
    loadKelasOptions();
    fetchList();

})();
</script>

<?php
require_once BASE_PATH . '/includes/footer.php';
?>