<?php
/**
 * ==============================================================================
 * ILMUNA EXAM BROWSER — ADMIN PANEL AUTO UPDATE MANAGER
 * ==============================================================================
 * Mengelola rilis berkas APK Ilmuna Exam Browser, pembaruan Version Code,
 * Version Name, dan Changelog untuk sistem In-App Auto Update Mandiri.
 * Tema Desain: Lumina Gold (Navy #1D3B5E, Teal #0D7A87, Sky #52B2DC, Gold #F59E0B)
 * ==============================================================================
 */

// Sertakan config & auth jika ada di ekosistem Ilmuna Learn
$configFile = dirname(dirname(__DIR__)) . '/config.php';
$authFile   = dirname(dirname(__DIR__)) . '/auth.php';

if (file_exists($configFile)) {
    require_once $configFile;
}
if (file_exists($authFile)) {
    require_once $authFile;
    // Jika auth aktif dan fungsi requireRole tersedia, batasi untuk role admin
    if (function_exists('requireRole') && php_sapi_name() !== 'cli') {
        requireRole('admin');
    }
}

// Konfigurasi Path Berkas
$targetDir      = dirname(dirname(__DIR__)) . '/assets/apk';
$targetApkFile  = $targetDir . '/android-exam-browser.apk';
$changelogFile  = $targetDir . '/changelog.json';

// Pastikan direktori tujuan tersedia
if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0777, true);
}

$alertMessage = '';
$alertType    = '';

// Proses Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $versionCode = isset($_POST['version_code']) ? (int)$_POST['version_code'] : 0;
    $versionName = isset($_POST['version_name']) ? trim($_POST['version_name']) : '';
    $changelog   = isset($_POST['changelog']) ? trim($_POST['changelog']) : '';

    if ($versionCode <= 0 || empty($versionName) || empty($changelog)) {
        $alertMessage = 'Semua field (Version Code, Version Name, dan Changelog) wajib diisi!';
        $alertType = 'danger';
    } else {
        $uploadSuccess = true;

        // Cek apakah ada file APK yang diunggah
        if (isset($_FILES['apk_file']) && $_FILES['apk_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $fileError = $_FILES['apk_file']['error'];
            $fileTmp   = $_FILES['apk_file']['tmp_name'];
            $fileName  = $_FILES['apk_file']['name'];
            $fileExt   = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if ($fileError !== UPLOAD_ERR_OK) {
                $uploadSuccess = false;
                $alertMessage = 'Gagal mengunggah berkas APK. Kode error: ' . $fileError;
                $alertType = 'danger';
            } elseif ($fileExt !== 'apk') {
                $uploadSuccess = false;
                $alertMessage = 'Berkas yang diunggah harus berekstensi .apk!';
                $alertType = 'danger';
            } else {
                // Hapus berkas APK lama jika ada untuk diganti dengan yang baru
                if (file_exists($targetApkFile)) {
                    @unlink($targetApkFile);
                }

                if (!move_uploaded_file($fileTmp, $targetApkFile)) {
                    $uploadSuccess = false;
                    $alertMessage = 'Gagal memindahkan berkas APK ke folder assets/apk/. Periksa permission folder server.';
                    $alertType = 'danger';
                }
            }
        } elseif (!file_exists($targetApkFile)) {
            $uploadSuccess = false;
            $alertMessage = 'Berkas APK belum pernah diunggah. Silakan pilih berkas APK!';
            $alertType = 'danger';
        }

        if ($uploadSuccess) {
            // Hitung ukuran berkas APK
            $fileSize = file_exists($targetApkFile) 
                ? round(filesize($targetApkFile) / (1024 * 1024), 2) . ' MB' 
                : '0 MB';

            // Siapkan metadata JSON
            $payload = [
                'versionCode' => $versionCode,
                'versionName' => $versionName,
                'changelog'   => $changelog,
                'updatedAt'   => date('Y-m-d H:i:s'),
                'fileSize'    => $fileSize
            ];

            if (@file_put_contents($changelogFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))) {
                $alertMessage = "Berhasil! Versi v{$versionName} (Build {$versionCode}) berhasil dipublikasikan.";
                $alertType = 'success';
            } else {
                $alertMessage = 'Berkas APK berhasil diunggah, namun gagal memperbarui changelog.json!';
                $alertType = 'warning';
            }
        }
    }
}

