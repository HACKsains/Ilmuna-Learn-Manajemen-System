<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — STANDALONE EMAIL DEBUG & DIAGNOSTIC PANEL
 * ==============================================================================
 * Akses Langsung: https://learn.ilmuna.my.id/email-debug.php
 * Bebas redirect loop, mandiri, responsif, dan real-time
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/dashboard/admin/users-email-template.php';
require_once __DIR__ . '/includes/email-verify-google-template.php';
require_once __DIR__ . '/includes/email-reset-password-template.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDBConnection();
ilmuna_ensure_google_verification_table($pdo);
ilmuna_ensure_password_resets_table($pdo);

// AJAX Handlers
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

        $serverName = $_SERVER['SERVER_NAME'] ?? 'learn.ilmuna.my.id';
        $ehlo = $send("EHLO $serverName");

        if ($secure === 'tls') {
            $starttls = $send("STARTTLS");
            if (substr($starttls, 0, 3) === '220') {
                $log("Memulai negosiasi kriptografi TLS...", 'info');
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

                $cryptoOk = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
                if ($cryptoOk) {
                    $log("Enkripsi TLS aktif!", 'success');
                    $send("EHLO $serverName");
                } else {
                    $log("Negosiasi TLS ditolak oleh OpenSSL/Socket.", 'error');
                }
            } else {
                $log("Server SMTP menolak perintah STARTTLS: $starttls", 'error');
            }
        }

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
        $method  = $_POST['method'] ?? 'auto';

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Alamat email tidak valid: ' . $toEmail]);
            exit;
        }

        $cfg = ilmuna_smtp_config();
        $subject = 'Uji Coba Pengiriman Ilmuna Learn (' . strtoupper($method) . ') — ' . date('d M Y H:i:s');
        $bodyHtml = "<p>Halo,</p><p>Ini adalah tes diagnostik pengiriman email dari server portal <strong>learn.ilmuna.my.id</strong>.</p><p>Metode: <strong>{$method}</strong><br>Waktu: <strong>" . date('Y-m-d H:i:s') . "</strong></p>";

        $res = null;
        if ($method === 'mail') {
            $ok = ilmuna_php_mail_fallback($toEmail, $subject, $bodyHtml, $cfg['from'], $cfg['from_name']);
            $res = [
                'success' => $ok,
                'message' => $ok ? 'Email berhasil dikirim via native PHP mail() server!' : 'PHP mail() mengembalikan false.'
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
            'message' => $saved ? 'Konfigurasi SMTP berhasil disimpan ke config/smtp_config.json!' : 'Gagal menyimpan konfigurasi.'
        ]);
        exit;
    }
}

$smtpCfg = ilmuna_smtp_config();

