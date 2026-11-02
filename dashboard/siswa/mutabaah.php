<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — JURNAL MUTABA'AH YAUMIYAH IBADAH (SISWA)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole('siswa');

$pageTitle = 'Mutaba\'ah Ibadah Yaumiyah Mandiri';
$pdo = getDBConnection();
$currentUser = getCurrentUser();
$message = null;
$error = null;

$todayDate = date('Y-m-d');

// Handle Saving Log
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_mutabaah') {
    $logDate = $_POST['log_date'] ?? $todayDate;
    $subuh = isset($_POST['subuh']) ? 1 : 0;
    $dzuhur = isset($_POST['dzuhur']) ? 1 : 0;
    $ashar = isset($_POST['ashar']) ? 1 : 0;
    $maghrib = isset($_POST['maghrib']) ? 1 : 0;
    $isya = isset($_POST['isya']) ? 1 : 0;
    $dhuha = isset($_POST['dhuha']) ? 1 : 0;
    $tahajud = isset($_POST['tahajud']) ? 1 : 0;
    $tilawahSurah = trim($_POST['tilawah_surah'] ?? '');
    $tilawahAyat = trim($_POST['tilawah_ayat'] ?? '');
    $puasaSunnah = isset($_POST['puasa_sunnah']) ? 1 : 0;
    $infaq = isset($_POST['infaq']) ? 1 : 0;
    $catatan = trim($_POST['catatan'] ?? '');

    try {
        // Check if log already exists for this date
        $stmtCheck = $pdo->prepare("SELECT id FROM mutabaah_logs WHERE student_id = ? AND log_date = ?");
        $stmtCheck->execute([$currentUser['id'], $logDate]);
        $existingId = $stmtCheck->fetchColumn();

        if ($existingId) {
            $stmtUpd = $pdo->prepare("
                UPDATE mutabaah_logs SET
                    subuh = ?, dzuhur = ?, ashar = ?, maghrib = ?, isya = ?,
                    dhuha = ?, tahajud = ?, tilawah_surah = ?, tilawah_ayat = ?,
                    puasa_sunnah = ?, infaq = ?, catatan = ?
                WHERE id = ?
            ");
            $stmtUpd->execute([
                $subuh, $dzuhur, $ashar, $maghrib, $isya,
                $dhuha, $tahajud, $tilawahSurah, $tilawahAyat,
                $puasaSunnah, $infaq, $catatan, $existingId
            ]);
            $message = 'Catatan ibadah yaumiyah Anda berhasil diperbarui!';
        } else {
            $stmtIns = $pdo->prepare("
                INSERT INTO mutabaah_logs (
                    student_id, log_date, subuh, dzuhur, ashar, maghrib, isya,
                    dhuha, tahajud, tilawah_surah, tilawah_ayat, puasa_sunnah, infaq, catatan
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtIns->execute([
                $currentUser['id'], $logDate, $subuh, $dzuhur, $ashar, $maghrib, $isya,
                $dhuha, $tahajud, $tilawahSurah, $tilawahAyat, $puasaSunnah, $infaq, $catatan
            ]);
            $message = 'Alhamdulillah! Jurnal ibadah yaumiyah hari ini berhasil disimpan.';
        }
    } catch (Exception $e) {
        $error = 'Gagal menyimpan mutaba\'ah: ' . $e->getMessage();
    }
}

// Fetch Today's Record
$stmtToday = $pdo->prepare("SELECT * FROM mutabaah_logs WHERE student_id = ? AND log_date = ?");
$stmtToday->execute([$currentUser['id'], $todayDate]);
$todayLog = $stmtToday->fetch();

// Fetch Past 14 Days Logs
$stmtHistory = $pdo->prepare("
    SELECT * FROM mutabaah_logs 
    WHERE student_id = ? 
    ORDER BY log_date DESC 
    LIMIT 14
");
$stmtHistory->execute([$currentUser['id']]);
$historyLogs = $stmtHistory->fetchAll();

require_once BASE_PATH . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> <?= $message ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Daily Mutabaah Input Form -->
<div class="content-card">
    <div class="content-card-header" style="background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%); color: #ffffff;">
        <h3 class="content-card-title" style="color: #ffffff;">
            <iconify-icon icon="solar:hand-heart-bold" style="color: #fde047;"></iconify-icon> Lembar Mutaba'ah Ibadah Yaumiyah (Tanggal: <?= date('d F Y') ?>)
        </h3>
    </div>
    <div class="content-card-body">
        <form method="POST">
            <input type="hidden" name="action" value="save_mutabaah">
            <input type="hidden" name="log_date" value="<?= $todayDate ?>">

            <h4 style="font-size: 1.05rem; margin-bottom: 12px; color: var(--text-heading); display: flex; align-items: center; gap: 8px;">
                <iconify-icon icon="solar:buildings-2-bold" style="color: var(--primary-teal);"></iconify-icon> 1. Sholat Fardhu 5 Waktu (Tepat Waktu / Berjamaah)
            </h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 24px;">
                <?php 
                $fardhu = [
                    'subuh' => 'Sholat Subuh',
                    'dzuhur' => 'Sholat Dzuhur',
                    'ashar' => 'Sholat Ashar',
                    'maghrib' => 'Sholat Maghrib',
                    'isya' => 'Sholat Isya'
                ];
                foreach ($fardhu as $k => $label): 
                    $checked = !empty($todayLog[$k]) ? 'checked' : '';
                ?>
                    <label style="background: #f8fafc; border: 1px solid var(--border-subtle); padding: 12px; border-radius: 12px; display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 600; font-size: 0.9rem;">
                        <input type="checkbox" name="<?= $k ?>" value="1" <?= $checked ?> style="width: 18px; height: 18px;">
                        <span><?= $label ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <h4 style="font-size: 1.05rem; margin-bottom: 12px; color: var(--text-heading); display: flex; align-items: center; gap: 8px;">
                <iconify-icon icon="solar:stars-line-bold" style="color: var(--primary-gold);"></iconify-icon> 2. Amalan Sunnah & Kebaikan
            </h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 24px;">
                <label style="background: #f8fafc; border: 1px solid var(--border-subtle); padding: 12px; border-radius: 12px; display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 600; font-size: 0.9rem;">
                    <input type="checkbox" name="dhuha" value="1" <?= !empty($todayLog['dhuha']) ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                    <span>Sholat Sunnah Dhuha</span>
                </label>
                <label style="background: #f8fafc; border: 1px solid var(--border-subtle); padding: 12px; border-radius: 12px; display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 600; font-size: 0.9rem;">
                    <input type="checkbox" name="tahajud" value="1" <?= !empty($todayLog['tahajud']) ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                    <span>Sholat Qiyamul Lail (Tahajud)</span>
                </label>
                <label style="background: #f8fafc; border: 1px solid var(--border-subtle); padding: 12px; border-radius: 12px; display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 600; font-size: 0.9rem;">
                    <input type="checkbox" name="puasa_sunnah" value="1" <?= !empty($todayLog['puasa_sunnah']) ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                    <span>Puasa Sunnah Hari Ini</span>
                </label>
                <label style="background: #f8fafc; border: 1px solid var(--border-subtle); padding: 12px; border-radius: 12px; display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 600; font-size: 0.9rem;">
                    <input type="checkbox" name="infaq" value="1" <?= !empty($todayLog['infaq']) ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                    <span>Infaq / Sedekah Subuh</span>
                </label>
            </div>

            <h4 style="font-size: 1.05rem; margin-bottom: 12px; color: var(--text-heading); display: flex; align-items: center; gap: 8px;">
                <iconify-icon icon="solar:book-bookmark-bold" style="color: var(--primary-sky);"></iconify-icon> 3. Tilawah & Tadarus Al-Qur'an
            </h4>
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-bottom: 20px;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Nama Surah Al-Qur'an</label>
                    <input type="text" name="tilawah_surah" class="form-control" placeholder="Contoh: QS. Al-Waqi'ah atau QS. Al-Kahfi" value="<?= htmlspecialchars($todayLog['tilawah_surah'] ?? '') ?>">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Ayat yang Dibaca</label>
                    <input type="text" name="tilawah_ayat" class="form-control" placeholder="Ayat 1 - 30" value="<?= htmlspecialchars($todayLog['tilawah_ayat'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Refleksi Diri / Doa Hari Ini (Opsional)</label>
                <input type="text" name="catatan" class="form-control" placeholder="Tuliskan komitmen atau muhasabah diri hari ini..." value="<?= htmlspecialchars($todayLog['catatan'] ?? '') ?>">
            </div>

            <div style="display: flex; justify-content: flex-end; margin-top: 24px;">
                <button type="submit" class="btn btn-primary" style="padding: 12px 28px;">
                    <iconify-icon icon="solar:check-circle-bold"></iconify-icon> Simpan Mutaba'ah Hari Ini
                </button>
            </div>
        </form>
    </div>
</div>

<!-- History Log Card -->
<div class="content-card">
    <div class="content-card-header">
        <h3 class="content-card-title">
            <iconify-icon icon="solar:calendar-date-bold" style="color: var(--primary-teal);"></iconify-icon> Riwayat Mutaba'ah Ibadah Anda
        </h3>
    </div>
    <div class="content-card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="lms-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Sholat 5 Waktu</th>
                        <th>Amalan Sunnah</th>
                        <th>Tilawah Qur'an</th>
                        <th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($historyLogs)): ?>
                        <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 32px;">Belum ada riwayat mutaba'ah yang tercatat.</td></tr>
                    <?php else: ?>
                        <?php foreach ($historyLogs as $hl): ?>
                            <tr>
                                <td><strong><?= date('d M Y', strtotime($hl['log_date'])) ?></strong></td>
                                <td>
                                    <div style="display: flex; gap: 4px; font-size: 0.75rem;">
                                        <span class="badge <?= $hl['subuh'] ? 'badge-success' : 'badge-navy' ?>">Sub</span>
                                        <span class="badge <?= $hl['dzuhur'] ? 'badge-success' : 'badge-navy' ?>">Dzu</span>
                                        <span class="badge <?= $hl['ashar'] ? 'badge-success' : 'badge-navy' ?>">Ash</span>
                                        <span class="badge <?= $hl['maghrib'] ? 'badge-success' : 'badge-navy' ?>">Mag</span>
                                        <span class="badge <?= $hl['isya'] ? 'badge-success' : 'badge-navy' ?>">Isy</span>
                                    </div>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 4px; font-size: 0.75rem;">
                                        <?= $hl['dhuha'] ? '<span class="badge badge-gold">Dhuha</span>' : '' ?>
                                        <?= $hl['tahajud'] ? '<span class="badge badge-sky">Tahajud</span>' : '' ?>
                                        <?= $hl['puasa_sunnah'] ? '<span class="badge badge-teal">Puasa</span>' : '' ?>
                                        <?= $hl['infaq'] ? '<span class="badge badge-success">Infaq</span>' : '' ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($hl['tilawah_surah'])): ?>
                                        <strong><?= htmlspecialchars($hl['tilawah_surah']) ?></strong>: <?= htmlspecialchars($hl['tilawah_ayat']) ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.8rem;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size: 0.82rem; color: var(--text-muted);"><?= htmlspecialchars($hl['catatan'] ?? '-') ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