// Baca data versi saat ini
$currentData = [
    'versionCode' => 1,
    'versionName' => '1.0.0',
    'changelog'   => "- Rilis awal Ilmuna Exam Browser",
    'updatedAt'   => '-',
    'fileSize'    => '0 MB'
];

if (file_exists($changelogFile)) {
    $raw = @file_get_contents($changelogFile);
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $currentData = array_merge($currentData, $decoded);
        }
    }
}

$hasApk = file_exists($targetApkFile);
$actualSize = $hasApk ? round(filesize($targetApkFile) / (1024 * 1024), 2) . ' MB' : 'Belum Ada File';

// Tentukan Base URL untuk tautan unduh & endpoint API
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
if (empty($baseUrl)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $rootPath = preg_replace('#/(dashboard/.*|dashboard|api/.*|api)$#', '', $scriptDir);
    $baseUrl = $protocol . $host . rtrim($rootPath, '/');
}
$apkDownloadUrl = $baseUrl . '/assets/apk/android-exam-browser.apk';
$apiCheckUrl    = $baseUrl . '/api/check-update.php';

$pageTitle = 'Pembaruan Aplikasi Android (In-App Auto Update)';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — Ilmuna Learn</title>
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <script src="https://code.iconify.design/iconify-icon/1.0.8/iconify-icon.min.js"></script>
    <style>
        :root {
            --navy: #1D3B5E;
            --navy-dark: #13273F;
            --teal: #0D7A87;
            --teal-light: #E0F4F5;
            --sky: #52B2DC;
            --gold: #F59E0B;
            --gold-light: #FEF3C7;
            --bg-page: #F8FAFC;
            --surface: #FFFFFF;
            --text-main: #0F172A;
            --text-muted: #64748B;
            --border: #E2E8F0;
            --success: #10B981;
            --danger: #EF4444;
            --radius-lg: 16px;
            --radius-md: 10px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 14px -2px rgba(29, 59, 94, 0.08);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-page);
            color: var(--text-main);
            line-height: 1.5;
            padding: 30px 20px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            background: linear-gradient(135deg, var(--teal), var(--navy));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 26px;
            box-shadow: 0 4px 10px rgba(13, 122, 135, 0.25);
        }

        .header-title h1 {
            font-size: 22px;
            font-weight: 800;
            color: var(--navy);
            letter-spacing: -0.02em;
        }

        .header-title p {
            font-size: 13px;
            color: var(--text-muted);
        }

        .badge-live {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            background: var(--teal-light);
            color: var(--teal);
        }

        .badge-live::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--teal);
            animation: pulse 1.8s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(0.95); opacity: 0.8; }
            50% { transform: scale(1.3); opacity: 0.4; }
            100% { transform: scale(0.95); opacity: 0.8; }
        }

        .grid-layout {
            display: grid;
            grid-template-columns: 1fr 1.3fr;
            gap: 25px;
        }

        @media (max-width: 820px) {
            .grid-layout {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: var(--surface);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-md);
            padding: 24px;
        }

        .card-header {
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-header h2 {
            font-size: 16px;
            font-weight: 700;
            color: var(--navy);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Status Widget */
        .status-box {
            background: linear-gradient(135deg, #F0FDF4 0%, #DCFCE7 100%);
            border: 1px solid #BBF7D0;
            border-radius: var(--radius-md);
            padding: 16px;
            margin-bottom: 20px;
        }

        .status-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            font-size: 13px;
        }

        .status-row:last-child {
            margin-bottom: 0;
        }

        .status-label {
            color: var(--text-muted);
            font-weight: 500;
        }

        .status-val {
            font-weight: 700;
            color: var(--navy);
            font-family: 'JetBrains Mono', monospace;
        }

        .code-pill {
            background: #E2E8F0;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 12px;
        }

        .api-preview {
            background: var(--navy-dark);
            color: #E2E8F0;
            border-radius: var(--radius-md);
            padding: 14px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            overflow-x: auto;
            margin-top: 15px;
            line-height: 1.6;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 6px;
        }

        .form-label .required {
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            font-size: 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            outline: none;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .form-control:focus {
            border-color: var(--teal);
            box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.15);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .file-upload-box {
            border: 2px dashed var(--teal);
            background: #F8FDFF;
            padding: 20px;
            border-radius: var(--radius-md);
            text-align: center;
            cursor: pointer;
            position: relative;
            transition: background 0.2s;
        }

        .file-upload-box:hover {
            background: #E6F7F9;
        }

        .file-upload-box input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .btn-submit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px 20px;
            background: linear-gradient(135deg, var(--teal), var(--navy));
            color: white;
            font-weight: 700;
            font-size: 14px;
            border: none;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(13, 122, 135, 0.25);
        }

        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(13, 122, 135, 0.35);
        }

        .btn-download {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            background: var(--gold);
            color: #78350F;
            font-weight: 700;
            font-size: 12px;
            border-radius: 8px;
            text-decoration: none;
            transition: filter 0.2s;
        }

        .btn-download:hover {
            filter: brightness(0.95);
        }

        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-md);
            font-size: 13px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #DCFCE7;
            color: #166534;
            border: 1px solid #BBF7D0;
        }

        .alert-danger {
            background: #FEE2E2;
            color: #991B1B;
            border: 1px solid #FECACA;
        }

        .alert-warning {
            background: #FEF3C7;
            color: #92400E;
            border: 1px solid #FDE68A;
        }
    </style>
