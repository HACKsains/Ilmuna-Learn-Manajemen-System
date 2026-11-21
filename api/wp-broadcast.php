<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — SECURE WORDPRESS BROADCAST & SHOWCASE REST API
 * ==============================================================================
 * Endpoint: https://learn.ilmuna.my.id/api/wp-broadcast.php
 * Consumer: https://ilmuna.my.id (WordPress Server-to-Server)
 *
 * Query Parameter ?resource=...:
 *   - health           : Cek kesehatan koneksi, jam server & validitas kunci
 *   - proyek_showcase  : Galeri Hasil Karya Proyek Siswa (alias: showcase)
 *   - tugas_broadcast  : Broadcast Tugas & Deadline Tugas (alias: tugas)
 *   - proyek_broadcast : Broadcast Proyek & Deadline Proyek (alias: proyek)
 *   - all              : Gabungan seluruh feed (alias: feed, broadcast)
 *
 * Proteksi Maksimal:
 *   Public Key + Private Key (HMAC-SHA256 / RSA-2048 / DUAL) + Timestamp +/-300s
 *   + Single-use Nonce (Anti-Replay) + Domain/IP Allowlist + Rate Limit +
 *   Signed JSON Response (X-Ilmuna-Response-Signature) + Audit Trail.
 * ==============================================================================
 */

$startMicro = microtime(true);
date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once BASE_PATH . '/includes/wp-api-security.php';
require_once BASE_PATH . '/includes/wp-api-data.php';

/**
 * Keluarkan respon JSON standar dengan header keamanan penuh + (opsional) response signature
 */
function ilmuna_wp_api_emit_json($httpStatus, array $payload, array $extraHeaders = [], $privateKey = null)
{
    while (ob_get_level()) {
        ob_end_clean();
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $httpStatus = 500;
        $json = '{"success":false,"error":{"code":"JSON_ENCODE_ERROR","message":"Gagal menyusun respon JSON."}}';
    }

    if (!headers_sent()) {
        http_response_code((int)$httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('X-Ilmuna-API-Version: ' . ILMUNA_WP_API_VERSION);

        if ($privateKey !== null && $privateKey !== '') {
            $respTs = time();
            $respSig = ilmuna_wp_api_sign_response($respTs, $json, $privateKey);
            header('X-Ilmuna-Response-Timestamp: ' . $respTs);
            header('X-Ilmuna-Response-Signature: ' . $respSig);
        }

        foreach ($extraHeaders as $k => $v) {
            header($k . ': ' . $v);
        }
    }

    echo $json;
    exit;
}

// Hanya izinkan metode GET (dan OPTIONS untuk preflight CORS terbatas)
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    if (!headers_sent()) {
        http_response_code(204);
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: X-Ilmuna-Public-Key, X-Ilmuna-Timestamp, X-Ilmuna-Nonce, X-Ilmuna-Signature, X-Ilmuna-RSA-Signature, X-Ilmuna-Site');
        header('Access-Control-Max-Age: 600');
    }
    exit;
}

if ($method !== 'GET') {
    ilmuna_wp_api_emit_json(405, [
        'success' => false,
        'error'   => [
            'code'    => 'METHOD_NOT_ALLOWED',
            'message' => 'Endpoint ini hanya menerima metode GET (read-only).',
        ],
    ], ['Allow' => 'GET, OPTIONS']);
}

try {
    $pdo = getDBConnection();
    $pdo->exec("SET time_zone = '+07:00'");
} catch (Throwable $e) {
    ilmuna_wp_api_emit_json(503, [
        'success' => false,
        'error'   => [
            'code'    => 'DATABASE_UNAVAILABLE',
            'message' => 'Koneksi database sementara tidak tersedia.',
        ],
    ]);
}

$rawBody  = '';
$resource = strtolower(trim((string)($_GET['resource'] ?? 'all')));

// Autentikasi Zero-Trust (Public Key + Private Key + Timestamp + Nonce + Domain/IP + Rate Limit)
$auth = ilmuna_wp_api_authenticate($pdo, [
    'method' => $method,
    'uri'    => $_SERVER['REQUEST_URI'] ?? '/api/wp-broadcast.php',
    'query'  => $_GET,
    'body'   => $rawBody,
]);

$durationMs = (int)round((microtime(true) - $startMicro) * 1000);
$extraHeaders = [];

