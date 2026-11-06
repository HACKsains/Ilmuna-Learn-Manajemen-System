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

// Lokasi berkas penyimpanan changelog & direktori APK
$apkDir = dirname(__DIR__) . '/assets/apk';
$changelogFile = $apkDir . '/changelog.json';
$defaultApkName = 'ilmuna-exam-browser-release.apk';
if (!file_exists($apkDir . '/' . $defaultApkName)) {
    $defaultApkName = file_exists($apkDir . '/android-exam-browser.apk') ? 'android-exam-browser.apk' : 'ilmuna-exam-browser.apk';
}
$apkFilePath = $apkDir . '/' . $defaultApkName;
$downloadUrl = $baseUrl . '/assets/apk/' . $defaultApkName;

// Data default jika file changelog belum dibuat oleh admin
$response = [
    'versionCode' => 4,
    'versionName' => '1.1.3',
    'changelog'   => "- Pembaruan stabilitas dan optimasi performa ujian CBT.\n- Peningkatan keamanan kiosk mode & sinkronisasi otomatis sisa waktu ujian.\n- Silakan perbarui ke versi 1.1.3 untuk pengalaman ujian yang lebih stabil.",
    'fileName'    => $defaultApkName,
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
            $response['versionCode'] = isset($data['versionCode']) ? (int)$data['versionCode'] : 4;
            $response['versionName'] = isset($data['versionName']) ? (string)$data['versionName'] : '1.1.3';
            $response['changelog']   = isset($data['changelog']) ? (string)$data['changelog'] : '';
            if (!empty($data['fileName'])) {
                $customApk = $apkDir . '/' . basename($data['fileName']);
                if (file_exists($customApk)) {
                    $apkFilePath = $customApk;
                    $response['fileName'] = basename($data['fileName']);
                    $response['downloadUrl'] = $baseUrl . '/assets/apk/' . basename($data['fileName']);
                }
            }
            $response['hasApkFile'] = file_exists($apkFilePath);
            if ($response['hasApkFile']) {
                $response['fileSize'] = round(filesize($apkFilePath) / (1024 * 1024), 2) . ' MB';
                $response['sha256']   = strtoupper(hash_file('sha256', $apkFilePath));
            }
            if (isset($data['updatedAt'])) {
                $response['updatedAt'] = $data['updatedAt'];
            }
        }
    }
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