</head>
<body>

<div class="container">

    <!-- Header Section -->
    <div class="header-bar">
        <div class="header-title">
            <div class="header-icon">
                <iconify-icon icon="solar:cloud-upload-bold"></iconify-icon>
            </div>
            <div>
                <h1>Ilmuna Exam Browser — In-App Auto Update Manager</h1>
                <p>Kelola berkas APK dan metadata pembaruan aplikasi Kiosk CBT Android</p>
            </div>
        </div>
        <div class="badge-live">
            Sistem Anti-Gravity Aktif
        </div>
    </div>

    <!-- Alert Notifikasi -->
    <?php if (!empty($alertMessage)): ?>
        <div class="alert alert-<?= $alertType ?>">
            <iconify-icon icon="<?= $alertType === 'success' ? 'solar:check-circle-bold' : 'solar:danger-triangle-bold' ?>" style="font-size: 20px;"></iconify-icon>
            <div><?= htmlspecialchars($alertMessage) ?></div>
        </div>
    <?php endif; ?>

    <div class="grid-layout">
        
        <!-- Kolom Kiri: Status & Endpoint Info -->
        <div>
            <div class="card">
                <div class="card-header">
                    <h2><iconify-icon icon="solar:devices-bold" style="color: var(--teal);"></iconify-icon> Status APK di Server</h2>
                    <?php if ($hasApk): ?>
                        <span style="font-size: 11px; color: var(--success); font-weight: 700; background: #DCFCE7; padding: 2px 8px; border-radius: 999px;">Tersedia</span>
                    <?php else: ?>
                        <span style="font-size: 11px; color: var(--danger); font-weight: 700; background: #FEE2E2; padding: 2px 8px; border-radius: 999px;">Kosong</span>
                    <?php endif; ?>
                </div>

                <div class="status-box">
                    <div class="status-row">
                        <span class="status-label">Version Name</span>
                        <span class="status-val code-pill">v<?= htmlspecialchars($currentData['versionName']) ?></span>
                    </div>
                    <div class="status-row">
                        <span class="status-label">Version Code (Build)</span>
                        <span class="status-val code-pill"><?= htmlspecialchars((string)$currentData['versionCode']) ?></span>
                    </div>
                    <div class="status-row">
                        <span class="status-label">Ukuran Berkas</span>
                        <span class="status-val"><?= htmlspecialchars($actualSize) ?></span>
                    </div>
                    <div class="status-row">
                        <span class="status-label">Rilis Terakhir</span>
                        <span class="status-val" style="font-size: 12px;"><?= htmlspecialchars($currentData['updatedAt'] ?? '-') ?></span>
                    </div>
                </div>

                <?php if ($hasApk): ?>
                    <div style="margin-bottom: 18px;">
                        <a href="<?= htmlspecialchars($apkDownloadUrl) ?>" class="btn-download" download>
                            <iconify-icon icon="solar:download-bold"></iconify-icon> Unduh File APK Terkini
                        </a>
                    </div>
                <?php endif; ?>

                <div style="margin-top: 15px;">
                    <div class="form-label" style="font-size: 12px;">Endpoint REST API Android:</div>
                    <div style="font-size: 11px; font-family: 'JetBrains Mono', monospace; background: #F1F5F9; padding: 8px 10px; border-radius: 6px; word-break: break-all; border: 1px solid var(--border);">
                        <a href="<?= htmlspecialchars($apiCheckUrl) ?>" target="_blank" style="color: var(--teal); text-decoration: none;">
                            <?= htmlspecialchars($apiCheckUrl) ?>
                        </a>
                    </div>
                </div>

                <div class="api-preview">
