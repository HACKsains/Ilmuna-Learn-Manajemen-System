<?php
// ==============================================================================
// GUARDIAN DOME (Patch-3.0) — LAYER PERLINDUNGAN TERDEPAN EKOSISTEM ILMUNA
// Target: learn.ilmuna.my.id (Subdomain)
// Menyaring 100% trafik masuk, Anti-Judol, Anti-DDoS, Auto-Ban Blacklist IP,
// dan Verifikasi Pengunjung sebelum koneksi database atau core sistem dimuat.
// ==============================================================================
$gd_waf_candidates = [
    '/home/uofruaju/guardian-dome/guardian-dome-waf.php',
    'C:/laragon/Guardian Dome/guardian-dome-waf.php',
    'C:/laragon/guardian-dome/guardian-dome-waf.php',
    dirname(dirname(__DIR__)) . '/Guardian Dome/guardian-dome-waf.php',
    dirname(dirname(__DIR__)) . '/guardian-dome/guardian-dome-waf.php',
    dirname(__DIR__) . '/Guardian Dome/guardian-dome-waf.php',
    dirname(__DIR__) . '/guardian-dome/guardian-dome-waf.php',
    __DIR__ . '/../Guardian Dome/guardian-dome-waf.php',
    __DIR__ . '/../guardian-dome/guardian-dome-waf.php',
    __DIR__ . '/guardian-dome-waf.php'
];
foreach ($gd_waf_candidates as $gd_waf) {
    if ($gd_waf && file_exists($gd_waf)) {
        require_once $gd_waf;
        break;
    }
}

// ==============================================================================
// GUARDIAN DOME — TELEGRAM SENTINEL REAL-TIME MONITORING
// Memantau ancaman siber, fatal error/crash, dan statistik kunjungan harian via Telegram
// ==============================================================================
$gd_tg_candidates = [
    '/home/uofruaju/guardian-dome/guardian-dome-telegram/bootstrap.php',
    'C:/laragon/Guardian Dome/guardian-dome-telegram/bootstrap.php',
    'C:/laragon/guardian-dome/guardian-dome-telegram/bootstrap.php',
    dirname(dirname(__DIR__)) . '/Guardian Dome/guardian-dome-telegram/bootstrap.php',
    dirname(dirname(__DIR__)) . '/guardian-dome/guardian-dome-telegram/bootstrap.php',
    dirname(__DIR__) . '/Guardian Dome/guardian-dome-telegram/bootstrap.php',
    dirname(__DIR__) . '/guardian-dome/guardian-dome-telegram/bootstrap.php',
    __DIR__ . '/../Guardian Dome/guardian-dome-telegram/bootstrap.php',
    __DIR__ . '/../guardian-dome/guardian-dome-telegram/bootstrap.php',
    __DIR__ . '/guardian-dome-telegram/bootstrap.php'
];
foreach ($gd_tg_candidates as $gd_tg) {
    if ($gd_tg && file_exists($gd_tg)) {
        require_once $gd_tg;
        break;
    }
}

/**
 * ==============================================================================
 * ILMUNA LEARN — CONFIGURATION & DATABASE CONNECTION
 * ==============================================================================
 * Platform: Media Pembelajaran Pendidikan Agama Islam (PAI) & SIAKAD LMS
 * Domain: learn.ilmuna.my.id
 * Theme Aesthetic: Lumina Gold (Navy: #1D3B5E, Teal: #0D7A87, Sky: #52B2DC, Gold: #F59E0B)
 * ==============================================================================
 */

// Set Timezone default ke Waktu Indonesia Barat (WIB)
date_default_timezone_set('Asia/Jakarta');

// PHP 8 Compatibility Polyfills for PHP 7.4 / older environments
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp((string)$haystack, (string)$needle, strlen((string)$needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return (string)$needle === '' || (string)$needle === substr((string)$haystack, -strlen((string)$needle));
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return (string)$needle !== '' && mb_strpos((string)$haystack, (string)$needle) !== false;
    }
}

// Start session if not active and not in CLI mode
if (session_status() === PHP_SESSION_NONE && !headers_sent() && php_sapi_name() !== 'cli') {
    session_start();
}

