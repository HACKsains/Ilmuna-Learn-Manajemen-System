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

<style>
    .mtb-pane { display: none; }
    .mtb-pane.is-active { display: block; }
    .mtb-check-card {
        background: #F8FAFC;
        border: 1.5px solid #E2E8F0;
        padding: 12px 14px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        font-weight: 700;
        font-size: 0.88rem;
        color: var(--lumina-navy);
        min-height: 48px;
        transition: all 0.18s ease;
    }
    .mtb-check-card:hover {
        border-color: var(--lumina-teal);
        background: #F0FDFA;
    }
    .mtb-check-card:has(input:checked) {
        border-color: var(--lumina-teal);
        background: #F0FDFA;
        color: var(--lumina-teal);
        box-shadow: 0 2px 8px rgba(13, 122, 135, 0.12);
    }
    .mtb-check-card input[type="checkbox"] {
        width: 20px;
        height: 20px;
        min-height: 20px !important;
        accent-color: var(--lumina-teal);
        flex-shrink: 0;
    }
</style>

<!-- Canonical Segmented Tabs -->
<div class="curriculum-main-tabs" role="tablist">
    <button type="button" class="curriculum-tab-btn is-active" data-mtb-tab="today" onclick="switchMtbTab('today', this)">
        <iconify-icon icon="solar:hand-heart-bold"></iconify-icon>
        <span class="curriculum-tab-label">Isi Jurnal Hari Ini</span>
        <span class="curriculum-tab-counter"><?= date('d M') ?></span>
    </button>
    <button type="button" class="curriculum-tab-btn" data-mtb-tab="history" onclick="switchMtbTab('history', this)">
        <iconify-icon icon="solar:calendar-date-bold"></iconify-icon>
        <span class="curriculum-tab-label">Riwayat 14 Hari</span>
        <span class="curriculum-tab-counter"><?= count($historyLogs) ?></span>
    </button>
</div>

<!-- TAB 1: DAILY MUTABAAH INPUT FORM -->
<div class="mtb-pane is-active" id="mtbPane-today">
    <div class="content-card">
        <div class="content-card-header">
            <h3 class="content-card-title">
                <iconify-icon icon="solar:hand-heart-bold" style="color: var(--lumina-teal);"></iconify-icon>
                Lembar Mutaba'ah Ibadah (<?= date('d F Y') ?>)
            </h3>
            <span class="ilmuna-badge ilmuna-badge--teal"><?= $todayLog ? 'Tercatat' : 'Belum Diisi' ?></span>
        </div>
        <div class="content-card-body">
            <form method="POST">
                <input type="hidden" name="action" value="save_mutabaah">
                <input type="hidden" name="log_date" value="<?= $todayDate ?>">

                <h4 style="font-size: 0.98rem; margin: 0 0 12px; color: var(--lumina-navy); display: flex; align-items: center; gap: 8px; font-weight: 800;">
                    <iconify-icon icon="solar:buildings-2-bold" style="color: var(--lumina-teal);"></iconify-icon> 1. Sholat Fardhu 5 Waktu
                </h4>
                <div class="ilm-grid-5" style="margin-bottom: 22px;">
                    <?php
                    $fardhu = [
                        'subuh'   => 'Subuh',
                        'dzuhur'  => 'Dzuhur',
                        'ashar'   => 'Ashar',
                        'maghrib' => 'Maghrib',
                        'isya'    => 'Isya'
                    ];
                    foreach ($fardhu as $k => $label):
                        $checked = !empty($todayLog[$k]) ? 'checked' : '';
                    ?>
                        <label class="mtb-check-card">
                            <input type="checkbox" name="<?= $k ?>" value="1" <?= $checked ?>>
                            <span>Sholat <?= $label ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <h4 style="font-size: 0.98rem; margin: 0 0 12px; color: var(--lumina-navy); display: flex; align-items: center; gap: 8px; font-weight: 800;">
                    <iconify-icon icon="solar:stars-line-bold" style="color: var(--lumina-gold);"></iconify-icon> 2. Amalan Sunnah &amp; Kebaikan
                </h4>
                <div class="ilm-grid-2" style="margin-bottom: 22px;">
                    <label class="mtb-check-card">
                        <input type="checkbox" name="dhuha" value="1" <?= !empty($todayLog['dhuha']) ? 'checked' : '' ?>>
                        <span>Sholat Sunnah Dhuha</span>
                    </label>
                    <label class="mtb-check-card">
                        <input type="checkbox" name="tahajud" value="1" <?= !empty($todayLog['tahajud']) ? 'checked' : '' ?>>
                        <span>Qiyamul Lail (Tahajud)</span>
                    </label>
                    <label class="mtb-check-card">
                        <input type="checkbox" name="puasa_sunnah" value="1" <?= !empty($todayLog['puasa_sunnah']) ? 'checked' : '' ?>>
                        <span>Puasa Sunnah Hari Ini</span>
                    </label>
                    <label class="mtb-check-card">
                        <input type="checkbox" name="infaq" value="1" <?= !empty($todayLog['infaq']) ? 'checked' : '' ?>>
                        <span>Infaq / Sedekah Subuh</span>
                    </label>
                </div>

                <h4 style="font-size: 0.98rem; margin: 0 0 12px; color: var(--lumina-navy); display: flex; align-items: center; gap: 8px; font-weight: 800;">
                    <iconify-icon icon="solar:book-bookmark-bold" style="color: var(--lumina-sky);"></iconify-icon> 3. Tilawah &amp; Tadarus Al-Qur'an
                </h4>
                <div class="ilm-grid-2-1" style="margin-bottom: 16px;">
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

                <div style="display: flex; justify-content: flex-end; margin-top: 20px;">
                    <button type="submit" class="ilmuna-nav-btn ilmuna-nav-btn--teal" style="height: 46px; padding: 0 24px; font-size: 14px;">
                        <iconify-icon icon="solar:check-circle-bold"></iconify-icon>
                        <span>Simpan Mutaba'ah Hari Ini</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- TAB 2: HISTORY LOG CARD -->
