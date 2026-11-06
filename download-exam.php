<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PORTAL UNDUH RESMI ILMUNA EXAM BROWSER (CBT KIOSK)
 * ==============================================================================
 * Lokasi: download-exam.php
 * Halaman unduh resmi aplikasi Android Exam Browser dengan rincian path,
 * keterangan teknis, spesifikasi, dan catatan perubahan (changelog) komprehensif.
 *
 * Standar Desain (Sesuai README.md):
 * - Tema Lumina Gold: Navy (#1D3B5E), Teal (#0D7A87), Sky Blue (#52B2DC), Gold (#F59E0B)
 * - Ornamen Nusantara: Watermark Gunungan Wayang & Pola Batik Kawung
 * - Standar Tab Konsisten (README.md Bagian 4.1)
 * - Zero Page Reload & In-Place Interaction (README.md Bagian 4.4)
 * - Ikon Sentral: Iconify Solar Icons
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Inisialisasi URL dan Path Sistem
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
if (empty($baseUrl)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $baseUrl = $protocol . $host . (trim($scriptDir, '/') !== '' ? '/' . trim($scriptDir, '/') : '');
}

$changelogPath = __DIR__ . '/assets/apk/changelog.json';

// 2. Baca Data Changelog & Metadata APK
$appData = [
    'versionCode'   => 4,
    'versionName'   => '1.1.3',
    'releaseDate'   => '06 November 2026',
    'minSdk'        => 'Android 7.0 (Nougat, API 24)',
    'targetSdk'     => 'Android 15 (API 37)',
    'fileSize'      => '5.82 MB',
    'fileName'      => 'ilmuna-exam-browser-release.apk',
    'sha256'        => '19907B6BCB99010A90F0A4B57FB2876816EC41696EEBBA4C1BAEA35FC3AAA9F5',
    'supervisorPin' => '123456',
    'releases'      => []
];

if (file_exists($changelogPath)) {
    $rawChangelog = @file_get_contents($changelogPath);
    if (!empty($rawChangelog)) {
        $parsed = json_decode($rawChangelog, true);
        if (is_array($parsed)) {
            $appData = array_merge($appData, $parsed);
        }
    }
}

// Tentukan berkas APK aktual yang tersedia di folder assets/apk/
$apkFileName = !empty($appData['fileName']) ? basename($appData['fileName']) : 'ilmuna-exam-browser-release.apk';
if (!file_exists(__DIR__ . '/assets/apk/' . $apkFileName)) {
    if (file_exists(__DIR__ . '/assets/apk/ilmuna-exam-browser-release.apk')) {
        $apkFileName = 'ilmuna-exam-browser-release.apk';
    } elseif (file_exists(__DIR__ . '/assets/apk/android-exam-browser.apk')) {
        $apkFileName = 'android-exam-browser.apk';
    } elseif (file_exists(__DIR__ . '/assets/apk/ilmuna-exam-browser.apk')) {
        $apkFileName = 'ilmuna-exam-browser.apk';
    }
}

$apkRelativePath  = '/assets/apk/' . $apkFileName;
$apkPhysicalPath  = __DIR__ . $apkRelativePath;

// Periksa keberadaan berkas fisik APK secara aktual & hitung ukuran presisi
$hasApk = file_exists($apkPhysicalPath);
if ($hasApk) {
    $actualBytes = @filesize($apkPhysicalPath);
    if ($actualBytes > 0) {
        $appData['fileSize'] = round($actualBytes / (1024 * 1024), 2) . ' MB';
    }
    $appData['sha256'] = strtoupper(hash_file('sha256', $apkPhysicalPath));
    $appData['lastModified'] = date('d M Y, H:i', filemtime($apkPhysicalPath)) . ' WIB';
} else {
    $appData['lastModified'] = 'Belum tersedia di server';
}

$apkDownloadUrl  = $baseUrl . $apkRelativePath;
$examGatewayUrl  = $baseUrl . '/exam';
$checkUpdateUrl  = $baseUrl . '/api/check-update.php';
$freezeApiUrl    = $baseUrl . '/api/cbt-freeze.php';
$sourceCodePath  = 'android-exam-browser/';

// QR Code URL generator untuk pemindaian instan smartphone
$qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=10&color=1D3B5E&bgcolor=FFFFFF&data=' . urlencode($apkDownloadUrl);

// Informasi Sesi Pengguna jika Sedang Login
$isLoggedIn = !empty($_SESSION['user']['id']);
$userRole   = $_SESSION['user']['role'] ?? '';
$dashboardUrl = $baseUrl ? ($baseUrl . '/dashboard') : '/dashboard';
if ($userRole === 'admin') {
    $dashboardUrl = $baseUrl . '/dashboard/admin';
} elseif ($userRole === 'guru') {
    $dashboardUrl = $baseUrl . '/dashboard/guru';
} elseif ($userRole === 'siswa') {
    $dashboardUrl = $baseUrl . '/dashboard/siswa';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Unduh Ilmuna Exam Browser (CBT Kiosk) — <?= htmlspecialchars(APP_NAME) ?></title>
    
    <!-- Favicon Sentral -->
    <?= renderFavicon() ?>

    <!-- Google Fonts: Plus Jakarta Sans & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Iconify Solar Icons -->
    <script src="https://code.iconify.design/iconify-icon/1.0.8/iconify-icon.min.js"></script>

    <!-- SweetAlert2 & Alert System Ilmuna -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="<?= $baseUrl ?>/assets/js/alert.js" defer></script>

    <style>
        :root {
            /* Lumina Gold & Nusantara Palette (README.md Section 3.1) */
            --navy: #1D3B5E;
            --navy-dark: #13273F;
            --navy-light: #2A5384;
            --teal: #0D7A87;
            --teal-dark: #095963;
            --teal-light: #129EAE;
            --sky: #52B2DC;
            --sky-fade: rgba(82, 178, 220, 0.12);
            --gold: #F59E0B;
            --gold-dark: #D97706;
            --gold-fade: rgba(245, 158, 11, 0.12);
            --emerald: #10B981;
            --rose: #EF4444;

            --bg-page: #F8FAFC;
            --card-bg: #FFFFFF;
            --border-color: #E2E8F0;
            --border-hover: #CBD5E1;
            
            --text-main: #0F172A;
            --text-muted: #64748B;
            --text-subtle: #94A3B8;

            --font-main: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --font-mono: 'JetBrains Mono', 'Fira Code', monospace;
            
            --radius-sm: 8px;
            --radius-md: 14px;
            --radius-lg: 20px;
            --radius-xl: 26px;

            --shadow-sm: 0 2px 6px rgba(15, 23, 42, 0.04);
            --shadow-md: 0 8px 24px rgba(29, 59, 94, 0.08);
            --shadow-lg: 0 16px 36px rgba(29, 59, 94, 0.12);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: var(--font-main);
            background-color: var(--bg-page);
            color: var(--text-main);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
            position: relative;
        }

        /* WATERMARK ORNAMEN NUSANTARA (GUNUNGAN & BATIK KAWUNG) */
        .nusantara-watermark {
            position: fixed;
            top: 0;
            right: 0;
            width: 480px;
            height: 480px;
            pointer-events: none;
            opacity: 0.035;
            z-index: 0;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><path fill="%231D3B5E" d="M50 0 L100 50 L50 100 L0 50 Z M50 15 L85 50 L50 85 L15 50 Z"/></svg>');
            background-repeat: no-repeat;
            background-size: contain;
        }

        .header-topbar {
            background: #FFFFFF;
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(12px);
            background-color: rgba(255, 255, 255, 0.95);
        }

        .header-container {
            max-width: 1140px;
            margin: 0 auto;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .brand-link {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--navy);
        }

        .brand-logo {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            object-fit: cover;
            box-shadow: 0 4px 10px rgba(13, 122, 135, 0.15);
        }

        .brand-text-wrap {
            display: flex;
            flex-direction: column;
        }

        .brand-title {
            font-size: 17px;
            font-weight: 800;
            letter-spacing: -0.02em;
            line-height: 1.2;
            color: var(--navy);
        }

        .brand-tagline {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--teal);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-portal {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
            border: 1px solid transparent;
        }

        .btn-portal-outline {
            color: var(--navy);
            border-color: var(--border-color);
            background: #FFFFFF;
        }

        .btn-portal-outline:hover {
            border-color: var(--sky);
            background: var(--sky-fade);
            color: var(--navy-dark);
        }

        .btn-portal-gold {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-dark) 100%);
            color: #FFFFFF;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
        }

        .btn-portal-gold:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(245, 158, 11, 0.35);
        }

        /* MAIN CONTAINER */
        .main-content {
            flex: 1;
            max-width: 1140px;
            width: 100%;
            margin: 0 auto;
            padding: 32px 20px 60px;
            position: relative;
            z-index: 1;
        }

        /* HERO CARD */
        .hero-download-card {
            background: linear-gradient(135deg, var(--navy) 0%, #153352 60%, var(--teal-dark) 100%);
            border-radius: var(--radius-xl);
            padding: 40px;
            color: #FFFFFF;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            margin-bottom: 32px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .hero-pattern {
            position: absolute;
            right: -40px;
            bottom: -40px;
            width: 320px;
            height: 320px;
            opacity: 0.08;
            pointer-events: none;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 36px;
            align-items: center;
            position: relative;
            z-index: 2;
        }

        .hero-badge-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
        }

        .hero-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .pill-gold {
            background: rgba(245, 158, 11, 0.2);
            color: #FCD34D;
            border: 1px solid rgba(245, 158, 11, 0.4);
        }

        .pill-emerald {
            background: rgba(16, 185, 129, 0.2);
            color: #6EE7B7;
            border: 1px solid rgba(16, 185, 129, 0.4);
        }

        .pill-sky {
            background: rgba(82, 178, 220, 0.2);
            color: #BAE6FD;
            border: 1px solid rgba(82, 178, 220, 0.4);
        }

        .hero-title {
            font-size: 32px;
            font-weight: 800;
            line-height: 1.25;
            letter-spacing: -0.02em;
            margin-bottom: 12px;
            color: #FFFFFF;
        }

        .hero-desc {
            font-size: 15px;
            line-height: 1.6;
            color: #CBD5E1;
            margin-bottom: 28px;
            max-width: 640px;
        }

        .hero-meta-bar {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 28px;
            padding: 14px 18px;
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: var(--radius-md);
            backdrop-filter: blur(8px);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13.5px;
        }

        .meta-item iconify-icon {
            color: var(--sky);
            font-size: 18px;
        }

        .meta-label {
            color: #94A3B8;
        }

        .meta-value {
            color: #FFFFFF;
            font-weight: 700;
        }

        .hero-btn-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
        }

        .btn-download-hero {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: linear-gradient(135deg, var(--gold) 0%, #D97706 100%);
            color: #FFFFFF;
            padding: 14px 28px;
            border-radius: var(--radius-md);
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            box-shadow: 0 8px 24px rgba(245, 158, 11, 0.35);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            cursor: pointer;
        }

        .btn-download-hero:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(245, 158, 11, 0.45);
            background: linear-gradient(135deg, #FBBF24 0%, var(--gold) 100%);
            color: #FFFFFF;
        }

        .btn-secondary-hero {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.12);
            color: #FFFFFF;
            padding: 14px 22px;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .btn-secondary-hero:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.35);
        }

        /* QR CODE BOX (SIDE HERO) */
        .hero-qr-box {
            background: #FFFFFF;
            border-radius: var(--radius-lg);
            padding: 20px;
            text-align: center;
            color: var(--navy);
            box-shadow: var(--shadow-md);
            border: 2px solid rgba(255, 255, 255, 0.6);
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .qr-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--teal);
            background: var(--sky-fade);
            padding: 4px 10px;
            border-radius: 20px;
            margin-bottom: 12px;
        }

        .qr-img {
            width: 170px;
            height: 170px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            margin-bottom: 12px;
            display: block;
        }

        .qr-caption {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-muted);
            line-height: 1.4;
        }

        /* TABS SEGMENTED CONTROL (README.md Section 4.1) */
        .curriculum-main-tabs {
            display: flex;
            gap: 8px;
            background: #FFFFFF;
            padding: 8px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .curriculum-main-tabs::-webkit-scrollbar { display: none; }

        .curriculum-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-muted);
            background: transparent;
            border: none;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s ease;
        }

        .curriculum-tab-btn:hover {
            color: var(--navy);
            background: #F1F5F9;
        }

        .curriculum-tab-btn.is-active {
            background: var(--navy);
            color: #FFFFFF;
            box-shadow: 0 4px 12px rgba(29, 59, 94, 0.2);
        }

        .curriculum-tab-btn .tab-badge {
            background: rgba(255, 255, 255, 0.2);
            color: #FFFFFF;
            padding: 2px 7px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
        }
        .curriculum-tab-btn:not(.is-active) .tab-badge {
            background: #E2E8F0;
            color: var(--text-muted);
        }

        /* TAB PANES */
        .tab-pane {
            display: none;
            opacity: 0;
            transform: translateY(8px);
            transition: opacity 0.25s ease, transform 0.25s ease;
        }
        .tab-pane.is-active {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        /* CARDS & GRIDS */
        .grid-2col {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }

        .info-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 28px;
            box-shadow: var(--shadow-sm);
            transition: box-shadow 0.2s ease, border-color 0.2s ease;
            position: relative;
        }
        .info-card:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--border-hover);
        }

        .card-header-clean {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 1px solid #F1F5F9;
        }

        .card-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        .icon-box-navy { background: rgba(29, 59, 94, 0.08); color: var(--navy); }
        .icon-box-teal { background: rgba(13, 122, 135, 0.08); color: var(--teal); }
        .icon-box-gold { background: rgba(245, 158, 11, 0.1); color: var(--gold); }
        .icon-box-emerald { background: rgba(16, 185, 129, 0.1); color: var(--emerald); }

        .card-title-clean {
            font-size: 17px;
            font-weight: 700;
            color: var(--navy);
            margin: 0;
        }
        .card-subtitle-clean {
            font-size: 12.5px;
            color: var(--text-muted);
            margin: 0;
        }

        /* PATH ITEM ROW STACK */
        .path-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .path-row {
            background: #F8FAFC;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            transition: border-color 0.2s ease, background 0.2s ease;
        }
        .path-row:hover {
            border-color: var(--sky);
            background: #FFFFFF;
        }

        .path-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .path-title {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--navy);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .path-type-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .pill-url { background: #E0F2FE; color: #0369A1; }
        .pill-local { background: #FEF3C7; color: #B45309; }
        .pill-api { background: #D1FAE5; color: #047857; }
        .pill-pin { background: #FCE7F3; color: #BE185D; }

        .path-value-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: #0F172A;
            border-radius: 8px;
            padding: 8px 12px;
            color: #38BDF8;
            font-family: var(--font-mono);
            font-size: 12.5px;
            word-break: break-all;
        }

        .btn-copy {
            background: rgba(255, 255, 255, 0.12);
            border: none;
            color: #FFFFFF;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background 0.2s ease;
            flex-shrink: 0;
            font-family: var(--font-main);
        }
        .btn-copy:hover {
            background: var(--sky);
            color: #0F172A;
        }

        .path-desc {
            font-size: 12.5px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        /* CHANGELOG TIMELINE */
        .changelog-timeline {
            position: relative;
            padding-left: 28px;
        }
        .changelog-timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 6px;
            bottom: 6px;
            width: 2px;
            background: #E2E8F0;
        }

        .changelog-node {
            position: relative;
            margin-bottom: 28px;
        }
        .changelog-node:last-child {
            margin-bottom: 0;
        }

        .changelog-dot {
            position: absolute;
            left: -28px;
            top: 2px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #FFFFFF;
            border: 3px solid var(--teal);
            box-shadow: 0 0 0 4px rgba(13, 122, 135, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .changelog-header-node {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .changelog-version {
            font-size: 18px;
            font-weight: 800;
            color: var(--navy);
        }

        .changelog-date {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .badge-release {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            background: rgba(16, 185, 129, 0.12);
            color: #065F46;
        }

        .changelog-card-inner {
            background: #F8FAFC;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 20px;
        }

        .changelog-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .changelog-list li {
            position: relative;
            padding-left: 24px;
            font-size: 13.5px;
            color: #334155;
            line-height: 1.5;
        }

        .changelog-list li::before {
            content: '✓';
            position: absolute;
            left: 0;
            top: 0;
            color: var(--teal);
            font-weight: 800;
        }

        /* SECURITY FEATURE CARDS */
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .feature-box {
            background: #FFFFFF;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 24px;
            transition: all 0.25s ease;
        }
        .feature-box:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            border-color: var(--teal);
        }

        .feature-icon-badge {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 16px;
        }

        .feature-box-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 8px;
        }

        .feature-box-desc {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.55;
        }

        /* SPECIFICATION TABLE */
        .spec-table {
            width: 100%;
            border-collapse: collapse;
        }
        .spec-table tr {
            border-bottom: 1px solid #F1F5F9;
        }
        .spec-table tr:last-child {
            border-bottom: none;
        }
        .spec-table td {
            padding: 12px 6px;
            font-size: 13.5px;
        }
        .spec-label {
            color: var(--text-muted);
            font-weight: 600;
            width: 40%;
        }
        .spec-val {
            color: var(--text-main);
            font-weight: 700;
        }
        .spec-mono {
            font-family: var(--font-mono);
            font-size: 12px;
            background: #F1F5F9;
            padding: 2px 6px;
            border-radius: 4px;
        }

        /* STEP CARDS FOR INSTALLATION */
        .step-timeline {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .step-item {
            display: flex;
            gap: 16px;
            background: #F8FAFC;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 18px;
        }

        .step-num {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--navy);
            color: #FFFFFF;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .step-content-title {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 4px;
        }

        .step-content-text {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.55;
        }

        /* FOOTER */
        .footer-bottom {
            background: #FFFFFF;
            border-top: 1px solid var(--border-color);
            padding: 24px 20px;
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
        }

        /* RESPONSIVE (README.md Section 5: 360px - 430px) */
        @media (max-width: 900px) {
            .hero-grid {
                grid-template-columns: 1fr;
            }
            .hero-qr-box {
                max-width: 260px;
                margin: 0 auto;
            }
            .grid-2col {
                grid-template-columns: 1fr;
            }
            .feature-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .hero-download-card {
                padding: 24px;
                border-radius: var(--radius-lg);
            }
            .hero-title {
                font-size: 24px;
            }
            .hero-meta-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            .btn-download-hero {
                width: 100%;
            }
            .btn-secondary-hero {
                width: 100%;
            }
            .main-content {
                padding: 18px 14px 40px;
            }
            .info-card {
                padding: 18px;
            }
            .header-container {
                padding: 10px 14px;
            }
            .brand-tagline {
                display: none;
            }
        }
    </style>
</head>
<body>

    <!-- Watermark Ornamen Nusantara -->
    <div class="nusantara-watermark"></div>

    <!-- Header Topbar -->
    <header class="header-topbar">
        <div class="header-container">
            <a href="<?= $baseUrl ?: '/' ?>" class="brand-link">
                <img src="<?= getFaviconUrl() ?>" alt="Logo <?= htmlspecialchars(APP_NAME) ?>" class="brand-logo">
                <div class="brand-text-wrap">
                    <span class="brand-title"><?= htmlspecialchars(APP_NAME) ?></span>
                    <span class="brand-tagline">Exam Browser Portal</span>
                </div>
            </a>

            <div class="header-actions">
                <?php if ($isLoggedIn): ?>
                    <a href="<?= htmlspecialchars($dashboardUrl) ?>" class="btn-portal btn-portal-outline">
                        <iconify-icon icon="solar:widget-2-bold" width="16"></iconify-icon>
                        <span>Dashboard</span>
                    </a>
                <?php else: ?>
                    <a href="<?= $baseUrl ?>/login.php" class="btn-portal btn-portal-outline">
                        <iconify-icon icon="solar:login-2-bold" width="16"></iconify-icon>
                        <span>Masuk Portal</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Main Content Container -->
    <main class="main-content">

        <!-- HERO DOWNLOAD SECTION -->
        <section class="hero-download-card">
            <div class="hero-pattern">
                <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                    <path fill="#52B2DC" d="M44.7,-76.4C58.8,-69.2,71.8,-59.1,79.6,-45.8C87.4,-32.6,90,-16.3,88.5,-0.9C87,14.6,81.4,29.2,73.1,42.1C64.7,55,53.6,66.2,40.4,73.8C27.2,81.3,13.6,85.2,-0.7,86.4C-15,87.6,-30,86.1,-43.3,79.5C-56.5,72.9,-68,61.2,-76.7,47.4C-85.4,33.5,-91.3,16.8,-90.4,0.5C-89.5,-15.7,-81.8,-31.4,-72,-44.1C-62.2,-56.8,-50.2,-66.4,-36.8,-73.9C-23.4,-81.4,-8.6,-86.7,3.9,-93.4C16.4,-100.2,30.6,-83.6,44.7,-76.4Z" transform="translate(100 100)" />
                </svg>
            </div>

            <div class="hero-grid">
                <div class="hero-info">
                    <div class="hero-badge-row">
                        <span class="hero-pill pill-gold">
                            <iconify-icon icon="solar:verified-check-bold" width="14"></iconify-icon>
                            <span>Rilis Resmi CBT Kiosk</span>
                        </span>
                        <span class="hero-pill pill-emerald">
                            <iconify-icon icon="solar:shield-keyhole-bold" width="14"></iconify-icon>
                            <span>Anti-Curang Hardware Level</span>
                        </span>
                        <span class="hero-pill pill-sky">
                            <iconify-icon icon="solar:code-bold" width="14"></iconify-icon>
                            <span>Kotlin Native</span>
                        </span>
                    </div>

                    <h1 class="hero-title">Ilmuna Exam Browser (Android)</h1>
                    <p class="hero-desc">
                        Aplikasi Safe Exam Kiosk khusus peserta didik untuk pengerjaan Asesmen Sumatif & Formatif CBT pada platform Ilmuna Learn. Dilengkapi proteksi anti-screenshot, anti-screen recorder, pencegahan keluar aplikasi, dan pembekuan sesi otomatis.
                    </p>

                    <div class="hero-meta-bar">
                        <div class="meta-item">
                            <iconify-icon icon="solar:tag-bold"></iconify-icon>
                            <span class="meta-label">Versi:</span>
                            <span class="meta-value">v<?= htmlspecialchars($appData['versionName']) ?> (Build <?= (int)$appData['versionCode'] ?>)</span>
                        </div>
                        <div class="meta-item">
                            <iconify-icon icon="solar:file-check-bold"></iconify-icon>
                            <span class="meta-label">Ukuran:</span>
                            <span class="meta-value"><?= htmlspecialchars($appData['fileSize']) ?></span>
                        </div>
                        <div class="meta-item">
                            <iconify-icon icon="solar:calendar-date-bold"></iconify-icon>
                            <span class="meta-label">Pembaruan:</span>
                            <span class="meta-value"><?= htmlspecialchars($appData['releaseDate']) ?></span>
                        </div>
                        <div class="meta-item">
                            <iconify-icon icon="solar:smartphone-bold"></iconify-icon>
                            <span class="meta-label">OS:</span>
                            <span class="meta-value">Android 7.0+</span>
                        </div>
                    </div>

                    <div class="hero-btn-row">
                        <a href="<?= htmlspecialchars($apkDownloadUrl) ?>" class="btn-download-hero" download="<?= htmlspecialchars($apkFileName) ?>" id="btnDownloadApk">
                            <iconify-icon icon="solar:download-square-bold" width="22"></iconify-icon>
                            <span>Unduh APK Sekarang (<?= htmlspecialchars($appData['fileSize']) ?>)</span>
                        </a>

                        <button type="button" class="btn-secondary-hero" onclick="copyText('<?= htmlspecialchars($apkDownloadUrl) ?>', 'Tautan Unduh APK Berhasil Disalin!')">
                            <iconify-icon icon="solar:copy-bold" width="18"></iconify-icon>
                            <span>Salin Tautan</span>
                        </button>

                        <button type="button" class="btn-secondary-hero" onclick="switchTab('tab-panduan')">
                            <iconify-icon icon="solar:book-bookmark-bold" width="18"></iconify-icon>
                            <span>Panduan Instalasi</span>
                        </button>
                    </div>
                </div>

                <!-- QR Code Box -->
                <div class="hero-qr-box">
                    <span class="qr-badge">
                        <iconify-icon icon="solar:qr-code-bold" width="15"></iconify-icon>
                        <span>Scan dari Kamera HP</span>
                    </span>
                    <img src="<?= htmlspecialchars($qrCodeUrl) ?>" alt="QR Code Unduh APK" class="qr-img" loading="lazy">
                    <p class="qr-caption">Arahkan kamera HP Android Anda untuk mengunduh berkas APK secara langsung tanpa mengetik URL.</p>
                </div>
            </div>
        </section>

        <!-- TABS NAVIGASI UTAMA (README.md Bagian 4.1) -->
        <nav class="curriculum-main-tabs" role="tablist">
            <button type="button" class="curriculum-tab-btn is-active" data-tab="tab-unduh" onclick="switchTab('tab-unduh')">
                <iconify-icon icon="solar:smartphone-2-bold" width="18"></iconify-icon>
                <span>Unduh & Spesifikasi</span>
                <span class="tab-badge">APK</span>
            </button>
            <button type="button" class="curriculum-tab-btn" data-tab="tab-changelog" onclick="switchTab('tab-changelog')">
                <iconify-icon icon="solar:history-bold" width="18"></iconify-icon>
                <span>Changelog & Riwayat Versi</span>
                <span class="tab-badge">v<?= htmlspecialchars($appData['versionName']) ?></span>
            </button>
            <button type="button" class="curriculum-tab-btn" data-tab="tab-keamanan" onclick="switchTab('tab-keamanan')">
                <iconify-icon icon="solar:shield-star-bold" width="18"></iconify-icon>
                <span>Fitur Keamanan Kiosk</span>
            </button>
            <button type="button" class="curriculum-tab-btn" data-tab="tab-panduan" onclick="switchTab('tab-panduan')">
                <iconify-icon icon="solar:book-bookmark-bold" width="18"></iconify-icon>
                <span>Panduan Instalasi Siswa</span>
            </button>
        </nav>

        <!-- TAB 1: UNDUH & SPESIFIKASI -->
        <div class="tab-pane is-active" id="tab-unduh">
            <!-- SPESIFIKASI TEKNIS -->
            <div class="info-card">
                <div class="card-header-clean">
                    <div class="card-title-group">
                        <div class="card-icon-box icon-box-navy">
                            <iconify-icon icon="solar:cpu-bolt-bold"></iconify-icon>
                        </div>
                        <div>
                            <h3 class="card-title-clean">Spesifikasi Teknis Aplikasi</h3>
                            <p class="card-subtitle-clean">Parameter biner paket installer Android</p>
                        </div>
                    </div>
                </div>

                <table class="spec-table">
                    <tbody>
                        <tr>
                            <td class="spec-label">Nama Aplikasi</td>
                            <td class="spec-val">Ilmuna Exam Browser (CBT Kiosk)</td>
                        </tr>
                        <tr>
                            <td class="spec-label">Package Name</td>
                            <td class="spec-val"><span class="spec-mono"><?= htmlspecialchars($appData['packageName']) ?></span></td>
                        </tr>
                        <tr>
                            <td class="spec-label">Versi Resmi</td>
                            <td class="spec-val">v<?= htmlspecialchars($appData['versionName']) ?> (Version Code: <?= (int)$appData['versionCode'] ?>)</td>
                        </tr>
                        <tr>
                            <td class="spec-label">Bahasa & Engine</td>
                            <td class="spec-val">Kotlin Native + Android Jetpack WebView</td>
                        </tr>
                        <tr>
                            <td class="spec-label">Target SDK</td>
                            <td class="spec-val"><?= htmlspecialchars($appData['targetSdk']) ?></td>
                        </tr>
                        <tr>
                            <td class="spec-label">Minimum SDK</td>
                            <td class="spec-val"><?= htmlspecialchars($appData['minSdk']) ?></td>
                        </tr>
                        <tr>
                            <td class="spec-label">Ukuran Berkas</td>
                            <td class="spec-val"><?= htmlspecialchars($appData['fileSize']) ?></td>
                        </tr>
                        <tr>
                            <td class="spec-label">Status Server</td>
                            <td class="spec-val">
                                <?php if ($hasApk): ?>
                                    <span class="hero-pill pill-emerald" style="padding: 2px 8px;">Tersedia & Siap Diunduh</span>
                                <?php else: ?>
                                    <span class="hero-pill pill-gold" style="padding: 2px 8px;">Sedang Disiapkan Admin</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB 2: CHANGELOG & RIWAYAT VERSI -->
        <div class="tab-pane" id="tab-changelog">
            <div class="info-card">
                <div class="card-header-clean">
                    <div class="card-title-group">
                        <div class="card-icon-box icon-box-teal">
                            <iconify-icon icon="solar:history-bold"></iconify-icon>
                        </div>
                        <div>
                            <h3 class="card-title-clean">Catatan Perubahan (Changelog)</h3>
                            <p class="card-subtitle-clean">Riwayat evolusi dan catatan rilis resmi aplikasi</p>
                        </div>
                    </div>
                </div>

                <div class="changelog-timeline">
                    <?php if (!empty($appData['releases']) && is_array($appData['releases'])): ?>
                        <?php foreach ($appData['releases'] as $rel): ?>
                            <div class="changelog-node <?= !empty($rel['isLatest']) ? 'is-latest' : '' ?>">
                                <div class="changelog-dot"></div>
                                <div class="changelog-header-node">
                                    <span class="changelog-version">Versi <?= htmlspecialchars($rel['versionName']) ?></span>
                                    <span class="badge-release" style="<?= !empty($rel['isLatest']) ? 'background: rgba(13, 122, 135, 0.12); color: #0D7A87; font-weight: 800;' : '' ?>">
                                        <?= htmlspecialchars($rel['badge'] ?? (!empty($rel['isLatest']) ? 'Terbaru & Disarankan' : 'Stabil')) ?>
                                    </span>
                                    <span class="changelog-date">
                                        <iconify-icon icon="solar:calendar-bold"></iconify-icon>
                                        <?= htmlspecialchars($rel['date']) ?>
                                    </span>
                                </div>

                                <div class="changelog-card-inner">
                                    <p style="font-size: 13.5px; color: var(--navy); font-weight: 700; margin-bottom: 12px;">
                                        <?= htmlspecialchars($rel['notes'] ?? ('Catatan Rilis v' . $rel['versionName'])) ?>
                                    </p>
                                    <?php if (!empty($rel['highlights']) && is_array($rel['highlights'])): ?>
                                        <ul class="changelog-list">
                                            <?php foreach ($rel['highlights'] as $hl): ?>
                                                <li><?= htmlspecialchars($hl) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- Fallback jika array rilis kosong -->
                        <div class="changelog-node is-latest">
                            <div class="changelog-dot"></div>
                            <div class="changelog-header-node">
                                <span class="changelog-version">Versi <?= htmlspecialchars($appData['versionName']) ?></span>
                                <span class="badge-release">Terbaru &amp; Stabil</span>
                                <span class="changelog-date">
                                    <iconify-icon icon="solar:calendar-bold"></iconify-icon>
                                    <?= htmlspecialchars($appData['releaseDate']) ?>
                                </span>
                            </div>
                            <div class="changelog-card-inner">
                                <p style="font-size: 13.5px; color: var(--navy); font-weight: 700; margin-bottom: 12px;">
                                    Catatan Rilis Ilmuna Exam Browser v<?= htmlspecialchars($appData['versionName']) ?>:
                                </p>
                                <div style="font-size: 13.5px; color: #334155; line-height: 1.6; white-space: pre-line;">
                                    <?= htmlspecialchars($appData['changelog'] ?? 'Pembaruan stabilitas dan optimasi performa ujian CBT.') ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- TAB 4: FITUR KEAMANAN KIOSK -->
        <div class="tab-pane" id="tab-keamanan">
            <div class="info-card" style="margin-bottom: 24px;">
                <div class="card-header-clean">
                    <div class="card-title-group">
                        <div class="card-icon-box icon-box-gold">
                            <iconify-icon icon="solar:shield-star-bold"></iconify-icon>
                        </div>
                        <div>
                            <h3 class="card-title-clean">Arsitektur Keamanan Safe Exam Kiosk</h3>
                            <p class="card-subtitle-clean">Perlindungan berlapis anti-kecurangan tingkat perangkat &amp; server</p>
                        </div>
                    </div>
                </div>

                <div class="feature-grid">
                    <div class="feature-box">
                        <div class="feature-icon-badge" style="background: rgba(239, 68, 68, 0.1); color: #EF4444;">
                            <iconify-icon icon="solar:camera-minimalistic-bold"></iconify-icon>
                        </div>
                        <h4 class="feature-box-title">Anti-Screenshot Hardware</h4>
                        <p class="feature-box-desc">
                            Mengunci jendela activity dengan `FLAG_SECURE`. Tangkapan layar tombol fisik diblokir sistem OS, dan rekaman video screen recorder menjadi hitam pekat (blank).
                        </p>
                    </div>

                    <div class="feature-box">
                        <div class="feature-icon-badge" style="background: rgba(245, 158, 11, 0.1); color: #D97706;">
                            <iconify-icon icon="solar:scissors-bold"></iconify-icon>
                        </div>
                        <h4 class="feature-box-title">Anti-Copy &amp; Floating Menu</h4>
                        <p class="feature-box-desc">
                            Menghilangkan floating context menu Android ("Salin", "Tempel", "Cari di Web"). Ditambah CSS anti-selection dan pembersihan clipboard otomatis.
                        </p>
                    </div>

                    <div class="feature-box">
                        <div class="feature-icon-badge" style="background: rgba(82, 178, 220, 0.12); color: #0284C7;">
                            <iconify-icon icon="solar:snowflake-bold"></iconify-icon>
                        </div>
                        <h4 class="feature-box-title">Freeze Sesi Ujian</h4>
                        <p class="feature-box-desc">
                            Saat siswa keluar aplikasi (Home, Recent Apps, Notifikasi), sistem membekukan sisa durasi detik di database dan me-logout aplikasi. Soal dan jawaban tetap utuh tersimpan.
                        </p>
                    </div>

                    <div class="feature-box">
                        <div class="feature-icon-badge" style="background: rgba(16, 185, 129, 0.1); color: #059669;">
                            <iconify-icon icon="solar:diskette-bold"></iconify-icon>
                        </div>
                        <h4 class="feature-box-title">Autosave Responsif</h4>
                        <p class="feature-box-desc">
                            Pemicu autosave otomatis saat aplikasi kehilangan fokus atau sebelum proses freeze dijalankan, memastikan tidak ada satu pun jawaban siswa yang hilang.
                        </p>
                    </div>

                    <div class="feature-box">
                        <div class="feature-icon-badge" style="background: rgba(13, 122, 135, 0.1); color: var(--teal);">
                            <iconify-icon icon="solar:code-bold"></iconify-icon>
                        </div>
                        <h4 class="feature-box-title">JavaScript Bridge</h4>
                        <p class="feature-box-desc">
                            Objek global `window.IlmunaKiosk` memungkinkan web CBT memverifikasi bahwa siswa benar-benar membuka ujian dari aplikasi resmi, bukan dari browser biasa.
                        </p>
                    </div>

                    <div class="feature-box">
                        <div class="feature-icon-badge" style="background: rgba(29, 59, 94, 0.1); color: var(--navy);">
                            <iconify-icon icon="solar:shield-keyhole-bold"></iconify-icon>
                        </div>
                        <h4 class="feature-box-title">PIN Otorisasi Proktor</h4>
                        <p class="feature-box-desc">
                            Siswa tidak dapat mengubah alamat server ujian sembarangan. Setiap modifikasi konfigurasi atau reset aplikasi diwajibkan memasukkan PIN Pengawas.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 4: PANDUAN INSTALASI SISWA -->
        <div class="tab-pane" id="tab-panduan">
            <!-- PANDUAN SISWA -->
            <div class="info-card">
                <div class="card-header-clean">
                    <div class="card-title-group">
                        <div class="card-icon-box icon-box-teal">
                            <iconify-icon icon="solar:user-bold"></iconify-icon>
                        </div>
                        <div>
                            <h3 class="card-title-clean">Panduan Instalasi untuk Siswa</h3>
                            <p class="card-subtitle-clean">Langkah mudah memasang APK di smartphone Android</p>
                        </div>
                    </div>
                </div>

                <div class="step-timeline">
                    <div class="step-item">
                        <div class="step-num">1</div>
                        <div>
                            <h4 class="step-content-title">Unduh Berkas APK</h4>
                            <p class="step-content-text">
                                Klik tombol <strong>"Unduh APK Sekarang"</strong> di bagian atas atau pindai kode QR menggunakan kamera HP Anda.
                            </p>
                        </div>
                    </div>

                    <div class="step-item">
                        <div class="step-num">2</div>
                        <div>
                            <h4 class="step-content-title">Izinkan Sumber Tidak Dikenal</h4>
                            <p class="step-content-text">
                                Jika muncul peringatan keamanan Google Chrome, klik <strong>"Tetap Download"</strong>. Buka berkas APK yang selesai diunduh dan pilih <strong>"Pengaturan / Setelan"</strong> &rarr; aktifkan <strong>"Izinkan dari sumber ini"</strong>.
                            </p>
                        </div>
                    </div>

                    <div class="step-item">
                        <div class="step-num">3</div>
                        <div>
                            <h4 class="step-content-title">Lanjutkan Pemasangan</h4>
                            <p class="step-content-text">
                                Jika muncul peringatan Google Play Protect ("Aplikasi diblokir"), klik opsi <strong>"Rincian Lebih Lanjut"</strong> lalu klik <strong>"Tetap Install" (Install anyway)</strong>.
                            </p>
                        </div>
                    </div>

                    <div class="step-item">
                        <div class="step-num">4</div>
                        <div>
                            <h4 class="step-content-title">Buka Aplikasi &amp; Siap Ujian</h4>
                            <p class="step-content-text">
                                Buka ikon <strong>Ilmuna Exam</strong> di menu HP. Masukkan username dan password Anda, lalu masuk ke ruang asesmen.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </main>

    <!-- FOOTER RESMI -->
    <footer class="footer-bottom">
        <p>&copy; <?= date('Y') ?> <strong><?= htmlspecialchars(APP_NAME) ?></strong> — Sistem Manajemen Pembelajaran &amp; CBT Kiosk Berbasis Web.</p>
        <p style="margin-top: 4px; font-size: 12px; color: var(--text-subtle);">
            Arsitektur PHP 8.2 Native &amp; Kotlin Safe Exam Browser. Sesuai Panduan Desain Lumina Gold &amp; Nusantara.
        </p>
    </footer>

    <!-- INTERACTION LOGIC (ZERO PAGE RELOAD) -->
    <script>
        // Tab Switcher (README.md Section 4.1)
        function switchTab(tabId) {
            const btns = document.querySelectorAll('.curriculum-tab-btn');
            const panes = document.querySelectorAll('.tab-pane');

            btns.forEach(btn => {
                if (btn.getAttribute('data-tab') === tabId) {
                    btn.classList.add('is-active');
                } else {
                    btn.classList.remove('is-active');
                }
            });

            panes.forEach(pane => {
                if (pane.id === tabId) {
                    pane.classList.add('is-active');
                } else {
                    pane.classList.remove('is-active');
                }
            });

            // Smooth scroll ke atas area tab jika di mobile
            if (window.innerWidth < 768) {
                const tabsNav = document.querySelector('.curriculum-main-tabs');
                if (tabsNav) {
                    tabsNav.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        }

        // Salin Teks ke Clipboard dengan Notifikasi Ilmuna
        async function copyText(text, successMsg) {
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                } else {
                    // Fallback browser lama
                    const ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                }

                if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
                    window.Ilmuna.toast('success', 'Tersalin', successMsg || 'Berhasil disalin ke clipboard!');
                } else if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: successMsg || 'Berhasil disalin ke clipboard!',
                        showConfirmButton: false,
                        timer: 2500
                    });
                } else {
                    alert(successMsg || 'Berhasil disalin!');
                }
            } catch (err) {
                if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
                    window.Ilmuna.toast('error', 'Gagal', 'Tidak dapat menyalin ke clipboard.');
                }
            }
        }
    </script>
</body>
</html>
