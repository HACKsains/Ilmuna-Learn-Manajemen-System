<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — INTEGRASI API & WEBSOCKET MARGO ONE SSO (PUBLIC + PRIVATE KEY)
 * ==============================================================================
 * Panel Admin Zero-Trust untuk menghubungkan learn.ilmuna.my.id <-> margo-one.ilmuna.my.id:
 *   1. Manajemen Public Key (pk_sso_*) & Private Key (sk_sso_*) + RSA-2048 PEM (HMAC/RSA/DUAL)
 *   2. Kontrol & Monitoring Daemon WebSocket Real-Time RFC 6455 (Zero Polling / Zero Reload)
 *   3. Simulasi Live Notification Push & Live Signed REST API Verification
 *   4. Preview Data Per Kelas: Deadline & Broadcast Tugas, Proyek, Kelompok, Jadwal Asesmen
 *   5. Unduh Paket SDK Margo One SSO (.zip) + Audit Log API
 *
 * Standar UI: Data-First Zero Title, Segmented Tabs, Solar Icons, Zero Reload.
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';
require_once BASE_PATH . '/includes/sso-api-security.php';
require_once BASE_PATH . '/includes/sso-api-data.php';
require_once BASE_PATH . '/includes/sso-realtime-dispatcher.php';

requireRole('admin');

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
ilmuna_sso_api_ensure_schema($pdo);

/* ==============================================================================
 * DOWNLOAD MARGO ONE SSO SDK & WEBSOCKET WIDGET (.zip)
 * ============================================================================== */
if (isset($_GET['download_sdk']) && $_GET['download_sdk'] === '1') {
    $sdkDir = BASE_PATH . '/sso-sdk/ilmuna-learn-sso-notifier';
    $files = [
        'IlmunaLearnSsoNotifierClient.php',
        'ilmuna-sso-realtime-widget.js',
        'INTEGRATION_SSO_GUIDE.md',
    ];
    while (ob_get_level()) {
        ob_end_clean();
    }
    if (class_exists('ZipArchive')) {
        $tmpZip = tempnam(sys_get_temp_dir(), 'ilmuna_sso_sdk_');
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::OVERWRITE) === true) {
            foreach ($files as $fname) {
                $full = $sdkDir . '/' . $fname;
                if (file_exists($full)) {
                    $zip->addFile($full, 'ilmuna-learn-sso-notifier/' . $fname);
                }
            }
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="ilmuna-learn-sso-notifier-sdk.zip"');
            header('Content-Length: ' . filesize($tmpZip));
            header('Cache-Control: no-store');
            readfile($tmpZip);
            @unlink($tmpZip);
            exit;
        }
    }
    http_response_code(500);
    echo 'ZipArchive tidak tersedia di server.';
    exit;
}

/* ==============================================================================
 * AJAX JSON API (?api=1) — ZERO PAGE RELOAD
 * ============================================================================== */