<div class="mtb-pane" id="mtbPane-history">
    <div class="content-card">
        <div class="content-card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="lms-table ilmuna-table-stack">
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
                            <tr class="ilmuna-empty-row"><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 32px;">Belum ada riwayat mutaba'ah yang tercatat.</td></tr>
                        <?php else: ?>
                            <?php foreach ($historyLogs as $hl): ?>
                                <tr>
                                    <td data-label="Tanggal"><strong><?= date('d M Y', strtotime($hl['log_date'])) ?></strong></td>
                                    <td data-label="Sholat 5 Waktu">
                                        <div style="display: flex; flex-wrap: wrap; gap: 4px; font-size: 0.75rem;">
                                            <span class="badge <?= $hl['subuh'] ? 'badge-success' : 'badge-navy' ?>">Sub</span>
                                            <span class="badge <?= $hl['dzuhur'] ? 'badge-success' : 'badge-navy' ?>">Dzu</span>
                                            <span class="badge <?= $hl['ashar'] ? 'badge-success' : 'badge-navy' ?>">Ash</span>
                                            <span class="badge <?= $hl['maghrib'] ? 'badge-success' : 'badge-navy' ?>">Mag</span>
                                            <span class="badge <?= $hl['isya'] ? 'badge-success' : 'badge-navy' ?>">Isy</span>
                                        </div>
                                    </td>
                                    <td data-label="Amalan Sunnah">
                                        <div style="display: flex; flex-wrap: wrap; gap: 4px; font-size: 0.75rem;">
                                            <?= $hl['dhuha'] ? '<span class="badge badge-gold">Dhuha</span>' : '' ?>
                                            <?= $hl['tahajud'] ? '<span class="badge badge-sky">Tahajud</span>' : '' ?>
                                            <?= $hl['puasa_sunnah'] ? '<span class="badge badge-teal">Puasa</span>' : '' ?>
                                            <?= $hl['infaq'] ? '<span class="badge badge-success">Infaq</span>' : '' ?>
                                            <?= (!$hl['dhuha'] && !$hl['tahajud'] && !$hl['puasa_sunnah'] && !$hl['infaq']) ? '<span style="color: var(--text-muted);">-</span>' : '' ?>
                                        </div>
                                    </td>
                                    <td data-label="Tilawah Qur'an">
                                        <?php if (!empty($hl['tilawah_surah'])): ?>
                                            <span><strong><?= htmlspecialchars($hl['tilawah_surah']) ?></strong>: <?= htmlspecialchars($hl['tilawah_ayat']) ?></span>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-size: 0.8rem;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Catatan">
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
</div>

<script>
function switchMtbTab(key, btn) {
    document.querySelectorAll('[data-mtb-tab]').forEach(b => b.classList.remove('is-active'));
    document.querySelectorAll('.mtb-pane').forEach(p => p.classList.remove('is-active'));
    if (btn) btn.classList.add('is-active');
    const target = document.getElementById('mtbPane-' + key);
    if (target) target.classList.add('is-active');
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