// Izinkan Akses Browser Geolocation (GPS) & Kamera (Scan QR) untuk Presensi Pintar & SIAKAD
if (!headers_sent() && php_sapi_name() !== 'cli') {
    header("Permissions-Policy: geolocation=(self), camera=(self), microphone=()");
}

// Application Constants
define('APP_NAME', 'Ilmuna Learn');
define('APP_TAGLINE', 'Media Pembelajaran Digital Pendidikan Agama Islam');
define('APP_VERSION', '1.0.0');
define('APP_DOMAIN', 'learn.ilmuna.my.id');

// Base URL Auto-detection
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = preg_replace('#/(dashboard/.*|api/.*|api|gateway\.php|login\.php|logout\.php|install\.php)$#', '', $scriptDir);
$basePath = rtrim($basePath, '/');
if ($basePath === '.' || $basePath === './') {
    $basePath = '';
}
define('BASE_URL', $protocol . $host . $basePath);
define('BASE_PATH', __DIR__);

// Centralized Favicon Configuration (Sentralisasi Favicon Website)
// Menggunakan assets/images/logo.ico sebagai icon resmi seluruh aplikasi
define('APP_FAVICON_FILE', '/assets/images/logo.ico');
define('APP_FAVICON', BASE_URL . APP_FAVICON_FILE);

/**
 * Mendapatkan URL favicon resmi dengan query string filemtime (cache-busting otomatis)
 */
function getFaviconUrl() {
    $relativePath = defined('APP_FAVICON_FILE') ? APP_FAVICON_FILE : '/assets/images/logo.ico';
    $absolutePath = (defined('BASE_PATH') ? BASE_PATH : __DIR__) . $relativePath;
    $version = file_exists($absolutePath) ? filemtime($absolutePath) : (defined('APP_VERSION') ? APP_VERSION : '1.0');
    $baseUrl = defined('APP_FAVICON') ? APP_FAVICON : ((defined('BASE_URL') ? BASE_URL : '') . $relativePath);
    return $baseUrl . '?v=' . $version;
}

/**
 * Render tag HTML <link> favicon resmi tersentralisasi untuk disisipkan di <head>
 */
function renderFavicon() {
    $url = htmlspecialchars(getFaviconUrl(), ENT_QUOTES, 'UTF-8');
    return '    <!-- Centralized Website Favicon via config.php -->' . "\n"
         . '    <link rel="icon" type="image/x-icon" href="' . $url . '">' . "\n"
         . '    <link rel="shortcut icon" type="image/x-icon" href="' . $url . '">' . "\n"
         . renderPwaHead();
}

/**
 * Render tag PWA (manifest, theme-color, ikon iOS, dan script pwa.js).
 * Otomatis ikut tercetak di setiap halaman yang memanggil renderFavicon().
 */
function renderPwaHead() {
    $base  = defined('BASE_URL') ? BASE_URL : '';
    $root  = defined('BASE_PATH') ? BASE_PATH : __DIR__;
    $ver   = function ($rel) use ($root) {
        $f = $root . $rel;
        return file_exists($f) ? filemtime($f) : (defined('APP_VERSION') ? APP_VERSION : '1');
    };
    $e     = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $name  = defined('APP_NAME') ? APP_NAME : 'Ilmuna Learn';

    return '    <!-- PWA (Progressive Web App) via config.php -->' . "\n"
         . '    <link rel="manifest" href="' . $e($base . '/manifest.json?v=' . $ver('/manifest.json')) . '">' . "\n"
         . '    <meta name="theme-color" content="#0D7A87">' . "\n"
         . '    <meta name="mobile-web-app-capable" content="yes">' . "\n"
         . '    <meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
         . '    <meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n"
         . '    <meta name="apple-mobile-web-app-title" content="' . $e($name) . '">' . "\n"
         . '    <link rel="apple-touch-icon" sizes="180x180" href="' . $e($base . '/assets/pwa/apple-touch-icon.png') . '">' . "\n"
         . '    <script src="' . $e($base . '/assets/js/pwa.js?v=' . $ver('/assets/js/pwa.js')) . '" data-base="' . $e($base) . '" defer></script>';
}

