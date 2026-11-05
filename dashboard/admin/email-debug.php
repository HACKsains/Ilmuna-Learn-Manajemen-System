<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PANEL DIAGNOSTIK & DEBUG EMAIL SISTEM
 * ==============================================================================
 * Lokasi: dashboard/admin/email-debug.php
 * Akses: Administrator portal atau URL dengan parameter kunci rahasia
 * Fitur:
 * 1. Inspeksi status sistem & kapabilitas socket jaringan server hosting
 * 2. Tes konektivitas port keluar (Port 587 STARTTLS vs Port 465 SSL vs Native mail)
 * 3. Terminal interaktif real-time trace pengiriman email
 * 4. Daftar token verifikasi penautan Google aktif & generator tautan instan
 * 5. Log riwayat pengiriman email server
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/users-email-template.php';
require_once __DIR__ . '/../../includes/email-verify-google-template.php';
require_once __DIR__ . '/../../includes/email-reset-password-template.php';

// Hak Akses: Login pengguna portal atau query key darurat
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$canAccess = isset($_SESSION['user_id']) 
          || isset($_SESSION['user'])
          || (isset($_GET['key']) && $_GET['key'] === 'ilmuna_debug_2026');

if (!$canAccess) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

$pdo = getDBConnection();
ilmuna_ensure_google_verification_table($pdo);
ilmuna_ensure_password_resets_table($pdo);

