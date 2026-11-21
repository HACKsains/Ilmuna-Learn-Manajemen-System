<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — WORDPRESS SECURE API ENGINE (PUBLIC KEY + PRIVATE KEY)
 * ==============================================================================
 * Layer Keamanan Zero-Trust untuk menghubungkan learn.ilmuna.my.id <-> ilmuna.my.id
 *
 * Proteksi Berlapis:
 *   1. Dual-Key Authentication: Public Key (pk_ilmuna_*) + Private Key (sk_ilmuna_*)
 *      Private Key TIDAK PERNAH dikirim lewat jaringan & dienkripsi AES-256-CBC+HMAC di DB.
 *   2. Cryptographic Request Signing:
 *      - Mode HMAC-SHA256 (Default): tanda tangan kanonik dengan Private Key (hash_equals)
 *      - Mode RSA-SHA256: tanda tangan asimetris RSA-2048 PEM (server hanya simpan Public PEM)
 *      - Mode DUAL: mewajibkan HMAC-SHA256 DAN RSA-2048 sekaligus (proteksi maksimal)
 *   3. Anti-Replay Protection:
 *      - Jendela waktu ketat +/- 300 detik (X-Ilmuna-Timestamp)
 *      - Nonce sekali pakai tersimpan di wp_api_nonces (X-Ilmuna-Nonce)
 *   4. Domain, Origin/Referer & IP Allowlist (khusus ilmuna.my.id / daftar whitelist)
 *   5. Sliding-Window Rate Limiter per Public Key + IP (429 Too Many Requests)
 *   6. Signed JSON Response (X-Ilmuna-Response-Signature) agar WordPress bisa
 *      memverifikasi keaslian respon dari learn.ilmuna.my.id
 *   7. Audit Trail Lengkap (wp_api_audit_logs) dengan auto-pruning ringan
 *
 * 100% kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

if (!defined('ILMUNA_WP_API_VERSION')) {
    define('ILMUNA_WP_API_VERSION', '1.0.0');
}
if (!defined('ILMUNA_WP_API_MAX_SKEW')) {
    define('ILMUNA_WP_API_MAX_SKEW', 300); // +/- 5 menit
}

/**
 * Kunci master internal untuk mengenkripsi Private Key API di database (at-rest).
 */
function ilmuna_wp_api_master_key()
{
    $seedParts = [
        defined('DB_PASS') ? DB_PASS : 'ilmuna_local_pass',
        defined('DB_NAME') ? DB_NAME : 'ilmuna_db',
        defined('APP_DOMAIN') ? APP_DOMAIN : 'learn.ilmuna.my.id',
        'ILMUNA_WP_API_ENC_V1_SALT_2026',
    ];
    return hash('sha256', implode('|', $seedParts), true);
}

/**
 * Enkripsi AES-256-CBC + HMAC-SHA256 (Encrypt-then-MAC) — aman di PHP 7.4+
 */
function ilmuna_wp_api_encrypt_secret($plaintext)
{
    $plaintext = (string)$plaintext;
    $masterKey = ilmuna_wp_api_master_key();
    $encKey = hash_hmac('sha256', 'enc', $masterKey, true);
    $macKey = hash_hmac('sha256', 'mac', $masterKey, true);

    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        throw new RuntimeException('Gagal mengenkripsi secret API menggunakan OpenSSL.');
    }
    $mac = hash_hmac('sha256', $iv . $cipher, $macKey, true);
    return 'v1:' . base64_encode($iv . $mac . $cipher);
}

/**
 * Dekripsi AES-256-CBC + verifikasi HMAC-SHA256 constant-time
 */