if (isset($auth['rate_limit']) && is_array($auth['rate_limit'])) {
    $extraHeaders['X-RateLimit-Limit']     = (string)$auth['rate_limit']['limit'];
    $extraHeaders['X-RateLimit-Remaining'] = (string)$auth['rate_limit']['remaining'];
    $extraHeaders['X-RateLimit-Reset']     = (string)$auth['rate_limit']['reset_in'];
    if (!$auth['rate_limit']['allowed']) {
        $extraHeaders['Retry-After'] = (string)$auth['rate_limit']['reset_in'];
    }
}

if (!$auth['ok']) {
    $meta = isset($auth['meta']) && is_array($auth['meta']) ? $auth['meta'] : [];
    ilmuna_wp_api_log_audit($pdo, [
        'client_id'     => $meta['client_id'] ?? null,
        'public_key'    => $meta['public_key'] ?? null,
        'endpoint'      => '/api/wp-broadcast',
        'resource'      => $resource,
        'http_status'   => (int)$auth['status'],
        'client_ip'     => $meta['client_ip'] ?? ilmuna_wp_api_client_ip(),
        'client_site'   => $meta['client_site'] ?? null,
        'sig_algorithm' => $meta['sig_algorithm'] ?? null,
        'error_code'    => (string)$auth['code'],
        'duration_ms'   => $durationMs,
    ]);

    $errPayload = [
        'success' => false,
        'error'   => [
            'code'    => (string)$auth['code'],
            'message' => (string)$auth['message'],
        ],
        'server_time' => time(),
    ];
    ilmuna_wp_api_emit_json((int)$auth['status'], $errPayload, $extraHeaders);
}

// Strict CORS header HANYA jika Origin terdaftar di allowed_domains
if (!empty($auth['origin'])) {
    $originHost = ilmuna_wp_api_extract_host($auth['origin']);
    if (ilmuna_wp_api_domain_allowed($originHost, $auth['allowed_domains'])) {
        $extraHeaders['Access-Control-Allow-Origin'] = $auth['origin'];
        $extraHeaders['Vary'] = 'Origin';
    }
}

$client     = $auth['client'];
$privateKey = $auth['private_key'];

// Normalisasi alias resource
$aliasMap = [
    'showcase'  => 'proyek_showcase',
    'tugas'     => 'tugas_broadcast',
    'proyek'    => 'proyek_broadcast',
    'feed'      => 'all',
    'broadcast' => 'all',
];
$canonicalResource = isset($aliasMap[$resource]) ? $aliasMap[$resource] : $resource;

