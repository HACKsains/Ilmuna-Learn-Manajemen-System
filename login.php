<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — LOGIN PAGE (SECURED, NO VERTICAL SCROLL)
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
    header("Cross-Origin-Opener-Policy: same-origin-allow-popups");

    $csp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://accounts.google.com",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://accounts.google.com",
        "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com",
        "img-src 'self' data: https://*.googleusercontent.com",
        "connect-src 'self' https://accounts.google.com https://oauth2.googleapis.com https://www.googleapis.com",
        "frame-src 'self' https://accounts.google.com",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "form-action 'self' https://accounts.google.com",
        "object-src 'none'",
    ]);
    header("Content-Security-Policy: " . $csp);
}

/* ==============================================================================
 * 2. SESSION & CSRF TOKEN
 * ============================================================================== */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (empty($_SESSION['csrf_login_token']) || !is_string($_SESSION['csrf_login_token'])) {
    $_SESSION['csrf_login_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_login_token'];

/* ==============================================================================
 * 3. REDIRECT JIKA SUDAH LOGIN
 * ============================================================================== */
if (isLoggedIn()) {
    header("Location: " . BASE_URL . "/gateway.php");
    exit;
}

/* ==============================================================================
 * 4. PROSES LOGIN GOOGLE OAUTH 2.0 (CALLBACK REDIRECT / POPUP TOKEN / EMAIL DB)
 * ============================================================================== */
$error = null;

/**
 * Helper HTTP request ke Google OAuth API (mendukung cURL & stream fallback)
 */
function fetchGoogleApi($url, $postFields = null) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        if (is_array($postFields)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        }
        $body = curl_exec($ch);
        curl_close($ch);
        if (is_string($body) && $body !== '') {
            return json_decode($body, true);
        }
    }
    $opts = [
        'http' => [
            'method'        => is_array($postFields) ? 'POST' : 'GET',
            'timeout'       => 8,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ];
    if (is_array($postFields)) {
        $opts['http']['header']  = "Content-Type: application/x-www-form-urlencoded\r\n";
        $opts['http']['content'] = http_build_query($postFields);
    }
    $resp = @file_get_contents($url, false, stream_context_create($opts));
    return $resp ? json_decode($resp, true) : null;
}