// ==============================================================================
// AJAX HANDLER: TEST SOCKET & EMAIL REAL-TIME
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    if ($action === 'test_connection') {
        $host   = trim($_POST['host'] ?? 'smtp.gmail.com');
        $port   = (int)($_POST['port'] ?? 587);
        $secure = strtolower(trim($_POST['secure'] ?? 'tls'));
        $user   = trim($_POST['user'] ?? '');
        $pass   = trim(str_replace(' ', '', $_POST['pass'] ?? ''));

        $logs = [];
        $log = function($msg, $type = 'info') use (&$logs) {
            $logs[] = ['time' => date('H:i:s'), 'type' => $type, 'text' => $msg];
        };

        $timeout = 10;
        $targetHost = ($secure === 'ssl') ? ('ssl://' . $host) : ('tcp://' . $host);

        $log("Membuka koneksi socket ke $targetHost:$port (timeout: {$timeout}s)...");

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ]
        ]);

        $errno  = 0;
        $errstr = '';
        $startT = microtime(true);
        $socket = @stream_socket_client($targetHost . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            $log("Gagal terhubung ke $targetHost:$port. Error: $errstr ($errno)", 'error');
            echo json_encode(['success' => false, 'logs' => $logs]);
            exit;
        }

        $connTime = round((microtime(true) - $startT) * 1000, 1);
        $log("Koneksi TCP berhasil terbentuk dalam {$connTime}ms!", 'success');
        stream_set_timeout($socket, $timeout);

        $read = function() use ($socket) {
            $d = '';
            while (!feof($socket)) {
                $l = fgets($socket, 1024);
                $d .= $l;
                if (substr($l, 3, 1) === ' ' || empty($l)) break;
            }
            return trim($d);
        };

        $send = function($cmd) use ($socket, $read, $log) {
            $log(">> $cmd", 'cmd');
            fputs($socket, $cmd . "\r\n");
            $resp = $read();
            $log("<< " . ($resp ?: '[Respons Kosong]'), 'resp');
            return $resp;
        };

        $greeting = $read();
        $log("<< Banner: " . ($greeting ?: '[Tidak ada respons banner awal]'), substr($greeting, 0, 3) === '220' ? 'success' : 'error');

        if (substr($greeting, 0, 3) !== '220') {
            fclose($socket);
            echo json_encode(['success' => false, 'logs' => $logs]);
            exit;
        }

        // Send EHLO
        $serverName = $_SERVER['SERVER_NAME'] ?? 'learn.ilmuna.my.id';
        $ehlo = $send("EHLO $serverName");

        // STARTTLS jika port 587 / tls
        if ($secure === 'tls') {
            $starttls = $send("STARTTLS");
            if (substr($starttls, 0, 3) === '220') {
                $log("Memulai negosiasi kriptografi TLS...", 'info');
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

                $cryptoOk = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
                if ($cryptoOk) {
                    $log("Enkripsi TLS v1.2/v1.3 aktif dan diamankan!", 'success');
                    $send("EHLO $serverName");
                } else {
                    $log("Negosiasi TLS ditolak oleh OpenSSL/Socket.", 'error');
                }
            } else {
                $log("Server SMTP menolak perintah STARTTLS.", 'error');
            }
        }

        // AUTH LOGIN test
        if (!empty($user) && !empty($pass)) {
            $auth = $send("AUTH LOGIN");
            if (substr($auth, 0, 3) === '334') {
                $uRes = $send(base64_encode($user));
                if (substr($uRes, 0, 3) === '334') {
                    $pRes = $send(base64_encode($pass));
                    if (substr($pRes, 0, 3) === '235') {
                        $log("AUTENTIKASI BERHASIL! (235 Accepted)", 'success');
                    } else {
                        $log("Autentikasi ditolak: $pRes", 'error');
                    }
                } else {
                    $log("Username ditolak: $uRes", 'error');
                }
            }
        }

        $send("QUIT");
        fclose($socket);

        echo json_encode(['success' => true, 'logs' => $logs]);
        exit;
    }

    if ($action === 'send_test_email') {
        $toEmail = trim($_POST['to_email'] ?? '');
        $method  = $_POST['method'] ?? 'auto'; // 'auto' | '587' | '465' | 'mail'

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Alamat email tujuan tidak valid: ' . $toEmail]);
            exit;
        }

        $cfg = ilmuna_smtp_config();
        $subject = 'Uji Coba Pengiriman Ilmuna Learn (' . strtoupper($method) . ') — ' . date('d M Y H:i:s');
        $bodyHtml = <<<HTML
        <div style="font-family:sans-serif; max-width:550px; margin:0 auto; padding:24px; border:1px solid #E2E8F0; border-radius:16px;">
            <div style="background:#1D3B5E; padding:18px; border-radius:12px; text-align:center; color:#FFF;">
                <h2 style="margin:0;">Ilmuna Learn</h2>
                <p style="margin:4px 0 0; font-size:13px; color:#52B2DC;">Tes Diagnostik Email Sistem</p>
            </div>
            <div style="padding:20px 4px;">
                <p>Halo,</p>
                <p>Ini adalah pesan uji coba diagnostik yang dikirimkan langsung dari server portal <strong>learn.ilmuna.my.id</strong>.</p>
                <table style="width:100%; border-collapse:collapse; margin:14px 0; font-size:13px;">
                    <tr><td style="padding:6px; border:1px solid #CBD5E1; font-weight:bold;">Metode</td><td style="padding:6px; border:1px solid #CBD5E1;">{$method}</td></tr>
                    <tr><td style="padding:6px; border:1px solid #CBD5E1; font-weight:bold;">Waktu Server</td><td style="padding:6px; border:1px solid #CBD5E1;">{$_SERVER['REQUEST_TIME']} / {$date}</td></tr>
                    <tr><td style="padding:6px; border:1px solid #CBD5E1; font-weight:bold;">IP Server Hosting</td><td style="padding:6px; border:1px solid #CBD5E1;">{$_SERVER['SERVER_ADDR']}</td></tr>
                </table>
                <p style="font-size:12px; color:#64748B;">Jika Anda menerima email ini, berarti sistem pengiriman email ke alamat tujuan Anda sudah bekerja sempurna.</p>
            </div>
        </div>
