<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — LAZY LOAD API: CHECK ASESMEN SUMATIF HARI INI
 * ==============================================================================
 * Endpoint asinkron ringan (on-demand) untuk header topbar.
 * Hanya berlaku untuk role: admin & guru.
 * ==============================================================================
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'has_sumatif' => false, 'message' => 'Unauthorized']);
    exit;
}

$activeRole = getActiveRole();
if (!in_array($activeRole, ['admin', 'guru'], true)) {
    echo json_encode(['success' => true, 'has_sumatif' => false]);
    exit;
}

try {
    $pdo = getDBConnection();
    $todayDate = date('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM asesmen_sumatif 
        WHERE tanggal_pelaksanaan = ? 
          AND status != 'Ditutup'
    ");
    $stmt->execute([$todayDate]);
    $count = (int) $stmt->fetchColumn();

    $hasSumatif = ($count > 0);
    $url = '';
    if ($hasSumatif) {
        $url = ($activeRole === 'admin')
            ? BASE_URL . '/dashboard/admin/absensi-sumatif'
            : BASE_URL . '/dashboard/guru/absensi-sumatif';
    }

    echo json_encode([
        'success'     => true,
        'has_sumatif' => $hasSumatif,
        'count'       => $count,
        'url'         => $url,
        'role'        => $activeRole
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success'     => false,
        'has_sumatif' => false,
        'error'       => $e->getMessage()
    ]);
}

