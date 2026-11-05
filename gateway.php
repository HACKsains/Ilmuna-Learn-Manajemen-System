<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — ROLE GATEWAY PORTAL (/gateway)
 * ==============================================================================
 * Layout split panel identik dengan halaman login (slide belakang, form depan)
 * Security: headers, CSP, whitelist role, anti-download, anti-inspect
 * No vertical scroll, split panel overlap, animasi wave + particles + logo glow
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

/* ==============================================================================
 * 1. SECURITY HEADERS
 * ============================================================================== */
if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: geolocation=(self), microphone=(), camera=(self), payment=()");
    header("Cross-Origin-Opener-Policy: same-origin");

    $csp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://code.iconify.design",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' data: https://fonts.gstatic.com",
        "img-src 'self' data:",
        "connect-src 'self'",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        "object-src 'none'",
    ]);
    header("Content-Security-Policy: " . $csp);
}

/* ==============================================================================
 * 2. AUTH REQUIRED
 * ============================================================================== */
requireAuth();

$currentUser = getCurrentUser();
$userRoles   = getUserRoles();
$nama        = $currentUser['full_name'] ?: $currentUser['username'];

/* ==============================================================================
 * 3. HANDLE DIRECT ROLE SELECTION — WITH WHITELIST
 * ============================================================================== */
$allowedRoles = ['admin', 'guru', 'ketua_kelas', 'siswa'];
if (isset($_GET['select_role'])) {
    $selected = $_GET['select_role'];
    // Validasi whitelist SEBELUM cek session role (defense-in-depth)
    if (is_string($selected) && in_array($selected, $allowedRoles, true) && hasRole($selected)) {
        setActiveRole($selected);
        header("Location: " . getRoleDashboardUrl($selected));
        exit;
    }
}

/* ==============================================================================
 * 4. BUILD ROLE CARDS
 * ============================================================================== */
$cards = [];

if (hasRole('admin')) {
    $cards[] = [
        'slug'    => 'admin',
        'nama'    => 'Dashboard Admin',
        'desc'    => 'Kelola modul materi, tugas, penilaian & administrasi KBM',
        'icon'    => 'solar:widget-5-bold',
        'url'     => BASE_URL . '/gateway.php?select_role=admin',
        'badge'   => 'ADMINISTRATOR',
        'variant' => 'dash-admin',
        'target'  => ''
    ];
}

if (hasRole('guru')) {
    $cards[] = [
        'slug'    => 'guru',
        'nama'    => 'Dashboard Guru',
        'desc'    => 'Akses presensi asesmen sumatif, CBT ujian, dan pemantauan kelas',
        'icon'    => 'solar:user-speak-bold',
        'url'     => BASE_URL . '/gateway.php?select_role=guru',
        'badge'   => 'DEWAN GURU',
        'variant' => 'dash-admin',
        'target'  => ''
    ];
}

if (hasRole('ketua_kelas')) {
    $cards[] = [
        'slug'    => 'ketua_kelas',
        'nama'    => 'Ketua Kelas',
        'desc'    => 'Kelola presensi harian siswa, agenda tadarus & koordinasi kelas',
        'icon'    => 'solar:crown-bold',
        'url'     => BASE_URL . '/gateway.php?select_role=ketua_kelas',
        'badge'   => 'KOORDINATOR',
        'variant' => 'ketua',
        'target'  => ''
    ];
}

if (hasRole('siswa')) {
    $cards[] = [
        'slug'    => 'siswa',
        'nama'    => 'Siswa (Peserta Didik)',
        'desc'    => 'Akses modul materi PAI, kumpulkan tugas digital & mutaba\'ah ibadah',
        'icon'    => 'solar:user-id-bold',
        'url'     => BASE_URL . '/gateway.php?select_role=siswa',
        'badge'   => 'PELAJAR AKTIF',
        'variant' => 'siswa',
        'target'  => ''
    ];
}

/* ==============================================================================
 * 5. AUTO-REDIRECT JIKA HANYA 1 ROLE (kecuali ?force)
 * ============================================================================== */
$skip_auto = isset($_GET['force']) ? true : false;
if (count($cards) === 1 && !$skip_auto) {
    $dest = $cards[0]['url'];
    header("Location: " . $dest);
    exit;
}