HTML;

        $res = null;

        if ($method === 'mail') {
            $ok = ilmuna_php_mail_fallback($toEmail, $subject, $bodyHtml, $cfg['from'], $cfg['from_name']);
            $res = [
                'success' => $ok,
                'message' => $ok ? 'Email berhasil dikirimkan via fungsi native PHP mail() server!' : 'PHP mail() mengembalikan nilai false.'
            ];
        } elseif ($method === '587') {
            $params = [
                'host'      => $cfg['host'],
                'port'      => 587,
                'secure'    => 'tls',
                'user'      => $cfg['user'],
                'pass'      => $cfg['pass'],
                'from'      => $cfg['from'],
                'from_name' => $cfg['from_name'],
                'to'        => $toEmail,
                'subject'   => $subject,
                'body'      => $bodyHtml
            ];
            $res = _ilmuna_single_socket_smtp($params);
        } elseif ($method === '465') {
            $params = [
                'host'      => $cfg['host'],
                'port'      => 465,
                'secure'    => 'ssl',
                'user'      => $cfg['user'],
                'pass'      => $cfg['pass'],
                'from'      => $cfg['from'],
                'from_name' => $cfg['from_name'],
                'to'        => $toEmail,
                'subject'   => $subject,
                'body'      => $bodyHtml
            ];
            $res = _ilmuna_single_socket_smtp($params);
        } else {
            // Auto fallback
            $res = ilmuna_raw_smtp_send([
                'host'      => $cfg['host'],
                'port'      => (int)$cfg['port'],
                'secure'    => $cfg['secure'],
                'user'      => $cfg['user'],
                'pass'      => $cfg['pass'],
                'from'      => $cfg['from'],
                'from_name' => $cfg['from_name'],
                'to'        => $toEmail,
                'subject'   => $subject,
                'body'      => $bodyHtml
            ]);
        }

        echo json_encode($res);
        exit;
    }

    if ($action === 'save_config') {
        $newCfg = [
            'host'       => trim($_POST['host'] ?? 'smtp.gmail.com'),
            'port'       => (int)($_POST['port'] ?? 587),
            'secure'     => trim($_POST['secure'] ?? 'tls'),
            'user'       => trim($_POST['user'] ?? ''),
            'pass'       => trim($_POST['pass'] ?? ''),
            'from'       => trim($_POST['from'] ?? ''),
            'from_name'  => trim($_POST['from_name'] ?? 'Ilmuna Learn'),
            'is_enabled' => !empty($_POST['is_enabled']) ? 1 : 0
        ];

        $saved = ilmuna_save_smtp_config($newCfg);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        echo json_encode([
            'success' => $saved,
            'message' => $saved ? 'Konfigurasi SMTP berhasil disimpan ke config/smtp_config.json!' : 'Gagal menyimpan file konfigurasi.'
        ]);
        exit;
    }
}

// Ambil data konfigurasi aktif
$smtpCfg = ilmuna_smtp_config();

// Ambil riwayat log pengiriman email
$logFile = BASE_PATH . '/uploads/email_logs.json';
$emailLogs = [];
if (file_exists($logFile)) {
    $raw = @file_get_contents($logFile);
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $emailLogs = array_reverse($decoded); // terbaru di atas
            $emailLogs = array_slice($emailLogs, 0, 30);
        }
    }
}