// ==============================================================================
// CENTRALIZED UI TEMPLATES & ICONIFY ENGINE
// ==============================================================================
// Icon Set Resmi Seluruh Website: Iconify Solar Icons (solar:*-bold, solar:*-linear, solar:*-bold-duotone)
define('ICONIFY_CDN_URL', 'https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js');
define('ICONIFY_OFFICIAL_SET', 'solar');

// Sentralisasi Path Direktori & Berkas Template UI (Includes)
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('TEMPLATE_MODAL_PATH', BASE_PATH . '/includes/modal-template.php');
define('TEMPLATE_CALENDAR_PATH', BASE_PATH . '/includes/calendar-template.php');
define('TEMPLATE_TABLE_PATH', BASE_PATH . '/includes/table-template.php');
define('ALERT_JS_PATH', BASE_PATH . '/assets/js/alert.js');

/**
 * Render tag script Iconify resmi tersentralisasi
 */
function renderIconifyScript() {
    return '    <!-- Centralized Iconify Solar Icons Engine via config.php -->' . "\n"
         . '    <script src="' . ICONIFY_CDN_URL . '" defer></script>';
}

/**
 * Pemuat Sentral Template UI Ilmuna (Modal, Kalender, Tabel Data)
 * Memastikan modul dapat memuat template universal secara terpusat dan efisien
 */
function loadCentralTemplates(array $templates = ['modal', 'calendar', 'table']) {
    foreach ($templates as $tpl) {
        switch (strtolower(trim($tpl))) {
            case 'modal':
                if (file_exists(TEMPLATE_MODAL_PATH)) require_once TEMPLATE_MODAL_PATH;
                break;
            case 'calendar':
            case 'kalender':
                if (file_exists(TEMPLATE_CALENDAR_PATH)) require_once TEMPLATE_CALENDAR_PATH;
                break;
            case 'table':
            case 'tabel':
                if (file_exists(TEMPLATE_TABLE_PATH)) require_once TEMPLATE_TABLE_PATH;
                break;
        }
    }
}

// MySQL Database Credentials (learn.ilmuna.my.id / cPanel / Local)
define('DB_TYPE', 'mysql');
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'uofruaju_learn_ilmuna');
define('DB_USER', 'uofruaju_Learn_Ilmuna');
define('DB_PASS', 'Ilmuna28@');
define('DB_CHARSET', 'utf8mb4');

// ==============================================================================
// GOOGLE APPS SCRIPT RELAY (HTTPS BYPASS - PORT 443)
// ==============================================================================
// Menggunakan Google Apps Script HTTPS Web App untuk mengirim email langsung
// dari support.ilmuna@gmail.com, 100% bypass pemblokiran port SMTP & bebas limit cPanel.
define('ILMUNA_GOOGLE_RELAY_URL', 'https://script.google.com/macros/s/AKfycbyfBUsTy6pTx-738xeXs_-MahXE_xAP3DOqfVNEfBE0TBgIjKPPlQ2z7FZHXsoR_i0Cwg/exec');

// ==============================================================================
// OFFICIAL SMTP CONFIGURATION (CPANEL HOSTING EXIM / MAIL SERVER)
// ==============================================================================
// Menggunakan Port 465 SSL Direct ke server mail cPanel (mail.ilmuna.my.id)
define('ILMUNA_SMTP_HOST', 'mail.ilmuna.my.id');
define('ILMUNA_SMTP_PORT', 465);
define('ILMUNA_SMTP_SECURE', 'ssl'); // 'ssl' (Port 465)
define('ILMUNA_SMTP_USER', 'noreply@ilmuna.my.id');
define('ILMUNA_SMTP_PASS', 'Ilmuna28@'); // Akun resmi cPanel
define('ILMUNA_SMTP_FROM', 'noreply@ilmuna.my.id');
define('ILMUNA_SMTP_FROM_NAME', 'Ilmuna System');
define('ILMUNA_SMTP_ENABLED', true);

/**
 * Mendapatkan Konfigurasi SMTP Terpadu
 */
