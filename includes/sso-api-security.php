<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — MARGO ONE SSO SECURE API & WEBSOCKET CRYPTO ENGINE
 * ==============================================================================
 * Layer Keamanan Zero-Trust Maksimal (learn.ilmuna.my.id <-> margo-one.ilmuna.my.id)
 *
 * Proteksi Berlapis:
 *   1. Dual-Key Authentication:
 *      - Public Key  (pk_sso_*) sebagai identitas klien terpublikasi
 *      - Private Key (sk_sso_*, 256-bit) TIDAK PERNAH dikirim lewat jaringan,
 *        disimpan terenkripsi AES-256-CBC + HMAC-SHA256 (Encrypt-then-MAC)
 *   2. Cryptographic Request Signing (3 Mode):
 *      - HMAC-SHA256 : Tanda tangan kanonik berbasis Private Key (constant-time hash_equals)
 *      - RSA-SHA256  : Tanda tangan asimetris RSA-2048 PEM (server hanya menyimpan Public PEM)
 *      - DUAL        : Proteksi tertinggi — mewajibkan HMAC-SHA256 DAN RSA-2048 sekaligus
 *   3. Anti-Replay Protection:
 *      - Jendela waktu ketat +/- 300 detik (X-Ilmuna-Timestamp)
 *      - Single-use Nonce tersimpan ter-hash di sso_api_nonces (X-Ilmuna-Nonce)
 *   4. Domain, Origin/Referer & IP/CIDR Allowlist
 *   5. Sliding-Window Rate Limiter per Public Key + IP (HTTP 429)
 *   6. Mutual Signed JSON Response (X-Ilmuna-Response-Signature)
 *   7. Ephemeral Single-Use WebSocket Ticket (wst_*) untuk koneksi browser real-time
 *      tanpa pernah mengekspos Private Key di sisi frontend browser SSO.
 *
 * 100% kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3+.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

if (!defined('ILMUNA_SSO_API_VERSION')) {
    define('ILMUNA_SSO_API_VERSION', '1.0.0');
}
if (!defined('ILMUNA_SSO_API_MAX_SKEW')) {
    define('ILMUNA_SSO_API_MAX_SKEW', 300); // +/- 5 menit
}
if (!defined('ILMUNA_SSO_WS_DEFAULT_PORT')) {
    define('ILMUNA_SSO_WS_DEFAULT_PORT', 8090);
}
if (!defined('ILMUNA_SSO_WS_DEFAULT_IPC_PORT')) {
    define('ILMUNA_SSO_WS_DEFAULT_IPC_PORT', 8091);
}

/**
 * Kunci master internal untuk enkripsi at-rest Private Key di database
 */
function ilmuna_sso_api_master_key()
{
    $seedParts = [
        defined('DB_PASS') ? DB_PASS : 'ilmuna_local_pass',
        defined('DB_NAME') ? DB_NAME : 'ilmuna_db',
        defined('APP_DOMAIN') ? APP_DOMAIN : 'learn.ilmuna.my.id',
        'ILMUNA_SSO_API_ENC_V1_ZERO_TRUST_2026',
    ];
    return hash('sha256', implode('|', $seedParts), true);
}

/**
 * Kunci HMAC khusus kanal IPC loopback (127.0.0.1) antara PHP FPM/Apache <-> Daemon WebSocket
 */
function ilmuna_sso_ipc_secret()
{
    return hash_hmac('sha256', 'ILMUNA_SSO_LOCAL_WS_IPC_CHANNEL_V1', ilmuna_sso_api_master_key());
}

/**
 * Enkripsi AES-256-CBC + HMAC-SHA256 (Encrypt-then-MAC)
 */
function ilmuna_sso_api_encrypt_secret($plaintext)
{
    $plaintext = (string)$plaintext;
    $masterKey = ilmuna_sso_api_master_key();
    $encKey = hash_hmac('sha256', 'sso_enc', $masterKey, true);
    $macKey = hash_hmac('sha256', 'sso_mac', $masterKey, true);

    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        throw new RuntimeException('Gagal mengenkripsi Private Key API SSO menggunakan OpenSSL.');
    }
    $mac = hash_hmac('sha256', $iv . $cipher, $macKey, true);
    return 'v1:' . base64_encode($iv . $mac . $cipher);
}

/**
 * Dekripsi AES-256-CBC + verifikasi HMAC-SHA256 constant-time
 */
function ilmuna_sso_api_decrypt_secret($encoded)
{
    $encoded = (string)$encoded;
    if (strpos($encoded, 'v1:') !== 0) {
        return '';
    }
    $raw = base64_decode(substr($encoded, 3), true);
    if ($raw === false || strlen($raw) <= 48) {
        return '';
    }

    $masterKey = ilmuna_sso_api_master_key();
    $encKey = hash_hmac('sha256', 'sso_enc', $masterKey, true);
    $macKey = hash_hmac('sha256', 'sso_mac', $masterKey, true);

    $iv = substr($raw, 0, 16);
    $mac = substr($raw, 16, 32);
    $cipher = substr($raw, 48);

    $expectedMac = hash_hmac('sha256', $iv . $cipher, $macKey, true);
    if (!hash_equals($expectedMac, $mac)) {
        return '';
    }

    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
    return ($plain === false) ? '' : $plain;
}

/**
 * Generate pasangan Public Key & Private Key baru untuk SSO
 */
