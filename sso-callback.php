<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — MARGO ONE SSO CALLBACK HANDLER (learn.ilmuna.my.id/sso-callback.php)
 * ==============================================================================
 * Letakkan file ini di root c:\laragon\www\learn\sso-callback.php
 * beserta sdk/ilmuna-sso-client.php.
 *
 * Alur:
 *   1. Validasi feature flag ILMUNA_SSO_ENABLED
 *   2. Terima code + state dari https://margo-one.ilmuna.my.id/authorize
 *   3. Tukar token bertanda tangan (PKCE S256 + HMAC/RSA + Signed Response + JWT RS256)
 *   4. Panggil establishUserSession($pdo, $user) di learn/auth.php
 *      -> Seluruh 51+ modul dashboard Admin/Guru/Ketua Kelas/Siswa langsung aktif!
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sdk/ilmuna-sso-client.php';

if (!defined('ILMUNA_SSO_ENABLED') || !ILMUNA_SSO_ENABLED) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (!empty($_GET['error'])) {
    $errDesc = isset($_GET['error_description']) ? (string)$_GET['error_description'] : (string)$_GET['error'];
    header('Location: ' . BASE_URL . '/login.php?sso_error=' . urlencode($errDesc));
    exit;
}

$code  = trim((string)($_GET['code'] ?? ''));
$state = trim((string)($_GET['state'] ?? ''));

if ($code === '' || $state === '') {
    header('Location: ' . BASE_URL . '/login.php?sso_error=' . urlencode('Parameter otorisasi SSO tidak lengkap.'));
    exit;
}

try {
    $currHost = strtolower((string)(parse_url(BASE_URL, PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'learn.ilmuna.my.id')));
    $isLocalLearn = ($currHost === 'localhost' || $currHost === '127.0.0.1');
    $resolvedSsoUrl = !empty($_SESSION['margo_sso_resolved_url'])
        ? (string)$_SESSION['margo_sso_resolved_url']
        : (($isLocalLearn && is_dir(dirname(__DIR__) . '/SSO'))
            ? ((strpos(BASE_URL, 'https://') === 0 ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/SSO')
            : (defined('ILMUNA_SSO_URL') ? ILMUNA_SSO_URL : 'https://margo-one.ilmuna.my.id'));
    $resolvedRedirectUri = !empty($_SESSION['margo_sso_redirect_uri'])
        ? (string)$_SESSION['margo_sso_redirect_uri']
        : ($isLocalLearn ? (BASE_URL . '/sso-callback.php') : (BASE_URL . '/sso-callback'));

    $client = new MargoOneSsoClient([
        'sso_url'         => $resolvedSsoUrl,
        'public_key'      => defined('ILMUNA_SSO_PUBLIC_KEY') ? ILMUNA_SSO_PUBLIC_KEY : '',
        'private_key'     => defined('ILMUNA_SSO_PRIVATE_KEY') ? ILMUNA_SSO_PRIVATE_KEY : '',
        'sig_mode'        => defined('ILMUNA_SSO_SIG_MODE') ? ILMUNA_SSO_SIG_MODE : 'HMAC-SHA256',
        'rsa_private_pem' => defined('ILMUNA_SSO_RSA_PRIVATE_PEM') ? ILMUNA_SSO_RSA_PRIVATE_PEM : '',
        'redirect_uri'    => $resolvedRedirectUri,
        'site_host'       => $isLocalLearn ? 'learn.ilmuna.my.id' : ($currHost ?: 'learn.ilmuna.my.id'),
    ]);

    $tokenData = $client->exchangeCode($code, $state);
    $claims    = $tokenData['id_token_claims'];
    $userId    = (int)($claims['sub'] ?? 0);

    if ($userId <= 0) {
        throw new RuntimeException('Identitas pengguna (sub) pada token SSO tidak valid.');
    }

    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException('Akun pengguna #' . $userId . ' tidak ditemukan di basis data Ilmuna Learn.');
    }

    // Inisialisasi sesi native Ilmuna Learn (51+ modul dashboard langsung berjalan mulus)
    $res = establishUserSession($pdo, $user);
    if (empty($res['success'])) {
        throw new RuntimeException($res['message'] ?? 'Gagal menginisialisasi sesi lokal.');
    }

    // Simpan metadata sesi Margo One untuk keperluan Single Logout (SLO) & Refresh Token
    $_SESSION['margo_sso_authenticated'] = true;
    $_SESSION['margo_sso_sid']           = (string)($tokenData['sid'] ?? ($claims['sid'] ?? ''));
    $_SESSION['margo_sso_refresh_token'] = (string)($tokenData['refresh_token'] ?? '');
    $_SESSION['margo_sso_access_token']  = (string)($tokenData['access_token'] ?? '');

    if (!empty($claims['active_role']) && hasRole((string)$claims['active_role'])) {
        setActiveRole((string)$claims['active_role']);
    }

    header('Location: ' . BASE_URL . '/gateway.php');
    exit;
} catch (Throwable $e) {
    error_log('[Ilmuna Learn SSO Callback] ' . $e->getMessage());
    header('Location: ' . BASE_URL . '/login.php?sso_error=' . urlencode('Autentikasi Margo One SSO gagal: ' . $e->getMessage()));
    exit;
}
