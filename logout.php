<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — GLOBAL SINGLE LOGOUT (SLO) ACTION
 * ==============================================================================
 * Ketika pengguna logout di Ilmuna Learn:
 *   1. Mencatat pencabutan sesi ke margo_slo_revocations & sso_sessions
 *   2. Memicu Back-Channel Logout ke Margo One SSO (/api/sso/logout-callback.php)
 *   3. Menghapus sesi PHP lokal Ilmuna Learn
 *   4. Mengarahkan browser ke Margo One SSO (/logout?all=1&client_id=...&sig=...)
 *      agar sesi di Margo One SSO & seluruh aplikasi terhubung otomatis keluar!
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$sid = isset($_SESSION['margo_sso_sid']) ? (int)$_SESSION['margo_sso_sid'] : 0;

// 1. Catat pencabutan sesi langsung di database agar tab/aplikasi lain otomatis ter-logout
if ($uid > 0 || $sid > 0) {
    try {
        $pdo = getDBConnection();
        $dbs = ['', '`uofruaju_margo_one`.'];
        foreach ($dbs as $pfx) {
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS {$pfx}`margo_slo_revocations` (
                        id BIGINT AUTO_INCREMENT PRIMARY KEY,
                        sso_session_id BIGINT NULL,
                        user_id INT NULL,
                        revoked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_slo_sid (sso_session_id),
                        INDEX idx_slo_uid (user_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                $st = $pdo->prepare("INSERT INTO {$pfx}`margo_slo_revocations` (sso_session_id, user_id, revoked_at) VALUES (?, ?, NOW())");
                $st->execute([$sid > 0 ? $sid : null, $uid > 0 ? $uid : null]);

                if ($uid > 0) {
                    $stS = $pdo->prepare("UPDATE {$pfx}`sso_sessions` SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
                    @$stS->execute([$uid]);
                } elseif ($sid > 0) {
                    $stS = $pdo->prepare("UPDATE {$pfx}`sso_sessions` SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL");
                    @$stS->execute([$sid]);
                }
            } catch (Throwable $ePfx) {
                // Abaikan bila DB prefix tidak tersedia
            }
        }
    } catch (Throwable $e) {
        // Non-fatal
    }
}

$ssoEnabled = (defined('ILMUNA_SSO_ENABLED') && ILMUNA_SSO_ENABLED)
    || !empty($_SESSION['margo_sso_authenticated'])
    || $sid > 0;

$sloRedirectUrl = BASE_URL . '/login.php?msg=logged_out';

if ($ssoEnabled) {
    $ssoBaseUrl = (defined('ILMUNA_SSO_URL') && ILMUNA_SSO_URL !== '')
        ? rtrim(ILMUNA_SSO_URL, '/')
        : 'https://margo-one.ilmuna.my.id';

    if (file_exists(__DIR__ . '/sdk/ilmuna-sso-client.php')) {
        require_once __DIR__ . '/sdk/ilmuna-sso-client.php';
        try {
            $client = new MargoOneSsoClient([
                'sso_url'     => $ssoBaseUrl,
                'public_key'  => defined('ILMUNA_SSO_PUBLIC_KEY') ? ILMUNA_SSO_PUBLIC_KEY : '',
                'private_key' => defined('ILMUNA_SSO_PRIVATE_KEY') ? ILMUNA_SSO_PRIVATE_KEY : '',
            ]);
            if ($uid > 0 || $sid > 0) {
                @$client->triggerBackchannelLogout($sid, $uid > 0 ? $uid : null, true);
            }
            $sloRedirectUrl = $client->buildLogoutUrl('', true, $uid, $sid);
        } catch (Throwable $eSdk) {
            $sloRedirectUrl = $ssoBaseUrl . '/logout?all=1';
        }
    } else {
        $sloRedirectUrl = $ssoBaseUrl . '/logout?all=1';
    }
}

// 2. Hapus sesi lokal Ilmuna Learn
logoutUser();

// 3. Arahkan ke Margo One SSO /logout (atau login lokal bila SSO nonaktif)
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: ' . $sloRedirectUrl);
exit;