function getSmtpConfig() {
    return [
        'host'       => defined('ILMUNA_SMTP_HOST') ? ILMUNA_SMTP_HOST : 'mail.ilmuna.my.id',
        'port'       => defined('ILMUNA_SMTP_PORT') ? (int)ILMUNA_SMTP_PORT : 465,
        'secure'     => defined('ILMUNA_SMTP_SECURE') ? ILMUNA_SMTP_SECURE : 'ssl',
        'user'       => defined('ILMUNA_SMTP_USER') ? ILMUNA_SMTP_USER : 'noreply@ilmuna.my.id',
        'pass'       => defined('ILMUNA_SMTP_PASS') ? ILMUNA_SMTP_PASS : 'Ilmuna28@',
        'from'       => defined('ILMUNA_SMTP_FROM') ? ILMUNA_SMTP_FROM : 'noreply@ilmuna.my.id',
        'from_name'  => defined('ILMUNA_SMTP_FROM_NAME') ? ILMUNA_SMTP_FROM_NAME : 'Ilmuna System',
        'is_enabled' => defined('ILMUNA_SMTP_ENABLED') ? (ILMUNA_SMTP_ENABLED ? 1 : 0) : 1,
    ];
}

// ==============================================================================
// GOOGLE SSO / OAUTH 2.0 CONFIGURATION
// ==============================================================================
if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', '81105294044-j8fm0kgnmo72vv1feglqnj71b25rtcre.apps.googleusercontent.com');
}
if (!defined('GOOGLE_CLIENT_SECRET')) {
    define('GOOGLE_CLIENT_SECRET', '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');
}

function getGoogleOAuthConfig() {
    return [
        'client_id'     => defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '',
        'client_secret' => defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : '',
        'redirect_uri'  => (defined('BASE_URL') ? BASE_URL : '') . '/login.php',
    ];
}

// ==============================================================================
// GOOGLE APPS SCRIPT (GAS) INTEGRATION (MATERI & GOOGLE DRIVE)
// ==============================================================================
// URL Web App Google Apps Script untuk otomatisasi upload materi & modul ke Google Drive
define('GAS_MATERI_URL', 'https://script.google.com/macros/s/AKfycbyidIcak-kKk6VFjuShevlpMv22y7H9nzEjZsT0fg75XQraesc6sNMsQ6gJL1HhwFo/exec');
define('GAS_FOLDER_ID', ''); // Opsional: Target ID Folder Google Drive

// URL Web App Google Apps Script untuk otomatisasi upload tugas & pengumpulan siswa ke Google Drive
define('GAS_TUGAS_URL', 'https://script.google.com/macros/s/AKfycbxmLXk38CPZzKKAPfyzB5ruldbj23sTxSVPeULEwimpxTWcXpRlyK2dzZlq-71bK_XfNQ/exec');
define('GAS_TUGAS_FOLDER_ID', ''); // Opsional: Target ID Folder Google Drive untuk Tugas

/**
 * Mendapatkan URL & konfigurasi Google Apps Script untuk Tugas
 */
function getGasTugasConfig() {
    return [
        'url'       => defined('GAS_TUGAS_URL') ? GAS_TUGAS_URL : '',
        'folder_id' => defined('GAS_TUGAS_FOLDER_ID') ? GAS_TUGAS_FOLDER_ID : '',
    ];
}

/**
 * Mendapatkan URL & konfigurasi Google Apps Script
 */
function getGasConfig() {
    return [
        'url'       => defined('GAS_MATERI_URL') ? GAS_MATERI_URL : '',
        'folder_id' => defined('GAS_FOLDER_ID') ? GAS_FOLDER_ID : '',
    ];
}

/**
 * Obfuscate / Hash ID Materi agar URL tidak mengekspos integer ID database secara telanjang
 * Contoh: id=6 -> id=c2bX9z...
 */
