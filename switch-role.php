<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — FAST ROLE SWITCHER ACTION
 * ==============================================================================
 * Memungkinkan pengguna dengan multi-role berpindah peran langsung dari topbar
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

requireAuth();

$targetRole = $_GET['role'] ?? '';

if (!empty($targetRole) && hasRole($targetRole)) {
    setActiveRole($targetRole);
    header("Location: " . getRoleDashboardUrl($targetRole));
    exit;
} else {
    header("Location: " . BASE_URL . "/gateway.php");
    exit;
}