// Tangani OAuth 2.0 Authorization Code Redirect (jika menggunakan alur redirect menggunakan Client ID + Client Secret)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['code']) && defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '') {
    $stateOk = !empty($_GET['state']) && isset($_SESSION['csrf_login_token']) && hash_equals($_SESSION['csrf_login_token'], (string)$_GET['state']);
    if (!$stateOk) {
        $error = "Sesi otentikasi Google tidak valid. Silakan coba lagi.";
    } else {
        $redirectUri = BASE_URL . '/login.php';
        $tokenData = fetchGoogleApi('https://oauth2.googleapis.com/token', [
            'code'          => (string)$_GET['code'],
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : '',
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);
        if (!empty($tokenData['access_token'])) {
            $uInfo = fetchGoogleApi('https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . urlencode($tokenData['access_token']));
            if (!empty($uInfo['email']) && !empty($uInfo['email_verified'])) {
                $resG = loginUserByGoogle($uInfo['email'], (string)($uInfo['sub'] ?? ''));
                if ($resG['success']) {
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_regenerate_id(true);
                        unset($_SESSION['csrf_login_token']);
                    }
                    header("Location: " . BASE_URL . "/gateway.php");
                    exit;
                } else {
                    $error = $resG['message'] ?? 'Akun Google tidak terdaftar di database.';
                }
            } else {
                $error = "Gagal mengambil informasi email dari akun Google Anda.";
            }
        } else {
            $error = "Gagal memverifikasi kode otorisasi Google.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token'] : '';

    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $error = "Sesi formulir tidak valid atau sudah kedaluwarsa. Muat ulang halaman dan coba lagi.";
    }
    elseif (!empty($_POST['website'])) {
        $error = "Tidak dapat memproses permintaan. Silakan muat ulang halaman.";
    }
    elseif (($_POST['login_mode'] ?? '') === 'google') {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || (isset($_POST['ajax']) && $_POST['ajax'] === '1');

        $googleEmail = '';
        $googleSub   = '';

        // Opsi A: Token dari Google OAuth / Google Identity Services
        if (!empty($_POST['google_access_token']) && is_string($_POST['google_access_token'])) {
            $token = trim($_POST['google_access_token']);
            $info  = fetchGoogleApi('https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . urlencode($token));
            if (is_array($info) && !empty($info['email']) && !empty($info['email_verified'])) {
                $googleEmail = strtolower(trim((string)$info['email']));
                $googleSub   = trim((string)($info['sub'] ?? ''));
            }
            if ($googleEmail === '') {
                $error = "Verifikasi token Google gagal atau telah kedaluwarsa.";
            }
        }
        // Opsi A2: ID Token (credential) dari Google Identity Services
        elseif (!empty($_POST['google_credential']) && is_string($_POST['google_credential'])) {
            $idToken = trim($_POST['google_credential']);
            $info    = fetchGoogleApi('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
            if (
                is_array($info)
                && !empty($info['email'])
                && ($info['email_verified'] === true || $info['email_verified'] === 'true')
                && (!defined('GOOGLE_CLIENT_ID') || GOOGLE_CLIENT_ID === '' || ($info['aud'] ?? '') === GOOGLE_CLIENT_ID)
            ) {
                $googleEmail = strtolower(trim((string)$info['email']));
                $googleSub   = trim((string)($info['sub'] ?? ''));
            }
            if ($googleEmail === '') {
                $error = "Verifikasi kredensial Google gagal atau tidak valid.";
            }
        }
        // Opsi B: Pemeriksaan langsung email Google terhadap database
        else {
            $rawEmail = isset($_POST['email_google']) && is_string($_POST['email_google'])
                ? $_POST['email_google'] : '';
            $googleEmail = strtolower(trim(strip_tags($rawEmail)));
            $googleEmail = preg_replace('/[\x00-\x1F\x7F]/u', '', $googleEmail);
            $googleEmail = mb_substr($googleEmail, 0, 150, 'UTF-8');

            if ($googleEmail === '' || !filter_var($googleEmail, FILTER_VALIDATE_EMAIL)) {
                $error = "Silakan masukkan alamat email Google yang valid.";
            }
        }

        if (!$error) {
            // HANYA mengecek ke database, TIDAK menambahkan/mendaftarkan akun Google baru dari sisi login
            $result = loginUserByGoogle($googleEmail, $googleSub);

            if ($result['success']) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                    unset($_SESSION['csrf_login_token']);
                }
                $redirectUrl = BASE_URL . "/gateway.php";
                if ($isAjax) {
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode(['success' => true, 'redirect' => $redirectUrl]);
                    exit;
                }
                header("Location: " . $redirectUrl);
                exit;
            } else {
                $error = is_string($result['message'] ?? null)
                    ? $result['message']
                    : "Akun Google tidak terdaftar di database.";
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }
    }
    else {
        $rawIdentifier = isset($_POST['identifier']) && is_string($_POST['identifier'])
            ? $_POST['identifier'] : '';

        $identifier = trim($rawIdentifier);
        $identifier = strip_tags($identifier);
        $identifier = preg_replace('/[\x00-\x1F\x7F]/u', '', $identifier);
        $identifier = mb_substr($identifier, 0, 120, 'UTF-8');

        if ($identifier !== '' && !preg_match('/^[A-Za-z0-9@._+\-]+$/', $identifier)) {
            $error = "Format username atau email tidak valid.";
        }

        $password = isset($_POST['password']) && is_string($_POST['password'])
            ? $_POST['password'] : '';

        if (!$error && strlen($password) > 200) {
            $error = "Kata sandi terlalu panjang.";
        }

        if (!$error && ($identifier === '' || $password === '')) {
            $error = "Silakan masukkan username/email dan kata sandi Anda.";
        }

        if (!$error) {
            $result = loginUser($identifier, $password);

            if ($result['success']) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                    unset($_SESSION['csrf_login_token']);
                }
                header("Location: " . BASE_URL . "/gateway.php");
                exit;
            } else {
                $error = is_string($result['message'] ?? null)
                    ? $result['message']
                    : "Username atau kata sandi salah.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">

    <title>Masuk — Ilmuna Learn</title>
    <?= renderFavicon() ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* ==========================================================
           RESET & VARIABEL
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

            /* Dynamic viewport height — akurat di mobile browser */
            height: 100vh;      /* fallback lama */
            height: 100dvh;     /* modern */

            overflow: hidden;
            overscroll-behavior: none;

            -webkit-font-smoothing: antialiased;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px 0;
            position: relative;

            /* Anti-select global */
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            -webkit-touch-callout: none;
        }

        @media (min-width: 768px) {
            body { padding: 24px 0; }
        }

        /* Izinkan selection di form */
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
           WRAPPER — dibatasi agar tidak melebihi viewport
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
        }
        .wave-wrap svg {
            position: relative;
            display: block;
            width: calc(100% + 800px);
            height: 30px;
        }
        @media (min-width: 768px) { .wave-wrap svg { height: 60px; } }

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

        /* ==========================================================
           LOGO — BACKGROUND PUTIH TERANG + SHINE SWEEP
           ========================================================== */
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

        /* slide-desc — disembunyikan di mobile untuk hemat ruang */
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
           dengan internal-scroll fallback tanpa scrollbar
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

            /* SAFETY: kalau konten lebih tinggi dari viewport,
               panel ini scroll internal tanpa scrollbar terlihat */
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
                padding-top: 32px;
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
            max-width: 380px;
        }

        .form-heading {
            color: var(--navy);
            font-family: var(--font-h);
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 2px;
        }
        @media (min-width: 768px) {
            .form-heading { font-size: 1.6rem; margin-bottom: 4px; }
        }

        .form-subheading {
            color: #64748B;
            font-size: 0.78rem;
            margin-bottom: 14px;
            font-weight: 500;
        }
        @media (min-width: 768px) {
            .form-subheading { font-size: 0.85rem; margin-bottom: 22px; }
        }

        .alert-error {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 9px 12px;
            background: #FEF2F2;
            border: 1px solid #FECACA;
            border-left: 4px solid #EF4444;
            border-radius: 10px;
            margin-bottom: 12px;
            font-size: 0.78rem;
            color: #991B1B;
            line-height: 1.4;
            animation: alertSlide 0.35s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        @keyframes alertSlide {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .alert-error > i { color: #DC2626; flex-shrink: 0; margin-top: 1px; }
        .alert-error .alert-close {
            background: none; border: none; color: #DC2626;
            cursor: pointer; margin-left: auto; padding: 0;
            font-size: 0.8rem; transition: transform 0.2s;
        }
        .alert-error .alert-close:hover { transform: scale(1.15); }

        .field { margin-bottom: 10px; }
        @media (min-width: 768px) {
            .field { margin-bottom: 14px; }
        }
        .field-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 4px;
        }
        @media (min-width: 768px) {
            .field-label { font-size: 0.8rem; margin-bottom: 6px; }
        }

        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-wrap > i {
            position: absolute;
            left: 14px;
            color: #94A3B8;
            font-size: 0.88rem;
            pointer-events: none;
            transition: color 0.2s;
        }
        .input-wrap:focus-within > i { color: var(--teal); }

        .form-input {
            width: 100%;
            padding: 11px 42px 11px 40px;
            border-radius: 12px;
            border: 1.5px solid #E2E8F0;
            background: rgba(255, 255, 255, 0.85);
            color: #0F1D2F;
            font-family: var(--font-b);
            font-size: 0.88rem;
            font-weight: 500;
            transition: all 0.25s ease;
        }
        @media (min-width: 768px) {
            .form-input { padding: 13px 46px 13px 44px; font-size: 0.92rem; }
        }
        .form-input::placeholder { color: #94A3B8; font-weight: 400; }
        .form-input:focus {
            outline: none;
            border-color: var(--teal);
            background: #FFFFFF;
            box-shadow: 0 0 0 4px rgba(13, 122, 135, 0.10);
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            color: #94A3B8;
            cursor: pointer;
            font-size: 0.88rem;
            padding: 6px;
            transition: color 0.2s;
        }
        .toggle-password:hover { color: var(--navy); }

        /* Honeypot */
        .hp-field {
            position: absolute !important;
            left: -9999px !important;
            top: -9999px !important;
            width: 1px !important;
            height: 1px !important;
            opacity: 0 !important;
            pointer-events: none !important;
        }

        .options-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.78rem;
            color: #64748B;
            margin: 6px 2px 14px;
        }
        @media (min-width: 768px) {
            .options-row { font-size: 0.82rem; margin: 10px 2px 18px; }
        }
        .options-row label {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            user-select: none;
        }
        .options-row input[type="checkbox"] {
            accent-color: var(--teal);
            cursor: pointer;
        }
        .options-row a {
            color: var(--teal);
            text-decoration: none;
            font-weight: 500;
            font-style: italic;
            transition: color 0.2s;
        }
        .options-row a:hover {
            color: var(--navy);
            text-decoration: underline;
        }

        .btn-submit {
            width: 100%;
            padding: 12px;
            border-radius: 12px;
            border: none;
            background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 50%, #52B2DC 100%);
            color: #FFFFFF;
            font-family: var(--font-h);
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 10px 24px rgba(13, 122, 135, 0.32);
            transition: all 0.32s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        @media (min-width: 768px) {
            .btn-submit { padding: 14px; font-size: 1rem; border-radius: 14px; }
        }
        .btn-submit::after {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 50%;
            height: 100%;
            background: linear-gradient(to right, rgba(255,255,255,0) 0%, rgba(255,255,255,0.60) 50%, rgba(255,255,255,0) 100%);
            transform: skewX(-20deg);
            animation: sweepShine 3s cubic-bezier(0.4, 0, 0.2, 1) infinite;
        }
        @keyframes sweepShine {
            0%   { left: -100%; }
            20%  { left: 200%; }
            100% { left: 200%; }
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 30px rgba(13, 122, 135, 0.44);
        }
        .btn-submit:active { transform: translateY(0); }
        .btn-submit:disabled { opacity: 0.7; cursor: not-allowed; }

        /* ==========================================================
           DROPDOWN INSTALL APP (PWA & EXAM BROWSER)
           ========================================================== */
        .form-head-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 2px;
            position: relative;
        }
        @media (min-width: 768px) {
            .form-head-row { margin-bottom: 4px; }
        }
        .form-head-row .form-heading { margin-bottom: 0; }

        .app-install-dropdown-wrap {
            position: relative;
            display: inline-block;
        }

        .btn-app-install-trigger {
            padding: 6px 11px;
            border-radius: 10px;
            border: 1.5px solid rgba(13, 122, 135, 0.35);
            background: rgba(255, 255, 255, 0.90);
            color: var(--teal);
            font-family: var(--font-h);
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            white-space: nowrap;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(13, 122, 135, 0.10);
            transition: all 0.25s ease;
        }
        .btn-app-install-trigger i.fa-chevron-down {
            font-size: 0.65rem;
            transition: transform 0.25s ease;
            margin-left: 2px;
        }
        .btn-app-install-trigger:hover,
        .app-install-dropdown-wrap.is-open .btn-app-install-trigger {
            background: var(--teal);
            border-color: var(--teal);
            color: #FFFFFF;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(13, 122, 135, 0.25);
        }
        .app-install-dropdown-wrap.is-open .btn-app-install-trigger i.fa-chevron-down {
            transform: rotate(180deg);
        }
        @media (min-width: 768px) {
            .btn-app-install-trigger {
                padding: 7px 13px;
                font-size: 0.8rem;
                border-radius: 11px;
            }
        }

        /* DROPDOWN MENU */
        .app-install-dropdown-menu {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: 295px;
            max-width: calc(100vw - 36px);
            background: #FFFFFF;
            border: 1.5px solid #E2E8F0;
            border-radius: 16px;
            box-shadow: 0 16px 40px rgba(29, 59, 94, 0.18);
            padding: 8px;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-8px) scale(0.96);
            transform-origin: top right;
            transition: opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1), transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.2s;
            pointer-events: none;
        }
        .app-install-dropdown-wrap.is-open .app-install-dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
            pointer-events: auto;
        }
        .dropdown-header-label {
            font-family: var(--font-h);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #94A3B8;
            padding: 6px 10px 4px;
        }
        .app-dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 12px;
            width: 100%;
            border: none;
            background: transparent;
            text-align: left;
            text-decoration: none;
            color: var(--navy);
            cursor: pointer;
            transition: background 0.18s ease, transform 0.18s ease;
        }
        .app-dropdown-item:hover {
            background: #F8FAFC;
            transform: translateX(2px);
        }
        .app-dropdown-item:active {
            transform: scale(0.98);
        }
        .item-icon-box {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .item-icon-pwa {
            background: rgba(13, 122, 135, 0.1);
            color: var(--teal);
        }
        .item-icon-exam {
            background: rgba(245, 158, 11, 0.12);
            color: #D97706;
        }
        .item-text-box {
            flex: 1;
            min-width: 0;
        }
        .item-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            margin-bottom: 2px;
        }
        .item-title {
            font-family: var(--font-h);
            font-size: 13px;
            font-weight: 700;
            color: var(--navy);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .item-badge-pill {
            font-size: 9.5px;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            flex-shrink: 0;
        }
        .pill-pwa {
            background: #E0F2FE;
            color: #0284C7;
        }
        .pill-exam {
            background: #FEF3C7;
            color: #B45309;
        }
        .item-subtitle {
            font-size: 11px;
            color: #64748B;
            line-height: 1.35;
        }
        .app-dropdown-divider {
            height: 1px;
            background: #F1F5F9;
            margin: 4px 6px;
        }

        /* ==========================================================
           MASUK DENGAN AKUN GOOGLE (SSO CHECK DB ONLY)
           ========================================================== */
        .sso-divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 10px 0;
            color: #94A3B8;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        @media (min-width: 768px) {
            .sso-divider { margin: 13px 0; }
        }
        .sso-divider::before,
        .sso-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(to right, transparent, #CBD5E1, transparent);
        }
        .btn-google-login {
            width: 100%;
            padding: 10px 14px;
            border-radius: 12px;
            border: 1.5px solid #E2E8F0;
            background: rgba(255, 255, 255, 0.92);
            color: #1E293B;
            font-family: var(--font-h);
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
            transition: all 0.25s ease;
        }
        @media (min-width: 768px) {
            .btn-google-login { padding: 12px 16px; font-size: 0.92rem; border-radius: 14px; }
        }
        .btn-google-login svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }
        .btn-google-login:hover {
            background: #FFFFFF;
            border-color: var(--teal);
            color: var(--navy);
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(13, 122, 135, 0.14);
        }
        .btn-google-login:active { transform: translateY(0); }

        /* Modal Masuk dengan Google */
        .google-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 100050;
            background: rgba(21, 44, 71, 0.55);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .google-modal-overlay.active { display: flex; }
        .google-modal-card {
            width: 100%;
            max-width: 400px;
            background: #FFFFFF;
            border-radius: 20px;
            padding: 24px 22px;
            box-shadow: 0 24px 48px rgba(15, 29, 47, 0.25);
            border: 1px solid #E2E8F0;
            animation: alertSlide 0.28s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        .google-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }
        .google-modal-title-wrap {
            display: flex;
            align-items: center;
            gap: 11px;
        }
        .google-modal-badge {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .google-modal-badge svg { width: 20px; height: 20px; }
        .google-modal-title {
            font-family: var(--font-h);
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--navy);
            line-height: 1.2;
        }
        .google-modal-sub {
            font-size: 0.75rem;
            color: #64748B;
            font-weight: 500;
        }
        .google-modal-close {
            background: #F1F5F9;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            color: #64748B;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .google-modal-close:hover { background: #E2E8F0; color: var(--navy); }
        .google-modal-desc {
            font-size: 0.78rem;
            color: #475569;
            line-height: 1.5;
            margin-bottom: 14px;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            padding: 9px 11px;
        }
        .google-modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }
        .btn-google-cancel {
            flex: 1;
            padding: 11px;
            border-radius: 11px;
            border: 1.5px solid #E2E8F0;
            background: #F8FAFC;
            color: #475569;
            font-family: var(--font-h);
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-google-cancel:hover { background: #E2E8F0; color: var(--navy); }
        .btn-google-submit {
            flex: 1.4;
            padding: 11px;
            border-radius: 11px;
            border: none;
            background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%);
            color: #FFFFFF;
            font-family: var(--font-h);
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 8px 18px rgba(13, 122, 135, 0.28);
            transition: all 0.2s;
        }
        .btn-google-submit:hover { transform: translateY(-1px); box-shadow: 0 10px 22px rgba(13, 122, 135, 0.36); }
        .btn-google-submit:disabled { opacity: 0.7; cursor: not-allowed; }

        .form-footer {
            margin-top: 14px;
            text-align: center;
        }
        @media (min-width: 768px) {
            .form-footer { margin-top: 24px; }
        }
        .form-footer p {
            color: #64748B;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        @media (min-width: 768px) {
            .form-footer p { font-size: 11px; }
        }
        .form-footer-links {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding-top: 4px;
            font-size: 10px;
            font-weight: 500;
            color: #64748B;
        }
        @media (min-width: 768px) {
            .form-footer-links { font-size: 11px; gap: 10px; padding-top: 6px; }
        }
        .form-footer-links a {
            color: #64748B;
            text-decoration: none;
            transition: color 0.2s;
        }
        .form-footer-links a:hover { color: var(--navy); }
        .form-footer-links .sep {
            width: 3px; height: 3px;
            border-radius: 50%;
            background: #94A3B8;
        }

        /* ==========================================================
           MOBILE: sangat kecil (<= 380px) — sembunyikan subheading
           ========================================================== */
        @media (max-width: 380px) {
            .form-subheading { display: none; }
            .slide-glass-card { padding: 8px; }
            .form-panel { padding-top: 30px; }
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
                            <p>Learn Management System</p>
                        </div>
                    </div>

                    <div class="badge">
                        <span class="dot"></span>
                        <span>Learn Management System</span>
                    </div>

                    <h1 class="slide-h1">
                        Ilmuna Learn<br>
                        <span class="gradient-text">Portal Akademik</span>
                    </h1>

                    <p class="slide-desc">
                        SIAKAD &amp; LMS Pembelajaran Pendidikan Agama Islam — Portal terintegrasi untuk guru, siswa, dan pengelola akademik.
                    </p>
                </div>
            </div>
        </div>

        <!-- =========== RIGHT FORM PANEL =========== -->
        <div class="form-panel" id="formPanel">
            <div class="form-inner">

                <div class="form-head-row">
                    <h2 class="form-heading">Masuk</h2>
                    <div class="app-install-dropdown-wrap" id="appInstallDropdown">
                        <button type="button" class="btn-app-install-trigger" id="btnAppInstallTrigger" aria-expanded="false" aria-haspopup="true" title="Pilihan Pemasangan Aplikasi">
                            <i class="fas fa-download"></i>
                            <span>Install App</span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="app-install-dropdown-menu" id="appInstallDropdownMenu" role="menu">
                            <div class="dropdown-header-label">PILIH APLIKASI</div>

                            <!-- OPSI 1: INSTALL PWA SECARA LANGSUNG -->
                            <button type="button" class="app-dropdown-item" id="btnPwaInstallOption" data-pwa-install role="menuitem" title="Pasang Web App (PWA) Secara Langsung">
                                <div class="item-icon-box item-icon-pwa">
                                    <i class="fas fa-mobile-screen-button"></i>
                                </div>
                                <div class="item-text-box">
                                    <div class="item-title-row">
                                        <span class="item-title">Web App (PWA)</span>
                                        <span class="item-badge-pill pill-pwa">Instan</span>
                                    </div>
                                    <div class="item-subtitle">Pasang ke layar HP tanpa unduh APK</div>
                                </div>
                            </button>

                            <div class="app-dropdown-divider"></div>

                            <!-- OPSI 2: UNDUH EXAM BROWSER APK (HALAMAN RESMI) -->
                            <a href="<?= (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . '/download-exam' ?>" class="app-dropdown-item" role="menuitem" title="Unduh Aplikasi Exam Browser CBT">
                                <div class="item-icon-box item-icon-exam">
                                    <i class="fas fa-shield-halved"></i>
                                </div>
                                <div class="item-text-box">
                                    <div class="item-title-row">
                                        <span class="item-title">Exam Browser</span>
                                        <span class="item-badge-pill pill-exam">CBT Kiosk</span>
                                    </div>
                                    <div class="item-subtitle">Aplikasi ujian aman anti-curang Android</div>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
                <p class="form-subheading">Selamat datang kembali di Ilmuna Learn</p>

                <?php if ($error): ?>
                    <div class="alert-error" role="alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <div><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <button type="button" class="alert-close"
                                onclick="this.parentElement.remove()" aria-label="Tutup">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="loginForm" autocomplete="on" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="hp-field" aria-hidden="true">
                        <label for="website">Website (jangan diisi)</label>
                        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="field">
                        <label class="field-label" for="identifier">Email atau Username</label>
                        <div class="input-wrap">
                            <i class="fas fa-user"></i>
                            <input type="text"
                                   id="identifier"
                                   name="identifier"
                                   class="form-input"
                                   placeholder="Email atau username"
                                   maxlength="120"
                                   required autofocus
                                   autocomplete="username"
                                   spellcheck="false">
                        </div>
                    </div>

                    <div class="field">
                        <label class="field-label" for="password">Password</label>
                        <div class="input-wrap">
                            <i class="fas fa-lock"></i>
                            <input type="password"
                                   id="password"
                                   name="password"
                                   class="form-input"
                                   placeholder="Password"
                                   maxlength="200"
                                   required
                                   autocomplete="current-password">
                            <button type="button"
                                    class="toggle-password"
                                    id="togglePasswordBtn"
                                    aria-label="Tampilkan password">
                                <i class="fas fa-eye" id="togglePasswordIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="options-row">
                        <label>
                            <input type="checkbox" name="remember"> Ingat Saya
                        </label>
                        <a href="forgot-password.php">Lupa Password?</a>
                    </div>

                    <button type="submit" class="btn-submit" id="btnSubmit">
                        <span>Masuk</span>
                        <i class="fas fa-arrow-right" style="font-size: 0.85rem;"></i>
                    </button>
                </form>

                <!-- Masuk dengan Akun Google (Hanya mengecek ke DB, tidak mendaftarkan akun baru) -->
                <div class="sso-divider"><span>atau</span></div>
                <button type="button" class="btn-google-login" id="btnGoogleLogin">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="#EA4335" d="M12 10.2v3.9h5.5c-.2 1.3-1.6 3.8-5.5 3.8-3.3 0-6-2.7-6-6s2.7-6 6-6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 3.4 14.6 2.5 12 2.5 6.8 2.5 2.5 6.8 2.5 12s4.3 9.5 9.5 9.5c5.5 0 9.1-3.9 9.1-9.3 0-.6-.1-1.1-.2-1.6H12z"/>
                        <path fill="#34A853" d="M3.6 7.4l3.2 2.4C7.7 7.7 9.7 6 12 6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 3.4 14.6 2.5 12 2.5 8.3 2.5 5.1 4.6 3.6 7.4z"/>
                        <path fill="#FBBC05" d="M12 21.5c2.5 0 4.7-.8 6.3-2.3l-2.9-2.4c-.8.6-1.9 1-3.4 1-3.8 0-5.2-2.5-5.5-3.8l-3.2 2.5c1.5 3 4.8 5 8.7 5z"/>
                        <path fill="#4285F4" d="M21.1 12.2c0-.6-.1-1.1-.2-1.6H12v3.9h5.5c-.3 1.3-1.1 2.4-2.1 3.1l2.9 2.4c1.7-1.6 2.8-4 2.8-7.8z"/>
                    </svg>
                    <span>Masuk dengan Akun Google</span>
                </button>
            </div>

            <div class="form-footer">
                <p>Hak Cipta © <?= date('Y') ?> Ilmuna Learn</p>
                <div class="form-footer-links">
                    <a href="/about" data-transition>Tentang</a>
                    <span class="sep"></span>
                    <a href="/terms" data-transition>Syarat</a>
                    <span class="sep"></span>
                    <a href="/privacy" data-transition>Privasi</a>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL MASUK DENGAN AKUN GOOGLE (CEK DB SAJA) -->
    <div class="google-modal-overlay" id="googleLoginModal" role="dialog" aria-modal="true" aria-labelledby="googleModalTitle">
        <div class="google-modal-card">
            <div class="google-modal-head">
                <div class="google-modal-title-wrap">
                    <div class="google-modal-badge">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="#EA4335" d="M12 10.2v3.9h5.5c-.2 1.3-1.6 3.8-5.5 3.8-3.3 0-6-2.7-6-6s2.7-6 6-6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 3.4 14.6 2.5 12 2.5 6.8 2.5 2.5 6.8 2.5 12s4.3 9.5 9.5 9.5c5.5 0 9.1-3.9 9.1-9.3 0-.6-.1-1.1-.2-1.6H12z"/>
                            <path fill="#34A853" d="M3.6 7.4l3.2 2.4C7.7 7.7 9.7 6 12 6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 3.4 14.6 2.5 12 2.5 8.3 2.5 5.1 4.6 3.6 7.4z"/>
                            <path fill="#FBBC05" d="M12 21.5c2.5 0 4.7-.8 6.3-2.3l-2.9-2.4c-.8.6-1.9 1-3.4 1-3.8 0-5.2-2.5-5.5-3.8l-3.2 2.5c1.5 3 4.8 5 8.7 5z"/>
                            <path fill="#4285F4" d="M21.1 12.2c0-.6-.1-1.1-.2-1.6H12v3.9h5.5c-.3 1.3-1.1 2.4-2.1 3.1l2.9 2.4c1.7-1.6 2.8-4 2.8-7.8z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="google-modal-title" id="googleModalTitle">Masuk dengan Google</div>
                        <div class="google-modal-sub">Verifikasi akun Google terdaftar</div>
                    </div>
                </div>
                <button type="button" class="google-modal-close" id="btnCloseGoogleModal" aria-label="Tutup">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <p class="google-modal-desc">
                Masukkan alamat <strong>Email Google</strong> yang sudah terdaftar atau ditautkan pada akun Ilmuna Anda.
            </p>

            <form method="POST" action="" id="googleLoginForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="login_mode" value="google">
                <div class="hp-field" aria-hidden="true">
                    <input type="text" name="website" tabindex="-1" autocomplete="off">
                </div>

                <div class="field" style="margin-bottom: 0;">
                    <label class="field-label" for="googleEmailInput">Email Google</label>
                    <div class="input-wrap">
                        <i class="fab fa-google"></i>
                        <input type="email"
                               id="googleEmailInput"
                               name="email_google"
                               class="form-input"
                               placeholder="nama@gmail.com"
                               maxlength="150"
                               required
                               autocomplete="email"
                               spellcheck="false">
                    </div>
                </div>

                <div class="google-modal-actions">
                    <button type="button" class="btn-google-cancel" id="btnCancelGoogleModal">Batal</button>
                    <button type="submit" class="btn-google-submit" id="btnSubmitGoogleLogin">
                        <span>Masuk</span>
                        <i class="fas fa-arrow-right" style="font-size: 0.8rem;"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== ''): ?>
        <script src="https://accounts.google.com/gsi/client" async defer></script>
    <?php endif; ?>

    <script>
    (function () {
        'use strict';

        /* ---------- 1. Anti-download & anti-inspect ---------- */
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

        /* ---------- 2. Page transition sweep-out ---------- */
        var pageTransition = document.getElementById('pageTransition');
        setTimeout(function () {
            if (pageTransition) pageTransition.classList.remove('active');
        }, 100);

        /* ---------- 3. Canvas particle system ---------- */
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

        /* ---------- 4. Toggle password ---------- */
        var btnToggle = document.getElementById('togglePasswordBtn');
        var passInput = document.getElementById('password');
        var passIcon  = document.getElementById('togglePasswordIcon');
        if (btnToggle && passInput && passIcon) {
            btnToggle.addEventListener('click', function () {
                if (passInput.type === 'password') {
                    passInput.type = 'text';
                    passIcon.classList.remove('fa-eye');
                    passIcon.classList.add('fa-eye-slash');
                } else {
                    passInput.type = 'password';
                    passIcon.classList.remove('fa-eye-slash');
                    passIcon.classList.add('fa-eye');
                }
            });
        }

        /* ---------- 5. Link clicks ---------- */
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
                    formPanel.style.transition = 'all 0.6s ease-in';
                }

                if (pageTransition) pageTransition.classList.add('active');

                setTimeout(function () {
                    window.location.href = href;
                }, 600);
            });
        });

        /* ---------- 6. Form submit loading ---------- */
        var loginForm = document.getElementById('loginForm');
        var btnSubmit = document.getElementById('btnSubmit');
        if (loginForm && btnSubmit) {
            loginForm.addEventListener('submit', function () {
                btnSubmit.disabled = true;
                btnSubmit.innerHTML =
                    '<i class="fas fa-circle-notch fa-spin" style="font-size: 0.9rem;"></i>' +
                    '<span>Memproses...</span>';
            });
        }

        /* ---------- 7. Masuk dengan Akun Google (Cek DB Saja) ---------- */
        var GOOGLE_CLIENT_ID = <?= json_encode(defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '') ?>;
        var btnGoogleLogin   = document.getElementById('btnGoogleLogin');
        var googleModal      = document.getElementById('googleLoginModal');
        var btnCloseGoogle   = document.getElementById('btnCloseGoogleModal');
        var btnCancelGoogle  = document.getElementById('btnCancelGoogleModal');
        var googleForm       = document.getElementById('googleLoginForm');
        var googleEmailInput = document.getElementById('googleEmailInput');
        var btnSubmitGoogle  = document.getElementById('btnSubmitGoogleLogin');

        function openGoogleModal() {
            if (!googleModal) return;
            googleModal.classList.add('active');
            setTimeout(function () {
                if (googleEmailInput) googleEmailInput.focus();
            }, 80);
        }

        function closeGoogleModal() {
            if (!googleModal) return;
            googleModal.classList.remove('active');
        }

        function submitGoogleVerify(payload, triggerBtn, defaultBtnHtml) {
            if (triggerBtn) {
                triggerBtn.disabled = true;
                triggerBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i><span>Memeriksa...</span>';
            }

            var fd = new FormData();
            fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
            fd.append('login_mode', 'google');
            fd.append('ajax', '1');
            if (payload.accessToken) {
                fd.append('google_access_token', payload.accessToken);
            } else if (payload.email) {
                fd.append('email_google', payload.email);
            }

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success && res.redirect) {
                    closeGoogleModal();
                    if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
                        window.Ilmuna.toast('success', 'Berhasil Masuk', 'Akun Google terverifikasi. Mengalihkan...');
                    }
                    setTimeout(function () { window.location.href = res.redirect; }, 350);
                    return;
                }
                var msg = (res && res.message) ? res.message : 'Akun Google tidak terdaftar di database.';
                if (window.Ilmuna && typeof window.Ilmuna.alert === 'function') {
                    window.Ilmuna.alert('error', 'Tidak Dapat Masuk', window.Ilmuna.escape(msg), 'Mengerti');
                } else {
                    window.alert(msg);
                }
                if (triggerBtn) {
                    triggerBtn.disabled = false;
                    triggerBtn.innerHTML = defaultBtnHtml;
                }
            })
            .catch(function () {
                if (triggerBtn) {
                    triggerBtn.disabled = false;
                    triggerBtn.innerHTML = defaultBtnHtml;
                }
                if (googleForm && payload.email) {
                    googleForm.submit();
                }
            });
        }

        if (btnGoogleLogin) {
            var defaultGoogleBtnHtml = btnGoogleLogin.innerHTML;
            btnGoogleLogin.addEventListener('click', function () {
                // Jika GOOGLE_CLIENT_ID diisi & library Google OAuth siap, gunakan popup resmi Google
                if (GOOGLE_CLIENT_ID && window.google && window.google.accounts && window.google.accounts.oauth2) {
                    var tokenClient = window.google.accounts.oauth2.initTokenClient({
                        client_id: GOOGLE_CLIENT_ID,
                        scope: 'openid email profile',
                        prompt: 'select_account',
                        callback: function (tokenResponse) {
                            if (tokenResponse && tokenResponse.access_token) {
                                submitGoogleVerify({ accessToken: tokenResponse.access_token }, btnGoogleLogin, defaultGoogleBtnHtml);
                            }
                        }
                    });
                    tokenClient.requestAccessToken();
                } else {
                    openGoogleModal();
                }
            });
        }

        if (btnCloseGoogle) btnCloseGoogle.addEventListener('click', closeGoogleModal);
        if (btnCancelGoogle) btnCancelGoogle.addEventListener('click', closeGoogleModal);
        if (googleModal) {
            googleModal.addEventListener('click', function (e) {
                if (e.target === googleModal) closeGoogleModal();
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && googleModal && googleModal.classList.contains('active')) {
                closeGoogleModal();
            }
        });

        if (googleForm && btnSubmitGoogle) {
            var defaultModalSubmitHtml = btnSubmitGoogle.innerHTML;
            googleForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var emailVal = googleEmailInput ? googleEmailInput.value.trim() : '';
                if (!emailVal) {
                    if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
                        window.Ilmuna.toast('warning', 'Perhatian', 'Silakan masukkan alamat email Google Anda.');
                    }
                    if (googleEmailInput) googleEmailInput.focus();
                    return;
                }
                submitGoogleVerify({ email: emailVal }, btnSubmitGoogle, defaultModalSubmitHtml);
            });
        }

        // ==========================================================
        // DROPDOWN INSTALL APP (PWA LANGSUNG & EXAM BROWSER)
        // ==========================================================
        var dropdownWrap = document.getElementById('appInstallDropdown');
        var dropdownTrigger = document.getElementById('btnAppInstallTrigger');
        var pwaOption = document.getElementById('btnPwaInstallOption');

        if (dropdownWrap && dropdownTrigger) {
            dropdownTrigger.addEventListener('click', function (e) {
                e.stopPropagation();
                var isOpen = dropdownWrap.classList.toggle('is-open');
                dropdownTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            document.addEventListener('click', function (e) {
                if (!dropdownWrap.contains(e.target)) {
                    dropdownWrap.classList.remove('is-open');
                    dropdownTrigger.setAttribute('aria-expanded', 'false');
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && dropdownWrap.classList.contains('is-open')) {
                    dropdownWrap.classList.remove('is-open');
                    dropdownTrigger.setAttribute('aria-expanded', 'false');
                }
            });

            if (pwaOption) {
                pwaOption.addEventListener('click', function () {
                    dropdownWrap.classList.remove('is-open');
                    dropdownTrigger.setAttribute('aria-expanded', 'false');
                });
            }
        }

    })();
    </script>
    <script src="<?= BASE_URL ?>/assets/js/alert.js"></script>
</body>
</html>