<?php
/**
 * ==============================================================================
 * ILMUNA EXAM BROWSER — IN-APP AUTO UPDATE REST API
 * ==============================================================================
 * Endpoint pengecekan versi aplikasi Android Exam Browser.
 * Membaca data rilis terbaru dari assets/apk/changelog.json dan
 * menyediakan URL unduh APK langsung untuk DownloadManager Android.
 * ==============================================================================
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Coba load config.php jika ada untuk mendapatkan BASE_URL resmi
$configPath = dirname(__DIR__) . '/config.php';
$baseUrl = '';
if (file_exists($configPath)) {
    @require_once $configPath;
    if (defined('BASE_URL')) {
        $baseUrl = rtrim(BASE_URL, '/');
        if (str_ends_with($baseUrl, 'localhostapi')) {
            $baseUrl = 'http://localhost';
        }
    }
}

// Fallback deteksi URL jika config.php tidak mendefinisikan BASE_URL
if (empty($baseUrl)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $rootPath = preg_replace('#/(api|dashboard.*)$#', '', $scriptDir);
    $rootPath = trim($rootPath, '/');
    $baseUrl = $protocol . $host . ($rootPath !== '' ? '/' . $rootPath : '');
}

// Lokasi berkas penyimpanan changelog & berkas APK
$changelogFile = dirname(__DIR__) . '/assets/apk/changelog.json';
$apkFilePath = dirname(__DIR__) . '/assets/apk/android-exam-browser.apk';
$downloadUrl = $baseUrl . '/assets/apk/android-exam-browser.apk';

// Data default jika file changelog belum dibuat oleh admin
$response = [
    'versionCode' => 1,
    'versionName' => '1.0.0',
    'changelog'   => "- Rilis perdana Ilmuna Exam Browser CBT Kiosk\n- Proteksi Anti-Screenshot & Screen Recording\n- Proteksi Freeze Sesi Ujian",
    'downloadUrl' => $downloadUrl,
    'hasApkFile'  => file_exists($apkFilePath),
    'fileSize'    => file_exists($apkFilePath) ? round(filesize($apkFilePath) / (1024 * 1024), 2) . ' MB' : '0 MB',
    'status'      => 'success'
];

if (file_exists($changelogFile)) {
    $raw = @file_get_contents($changelogFile);
    if (!empty($raw)) {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            $response['versionCode'] = isset($data['versionCode']) ? (int)$data['versionCode'] : 1;
            $response['versionName'] = isset($data['versionName']) ? (string)$data['versionName'] : '1.0.0';
            $response['changelog']   = isset($data['changelog']) ? (string)$data['changelog'] : '';
            if (isset($data['updatedAt'])) {
                $response['updatedAt'] = $data['updatedAt'];
            }
        }
    }
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
