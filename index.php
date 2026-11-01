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
    header("Location: " . BASE_URL . "/login");
    exit;
}