if (isset($_GET['api']) && $_GET['api'] === '1') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $act = trim((string)($_POST['api_action'] ?? $_GET['api_action'] ?? ''));

    try {
        if ($act === 'bootstrap') {
            $clients = $pdo->query("SELECT * FROM sso_api_clients ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $clientOut = [];
            foreach ($clients as $c) {
                $plainPriv = ilmuna_sso_api_decrypt_secret($c['private_key_enc']);
                $wsStat    = ilmuna_sso_ws_server_status((int)$c['ws_port'], (int)$c['ws_ipc_port']);
                $clientOut[] = [
                    'id'                 => (int)$c['id'],
                    'client_name'        => (string)$c['client_name'],
                    'public_key'         => (string)$c['public_key'],
                    'private_key'        => $plainPriv,
                    'private_key_masked' => strlen($plainPriv) > 16 ? (substr($plainPriv, 0, 14) . str_repeat('•', 20) . substr($plainPriv, -6)) : '••••••••',
                    'has_rsa_public'     => !empty($c['rsa_public_pem']),
                    'rsa_fingerprint'    => !empty($c['rsa_public_pem']) ? substr(hash('sha256', trim($c['rsa_public_pem'])), 0, 24) : '',
                    'sig_mode'           => (string)$c['sig_mode'],
                    'allowed_domains'    => (string)$c['allowed_domains'],
                    'allowed_ips'        => (string)($c['allowed_ips'] ?? ''),
                    'allowed_scopes'     => (string)$c['allowed_scopes'],
                    'rate_limit_per_min' => (int)$c['rate_limit_per_min'],
                    'ws_public_url'      => (string)($c['ws_public_url'] ?: ilmuna_sso_default_ws_url((int)$c['ws_port'])),
                    'ws_port'            => (int)$c['ws_port'],
                    'ws_ipc_port'        => (int)$c['ws_ipc_port'],
                    'webhook_url'        => (string)($c['webhook_url'] ?? ''),
                    'ws_status'          => $wsStat,
                    'is_active'          => (int)$c['is_active'],
                    'last_used_at'       => $c['last_used_at'],
                    'last_ip'            => $c['last_ip'],
                    'request_count'      => (int)$c['request_count'],
                ];
            }

            $filterKelasId = (int)($_POST['kelas_id'] ?? $_GET['kelas_id'] ?? 0);
            $ctx = $filterKelasId > 0
                ? ilmuna_sso_resolve_identity_context($pdo, ['kelas_id' => $filterKelasId])
                : ilmuna_sso_resolve_identity_context($pdo, []);

            $feed    = ilmuna_sso_build_unified_feed($pdo, ['limit' => 20], $ctx);
            $classes = ilmuna_sso_fetch_master_classes($pdo);
            $logs    = $pdo->query("
                SELECT id, public_key, endpoint, resource, http_status, client_ip, client_site,
                       sig_algorithm, error_code, duration_ms, created_at
                FROM sso_api_audit_logs
                ORDER BY id DESC
                LIMIT 50
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data'    => [
                    'api_endpoint' => ilmuna_wp_api_public_base_url() . '/api/sso-broadcast.php',
                    'clients'      => $clientOut,
                    'classes'      => $classes,
                    'feed'         => $feed,
                    'audit_logs'   => $logs,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($act === 'save_client') {
            $id         = (int)($_POST['id'] ?? 0);
            $clientName = trim((string)($_POST['client_name'] ?? 'Margo One SSO (margo-one.ilmuna.my.id)'));
            $sigMode    = trim((string)($_POST['sig_mode'] ?? 'HMAC-SHA256'));
            if (!in_array($sigMode, ['HMAC-SHA256', 'RSA-SHA256', 'DUAL'], true)) {
                $sigMode = 'HMAC-SHA256';
            }
            $domains    = trim((string)($_POST['allowed_domains'] ?? 'margo-one.ilmuna.my.id,*.ilmuna.my.id'));
            $ips        = trim((string)($_POST['allowed_ips'] ?? ''));
            $rateLim    = max(10, min(2000, (int)($_POST['rate_limit_per_min'] ?? 240)));
            $wsUrl      = trim((string)($_POST['ws_public_url'] ?? ''));
            $wsPort     = max(1024, min(65535, (int)($_POST['ws_port'] ?? 8090)));
            $wsIpcPort  = max(1024, min(65535, (int)($_POST['ws_ipc_port'] ?? 8091)));
            $webhookUrl = trim((string)($_POST['webhook_url'] ?? '')) ?: null;
            $isActive   = !empty($_POST['is_active']) ? 1 : 0;

            if ($sigMode !== 'HMAC-SHA256') {
                $chkRsa = $pdo->prepare("SELECT rsa_public_pem FROM sso_api_clients WHERE id = ?");
                $chkRsa->execute([$id]);
                if (trim((string)$chkRsa->fetchColumn()) === '') {
                    echo json_encode(['success' => false, 'message' => 'Generate RSA-2048 PEM terlebih dahulu sebelum mengaktifkan mode ' . $sigMode . '.']);
                    exit;
                }
            }

            $stmt = $pdo->prepare("
                UPDATE sso_api_clients
                SET client_name = ?, sig_mode = ?, allowed_domains = ?, allowed_ips = ?,
                    rate_limit_per_min = ?, ws_public_url = ?, ws_port = ?, ws_ipc_port = ?,
                    webhook_url = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$clientName, $sigMode, $domains, $ips, $rateLim, $wsUrl ?: ilmuna_sso_default_ws_url($wsPort), $wsPort, $wsIpcPort, $webhookUrl, $isActive, $id]);

            echo json_encode(['success' => true, 'message' => 'Pengaturan keamanan REST API & WebSocket Margo One SSO berhasil disimpan.']);
            exit;
        }

        if ($act === 'rotate_keys') {
            $id          = (int)($_POST['id'] ?? 0);
            $pair        = ilmuna_sso_api_generate_keypair();
            $encPrivate  = ilmuna_sso_api_encrypt_secret($pair['private_key']);
            $hashPrivate = hash('sha256', $pair['private_key']);

            $stmt = $pdo->prepare("UPDATE sso_api_clients SET public_key = ?, private_key_enc = ?, private_key_hash = ? WHERE id = ?");
            $stmt->execute([$pair['public_key'], $encPrivate, $hashPrivate, $id]);

            echo json_encode([
                'success' => true,
                'message' => 'Public Key & Private Key SSO baru berhasil dibangkitkan! Perbarui konfigurasi pada server margo-one.ilmuna.my.id.',
                'data'    => $pair,
            ]);
            exit;
        }

        if ($act === 'generate_rsa') {
            $id  = (int)($_POST['id'] ?? 0);
            $rsa = ilmuna_sso_api_generate_rsa_pair();
            if (empty($rsa['success'])) {
                echo json_encode(['success' => false, 'message' => $rsa['message'] ?? 'Gagal membangkitkan pasangan RSA-2048.']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE sso_api_clients SET rsa_public_pem = ? WHERE id = ?");
            $stmt->execute([$rsa['public_pem'], $id]);

            echo json_encode([
                'success' => true,
                'message' => 'RSA-2048 PEM berhasil dibuat! Salin Private Key PEM sekarang ke server Margo One SSO — server ini hanya menyimpan Public PEM.',
                'data'    => [
                    'private_pem' => $rsa['private_pem'],
                    'fingerprint' => substr($rsa['fingerprint'], 0, 24),
                ],
            ]);
            exit;
        }

        if ($act === 'start_ws_daemon') {
            $id = (int)($_POST['id'] ?? 0);
            $cStmt = $pdo->prepare("SELECT ws_port, ws_ipc_port FROM sso_api_clients WHERE id = ? LIMIT 1");
            $cStmt->execute([$id]);
            $c = $cStmt->fetch(PDO::FETCH_ASSOC);
            $wsPort  = $c ? (int)$c['ws_port'] : ILMUNA_SSO_WS_DEFAULT_PORT;
            $ipcPort = $c ? (int)$c['ws_ipc_port'] : ILMUNA_SSO_WS_DEFAULT_IPC_PORT;

            $stat = ilmuna_sso_ws_server_status($wsPort, $ipcPort);
            if ($stat['running']) {
                echo json_encode(['success' => true, 'message' => "Daemon WebSocket sudah aktif di port {$wsPort} (IPC {$ipcPort}).", 'data' => $stat]);
                exit;
            }

            $script = BASE_PATH . DIRECTORY_SEPARATOR . 'websocket' . DIRECTORY_SEPARATOR . 'sso-realtime-server.php';
            if (!file_exists($script)) {
                echo json_encode(['success' => false, 'message' => 'Script WebSocket server tidak ditemukan.']);
                exit;
            }

            // Deteksi binary PHP CLI yang sesungguhnya (karena di Apache/FPM/LSPHP, PHP_BINARY bukan CLI!)
            $isWin  = (stripos(PHP_OS_FAMILY ?? PHP_OS, 'Win') !== false);
            $phpBin = '';
            $candidates = [];
            if ($isWin) {
                if (defined('PHP_BINDIR') && PHP_BINDIR) {
                    $candidates[] = rtrim(PHP_BINDIR, '\\/') . DIRECTORY_SEPARATOR . 'php.exe';
                }
                if (PHP_BINARY && stripos(basename(PHP_BINARY), 'php') === 0) {
                    $candidates[] = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php.exe';
                }
                foreach (glob('C:\\laragon\\bin\\php\\php-*\\php.exe') ?: [] as $gPhp) {
                    $candidates[] = $gPhp;
                }
            } else {
                if (defined('PHP_BINDIR') && PHP_BINDIR) {
                    $candidates[] = rtrim(PHP_BINDIR, '/') . '/php';
                }
                if (PHP_BINARY) {
                    $candidates[] = preg_replace('#/(lsphp|php-fpm)[^/]*$#', '/php', PHP_BINARY);
                    $candidates[] = preg_replace('#/sbin/php-fpm$#', '/bin/php', PHP_BINARY);
                }
                $candidates[] = '/usr/local/bin/php';
                $candidates[] = '/usr/bin/php';
            }
            foreach ($candidates as $cand) {
                if ($cand && @is_file($cand) && ($isWin || @is_executable($cand))) {
                    $phpBin = $cand;
                    break;
                }
            }
            if ($phpBin === '') {
                $phpBin = 'php';
            }

            $disabledFns = array_map('trim', explode(',', (string)ini_get('disable_functions')));
            $canPopen    = function_exists('popen') && !in_array('popen', $disabledFns, true);
            $canExec     = function_exists('exec') && !in_array('exec', $disabledFns, true);

            if (!$canPopen && !$canExec) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Shared cPanel terdeteksi (exec/popen & CLI tidak tersedia). Mode Real-Time SSE Stream (/api/sso-stream.php) otomatis aktif sebagai pengganti WebSocket port ' . $wsPort . ' tanpa perlu CLI!',
                    'data'    => array_merge($stat, ['sse_fallback_ready' => true]),
                ]);
                exit;
            }

            $logPath = BASE_PATH . DIRECTORY_SEPARATOR . 'websocket' . DIRECTORY_SEPARATOR . 'ws.log';
            $iniPath = php_ini_loaded_file();
            $iniArg  = ($iniPath && @is_file($iniPath)) ? (' -c "' . $iniPath . '"') : '';

            if ($isWin) {
                $cmd = 'start "" /B "' . $phpBin . '"' . $iniArg . ' "' . $script . '" --ws-port=' . $wsPort . ' --ipc-port=' . $ipcPort . ' > "' . $logPath . '" 2>&1';
                if ($canPopen) {
                    @pclose(@popen($cmd, 'r'));
                } else {
                    @exec($cmd);
                }
            } else {
                $cmd = 'nohup "' . $phpBin . '"' . $iniArg . ' "' . $script . '" --ws-port=' . $wsPort . ' --ipc-port=' . $ipcPort . ' > "' . $logPath . '" 2>&1 &';
                if ($canExec) {
                    @exec($cmd);
                } else {
                    @pclose(@popen($cmd, 'r'));
                }
            }

            $statAfter = $stat;
            for ($w = 0; $w < 4; $w++) {
                usleep(400000);
                $statAfter = ilmuna_sso_ws_server_status($wsPort, $ipcPort);
                if ($statAfter['running']) {
                    break;
                }
            }

            echo json_encode([
                'success' => true,
                'message' => $statAfter['running']
                    ? "Daemon WebSocket berhasil dijalankan pada port {$wsPort}!"
                    : "Port {$wsPort} tidak dibuka oleh shared hosting / cPanel tanpa CLI. Mode Real-Time SSE Stream (/api/sso-stream.php) otomatis aktif agar notifikasi real-time ke SSO tetap berjalan!",
                'data'    => array_merge($statAfter, ['sse_fallback_ready' => true]),
            ]);
            exit;
        }

        if ($act === 'test_signed_request') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM sso_api_clients WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$client) {
                echo json_encode(['success' => false, 'message' => 'Klien API SSO tidak ditemukan.']);
                exit;
            }

            $privateKey = ilmuna_sso_api_decrypt_secret($client['private_key_enc']);
            $ts    = (string)time();
            $nonce = bin2hex(random_bytes(16));
            $query = ['resource' => 'all'];
            $canon = ilmuna_sso_api_canonical_string('GET', '/api/sso-broadcast.php', $query, $ts, $nonce, '');
            $sig   = ilmuna_sso_api_sign_hmac($canon, $privateKey);

            $domains = ilmuna_sso_api_parse_list($client['allowed_domains']);
            $simSite = !empty($domains) ? str_replace('*.', 'margo-one.', $domains[0]) : 'margo-one.ilmuna.my.id';

            if ($client['sig_mode'] === 'HMAC-SHA256') {
                $authRes = ilmuna_sso_api_authenticate($pdo, [
                    'method'    => 'GET',
                    'uri'       => '/api/sso-broadcast.php',
                    'query'     => $query,
                    'body'      => '',
                    'client_ip' => ilmuna_sso_api_client_ip(),
                    'headers'   => [
                        'x-ilmuna-public-key' => $client['public_key'],
                        'x-ilmuna-timestamp'  => $ts,
                        'x-ilmuna-nonce'      => $nonce,
                        'x-ilmuna-signature'  => $sig,
                        'x-ilmuna-site'       => $simSite,
                    ],
                ]);
                if (!$authRes['ok']) {
                    echo json_encode(['success' => false, 'message' => 'Uji Autentikasi Gagal [' . $authRes['code'] . ']: ' . $authRes['message']]);
                    exit;
                }
            }

            $ctx  = ilmuna_sso_resolve_identity_context($pdo, []);
            $feed = ilmuna_sso_build_unified_feed($pdo, ['limit' => 20], $ctx);
            $wsStat = ilmuna_sso_ws_server_status((int)$client['ws_port'], (int)$client['ws_ipc_port']);

            echo json_encode([
                'success' => true,
                'message' => 'Verifikasi Kriptografis Signed API Sukses untuk ' . $simSite . '!',
                'data'    => [
                    'sig_mode'       => $client['sig_mode'],
                    'simulated_site' => $simSite,
                    'ws_live'        => $wsStat['running'],
                    'counts'         => $feed['counts'],
                ],
            ]);
            exit;
        }

        if ($act === 'issue_demo_ws_ticket') {
            $id = (int)($_POST['id'] ?? 0);
            $kelasId = (int)($_POST['kelas_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM sso_api_clients WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$client) {
                echo json_encode(['success' => false, 'message' => 'Klien API SSO tidak ditemukan.']);
                exit;
            }
            $privateKey = ilmuna_sso_api_decrypt_secret($client['private_key_enc']);
            $ticket = ilmuna_sso_api_issue_ws_ticket($pdo, $client, $privateKey, [
                'ttl'             => 300,
                'kelas_ids'       => $kelasId > 0 ? [$kelasId] : [],
                'role_code'       => $kelasId > 0 ? 'siswa' : 'admin',
                'user_identifier' => 'admin-live-monitor',
                'subscribe_all'   => ($kelasId <= 0),
            ]);
            echo json_encode(['success' => true, 'data' => $ticket]);
            exit;
        }

        if ($act === 'push_test_notification') {
            $kelasId = (int)($_POST['kelas_id'] ?? 0);
            $category = trim((string)($_POST['category'] ?? 'asesmen'));
            $title    = trim((string)($_POST['title'] ?? 'Uji Push Notifikasi Real-Time Margo One SSO'));
            $summary  = trim((string)($_POST['summary'] ?? 'Notifikasi ini dikirim instan via WebSocket tanpa polling/reload halaman.'));

            $res = ilmuna_sso_emit_realtime_event($pdo, [
                'event_type'   => $category . '.test_push',
                'category'     => $category,
                'kelas_id'     => $kelasId > 0 ? $kelasId : null,
                'reference_id' => 0,
                'title'        => $title,
                'summary'      => $summary,
                'deadline_at'  => date('Y-m-d H:i:s', time() + 86400),
                'urgency'      => 'segera',
                'portal_url'   => ilmuna_wp_api_public_base_url(),
                'data'         => ['source' => 'admin_simulator'],
            ]);

            echo json_encode([
                'success' => !empty($res['ok']),
                'message' => !empty($res['pushed_ws'])
                    ? 'Event #' . ($res['event_id'] ?? 0) . ' berhasil didorong real-time ke WebSocket Server!'
                    : 'Event #' . ($res['event_id'] ?? 0) . ' tersimpan ke antrean event (Daemon WebSocket belum aktif pada port IPC).',
                'data' => $res,
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali.']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$pageTitle = 'API & WebSocket Margo One SSO';
$pageIcon  = 'solar:bolt-circle-bold';

$extraHead = '<style>
.ssoapi-app{display:flex;flex-direction:column;gap:16px}
.ssoapi-pane{display:none}.ssoapi-pane.is-active{display:flex;flex-direction:column;gap:16px}
.ssoapi-card{background:#fff;border:1px solid #E2E8F0;border-radius:16px;padding:18px 20px;box-shadow:0 4px 16px rgba(15,23,42,.04)}
.ssoapi-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #F1F5F9}
.ssoapi-card-title{margin:0;font-size:15px;font-weight:800;color:#1D3B5E;display:inline-flex;align-items:center;gap:8px}
.ssoapi-grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
@media(max-width:768px){.ssoapi-grid-2{grid-template-columns:1fr}}
.ssoapi-key-box{background:#F8FAFC;border:1px solid #CBD5E1;border-radius:12px;padding:12px 14px;display:flex;flex-direction:column;gap:6px}
.ssoapi-key-lbl{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#475569;display:flex;align-items:center;justify-content:space-between;gap:8px}
.ssoapi-key-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.ssoapi-code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;background:#0F172A;color:#E2E8F0;padding:8px 11px;border-radius:9px;word-break:break-all;flex:1;min-width:180px}
.ssoapi-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:700}
.ssoapi-badge--ok{background:#DCFCE7;color:#15803D}
.ssoapi-badge--warn{background:#FEF3C7;color:#B45309}
.ssoapi-badge--err{background:#FEE2E2;color:#B91C1C}
.ssoapi-badge--teal{background:#CCFBF1;color:#0F766E}
.ssoapi-badge--navy{background:#E0E7FF;color:#1D3B5E}
.ssoapi-badge--purple{background:#EDE9FE;color:#6D28D9}
.ssoapi-btn{appearance:none;border:1px solid transparent;border-radius:10px;padding:8px 14px;font-size:12.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .18s;text-decoration:none}
.ssoapi-btn--primary{background:linear-gradient(135deg,#1D3B5E,#0D7A87);color:#fff}
.ssoapi-btn--primary:hover{opacity:.93}
.ssoapi-btn--gold{background:linear-gradient(135deg,#D97706,#F59E0B);color:#fff}
.ssoapi-btn--emerald{background:linear-gradient(135deg,#059669,#10B981);color:#fff}
.ssoapi-btn--ghost{background:#F8FAFC;color:#1D3B5E;border-color:#CBD5E1}
.ssoapi-btn--ghost:hover{background:#F1F5F9}
.ssoapi-btn--danger{background:#FEF2F2;color:#B91C1C;border-color:#FECACA}
.ssoapi-btn--danger:hover{background:#FEE2E2}
.ssoapi-field{display:flex;flex-direction:column;gap:5px}
.ssoapi-field label{font-size:12.5px;font-weight:700;color:#334155}
.ssoapi-input{width:100%;padding:9px 12px;border:1px solid #CBD5E1;border-radius:10px;font-size:13px;background:#fff;color:#0F172A;box-sizing:border-box}
.ssoapi-input:focus{outline:none;border-color:#0D7A87;box-shadow:0 0 0 3px rgba(13,122,135,.14)}
.ssoapi-pre{background:#0F172A;color:#E2E8F0;padding:14px;border-radius:12px;font-size:12px;overflow:auto;margin:0;line-height:1.55}
.ssoapi-switch{display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#1E293B;cursor:pointer}
.ssoapi-banner{display:none;padding:12px 14px;border-radius:12px;font-size:13px;font-weight:600;margin-bottom:12px}
.ssoapi-feed-list{display:flex;flex-direction:column;gap:8px;max-height:340px;overflow-y:auto}
.ssoapi-ws-console{background:#0F172A;color:#E2E8F0;border-radius:12px;padding:12px 14px;font-family:ui-monospace,Consolas,monospace;font-size:12px;max-height:240px;overflow-y:auto;display:flex;flex-direction:column;gap:6px}
</style>';

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'table']);
?>

<div class="ssoapi-app">
    <div class="curriculum-main-tabs" id="ssoapiMainTabs" role="tablist">
        <button type="button" class="curriculum-tab-btn is-active" data-tab="keys" role="tab" aria-selected="true">
            <span class="curriculum-tab-step">1</span>
            <iconify-icon icon="solar:shield-keyhole-bold"></iconify-icon>
            <span class="curriculum-tab-label">Kunci API &amp; Keamanan SSO</span>
            <span class="curriculum-tab-counter" id="cntSsoStatus">AKTIF</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="feed" role="tab" aria-selected="false">
            <span class="curriculum-tab-step">2</span>
            <iconify-icon icon="solar:calendar-mark-bold"></iconify-icon>
            <span class="curriculum-tab-label">Preview Feed Per Kelas</span>
            <span class="curriculum-tab-counter" id="cntSsoFeedTotal">0</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="websocket" role="tab" aria-selected="false">
            <span class="curriculum-tab-step">3</span>
            <iconify-icon icon="solar:bolt-circle-bold"></iconify-icon>
            <span class="curriculum-tab-label">WebSocket Real-Time &amp; SDK</span>
            <span class="curriculum-tab-counter" id="cntSsoWsState">WS</span>
        </button>
    </div>

    <!-- TAB 1: KUNCI PUBLIC/PRIVATE KEY & ZERO-TRUST -->
    <section class="ssoapi-pane is-active" id="ssoapiPane_keys">
        <div class="ssoapi-card">
            <div class="ssoapi-card-head">
                <h3 class="ssoapi-card-title">
                    <iconify-icon icon="solar:key-minimalistic-square-2-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    Kredensial Zero-Trust (learn.ilmuna.my.id &harr; margo-one.ilmuna.my.id)
                </h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="button" class="ssoapi-btn ssoapi-btn--ghost" onclick="SSOAPI.testConnection()">
                        <iconify-icon icon="solar:shield-check-bold"></iconify-icon>
                        <span>Uji Signed REST API</span>
                    </button>
                    <button type="button" class="ssoapi-btn ssoapi-btn--gold" onclick="SSOAPI.generateRsa()">
                        <iconify-icon icon="solar:lock-keyhole-bold"></iconify-icon>
                        <span>Generate RSA-2048 PEM</span>
                    </button>
                    <button type="button" class="ssoapi-btn ssoapi-btn--danger" onclick="SSOAPI.rotateKeys()">
                        <iconify-icon icon="solar:refresh-circle-bold"></iconify-icon>
                        <span>Rotasi Kunci Baru</span>
                    </button>
                </div>
            </div>

            <div id="ssoapiTestBanner" class="ssoapi-banner"></div>

            <div class="ssoapi-grid-2" style="margin-bottom:12px;">
                <div class="ssoapi-key-box">
                    <div class="ssoapi-key-lbl">
                        <span>Endpoint REST API</span>
                        <span class="ssoapi-badge ssoapi-badge--teal">HMAC / RSA-2048 / DUAL</span>
                    </div>
                    <div class="ssoapi-key-row">
                        <div class="ssoapi-code" id="ssoapiEndpointText">Memuat...</div>
                        <button type="button" class="ssoapi-btn ssoapi-btn--ghost" onclick="SSOAPI.copyText('ssoapiEndpointText')">
                            <iconify-icon icon="solar:copy-bold"></iconify-icon> Salin
                        </button>
                    </div>
                </div>
                <div class="ssoapi-key-box">
                    <div class="ssoapi-key-lbl">
                        <span>Endpoint WebSocket Real-Time</span>
                        <span class="ssoapi-badge" id="ssoapiWsBadge">Memeriksa...</span>
                    </div>
                    <div class="ssoapi-key-row">
                        <div class="ssoapi-code" id="ssoapiWsUrlText">Memuat...</div>
                        <button type="button" class="ssoapi-btn ssoapi-btn--emerald" onclick="SSOAPI.startWsDaemon()">
                            <iconify-icon icon="solar:play-bold"></iconify-icon> Aktifkan WS
                        </button>
                    </div>
                </div>
            </div>

            <div class="ssoapi-grid-2">
                <div class="ssoapi-key-box">
                    <div class="ssoapi-key-lbl">
                        <span>Public Key (X-Ilmuna-Public-Key)</span>
                        <span class="ssoapi-badge ssoapi-badge--navy">Identitas Klien SSO</span>
                    </div>
                    <div class="ssoapi-key-row">
                        <div class="ssoapi-code" id="ssoapiPublicKeyText">Memuat...</div>
                        <button type="button" class="ssoapi-btn ssoapi-btn--ghost" onclick="SSOAPI.copyText('ssoapiPublicKeyText')">
                            <iconify-icon icon="solar:copy-bold"></iconify-icon> Salin
                        </button>
                    </div>
                </div>

                <div class="ssoapi-key-box">
                    <div class="ssoapi-key-lbl">
                        <span>Private Key (Rahasia Server Margo One SSO)</span>
                        <span class="ssoapi-badge ssoapi-badge--warn">AES-256 Encrypted</span>
                    </div>
                    <div class="ssoapi-key-row">
                        <div class="ssoapi-code" id="ssoapiPrivateKeyText">••••••••••••••••••••••••••••</div>
                        <button type="button" class="ssoapi-btn ssoapi-btn--ghost" id="btnToggleSsoPriv" onclick="SSOAPI.togglePrivate()">
                            <iconify-icon icon="solar:eye-bold"></iconify-icon> Lihat
                        </button>
                        <button type="button" class="ssoapi-btn ssoapi-btn--ghost" onclick="SSOAPI.copyPrivate()">
                            <iconify-icon icon="solar:copy-bold"></iconify-icon> Salin
                        </button>
                    </div>
                </div>
            </div>

            <div id="ssoapiRsaRevealBox" style="display:none;margin-top:14px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:12px;padding:14px;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
                    <strong style="color:#92400E;font-size:13px;">
                        <iconify-icon icon="solar:danger-triangle-bold" style="vertical-align:middle;"></iconify-icon>
                        RSA-2048 Private Key PEM (Ditampilkan SATU KALI — Salin ke margo-one.ilmuna.my.id sekarang!)
                    </strong>
                    <button type="button" class="ssoapi-btn ssoapi-btn--gold" onclick="SSOAPI.copyRsaPem()">
                        <iconify-icon icon="solar:copy-bold"></iconify-icon> Salin RSA Private PEM
                    </button>
                </div>
                <textarea id="ssoapiRsaPemOutput" class="ssoapi-input" rows="6" readonly style="font-family:monospace;font-size:11.5px;background:#0F172A;color:#FDE68A;"></textarea>
            </div>
        </div>

        <form class="ssoapi-card" onsubmit="SSOAPI.saveClient(event)">
            <div class="ssoapi-card-head">
                <h3 class="ssoapi-card-title">
                    <iconify-icon icon="solar:tuning-2-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    Kebijakan Keamanan, Whitelist Domain/IP &amp; Konfigurasi WebSocket
                </h3>
                <button type="submit" class="ssoapi-btn ssoapi-btn--primary">
                    <iconify-icon icon="solar:diskette-bold"></iconify-icon>
                    <span>Simpan Kebijakan</span>
                </button>
            </div>

            <input type="hidden" id="ssoClientId" value="0">
            <div class="ssoapi-grid-2" style="margin-bottom:14px;">
                <div class="ssoapi-field">
                    <label for="ssoClientName">Nama Klien Integrasi</label>
                    <input type="text" id="ssoClientName" class="ssoapi-input" required>
                </div>
                <div class="ssoapi-field">
                    <label for="ssoSigMode">Mode Tanda Tangan Kriptografis</label>
                    <select id="ssoSigMode" class="ssoapi-input">
                        <option value="HMAC-SHA256">HMAC-SHA256 (Public Key + Private Key HMAC)</option>
                        <option value="RSA-SHA256">RSA-2048 Asimetris (Public PEM di Learn, Private PEM di SSO)</option>
                        <option value="DUAL">DUAL (Wajib HMAC-SHA256 + RSA-2048 Sekaligus — Proteksi Maksimal)</option>
                    </select>
                </div>
                <div class="ssoapi-field">
                    <label for="ssoAllowedDomains">Whitelist Domain Diizinkan (pisahkan koma)</label>
                    <input type="text" id="ssoAllowedDomains" class="ssoapi-input" placeholder="margo-one.ilmuna.my.id,*.ilmuna.my.id">
                </div>
                <div class="ssoapi-field">
                    <label for="ssoAllowedIps">Whitelist IP Server SSO (opsional, kosong = bebas IP bertanda tangan)</label>
                    <input type="text" id="ssoAllowedIps" class="ssoapi-input" placeholder="Contoh: 103.147.154.xx atau CIDR">
                </div>
                <div class="ssoapi-field">
                    <label for="ssoWsPublicUrl">WebSocket Public URL (untuk browser klien SSO)</label>
                    <input type="text" id="ssoWsPublicUrl" class="ssoapi-input" placeholder="wss://learn.ilmuna.my.id/ws/sso atau ws://127.0.0.1:8090">
                </div>
                <div class="ssoapi-field">
                    <label for="ssoWebhookUrl">Outbound Webhook URL SSO (opsional, backup push selain WebSocket)</label>
                    <input type="text" id="ssoWebhookUrl" class="ssoapi-input" placeholder="https://margo-one.ilmuna.my.id/api/learn-webhook.php">
                </div>
                <div class="ssoapi-field">
                    <label for="ssoWsPort">Port WebSocket &amp; IPC Internal</label>
                    <div style="display:flex;gap:8px;">
                        <input type="number" id="ssoWsPort" class="ssoapi-input" min="1024" max="65535" value="8090" title="WS Port">
                        <input type="number" id="ssoWsIpcPort" class="ssoapi-input" min="1024" max="65535" value="8091" title="IPC Port">
                    </div>
                </div>
                <div class="ssoapi-field">
                    <label for="ssoRateLimit">Rate Limit (maks request / menit / IP)</label>
                    <input type="number" id="ssoRateLimit" class="ssoapi-input" min="10" max="2000" value="240">
                </div>
            </div>

            <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;">
                <label class="ssoapi-switch">
                    <input type="checkbox" id="ssoIsActive" checked>
                    Aktifkan Akses API &amp; WebSocket SSO
                </label>
                <span id="ssoRsaBadge" class="ssoapi-badge ssoapi-badge--navy" style="margin-left:auto;">RSA Public PEM: Belum diatur</span>
            </div>
        </form>
    </section>

    <!-- TAB 2: PREVIEW FEED PER KELAS -->
    <section class="ssoapi-pane" id="ssoapiPane_feed">
        <div class="ssoapi-card">
            <div class="ssoapi-card-head">
                <h3 class="ssoapi-card-title">
                    <iconify-icon icon="solar:buildings-2-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    Simulasi Filter Payload Sesuai Rombel Kelas di SSO
                </h3>
                <div style="display:flex;gap:8px;align-items:center;">
                    <select id="ssoFilterKelasSelect" class="ssoapi-input" style="min-width:220px;" onchange="SSOAPI.load()">
                        <option value="0">— Semua Rombel Kelas —</option>
                    </select>
                </div>
            </div>

            <div class="ssoapi-grid-2">
                <div class="ssoapi-card" style="padding:14px;">
                    <div class="ssoapi-card-head" style="margin-bottom:10px;padding-bottom:8px;">
                        <h4 class="ssoapi-card-title" style="font-size:14px;">
                            <iconify-icon icon="solar:document-text-bold" style="color:#0D7A87;"></iconify-icon>
                            Tugas &amp; Deadline
                        </h4>
                        <span class="ssoapi-badge ssoapi-badge--teal" id="cntFeedTugas">0</span>
                    </div>
                    <div class="ssoapi-feed-list" id="ssoFeedTugas"></div>
                </div>

                <div class="ssoapi-card" style="padding:14px;">
                    <div class="ssoapi-card-head" style="margin-bottom:10px;padding-bottom:8px;">
                        <h4 class="ssoapi-card-title" style="font-size:14px;">
                            <iconify-icon icon="solar:rocket-2-bold" style="color:#D97706;"></iconify-icon>
                            Proyek P5/PjBL &amp; Deadline
                        </h4>
                        <span class="ssoapi-badge ssoapi-badge--warn" id="cntFeedProyek">0</span>
                    </div>
                    <div class="ssoapi-feed-list" id="ssoFeedProyek"></div>
                </div>

                <div class="ssoapi-card" style="padding:14px;">
                    <div class="ssoapi-card-head" style="margin-bottom:10px;padding-bottom:8px;">
                        <h4 class="ssoapi-card-title" style="font-size:14px;">
                            <iconify-icon icon="solar:users-group-rounded-bold" style="color:#6D28D9;"></iconify-icon>
                            Pembagian Kelompok
                        </h4>
                        <span class="ssoapi-badge ssoapi-badge--purple" id="cntFeedKelompok">0</span>
                    </div>
                    <div class="ssoapi-feed-list" id="ssoFeedKelompok"></div>
                </div>

                <div class="ssoapi-card" style="padding:14px;">
                    <div class="ssoapi-card-head" style="margin-bottom:10px;padding-bottom:8px;">
                        <h4 class="ssoapi-card-title" style="font-size:14px;">
                            <iconify-icon icon="solar:alarm-bold" style="color:#B91C1C;"></iconify-icon>
                            Jadwal Asesmen (Bank &amp; Master)
                        </h4>
                        <span class="ssoapi-badge ssoapi-badge--err" id="cntFeedAsesmen">0</span>
                    </div>
                    <div class="ssoapi-feed-list" id="ssoFeedAsesmen"></div>
                </div>
            </div>
        </div>
    </section>

    <!-- TAB 3: WEBSOCKET LIVE CONSOLE, SIMULATOR PUSH, & SDK -->
    <section class="ssoapi-pane" id="ssoapiPane_websocket">
        <div class="ssoapi-card">
            <div class="ssoapi-card-head">
                <h3 class="ssoapi-card-title">
                    <iconify-icon icon="solar:bolt-circle-bold" style="color:#059669;font-size:20px;"></iconify-icon>
                    Uji Live WebSocket Real-Time (Zero Polling / Instant Push)
                </h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="button" class="ssoapi-btn ssoapi-btn--emerald" onclick="SSOAPI.connectLiveWsTest()">
                        <iconify-icon icon="solar:plug-circle-bold"></iconify-icon>
                        <span>Hubungkan Konsol WebSocket</span>
                    </button>
                    <button type="button" class="ssoapi-btn ssoapi-btn--primary" onclick="SSOAPI.pushTestNotif()">
                        <iconify-icon icon="solar:plain-bold"></iconify-icon>
                        <span>Kirim Simulasi Push Notif</span>
                    </button>
                </div>
            </div>

            <div class="ssoapi-grid-2" style="margin-bottom:12px;">
                <div class="ssoapi-field">
                    <label for="simCategory">Kategori Simulasi Event</label>
                    <select id="simCategory" class="ssoapi-input">
                        <option value="tugas">Tugas &amp; Deadline Baru</option>
                        <option value="proyek">Proyek P5/PjBL Baru</option>
                        <option value="kelompok">Pembagian Kelompok Diperbarui</option>
                        <option value="asesmen">Jadwal Asesmen (Bank &amp; Master)</option>
                    </select>
                </div>
                <div class="ssoapi-field">
                    <label for="simTitle">Judul Notifikasi Push</label>
                    <input type="text" id="simTitle" class="ssoapi-input" value="Jadwal Asesmen Sumatif PAI Kelas XI-A">
                </div>
            </div>

            <div class="ssoapi-ws-console" id="ssoWsConsoleLog">
                <div style="color:#94A3B8;">&gt; Klik "Hubungkan Konsol WebSocket" lalu "Kirim Simulasi Push Notif" untuk melihat event mendarat 0ms secara real-time...</div>
            </div>
        </div>

        <div class="ssoapi-card">
            <div class="ssoapi-card-head">
                <h3 class="ssoapi-card-title">
                    <iconify-icon icon="solar:box-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    SDK Integrasi Resmi untuk margo-one.ilmuna.my.id
                </h3>
                <a class="ssoapi-btn ssoapi-btn--primary" href="<?= BASE_URL ?>/dashboard/admin/api-sso?download_sdk=1">
                    <iconify-icon icon="solar:download-square-bold"></iconify-icon>
                    <span>Unduh Margo One SSO Notifier SDK (.zip)</span>
                </a>
            </div>
            <pre class="ssoapi-pre">require_once __DIR__ . '/ilmuna-learn-sso-notifier/IlmunaLearnSsoNotifierClient.php';

$learnClient = new IlmunaLearnSsoNotifierClient([
    'endpoint_url'    => '<?= htmlspecialchars(ilmuna_wp_api_public_base_url() . '/api/sso-broadcast.php', ENT_QUOTES) ?>',
    'public_key'      => 'pk_sso_...',
    'private_key'     => 'sk_sso_...',
    'sig_mode'        => 'HMAC-SHA256', // atau 'RSA-SHA256' / 'DUAL'
]);

$feed = $learnClient->getStudentFeedWithWebSocketTicket([
    'nis'   => $user['nisn_nip'],
    'email' => $user['email'],
    'role'  => $user['active_role'],
]);</pre>
        </div>

        <div class="ssoapi-card">
            <div class="ssoapi-card-head">
                <h3 class="ssoapi-card-title">
                    <iconify-icon icon="solar:history-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    Log Audit &amp; Keamanan API SSO (50 Request Terakhir)
                </h3>
                <button type="button" class="ssoapi-btn ssoapi-btn--ghost" onclick="SSOAPI.load()">
                    <iconify-icon icon="solar:refresh-bold"></iconify-icon> Muat Ulang
                </button>
            </div>
            <div class="table-responsive">
                <table class="lms-table">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Resource</th>
                            <th>Status</th>
                            <th>Domain &amp; IP</th>
                            <th>Algoritma</th>
                            <th>Keterangan</th>
                            <th style="text-align:right;">Durasi</th>
                        </tr>
                    </thead>
                    <tbody id="ssoapiLogsTbody">
                        <tr><td colspan="7" style="text-align:center;padding:24px;color:#64748B;">Belum ada aktivitas API SSO.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<script>
(function () {
    const ENDPOINT = <?= json_encode(BASE_URL . '/dashboard/admin/api-sso?api=1') ?>;
    let STATE = { client: null, showPriv: false, ws: null, classesLoaded: false };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function toast(type, title, msg) {
        const normType = (type === 'danger' || type === 'error') ? 'error' : ((type === 'warn' || type === 'warning') ? 'warning' : (type === 'info' ? 'info' : 'success'));
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            window.Ilmuna.toast(normType, title || 'Informasi', msg || '', 4500);
        }
    }

    async function apiCall(action, payload) {
        const fd = new FormData();
        fd.append('api_action', action);
        if (payload) {
            Object.keys(payload).forEach(function (k) { fd.append(k, payload[k]); });
        }
        const res = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
        return await res.json();
    }

    function wsLogLine(text, color) {
        const box = document.getElementById('ssoWsConsoleLog');
        if (!box) return;
        const div = document.createElement('div');
        div.style.color = color || '#E2E8F0';
        div.textContent = '[' + new Date().toLocaleTimeString('id-ID') + '] ' + text;
        box.appendChild(div);
        box.scrollTop = box.scrollHeight;
    }

    function bindTabs() {
        document.querySelectorAll('#ssoapiMainTabs .curriculum-tab-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const tab = btn.getAttribute('data-tab');
                document.querySelectorAll('#ssoapiMainTabs .curriculum-tab-btn').forEach(function (b) {
                    const active = (b === btn);
                    b.classList.toggle('is-active', active);
                    b.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                document.querySelectorAll('.ssoapi-pane').forEach(function (p) {
                    p.classList.toggle('is-active', p.id === 'ssoapiPane_' + tab);
                });
            });
        });
    }

    function renderClient(data) {
        const c = (data.clients && data.clients[0]) ? data.clients[0] : null;
        STATE.client = c;
        document.getElementById('ssoapiEndpointText').textContent = data.api_endpoint || '';
        if (!c) return;

        document.getElementById('ssoClientId').value = c.id;
        document.getElementById('ssoClientName').value = c.client_name;
        document.getElementById('ssoSigMode').value = c.sig_mode;
        document.getElementById('ssoAllowedDomains').value = c.allowed_domains;
        document.getElementById('ssoAllowedIps').value = c.allowed_ips;
        document.getElementById('ssoWsPublicUrl').value = c.ws_public_url;
        document.getElementById('ssoWsPort').value = c.ws_port;
        document.getElementById('ssoWsIpcPort').value = c.ws_ipc_port;
        document.getElementById('ssoWebhookUrl').value = c.webhook_url || '';
        document.getElementById('ssoRateLimit').value = c.rate_limit_per_min;
        document.getElementById('ssoIsActive').checked = Number(c.is_active) === 1;

        document.getElementById('ssoapiPublicKeyText').textContent = c.public_key;
        document.getElementById('ssoapiPrivateKeyText').textContent = STATE.showPriv ? c.private_key : c.private_key_masked;
        document.getElementById('ssoapiWsUrlText').textContent = c.ws_public_url;
        document.getElementById('cntSsoStatus').textContent = Number(c.is_active) === 1 ? c.sig_mode : 'NONAKTIF';

        const wsBadge = document.getElementById('ssoapiWsBadge');
        const wsLive = c.ws_status && c.ws_status.running;
        wsBadge.className = 'ssoapi-badge ' + (wsLive ? 'ssoapi-badge--ok' : 'ssoapi-badge--warn');
        wsBadge.textContent = wsLive ? ('Daemon Live :' + c.ws_port) : 'Daemon Offline';
        document.getElementById('cntSsoWsState').textContent = wsLive ? 'ONLINE' : 'OFFLINE';

        document.getElementById('ssoRsaBadge').textContent = c.has_rsa_public
            ? ('RSA Public PEM Aktif · ' + c.rsa_fingerprint.slice(0, 12) + '…')
            : 'RSA Public PEM: Belum diatur';
    }

    function renderClasses(classes) {
        if (STATE.classesLoaded) return;
        const sel = document.getElementById('ssoFilterKelasSelect');
        classes.forEach(function (k) {
            const opt = document.createElement('option');
            opt.value = k.id;
            opt.textContent = k.nama_kelas + ' (' + k.jumlah_siswa + ' siswa)';
            sel.appendChild(opt);
        });
        STATE.classesLoaded = true;
    }

    function renderFeed(feed) {
        const tugas    = feed.tugas_broadcast || [];
        const proyek   = feed.proyek_broadcast || [];
        const kelompok = feed.kelompok_broadcast || [];
        const asesmen  = feed.jadwal_asesmen || [];

        const totalAll = tugas.length + proyek.length + kelompok.length + asesmen.length;
        document.getElementById('cntSsoFeedTotal').textContent = totalAll;
        document.getElementById('cntFeedTugas').textContent    = tugas.length;
        document.getElementById('cntFeedProyek').textContent   = proyek.length;
        document.getElementById('cntFeedKelompok').textContent = kelompok.length;
        document.getElementById('cntFeedAsesmen').textContent  = asesmen.length;

        document.getElementById('ssoFeedTugas').innerHTML = tugas.length ? tugas.map(function (t) {
            return '<div style="padding:10px 12px;border:1px solid #E2E8F0;border-radius:11px;background:#F8FAFC;">'
                + '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">'
                + '<strong style="font-size:13px;">' + esc(t.judul_tugas) + '</strong>'
                + '<span class="ssoapi-badge ssoapi-badge--teal">' + esc(t.sisa_waktu_label) + '</span></div>'
                + '<div style="font-size:12px;color:#64748B;">' + esc(t.kelas.nama) + ' · ' + esc(t.mata_pelajaran.nama) + ' · ' + esc(t.jumlah_kelompok) + ' Kelompok · Deadline: ' + esc(t.tenggat_waktu_formatted || '-') + '</div>'
                + '</div>';
        }).join('') : '<div style="padding:16px;text-align:center;color:#64748B;font-size:12.5px;">Tidak ada tugas untuk filter ini.</div>';

        document.getElementById('ssoFeedProyek').innerHTML = proyek.length ? proyek.map(function (p) {
            return '<div style="padding:10px 12px;border:1px solid #E2E8F0;border-radius:11px;background:#F8FAFC;">'
                + '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">'
                + '<strong style="font-size:13px;">' + esc(p.nama_proyek) + '</strong>'
                + '<span class="ssoapi-badge ssoapi-badge--warn">' + esc(p.sisa_waktu_label) + '</span></div>'
                + '<div style="font-size:12px;color:#64748B;">' + esc(p.kelas.nama) + ' · ' + esc(p.mata_pelajaran.nama) + ' · ' + esc(p.jumlah_kelompok) + ' Kelompok · Selesai: ' + esc(p.jadwal_selesai_formatted || '-') + '</div>'
                + '</div>';
        }).join('') : '<div style="padding:16px;text-align:center;color:#64748B;font-size:12.5px;">Tidak ada proyek untuk filter ini.</div>';

        document.getElementById('ssoFeedKelompok').innerHTML = kelompok.length ? kelompok.map(function (g) {
            return '<div style="padding:10px 12px;border:1px solid #E2E8F0;border-radius:11px;background:#F8FAFC;">'
                + '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">'
                + '<strong style="font-size:13px;">' + esc(g.nama_kelompok) + '</strong>'
                + '<span class="ssoapi-badge ssoapi-badge--purple">' + esc(g.sumber.toUpperCase()) + ' · ' + esc(g.jumlah_anggota) + ' Siswa</span></div>'
                + '<div style="font-size:12px;color:#64748B;">' + esc(g.kelas.nama) + ' · ' + esc(g.induk.judul) + '</div>'
                + '</div>';
        }).join('') : '<div style="padding:16px;text-align:center;color:#64748B;font-size:12.5px;">Belum ada pembagian kelompok pada kelas ini.</div>';

        document.getElementById('ssoFeedAsesmen').innerHTML = asesmen.length ? asesmen.map(function (a) {
            return '<div style="padding:10px 12px;border:1px solid #E2E8F0;border-radius:11px;background:#F8FAFC;">'
                + '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">'
                + '<strong style="font-size:13px;">' + esc(a.judul_asesmen) + '</strong>'
                + '<span class="ssoapi-badge ssoapi-badge--err">' + esc(a.sisa_waktu_label) + '</span></div>'
                + '<div style="font-size:12px;color:#64748B;">' + esc(a.kelas.nama) + ' · ' + esc(a.mata_pelajaran.nama) + ' · ' + esc(a.jadwal_formatted) + '</div>'
                + '</div>';
        }).join('') : '<div style="padding:16px;text-align:center;color:#64748B;font-size:12.5px;">Belum ada jadwal asesmen aktif untuk filter ini.</div>';
    }

    function renderLogs(logs) {
        const tb = document.getElementById('ssoapiLogsTbody');
        if (!logs.length) {
            tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:#64748B;">Belum ada riwayat request API SSO.</td></tr>';
            return;
        }
        tb.innerHTML = logs.map(function (l) {
            const ok = Number(l.http_status) === 200;
            return '<tr>'
                + '<td style="font-size:12px;white-space:nowrap;">' + esc(l.created_at) + '</td>'
                + '<td><code>' + esc(l.resource || '-') + '</code></td>'
                + '<td><span class="ssoapi-badge ' + (ok ? 'ssoapi-badge--ok' : 'ssoapi-badge--err') + '">' + esc(l.http_status) + '</span></td>'
                + '<td style="font-size:12px;">' + esc(l.client_site || '-') + '<div style="color:#64748B;">' + esc(l.client_ip) + '</div></td>'
                + '<td style="font-size:12px;">' + esc(l.sig_algorithm || '-') + '</td>'
                + '<td style="font-size:12px;color:' + (ok ? '#15803D' : '#B91C1C') + ';">' + esc(l.error_code || 'OK · Verified') + '</td>'
                + '<td style="text-align:right;font-size:12px;">' + esc(l.duration_ms) + ' ms</td>'
                + '</tr>';
        }).join('');
    }

    window.SSOAPI = {
        load: async function () {
            const kelasId = document.getElementById('ssoFilterKelasSelect') ? document.getElementById('ssoFilterKelasSelect').value : '0';
            const res = await apiCall('bootstrap', { kelas_id: kelasId });
            if (!res.success) {
                toast('error', 'Gagal Memuat', res.message || 'Gagal memuat konfigurasi API SSO.');
                return;
            }
            renderClient(res.data);
            renderClasses(res.data.classes || []);
            renderFeed(res.data.feed || {});
            renderLogs(res.data.audit_logs || []);
        },
        togglePrivate: function () {
            if (!STATE.client) return;
            STATE.showPriv = !STATE.showPriv;
            document.getElementById('ssoapiPrivateKeyText').textContent = STATE.showPriv ? STATE.client.private_key : STATE.client.private_key_masked;
            document.getElementById('btnToggleSsoPriv').innerHTML = STATE.showPriv
                ? '<iconify-icon icon="solar:eye-closed-bold"></iconify-icon> Sembunyikan'
                : '<iconify-icon icon="solar:eye-bold"></iconify-icon> Lihat';
        },
        copyText: function (elId) {
            const txt = (document.getElementById(elId).textContent || '').trim();
            navigator.clipboard.writeText(txt).then(function () { toast('success', 'Disalin', 'Teks berhasil disalin ke clipboard.'); });
        },
        copyPrivate: function () {
            if (!STATE.client) return;
            navigator.clipboard.writeText(STATE.client.private_key).then(function () { toast('success', 'Disalin', 'Private Key SSO disalin. Simpan hanya di server margo-one.ilmuna.my.id!'); });
        },
        copyRsaPem: function () {
            const txt = document.getElementById('ssoapiRsaPemOutput').value;
            navigator.clipboard.writeText(txt).then(function () { toast('success', 'Disalin', 'RSA-2048 Private Key PEM disalin!'); });
        },
        saveClient: async function (ev) {
            ev.preventDefault();
            const res = await apiCall('save_client', {
                id: document.getElementById('ssoClientId').value,
                client_name: document.getElementById('ssoClientName').value,
                sig_mode: document.getElementById('ssoSigMode').value,
                allowed_domains: document.getElementById('ssoAllowedDomains').value,
                allowed_ips: document.getElementById('ssoAllowedIps').value,
                ws_public_url: document.getElementById('ssoWsPublicUrl').value,
                ws_port: document.getElementById('ssoWsPort').value,
                ws_ipc_port: document.getElementById('ssoWsIpcPort').value,
                webhook_url: document.getElementById('ssoWebhookUrl').value,
                rate_limit_per_min: document.getElementById('ssoRateLimit').value,
                is_active: document.getElementById('ssoIsActive').checked ? '1' : '0',
            });
            toast(res.success ? 'success' : 'error', res.success ? 'Tersimpan' : 'Gagal', res.message || '');
            if (res.success) SSOAPI.load();
        },
        rotateKeys: async function () {
            if (!STATE.client) return;
            const ok = (window.Ilmuna && window.Ilmuna.confirm)
                ? await window.Ilmuna.confirm('warning', 'Rotasi Kunci API SSO', 'Kunci lama pada server margo-one.ilmuna.my.id akan langsung tidak berlaku sampai diperbarui.', 'Ya, Rotasi', 'Batal')
                : confirm('Rotasi kunci API SSO?');
            if (!ok) return;
            const res = await apiCall('rotate_keys', { id: STATE.client.id });
            toast(res.success ? 'success' : 'error', 'Rotasi Kunci', res.message);
            if (res.success) {
                STATE.showPriv = true;
                SSOAPI.load();
            }
        },
        generateRsa: async function () {
            if (!STATE.client) return;
            const ok = (window.Ilmuna && window.Ilmuna.confirm)
                ? await window.Ilmuna.confirm('info', 'Generate RSA-2048 PEM', 'Private Key PEM hanya ditampilkan satu kali dan wajib disalin ke server Margo One SSO bila mode RSA-SHA256 / DUAL digunakan.', 'Generate RSA-2048', 'Batal')
                : confirm('Buat RSA-2048 PEM baru?');
            if (!ok) return;
            const res = await apiCall('generate_rsa', { id: STATE.client.id });
            toast(res.success ? 'success' : 'error', 'RSA-2048 PEM', res.message);
            if (res.success && res.data && res.data.private_pem) {
                document.getElementById('ssoapiRsaRevealBox').style.display = 'block';
                document.getElementById('ssoapiRsaPemOutput').value = res.data.private_pem;
                SSOAPI.load();
            }
        },
        startWsDaemon: async function () {
            if (!STATE.client) return;
            const res = await apiCall('start_ws_daemon', { id: STATE.client.id });
            toast(res.success ? 'success' : 'warning', 'WebSocket Daemon', res.message);
            SSOAPI.load();
        },
        testConnection: async function () {
            if (!STATE.client) return;
            const banner = document.getElementById('ssoapiTestBanner');
            banner.style.display = 'block';
            banner.style.background = '#F1F5F9';
            banner.style.color = '#334155';
            banner.textContent = 'Menguji verifikasi Public/Private Key, Anti-Replay Nonce & Feed SSO...';

            const res = await apiCall('test_signed_request', { id: STATE.client.id });
            if (res.success) {
                banner.style.background = '#DCFCE7';
                banner.style.color = '#166534';
                const c = res.data.counts;
                banner.innerHTML = '<strong>VERIFIKASI SUKSES (' + esc(res.data.sig_mode) + ')</strong> — Domain: <code>' + esc(res.data.simulated_site) + '</code> · WebSocket: <strong>' + (res.data.ws_live ? 'ONLINE' : 'OFFLINE') + '</strong> · Payload: <strong>' + c.tugas_broadcast + ' Tugas</strong>, <strong>' + c.proyek_broadcast + ' Proyek</strong>, <strong>' + c.kelompok_broadcast + ' Kelompok</strong>, <strong>' + c.jadwal_asesmen + ' Jadwal Asesmen</strong>.';
                toast('success', 'REST API Valid', res.message);
                SSOAPI.load();
            } else {
                banner.style.background = '#FEE2E2';
                banner.style.color = '#991B1B';
                banner.textContent = res.message || 'Uji koneksi gagal.';
                toast('error', 'Verifikasi Gagal', res.message || '');
            }
        },
        connectLiveWsTest: async function () {
            if (!STATE.client) return;
            const kelasId = document.getElementById('ssoFilterKelasSelect') ? document.getElementById('ssoFilterKelasSelect').value : '0';
            wsLogLine('Meminta Single-Use Ephemeral WebSocket Ticket (wst_*)...', '#FDE68A');
            const res = await apiCall('issue_demo_ws_ticket', { id: STATE.client.id, kelas_id: kelasId });
            if (!res.success || !res.data || !res.data.ws_connect_url) {
                wsLogLine('Gagal menerbitkan tiket WebSocket: ' + (res.message || 'unknown'), '#FCA5A5');
                return;
            }
            if (STATE.ws) {
                try { STATE.ws.close(); } catch (e) {}
            }
            wsLogLine('Membuka koneksi WebSocket ke ' + res.data.ws_url + ' ...', '#93C5FD');
            const ws = new WebSocket(res.data.ws_connect_url);
            STATE.ws = ws;

            ws.onopen = function () {
                wsLogLine('CONNECTED! Handshake RFC 6455 sukses tanpa polling.', '#6EE7B7');
            };
            ws.onmessage = function (ev) {
                try {
                    const obj = JSON.parse(ev.data);
                    if (obj.type === 'ilmuna.realtime.welcome') {
                        wsLogLine('WELCOME: Snapshot diterima (' + (obj.snapshot && obj.snapshot.counts ? JSON.stringify(obj.snapshot.counts) : 'ready') + ')', '#6EE7B7');
                    } else if (obj.type === 'ilmuna.realtime.event') {
                        wsLogLine('PUSH [' + obj.event_type + '] Kelas=' + (obj.kelas ? obj.kelas.nama : '-') + ' -> ' + obj.title + ' | ' + (obj.summary || ''), '#FDE047');
                        toast('info', obj.title, obj.summary || 'Notifikasi Real-Time Masuk');
                    } else {
                        wsLogLine('MSG: ' + ev.data, '#E2E8F0');
                    }
                } catch (err) {
                    wsLogLine('RAW: ' + ev.data, '#E2E8F0');
                }
            };
            ws.onerror = function () {
                wsLogLine('ERROR WebSocket — pastikan Daemon WebSocket aktif pada Tab 1 (klik Aktifkan WS).', '#FCA5A5');
            };
            ws.onclose = function (ce) {
                wsLogLine('CLOSED (code ' + ce.code + ')', '#94A3B8');
            };
        },
        pushTestNotif: async function () {
            const kelasId  = document.getElementById('ssoFilterKelasSelect') ? document.getElementById('ssoFilterKelasSelect').value : '0';
            const category = document.getElementById('simCategory').value;
            const title    = document.getElementById('simTitle').value.trim();
            const res = await apiCall('push_test_notification', { kelas_id: kelasId, category: category, title: title });
            toast(res.success ? 'success' : 'error', 'Simulasi Push Notifikasi', res.message);
        }
    };

    bindTabs();
    SSOAPI.load();
})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