// Ambil daftar antrean verifikasi Google aktif
$verifList = [];
try {
    $vStmt = $pdo->query("SELECT v.*, u.username, u.full_name 
                          FROM google_link_verifications v 
                          LEFT JOIN users u ON v.user_id = u.id 
                          ORDER BY v.id DESC LIMIT 15");
    $verifList = $vStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Ambil daftar antrean reset password aktif
$resetList = [];
try {
    $rStmt = $pdo->query("SELECT r.*, u.username, u.full_name 
                          FROM password_resets r 
                          LEFT JOIN users u ON r.user_id = u.id 
                          ORDER BY r.id DESC LIMIT 15");
    $resetList = $rStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$pageTitle = 'Debug & Diagnostik Email';
$pageIcon  = 'solar:shield-check-bold';

require_once BASE_PATH . '/includes/header.php';
?>

<div class="row g-4">
    <!-- BARIS 1: STATUS SISTEM & SERVER HOSTING -->
    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="p-3 rounded-4 bg-primary bg-opacity-10 text-primary">
                            <iconify-icon icon="solar:server-square-bold" width="28"></iconify-icon>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-0">Status Sistem & Kapabilitas Jaringan Hosting</h5>
                            <p class="text-muted small mb-0">Informasi lingkungan server cPanel dan modul transmisi socket</p>
                        </div>
                    </div>
                    <span class="badge bg-success-subtle text-success px-3 py-2 rounded-pill">
                        <iconify-icon icon="solar:check-circle-bold" class="me-1"></iconify-icon> Server Online
                    </span>
                </div>

                <div class="row g-3">
                    <div class="col-md-3 col-sm-6">
                        <div class="p-3 rounded-3 bg-light border">
                            <small class="text-muted d-block mb-1">PHP Version</small>
                            <span class="fw-bold fs-6 text-dark"><?= phpversion() ?></span>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="p-3 rounded-3 bg-light border">
                            <small class="text-muted d-block mb-1">IP Server Hosting</small>
                            <span class="fw-bold fs-6 text-dark"><?= $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname()) ?></span>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="p-3 rounded-3 bg-light border">
                            <small class="text-muted d-block mb-1">Ekstensi OpenSSL</small>
                            <span class="fw-bold fs-6 <?= extension_loaded('openssl') ? 'text-success' : 'text-danger' ?>">
                                <?= extension_loaded('openssl') ? 'Aktif (OPENSSL_VERSION: ' . OPENSSL_VERSION_TEXT . ')' : 'Tidak Aktif' ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="p-3 rounded-3 bg-light border">
                            <small class="text-muted d-block mb-1">Fungsi Native mail()</small>
                            <span class="fw-bold fs-6 <?= function_exists('mail') ? 'text-success' : 'text-danger' ?>">
                                <?= function_exists('mail') ? 'Tersedia' : 'Dinonaktifkan di php.ini' ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- BARIS 2: KONFIGURASI SMTP AKTIF & FORM TES KONEKSI -->
    <div class="col-lg-6 col-12">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <iconify-icon icon="solar:settings-bold" class="text-primary fs-4"></iconify-icon>
                        <h6 class="fw-bold mb-0">Konfigurasi SMTP Server</h6>
                    </div>
                    <span class="badge <?= $smtpCfg['is_enabled'] ? 'bg-success' : 'bg-secondary' ?>">
                        <?= $smtpCfg['is_enabled'] ? 'SMTP Aktif' : 'SMTP Nonaktif' ?>
                    </span>
                </div>
            </div>
            <div class="card-body p-4">
                <form id="formSmtpConfig" onsubmit="handleSaveConfig(event)">
                    <div class="row g-3">
                        <div class="col-8">
                            <label class="form-label small fw-semibold">SMTP Host</label>
                            <input type="text" name="host" id="cfg_host" class="form-control" value="<?= htmlspecialchars($smtpCfg['host']) ?>" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label small fw-semibold">Port</label>
                            <input type="number" name="port" id="cfg_port" class="form-control" value="<?= (int)$smtpCfg['port'] ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Enkripsi</label>
                            <select name="secure" id="cfg_secure" class="form-select">
                                <option value="tls" <?= $smtpCfg['secure'] === 'tls' ? 'selected' : '' ?>>TLS (Port 587)</option>
                                <option value="ssl" <?= $smtpCfg['secure'] === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                                <option value="none" <?= $smtpCfg['secure'] === 'none' ? 'selected' : '' ?>>Tanpa Enkripsi (Port 25)</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Status Pengiriman</label>
                            <select name="is_enabled" id="cfg_enabled" class="form-select">
                                <option value="1" <?= $smtpCfg['is_enabled'] ? 'selected' : '' ?>>Diaktifkan (1)</option>
                                <option value="0" <?= !$smtpCfg['is_enabled'] ? 'selected' : '' ?>>Dinonaktifkan (0)</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Email Pengirim (Username SMTP)</label>
                            <input type="email" name="user" id="cfg_user" class="form-control" value="<?= htmlspecialchars($smtpCfg['user']) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Sandi / App Password Google (16 Huruf)</label>
                            <div class="input-group">
                                <input type="password" name="pass" id="cfg_pass" class="form-control font-monospace" value="<?= htmlspecialchars($smtpCfg['pass']) ?>" required>
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePassVisibility('cfg_pass')">
                                    <iconify-icon icon="solar:eye-bold" id="cfg_pass_icon"></iconify-icon>
                                </button>
                            </div>
                            <small class="text-muted">App password tanpa spasi (contoh: <code>culgyngfrzpudkkr</code>)</small>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Nama Pengirim</label>
                            <input type="text" name="from_name" id="cfg_from_name" class="form-control" value="<?= htmlspecialchars($smtpCfg['from_name']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Alamat Balasan (From/Reply-To)</label>
                            <input type="email" name="from" id="cfg_from" class="form-control" value="<?= htmlspecialchars($smtpCfg['from']) ?>">
                        </div>
                        <div class="col-12 d-flex gap-2 justify-content-end mt-4">
                            <button type="button" class="btn btn-outline-primary" onclick="runSocketTest()">
                                <iconify-icon icon="solar:round-transfer-vertical-bold" class="me-1"></iconify-icon> Uji Handshake
                            </button>
                            <button type="submit" class="btn btn-primary" id="btnSaveConfig">
                                <iconify-icon icon="solar:diskette-bold" class="me-1"></iconify-icon> Simpan Perubahan
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- TERMINAL RAW TRACE & TES PENGIRIMAN LANGSUNG -->
    <div class="col-lg-6 col-12">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <iconify-icon icon="solar:plain-bold" class="text-success fs-4"></iconify-icon>
                        <h6 class="fw-bold mb-0">Uji Coba Pengiriman Langsung</h6>
                    </div>
                    <span class="badge bg-primary-subtle text-primary">Terminal Trace</span>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row g-2 mb-3">
                    <div class="col-7">
                        <input type="email" id="test_target_email" class="form-control" placeholder="Email tujuan (contoh: syahriel.andika28@gmail.com)" value="syahriel.andika28@gmail.com">
                    </div>
                    <div class="col-5">
                        <select id="test_method" class="form-select">
                            <option value="auto">Auto (Smart Fallback)</option>
                            <option value="587">Paksa Port 587 (TLS)</option>
                            <option value="465">Paksa Port 465 (SSL)</option>
                            <option value="mail">Paksa PHP mail()</option>
                        </select>
                    </div>
                    <div class="col-12 mt-2">
                        <button type="button" class="btn btn-success w-100" id="btnSendTest" onclick="sendTestEmail()">
                            <iconify-icon icon="solar:letter-unread-bold" class="me-1"></iconify-icon> Kirim Email Uji Coba Sekarang
                        </button>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between mb-2">
                    <small class="fw-bold text-muted text-uppercase">Log Konsol Handshake:</small>
                    <button type="button" class="btn btn-sm btn-link text-muted p-0 text-decoration-none" onclick="clearTerminal()">Bersihkan</button>
                </div>

                <div id="debugTerminal" style="background:#0F172A; color:#E2E8F0; font-family:'JetBrains Mono', Consolas, monospace; font-size:12.5px; height:240px; overflow-y:auto; padding:14px; border-radius:12px; border:1px solid #1E293B;">
                    <div class="text-secondary">[<?= date('H:i:s') ?>] Terminal siap. Klik tombol "Uji Handshake" atau "Kirim Email Uji Coba" untuk melihat transmisi socket mentah.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- BARIS 3: ANTREAN PENAUTAN GOOGLE AKTIF & TAUTAN MANUAL INSTAN -->
    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <iconify-icon icon="logos:google-icon" class="fs-4"></iconify-icon>
                        <div>
                            <h6 class="fw-bold mb-0">Antrean Verifikasi Penautan Akun Google</h6>
                            <small class="text-muted">Jika email terlambat masuk, Anda dapat mengklik atau menyalin tautan verifikasi langsung dari tabel ini</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body p-4 pt-2">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Waktu Permintaan</th>
                                <th>Pengguna</th>
                                <th>Gmail Ditautkan</th>
                                <th>Kedaluwarsa</th>
                                <th>Status</th>
                                <th class="text-end">Aksi Instan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($verifList)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">Belum ada permintaan verifikasi Google yang tercatat di database.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($verifList as $v): 
                                    $isVerified = !empty($v['verified_at']);
                                    $isExpired  = strtotime($v['expires_at']) < time();
                                    $vUrl = BASE_URL . '/verify-google.php?token=' . $v['token'];
                                ?>
                                    <tr>
                                        <td class="small text-muted"><?= htmlspecialchars($v['created_at']) ?></td>
                                        <td>
                                            <span class="fw-bold"><?= htmlspecialchars($v['full_name'] ?: ($v['username'] ?: 'User #' . $v['user_id'])) ?></span>
                                            <span class="small text-muted d-block">ID: #<?= (int)$v['user_id'] ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border font-monospace"><?= htmlspecialchars($v['email_google']) ?></span>
                                        </td>
                                        <td class="small"><?= htmlspecialchars($v['expires_at']) ?></td>
                                        <td>
                                            <?php if ($isVerified): ?>
                                                <span class="badge bg-success-subtle text-success">✓ Terverifikasi (<?= htmlspecialchars($v['verified_at']) ?>)</span>
                                            <?php elseif ($isExpired): ?>
                                                <span class="badge bg-danger-subtle text-danger">Kedaluwarsa</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning-subtle text-warning">Menunggu Klik Pengguna</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="copyLink('<?= htmlspecialchars($vUrl) ?>')">
                                                    <iconify-icon icon="solar:copy-bold" class="me-1"></iconify-icon> Salin Link
                                                </button>
                                                <?php if (!$isVerified && !$isExpired): ?>
                                                    <a href="<?= htmlspecialchars($vUrl) ?>" target="_blank" class="btn btn-sm btn-primary">
                                                        <iconify-icon icon="solar:check-circle-bold" class="me-1"></iconify-icon> Buka & Verifikasi
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- BARIS 4: LOG RIWAYAT PENGIRIMAN EMAIL TERBARU -->
    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <iconify-icon icon="solar:history-bold" class="text-primary fs-4"></iconify-icon>
                        <h6 class="fw-bold mb-0">Riwayat Log Transmisi Email Sistem (uploads/email_logs.json)</h6>
                    </div>
                </div>
            </div>
            <div class="card-body p-4 pt-2">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Waktu</th>
                                <th>Tujuan</th>
                                <th>Penerima</th>
                                <th>Status</th>
                                <th>Keterangan / Respons SMTP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($emailLogs)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">Belum ada riwayat email yang tersimpan.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($emailLogs as $logItem): ?>
                                    <tr>
                                        <td class="small text-muted"><?= htmlspecialchars($logItem['timestamp'] ?? '-') ?></td>
                                        <td class="font-monospace small"><?= htmlspecialchars($logItem['to'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($logItem['nama'] ?? ($logItem['username'] ?? '-')) ?></td>
                                        <td>
                                            <?php if (!empty($logItem['success'])): ?>
                                                <span class="badge bg-success-subtle text-success">Sukses Terkirim</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger">Gagal</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted font-monospace"><?= htmlspecialchars($logItem['detail'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassVisibility(id) {
    const el = document.getElementById(id);
    const icon = document.getElementById(id + '_icon');
    if (el.type === 'password') {
        el.type = 'text';
        icon.setAttribute('icon', 'solar:eye-closed-bold');
    } else {
        el.type = 'password';
        icon.setAttribute('icon', 'solar:eye-bold');
    }
}

function clearTerminal() {
    document.getElementById('debugTerminal').innerHTML = '';
}

function appendLog(text, type = 'info') {
    const term = document.getElementById('debugTerminal');
    const row = document.createElement('div');
    row.style.marginBottom = '4px';

    let color = '#94A3B8';
    if (type === 'cmd') color = '#38BDF8';
    if (type === 'resp') color = '#FDE047';
    if (type === 'success') color = '#4ADE80';
    if (type === 'error') color = '#F87171';

    row.style.color = color;
    row.textContent = text;
    term.appendChild(row);
    term.scrollTop = term.scrollHeight;
}

async function runSocketTest() {
    clearTerminal();
    appendLog('[Memulai Uji Handshake Socket SMTP]');

    const formData = new FormData();
    formData.append('action', 'test_connection');
    formData.append('host', document.getElementById('cfg_host').value);
    formData.append('port', document.getElementById('cfg_port').value);
    formData.append('secure', document.getElementById('cfg_secure').value);
    formData.append('user', document.getElementById('cfg_user').value);
    formData.append('pass', document.getElementById('cfg_pass').value);

    try {
        const res = await fetch('<?= BASE_URL ?>/dashboard/admin/email-debug.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.logs && data.logs.length) {
            data.logs.forEach(l => appendLog(`[${l.time}] ${l.text}`, l.type));
        }
    } catch (err) {
        appendLog('Gagal menjalankan pengujian: ' + err.message, 'error');
    }
}

async function sendTestEmail() {
    const toEmail = document.getElementById('test_target_email').value;
    const method  = document.getElementById('test_method').value;
    const btn     = document.getElementById('btnSendTest');

    if (!toEmail) {
        alert('Harap masukkan alamat email tujuan');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="spin-icon me-1"></iconify-icon> Mengirim...';
    appendLog(`[Mengirim email tes ke ${toEmail} dengan metode ${method}...]`);

    const formData = new FormData();
    formData.append('action', 'send_test_email');
    formData.append('to_email', toEmail);
    formData.append('method', method);

    try {
        const res = await fetch('<?= BASE_URL ?>/dashboard/admin/email-debug.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            appendLog(`[SUKSES] ${data.message}`, 'success');
            alert('Sukses: ' + data.message);
        } else {
            appendLog(`[GAGAL] ${data.message}`, 'error');
            alert('Gagal: ' + data.message);
        }
    } catch (err) {
        appendLog('Kesalahan: ' + err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<iconify-icon icon="solar:letter-unread-bold" class="me-1"></iconify-icon> Kirim Email Uji Coba Sekarang';
    }
}

async function handleSaveConfig(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveConfig');
    btn.disabled = true;

    const formData = new FormData(document.getElementById('formSmtpConfig'));
    formData.append('action', 'save_config');

    try {
        const res = await fetch('<?= BASE_URL ?>/dashboard/admin/email-debug.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    } catch (err) {
        alert('Gagal menyimpan: ' + err.message);
    } finally {
        btn.disabled = false;
    }
}

function copyLink(url) {
    navigator.clipboard.writeText(url).then(() => {
        alert('Tautan berhasil disalin ke clipboard:\n' + url);
    }).catch(() => {
        prompt('Salin tautan ini secara manual:', url);
    });
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