function ilmuna_sso_api_generate_keypair()
{
    return [
        'public_key'  => 'pk_sso_' . bin2hex(random_bytes(16)), // 39 chars
        'private_key' => 'sk_sso_' . bin2hex(random_bytes(32)), // 71 chars (256-bit entropy)
    ];
}

/**
 * Cari lokasi openssl.cnf lintas OS (Windows Laragon & Linux cPanel/VPS)
 */
function ilmuna_sso_api_find_openssl_cnf()
{
    $candidates = [
        getenv('OPENSSL_CONF') ?: '',
        'C:/laragon/bin/php/php-8.3.36-Win32-vs16-x64/extras/ssl/openssl.cnf',
        'C:/laragon/bin/apache/httpd-2.4.54-win64-VS16/conf/openssl.cnf',
        'C:/laragon/etc/ssl/openssl.cnf',
        '/etc/ssl/openssl.cnf',
        '/usr/lib/ssl/openssl.cnf',
        '/etc/pki/tls/openssl.cnf',
    ];
    foreach ($candidates as $path) {
        if ($path !== '' && @file_exists($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * Generate pasangan kunci asimetris RSA-2048 PEM (Public PEM disimpan di Learn, Private PEM diberikan 1x ke SSO)
 */
function ilmuna_sso_api_generate_rsa_pair()
{
    if (!extension_loaded('openssl')) {
        return ['success' => false, 'message' => 'Ekstensi OpenSSL tidak tersedia di PHP.'];
    }
    $cfg = [
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];
    $cnfPath = ilmuna_sso_api_find_openssl_cnf();
    if ($cnfPath !== null) {
        $cfg['config'] = $cnfPath;
    }

    $res = @openssl_pkey_new($cfg);
    if ($res === false) {
        return ['success' => false, 'message' => 'OpenSSL gagal membangkitkan pasangan RSA-2048: ' . (openssl_error_string() ?: 'unknown error')];
    }

    $privatePem = '';
    $exported = ($cnfPath !== null)
        ? @openssl_pkey_export($res, $privatePem, null, ['config' => $cnfPath])
        : @openssl_pkey_export($res, $privatePem);
    if (!$exported || empty($privatePem)) {
        return ['success' => false, 'message' => 'Gagal mengekspor RSA Private Key PEM.'];
    }

    $details = @openssl_pkey_get_details($res);
    $publicPem = (is_array($details) && !empty($details['key'])) ? $details['key'] : '';
    if (empty($publicPem)) {
        return ['success' => false, 'message' => 'Gagal mengekstrak RSA Public Key PEM.'];
    }

    return [
        'success'     => true,
        'public_pem'  => trim($publicPem),
        'private_pem' => trim($privatePem),
        'fingerprint' => hash('sha256', trim($publicPem)),
    ];
}

/**
 * Deteksi URL WebSocket publik default sesuai lingkungan (lokal vs produksi)
 */
function ilmuna_sso_default_ws_url($port = 8090)
{
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower(trim((string)$_SERVER['HTTP_HOST'])) : '';
    $cleanHost = preg_replace('/:\d+$/', '', $host);
    if ($cleanHost === '' || $cleanHost === 'localhost' || $cleanHost === '127.0.0.1') {
        return 'ws://127.0.0.1:' . (int)$port;
    }
    return 'wss://' . $cleanHost . '/ws/sso';
}

/**
 * Auto-migrasi tabel keamanan REST API & WebSocket Real-Time SSO + seed klien Margo One SSO
 */
function ilmuna_sso_api_ensure_schema(PDO $pdo)
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_api_clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_name VARCHAR(120) NOT NULL,
            public_key VARCHAR(120) NOT NULL UNIQUE,
            private_key_enc TEXT NOT NULL,
            private_key_hash CHAR(64) NOT NULL,
            rsa_public_pem TEXT NULL,
            sig_mode ENUM('HMAC-SHA256','RSA-SHA256','DUAL') NOT NULL DEFAULT 'HMAC-SHA256',
            allowed_domains TEXT NOT NULL,
            allowed_ips TEXT NULL,
            allowed_scopes VARCHAR(500) NOT NULL DEFAULT 'health,tugas_broadcast,proyek_broadcast,kelompok_broadcast,jadwal_asesmen,student_feed,ws_ticket,all',
            rate_limit_per_min INT NOT NULL DEFAULT 240,
            ws_public_url VARCHAR(255) NULL,
            ws_port INT NOT NULL DEFAULT 8090,
            ws_ipc_port INT NOT NULL DEFAULT 8091,
            webhook_url VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_used_at DATETIME NULL,
            last_ip VARCHAR(64) NULL,
            request_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sso_api_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_api_nonces (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            public_key VARCHAR(120) NOT NULL,
            nonce_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sso_api_pk_nonce (public_key, nonce_hash),
            INDEX idx_sso_api_nonce_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_api_rate_limits (
            bucket_key CHAR(64) PRIMARY KEY,
            hits INT NOT NULL DEFAULT 1,
            expires_at DATETIME NOT NULL,
            INDEX idx_sso_api_rl_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_api_audit_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NULL,
            public_key VARCHAR(120) NULL,
            endpoint VARCHAR(120) NOT NULL,
            resource VARCHAR(60) NULL,
            http_status SMALLINT NOT NULL,
            client_ip VARCHAR(64) NOT NULL,
            client_site VARCHAR(190) NULL,
            sig_algorithm VARCHAR(30) NULL,
            error_code VARCHAR(60) NULL,
            duration_ms INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sso_api_log_created (created_at),
            INDEX idx_sso_api_log_status (http_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_ws_tickets (
            ticket_hash CHAR(64) PRIMARY KEY,
            client_id INT NOT NULL,
            kelas_ids_csv VARCHAR(255) NOT NULL DEFAULT '',
            siswa_id INT NULL,
            guru_id INT NULL,
            role_code VARCHAR(40) NOT NULL DEFAULT 'siswa',
            user_identifier VARCHAR(150) NULL,
            subscribe_all TINYINT(1) NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sso_wst_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_realtime_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_uuid VARCHAR(64) NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            category VARCHAR(30) NOT NULL,
            kelas_id INT NULL,
            nama_kelas VARCHAR(100) NULL,
            kode_kelas VARCHAR(50) NULL,
            reference_id INT NULL,
            title VARCHAR(255) NOT NULL,
            summary TEXT NULL,
            deadline_at DATETIME NULL,
            urgency VARCHAR(20) NOT NULL DEFAULT 'normal',
            portal_url VARCHAR(255) NULL,
            payload_json LONGTEXT NOT NULL,
            signature_hmac CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sso_rt_kelas_id (kelas_id, id),
            INDEX idx_sso_rt_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM sso_api_clients")->fetchColumn();
    if ($cnt === 0) {
        $pair = ilmuna_sso_api_generate_keypair();
        $encPrivate  = ilmuna_sso_api_encrypt_secret($pair['private_key']);
        $hashPrivate = hash('sha256', $pair['private_key']);

        $stmt = $pdo->prepare("
            INSERT INTO sso_api_clients
            (client_name, public_key, private_key_enc, private_key_hash, sig_mode, allowed_domains, allowed_ips, allowed_scopes, rate_limit_per_min, ws_public_url, ws_port, ws_ipc_port, is_active)
            VALUES (?, ?, ?, ?, 'HMAC-SHA256', ?, '', ?, 240, ?, ?, ?, 1)
        ");
        $stmt->execute([
            'Margo One SSO (margo-one.ilmuna.my.id)',
            $pair['public_key'],
            $encPrivate,
            $hashPrivate,
            'margo-one.ilmuna.my.id,*.ilmuna.my.id,ilmuna.my.id,learn.ilmuna.my.id',
            'health,tugas_broadcast,proyek_broadcast,kelompok_broadcast,jadwal_asesmen,student_feed,ws_ticket,all',
            ilmuna_sso_default_ws_url(ILMUNA_SSO_WS_DEFAULT_PORT),
            ILMUNA_SSO_WS_DEFAULT_PORT,
            ILMUNA_SSO_WS_DEFAULT_IPC_PORT,
        ]);
    }

    $ensured = true;
}

/**
 * Ambil seluruh header HTTP lowercase secara portabel
 */
function ilmuna_sso_api_get_headers(array $overrideHeaders = null)
{
    $headers = [];
    if (is_array($overrideHeaders)) {
        foreach ($overrideHeaders as $k => $v) {
            $headers[strtolower(trim((string)$k))] = trim((string)$v);
        }
        return $headers;
    }

    if (function_exists('getallheaders')) {
        $all = @getallheaders();
        if (is_array($all)) {
            foreach ($all as $k => $v) {
                $headers[strtolower(trim((string)$k))] = trim((string)$v);
            }
        }
    }

    foreach ($_SERVER as $key => $val) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            if (!isset($headers[$name])) {
                $headers[$name] = trim((string)$val);
            }
        }
    }

    return $headers;
}

/**
 * Resolusi IP klien aman
 */
function ilmuna_sso_api_client_ip()
{
    $candidates = [];
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = $_SERVER['REMOTE_ADDR'];
    }
    foreach ($candidates as $ip) {
        $ip = trim((string)$ip);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * Normalisasi path kanonik endpoint API SSO
 */
function ilmuna_sso_api_canonical_path($requestUri)
{
    $path = parse_url((string)$requestUri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = '/api/sso-broadcast';
    }
    $base = basename($path);
    $base = preg_replace('/\.php$/i', '', $base);
    if ($base === '' || $base === '/' || $base === '.') {
        $base = 'sso-broadcast';
    }
    return '/api/' . strtolower($base);
}

/**
 * Normalisasi parameter query string terurut sesuai RFC3986
 */
function ilmuna_sso_api_canonical_query(array $queryParams)
{
    $normalized = [];
    foreach ($queryParams as $k => $v) {
        if (is_array($v)) {
            $normalized[(string)$k] = implode(',', array_map('strval', $v));
        } else {
            $normalized[(string)$k] = (string)$v;
        }
    }
    ksort($normalized, SORT_STRING);
    return http_build_query($normalized, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Canonical String yang ditandatangani klien & diverifikasi server:
 * METHOD\n/api/sso-broadcast\ncanonical_query\ntimestamp\nnonce\nsha256(raw_body)
 */
function ilmuna_sso_api_canonical_string($method, $canonicalPath, array $queryParams, $timestamp, $nonce, $rawBody = '')
{
    $method = strtoupper(trim((string)$method));
    $cPath  = ilmuna_sso_api_canonical_path($canonicalPath);
    $cQuery = ilmuna_sso_api_canonical_query($queryParams);
    $bodyHash = hash('sha256', (string)$rawBody);

    return implode("\n", [
        $method,
        $cPath,
        $cQuery,
        (string)$timestamp,
        (string)$nonce,
        $bodyHash,
    ]);
}

function ilmuna_sso_api_sign_hmac($canonicalString, $privateKey)
{
    return hash_hmac('sha256', (string)$canonicalString, (string)$privateKey);
}

function ilmuna_sso_api_sign_rsa($canonicalString, $rsaPrivatePem)
{
    $sig = '';
    $ok = @openssl_sign((string)$canonicalString, $sig, (string)$rsaPrivatePem, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        return '';
    }
    return base64_encode($sig);
}

function ilmuna_sso_api_sign_response($responseTimestamp, $jsonBody, $privateKey)
{
    return hash_hmac('sha256', (string)$responseTimestamp . "\n" . (string)$jsonBody, (string)$privateKey);
}

function ilmuna_sso_api_extract_host($value)
{
    $value = trim(strtolower((string)$value));
    if ($value === '') {
        return '';
    }
    if (strpos($value, '://') === false) {
        $value = 'https://' . $value;
    }
    $host = parse_url($value, PHP_URL_HOST);
    return is_string($host) ? strtolower(trim($host)) : '';
}

function ilmuna_sso_api_parse_list($raw)
{
    $parts = preg_split('/[\s,;]+/', (string)$raw);
    $out = [];
    if (is_array($parts)) {
        foreach ($parts as $p) {
            $p = strtolower(trim($p));
            if ($p !== '') {
                $out[] = $p;
            }
        }
    }
    return array_values(array_unique($out));
}

function ilmuna_sso_api_domain_allowed($host, array $allowedDomains)
{
    $host = strtolower(trim((string)$host));
    if ($host === '') {
        return false;
    }
    $serverHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $isLocalServer = (
        php_sapi_name() === 'cli'
        || strpos($serverHost, 'localhost') !== false
        || strpos($serverHost, '127.0.0.1') !== false
        || strpos($serverHost, '.test') !== false
    );
    if ($isLocalServer && in_array($host, ['localhost', '127.0.0.1'], true)) {
        return true;
    }

    foreach ($allowedDomains as $pattern) {
        $pattern = strtolower(trim($pattern));
        if ($pattern === '') {
            continue;
        }
        if ($pattern === $host) {
            return true;
        }
        if (strpos($pattern, '*.') === 0) {
            $suffix = substr($pattern, 1);
            $len = strlen($suffix);
            if ($len > 0 && strlen($host) > $len && substr($host, -$len) === $suffix) {
                return true;
            }
        }
    }
    return false;
}

function ilmuna_sso_api_ip_allowed($clientIp, array $allowedIps)
{
    if (empty($allowedIps)) {
        return true;
    }
    if ($clientIp === '127.0.0.1' || $clientIp === '::1') {
        $serverHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if (php_sapi_name() === 'cli' || strpos($serverHost, 'localhost') !== false || strpos($serverHost, '127.0.0.1') !== false) {
            return true;
        }
    }
    foreach ($allowedIps as $rule) {
        $rule = trim($rule);
        if ($rule === '') {
            continue;
        }
        if ($rule === $clientIp) {
            return true;
        }
        if (strpos($rule, '/') !== false && filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            list($subnet, $mask) = explode('/', $rule, 2);
            $maskInt = (int)$mask;
            if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $maskInt >= 0 && $maskInt <= 32) {
                $ipLong = ip2long($clientIp);
                $subLong = ip2long($subnet);
                $maskLong = ($maskInt === 0) ? 0 : (~((1 << (32 - $maskInt)) - 1));
                if (($ipLong & $maskLong) === ($subLong & $maskLong)) {
                    return true;
                }
            }
        }
    }
    return false;
}

function ilmuna_sso_api_check_rate_limit(PDO $pdo, $publicKey, $clientIp, $limitPerMin)
{
    $limitPerMin = max(10, (int)$limitPerMin);
    $minuteWindow = (int)floor(time() / 60);
    $bucketKey = hash('sha256', $publicKey . '|' . $clientIp . '|' . $minuteWindow);
    $expiresAt = date('Y-m-d H:i:s', ($minuteWindow + 2) * 60);

    if (mt_rand(1, 20) === 1) {
        $pdo->exec("DELETE FROM sso_api_rate_limits WHERE expires_at < NOW()");
    }

    $stmt = $pdo->prepare("
        INSERT INTO sso_api_rate_limits (bucket_key, hits, expires_at)
        VALUES (?, 1, ?)
        ON DUPLICATE KEY UPDATE hits = hits + 1
    ");
    $stmt->execute([$bucketKey, $expiresAt]);

    $sel = $pdo->prepare("SELECT hits FROM sso_api_rate_limits WHERE bucket_key = ?");
    $sel->execute([$bucketKey]);
    $hits = (int)$sel->fetchColumn();

    return [
        'allowed'   => ($hits <= $limitPerMin),
        'limit'     => $limitPerMin,
        'hits'      => $hits,
        'remaining' => max(0, $limitPerMin - $hits),
        'reset_in'  => 60 - (time() % 60),
    ];
}

function ilmuna_sso_api_log_audit(PDO $pdo, array $entry)
{
    try {
        if (mt_rand(1, 30) === 1) {
            $pdo->exec("DELETE FROM sso_api_audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        }
        $stmt = $pdo->prepare("
            INSERT INTO sso_api_audit_logs
            (client_id, public_key, endpoint, resource, http_status, client_ip, client_site, sig_algorithm, error_code, duration_ms)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            isset($entry['client_id']) ? (int)$entry['client_id'] : null,
            isset($entry['public_key']) ? substr((string)$entry['public_key'], 0, 120) : null,
            substr((string)($entry['endpoint'] ?? '/api/sso-broadcast'), 0, 120),
            substr((string)($entry['resource'] ?? ''), 0, 60),
            (int)($entry['http_status'] ?? 200),
            substr((string)($entry['client_ip'] ?? '0.0.0.0'), 0, 64),
            isset($entry['client_site']) ? substr((string)$entry['client_site'], 0, 190) : null,
            isset($entry['sig_algorithm']) ? substr((string)$entry['sig_algorithm'], 0, 30) : null,
            isset($entry['error_code']) ? substr((string)$entry['error_code'], 0, 60) : null,
            (int)($entry['duration_ms'] ?? 0),
        ]);
    } catch (Throwable $e) {
        // Non-blocking audit log
    }
}

/**
 * Verifikasi autentikasi Zero-Trust penuh untuk REST API maupun Server-to-Server WebSocket Handshake
 */
function ilmuna_sso_api_authenticate(PDO $pdo, array $opts = [])
{
    ilmuna_sso_api_ensure_schema($pdo);

    $method     = strtoupper((string)($opts['method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
    $requestUri = (string)($opts['uri'] ?? ($_SERVER['REQUEST_URI'] ?? '/api/sso-broadcast'));
    $query      = isset($opts['query']) && is_array($opts['query']) ? $opts['query'] : $_GET;
    $rawBody    = isset($opts['body']) ? (string)$opts['body'] : '';
    $headers    = ilmuna_sso_api_get_headers(isset($opts['headers']) ? $opts['headers'] : null);
    $clientIp   = (string)($opts['client_ip'] ?? ilmuna_sso_api_client_ip());
    $resource   = strtolower(trim((string)($query['resource'] ?? 'all')));

    $publicKey  = isset($headers['x-ilmuna-public-key']) ? trim($headers['x-ilmuna-public-key']) : '';
    $timestamp  = isset($headers['x-ilmuna-timestamp']) ? trim($headers['x-ilmuna-timestamp']) : '';
    $nonce      = isset($headers['x-ilmuna-nonce']) ? trim($headers['x-ilmuna-nonce']) : '';
    $signature  = isset($headers['x-ilmuna-signature']) ? trim($headers['x-ilmuna-signature']) : '';
    $rsaSig     = isset($headers['x-ilmuna-rsa-signature']) ? trim($headers['x-ilmuna-rsa-signature']) : '';
    $siteHeader = isset($headers['x-ilmuna-site']) ? trim($headers['x-ilmuna-site']) : '';
    $originHdr  = isset($headers['origin']) ? trim($headers['origin']) : '';
    $refererHdr = isset($headers['referer']) ? trim($headers['referer']) : '';

    if ($publicKey === '' || $timestamp === '' || $nonce === '') {
        return [
            'ok'      => false,
            'status'  => 401,
            'code'    => 'MISSING_AUTH_HEADERS',
            'message' => 'Header autentikasi wajib (X-Ilmuna-Public-Key, X-Ilmuna-Timestamp, X-Ilmuna-Nonce) tidak lengkap.',
            'meta'    => ['public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    if (!ctype_digit($timestamp)) {
        return [
            'ok'      => false,
            'status'  => 401,
            'code'    => 'INVALID_TIMESTAMP',
            'message' => 'Header X-Ilmuna-Timestamp wajib berupa Unix timestamp angka bulat.',
            'meta'    => ['public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    $tsInt = (int)$timestamp;
    $now = time();
    if (abs($now - $tsInt) > ILMUNA_SSO_API_MAX_SKEW) {
        return [
            'ok'      => false,
            'status'  => 401,
            'code'    => 'TIMESTAMP_EXPIRED',
            'message' => 'Timestamp request kedaluwarsa atau selisih waktu server melebihi ' . ILMUNA_SSO_API_MAX_SKEW . ' detik.',
            'meta'    => ['public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader, 'server_time' => $now],
        ];
    }

    if (!preg_match('/^[a-zA-Z0-9_\-]{16,128}$/', $nonce)) {
        return [
            'ok'      => false,
            'status'  => 401,
            'code'    => 'INVALID_NONCE_FORMAT',
            'message' => 'Header X-Ilmuna-Nonce wajib berupa string acak minimal 16 karakter alfanumerik.',
            'meta'    => ['public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    $stmt = $pdo->prepare("SELECT * FROM sso_api_clients WHERE public_key = ? LIMIT 1");
    $stmt->execute([$publicKey]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        return [
            'ok'      => false,
            'status'  => 401,
            'code'    => 'INVALID_PUBLIC_KEY',
            'message' => 'Public Key API SSO tidak dikenali oleh server Ilmuna Learn.',
            'meta'    => ['public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    if ((int)$client['is_active'] !== 1) {
        return [
            'ok'      => false,
            'status'  => 403,
            'code'    => 'CLIENT_DISABLED',
            'message' => 'Kunci API klien SSO sedang dinonaktifkan oleh Administrator.',
            'meta'    => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    $rl = ilmuna_sso_api_check_rate_limit($pdo, $publicKey, $clientIp, (int)$client['rate_limit_per_min']);
    if (!$rl['allowed']) {
        return [
            'ok'         => false,
            'status'     => 429,
            'code'       => 'RATE_LIMIT_EXCEEDED',
            'message'    => 'Batas request API (' . $rl['limit'] . '/menit) terlampaui. Coba lagi dalam ' . $rl['reset_in'] . ' detik.',
            'rate_limit' => $rl,
            'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    $allowedIps = ilmuna_sso_api_parse_list($client['allowed_ips']);
    if (!empty($allowedIps) && !ilmuna_sso_api_ip_allowed($clientIp, $allowedIps)) {
        return [
            'ok'         => false,
            'status'     => 403,
            'code'       => 'IP_NOT_ALLOWED',
            'message'    => 'Alamat IP pengirim (' . $clientIp . ') tidak terdaftar pada whitelist IP API SSO.',
            'rate_limit' => $rl,
            'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    $allowedDomains = ilmuna_sso_api_parse_list($client['allowed_domains']);
    if (!empty($allowedDomains)) {
        if ($siteHeader !== '') {
            $siteHost = ilmuna_sso_api_extract_host($siteHeader);
            if (!ilmuna_sso_api_domain_allowed($siteHost, $allowedDomains)) {
                return [
                    'ok'         => false,
                    'status'     => 403,
                    'code'       => 'DOMAIN_NOT_ALLOWED',
                    'message'    => 'Domain klien (' . $siteHost . ') tidak diizinkan mengakses API SSO ini.',
                    'rate_limit' => $rl,
                    'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHost],
                ];
            }
        }
        if ($originHdr !== '') {
            $originHost = ilmuna_sso_api_extract_host($originHdr);
            if (!ilmuna_sso_api_domain_allowed($originHost, $allowedDomains)) {
                return [
                    'ok'         => false,
                    'status'     => 403,
                    'code'       => 'ORIGIN_NOT_ALLOWED',
                    'message'    => 'Origin (' . $originHost . ') ditolak oleh kebijakan keamanan API SSO.',
                    'rate_limit' => $rl,
                    'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $originHost],
                ];
            }
        }
        if ($refererHdr !== '') {
            $refHost = ilmuna_sso_api_extract_host($refererHdr);
            if ($refHost !== '' && !ilmuna_sso_api_domain_allowed($refHost, $allowedDomains)) {
                return [
                    'ok'         => false,
                    'status'     => 403,
                    'code'       => 'REFERER_NOT_ALLOWED',
                    'message'    => 'Referer (' . $refHost . ') tidak masuk dalam domain whitelist.',
                    'rate_limit' => $rl,
                    'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $refHost],
                ];
            }
        }
    }

    $allowedScopes = ilmuna_sso_api_parse_list($client['allowed_scopes']);
    $scopeAliasMap = [
        'tugas'     => 'tugas_broadcast',
        'proyek'    => 'proyek_broadcast',
        'kelompok'  => 'kelompok_broadcast',
        'asesmen'   => 'jadwal_asesmen',
        'jadwal'    => 'jadwal_asesmen',
        'feed'      => 'all',
        'broadcast' => 'all',
        'sso_feed'  => 'student_feed',
        'ticket'    => 'ws_ticket',
        'classes'   => 'all',
        'events'    => 'all',
    ];
    $canonicalScope = isset($scopeAliasMap[$resource]) ? $scopeAliasMap[$resource] : $resource;
    if (!empty($allowedScopes) && !in_array('all', $allowedScopes, true) && !in_array($canonicalScope, $allowedScopes, true)) {
        return [
            'ok'         => false,
            'status'     => 403,
            'code'       => 'SCOPE_FORBIDDEN',
            'message'    => 'Kunci API tidak memiliki hak akses ke scope "' . $canonicalScope . '".',
            'rate_limit' => $rl,
            'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    $privateKey = ilmuna_sso_api_decrypt_secret($client['private_key_enc']);
    if ($privateKey === '') {
        return [
            'ok'         => false,
            'status'     => 500,
            'code'       => 'SERVER_KEY_ERROR',
            'message'    => 'Gagal mendekripsi Private Key internal server. Silakan lakukan Rotasi Kunci di panel Admin.',
            'rate_limit' => $rl,
            'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    $sigMode   = (string)$client['sig_mode'];
    $canonical = ilmuna_sso_api_canonical_string($method, $requestUri, $query, $timestamp, $nonce, $rawBody);

    if ($sigMode === 'HMAC-SHA256' || $sigMode === 'DUAL') {
        if ($signature === '') {
            return [
                'ok'         => false,
                'status'     => 401,
                'code'       => 'MISSING_HMAC_SIGNATURE',
                'message'    => 'Header X-Ilmuna-Signature (HMAC-SHA256) wajib disertakan.',
                'rate_limit' => $rl,
                'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader, 'sig_algorithm' => $sigMode],
            ];
        }
        $expectedHmac = ilmuna_sso_api_sign_hmac($canonical, $privateKey);
        if (!hash_equals($expectedHmac, strtolower($signature))) {
            return [
                'ok'         => false,
                'status'     => 401,
                'code'       => 'INVALID_HMAC_SIGNATURE',
                'message'    => 'Tanda tangan kriptografis HMAC-SHA256 tidak cocok.',
                'rate_limit' => $rl,
                'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader, 'sig_algorithm' => $sigMode],
            ];
        }
    }

    if ($sigMode === 'RSA-SHA256' || $sigMode === 'DUAL') {
        $rsaPublicPem = trim((string)($client['rsa_public_pem'] ?? ''));
        if ($rsaPublicPem === '') {
            return [
                'ok'         => false,
                'status'     => 500,
                'code'       => 'RSA_PUBLIC_KEY_NOT_CONFIGURED',
                'message'    => 'Mode ' . $sigMode . ' aktif tetapi RSA-2048 Public PEM belum dibangkitkan di panel Admin.',
                'rate_limit' => $rl,
                'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader, 'sig_algorithm' => $sigMode],
            ];
        }
        if ($rsaSig === '') {
            return [
                'ok'         => false,
                'status'     => 401,
                'code'       => 'MISSING_RSA_SIGNATURE',
                'message'    => 'Header X-Ilmuna-RSA-Signature (RSA-2048/SHA-256) wajib disertakan untuk mode ' . $sigMode . '.',
                'rate_limit' => $rl,
                'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader, 'sig_algorithm' => $sigMode],
            ];
        }
        $rawRsaSig = base64_decode($rsaSig, true);
        if ($rawRsaSig === false) {
            return [
                'ok'         => false,
                'status'     => 401,
                'code'       => 'INVALID_RSA_SIGNATURE_ENCODING',
                'message'    => 'Header X-Ilmuna-RSA-Signature harus berformat Base64 valid.',
                'rate_limit' => $rl,
                'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader, 'sig_algorithm' => $sigMode],
            ];
        }
        $verified = @openssl_verify($canonical, $rawRsaSig, $rsaPublicPem, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            return [
                'ok'         => false,
                'status'     => 401,
                'code'       => 'INVALID_RSA_SIGNATURE',
                'message'    => 'Verifikasi tanda tangan asimetris RSA-2048 gagal.',
                'rate_limit' => $rl,
                'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader, 'sig_algorithm' => $sigMode],
            ];
        }
    }

    if (mt_rand(1, 20) === 1) {
        $pdo->exec("DELETE FROM sso_api_nonces WHERE expires_at < NOW()");
    }
    $nonceHash = hash('sha256', $nonce);
    $nonceExp  = date('Y-m-d H:i:s', $now + (ILMUNA_SSO_API_MAX_SKEW * 2));
    try {
        $insNonce = $pdo->prepare("INSERT INTO sso_api_nonces (public_key, nonce_hash, expires_at) VALUES (?, ?, ?)");
        $insNonce->execute([$publicKey, $nonceHash, $nonceExp]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            return [
                'ok'         => false,
                'status'     => 401,
                'code'       => 'REPLAY_DETECTED',
                'message'    => 'Nonce sudah pernah dipakai (serangan replay diblokir). Gunakan nonce unik baru pada setiap request.',
                'rate_limit' => $rl,
                'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader, 'sig_algorithm' => $sigMode],
            ];
        }
        throw $e;
    }

    $upd = $pdo->prepare("UPDATE sso_api_clients SET last_used_at = NOW(), last_ip = ?, request_count = request_count + 1 WHERE id = ?");
    $upd->execute([$clientIp, (int)$client['id']]);

    return [
        'ok'              => true,
        'client'          => $client,
        'private_key'     => $privateKey,
        'rate_limit'      => $rl,
        'sig_mode'        => $sigMode,
        'client_ip'       => $clientIp,
        'client_site'     => ($siteHeader !== '' ? ilmuna_sso_api_extract_host($siteHeader) : ilmuna_sso_api_extract_host($originHdr)),
        'origin'          => $originHdr,
        'allowed_domains' => $allowedDomains,
    ];
}

/**
 * Terbitkan Ephemeral Single-Use WebSocket Ticket (wst_...) berumur pendek (default 120 detik).
 * Digunakan oleh backend SSO (margo-one.ilmuna.my.id) agar browser pengguna SSO dapat membuka
 * koneksi WebSocket real-time langsung sesuai kelasnya tanpa membocorkan Private Key di browser.
 */
function ilmuna_sso_api_issue_ws_ticket(PDO $pdo, array $client, $privateKey, array $opts = [])
{
    ilmuna_sso_api_ensure_schema($pdo);

    if (mt_rand(1, 10) === 1) {
        $pdo->exec("DELETE FROM sso_ws_tickets WHERE expires_at < NOW()");
    }

    $ttlSec = max(30, min(600, (int)($opts['ttl'] ?? 120)));
    $expTs  = time() + $ttlSec;
    $expDt  = date('Y-m-d H:i:s', $expTs);

    $kelasIds = [];
    if (!empty($opts['kelas_ids']) && is_array($opts['kelas_ids'])) {
        foreach ($opts['kelas_ids'] as $kid) {
            $kidInt = (int)$kid;
            if ($kidInt > 0) {
                $kelasIds[] = $kidInt;
            }
        }
    }
    $kelasIds = array_values(array_unique($kelasIds));
    $kelasCsv = implode(',', $kelasIds);

    $siswaId       = !empty($opts['siswa_id']) ? (int)$opts['siswa_id'] : null;
    $guruId        = !empty($opts['guru_id']) ? (int)$opts['guru_id'] : null;
    $roleCode      = trim((string)($opts['role_code'] ?? 'siswa')) ?: 'siswa';
    $userIdent     = isset($opts['user_identifier']) ? substr(trim((string)$opts['user_identifier']), 0, 150) : null;
    $subscribeAll  = !empty($opts['subscribe_all']) ? 1 : 0;

    $rawRand   = bin2hex(random_bytes(24));
    $mac       = substr(hash_hmac('sha256', $rawRand . '|' . $expTs . '|' . $kelasCsv . '|' . $roleCode, (string)$privateKey), 0, 32);
    $rawTicket = 'wst_' . $rawRand . '_' . $expTs . '_' . $mac;
    $ticketHash = hash('sha256', $rawTicket);

    $stmt = $pdo->prepare("
        INSERT INTO sso_ws_tickets
        (ticket_hash, client_id, kelas_ids_csv, siswa_id, guru_id, role_code, user_identifier, subscribe_all, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $ticketHash,
        (int)$client['id'],
        $kelasCsv,
        $siswaId,
        $guruId,
        $roleCode,
        $userIdent,
        $subscribeAll,
        $expDt,
    ]);

    $wsPort = !empty($client['ws_port']) ? (int)$client['ws_port'] : ILMUNA_SSO_WS_DEFAULT_PORT;
    $wsUrl  = !empty($client['ws_public_url']) ? trim((string)$client['ws_public_url']) : ilmuna_sso_default_ws_url($wsPort);

    return [
        'ticket'          => $rawTicket,
        'ws_url'          => $wsUrl,
        'ws_connect_url'  => $wsUrl . (strpos($wsUrl, '?') === false ? '?' : '&') . 'ticket=' . rawurlencode($rawTicket),
        'expires_at'      => $expTs,
        'expires_in'      => $ttlSec,
        'kelas_ids'       => $kelasIds,
        'role'            => $roleCode,
        'subscribe_all'   => (bool)$subscribeAll,
    ];
}

/**
 * Verifikasi & konsumsi Single-Use WebSocket Ticket saat handshake RFC 6455
 */
function ilmuna_sso_api_consume_ws_ticket(PDO $pdo, $rawTicket)
{
    $rawTicket = trim((string)$rawTicket);
    if (!preg_match('/^wst_([a-f0-9]{48})_(\d{10,12})_([a-f0-9]{32})$/', $rawTicket, $m)) {
        return ['ok' => false, 'reason' => 'FORMAT_INVALID'];
    }

    $rawRand = $m[1];
    $expTs   = (int)$m[2];
    $mac     = $m[3];

    if (time() > $expTs) {
        return ['ok' => false, 'reason' => 'TICKET_EXPIRED'];
    }

    $ticketHash = hash('sha256', $rawTicket);
    $stmt = $pdo->prepare("
        SELECT t.*, c.private_key_enc, c.is_active, c.public_key
        FROM sso_ws_tickets t
        JOIN sso_api_clients c ON t.client_id = c.id
        WHERE t.ticket_hash = ?
        LIMIT 1
    ");
    $stmt->execute([$ticketHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['ok' => false, 'reason' => 'TICKET_NOT_FOUND'];
    }
    if ((int)$row['is_active'] !== 1) {
        return ['ok' => false, 'reason' => 'CLIENT_DISABLED'];
    }
    if (!empty($row['used_at'])) {
        return ['ok' => false, 'reason' => 'TICKET_ALREADY_USED'];
    }
    if (strtotime((string)$row['expires_at']) < time()) {
        return ['ok' => false, 'reason' => 'TICKET_EXPIRED'];
    }

    $privateKey = ilmuna_sso_api_decrypt_secret($row['private_key_enc']);
    if ($privateKey === '') {
        return ['ok' => false, 'reason' => 'SERVER_KEY_ERROR'];
    }

    $expectedMac = substr(hash_hmac('sha256', $rawRand . '|' . $expTs . '|' . $row['kelas_ids_csv'] . '|' . $row['role_code'], $privateKey), 0, 32);
    if (!hash_equals($expectedMac, $mac)) {
        return ['ok' => false, 'reason' => 'TICKET_MAC_MISMATCH'];
    }

    $upd = $pdo->prepare("UPDATE sso_ws_tickets SET used_at = NOW() WHERE ticket_hash = ? AND used_at IS NULL");
    $upd->execute([$ticketHash]);
    if ($upd->rowCount() < 1) {
        return ['ok' => false, 'reason' => 'TICKET_RACE_USED'];
    }

    $kelasIds = [];
    if (trim((string)$row['kelas_ids_csv']) !== '') {
        foreach (explode(',', (string)$row['kelas_ids_csv']) as $k) {
            $ki = (int)trim($k);
            if ($ki > 0) {
                $kelasIds[] = $ki;
            }
        }
    }

    return [
        'ok'              => true,
        'client_id'       => (int)$row['client_id'],
        'public_key'      => (string)$row['public_key'],
        'kelas_ids'       => array_values(array_unique($kelasIds)),
        'siswa_id'        => !empty($row['siswa_id']) ? (int)$row['siswa_id'] : null,
        'guru_id'         => !empty($row['guru_id']) ? (int)$row['guru_id'] : null,
        'role_code'       => (string)$row['role_code'],
        'user_identifier' => $row['user_identifier'],
        'subscribe_all'   => ((int)$row['subscribe_all'] === 1),
    ];
}