$home_url = BASE_URL . '/';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">

    <title>Pilih Peran — Ilmuna Learn</title>
    <?= renderFavicon() ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.iconify.design/iconify-icon/1.0.8/iconify-icon.min.js"></script>

    <style>
        /* ==========================================================
           RESET
           ========================================================== */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --navy: #1D3B5E;
            --navy-dark: #152C47;
            --teal: #0D7A87;
            --teal-dark: #096470;
            --sky: #52B2DC;
            --mint: #14B8A6;
            --emerald: #10B981;
            --font-h: 'Outfit', sans-serif;
            --font-b: 'Plus Jakarta Sans', sans-serif;
        }

        /* ==========================================================
           VIEWPORT LOCK — TIDAK ADA SCROLL VERTIKAL
           ========================================================== */
        html {
            height: 100%;
            overflow: hidden;
        }

        body {
            font-family: var(--font-b);
            background: #F8FAFC;
            color: #334155;
            height: 100vh;
            height: 100dvh;
            overflow: hidden;
            overscroll-behavior: none;
            -webkit-font-smoothing: antialiased;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px 0;
            position: relative;

            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            -webkit-touch-callout: none;
        }

        @media (min-width: 768px) {
            body { padding: 24px 0; }
        }

        input, textarea {
            user-select: text !important;
            -webkit-user-select: text !important;
        }

        img {
            -webkit-user-drag: none;
            -moz-user-drag: none;
            user-drag: none;
            -webkit-touch-callout: none;
            pointer-events: none;
        }

        /* ==========================================================
           PAGE TRANSITION (RADIAL WAVE)
           ========================================================== */
        .page-transition {
            position: fixed;
            top: 50%; left: 50%;
            width: 300vmax;
            height: 300vmax;
            margin-top: -150vmax;
            margin-left: -150vmax;
            background: #E8EFF7;
            z-index: 9999;
            transform-origin: center;
            transform: rotate(-225deg) translateY(85%);
            transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            pointer-events: none;
            box-shadow: 0 -20px 60px rgba(0,0,0,0.1);
            will-change: transform;
        }
        .page-transition::before {
            content: '';
            position: absolute;
            top: 10vh; left: 0;
            width: 100%; height: 100%;
            background: #52B2DC;
            box-shadow: 0 -15px 35px rgba(0,0,0,0.08);
        }
        .page-transition::after {
            content: '';
            position: absolute;
            top: 20vh; left: 0;
            width: 100%; height: 100%;
            background: #0D7A87;
            box-shadow: 0 -15px 35px rgba(0,0,0,0.1);
        }
        .page-transition.active {
            transform: rotate(135deg) translateY(-10%);
        }

        /* ==========================================================
           CANVAS + GLOW ORBS
           ========================================================== */
        #particleCanvas {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 2;
        }

        .glow-orb {
            position: absolute;
            border-radius: 50%;
            background: rgba(13, 122, 135, 0.10);
            filter: blur(100px);
            pointer-events: none;
            z-index: 1;
            width: 300px; height: 300px;
        }
        @media (min-width: 768px) {
            .glow-orb { width: 600px; height: 600px; }
        }
        .glow-orb-1 { top: -10%; left: 10%; }
        .glow-orb-2 { bottom: -10%; right: 10%; }

        /* ==========================================================
           WRAPPER
           ========================================================== */
        .login-wrapper {
            position: relative;
            z-index: 10;
            width: 90%;
            max-width: 1200px;
            max-height: 100%;
            min-height: 0;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            overflow: visible;
        }

        @media (min-width: 768px) {
            .login-wrapper {
                flex-direction: row;
                align-items: stretch;
                justify-content: center;
                gap: 0;
                width: 85%;
            }
        }
        @media (min-width: 1024px) { .login-wrapper { width: 75%; } }
        @media (min-width: 1280px) { .login-wrapper { width: 70%; } }

        /* ==========================================================
           LEFT SLIDE PANEL (Z-INDEX 1 → BELAKANG)
           ========================================================== */
        .slide-panel {
            width: 100%;
            height: auto;
            background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 50%, #152C47 100%);
            border-radius: 28px;
            border-bottom-right-radius: 60px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            padding: 8px;
            position: relative;
            z-index: 1;
            overflow: hidden;
            transform: translate(-100%, 100%);
            animation: slideInFromBehind 1.2s cubic-bezier(0.22, 1, 0.36, 1) forwards;
            will-change: transform;
            flex-shrink: 0;
        }

        @media (min-width: 768px) {
            .slide-panel {
                width: 45%;
                padding: 32px;
                border-radius: 0;
                border-top-left-radius: 32px;
                border-bottom-left-radius: 32px;
                border-bottom-right-radius: 100px;
            }
        }
        @media (min-width: 1024px) { .slide-panel { width: 48%; } }

        @keyframes slideInFromBehind {
            0% { transform: translate(-100%, 100%); opacity: 0; }
            100% { transform: translate(0, 0); opacity: 1; }
        }

        .slide-out-behind {
            animation: slideOutBehind 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }
        @keyframes slideOutBehind {
            0% { transform: translate(0, 0); opacity: 1; }
            100% { transform: translate(-100%, 100%); opacity: 0; }
        }

        .pixel-pattern {
            position: absolute;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background-image: radial-gradient(circle, rgba(82, 178, 220, 0.30) 1.5px, transparent 1.5px);
            background-size: 20px 20px;
            animation: pixelPulse 8s ease-in-out infinite;
            opacity: 0.55;
        }
        @keyframes pixelPulse {
            0%, 100% { opacity: 0.40; transform: scale(1); }
            50%      { opacity: 0.70; transform: scale(1.02); }
        }

        .wave-wrap {
            position: absolute;
            bottom: 0; left: 0;
            width: 100%;
            overflow: hidden;
            line-height: 0;
            z-index: 10;
            pointer-events: none;
            opacity: 0.40;
            display: none;
        }
        @media (min-width: 768px) {
            .wave-wrap { display: block; }
        }
        .wave-wrap svg {
            position: relative;
            display: block;
            width: calc(100% + 800px);
            height: 60px;
        }

        .parallax > use {
            animation: wave-move 25s cubic-bezier(.55,.5,.45,.5) infinite;
            will-change: transform;
        }
        .parallax > use:nth-child(1) { animation-delay: -2s; animation-duration: 7s; }
        .parallax > use:nth-child(2) { animation-delay: -3s; animation-duration: 10s; }
        .parallax > use:nth-child(3) { animation-delay: -4s; animation-duration: 13s; }
        .parallax > use:nth-child(4) { animation-delay: -5s; animation-duration: 20s; }
        @keyframes wave-move {
            0%   { transform: translate3d(-90px, 0, 0); }
            100% { transform: translate3d(85px, 0, 0); }
        }

        .slide-inner {
            position: relative;
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
            text-align: left;
            width: 100%;
            max-width: 360px;
            margin: 0 auto;
            padding: 4px 0 8px;
        }
        @media (min-width: 768px) {
            .slide-inner {
                margin: 0;
                padding: 16px 0 0;
            }
        }

        .slide-glass-card {
            background: rgba(255, 255, 255, 0.10);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.20);
            border-radius: 14px;
            padding: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            width: 100%;
        }
        @media (min-width: 768px) {
            .slide-glass-card {
                padding: 28px;
            }
        }

        .logo-line {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 10px;
            margin-bottom: 8px;
        }
        @media (min-width: 768px) {
            .logo-line { margin-bottom: 20px; }
        }

        /* Logo — background putih terang */
        .logo-wrap {
            position: relative;
            width: fit-content;
            padding: 8px 14px;
            background: linear-gradient(
                135deg,
                rgba(255, 255, 255, 0.98) 0%,
                rgba(248, 250, 252, 0.95) 100%
            );
            border-radius: 12px;
            box-shadow:
                0 8px 24px rgba(0, 0, 0, 0.20),
                0 0 28px rgba(255, 255, 255, 0.30),
                inset 0 1px 0 rgba(255, 255, 255, 1),
                inset 0 -1px 0 rgba(29, 59, 94, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.85);
            overflow: hidden;
        }
        @media (min-width: 768px) {
            .logo-wrap {
                padding: 12px 20px;
                border-radius: 16px;
            }
        }

        .logo-wrap::after {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 50%;
            height: 100%;
            background: linear-gradient(to right, rgba(255,255,255,0) 0%, rgba(255,255,255,0.75) 50%, rgba(255,255,255,0) 100%);
            transform: translateX(-150%) skewX(-25deg);
            animation: logoShine 6s infinite ease-in-out;
            pointer-events: none;
            z-index: 3;
        }
        @keyframes logoShine {
            0%   { transform: translateX(-150%) skewX(-25deg); }
            100% { transform: translateX(250%) skewX(-25deg); }
        }

        .logo-wrap img {
            width: 60px;
            height: auto;
            object-fit: contain;
            filter: drop-shadow(0 2px 6px rgba(29, 59, 94, 0.15));
            animation: logoGlow 8s ease-in-out infinite;
            will-change: transform, filter;
            display: block;
        }
        @media (min-width: 768px) {
            .logo-wrap img { width: 160px; }
        }
        @keyframes logoGlow {
            0%, 100% {
                filter: drop-shadow(0 2px 6px rgba(29,59,94,0.15)) drop-shadow(0 0 0 rgba(13,122,135,0));
                transform: translate3d(0, 0, 0) scale(1);
            }
            50% {
                filter: drop-shadow(0 4px 10px rgba(29,59,94,0.20)) drop-shadow(0 0 16px rgba(82,178,220,0.45));
                transform: translate3d(0, -1px, 0) scale(1.005);
            }
        }

        .mobile-side-text {
            flex: 1;
            text-align: left;
        }
        .mobile-side-text h2 {
            color: #FFFFFF;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.2;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        .mobile-side-text p {
            color: rgba(255,255,255,0.8);
            font-size: 9px;
            font-weight: 500;
            margin-top: 1px;
        }
        @media (min-width: 768px) {
            .mobile-side-text { display: none; }
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 9px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.20);
            border: 1px solid rgba(255, 255, 255, 0.30);
            backdrop-filter: blur(8px);
            margin-bottom: 6px;
            width: fit-content;
        }
        @media (min-width: 768px) {
            .badge { padding: 6px 14px; gap: 10px; margin-bottom: 14px; }
        }
        .badge .dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: var(--sky);
            animation: pulseDot 2s ease-in-out infinite;
            box-shadow: 0 0 8px var(--sky);
        }
        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%      { opacity: 0.5; transform: scale(0.85); }
        }
        .badge span {
            color: #FFFFFF;
            font-size: 8px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }
        @media (min-width: 768px) {
            .badge span { font-size: 11px; }
        }

        .slide-h1 {
            display: none;
            color: #FFFFFF;
            font-family: var(--font-h);
            font-weight: 900;
            font-size: 1.5rem;
            letter-spacing: -0.02em;
            line-height: 1.15;
            margin-bottom: 8px;
            text-shadow: 0 4px 12px rgba(0,0,0,0.30);
        }
        @media (min-width: 768px) {
            .slide-h1 {
                display: block;
                font-size: 2rem;
                margin-bottom: 10px;
            }
        }
        @media (min-width: 1024px) {
            .slide-h1 { font-size: 2.25rem; }
        }
        .slide-h1 .gradient-text {
            background: linear-gradient(to right, #FFFFFF 0%, #52B2DC 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            color: transparent;
            filter: drop-shadow(0 2px 8px rgba(0,0,0,0.2));
        }

        .slide-desc {
            display: none;
            color: rgba(255, 255, 255, 0.90);
            font-size: 13px;
            line-height: 1.55;
            font-weight: 500;
            max-width: 320px;
            text-shadow: 0 1px 4px rgba(0,0,0,0.2);
        }
        @media (min-width: 768px) {
            .slide-desc { display: block; }
        }

        /* ==========================================================
           RIGHT FORM PANEL (Z-INDEX 20 → DEPAN)
           ========================================================== */
        .form-panel {
            width: 100%;
            height: auto;
            border-radius: 28px;
            border-top-left-radius: 60px;
            padding: 16px;
            padding-top: 36px;
            padding-bottom: 16px;
            margin-top: -28px;
            position: relative;
            z-index: 20;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;

            background: rgba(255, 255, 255, 0.45);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.60);
            box-shadow:
                0 8px 32px 0 rgba(29, 59, 94, 0.15),
                -20px 12px 40px -12px rgba(29, 59, 94, 0.20);

            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            -ms-overflow-style: none;
            max-height: 100%;

            opacity: 0;
            animation: fadeIn 0.8s cubic-bezier(0.22, 1, 0.36, 1) forwards;
            animation-delay: 0.4s;
            will-change: opacity;
        }
        .form-panel::-webkit-scrollbar { display: none; }

        @media (min-width: 768px) {
            .form-panel {
                width: 55%;
                border-radius: 0;
                border-top-right-radius: 40px;
                border-bottom-right-radius: 40px;
                border-top-left-radius: 100px;
                padding: 32px;
                margin-top: 0;
                margin-left: -28px;
            }
        }
        @media (min-width: 1024px) { .form-panel { width: 52%; } }

        @keyframes fadeIn {
            0%   { opacity: 0; }
            100% { opacity: 1; }
        }

        .form-inner {
            width: 100%;
            max-width: 400px;
        }

        .welcome-line {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--teal);
            margin-bottom: 4px;
            letter-spacing: 0.02em;
        }
        @media (min-width: 768px) {
            .welcome-line { font-size: 0.85rem; margin-bottom: 6px; }
        }
        .welcome-dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: #10B981;
            box-shadow: 0 0 8px #10B981;
            animation: pulseDot 2s ease-in-out infinite;
        }

        .form-heading {
            color: var(--navy);
            font-family: var(--font-h);
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 2px;
            line-height: 1.2;
        }
        @media (min-width: 768px) {
            .form-heading { font-size: 1.55rem; margin-bottom: 4px; }
        }

        .form-subheading {
            color: #64748B;
            font-size: 0.78rem;
            margin-bottom: 14px;
            font-weight: 500;
            line-height: 1.5;
        }
        @media (min-width: 768px) {
            .form-subheading { font-size: 0.85rem; margin-bottom: 18px; }
        }

        /* ==========================================================
           ROLE CARDS — COMPACT
           ========================================================== */
        .role-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            width: 100%;
        }

        .role-card {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 9px 12px;
            border: 1.5px solid #E2E8F0;
            border-radius: 12px;
            background: #F8FAFC;
            text-decoration: none !important;
            color: inherit !important;
            transition: all 0.28s cubic-bezier(0.22, 1, 0.36, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 6px rgba(29, 59, 94, 0.03);
        }
        @media (min-width: 768px) {
            .role-card {
                gap: 13px;
                padding: 11px 14px;
                border-radius: 14px;
            }
        }

        .role-card::before {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(13, 122, 135, 0.08), transparent);
            transition: left 0.6s ease;
        }

        .role-card:hover {
            border-color: #0D7A87;
            background: #FFFFFF;
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(13, 122, 135, 0.12), 0 3px 8px rgba(29, 59, 94, 0.04);
        }
        .role-card:hover::before { left: 100%; }

        .role-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            flex-shrink: 0;
            background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%);
            color: #FFFFFF;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(13, 122, 135, 0.25);
            transition: transform 0.25s ease;
        }
        @media (min-width: 768px) {
            .role-icon { width: 40px; height: 40px; border-radius: 12px; }
        }
        .role-card:hover .role-icon {
            transform: scale(1.08) rotate(-3deg);
        }

        .role-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 1px;
            min-width: 0;
            text-align: left;
        }

        .role-title {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            font-family: var(--font-h);
            font-size: 0.85rem;
            font-weight: 700;
            color: #0F1D2F;
            line-height: 1.25;
        }
        @media (min-width: 768px) {
            .role-title { font-size: 0.92rem; gap: 8px; }
        }

        .role-badge {
            display: inline-block;
            font-family: var(--font-b);
            font-size: 8px;
            font-weight: 800;
            background: rgba(13, 122, 135, 0.12);
            color: #0D7A87;
            border: 1px solid rgba(13, 122, 135, 0.25);
            padding: 1px 6px;
            border-radius: 999px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        @media (min-width: 768px) {
            .role-badge { font-size: 9px; padding: 2px 8px; }
        }

        .role-desc {
            font-size: 10.5px;
            color: #64748B;
            line-height: 1.35;
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        @media (min-width: 768px) {
            .role-desc { font-size: 11.5px; -webkit-line-clamp: 2; }
        }

        .role-arrow {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #94A3B8;
            transition: transform 0.25s ease, color 0.25s ease;
            flex-shrink: 0;
        }
        .role-card:hover .role-arrow {
            transform: translateX(3px);
            color: #0D7A87;
        }

        /* ==========================================================
           VARIANT WARNA ROLE
           ========================================================== */
        .role-card--dash-admin:hover {
            border-color: #14B8A6;
            box-shadow: 0 10px 22px rgba(20, 184, 166, 0.18);
        }
        .role-icon--dash-admin {
            background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%);
            color: #14B8A6;
        }
        .role-card--dash-admin .role-badge {
            background: rgba(20, 184, 166, 0.12);
            color: #0D7A87;
            border-color: rgba(20, 184, 166, 0.3);
        }

        .role-card--ketua:hover {
            border-color: #0D7A87;
            box-shadow: 0 10px 22px rgba(13, 122, 135, 0.15);
        }
        .role-icon--ketua {
            background: linear-gradient(135deg, #0D7A87 0%, #52B2DC 100%);
        }
        .role-card--ketua .role-badge {
            background: rgba(13, 122, 135, 0.12);
            color: #0D7A87;
            border-color: rgba(13, 122, 135, 0.3);
        }

        .role-card--siswa:hover {
            border-color: #10B981;
            box-shadow: 0 10px 22px rgba(16, 185, 129, 0.15);
        }
        .role-icon--siswa {
            background: linear-gradient(135deg, #0D7A87 0%, #10B981 100%);
        }
        .role-card--siswa .role-badge {
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
            border-color: rgba(16, 185, 129, 0.3);
        }

        /* ==========================================================
           ALERT — BELUM ADA ROLE
           ========================================================== */
        .alert-error {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 11px 14px;
            background: #FEF2F2;
            border: 1px solid #FECACA;
            border-left: 4px solid #EF4444;
            border-radius: 10px;
            margin-bottom: 12px;
            font-size: 0.8rem;
            color: #991B1B;
            line-height: 1.45;
            animation: alertSlide 0.35s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        @keyframes alertSlide {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .alert-error > .alert-icon {
            width: 30px; height: 30px;
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
            color: #FFFFFF;
            border-radius: 8px;
            box-shadow: 0 3px 8px rgba(239, 68, 68, 0.3);
        }
        .alert-error strong {
            display: block;
            font-family: var(--font-h);
            font-size: 0.88rem;
            font-weight: 700;
            color: #0F1D2F;
            margin-bottom: 2px;
        }

        /* ==========================================================
           ACTIONS ROW
           ========================================================== */
        .actions-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid #E2E8F0;
        }
        @media (min-width: 768px) {
            .actions-row { margin-top: 18px; padding-top: 14px; gap: 14px; }
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            font-weight: 600;
            color: #64748B;
            text-decoration: none;
            transition: all 0.2s ease;
            padding: 6px 10px;
            border-radius: 8px;
        }
        @media (min-width: 768px) {
            .btn-action { font-size: 12.5px; padding: 7px 12px; }
        }
        .btn-action:hover {
            color: var(--teal);
            background: rgba(13, 122, 135, 0.08);
        }

        .btn-logout { color: #EF4444; }
        .btn-logout:hover {
            color: #DC2626;
            background: rgba(239, 68, 68, 0.08);
        }

        /* ==========================================================
           SUPER SMALL SCREENS
           ========================================================== */
        @media (max-width: 380px) {
            .form-subheading { display: none; }
            .slide-glass-card { padding: 8px; }
            .form-panel { padding-top: 30px; }
            .role-desc { display: none; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>
    <div id="pageTransition" class="page-transition active"></div>
    <canvas id="particleCanvas"></canvas>
    <div class="glow-orb glow-orb-1"></div>
    <div class="glow-orb glow-orb-2"></div>

    <div class="login-wrapper">
        <!-- =========== LEFT SLIDE PANEL =========== -->
        <div class="slide-panel" id="slidePanel">
            <div class="pixel-pattern"></div>

            <div class="wave-wrap">
                <svg viewBox="0 24 150 28" preserveAspectRatio="none" shape-rendering="auto">
                    <defs>
                        <path id="gentle-wave" d="M-160 44c30 0 58-18 88-18s 58 18 88 18 58-18 88-18 58 18 88 18 v44h-352z"/>
                    </defs>
                    <g class="parallax">
                        <use href="#gentle-wave" x="48" y="0" fill="rgba(255,255,255,0.30)"/>
                        <use href="#gentle-wave" x="48" y="3" fill="rgba(255,255,255,0.20)"/>
                        <use href="#gentle-wave" x="48" y="5" fill="rgba(255,255,255,0.15)"/>
                        <use href="#gentle-wave" x="48" y="7" fill="rgba(255,255,255,0.40)"/>
                    </g>
                </svg>
            </div>

            <div class="slide-inner">
                <div class="slide-glass-card">
                    <div class="logo-line">
                        <div class="logo-wrap">
                            <img src="<?= htmlspecialchars(BASE_URL . '/assets/images/logo-learn.png', ENT_QUOTES, 'UTF-8') ?>"
                                 alt="Ilmuna Learn"
                                 draggable="false"
                                 oncontextmenu="return false;">
                        </div>
                        <div class="mobile-side-text">
                            <h2>Ilmuna Learn</h2>
                            <p>Portal Multi-Role</p>
                        </div>
                    </div>

                    <div class="badge">
                        <span class="dot"></span>
                        <span>Multi-Role RBAC Portal</span>
                    </div>

                    <h1 class="slide-h1">
                        Ilmuna Learn<br>
                        <span class="gradient-text">Portal Akademik</span>
                    </h1>

                    <p class="slide-desc">
                        Media Pembelajaran Pendidikan Agama Islam — Sistem Informasi Akademik untuk Guru, Ketua Kelas, dan Siswa.
                    </p>
                </div>
            </div>
        </div>

        <!-- =========== RIGHT FORM PANEL =========== -->
        <div class="form-panel" id="formPanel">
            <div class="form-inner">

                <div class="welcome-line">
                    <span class="welcome-dot"></span>
                    Halo, <?= htmlspecialchars($nama) ?>
                </div>
                <h2 class="form-heading">Pilih Peran</h2>
                <p class="form-subheading">Pilih dashboard peran untuk melanjutkan aktivitas Anda</p>

                <?php if (empty($cards)): ?>

                    <div class="alert-error" role="alert">
                        <div class="alert-icon">
                            <iconify-icon icon="solar:danger-triangle-bold" width="18"></iconify-icon>
                        </div>
                        <div>
                            <strong>Belum ada peran aktif</strong>
                            Akun Anda belum memiliki hak akses atau peran yang valid. Silakan hubungi Guru Pengampu atau Administrator sistem.
                        </div>
                    </div>

                <?php else: ?>

                    <div class="role-list">
                        <?php foreach ($cards as $c):
                            $variantClass = !empty($c['variant']) ? 'role-card--' . $c['variant'] : '';
                            $iconClass    = !empty($c['variant']) ? 'role-icon--' . $c['variant'] : '';
                            $targetAttr   = !empty($c['target']) ? 'target="' . htmlspecialchars($c['target']) . '" rel="noopener"' : '';
                        ?>
                            <a href="<?= htmlspecialchars($c['url']) ?>"
                               class="role-card <?= $variantClass ?>"
                               data-role="<?= htmlspecialchars($c['slug']) ?>"
                               <?= $targetAttr ?>>
                                <span class="role-icon <?= $iconClass ?>">
                                    <iconify-icon icon="<?= htmlspecialchars($c['icon']) ?>" width="20"></iconify-icon>
                                </span>
                                <span class="role-content">
                                    <span class="role-title">
                                        <?= htmlspecialchars($c['nama']) ?>
                                        <?php if (!empty($c['badge'])): ?>
                                            <span class="role-badge"><?= htmlspecialchars($c['badge']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="role-desc"><?= htmlspecialchars($c['desc']) ?></span>
                                </span>
                                <span class="role-arrow">
                                    <iconify-icon icon="solar:arrow-right-bold" width="16"></iconify-icon>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>

                <div class="actions-row">
                    <a href="<?= htmlspecialchars(BASE_URL . '/logout.php', ENT_QUOTES, 'UTF-8') ?>" class="btn-action btn-logout" data-transition>
                        <iconify-icon icon="solar:logout-2-bold" width="14"></iconify-icon>
                        Keluar
                    </a>
                    <a href="<?= htmlspecialchars($home_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-action" data-transition>
                        <iconify-icon icon="solar:home-2-bold" width="14"></iconify-icon>
                        Beranda
                    </a>
                </div>

            </div>
        </div>
    </div>

    <script>
    (function () {
        'use strict';

        /* ---------- 1. ANTI-DOWNLOAD & ANTI-INSPECT ---------- */
        document.addEventListener('contextmenu', function (e) {
            e.preventDefault();
            return false;
        });

        document.addEventListener('dragstart', function (e) {
            if (e.target && (e.target.tagName === 'IMG' || e.target.tagName === 'A')) {
                e.preventDefault();
                return false;
            }
        });

        document.addEventListener('selectstart', function (e) {
            var t = e.target;
            if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) return true;
            e.preventDefault();
            return false;
        });

        document.addEventListener('copy', function (e) {
            var t = e.target;
            if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) return true;
            e.preventDefault();
            return false;
        });

        document.addEventListener('keydown', function (e) {
            var k = (e.key || '').toLowerCase();
            if (e.ctrlKey && !e.shiftKey && !e.altKey && (k === 's' || k === 'p' || k === 'u')) {
                e.preventDefault(); return false;
            }
            if (e.ctrlKey && e.shiftKey && (k === 'i' || k === 'j' || k === 'c' || k === 'k')) {
                e.preventDefault(); return false;
            }
            if (k === 'f12' || e.keyCode === 123) {
                e.preventDefault(); return false;
            }
        });

        /* ---------- 2. PAGE TRANSITION SWEEP-OUT ---------- */
        var pageTransition = document.getElementById('pageTransition');
        setTimeout(function () {
            if (pageTransition) pageTransition.classList.remove('active');
        }, 100);

        /* ---------- 3. CANVAS PARTICLE SYSTEM ---------- */
        var canvas = document.getElementById('particleCanvas');
        if (canvas) {
            var ctx = canvas.getContext('2d');

            function resizeCanvas() {
                canvas.width  = window.innerWidth;
                canvas.height = window.innerHeight;
            }
            resizeCanvas();
            window.addEventListener('resize', resizeCanvas);

            var particleColors = ['#1D3B5E', '#0D7A87', '#52B2DC', '#10B981'];
            var particles = [];
            for (var i = 0; i < 50; i++) {
                particles.push({
                    x: Math.random() * canvas.width,
                    y: Math.random() * canvas.height,
                    vx: (Math.random() - 0.5) * 0.5,
                    vy: (Math.random() - 0.5) * 0.5,
                    radius: Math.random() * 3 + 1,
                    color: particleColors[Math.floor(Math.random() * particleColors.length)],
                    opacity: Math.random() * 0.5 + 0.2
                });
            }

            function animate() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                for (var i = 0; i < particles.length; i++) {
                    var p1 = particles[i];
                    p1.x += p1.vx;
                    p1.y += p1.vy;
                    if (p1.x < 0 || p1.x > canvas.width)  p1.vx *= -1;
                    if (p1.y < 0 || p1.y > canvas.height) p1.vy *= -1;

                    ctx.beginPath();
                    ctx.arc(p1.x, p1.y, p1.radius, 0, Math.PI * 2);
                    ctx.fillStyle = p1.color;
                    ctx.globalAlpha = p1.opacity;
                    ctx.fill();

                    for (var j = i + 1; j < particles.length; j++) {
                        var p2 = particles[j];
                        var distance = Math.hypot(p1.x - p2.x, p1.y - p2.y);
                        if (distance < 100) {
                            ctx.beginPath();
                            ctx.moveTo(p1.x, p1.y);
                            ctx.lineTo(p2.x, p2.y);
                            ctx.strokeStyle = p1.color;
                            ctx.globalAlpha = (1 - distance / 100) * 0.2;
                            ctx.stroke();
                        }
                    }
                }
                ctx.globalAlpha = 1;
                requestAnimationFrame(animate);
            }
            animate();
        }

        /* ---------- 4. ROLE CARD CLICK — SLIDE-OUT TRANSITION ---------- */
        document.querySelectorAll('.role-card').forEach(function (card) {
            card.addEventListener('click', function (e) {
                var href = this.getAttribute('href');
                if (!href) return;
                e.preventDefault();

                var slidePanel = document.getElementById('slidePanel');
                var formPanel  = document.getElementById('formPanel');

                if (slidePanel) slidePanel.classList.add('slide-out-behind');
                if (formPanel) {
                    formPanel.style.opacity = '0';
                    formPanel.style.transform = 'translateX(100px)';
                    formPanel.style.transition = 'all 0.5s ease-in';
                }

                if (pageTransition) pageTransition.classList.add('active');

                setTimeout(function () {
                    window.location.href = href;
                }, 500);
            });
        });

        /* ---------- 5. FOOTER LINK TRANSITION ---------- */
        document.querySelectorAll('a[data-transition]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var href = this.getAttribute('href');
                if (!href || href === '#' || href.indexOf('javascript:') === 0) return;

                var slidePanel = document.getElementById('slidePanel');
                var formPanel  = document.getElementById('formPanel');

                if (slidePanel) slidePanel.classList.add('slide-out-behind');
                if (formPanel) {
                    formPanel.style.opacity = '0';
                    formPanel.style.transform = 'translateX(100px)';
                    formPanel.style.transition = 'all 0.5s ease-in';
                }

                if (pageTransition) pageTransition.classList.add('active');

                setTimeout(function () {
                    window.location.href = href;
                }, 500);
            });
        });

    })();
    </script>
</body>
</html>