// Daftar verifikasi Google terbaru
$verifList = [];
try {
    $vStmt = $pdo->query("SELECT v.*, u.username, u.full_name 
                          FROM google_link_verifications v 
                          LEFT JOIN users u ON v.user_id = u.id 
                          ORDER BY v.id DESC LIMIT 20");
    $verifList = $vStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Log email
$logFile = BASE_PATH . '/uploads/email_logs.json';
$emailLogs = [];
if (file_exists($logFile)) {
    $raw = @file_get_contents($logFile);
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $emailLogs = array_reverse($decoded);
            $emailLogs = array_slice($emailLogs, 0, 30);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Debug & Diagnostik Email — Ilmuna Learn</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <script src="https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js"></script>
    <style>
        body { background-color: #F8FAFC; font-family: 'Plus Jakarta Sans', sans-serif; color: #0F1D2F; padding: 24px 16px; }
        .card { border-radius: 20px; border: 1px solid #E2E8F0; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
        .font-mono { font-family: 'JetBrains Mono', Consolas, monospace; }
        .brand-badge { background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%); color: #FFF; padding: 8px 16px; border-radius: 12px; font-weight: 700; }
    </style>
</head>
<body>

<div class="container-fluid" style="max-width: 1240px;">
    
    <!-- TOP HEADER -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <span class="brand-badge fs-5">Ilmuna Learn</span>
            <div>
                <h4 class="fw-bold mb-0">Panel Debug & Diagnostik Email</h4>
                <p class="text-muted small mb-0">Portal Diagnostik Mandiri Transmisi Socket, Google SSO & Pemulihan Kata Sandi</p>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>/profile" class="btn btn-outline-secondary btn-sm">
                <iconify-icon icon="solar:user-bold" class="me-1"></iconify-icon> Buka Profil Saya
            </a>
            <a href="<?= BASE_URL ?>/gateway.php" class="btn btn-outline-primary btn-sm">
                <iconify-icon icon="solar:widget-bold" class="me-1"></iconify-icon> Gateway Portal
            </a>
        </div>
    </div>

    <!-- TABEL UTAMA: ANTREAN PENAUTAN GOOGLE & TOMBOL VERIFIKASI LANGSUNG -->
    <div class="card mb-4 bg-white">
        <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <iconify-icon icon="logos:google-icon" class="fs-4"></iconify-icon>
                    <div>
                        <h5 class="fw-bold mb-0">Antrean Verifikasi Penautan Akun Google</h5>
                        <small class="text-muted">Klik tombol "Buka & Selesaikan Verifikasi" jika email verifikasi di Gmail belum masuk</small>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="location.reload()">
                    <iconify-icon icon="solar:restart-bold" class="me-1"></iconify-icon> Segarkan Antrean
                </button>
            </div>
        </div>
        <div class="card-body p-4 pt-2">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Waktu Dibuat</th>
                            <th>Pengguna</th>
                            <th>Gmail Ditautkan</th>
                            <th>Masa Berlaku</th>
                            <th>Status Verifikasi</th>
                            <th class="text-end">Aksi Instan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($verifList)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">Belum ada permintaan penautan Google di database. Buka Profil lalu klik "Tautkan Akun Google".</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($verifList as $v): 
                                $isVerified = !empty($v['verified_at']);
                                $isExpired  = strtotime($v['expires_at']) < time();
                                $vUrl = BASE_URL . '/verify-google.php?token=' . $v['token'];
                            ?>
                                <tr class="<?= (!$isVerified && !$isExpired) ? 'table-warning bg-opacity-25' : '' ?>">
                                    <td class="small text-muted"><?= htmlspecialchars($v['created_at']) ?></td>
                                    <td>
                                        <span class="fw-bold"><?= htmlspecialchars($v['full_name'] ?: ($v['username'] ?: 'ID #' . $v['user_id'])) ?></span>
                                        <span class="small text-muted d-block font-mono">User ID: #<?= (int)$v['user_id'] ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border font-mono fs-6"><?= htmlspecialchars($v['email_google']) ?></span>
                                    </td>
                                    <td class="small"><?= htmlspecialchars($v['expires_at']) ?></td>
                                    <td>
                                        <?php if ($isVerified): ?>
                                            <span class="badge bg-success">✓ Terverifikasi (<?= htmlspecialchars($v['verified_at']) ?>)</span>
                                        <?php elseif ($isExpired): ?>
                                            <span class="badge bg-danger">Kedaluwarsa</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark fw-bold">Menunggu Verifikasi</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="copyLink('<?= htmlspecialchars($vUrl) ?>')">
                                                <iconify-icon icon="solar:copy-bold" class="me-1"></iconify-icon> Salin Link
                                            </button>
                                            <?php if (!$isVerified && !$isExpired): ?>
                                                <a href="<?= htmlspecialchars($vUrl) ?>" target="_blank" class="btn btn-sm btn-success fw-bold">
                                                    <iconify-icon icon="solar:check-circle-bold" class="me-1"></iconify-icon> Buka & Selesaikan Verifikasi
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

    <!-- BARIS KEDUA: TERMINAL RAW SOCKET & KONFIGURASI SMTP -->
    <div class="row g-4 mb-4">
        
        <!-- FORM TES PENGIRIMAN & TERMINAL -->
        <div class="col-lg-6 col-12">
            <div class="card h-100 bg-white">
                <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                    <h5 class="fw-bold mb-0">Uji Coba Pengiriman & Konsol Trace</h5>
                    <small class="text-muted">Tes transmisi socket SMTP atau native mail secara langsung</small>
                </div>
                <div class="card-body p-4">
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <input type="email" id="test_target_email" class="form-control" value="syahriel.andika28@gmail.com" placeholder="Email tujuan">
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
                            <button type="button" class="btn btn-success w-100 fw-bold" id="btnSendTest" onclick="sendTestEmail()">
                                <iconify-icon icon="solar:plain-bold" class="me-1"></iconify-icon> Kirim Email Uji Coba Sekarang
                            </button>
                        </div>
                    </div>

                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <small class="fw-bold text-muted text-uppercase">Log Terminal Socket:</small>
                        <button type="button" class="btn btn-sm btn-link text-muted p-0 text-decoration-none" onclick="clearTerminal()">Bersihkan</button>
                    </div>

                    <div id="debugTerminal" class="font-mono" style="background:#0F172A; color:#E2E8F0; font-size:12.5px; height:240px; overflow-y:auto; padding:14px; border-radius:12px; border:1px solid #1E293B;">
                        <div class="text-secondary">[<?= date('H:i:s') ?>] Terminal siap. Tekan tombol "Uji Handshake" atau "Kirim Email Uji Coba" untuk melihat transmisi mentah.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- FORM KONFIGURASI AKTIF -->
        <div class="col-lg-6 col-12">
            <div class="card h-100 bg-white">
                <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                    <div class="d-flex align-items-center justify-content-between">
                        <h5 class="fw-bold mb-0">Konfigurasi SMTP Server</h5>
                        <span class="badge <?= $smtpCfg['is_enabled'] ? 'bg-success' : 'bg-secondary' ?>">
                            <?= $smtpCfg['is_enabled'] ? 'Aktif' : 'Nonaktif' ?>
                        </span>
                    </div>
                    <small class="text-muted">Tersimpan di config/smtp_config.json</small>
                </div>
                <div class="card-body p-4">
                    <form id="formSmtpConfig" onsubmit="handleSaveConfig(event)">
                        <div class="row g-2">
                            <div class="col-8">
                                <label class="form-label small fw-semibold">Host SMTP</label>
                                <input type="text" name="host" id="cfg_host" class="form-control form-control-sm" value="<?= htmlspecialchars($smtpCfg['host']) ?>" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label small fw-semibold">Port</label>
                                <input type="number" name="port" id="cfg_port" class="form-control form-control-sm" value="<?= (int)$smtpCfg['port'] ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Enkripsi</label>
                                <select name="secure" id="cfg_secure" class="form-select form-select-sm">
                                    <option value="tls" <?= $smtpCfg['secure'] === 'tls' ? 'selected' : '' ?>>TLS (587)</option>
                                    <option value="ssl" <?= $smtpCfg['secure'] === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
                                    <option value="none" <?= $smtpCfg['secure'] === 'none' ? 'selected' : '' ?>>None (25)</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Status Pengiriman</label>
                                <select name="is_enabled" id="cfg_enabled" class="form-select form-select-sm">
                                    <option value="1" <?= $smtpCfg['is_enabled'] ? 'selected' : '' ?>>Aktif (1)</option>
                                    <option value="0" <?= !$smtpCfg['is_enabled'] ? 'selected' : '' ?>>Nonaktif (0)</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Email Pengirim (Username)</label>
                                <input type="email" name="user" id="cfg_user" class="form-control form-control-sm" value="<?= htmlspecialchars($smtpCfg['user']) ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Sandi / App Password Google (16 Karakter)</label>
                                <input type="text" name="pass" id="cfg_pass" class="form-control form-control-sm font-mono" value="<?= htmlspecialchars($smtpCfg['pass']) ?>" required>
                            </div>
                            <div class="col-12 d-flex gap-2 justify-content-end mt-3">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="runSocketTest()">
                                    <iconify-icon icon="solar:round-transfer-vertical-bold" class="me-1"></iconify-icon> Uji Handshake Socket
                                </button>
                                <button type="submit" class="btn btn-primary btn-sm fw-bold" id="btnSaveConfig">
                                    <iconify-icon icon="solar:diskette-bold" class="me-1"></iconify-icon> Simpan Perubahan
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>

</div>

<script>
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
        const res = await fetch('<?= BASE_URL ?>/email-debug.php', { method: 'POST', body: formData });
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
    btn.innerHTML = 'Mengirim...';
    appendLog(`[Mengirim email tes ke ${toEmail} dengan metode ${method}...]`);

    const formData = new FormData();
    formData.append('action', 'send_test_email');
    formData.append('to_email', toEmail);
    formData.append('method', method);

    try {
        const res = await fetch('<?= BASE_URL ?>/email-debug.php', { method: 'POST', body: formData });
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
        btn.innerHTML = '<iconify-icon icon="solar:plain-bold" class="me-1"></iconify-icon> Kirim Email Uji Coba Sekarang';
    }
}

async function handleSaveConfig(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveConfig');
    btn.disabled = true;

    const formData = new FormData(document.getElementById('formSmtpConfig'));
    formData.append('action', 'save_config');

    try {
        const res = await fetch('<?= BASE_URL ?>/email-debug.php', { method: 'POST', body: formData });
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
        alert('Tautan berhasil disalin:\n' + url);
    }).catch(() => {
        prompt('Salin tautan ini secara manual:', url);
    });
}
</script>

</body>
</html>
