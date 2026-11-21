<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — LOGOUT ACTION
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (defined('ILMUNA_SSO_ENABLED') && ILMUNA_SSO_ENABLED) {
    $ssoBaseUrl = defined('ILMUNA_SSO_URL') && ILMUNA_SSO_URL !== ''
        ? ILMUNA_SSO_URL
        : 'https://margo-one.ilmuna.my.id';
    $sloUrl = rtrim($ssoBaseUrl, '/') . '/logout?post_logout_redirect_uri=' . urlencode(BASE_URL . '/login.php?msg=logged_out');
    logoutUser();
    header('Location: ' . $sloUrl);
    exit;
}

logoutUser();

header("Location: " . BASE_URL . "/login.php?msg=logged_out");
exit;
