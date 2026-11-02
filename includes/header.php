<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — BASE TEMPLATE HEADER & TOPNAV
 * ==============================================================================
 * Diadaptasi dari base-template-dosen.code-snippets.php (Panel Guru & SIAKAD)
 * Tema Warna: Lumina Gold (Navy #1D3B5E, Teal #0D7A87, Sky #52B2DC, Gold #F59E0B)
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    exit;
}

$currentUser = getCurrentUser();
$activeRole  = $GLOBALS['activeRole'] ?? $activeRole ?? getActiveRole();
$userRoles   = getUserRoles();
$pageTitle   = $pageTitle ?? 'Dashboard';

$user_name  = $currentUser['full_name'] ?: $currentUser['username'];
$user_foto  = $currentUser['foto_profil'] ?? '';
$user_email = $currentUser['email'] ?? '';

// Generate Initials
$parts = preg_split('/\s+/', trim($user_name));
$inisial = '';
if (!empty($parts)) {
    $inisial .= mb_substr($parts[0], 0, 1);
    if (isset($parts[1])) {
        $inisial .= mb_substr($parts[1], 0, 1);
    }
}
$inisial = mb_strtoupper($inisial ?: mb_substr($user_name, 0, 2));

// ==============================================================================
// ROLE META — DIAMBIL DARI DATABASE (TIDAK HARDCODE)
// ==============================================================================
$roleMetaMap = [];

$__pick = function (array $row, array $keys, $default = null) {
    foreach ($keys as $k) {
        if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) {
            return $row[$k];
        }
    }
    return $default;
};