// Contoh Payload JSON yang dikembalikan API:
{
  "versionCode": <?= (int)$currentData['versionCode'] ?>,
  "versionName": "<?= htmlspecialchars($currentData['versionName']) ?>",
  "changelog": "<?= htmlspecialchars(str_replace(["\r\n", "\n"], "\\n", $currentData['changelog'])) ?>",
  "downloadUrl": "<?= htmlspecialchars($apkDownloadUrl) ?>"
}
                </div>
            </div>
        </div>

        <!-- Kolom Kanan: Form Unggah Pembaruan -->
        <div>
            <div class="card">
                <div class="card-header">
                    <h2><iconify-icon icon="solar:upload-track-bold" style="color: var(--teal);"></iconify-icon> Form Rilis Pembaruan APK</h2>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label class="form-label">Version Code (Build) <span class="required">*</span></label>
                            <input type="number" name="version_code" class="form-control" min="1" value="<?= (int)$currentData['versionCode'] + 1 ?>" required placeholder="Contoh: 2">
                            <small style="color: var(--text-muted); font-size: 11px;">Wajib lebih tinggi dari versi sebelumnya agar HP siswa mendeteksi update.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Version Name <span class="required">*</span></label>
                            <input type="text" name="version_name" class="form-control" value="1.0.<?= (int)$currentData['versionCode'] ?>" required placeholder="Contoh: 1.0.1">
                            <small style="color: var(--text-muted); font-size: 11px;">Versi teks yang tampil di layar dialog siswa.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Changelog (Catatan Perubahan) <span class="required">*</span></label>
                        <textarea name="changelog" class="form-control" rows="4" required placeholder="- Fitur baru...&#10;- Perbaikan bug crash..."><?= htmlspecialchars($currentData['changelog']) ?></textarea>
                        <small style="color: var(--text-muted); font-size: 11px;">Gunakan tanda hubung (-) untuk tiap poin pembaruan.</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Unggah File APK (.apk) <span class="required"><?= $hasApk ? '(Opsional jika hanya ubah teks)' : '*' ?></span></label>
                        <div class="file-upload-box" id="dropArea">
                            <iconify-icon icon="solar:document-add-bold" style="font-size: 36px; color: var(--teal); margin-bottom: 6px;"></iconify-icon>
                            <div style="font-size: 13px; font-weight: 600; color: var(--navy);" id="fileLabel">
                                Klik untuk memilih berkas APK atau seret ke sini
                            </div>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
                                File akan disimpan di: <span style="font-family: 'JetBrains Mono', monospace;">assets/apk/android-exam-browser.apk</span>
                            </div>
                            <input type="file" name="apk_file" id="apkFileInput" accept=".apk" <?= $hasApk ? '' : 'required' ?>>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">
                        <iconify-icon icon="solar:diskette-bold" style="font-size: 18px;"></iconify-icon>
                        Publikasikan Pembaruan APK
                    </button>
                </form>
            </div>
        </div>

    </div>

</div>

<script>
    const fileInput = document.getElementById('apkFileInput');
    const fileLabel = document.getElementById('fileLabel');

    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            if (this.files && this.files.length > 0) {
                const f = this.files[0];
                const sizeMb = (f.size / (1024 * 1024)).toFixed(2);
                fileLabel.innerHTML = `<strong>${f.name}</strong> (${sizeMb} MB) terpilih`;
            }
        });
    }
</script>

</body>
</html>