function hashMateriId($id) {
    $id = (int)$id;
    if ($id <= 0) return '';
    $secret = defined('APP_KEY') ? APP_KEY : 'ilmuna_materi_hash_key_2026';
    $iv = substr(hash('sha256', $secret . 'iv_salt'), 0, 16);
    $key = hash('sha256', $secret, true);
    $enc = openssl_encrypt((string)$id, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return rtrim(strtr(base64_encode($enc), '+/', '-_'), '=');
}

/**
 * Decode Hash ID kembali ke integer ID asli database (mendukung fallback integer lama)
 */
function decodeMateriId($hash) {
    if (empty($hash)) return 0;
    $hash = trim((string)$hash);
    if (ctype_digit($hash) && (int)$hash > 0 && strlen($hash) < 10) {
        return (int)$hash; // Dukungan transisi URL lama (?id=6)
    }
    $secret = defined('APP_KEY') ? APP_KEY : 'ilmuna_materi_hash_key_2026';
    $iv = substr(hash('sha256', $secret . 'iv_salt'), 0, 16);
    $key = hash('sha256', $secret, true);
    $data = strtr($hash, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    $raw = base64_decode($data);
    if ($raw === false) return 0;
    $decrypted = openssl_decrypt($raw, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return ($decrypted !== false && is_numeric($decrypted)) ? (int)$decrypted : 0;
}

/**
 * Get PDO Database Connection
 * Auto-initializes schema and tables if they do not exist yet!
 */
function getDBConnection() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $errors = [];

    // 1. Try connecting with user's MySQL credentials directly to DB_NAME
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+07:00'"
        ]);
    } catch (PDOException $e1) {
        $errors[] = "Main MySQL DB Connection: " . $e1->getMessage();

        // 2. If DB doesn't exist yet on MySQL, try connecting to host without db to create it
        try {
            $dsnNoDb = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=" . DB_CHARSET;
            $tempPdo = new PDO($dsnNoDb, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3
            ]);
            $tempPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e2) {
            $errors[] = "Create DB Attempt: " . $e2->getMessage();

            // 3. Check if local dev environment (Laragon / XAMPP root default)
            try {
                $dsnLocal = "mysql:host=127.0.0.1;port=3306;charset=utf8mb4";
                $localPdo = new PDO($dsnLocal, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
                $localPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=" . DB_NAME . ";charset=utf8mb4", 'root', '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            } catch (PDOException $e3) {
                $errors[] = "Local Dev MySQL: " . $e3->getMessage();

                // 4. Safe offline SQLite fallback so the app is always 100% testable & functional immediately
                try {
                    $sqliteFile = BASE_PATH . '/ilmuna_learn.sqlite';
                    $pdo = new PDO("sqlite:" . $sqliteFile, null, null, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]);
                    $pdo->exec("PRAGMA foreign_keys = ON;");
                } catch (PDOException $e4) {
                    die("Database Connection Error. Details:<br>" . implode("<br>", $errors));
                }
            }
        }
    }

    // Auto-check and initialize tables if needed
    autoInitializeDatabase($pdo);

    return $pdo;
}

/**
 * Auto Initialize Schema if tables are missing
 */
function autoInitializeDatabase($pdo) {
    static $alreadyChecked = false;
    if ($alreadyChecked) {
        return;
    }
    // Skip on AJAX API requests for maximum response speed
    if (isset($_POST['action']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        $alreadyChecked = true;
        return;
    }
    $alreadyChecked = true;

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tableExists = false;

        if ($driver === 'mysql') {
            $stmt = $pdo->query("SHOW TABLES LIKE 'kelas_mata_pelajaran'");
            $tableExists = (bool) $stmt->fetch();
        } else {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='kelas_mata_pelajaran'");
            $tableExists = (bool) $stmt->fetch();
        }

        if (!$tableExists) {
            require_once BASE_PATH . '/database.php';
            runDatabaseMigration($pdo);
        }
    } catch (Exception $e) {
        // Migration can also be triggered via install.php
    }
}

/**
 * CBT Event Flag: mencatat waktu kejadian terakhir (microsecond)
 * Digunakan untuk lazy loading event-driven instan tanpa membebani database
 */
function recordCbtEvent() {
    $flagFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ilmuna_cbt_event.flag';
    @file_put_contents($flagFile, (string)microtime(true), LOCK_EX);
}

function getCbtEventVersion() {
    $flagFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ilmuna_cbt_event.flag';
    return file_exists($flagFile) ? (string)@file_get_contents($flagFile) : '0';
}