try {
    $school = ilmuna_wp_api_fetch_school_profile($pdo);
    $data = [];

    if ($canonicalResource === 'health') {
        $data = [
            'status'          => 'healthy',
            'client_name'     => (string)$client['client_name'],
            'sig_mode'        => (string)$client['sig_mode'],
            'allowed_scopes'  => ilmuna_wp_api_parse_list($client['allowed_scopes']),
            'showcase_policy' => (string)$client['showcase_min_status'],
            'school'          => $school,
            'server_time'     => time(),
            'server_time_wib' => date('Y-m-d H:i:s') . ' WIB',
            'api_version'     => ILMUNA_WP_API_VERSION,
        ];
    } elseif ($canonicalResource === 'proyek_showcase') {
        $res = ilmuna_wp_api_fetch_proyek_showcase($pdo, $_GET, $client);
        $data = [
            'school'     => $school,
            'showcase'   => $res['items'],
            'pagination' => $res['pagination'],
            'policy'     => $res['policy'],
        ];
    } elseif ($canonicalResource === 'tugas_broadcast') {
        $res = ilmuna_wp_api_fetch_tugas_broadcast($pdo, $_GET);
        $data = [
            'school'     => $school,
            'tugas'      => $res['items'],
            'pagination' => $res['pagination'],
        ];
    } elseif ($canonicalResource === 'proyek_broadcast') {
        $res = ilmuna_wp_api_fetch_proyek_broadcast($pdo, $_GET);
        $data = [
            'school'     => $school,
            'proyek'     => $res['items'],
            'pagination' => $res['pagination'],
        ];
    } elseif ($canonicalResource === 'all') {
        $showcaseLimit = max(1, min(30, (int)($_GET['limit_showcase'] ?? ($_GET['limit'] ?? 9))));
        $tugasLimit    = max(1, min(30, (int)($_GET['limit_tugas'] ?? ($_GET['limit'] ?? 10))));
        $proyekLimit   = max(1, min(30, (int)($_GET['limit_proyek'] ?? ($_GET['limit'] ?? 8))));

        $showcaseRes = ilmuna_wp_api_fetch_proyek_showcase($pdo, array_merge($_GET, ['limit' => $showcaseLimit, 'page' => 1]), $client);
        $tugasRes    = ilmuna_wp_api_fetch_tugas_broadcast($pdo, array_merge($_GET, ['limit' => $tugasLimit, 'page' => 1]));
        $proyekRes   = ilmuna_wp_api_fetch_proyek_broadcast($pdo, array_merge($_GET, ['limit' => $proyekLimit, 'page' => 1]));

        $data = [
            'school'           => $school,
            'proyek_showcase'  => $showcaseRes['items'],
            'tugas_broadcast'  => $tugasRes['items'],
            'proyek_broadcast' => $proyekRes['items'],
            'counts' => [
                'proyek_showcase'  => $showcaseRes['pagination']['total_items'],
                'tugas_broadcast'  => $tugasRes['pagination']['total_items'],
                'proyek_broadcast' => $proyekRes['pagination']['total_items'],
            ],
        ];
    } else {
        $durationMs = (int)round((microtime(true) - $startMicro) * 1000);
        ilmuna_wp_api_log_audit($pdo, [
            'client_id'     => (int)$client['id'],
            'public_key'    => (string)$client['public_key'],
            'endpoint'      => '/api/wp-broadcast',
            'resource'      => $resource,
            'http_status'   => 400,
            'client_ip'     => $auth['client_ip'],
            'client_site'   => $auth['client_site'],
            'sig_algorithm' => $auth['sig_mode'],
            'error_code'    => 'UNKNOWN_RESOURCE',
            'duration_ms'   => $durationMs,
        ]);
        ilmuna_wp_api_emit_json(400, [
            'success' => false,
            'error'   => [
                'code'    => 'UNKNOWN_RESOURCE',
                'message' => 'Resource tidak dikenali. Gunakan: health, proyek_showcase, tugas_broadcast, proyek_broadcast, atau all.',
            ],
        ], $extraHeaders, $privateKey);
    }

    $durationMs = (int)round((microtime(true) - $startMicro) * 1000);
    ilmuna_wp_api_log_audit($pdo, [
        'client_id'     => (int)$client['id'],
        'public_key'    => (string)$client['public_key'],
        'endpoint'      => '/api/wp-broadcast',
        'resource'      => $canonicalResource,
        'http_status'   => 200,
        'client_ip'     => $auth['client_ip'],
        'client_site'   => $auth['client_site'],
        'sig_algorithm' => $auth['sig_mode'],
        'error_code'    => null,
        'duration_ms'   => $durationMs,
    ]);

    ilmuna_wp_api_emit_json(200, [
        'success'  => true,
        'resource' => $canonicalResource,
        'data'     => $data,
        'meta'     => [
            'api_version'           => ILMUNA_WP_API_VERSION,
            'generated_at'          => date('c'),
            'generated_at_wib'      => date('d M Y H:i:s') . ' WIB',
            'cache_ttl_recommended' => 180,
            'sig_mode'              => (string)$client['sig_mode'],
        ],
    ], $extraHeaders, $privateKey);
} catch (Throwable $e) {
    $durationMs = (int)round((microtime(true) - $startMicro) * 1000);
    ilmuna_wp_api_log_audit($pdo, [
        'client_id'     => (int)$client['id'],
        'public_key'    => (string)$client['public_key'],
        'endpoint'      => '/api/wp-broadcast',
        'resource'      => $canonicalResource,
        'http_status'   => 500,
        'client_ip'     => $auth['client_ip'],
        'client_site'   => $auth['client_site'],
        'sig_algorithm' => $auth['sig_mode'],
        'error_code'    => 'INTERNAL_ERROR',
        'duration_ms'   => $durationMs,
    ]);
    ilmuna_wp_api_emit_json(500, [
        'success' => false,
        'error'   => [
            'code'    => 'INTERNAL_ERROR',
            'message' => 'Terjadi kesalahan internal saat mengambil data.',
        ],
    ], $extraHeaders, $privateKey);
}