function ilmuna_wp_api_decrypt_secret($encoded)
{
    $encoded = (string)$encoded;
    if (strpos($encoded, 'v1:') !== 0) {
        return '';
    }
    $raw = base64_decode(substr($encoded, 3), true);
    if ($raw === false || strlen($raw) <= 48) {
        return '';
    }

    $masterKey = ilmuna_wp_api_master_key();
    $encKey = hash_hmac('sha256', 'enc', $masterKey, true);
    $macKey = hash_hmac('sha256', 'mac', $masterKey, true);

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
 * Generate pasangan Public Key & Private Key kriptografis baru
 */
function ilmuna_wp_api_generate_keypair()
{
    $publicKey  = 'pk_ilmuna_' . bin2hex(random_bytes(16)); // 42 chars
    $privateKey = 'sk_ilmuna_' . bin2hex(random_bytes(32)); // 74 chars (256-bit)
    return [
        'public_key'  => $publicKey,
        'private_key' => $privateKey,
    ];
}

/**
 * Temukan lokasi openssl.cnf untuk lingkungan Windows/Laragon maupun Linux cPanel
 */
function ilmuna_wp_api_find_openssl_cnf()
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
 * Generate pasangan RSA-2048 PEM (Public PEM + Private PEM) untuk mode RSA-SHA256 / DUAL
 */
function ilmuna_wp_api_generate_rsa_pair()
{
    if (!extension_loaded('openssl')) {
        return ['success' => false, 'message' => 'Ekstensi OpenSSL tidak aktif di server PHP.'];
    }
    $cfg = [
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];
    $cnfPath = ilmuna_wp_api_find_openssl_cnf();
    if ($cnfPath !== null) {
        $cfg['config'] = $cnfPath;
    }

    $res = @openssl_pkey_new($cfg);
    if ($res === false) {
        return ['success' => false, 'message' => 'OpenSSL gagal membangkitkan kunci RSA-2048: ' . (openssl_error_string() ?: 'unknown error')];
    }

    $privatePem = '';
    $exported = ($cnfPath !== null)
        ? @openssl_pkey_export($res, $privatePem, null, ['config' => $cnfPath])
        : @openssl_pkey_export($res, $privatePem);
    if (!$exported || empty($privatePem)) {
        return ['success' => false, 'message' => 'Gagal mengekspor RSA Private Key PEM.'];
    }

    $details = @openssl_pkey_get_details($res);
    $publicPem = is_array($details) && !empty($details['key']) ? $details['key'] : '';
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
 * Auto-migrasi skema tabel API WordPress + seed klien default ilmuna.my.id
 */
function ilmuna_wp_api_ensure_schema(PDO $pdo)
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wp_api_clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_name VARCHAR(120) NOT NULL,
            public_key VARCHAR(120) NOT NULL UNIQUE,
            private_key_enc TEXT NOT NULL,
            private_key_hash CHAR(64) NOT NULL,
            rsa_public_pem TEXT NULL,
            sig_mode ENUM('HMAC-SHA256','RSA-SHA256','DUAL') NOT NULL DEFAULT 'HMAC-SHA256',
            allowed_domains TEXT NOT NULL,
            allowed_ips TEXT NULL,
            allowed_scopes VARCHAR(255) NOT NULL DEFAULT 'health,proyek_showcase,tugas_broadcast,proyek_broadcast,all',
            rate_limit_per_min INT NOT NULL DEFAULT 120,
            showcase_min_status ENUM('Dipublikasikan','Disetujui_atau_Dipublikasikan','Semua_Terkumpul') NOT NULL DEFAULT 'Disetujui_atau_Dipublikasikan',
            hide_student_lastname TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_used_at DATETIME NULL,
            last_ip VARCHAR(64) NULL,
            request_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_wp_api_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wp_api_nonces (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            public_key VARCHAR(120) NOT NULL,
            nonce_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_wp_api_pk_nonce (public_key, nonce_hash),
            INDEX idx_wp_api_nonce_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wp_api_rate_limits (
            bucket_key CHAR(64) PRIMARY KEY,
            hits INT NOT NULL DEFAULT 1,
            expires_at DATETIME NOT NULL,
            INDEX idx_wp_api_rl_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wp_api_audit_logs (
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
            INDEX idx_wp_api_log_created (created_at),
            INDEX idx_wp_api_log_status (http_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Seed klien default untuk WordPress ilmuna.my.id jika belum ada satupun
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM wp_api_clients")->fetchColumn();
    if ($cnt === 0) {
        $pair = ilmuna_wp_api_generate_keypair();
        $encPrivate = ilmuna_wp_api_encrypt_secret($pair['private_key']);
        $hashPrivate = hash('sha256', $pair['private_key']);

        $stmt = $pdo->prepare("
            INSERT INTO wp_api_clients
            (client_name, public_key, private_key_enc, private_key_hash, sig_mode, allowed_domains, allowed_ips, allowed_scopes, rate_limit_per_min, showcase_min_status, hide_student_lastname, is_active)
            VALUES (?, ?, ?, ?, 'HMAC-SHA256', ?, '', 'health,proyek_showcase,tugas_broadcast,proyek_broadcast,all', 120, 'Disetujui_atau_Dipublikasikan', 0, 1)
        ");
        $stmt->execute([
            'WordPress Utama (ilmuna.my.id)',
            $pair['public_key'],
            $encPrivate,
            $hashPrivate,
            'ilmuna.my.id,www.ilmuna.my.id,learn.ilmuna.my.id',
        ]);
    }

    $ensured = true;
}

/**
 * Ambil seluruh header HTTP dalam format lowercase secara portabel (Apache/LiteSpeed/Nginx/CLI)
 */
function ilmuna_wp_api_get_headers(array $overrideHeaders = null)
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
 * Ambil IP klien yang aman
 */
function ilmuna_wp_api_client_ip()
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
 * Normalisasi path endpoint menjadi bentuk kanonik yang kebal terhadap .php / subfolder
 * Contoh: /api/wp-broadcast.php atau /learn/api/wp-broadcast -> /api/wp-broadcast
 */
function ilmuna_wp_api_canonical_path($requestUri)
{
    $path = parse_url((string)$requestUri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = '/api/wp-broadcast';
    }
    $base = basename($path);
    $base = preg_replace('/\.php$/i', '', $base);
    if ($base === '' || $base === '/' || $base === '.') {
        $base = 'wp-broadcast';
    }
    return '/api/' . strtolower($base);
}

/**
 * Normalisasi parameter query string agar deterministik (diurutkan per kunci, RFC3986)
 */
function ilmuna_wp_api_canonical_query(array $queryParams)
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
 * Bangun Canonical String yang ditandatangani klien & diverifikasi server
 */
function ilmuna_wp_api_canonical_string($method, $canonicalPath, array $queryParams, $timestamp, $nonce, $rawBody = '')
{
    $method = strtoupper(trim((string)$method));
    $cPath  = ilmuna_wp_api_canonical_path($canonicalPath);
    $cQuery = ilmuna_wp_api_canonical_query($queryParams);
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

/**
 * Hitung signature HMAC-SHA256 untuk request
 */
function ilmuna_wp_api_sign_hmac($canonicalString, $privateKey)
{
    return hash_hmac('sha256', (string)$canonicalString, (string)$privateKey);
}

/**
 * Hitung signature RSA-SHA256 (Base64) untuk request menggunakan RSA Private Key PEM
 */
function ilmuna_wp_api_sign_rsa($canonicalString, $rsaPrivatePem)
{
    $sig = '';
    $ok = @openssl_sign((string)$canonicalString, $sig, (string)$rsaPrivatePem, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        return '';
    }
    return base64_encode($sig);
}

/**
 * Hitung signature respon server (agar WordPress dapat memverifikasi keaslian respon)
 */
function ilmuna_wp_api_sign_response($responseTimestamp, $jsonBody, $privateKey)
{
    return hash_hmac('sha256', (string)$responseTimestamp . "\n" . (string)$jsonBody, (string)$privateKey);
}

/**
 * Parse host domain murni dari URL atau string domain
 */
function ilmuna_wp_api_extract_host($value)
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

/**
 * Parse CSV/newline list menjadi array bersih
 */
function ilmuna_wp_api_parse_list($raw)
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

/**
 * Periksa apakah domain sesuai daftar whitelist (mendukung wildcard *.ilmuna.my.id)
 */
function ilmuna_wp_api_domain_allowed($host, array $allowedDomains)
{
    $host = strtolower(trim((string)$host));
    if ($host === '') {
        return false;
    }
    // Izinkan localhost otomatis HANYA ketika server sendiri berjalan di lingkungan dev lokal
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
        if ($pattern === '') continue;
        if ($pattern === $host) {
            return true;
        }
        if (strpos($pattern, '*.') === 0) {
            $suffix = substr($pattern, 1); // ".ilmuna.my.id"
            $len = strlen($suffix);
            if ($len > 0 && strlen($host) > $len && substr($host, -$len) === $suffix) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Periksa kecocokan IP terhadap whitelist (exact IPv4/IPv6 atau CIDR IPv4)
 */
function ilmuna_wp_api_ip_allowed($clientIp, array $allowedIps)
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
        if ($rule === '') continue;
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

/**
 * Sliding-window Rate Limit per menit berdasarkan Public Key + IP
 */
function ilmuna_wp_api_check_rate_limit(PDO $pdo, $publicKey, $clientIp, $limitPerMin)
{
    $limitPerMin = max(5, (int)$limitPerMin);
    $minuteWindow = (int)floor(time() / 60);
    $bucketKey = hash('sha256', $publicKey . '|' . $clientIp . '|' . $minuteWindow);
    $expiresAt = date('Y-m-d H:i:s', ($minuteWindow + 2) * 60);

    // Pruning probabilistik 5% agar tabel tetap ramping
    if (mt_rand(1, 20) === 1) {
        $pdo->exec("DELETE FROM wp_api_rate_limits WHERE expires_at < NOW()");
    }

    $stmt = $pdo->prepare("
        INSERT INTO wp_api_rate_limits (bucket_key, hits, expires_at)
        VALUES (?, 1, ?)
        ON DUPLICATE KEY UPDATE hits = hits + 1
    ");
    $stmt->execute([$bucketKey, $expiresAt]);

    $sel = $pdo->prepare("SELECT hits FROM wp_api_rate_limits WHERE bucket_key = ?");
    $sel->execute([$bucketKey]);
    $hits = (int)$sel->fetchColumn();

    $remaining = max(0, $limitPerMin - $hits);
    $resetIn = 60 - (time() % 60);

    return [
        'allowed'   => ($hits <= $limitPerMin),
        'limit'     => $limitPerMin,
        'hits'      => $hits,
        'remaining' => $remaining,
        'reset_in'  => $resetIn,
    ];
}

/**
 * Catat jejak audit request API ke wp_api_audit_logs
 */
function ilmuna_wp_api_log_audit(PDO $pdo, array $entry)
{
    try {
        // Pruning ringan probabilistik 3%
        if (mt_rand(1, 30) === 1) {
            $pdo->exec("DELETE FROM wp_api_audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        }
        $stmt = $pdo->prepare("
            INSERT INTO wp_api_audit_logs
            (client_id, public_key, endpoint, resource, http_status, client_ip, client_site, sig_algorithm, error_code, duration_ms)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            isset($entry['client_id']) ? (int)$entry['client_id'] : null,
            isset($entry['public_key']) ? substr((string)$entry['public_key'], 0, 120) : null,
            substr((string)($entry['endpoint'] ?? '/api/wp-broadcast'), 0, 120),
            substr((string)($entry['resource'] ?? ''), 0, 60),
            (int)($entry['http_status'] ?? 200),
            substr((string)($entry['client_ip'] ?? '0.0.0.0'), 0, 64),
            isset($entry['client_site']) ? substr((string)$entry['client_site'], 0, 190) : null,
            isset($entry['sig_algorithm']) ? substr((string)$entry['sig_algorithm'], 0, 30) : null,
            isset($entry['error_code']) ? substr((string)$entry['error_code'], 0, 60) : null,
            (int)($entry['duration_ms'] ?? 0),
        ]);
    } catch (Throwable $e) {
        // Jangan sampai kegagalan logging mematahkan respon utama API
    }
}

/**
 * Verifikasi autentikasi penuh untuk request API WordPress.
 * Mengembalikan ['ok' => true, 'client' => [...], 'private_key' => '...', 'rate_limit' => [...]]
 * atau ['ok' => false, 'status' => 401/403/429, 'code' => '...', 'message' => '...']
 */
function ilmuna_wp_api_authenticate(PDO $pdo, array $opts = [])
{
    ilmuna_wp_api_ensure_schema($pdo);

    $method     = strtoupper((string)($opts['method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
    $requestUri = (string)($opts['uri'] ?? ($_SERVER['REQUEST_URI'] ?? '/api/wp-broadcast'));
    $query      = isset($opts['query']) && is_array($opts['query']) ? $opts['query'] : $_GET;
    $rawBody    = isset($opts['body']) ? (string)$opts['body'] : '';
    $headers    = ilmuna_wp_api_get_headers(isset($opts['headers']) ? $opts['headers'] : null);
    $clientIp   = (string)($opts['client_ip'] ?? ilmuna_wp_api_client_ip());
    $resource   = strtolower(trim((string)($query['resource'] ?? 'all')));

    $publicKey  = isset($headers['x-ilmuna-public-key']) ? trim($headers['x-ilmuna-public-key']) : '';
    $timestamp  = isset($headers['x-ilmuna-timestamp']) ? trim($headers['x-ilmuna-timestamp']) : '';
    $nonce      = isset($headers['x-ilmuna-nonce']) ? trim($headers['x-ilmuna-nonce']) : '';
    $signature  = isset($headers['x-ilmuna-signature']) ? trim($headers['x-ilmuna-signature']) : '';
    $rsaSig     = isset($headers['x-ilmuna-rsa-signature']) ? trim($headers['x-ilmuna-rsa-signature']) : '';
    $siteHeader = isset($headers['x-ilmuna-site']) ? trim($headers['x-ilmuna-site']) : '';
    $originHdr  = isset($headers['origin']) ? trim($headers['origin']) : '';
    $refererHdr = isset($headers['referer']) ? trim($headers['referer']) : '';

    // 1. Validasi keberadaan header keamanan dasar
    if ($publicKey === '' || $timestamp === '' || $nonce === '') {
        return [
            'ok'      => false,
            'status'  => 401,
            'code'    => 'MISSING_AUTH_HEADERS',
            'message' => 'Header autentikasi wajib (X-Ilmuna-Public-Key, X-Ilmuna-Timestamp, X-Ilmuna-Nonce) tidak lengkap.',
            'meta'    => ['public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    // 2. Validasi format & jendela waktu Timestamp (+/- 300 detik)
    if (!ctype_digit($timestamp)) {
        return [
            'ok'      => false,
            'status'  => 401,
            'code'    => 'INVALID_TIMESTAMP',
            'message' => 'Header X-Ilmuna-Timestamp harus berupa Unix timestamp angka bulat.',
            'meta'    => ['public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }
    $tsInt = (int)$timestamp;
    $now = time();
    if (abs($now - $tsInt) > ILMUNA_WP_API_MAX_SKEW) {
        return [
            'ok'      => false,
            'status'  => 401,
            'code'    => 'TIMESTAMP_EXPIRED',
            'message' => 'Timestamp request kedaluwarsa atau jam server tidak sinkron (maks selisih ' . ILMUNA_WP_API_MAX_SKEW . ' detik).',
            'meta'    => ['public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader, 'server_time' => $now],
        ];
    }

    // 3. Validasi format Nonce
    if (!preg_match('/^[a-zA-Z0-9_\-]{16,128}$/', $nonce)) {
        return [
            'ok'      => false,
            'status'  => 401,
            'code'    => 'INVALID_NONCE_FORMAT',
            'message' => 'Header X-Ilmuna-Nonce wajib berupa string acak minimal 16 karakter alfanumerik/hex.',
            'meta'    => ['public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    // 4. Lookup Client berdasarkan Public Key
    $stmt = $pdo->prepare("SELECT * FROM wp_api_clients WHERE public_key = ? LIMIT 1");
    $stmt->execute([$publicKey]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        return [
            'ok'      => false,
            'status'  => 401,
            'code'    => 'INVALID_PUBLIC_KEY',
            'message' => 'Public Key tidak dikenali oleh server Ilmuna Learn.',
            'meta'    => ['public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    if ((int)$client['is_active'] !== 1) {
        return [
            'ok'      => false,
            'status'  => 403,
            'code'    => 'CLIENT_DISABLED',
            'message' => 'Kunci API klien sedang dinonaktifkan oleh Administrator.',
            'meta'    => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    // 5. Cek Rate Limit per Public Key + IP
    $rl = ilmuna_wp_api_check_rate_limit($pdo, $publicKey, $clientIp, (int)$client['rate_limit_per_min']);
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

    // 6. Cek Whitelist IP (bila dikonfigurasi)
    $allowedIps = ilmuna_wp_api_parse_list($client['allowed_ips']);
    if (!empty($allowedIps) && !ilmuna_wp_api_ip_allowed($clientIp, $allowedIps)) {
        return [
            'ok'         => false,
            'status'     => 403,
            'code'       => 'IP_NOT_ALLOWED',
            'message'    => 'Alamat IP pengirim (' . $clientIp . ') tidak terdaftar dalam whitelist IP.',
            'rate_limit' => $rl,
            'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    // 7. Cek Whitelist Domain (X-Ilmuna-Site wajib bila allowed_domains diisi; cek juga Origin/Referer jika ada)
    $allowedDomains = ilmuna_wp_api_parse_list($client['allowed_domains']);
    if (!empty($allowedDomains)) {
        if ($siteHeader !== '') {
            $siteHost = ilmuna_wp_api_extract_host($siteHeader);
            if (!ilmuna_wp_api_domain_allowed($siteHost, $allowedDomains)) {
                return [
                    'ok'         => false,
                    'status'     => 403,
                    'code'       => 'DOMAIN_NOT_ALLOWED',
                    'message'    => 'Domain klien (' . $siteHost . ') tidak diizinkan mengakses API ini.',
                    'rate_limit' => $rl,
                    'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHost],
                ];
            }
        }
        if ($originHdr !== '') {
            $originHost = ilmuna_wp_api_extract_host($originHdr);
            if (!ilmuna_wp_api_domain_allowed($originHost, $allowedDomains)) {
                return [
                    'ok'         => false,
                    'status'     => 403,
                    'code'       => 'ORIGIN_NOT_ALLOWED',
                    'message'    => 'Origin (' . $originHost . ') diblokir oleh kebijakan keamanan API.',
                    'rate_limit' => $rl,
                    'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $originHost],
                ];
            }
        }
        if ($refererHdr !== '') {
            $refHost = ilmuna_wp_api_extract_host($refererHdr);
            if ($refHost !== '' && !ilmuna_wp_api_domain_allowed($refHost, $allowedDomains)) {
                return [
                    'ok'         => false,
                    'status'     => 403,
                    'code'       => 'REFERER_NOT_ALLOWED',
                    'message'    => 'Referer (' . $refHost . ') tidak masuk dalam domain yang diizinkan.',
                    'rate_limit' => $rl,
                    'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $refHost],
                ];
            }
        }
    }

    // 8. Verifikasi Scope Resource
    $allowedScopes = ilmuna_wp_api_parse_list($client['allowed_scopes']);
    $scopeAliasMap = [
        'showcase' => 'proyek_showcase',
        'tugas'    => 'tugas_broadcast',
        'proyek'   => 'proyek_broadcast',
        'feed'     => 'all',
        'broadcast'=> 'all',
    ];
    $canonicalScope = isset($scopeAliasMap[$resource]) ? $scopeAliasMap[$resource] : $resource;
    if (!empty($allowedScopes) && !in_array($canonicalScope, $allowedScopes, true)) {
        return [
            'ok'         => false,
            'status'     => 403,
            'code'       => 'SCOPE_FORBIDDEN',
            'message'    => 'Kunci API tidak memiliki izin untuk mengakses resource "' . $canonicalScope . '".',
            'rate_limit' => $rl,
            'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    // 9. Verifikasi Tanda Tangan Kriptografis (HMAC-SHA256 / RSA-SHA256 / DUAL)
    $privateKey = ilmuna_wp_api_decrypt_secret($client['private_key_enc']);
    if ($privateKey === '') {
        return [
            'ok'         => false,
            'status'     => 500,
            'code'       => 'SERVER_KEY_ERROR',
            'message'    => 'Gagal mendekripsi Private Key internal server. Lakukan rotasi kunci di panel Admin.',
            'rate_limit' => $rl,
            'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    $sigMode = (string)$client['sig_mode'];
    $canonical = ilmuna_wp_api_canonical_string($method, $requestUri, $query, $timestamp, $nonce, $rawBody);

    // 9a. Cek HMAC-SHA256 jika mode HMAC-SHA256 atau DUAL
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
        $expectedHmac = ilmuna_wp_api_sign_hmac($canonical, $privateKey);
        if (!hash_equals($expectedHmac, strtolower($signature))) {
            return [
                'ok'         => false,
                'status'     => 401,
                'code'       => 'INVALID_HMAC_SIGNATURE',
                'message'    => 'Tanda tangan HMAC-SHA256 tidak valid. Periksa kesesuaian Private Key dan parameter request.',
                'rate_limit' => $rl,
                'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader, 'sig_algorithm' => $sigMode],
            ];
        }
    }

    // 9b. Cek RSA-SHA256 jika mode RSA-SHA256 atau DUAL
    if ($sigMode === 'RSA-SHA256' || $sigMode === 'DUAL') {
        $rsaPublicPem = trim((string)($client['rsa_public_pem'] ?? ''));
        if ($rsaPublicPem === '') {
            return [
                'ok'         => false,
                'status'     => 500,
                'code'       => 'RSA_PUBLIC_KEY_NOT_CONFIGURED',
                'message'    => 'Mode ' . $sigMode . ' aktif tetapi RSA Public Key PEM belum dikonfigurasi di Admin.',
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
                'message'    => 'Format X-Ilmuna-RSA-Signature harus Base64 valid.',
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

    // 10. Anti-Replay Nonce Protection (hanya disimpan setelah signature lolos!)
    if (mt_rand(1, 20) === 1) {
        $pdo->exec("DELETE FROM wp_api_nonces WHERE expires_at < NOW()");
    }
    $nonceHash = hash('sha256', $nonce);
    $nonceExp  = date('Y-m-d H:i:s', $now + (ILMUNA_WP_API_MAX_SKEW * 2));
    try {
        $insNonce = $pdo->prepare("INSERT INTO wp_api_nonces (public_key, nonce_hash, expires_at) VALUES (?, ?, ?)");
        $insNonce->execute([$publicKey, $nonceHash, $nonceExp]);
    } catch (PDOException $e) {
        $sqlState = (string)$e->getCode();
        if ($sqlState === '23000') {
            return [
                'ok'         => false,
                'status'     => 401,
                'code'       => 'REPLAY_DETECTED',
                'message'    => 'Nonce sudah pernah digunakan (potensi replay attack diblokir). Generate nonce baru untuk setiap request.',
                'rate_limit' => $rl,
                'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader, 'sig_algorithm' => $sigMode],
            ];
        }
        throw $e;
    }

    // 11. Perbarui metrik pemakaian terakhir klien
    $upd = $pdo->prepare("UPDATE wp_api_clients SET last_used_at = NOW(), last_ip = ?, request_count = request_count + 1 WHERE id = ?");
    $upd->execute([$clientIp, (int)$client['id']]);

    return [
        'ok'          => true,
        'client'      => $client,
        'private_key' => $privateKey,
        'rate_limit'  => $rl,
        'sig_mode'    => $sigMode,
        'client_ip'   => $clientIp,
        'client_site' => ($siteHeader !== '' ? ilmuna_wp_api_extract_host($siteHeader) : ilmuna_wp_api_extract_host($originHdr)),
        'origin'      => $originHdr,
        'allowed_domains' => $allowedDomains,
    ];
}
