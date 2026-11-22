<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — MAIN ENTRY ROUTER
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (isLoggedIn()) {
    $activeRole = getActiveRole();
    if ($activeRole) {
        header("Location: " . getRoleDashboardUrl($activeRole));
    } else {
        header("Location: " . BASE_URL . "/gateway");
    }
    exit;
} else {
    $referer = strtolower((string)($_SERVER['HTTP_REFERER'] ?? ''));
    $fromSso = isset($_GET['sso_login'])
        || strpos($referer, 'margo-one.ilmuna.my.id') !== false
        || strpos($referer, '/sso') !== false;
    if ($fromSso && defined('ILMUNA_SSO_ENABLED') && ILMUNA_SSO_ENABLED) {
        header("Location: " . BASE_URL . "/login.php?sso_login=1");
        exit;
    }
    header("Location: " . BASE_URL . "/login");
    exit;
}