try {
    $roleRows = [];

    if (function_exists('getAllRoles')) {
        $roleRows = getAllRoles();
    } elseif (function_exists('db')) {
        $roleRows = db()->table('roles')->get();
        if (is_object($roleRows) && method_exists($roleRows, 'toArray')) {
            $roleRows = $roleRows->toArray();
        }
    } elseif (function_exists('getDB')) {
        $conn = getDB();
        if ($conn instanceof PDO) {
            $roleRows = $conn->query("SELECT * FROM roles")->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $roleRows = $pdo->query("SELECT * FROM roles")->fetchAll(PDO::FETCH_ASSOC);
    } elseif (isset($conn) && $conn instanceof PDO) {
        $roleRows = $conn->query("SELECT * FROM roles")->fetchAll(PDO::FETCH_ASSOC);
    } elseif (function_exists('mysqli_connect') && isset($GLOBALS['mysqli'])) {
        $res = $GLOBALS['mysqli']->query("SELECT * FROM roles");
        if ($res) {
            while ($r = $res->fetch_assoc()) $roleRows[] = $r;
        }
    }

    foreach ($roleRows as $row) {
        $slug = $__pick($row, ['slug', 'key', 'code', 'role_key', 'role_slug']);
        if (!$slug) continue;

        $roleMetaMap[$slug] = [
            'name'  => $__pick($row, ['name', 'display_name', 'label', 'title'], ucfirst($slug)),
            'badge' => $__pick($row, ['badge', 'description', 'short_name', 'short_label'], ucfirst($slug)),
            'icon'  => $__pick($row, ['icon', 'icon_name'], 'solar:user-bold'),
            'color' => $__pick($row, ['color', 'color_hex', 'brand_color'], '#52B2DC'),
        ];
    }
} catch (Throwable $e) {
    error_log('[Ilmuna] Gagal load role meta dari DB: ' . $e->getMessage());
}

if (empty($roleMetaMap)) {
    foreach ((array) $userRoles as $slug) {
        $roleMetaMap[$slug] = [
            'name'  => ucwords(str_replace(['_', '-'], ' ', $slug)),
            'badge' => 'Aktif',
            'icon'  => 'solar:user-bold',
            'color' => '#52B2DC',
        ];
    }
}

$__activeSlug = $activeRole ?? null;
if ($__activeSlug && !isset($roleMetaMap[$__activeSlug])) {
    $roleMetaMap[$__activeSlug] = [
        'name'  => ucwords(str_replace(['_', '-'], ' ', $__activeSlug)),
        'badge' => 'Aktif',
        'icon'  => 'solar:user-bold',
        'color' => '#52B2DC',
    ];
}

$currentRoleMeta = $roleMetaMap[$activeRole] ?? [
    'name'  => ucfirst($activeRole ?? 'Pengguna'),
    'badge' => 'Aktif',
    'icon'  => 'solar:user-bold',
    'color' => '#52B2DC'
];

$current_page_icon = $pageIcon ?? 'solar:widget-5-bold';

// ==============================================================================
// DETEKSI PELAKSANAAN ASESMEN SUMATIF HARI INI (KHUSUS ROLE ADMIN & GURU)
// Tombol Absensi Sumatif di header hanya muncul jika ada asesmen sumatif hari ini
// ==============================================================================
$showSumatifHeaderBtn = false;
$sumatifHeaderUrl     = '';

if (in_array($activeRole, ['admin', 'guru'], true)) {
    try {
        $dbHeader = function_exists('getDBConnection') ? getDBConnection() : ($GLOBALS['pdo'] ?? null);
        if ($dbHeader instanceof PDO) {
            $todayDate = date('Y-m-d');
            $stmtSumatifCek = $dbHeader->prepare("
                SELECT COUNT(*) 
                FROM asesmen_sumatif 
                WHERE tanggal_pelaksanaan = ? 
                  AND status != 'Ditutup'
            ");
            $stmtSumatifCek->execute([$todayDate]);
            $countSumatifToday = (int) $stmtSumatifCek->fetchColumn();

            if ($countSumatifToday > 0) {
                $showSumatifHeaderBtn = true;
                $sumatifHeaderUrl = ($activeRole === 'admin')
                    ? BASE_URL . '/dashboard/admin/absensi-sumatif'
                    : BASE_URL . '/dashboard/guru/absensi-sumatif';
            }
        }
    } catch (Throwable $eSumatif) {
        // Fallback aman jika query DB gagal
        $showSumatifHeaderBtn = false;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($pageTitle) ?> — Ilmuna Learn</title>

<?= renderFavicon() ?>

    <!-- Google Fonts: Outfit & Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<?= function_exists('renderIconifyScript') ? renderIconifyScript() : '    <script src="https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js" defer></script>' ?>

    <!-- Base LMS Stylesheet -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/lumina-lms.css">

    <!-- Ilmuna UI: Toast & Alert Modal (custom, prefix ilm-ui-*) -->
    <script>
        // Expose base URL agar Ilmuna.logout() otomatis tahu endpoint default
        window.ILMUNA_BASE_URL = '<?= BASE_URL ?>';
    </script>
    <script src="<?= BASE_URL ?>/assets/js/alert.js" defer></script>

<?php
// Universal Central UI Templates (Modal 28px, Calendar & Date-Time Picker, Data Table & Card Paginator)
if (function_exists('loadCentralTemplates')) {
    loadCentralTemplates(['modal', 'calendar', 'table']);
}
?>

    <style id="ilmuna-base-template-css">
    :root {
        --lumina-navy: #1D3B5E;
        --lumina-navy-rgb: 29, 59, 94;
        --lumina-teal: #0D7A87;
        --lumina-teal-rgb: 13, 122, 135;
        --lumina-sky: #52B2DC;
        --lumina-mint: #14B8A6;
        --lumina-mint-rgb: 20, 184, 166;
        --lumina-emerald: #10B981;
        --lumina-emerald-rgb: 16, 185, 129;
        --lumina-soft-emerald: #34D399;
        --lumina-soft-emerald-rgb: 52, 211, 153;

        --lumina-bg-canvas: #F8FAFC;
        --lumina-bg-card: #FFFFFF;
        --lumina-bg-card-hover: #FFFFFF;
        --lumina-bg-sidebar: rgba(255, 255, 255, 0.96);
        --lumina-bg-topbar: rgba(255, 255, 255, 0.90);
        --lumina-border: #E2E8F0;
        --lumina-border-subtle: #F1F5F9;
        --lumina-border-focus: rgba(13, 122, 135, 0.4);
        --lumina-border-mint: rgba(20, 184, 166, 0.35);

        --lumina-text-title: #0F1D2F;
        --lumina-text-body: #334155;
        --lumina-text-muted: #64748B;
        --lumina-text-light: #94A3B8;

        --lumina-shadow-xs: 0 1px 3px rgba(29, 59, 94, 0.05);
        --lumina-shadow-sm: 0 4px 12px -2px rgba(29, 59, 94, 0.06);
        --lumina-shadow-md: 0 10px 25px -4px rgba(29, 59, 94, 0.08);
        --lumina-shadow-lg: 0 20px 35px -8px rgba(13, 122, 135, 0.12);
        --lumina-shadow-glow: 0 0 24px rgba(82, 178, 220, 0.3);

        --radius-sm: 10px;
        --radius-md: 14px;
        --radius-lg: 20px;
        --radius-full: 9999px;
    }

    *, *::before, *::after { box-sizing: border-box; }

    iconify-icon {
        display: inline-block;
        width: 1em;
        height: 1em;
        vertical-align: -0.125em;
        fill: currentColor;
    }

    body {
        margin: 0;
        padding: 0;
        font-family: 'Plus Jakarta Sans', sans-serif;
        background-color: var(--lumina-bg-canvas);
        background-image:
            radial-gradient(at 0% 0%, rgba(82, 178, 220, 0.09) 0px, transparent 48%),
            radial-gradient(at 100% 0%, rgba(13, 122, 135, 0.07) 0px, transparent 45%),
            radial-gradient(at 50% 100%, rgba(29, 59, 94, 0.04) 0px, transparent 55%);
        background-attachment: fixed;
        color: var(--lumina-text-body);
        min-height: 100vh;
        overflow-x: hidden;
        -webkit-font-smoothing: antialiased;
    }

    .ilmuna-loader-bar {
        position: fixed;
        top: 0; left: 0;
        height: 3px;
        width: 0%;
        background: linear-gradient(90deg, #14B8A6, #52B2DC, #0D7A87);
        z-index: 10001;
        transition: width 0.3s ease, opacity 0.3s ease;
        box-shadow: 0 0 12px rgba(13, 122, 135, 0.5);
    }

    .ilmuna-loader-overlay {
        position: fixed;
        inset: 0;
        background: rgba(248, 250, 252, 0.85);
        backdrop-filter: blur(14px);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.35s ease;
    }
    .ilmuna-loader-overlay.active { opacity: 1; pointer-events: auto; }
    .ilmuna-loader-box {
        background: #FFFFFF;
        border: 1px solid var(--lumina-border);
        border-radius: 24px;
        padding: 34px 38px;
        text-align: center;
        box-shadow: 0 25px 60px -10px rgba(29, 59, 94, 0.15), 0 0 20px rgba(13, 122, 135, 0.08);
        max-width: 320px;
        width: 90%;
        animation: popIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .ilmuna-loader-anim { position: relative; width: 68px; height: 68px; margin: 0 auto 16px; }
    .ilmuna-loader-ring { position: absolute; inset: 0; border-radius: 50%; border: 2.5px solid transparent; }
    .ilmuna-loader-ring-1 { border-top-color: #0D7A87; border-right-color: #52B2DC; animation: spinClockwise 1.1s linear infinite; }
    .ilmuna-loader-ring-2 { inset: 7px; border-bottom-color: #14B8A6; border-left-color: #1D3B5E; animation: spinCounter 0.85s linear infinite; }
    .ilmuna-loader-emblem { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #0D7A87; }
    .ilmuna-loader-label { font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 16px; color: var(--lumina-text-title); margin-bottom: 4px; }
    .ilmuna-loader-subtext { font-size: 12px; color: var(--lumina-text-muted); margin-bottom: 16px; }
    .ilmuna-loader-track { height: 4px; background: #F1F5F9; border-radius: 999px; overflow: hidden; position: relative; }
    .ilmuna-loader-slider {
        position: absolute; top: 0; left: -40%; height: 100%; width: 40%;
        background: linear-gradient(90deg, #0D7A87, #52B2DC, #14B8A6);
        border-radius: 999px; animation: loaderSlide 1.2s ease-in-out infinite;
    }
    @keyframes spinClockwise { 100% { transform: rotate(360deg); } }
    @keyframes spinCounter { 100% { transform: rotate(-360deg); } }
    @keyframes loaderSlide { 0% { left: -40%; } 50% { left: 100%; width: 60%; } 100% { left: 100%; width: 40%; } }
    @keyframes popIn { from { transform: scale(0.92); opacity: 0; } to { transform: scale(1); opacity: 1; } }

    .ilmuna-mhs { display: flex; flex-direction: column; min-height: 100vh; background-color: transparent; }

    /* SIDEBAR */
    .ilmuna-sidebar {
        position: fixed; top: 0; left: 0; bottom: 0; width: 265px;
        background: var(--lumina-bg-sidebar);
        backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
        border-right: 1px solid var(--lumina-border);
        display: flex; flex-direction: column; z-index: 1000;
        box-shadow: 4px 0 24px rgba(29, 59, 94, 0.04);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .ilmuna-sidebar-header {
        padding: 22px 22px; border-bottom: 1px solid var(--lumina-border);
        flex-shrink: 0; background: rgba(255, 255, 255, 0.7);
    }
    .ilmuna-sidebar-brand { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; text-decoration: none; transition: transform 0.2s ease; }
    .ilmuna-sidebar-brand:hover { transform: translateY(-1px); }
    .ilmuna-sidebar-logo-wrap { display: flex; align-items: center; max-width: 100%; padding: 4px 0; }
    .ilmuna-sidebar-logo-img { max-width: 172px; height: auto; max-height: 52px; object-fit: contain; display: block; transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
    .ilmuna-sidebar-brand:hover .ilmuna-sidebar-logo-img { transform: scale(1.03); }
    .ilmuna-sidebar-brand-text { display: flex; flex-direction: column; }
    .ilmuna-sidebar-brand-role {
        display: inline-block; font-size: 10.5px; color: var(--lumina-teal);
        background: rgba(13, 122, 135, 0.08); border: 1px solid rgba(13, 122, 135, 0.2);
        padding: 2.5px 10px; border-radius: 999px;
        font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase;
    }

    .ilmuna-sidebar-menu {
        flex: 1; overflow-y: auto; overflow-x: hidden; padding: 18px 14px;
        scrollbar-width: thin; scrollbar-color: rgba(13, 122, 135, 0.15) transparent;
    }
    .ilmuna-sidebar-menu::-webkit-scrollbar { width: 5px; }
    .ilmuna-sidebar-menu::-webkit-scrollbar-thumb { background: rgba(13, 122, 135, 0.2); border-radius: 10px; }

    .ilmuna-menu-category {
        font-size: 10.5px; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.8px; color: #94A3B8; margin: 18px 0 6px 10px;
    }
    .ilmuna-menu-category:first-child { margin-top: 0; }

    .ilmuna-menu-item {
        display: flex; align-items: center; gap: 12px;
        padding: 10px 14px; border-radius: 12px;
        color: #475569; text-decoration: none !important;
        font-size: 13.5px; font-weight: 600; margin-bottom: 4px;
        transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1);
        cursor: pointer; position: relative;
    }
    .ilmuna-menu-item:hover { background: rgba(13, 122, 135, 0.06); color: var(--lumina-teal); transform: translateX(4px); }
    .ilmuna-menu-item:hover .ilmuna-menu-icon-box { background: rgba(13, 122, 135, 0.12); color: var(--lumina-teal); border-color: rgba(13, 122, 135, 0.3); transform: scale(1.08); }
    .ilmuna-menu-item.active {
        background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%);
        color: #FFFFFF !important; box-shadow: 0 6px 18px rgba(13, 122, 135, 0.25);
    }
    .ilmuna-menu-item.active .ilmuna-menu-icon-box { background: rgba(255, 255, 255, 0.2); color: #FFFFFF; border-color: rgba(255, 255, 255, 0.35); box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15); }

    .ilmuna-menu-icon-box {
        width: 34px; height: 34px; border-radius: 10px;
        background: #F1F5F9; border: 1px solid var(--lumina-border);
        display: flex; align-items: center; justify-content: center;
        color: #64748B; flex-shrink: 0;
        transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .ilmuna-menu-label { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ilmuna-menu-badge {
        font-size: 10px; font-weight: 800; padding: 2px 8px;
        border-radius: 999px;
        background: linear-gradient(135deg, #F59E0B, #D97706);
        color: #FFFFFF; box-shadow: 0 2px 6px rgba(245, 158, 11, 0.3);
    }

    .ilmuna-sidebar-footer {
        padding: 14px; border-top: 1px solid var(--lumina-border);
        flex-shrink: 0; background: rgba(248, 250, 252, 0.85);
    }
    .ilmuna-sidebar-user {
        display: flex; align-items: center; gap: 12px;
        padding: 9px 12px; border-radius: 12px;
        background: #FFFFFF; border: 1px solid var(--lumina-border);
        cursor: pointer; transition: all 0.25s ease;
        box-shadow: var(--lumina-shadow-xs);
    }
    .ilmuna-sidebar-user:hover { background: #FFFFFF; border-color: var(--lumina-sky); box-shadow: 0 4px 15px rgba(13, 122, 135, 0.12); transform: translateY(-1px); }
    .ilmuna-sidebar-user-avatar {
        width: 38px; height: 38px; border-radius: 11px;
        background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%);
        color: #ffffff; font-family: 'Outfit', sans-serif;
        font-weight: 700; font-size: 14px;
        display: flex; align-items: center; justify-content: center;
        overflow: hidden; border: 1.5px solid rgba(82, 178, 220, 0.35);
        box-shadow: 0 2px 8px rgba(13, 122, 135, 0.2);
    }
    .ilmuna-sidebar-user-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .ilmuna-sidebar-user-meta { flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .ilmuna-sidebar-user-name { font-size: 13px; font-weight: 700; color: var(--lumina-text-title); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ilmuna-sidebar-user-role { font-size: 11px; color: var(--lumina-text-muted); font-weight: 500; }
    .ilmuna-sidebar-user-logout { color: #EF4444; transition: transform 0.2s ease; }
    .ilmuna-sidebar-user:hover .ilmuna-sidebar-user-logout { transform: scale(1.15) rotate(5deg); }

    /* ==========================================================================
     * TOPBAR
     * ========================================================================== */
    .ilmuna-topnav {
        position: fixed; top: 0; right: 0; left: 265px; height: 68px;
        background: var(--lumina-bg-topbar);
        backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
        border-bottom: 1px solid var(--lumina-border);
        display: flex; align-items: center; justify-content: space-between;
        padding: 0 28px; z-index: 900;
        box-shadow: 0 2px 14px rgba(29, 59, 94, 0.04);
        transition: left 0.3s ease;
    }

    /* KIRI — Logo (selalu tampil, agak besar) */
    .ilmuna-nav-left {
        display: flex;
        align-items: center;
        gap: 16px;
    }
    .ilmuna-logo-brand {
        display: inline-flex;
        align-items: center;
        text-decoration: none !important;
        flex-shrink: 0;
        transition: opacity 0.2s ease, transform 0.2s ease;
    }
    .ilmuna-logo-brand:hover {
        opacity: 0.85;
        transform: translateY(-1px);
    }
    .ilmuna-logo-img {
        height: 48px;
        width: auto;
        max-width: 200px;
        object-fit: contain;
        display: block;
    }

    .ilmuna-nav-right { display: flex; align-items: center; gap: 14px; }
    
    /* TOPBAR ABSENSI SUMATIF BUTTON */
    .ilmuna-sumatif-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 6px 14px;
        border-radius: var(--radius-full);
        background: linear-gradient(135deg, rgba(13, 122, 135, 0.10) 0%, rgba(20, 184, 166, 0.14) 100%);
        border: 1px solid rgba(13, 122, 135, 0.32);
        color: #0D7A87 !important;
        font-size: 12px;
        font-weight: 700;
        text-decoration: none !important;
        box-shadow: 0 2px 8px rgba(13, 122, 135, 0.10);
        transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
        cursor: pointer;
    }
    .ilmuna-sumatif-btn:hover {
        background: linear-gradient(135deg, #0D7A87 0%, #0A6B75 100%);
        color: #FFFFFF !important;
        border-color: #0D7A87;
        transform: translateY(-1px);
        box-shadow: 0 4px 16px rgba(13, 122, 135, 0.28);
    }
    .ilmuna-sumatif-live-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #10B981;
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
        animation: ilmunaSumatifPulse 1.8s infinite;
        flex-shrink: 0;
    }
    .ilmuna-sumatif-btn:hover .ilmuna-sumatif-live-dot {
        background: #FFFFFF;
        box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.7);
    }
    @keyframes ilmunaSumatifPulse {
        0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
        100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }
    .ilmuna-sumatif-btn-badge {
        font-size: 9.5px;
        font-weight: 800;
        letter-spacing: 0.05em;
        padding: 2px 6px;
        border-radius: 999px;
        background: #F59E0B;
        color: #FFFFFF;
        line-height: 1;
        box-shadow: 0 1px 4px rgba(245, 158, 11, 0.35);
    }
    .ilmuna-sumatif-btn:hover .ilmuna-sumatif-btn-badge {
        background: #FFFFFF;
        color: #0D7A87;
    }

    .ilmuna-sumatif-btn-slot {
        display: inline-flex;
        align-items: center;
    }

    /* SMOOTH IN-PLACE DOM TRANSITIONS (README.md Section 4.4) */
    @keyframes rowSmoothIn {
        0% {
            opacity: 0;
            transform: translateY(-8px) scale(0.96);
        }
        100% {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }
    @keyframes rowSmoothOut {
        0% {
            opacity: 1;
            transform: scale(1);
        }
        100% {
            opacity: 0;
            transform: scale(0.95);
        }
    }
    .row-smooth-in {
        animation: rowSmoothIn 0.38s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    .row-smooth-out {
        animation: rowSmoothOut 0.28s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    }

    .ilmuna-date-chip {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 7px 14px; border-radius: var(--radius-full);
        background: #FFFFFF; border: 1px solid var(--lumina-border);
        color: #475569; font-size: 12px; font-weight: 600;
        box-shadow: var(--lumina-shadow-xs);
    }

    /* USER PROFILE PILL — tanpa badge/deskripsi */
    .ilmuna-user-pill {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 5px 14px 5px 6px;
        border-radius: var(--radius-full);
        background: #FFFFFF;
        border: 1px solid var(--lumina-border);
        cursor: pointer;
        transition: all 0.2s ease;
        box-shadow: var(--lumina-shadow-xs);
    }
    .ilmuna-user-pill:hover {
        border-color: var(--lumina-teal);
        box-shadow: 0 4px 14px rgba(13, 122, 135, 0.12);
        transform: translateY(-1px);
    }
    .ilmuna-user-avatar {
        position: relative;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%);
        border: 1.5px solid rgba(82, 178, 220, 0.4);
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: 'Outfit', sans-serif;
        font-size: 12.5px;
        font-weight: 700;
        color: #ffffff;
        box-shadow: 0 2px 6px rgba(13, 122, 135, 0.2);
        overflow: hidden;
        flex-shrink: 0;
    }
    .ilmuna-user-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .ilmuna-user-dot {
        position: absolute;
        bottom: 0; right: 0;
        width: 9px; height: 9px;
        background: #10B981;
        border: 2px solid #FFFFFF;
        border-radius: 50%;
    }
    .ilmuna-user-info {
        display: flex;
        align-items: center;
    }
    .ilmuna-user-name {
        font-size: 13px;
        font-weight: 700;
        color: var(--lumina-text-title);
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 140px;
    }
    .ilmuna-user-caret {
        color: #94A3B8;
        font-size: 12px;
        transition: transform 0.2s ease;
        flex-shrink: 0;
    }
    .ilmuna-user-pill:hover .ilmuna-user-caret {
        color: var(--lumina-teal);
        transform: translateY(2px);
    }
    .ilmuna-user-pill.is-open .ilmuna-user-caret {
        transform: rotate(180deg);
        color: var(--lumina-teal);
    }

    /* PROFILE DROPDOWN */
    .ilmuna-user-pill-wrap { position: relative; }
    .ilmuna-profile-dropdown {
        position: absolute;
        top: calc(100% + 12px); right: 0;
        width: 280px;
        background: #FFFFFF;
        border: 1px solid var(--lumina-border);
        border-radius: 20px;
        box-shadow: 0 22px 48px -12px rgba(29, 59, 94, 0.18), 0 0 22px rgba(13, 122, 135, 0.06);
        overflow: hidden;
        opacity: 0;
        transform: translateY(-10px) scale(0.96);
        pointer-events: none;
        transition: opacity 0.22s ease, transform 0.22s cubic-bezier(0.22, 1, 0.36, 1);
        transform-origin: top right;
        z-index: 1100;
    }
    .ilmuna-profile-dropdown.active { opacity: 1; transform: translateY(0) scale(1); pointer-events: auto; }

    .ilmuna-profile-head {
        padding: 22px 18px 18px; text-align: center;
        border-bottom: 1px solid var(--lumina-border-subtle);
        background: linear-gradient(180deg, rgba(82, 178, 220, 0.08) 0%, rgba(255, 255, 255, 0) 100%);
    }
    .ilmuna-profile-head-avatar {
        width: 58px; height: 58px; border-radius: 18px;
        background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%);
        color: #FFFFFF;
        font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 20px;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 12px;
        border: 2px solid #FFFFFF;
        box-shadow: 0 6px 16px rgba(13, 122, 135, 0.28);
        overflow: hidden; position: relative;
    }
    .ilmuna-profile-head-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .ilmuna-profile-head-avatar::after {
        content: ""; position: absolute; bottom: 0; right: 0;
        width: 12px; height: 12px; background: #10B981;
        border: 2.5px solid #FFFFFF; border-radius: 50%;
    }
    .ilmuna-profile-head-name {
        font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 14.5px;
        color: var(--lumina-text-title);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .ilmuna-profile-head-email {
        font-size: 11.5px; color: var(--lumina-text-muted); margin-top: 3px;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .ilmuna-profile-head-badge {
        display: inline-flex; align-items: center; gap: 5px;
        margin-top: 12px; font-size: 10px; font-weight: 800;
        text-transform: uppercase; letter-spacing: 0.06em;
        padding: 5px 12px; border-radius: 999px;
        background: rgba(13, 122, 135, 0.09);
        color: var(--lumina-teal);
        border: 1px solid rgba(13, 122, 135, 0.22);
    }

    .ilmuna-profile-menu { padding: 8px; }
    .ilmuna-profile-item {
        display: flex; align-items: center; gap: 11px;
        padding: 9px 12px; border-radius: 10px;
        color: var(--lumina-text-body); font-size: 13px; font-weight: 600;
        text-decoration: none !important; cursor: pointer;
        transition: all 0.18s ease;
        border: none; background: transparent;
        width: 100%; text-align: left; font-family: inherit;
    }
    .ilmuna-profile-item:hover { background: rgba(13, 122, 135, 0.07); color: var(--lumina-teal); }
    .ilmuna-profile-item:hover .ilmuna-profile-item-icon { color: var(--lumina-teal); transform: scale(1.08); }
    .ilmuna-profile-item:hover .ilmuna-profile-item-arrow { opacity: 1; transform: translateX(0); }
    .ilmuna-profile-item-icon {
        color: #64748B; display: flex; align-items: center; justify-content: center;
        flex-shrink: 0; transition: all 0.2s ease;
    }
    .ilmuna-profile-item-arrow {
        margin-left: auto; color: #94A3B8;
        opacity: 0; transform: translateX(-4px);
        transition: all 0.22s ease;
    }
    .ilmuna-profile-item.danger { color: #DC2626; }
    .ilmuna-profile-item.danger .ilmuna-profile-item-icon { color: #DC2626; }
    .ilmuna-profile-item.danger:hover { background: rgba(239, 68, 68, 0.08); color: #DC2626; }
    .ilmuna-profile-item.danger:hover .ilmuna-profile-item-icon { color: #DC2626; }
    .ilmuna-profile-item.danger:hover .ilmuna-profile-item-arrow { color: #DC2626; }
    .ilmuna-profile-item-divider { height: 1px; background: var(--lumina-border-subtle); margin: 6px 8px; }

    .ilmuna-profile-footer {
        padding: 11px 14px;
        border-top: 1px solid var(--lumina-border-subtle);
        text-align: center;
        font-size: 10.5px;
        color: var(--lumina-text-light);
        font-weight: 600;
        background: rgba(248, 250, 252, 0.7);
        letter-spacing: 0.02em;
    }

    .ilmuna-content-area {
        padding: 30px; padding-top: 96px; padding-bottom: 32px;
        margin-left: 265px; min-height: 100vh; flex: 1;
        animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

    .ilmuna-bottomnav {
        display: none; position: fixed; bottom: 0; left: 0; right: 0;
        padding: 10px 14px calc(10px + env(safe-area-inset-bottom, 0px));
        background: rgba(255, 255, 255, 0.94);
        backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
        border-top: 1px solid var(--lumina-border);
        z-index: 950; box-shadow: 0 -4px 20px rgba(29, 59, 94, 0.05);
    }
    .ilmuna-dock { display: flex; align-items: center; justify-content: space-around; width: 100%; max-width: 500px; margin: 0 auto; }
    .ilmuna-nav-item {
        display: flex; flex-direction: column; align-items: center; gap: 3px;
        color: #64748B; text-decoration: none; background: none; border: none;
        font-size: 10.5px; font-weight: 600; cursor: pointer;
        transition: all 0.2s ease; padding: 6px 10px; border-radius: 10px;
    }
    .ilmuna-nav-item:hover, .ilmuna-nav-item.active { color: var(--lumina-teal); background: rgba(13, 122, 135, 0.06); }
    .ilmuna-nav-item.active { color: var(--lumina-teal); font-weight: 700; }
    .ilmuna-nav-item.logout-btn { color: #EF4444; }

    .ilmuna-overlay {
        position: fixed; inset: 0;
        background: rgba(15, 29, 47, 0.35);
        backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
        z-index: 1050; opacity: 0; pointer-events: none;
        transition: opacity 0.25s ease;
    }
    .ilmuna-overlay.active { opacity: 1; pointer-events: auto; }

    .ilmuna-sheet {
        position: fixed; bottom: 0; left: 0; right: 0;
        background: #FFFFFF; border-top: 2px solid var(--lumina-teal);
        border-radius: 24px 24px 0 0;
        padding: 16px 20px calc(24px + env(safe-area-inset-bottom, 0px));
        z-index: 1100; transform: translateY(100%);
        transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
        max-height: 80vh; overflow-y: auto;
        box-shadow: 0 -10px 40px rgba(29, 59, 94, 0.15);
    }
    .ilmuna-sheet.active { transform: translateY(0); }
    .ilmuna-sheet-handle { width: 44px; height: 4px; border-radius: 999px; background: #CBD5E1; margin: 0 auto 16px; }
    .ilmuna-sheet-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px 10px; }
    .ilmuna-sheet-item {
        display: flex; flex-direction: column; align-items: center; gap: 6px;
        padding: 12px 6px; border-radius: 12px;
        background: #F8FAFC; border: 1px solid var(--lumina-border);
        color: var(--lumina-text-body); text-decoration: none;
        font-size: 11px; font-weight: 600; text-align: center;
        transition: all 0.2s ease;
    }
    .ilmuna-sheet-item iconify-icon { color: var(--lumina-teal); }
    .ilmuna-sheet-item:hover { background: rgba(13, 122, 135, 0.08); border-color: var(--lumina-sky); color: var(--lumina-teal); transform: translateY(-2px); }

    .ilmuna-modal {
        position: fixed; inset: 0;
        background: rgba(15, 29, 47, 0.45);
        backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
        display: flex; align-items: center; justify-content: center;
        z-index: 12000; opacity: 0; pointer-events: none;
        transition: opacity 0.25s ease; padding: 20px;
    }
    .ilmuna-modal.active { opacity: 1; pointer-events: auto; }
    .ilmuna-popup {
        background: #FFFFFF; border: 1px solid var(--lumina-border);
        border-radius: 22px; padding: 32px 28px; text-align: center;
        max-width: 380px; width: 100%;
        box-shadow: 0 25px 60px -10px rgba(29, 59, 94, 0.2), 0 0 25px rgba(13, 122, 135, 0.08);
        transform: scale(0.92);
        transition: transform 0.25s cubic-bezier(0.22, 1, 0.36, 1);
    }
    .ilmuna-modal.active .ilmuna-popup { transform: scale(1); }
    .ilmuna-popup-icon {
        width: 64px; height: 64px;
        background: rgba(239, 68, 68, 0.1);
        border: 1px solid rgba(239, 68, 68, 0.25);
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        margin: 0 auto 16px; color: #EF4444; font-size: 28px;
    }
    .ilmuna-popup h3 { font-family: 'Outfit', sans-serif; font-size: 19px; color: var(--lumina-text-title); margin-bottom: 8px; font-weight: 700; }
    .ilmuna-popup p { font-size: 13.5px; color: var(--lumina-text-muted); margin-bottom: 24px; line-height: 1.5; }
    .ilmuna-popup-btns { display: flex; gap: 12px; }
    .ilmuna-popup-btns button, .ilmuna-popup-btns a {
        flex: 1; padding: 11px 16px; border-radius: 12px;
        font-size: 13.5px; font-weight: 700; cursor: pointer;
        border: none; text-decoration: none; text-align: center;
        transition: all 0.2s ease;
    }
    .ilmuna-btn-cancel { background: #F1F5F9; color: #475569; border: 1px solid var(--lumina-border); }
    .ilmuna-btn-cancel:hover { background: #E2E8F0; color: var(--lumina-text-title); }
    .ilmuna-btn-confirm {
        background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
        color: #FFFFFF; box-shadow: 0 4px 14px rgba(220, 38, 38, 0.3);
    }
    .ilmuna-btn-confirm:hover { background: #B91C1C; transform: translateY(-1px); box-shadow: 0 6px 18px rgba(220, 38, 38, 0.4); }

    @media (max-width: 768px) {
        .ilmuna-sidebar { display: none !important; }
        .ilmuna-topnav { left: 0 !important; padding: 0 16px; }
        .ilmuna-logo-img { height: 40px; max-width: 160px; }
        .ilmuna-date-chip { display: none !important; }
        .ilmuna-sumatif-btn {
            padding: 7px 10px;
            gap: 6px;
        }
        .ilmuna-sumatif-btn-text,
        .ilmuna-sumatif-btn-badge {
            display: none !important;
        }
        .ilmuna-user-info { display: none !important; }
        .ilmuna-user-caret { display: none !important; }
        .ilmuna-content-area {
            margin-left: 0 !important; padding: 16px; padding-top: 84px;
            padding-bottom: calc(75px + env(safe-area-inset-bottom, 0px)) !important;
        }
        .ilmuna-bottomnav { display: block !important; }

        .ilmuna-profile-dropdown {
            position: fixed;
            top: 76px; right: 14px; left: auto;
            width: calc(100vw - 28px);
            max-width: 320px;
        }
    }
    </style>

    <?php if (!empty($extraCss)): ?>
        <style id="ilmuna-page-extra-css"><?= $extraCss ?></style>
    <?php endif; ?>
    <?php if (!empty($extraHead)): ?>
        <?= $extraHead ?>
    <?php endif; ?>
</head>
<body>

<div id="ilmunaToastContainer" class="ilmuna-toast-container"></div>
<div id="ilmunaLoaderBar" class="ilmuna-loader-bar"></div>

<div id="ilmunaLoaderOverlay" class="ilmuna-loader-overlay">
    <div class="ilmuna-loader-box">
        <div class="ilmuna-loader-anim">
            <div class="ilmuna-loader-ring ilmuna-loader-ring-1"></div>
            <div class="ilmuna-loader-ring ilmuna-loader-ring-2"></div>
            <div class="ilmuna-loader-emblem">
                <iconify-icon icon="solar:square-academic-cap-bold" width="26"></iconify-icon>
            </div>
        </div>
        <div class="ilmuna-loader-label">Memuat Halaman...</div>
        <div class="ilmuna-loader-subtext">Ilmuna Learn LMS & SIAKAD</div>
        <div class="ilmuna-loader-track"><div class="ilmuna-loader-slider"></div></div>
    </div>
</div>

<div class="ilmuna-mhs">
    <?php require_once BASE_PATH . '/includes/sidebar.php'; ?>

    <header class="ilmuna-topnav">
        <div class="ilmuna-nav-left">
            <a href="<?= BASE_URL ?>/" class="ilmuna-logo-brand" aria-label="Ilmuna Learn">
                <img src="<?= BASE_URL ?>/assets/images/logo-learn.png" alt="Ilmuna Learn Logo" class="ilmuna-logo-img">
            </a>
        </div>

        <div class="ilmuna-nav-right">
            <!-- Slot Tombol Absensi Sumatif (Lazy Load on-demand & In-Place DOM Mutation) -->
            <div id="ilmunaSumatifBtnSlot" class="ilmuna-sumatif-btn-slot">
                <?php if (!empty($showSumatifHeaderBtn)): ?>
                    <a href="<?= htmlspecialchars($sumatifHeaderUrl) ?>" class="ilmuna-sumatif-btn" id="ilmunaHeaderSumatifBtn" title="Absensi Asesmen Sumatif Hari Ini">
                        <span class="ilmuna-sumatif-live-dot"></span>
                        <iconify-icon icon="solar:scanner-bold" width="16"></iconify-icon>
                        <span class="ilmuna-sumatif-btn-text">Absensi Sumatif</span>
                        <span class="ilmuna-sumatif-btn-badge">HARI INI</span>
                    </a>
                <?php endif; ?>
            </div>

            <div class="ilmuna-date-chip">
                <iconify-icon icon="solar:calendar-bold" width="14"></iconify-icon>
                <span id="ilmunaDateNow"><?= date('d M Y') ?></span>
            </div>

            <div class="ilmuna-user-pill-wrap" id="ilmunaProfileWrap">
                <div class="ilmuna-user-pill" id="ilmunaUserPill" title="Kelola Akun / Keluar" role="button" tabindex="0" aria-haspopup="true" aria-expanded="false">
                    <div class="ilmuna-user-avatar">
                        <?php if (!empty($user_foto)): ?>
                            <img src="<?= BASE_URL ?>/<?= htmlspecialchars($user_foto) ?>" alt="Avatar">
                        <?php else: ?>
                            <?= htmlspecialchars($inisial) ?>
                        <?php endif; ?>
                        <span class="ilmuna-user-dot"></span>
                    </div>
                    <div class="ilmuna-user-info">
                        <span class="ilmuna-user-name"><?= htmlspecialchars($user_name) ?></span>
                    </div>
                    <iconify-icon icon="solar:alt-arrow-down-bold" width="12" class="ilmuna-user-caret"></iconify-icon>
                </div>

                <div class="ilmuna-profile-dropdown" id="ilmunaProfileDropdown" role="menu" aria-label="Menu Profil">
                    <div class="ilmuna-profile-head">
                        <div class="ilmuna-profile-head-avatar">
                            <?php if (!empty($user_foto)): ?>
                                <img src="<?= BASE_URL ?>/<?= htmlspecialchars($user_foto) ?>" alt="Avatar">
                            <?php else: ?>
                                <?= htmlspecialchars($inisial) ?>
                            <?php endif; ?>
                        </div>
                        <div class="ilmuna-profile-head-name"><?= htmlspecialchars($user_name) ?></div>
                        <div class="ilmuna-profile-head-email"><?= htmlspecialchars($user_email ?: 'Belum ada email') ?></div>
                        <span class="ilmuna-profile-head-badge">
                            <iconify-icon icon="<?= htmlspecialchars($currentRoleMeta['icon']) ?>" width="12"></iconify-icon>
                            <?= htmlspecialchars($currentRoleMeta['name']) ?>
                        </span>
                    </div>

                    <div class="ilmuna-profile-menu">
                        <a href="<?= BASE_URL ?>/profile" class="ilmuna-profile-item" role="menuitem">
                            <span class="ilmuna-profile-item-icon">
                                <iconify-icon icon="solar:user-circle-bold" width="18"></iconify-icon>
                            </span>
                            <span>Profile Saya</span>
                            <iconify-icon icon="solar:alt-arrow-right-bold" width="14" class="ilmuna-profile-item-arrow"></iconify-icon>
                        </a>

                        <a href="<?= BASE_URL ?>/settings.php" class="ilmuna-profile-item" role="menuitem">
                            <span class="ilmuna-profile-item-icon">
                                <iconify-icon icon="solar:settings-bold" width="18"></iconify-icon>
                            </span>
                            <span>Pengaturan</span>
                            <iconify-icon icon="solar:alt-arrow-right-bold" width="14" class="ilmuna-profile-item-arrow"></iconify-icon>
                        </a>

                        <a href="<?= BASE_URL ?>/gateway" class="ilmuna-profile-item" role="menuitem">
                            <span class="ilmuna-profile-item-icon">
                                <iconify-icon icon="solar:widget-5-bold" width="18"></iconify-icon>
                            </span>
                            <span>Gateway</span>
                            <iconify-icon icon="solar:alt-arrow-right-bold" width="14" class="ilmuna-profile-item-arrow"></iconify-icon>
                        </a>

                        <div class="ilmuna-profile-item-divider"></div>

                        <button type="button"
                                class="ilmuna-profile-item danger"
                                id="ilmunaLogoutBtn"
                                role="menuitem"
                                data-ilm-logout="<?= BASE_URL ?>/logout.php">
                            <span class="ilmuna-profile-item-icon">
                                <iconify-icon icon="solar:logout-2-bold" width="18"></iconify-icon>
                            </span>
                            <span>Keluar</span>
                            <iconify-icon icon="solar:alt-arrow-right-bold" width="14" class="ilmuna-profile-item-arrow"></iconify-icon>
                        </button>
                    </div>

                    <div class="ilmuna-profile-footer">
                        Ilmuna Learn · v1.0 · LMS &amp; SIAKAD
                    </div>
                </div>
            </div>
        </div>
    </header>

    <script>
    (function () {
        var wrap      = document.getElementById('ilmunaProfileWrap');
        var pill      = document.getElementById('ilmunaUserPill');
        var dropdown  = document.getElementById('ilmunaProfileDropdown');
        var logoutBtn = document.getElementById('ilmunaLogoutBtn');
        if (!wrap || !pill || !dropdown) return;

        function openDropdown() {
            dropdown.classList.add('active');
            pill.classList.add('is-open');
            pill.setAttribute('aria-expanded', 'true');
        }
        function closeDropdown() {
            dropdown.classList.remove('active');
            pill.classList.remove('is-open');
            pill.setAttribute('aria-expanded', 'false');
        }
        function toggleDropdown() {
            dropdown.classList.contains('active') ? closeDropdown() : openDropdown();
        }

        pill.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleDropdown();
        });
        pill.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleDropdown();
            }
        });

        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) closeDropdown();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeDropdown();
        });

        // ==================================================================
        // LOGOUT — pakai Ilmuna.logout() (Nusantara Touch modal)
        // Fallback berlapis: Ilmuna.logout() → ilmunaShowLogout() → confirm()
        // ==================================================================
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();   // cegah auto-bind alert.js (data-ilm-logout) ikut jalan
                closeDropdown();

                var logoutUrl = '<?= BASE_URL ?>/logout.php';

                if (window.Ilmuna && typeof window.Ilmuna.logout === 'function') {
                    // Moderen: pakai alert modal Nusantara Touch
                    window.Ilmuna.logout({
                        url: logoutUrl,
                        title: 'Keluar dari Akun?',
                        message: 'Anda akan keluar dari sesi ini. Pastikan semua perubahan sudah tersimpan.',
                        confirmText: 'Ya, Keluar',
                        cancelText: 'Batal',
                        type: 'logout'
                    });
                } else if (typeof window.ilmunaShowLogout === 'function') {
                    // Fallback: popup modal lama (jika alert.js gagal dimuat)
                    window.ilmunaShowLogout();
                } else if (typeof window.ilmunaShowLogout === 'function') {
                    window.ilmunaShowLogout();
                } else {
                    // Fallback langsung redirect
                    window.location.href = logoutUrl;
                }
            });
        }

        // ==================================================================
        // LAZY LOAD ON-DEMAND: ABSENSI SUMATIF HEADER BUTTON (README.md Section 4.4)
        // DILARANG REFRESH BERKALA (Anti-Polling / Zero Interval).
        // Dimuat malas on-demand saat browser idle / visibilitychange,
        // dengan mutasi DOM in-place halus (.row-smooth-in / .row-smooth-out).
        // ==================================================================
        var activeUserRole = '<?= htmlspecialchars($activeRole ?? "") ?>';
        if (activeUserRole === 'admin' || activeUserRole === 'guru') {
            function lazyLoadHeaderSumatif() {
                var slot = document.getElementById('ilmunaSumatifBtnSlot');
                if (!slot) return;

                var endpoint = (window.ILMUNA_BASE_URL || '') + '/api/check-sumatif-today.php';
                fetch(endpoint, {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-cache'
                })
                .then(function(res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(function(data) {
                    var existingBtn = document.getElementById('ilmunaHeaderSumatifBtn');
                    if (data && data.success && data.has_sumatif && data.url) {
                        if (!existingBtn) {
                            // Sisipkan in-place dengan animasi halus .row-smooth-in
                            var btnHtml = '<a href="' + data.url + '" class="ilmuna-sumatif-btn row-smooth-in" id="ilmunaHeaderSumatifBtn" title="Absensi Asesmen Sumatif Hari Ini">' +
                                '<span class="ilmuna-sumatif-live-dot"></span>' +
                                '<iconify-icon icon="solar:scanner-bold" width="16"></iconify-icon>' +
                                '<span class="ilmuna-sumatif-btn-text">Absensi Sumatif</span>' +
                                '<span class="ilmuna-sumatif-btn-badge">HARI INI</span>' +
                            '</a>';
                            slot.innerHTML = btnHtml;
                        } else {
                            existingBtn.setAttribute('href', data.url);
                            existingBtn.style.display = 'inline-flex';
                        }
                    } else {
                        // Jika tidak ada pelaksanaan, hapus in-place secara halus
                        if (existingBtn) {
                            existingBtn.classList.add('row-smooth-out');
                            setTimeout(function() {
                                if (slot) slot.innerHTML = '';
                            }, 280);
                        }
                    }
                })
                .catch(function() {
                    // Fallback diam tanpa error
                });
            }

            // Lazy Load saat browser idle (bukan polling berulang)
            if (window.requestIdleCallback) {
                window.requestIdleCallback(function() {
                    lazyLoadHeaderSumatif();
                });
            } else {
                setTimeout(lazyLoadHeaderSumatif, 200);
            }

            // Update on-demand hanya saat pengguna kembali membuka/fokus ke tab ini
            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'visible') {
                    lazyLoadHeaderSumatif();
                }
            });
        }
    })();
    </script>
    <main class="ilmuna-content-area">