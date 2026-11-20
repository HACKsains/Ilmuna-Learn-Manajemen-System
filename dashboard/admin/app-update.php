<?php
/**
 * ==============================================================================
 * ILMUNA EXAM BROWSER — ADMIN PANEL AUTO UPDATE MANAGER
 * ==============================================================================
 * Mengelola rilis berkas APK Ilmuna Exam Browser, pembaruan Version Code,
 * Version Name, dan Changelog untuk sistem In-App Auto Update Mandiri.
 * Mengikuti standar Data-First Lumina Gold (README.md).
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

if (function_exists('requireRole') && php_sapi_name() !== 'cli') {
    requireRole('admin');
}

// Konfigurasi Path Berkas
$targetDir     = BASE_PATH . '/assets/apk';
$targetApkFile = $targetDir . '/android-exam-browser.apk';
$changelogFile = $targetDir . '/changelog.json';

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
            $fileSize = file_exists($targetApkFile)
                ? round(filesize($targetApkFile) / (1024 * 1024), 2) . ' MB'
                : '0 MB';

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

$hasApk     = file_exists($targetApkFile);
$actualSize = $hasApk ? round(filesize($targetApkFile) / (1024 * 1024), 2) . ' MB' : 'Belum Ada File';

$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
if (empty($baseUrl)) {
    $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? 'https://' : 'http://';
    $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $rootPath  = preg_replace('#/(dashboard/.*|dashboard|api/.*|api)$#', '', $scriptDir);
    $baseUrl   = $protocol . $host . rtrim($rootPath, '/');
}
$apkDownloadUrl = $baseUrl . '/assets/apk/android-exam-browser.apk';
$apiCheckUrl    = $baseUrl . '/api/check-update.php';

$pageTitle = 'Auto Update Exam Browser';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
    .apk-status-box {
        background: linear-gradient(135deg, #F0FDF4 0%, #DCFCE7 100%);
        border: 1px solid #BBF7D0;
        border-radius: 14px;
        padding: 16px;
        margin-bottom: 18px;
    }
    .apk-status-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        padding: 7px 0;
        border-bottom: 1px dashed rgba(16, 185, 129, 0.25);
        font-size: 13px;
    }
    .apk-status-row:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }
    .apk-status-label {
        color: #475569;
        font-weight: 600;
    }
    .apk-status-val {
        font-weight: 800;
        color: var(--lumina-navy);
        font-family: var(--font-mono);
        text-align: right;
    }
    .apk-code-pill {
        background: rgba(255, 255, 255, 0.9);
        border: 1px solid #CBD5E1;
        padding: 2px 9px;
        border-radius: 7px;
        font-size: 12px;
    }
    .apk-endpoint-box {
        font-size: 12px;
        font-family: var(--font-mono);
        background: #F1F5F9;
        padding: 10px 12px;
        border-radius: 10px;
        word-break: break-all;
        border: 1px solid var(--lumina-border);
    }
    .apk-api-preview {
        background: #0F172A;
        color: #E2E8F0;
        border-radius: 12px;
        padding: 14px;
        font-family: var(--font-mono);
        font-size: 11.5px;
        overflow-x: auto;
        margin-top: 14px;
        line-height: 1.65;
        white-space: pre;
    }
    .apk-dropzone {
        border: 2px dashed var(--lumina-teal);
        background: #F8FDFF;
        padding: 22px 16px;
        border-radius: 14px;
        text-align: center;
        cursor: pointer;
        position: relative;
        transition: all 0.2s ease;
    }
    .apk-dropzone:hover {
        background: #E6F7F9;
        border-color: var(--lumina-navy);
    }
    .apk-dropzone input[type="file"] {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }
    @media (max-width: 768px) {
        .apk-status-box { padding: 12px 14px; }
        .apk-status-row { flex-wrap: wrap; gap: 4px; font-size: 12.5px; }
        .apk-api-preview { font-size: 11px; padding: 12px; }
        .apk-dropzone { padding: 18px 12px; }
    }
</style>

<div class="ilmuna-app-container">
    <!-- Data-First Compact Status & Action Toolbar -->
    <div class="ilmuna-filter-box">
        <div class="ilmuna-filter-topline">
            <span class="ilmuna-nav-stat-inline ilmuna-nav-stat-inline--teal">
                <iconify-icon icon="solar:devices-bold"></iconify-icon>
                Versi: <strong>v<?= htmlspecialchars($currentData['versionName']) ?></strong>
            </span>
            <span class="ilmuna-nav-stat-inline ilmuna-nav-stat-inline--navy">
                <iconify-icon icon="solar:code-circle-bold"></iconify-icon>
                Build: <strong>#<?= (int)$currentData['versionCode'] ?></strong>
            </span>
            <span class="ilmuna-nav-stat-inline <?= $hasApk ? 'ilmuna-nav-stat-inline--emerald' : '' ?>">
                <iconify-icon icon="<?= $hasApk ? 'solar:check-circle-bold' : 'solar:danger-triangle-bold' ?>"></iconify-icon>
                APK: <strong><?= htmlspecialchars($actualSize) ?></strong>
            </span>

            <div class="ilmuna-filter-actions">
                <a href="<?= htmlspecialchars($apiCheckUrl) ?>" target="_blank" class="ilmuna-nav-btn ilmuna-nav-btn--ghost">
                    <iconify-icon icon="solar:link-circle-bold"></iconify-icon>
                    <span>Cek API JSON</span>
                </a>
                <?php if ($hasApk): ?>
                    <a href="<?= htmlspecialchars($apkDownloadUrl) ?>" class="ilmuna-nav-btn ilmuna-nav-btn--primary" download>
                        <iconify-icon icon="solar:download-bold"></iconify-icon>
                        <span>Unduh APK Terkini</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($alertMessage)): ?>
        <div class="alert alert-<?= $alertType === 'success' ? 'success' : 'danger' ?> alert-dismissible" style="margin-bottom: 16px;">
            <iconify-icon icon="<?= $alertType === 'success' ? 'solar:check-circle-bold' : 'solar:danger-triangle-bold' ?>"></iconify-icon>
            <span><?= htmlspecialchars($alertMessage) ?></span>
        </div>
    <?php endif; ?>

    <div class="ilm-grid-1-2">
        <!-- Kolom Kiri: Status & Endpoint Info -->
        <div class="content-card" style="margin-bottom: 0;">
            <div class="content-card-header">
                <h3 class="content-card-title">
                    <iconify-icon icon="solar:devices-bold" style="color: var(--lumina-teal);"></iconify-icon>
                    Status APK di Server
                </h3>
                <?php if ($hasApk): ?>
                    <span class="ilmuna-badge ilmuna-badge--success">
                        <iconify-icon icon="solar:check-circle-bold"></iconify-icon> Tersedia
                    </span>
                <?php else: ?>
                    <span class="ilmuna-badge ilmuna-badge--danger">
                        <iconify-icon icon="solar:close-circle-bold"></iconify-icon> Kosong
                    </span>
                <?php endif; ?>
            </div>
            <div class="content-card-body">
                <div class="apk-status-box">
                    <div class="apk-status-row">
                        <span class="apk-status-label">Version Name</span>
                        <span class="apk-status-val apk-code-pill">v<?= htmlspecialchars($currentData['versionName']) ?></span>
                    </div>
                    <div class="apk-status-row">
                        <span class="apk-status-label">Version Code (Build)</span>
                        <span class="apk-status-val apk-code-pill"><?= (int)$currentData['versionCode'] ?></span>
                    </div>
                    <div class="apk-status-row">
                        <span class="apk-status-label">Ukuran Berkas</span>
                        <span class="apk-status-val"><?= htmlspecialchars($actualSize) ?></span>
                    </div>
                    <div class="apk-status-row">
                        <span class="apk-status-label">Rilis Terakhir</span>
                        <span class="apk-status-val" style="font-size: 12px;"><?= htmlspecialchars($currentData['updatedAt'] ?? '-') ?></span>
                    </div>
                </div>

                <div>
                    <div class="form-label" style="font-size: 12.5px; margin-bottom: 6px;">Endpoint REST API Android</div>
                    <div class="apk-endpoint-box">
                        <a href="<?= htmlspecialchars($apiCheckUrl) ?>" target="_blank" style="color: var(--lumina-teal); text-decoration: none; font-weight: 600;">
                            <?= htmlspecialchars($apiCheckUrl) ?>
                        </a>
                    </div>
                </div>

                <div class="apk-api-preview">// Contoh Payload JSON yang dikembalikan API:
{
  "versionCode": <?= (int)$currentData['versionCode'] ?>,
  "versionName": "<?= htmlspecialchars($currentData['versionName']) ?>",
  "changelog": "<?= htmlspecialchars(str_replace(["\r\n", "\n"], "\\n", $currentData['changelog'])) ?>",
  "downloadUrl": "<?= htmlspecialchars($apkDownloadUrl) ?>"
}</div>
            </div>
        </div>

        <!-- Kolom Kanan: Form Unggah Pembaruan -->
        <div class="content-card" style="margin-bottom: 0;">
            <div class="content-card-header">
                <h3 class="content-card-title">
                    <iconify-icon icon="solar:cloud-upload-bold" style="color: var(--lumina-teal);"></iconify-icon>
                    Rilis Pembaruan APK
                </h3>
                <span class="ilmuna-badge ilmuna-badge--teal">In-App OTA</span>
            </div>
            <div class="content-card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="ilm-grid-2" style="margin-bottom: 14px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Version Code (Build) <span style="color: var(--lumina-rose);">*</span></label>
                            <input type="number" name="version_code" class="form-control" min="1" value="<?= (int)$currentData['versionCode'] + 1 ?>" required placeholder="Contoh: 5">
                            <small style="color: var(--lumina-text-muted); font-size: 11.5px; display: block; margin-top: 4px;">Wajib lebih tinggi dari build sebelumnya.</small>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Version Name <span style="color: var(--lumina-rose);">*</span></label>
                            <input type="text" name="version_name" class="form-control" value="1.1.<?= (int)$currentData['versionCode'] ?>" required placeholder="Contoh: 1.1.4">
                            <small style="color: var(--lumina-text-muted); font-size: 11.5px; display: block; margin-top: 4px;">Teks versi yang tampil di dialog siswa.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Changelog (Catatan Perubahan) <span style="color: var(--lumina-rose);">*</span></label>
                        <textarea name="changelog" class="form-control" rows="4" required placeholder="- Fitur baru...&#10;- Perbaikan bug..."><?= htmlspecialchars($currentData['changelog']) ?></textarea>
                        <small style="color: var(--lumina-text-muted); font-size: 11.5px; display: block; margin-top: 4px;">Gunakan tanda hubung (-) untuk tiap poin pembaruan.</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            Unggah File APK (.apk)
                            <span style="color: var(--lumina-rose); font-weight: 600;"><?= $hasApk ? '(Opsional jika hanya ubah changelog)' : '*' ?></span>
                        </label>
                        <div class="apk-dropzone" id="dropArea">
                            <iconify-icon icon="solar:document-add-bold" style="font-size: 36px; color: var(--lumina-teal); margin-bottom: 6px;"></iconify-icon>
                            <div style="font-size: 13.5px; font-weight: 700; color: var(--lumina-navy);" id="fileLabel">
                                Klik untuk memilih berkas APK atau seret ke sini
                            </div>
                            <div style="font-size: 11.5px; color: var(--lumina-text-muted); margin-top: 4px; word-break: break-word;">
                                Target: <span style="font-family: var(--font-mono);">assets/apk/android-exam-browser.apk</span>
                            </div>
                            <input type="file" name="apk_file" id="apkFileInput" accept=".apk" <?= $hasApk ? '' : 'required' ?>>
                        </div>
                    </div>

                    <button type="submit" class="ilmuna-nav-btn ilmuna-nav-btn--teal" style="width: 100%; height: 46px; font-size: 14px;">
                        <iconify-icon icon="solar:diskette-bold" style="font-size: 18px;"></iconify-icon>
                        <span>Publikasikan Pembaruan APK</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('apkFileInput');
    const fileLabel = document.getElementById('fileLabel');

    if (fileInput && fileLabel) {
        fileInput.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                const f = this.files[0];
                const sizeMb = (f.size / (1024 * 1024)).toFixed(2);
                fileLabel.innerHTML = '<strong>' + f.name.replace(/</g, '&lt;') + '</strong> (' + sizeMb + ' MB) terpilih';
            }
        });
    }

    <?php if (!empty($alertMessage)): ?>
    if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
        window.Ilmuna.toast(<?= json_encode($alertMessage) ?>, <?= json_encode($alertType === 'success' ? 'success' : ($alertType === 'warning' ? 'warning' : 'error')) ?>);
    }
    <?php endif; ?>
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
