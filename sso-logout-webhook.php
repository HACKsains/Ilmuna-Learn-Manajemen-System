<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — MARGO ONE BACK-CHANNEL SINGLE LOGOUT (SLO) WEBHOOK RECEIVER
 * ==============================================================================
 * Letakkan di root c:\laragon\www\learn\sso-logout-webhook.php
 * Menerima notifikasi logout bertanda tangan dari https://margo-one.ilmuna.my.id
 * saat pengguna keluar dari Margo One / WordPress / perangkat lain.
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sdk/ilmuna-sso-client.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

try {
    $client = new MargoOneSsoClient([
        'sso_url'     => defined('ILMUNA_SSO_URL') ? ILMUNA_SSO_URL : 'https://margo-one.ilmuna.my.id',
        'public_key'  => defined('ILMUNA_SSO_PUBLIC_KEY') ? ILMUNA_SSO_PUBLIC_KEY : '',
        'private_key' => defined('ILMUNA_SSO_PRIVATE_KEY') ? ILMUNA_SSO_PRIVATE_KEY : '',
    ]);

    $ver = $client->handleLogoutWebhook();
    if (empty($ver['ok'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => $ver['code'], 'message' => $ver['message']]);
        exit;
    }

    // Catat pencabutan sesi ke tabel lokal agar middleware auth.php langsung menolak sesi terkait
    $pdo = getDBConnection();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS margo_slo_revocations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            sso_session_id BIGINT NULL,
            user_id INT NULL,
            revoked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_slo_sid (sso_session_id),
            INDEX idx_slo_uid (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->prepare("INSERT INTO margo_slo_revocations (sso_session_id, user_id) VALUES (?, ?)");
    $stmt->execute([
        !empty($ver['sid']) ? (int)$ver['sid'] : null,
        !empty($ver['user_id']) ? (int)$ver['user_id'] : null,
    ]);

    // Pruning ringan entri > 7 hari
    if (mt_rand(1, 20) === 1) {
        $pdo->exec("DELETE FROM margo_slo_revocations WHERE revoked_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    }

    echo json_encode(['success' => true, 'message' => 'SLO revocation dicatat.']);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'INTERNAL_ERROR', 'message' => $e->getMessage()]);
    exit;
}
