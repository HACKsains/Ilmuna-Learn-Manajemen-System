<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PANEL DEBUG & DIAGNOSTIK EMAIL (ADMIN)
 * ==============================================================================
 * Diagnostik transmisi Socket SMTP, Google Apps Script Relay, dan Verifikasi
 * Penautan Akun Google secara real-time (Zero Reload, Lumina Gold Standard).
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/users-email-template.php';
require_once __DIR__ . '/../../includes/email-verify-google-template.php';
require_once __DIR__ . '/../../includes/email-reset-password-template.php';

requireRole('admin');

$pdo = getDBConnection();
ilmuna_ensure_google_verification_table($pdo);
ilmuna_ensure_password_resets_table($pdo);

// AJAX Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    if ($action === 'get_verifications') {
        $rows = [];
        try {
            $vStmt = $pdo->query("SELECT v.*, u.username, u.full_name
                                  FROM google_link_verifications v
                                  LEFT JOIN users u ON v.user_id = u.id
                                  ORDER BY v.id DESC LIMIT 20");
            $now = time();
            foreach ($vStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
                $isVerified = !empty($v['verified_at']);
                $isExpired  = strtotime($v['expires_at']) < $now;
                $rows[] = [
                    'id'           => (int)$v['id'],
                    'user_id'      => (int)$v['user_id'],
                    'display_name' => $v['full_name'] ?: ($v['username'] ?: 'ID #' . $v['user_id']),
                    'email_google' => $v['email_google'],
                    'created_at'   => $v['created_at'],
                    'expires_at'   => $v['expires_at'],
                    'verified_at'  => $v['verified_at'],
                    'status'       => $isVerified ? 'verified' : ($isExpired ? 'expired' : 'pending'),
                    'verify_url'   => BASE_URL . '/verify-google.php?token=' . $v['token']
                ];
            }
        } catch (Exception $e) {}

        echo json_encode(['success' => true, 'rows' => $rows]);
        exit;
    }

    if ($action === 'test_connection') {
        $host   = trim($_POST['host'] ?? 'smtp.gmail.com');
        $port   = (int)($_POST['port'] ?? 465);
        $secure = strtolower(trim($_POST['secure'] ?? 'ssl'));
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
        $send("EHLO $serverName");

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

        $fromEmail = !empty($cfg['from']) ? $cfg['from'] : (!empty($cfg['user']) ? $cfg['user'] : 'support.ilmuna@gmail.com');
        $fromName  = !empty($cfg['from_name']) ? $cfg['from_name'] : 'Ilmuna System';

        if ($method === 'relay') {
            $relayUrl = ilmuna_get_google_relay_url();
            if (empty($relayUrl)) {
                $res = ['success' => false, 'message' => 'URL Google Apps Script Relay belum dikonfigurasi.'];
            } else {
                $res = ilmuna_send_via_google_relay($relayUrl, [
                    'to'        => $toEmail,
                    'subject'   => $subject,
                    'body'      => $bodyHtml,
                    'from_name' => $fromName
                ]);
            }
        } elseif ($method === 'mail') {
            $ok = ilmuna_php_mail_fallback($toEmail, $subject, $bodyHtml, $fromEmail, $fromName);
            $res = [
                'success' => $ok,
                'message' => $ok ? 'Email berhasil dikirim via native PHP mail() server!' : 'PHP mail() mengembalikan false.'
            ];
        } elseif ($method === '587') {
            $params = [
                'host'      => $cfg['host'] ?: 'mail.ilmuna.my.id',
                'port'      => 587,
                'secure'    => 'tls',
                'user'      => $cfg['user'] ?: 'noreply@ilmuna.my.id',
                'pass'      => $cfg['pass'] ?: 'Ilmuna28@',
                'from'      => $fromEmail,
                'from_name' => $fromName,
                'to'        => $toEmail,
                'subject'   => $subject,
                'body'      => $bodyHtml
            ];
            $res = _ilmuna_single_socket_smtp($params);
        } elseif ($method === '465') {
            $params = [
                'host'      => $cfg['host'] ?: 'mail.ilmuna.my.id',
                'port'      => 465,
                'secure'    => 'ssl',
                'user'      => $cfg['user'] ?: 'noreply@ilmuna.my.id',
                'pass'      => $cfg['pass'] ?: 'Ilmuna28@',
                'from'      => $fromEmail,
                'from_name' => $fromName,
                'to'        => $toEmail,
                'subject'   => $subject,
                'body'      => $bodyHtml
            ];
            $res = _ilmuna_single_socket_smtp($params);
        } else {
            $res = ilmuna_raw_smtp_send([
                'host'      => $cfg['host'] ?: 'mail.ilmuna.my.id',
                'port'      => (int)($cfg['port'] ?: 465),
                'secure'    => $cfg['secure'] ?: 'ssl',
                'user'      => $cfg['user'] ?: 'noreply@ilmuna.my.id',
                'pass'      => $cfg['pass'] ?: 'Ilmuna28@',
                'from'      => $fromEmail,
                'from_name' => $fromName,
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
            'google_relay_url' => trim($_POST['google_relay_url'] ?? ''),
            'host'             => trim($_POST['host'] ?? 'smtp.gmail.com'),
            'port'             => (int)($_POST['port'] ?? 587),
            'secure'           => trim($_POST['secure'] ?? 'tls'),
            'user'             => trim($_POST['user'] ?? ''),
            'pass'             => trim($_POST['pass'] ?? ''),
            'from'             => trim($_POST['from'] ?? ''),
            'from_name'        => trim($_POST['from_name'] ?? 'Ilmuna Learn'),
            'is_enabled'       => !empty($_POST['is_enabled']) ? 1 : 0
        ];

        $saved = ilmuna_save_smtp_config($newCfg);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        echo json_encode([
            'success'    => $saved,
            'is_enabled' => $newCfg['is_enabled'],
            'message'    => $saved ? 'Konfigurasi SMTP berhasil disimpan ke config/smtp_config.json!' : 'Gagal menyimpan konfigurasi.'
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

$pendingVerifCount = 0;
foreach ($verifList as $vr) {
    if (empty($vr['verified_at']) && strtotime($vr['expires_at']) >= time()) {
        $pendingVerifCount++;
    }
}

$pageTitle = 'Diagnostik Email & SMTP';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
    .edbg-terminal {
        background: #0F172A;
        color: #E2E8F0;
        font-family: var(--font-mono);
        font-size: 12.5px;
        height: 250px;
        overflow-y: auto;
        padding: 14px;
        border-radius: 12px;
        border: 1px solid #1E293B;
        line-height: 1.55;
        word-break: break-word;
    }
    .edbg-mono {
        font-family: var(--font-mono);
    }
    .edbg-email-pill {
        display: inline-block;
        padding: 3px 9px;
        border-radius: 8px;
        background: #F1F5F9;
        border: 1px solid #E2E8F0;
        color: var(--lumina-navy);
        font-family: var(--font-mono);
        font-size: 12px;
        font-weight: 600;
        word-break: break-all;
    }
    .edbg-pane { display: none; }
    .edbg-pane.is-active { display: block; }
    .edbg-action-row {
        display: inline-flex;
        align-items: center;
        justify-content: flex-end;
        gap: 6px;
        flex-wrap: wrap;
    }
    .edbg-row-pending {
        background: rgba(245, 158, 11, 0.06);
    }
    @media (max-width: 768px) {
        .edbg-action-row {
            width: 100%;
            justify-content: flex-start;
        }
        .edbg-action-row .ilmuna-nav-btn {
            flex: 1 1 auto;
        }
        .edbg-terminal {
            height: 210px;
            font-size: 12px;
        }
    }
</style>

<div class="ilmuna-app-container">
    <!-- Canonical Segmented Tabs -->
    <div class="curriculum-main-tabs" role="tablist">
        <button type="button" class="curriculum-tab-btn is-active" data-edbg-tab="verifications" onclick="switchEdbgTab('verifications', this)">
            <iconify-icon icon="solar:letter-bold"></iconify-icon>
            <span class="curriculum-tab-label">Antrean Verifikasi Google</span>
            <span class="curriculum-tab-counter" id="edbgVerifCount"><?= count($verifList) ?></span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-edbg-tab="smtp" onclick="switchEdbgTab('smtp', this)">
            <iconify-icon icon="solar:server-square-bold"></iconify-icon>
            <span class="curriculum-tab-label">Uji Socket &amp; Konfigurasi SMTP</span>
            <span class="curriculum-tab-counter" id="edbgSmtpBadge"><?= !empty($smtpCfg['is_enabled']) ? 'ON' : 'OFF' ?></span>
        </button>
    </div>

    <!-- TAB 1: ANTREAN VERIFIKASI GOOGLE -->
    <div class="edbg-pane is-active" id="edbgPane-verifications">
        <div class="ilmuna-filter-box">
            <div class="ilmuna-filter-topline">
                <div class="ilmuna-search-wrap">
                    <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
                    <input type="text" id="edbgSearchInput" class="ilmuna-search-input" placeholder="Cari nama pengguna atau alamat Gmail..." oninput="filterVerifRows()">
                </div>

                <span class="ilmuna-nav-stat-inline ilmuna-nav-stat-inline--teal">
                    <iconify-icon icon="solar:clock-circle-bold"></iconify-icon>
                    Menunggu: <strong id="edbgPendingCount"><?= $pendingVerifCount ?></strong>
                </span>

                <div class="ilmuna-filter-actions">
                    <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--teal" id="btnRefreshVerif" onclick="refreshVerifications()">
                        <iconify-icon icon="solar:restart-bold"></iconify-icon>
                        <span>Segarkan Antrean</span>
                    </button>
                    <a href="<?= BASE_URL ?>/profile" class="ilmuna-nav-btn ilmuna-nav-btn--ghost">
                        <iconify-icon icon="solar:user-bold"></iconify-icon>
                        <span>Profil Saya</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="content-card">
            <div class="content-card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="lms-table ilmuna-table-stack" id="edbgVerifTable">
                        <thead>
                            <tr>
                                <th>Waktu Dibuat</th>
                                <th>Pengguna</th>
                                <th>Gmail Ditautkan</th>
                                <th>Masa Berlaku</th>
                                <th>Status Verifikasi</th>
                                <th style="text-align: right;">Aksi Instan</th>
                            </tr>
                        </thead>
                        <tbody id="edbgVerifBody">
                            <?php if (empty($verifList)): ?>
                                <tr class="ilmuna-empty-row">
                                    <td colspan="6" style="text-align: center; padding: 32px; color: var(--lumina-text-muted);">
                                        Belum ada permintaan penautan Google di database. Buka Profil lalu klik "Tautkan Akun Google".
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($verifList as $v):
                                    $isVerified = !empty($v['verified_at']);
                                    $isExpired  = strtotime($v['expires_at']) < time();
                                    $vUrl       = BASE_URL . '/verify-google.php?token=' . $v['token'];
                                    $dispName   = $v['full_name'] ?: ($v['username'] ?: 'ID #' . $v['user_id']);
                                ?>
                                    <tr class="<?= (!$isVerified && !$isExpired) ? 'edbg-row-pending' : '' ?>" data-search="<?= htmlspecialchars(strtolower($dispName . ' ' . $v['email_google'])) ?>">
                                        <td data-label="Waktu Dibuat" style="font-size: 12.5px; color: var(--lumina-text-muted);">
                                            <?= htmlspecialchars($v['created_at']) ?>
                                        </td>
                                        <td data-label="Pengguna">
                                            <strong><?= htmlspecialchars($dispName) ?></strong>
                                            <div class="edbg-mono" style="font-size: 11.5px; color: var(--lumina-text-muted);">User ID: #<?= (int)$v['user_id'] ?></div>
                                        </td>
                                        <td data-label="Gmail Ditautkan">
                                            <span class="edbg-email-pill"><?= htmlspecialchars($v['email_google']) ?></span>
                                        </td>
                                        <td data-label="Masa Berlaku" style="font-size: 12.5px;">
                                            <?= htmlspecialchars($v['expires_at']) ?>
                                        </td>
                                        <td data-label="Status">
                                            <?php if ($isVerified): ?>
                                                <span class="ilmuna-badge ilmuna-badge--success">
                                                    <iconify-icon icon="solar:check-circle-bold"></iconify-icon> Terverifikasi
                                                </span>
                                            <?php elseif ($isExpired): ?>
                                                <span class="ilmuna-badge ilmuna-badge--danger">
                                                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon> Kedaluwarsa
                                                </span>
                                            <?php else: ?>
                                                <span class="ilmuna-badge ilmuna-badge--warning">
                                                    <iconify-icon icon="solar:clock-circle-bold"></iconify-icon> Menunggu Verifikasi
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Aksi" style="text-align: right;">
                                            <div class="edbg-action-row">
                                                <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" style="height: 34px; padding: 0 10px; font-size: 12px;" onclick="copyLink(<?= htmlspecialchars(json_encode($vUrl), ENT_QUOTES) ?>)">
                                                    <iconify-icon icon="solar:copy-bold"></iconify-icon>
                                                    <span>Salin</span>
                                                </button>
                                                <?php if (!$isVerified && !$isExpired): ?>
                                                    <a href="<?= htmlspecialchars($vUrl) ?>" target="_blank" class="ilmuna-nav-btn ilmuna-nav-btn--teal" style="height: 34px; padding: 0 11px; font-size: 12px;">
                                                        <iconify-icon icon="solar:check-circle-bold"></iconify-icon>
                                                        <span>Verifikasi</span>
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

    <!-- TAB 2: UJI PENGIRIMAN & KONFIGURASI SMTP -->
    <div class="edbg-pane" id="edbgPane-smtp">
        <div class="ilm-grid-2">
            <!-- FORM TES PENGIRIMAN & TERMINAL -->
            <div class="content-card" style="margin-bottom: 0;">
                <div class="content-card-header">
                    <h3 class="content-card-title">
                        <iconify-icon icon="solar:plain-bold" style="color: var(--lumina-teal);"></iconify-icon>
                        Uji Coba Pengiriman &amp; Konsol Trace
                    </h3>
                    <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" style="height: 32px; padding: 0 10px; font-size: 12px;" onclick="clearTerminal()">
                        <iconify-icon icon="solar:eraser-bold"></iconify-icon>
                        <span>Bersihkan</span>
                    </button>
                </div>
                <div class="content-card-body">
                    <div class="ilm-grid-2" style="margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Email Tujuan</label>
                            <input type="email" id="test_target_email" class="form-control" value="syahriel.andika28@gmail.com" placeholder="Email tujuan">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Metode Transmisi</label>
                            <select id="test_method" class="form-control">
                                <option value="relay" selected>Google Apps Script Relay (HTTPS 443)</option>
                                <option value="auto">Auto (Smart Fallback)</option>
                                <option value="465">Paksa Port 465 (SSL)</option>
                                <option value="587">Paksa Port 587 (TLS)</option>
                                <option value="mail">Paksa PHP mail()</option>
                            </select>
                        </div>
                    </div>

                    <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--teal" id="btnSendTest" onclick="sendTestEmail()" style="width: 100%; height: 44px; margin-bottom: 14px;">
                        <iconify-icon icon="solar:plain-bold"></iconify-icon>
                        <span>Kirim Email Uji Coba Sekarang</span>
                    </button>

                    <div style="font-size: 11.5px; font-weight: 700; color: var(--lumina-text-muted); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 6px;">
                        Log Terminal Socket
                    </div>
                    <div id="debugTerminal" class="edbg-terminal">
                        <div style="color: #94A3B8;">[<?= date('H:i:s') ?>] Terminal siap. Tekan "Uji Handshake" atau "Kirim Email Uji Coba" untuk melihat transmisi mentah.</div>
                    </div>
                </div>
            </div>

            <!-- FORM KONFIGURASI AKTIF -->
            <div class="content-card" style="margin-bottom: 0;">
                <div class="content-card-header">
                    <h3 class="content-card-title">
                        <iconify-icon icon="solar:settings-bold" style="color: var(--lumina-navy);"></iconify-icon>
                        Konfigurasi SMTP Server
                    </h3>
                    <span id="smtpStatusBadge" class="ilmuna-badge <?= !empty($smtpCfg['is_enabled']) ? 'ilmuna-badge--success' : 'ilmuna-badge--navy' ?>">
                        <?= !empty($smtpCfg['is_enabled']) ? 'Aktif' : 'Nonaktif' ?>
                    </span>
                </div>
                <div class="content-card-body">
                    <form id="formSmtpConfig" onsubmit="handleSaveConfig(event)">
                        <div class="form-group">
                            <label class="form-label" style="color: var(--lumina-teal);">
                                <iconify-icon icon="solar:shield-check-bold"></iconify-icon> URL Google Apps Script Relay (HTTPS 443 Bypass)
                            </label>
                            <input type="url" name="google_relay_url" id="cfg_relay_url" class="form-control edbg-mono" value="<?= htmlspecialchars($smtpCfg['google_relay_url'] ?? '') ?>" placeholder="https://script.google.com/macros/s/.../exec">
                            <small style="color: var(--lumina-text-muted); font-size: 11.5px; display: block; margin-top: 4px;">Jika diisi, email sistem dialihkan via Google Apps Script (bebas blokir port hosting).</small>
                        </div>

                        <div class="ilm-grid-2-1" style="margin-bottom: 14px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Host SMTP</label>
                                <input type="text" name="host" id="cfg_host" class="form-control" value="<?= htmlspecialchars($smtpCfg['host']) ?>" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Port</label>
                                <input type="number" name="port" id="cfg_port" class="form-control" value="<?= (int)$smtpCfg['port'] ?>" required>
                            </div>
                        </div>

                        <div class="ilm-grid-2" style="margin-bottom: 14px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Enkripsi</label>
                                <select name="secure" id="cfg_secure" class="form-control">
                                    <option value="tls" <?= $smtpCfg['secure'] === 'tls' ? 'selected' : '' ?>>TLS (587)</option>
                                    <option value="ssl" <?= $smtpCfg['secure'] === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
                                    <option value="none" <?= $smtpCfg['secure'] === 'none' ? 'selected' : '' ?>>None (25)</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Status Pengiriman</label>
                                <select name="is_enabled" id="cfg_enabled" class="form-control">
                                    <option value="1" <?= !empty($smtpCfg['is_enabled']) ? 'selected' : '' ?>>Aktif (1)</option>
                                    <option value="0" <?= empty($smtpCfg['is_enabled']) ? 'selected' : '' ?>>Nonaktif (0)</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email Pengirim (Username)</label>
                            <input type="email" name="user" id="cfg_user" class="form-control" value="<?= htmlspecialchars($smtpCfg['user']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Sandi / App Password Google</label>
                            <input type="text" name="pass" id="cfg_pass" class="form-control edbg-mono" value="<?= htmlspecialchars($smtpCfg['pass']) ?>" required>
                        </div>

                        <div class="ilm-grid-2" style="margin-top: 16px;">
                            <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" style="height: 44px; width: 100%;" onclick="runSocketTest()">
                                <iconify-icon icon="solar:round-transfer-vertical-bold"></iconify-icon>
                                <span>Uji Handshake Socket</span>
                            </button>
                            <button type="submit" class="ilmuna-nav-btn ilmuna-nav-btn--primary" id="btnSaveConfig" style="height: 44px; width: 100%;">
                                <iconify-icon icon="solar:diskette-bold"></iconify-icon>
                                <span>Simpan Perubahan</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const EDBG_ENDPOINT = '<?= BASE_URL ?>/dashboard/admin/email-debug.php';

function switchEdbgTab(tabKey, btnEl) {
    document.querySelectorAll('[data-edbg-tab]').forEach(b => b.classList.remove('is-active'));
    document.querySelectorAll('.edbg-pane').forEach(p => p.classList.remove('is-active'));
    if (btnEl) btnEl.classList.add('is-active');
    const pane = document.getElementById('edbgPane-' + tabKey);
    if (pane) pane.classList.add('is-active');
}

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
}

function filterVerifRows() {
    const q = (document.getElementById('edbgSearchInput')?.value || '').trim().toLowerCase();
    document.querySelectorAll('#edbgVerifBody tr[data-search]').forEach(tr => {
        const hay = tr.getAttribute('data-search') || '';
        tr.style.display = (!q || hay.includes(q)) ? '' : 'none';
    });
}

async function refreshVerifications() {
    const btn = document.getElementById('btnRefreshVerif');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<iconify-icon icon="solar:refresh-bold" class="ilmuna-spin"></iconify-icon><span>Menyegarkan...</span>';
    }
    const fd = new FormData();
    fd.append('action', 'get_verifications');
    try {
        const res = await fetch(EDBG_ENDPOINT, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success && Array.isArray(data.rows)) {
            const tbody = document.getElementById('edbgVerifBody');
            let pendingCount = 0;
            if (!data.rows.length) {
                tbody.innerHTML = '<tr class="ilmuna-empty-row"><td colspan="6" style="text-align:center; padding:32px; color:var(--lumina-text-muted);">Belum ada permintaan penautan Google di database.</td></tr>';
            } else {
                tbody.innerHTML = data.rows.map(v => {
                    if (v.status === 'pending') pendingCount++;
                    const badge = v.status === 'verified'
                        ? '<span class="ilmuna-badge ilmuna-badge--success"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> Terverifikasi</span>'
                        : (v.status === 'expired'
                            ? '<span class="ilmuna-badge ilmuna-badge--danger"><iconify-icon icon="solar:close-circle-bold"></iconify-icon> Kedaluwarsa</span>'
                            : '<span class="ilmuna-badge ilmuna-badge--warning"><iconify-icon icon="solar:clock-circle-bold"></iconify-icon> Menunggu Verifikasi</span>');
                    const verifyBtn = v.status === 'pending'
                        ? `<a href="${escapeHtml(v.verify_url)}" target="_blank" class="ilmuna-nav-btn ilmuna-nav-btn--teal" style="height:34px; padding:0 11px; font-size:12px;"><iconify-icon icon="solar:check-circle-bold"></iconify-icon><span>Verifikasi</span></a>`
                        : '';
                    return `<tr class="${v.status === 'pending' ? 'edbg-row-pending' : ''}" data-search="${escapeHtml((v.display_name + ' ' + v.email_google).toLowerCase())}">
                        <td data-label="Waktu Dibuat" style="font-size:12.5px; color:var(--lumina-text-muted);">${escapeHtml(v.created_at)}</td>
                        <td data-label="Pengguna"><strong>${escapeHtml(v.display_name)}</strong><div class="edbg-mono" style="font-size:11.5px; color:var(--lumina-text-muted);">User ID: #${v.user_id}</div></td>
                        <td data-label="Gmail Ditautkan"><span class="edbg-email-pill">${escapeHtml(v.email_google)}</span></td>
                        <td data-label="Masa Berlaku" style="font-size:12.5px;">${escapeHtml(v.expires_at)}</td>
                        <td data-label="Status">${badge}</td>
                        <td data-label="Aksi" style="text-align:right;">
                            <div class="edbg-action-row">
                                <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" style="height:34px; padding:0 10px; font-size:12px;" data-copy-url="${escapeHtml(v.verify_url)}" onclick="copyLink(this.getAttribute('data-copy-url'))">
                                    <iconify-icon icon="solar:copy-bold"></iconify-icon><span>Salin</span>
                                </button>
                                ${verifyBtn}
                            </div>
                        </td>
                    </tr>`;
                }).join('');
            }
            const cEl = document.getElementById('edbgVerifCount');
            const pEl = document.getElementById('edbgPendingCount');
            if (cEl) cEl.textContent = data.rows.length;
            if (pEl) pEl.textContent = pendingCount;
            filterVerifRows();
            if (window.Ilmuna) Ilmuna.toast('Antrean verifikasi berhasil disegarkan.', 'success');
        }
    } catch (err) {
        if (window.Ilmuna) Ilmuna.toast('Gagal menyegarkan antrean: ' + err.message, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<iconify-icon icon="solar:restart-bold"></iconify-icon><span>Segarkan Antrean</span>';
        }
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
        const res = await fetch(EDBG_ENDPOINT, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.logs && data.logs.length) {
            data.logs.forEach(l => appendLog(`[${l.time}] ${l.text}`, l.type));
        }
        if (window.Ilmuna) {
            Ilmuna.toast(data.success ? 'Handshake socket selesai. Periksa terminal trace.' : 'Koneksi socket gagal.', data.success ? 'success' : 'error');
        }
    } catch (err) {
        appendLog('Gagal menjalankan pengujian: ' + err.message, 'error');
        if (window.Ilmuna) Ilmuna.toast('Gagal menguji socket: ' + err.message, 'error');
    }
}

async function sendTestEmail() {
    const toEmail = document.getElementById('test_target_email').value.trim();
    const method  = document.getElementById('test_method').value;
    const btn     = document.getElementById('btnSendTest');

    if (!toEmail) {
        if (window.Ilmuna) Ilmuna.toast('Harap masukkan alamat email tujuan.', 'warning');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<iconify-icon icon="solar:refresh-bold" class="ilmuna-spin"></iconify-icon><span>Mengirim...</span>';
    appendLog(`[Mengirim email tes ke ${toEmail} dengan metode ${method}...]`);

    const formData = new FormData();
    formData.append('action', 'send_test_email');
    formData.append('to_email', toEmail);
    formData.append('method', method);

    try {
        const res = await fetch(EDBG_ENDPOINT, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            appendLog(`[SUKSES] ${data.message}`, 'success');
            if (window.Ilmuna) Ilmuna.toast(data.message, 'success');
        } else {
            appendLog(`[GAGAL] ${data.message}`, 'error');
            if (window.Ilmuna) Ilmuna.toast(data.message, 'error');
        }
    } catch (err) {
        appendLog('Kesalahan: ' + err.message, 'error');
        if (window.Ilmuna) Ilmuna.toast('Kesalahan jaringan: ' + err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<iconify-icon icon="solar:plain-bold"></iconify-icon><span>Kirim Email Uji Coba Sekarang</span>';
    }
}

async function handleSaveConfig(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveConfig');
    btn.disabled = true;
    btn.innerHTML = '<iconify-icon icon="solar:refresh-bold" class="ilmuna-spin"></iconify-icon><span>Menyimpan...</span>';

    const formData = new FormData(document.getElementById('formSmtpConfig'));
    formData.append('action', 'save_config');

    try {
        const res = await fetch(EDBG_ENDPOINT, { method: 'POST', body: formData });
        const data = await res.json();
        if (window.Ilmuna) {
            Ilmuna.toast(data.message || 'Tersimpan', data.success ? 'success' : 'error');
        }
        if (data.success) {
            const enabled = Number(data.is_enabled) === 1;
            const statusBadge = document.getElementById('smtpStatusBadge');
            const tabBadge = document.getElementById('edbgSmtpBadge');
            if (statusBadge) {
                statusBadge.textContent = enabled ? 'Aktif' : 'Nonaktif';
                statusBadge.className = 'ilmuna-badge ' + (enabled ? 'ilmuna-badge--success' : 'ilmuna-badge--navy');
            }
            if (tabBadge) tabBadge.textContent = enabled ? 'ON' : 'OFF';
        }
    } catch (err) {
        if (window.Ilmuna) Ilmuna.toast('Gagal menyimpan: ' + err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<iconify-icon icon="solar:diskette-bold"></iconify-icon><span>Simpan Perubahan</span>';
    }
}

function copyLink(url) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(() => {
            if (window.Ilmuna) Ilmuna.toast('Tautan verifikasi berhasil disalin ke clipboard.', 'success');
        }).catch(() => {
            if (window.Ilmuna) Ilmuna.alert('Salin Tautan Verifikasi', url, 'info');
        });
    } else if (window.Ilmuna) {
        Ilmuna.alert('Salin Tautan Verifikasi', url, 'info');
    }
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
